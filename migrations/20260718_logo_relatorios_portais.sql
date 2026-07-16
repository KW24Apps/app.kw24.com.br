-- Migration: logo do cliente no Portal BI — 2 níveis com fallback (relatório = padrão,
-- portal = override opcional). Ver RELATORIOS_BI.md / ARQUITETURA.md.

ALTER TABLE relatorios_bi ADD COLUMN IF NOT EXISTS logo_path VARCHAR(300) NULL;
ALTER TABLE portais_bi    ADD COLUMN IF NOT EXISTS logo_path VARCHAR(300) NULL;

COMMENT ON COLUMN relatorios_bi.logo_path IS
    'Caminho público (ex: /assets/img/logos-clientes/relatorio_3.png) do logo padrão do relatório, usado no card de login do Portal BI (public/portal-bi-acesso.php) quando o portal acessado não tem logo próprio. NULL = cai no logo KW24 (fallback final).';

COMMENT ON COLUMN portais_bi.logo_path IS
    'Caminho público do logo específico deste portal — sobrepõe relatorios_bi.logo_path quando definido. NULL = usa o logo padrão do relatório (ou KW24, se o relatório também não tiver um).';
