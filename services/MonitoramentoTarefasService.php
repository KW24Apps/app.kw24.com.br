<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../services/BitrixService.php';

/**
 * Agregação do painel "Tarefas" — Monitoramento KW24.
 * Fonte: módulo nativo de Tarefas do Bitrix24 (tasks.task.list) — completamente separado do
 * funil SPA 1054/Funil 208 usado pelo painel Equipe.
 *
 * Nota técnica sobre nomes de campo: para SELECT, os campos são ACCOMPLICES/AUDITORS (plural,
 * arrays — nomes do schema real, ver tasks.task.getFields). Para FILTER, os nomes corretos são
 * ACCOMPLICE/AUDITOR (singular) — usar o nome plural no filtro é silenciosamente ignorado pelo
 * Bitrix24 (retorna o total do portal inteiro sem aplicar o filtro). Confirmado por teste real
 * (Gabriel, 2026-07-13) após um teste anterior errado ter usado o nome plural no filtro.
 */
class MonitoramentoTarefasService {
    private const EQUIPE = [
        21    => 'Gabriel Acker',
        83    => 'Jeferson Santos',
        11292 => 'Tainá Oliveira',
        12126 => 'Michael Botelho',
    ];

    private const ROLE_RESPONSAVEL  = 'Responsável';
    private const ROLE_CRIADOR      = 'Criador';
    private const ROLE_PARTICIPANTE = 'Participante';
    private const ROLE_OBSERVADOR   = 'Observador';

    private const SELECT = [
        'ID', 'TITLE', 'RESPONSIBLE_ID', 'CREATED_BY', 'ACCOMPLICES', 'AUDITORS',
        'DEADLINE', 'CLOSED_DATE', 'DESCRIPTION',
    ];

    private BitrixService $bitrix;

    public function __construct() {
        $this->bitrix = new BitrixService(BitrixService::getWebhookForOrganizacao(BitrixService::ORG_GRUPO_NIMBUS));
    }

    public function isConfigured(): bool {
        return $this->bitrix->isConfigured();
    }

    public function getDados(int $comentariosPorTarefa = 5): array {
        $porId = $this->buscarPorPapeis(['CLOSED_DATE' => ''], self::SELECT);

        $taskIds     = array_map('intval', array_keys($porId));
        $comentarios = $taskIds ? $this->bitrix->getCommentsForTasks($taskIds, $comentariosPorTarefa) : [];

        $tarefas = [];
        foreach ($porId as $t) {
            $badges = $this->montarBadges($t);
            if (!$badges) continue; // defensivo — não deveria ocorrer dado o filtro acima

            $id       = (int)$t['id'];
            $deadline = $t['deadline'] ?? null;
            $atrasada = !empty($deadline) && strtotime($deadline) < time();

            $coments = array_map(function ($c) {
                return [
                    'autor'    => $c['AUTHOR_NAME'] ?? ('Usuário #' . ($c['AUTHOR_ID'] ?? '?')),
                    'data'     => $c['POST_DATE'] ?? null,
                    'mensagem' => $this->limparBBCode((string)($c['POST_MESSAGE'] ?? '')),
                ];
            }, $comentarios[$id] ?? []);

            $tarefas[] = [
                'id'            => $id,
                'responsibleId' => (int)($t['responsibleId'] ?? 0), // usado p/ montar o deep link /company/personal/user/{id}/tasks/task/view/{id}/
                'titulo'        => $t['title'] ?? '',
                'descricao'     => $this->limparBBCode((string)($t['description'] ?? '')),
                'deadline'      => $deadline,
                'atrasada'      => $atrasada,
                'badges'        => $badges,
                'temChat'       => count($coments) > 0,
                'comentarios'   => $coments,
            ];
        }

        usort($tarefas, function ($a, $b) {
            if ($a['atrasada'] !== $b['atrasada']) return $a['atrasada'] ? -1 : 1;
            $da = $a['deadline'] ? strtotime($a['deadline']) : PHP_INT_MAX;
            $db = $b['deadline'] ? strtotime($b['deadline']) : PHP_INT_MAX;
            return $da <=> $db;
        });

        $emAberto    = count($tarefas);
        $finalizadas = $this->buscarFinalizadas();

        $equipe = [];
        foreach (self::EQUIPE as $uid => $nome) {
            $equipe[] = ['bitrixUserId' => $uid, 'nome' => $nome];
        }

        return [
            'bitrixBase' => $this->bitrix->getPortalBaseUrl(),
            'total'      => $emAberto,
            'tarefas'    => $tarefas,
            'equipe'     => $equipe, // roster fixo, usado pelo filtro por pessoa da tela
            'kpi'        => [
                // Sem recorte de data — Tarefas não tem conceito de ciclo/período (isso só se
                // aplica a Chamados/Equipe e, futuramente, Atendimento). "Total" = todas as
                // tarefas da equipe, abertas + fechadas, desde sempre; "Finalizadas" = só as
                // fechadas, também sem recorte de data.
                'emAberto'    => $emAberto,
                'finalizadas' => count($finalizadas),
                'total'       => $emAberto + count($finalizadas),
                // Badges por tarefa finalizada — permite recalcular os KPIs no cliente quando o
                // filtro por pessoa da tela muda, sem precisar de nova requisição.
                'finalizadasTarefas' => $finalizadas,
            ],
        ];
    }

    /**
     * Busca tarefas envolvendo a equipe (qualquer um dos 4 papéis), com um filtro extra em comum
     * (ex.: CLOSED_DATE vazio para abertas, ou intervalo de datas para finalizadas no ciclo).
     * Retorna deduplicado por ID — a mesma tarefa pode casar em mais de um papel.
     */
    private function buscarPorPapeis(array $filtroExtra, array $select): array {
        $uids = array_keys(self::EQUIPE);

        $porResponsavel  = $this->bitrix->listTasks(array_merge(['RESPONSIBLE_ID' => $uids], $filtroExtra), $select, 0);
        $porCriador      = $this->bitrix->listTasks(array_merge(['CREATED_BY'     => $uids], $filtroExtra), $select, 0);
        $porParticipante = $this->bitrix->listTasks(array_merge(['ACCOMPLICE'     => $uids], $filtroExtra), $select, 0);
        $porObservador   = $this->bitrix->listTasks(array_merge(['AUDITOR'        => $uids], $filtroExtra), $select, 0);

        $porId = [];
        foreach (array_merge($porResponsavel, $porCriador, $porParticipante, $porObservador) as $t) {
            $porId[$t['id']] = $t;
        }
        return $porId;
    }

    /**
     * Tarefas da equipe (qualquer papel) já finalizadas — SEM recorte de data (todo o histórico).
     * Retorna id + badges (não a tarefa completa — não é exibida em lista, só usada para os KPIs
     * e para o filtro por pessoa recalcular os números no cliente). Usa paginação em lote
     * (listTasksBatched) porque o histórico completo pode ter milhares de tarefas por papel —
     * paginar uma página por requisição seria lento.
     */
    private function buscarFinalizadas(): array {
        $uids   = array_keys(self::EQUIPE);
        $select = ['ID', 'RESPONSIBLE_ID', 'CREATED_BY', 'ACCOMPLICES', 'AUDITORS'];

        $porResponsavel  = $this->bitrix->listTasksBatched(['RESPONSIBLE_ID' => $uids, '!CLOSED_DATE' => ''], $select);
        $porCriador      = $this->bitrix->listTasksBatched(['CREATED_BY'     => $uids, '!CLOSED_DATE' => ''], $select);
        $porParticipante = $this->bitrix->listTasksBatched(['ACCOMPLICE'     => $uids, '!CLOSED_DATE' => ''], $select);
        $porObservador   = $this->bitrix->listTasksBatched(['AUDITOR'        => $uids, '!CLOSED_DATE' => ''], $select);

        $porId = [];
        foreach (array_merge($porResponsavel, $porCriador, $porParticipante, $porObservador) as $t) {
            $porId[$t['id']] = $t;
        }

        $out = [];
        foreach ($porId as $t) {
            $badges = $this->montarBadges($t);
            if (!$badges) continue; // defensivo
            $out[] = ['id' => (int)$t['id'], 'badges' => $badges];
        }
        return $out;
    }

    /**
     * Um badge por pessoa da equipe envolvida, combinando todos os papéis dela na mesma tarefa.
     * Intensidade: Responsável/Criador = forte; Participante (sem Resp./Criador) = média;
     * só Observador = fraca.
     */
    private function montarBadges(array $t): array {
        $porPessoa = [];

        $add = function (int $uid, string $papel) use (&$porPessoa) {
            if (!isset(self::EQUIPE[$uid])) return;
            $porPessoa[$uid]['nome'] ??= self::EQUIPE[$uid];
            $porPessoa[$uid]['papeis'][] = $papel;
        };

        $add((int)($t['responsibleId'] ?? 0), self::ROLE_RESPONSAVEL);
        $add((int)($t['createdBy'] ?? 0), self::ROLE_CRIADOR);
        foreach ((array)($t['accomplices'] ?? []) as $uid) $add((int)$uid, self::ROLE_PARTICIPANTE);
        foreach ((array)($t['auditors'] ?? []) as $uid) $add((int)$uid, self::ROLE_OBSERVADOR);

        $badges = [];
        foreach ($porPessoa as $uid => $info) {
            $papeis = array_values(array_unique($info['papeis']));
            $badges[] = [
                'bitrixUserId' => $uid,
                'nome'         => $info['nome'],
                'papeis'       => $papeis,
                'intensidade'  => $this->intensidadeDosPapeis($papeis),
            ];
        }
        return $badges;
    }

    private function intensidadeDosPapeis(array $papeis): string {
        if (in_array(self::ROLE_RESPONSAVEL, $papeis, true) || in_array(self::ROLE_CRIADOR, $papeis, true)) {
            return 'forte';
        }
        if (in_array(self::ROLE_PARTICIPANTE, $papeis, true)) {
            return 'media';
        }
        return 'fraca'; // só Observador
    }

    /**
     * Remove marcação BBCode do Bitrix em descrição/comentários (ex.: "[USER=21]Nome[/USER]" ->
     * "Nome", "[b]texto[/b]" -> "texto", "[*]item" -> "• item"). Usado tanto na descrição da
     * tarefa quanto nas mensagens de comentário — ambas vêm com a mesma marcação do Bitrix.
     */
    private function limparBBCode(string $msg): string {
        $msg = str_replace('[*]', '• ', $msg);
        $msg = preg_replace('/\[(\w+)(=[^\]]*)?\](.*?)\[\/\1\]/s', '$3', $msg);
        $msg = preg_replace('/\[\/?\w+(=[^\]]*)?\]/', '', $msg);
        return trim($msg);
    }
}
