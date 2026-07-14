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
 */
class MonitoramentoAtendimentoService {
    private const LINE_CONFIG_ID = 21; // "Geral KW24 - Suporte"

    // Horário comercial usado no cálculo de tempo médio de resposta — seg-sex, 8h-18h.
    private const HORA_INICIO_COMERCIAL = 8;
    private const HORA_FIM_COMERCIAL    = 18;

    // Não existe método REST que liste o histórico completo de sessões por fila (pesquisado
    // e confirmado) — im.recent.list só devolve uma janela "recente". 200 é o máximo
    // documentado de LIMIT; usado só pra tempoMedioRespostaAmplaMinutos, uma segunda amostra
    // maior (inclui conversas já finalizadas dentro dessa janela) pra dar perspectiva sobre o
    // tempoMedioRespostaMinutos "atual" — nunca é uma média histórica completa.
    private const LIMIT_AMOSTRA_AMPLA = 200;

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

        // Segunda busca, com LIMIT bem maior (200, o máximo documentado) — mesma fila
        // (filtrarPorFila aplicado igual), mas alcança conversas já finalizadas que saíram do
        // recorte de 50 usado acima. Usada só pra tempoMedioRespostaAmplaMinutos (ver return);
        // não participa da lista "conversas", de conversasAtivas nem do statusFila/reclamadaPor
        // (esses continuam exatamente como antes, baseados só em $itensFila).
        $itensAmplos = [];
        foreach ($identidades as $identidade) {
            $recentesAmplos = $identidade['bitrix']->call('im.recent.list', [
                'SKIP_DIALOG'                  => 'Y',
                'SKIP_CHAT'                    => 'Y',
                'SKIP_OPENLINES'               => 'N',
                'SKIP_UNDISTRIBUTED_OPENLINES' => 'N',
                'LIMIT'                        => self::LIMIT_AMOSTRA_AMPLA,
            ]);
            foreach ($this->filtrarPorFila($recentesAmplos['items'] ?? []) as $it) {
                $sessionId = $it['lines']['id'] ?? null;
                if ($sessionId === null || isset($itensAmplos[$sessionId])) continue;
                $itensAmplos[$sessionId] = ['item' => $it, 'identidade' => $identidade];
            }
        }

        // Histórico completo de cada conversa — busca com o webhook da identidade que
        // efetivamente viu essa conversa (ela é participante real; a automação pode não ser).
        // União de $itensAmplos + $itensFila: como a amostra ampla usa LIMIT maior na mesma
        // ordenação por recência, ela já cobre praticamente todo $itensFila — evita refazer a
        // chamada de histórico duas vezes pra mesma sessão.
        $historicos = [];
        foreach (($itensAmplos + $itensFila) as $sessionId => $entrada) {
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

            $conversas[] = [
                'sessionId'                   => (int)$sessionId,
                'titulo'                      => $titulo,
                'ehGrupo'                     => $ehGrupo,
                'aguardando'                  => $aguardando,
                'ultimaMensagemTexto'         => $ultima ? $this->limparBBCode((string)$ultima['text']) : null,
                'ultimaMensagemData'          => $ultimaData,
                'minutosDesdeUltimaAtividade' => $ultimaData ? round((time() - strtotime($ultimaData)) / 60) : null,
                'statusCodigoBruto'           => $it['lines']['status'] ?? null, // não confirmado — só pra calibração visual
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

        // Amostra ampliada (ver $itensAmplos acima) — mesma exclusão de grupo, mesmo
        // filtrarPorFila, mesma paresClienteAtendente/minutosUteisEntre; só o conjunto de
        // sessões de origem é maior (inclui conversas já finalizadas dentro da janela de 200).
        $amostrasRespostaAmpla = [];
        foreach ($itensAmplos as $sessionId => $entrada) {
            $titulo = $entrada['item']['title'] ?? '(sem título)';
            if ($this->ehGrupo($titulo)) continue;

            $msgs = $historicos[$sessionId] ?? [];
            foreach ($this->paresClienteAtendente($msgs, $nomesInternos) as $par) {
                $amostrasRespostaAmpla[] = $this->minutosUteisEntre(
                    new DateTime($par['clienteData']),
                    new DateTime($par['atendenteData'])
                );
            }
        }

        return [
            'bitrixBase'              => $bitrixBase,
            'identidades'             => array_column($identidades, 'nome'), // diagnóstico — quais webhooks foram consultados
            'agrupamentoPorResponsavel' => $multiIdentidade, // se true, front separa em "Aguardando atendimento" / "Sendo atendida"
            'conversasAtivas' => [
                'total'      => count($conversasSemGrupo),
                'aguardando' => count(array_filter($conversasSemGrupo, fn($c) => $c['aguardando'])),
            ],
            'tempoMedioRespostaMinutos' => $amostrasResposta
                ? round(array_sum($amostrasResposta) / count($amostrasResposta))
                : null,
            // Amostra maior (LIMIT=200/identidade) pra dar perspectiva — NÃO é média histórica
            // completa (Bitrix24 não expõe isso, ver docblock da classe), só uma janela mais
            // larga da mesma fila/exclusão de grupo. amostraAmplaTotalSessoes é diagnóstico.
            'tempoMedioRespostaAmplaMinutos' => $amostrasRespostaAmpla
                ? round(array_sum($amostrasRespostaAmpla) / count($amostrasRespostaAmpla))
                : null,
            'amostraAmplaTotalSessoes' => count($itensAmplos),
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
