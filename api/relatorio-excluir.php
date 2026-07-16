<?php
// Lixeira de relatórios BI — admin_interno only. Substitui o modelo antigo (exclusão
// imediata, só pra rascunhos) por um fluxo único de 2 passos pra QUALQUER relatório
// (publicado ou rascunho): mover pra lixeira (reversível, nunca apaga dado) e, só depois,
// excluir definitivamente (cascata destrutiva, manual ou pela purga automática de 30 dias
// em crons/lixeira-purge.php). Ver seção "Lixeira" em RELATORIOS_BI.md.
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../helpers/RelatoriosBiHelper.php';

header('Content-Type: application/json');

$auth = new AuthenticationService();
if (!$auth->validateSession()) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}
$user = $auth->getCurrentUser();
if (($user['perfil'] ?? '') !== 'admin_interno') {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso restrito a administradores']);
    exit;
}

$db = Database::getInstance();
$action = $_GET['action'] ?? '';

try {
    // ── resumo ──────────────────────────────────────────────────────────────
    // Contagens (empresas/usuários/portais) exibidas ANTES de confirmar mover pra
    // lixeira — nunca lista item a item, só o total (0 é uma resposta válida).
    if ($action === 'resumo' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['relatorio_id'] ?? 0);
        if (!$id) { echo json_encode(['erro' => 'relatorio_id inválido']); exit; }

        $relatorio = $db->fetchOne('SELECT id, slug FROM relatorios_bi WHERE id = :id', ['id' => $id]);
        if (!$relatorio) { echo json_encode(['erro' => 'Relatório não encontrado']); exit; }

        echo json_encode(['sucesso' => true, 'resumo' => resumoAtivosRelatorio($db, $relatorio['slug'], $id)]);
        exit;
    }

    // ── mover-lixeira ───────────────────────────────────────────────────────
    // Aplica a QUALQUER relatório (publicado ou rascunho) — reversível, nunca apaga dado.
    if ($action === 'mover-lixeira' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['erro' => 'id inválido']); exit; }

        $relatorio = $db->fetchOne('SELECT id, slug, nome_amigavel, lixeira_em FROM relatorios_bi WHERE id = :id', ['id' => $id]);
        if (!$relatorio) { echo json_encode(['erro' => 'Relatório não encontrado']); exit; }
        if ($relatorio['lixeira_em'] !== null) {
            echo json_encode(['erro' => 'Este relatório já está na lixeira']);
            exit;
        }

        $res = moverRelatorioParaLixeira($db, $relatorio);
        echo json_encode(array_merge(['sucesso' => true], $res));
        exit;
    }

    // ── lixeira-list ────────────────────────────────────────────────────────
    if ($action === 'lixeira-list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = $db->fetchAll(
            "SELECT id, slug, nome_amigavel, em_construcao, lixeira_em,
                    EXTRACT(DAY FROM (lixeira_em + INTERVAL '30 days' - NOW()))::int AS dias_restantes
               FROM relatorios_bi
              WHERE lixeira_em IS NOT NULL
              ORDER BY lixeira_em ASC"
        );
        foreach ($rows as &$r) {
            $r['em_construcao']   = filter_var($r['em_construcao'], FILTER_VALIDATE_BOOLEAN);
            $r['dias_restantes']  = max(0, (int)$r['dias_restantes']);
        }
        echo json_encode(['sucesso' => true, 'relatorios' => $rows]);
        exit;
    }

    // ── restaurar ───────────────────────────────────────────────────────────
    if ($action === 'restaurar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['erro' => 'id inválido']); exit; }

        $relatorio = $db->fetchOne('SELECT id, slug, lixeira_em, lixeira_portais_estado FROM relatorios_bi WHERE id = :id', ['id' => $id]);
        if (!$relatorio) { echo json_encode(['erro' => 'Relatório não encontrado']); exit; }
        if ($relatorio['lixeira_em'] === null) {
            echo json_encode(['erro' => 'Este relatório não está na lixeira']);
            exit;
        }

        $res = restaurarRelatorioDaLixeira($db, $relatorio);
        echo json_encode(array_merge(['sucesso' => true], $res));
        exit;
    }

    // ── excluir-definitivo ──────────────────────────────────────────────────
    // Cascata destrutiva completa. Só permitido a partir da lixeira (guard abaixo) —
    // confirmação forte (digitar nome/slug exato) é responsabilidade do frontend, mesmo
    // padrão já usado no antigo delete de rascunho.
    if ($action === 'excluir-definitivo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['erro' => 'id inválido']); exit; }

        $relatorio = $db->fetchOne('SELECT id, slug, nome_amigavel, lixeira_em FROM relatorios_bi WHERE id = :id', ['id' => $id]);
        if (!$relatorio) { echo json_encode(['erro' => 'Relatório não encontrado']); exit; }
        if ($relatorio['lixeira_em'] === null) {
            http_response_code(403);
            echo json_encode(['erro' => 'Só é possível excluir definitivamente relatórios que já estejam na lixeira.']);
            exit;
        }

        $res = excluirRelatorioDefinitivamente($db, $relatorio);
        if (!$res['sucesso']) { echo json_encode($res); exit; }
        echo json_encode($res);
        exit;
    }

    http_response_code(400);
    echo json_encode(['erro' => 'Ação inválida']);

} catch (Exception $e) {
    error_log('[relatorio-excluir] ' . $e->getMessage());
    echo json_encode(['erro' => 'Erro interno']);
}
