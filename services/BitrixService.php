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

    /**
     * Busca o webhook Bitrix24 vinculado ao cliente que tem a aplicação de slug $appSlug ativa
     * (cliente_aplicacoes.ativo=TRUE), via cliente_aplicacoes → clientes → organizacoes.webhook_motor.
     * Mesmo padrão de resolução já usado por apis2.kw24.com.br (AccessControl::validate() e
     * NimbusPartnersReportJob) para o app 'nimbus_parceiros' — conta Bitrix24 da Nimbus Tax,
     * diferente do webhook interno da KW24 (financeiro_webhook_bitrix / ORG_GRUPO_NIMBUS).
     */
    public static function getWebhookForAppSlug(string $appSlug): ?string {
        $db  = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT o.webhook_motor
               FROM cliente_aplicacoes ca
               JOIN clientes c ON ca.cliente_id = c.id
               JOIN aplicacoes a ON a.id = ca.aplicacao_id
               JOIN organizacoes o ON o.id = c.org_id
              WHERE a.slug = :slug AND ca.ativo = TRUE
              LIMIT 1',
            ['slug' => $appSlug]
        );
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

    /** Chamada crua — retorna a resposta completa (result + total + next), não só 'result'. */
    private function callRaw(string $method, array $params = []): ?array {
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

        return $data;
    }

    private function post(string $method, array $params = []): ?array {
        $data = $this->callRaw($method, $params);
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

    /** Atualiza campos (inclusive UF_CRM_*) de uma Company via crm.company.update. */
    public function updateCompany(int $companyId, array $fields): bool {
        $result = $this->post('crm.company.update', [
            'id'     => $companyId,
            'fields' => $fields,
        ]);
        return $result !== null;
    }

    /** Resolve IDs de usuário Bitrix24 para nome completo. Retorna [id => "Nome Sobrenome"]. */
    public function getUserNames(array $userIds): array {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if (!$ids) return [];

        $result = $this->post('user.get', ['filter' => ['ID' => $ids]]);
        $out = [];
        foreach ((array)$result as $u) {
            $nome = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
            $out[(int)$u['ID']] = $nome !== '' ? $nome : ('Usuário #' . $u['ID']);
        }
        return $out;
    }

    /**
     * Resolve o chat vinculado (im.chat) de vários itens CRM (SPA) de uma vez, via batch.
     * Retorna [itemId => chatId] — itens sem chat vinculado não aparecem no retorno.
     * Padrão descoberto em apis2.kw24.com.br/TRANSICAO.md ("Localizar chat vinculado a um card
     * CRM"): ENTITY_TYPE fixo "CRM", ENTITY_ID = "DYNAMIC_{entityTypeId}|{itemId}".
     */
    public function getCrmChatIds(int $entityTypeId, array $itemIds): array {
        $out = [];
        foreach (array_chunk($itemIds, 50) as $chunk) {
            $cmd = [];
            foreach ($chunk as $i => $itemId) {
                $cmd["c{$i}"] = 'im.chat.get?' . http_build_query([
                    'ENTITY_TYPE' => 'CRM',
                    'ENTITY_ID'   => "DYNAMIC_{$entityTypeId}|{$itemId}",
                ], '', '&', PHP_QUERY_RFC3986);
            }

            $resp = $this->post('batch', ['halt' => 0, 'cmd' => $cmd]);
            if ($resp === null) continue;

            $results = $resp['result'] ?? [];
            foreach ($chunk as $i => $itemId) {
                $chatId = $results["c{$i}"]['ID'] ?? null;
                if ($chatId) $out[(int)$itemId] = (int)$chatId;
            }
        }
        return $out;
    }

    /**
     * Mensagens recentes de vários chats de uma vez, via batch de im.dialog.messages.get.
     * Retorna [chatId => ['mensagens' => [...], 'usuarios' => [id => dadosDoUsuario]]] em caso de
     * sucesso, ou [chatId => ['erro' => 'ACCESS_ERROR']] quando o usuário do webhook não é membro
     * do chat (comum para chats de card CRM — só quem participou da conversa tem acesso; ver nota
     * em MonitoramentoChamadosService). $limit corta as mensagens mais recentes (a API já retorna
     * as últimas ~20 por padrão, sem paginação padrão).
     */
    public function getCrmChatMessages(array $chatIds, int $limit = 5): array {
        $out = [];
        foreach (array_chunk(array_values(array_unique($chatIds)), 50) as $chunk) {
            $cmd = [];
            foreach ($chunk as $i => $chatId) {
                $cmd["d{$i}"] = 'im.dialog.messages.get?' . http_build_query([
                    'DIALOG_ID' => 'chat' . $chatId,
                ], '', '&', PHP_QUERY_RFC3986);
            }

            $resp = $this->post('batch', ['halt' => 0, 'cmd' => $cmd]);
            if ($resp === null) continue;

            $results = $resp['result']       ?? [];
            $errors  = $resp['result_error'] ?? [];

            foreach ($chunk as $i => $chatId) {
                $key = "d{$i}";
                if (!empty($errors[$key])) {
                    $out[$chatId] = ['erro' => $errors[$key]['error'] ?? 'erro'];
                    continue;
                }
                $usuarios = [];
                foreach (($results[$key]['users'] ?? []) as $u) {
                    $usuarios[(int)$u['id']] = $u['name'] ?? ('Usuário #' . $u['id']);
                }
                $out[$chatId] = [
                    'mensagens' => array_slice($results[$key]['messages'] ?? [], 0, $limit),
                    'usuarios'  => $usuarios,
                ];
            }
        }
        return $out;
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
     * Lista tarefas com paginação em lote (via batch) — usar quando o resultado pode ter muitas
     * páginas (ex.: histórico completo, sem filtro de data). A primeira página é buscada
     * normalmente (pra descobrir 'total'); o resto é buscado em comandos batch (até 50 páginas —
     * 2500 tarefas — por chamada HTTP), bem mais rápido que listTasks() paginando uma página por
     * requisição. Mesma ressalva de chaves camelCase na resposta que listTasks().
     */
    public function listTasksBatched(array $filter = [], array $select = []): array {
        $params = ['filter' => $filter];
        if ($select) {
            $params['select'] = $select;
        }

        $first = $this->callRaw('tasks.task.list', array_merge($params, ['start' => 0]));
        if ($first === null) return [];

        $all   = $first['result']['tasks'] ?? [];
        $total = (int)($first['total'] ?? count($all));

        $starts = [];
        for ($s = 50; $s < $total; $s += 50) $starts[] = $s;

        foreach (array_chunk($starts, 50) as $chunk) {
            $cmd = [];
            foreach ($chunk as $i => $start) {
                $cmd["p{$i}"] = 'tasks.task.list?' . http_build_query(
                    array_merge($params, ['start' => $start]), '', '&', PHP_QUERY_RFC3986
                );
            }

            $resp = $this->post('batch', ['halt' => 0, 'cmd' => $cmd]);
            if ($resp === null) continue;

            $results = $resp['result'] ?? [];
            foreach ($chunk as $i => $start) {
                $all = array_merge($all, $results["p{$i}"]['tasks'] ?? []);
            }
        }

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
