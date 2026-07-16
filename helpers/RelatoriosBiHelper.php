<?php
/**
 * Funções compartilhadas do módulo Relatórios BI — usadas por api/relatorio-conexao.php
 * (editar conexão de um relatório existente), api/relatorio-criar.php (cadastrar um
 * relatório novo, Etapa 2) e api/relatorio-excluir.php (excluir rascunho). Ver
 * RELATORIOS_BI.md para o desenho completo do módulo.
 */
require_once __DIR__ . '/XlsxReader.php';

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
        try {
            $lido = XlsxReader::ler($t['tmp_path']);
        } catch (XlsxLerException $e) {
            return ['sucesso' => false, 'erro' => "Tabela '{$t['nome_original']}': " . $e->getMessage()];
        }
        $cabecalho = $lido['cabecalho'];
        $linhas    = $lido['linhas'];

        if (!$linhas) {
            return ['sucesso' => false, 'erro' => "Tabela '{$t['nome_original']}': arquivo sem linhas de dados"];
        }

        $colunas = [];
        $colunasAjustadas = []; // pra avisar o admin quais headers foram renomeados
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
            return ['sucesso' => false, 'erro' => "Tabela '{$t['nome_original']}': cabeçalho com colunas duplicadas depois de normalizado — " . implode('; ', $detalhes)];
        }

        $tipos = [];
        foreach (array_keys($colunas) as $idx) {
            $valoresColuna = array_map(fn($linha) => $linha[$idx] ?? null, $linhas);
            $tipos[] = inferirTipoColuna($valoresColuna);
        }

        $parseados[] = [
            'nome' => $t['nome'], 'colunas' => $colunas, 'tipos' => $tipos, 'linhas' => $linhas,
            'colunas_ajustadas' => $colunasAjustadas,
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
