<?php
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
header('Content-Type: application/json');

$auth = new AuthenticationService();
if (!$auth->validateSession()) { http_response_code(401); echo json_encode(['erro'=>'Não autenticado']); exit; }

$body      = json_decode(file_get_contents('php://input'), true);
$caId      = (int)($body['ca_id']      ?? 0);
$clienteId = (int)($body['cliente_id'] ?? 0);

if (!$caId || !$clienteId) { echo json_encode(['erro'=>'Dados inválidos']); exit; }

try {
    $db = Database::getInstance();
    $sets   = ["valor = :v"];
    $params = [
        'ca_id' => $caId,
        'c'     => $clienteId,
        'v'     => (isset($body['valor']) && $body['valor'] !== '' && $body['valor'] !== null)
                    ? (float)$body['valor'] : null,
    ];
    if (isset($body['descricao']) && trim($body['descricao']) !== '') {
        $sets[]          = "descricao = :desc";
        $params['desc']  = trim($body['descricao']);
    }
    $db->execute(
        "UPDATE cliente_aplicacoes SET " . implode(', ', $sets) . " WHERE id = :ca_id AND cliente_id = :c",
        $params
    );
    echo json_encode(['sucesso' => true]);
} catch (Exception $e) { echo json_encode(['erro' => $e->getMessage()]); }
