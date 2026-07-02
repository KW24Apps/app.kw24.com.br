-- Remove as flags globais legadas de acesso a Relatórios BI (superadas pela tabela
-- relatorio_usuario_permissoes, per-report per-user). Confirmado via grep antes desta
-- migration: nenhum código lê ou escreve estas colunas.
ALTER TABLE cliente_usuarios DROP COLUMN IF EXISTS pode_ver_relatorio, DROP COLUMN IF EXISTS pode_criar_portal;
