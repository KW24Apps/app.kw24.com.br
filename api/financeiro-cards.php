<?php
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../dao/ConfiguracaoDAO.php';
require_once __DIR__ . '/../services/BitrixService.php';

header('Content-Type: application/json');

$auth = new AuthenticationService();
if (!$auth->validateSession()) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

// ── Campos cat/210/ ───────────────────────────────────────────────────────────
define('BX_ENTITY_TYPE', 1054);
define('BX_CAT_FINANC',  210);
define('F_CONTROLE',    'ufCrm41_1742082168');
define('F_MIN_SUPORTE', 'ufCrm41_1767900752'); // Minutos Suporte (gravado por run())
define('F_MIN_DEV',     'ufCrm41_1767900780'); // Minutos Dev     (gravado por run())
// Tabela principal
define('FC_VSUP',        'ufCrm41_1767901194'); // Valor Total Suporte
define('FC_VDEV',        'ufCrm41_1767901128'); // Valor Total Dev
define('FC_VINFRA',      'ufCrm41_1770316473'); // Valor Total Infra
// Detalhe Suporte
define('FC_SH_CONT',     'ufCrm41_1742071347'); // Horas contrato Sup (÷60)
define('FC_SH_EXTRA',    'ufCrm41_1767900828'); // Horas extra Sup    (÷60)
define('FC_SVH',         'ufCrm41_1767928096'); // Valor/hora Sup
define('FC_SV_CONT',     'ufCrm41_1773474286'); // Valor contratado Sup
define('FC_SV_EXTRA',    'ufCrm41_1767901210'); // Valor extra Sup
// Detalhe Dev
define('FC_DH_CONT',     'ufCrm41_1742071291'); // Horas contrato Dev (÷60)
define('FC_DH_EXTRA',    'ufCrm41_1767900863'); // Horas extra Dev    (÷60)
define('FC_DVH',         'ufCrm41_1767928073'); // Valor/hora Dev
define('FC_DV_CONT',     'ufCrm41_1773474261'); // Valor contratado Dev
define('FC_DV_EXTRA',    'ufCrm41_1767901161'); // Valor extra Dev
// Detalhe Infra
define('FC_RDP',         'ufCrm41_1770316177');
define('FC_VM',          'ufCrm41_1770316215');
define('FC_DADOS',       'ufCrm41_1770316226');
define('FC_SIS',         'ufCrm41_1770316237');
define('FC_HOSP',        'ufCrm41_1770316252');
define('FC_GESTAO',      'ufCrm41_1770316270');
define('FC_CNPJ',        'ufCrm41_1770316647');
define('FC_CLICK',       'ufCrm41_1770316694');
define('FC_RFB',         'ufCrm41_1773452068');
define('FC_ZAP',         'ufCrm41_1773452083');
define('FC_QTD_RDP',     'ufCrm41_1773467142');
define('FC_DOMINIOS',    'ufCrm41_1773467121');

try {
    $dao       = new ConfiguracaoDAO();
    $diaInicio = max(1, min(28, (int)($dao->get('financeiro_dia_inicio') ?? 27)));

    // ── Lista dos últimos 6 períodos para o seletor ───────────────────────────
    if (!empty($_GET['periodos'])) {
        $hoje = new DateTime();
        $dia  = (int)$hoje->format('d');
        $mes  = (int)$hoje->format('m');
        $ano  = (int)$hoje->format('Y');

        if ($dia >= $diaInicio) {
            $inicioMes = $mes;
            $inicioAno = $ano;
        } else {
            $inicioMes = $mes - 1;
            $inicioAno = $ano;
            if ($inicioMes < 1) { $inicioMes = 12; $inicioAno--; }
        }

        $baseInicio = new DateTime(sprintf('%04d-%02d-01', $inicioAno, $inicioMes));

        $periodos = [];
        for ($i = 0; $i < 6; $i++) {
            $dt = clone $baseInicio;
            $dt->modify("-{$i} months");
            $p = calcularPeriodoDeInicio((int)$dt->format('Y'), (int)$dt->format('m'), $diaInicio);
            $p['atual'] = ($i === 0);
            $periodos[] = $p;
        }

        echo json_encode(['sucesso' => true, 'periodos' => $periodos]);
        exit;
    }

    // Referência explícita (?ref=MM/YYYY) ou período atual
    $refParam = $_GET['ref'] ?? null;
    $periodo  = ($refParam && preg_match('/^\d{2}\/\d{4}$/', $refParam))
        ? calcularPeriodoPorReferencia($refParam, $diaInicio)
        : calcularPeriodoAtual($diaInicio);

    // Extrai base URL do Bitrix para links externos
    $webhookUrl = $dao->get('financeiro_webhook_bitrix') ?? '';
    preg_match('#^(https?://[^/]+)#', $webhookUrl, $mUrl);
    $bitrixBase = $mUrl[1] ?? '';

    $bitrix = new BitrixService();

    if (!$bitrix->isConfigured()) {
        echo json_encode([
            'sucesso'    => true,
            'periodo'    => $periodo,
            'cards'      => [],
            'kpi'        => ['totalFatura' => 0, 'totalSuporte' => 0, 'totalDev' => 0, 'totalInfra' => 0],
            'bitrixBase' => '',
            'aviso'      => 'Webhook Bitrix24 não configurado',
        ]);
        exit;
    }

    // ── Modo histórico: últimos 6 meses para o gráfico ────────────────────────
    if (!empty($_GET['history'])) {
        $history = [];
        for ($i = 5; $i >= 0; $i--) {
            $dt = new DateTime('first day of this month');
            $dt->modify("-{$i} months");
            $ref = sprintf('%02d/%04d', (int)$dt->format('m'), (int)$dt->format('Y'));

            $hCards = $bitrix->listItems(BX_ENTITY_TYPE, [
                'categoryId' => BX_CAT_FINANC,
                F_CONTROLE   => $ref,
            ], ['opportunity'], 0);

            $total = 0.0;
            foreach ($hCards as $hc) $total += (float)($hc['opportunity'] ?? 0);
            $history[] = ['mes' => $ref, 'total' => round($total, 2)];
        }
        echo json_encode(['sucesso' => true, 'history' => $history]);
        exit;
    }

    // ── Cards do período selecionado ───────────────────────────────────────────
    $rawCards = $bitrix->listItems(BX_ENTITY_TYPE, [
        'categoryId' => BX_CAT_FINANC,
        F_CONTROLE   => $periodo['referencia'],
    ], [
        'id', 'title', 'stageId', 'companyId', 'opportunity',
        F_CONTROLE, F_MIN_SUPORTE, F_MIN_DEV,
        FC_VSUP, FC_VDEV, FC_VINFRA,
        FC_SH_CONT, FC_SH_EXTRA, FC_SVH, FC_SV_CONT, FC_SV_EXTRA,
        FC_DH_CONT, FC_DH_EXTRA, FC_DVH, FC_DV_CONT, FC_DV_EXTRA,
        FC_RDP, FC_VM, FC_DADOS, FC_SIS, FC_HOSP, FC_GESTAO,
        FC_CNPJ, FC_CLICK, FC_RFB, FC_ZAP,
        FC_QTD_RDP, FC_DOMINIOS,
    ], 0);

    $prefix = "Fatura Referente a {$periodo['referencia']} - ";
    $kpi    = ['totalFatura' => 0.0, 'totalSuporte' => 0.0, 'totalDev' => 0.0, 'totalInfra' => 0.0];
    $cards  = [];

    foreach ($rawCards as $c) {
        $title   = $c['title'] ?? '';
        $empresa = str_starts_with($title, $prefix) ? substr($title, strlen($prefix)) : $title;
        // Remove sufixo " - NNNNN" que algumas empresas têm no Bitrix
        $empresa = preg_replace('/ - \d+$/', '', $empresa);

        $opportunity = (float)($c['opportunity'] ?? 0);
        $valSuporte  = parseMoney($c[FC_VSUP]   ?? null);
        $valDev      = parseMoney($c[FC_VDEV]   ?? null);
        $valInfra    = parseMoney($c[FC_VINFRA] ?? null);

        $kpi['totalFatura']  += $opportunity;
        $kpi['totalSuporte'] += $valSuporte;
        $kpi['totalDev']     += $valDev;
        $kpi['totalInfra']   += $valInfra;

        $dominiosRaw = $c[FC_DOMINIOS] ?? '';
        $dominios = is_array($dominiosRaw) ? implode(', ', $dominiosRaw) : (string)$dominiosRaw;

        $cards[] = [
            'id'          => (int)$c['id'],
            'empresa'     => $empresa,
            'stageId'     => $c['stageId'] ?? '',
            'opportunity' => $opportunity,
            // Tabela principal
            'valSuporte'  => $valSuporte,
            'valDev'      => $valDev,
            'valInfra'    => $valInfra,
            // Detalhe Suporte (valores em minutos; frontend divide por 60)
            'supHCont'    => (int)($c[FC_SH_CONT]  ?? 0),
            'supHGasto'   => (int)($c[F_MIN_SUPORTE] ?? 0),
            'supHExtra'   => (int)($c[FC_SH_EXTRA]  ?? 0),
            'supVH'       => parseMoney($c[FC_SVH]      ?? null),
            'supVCont'    => parseMoney($c[FC_SV_CONT]  ?? null),
            'supVExtra'   => parseMoney($c[FC_SV_EXTRA] ?? null),
            // Detalhe Dev
            'devHCont'    => (int)($c[FC_DH_CONT]  ?? 0),
            'devHGasto'   => (int)($c[F_MIN_DEV]    ?? 0),
            'devHExtra'   => (int)($c[FC_DH_EXTRA]  ?? 0),
            'devVH'       => parseMoney($c[FC_DVH]      ?? null),
            'devVCont'    => parseMoney($c[FC_DV_CONT]  ?? null),
            'devVExtra'   => parseMoney($c[FC_DV_EXTRA] ?? null),
            // Detalhe Infra
            'srvRdp'      => parseMoney($c[FC_RDP]    ?? null),
            'srvVm'       => parseMoney($c[FC_VM]     ?? null),
            'srvDados'    => parseMoney($c[FC_DADOS]  ?? null),
            'srvSis'      => parseMoney($c[FC_SIS]    ?? null),
            'hospedagem'  => parseMoney($c[FC_HOSP]   ?? null),
            'gestao'      => parseMoney($c[FC_GESTAO] ?? null),
            'apiCnpj'     => parseMoney($c[FC_CNPJ]   ?? null),
            'apiClick'    => parseMoney($c[FC_CLICK]  ?? null),
            'apiRfb'      => parseMoney($c[FC_RFB]    ?? null),
            'apiZap'      => parseMoney($c[FC_ZAP]    ?? null),
            'qtdRdp'      => (int)($c[FC_QTD_RDP]     ?? 0),
            'dominios'    => $dominios,
        ];
    }

    usort($cards, fn($a, $b) => strcmp($a['empresa'], $b['empresa']));

    foreach ($kpi as $k => $v) $kpi[$k] = round($v, 2);

    echo json_encode([
        'sucesso'    => true,
        'periodo'    => $periodo,
        'cards'      => $cards,
        'kpi'        => $kpi,
        'bitrixBase' => $bitrixBase,
    ]);

} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}

// "130.50|BRL" ou plain float → float
function parseMoney(mixed $val): float {
    if ($val === null || $val === '' || $val === false) return 0.0;
    $s = is_array($val) ? (string)($val[0] ?? '') : (string)$val;
    return round((float)explode('|', $s)[0], 2);
}

function calcularPeriodoAtual(int $diaInicio): array {
    $hoje = new DateTime();
    $dia  = (int)$hoje->format('d');
    $mes  = (int)$hoje->format('m');
    $ano  = (int)$hoje->format('Y');

    if ($dia >= $diaInicio) {
        $inicioMes = $mes;
        $inicioAno = $ano;
    } else {
        $inicioMes = $mes - 1;
        $inicioAno = $ano;
        if ($inicioMes < 1) { $inicioMes = 12; $inicioAno--; }
    }

    return calcularPeriodoDeInicio($inicioAno, $inicioMes, $diaInicio);
}

// Referência (MM/YYYY) é sempre o mês de encerramento do período (ver FINANCEIRO.md).
// Logo o mês de início é sempre um mês antes da referência.
function calcularPeriodoPorReferencia(string $referencia, int $diaInicio): array {
    [$refMes, $refAno] = array_map('intval', explode('/', $referencia));

    $inicioMes = $refMes - 1;
    $inicioAno = $refAno;
    if ($inicioMes < 1) { $inicioMes = 12; $inicioAno--; }

    return calcularPeriodoDeInicio($inicioAno, $inicioMes, $diaInicio);
}

function calcularPeriodoDeInicio(int $inicioAno, int $inicioMes, int $diaInicio): array {
    $inicio = new DateTime(sprintf('%04d-%02d-%02d', $inicioAno, $inicioMes, $diaInicio));
    $fim    = clone $inicio;
    $fim->add(new DateInterval('P1M'));
    $fim->sub(new DateInterval('P1D'));

    $refMes = (int)$fim->format('m');
    $refAno = (int)$fim->format('Y');

    return [
        'referencia' => sprintf('%02d/%04d', $refMes, $refAno),
        'inicio'     => $inicio->format('d/m/Y'),
        'fim'        => $fim->format('d/m/Y'),
    ];
}
