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

        echo json_encode([
            'sucesso'        => true,
            'relatorio'      => $relatorio,
            'conexao'        => $conexao ? [
                'tipo_conexao' => $conexao['tipo_conexao'],
                'config'       => json_decode($conexao['config'], true) ?? [],
                'testado_em'   => $conexao['testado_em'],
            ] : null,
            'tipos_habilitados' => RBI_TIPOS_CONEXAO_HABILITADOS,
            'infraestrutura'    => infraestruturaRelatorio($relatorioId, $relatorio['slug']),
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
