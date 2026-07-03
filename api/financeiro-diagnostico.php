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

$user = $auth->getCurrentUser();
if (!$user || $user['perfil'] !== 'admin_interno') {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso negado']);
    exit;
}

// ── Campos cat/208/ (Demandas Mensais) ──────────────────────────────────────
define('BX_ENTITY_TYPE',   1054);
define('BX_CAT_DEMANDAS',  208);
define('F_TIPO_CHAMADO',   'ufCrm41_1737476320'); // Tipo de Chamado
define('F_TEMPO_ATUAL',    'ufCrm41_1751475675'); // Tempo de Atendimento Final (Em Minutos)
define('F_TEMPO_COMPUTADO','ufCrm41_1767906196'); // Tempo Final # (último valor computado)
define('F_DATA_FIN',       'ufCrm41_1778777816'); // Data de finalização
define('F_FATURA_LINK',    'ufCrm41_1767897101'); // Fatura/Cobrança (card financeiro vinculado)

const TIPOS_FATURA = [21204, 21206, 21208, 21210];

try {
    $dao       = new ConfiguracaoDAO();
    $diaInicio = max(1, min(28, (int)($dao->get('financeiro_dia_inicio') ?? 27)));

    $refParam = $_GET['ref'] ?? null;
    if ($refParam !== null && !preg_match('/^\d{2}\/\d{4}$/', $refParam)) {
        echo json_encode(['erro' => 'Formato de referência inválido (esperado: MM/YYYY)']);
        exit;
    }

    $periodo = $refParam
        ? calcularPeriodoPorReferencia($refParam, $diaInicio)
        : calcularPeriodoAtual($diaInicio);

    $bitrix = new BitrixService();
    if (!$bitrix->isConfigured()) {
        echo json_encode(['erro' => 'Webhook Bitrix24 não configurado']);
        exit;
    }

    // listItems já pagina automaticamente (maxItems=0 → sem limite)
    $rawDemandas = $bitrix->listItems(BX_ENTITY_TYPE, [
        'categoryId'      => BX_CAT_DEMANDAS,
        '>=' . F_DATA_FIN => $periodo['inicioIso'],
        '<=' . F_DATA_FIN => $periodo['fimIso'],
    ], [
        'id', 'title', 'companyId',
        F_TIPO_CHAMADO, F_TEMPO_ATUAL, F_TEMPO_COMPUTADO, F_DATA_FIN, F_FATURA_LINK,
    ], 0);

    $inicioReal = $periodo['inicioReal'];
    $fimReal    = $periodo['fimReal'];

    $demandas = array_values(array_filter($rawDemandas, function ($d) use ($inicioReal, $fimReal) {
        $tipo = (int)($d[F_TIPO_CHAMADO] ?? 0);
        if (!in_array($tipo, TIPOS_FATURA, true)) return false;

        $dataFin = substr((string)($d[F_DATA_FIN] ?? ''), 0, 10);
        return $dataFin >= $inicioReal && $dataFin <= $fimReal;
    }));

    $lista = [];
    foreach ($demandas as $d) {
        $lista[] = [
            'id'                => (int)$d['id'],
            'titulo'            => $d['title'] ?? '',
            'empresa'           => (int)($d['companyId'] ?? 0),
            'tipo'              => (int)($d[F_TIPO_CHAMADO] ?? 0),
            'minutos_atual'     => (int)($d[F_TEMPO_ATUAL] ?? 0),
            'minutos_computado' => (int)($d[F_TEMPO_COMPUTADO] ?? 0),
            'data_finalizacao'  => $d[F_DATA_FIN] ?? null,
            'fatura_vinculada'  => parseLinkId($d[F_FATURA_LINK] ?? null),
        ];
    }

    echo json_encode([
        'sucesso'           => true,
        'total_encontradas' => count($lista),
        'periodo'           => [
            'referencia' => $periodo['referencia'],
            'inicio'     => $periodo['inicio'],
            'fim'        => $periodo['fim'],
        ],
        'demandas'          => $lista,
    ]);

} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}

// Normaliza campo de link CRM (ex: "D1054_123", 123, ["123"]) → int ID ou null
function parseLinkId(mixed $val): ?int {
    if (is_array($val)) $val = $val[0] ?? null;
    if ($val === null || $val === '' || $val === false) return null;
    if (is_int($val)) return $val;
    preg_match('/(\d+)$/', (string)$val, $m);
    return isset($m[1]) ? (int)$m[1] : null;
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

    $fimFinal = clone $fim;
    $fimFinal->setTime(23, 59, 59);

    // Filtro Bitrix alargado em 1 dia no início: demandas finalizadas exatamente
    // à meia-noite do primeiro dia do período ficavam fora do filtro ">=" por um
    // deslocamento de timezone na comparação de datetime do Bitrix. O re-filtro
    // por data real (inicioReal/fimReal, usado na query em si) garante que nada
    // antes do período de fato entre. Ver services/FinanceiroSync.php::buscarDemandas.
    $inicioFiltro = clone $inicio;
    $inicioFiltro->sub(new DateInterval('P1D'));

    $refMes = (int)$fim->format('m');
    $refAno = (int)$fim->format('Y');

    return [
        'referencia' => sprintf('%02d/%04d', $refMes, $refAno),
        'inicio'     => $inicio->format('d/m/Y'),
        'fim'        => $fim->format('d/m/Y'),
        'inicioReal' => $inicio->format('Y-m-d'),
        'fimReal'    => $fim->format('Y-m-d'),
        'inicioIso'  => $inicioFiltro->format('Y-m-d\T00:00:00'),
        'fimIso'     => $fimFinal->format('Y-m-d\TH:i:s'),
    ];
}
