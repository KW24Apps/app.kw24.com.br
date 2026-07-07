<?php
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
header('Content-Type: application/json');

$auth = new AuthenticationService();
if (!$auth->validateSession()) { http_response_code(401); echo json_encode(['erro'=>'Não autenticado']); exit; }

$body        = json_decode(file_get_contents('php://input'), true);
$clienteId   = (int)($body['cliente_id']   ?? 0);
$aplicacaoId = (int)($body['aplicacao_id'] ?? 0);
$config      = $body['config_extra']        ?? [];

if (!$clienteId || !$aplicacaoId) { echo json_encode(['erro'=>'Dados inválidos']); exit; }

try {
    $db = Database::getInstance();

    // Validar unicidade do db_name entre clientes diferentes
    $dbName = $config['db_name'] ?? null;
    if ($dbName) {
        $conflito = $db->fetchOne(
            "SELECT c.nome
             FROM cliente_aplicacoes ca
             JOIN clientes c ON c.id = ca.cliente_id
             JOIN aplicacoes a ON a.id = ca.aplicacao_id
             WHERE a.slug = 'BancoDados'
               AND ca.cliente_id != :cid
               AND ca.config_extra->>'db_name' = :db_name
             LIMIT 1",
            ['cid' => $clienteId, 'db_name' => $dbName]
        );
        if ($conflito) {
            echo json_encode(['erro' => "O nome de banco \"bx_sync_{$dbName}\" já está em uso pelo cliente \"{$conflito['nome']}\". Escolha outro nome."]);
            exit;
        }
    }
    // Webhook: só atualiza se fornecido e não-vazio (vazio = preservar valor atual — mesma regra de cliente-app-atualizar.php)
    $sets   = ["config_extra = :config", "valor = :valor"];
    $params = [
        'config' => json_encode($config),
        'valor'  => (isset($body['valor']) && $body['valor'] !== '' && $body['valor'] !== null)
                      ? (float)$body['valor'] : null,
        'c'      => $clienteId,
        'a'      => $aplicacaoId
    ];
    if (!empty($body['webhook_bitrix'])) {
        $sets[]            = "webhook_bitrix = :webhook";
        $params['webhook'] = $body['webhook_bitrix'];
    }
    $db->execute(
        "UPDATE cliente_aplicacoes SET " . implode(', ', $sets) . " WHERE cliente_id = :c AND aplicacao_id = :a",
        $params
    );
    echo json_encode(['sucesso' => true]);
} catch (Exception $e) { echo json_encode(['erro' => $e->getMessage()]); }
