-- Log genérico de atividades do ecossistema KW24 (multi-app), para o widget
-- "Atividades Recentes" do dashboard. Diferente de sync_historico (uma linha por
-- entidade sincronizada) e nimbus_job_logs (log de texto em arquivo, apis2) — esta
-- tabela guarda uma linha por EXECUÇÃO de qualquer job/app do ecossistema (início,
-- fim, status e um resumo curto), para permitir listar e ordenar por recência
-- atividades de tipos diferentes lado a lado, sem que cada app precise de sua
-- própria tabela de histórico.
CREATE TABLE IF NOT EXISTS atividades_historico (
    id            SERIAL PRIMARY KEY,
    app           VARCHAR(50)  NOT NULL,                    -- sistema de origem (ex: 'apis2', 'app.kw24')
    tipo          VARCHAR(50)  NOT NULL,                    -- slug do tipo de atividade (ex: 'nimbus_parceiros')
    titulo        VARCHAR(255) NOT NULL,                    -- nome amigável para exibição (ex: "Nimbus Partners Report")
    cliente_id    INTEGER NULL REFERENCES clientes(id) ON DELETE SET NULL,
    status        VARCHAR(20) NOT NULL DEFAULT 'rodando' CHECK (status IN ('rodando', 'concluido', 'erro')),
    iniciado_em   TIMESTAMP NOT NULL DEFAULT NOW(),
    finalizado_em TIMESTAMP NULL,
    resumo        VARCHAR(500) NULL,                        -- texto curto pro subtítulo da linha (ex: "13 de 64 parceiros com relatório")
    detalhe       JSONB NULL,                                -- detalhe estruturado opcional, pro expand da linha
    criado_em     TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_atividades_historico_iniciado_em ON atividades_historico (iniciado_em DESC);
CREATE INDEX IF NOT EXISTS idx_atividades_historico_cliente     ON atividades_historico (cliente_id);
