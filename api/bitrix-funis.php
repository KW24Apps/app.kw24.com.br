<?php
/**
 * Busca funis/categorias de uma entidade no Bitrix24
 */
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
header('Content-Type: application/json');

$auth = new AuthenticationService();
if (!$auth->validateSession()) { http_response_code(401); echo json_encode(['erro'=>'Não autenticado']); exit; }

$clienteId   = (int)($_GET['cliente_id']   ?? 0);
$aplicacaoId = (int)($_GET['aplicacao_id'] ?? 0);
$entityId    = (int)($_GET['entity_id']    ?? 0);

if (!$clienteId || !$aplicacaoId || !$entityId) { echo json_encode(['erro'=>'Dados inválidos']); exit; }

try {
    $db  = Database::getInstance();
    $row = $db->fetchOne(
        "SELECT o.webhook_motor
         FROM clientes c
         LEFT JOIN organizacoes o ON o.id = c.org_id
         WHERE c.id = :c",
        ['c' => $clienteId]
    );

    if (!$row || empty($row['webhook_motor'])) {
        error_log("bitrix-funis: webhook_motor ausente para cliente_id={$clienteId} (sem org_id ou organização sem webhook_motor)");
        echo json_encode(['erro' => 'Webhook não configurado']); exit;
    }

    $webhook = rtrim($row['webhook_motor'], '/') . '/';
    $url     = $webhook . 'crm.category.list?entityTypeId=' . $entityId;
    $resp    = @file_get_contents($url);

    if ($resp === false) {
        echo json_encode(['funis' => [], 'aviso' => 'Não foi possível consultar o Bitrix24']); exit;
    }

    $data  = json_decode($resp, true);
    // Formato: result.categories[].{id, name, sort}
    $cats  = $data['result']['categories'] ?? [];

    // Ordena por sort e mapeia id (salvo no JSON) + name (exibido ao usuário)
    usort($cats, fn($a, $b) => ($a['sort'] ?? 0) - ($b['sort'] ?? 0));

    $funis = array_map(fn($c) => [
        'id'   => (int)$c['id'],
        'nome' => $c['name']
    ], $cats);

    echo json_encode(['sucesso' => true, 'funis' => $funis]);

} catch (Exception $e) { echo json_encode(['erro' => $e->getMessage()]); }
