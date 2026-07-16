<?php
// Excluir relatório BI — SOMENTE rascunhos (em_construcao=TRUE). admin_interno only.
// Relatório publicado (em_construcao=FALSE) NUNCA pode ser excluído por aqui — decisão
// deliberada: clientes/portais podem depender de um relatório já publicado; excluir um
// publicado é uma conversa separada e mais cuidadosa, fora de escopo aqui.
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
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['erro' => 'id inválido']); exit; }

        $relatorio = $db->fetchOne(
            'SELECT id, slug, nome_amigavel, em_construcao FROM relatorios_bi WHERE id = :id',
            ['id' => $id]
        );
        if (!$relatorio) { echo json_encode(['erro' => 'Relatório não encontrado']); exit; }

        // Guard de segurança no servidor — nunca confia só na UI escondendo o botão pra
        // relatórios publicados. Mesmo que alguém force a chamada, isso bloqueia.
        if (!filter_var($relatorio['em_construcao'], FILTER_VALIDATE_BOOLEAN)) {
            http_response_code(403);
            echo json_encode(['erro' => 'Só é possível excluir relatórios ainda em construção (rascunhos). Relatórios publicados não podem ser excluídos por aqui.']);
            exit;
        }

        $conexao = $db->fetchOne(
            'SELECT tipo_conexao, config FROM relatorios_bi_conexoes WHERE relatorio_id = :id',
            ['id' => $id]
        );

        // Excel: apaga o schema real (tabelas + dados) — SQL: nunca toca no banco externo
        // do cliente, só a linha de credencial local é removida (abaixo, junto com o resto).
        if ($conexao && $conexao['tipo_conexao'] === 'excel') {
            $cfg    = json_decode($conexao['config'], true) ?? [];
            $schema = $cfg['schema'] ?? schemaExcelRelatorio($relatorio['slug']);
            try {
                $excelPdo = getExcelPdo();
                $excelPdo->exec('DROP SCHEMA IF EXISTS ' . quoteIdent($schema) . ' CASCADE');
            } catch (Exception $e) {
                error_log('[relatorio-excluir] falha ao dropar schema Excel: ' . $e->getMessage());
                echo json_encode(['erro' => 'Falha ao remover as tabelas Excel: ' . $e->getMessage()]);
                exit;
            }
        }

        $conn = $db->getConnection();
        $conn->beginTransaction();
        try {
            // Defensivo — normalmente um rascunho não tem nenhuma dessas linhas ainda,
            // mas limpa de qualquer forma pra nunca deixar referência órfã.
            $db->execute('DELETE FROM relatorio_usuario_permissoes WHERE relatorio_id = :id', ['id' => $id]);
            $db->execute('DELETE FROM portais_bi WHERE relatorio_slug = :slug', ['slug' => $relatorio['slug']]);
            $db->execute('DELETE FROM relatorios_bi_conexoes WHERE relatorio_id = :id', ['id' => $id]);
            $db->execute('DELETE FROM relatorios_bi WHERE id = :id', ['id' => $id]);
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }

        echo json_encode(['sucesso' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['erro' => 'Ação inválida']);

} catch (Exception $e) {
    error_log('[relatorio-excluir] ' . $e->getMessage());
    echo json_encode(['erro' => 'Erro interno']);
}
