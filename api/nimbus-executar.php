<?php
set_time_limit(0);
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

try {
    $db = Database::getInstance();

    $ca = $db->fetchOne(
        "SELECT ca.chave, ca.webhook_bitrix
         FROM cliente_aplicacoes ca
         JOIN aplicacoes a ON a.id = ca.aplicacao_id
         WHERE ca.id = :id AND a.slug = 'nimbus_parceiros' AND ca.ativo = TRUE",
        ['id' => $caId]
    );
    if (!$ca) {
        echo json_encode(['erro' => 'Configuração não encontrada ou inativa']);
        exit;
    }
    if (!$ca['chave']) {
        echo json_encode(['erro' => 'Chave de acesso não configurada para esta entrada']);
        exit;
    }

    $url     = 'https://apis2.kw24.com.br/nimbus/executar';
    $payload = json_encode(['cliente' => $ca['chave']]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,    // job roda em background no apis2, só aguardamos a confirmação de início
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $resp     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        echo json_encode(['erro' => 'Erro de conexão com apis2: ' . $curlErr]);
        exit;
    }

    $decoded = json_decode($resp, true);

    if ($httpCode >= 200 && $httpCode < 300 && ($decoded['success'] ?? false)) {
        echo json_encode([
            'sucesso' => true,
            'message' => $decoded['message'] ?? 'Job executado com sucesso',
        ]);
    } else {
        echo json_encode([
            'erro' => $decoded['error'] ?? ('Erro no servidor APIs2 (HTTP ' . $httpCode . ')'),
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}
