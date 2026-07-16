<?php
/**
 * Funções compartilhadas do módulo Relatórios BI — usadas por api/relatorio-conexao.php
 * (editar conexão de um relatório existente), api/relatorio-criar.php (cadastrar um
 * relatório novo, Etapa 2), api/relatorio-excluir.php (lixeira/exclusão definitiva) e
 * crons/lixeira-purge.php (purga automática de 30 dias). Ver RELATORIOS_BI.md para o
 * desenho completo do módulo.
 */
require_once __DIR__ . '/XlsxReader.php';
require_once __DIR__ . '/../services/NimbusTaxPortalSync.php';
require_once __DIR__ . '/../scripts/regenerar-nginx-relatorios-bi.php';

// Tipos de conexão suportados hoje; 'webhook' reservado para o futuro (desabilitado na UI).
const RBI_TIPOS_CONEXAO_HABILITADOS = ['sql', 'excel'];

/**
 * Testa uma conexão Postgres com as credenciais informadas. Nunca lança —
 * retorna [true, null] em sucesso ou [false, mensagem] em falha.
 */
function testarConexaoSql(array $cfg): array {
    $host = trim($cfg['host'] ?? '');
    $port = trim((string)($cfg['port'] ?? '5432'));
    $dbname = trim($cfg['dbname'] ?? '');
    $dbUser = trim($cfg['user'] ?? '');
    $dbPass = (string)($cfg['password'] ?? '');

    if ($host === '' || $dbname === '' || $dbUser === '') {
        return [false, 'Host, banco e usuário são obrigatórios'];
    }

    try {
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};connect_timeout=5";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->query('SELECT 1');
        return [true, null];
    } catch (PDOException $e) {
        // Mensagem do driver pode conter a senha no DSN de erro em alguns casos — usar só getMessage()
        // do PDOException (não expõe params) e nunca logar $cfg completo.
        return [false, 'Falha ao conectar: ' . $e->getMessage()];
    }
}

/**
 * Caminho do relatório no filesystem — slug é imutável e igual ao nome da pasta (ver RELATORIOS_BI.md).
 */
function pastaRelatorio(string $slug): string {
    return __DIR__ . '/../relatorios-bi/' . $slug;
}

/**
 * Porta interna do Gunicorn — determinística (8100 + relatorio_id), nunca gravada
 * no banco. Mesma fórmula usada por scripts/regenerar-nginx-relatorios-bi.php para
 * o map do nginx. Ver RELATORIOS_BI.md.
 */
function portaRelatorio(int $relatorioId): int {
    return 8100 + $relatorioId;
}

/**
 * Bloco somente-leitura "Infraestrutura" da aba Conexão — pasta/serviço/porta
 * computados a partir de slug/id, válidos mesmo antes de o app Python existir.
 */
function infraestruturaRelatorio(int $relatorioId, string $slug): array {
    return [
        'pasta'   => 'relatorios-bi/' . $slug,
        'servico' => 'kw24-relatorio-' . $slug . '.service',
        'porta'   => portaRelatorio($relatorioId),
    ];
}

/**
 * Grava (ou remove) o arquivo local de config que o processo Python (db.py) lê no lugar do .env.
 * Permissão restrita (0600) — mesmo usuário (kw24) roda PHP-FPM e o Gunicorn dos relatórios.
 */
function escreverDbConfigJson(string $slug, array $cfg): bool {
    $dir = pastaRelatorio($slug);
    if (!is_dir($dir)) return false;
    $path = $dir . '/.dbconfig.json';
    $ok = @file_put_contents($path, json_encode($cfg, JSON_PRETTY_PRINT)) !== false;
    if ($ok) @chmod($path, 0600);
    return $ok;
}

/**
 * Homóglifos cirílicos comuns (visualmente idênticos a letras latinas) — comum em
 * exports de ferramentas de terceiros (ex.: Bitrix Contact Center) que acabam
 * misturando alfabetos por engano. Sem este mapa, iconv TRANSLIT//IGNORE
 * simplesmente DESCARTA esses caracteres (não tem equivalente ASCII direto),
 * o que já causou um cabeçalho real "Сolaborador" (С cirílico, U+0421) virar
 * "olaborador" — perdendo a primeira letra silenciosamente. Mapeado ANTES do
 * iconv pra virar a letra latina óbvia em vez de sumir.
 */
const RBI_HOMOGLIFOS_CIRILICOS = [
    'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
    'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'У' => 'Y', 'Х' => 'X',
    'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x',
];

/**
 * Slugify — usado tanto para sugerir o slug do relatório a partir do nome amigável
 * quanto (aplicado a cada tabela) para o nome real de tabela Excel. Minúsculo, sem
 * acento, alfanumérico com hífen/underscore como separador único, sem hífen/underscore
 * nas pontas.
 */
function slugify(string $texto, string $separador = '-'): string {
    $t = trim($texto);
    $t = strtr($t, RBI_HOMOGLIFOS_CIRILICOS);
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    if ($translit !== false) $t = $translit;
    $t = strtolower($t);
    $t = preg_replace('/[^a-z0-9]+/', $separador, $t);
    $t = trim($t, $separador);
    $t = preg_replace('/' . preg_quote($separador, '/') . '{2,}/', $separador, $t);
    return $t;
}

/**
 * Formato válido de slug de relatório: minúsculo, alfanumérico, hífen único como
 * separador (mesmo padrão dos 2 relatórios existentes — ex. relatorio-parceiros-tax).
 */
function slugFormatoValido(string $slug): bool {
    return (bool) preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug);
}

/**
 * true se o slug já existe em relatorios_bi (qualquer linha, inclusive em_construcao).
 */
function slugJaExiste(Database $db, string $slug): bool {
    return (bool) $db->fetchOne('SELECT 1 FROM relatorios_bi WHERE slug = :s', ['s' => $slug]);
}

/**
 * Sanitiza um texto livre (nome de tabela Excel ou cabeçalho de coluna) num
 * identificador Postgres seguro: minúsculo, ASCII, [a-z0-9_], sem underscore
 * nas pontas. Vazio quando o texto não tem nenhum caractere alfanumérico
 * aproveitável (ex.: célula de cabeçalho vazia) — chamador deve tratar como erro.
 */
function sanitizarIdentificador(string $texto): string {
    return slugify($texto, '_');
}

/**
 * Mesma sanitização, mas com fallback posicional (ex.: "coluna_3") quando o texto
 * não sobra com nenhum caractere alfanumérico aproveitável (ex.: cabeçalho "#",
 * comum em exports de terceiros como colunas de ID) — em vez de bloquear a
 * criação inteira da tabela por causa de UMA coluna. Nunca lança/rejeita aqui;
 * o chamador decide o que fazer (ex.: avisar o admin que houve renomeação).
 *
 * @return array{0: string, 1: bool} [nomeFinal, foiFallback]
 */
function sanitizarIdentificadorComFallback(string $texto, string $prefixoFallback, int $posicao1Based): array {
    $nome = sanitizarIdentificador($texto);
    if ($nome !== '') return [$nome, false];
    return [$prefixoFallback . '_' . $posicao1Based, true];
}

/**
 * Infere o tipo de coluna Postgres a partir dos valores de uma coluna do Excel
 * (já como strings cruas vindas do XlsxReader). 'numeric' só se TODOS os valores
 * não-vazios forem numéricos; 'date' só se TODOS baterem com um formato de data
 * reconhecido (YYYY-MM-DD ou DD/MM/YYYY); 'text' (padrão, "quando em dúvida")
 * em qualquer outro caso — inclusive coluna inteiramente vazia.
 *
 * Limitação conhecida: células de data NATIVAS do Excel (formatadas via estilo,
 * não digitadas como texto) chegam do XlsxReader como número serial — esta
 * função não lê xl/styles.xml, então essas colunas viram 'numeric', não 'date'.
 * Aceitável para a Etapa 2 (create-only); reavaliar se virar problema recorrente.
 */
function inferirTipoColuna(array $valores): string {
    $naoVazios = array_values(array_filter($valores, fn($v) => $v !== null && trim((string)$v) !== ''));
    if (!$naoVazios) return 'text';

    $todosNumericos = true;
    foreach ($naoVazios as $v) {
        if (!is_numeric(trim((string)$v))) { $todosNumericos = false; break; }
    }
    if ($todosNumericos) return 'numeric';

    $todosData = true;
    foreach ($naoVazios as $v) {
        $s = trim((string)$v);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && !preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $s)) {
            $todosData = false; break;
        }
    }
    if ($todosData) return 'date';

    return 'text';
}

/**
 * Normaliza um valor de data (DD/MM/YYYY ou YYYY-MM-DD, únicos formatos aceitos
 * por inferirTipoColuna) pro formato YYYY-MM-DD que o Postgres espera.
 */
function normalizarValorData(string $v): string {
    $v = trim($v);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    return $v; // já está em YYYY-MM-DD
}

/**
 * Conexão dedicada ao banco relatorios_bi_excel — mesmo servidor/credenciais do
 * kwconfig (config/config.php), dbname diferente. Mesmo padrão de getBxPdo()/
 * getCtPdo() em api/portais-bi.php.
 */
function getExcelPdo(): PDO {
    $cfg = require __DIR__ . '/../config/config.php';
    $dbCfg = $cfg['database'];
    $dsn = "pgsql:host={$dbCfg['host']};port={$dbCfg['port']};dbname=relatorios_bi_excel";
    return new PDO($dsn, $dbCfg['username'], $dbCfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function quoteIdent(string $ident): string {
    return '"' . str_replace('"', '""', $ident) . '"';
}

/** Schema Postgres (banco relatorios_bi_excel) dedicado a um relatório — 1 por RELATÓRIO. */
function schemaExcelRelatorio(string $slug): string {
    return 'rbi_' . str_replace('-', '_', $slug);
}

/**
 * Parseia e valida um único arquivo .xlsx (cabeçalho + linhas de dados) — não toca o
 * banco, só interpreta o arquivo em si. Reaproveitada tanto na criação/adição de
 * tabelas (processarUploadTabelasExcel) quanto na atualização de dados de uma tabela
 * já existente (ver api/relatorio-conexao.php, action=detectar-atualizar-tabela /
 * atualizar-tabela-excel) — mesma validação de cabeçalho vazio/duplicado em ambos os
 * fluxos, nunca duplicada.
 *
 * @param string $rotulo usado nas mensagens de erro (ex.: "Tabela 'clientes'")
 * @return array{sucesso:bool, erro?:string, colunas?:array<string>, tipos?:array<string>,
 *   linhas?:array<array>, cabecalho_original?:array, colunas_ajustadas?:array<string>}
 */
function parseArquivoExcelTabela(string $tmpPath, string $rotulo): array {
    try {
        $lido = XlsxReader::ler($tmpPath);
    } catch (XlsxLerException $e) {
        return ['sucesso' => false, 'erro' => "{$rotulo}: " . $e->getMessage()];
    }
    $cabecalho = $lido['cabecalho'];
    $linhas    = $lido['linhas'];

    if (!$linhas) {
        return ['sucesso' => false, 'erro' => "{$rotulo}: arquivo sem linhas de dados"];
    }

    $colunas = [];
    $colunasAjustadas = [];
    foreach ($cabecalho as $idx => $h) {
        $original = (string)($h ?? '');
        [$colNome, $foiFallback] = sanitizarIdentificadorComFallback($original, 'coluna', $idx + 1);
        if ($foiFallback) {
            $rotuloOriginal = trim($original) === '' ? '(vazio)' : $original;
            $colunasAjustadas[] = "coluna " . ($idx + 1) . " (\"{$rotuloOriginal}\") -> \"{$colNome}\"";
        }
        $colunas[] = $colNome;
    }
    if (count($colunas) !== count(array_unique($colunas))) {
        $porNome = [];
        foreach ($colunas as $idx => $nome) $porNome[$nome][] = ($idx + 1) . " (\"{$cabecalho[$idx]}\")";
        $detalhes = [];
        foreach ($porNome as $nome => $posicoes) {
            if (count($posicoes) > 1) $detalhes[] = "'{$nome}' nas colunas " . implode(', ', $posicoes);
        }
        return ['sucesso' => false, 'erro' => "{$rotulo}: cabeçalho com colunas duplicadas depois de normalizado — " . implode('; ', $detalhes)];
    }

    $tipos = [];
    foreach (array_keys($colunas) as $idx) {
        $valoresColuna = array_map(fn($linha) => $linha[$idx] ?? null, $linhas);
        $tipos[] = inferirTipoColuna($valoresColuna);
    }

    return [
        'sucesso' => true, 'colunas' => $colunas, 'tipos' => $tipos, 'linhas' => $linhas,
        'cabecalho_original' => $cabecalho, 'colunas_ajustadas' => $colunasAjustadas,
    ];
}

/**
 * Valida, parseia e cria de fato (schema + tabelas + dados, numa transação própria no
 * banco relatorios_bi_excel) as tabelas Excel enviadas via $_POST/$_FILES. Não mexe em
 * nada no kwconfig (relatorios_bi/relatorios_bi_conexoes) — quem chama decide o que
 * fazer com o resultado: inserir uma linha relatorios_bi nova (criação, ver
 * api/relatorio-criar.php) ou só atualizar a lista de tabelas de um relatório Excel já
 * existente (ver api/relatorio-conexao.php, action=add-tabelas-excel).
 *
 * @param array $nomesExistentes nomes de tabela (já sanitizados) que já existem nesse
 *   schema — usado ao ADICIONAR tabelas a um relatório já existente, pra rejeitar
 *   colisão com o que já está lá. Passar [] na criação inicial (schema novo).
 * @return array{sucesso:bool, erro?:string, tabelas_criadas?:array<string>, colunas_ajustadas?:array}
 */
function processarUploadTabelasExcel(string $schema, array $nomesTabelas, array $arquivos, array $nomesExistentes = []): array {
    // ── Validação de linhas (nome + upload) ─────────────────────────────────
    $tabelasValidas = [];
    $total = count($arquivos['name'] ?? []);
    for ($i = 0; $i < $total; $i++) {
        $nomeBruto  = trim($nomesTabelas[$i] ?? '');
        $erroUpload = $arquivos['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($nomeBruto === '' && $erroUpload === UPLOAD_ERR_NO_FILE) continue; // linha vazia da UI

        if ($nomeBruto === '') return ['sucesso' => false, 'erro' => 'Toda tabela precisa de um nome'];
        if ($erroUpload !== UPLOAD_ERR_OK) {
            return ['sucesso' => false, 'erro' => "Tabela '{$nomeBruto}': falha no upload do arquivo"];
        }

        // Nome só com símbolos (ex.: "###") cai no fallback posicional em vez de
        // bloquear — mesma lógica do cabeçalho de coluna, ver sanitizarIdentificadorComFallback().
        [$nomeTabela, ] = sanitizarIdentificadorComFallback($nomeBruto, 'tabela', $i + 1);

        $tabelasValidas[] = ['nome' => $nomeTabela, 'nome_original' => $nomeBruto, 'tmp_path' => $arquivos['tmp_name'][$i]];
    }

    if (!$tabelasValidas) return ['sucesso' => false, 'erro' => 'Pelo menos uma tabela (nome + arquivo) é obrigatória'];

    $nomesSanit = array_column($tabelasValidas, 'nome');
    if (count($nomesSanit) !== count(array_unique($nomesSanit))) {
        $porNomeTabela = [];
        foreach ($tabelasValidas as $t) $porNomeTabela[$t['nome']][] = "\"{$t['nome_original']}\"";
        $detalhes = [];
        foreach ($porNomeTabela as $nome => $originais) {
            if (count($originais) > 1) $detalhes[] = "'{$nome}' (" . implode(', ', $originais) . ")";
        }
        return ['sucesso' => false, 'erro' => 'Duas ou mais tabelas resultaram no mesmo nome depois de normalizado: ' . implode('; ', $detalhes) . ' — renomeie para diferenciar'];
    }

    $colisao = array_values(array_intersect($nomesSanit, $nomesExistentes));
    if ($colisao) {
        return ['sucesso' => false, 'erro' => 'Já existe(m) tabela(s) com esse nome neste relatório: ' . implode(', ', $colisao) . ' — escolha outro nome'];
    }

    // ── Parse + validação de TODOS os arquivos antes de criar qualquer coisa no banco ──
    $parseados = [];
    foreach ($tabelasValidas as $t) {
        $parse = parseArquivoExcelTabela($t['tmp_path'], "Tabela '{$t['nome_original']}'");
        if (!$parse['sucesso']) return $parse;

        $parseados[] = [
            'nome' => $t['nome'], 'colunas' => $parse['colunas'], 'tipos' => $parse['tipos'],
            'linhas' => $parse['linhas'], 'colunas_ajustadas' => $parse['colunas_ajustadas'],
        ];
    }

    // ── Tudo validado — cria schema + tabelas + insere dados (transação) ──
    $excelPdo = getExcelPdo();
    $excelPdo->beginTransaction();
    try {
        $excelPdo->exec('CREATE SCHEMA IF NOT EXISTS ' . quoteIdent($schema));

        foreach ($parseados as $tab) {
            $tabelaQuoted = quoteIdent($schema) . '.' . quoteIdent($tab['nome']);

            $defsColunas = [];
            foreach ($tab['colunas'] as $i => $col) {
                $tipoSql = ['numeric' => 'numeric', 'date' => 'date'][$tab['tipos'][$i]] ?? 'text';
                $defsColunas[] = quoteIdent($col) . ' ' . $tipoSql;
            }
            // DROP defensivo: nome já checado contra $nomesExistentes acima, então não
            // deveria existir — evita erro caso o schema tenha ficado num estado inesperado.
            $excelPdo->exec('DROP TABLE IF EXISTS ' . $tabelaQuoted);
            $excelPdo->exec('CREATE TABLE ' . $tabelaQuoted . ' (' . implode(', ', $defsColunas) . ')');

            $colunasQuoted = implode(', ', array_map('quoteIdent', $tab['colunas']));
            $placeholders  = implode(', ', array_fill(0, count($tab['colunas']), '?'));
            $stmt = $excelPdo->prepare("INSERT INTO {$tabelaQuoted} ({$colunasQuoted}) VALUES ({$placeholders})");

            foreach ($tab['linhas'] as $linha) {
                $valores = [];
                foreach ($tab['colunas'] as $i => $col) {
                    $v = $linha[$i] ?? null;
                    $vazio = ($v === null || trim((string)$v) === '');
                    if (!$vazio && $tab['tipos'][$i] === 'date') $v = normalizarValorData((string)$v);
                    $valores[] = $vazio ? null : $v;
                }
                $stmt->execute($valores);
            }
        }
        $excelPdo->commit();
    } catch (Exception $e) {
        $excelPdo->rollBack();
        error_log('[relatorios-bi-excel] falha ao criar tabelas: ' . $e->getMessage());
        return ['sucesso' => false, 'erro' => 'Falha ao criar as tabelas: ' . $e->getMessage()];
    }

    $ajustesPorTabela = [];
    foreach ($parseados as $tab) {
        if ($tab['colunas_ajustadas']) $ajustesPorTabela[$tab['nome']] = $tab['colunas_ajustadas'];
    }

    return [
        'sucesso'           => true,
        'tabelas_criadas'   => array_column($parseados, 'nome'),
        'colunas_ajustadas' => $ajustesPorTabela,
    ];
}

/**
 * Colunas REAIS de uma tabela Excel, direto do information_schema — nunca uma lista
 * em cache (config JSONB não guarda estrutura de coluna, só nomes de tabela). Usado
 * no fluxo de atualização de dados pra reconciliar contra o arquivo novo.
 *
 * @return array<string,string> nome da coluna => tipo Postgres ('text'/'numeric'/'date'/...),
 *   na ordem real das colunas (ordinal_position). Vazio se a tabela não existir.
 */
function colunasReaisTabelaExcel(PDO $excelPdo, string $schema, string $tabela): array {
    $stmt = $excelPdo->prepare(
        'SELECT column_name, data_type FROM information_schema.columns
          WHERE table_schema = :schema AND table_name = :tabela
          ORDER BY ordinal_position'
    );
    $stmt->execute(['schema' => $schema, 'tabela' => $tabela]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['column_name']] = $row['data_type'];
    }
    return $out;
}

/**
 * Compara as colunas de um arquivo recém-parseado (parseArquivoExcelTabela) contra as
 * colunas REAIS já existentes numa tabela Excel. Nome igual (exato, já sanitizado) é
 * auto-vinculado, sem revisão — qualquer coluna do arquivo novo sem correspondência
 * exata precisa de decisão do admin (nunca resolvido sozinho/silenciosamente): mapear
 * pra uma coluna existente (rename/typo) ou criar como coluna nova.
 *
 * @return array{colunas_existentes:array<string>, colunas_batem:array<string>, colunas_revisao:array}
 */
function compararColunasAtualizacao(array $colunasReais, array $colunasParseadas, array $tiposParseados, array $cabecalhoOriginal): array {
    $colunasBatem   = [];
    $colunasRevisao = [];
    foreach ($colunasParseadas as $idx => $col) {
        if (array_key_exists($col, $colunasReais)) {
            $colunasBatem[] = $col;
        } else {
            $colunasRevisao[] = [
                'coluna'            => $col,
                'tipo_inferido'     => $tiposParseados[$idx],
                'cabecalho_original'=> (string)($cabecalhoOriginal[$idx] ?? $col),
            ];
        }
    }
    return [
        'colunas_existentes' => array_keys($colunasReais),
        'colunas_batem'      => $colunasBatem,
        'colunas_revisao'    => $colunasRevisao,
    ];
}

/**
 * Aplica a atualização de dados de uma tabela Excel já existente — modo 'atualizar'
 * (insere as linhas do arquivo novo por cima, sem apagar nada) ou 'substituir' (apaga
 * as linhas atuais e insere só as do arquivo novo). Em AMBOS os modos, nenhuma coluna
 * existente é removida — uma coluna ausente no arquivo novo só significa NULL pras
 * linhas recém-inseridas (ver RELATORIOS_BI.md).
 *
 * Sempre relê as colunas reais do banco (nunca confia em lista vinda do cliente) e
 * revalida o mapeamento contra elas — inclusive rejeitando duas colunas do arquivo
 * mapeadas pra mesma coluna existente (ambíguo).
 *
 * @param array $parse retorno de parseArquivoExcelTabela() (já validado, sucesso=true)
 * @param array $mapeamento coluna-do-arquivo-novo (já sanitizada, só as SEM correspondência
 *   exata) => nome de coluna existente pra vincular, ou null/vazio pra criar como nova.
 * @return array{sucesso:bool, erro?:string, linhas_inseridas?:int, colunas_criadas?:array<string>}
 */
function atualizarDadosTabelaExcel(string $schema, string $tabela, string $modo, array $parse, array $mapeamento): array {
    if (!in_array($modo, ['substituir', 'atualizar'], true)) {
        return ['sucesso' => false, 'erro' => 'Modo inválido'];
    }

    $excelPdo = getExcelPdo();
    $colunasReais = colunasReaisTabelaExcel($excelPdo, $schema, $tabela);
    if (!$colunasReais) {
        return ['sucesso' => false, 'erro' => 'Tabela não encontrada ou sem colunas'];
    }

    // ── Resolve o destino (nome de coluna real) de cada coluna do arquivo novo ──
    $destinoPorColuna  = []; // colunaArquivo => nomeColunaReal (existente ou nova)
    $colunasNovasCriar = []; // nomeColunaReal => tipo lógico ('text'/'numeric'/'date'), só as que precisam ALTER TABLE
    $usadasComoDestino = []; // nomeColunaReal => colunaArquivo, detecta 2 mapeadas pra mesma

    foreach ($parse['colunas'] as $idx => $col) {
        if (array_key_exists($col, $colunasReais)) {
            $destino = $col; // match exato — auto-vinculado, ignora qualquer mapeamento pra ela
        } else {
            if (!array_key_exists($col, $mapeamento)) {
                return ['sucesso' => false, 'erro' => "Coluna '{$col}' sem decisão de mapeamento (vincular a uma existente ou criar nova)"];
            }
            $escolha = $mapeamento[$col];
            if ($escolha === null || $escolha === '') {
                $destino = $col; // cria como nova coluna, com o próprio nome sanitizado
                $colunasNovasCriar[$destino] = $parse['tipos'][$idx];
            } else {
                if (!array_key_exists($escolha, $colunasReais)) {
                    return ['sucesso' => false, 'erro' => "Coluna existente '{$escolha}' (mapeamento de '{$col}') não encontrada na tabela"];
                }
                $destino = $escolha;
            }
        }

        if (isset($usadasComoDestino[$destino]) && $usadasComoDestino[$destino] !== $col) {
            return ['sucesso' => false, 'erro' => "Duas colunas do arquivo novo ('{$usadasComoDestino[$destino]}' e '{$col}') foram mapeadas pra mesma coluna existente '{$destino}' — escolha mapeamentos diferentes"];
        }
        $usadasComoDestino[$destino] = $col;

        $destinoPorColuna[$col] = $destino;
    }

    $tabelaQuoted = quoteIdent($schema) . '.' . quoteIdent($tabela);

    $excelPdo->beginTransaction();
    try {
        foreach ($colunasNovasCriar as $nome => $tipoLogico) {
            $tipoSql = ['numeric' => 'numeric', 'date' => 'date'][$tipoLogico] ?? 'text';
            $excelPdo->exec('ALTER TABLE ' . $tabelaQuoted . ' ADD COLUMN ' . quoteIdent($nome) . ' ' . $tipoSql);
            $colunasReais[$nome] = $tipoSql; // agora existe de verdade — usado abaixo pra decidir normalização de data
        }

        if ($modo === 'substituir') {
            $excelPdo->exec('TRUNCATE TABLE ' . $tabelaQuoted); // só apaga LINHAS — nenhuma coluna é removida
        }

        $colunasDestinoOrdenadas = array_values($destinoPorColuna); // mesma ordem de $parse['colunas']
        $colunasQuoted = implode(', ', array_map('quoteIdent', $colunasDestinoOrdenadas));
        $placeholders  = implode(', ', array_fill(0, count($colunasDestinoOrdenadas), '?'));
        $stmt = $excelPdo->prepare("INSERT INTO {$tabelaQuoted} ({$colunasQuoted}) VALUES ({$placeholders})");

        foreach ($parse['linhas'] as $linha) {
            $valores = [];
            foreach ($parse['colunas'] as $idx => $col) {
                $destino     = $destinoPorColuna[$col];
                $tipoDestino = $colunasReais[$destino] ?? 'text'; // tipo REAL da coluna de destino, não o inferido do arquivo
                $v = $linha[$idx] ?? null;
                $vazio = ($v === null || trim((string)$v) === '');
                if (!$vazio && $tipoDestino === 'date') $v = normalizarValorData((string)$v);
                $valores[] = $vazio ? null : $v;
            }
            $stmt->execute($valores);
        }

        $excelPdo->commit();
    } catch (Exception $e) {
        $excelPdo->rollBack();
        error_log('[relatorios-bi-excel] falha ao atualizar dados da tabela: ' . $e->getMessage());
        return ['sucesso' => false, 'erro' => 'Falha ao atualizar os dados: ' . $e->getMessage()];
    }

    return [
        'sucesso'          => true,
        'linhas_inseridas' => count($parse['linhas']),
        'colunas_criadas'  => array_keys($colunasNovasCriar),
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// Lixeira (jul/2026) — mover/restaurar/excluir definitivamente QUALQUER relatório
// (publicado ou rascunho). Ver seção "Lixeira" em RELATORIOS_BI.md.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Resumo (contagens, não lista item a item) do que está ligado a um relatório — exibido
 * antes de confirmar mover pra lixeira, pra deixar claro o que está em jogo mesmo que o
 * número seja zero. Empresas = cliente_aplicacoes ativas com o slug no config_extra;
 * usuários = relatorio_usuario_permissoes distintos com pode_ver; portais = portais_bi
 * ATIVOS (não conta os já inativos por outro motivo).
 *
 * @return array{empresas:int, usuarios:int, portais:int}
 */
function resumoAtivosRelatorio(Database $db, string $slug, int $relatorioId): array {
    $rowEmp = $db->fetchOne(
        "SELECT COUNT(DISTINCT ca.cliente_id) AS total
           FROM cliente_aplicacoes ca
           JOIN aplicacoes a ON a.id = ca.aplicacao_id AND a.slug = 'relatorios-bi'
          WHERE ca.ativo = TRUE AND jsonb_exists(ca.config_extra -> 'relatorios', :slug)",
        ['slug' => $slug]
    );
    $rowUsr = $db->fetchOne(
        'SELECT COUNT(DISTINCT usuario_id) AS total FROM relatorio_usuario_permissoes WHERE relatorio_id = :id AND pode_ver = TRUE',
        ['id' => $relatorioId]
    );
    $rowPortal = $db->fetchOne(
        'SELECT COUNT(*) AS total FROM portais_bi WHERE relatorio_slug = :slug AND ativo = TRUE',
        ['slug' => $slug]
    );
    return [
        'empresas' => (int)($rowEmp['total'] ?? 0),
        'usuarios' => (int)($rowUsr['total'] ?? 0),
        'portais'  => (int)($rowPortal['total'] ?? 0),
    ];
}

/** Nome do serviço systemd — determinístico, mesma fórmula de infraestruturaRelatorio(). */
function servicoSystemdRelatorio(string $slug): string {
    return 'kw24-relatorio-' . $slug . '.service';
}

/**
 * Para e desabilita o serviço systemd de um relatório — best-effort. Um relatório que
 * nunca teve dashboard Python de verdade (ex.: ainda em_construcao, ou um rascunho de
 * teste) não tem nenhum unit — `systemctl stop/disable` num serviço inexistente falha,
 * mas isso NUNCA bloqueia mover pra lixeira. Resultado é só informativo pro admin.
 */
function pararEDesabilitarServicoRelatorio(string $slug): bool {
    $servico = escapeshellarg(servicoSystemdRelatorio($slug));
    exec("sudo systemctl stop {$servico} 2>&1", $outStop, $rcStop);
    exec("sudo systemctl disable {$servico} 2>&1", $outDisable, $rcDisable);
    return $rcStop === 0 && $rcDisable === 0;
}

/**
 * Reabilita e reinicia o serviço systemd de um relatório restaurado da lixeira — mesma
 * ressalva best-effort do irmão acima (nunca bloqueia o restore).
 */
function reabilitarEIniciarServicoRelatorio(string $slug): bool {
    $servico = escapeshellarg(servicoSystemdRelatorio($slug));
    exec("sudo systemctl enable {$servico} 2>&1", $outEnable, $rcEnable);
    exec("sudo systemctl start {$servico} 2>&1", $outStart, $rcStart);
    return $rcEnable === 0 && $rcStart === 0;
}

/**
 * Move QUALQUER relatório (publicado ou rascunho) pra lixeira — soft-delete reversível,
 * NUNCA apaga dado nenhum. Para/desabilita o serviço systemd, tira o slug do map do
 * nginx (regenerarMapNginxRelatoriosBi() já ignora quem está na lixeira — ver ali),
 * some do hub pra todo mundo (api/relatorios-bi.php?action=list filtra lixeira_em IS
 * NULL) e desativa (nunca apaga) os portais ligados a ele — gravando o estado ANTERIOR
 * de cada um (alguns podem já estar inativos por outro motivo) pra restaurar exato.
 *
 * @return array{sucesso:bool, systemd_ok:bool, nginx_ok:bool, nginx_erro:?string, portais_desativados:int}
 */
function moverRelatorioParaLixeira(Database $db, array $relatorio): array {
    $id   = (int)$relatorio['id'];
    $slug = $relatorio['slug'];

    $portais = $db->fetchAll(
        'SELECT id, filter_type, filter_values, slug, ativo FROM portais_bi WHERE relatorio_slug = :slug',
        ['slug' => $slug]
    );
    $estadoAntes = [];
    foreach ($portais as $p) {
        $estadoAntes[(string)$p['id']] = filter_var($p['ativo'], FILTER_VALIDATE_BOOLEAN);
    }

    foreach ($portais as $p) {
        if (!filter_var($p['ativo'], FILTER_VALIDATE_BOOLEAN)) continue; // já inativo, nada a fazer
        $oldForSync = [
            'relatorio_slug' => $slug, 'filter_type' => $p['filter_type'],
            'filter_values'  => json_decode($p['filter_values'], true) ?? [],
            'slug'           => $p['slug'], 'ativo' => true,
        ];
        $newForSync = $oldForSync; $newForSync['ativo'] = false;
        $db->execute('UPDATE portais_bi SET ativo = FALSE WHERE id = :id', ['id' => $p['id']]);
        NimbusTaxPortalSync::sync($oldForSync, $newForSync, null);
    }

    $systemdOk = pararEDesabilitarServicoRelatorio($slug);

    $db->execute(
        'UPDATE relatorios_bi SET lixeira_em = NOW(), lixeira_portais_estado = :estado::jsonb WHERE id = :id',
        ['estado' => json_encode($estadoAntes), 'id' => $id]
    );

    $nginx = regenerarMapNginxRelatoriosBi();

    return [
        'sucesso'             => true,
        'systemd_ok'          => $systemdOk,
        'nginx_ok'            => $nginx['sucesso'],
        'nginx_erro'          => $nginx['sucesso'] ? null : $nginx['erro'],
        'portais_desativados' => count(array_filter($estadoAntes)),
    ];
}

/**
 * Restaura um relatório da lixeira ao estado exato de antes: cada portal volta ao seu
 * PRÓPRIO estado anterior (gravado em lixeira_portais_estado) — um portal que já estava
 * inativo antes de mover pra lixeira NÃO reativa só porque o relatório voltou. Reabilita
 * o serviço systemd e recoloca o slug no map do nginx.
 *
 * @return array{sucesso:bool, systemd_ok:bool, nginx_ok:bool, nginx_erro:?string}
 */
function restaurarRelatorioDaLixeira(Database $db, array $relatorio): array {
    $id          = (int)$relatorio['id'];
    $slug        = $relatorio['slug'];
    $estadoAntes = json_decode($relatorio['lixeira_portais_estado'] ?? '[]', true) ?: [];

    $portais = $db->fetchAll(
        'SELECT id, filter_type, filter_values, slug, ativo FROM portais_bi WHERE relatorio_slug = :slug',
        ['slug' => $slug]
    );
    foreach ($portais as $p) {
        $idStr = (string)$p['id'];
        if (!array_key_exists($idStr, $estadoAntes)) continue; // portal criado depois do trash — não mexe
        $deveEstarAtivo = (bool)$estadoAntes[$idStr];
        $estaAtivo      = filter_var($p['ativo'], FILTER_VALIDATE_BOOLEAN);
        if ($deveEstarAtivo === $estaAtivo) continue; // já está no estado correto, nada a fazer

        $oldForSync = [
            'relatorio_slug' => $slug, 'filter_type' => $p['filter_type'],
            'filter_values'  => json_decode($p['filter_values'], true) ?? [],
            'slug'           => $p['slug'], 'ativo' => $estaAtivo,
        ];
        $newForSync = $oldForSync; $newForSync['ativo'] = $deveEstarAtivo;
        $db->execute('UPDATE portais_bi SET ativo = :ativo WHERE id = :id', ['ativo' => $deveEstarAtivo ? 'true' : 'false', 'id' => $p['id']]);
        NimbusTaxPortalSync::sync($oldForSync, $newForSync, null);
    }

    $systemdOk = reabilitarEIniciarServicoRelatorio($slug);

    $db->execute('UPDATE relatorios_bi SET lixeira_em = NULL, lixeira_portais_estado = NULL WHERE id = :id', ['id' => $id]);

    $nginx = regenerarMapNginxRelatoriosBi();

    return [
        'sucesso'    => true,
        'systemd_ok' => $systemdOk,
        'nginx_ok'   => $nginx['sucesso'],
        'nginx_erro' => $nginx['sucesso'] ? null : $nginx['erro'],
    ];
}

/**
 * Cascata destrutiva completa — chamada tanto pelo botão manual "Excluir definitivamente"
 * (tela Lixeira) quanto pela purga automática de 30 dias (crons/lixeira-purge.php). Quem
 * chama garante que o relatório já está na lixeira antes de invocar esta função. Nunca
 * apaga a pasta relatorios-bi/{slug}/ (código) nem toca no unit systemd além do que a
 * lixeira já fez (parar/desabilitar) — fica pro dev arquivar manualmente se quiser.
 *
 * @return array{sucesso:bool, erro?:string, bitrix_limpeza?:array, nginx_ok?:bool}
 */
function excluirRelatorioDefinitivamente(Database $db, array $relatorio): array {
    $id   = (int)$relatorio['id'];
    $slug = $relatorio['slug'];

    // Excel: apaga o schema real (tabelas + dados) — SQL: nunca toca no banco externo do
    // cliente, só a linha de credencial local é removida (abaixo, junto com o resto).
    $conexao = $db->fetchOne('SELECT tipo_conexao, config FROM relatorios_bi_conexoes WHERE relatorio_id = :id', ['id' => $id]);
    if ($conexao && $conexao['tipo_conexao'] === 'excel') {
        $cfg    = json_decode($conexao['config'], true) ?? [];
        $schema = $cfg['schema'] ?? schemaExcelRelatorio($slug);
        try {
            $excelPdo = getExcelPdo();
            $excelPdo->exec('DROP SCHEMA IF EXISTS ' . quoteIdent($schema) . ' CASCADE');
        } catch (Exception $e) {
            error_log('[lixeira] falha ao dropar schema Excel: ' . $e->getMessage());
            return ['sucesso' => false, 'erro' => 'Falha ao remover as tabelas Excel: ' . $e->getMessage()];
        }
    }

    // Bitrix — best-effort: tenta limpar os campos de Company de todo portal que qualificava
    // pro sync NimbusTax. Nunca bloqueia a exclusão se a chamada falhar — só é reportado.
    $portais = $db->fetchAll(
        'SELECT id, filter_type, filter_values, slug, ativo FROM portais_bi WHERE relatorio_slug = :slug',
        ['slug' => $slug]
    );
    $bitrixLimpeza = [];
    foreach ($portais as $p) {
        $rowForSync = [
            'relatorio_slug' => $slug, 'filter_type' => $p['filter_type'],
            'filter_values'  => json_decode($p['filter_values'], true) ?? [],
            'slug'           => $p['slug'], 'ativo' => filter_var($p['ativo'], FILTER_VALIDATE_BOOLEAN),
        ];
        if (!NimbusTaxPortalSync::qualifies($rowForSync)) continue;
        try {
            NimbusTaxPortalSync::sync($rowForSync, null, null);
            $bitrixLimpeza[] = ['portal_slug' => $p['slug'], 'tentativa' => true];
        } catch (Exception $e) {
            $bitrixLimpeza[] = ['portal_slug' => $p['slug'], 'tentativa' => true, 'erro' => $e->getMessage()];
        }
    }

    $conn = $db->getConnection();
    $conn->beginTransaction();
    try {
        // Remove o slug de TODO cliente_aplicacoes.config_extra->relatorios que o referencia —
        // jsonb - text remove só o elemento que bate exatamente, preserva os demais da lista.
        $db->execute(
            "UPDATE cliente_aplicacoes
                SET config_extra = jsonb_set(config_extra, '{relatorios}', (config_extra->'relatorios') - :slug)
              WHERE jsonb_exists(config_extra -> 'relatorios', :slug)",
            ['slug' => $slug]
        );
        $db->execute('DELETE FROM relatorio_usuario_permissoes WHERE relatorio_id = :id', ['id' => $id]);
        $db->execute('DELETE FROM portais_bi WHERE relatorio_slug = :slug', ['slug' => $slug]);
        $db->execute('DELETE FROM relatorios_bi_conexoes WHERE relatorio_id = :id', ['id' => $id]);
        $db->execute('DELETE FROM relatorios_bi WHERE id = :id', ['id' => $id]);
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }

    $nginx = regenerarMapNginxRelatoriosBi();

    return [
        'sucesso'        => true,
        'bitrix_limpeza' => $bitrixLimpeza,
        'nginx_ok'       => $nginx['sucesso'],
    ];
}
