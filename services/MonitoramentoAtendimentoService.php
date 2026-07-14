<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../services/BitrixService.php';

/**
 * Agregação do painel "Atendimento" — Monitoramento KW24.
 * Fonte: Contact Center / Open Lines (Canal Aberto) da fila "Geral KW24 - Suporte"
 * (imopenlines CONFIG_ID=21), via im.recent.list — NÃO existe método REST que liste
 * sessões ativas por fila diretamente (pesquisado e confirmado); im.recent.list retorna
 * atividade recente do usuário do webhook, filtrada aqui pela fila certa via entity_id.
 *
 * Sem conceito de período/ciclo neste painel (mesma decisão já aplicada ao Tarefas) — o
 * que importa é o que está ativo/sem resposta agora, não um total histórico.
 */
class MonitoramentoAtendimentoService {
    private const LINE_CONFIG_ID = 21; // "Geral KW24 - Suporte"

    // Horário comercial usado no cálculo de tempo médio de resposta — seg-sex, 8h-18h.
    private const HORA_INICIO_COMERCIAL = 8;
    private const HORA_FIM_COMERCIAL    = 18;

    private BitrixService $bitrix;

    public function __construct() {
        $this->bitrix = new BitrixService(BitrixService::getWebhookForOrganizacao(BitrixService::ORG_GRUPO_NIMBUS));
    }

    public function isConfigured(): bool {
        return $this->bitrix->isConfigured();
    }

    public function getDados(): array {
        $recentes = $this->bitrix->call('im.recent.list', [
            'SKIP_DIALOG'                  => 'Y',
            'SKIP_CHAT'                    => 'Y',
            'SKIP_OPENLINES'               => 'N',
            'SKIP_UNDISTRIBUTED_OPENLINES' => 'N',
            'LIMIT'                        => 50,
        ]);
        $itensFila = $this->filtrarPorFila($recentes['items'] ?? []);

        // Histórico completo de cada conversa da fila — necessário porque im.recent.list só traz a
        // última mensagem (não dá pra saber quem falou por último de fato, nem calcular tempo de
        // resposta, sem o histórico completo da sessão).
        $historicos = [];
        foreach ($itensFila as $it) {
            $chatId    = $it['chat']['id'] ?? null;
            $sessionId = $it['lines']['id'] ?? null;
            if ($chatId === null || $sessionId === null) continue;

            $hist = $this->bitrix->call('imopenlines.session.history.get', [
                'CHAT_ID'    => $chatId,
                'SESSION_ID' => $sessionId,
            ]);
            $historicos[$sessionId] = $this->mensagensReaisOrdenadas($hist['message'] ?? []);
        }

        // Resolve em lote quais autores de mensagem são usuários internos reais do Bitrix24
        // (atendentes) — quem não resolve é o contato externo (conector do canal).
        $todosSenderIds = [];
        foreach ($historicos as $msgs) {
            foreach ($msgs as $m) $todosSenderIds[] = (int)$m['senderid'];
        }
        $nomesInternos = $todosSenderIds ? $this->bitrix->getUserNames($todosSenderIds) : [];

        $conversas          = [];
        $amostrasResposta   = [];
        $bitrixBase         = $this->bitrix->getPortalBaseUrl();

        foreach ($itensFila as $it) {
            $sessionId = $it['lines']['id'] ?? null;
            $msgs      = $historicos[$sessionId] ?? [];

            $ultima      = end($msgs) ?: null;
            $aguardando  = $ultima === null || !isset($nomesInternos[(int)$ultima['senderid']]);
            $ultimaData  = $ultima['date'] ?? ($it['lines']['date_create'] ?? null);

            foreach ($this->paresClienteAtendente($msgs, $nomesInternos) as $par) {
                $amostrasResposta[] = $this->minutosUteisEntre(
                    new DateTime($par['clienteData']),
                    new DateTime($par['atendenteData'])
                );
            }

            $conversas[] = [
                'sessionId'                 => (int)$sessionId,
                'titulo'                    => $it['title'] ?? '(sem título)',
                'aguardando'                => $aguardando,
                'ultimaMensagemTexto'       => $ultima ? $this->limparBBCode((string)$ultima['text']) : null,
                'ultimaMensagemData'        => $ultimaData,
                'minutosDesdeUltimaAtividade' => $ultimaData ? round((time() - strtotime($ultimaData)) / 60) : null,
                'statusCodigoBruto'         => $it['lines']['status'] ?? null, // não confirmado — só pra calibração visual
                'urlBitrix24'               => $bitrixBase ? ($bitrixBase . '/online/?IM_HISTORY=imol|' . $sessionId) : null,
            ];
        }

        usort($conversas, function ($a, $b) {
            if ($a['aguardando'] !== $b['aguardando']) return $b['aguardando'] <=> $a['aguardando'];
            return ($b['minutosDesdeUltimaAtividade'] ?? 0) <=> ($a['minutosDesdeUltimaAtividade'] ?? 0);
        });

        return [
            'bitrixBase'      => $bitrixBase,
            'conversasAtivas' => [
                'total'      => count($conversas),
                'aguardando' => count(array_filter($conversas, fn($c) => $c['aguardando'])),
            ],
            'tempoMedioRespostaMinutos' => $amostrasResposta
                ? round(array_sum($amostrasResposta) / count($amostrasResposta))
                : null,
            'conversas' => $conversas,
        ];
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
