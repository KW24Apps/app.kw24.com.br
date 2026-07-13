<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../dao/ConfiguracaoDAO.php';

class BitrixService {
    /** organizacoes.id do "Grupo Nimbus" — portal Bitrix24 (gnapp.bitrix24.com.br) compartilhado
     *  por várias empresas do grupo, incluindo a KW24. Usado por telas internas (dashboards) que
     *  não são uma "aplicação" registrada em cliente_aplicacoes. */
    public const ORG_GRUPO_NIMBUS = 1;

    private string $webhookUrl;

    /**
     * Sem argumento: usa o webhook interno padrão (configuracoes_sistema.financeiro_webhook_bitrix),
     * mesmo comportamento de sempre (usado por FinanceiroSync).
     * Com argumento: usa a URL de webhook informada diretamente — ver getWebhookForOrganizacao().
     */
    public function __construct(?string $webhookUrl = null) {
        if ($webhookUrl !== null) {
            $this->webhookUrl = rtrim($webhookUrl, '/') . '/';
            return;
        }
        $dao = new ConfiguracaoDAO();
        $wh  = $dao->get('financeiro_webhook_bitrix') ?? '';
        $this->webhookUrl = rtrim($wh, '/') . '/';
    }

    /**
     * Busca a URL de webhook de uma organização (organizacoes.webhook_motor) — para telas
     * internas que precisam de um webhook Bitrix24 diferente do padrão financeiro (ex.: escopos
     * adicionais como "task"). Reutilizável por qualquer dashboard interno futuro.
     */
    public static function getWebhookForOrganizacao(int $organizacaoId): ?string {
        $db  = Database::getInstance();
        $row = $db->fetchOne('SELECT webhook_motor FROM organizacoes WHERE id = :id', ['id' => $organizacaoId]);
        return $row['webhook_motor'] ?? null;
    }

    public function isConfigured(): bool {
        return strlen($this->webhookUrl) > 15;
    }

    /** Domínio base do portal Bitrix24 (ex: https://gnapp.bitrix24.com.br), extraído da URL do webhook. */
    public function getPortalBaseUrl(): string {
        preg_match('#^(https?://[^/]+)#', $this->webhookUrl, $m);
        return $m[1] ?? '';
    }

    private function post(string $method, array $params = []): ?array {
        if (!$this->isConfigured()) {
            error_log("[BitrixService] Webhook URL não configurada");
            return null;
        }

        $ch = curl_init($this->webhookUrl . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 60,
        ]);
        $resp    = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("[BitrixService] cURL error ({$method}): {$curlErr} | Raw: " . substr($resp, 0, 500));
            return null;
        }

        $data = json_decode($resp, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[BitrixService] JSON decode error ({$method}): " . json_last_error_msg() . " | Raw: " . substr($resp, 0, 500));
            return null;
        }

        if (!empty($data['error'])) {
            error_log("[BitrixService] API error ({$method}): {$data['error']} — " . ($data['error_description'] ?? '') . " | Raw: " . substr($resp, 0, 500));
            return null;
        }

        return $data['result'] ?? null;
    }

    public function getItem(int $entityTypeId, int $itemId): ?array {
        $result = $this->post('crm.item.get', [
            'entityTypeId' => $entityTypeId,
            'id'           => $itemId,
        ]);
        return $result['item'] ?? null;
    }

    /**
     * Lista itens com paginação automática.
     * $maxItems = 0 → sem limite; padrão 200 por segurança.
     */
    public function listItems(int $entityTypeId, array $filter = [], array $select = [], int $maxItems = 200): array {
        $params = [
            'entityTypeId' => $entityTypeId,
            'filter'       => $filter,
        ];
        if ($select) {
            $params['select'] = $select;
        }

        $all   = [];
        $start = 0;

        do {
            $params['start'] = $start;
            $result = $this->post('crm.item.list', $params);
            if ($result === null) break;

            $items = $result['items'] ?? [];
            $all   = array_merge($all, $items);

            $next = $result['next'] ?? null;
            if ($next !== null) {
                $start = $next;
            } elseif (count($items) === 50) {
                $start += 50; // paginação manual quando API não retorna 'next'
            } else {
                break;
            }
        } while ($maxItems === 0 || count($all) < $maxItems);

        return $all;
    }

    /**
     * Cria item. Retorna ID do item criado ou null em caso de erro.
     */
    public function createItem(int $entityTypeId, array $fields): ?int {
        $result = $this->post('crm.item.add', [
            'entityTypeId' => $entityTypeId,
            'fields'       => $fields,
        ]);
        $id = (int)($result['item']['id'] ?? 0);
        return $id ?: null;
    }

    public function updateItem(int $entityTypeId, int $itemId, array $fields): bool {
        $result = $this->post('crm.item.update', [
            'entityTypeId' => $entityTypeId,
            'id'           => $itemId,
            'fields'       => $fields,
        ]);
        return $result !== null;
    }

    public function deleteItem(int $entityTypeId, int $itemId): bool {
        $result = $this->post('crm.item.delete', [
            'entityTypeId' => $entityTypeId,
            'id'           => $itemId,
        ]);
        return $result !== null;
    }

    /** Chama qualquer método Bitrix24 REST diretamente (discovery, diagnóstico). */
    public function call(string $method, array $params = []): ?array {
        return $this->post($method, $params);
    }

    public function getCompany(int $companyId): ?array {
        return $this->post('crm.company.get', ['id' => $companyId]);
    }

    /**
     * Lista tarefas do Bitrix24 Tasks (tasks.task.list) com paginação automática.
     * Atenção: a resposta usa chaves camelCase (id, title, responsibleId, closedDate, ...),
     * diferente das chaves UPPER_SNAKE_CASE usadas em filter/select — confirmado por teste real.
     * $maxItems = 0 → sem limite.
     */
    public function listTasks(array $filter = [], array $select = [], int $maxItems = 200): array {
        $params = ['filter' => $filter];
        if ($select) {
            $params['select'] = $select;
        }

        $all   = [];
        $start = 0;

        do {
            $params['start'] = $start;
            $result = $this->post('tasks.task.list', $params);
            if ($result === null) break;

            $items = $result['tasks'] ?? [];
            $all   = array_merge($all, $items);

            if (count($items) === 50) {
                $start += 50; // tasks.task.list não retorna cursor 'next' confiável — mesma lógica de listItems()
            } else {
                break;
            }
        } while ($maxItems === 0 || count($all) < $maxItems);

        return $all;
    }

    /**
     * Comentários de várias tarefas de uma vez, via batch de task.commentItem.getList — é o que
     * funciona como "chat" da tarefa no Bitrix24 Tasks (não é im.chat). Retorna
     * [taskId => array dos $limit comentários mais recentes, mais novo primeiro].
     *
     * Cada campo do comentário: ID, AUTHOR_ID, AUTHOR_NAME (já resolvido pelo Bitrix, sem
     * necessidade de user.get), POST_DATE, POST_MESSAGE.
     *
     * Nota técnica: task.commentItem.getList exige filter/order NÃO vazios (ex.: order=[ID=>desc],
     * filter=['>ID'=>0] para "sem filtro real") — confirmado por teste real. Um corpo POST simples
     * application/x-www-form-urlencoded com esses params falha com erro de tipo neste endpoint
     * específico; o mesmo formato de query string funciona normalmente dentro de um comando batch.
     */
    public function getCommentsForTasks(array $taskIds, int $limit = 5): array {
        $out = [];
        foreach (array_chunk($taskIds, 50) as $chunk) {
            $cmd = [];
            foreach ($chunk as $i => $taskId) {
                $cmd["c{$i}"] = 'task.commentItem.getList?' . http_build_query([
                    'taskId' => (int)$taskId,
                    'order'  => ['ID' => 'desc'],
                    'filter' => ['>ID' => 0],
                ], '', '&', PHP_QUERY_RFC3986);
            }

            $resp = $this->post('batch', ['halt' => 0, 'cmd' => $cmd]);
            if ($resp === null) continue;

            $results = $resp['result'] ?? [];
            foreach ($chunk as $i => $taskId) {
                $comments = $results["c{$i}"] ?? [];
                $out[(int)$taskId] = array_slice($comments, 0, $limit);
            }
        }
        return $out;
    }
}
