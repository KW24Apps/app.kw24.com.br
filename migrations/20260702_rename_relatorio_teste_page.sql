-- Renomeia a página do hub de Relatórios BI: relatorio-teste -> relatorios-bi
-- (public/relatorio-teste.php -> public/relatorios-bi.php).
-- Sem essa migration, perfis de permissão existentes com "relatorio-teste" em menus
-- perderiam o acesso a Relatórios BI silenciosamente (RBAC e painel de permissões
-- comparam string exata contra o slug da página).
UPDATE permission_profiles
SET menus = (
    SELECT jsonb_agg(
        CASE WHEN elem = '"relatorio-teste"'::jsonb THEN '"relatorios-bi"'::jsonb ELSE elem END
    )
    FROM jsonb_array_elements(menus) AS elem
)
WHERE menus::text ILIKE '%relatorio-teste%';
