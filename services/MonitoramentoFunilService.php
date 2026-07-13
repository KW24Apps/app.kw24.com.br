<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../dao/ConfiguracaoDAO.php';
require_once __DIR__ . '/../services/BitrixService.php';

/**
 * Agregação do painel "Funil" — Monitoramento KW24 (parcial: só os 2 gráficos de volume;
 * "cards em atenção"/saúde da fila fica pra uma tarefa futura, critério ainda não definido).
 * Fonte: SPA 1054 / Funil 208 — NÃO inclui Tarefas nativas do Bitrix24 (conceito completamente
 * separado neste dashboard, coberto pelo painel Tarefas).
 */
class MonitoramentoFunilService {
    private const ENTITY_TYPE   = 1054;
    private const CAT_DEMANDAS  = 208;
    private const STAGE_SUCCESS = 'DT1054_208:SUCCESS';

    // Mesmo campo de "data de finalização" usado pelo painel Equipe.
    private const F_DATA_FIN = 'ufCrm41_1778777816';

    private BitrixService $bitrix;

    public function __construct() {
        $this->bitrix = new BitrixService(BitrixService::getWebhookForOrganizacao(BitrixService::ORG_GRUPO_NIMBUS));
    }

    public function isConfigured(): bool {
        return $this->bitrix->isConfigured();
    }

    public function getDados(): array {
        $semana  = $this->calcularSemanaAtual();
        $periodo = $this->calcularCicloAtual();

        return [
            'semana'  => [
                'inicio' => $semana['inicio']->format('Y-m-d'),
                'fim'    => $semana['fim']->format('Y-m-d'),
            ],
            'periodo' => [
                'inicio' => $periodo['inicio']->format('Y-m-d'),
                'fim'    => $periodo['fim']->format('Y-m-d'),
            ],
            'chamadosCriados' => [
                'semana'  => $this->contarCriados($semana['inicio'], $semana['fim']),
                'periodo' => $this->contarCriados($periodo['inicio'], $periodo['fim']),
            ],
            'chamadosFinalizados' => [
                'semana'  => $this->contarFinalizados($semana['inicio'], $semana['fim']),
                'periodo' => $this->contarFinalizados($periodo['inicio'], $periodo['fim']),
            ],
        ];
    }

    /**
     * Chamados criados no intervalo — filtro direto em createdTime (campo nativo do CRM item).
     * Confirmado por teste real que este campo não precisa do ajuste de "alargar 1 dia" usado
     * pra campos UF customizados (ver contarFinalizados()) — filtro exato bateu com a contagem
     * esperada num teste com item conhecido.
     */
    private function contarCriados(DateTime $inicio, DateTime $fim): int {
        $items = $this->bitrix->listItems(
            self::ENTITY_TYPE,
            [
                'categoryId'      => self::CAT_DEMANDAS,
                '>=createdTime'   => $inicio->format('Y-m-d\TH:i:s'),
                '<=createdTime'   => $fim->format('Y-m-d\TH:i:s'),
            ],
            ['id'],
            0
        );
        return count($items);
    }

    /**
     * Chamados finalizados (estágio SUCCESS) com data de finalização dentro do intervalo — mesma
     * lógica de alargar 1 dia no início do filtro + re-filtrar por data real usada em
     * MonitoramentoEquipeService::agregarFinalizado() (campo UF customizado, quirk de timezone).
     */
    private function contarFinalizados(DateTime $inicio, DateTime $fim): int {
        $inicioFiltro = clone $inicio;
        $inicioFiltro->sub(new DateInterval('P1D'));

        $items = $this->bitrix->listItems(
            self::ENTITY_TYPE,
            [
                'categoryId'            => self::CAT_DEMANDAS,
                'stageId'               => self::STAGE_SUCCESS,
                '>=' . self::F_DATA_FIN => $inicioFiltro->format('Y-m-d\T00:00:00'),
                '<=' . self::F_DATA_FIN => $fim->format('Y-m-d\T23:59:59'),
            ],
            ['id', self::F_DATA_FIN],
            0
        );

        $inicioReal = $inicio->format('Y-m-d');
        $fimReal    = $fim->format('Y-m-d');

        $count = 0;
        foreach ($items as $it) {
            $data = substr((string)($it[self::F_DATA_FIN] ?? ''), 0, 10);
            if ($data !== '' && $data >= $inicioReal && $data <= $fimReal) $count++;
        }
        return $count;
    }

    /** Semana atual — segunda 00:00 a domingo 23:59 (calendário, não ISO relativo — evita a
     *  ambiguidade de "monday this week" do PHP quando hoje é domingo). */
    private function calcularSemanaAtual(): array {
        $hoje = new DateTime();
        $dow  = (int)$hoje->format('N'); // 1 (segunda) .. 7 (domingo)

        $inicio = clone $hoje;
        $inicio->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);

        $fim = clone $inicio;
        $fim->modify('+6 days')->setTime(23, 59, 59);

        return ['inicio' => $inicio, 'fim' => $fim];
    }

    /** Ciclo de faturamento atual — mesma regra/config dos painéis Equipe e Financeiro (dia 27 → 26). */
    private function calcularCicloAtual(): array {
        $dao       = new ConfiguracaoDAO();
        $diaInicio = max(1, min(28, (int)($dao->get('financeiro_dia_inicio') ?? 27)));

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

        return ['inicio' => $inicio, 'fim' => $fim];
    }
}
