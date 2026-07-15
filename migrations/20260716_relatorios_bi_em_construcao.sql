-- Migration: relatorios_bi_em_construcao
-- Etapa 2 do self-service de Relatórios BI: flag manual "em construção".
-- Novo relatório nasce em_construcao=true (visível só a admin_interno, ver
-- api/relatorios-bi.php). Admin desliga manualmente quando um dev termina o
-- dashboard de verdade — nunca auto-detectado (não pinga o serviço Dash).

ALTER TABLE relatorios_bi ADD COLUMN IF NOT EXISTS em_construcao BOOLEAN NOT NULL DEFAULT true;

-- Os 2 relatórios já existentes e em produção nunca foram "em construção" —
-- backfill explícito pra não escondê-los de usuários não-admin.
UPDATE relatorios_bi SET em_construcao = false WHERE slug IN ('relatorio-parceiros-tax', 'relatorio-contabilidade');

COMMENT ON COLUMN relatorios_bi.em_construcao IS 'Flag manual (nunca auto-detectada): true = card visível só a admin_interno com badge "Em construção". Admin desliga quando o dashboard real estiver pronto.';
