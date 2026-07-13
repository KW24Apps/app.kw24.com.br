<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../services/BitrixService.php';

/**
 * Agregação do painel "Chamados abertos" — Monitoramento KW24.
 * Fonte: SPA 1054 / Funil 208 ("Demandas em Execução") — mesma fonte do painel Equipe, mas SEM
 * escopo de equipe: mostra a fila inteira de chamados abertos do funil, não só os 4 monitorados
 * no Equipe/Tarefas.
 *
 * "Aberto" = filtro POSITIVO pelos 10 estágios não-terminais conhecidos (não um NOT-IN dos 3
 * terminais) — confirmado por teste real que `!stageId` com array (ou grupos LOGIC) retorna
 * INVALID_ARG_VALUE neste método; filtrar por IN funciona normalmente. Também confirmado por
 * teste real que a soma dos 10 não-terminais + os 3 terminais (SUCCESS/FAIL/UC_WI65JW) bate
 * exatamente com o total bruto do funil — não há estágio "esquecido" fora deste mapa.
 */
class MonitoramentoChamadosService {
    private const ENTITY_TYPE  = 1054;
    private const CAT_DEMANDAS = 208;

    private const F_NOME = 'ufCrm41_1737476071'; // Nome do Chamado
    private const F_TIPO = 'ufCrm41_1737476320'; // Tipo de Chamado
    private const F_RESP = 'ufCrm41_1727877194'; // Responsável pelo Chamado (array de user IDs)

    private const ETAPAS = [
        'DT1054_208:NEW'         => 'Fila - Desenvolvimento',
        'DT1054_208:PREPARATION' => 'Fila - Suporte',
        'DT1054_208:UC_ZZ9RPV'   => 'Demandas - KW24',
        'DT1054_208:CLIENT'      => 'Fila - Demandas Programadas',
        'DT1054_208:UC_UNOPWM'   => 'Pendente Cliente',
        'DT1054_208:UC_1GHUI5'   => 'Gabriel Acker',
        'DT1054_208:UC_ASEGSF'   => 'Jeferson Santos',
        'DT1054_208:UC_DBW95I'   => 'Tainá Oliveira',
        'DT1054_208:UC_F3HI83'   => 'Michael Botelho',
        'DT1054_208:UC_NUJRTQ'   => 'Treinamento/Validação',
    ];

    // MVP padrão: os 5 tipos faturáveis. "Mostrar todos os tipos" acrescenta os 3 extras.
    // Cobrança (23322) está incluída no conjunto extra por instrução da tarefa, mas confirmado por
    // teste real que não aparece atualmente em nenhum chamado ABERTO do Funil 208 (só 1 ocorrência
    // histórica, já finalizada) — condiz com a nota de que Cobrança normalmente vive no funil de
    // faturamento (210), não no 208.
    private const TIPOS_PADRAO = [21204, 21206, 21208, 21210, 28354];
    private const TIPOS_EXTRA  = [24458, 21216, 23322];

    private const TIPO_LABELS = [
        21204 => 'Suporte Bitrix24',
        21206 => 'Suporte Técnico',
        21208 => 'Desenvolvimento - Melhoria',
        21210 => 'Desenvolvimento - Implementação',
        28354 => 'Projeto',
        24458 => 'Desenvolvimento - Correção',
        21216 => 'INFRA',
        23322 => 'Cobrança',
    ];

    private const TIPO_CORES = [
        21204 => '#0DC2FF',
        21206 => '#0DC2FF',
        21208 => '#b794f4',
        21210 => '#b794f4',
        28354 => '#26FF93',
        24458 => '#b794f4',
        21216 => '#f6ad55',
        23322 => '#fc8181',
    ];

    private BitrixService $bitrix;

    public function __construct() {
        $this->bitrix = new BitrixService(BitrixService::getWebhookForOrganizacao(BitrixService::ORG_GRUPO_NIMBUS));
    }

    public function isConfigured(): bool {
        return $this->bitrix->isConfigured();
    }

    public function getDados(int $mensagensPorChamado = 5): array {
        $tiposTodos = array_merge(self::TIPOS_PADRAO, self::TIPOS_EXTRA);

        $items = $this->bitrix->listItems(
            self::ENTITY_TYPE,
            [
                'categoryId' => self::CAT_DEMANDAS,
                'stageId'    => array_keys(self::ETAPAS),
                self::F_TIPO => $tiposTodos,
            ],
            ['id', self::F_NOME, 'title', self::F_TIPO, 'stageId', self::F_RESP, 'createdTime'],
            0
        );

        // Responsáveis podem ser qualquer colaborador (não só a equipe de 4 do Equipe/Tarefas) —
        // resolvidos em lote via user.get.
        $respIds = [];
        foreach ($items as $it) {
            foreach ((array)($it[self::F_RESP] ?? []) as $uid) $respIds[] = (int)$uid;
        }
        $nomesUsuarios = $respIds ? $this->bitrix->getUserNames($respIds) : [];

        // Chat: resolve o chat vinculado de cada card, depois busca mensagens recentes dos chats
        // encontrados. ACCESS_ERROR é esperado e comum aqui — ver BitrixService::getCrmChatMessages().
        $itemIds          = array_column($items, 'id');
        $chatIdsPorItem   = $itemIds ? $this->bitrix->getCrmChatIds(self::ENTITY_TYPE, $itemIds) : [];
        $mensagensPorChat = $chatIdsPorItem
            ? $this->bitrix->getCrmChatMessages(array_values($chatIdsPorItem), $mensagensPorChamado)
            : [];

        $chamados = [];
        foreach ($items as $it) {
            $id      = (int)$it['id'];
            $tipo    = (int)($it[self::F_TIPO] ?? 0);
            $stageId = $it['stageId'] ?? '';

            $responsaveis = [];
            foreach ((array)($it[self::F_RESP] ?? []) as $uid) {
                $uid = (int)$uid;
                $responsaveis[] = [
                    'bitrixUserId' => $uid,
                    'nome'         => $nomesUsuarios[$uid] ?? ('Usuário #' . $uid),
                ];
            }

            $chatId   = $chatIdsPorItem[$id] ?? null;
            $chatInfo = $chatId !== null ? ($mensagensPorChat[$chatId] ?? null) : null;

            $mensagens = [];
            $chatErro  = null;
            if ($chatInfo !== null) {
                if (!empty($chatInfo['erro'])) {
                    $chatErro = $chatInfo['erro'];
                } else {
                    $usuarios = $chatInfo['usuarios'] ?? [];
                    foreach (($chatInfo['mensagens'] ?? []) as $m) {
                        $mensagens[] = [
                            'autor'    => $usuarios[(int)($m['author_id'] ?? 0)] ?? ('Usuário #' . ($m['author_id'] ?? '?')),
                            'data'     => $m['date'] ?? null,
                            'mensagem' => (string)($m['text'] ?? ''),
                        ];
                    }
                }
            }

            $chamados[] = [
                'id'           => $id,
                'titulo'       => $it[self::F_NOME] ?: ($it['title'] ?? ''),
                'tipo'         => $tipo,
                'tipoLabel'    => self::TIPO_LABELS[$tipo] ?? ('Tipo #' . $tipo),
                'tipoCor'      => self::TIPO_CORES[$tipo] ?? '#a0aec0',
                'tipoPadrao'   => in_array($tipo, self::TIPOS_PADRAO, true),
                'etapaLabel'   => self::ETAPAS[$stageId] ?? $stageId, // defensivo — não deveria faltar (ver nota da classe)
                'responsaveis' => $responsaveis,
                'createdTime'  => $it['createdTime'] ?? null,
                'temChat'      => $chatId !== null,
                'chatErro'     => $chatErro,
                'comentarios'  => $mensagens,
            ];
        }

        usort($chamados, function ($a, $b) {
            $ta = $a['createdTime'] ? strtotime($a['createdTime']) : PHP_INT_MAX;
            $tb = $b['createdTime'] ? strtotime($b['createdTime']) : PHP_INT_MAX;
            return $ta <=> $tb; // mais antigo primeiro
        });

        return [
            'bitrixBase'  => $this->bitrix->getPortalBaseUrl(),
            'total'       => count($chamados),
            'chamados'    => $chamados,
            'tiposPadrao' => self::TIPOS_PADRAO,
            'tiposExtra'  => self::TIPOS_EXTRA,
        ];
    }
}
