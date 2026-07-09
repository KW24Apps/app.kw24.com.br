<?php
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
header('Content-Type: application/json');
$auth = new AuthenticationService();
if (!$auth->validateSession()) { http_response_code(401); echo json_encode(['erro' => 'Não autenticado']); exit; }

$body        = json_decode(file_get_contents('php://input'), true);
$clienteId   = (int)($body['cliente_id']   ?? 0);
$aplicacaoId = (int)($body['aplicacao_id'] ?? 0);
$descricao   = trim($body['descricao']      ?? '') ?: null;

if (!$clienteId || !$aplicacaoId) { echo json_encode(['erro' => 'Dados inválidos']); exit; }

try {
    $db = Database::getInstance();

    // Recupera chave_acesso do cliente como base
    $cliente = $db->fetchOne("SELECT chave_acesso FROM clientes WHERE id = :id", ['id' => $clienteId]);
    if (!$cliente) { echo json_encode(['erro' => 'Cliente não encontrado']); exit; }
    if (!$cliente['chave_acesso']) { echo json_encode(['erro' => 'Cliente sem chave de acesso. Gere a chave antes de ativar aplicações.']); exit; }

    // Sufixo determinístico: primeiros 5 chars do MD5 da descrição em uppercase hex
    $sufixo = strtoupper(substr(md5($descricao ?? ''), 0, 5));
    $chave  = $cliente['chave_acesso'] . $sufixo;

    $db->execute(
        "INSERT INTO cliente_aplicacoes (cliente_id, aplicacao_id, ativo, chave, descricao)
         VALUES (:c, :a, TRUE, :chave, :desc)",
        [
            'c'     => $clienteId,
            'a'     => $aplicacaoId,
            'chave' => $chave,
            'desc'  => $descricao,
        ]
    );

    $caId = (int)$db->getLastInsertId('cliente_aplicacoes_id_seq');
    echo json_encode(['sucesso' => true, 'ca_id' => $caId, 'chave' => $chave]);

} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}
