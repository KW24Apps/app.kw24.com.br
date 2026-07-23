<?php
if (!defined('SYSTEM_ACCESS') && !isset($user_data)) {
    header('Location: /public/login.php'); exit;
}
// Defesa em profundidade: garante admin_interno mesmo no caminho AJAX do index.php
// (o guard genérico de lá não bloqueia usuário sem profile_id atribuído).
if (($user_data['perfil'] ?? '') !== 'admin_interno') {
    header('Location: ?page=dashboard'); exit;
}
?>
<link rel="stylesheet" href="/assets/css/monitoramento.css">

<div class="page-header">
    <h1 class="page-title"><i class="fas fa-satellite-dish" style="color:#0DC2FF;margin-right:.5rem"></i>Monitoramento KW24</h1>
    <div class="page-header-actions">
        <span class="mon-updated" id="mon-updated">—</span>
        <button class="mon-config-btn" id="mon-config-btn" onclick="monAbrirConfigWebhooks()" title="Webhooks pessoais (Atendimento)">
            <i class="fas fa-cog"></i>
        </button>
        <button class="btn-primary" id="mon-refresh-btn" onclick="monAtualizar()">
            <i class="fas fa-sync-alt" id="mon-refresh-icon"></i> Atualizar
        </button>
    </div>
</div>

<div class="topo-row">
    <div class="ate-section" id="ate-section">
        <div class="mon-tabs-bar">
            <div class="mon-tab active" id="mon-tab-ate-conv" onclick="ateTrocarAba('conv')">
                <span class="mon-tab-title"><i class="fas fa-comments"></i>Conversas</span>
            </div>
            <div class="mon-tab" id="mon-tab-ate-grupo" onclick="ateTrocarAba('grupo')">
                <span class="mon-tab-title"><i class="fab fa-whatsapp"></i>Grupos</span>
                <span class="mon-tab-count" id="ate-grupo-count">0</span>
            </div>
            <div class="mon-tab-filters" id="ate-filtros">
                <div class="cha-dropdown" id="ate-dropdown-pessoa">
                    <button class="cha-dropdown-trigger" onclick="ateToggleDropdown()">
                        <span>Responsável · <span id="ate-dropdown-pessoa-count">0</span></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="cha-dropdown-panel" id="ate-dropdown-pessoa-panel"></div>
                </div>
            </div>
        </div>
        <div class="mon-tab-content" id="ate-tab-content-conv">
            <div class="ate-kpis" id="ate-kpis"></div>
            <div class="ate-thead">
                <span></span>
                <span class="ate-th">Conversa</span>
                <span class="ate-th">Responsável</span>
                <span class="ate-th tempo">Tempo</span>
            </div>
            <div class="ate-list" id="ate-list">
                <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
            </div>
        </div>
        <div class="mon-tab-content" id="ate-tab-content-grupo" style="display:none">
            <div class="ate-thead">
                <span></span>
                <span class="ate-th">Grupo</span>
                <span class="ate-th">Responsável</span>
                <span class="ate-th tempo">Tempo</span>
            </div>
            <div class="ate-list" id="ate-grupo-list">
                <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
            </div>
        </div>
    </div>

    <div class="fun-box" id="fun-section">
        <div class="fun-box-header"><i class="fas fa-filter"></i>Funil<span class="fun-box-ciclo" id="fun-ciclo"></span></div>
        <div class="fun-cards">
            <div class="fun-card">
                <div class="fun-card-header criados"><i class="fas fa-inbox"></i>Chamados criados</div>
                <div class="fun-card-body" id="fun-criados-body">
                    <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
                </div>
            </div>
            <div class="fun-card">
                <div class="fun-card-header finalizados"><i class="fas fa-check-circle"></i>Chamados finalizados</div>
                <div class="fun-card-body" id="fun-finalizados-body">
                    <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
                </div>
            </div>
        </div>
        <div class="fun-dist">
            <div class="fun-dist-header">Distribuição dos chamados abertos</div>
            <div id="fun-dist-rows"></div>
        </div>
    </div>
</div>

<div class="mon-panels-row">
    <div class="mon-equipe-card">
        <div class="mon-equipe-header">
            <span><i class="fas fa-users"></i>Equipe</span>
            <span class="mon-equipe-total" id="mon-equipe-total"></span>
        </div>
        <div class="mon-equipe-body" id="mon-equipe-grid">
            <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
        </div>
    </div>
</div>

<div class="mon-right-col">
    <div class="mon-tabs-section">
            <div class="mon-tabs-bar">
                <div class="mon-tab active" id="mon-tab-cha" onclick="monTrocarAba('cha')">
                    <span class="mon-tab-title"><i class="fas fa-inbox"></i>Chamados abertos</span>
                    <span class="mon-tab-count" id="cha-count">Carregando…</span>
                </div>
                <div class="mon-tab" id="mon-tab-tsk" onclick="monTrocarAba('tsk')">
                    <span class="mon-tab-title"><i class="fas fa-list-check"></i>Tarefas</span>
                    <span class="mon-tab-count" id="tsk-count">Carregando…</span>
                </div>
                <div class="mon-tab-filters" id="cha-filtros">
                    <div class="cha-dropdown" id="cha-dropdown-tipo">
                        <button class="cha-dropdown-trigger" onclick="chaToggleDropdown('tipo')">
                            <span>Tipo · <span id="cha-dropdown-tipo-count">0</span></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="cha-dropdown-panel" id="cha-dropdown-tipo-panel"></div>
                    </div>
                    <div class="cha-dropdown" id="cha-dropdown-pessoa">
                        <button class="cha-dropdown-trigger" onclick="chaToggleDropdown('pessoa')">
                            <span>Responsável · <span id="cha-dropdown-pessoa-count">0</span></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="cha-dropdown-panel" id="cha-dropdown-pessoa-panel"></div>
                    </div>
                </div>
                <div class="mon-tab-filters" id="tsk-filter-row" style="display:none">
                    <div class="cha-dropdown" id="tsk-dropdown-pessoa">
                        <button class="cha-dropdown-trigger" onclick="tskToggleDropdown()">
                            <span>Envolvidos · <span id="tsk-dropdown-pessoa-count">0</span></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="cha-dropdown-panel" id="tsk-dropdown-pessoa-panel"></div>
                    </div>
                </div>
            </div>

            <div class="mon-tab-content" id="mon-tab-content-cha">
                <div class="cha-thead">
                    <span></span>
                    <span class="cha-th">Chamado</span>
                    <span class="cha-th cha-th-sort" data-col="data" onclick="chaOrdenarPor('data')">Criado/Prev.<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="cha-th cha-th-sort" data-col="empresa" onclick="chaOrdenarPor('empresa')">Empresa<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="cha-th cha-th-sort" data-col="tipo" onclick="chaOrdenarPor('tipo')">Tipo<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="cha-th cha-th-sort" data-col="prioridade" onclick="chaOrdenarPor('prioridade')">Prior.<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="cha-th cha-th-sort" data-col="etapa" onclick="chaOrdenarPor('etapa')">Etapa<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="cha-th cha-th-sort" data-col="solicitante" onclick="chaOrdenarPor('solicitante')">Solicitante<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="cha-th cha-th-sort" data-col="responsavel" onclick="chaOrdenarPor('responsavel')">Resp.<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span></span>
                </div>
                <div class="cha-list" id="cha-list">
                    <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
                </div>
            </div>

            <div class="mon-tab-content" id="mon-tab-content-tsk" style="display:none">
                <div class="tsk-thead">
                    <span></span>
                    <span></span>
                    <span class="tsk-th">Tarefa</span>
                    <span class="tsk-th">Criado</span>
                    <span class="tsk-th tsk-th-sort" data-col="criador" onclick="tskOrdenarPor('criador')">Criador<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="tsk-th tsk-th-sort" data-col="responsavel" onclick="tskOrdenarPor('responsavel')">Responsável<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="tsk-th tsk-th-sort" data-col="participantes" onclick="tskOrdenarPor('participantes')">Participantes<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="tsk-th tsk-th-sort" data-col="observadores" onclick="tskOrdenarPor('observadores')">Observadores<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="tsk-th tsk-th-sort tsk-th-prazo" data-col="prazo" onclick="tskOrdenarPor('prazo')">Prazo<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span></span>
                </div>
                <div class="tsk-list" id="tsk-list">
                    <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
                </div>
            </div>
        </div>
    </div>
<!-- Drill-down: chamados por trás de um segmento da barra -->
<div id="mon-drill-overlay" onclick="if(event.target===this) monFecharDrill()">
    <div id="mon-drill-box">
        <div id="mon-drill-header">
            <div>
                <h3 id="mon-drill-title"></h3>
                <p id="mon-drill-subtitle"></p>
            </div>
            <button id="mon-drill-close" onclick="monFecharDrill()" aria-label="Fechar">&times;</button>
        </div>
        <div id="mon-drill-list"></div>
    </div>
</div>

<!-- Modal de chat de uma tarefa (clique no ícone de chat) -->
<div id="tsk-chat-overlay" onclick="if(event.target===this) tskFecharChat()">
    <div id="tsk-chat-box">
        <div id="tsk-chat-header">
            <h3 id="tsk-chat-title"></h3>
            <button id="tsk-chat-close" onclick="tskFecharChat()" aria-label="Fechar">&times;</button>
        </div>
        <div id="tsk-chat-list"></div>
    </div>
</div>

<!-- Config dos webhooks pessoais (Atendimento) — um webhook Bitrix24 (escopo im) por
     colaborador, pra o painel ver as conversas que cada um participa. -->
<div id="mon-webhooks-overlay" onclick="if(event.target===this) monFecharConfigWebhooks()">
    <div id="mon-webhooks-box">
        <div id="mon-webhooks-header">
            <div>
                <h3 id="mon-webhooks-title">Webhooks pessoais — Atendimento</h3>
                <p id="mon-webhooks-subtitle">Cada colaborador gera seu próprio webhook Bitrix24
                    (escopo <code>im</code>) no perfil dele — cadastre aqui pra o painel
                    Atendimento agregar as conversas de todos, não só as do automação.</p>
            </div>
            <button id="mon-webhooks-close" onclick="monFecharConfigWebhooks()" aria-label="Fechar">&times;</button>
        </div>
        <div id="mon-webhooks-list"></div>
        <div id="mon-webhooks-form">
            <input type="text" id="mon-webhook-nome" placeholder="Preenchido automaticamente ao validar o webhook">
            <input type="url" id="mon-webhook-url" placeholder="https://.../rest/.../TOKEN/" onblur="monValidarWebhookPessoal()">
            <button onclick="monSalvarWebhookPessoal()"><span id="mon-webhooks-submit-label">Adicionar</span></button>
        </div>
        <div id="mon-webhooks-feedback"></div>
    </div>
</div>

<script src="/assets/js/monitoramento.js"></script>
