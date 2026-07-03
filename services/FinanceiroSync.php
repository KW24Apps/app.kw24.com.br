<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../dao/ConfiguracaoDAO.php';
require_once __DIR__ . '/../services/BitrixService.php';

class FinanceiroSync {

    // ── Part 1: Demandas ─────────────────────────────────────────────────────
    private const BX_ENTITY_TYPE  = 1054;
    private const BX_CAT_DEMANDAS = 208;
    private const BX_CAT_FINANC   = 210;

    private const TIPOS_SUPORTE = [21204, 21206];
    private const TIPOS_DEV     = [21208, 21210];
    private const TIPOS_FATURA  = [21204, 21206, 21208, 21210];

    // Campos do card de demanda (category 208)
    private const F_TIPO_CHAMADO = 'ufCrm41_1737476320';
    private const F_TEMPO_ATUAL  = 'ufCrm41_1751475675';
    private const F_DATA_FIN     = 'ufCrm41_1778777816';
    private const F_FATURA_LINK  = 'ufCrm41_1767897101';

    // Campos do card financeiro (category 210)
    private const F_CONTROLE     = 'ufCrm41_1742082168'; // Controle de Fatura # (lookup key — shared com cat/284/)
    private const F_MIN_SUPORTE  = 'ufCrm41_1767900752';
    private const F_MIN_DEV      = 'ufCrm41_1767900780';
    private const F_DEM_SUPORTE  = 'ufCrm41_1778777514';
    private const F_DEM_DEV      = 'ufCrm41_1778777535';
    private const F_COMPETENCIA  = 'ufCrm41_1742081702';

    // ── Part 2A: Infra Execução ───────────────────────────────────────────────
    // Fonte: SPA 1130 / cat 282 — Produtos de Infra Contratados
    private const BX_INFRA_SRC_ENTITY = 1130;
    private const BX_CAT_INFRA_SRC   = 282;

    // Destino: SPA 1054 / cat 284 — Infra Mensal (Execução)
    private const BX_CAT_INFRA       = 284;
    private const BX_INFRA_STAGE_NEW = 'DT1054_284:NEW'; // confirmado via crm.status.list

    // Campos fonte (SPA 1130 / ufCrm66_*)
    private const S_PRODUTO   = 'ufCrm66_1773322225'; // Produto Contratado (enum)
    private const S_DEPTO     = 'ufCrm66_1773325912'; // Departamento (enum)
    private const S_HORAS_DEV = 'ufCrm66_1773337978'; // Horas Dev
    private const S_HORAS_SUP = 'ufCrm66_1773338012'; // Horas Suporte
    private const S_VH_DEV    = 'ufCrm66_1773337676'; // Valor Hora Dev (money)
    private const S_VH_SUP    = 'ufCrm66_1773337956'; // Valor Hora Suporte (money)
    private const S_DOMINIOS     = 'ufCrm66_1773340437'; // Domínios (string[])
    private const S_QTD_RDP     = 'ufCrm66_1773350132'; // Qtd Usuários RDP
    private const S_USUARIOS_RDP = 'ufCrm66_1773344477'; // Usuários RDP (employee[])

    // Campos destino (SPA 1054 / cat 284 / ufCrm41_*)
    private const I_PRODUTO   = 'ufCrm41_1773942147'; // Produto Contratado (enum)
    private const I_DEPTO     = 'ufCrm41_1737476922'; // Departamento (enum)
    private const I_HORAS_DEV = 'ufCrm41_1742071291'; // Horas Dev
    private const I_HORAS_SUP = 'ufCrm41_1742071347'; // Horas Suporte
    private const I_VH_DEV    = 'ufCrm41_1767928073'; // Valor Hora Dev (money)
    private const I_VH_SUP    = 'ufCrm41_1767928096'; // Valor Hora Suporte (money)
    private const I_DOMINIOS      = 'ufCrm41_1773467121'; // Domínios (string[])
    private const I_QTD_RDP      = 'ufCrm41_1773467142'; // Qtd Usuários RDP
    private const I_USUARIOS_RDP = 'ufCrm41_1773467182'; // Usuários RDP (employee[])
    private const I_SOLICITANTE  = 'ufCrm41_1737477724'; // Solicitante (traceabilidade)
    private const I_PRODUTO_ORIG = 'ufCrm41_1781576165'; // Produto Origem (link SPA 1130)

    // Estágios ativos de cat/282/ (fonte) — apenas estes são sincronizados
    private const INFRA_SRC_STAGES = ['DT1130_282:UC_8094MO', 'DT1130_282:UC_GXQIX9'];

    // ── Part 2B: Financeiro — produto types (enum IDs em UF_CRM_41_1773942147 / cat 284) ──
    private const PROD_MENSAL     = [28422, 28424]; // Contrato Mensal, Demandas Avulsas
    private const PROD_SRV_RDP    = 28426;
    private const PROD_SRV_VM     = 28428;
    private const PROD_SRV_DADOS  = 28430;
    private const PROD_SRV_SIS    = 28432;
    private const PROD_HOSPEDAGEM = 28434;
    private const PROD_GESTAO     = 28436;
    private const PROD_API_CNPJ   = 28438;
    private const PROD_API_CLICK  = 28440;
    private const PROD_API_RFB    = 28442;
    private const PROD_API_ZAP    = 28444;
    private const PROD_VONO       = 28832; // Vono Telefonia (money)

    // Campos destino cat/210/ — Part 2B (ufCrm41_* exclusivos; campos compartilhados reutilizam I_*)
    private const F2_PRODUTOS_284    = 'ufCrm41_1773457133'; // Produtos Contratados (crm[] → cat/284/)
    private const F2_TEMPO_CONT_DEV  = 'ufCrm41_1767900847'; // Tempo Contrato Dev (min)
    private const F2_TEMPO_CONT_SUP  = 'ufCrm41_1767900807'; // Tempo Contrato Sup (min)
    private const F2_TEMPO_EXTRA_DEV = 'ufCrm41_1767900863'; // Tempo Extra Dev (min)
    private const F2_TEMPO_EXTRA_SUP = 'ufCrm41_1767900828'; // Tempo Extra Sup (min)
    private const F2_VALOR_CONT_DEV  = 'ufCrm41_1773474261'; // Valor Contratado Dev (money)
    private const F2_VALOR_CONT_SUP  = 'ufCrm41_1773474286'; // Valor Contratado Sup (money)
    private const F2_VALOR_EXTRA_DEV = 'ufCrm41_1767901161'; // Valor Extra Dev (money)
    private const F2_VALOR_EXTRA_SUP = 'ufCrm41_1767901210'; // Valor Extra Sup (money)
    private const F2_VALOR_TOTAL_DEV = 'ufCrm41_1767901128'; // Valor Total Dev (money)
    private const F2_VALOR_TOTAL_SUP = 'ufCrm41_1767901194'; // Valor Total Sup (money)
    private const F2_SRV_RDP         = 'ufCrm41_1770316177'; // Servidor RDP (money)
    private const F2_SRV_VM          = 'ufCrm41_1770316215'; // Servidor VM (money)
    private const F2_SRV_DADOS       = 'ufCrm41_1770316226'; // Servidor de Dados (money)
    private const F2_SRV_SIS         = 'ufCrm41_1770316237'; // Servidor Sist Dom (money)
    private const F2_HOSPEDAGEM      = 'ufCrm41_1770316252'; // Hospedagem de Dom (money)
    private const F2_GESTAO          = 'ufCrm41_1770316270'; // Gestão E-mail Sites (money)
    private const F2_API_CNPJ        = 'ufCrm41_1770316647'; // API CNPJ (money)
    private const F2_API_CLICK       = 'ufCrm41_1770316694'; // API ClickSign (money)
    private const F2_API_RFB         = 'ufCrm41_1773452068'; // API Receita Federal (money)
    private const F2_API_ZAP         = 'ufCrm41_1773452083'; // API WhatsApp (money)
    private const F2_VONO            = 'ufCrm41_1782135657'; // Vono Telefonia (money)
    private const F2_VALOR_INFRA     = 'ufCrm41_1770316473'; // Total Infra (money)

    // Tradução enum: Produto Contratado (SPA 1130 → SPA 1054)
    private const PRODUTO_MAP = [
        28358 => 28422, // Contrato Mensal
        28360 => 28424, // Demandas Avulsas Mensal
        28362 => 28426, // Servidor RDP
        28364 => 28428, // Servidor VM
        28366 => 28430, // Servidor de Dados
        28368 => 28432, // Servidor Sistema Domínio
        28370 => 28434, // Hospedagem de Domínio
        28372 => 28436, // Gestão de E-mail e Sites
        28374 => 28438, // API Validador de CNPJ
        28376 => 28440, // API ClickSign
        28378 => 28442, // API Receita Federal
        28380 => 28444, // API WhatsApp
        28830 => 28832, // Vono Telefonia
    ];

    // Tradução enum: Departamento (SPA 1130 → SPA 1054) — null = sem equivalente (campo em branco)
    private const DEPTO_MAP = [
        28382 => 21226, // Grupo Nimbus
        28384 => 21234, // Nimbus Tax
        28386 => 21518, // GN - Financeiro
        28388 => 21228, // GN - Controladoria
        28390 => 21230, // GN - Marketing
        28392 => 21520, // GN - RH
        28394 => 21232, // GN - Núcleo de Produtos
        28396 => 21240, // Capiton
        28398 => 21242, // BGA - Advocacia
        28400 => 21244, // Altura Assessoria
        28402 => 21246, // Nimbus Privacy
        28404 => 21538, // ContaFarma
        28410 => 21250, // Externo
        28804 => null,  // Consisto — sem equivalente no SPA 1054
    ];

    // Mapa de labels para geração de título (SPA 1130 enum ID → nome)
    private const PRODUTO_LABELS = [
        28358 => 'Contrato Mensal',        28360 => 'Demandas Avulsas Mensal',
        28362 => 'Servidor RDP',           28364 => 'Servidor VM',
        28366 => 'Servidor de Dados',      28368 => 'Servidor Sistema Domínio',
        28370 => 'Hospedagem de Domínio',  28372 => 'Gestão de E-mail e Sites',
        28374 => 'API Validador de CNPJ',  28376 => 'API ClickSign',
        28378 => 'API Receita Federal',    28380 => 'API WhatsApp',
    ];

    private BitrixService $bitrix;
    private int $diaInicio;
    private array $log = [];

    public function __construct() {
        $dao = new ConfiguracaoDAO();
        $this->diaInicio = max(1, min(28, (int)($dao->get('financeiro_dia_inicio') ?? 27)));
        $this->bitrix    = new BitrixService();
    }

    public function run(?string $period = null): array {
        $this->log = [];

        if (!$this->bitrix->isConfigured()) {
            return $this->erroRetorno('Webhook Bitrix24 não configurado em configuracoes_sistema');
        }

        $periodo = $this->calcularPeriodo($period);
        $this->addLog("Período: {$periodo['referencia']} ({$periodo['inicio']->format('Y-m-d')} → {$periodo['fim']->format('Y-m-d')})");

        $demandas = $this->buscarDemandas($periodo);
        $this->addLog("Demandas faturáveis encontradas: " . count($demandas));

        if (empty($demandas)) {
            return [
                'periodo'        => $periodo['referencia'],
                'inicio'         => $periodo['inicio']->format('Y-m-d'),
                'fim'            => $periodo['fim']->format('Y-m-d'),
                'demandas_total' => 0,
                'empresas'       => 0,
                'atualizados'    => 0,
                'erros'          => 0,
                'log'            => $this->log,
            ];
        }

        // Agrupar por empresa
        $porEmpresa = [];
        foreach ($demandas as $d) {
            $cid = (int)($d['companyId'] ?? 0);
            if ($cid) $porEmpresa[$cid][] = $d;
        }
        $this->addLog("Empresas distintas: " . count($porEmpresa));

        $atualizados = 0;
        $erros       = 0;
        foreach ($porEmpresa as $companyId => $dems) {
            try {
                $this->processarEmpresa($companyId, $dems, $periodo);
                $atualizados++;
            } catch (Exception $e) {
                $erros++;
                $this->addLog("ERRO empresa {$companyId}: " . $e->getMessage());
            }
        }

        return [
            'periodo'        => $periodo['referencia'],
            'inicio'         => $periodo['inicio']->format('Y-m-d'),
            'fim'            => $periodo['fim']->format('Y-m-d'),
            'demandas_total' => count($demandas),
            'empresas'       => count($porEmpresa),
            'atualizados'    => $atualizados,
            'erros'          => $erros,
            'log'            => $this->log,
        ];
    }

    private function calcularPeriodo(?string $period): array {
        $diaInicio = $this->diaInicio;

        if ($period !== null && preg_match('/^(\d{4})-(\d{2})$/', $period, $m)) {
            $refYear  = (int)$m[1];
            $refMonth = (int)$m[2];
            $diaFim   = $diaInicio - 1;

            $fim = new DateTime(sprintf('%04d-%02d-%02d', $refYear, $refMonth, $diaFim));
            $fim->setTime(23, 59, 59);

            $inicioMes = $refMonth - 1;
            $inicioAno = $refYear;
            if ($inicioMes < 1) { $inicioMes = 12; $inicioAno--; }
            $inicio = new DateTime(sprintf('%04d-%02d-%02d', $inicioAno, $inicioMes, $diaInicio));

            return [
                'inicio'     => $inicio,
                'fim'        => $fim,
                'referencia' => sprintf('%02d/%04d', $refMonth, $refYear),
                'refDate'    => sprintf('%04d-%02d-01', $refYear, $refMonth),
            ];
        }

        // Período atual (mesma lógica do webhook)
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

        $inicio = new DateTime(sprintf('%04d-%02d-%02d', $inicioAno, $inicioMes, $diaInicio));
        $fim    = clone $inicio;
        $fim->add(new DateInterval('P1M'));
        $fim->sub(new DateInterval('P1D'));
        $fim->setTime(23, 59, 59);

        $refMes = (int)$fim->format('m');
        $refAno = (int)$fim->format('Y');

        return [
            'inicio'     => $inicio,
            'fim'        => $fim,
            'referencia' => sprintf('%02d/%04d', $refMes, $refAno),
            'refDate'    => sprintf('%04d-%02d-01', $refAno, $refMes),
        ];
    }

    private function buscarDemandas(array $periodo): array {
        // Alarga 1 dia no início do filtro enviado ao Bitrix: demandas finalizadas
        // exatamente à meia-noite do primeiro dia do período ficavam fora do
        // filtro ">=" por um deslocamento de timezone na comparação de datetime
        // do Bitrix. O re-filtro por data real (abaixo) garante que nada antes
        // do período de fato entre.
        $inicioFiltro = clone $periodo['inicio'];
        $inicioFiltro->sub(new DateInterval('P1D'));

        $inicioStr = $inicioFiltro->format('Y-m-d\T00:00:00');
        $fimStr    = $periodo['fim']->format('Y-m-d\T23:59:59');

        $todas = $this->bitrix->listItems(
            self::BX_ENTITY_TYPE,
            [
                'categoryId'                       => self::BX_CAT_DEMANDAS,
                '>=' . self::F_DATA_FIN            => $inicioStr,
                '<=' . self::F_DATA_FIN            => $fimStr,
            ],
            [
                'id', 'title', 'companyId', 'stageId',
                self::F_TIPO_CHAMADO,
                self::F_TEMPO_ATUAL,
                self::F_DATA_FIN,
                self::F_FATURA_LINK,
            ],
            0 // sem limite — sync precisa de todos
        );

        $inicioReal = $periodo['inicio']->format('Y-m-d');
        $fimReal    = $periodo['fim']->format('Y-m-d');

        return array_values(array_filter($todas, function ($d) use ($inicioReal, $fimReal) {
            $tipo = (int)($d[self::F_TIPO_CHAMADO] ?? 0);
            if (!in_array($tipo, self::TIPOS_FATURA, true)) return false;

            $dataFin = substr((string)($d[self::F_DATA_FIN] ?? ''), 0, 10);
            return $dataFin >= $inicioReal && $dataFin <= $fimReal;
        }));
    }

    private function processarEmpresa(int $companyId, array $demandas, array $periodo): void {
        $minSuporte = 0;
        $minDev     = 0;
        $idsSuporte = [];
        $idsDev     = [];

        foreach ($demandas as $d) {
            $tipo = (int)($d[self::F_TIPO_CHAMADO] ?? 0);
            $mins = (int)($d[self::F_TEMPO_ATUAL]  ?? 0);
            $id   = (int)$d['id'];

            if (in_array($tipo, self::TIPOS_SUPORTE, true)) {
                $minSuporte  += $mins;
                $idsSuporte[] = $id;
            } else {
                $minDev  += $mins;
                $idsDev[] = $id;
            }
        }

        $financialId = $this->encontrarOuCriarCard($companyId, $periodo);

        // REPLACE completo — sobrescreve totais e listas de demanda
        $ok = $this->bitrix->updateItem(self::BX_ENTITY_TYPE, $financialId, [
            self::F_MIN_SUPORTE => (string)$minSuporte,
            self::F_MIN_DEV     => (string)$minDev,
            self::F_DEM_SUPORTE => $idsSuporte,
            self::F_DEM_DEV     => $idsDev,
        ]);

        if (!$ok) {
            throw new Exception("Falha ao atualizar card financeiro {$financialId}");
        }

        $this->addLog("Empresa {$companyId}: suporte={$minSuporte}min dev={$minDev}min card={$financialId}");

        // Vincular demandas ao card financeiro (corrige links errados ou ausentes)
        foreach ($demandas as $d) {
            $existing   = (array)($d[self::F_FATURA_LINK] ?? []);
            $existingId = (int)($existing[0] ?? 0);
            if ($existingId !== $financialId) {
                $this->bitrix->updateItem(self::BX_ENTITY_TYPE, (int)$d['id'], [
                    self::F_FATURA_LINK => [$financialId],
                ]);
            }
        }
    }

    private function encontrarOuCriarCard(int $companyId, array $periodo): int {
        // Lookup via F_CONTROLE — campo dedicado, imune a renomeação de título
        $cards = $this->bitrix->listItems(self::BX_ENTITY_TYPE, [
            'categoryId'     => self::BX_CAT_FINANC,
            'companyId'      => $companyId,
            self::F_CONTROLE => $periodo['referencia'],
        ], ['id', self::F_CONTROLE]);

        if (!empty($cards)) {
            return (int)$cards[0]['id'];
        }

        $company     = $this->bitrix->getCompany($companyId);
        $companyName = $company['TITLE'] ?? "Empresa #{$companyId}";
        $title       = "Fatura Referente a {$periodo['referencia']} - {$companyName}";

        $id = $this->bitrix->createItem(self::BX_ENTITY_TYPE, [
            'categoryId'       => self::BX_CAT_FINANC,
            'stageId'          => 'DT1054_210:NEW',
            'title'            => $title,
            'companyId'        => $companyId,
            self::F_COMPETENCIA => $periodo['refDate'],
            self::F_CONTROLE   => $periodo['referencia'],
        ]);

        if (!$id) {
            throw new Exception("Falha ao criar card financeiro empresa {$companyId}");
        }

        $this->addLog("Card financeiro criado id={$id} empresa={$companyId}");
        return $id;
    }

    // ── Part 2A: syncInfra ───────────────────────────────────────────────────

    public function syncInfra(?string $period = null): array {
        $this->log = [];

        if (!$this->bitrix->isConfigured()) {
            $this->addLog('Webhook Bitrix24 não configurado');
            return $this->erroInfraRetorno();
        }

        $periodo = $this->calcularPeriodo($period);
        $this->addLog("Infra sync — Período: {$periodo['referencia']} ({$periodo['inicio']->format('Y-m-d')} → {$periodo['fim']->format('Y-m-d')})");

        // 1. Buscar produtos contratados (SPA 1130 / cat 282) e filtrar pelos estágios ativos
        $allSource = $this->bitrix->listItems(
            self::BX_INFRA_SRC_ENTITY,
            ['categoryId' => self::BX_CAT_INFRA_SRC],
            [
                'id', 'stageId', 'companyId', 'opportunity',
                self::S_PRODUTO, self::S_DEPTO,
                self::S_HORAS_DEV, self::S_HORAS_SUP,
                self::S_VH_DEV, self::S_VH_SUP,
                self::S_DOMINIOS, self::S_QTD_RDP, self::S_USUARIOS_RDP,
            ],
            0
        );
        $sourcecards = array_values(array_filter(
            $allSource,
            fn($s) => in_array($s['stageId'] ?? '', self::INFRA_SRC_STAGES, true)
        ));
        $this->addLog("Produtos em cat/282/ (total=" . count($allSource) . ", ativos=" . count($sourcecards) . ")");

        if (empty($sourcecards)) {
            return [
                'periodo'      => $periodo['referencia'],
                'inicio'       => $periodo['inicio']->format('Y-m-d'),
                'fim'          => $periodo['fim']->format('Y-m-d'),
                'total_source' => 0,
                'created'      => 0,
                'skipped'      => 0,
                'errors'       => 0,
                'log'          => $this->log,
            ];
        }

        // 2. Pré-carregar nomes de empresa em batch (evita N chamadas individuais)
        $uniqueCompanyIds = array_values(array_unique(array_filter(
            array_map(fn($s) => (int)($s['companyId'] ?? 0), $sourcecards)
        )));
        $companyCache = $this->batchFetchCompanyNames($uniqueCompanyIds);
        $this->addLog("Empresas pré-carregadas: " . count($companyCache));

        // 3. Carregar cards existentes em cat/284/ com stageId=NEW para o período.
        //    Indexados por I_PRODUTO_ORIG (ID do source em cat/282/) + período.
        //    Cards de automação (sem I_PRODUTO_ORIG) são ignorados na construção do índice.
        $existing = $this->bitrix->listItems(
            self::BX_ENTITY_TYPE,
            [
                'categoryId'     => self::BX_CAT_INFRA,
                'stageId'        => self::BX_INFRA_STAGE_NEW,
                self::F_CONTROLE => $periodo['referencia'],
            ],
            ['id', self::I_PRODUTO_ORIG],
            0
        );
        $index = [];
        foreach ($existing as $c) {
            $srcId = $this->parseCrmLinkId($c[self::I_PRODUTO_ORIG] ?? null);
            if ($srcId > 0) {
                $index["{$srcId}|{$periodo['referencia']}"] = (int)$c['id'];
            }
        }
        $this->addLog("Cards existentes em cat/284/ (NEW, {$periodo['referencia']}): " . count($existing) . " / sync-indexados: " . count($index));

        // 4. Determinar quais cards precisam ser criados (idempotência)
        $toCreate = [];
        $skipped  = 0;
        $errors   = 0;

        foreach ($sourcecards as $src) {
            $srcId       = (int)$src['id'];
            $companyId   = (int)($src['companyId'] ?? 0);
            $produto1130 = (int)($src[self::S_PRODUTO] ?? 0);
            $depto1130   = (int)($src[self::S_DEPTO]   ?? 0);

            if (!$companyId) {
                $this->addLog("SKIP: src={$srcId} sem empresa");
                $errors++;
                continue;
            }

            if (!isset(self::PRODUTO_MAP[$produto1130])) {
                $this->addLog("WARN: src={$srcId} produto sem tradução id={$produto1130} — ignorado");
                $errors++;
                continue;
            }
            $produto284 = self::PRODUTO_MAP[$produto1130];

            if ($depto1130 === 0) {
                $depto284 = null;
            } elseif (array_key_exists($depto1130, self::DEPTO_MAP)) {
                $depto284 = self::DEPTO_MAP[$depto1130];
            } else {
                $this->addLog("WARN: src={$srcId} departamento desconhecido id={$depto1130} — campo em branco");
                $depto284 = null;
            }

            // Nova chave de idempotência: ID do card fonte + período
            $key = "{$srcId}|{$periodo['referencia']}";

            if (isset($index[$key])) {
                $skipped++;
                $this->addLog("SKIP: src={$srcId} cid={$companyId} card_id={$index[$key]}");
                continue;
            }

            $prodLabel = self::PRODUTO_LABELS[$produto1130] ?? "Produto #{$produto1130}";
            $coName    = $companyCache[$companyId] ?? "Empresa #{$companyId}";

            $fields = [
                'categoryId'          => self::BX_CAT_INFRA,
                'stageId'             => self::BX_INFRA_STAGE_NEW,
                'title'               => "Infra {$periodo['referencia']} - {$prodLabel} - {$coName}",
                'companyId'           => $companyId,
                'opportunity'         => $src['opportunity'] ?? 0,
                self::F_CONTROLE      => $periodo['referencia'],
                self::I_PRODUTO       => $produto284,
                self::I_HORAS_DEV     => $src[self::S_HORAS_DEV] ?? '',
                self::I_HORAS_SUP     => $src[self::S_HORAS_SUP] ?? '',
                self::I_VH_DEV        => $src[self::S_VH_DEV]    ?? '',
                self::I_VH_SUP        => $src[self::S_VH_SUP]    ?? '',
                self::I_DOMINIOS      => $src[self::S_DOMINIOS]     ?? [],
                self::I_QTD_RDP      => $src[self::S_QTD_RDP]     ?? 0,
                self::I_USUARIOS_RDP  => $src[self::S_USUARIOS_RDP] ?? [],
                self::I_SOLICITANTE   => 'Sistema Financeiro KW24',
                self::I_PRODUTO_ORIG  => $srcId,
            ];

            if ($depto284 !== null) {
                $fields[self::I_DEPTO] = $depto284;
            }

            $toCreate[] = [
                'key'    => $key,
                'logKey' => "src={$srcId} cid={$companyId} produto={$produto284}",
                'fields' => $fields,
            ];
            $index[$key] = 0; // sentinela: já agendado neste ciclo, previne duplicata de source
        }

        $this->addLog("A criar: " . count($toCreate) . " · Skipped: {$skipped}");

        // 5. Criar em batch (grupos de 15 — limite seguro do Bitrix)
        $created   = 0;
        $chunks    = array_chunk($toCreate, 15);
        $numChunks = count($chunks);

        foreach ($chunks as $chunkIdx => $chunk) {
            $cmd = [];
            foreach ($chunk as $i => $card) {
                $cmd["c{$i}"] = 'crm.item.add?' . http_build_query(
                    ['entityTypeId' => self::BX_ENTITY_TYPE, 'fields' => $card['fields']],
                    '', '&', PHP_QUERY_RFC3986
                );
            }

            $resp = $this->bitrix->call('batch', ['halt' => 0, 'cmd' => $cmd]);

            if ($resp === null) {
                $errors += count($chunk);
                $this->addLog("ERRO: batch " . ($chunkIdx + 1) . "/{$numChunks} falhou (resposta nula)");
                continue;
            }

            $results      = $resp['result']       ?? [];
            $resultErrors = $resp['result_error'] ?? [];

            foreach ($chunk as $i => $card) {
                $cmdKey = "c{$i}";
                if (!empty($resultErrors[$cmdKey])) {
                    $errors++;
                    $errMsg = $resultErrors[$cmdKey]['error_description']
                        ?? $resultErrors[$cmdKey]['error']
                        ?? 'falha';
                    $this->addLog("ERRO: {$card['logKey']} — {$errMsg}");
                } else {
                    $newId = (int)(($results[$cmdKey]['item']['id'] ?? 0));
                    if ($newId) {
                        $created++;
                        $index[$card['key']] = $newId;
                        $this->addLog("CRIADO: id={$newId} {$card['logKey']}");
                    } else {
                        $errors++;
                        $this->addLog("ERRO: {$card['logKey']} — sem ID na resposta do batch");
                    }
                }
            }

            $this->addLog("Batch " . ($chunkIdx + 1) . "/{$numChunks}: " . count($chunk) . " enviados");

            if ($chunkIdx + 1 < $numChunks) {
                usleep(500000); // 0.5s entre batches
            }
        }

        return [
            'periodo'      => $periodo['referencia'],
            'inicio'       => $periodo['inicio']->format('Y-m-d'),
            'fim'          => $periodo['fim']->format('Y-m-d'),
            'total_source' => count($sourcecards),
            'created'      => $created,
            'skipped'      => $skipped,
            'errors'       => $errors,
            'log'          => $this->log,
        ];
    }

    // ── Part 2B: syncFinanceiro ──────────────────────────────────────────────

    /**
     * Lê cards cat/284/ NEW do período e atualiza (ou cria) o card cat/210/ de cada empresa
     * com os totais financeiros agregados.
     */
    public function syncFinanceiro(?string $period = null): array {
        $this->log = [];

        if (!$this->bitrix->isConfigured()) {
            $this->addLog('Webhook Bitrix24 não configurado');
            return $this->erroFinancRetorno();
        }

        $periodo = $this->calcularPeriodo($period);
        $this->addLog("Financeiro sync — Período: {$periodo['referencia']} ({$periodo['inicio']->format('Y-m-d')} → {$periodo['fim']->format('Y-m-d')})");

        // 1. Buscar todos os cards cat/284/ NEW do período
        $infracards = $this->bitrix->listItems(
            self::BX_ENTITY_TYPE,
            [
                'categoryId'     => self::BX_CAT_INFRA,
                'stageId'        => self::BX_INFRA_STAGE_NEW,
                self::F_CONTROLE => $periodo['referencia'],
            ],
            [
                'id', 'companyId', 'opportunity',
                self::I_PRODUTO,
                self::I_HORAS_DEV, self::I_HORAS_SUP,
                self::I_VH_DEV,    self::I_VH_SUP,
            ],
            0
        );
        $this->addLog("Cards cat/284/ NEW do período: " . count($infracards));

        if (empty($infracards)) {
            return [
                'periodo'  => $periodo['referencia'],
                'inicio'   => $periodo['inicio']->format('Y-m-d'),
                'fim'      => $periodo['fim']->format('Y-m-d'),
                'empresas' => 0,
                'updated'  => 0,
                'created'  => 0,
                'errors'   => 0,
                'log'      => $this->log,
            ];
        }

        // Agrupar por empresa
        $porEmpresa = [];
        foreach ($infracards as $c) {
            $cid = (int)($c['companyId'] ?? 0);
            if ($cid) $porEmpresa[$cid][] = $c;
        }
        $this->addLog("Empresas distintas: " . count($porEmpresa));

        // Pré-carregar nomes de empresa
        $companyCache = $this->batchFetchCompanyNames(array_keys($porEmpresa));

        // 2–5. Por empresa: encontrar/criar cat/210/, agregar, calcular, atualizar
        $updatedCount = 0;
        $createdCount = 0;
        $errors       = 0;

        foreach ($porEmpresa as $companyId => $cards) {
            try {
                // 2. Encontrar ou criar card cat/210/ (retorna id + minutos já gravados por run())
                $financCard = $this->encontrarOuCriarCardFinanc(
                    $companyId, $periodo, $companyCache[$companyId] ?? null
                );

                // 3. Agregar dados dos cards cat/284/ por tipo de produto
                $agg = $this->agregarInfraCards($cards);

                // 4. Calcular todos os campos financeiros
                $fields = $this->calcularCamposFinanceiro($agg, $financCard);

                // Vincular IDs dos cards cat/284/ ao campo de link
                $fields[self::F2_PRODUTOS_284] = array_map(fn($c) => (int)$c['id'], $cards);

                // 5. Atualizar card cat/210/ com um único crm.item.update
                $ok = $this->bitrix->updateItem(self::BX_ENTITY_TYPE, $financCard['id'], $fields);
                if (!$ok) {
                    throw new Exception("Falha ao atualizar card financeiro {$financCard['id']}");
                }

                if ($financCard['created']) {
                    $createdCount++;
                } else {
                    $updatedCount++;
                }

                $totalFatura = number_format((float)($fields['opportunity'] ?? 0), 2, '.', '');
                $this->addLog("Empresa {$companyId}: card={$financCard['id']} total_fatura={$totalFatura}");

            } catch (Exception $e) {
                $errors++;
                $this->addLog("ERRO empresa {$companyId}: " . $e->getMessage());
            }
        }

        return [
            'periodo'  => $periodo['referencia'],
            'inicio'   => $periodo['inicio']->format('Y-m-d'),
            'fim'      => $periodo['fim']->format('Y-m-d'),
            'empresas' => count($porEmpresa),
            'updated'  => $updatedCount,
            'created'  => $createdCount,
            'errors'   => $errors,
            'log'      => $this->log,
        ];
    }

    /**
     * Localiza card cat/210/ do período para a empresa; cria se inexistente (mesma lógica de encontrarOuCriarCard).
     * Retorna array com id, min_dev, min_suporte (para cálculo de horas extras) e flag created.
     */
    private function encontrarOuCriarCardFinanc(int $companyId, array $periodo, ?string $companyName): array {
        $cards = $this->bitrix->listItems(self::BX_ENTITY_TYPE, [
            'categoryId'     => self::BX_CAT_FINANC,
            'companyId'      => $companyId,
            self::F_CONTROLE => $periodo['referencia'],
        ], ['id', self::F_MIN_DEV, self::F_MIN_SUPORTE, self::F_CONTROLE]);

        if (!empty($cards)) {
            return [
                'id'          => (int)$cards[0]['id'],
                'min_dev'     => (int)($cards[0][self::F_MIN_DEV]     ?? 0),
                'min_suporte' => (int)($cards[0][self::F_MIN_SUPORTE] ?? 0),
                'created'     => false,
            ];
        }

        $name  = $companyName ?? "Empresa #{$companyId}";
        $title = "Fatura Referente a {$periodo['referencia']} - {$name}";

        $id = $this->bitrix->createItem(self::BX_ENTITY_TYPE, [
            'categoryId'        => self::BX_CAT_FINANC,
            'stageId'           => 'DT1054_210:NEW',
            'title'             => $title,
            'companyId'         => $companyId,
            self::F_COMPETENCIA => $periodo['refDate'],
            self::F_CONTROLE    => $periodo['referencia'],
        ]);

        if (!$id) {
            throw new Exception("Falha ao criar card financeiro empresa {$companyId}");
        }

        $this->addLog("Card financeiro criado id={$id} empresa={$companyId}");
        return ['id' => $id, 'min_dev' => 0, 'min_suporte' => 0, 'created' => true];
    }

    /**
     * Agrega cards cat/284/ por tipo de produto:
     * — PROD_MENSAL: soma de horas dev/sup e captura da taxa horária
     * — demais produtos: soma do opportunity (valor mensal do produto)
     */
    private function agregarInfraCards(array $cards): array {
        $mensal = ['horas_dev' => 0.0, 'horas_sup' => 0.0, 'vh_dev' => 0.0, 'vh_sup' => 0.0];
        $infra  = [
            self::PROD_SRV_RDP    => 0.0,
            self::PROD_SRV_VM     => 0.0,
            self::PROD_SRV_DADOS  => 0.0,
            self::PROD_SRV_SIS    => 0.0,
            self::PROD_HOSPEDAGEM => 0.0,
            self::PROD_GESTAO     => 0.0,
            self::PROD_API_CNPJ   => 0.0,
            self::PROD_API_CLICK  => 0.0,
            self::PROD_API_RFB    => 0.0,
            self::PROD_API_ZAP    => 0.0,
            self::PROD_VONO       => 0.0,
        ];

        foreach ($cards as $c) {
            $prod = (int)($c[self::I_PRODUTO] ?? 0);

            if (in_array($prod, self::PROD_MENSAL, true)) {
                $mensal['horas_dev'] += (float)($c[self::I_HORAS_DEV] ?? 0);
                $mensal['horas_sup'] += (float)($c[self::I_HORAS_SUP] ?? 0);
                $vh = $this->parseMoneyValue($c[self::I_VH_DEV]);
                if ($vh > 0.0) $mensal['vh_dev'] = $vh;
                $vh = $this->parseMoneyValue($c[self::I_VH_SUP]);
                if ($vh > 0.0) $mensal['vh_sup'] = $vh;
            } elseif (array_key_exists($prod, $infra)) {
                $infra[$prod] += (float)($c['opportunity'] ?? 0);
            }
        }

        return ['mensal' => $mensal, 'infra' => $infra];
    }

    /**
     * Deriva todos os campos monetários e de tempo para o card cat/210/.
     * $financCard deve conter min_dev e min_suporte (gravados por run()).
     */
    private function calcularCamposFinanceiro(array $agg, array $financCard): array {
        $m = $agg['mensal'];

        // Horas → minutos (I_HORAS_* vem em horas)
        $tempContDev = (int)round($m['horas_dev'] * 60);
        $tempContSup = (int)round($m['horas_sup'] * 60);

        // Tempo extra = demanda real − contratado (mín. 0)
        $tempExtraDev = max(0, $financCard['min_dev']     - $tempContDev);
        $tempExtraSup = max(0, $financCard['min_suporte'] - $tempContSup);

        $vhDev = $m['vh_dev'];
        $vhSup = $m['vh_sup'];

        $valContDev  = round(($tempContDev  / 60) * $vhDev, 2);
        $valContSup  = round(($tempContSup  / 60) * $vhSup, 2);
        $valExtraDev = round(($tempExtraDev / 60) * $vhDev, 2);
        $valExtraSup = round(($tempExtraSup / 60) * $vhSup, 2);
        $valTotalDev = $valContDev + $valExtraDev;
        $valTotalSup = $valContSup + $valExtraSup;

        $inf      = $agg['infra'];
        $valInfra = array_sum($inf);
        $totalFat = $valTotalDev + $valTotalSup + $valInfra;

        $fmt = fn(float $v): string => number_format($v, 2, '.', '') . '|BRL';

        return [
            self::F2_TEMPO_CONT_DEV  => (string)$tempContDev,
            self::F2_TEMPO_CONT_SUP  => (string)$tempContSup,
            self::F2_TEMPO_EXTRA_DEV => (string)$tempExtraDev,
            self::F2_TEMPO_EXTRA_SUP => (string)$tempExtraSup,
            self::F2_VALOR_CONT_DEV  => $fmt($valContDev),
            self::F2_VALOR_CONT_SUP  => $fmt($valContSup),
            self::F2_VALOR_EXTRA_DEV => $fmt($valExtraDev),
            self::F2_VALOR_EXTRA_SUP => $fmt($valExtraSup),
            self::F2_VALOR_TOTAL_DEV => $fmt($valTotalDev),
            self::F2_VALOR_TOTAL_SUP => $fmt($valTotalSup),
            self::F2_SRV_RDP         => $fmt($inf[self::PROD_SRV_RDP]),
            self::F2_SRV_VM          => $fmt($inf[self::PROD_SRV_VM]),
            self::F2_SRV_DADOS       => $fmt($inf[self::PROD_SRV_DADOS]),
            self::F2_SRV_SIS         => $fmt($inf[self::PROD_SRV_SIS]),
            self::F2_HOSPEDAGEM      => $fmt($inf[self::PROD_HOSPEDAGEM]),
            self::F2_GESTAO          => $fmt($inf[self::PROD_GESTAO]),
            self::F2_API_CNPJ        => $fmt($inf[self::PROD_API_CNPJ]),
            self::F2_API_CLICK       => $fmt($inf[self::PROD_API_CLICK]),
            self::F2_API_RFB         => $fmt($inf[self::PROD_API_RFB]),
            self::F2_API_ZAP         => $fmt($inf[self::PROD_API_ZAP]),
            self::F2_VONO            => $fmt($inf[self::PROD_VONO]),
            self::F2_VALOR_INFRA     => $fmt($valInfra),
            'opportunity'            => $totalFat,
        ];
    }

    /** Converte campo money "130|BRL" ou "130.50|BRL" (ou plain float) para float. */
    private function parseMoneyValue(mixed $val): float {
        if ($val === null || $val === '' || $val === false) return 0.0;
        $s = is_array($val) ? (string)($val[0] ?? '') : (string)$val;
        return (float)explode('|', $s)[0];
    }

    private function erroFinancRetorno(): array {
        return [
            'periodo'  => '',
            'inicio'   => '',
            'fim'      => '',
            'empresas' => 0,
            'updated'  => 0,
            'created'  => 0,
            'errors'   => 1,
            'log'      => $this->log,
        ];
    }

    /**
     * Normaliza o valor de um campo CRM link (ex: "D1130_123", 123, ["D1130_123"]) → int ID.
     */
    private function parseCrmLinkId(mixed $val): int {
        if (is_array($val)) $val = $val[0] ?? null;
        if ($val === null || $val === '' || $val === false) return 0;
        if (is_int($val)) return $val;
        if (is_string($val)) {
            preg_match('/(\d+)$/', $val, $m);
            return (int)($m[1] ?? 0);
        }
        return 0;
    }

    private function batchFetchCompanyNames(array $companyIds): array {
        $cache = [];
        foreach (array_chunk($companyIds, 50) as $chunk) {
            $cmd = [];
            foreach ($chunk as $i => $cid) {
                $cmd["co{$i}"] = 'crm.company.get?' . http_build_query(
                    ['id' => $cid], '', '&', PHP_QUERY_RFC3986
                );
            }
            $resp    = $this->bitrix->call('batch', ['halt' => 0, 'cmd' => $cmd]);
            $results = $resp['result'] ?? [];
            foreach ($chunk as $i => $cid) {
                $co = $results["co{$i}"] ?? null;
                $cache[$cid] = $co['TITLE'] ?? "Empresa #{$cid}";
            }
        }
        return $cache;
    }

    private function erroInfraRetorno(): array {
        return [
            'periodo'      => '',
            'inicio'       => '',
            'fim'          => '',
            'total_source' => 0,
            'created'      => 0,
            'skipped'      => 0,
            'errors'       => 1,
            'log'          => $this->log,
        ];
    }

    private function erroRetorno(string $msg): array {
        $this->addLog($msg);
        return [
            'periodo'        => '',
            'inicio'         => '',
            'fim'            => '',
            'demandas_total' => 0,
            'empresas'       => 0,
            'atualizados'    => 0,
            'erros'          => 1,
            'log'            => $this->log,
        ];
    }

    private function addLog(string $msg): void {
        $ts = date('H:i:s');
        $this->log[] = "[{$ts}] {$msg}";
        error_log("[FinanceiroSync] {$msg}");
    }
}
