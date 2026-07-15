<?php
require_once __DIR__ . '/BitrixService.php';

/**
 * Sincroniza link + senha do Portal BI NimbusTax (relatorio-parceiros-tax, filter_type=parceiro,
 * exatamente 1 parceiro) com os campos UF_CRM_1784116631 (link) / UF_CRM_1784116775 (senha) da
 * Company Bitrix24 correspondente — companyId = filter_values[0] diretamente, sem lookup
 * (parceiro_comercial_id = companyId, confirmado pelo Usuário). Já usado por
 * apis2.kw24.com.br\src\Services\Nimbus\PartnerEmailBuilder.php para montar o e-mail semanal.
 *
 * Reaproveitado por api/portais-bi.php (create/update/toggle/delete) e pelo script one-off
 * scripts/backfill-nimbustax-portal-senha.php — mesma lógica de qualificação e mesma chamada
 * Bitrix nos dois lugares.
 */
class NimbusTaxPortalSync {
    private const RELATORIO_SLUG   = 'relatorio-parceiros-tax';
    // aplicacoes.slug cujo webhook (cliente_aplicacoes → clientes → organizacoes.webhook_motor)
    // é a conta Bitrix24 da Nimbus Tax — mesma resolução usada por apis2 (AccessControl,
    // NimbusPartnersReportJob), não o webhook interno da KW24 (financeiro_webhook_bitrix).
    private const APP_SLUG_WEBHOOK  = 'nimbus_parceiros';
    private const FIELD_LINK  = 'UF_CRM_1784116631';
    private const FIELD_SENHA = 'UF_CRM_1784116775';

    /**
     * $row: ['relatorio_slug','filter_type','filter_values' (array),'slug','ativo'] ou null.
     * filter_values[0] precisa ser numérico — exclui o sentinela '__completo__' (Relatório
     * Completo), que teria count()===1 mas não é um companyId de verdade.
     */
    public static function qualifies(?array $row): bool {
        if (!$row) return false;
        if (($row['relatorio_slug'] ?? '') !== self::RELATORIO_SLUG) return false;
        if (($row['filter_type']    ?? '') !== 'parceiro') return false;
        if (!($row['ativo'] ?? true)) return false;
        $values = $row['filter_values'] ?? [];
        if (count($values) !== 1) return false;
        return is_numeric($values[0]);
    }

    public static function buildLink(array $row): string {
        return 'https://app.kw24.com.br/portal/' . $row['relatorio_slug'] . '/' . $row['slug'];
    }

    private static function bitrix(): ?BitrixService {
        $webhook = BitrixService::getWebhookForAppSlug(self::APP_SLUG_WEBHOOK);
        if (!$webhook) {
            error_log('[NimbusTaxPortalSync] Webhook não configurado (aplicacoes.slug=' . self::APP_SLUG_WEBHOOK . ') — sync Bitrix pulado.');
            return null;
        }
        return new BitrixService($webhook);
    }

    /**
     * $old  = estado do portal ANTES da operação (null em create).
     * $new  = estado do portal DEPOIS da operação (null em delete).
     * $plainSenha = senha em texto puro capturada ANTES do bcrypt hash — só não-nula quando a
     * requisição atual está criando o portal ou regenerando a senha (é o único momento em que
     * o texto puro existe; portais_bi só guarda senha_hash).
     *
     * Best-effort: qualquer falha (webhook indisponível, companyId inválido, campo rejeitado)
     * só é logada — nunca lança exceção, para não bloquear a operação principal do chamador.
     */
    public static function sync(?array $old, ?array $new, ?string $plainSenha): void {
        try {
            $oldQualifies = self::qualifies($old);
            $newQualifies = self::qualifies($new);
            if (!$oldQualifies && !$newQualifies) return;

            $oldCompanyId = $oldQualifies ? (int)$old['filter_values'][0] : null;
            $newCompanyId = $newQualifies ? (int)$new['filter_values'][0] : null;

            $bitrix = self::bitrix();
            if (!$bitrix) return;

            // Portal que qualificava e deixou de qualificar (ou passou a apontar para outra
            // empresa) — a Company antiga não representa mais este portal 1:1, limpa os campos.
            if ($oldQualifies && (!$newQualifies || $oldCompanyId !== $newCompanyId)) {
                $ok = $bitrix->updateCompany($oldCompanyId, [
                    self::FIELD_LINK  => '',
                    self::FIELD_SENHA => '',
                ]);
                error_log("[NimbusTaxPortalSync] clear companyId={$oldCompanyId} ok=" . ($ok ? '1' : '0'));
            }

            if ($newQualifies) {
                if ($plainSenha !== null && $plainSenha !== '') {
                    $ok = $bitrix->updateCompany($newCompanyId, [
                        self::FIELD_LINK  => self::buildLink($new),
                        self::FIELD_SENHA => $plainSenha,
                    ]);
                    error_log("[NimbusTaxPortalSync] write companyId={$newCompanyId} ok=" . ($ok ? '1' : '0'));
                } elseif (!$oldQualifies || $oldCompanyId !== $newCompanyId) {
                    // Passou a qualificar (ou mudou de empresa) nesta mesma requisição, mas sem
                    // senha em texto puro disponível — não há o que escrever (best-effort).
                    error_log("[NimbusTaxPortalSync] companyId={$newCompanyId} qualifica mas sem senha em texto puro nesta requisição — campos não escritos.");
                }
            }
        } catch (\Throwable $e) {
            error_log('[NimbusTaxPortalSync] erro inesperado: ' . $e->getMessage());
        }
    }
}
