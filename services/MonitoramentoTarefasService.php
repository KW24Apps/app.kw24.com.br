<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../services/BitrixService.php';

/**
 * Agregação do painel "Tarefas" — Monitoramento KW24.
 * Fonte: módulo nativo de Tarefas do Bitrix24 (tasks.task.list) — completamente separado do
 * funil SPA 1054/Funil 208 usado pelo painel Equipe.
 *
 * Limitação conhecida: ACCOMPLICES (Participante) e AUDITORS (Observador) NÃO são filtráveis
 * via tasks.task.list nesta conta — confirmado por teste real (o filtro é silenciosamente
 * ignorado e o método retorna o total do portal inteiro, ~4462 tarefas abertas de todas as
 * empresas do Grupo Nimbus, não só KW24). Varrer todo esse volume para filtrar no PHP seria
 * lento (dezenas de segundos por carregamento) e tocaria dados de tarefas de outras empresas
 * do grupo sem necessidade. Decisão (Usuário, 2026-07-13): cobrir por ora somente Responsável
 * (RESPONSIBLE_ID) e Criador (CREATED_BY) — ambos filtráveis normalmente. Participante e
 * Observador ficam como limitação conhecida para decisão futura.
 */
class MonitoramentoTarefasService {
    private const EQUIPE = [
        21    => 'Gabriel Acker',
        83    => 'Jeferson Santos',
        11292 => 'Tainá Oliveira',
        12126 => 'Michael Botelho',
    ];

    private const ROLE_RESPONSAVEL = 'Responsável';
    private const ROLE_CRIADOR     = 'Criador';

    private const SELECT = ['ID', 'TITLE', 'RESPONSIBLE_ID', 'CREATED_BY', 'DEADLINE', 'CLOSED_DATE', 'DESCRIPTION'];

    private BitrixService $bitrix;

    public function __construct() {
        $this->bitrix = new BitrixService(BitrixService::getWebhookForOrganizacao(BitrixService::ORG_GRUPO_NIMBUS));
    }

    public function isConfigured(): bool {
        return $this->bitrix->isConfigured();
    }

    public function getDados(int $comentariosPorTarefa = 5): array {
        $uids = array_keys(self::EQUIPE);

        $porResponsavel = $this->bitrix->listTasks(
            ['RESPONSIBLE_ID' => $uids, 'CLOSED_DATE' => ''],
            self::SELECT,
            0
        );
        $porCriador = $this->bitrix->listTasks(
            ['CREATED_BY' => $uids, 'CLOSED_DATE' => ''],
            self::SELECT,
            0
        );

        // Dedup por ID — a mesma tarefa pode casar nas duas buscas (ex.: responsável e criador
        // são pessoas diferentes da equipe, ou a mesma pessoa em ambos os papéis).
        $porId = [];
        foreach (array_merge($porResponsavel, $porCriador) as $t) {
            $porId[$t['id']] = $t;
        }

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
                'descricao'     => $t['description'] ?? '',
                'deadline'      => $deadline,
                'atrasada'      => $atrasada,
                'badges'        => $badges,
                'temChat'     => count($coments) > 0,
                'comentarios' => $coments,
            ];
        }

        usort($tarefas, function ($a, $b) {
            if ($a['atrasada'] !== $b['atrasada']) return $a['atrasada'] ? -1 : 1;
            $da = $a['deadline'] ? strtotime($a['deadline']) : PHP_INT_MAX;
            $db = $b['deadline'] ? strtotime($b['deadline']) : PHP_INT_MAX;
            return $da <=> $db;
        });

        return [
            'bitrixBase' => $this->bitrix->getPortalBaseUrl(),
            'total'      => count($tarefas),
            'tarefas'    => $tarefas,
        ];
    }

    /** Um badge por pessoa da equipe envolvida, combinando todos os papéis dela na mesma tarefa. */
    private function montarBadges(array $t): array {
        $porPessoa = [];

        $resp    = (int)($t['responsibleId'] ?? 0);
        $criador = (int)($t['createdBy'] ?? 0);

        if (isset(self::EQUIPE[$resp])) {
            $porPessoa[$resp]['nome']     = self::EQUIPE[$resp];
            $porPessoa[$resp]['papeis'][] = self::ROLE_RESPONSAVEL;
        }
        if (isset(self::EQUIPE[$criador])) {
            $porPessoa[$criador]['nome']   ??= self::EQUIPE[$criador];
            $porPessoa[$criador]['papeis'][] = self::ROLE_CRIADOR;
        }

        $badges = [];
        foreach ($porPessoa as $uid => $info) {
            $badges[] = [
                'bitrixUserId' => $uid,
                'nome'         => $info['nome'],
                'papeis'       => $info['papeis'],
                // Responsável/Criador = intensidade forte (só o que existe nesta versão).
                // Participante/Observador seriam média/fraca — ver limitação de escopo no topo do arquivo.
                'intensidade'  => 'forte',
            ];
        }
        return $badges;
    }

    /** Remove marcação BBCode do Bitrix nas mensagens de comentário (ex.: "[USER=21]Nome[/USER]" -> "Nome"). */
    private function limparBBCode(string $msg): string {
        $msg = preg_replace('/\[(\w+)(=[^\]]*)?\](.*?)\[\/\1\]/s', '$3', $msg);
        $msg = preg_replace('/\[\/?\w+(=[^\]]*)?\]/', '', $msg);
        return trim($msg);
    }
}
