<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../dao/ConfiguracaoDAO.php';

class BitrixService {
    private string $webhookUrl;

    public function __construct() {
        $dao = new ConfiguracaoDAO();
        $wh  = $dao->get('financeiro_webhook_bitrix') ?? '';
        $this->webhookUrl = rtrim($wh, '/') . '/';
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
}
