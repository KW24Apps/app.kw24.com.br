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

$action = $_GET['action'] ?? '';
$db     = Database::getInstance();

if ($action === 'list') {
    $user    = $auth->getCurrentUser();
    $isAdmin = ($user['perfil'] ?? '') === 'admin_interno';
    $uid     = (int)($user['id'] ?? 0);

    $rows = $db->fetchAll(
        'SELECT id, slug, nome_amigavel, visivel FROM relatorios_bi ORDER BY ordem ASC'
    );

    // Enriquecimento por relatório (empresas vinculadas + usuários com acesso) para o hub
    // (public/relatorios-bi.php). admin_interno vê tudo; usuário comum só as próprias
    // empresas/seu próprio usuário — nunca dados de outros clientes/usuários.
    foreach ($rows as $i => $r) {
        $relatorioId = (int)$r['id'];
        $slug        = $r['slug'];

        $sqlEmpresas = "SELECT DISTINCT c.id, c.nome
                          FROM cliente_aplicacoes ca
                          JOIN aplicacoes a ON a.id = ca.aplicacao_id AND a.slug = 'relatorios-bi'
                          JOIN clientes c   ON c.id = ca.cliente_id
                         WHERE ca.ativo = TRUE AND jsonb_exists(ca.config_extra -> 'relatorios', :slug)";
        $paramsEmpresas = ['slug' => $slug];
        if (!$isAdmin) {
            $sqlEmpresas .= " AND ca.cliente_id IN (SELECT cliente_id FROM cliente_usuarios WHERE usuario_id = :uid)";
            $paramsEmpresas['uid'] = $uid;
        }
        $sqlEmpresas .= " ORDER BY c.nome";
        $empresas = $db->fetchAll($sqlEmpresas, $paramsEmpresas);

        $sqlUsuarios = "SELECT u.id, u.nome, bool_or(rup.pode_criar_portal) AS pode_criar_portal
                          FROM relatorio_usuario_permissoes rup
                          JOIN cliente_aplicacoes ca ON ca.id = rup.cliente_aplicacao_id AND ca.ativo = TRUE
                          JOIN cliente_usuarios cu   ON cu.cliente_id = ca.cliente_id AND cu.usuario_id = rup.usuario_id
                          JOIN usuarios u            ON u.id = rup.usuario_id
                         WHERE rup.relatorio_id = :rid AND rup.pode_ver = TRUE";
        $paramsUsuarios = ['rid' => $relatorioId];
        if (!$isAdmin) {
            $sqlUsuarios .= " AND rup.usuario_id = :uid";
            $paramsUsuarios['uid'] = $uid;
        }
        $sqlUsuarios .= " GROUP BY u.id, u.nome ORDER BY u.nome";
        $usuarios = $db->fetchAll($sqlUsuarios, $paramsUsuarios);
        foreach ($usuarios as $j => $u) {
            $usuarios[$j] = [
                'id'    => (int)$u['id'],
                'nome'  => $u['nome'],
                'nivel' => $u['pode_criar_portal'] ? 'VP' : 'V',
            ];
        }

        $rows[$i]['empresas']   = $empresas;
        $rows[$i]['usuarios']   = $usuarios;
        $rows[$i]['user_count'] = count($usuarios);
    }

    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['erro' => 'id inválido']);
        exit;
    }

    $nome    = trim($body['nome_amigavel'] ?? '');
    $visivel = isset($body['visivel']) ? (bool)$body['visivel'] : true;

    if ($nome === '') {
        http_response_code(400);
        echo json_encode(['erro' => 'nome_amigavel não pode ser vazio']);
        exit;
    }

    // slug is immutable — never updated, only set at row creation
    $db->execute(
        'UPDATE relatorios_bi SET nome_amigavel = :nome, visivel = :visivel WHERE id = :id',
        [':nome' => $nome, ':visivel' => $visivel ? 'true' : 'false', ':id' => $id]
    );

    $row = $db->fetchAll('SELECT slug FROM relatorios_bi WHERE id = :id', [':id' => $id]);
    echo json_encode(['success' => true, 'slug' => $row[0]['slug'] ?? '']);
    exit;
}

http_response_code(400);
echo json_encode(['erro' => 'action inválida']);
