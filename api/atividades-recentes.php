<?php
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
header('Content-Type: application/json');

$auth = new AuthenticationService();
if (!$auth->validateSession()) { http_response_code(401); echo json_encode(['erro'=>'Não autenticado']); exit; }

try {
    $db = Database::getInstance();

    $atividades = [];

    // ─── Sincronizações de banco de dados em andamento (clientes com BancoDados ativo) ──
    // Mesma consulta/regra de api/bancodados-status.php (running_since nas últimas 4h).
    $clientesBd = $db->fetchAll("
        SELECT c.id AS cliente_id, c.nome AS cliente_nome, ca.running_since, ca.last_run_started_at
        FROM cliente_aplicacoes ca
        JOIN clientes   c ON c.id = ca.cliente_id
        JOIN aplicacoes a ON a.id = ca.aplicacao_id
        WHERE a.slug = 'BancoDados'
    ");
    foreach ($clientesBd as $cl) {
        $runningSince = $cl['running_since'];
        $isRunning    = $runningSince && (time() - strtotime($runningSince)) < 4 * 3600;
        if (!$isRunning) continue;

        $atividades[] = [
            'tipo'          => 'sync',
            'titulo'        => 'Banco de Dados',
            'cliente_nome'  => $cl['cliente_nome'],
            'status'        => 'rodando',
            'iniciado_em'   => $cl['last_run_started_at'] ?? $runningSince,
            'finalizado_em' => null,
            'sync_detail'   => null,
        ];
    }

    // ─── Sincronizações concluídas — mesma consulta agrupada de bancodados-status.php ──
    $runs = $db->fetchAll("
        SELECT
            sh.cliente_id,
            c.nome                          AS cliente_nome,
            DATE_TRUNC('hour', sh.executado_em) AS run_hora,
            MIN(sh.executado_em)            AS iniciou_em,
            MAX(sh.executado_em)            AS terminou_em,
            SUM(sh.registros)               AS total_registros,
            COUNT(*)                        AS total_tabelas,
            SUM(CASE WHEN sh.status='erro' THEN 1 ELSE 0 END) AS total_erros,
            JSON_AGG(
                JSON_BUILD_OBJECT(
                    'entidade',     sh.entidade,
                    'registros',    sh.registros,
                    'status',       sh.status,
                    'executado_em', sh.executado_em
                ) ORDER BY sh.executado_em
            ) AS entidades
        FROM sync_historico sh
        JOIN clientes c ON c.id = sh.cliente_id
        GROUP BY sh.cliente_id, c.nome, DATE_TRUNC('hour', sh.executado_em)
        ORDER BY MAX(sh.executado_em) DESC
        LIMIT 10
    ");

    // Nomes amigáveis de entidade — mesmo mapa de bancodados-status.php
    $labelMap = [
        'usuarios'  => 'Usuários',
        'pipelines' => 'Pipelines',
        'etapas'    => 'Etapas',
        'empresas'  => 'Empresas',
        'contatos'  => 'Contatos',
    ];
    $clientesConfig = $db->fetchAll("
        SELECT ca.config_extra
        FROM cliente_aplicacoes ca
        JOIN aplicacoes a ON a.id = ca.aplicacao_id
        WHERE a.slug = 'BancoDados'
    ");
    foreach ($clientesConfig as $cl) {
        $cfg = json_decode($cl['config_extra'] ?? '{}', true);
        foreach ($cfg['entities'] ?? [] as $e) {
            $key = ($e['type'] ?? 'crm') . '_' . $e['id'];
            $labelMap[$key] = $e['label'] ?? $e['table_base_name'];
        }
    }

    foreach ($runs as $r) {
        $ents = json_decode($r['entidades'], true) ?? [];
        foreach ($ents as &$e) {
            $e['entidade_label'] = $labelMap[$e['entidade']] ?? $e['entidade'];
        }
        unset($e);
        $r['entidades'] = $ents;

        $atividades[] = [
            'tipo'          => 'sync',
            'titulo'        => 'Banco de Dados',
            'cliente_nome'  => $r['cliente_nome'],
            'status'        => ((int)$r['total_erros'] > 0) ? 'erro' : 'concluido',
            'iniciado_em'   => $r['iniciou_em'],
            'finalizado_em' => $r['terminou_em'],
            'sync_detail'   => $r,
        ];
    }

    // ─── Atividades de outros apps do ecossistema (ex.: Nimbus Partners Report) ──────
    $ativLog = $db->fetchAll("
        SELECT ah.id, ah.app, ah.tipo, ah.titulo, ah.cliente_id, c.nome AS cliente_nome,
               ah.status, ah.iniciado_em, ah.finalizado_em, ah.resumo, ah.detalhe
        FROM atividades_historico ah
        LEFT JOIN clientes c ON c.id = ah.cliente_id
        ORDER BY ah.iniciado_em DESC
        LIMIT 10
    ");
    foreach ($ativLog as $a) {
        $atividades[] = [
            'tipo'          => $a['tipo'],
            'titulo'        => $a['titulo'],
            'cliente_nome'  => $a['cliente_nome'],
            'status'        => $a['status'],
            'iniciado_em'   => $a['iniciado_em'],
            'finalizado_em' => $a['finalizado_em'],
            'resumo'        => $a['resumo'],
            'detalhe'       => json_decode($a['detalhe'] ?? 'null', true),
        ];
    }

    // ─── Merge: ordena por início desc e corta em 10 no total (não por tipo) ────────
    usort($atividades, fn($a, $b) => strtotime($b['iniciado_em']) <=> strtotime($a['iniciado_em']));
    $atividades = array_slice($atividades, 0, 10);

    echo json_encode(['sucesso' => true, 'atividades' => $atividades]);

} catch (Exception $e) { echo json_encode(['erro' => $e->getMessage()]); }
