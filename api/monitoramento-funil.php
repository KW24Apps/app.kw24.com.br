<?php
/**
 * Endpoint somente-leitura para consumo de máquina (ex.: assistente/Secretária externa).
 * Autenticação por token estático — NÃO depende de sessão PHP. Reusa o mesmo token do painel
 * Equipe (configuracoes_sistema.monitoramento_equipe_token) — mesmo consumidor autorizado,
 * mesmo nível de acesso, para toda a tela Monitoramento KW24.
 */
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../dao/ConfiguracaoDAO.php';
require_once __DIR__ . '/../services/MonitoramentoFunilService.php';

header('Content-Type: application/json');

$dao           = new ConfiguracaoDAO();
$tokenEsperado = $dao->get('monitoramento_equipe_token') ?? '';
$tokenRecebido = $_SERVER['HTTP_X_PAINEL_TOKEN'] ?? '';

if ($tokenEsperado === '' || $tokenRecebido === '' || !hash_equals($tokenEsperado, $tokenRecebido)) {
    http_response_code(401);
    echo json_encode(['erro' => 'Token inválido ou ausente']);
    exit;
}

try {
    $service = new MonitoramentoFunilService();

    if (!$service->isConfigured()) {
        http_response_code(503);
        echo json_encode(['erro' => 'Webhook Bitrix24 não configurado']);
        exit;
    }

    echo json_encode(array_merge(['sucesso' => true], $service->getDados()));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
