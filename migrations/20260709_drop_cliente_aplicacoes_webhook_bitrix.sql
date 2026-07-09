-- Remove cliente_aplicacoes.webhook_bitrix — migrado para organizacoes.webhook_motor
-- (apis2.kw24.com.br, dadosgn.kw24.com.br e app.kw24.com.br já leem/escrevem via
-- organizacoes.webhook_motor; nenhuma referência a webhook_bitrix permanece no código)
ALTER TABLE cliente_aplicacoes DROP COLUMN IF EXISTS webhook_bitrix;
