<?php
/**
 * Busca entidades disponíveis no Bitrix24 do cliente
 * Retorna entidades padrão + SPAs customizados via crm.type.list
 */
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
header('Content-Type: application/json');

$auth = new AuthenticationService();
if (!$auth->validateSession()) { http_response_code(401); echo json_encode(['erro'=>'Não autenticado']); exit; }

$clienteId   = (int)($_GET['cliente_id']   ?? 0);
$aplicacaoId = (int)($_GET['aplicacao_id'] ?? 0);

if (!$clienteId || !$aplicacaoId) { echo json_encode(['erro'=>'Dados inválidos']); exit; }

try {
    $db      = Database::getInstance();
    $row     = $db->fetchOne(
        "SELECT o.webhook_motor
         FROM clientes c
         LEFT JOIN organizacoes o ON o.id = c.org_id
         WHERE c.id = :c",
        ['c' => $clienteId]
    );

    if (!$row || empty($row['webhook_motor'])) {
        error_log("bitrix-entidades: webhook_motor ausente para cliente_id={$clienteId} (sem org_id ou organização sem webhook_motor)");
        echo json_encode(['erro' => 'Webhook não configurado para esta aplicação']); exit;
    }

    $webhook = rtrim($row['webhook_motor'], '/') . '/';

    // Entidades padrão fixas do Bitrix24
    $entidades = [
        ['id' => 1,  'title' => 'Leads'],
        ['id' => 2,  'title' => 'Negócios'],
        ['id' => 3,  'title' => 'Contatos'],
        ['id' => 4,  'title' => 'Empresas'],
        ['id' => 31, 'title' => 'Faturas'],
    ];

    // Busca SPAs customizados via crm.type.list
    // Retorno: result.types[].{entityTypeId, title}
    $resp = @file_get_contents($webhook . 'crm.type.list');
    if ($resp !== false) {
        $data  = json_decode($resp, true);
        $types = $data['result']['types'] ?? [];
        foreach ($types as $t) {
            $entidades[] = [
                'id'    => (int)$t['entityTypeId'],
                'title' => $t['title']
            ];
        }
    }

    echo json_encode(['sucesso' => true, 'entidades' => $entidades]);

} catch (Exception $e) { echo json_encode(['erro' => $e->getMessage()]); }
