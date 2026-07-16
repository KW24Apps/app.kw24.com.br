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

            // Schema novo (slug já checado como inédito acima) — sem nomes existentes pra colidir.
            $schema = schemaExcelRelatorio($slug);
            $resultado = processarUploadTabelasExcel($schema, $nomesTabelas, $arquivos, []);
            if (!$resultado['sucesso']) {
                echo json_encode(['erro' => $resultado['erro']]);
                exit;
            }

            $conn = $db->getConnection();
            $conn->beginTransaction();
            try {
                $novoId = criarLinhaRelatorio($db, $slug, $nomeAmigavel);
                $configExcel = [
                    'database' => 'relatorios_bi_excel',
                    'schema'   => $schema,
                    'tabelas'  => $resultado['tabelas_criadas'],
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

            echo json_encode([
                'sucesso'           => true,
                'relatorio'         => ['id' => $novoId, 'slug' => $slug],
                'tabelas_criadas'   => $resultado['tabelas_criadas'],
                'colunas_ajustadas' => $resultado['colunas_ajustadas'], // {} se nenhuma coluna precisou de fallback
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
