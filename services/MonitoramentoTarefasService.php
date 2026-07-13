<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../dao/ConfiguracaoDAO.php';
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

        $ciclo              = $this->calcularCicloAtual();
        $emAberto           = count($tarefas);
        $finalizadasNoCiclo = $this->contarFinalizadasNoCiclo($ciclo);

        return [
            'bitrixBase' => $this->bitrix->getPortalBaseUrl(),
            'total'      => $emAberto,
            'tarefas'    => $tarefas,
            'kpi'        => [
                'periodo' => [
                    'inicio' => $ciclo['inicio']->format('Y-m-d'),
                    'fim'    => $ciclo['fim']->format('Y-m-d'),
                ],
                // "Total no ciclo" = backlog aberto agora (independente de quando foi criado) +
                // o que foi finalizado dentro do ciclo — representa o volume de trabalho tocado
                // no período, não só o que foi criado nele.
                'emAberto'           => $emAberto,
                'finalizadasNoCiclo' => $finalizadasNoCiclo,
                'totalNoCiclo'       => $emAberto + $finalizadasNoCiclo,
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

    /** Tarefas da equipe (qualquer papel) finalizadas dentro do ciclo de faturamento atual. */
    private function contarFinalizadasNoCiclo(array $ciclo): int {
        $inicioStr = $ciclo['inicio']->format('Y-m-d\TH:i:s');
        $fimStr    = $ciclo['fim']->format('Y-m-d\TH:i:s');

        $porId = $this->buscarPorPapeis(
            ['>=CLOSED_DATE' => $inicioStr, '<=CLOSED_DATE' => $fimStr],
            ['ID'],
        );
        return count($porId);
    }

    /** Ciclo de faturamento atual — mesma regra/config do painel Equipe (dia 27 → dia 26). */
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
