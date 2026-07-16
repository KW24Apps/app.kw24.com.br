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

    private const ROLE_PARTICIPANTE = 'Participante';
    private const ROLE_OBSERVADOR   = 'Observador';

    private const SELECT = [
        'ID', 'TITLE', 'RESPONSIBLE_ID', 'CREATED_BY', 'CREATED_DATE', 'ACCOMPLICES', 'AUDITORS',
        'DEADLINE', 'CLOSED_DATE', 'DESCRIPTION',
    ];

    private BitrixService $bitrix;

    public function __construct() {
        $this->bitrix = new BitrixService(BitrixService::getWebhookForOrganizacao(BitrixService::ORG_GRUPO_NIMBUS));
    }

    public function isConfigured(): bool {
        return $this->bitrix->isConfigured();
    }

    /**
     * $detalheCompleto controla se 'descricao' e 'comentarios' (as duas causas do peso do
     * payload — medido ~34KB com detalhe) entram na resposta. Default true preserva o
     * comportamento de sempre pra quem já consome este service sem passar o parâmetro (a tela
     * Tarefas via api/monitoramento-tarefas.php, que precisa do conteúdo pra expandir a linha) —
     * só monitoramento-resumo.php passa false por padrão (ver ?detalhe=completo). Diferente de
     * Chamados abertos: não existe forma "leve" de saber se uma tarefa tem comentário sem
     * buscar o conteúdo (task.commentItem.getList não tem endpoint de contagem separado) — os
     * comentários continuam sendo buscados em ambos os modos, só o CONTEÚDO (autor/data/texto)
     * é que sai do payload em modo resumo; 'temChat' continua correto nos dois casos.
     */
    public function getDados(int $comentariosPorTarefa = 5, bool $detalheCompleto = true): array {
        $select = $detalheCompleto ? self::SELECT : array_values(array_diff(self::SELECT, ['DESCRIPTION']));
        $porId  = $this->buscarPorPapeis(['CLOSED_DATE' => ''], $select);

        $taskIds     = array_map('intval', array_keys($porId));
        $comentarios = $taskIds ? $this->bitrix->getCommentsForTasks($taskIds, $comentariosPorTarefa) : [];

        // Criador/Responsável são mostrados SEMPRE, mesmo quando não são um dos 4 da equipe
        // (badges, abaixo, só cobrem Participante/Observador da equipe — ver montarBadges) —
        // resolve em lote os nomes de quem não está no roster fixo self::EQUIPE.
        $idsEnvolvidos = [];
        foreach ($porId as $t) {
            $idsEnvolvidos[] = (int)($t['responsibleId'] ?? 0);
            $idsEnvolvidos[] = (int)($t['createdBy'] ?? 0);
        }
        $idsForaEquipe = array_diff(array_unique(array_filter($idsEnvolvidos)), array_keys(self::EQUIPE));
        $nomesExtras   = $idsForaEquipe ? $this->bitrix->getUserNames($idsForaEquipe) : [];

        $tarefas = [];
        foreach ($porId as $t) {
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

            $tarefa = [
                'id'            => $id,
                'responsibleId' => (int)($t['responsibleId'] ?? 0), // usado p/ montar o deep link /company/personal/user/{id}/tasks/task/view/{id}/
                'titulo'        => $t['title'] ?? '',
                'criadoEm'      => $t['createdDate'] ?? null,
                'deadline'      => $deadline,
                'atrasada'      => $atrasada,
                'criador'       => $this->pessoa((int)($t['createdBy']     ?? 0), $nomesExtras),
                'responsavel'   => $this->pessoa((int)($t['responsibleId'] ?? 0), $nomesExtras),
                'badges'        => $this->montarBadges($t),
                'temChat'       => count($coments) > 0,
            ];
            if ($detalheCompleto) {
                $tarefa['descricao']   = $this->limparBBCode((string)($t['description'] ?? ''));
                $tarefa['comentarios'] = $coments;
            }
            $tarefas[] = $tarefa;
        }

        usort($tarefas, function ($a, $b) {
            if ($a['atrasada'] !== $b['atrasada']) return $a['atrasada'] ? -1 : 1;
            $da = $a['deadline'] ? strtotime($a['deadline']) : PHP_INT_MAX;
            $db = $b['deadline'] ? strtotime($b['deadline']) : PHP_INT_MAX;
            return $da <=> $db;
        });

        $equipe = [];
        foreach (self::EQUIPE as $uid => $nome) {
            $equipe[] = ['bitrixUserId' => $uid, 'nome' => $nome];
        }

        return [
            'bitrixBase' => $this->bitrix->getPortalBaseUrl(),
            'total'      => count($tarefas),
            'tarefas'    => $tarefas,
            'equipe'     => $equipe, // roster fixo, usado pelo filtro por pessoa da tela
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

    /** Resolve um envolvido (Criador/Responsável) pra nome + se é um dos 4 da equipe — mostrado
     *  sempre, mesmo quando a pessoa não está em self::EQUIPE (ver getDados()). */
    private function pessoa(int $uid, array $nomesExtras): array {
        return [
            'bitrixUserId' => $uid,
            'nome'         => self::EQUIPE[$uid] ?? ($nomesExtras[$uid] ?? ('Usuário #' . $uid)),
            'ehEquipe'     => isset(self::EQUIPE[$uid]),
        ];
    }

    /**
     * Um badge por pessoa da equipe envolvida como Participante ou Observador — Responsável e
     * Criador viram campos dedicados (ver pessoa()/getDados()), não duplicam aqui.
     * Intensidade: Participante = média; só Observador = fraca.
     */
    private function montarBadges(array $t): array {
        $porPessoa = [];

        $add = function (int $uid, string $papel) use (&$porPessoa) {
            if (!isset(self::EQUIPE[$uid])) return;
            $porPessoa[$uid]['nome'] ??= self::EQUIPE[$uid];
            $porPessoa[$uid]['papeis'][] = $papel;
        };

        foreach ((array)($t['accomplices'] ?? []) as $uid) $add((int)$uid, self::ROLE_PARTICIPANTE);
        foreach ((array)($t['auditors'] ?? []) as $uid) $add((int)$uid, self::ROLE_OBSERVADOR);

        $badges = [];
        foreach ($porPessoa as $uid => $info) {
            $papeis = array_values(array_unique($info['papeis']));
            $badges[] = [
                'bitrixUserId' => $uid,
                'nome'         => $info['nome'],
                'papeis'       => $papeis,
                'intensidade'  => in_array(self::ROLE_PARTICIPANTE, $papeis, true) ? 'media' : 'fraca',
            ];
        }
        return $badges;
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
