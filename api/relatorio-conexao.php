<?php
// Config de conexão de dados por relatório BI (relatorios_bi_conexoes).
// admin_interno only. Salvar sempre testa a conexão real antes de persistir —
// nunca grava credencial que não conecta.
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../helpers/RelatoriosBiHelper.php';

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

$action = $_GET['action'] ?? '';

try {
    // ── get ─────────────────────────────────────────────────────────────────
    if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $relatorioId = (int)($_GET['relatorio_id'] ?? 0);
        if (!$relatorioId) { echo json_encode(['erro' => 'relatorio_id inválido']); exit; }

        $relatorio = $db->fetchOne(
            'SELECT id, slug, nome_amigavel FROM relatorios_bi WHERE id = :id',
            ['id' => $relatorioId]
        );
        if (!$relatorio) { echo json_encode(['erro' => 'Relatório não encontrado']); exit; }

        $conexao = $db->fetchOne(
            'SELECT tipo_conexao, config, testado_em FROM relatorios_bi_conexoes WHERE relatorio_id = :id',
            ['id' => $relatorioId]
        );

        $conexaoResp = null;
        if ($conexao) {
            $cfgArr = json_decode($conexao['config'], true) ?? [];
            $conexaoResp = [
                'tipo_conexao' => $conexao['tipo_conexao'],
                'config'       => $cfgArr,
                'testado_em'   => $conexao['testado_em'],
            ];

            // Excel: complementa com contagem de linhas por tabela (best-effort — uma
            // tabela removida por fora, ou o banco indisponível, não deve quebrar a
            // tela, só aparece sem contagem).
            if ($conexao['tipo_conexao'] === 'excel') {
                $schema = $cfgArr['schema'] ?? schemaExcelRelatorio($relatorio['slug']);
                $tabelasInfo = [];
                try {
                    $excelPdo = getExcelPdo();
                    foreach (($cfgArr['tabelas'] ?? []) as $tab) {
                        $linhas = null;
                        try {
                            $linhas = (int) $excelPdo->query(
                                'SELECT COUNT(*) FROM ' . quoteIdent($schema) . '.' . quoteIdent($tab)
                            )->fetchColumn();
                        } catch (Exception $e) { /* tabela pode ter sumido por fora — segue sem contagem */ }
                        $tabelasInfo[] = ['nome' => $tab, 'linhas' => $linhas];
                    }
                } catch (Exception $e) { /* banco relatorios_bi_excel indisponível — best-effort */ }
                $conexaoResp['tabelas_info'] = $tabelasInfo;
            }
        }

        echo json_encode([
            'sucesso'        => true,
            'relatorio'      => $relatorio,
            'conexao'        => $conexaoResp,
            'tipos_habilitados' => RBI_TIPOS_CONEXAO_HABILITADOS,
            'infraestrutura'    => infraestruturaRelatorio($relatorioId, $relatorio['slug']),
        ]);
        exit;
    }

    // ── add-tabelas-excel ─────────────────────────────────────────────────────
    // Adiciona 1+ tabelas novas a um relatório Excel JÁ EXISTENTE (mesmo schema).
    // Não mexe nas tabelas já existentes — só cria as novas e acrescenta seus nomes
    // à lista em relatorios_bi_conexoes.config. Reaproveita processarUploadTabelasExcel(),
    // a mesma lógica de validação/criação usada na criação inicial (api/relatorio-criar.php).
    if ($action === 'add-tabelas-excel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $relatorioId = (int)($_POST['relatorio_id'] ?? 0);
        if (!$relatorioId) { echo json_encode(['erro' => 'relatorio_id inválido']); exit; }

        $relatorio = $db->fetchOne('SELECT id, slug FROM relatorios_bi WHERE id = :id', ['id' => $relatorioId]);
        if (!$relatorio) { echo json_encode(['erro' => 'Relatório não encontrado']); exit; }

        $conexao = $db->fetchOne('SELECT tipo_conexao, config FROM relatorios_bi_conexoes WHERE relatorio_id = :id', ['id' => $relatorioId]);
        if (!$conexao || $conexao['tipo_conexao'] !== 'excel') {
            echo json_encode(['erro' => 'Este relatório não é do tipo Excel']);
            exit;
        }

        $cfgAtual = json_decode($conexao['config'], true) ?? [];
        $schema   = $cfgAtual['schema'] ?? schemaExcelRelatorio($relatorio['slug']);
        $tabelasExistentes = $cfgAtual['tabelas'] ?? [];

        $nomesTabelas = $_POST['tabela_nome'] ?? [];
        $arquivos     = $_FILES['tabela_arquivo'] ?? null;
        if (!is_array($nomesTabelas) || !$arquivos || !is_array($arquivos['name'] ?? null)) {
            echo json_encode(['erro' => 'Pelo menos uma tabela (nome + arquivo) é obrigatória']);
            exit;
        }

        $resultado = processarUploadTabelasExcel($schema, $nomesTabelas, $arquivos, $tabelasExistentes);
        if (!$resultado['sucesso']) {
            echo json_encode(['erro' => $resultado['erro']]);
            exit;
        }

        $cfgAtual['tabelas'] = array_values(array_unique(array_merge($tabelasExistentes, $resultado['tabelas_criadas'])));
        $db->execute(
            'UPDATE relatorios_bi_conexoes SET config = :cfg::jsonb, atualizado_em = NOW() WHERE relatorio_id = :id',
            ['cfg' => json_encode($cfgAtual), 'id' => $relatorioId]
        );

        echo json_encode([
            'sucesso'           => true,
            'tabelas_criadas'   => $resultado['tabelas_criadas'],
            'colunas_ajustadas' => $resultado['colunas_ajustadas'],
        ]);
        exit;
    }

    // ── save ────────────────────────────────────────────────────────────────
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $relatorioId = (int)($body['relatorio_id'] ?? 0);
        $tipoConexao = trim($body['tipo_conexao'] ?? 'sql');
        $config      = is_array($body['config'] ?? null) ? $body['config'] : [];

        if (!$relatorioId) { echo json_encode(['erro' => 'relatorio_id inválido']); exit; }

        $relatorio = $db->fetchOne('SELECT id, slug FROM relatorios_bi WHERE id = :id', ['id' => $relatorioId]);
        if (!$relatorio) { echo json_encode(['erro' => 'Relatório não encontrado']); exit; }

        if (!in_array($tipoConexao, RBI_TIPOS_CONEXAO_HABILITADOS, true)) {
            echo json_encode(['erro' => "Tipo de conexão '{$tipoConexao}' ainda não é suportado"]);
            exit;
        }

        // Hoje só 'sql' — normaliza os campos esperados e testa antes de persistir.
        $configNormalizado = [
            'host'     => trim($config['host'] ?? ''),
            'port'     => (int)($config['port'] ?? 5432),
            'dbname'   => trim($config['dbname'] ?? ''),
            'user'     => trim($config['user'] ?? ''),
            'password' => (string)($config['password'] ?? ''),
        ];

        [$ok, $erro] = testarConexaoSql($configNormalizado);
        if (!$ok) {
            echo json_encode(['erro' => $erro]);
            exit;
        }

        $configJson = json_encode($configNormalizado);

        $existente = $db->fetchOne('SELECT id FROM relatorios_bi_conexoes WHERE relatorio_id = :id', ['id' => $relatorioId]);
        if ($existente) {
            $db->execute(
                "UPDATE relatorios_bi_conexoes
                    SET tipo_conexao = :tipo, config = :cfg::jsonb, testado_em = NOW(), atualizado_em = NOW()
                  WHERE relatorio_id = :id",
                ['tipo' => $tipoConexao, 'cfg' => $configJson, 'id' => $relatorioId]
            );
        } else {
            $db->execute(
                "INSERT INTO relatorios_bi_conexoes (relatorio_id, tipo_conexao, config, testado_em)
                 VALUES (:id, :tipo, :cfg::jsonb, NOW())",
                ['id' => $relatorioId, 'tipo' => $tipoConexao, 'cfg' => $configJson]
            );
        }

        // Gera o arquivo local que o processo Python lê — best-effort: se a pasta não existir
        // (relatório ainda não tem código Python, ex. futuro tipo webhook/excel), não bloqueia o save.
        $arquivoOk = escreverDbConfigJson($relatorio['slug'], $configNormalizado);

        echo json_encode(['sucesso' => true, 'arquivo_gravado' => $arquivoOk]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['erro' => 'Ação inválida']);

} catch (Exception $e) {
    error_log('[relatorio-conexao] ' . $e->getMessage());
    echo json_encode(['erro' => 'Erro interno']);
}
