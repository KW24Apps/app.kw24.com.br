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

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['erro' => 'ID inválido']);
    exit;
}

$db = Database::getInstance();

$cliente = $db->fetchOne(
    "SELECT c.*, o.nome AS org_nome
     FROM clientes c
     LEFT JOIN organizacoes o ON o.id = c.org_id
     WHERE c.id = :id",
    ['id' => $id]
);
if (!$cliente) {
    echo json_encode(['erro' => 'Cliente não encontrado']);
    exit;
}

$aplicacoes = $db->fetchAll("
    SELECT a.id          AS aplicacao_id,
           a.slug,
           a.nome,
           a.descricao   AS app_descricao,
           ca.id         AS ca_id,
           ca.ativo,
           ca.config_extra,
           ca.valor,
           ca.chave,
           ca.descricao,
           ca.created_at
    FROM cliente_aplicacoes ca
    JOIN aplicacoes a ON a.id = ca.aplicacao_id
    WHERE ca.cliente_id = :id
    ORDER BY ca.ativo DESC, a.nome ASC
", ['id' => $id]);

echo json_encode([
    'cliente'    => $cliente,
    'aplicacoes' => $aplicacoes
]);
