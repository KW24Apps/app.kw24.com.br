-- Migration: relatorios_bi_conexoes
-- Config de conexão de dados por relatório BI, editável via UI (substitui .env local no servidor).
-- 1 linha por relatório (relatorio_id é UNIQUE). tipo_conexao só 'sql' habilitado na UI hoje;
-- 'webhook' e 'excel' reservados para tipos futuros, sem exigir novas colunas.
-- Todos os parâmetros específicos do tipo de conexão vivem em `config` (JSONB) — não em
-- colunas fixas — para não especializar o schema em "2 relatórios hardcoded".

CREATE TABLE IF NOT EXISTS relatorios_bi_conexoes (
    id            SERIAL PRIMARY KEY,
    relatorio_id  INTEGER NOT NULL UNIQUE REFERENCES relatorios_bi(id) ON DELETE CASCADE,
    tipo_conexao  VARCHAR(20) NOT NULL DEFAULT 'sql' CHECK (tipo_conexao IN ('sql', 'webhook', 'excel')),
    config        JSONB NOT NULL DEFAULT '{}'::jsonb,
    testado_em    TIMESTAMP,
    criado_em     TIMESTAMP NOT NULL DEFAULT NOW(),
    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE relatorios_bi_conexoes IS 'Configuração de conexão de dados por relatório BI (1:1 com relatorios_bi), editável via api/relatorio-conexao.php. Para tipo_conexao=sql, config guarda {host,port,dbname,user,password}.';
COMMENT ON COLUMN relatorios_bi_conexoes.config IS 'Parâmetros específicos do tipo de conexão, formato livre por tipo_conexao. Nunca expor via logs/console.';

-- Backfill dos valores reais de conexão (host/port/dbname/user/password hoje em .env no servidor)
-- é feito separadamente via PHP runner por SSH (nunca commitado em .sql — evita credenciais no git).
