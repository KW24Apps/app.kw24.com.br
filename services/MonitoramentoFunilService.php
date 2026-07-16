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

    // Estágios não-terminais do Funil 208 — mesmo mapa/critério de "aberto" já validado em
    // MonitoramentoChamadosService (soma bate com o total bruto do funil, sem estágio
    // esquecido). Duplicado aqui (não reaproveita a outra classe) pela mesma convenção já
    // usada no resto do código pra esse tipo de mapa pequeno e autocontido.
    private const STAGE_DEV         = 'DT1054_208:NEW';
    private const STAGE_SUPORTE     = 'DT1054_208:PREPARATION';
    private const STAGE_PROGRAMADAS = 'DT1054_208:CLIENT';

    // Os 6 estágios agregados no bucket "Demandas em Execução" (ver distribuicaoAbertos()) —
    // 1 total somado + sub-itens expansíveis com a contagem individual de cada um.
    private const STAGES_EXECUCAO = [
        'DT1054_208:UC_UNOPWM' => 'Pendente Cliente',
        'DT1054_208:UC_1GHUI5' => 'Gabriel Acker',
        'DT1054_208:UC_ASEGSF' => 'Jeferson Santos',
        'DT1054_208:UC_DBW95I' => 'Tainá Oliveira',
        'DT1054_208:UC_F3HI83' => 'Michael Botelho',
        'DT1054_208:UC_NUJRTQ' => 'Treinamento/Validação',
    ];

    // 3 individuais + 6 agregados = os 9 estágios não-terminais reais do Funil 208 (confirmado
    // ao vivo no Bitrix24) — "Demandas - KW24" (DT1054_208:UC_ZZ9RPV) existia aqui antes, mas
    // foi confirmado que esse estágio não existe mais no funil real (sempre renderizava 0) —
    // removido inteiramente, sem deixar referência morta.
    private const STAGES_ABERTOS = [
        self::STAGE_DEV, self::STAGE_SUPORTE, self::STAGE_PROGRAMADAS,
        'DT1054_208:UC_UNOPWM', 'DT1054_208:UC_1GHUI5', 'DT1054_208:UC_ASEGSF',
        'DT1054_208:UC_DBW95I', 'DT1054_208:UC_F3HI83', 'DT1054_208:UC_NUJRTQ',
    ];

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
            'distribuicaoAbertos' => $this->distribuicaoAbertos(),
        ];
    }

    /**
     * Distribuição dos chamados ABERTOS (estágios não-terminais) por bucket — pra preencher o
     * espaço do card Funil com algo além dos 2 números de volume. NÃO é o critério de "cards em
     * atenção" (idade/estágio parado) — isso continua fora de escopo, aguardando os campos que o
     * usuário vai trazer depois. Aqui é só a foto atual de "quanto tem em cada fila".
     *
     * 3 buckets individuais (Fila - Desenvolvimento/Suporte/Demandas Programadas) + 1 bucket
     * agregado "Demandas em Execução" (soma dos 6 estágios de STAGES_EXECUCAO, com 'subItens'
     * pro front expandir e mostrar a contagem individual de cada um). Nenhum "Outros" catch-all
     * mais — os 9 estágios de STAGES_ABERTOS cobrem exatamente todos os estágios não-terminais
     * reais do funil (confirmado ao vivo), então a soma dos buckets sempre bate com o total de
     * chamados abertos do painel Chamados abertos sem precisar de um balde genérico.
     *
     * 'stages' em cada bucket/sub-item é a lista de stageId que ele representa — usada pelo
     * front pro clique-pra-filtrar do Chamados abertos (ver monFiltrarPorEtapa() em
     * monitoramento.php): clicar um bucket individual ou um sub-item filtra por 1 stageId só;
     * clicar o bucket agregado (recolhido) filtra pelos 6 de uma vez.
     */
    private function distribuicaoAbertos(): array {
        $items = $this->bitrix->listItems(
            self::ENTITY_TYPE,
            ['categoryId' => self::CAT_DEMANDAS, 'stageId' => self::STAGES_ABERTOS],
            ['id', 'stageId'],
            0
        );

        $contagem = array_fill_keys(self::STAGES_ABERTOS, 0);
        foreach ($items as $it) {
            $stage = $it['stageId'] ?? '';
            if (isset($contagem[$stage])) $contagem[$stage]++;
        }

        $subItens      = [];
        $totalExecucao = 0;
        foreach (self::STAGES_EXECUCAO as $stage => $label) {
            $totalExecucao += $contagem[$stage];
            $subItens[] = ['label' => $label, 'total' => $contagem[$stage], 'stages' => [$stage]];
        }

        return [
            ['label' => 'Fila - Desenvolvimento',     'total' => $contagem[self::STAGE_DEV],         'stages' => [self::STAGE_DEV]],
            ['label' => 'Fila - Suporte',              'total' => $contagem[self::STAGE_SUPORTE],     'stages' => [self::STAGE_SUPORTE]],
            ['label' => 'Fila - Demandas Programadas', 'total' => $contagem[self::STAGE_PROGRAMADAS], 'stages' => [self::STAGE_PROGRAMADAS]],
            [
                'label'    => 'Demandas em Execução',
                'total'    => $totalExecucao,
                'stages'   => array_keys(self::STAGES_EXECUCAO),
                'subItens' => $subItens,
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
