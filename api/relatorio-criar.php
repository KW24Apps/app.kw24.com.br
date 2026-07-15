<?php
// Cadastro de relatório novo (Etapa 2 do self-service de Relatórios BI).
// admin_interno only. Cria a linha em relatorios_bi (sempre em_construcao=TRUE,
// nunca detectado automaticamente) + a conexão de dados:
//   - tipo 'sql'   -> relatorios_bi_conexoes (mesmos campos/teste da aba Conexão)
//   - tipo 'excel' -> 1+ tabelas reais criadas num schema dedicado ao relatório,
//                     dentro do banco relatorios_bi_excel (isolamento por
//                     RELATÓRIO, não por cliente/empresa)
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../helpers/RelatoriosBiHelper.php';
require_once __DIR__ . '/../helpers/XlsxReader.php';

header('Content-Type: application/json');

$auth = new AuthenticationService();
if (!$auth->validateSession()) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}
$user = $auth->getCurrentUser();
if (($user['perfil'] ?? '') !== 'admin_interno') {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso restrito a administradores']);
    exit;
}

$db = Database::getInstance();

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

/**
 * Insere a linha em relatorios_bi (sempre visivel=TRUE, em_construcao=TRUE) e
 * devolve o id gerado. ordem = próxima disponível (mesmo padrão de "adicionar
 * ao fim da lista" usado em outras telas do painel).
 */
function criarLinhaRelatorio(Database $db, string $slug, string $nomeAmigavel): int {
    $prox = $db->fetchOne('SELECT COALESCE(MAX(ordem), -1) + 1 AS prox FROM relatorios_bi');
    $ordem = (int)($prox['prox'] ?? 0);
    $db->execute(
        "INSERT INTO relatorios_bi (slug, nome_amigavel, visivel, ordem, em_construcao)
         VALUES (:slug, :nome, TRUE, :ordem, TRUE)",
        ['slug' => $slug, 'nome' => $nomeAmigavel, 'ordem' => $ordem]
    );
    return (int) $db->getLastInsertId('relatorios_bi_id_seq');
}

$action = $_GET['action'] ?? '';

try {
    // ── check-slug ──────────────────────────────────────────────────────────
    if ($action === 'check-slug' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $slug = trim($_GET['slug'] ?? '');
        if ($slug === '') { echo json_encode(['disponivel' => false, 'erro' => 'Slug vazio']); exit; }
        if (!slugFormatoValido($slug)) {
            echo json_encode(['disponivel' => false, 'erro' => 'Formato inválido — use letras minúsculas, números e hífen']);
            exit;
        }
        $existe = slugJaExiste($db, $slug);
        echo json_encode(['disponivel' => !$existe, 'erro' => $existe ? 'Esse slug já está em uso' : null]);
        exit;
    }

    // ── create ──────────────────────────────────────────────────────────────
    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $nomeAmigavel = trim($_POST['nome_amigavel'] ?? '');
        $slug         = trim($_POST['slug'] ?? '');
        $tipoConexao  = trim($_POST['tipo_conexao'] ?? '');

        if ($nomeAmigavel === '') { echo json_encode(['erro' => 'Nome amigável é obrigatório']); exit; }
        if (!slugFormatoValido($slug)) { echo json_encode(['erro' => 'Slug em formato inválido']); exit; }
        if (slugJaExiste($db, $slug)) { echo json_encode(['erro' => 'Esse slug já está em uso por outro relatório']); exit; }
        if (!in_array($tipoConexao, RBI_TIPOS_CONEXAO_HABILITADOS, true)) {
            echo json_encode(['erro' => "Tipo de conexão '{$tipoConexao}' ainda não é suportado"]);
            exit;
        }

        // ── SQL ────────────────────────────────────────────────────────────
        if ($tipoConexao === 'sql') {
            $configNormalizado = [
                'host'     => trim($_POST['host'] ?? ''),
                'port'     => (int)($_POST['porta'] ?? 5432),
                'dbname'   => trim($_POST['banco'] ?? ''),
                'user'     => trim($_POST['usuario'] ?? ''),
                'password' => (string)($_POST['senha'] ?? ''),
            ];
            [$ok, $erro] = testarConexaoSql($configNormalizado);
            if (!$ok) { echo json_encode(['erro' => $erro]); exit; }

            $conn = $db->getConnection();
            $conn->beginTransaction();
            try {
                $novoId = criarLinhaRelatorio($db, $slug, $nomeAmigavel);
                $db->execute(
                    "INSERT INTO relatorios_bi_conexoes (relatorio_id, tipo_conexao, config, testado_em)
                     VALUES (:id, 'sql', :cfg::jsonb, NOW())",
                    ['id' => $novoId, 'cfg' => json_encode($configNormalizado)]
                );
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }

            // Best-effort — a pasta relatorios-bi/{slug}/ normalmente não existe ainda
            // (nenhum dev construiu o app Python pra este relatório novo).
            escreverDbConfigJson($slug, $configNormalizado);

            echo json_encode(['sucesso' => true, 'relatorio' => ['id' => $novoId, 'slug' => $slug]]);
            exit;
        }

        // ── Excel ──────────────────────────────────────────────────────────
        if ($tipoConexao === 'excel') {
            $nomesTabelas = $_POST['tabela_nome'] ?? [];
            $arquivos     = $_FILES['tabela_arquivo'] ?? null;
            if (!is_array($nomesTabelas) || !$arquivos || !is_array($arquivos['name'] ?? null)) {
                echo json_encode(['erro' => 'Pelo menos uma tabela (nome + arquivo) é obrigatória']);
                exit;
            }

            // ── Validação de linhas (nome + upload) ─────────────────────────
            $tabelasValidas = [];
            $total = count($arquivos['name']);
            for ($i = 0; $i < $total; $i++) {
                $nomeBruto  = trim($nomesTabelas[$i] ?? '');
                $erroUpload = $arquivos['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                if ($nomeBruto === '' && $erroUpload === UPLOAD_ERR_NO_FILE) continue; // linha vazia da UI

                if ($nomeBruto === '') { echo json_encode(['erro' => 'Toda tabela precisa de um nome']); exit; }
                if ($erroUpload !== UPLOAD_ERR_OK) {
                    echo json_encode(['erro' => "Tabela '{$nomeBruto}': falha no upload do arquivo"]);
                    exit;
                }

                // Nome só com símbolos (ex.: "###") cai no fallback posicional em vez de
                // bloquear — mesma lógica do cabeçalho de coluna, ver sanitizarIdentificadorComFallback().
                [$nomeTabela, ] = sanitizarIdentificadorComFallback($nomeBruto, 'tabela', $i + 1);

                $tabelasValidas[] = ['nome' => $nomeTabela, 'nome_original' => $nomeBruto, 'tmp_path' => $arquivos['tmp_name'][$i]];
            }

            if (!$tabelasValidas) { echo json_encode(['erro' => 'Pelo menos uma tabela (nome + arquivo) é obrigatória']); exit; }

            $nomesSanit = array_column($tabelasValidas, 'nome');
            if (count($nomesSanit) !== count(array_unique($nomesSanit))) {
                $porNomeTabela = [];
                foreach ($tabelasValidas as $t) $porNomeTabela[$t['nome']][] = "\"{$t['nome_original']}\"";
                $detalhes = [];
                foreach ($porNomeTabela as $nome => $originais) {
                    if (count($originais) > 1) $detalhes[] = "'{$nome}' (" . implode(', ', $originais) . ")";
                }
                echo json_encode(['erro' => 'Duas ou mais tabelas resultaram no mesmo nome depois de normalizado: ' . implode('; ', $detalhes) . ' — renomeie para diferenciar']);
                exit;
            }

            // ── Parse + validação de TODOS os arquivos antes de criar qualquer coisa no banco ──
            $parseados = [];
            foreach ($tabelasValidas as $t) {
                try {
                    $lido = XlsxReader::ler($t['tmp_path']);
                } catch (XlsxLerException $e) {
                    echo json_encode(['erro' => "Tabela '{$t['nome_original']}': " . $e->getMessage()]);
                    exit;
                }
                $cabecalho = $lido['cabecalho'];
                $linhas    = $lido['linhas'];

                if (!$linhas) {
                    echo json_encode(['erro' => "Tabela '{$t['nome_original']}': arquivo sem linhas de dados"]);
                    exit;
                }

                // Coluna cujo cabeçalho não sobra nenhum caractere alfanumérico aproveitável
                // (ex.: "#", comum como coluna de ID em exports de terceiros) cai num fallback
                // posicional ("coluna_N") em vez de bloquear a tabela inteira — achado real
                // testando com export do Bitrix Contact Center (Gabriel, jul/2026).
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
                    // Nomeia exatamente quais colunas colidiram (posição + cabeçalho original) —
                    // sem isso o admin não tem como saber qual das N colunas causou o problema.
                    $porNome = [];
                    foreach ($colunas as $idx => $nome) $porNome[$nome][] = ($idx + 1) . " (\"{$cabecalho[$idx]}\")";
                    $detalhes = [];
                    foreach ($porNome as $nome => $posicoes) {
                        if (count($posicoes) > 1) $detalhes[] = "'{$nome}' nas colunas " . implode(', ', $posicoes);
                    }
                    echo json_encode(['erro' => "Tabela '{$t['nome_original']}': cabeçalho com colunas duplicadas depois de normalizado — " . implode('; ', $detalhes)]);
                    exit;
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
            $schema = 'rbi_' . str_replace('-', '_', $slug);
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
                    // DROP defensivo: o slug é novo (checado acima), então a tabela não deveria
                    // existir — evita erro caso o schema já tenha sido criado numa tentativa anterior.
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
                error_log('[relatorio-criar] falha ao criar tabelas Excel: ' . $e->getMessage());
                echo json_encode(['erro' => 'Falha ao criar as tabelas: ' . $e->getMessage()]);
                exit;
            }

            $conn = $db->getConnection();
            $conn->beginTransaction();
            try {
                $novoId = criarLinhaRelatorio($db, $slug, $nomeAmigavel);
                $configExcel = [
                    'database' => 'relatorios_bi_excel',
                    'schema'   => $schema,
                    'tabelas'  => array_column($parseados, 'nome'),
                ];
                $db->execute(
                    "INSERT INTO relatorios_bi_conexoes (relatorio_id, tipo_conexao, config, testado_em)
                     VALUES (:id, 'excel', :cfg::jsonb, NOW())",
                    ['id' => $novoId, 'cfg' => json_encode($configExcel)]
                );
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollBack();
                // Schema/tabelas Excel já foram criados e persistem (dado real, não vale a
                // pena descartar) — mas sem uma linha em relatorios_bi correspondente. Caso
                // raro (falha aqui só se o INSERT em kwconfig falhar depois do Excel ter dado certo).
                throw $e;
            }

            $ajustesPorTabela = [];
            foreach ($parseados as $tab) {
                if ($tab['colunas_ajustadas']) $ajustesPorTabela[$tab['nome']] = $tab['colunas_ajustadas'];
            }

            echo json_encode([
                'sucesso'          => true,
                'relatorio'        => ['id' => $novoId, 'slug' => $slug],
                'tabelas_criadas'  => array_column($parseados, 'nome'),
                'colunas_ajustadas' => $ajustesPorTabela, // {} se nenhuma coluna precisou de fallback
            ]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['erro' => 'Ação inválida']);

} catch (Exception $e) {
    error_log('[relatorio-criar] ' . $e->getMessage());
    echo json_encode(['erro' => 'Erro interno']);
}
