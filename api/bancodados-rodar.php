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

if (!$clienteId || !$aplicacaoId) { echo json_encode(['erro'=>'Dados inválidos']); exit; }

try {
    $db  = Database::getInstance();
    $row = $db->fetchOne(
        "SELECT ca.config_extra
         FROM cliente_aplicacoes ca
         JOIN aplicacoes a ON a.id = ca.aplicacao_id
         WHERE ca.cliente_id = :c AND ca.aplicacao_id = :a AND a.slug = 'BancoDados'",
        ['c' => $clienteId, 'a' => $aplicacaoId]
    );

    if (!$row) { echo json_encode(['erro' => 'Configuração não encontrada']); exit; }

    $config = json_decode($row['config_extra'] ?? '{}', true);
    $dbName = $config['db_name'] ?? null;

    if (!$dbName) { echo json_encode(['erro' => 'Nome do banco (db_name) não configurado']); exit; }

    // Reserva atômica do lock antes de disparar o processo em background — evita a corrida em que
    // dois cliques quase simultâneos passariam pela checagem antes do main.php marcar running_since.
    // Só reivindica se não houver sync em andamento (running_since nulo ou expirado há mais de 4h).
    $claim = $db->execute(
        "UPDATE cliente_aplicacoes
         SET running_since = NOW(), last_run_started_at = NOW()
         WHERE cliente_id = :c AND aplicacao_id = :a
           AND (running_since IS NULL OR running_since < NOW() - INTERVAL '4 hours')",
        ['c' => $clienteId, 'a' => $aplicacaoId]
    );

    if ($claim->rowCount() === 0) {
        http_response_code(409);
        echo json_encode(['erro' => 'Sincronização já está em andamento para este cliente.']);
        exit;
    }

    // PHP CLI — busca binário correto (não usa PHP_BINARY que aponta para FPM)
    $phpBin = '/usr/bin/php8.1';
    if (!file_exists($phpBin)) $phpBin = trim(shell_exec('which php') ?: '/usr/bin/php');
    $script  = '/var/www/bancodados.kw24.com.br/BitrixDataSync/main.php';
    $logFile = '/tmp/bitrix_sync_' . preg_replace('/[^a-z0-9_]/', '', $dbName) . '.log';

    $cmd = "{$phpBin} {$script} --cliente=" . escapeshellarg($dbName) . " >> {$logFile} 2>&1 &";
    shell_exec($cmd);

    echo json_encode([
        'sucesso'  => true,
        'mensagem' => "Sincronização iniciada em background.",
        'log_file' => $logFile
    ]);

} catch (Exception $e) { echo json_encode(['erro' => $e->getMessage()]); }
