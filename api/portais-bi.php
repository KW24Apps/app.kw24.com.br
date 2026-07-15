<?php
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../services/NimbusTaxPortalSync.php';

header('Content-Type: application/json');

$auth = new AuthenticationService();
if (!$auth->validateSession()) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}
$user = $auth->getCurrentUser();
$isAdmin = ($user['perfil'] ?? '') === 'admin_interno';
$uid     = (int)($user['id'] ?? 0);
// Acesso a portais-bi é controlado por cliente_usuarios.pode_criar_portal (computado em
// index.php como $_SESSION['pode_criar_portal']), não pelo menu de permission_profiles —
// ver index.php e ARQUITETURA.md, seção Módulo Relatórios BI.
if (!$isAdmin && empty($_SESSION['pode_criar_portal'])) {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso negado']);
    exit;
}

$pdo    = Database::getInstance()->getConnection();
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

// Direct connection to bx_sync_nimbus_tax for filter lists (same PG instance, same credentials)
function getBxPdo(): PDO {
    $cfg = require __DIR__ . '/../config/config.php';
    $db  = $cfg['database'];
    $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname=bx_sync_nimbus_tax";
    return new PDO($dsn, $db['username'], $db['password'], $db['options']);
}

// Direct connection to bx_sync_contabilidade (relatorio-contabilidade filter lists)
function getCtPdo(): PDO {
    $cfg = require __DIR__ . '/../config/config.php';
    $db  = $cfg['database'];
    $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname=bx_sync_contabilidade";
    return new PDO($dsn, $db['username'], $db['password'], $db['options']);
}

try {

    // ── GET: list all portals ───────────────────────────────────────────────
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $sqlList = 'SELECT id, relatorio_slug, filter_type, filter_values, filter_labels,
                    slug, nome, embed_token, ativo,
                    ct_indicador_values, ct_indicador_labels,
                    ct_contab_values, ct_contab_labels, ct_completo,
                    to_char(created_at, \'DD/MM/YYYY\') AS created_fmt
             FROM portais_bi';
        $bind = [];
        // admin_interno vê todos os portais; demais usuários só os de relatórios
        // liberados via aplicação (relatorios_visiveis, calculado em index.php).
        if (!$isAdmin) {
            $visiveis = $_SESSION['relatorios_visiveis'] ?? [];
            if (!$visiveis) {
                echo json_encode(['sucesso' => true, 'portais' => []]);
                exit;
            }
            $ph = [];
            foreach ($visiveis as $i => $slug) { $ph[] = ':s' . $i; $bind[':s' . $i] = $slug; }
            $sqlList .= ' WHERE relatorio_slug IN (' . implode(',', $ph) . ')';
        }
        $sqlList .= ' ORDER BY created_at DESC';
        $stmtList = $pdo->prepare($sqlList);
        foreach ($bind as $k => $v) { $stmtList->bindValue($k, $v); }
        $stmtList->execute();
        $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);

        // Enriquecimento por portal: nome amigável do relatório + empresas vinculadas
        // (mesmo padrão de api/relatorios-bi.php — jsonb_exists em cliente_aplicacoes.config_extra,
        // escopado por admin/não-admin). Consultado uma vez por slug distinto, não por linha.
        $slugsPresentes = array_values(array_unique(array_column($rows, 'relatorio_slug')));
        $nomesRelatorio  = [];
        $empresasPorSlug = [];
        if ($slugsPresentes) {
            $phN = [];
            $bindN = [];
            foreach ($slugsPresentes as $i => $slug) { $phN[] = ':n' . $i; $bindN[':n' . $i] = $slug; }
            $stmtN = $pdo->prepare('SELECT slug, nome_amigavel FROM relatorios_bi WHERE slug IN (' . implode(',', $phN) . ')');
            $stmtN->execute($bindN);
            foreach ($stmtN->fetchAll(PDO::FETCH_ASSOC) as $nr) {
                $nomesRelatorio[$nr['slug']] = $nr['nome_amigavel'];
            }

            foreach ($slugsPresentes as $slug) {
                $sqlEmpresas = "SELECT DISTINCT c.id, c.nome
                                   FROM cliente_aplicacoes ca
                                   JOIN aplicacoes a ON a.id = ca.aplicacao_id AND a.slug = 'relatorios-bi'
                                   JOIN clientes c   ON c.id = ca.cliente_id
                                  WHERE ca.ativo = TRUE AND jsonb_exists(ca.config_extra -> 'relatorios', :slug)";
                $paramsEmpresas = ['slug' => $slug];
                if (!$isAdmin) {
                    $sqlEmpresas .= ' AND ca.cliente_id IN (SELECT cliente_id FROM cliente_usuarios WHERE usuario_id = :uid)';
                    $paramsEmpresas['uid'] = $uid;
                }
                $sqlEmpresas .= ' ORDER BY c.nome';
                $stmtEmpresas = $pdo->prepare($sqlEmpresas);
                $stmtEmpresas->execute($paramsEmpresas);
                $empresasPorSlug[$slug] = $stmtEmpresas->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        foreach ($rows as &$r) {
            $r['ativo']               = (bool)$r['ativo'];
            $r['filter_values']       = json_decode($r['filter_values'], true) ?? [];
            $r['filter_labels']       = json_decode($r['filter_labels'], true) ?? [];
            $r['ct_indicador_values'] = json_decode($r['ct_indicador_values'] ?? '[]', true) ?? [];
            $r['ct_indicador_labels'] = json_decode($r['ct_indicador_labels'] ?? '[]', true) ?? [];
            $r['ct_contab_values']    = json_decode($r['ct_contab_values']    ?? '[]', true) ?? [];
            $r['ct_contab_labels']    = json_decode($r['ct_contab_labels']    ?? '[]', true) ?? [];
            $r['ct_completo']         = (bool)$r['ct_completo'];
            $r['relatorio_nome']      = $nomesRelatorio[$r['relatorio_slug']] ?? $r['relatorio_slug'];
            $r['empresas']            = $empresasPorSlug[$r['relatorio_slug']] ?? [];
        }
        echo json_encode(['sucesso' => true, 'portais' => $rows]);
        exit;
    }

    // ── GET: list filter options from bx_sync_nimbus_tax ───────────────────
    if ($action === 'list-filters' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $type = $_GET['type'] ?? '';
        $bx   = getBxPdo();

        if ($type === 'parceiro') {
            $rows = $bx->query(
                "SELECT DISTINCT parceiro_comercial_id AS id, parceiro_comercial AS nome
                 FROM tbl_negocio
                 WHERE parceiro_comercial IS NOT NULL AND parceiro_comercial != ''
                   AND parceiro_comercial_id IS NOT NULL AND parceiro_comercial_id != ''
                 ORDER BY parceiro_comercial"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['sucesso' => true, 'items' => $rows]);
            exit;
        }

        if ($type === 'oportunidade') {
            $rows = $bx->query(
                "SELECT DISTINCT oportunidade_id AS id, oportunidade AS nome
                 FROM tbl_negocio
                 WHERE oportunidade IS NOT NULL AND oportunidade != ''
                   AND oportunidade_id IS NOT NULL AND oportunidade_id != ''
                 ORDER BY oportunidade"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['sucesso' => true, 'items' => $rows]);
            exit;
        }

        // relatorio-contabilidade — indicadores (parceiro_indicacao), excluindo vendas próprias
        if ($type === 'ct-indicador') {
            $ct = getCtPdo();
            $rows = $ct->query(
                "SELECT DISTINCT TRIM(parceiro_indicacao) AS id, TRIM(parceiro_indicacao) AS nome
                 FROM tbl_onboard
                 WHERE parceiro_indicacao IS NOT NULL
                   AND TRIM(parceiro_indicacao) != ''
                   AND UPPER(TRIM(parceiro_indicacao))
                       NOT IN ('FF CONTABILIDADE LTDA','CAPITON CONTABILIDADE S/S')
                 ORDER BY nome"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['sucesso' => true, 'items' => $rows]);
            exit;
        }

        // relatorio-contabilidade — contabilidades responsáveis
        if ($type === 'ct-contab') {
            $ct = getCtPdo();
            $rows = $ct->query(
                "SELECT DISTINCT TRIM(contabilidade_responsavel_operacional) AS id,
                                 TRIM(contabilidade_responsavel_operacional) AS nome
                 FROM tbl_onboard
                 WHERE contabilidade_responsavel_operacional IS NOT NULL
                   AND TRIM(contabilidade_responsavel_operacional) != ''
                 ORDER BY nome"
            )->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['sucesso' => true, 'items' => $rows]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['erro' => 'type inválido — use parceiro, oportunidade, ct-indicador ou ct-contab']);
        exit;
    }

    // ── POST: create ────────────────────────────────────────────────────────
    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $relatorioSlug = trim($body['relatorio_slug'] ?? '');
        $filterType    = trim($body['filter_type']    ?? '');
        $filterValues  = $body['filter_values'] ?? [];
        $filterLabels  = $body['filter_labels'] ?? [];
        $slug          = strtolower(trim($body['slug']  ?? ''));
        $nome          = trim($body['nome']            ?? '');
        $senha         = trim($body['senha']           ?? '');

        $isContab = ($relatorioSlug === 'relatorio-contabilidade');

        if (!$relatorioSlug || !$filterType || !$slug || !$senha) {
            echo json_encode(['erro' => 'Campos obrigatórios não preenchidos']); exit;
        }
        // Contabilidade usa o par indicador/contabilidade (dimensões próprias, ver ct_*
        // abaixo); demais relatórios usam parceiro/oportunidade.
        $filterTypesValidos = $isContab ? ['indicador', 'contabilidade'] : ['parceiro', 'oportunidade'];
        if (!in_array($filterType, $filterTypesValidos, true)) {
            echo json_encode(['erro' => 'filter_type inválido']); exit;
        }
        // filter_values pode vir vazio (contabilidade usa ct_*) ou ['__completo__']
        // (Relatório Completo — sem filtro). Validação de seleção fica no frontend.
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            echo json_encode(['erro' => 'Slug inválido — use apenas letras minúsculas, números e hifens']); exit;
        }
        if (in_array($slug, ['bi', 'sair', 'embed'], true)) {
            echo json_encode(['erro' => 'Slug reservado — escolha outro']); exit;
        }

        $senhaHash  = password_hash($senha, PASSWORD_BCRYPT);
        $embedToken = bin2hex(random_bytes(32));

        if ($isContab) {
            $ctCompleto        = (bool)($body['ct_completo'] ?? false);
            $ctIndicadorValues = array_values($body['ct_indicador_values'] ?? []);
            $ctIndicadorLabels = array_values($body['ct_indicador_labels'] ?? []);
            $ctContabValues    = array_values($body['ct_contab_values']    ?? []);
            $ctContabLabels    = array_values($body['ct_contab_labels']    ?? []);

            $stmt = $pdo->prepare(
                'INSERT INTO portais_bi
                    (relatorio_slug, filter_type, filter_values, filter_labels, slug, nome, senha_hash, embed_token,
                     ct_indicador_values, ct_indicador_labels, ct_contab_values, ct_contab_labels, ct_completo)
                 VALUES (?, ?, ?::jsonb, ?::jsonb, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?::jsonb, ?)'
            );
            $stmt->execute([
                $relatorioSlug, $filterType,
                json_encode(array_values($filterValues)), json_encode(array_values($filterLabels)),
                $slug, $nome ?: null, $senhaHash, $embedToken,
                json_encode($ctIndicadorValues), json_encode($ctIndicadorLabels),
                json_encode($ctContabValues), json_encode($ctContabLabels),
                $ctCompleto ? 'true' : 'false',
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO portais_bi
                    (relatorio_slug, filter_type, filter_values, filter_labels, slug, nome, senha_hash, embed_token)
                 VALUES (?, ?, ?::jsonb, ?::jsonb, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $relatorioSlug, $filterType,
                json_encode(array_values($filterValues)), json_encode(array_values($filterLabels)),
                $slug, $nome ?: null, $senhaHash, $embedToken,
            ]);
        }
        $id = (int)$pdo->lastInsertId('portais_bi_id_seq');

        NimbusTaxPortalSync::sync(null, [
            'relatorio_slug' => $relatorioSlug,
            'filter_type'    => $filterType,
            'filter_values'  => array_values($filterValues),
            'slug'           => $slug,
            'ativo'          => true,
        ], $senha);

        echo json_encode([
            'sucesso'     => true,
            'id'          => $id,
            'slug'        => $slug,
            'embed_token' => $embedToken,
        ]);
        exit;
    }

    // ── POST: update ────────────────────────────────────────────────────────
    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $id           = (int)($body['id']            ?? 0);
        $filterType   = trim($body['filter_type']    ?? '');
        $filterValues = $body['filter_values'] ?? [];
        $filterLabels = $body['filter_labels'] ?? [];
        $slug         = strtolower(trim($body['slug'] ?? ''));
        $nome         = trim($body['nome']            ?? '');
        $novaSenha    = trim($body['senha']           ?? '');

        if (!$id || !$filterType || !$slug) {
            echo json_encode(['erro' => 'Campos obrigatórios não preenchidos']); exit;
        }
        // relatorio_slug é imutável — busca do banco p/ saber se é contabilidade; aproveita a
        // mesma consulta para capturar o estado ANTES da atualização (sync Bitrix NimbusTax).
        $oldStmt = $pdo->prepare('SELECT relatorio_slug, filter_type, filter_values, slug, ativo FROM portais_bi WHERE id=?');
        $oldStmt->execute([$id]);
        $oldRow   = $oldStmt->fetch(PDO::FETCH_ASSOC);
        $isContab = (($oldRow['relatorio_slug'] ?? null) === 'relatorio-contabilidade');
        $oldForSync = $oldRow ? [
            'relatorio_slug' => $oldRow['relatorio_slug'],
            'filter_type'    => $oldRow['filter_type'],
            'filter_values'  => json_decode($oldRow['filter_values'], true) ?? [],
            'slug'           => $oldRow['slug'],
            'ativo'          => (bool)$oldRow['ativo'],
        ] : null;

        $filterTypesValidos = $isContab ? ['indicador', 'contabilidade'] : ['parceiro', 'oportunidade'];
        if (!in_array($filterType, $filterTypesValidos, true)) {
            echo json_encode(['erro' => 'filter_type inválido']); exit;
        }
        // filter_values pode vir vazio (contabilidade usa ct_*) ou ['__completo__']
        // (Relatório Completo — sem filtro). Validação de seleção fica no frontend.
        if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            echo json_encode(['erro' => 'Slug inválido']); exit;
        }
        if (in_array($slug, ['bi', 'sair', 'embed'], true)) {
            echo json_encode(['erro' => 'Slug reservado']); exit;
        }

        if ($novaSenha) {
            $senhaHash = password_hash($novaSenha, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                'UPDATE portais_bi
                 SET filter_type=?, filter_values=?::jsonb, filter_labels=?::jsonb,
                     slug=?, nome=?, senha_hash=?
                 WHERE id=?'
            );
            $stmt->execute([
                $filterType,
                json_encode(array_values($filterValues)), json_encode(array_values($filterLabels)),
                $slug, $nome ?: null, $senhaHash, $id,
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE portais_bi
                 SET filter_type=?, filter_values=?::jsonb, filter_labels=?::jsonb,
                     slug=?, nome=?
                 WHERE id=?'
            );
            $stmt->execute([
                $filterType,
                json_encode(array_values($filterValues)), json_encode(array_values($filterLabels)),
                $slug, $nome ?: null, $id,
            ]);
        }

        // contabilidade: atualiza também os campos ct_* (vindos do body)
        if ($isContab) {
            $stmt = $pdo->prepare(
                'UPDATE portais_bi
                 SET ct_indicador_values=?::jsonb, ct_indicador_labels=?::jsonb,
                     ct_contab_values=?::jsonb, ct_contab_labels=?::jsonb, ct_completo=?
                 WHERE id=?'
            );
            $stmt->execute([
                json_encode(array_values($body['ct_indicador_values'] ?? [])),
                json_encode(array_values($body['ct_indicador_labels'] ?? [])),
                json_encode(array_values($body['ct_contab_values']    ?? [])),
                json_encode(array_values($body['ct_contab_labels']    ?? [])),
                ((bool)($body['ct_completo'] ?? false)) ? 'true' : 'false',
                $id,
            ]);
        }

        $newForSync = [
            'relatorio_slug' => $oldForSync['relatorio_slug'] ?? '',
            'filter_type'    => $filterType,
            'filter_values'  => array_values($filterValues),
            'slug'           => $slug,
            'ativo'          => $oldForSync['ativo'] ?? true,
        ];
        NimbusTaxPortalSync::sync($oldForSync, $newForSync, $novaSenha !== '' ? $novaSenha : null);

        echo json_encode(['sucesso' => true]);
        exit;
    }

    // ── POST: toggle ────────────────────────────────────────────────────────
    if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['erro' => 'ID inválido']); exit; }

        $beforeStmt = $pdo->prepare('SELECT relatorio_slug, filter_type, filter_values, slug, ativo FROM portais_bi WHERE id=?');
        $beforeStmt->execute([$id]);
        $beforeRow = $beforeStmt->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare('UPDATE portais_bi SET ativo = NOT ativo WHERE id=?')->execute([$id]);

        $r = $pdo->prepare('SELECT ativo FROM portais_bi WHERE id=?');
        $r->execute([$id]);
        $novoAtivo = (bool)$r->fetchColumn();

        if ($beforeRow) {
            $oldForSync = [
                'relatorio_slug' => $beforeRow['relatorio_slug'],
                'filter_type'    => $beforeRow['filter_type'],
                'filter_values'  => json_decode($beforeRow['filter_values'], true) ?? [],
                'slug'           => $beforeRow['slug'],
                'ativo'          => (bool)$beforeRow['ativo'],
            ];
            $newForSync = $oldForSync;
            $newForSync['ativo'] = $novoAtivo;
            NimbusTaxPortalSync::sync($oldForSync, $newForSync, null);
        }

        echo json_encode(['sucesso' => true, 'ativo' => $novoAtivo]);
        exit;
    }

    // ── POST: delete ────────────────────────────────────────────────────────
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['erro' => 'ID inválido']); exit; }

        $beforeStmt = $pdo->prepare('SELECT relatorio_slug, filter_type, filter_values, slug, ativo FROM portais_bi WHERE id=?');
        $beforeStmt->execute([$id]);
        $beforeRow = $beforeStmt->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare('DELETE FROM portais_bi WHERE id=?')->execute([$id]);

        if ($beforeRow) {
            $oldForSync = [
                'relatorio_slug' => $beforeRow['relatorio_slug'],
                'filter_type'    => $beforeRow['filter_type'],
                'filter_values'  => json_decode($beforeRow['filter_values'], true) ?? [],
                'slug'           => $beforeRow['slug'],
                'ativo'          => (bool)$beforeRow['ativo'],
            ];
            NimbusTaxPortalSync::sync($oldForSync, null, null);
        }

        echo json_encode(['sucesso' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['erro' => 'Ação inválida']);

} catch (PDOException $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'unique') !== false || stripos($msg, 'duplicate') !== false) {
        echo json_encode(['erro' => 'Slug já existe — escolha outro']);
    } else {
        error_log('[portais-bi] ' . $msg);
        echo json_encode(['erro' => 'Erro no banco de dados: ' . $msg]);
    }
} catch (Exception $e) {
    error_log('[portais-bi] ' . $e->getMessage());
    echo json_encode(['erro' => 'Erro interno: ' . $e->getMessage()]);
}
