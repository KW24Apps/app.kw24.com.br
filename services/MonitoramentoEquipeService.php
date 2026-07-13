<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../dao/ConfiguracaoDAO.php';
require_once __DIR__ . '/../services/BitrixService.php';

/**
 * Agregação do painel "Equipe" — Monitoramento KW24.
 * Fonte: SPA 1054 / cat 208 (Demandas em Execução).
 */
class MonitoramentoEquipeService {
    private const BX_ENTITY_TYPE  = 1054;
    private const BX_CAT_DEMANDAS = 208;
    private const STAGE_SUCCESS   = 'DT1054_208:SUCCESS';

    private const F_TIPO_CHAMADO = 'ufCrm41_1737476320';
    private const F_RESPONSAVEL  = 'ufCrm41_1727877194';
    private const F_TEMPO_FINAL  = 'ufCrm41_1751475675';
    private const F_DATA_FIN     = 'ufCrm41_1778777816';

    private const TIPOS_SUPORTE = [21204, 21206];
    private const TIPOS_DEV     = [21208, 21210];

    private const EQUIPE = [
        ['nome' => 'Gabriel Acker',   'bitrixUserId' => 21,    'stageId' => 'DT1054_208:UC_1GHUI5'],
        ['nome' => 'Jeferson Santos', 'bitrixUserId' => 83,    'stageId' => 'DT1054_208:UC_ASEGSF'],
        ['nome' => 'Tainá Oliveira',  'bitrixUserId' => 11292, 'stageId' => 'DT1054_208:UC_DBW95I'],
        ['nome' => 'Michael Botelho', 'bitrixUserId' => 12126, 'stageId' => 'DT1054_208:UC_F3HI83'],
    ];

    private BitrixService $bitrix;

    public function __construct() {
        $this->bitrix = new BitrixService();
    }

    public function isConfigured(): bool {
        return $this->bitrix->isConfigured();
    }

    public function getDados(): array {
        $ciclo = $this->calcularCicloAtual();

        $agg = [];
        foreach (self::EQUIPE as $m) {
            $agg[$m['bitrixUserId']] = [
                'andamento'  => [
                    'suporte'         => ['count' => 0, 'cards' => []],
                    'desenvolvimento' => ['count' => 0, 'cards' => []],
                ],
                'finalizado' => [
                    'suporte'         => ['count' => 0, 'minutos' => 0, 'cards' => []],
                    'desenvolvimento' => ['count' => 0, 'minutos' => 0, 'cards' => []],
                ],
            ];
        }

        $this->agregarAndamento($agg);
        $this->agregarFinalizado($agg, $ciclo);

        $equipe = [];
        foreach (self::EQUIPE as $m) {
            $uid = $m['bitrixUserId'];
            $equipe[] = [
                'nome'         => $m['nome'],
                'bitrixUserId' => $uid,
                'andamento'    => $agg[$uid]['andamento'],
                'finalizado'   => $agg[$uid]['finalizado'],
            ];
        }

        return [
            'periodo'    => [
                'inicio' => $ciclo['inicio']->format('Y-m-d'),
                'fim'    => $ciclo['fim']->format('Y-m-d'),
            ],
            'bitrixBase' => $this->bitrix->getPortalBaseUrl(),
            'equipe'     => $equipe,
        ];
    }

    private function bucketDoTipo(int $tipo): ?string {
        if (in_array($tipo, self::TIPOS_SUPORTE, true)) return 'suporte';
        if (in_array($tipo, self::TIPOS_DEV, true))     return 'desenvolvimento';
        return null;
    }

    /** "Em andamento" — cards parados no estágio pessoal de cada membro, agrupados por tipo. */
    private function agregarAndamento(array &$agg): void {
        $stageToUid = [];
        foreach (self::EQUIPE as $m) $stageToUid[$m['stageId']] = $m['bitrixUserId'];

        $cards = $this->bitrix->listItems(
            self::BX_ENTITY_TYPE,
            [
                'categoryId' => self::BX_CAT_DEMANDAS,
                'stageId'    => array_keys($stageToUid),
            ],
            ['id', 'title', 'stageId', self::F_TIPO_CHAMADO],
            0
        );

        foreach ($cards as $c) {
            $bucket = $this->bucketDoTipo((int)($c[self::F_TIPO_CHAMADO] ?? 0));
            if ($bucket === null) continue; // tipo fora de escopo — exclui silenciosamente

            $uid = $stageToUid[$c['stageId'] ?? ''] ?? null;
            if ($uid === null) continue;

            $agg[$uid]['andamento'][$bucket]['count']++;
            $agg[$uid]['andamento'][$bucket]['cards'][] = [
                'id'    => (int)$c['id'],
                'title' => $c['title'] ?? '',
            ];
        }
    }

    /** "Finalizado no ciclo" — cards SUCCESS com data de finalização dentro do ciclo de faturamento. */
    private function agregarFinalizado(array &$agg, array $ciclo): void {
        $uidsValidos = array_column(self::EQUIPE, 'bitrixUserId');

        // Alarga 1 dia no início do filtro Bitrix — mesmo ajuste de timezone usado em
        // FinanceiroSync::buscarDemandas(); o re-filtro por data real abaixo corrige.
        $inicioFiltro = clone $ciclo['inicio'];
        $inicioFiltro->sub(new DateInterval('P1D'));

        $inicioStr = $inicioFiltro->format('Y-m-d\T00:00:00');
        $fimStr    = $ciclo['fim']->format('Y-m-d\T23:59:59');

        $cards = $this->bitrix->listItems(
            self::BX_ENTITY_TYPE,
            [
                'categoryId'            => self::BX_CAT_DEMANDAS,
                'stageId'               => self::STAGE_SUCCESS,
                '>=' . self::F_DATA_FIN => $inicioStr,
                '<=' . self::F_DATA_FIN => $fimStr,
            ],
            ['id', 'title', self::F_TIPO_CHAMADO, self::F_RESPONSAVEL, self::F_TEMPO_FINAL, self::F_DATA_FIN],
            0
        );

        $inicioReal = $ciclo['inicio']->format('Y-m-d');
        $fimReal    = $ciclo['fim']->format('Y-m-d');

        foreach ($cards as $c) {
            $dataFinRaw = $c[self::F_DATA_FIN]    ?? null;
            $tempoRaw   = $c[self::F_TEMPO_FINAL] ?? null;

            // Edge case: falta tempo final ou data de finalização — excluir inteiramente do ciclo
            if ($dataFinRaw === null || $dataFinRaw === '' || $tempoRaw === null || $tempoRaw === '') continue;

            $dataFin = substr((string)$dataFinRaw, 0, 10);
            if ($dataFin < $inicioReal || $dataFin > $fimReal) continue; // fora do ciclo real

            $bucket = $this->bucketDoTipo((int)($c[self::F_TIPO_CHAMADO] ?? 0));
            if ($bucket === null) continue; // tipo fora de escopo — exclui de todos os buckets

            $mins = (int)$tempoRaw;

            $responsaveis = (array)($c[self::F_RESPONSAVEL] ?? []);
            foreach ($responsaveis as $ruid) {
                $ruid = (int)$ruid;
                if (!in_array($ruid, $uidsValidos, true)) continue; // não é membro rastreado nesta tela
                $agg[$ruid]['finalizado'][$bucket]['count']++;
                $agg[$ruid]['finalizado'][$bucket]['minutos'] += $mins;
                $agg[$ruid]['finalizado'][$bucket]['cards'][] = [
                    'id'      => (int)$c['id'],
                    'title'   => $c['title'] ?? '',
                    'minutos' => $mins,
                ];
            }
        }
    }

    /** Ciclo de faturamento atual — mesma regra/config do módulo Financeiro (dia 27 → dia 26). */
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
