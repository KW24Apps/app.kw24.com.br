<?php
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../services/MonitoramentoEquipeService.php';

header('Content-Type: application/json');

$auth = new AuthenticationService();
if (!$auth->validateSession()) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$user_data = $auth->getCurrentUser();
if (($user_data['perfil'] ?? '') !== 'admin_interno') {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso restrito']);
    exit;
}

try {
    $service = new MonitoramentoEquipeService();

    if (!$service->isConfigured()) {
        echo json_encode([
            'sucesso' => true,
            'periodo' => null,
            'equipe'  => [],
            'aviso'   => 'Webhook Bitrix24 não configurado',
        ]);
        exit;
    }

    echo json_encode(array_merge(['sucesso' => true], $service->getDados()));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
