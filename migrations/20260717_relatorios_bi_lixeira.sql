-- Migration: relatorios_bi_lixeira
-- Lixeira (trash) — soft-delete reversível pra QUALQUER relatório (publicado ou
-- rascunho), antes da exclusão definitiva. Substitui o modelo anterior de exclusão
-- imediata só pra rascunhos (api/relatorio-excluir.php, ver RELATORIOS_BI.md).

ALTER TABLE relatorios_bi ADD COLUMN IF NOT EXISTS lixeira_em TIMESTAMP NULL;
ALTER TABLE relatorios_bi ADD COLUMN IF NOT EXISTS lixeira_portais_estado JSONB NULL;

COMMENT ON COLUMN relatorios_bi.lixeira_em IS
    'NULL = não está na lixeira. Preenchido = timestamp de quando foi movido pra lixeira; serve tanto de flag (distinto de em_construcao — trashed é ortogonal a rascunho/publicado) quanto de relógio da retenção de 30 dias (purga automática, ver crons/lixeira-purge.php).';

COMMENT ON COLUMN relatorios_bi.lixeira_portais_estado IS
    'Snapshot {portal_id: ativo_bool} do estado de portais_bi.ativo no momento em que o relatório foi movido pra lixeira — usado pra restaurar cada portal exatamente ao estado que já tinha antes (nunca uma reativação geral). NULL quando o relatório não está na lixeira.';
