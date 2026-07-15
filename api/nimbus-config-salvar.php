<?php
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
header('Content-Type: application/json');

$auth = new AuthenticationService();
if (!$auth->validateSession()) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$caId = (int)($body['ca_id'] ?? 0);
if (!$caId) {
    echo json_encode(['erro' => 'ca_id inválido']);
    exit;
}

$desc       = trim($body['descricao']    ?? '') ?: null;
$horario    = trim($body['horario']      ?? '') ?: null;
$emailTeste = trim($body['email_teste']  ?? '') ?: null;
$valor      = (isset($body['valor']) && $body['valor'] !== '' && $body['valor'] !== null)
             ? (float)$body['valor'] : null;

if ($emailTeste !== null && !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $emailTeste)) {
    echo json_encode(['erro' => 'Email Teste inválido']);
    exit;
}

// Valida e normaliza dias_semana (aceita apenas 0–6)
$dias = array_values(array_unique(array_filter(
    array_map('intval', $body['dias_semana'] ?? []),
    fn($d) => $d >= 0 && $d <= 6
)));
sort($dias);

try {
    $db = Database::getInstance();

    $ca = $db->fetchOne(
        "SELECT ca.id FROM cliente_aplicacoes ca
         JOIN aplicacoes a ON a.id = ca.aplicacao_id
         WHERE ca.id = :id AND a.slug = 'nimbus_parceiros'",
        ['id' => $caId]
    );
    if (!$ca) {
        echo json_encode(['erro' => 'Configuração não encontrada']);
        exit;
    }

    $config = json_encode(['dias_semana' => $dias, 'horario' => $horario, 'email_teste' => $emailTeste]);

    $sets   = ["descricao = :desc", "config_extra = :config", "valor = :valor"];
    $params = [
        'desc'   => $desc,
        'config' => $config,
        'valor'  => $valor,
        'id'     => $caId,
    ];

    $db->execute(
        "UPDATE cliente_aplicacoes SET " . implode(', ', $sets) . " WHERE id = :id",
        $params
    );

    echo json_encode(['sucesso' => true]);

} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}
