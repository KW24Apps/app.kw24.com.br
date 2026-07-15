<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../services/BitrixService.php';
require_once __DIR__ . '/../services/WebhooksPessoaisAtendimento.php';

/**
 * Agregação do painel "Atendimento" — Monitoramento KW24.
 * Fonte: Contact Center / Open Lines (Canal Aberto) da fila "Geral KW24 - Suporte"
 * (imopenlines CONFIG_ID=21), via im.recent.list — NÃO existe método REST que liste
 * sessões ativas por fila diretamente (pesquisado e confirmado); im.recent.list retorna
 * atividade recente do usuário DO WEBHOOK, filtrada aqui pela fila certa via entity_id.
 *
 * Multi-identidade (Incremento 2): im.recent.list só vê o que o DONO do webhook participa —
 * um webhook fixo só (o de automação) nunca via as conversas atendidas direto por
 * Gabriel/Jeferson/Tainá/Michael. Por isso, quando há webhooks pessoais cadastrados (ver
 * WebhooksPessoaisAtendimento), chama im.recent.list uma vez POR PESSOA e mescla os
 * resultados. Sem nenhum cadastrado ainda, cai pro comportamento de sempre (só automação) —
 * ver resolverIdentidades().
 *
 * Sem conceito de período/ciclo neste painel (mesma decisão já aplicada ao Tarefas) — o
 * que importa é o que está ativo/sem resposta agora, não um total histórico.
 *
 * tempoMedioRespostaMinutos = média SÓ sobre conversas atualmente ABERTAS. Isso já é
 * garantido de graça pela própria API: calibrado ao vivo (10 sessões confirmadas como
 * "Conversa fechada" no export nativo do Contact Center do Bitrix24, cruzadas contra
 * lines.id) que conversas fechadas NUNCA aparecem em im.recent.list — nem com LIMIT alto,
 * nem sem o filtro de fila — apesar de sessões abertas cronologicamente vizinhas (IDs antes
 * E depois) aparecerem normalmente. Ou seja, $itensFila já É "só conversas abertas" por
 * construção; não existe um valor de lines.status próprio pra "fechada" que precise ser
 * filtrado à parte (só sub-estados de conversa aberta foram observados/confirmados com
 * Gabriel: 25 = "Cliente aguardando resposta do agente", 40 = "O agente respondeu" — ver
 * statusCodigoBruto/statusRotulo abaixo). Um design anterior (Incremento: "amostra
 * ampliada") tentava um segundo cálculo com LIMIT=200 pra comparar contra esse — removido
 * porque a amostra maior nunca achava nada além do que LIMIT=50 já cobria (mesma razão: não
 * há conversa fechada pra "recuperar" aumentando o LIMIT).
 */
class MonitoramentoAtendimentoService {
    private const LINE_CONFIG_ID = 21; // "Geral KW24 - Suporte"

    // Horário comercial usado no cálculo de tempo médio de resposta — seg-sex, 8h-18h.
    private const HORA_INICIO_COMERCIAL = 8;
    private const HORA_FIM_COMERCIAL    = 18;

    // Rótulos confirmados ao vivo com Gabriel (cruzando lines.status contra a coluna "Status"
    // do export nativo do Contact Center) — só os 2 valores realmente confirmados até agora;
    // qualquer outro (5, 20 observados, mas não confirmados) fica sem rótulo (null).
    private const STATUS_FILA_ROTULOS = [
        25 => 'Cliente aguardando resposta do agente',
        40 => 'O agente respondeu',
    ];

    private BitrixService $bitrixAutomacao;

    public function __construct() {
        $this->bitrixAutomacao = new BitrixService(BitrixService::getWebhookForOrganizacao(BitrixService::ORG_GRUPO_NIMBUS));
    }

    public function isConfigured(): bool {
        return $this->bitrixAutomacao->isConfigured();
    }

    public function getDados(): array {
        $identidades = $this->resolverIdentidades();

        // Busca im.recent.list em cada identidade e mescla. Dedup por sessionId: uma conversa
        // ainda não reclamada por ninguém é entregue simultaneamente a todos os membros da
        // fila, então apareceria repetida em mais de uma resposta — mantém só a primeira
        // ocorrência (usada abaixo pra buscar o histórico com o webhook certo, já que só quem
        // participa de fato consegue ler a sessão), mas registra TODAS as identidades que a
        // viram em $vistoPor. Esse é o sinal usado pra distinguir "aguardando atendimento"
        // (ninguém reclamou ainda, todo mundo da fila vê a mesma sessão) de "sendo atendida
        // por alguém" (só quem pegou o atendimento continua vendo): ver statusFila abaixo.
        // Só é confiável com 2+ identidades reais cadastradas — com 1 só (ou no fallback de
        // automação única), toda sessão aparece pra essa única identidade de qualquer forma,
        // reclamada ou não, e não dá pra diferenciar (ver $multiIdentidade).
        $multiIdentidade = count($identidades) >= 2;
        $vistoPor  = [];
        $itensFila = [];
        foreach ($identidades as $identidade) {
            $recentes = $identidade['bitrix']->call('im.recent.list', [
                'SKIP_DIALOG'                  => 'Y',
                'SKIP_CHAT'                    => 'Y',
                'SKIP_OPENLINES'               => 'N',
                'SKIP_UNDISTRIBUTED_OPENLINES' => 'N',
                'LIMIT'                        => 50,
            ]);
            foreach ($this->filtrarPorFila($recentes['items'] ?? []) as $it) {
                $sessionId = $it['lines']['id'] ?? null;
                if ($sessionId === null) continue;
                $vistoPor[$sessionId][] = $identidade['nome'];
                if (isset($itensFila[$sessionId])) continue;
                $itensFila[$sessionId] = ['item' => $it, 'identidade' => $identidade];
            }
        }

        // Histórico completo de cada conversa — busca com o webhook da identidade que
        // efetivamente viu essa conversa (ela é participante real; a automação pode não ser).
        $historicos = [];
        foreach ($itensFila as $sessionId => $entrada) {
            $it     = $entrada['item'];
            $chatId = $it['chat']['id'] ?? null;
            if ($chatId === null) continue;

            $hist = $entrada['identidade']['bitrix']->call('imopenlines.session.history.get', [
                'CHAT_ID'    => $chatId,
                'SESSION_ID' => $sessionId,
            ]);
            $historicos[$sessionId] = $this->mensagensReaisOrdenadas($hist['message'] ?? []);
        }

        // Resolve em lote quais autores de mensagem são usuários internos reais do Bitrix24
        // (atendentes) — quem não resolve é o contato externo (conector do canal). Sempre pelo
        // webhook de automação: os webhooks pessoais são cadastrados só com escopo "im" (ver
        // WebhooksPessoaisAtendimento), podem não ter escopo "user".
        $todosSenderIds = [];
        foreach ($historicos as $msgs) {
            foreach ($msgs as $m) $todosSenderIds[] = (int)$m['senderid'];
        }
        $nomesInternos = $todosSenderIds ? $this->bitrixAutomacao->getUserNames($todosSenderIds) : [];

        $conversas        = [];
        $amostrasResposta = [];
        $bitrixBase       = $this->bitrixAutomacao->getPortalBaseUrl();

        foreach ($itensFila as $entrada) {
            $it        = $entrada['item'];
            $sessionId = $it['lines']['id'] ?? null;
            $msgs      = $historicos[$sessionId] ?? [];
            $titulo    = $it['title'] ?? '(sem título)';
            $ehGrupo   = $this->ehGrupo($titulo);

            $ultima     = end($msgs) ?: null;
            $aguardando = $ultima === null || !isset($nomesInternos[(int)$ultima['senderid']]);
            $ultimaData = $ultima['date'] ?? ($it['lines']['date_create'] ?? null);

            // Grupo de WhatsApp não é atendimento 1:1 — mensagens de vários membros externos
            // inflam o tempo de resposta com números irreais (ex.: 430h). Fica de fora das
            // amostras e das métricas do painel, só aparece na aba "Grupos" (ver getDados()).
            if (!$ehGrupo) {
                foreach ($this->paresClienteAtendente($msgs, $nomesInternos) as $par) {
                    $amostrasResposta[] = $this->minutosUteisEntre(
                        new DateTime($par['clienteData']),
                        new DateTime($par['atendenteData'])
                    );
                }
            }

            // Reclamada = só uma identidade viu essa sessão (ela pegou o atendimento);
            // aguardando atendimento = 2+ identidades ainda veem a mesma sessão (ninguém
            // reclamou, continua sendo distribuída pra fila toda). Só calculável com
            // $multiIdentidade — no fallback (1 identidade só) fica null (indeterminado).
            $vistoPorCount = count($vistoPor[$sessionId] ?? []);
            $statusFila    = null;
            $reclamadaPor  = null;
            if ($multiIdentidade) {
                if ($vistoPorCount >= 2) {
                    $statusFila = 'aguardando_atendimento';
                } else {
                    $statusFila   = 'em_atendimento';
                    $reclamadaPor = $entrada['identidade']['nome'];
                }
            }

            $statusCodigoBruto = $it['lines']['status'] ?? null;

            $conversas[] = [
                'sessionId'                   => (int)$sessionId,
                'titulo'                      => $titulo,
                'ehGrupo'                     => $ehGrupo,
                'aguardando'                  => $aguardando,
                'ultimaMensagemTexto'         => $ultima ? $this->limparBBCode((string)$ultima['text']) : null,
                'ultimaMensagemData'          => $ultimaData,
                'minutosDesdeUltimaAtividade' => $ultimaData ? round((time() - strtotime($ultimaData)) / 60) : null,
                'statusCodigoBruto'           => $statusCodigoBruto, // valor cru do lines.status — ver STATUS_FILA_ROTULOS
                'statusRotulo'                => self::STATUS_FILA_ROTULOS[$statusCodigoBruto] ?? null, // texto real, só quando confirmado
                'urlBitrix24'                 => $bitrixBase ? ($bitrixBase . '/online/?IM_HISTORY=imol|' . $sessionId) : null,
                'vistaPor'                    => $entrada['identidade']['nome'], // diagnóstico — qual identidade trouxe essa conversa
                'statusFila'                  => $statusFila,   // 'aguardando_atendimento' | 'em_atendimento' | null (indeterminado)
                'reclamadaPor'                => $reclamadaPor, // nome de quem está atendendo, só quando statusFila === 'em_atendimento'
            ];
        }

        usort($conversas, function ($a, $b) {
            $prioridadeFila = ['aguardando_atendimento' => 0, 'em_atendimento' => 1];
            $pa = $prioridadeFila[$a['statusFila']] ?? 1;
            $pb = $prioridadeFila[$b['statusFila']] ?? 1;
            if ($pa !== $pb) return $pa <=> $pb;
            if ($a['aguardando'] !== $b['aguardando']) return $b['aguardando'] <=> $a['aguardando'];
            return ($b['minutosDesdeUltimaAtividade'] ?? 0) <=> ($a['minutosDesdeUltimaAtividade'] ?? 0);
        });

        // "Conversas" nunca inclui grupo (nem nas métricas, nem na lista) — grupo só existe
        // na aba "Grupos", separada e sem métricas agregadas (ver monitoramento.php).
        $conversasSemGrupo = array_values(array_filter($conversas, fn($c) => !$c['ehGrupo']));
        $grupos             = array_values(array_filter($conversas, fn($c) => $c['ehGrupo']));

        return [
            'bitrixBase'              => $bitrixBase,
            'identidades'             => array_column($identidades, 'nome'), // diagnóstico — quais webhooks foram consultados
            'agrupamentoPorResponsavel' => $multiIdentidade, // se true, front separa em "Aguardando atendimento" / "Sendo atendida"
            'conversasAtivas' => [
                'total'      => count($conversasSemGrupo),
                'aguardando' => count(array_filter($conversasSemGrupo, fn($c) => $c['aguardando'])),
            ],
            // Média de tempo de resposta sobre conversas ABERTAS agora — garantido pela própria
            // API (ver docblock da classe), não por um filtro extra aqui; grupo continua fora
            // (ver exclusão de $ehGrupo acima).
            'tempoMedioRespostaMinutos' => $amostrasResposta
                ? round(array_sum($amostrasResposta) / count($amostrasResposta))
                : null,
            'conversas' => $conversasSemGrupo,
            'grupos'    => $grupos,
        ];
    }

    /** Detecta conversas de grupo de WhatsApp (não são atendimento 1:1) — confirmado ao vivo
     *  que o título vem no formato "WA: WhatsApp group - {nome do grupo} - {fila}". Case
     *  insensitive por segurança (o conector pode variar capitalização entre canais). */
    private function ehGrupo(string $titulo): bool {
        return stripos($titulo, 'whatsapp group') !== false;
    }

    /** Uma identidade por webhook pessoal cadastrado (ver WebhooksPessoaisAtendimento) — se
     *  nenhum estiver cadastrado ainda, cai pro comportamento de sempre (só o webhook de
     *  automação), sem quebrar nada antes do Gabriel popular a config nova. */
    private function resolverIdentidades(): array {
        $cadastradas = (new WebhooksPessoaisAtendimento())->listar();
        if (!$cadastradas) {
            return [['nome' => 'Automação', 'bitrix' => $this->bitrixAutomacao]];
        }

        $identidades = [];
        foreach ($cadastradas as $p) {
            $identidades[] = [
                'nome'   => $p['nome'] ?: 'Sem nome',
                'bitrix' => new BitrixService($p['webhookUrl'] ?? ''),
            ];
        }
        return $identidades;
    }

    /** Mantém só os itens de im.recent.list cujo entity_id aponta pra fila configurada (2º campo,
     *  formato "{conector}|{LINE_ID}|{id_externo}|{...}") — confirmado na doc oficial de
     *  im.recent.list e validado ao vivo; título/nome do chat NÃO é sinal confiável (pode conter
     *  o nome de outra marca/fila do mesmo portal multi-empresa). */
    private function filtrarPorFila(array $items): array {
        return array_values(array_filter($items, function ($it) {
            $entityId = $it['chat']['entity_id'] ?? '';
            $partes   = explode('|', $entityId);
            return ($partes[1] ?? null) === (string)self::LINE_CONFIG_ID;
        }));
    }

    /** Remove mensagens de sistema (senderid=0 — "conversa iniciada", "fulano aceitou" etc.) e
     *  ordena por data ascendente. Mensagens reais de cliente/atendente sempre têm senderid != 0
     *  (confirmado nos exemplos da doc oficial e em teste real). */
    private function mensagensReaisOrdenadas(array $mensagens): array {
        $reais = array_values(array_filter($mensagens, fn($m) => (int)($m['senderid'] ?? 0) !== 0));
        usort($reais, fn($a, $b) => strtotime($a['date']) <=> strtotime($b['date']));
        return $reais;
    }

    /** Pares [mensagem do cliente -> próxima resposta de atendente interno] numa sequência de
     *  mensagens reais — usados pra amostrar o tempo de resposta. Ignora mensagens consecutivas do
     *  mesmo lado (só conta o primeiro atendente a responder depois do cliente falar). */
    private function paresClienteAtendente(array $msgs, array $nomesInternos): array {
        $pares = [];
        $aguardandoDesde = null;

        foreach ($msgs as $m) {
            $ehInterno = isset($nomesInternos[(int)$m['senderid']]);
            if (!$ehInterno) {
                if ($aguardandoDesde === null) $aguardandoDesde = $m['date'];
            } elseif ($aguardandoDesde !== null) {
                $pares[] = ['clienteData' => $aguardandoDesde, 'atendenteData' => $m['date']];
                $aguardandoDesde = null;
            }
        }

        return $pares;
    }

    /** Minutos úteis (seg-sex, HORA_INICIO_COMERCIAL–HORA_FIM_COMERCIAL) entre dois instantes —
     *  usado pro tempo médio de resposta não inflar por causa de mensagens fora do expediente. */
    private function minutosUteisEntre(DateTime $inicio, DateTime $fim): float {
        if ($fim <= $inicio) return 0.0;

        $totalMinutos = 0.0;
        $cursor = clone $inicio;

        while ($cursor < $fim) {
            $diaSemana = (int)$cursor->format('N'); // 1=seg .. 7=dom

            if ($diaSemana <= 5) {
                $inicioExpediente = (clone $cursor)->setTime(self::HORA_INICIO_COMERCIAL, 0, 0);
                $fimExpediente    = (clone $cursor)->setTime(self::HORA_FIM_COMERCIAL, 0, 0);
                $fimDoDia         = (clone $cursor)->setTime(23, 59, 59);

                $janelaInicio = max($cursor, $inicioExpediente);
                $janelaFim    = min($fim, $fimExpediente, $fimDoDia);

                if ($janelaFim > $janelaInicio) {
                    $totalMinutos += ($janelaFim->getTimestamp() - $janelaInicio->getTimestamp()) / 60;
                }
            }

            $cursor = (clone $cursor)->setTime(0, 0, 0)->modify('+1 day');
        }

        return $totalMinutos;
    }

    /** Mesma limpeza de BBCode duplicada nos outros services de Monitoramento (sem base comum). */
    private function limparBBCode(string $msg): string {
        $msg = str_replace('[*]', '• ', $msg);
        $msg = preg_replace('/\[(\w+)(=[^\]]*)?\](.*?)\[\/\1\]/s', '$3', $msg);
        $msg = preg_replace('/\[\/?\w+(=[^\]]*)?\]/', '', $msg);
        return trim($msg);
    }
}
