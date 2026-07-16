<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../services/BitrixService.php';
require_once __DIR__ . '/../services/TipoChamadoCatalogo.php';

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

    private const F_NOME        = 'ufCrm41_1737476071'; // Nome do Chamado
    private const F_TIPO        = 'ufCrm41_1737476320'; // Tipo de Chamado
    private const F_RESP        = 'ufCrm41_1727877194'; // Responsável pelo Chamado (array de user IDs)
    private const F_SOLICITANTE = 'ufCrm41_1737477724'; // Solicitante (texto livre, não é usuário Bitrix)
    private const F_RESUMO      = 'ufCrm41_1727788277'; // Comentário Resumo (texto longo, exibido só ao expandir)
    private const F_PRIORIDADE  = 'ufCrm41_1742220550'; // Prioridade
    private const F_PREVISAO    = 'ufCrm41_1737476106'; // Data Prevista para término do Chamado (não obrigatório)

    // Cores reaproveitadas da paleta já usada em Tipo/Equipe (nenhuma cor nova) — ordenadas por
    // urgência visual: Urgente o mais alarmante (vermelho, mesmo tom de erro/atrasada), Baixa a
    // mais neutra (cinza, mesmo tom de "Outros").
    private const PRIORIDADES = [
        21886 => ['label' => 'Urgente', 'cor' => '#fc8181'],
        21812 => ['label' => 'Alta',    'cor' => '#f6ad55'],
        21814 => ['label' => 'Média',   'cor' => '#0DC2FF'],
        21816 => ['label' => 'Baixa',   'cor' => '#a0aec0'],
    ];

    // Ordem real do pipeline do Funil 208 (mesmo critério já validado em
    // MonitoramentoFunilService — "Demandas - KW24"/UC_ZZ9RPV confirmado morto, removido) — usada
    // tanto pro filtro de estágios abertos quanto pro sort por Etapa no frontend (etapaOrdem,
    // abaixo), que precisa da posição no pipeline, não da ordem alfabética do label.
    private const ETAPAS = [
        'DT1054_208:NEW'         => 'Fila - Desenvolvimento',
        'DT1054_208:PREPARATION' => 'Fila - Suporte',
        'DT1054_208:CLIENT'      => 'Fila - Demandas Programadas',
        'DT1054_208:UC_UNOPWM'   => 'Pendente Cliente',
        'DT1054_208:UC_1GHUI5'   => 'Gabriel Acker',
        'DT1054_208:UC_ASEGSF'   => 'Jeferson Santos',
        'DT1054_208:UC_DBW95I'   => 'Tainá Oliveira',
        'DT1054_208:UC_F3HI83'   => 'Michael Botelho',
        'DT1054_208:UC_NUJRTQ'   => 'Treinamento/Validação',
    ];

    private BitrixService $bitrix;

    public function __construct() {
        $this->bitrix = new BitrixService(BitrixService::getWebhookForOrganizacao(BitrixService::ORG_GRUPO_NIMBUS));
    }

    public function isConfigured(): bool {
        return $this->bitrix->isConfigured();
    }

    /**
     * $detalheCompleto controla se 'resumo' e 'comentarios' (as duas causas do peso do payload —
     * medido ~27KB com detalhe vs. muito menos sem) entram na resposta. Default true preserva o
     * comportamento de sempre pra quem já consome este service sem passar o parâmetro (a tela
     * Chamados abertos via api/monitoramento-chamados.php, que precisa do conteúdo pra expandir
     * a linha) — só monitoramento-resumo.php passa false por padrão (ver ?detalhe=completo).
     * 'temChat' continua sempre presente mesmo em modo resumo (só o CONTEÚDO do chat é que sai);
     * como isso só depende de existir um chatId (não do conteúdo das mensagens), a busca de
     * mensagens (getCrmChatMessages) é pulada inteiramente em modo resumo — economia real de
     * chamada à API do Bitrix24, não só de tamanho de payload.
     */
    public function getDados(int $mensagensPorChamado = 5, bool $detalheCompleto = true): array {
        // Sem filtro por Tipo aqui de propósito — um tipo novo que apareça no futuro (fora do
        // catálogo conhecido) deve continuar aparecendo no painel (cai em "Outros" no
        // frontend), não desaparecer silenciosamente por não estar numa lista fixa.
        $selectFields = ['id', self::F_NOME, 'title', self::F_TIPO, self::F_PRIORIDADE, 'stageId', self::F_RESP, 'createdTime', self::F_SOLICITANTE, 'companyId', self::F_PREVISAO];
        if ($detalheCompleto) $selectFields[] = self::F_RESUMO;

        $items = $this->bitrix->listItems(
            self::ENTITY_TYPE,
            [
                'categoryId' => self::CAT_DEMANDAS,
                'stageId'    => array_keys(self::ETAPAS),
            ],
            $selectFields,
            0
        );

        // Responsáveis podem ser qualquer colaborador (não só a equipe de 4 do Equipe/Tarefas) —
        // resolvidos em lote via user.get.
        $respIds = [];
        foreach ($items as $it) {
            foreach ((array)($it[self::F_RESP] ?? []) as $uid) $respIds[] = (int)$uid;
        }
        $nomesUsuarios = $respIds ? $this->bitrix->getUserNames($respIds) : [];

        // companyId é campo nativo do item (não UF customizado) — não lido/exibido neste
        // painel até agora (não tinha como saber de qual cliente é um chamado, olhando só a
        // tela). Chamados sem empresa vinculada (companyId=0/ausente) ficam com
        // empresaNome=null — o frontend mostra "—", não um erro nem "Empresa #0".
        $companyIds = [];
        foreach ($items as $it) {
            $cid = (int)($it['companyId'] ?? 0);
            if ($cid) $companyIds[] = $cid;
        }
        $nomesEmpresas = $companyIds ? $this->bitrix->getCompanyNames($companyIds) : [];

        // Chat: resolve o chat vinculado de cada card (sempre — é o que define 'temChat'), depois
        // busca mensagens recentes dos chats encontrados (só em modo detalhado — ver docblock
        // acima). Usa sempre o webhook principal/organizacional deste service ($this->bitrix,
        // mesmo usado pra tudo o mais aqui) — mecanismo completamente separado do de
        // WebhooksPessoaisAtendimento (webhooks pessoais por pessoa, usado só pelo painel
        // Atendimento/Open Line, não aqui). Se o webhook principal não tiver acesso a algum
        // chat específico, o card fica com chatErro (ver abaixo) — caso esperado, não é bug.
        $itemIds        = array_column($items, 'id');
        $chatIdsPorItem = $itemIds ? $this->bitrix->getCrmChatIds(self::ENTITY_TYPE, $itemIds) : [];

        $mensagensPorChat = [];
        if ($detalheCompleto && $chatIdsPorItem) {
            $mensagensPorChat = $this->bitrix->getCrmChatMessages(array_values($chatIdsPorItem), $mensagensPorChamado);
        }

        $etapaOrdem = array_flip(array_keys(self::ETAPAS));

        $chamados = [];
        foreach ($items as $it) {
            $id         = (int)$it['id'];
            $tipo       = (int)($it[self::F_TIPO] ?? 0);
            $prioridade = (int)($it[self::F_PRIORIDADE] ?? 0);
            $stageId    = $it['stageId'] ?? '';
            $companyId  = (int)($it['companyId'] ?? 0);

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
                            'mensagem' => $this->limparBBCode((string)($m['text'] ?? '')),
                        ];
                    }
                }
            }

            $chamado = [
                'id'             => $id,
                'titulo'         => $it[self::F_NOME] ?: ($it['title'] ?? ''),
                'tipo'           => $tipo,
                'tipoLabel'      => TipoChamadoCatalogo::label($tipo),
                'tipoCor'        => TipoChamadoCatalogo::cor($tipo),
                'prioridade'     => $prioridade,
                'prioridadeLabel'=> self::PRIORIDADES[$prioridade]['label'] ?? null,
                'prioridadeCor'  => self::PRIORIDADES[$prioridade]['cor'] ?? '#a0aec0',
                'etapa'          => $stageId, // stageId bruto — usado pelo clique-pra-filtrar do gráfico de distribuição do Funil
                'etapaLabel'     => self::ETAPAS[$stageId] ?? $stageId, // defensivo — não deveria faltar (ver nota da classe)
                'etapaOrdem'     => $etapaOrdem[$stageId] ?? 999, // posição no pipeline real (ver ETAPAS) — usado pro sort por Etapa no frontend, não a ordem alfabética do label
                'empresaId'      => $companyId ?: null,
                'empresaNome'    => $companyId ? ($nomesEmpresas[$companyId] ?? "Empresa #{$companyId}") : null,
                'responsaveis'   => $responsaveis,
                'solicitante'    => trim((string)($it[self::F_SOLICITANTE] ?? '')),
                'createdTime'    => $it['createdTime'] ?? null,
                'previsao'       => substr((string)($it[self::F_PREVISAO] ?? ''), 0, 10) ?: null,
                'temChat'        => $chatId !== null,
                'chatErro'       => $chatErro,
            ];
            if ($detalheCompleto) {
                $chamado['resumo']      = trim((string)($it[self::F_RESUMO] ?? ''));
                $chamado['comentarios'] = $mensagens;
            }
            $chamados[] = $chamado;
        }

        usort($chamados, function ($a, $b) {
            $ta = $a['createdTime'] ? strtotime($a['createdTime']) : PHP_INT_MAX;
            $tb = $b['createdTime'] ? strtotime($b['createdTime']) : PHP_INT_MAX;
            return $ta <=> $tb; // mais antigo primeiro
        });

        return [
            'bitrixBase'    => $this->bitrix->getPortalBaseUrl(),
            'total'         => count($chamados),
            'chamados'      => $chamados,
            'catalogoTipos' => TipoChamadoCatalogo::paraPills(),
        ];
    }

    /**
     * Remove marcação BBCode do Bitrix nas mensagens de chat (ex.: "[USER=21]Nome[/USER]" ->
     * "Nome"). Mesma lógica de MonitoramentoTarefasService::limparBBCode() — duplicada aqui
     * porque as duas classes não têm uma base comum.
     */
    private function limparBBCode(string $msg): string {
        $msg = str_replace('[*]', '• ', $msg);
        $msg = preg_replace('/\[(\w+)(=[^\]]*)?\](.*?)\[\/\1\]/s', '$3', $msg);
        $msg = preg_replace('/\[\/?\w+(=[^\]]*)?\]/', '', $msg);
        return trim($msg);
    }
}
