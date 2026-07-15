<?php
if (!defined('SYSTEM_ACCESS') && !isset($user_data)) {
    header('Location: /public/login.php'); exit;
}
// admin_interno vê todos os relatórios; demais usuários são filtrados pelos slugs
// liberados via aplicação (calculados na sessão em index.php: $_SESSION['relatorios_visiveis']).
$_rtIsAdmin  = ($user_data['perfil'] ?? '') === 'admin_interno';
$_rtVisiveis = $_rtIsAdmin ? null : ($_SESSION['relatorios_visiveis'] ?? []);

// Slugs com miniatura estática disponível (assets/img/relatorios/thumbs/{slug}.html) —
// checagem simples de filesystem, sem tocar em relatorios-bi/ (Python/Dash, fora de escopo).
$_rtThumbsDisponiveis = [];
$_rtThumbsDir = __DIR__ . '/../assets/img/relatorios/thumbs';
if (is_dir($_rtThumbsDir)) {
    foreach (glob($_rtThumbsDir . '/*.html') as $_rtThumbFile) {
        $_rtThumbsDisponiveis[] = basename($_rtThumbFile, '.html');
    }
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<script>
window.REL_TESTE_VISIVEIS     = <?= $_rtIsAdmin ? 'null' : json_encode($_rtVisiveis) ?>;
window.RBI_THUMBS_DISPONIVEIS = <?= json_encode($_rtThumbsDisponiveis) ?>;
window.RBI_IS_ADMIN           = <?= json_encode($_rtIsAdmin) ?>;
</script>
<style>
/* ── Relatórios BI Hub ──────────────────────────────────────────────────── */
.rbi-wrap {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    padding: .25rem 0;
}

/* Page header */
.rbi-page-header {
    display: flex;
    align-items: center;
    gap: .75rem;
}
.rbi-page-icon {
    font-size: 1.4rem;
    color: #0DC2FF;
}
.rbi-page-title {
    font-family: 'Rubik', sans-serif;
    font-size: 1.6rem;
    font-weight: 600;
    color: #fff;
    line-height: 1;
}

/* Top bar — título + busca + filtro de empresa + toggle grade/lista */
.rbi-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.rbi-controls {
    display: flex;
    align-items: center;
    gap: .6rem;
    flex-wrap: wrap;
}
.rbi-search-wrap { position: relative; }
.rbi-search-wrap i {
    position: absolute;
    left: .7rem; top: 50%; transform: translateY(-50%);
    color: rgba(255,255,255,.3);
    font-size: .85rem;
    pointer-events: none;
}
#rbi-search {
    background: rgba(255,255,255,0.07);
    border: 1.5px solid rgba(255,255,255,0.12);
    border-radius: 8px;
    padding: .5rem .75rem .5rem 2.15rem;
    color: #fff;
    font-family: 'Inter', sans-serif;
    font-size: .82rem;
    outline: none;
    width: 210px;
    box-sizing: border-box;
    transition: border-color .15s;
}
#rbi-search:focus { border-color: #0DC2FF; }
#rbi-search::placeholder { color: rgba(255,255,255,.3); }

.rbi-select {
    background: rgba(255,255,255,0.07);
    border: 1.5px solid rgba(255,255,255,0.12);
    border-radius: 8px;
    padding: .5rem .75rem;
    color: #fff;
    font-family: 'Inter', sans-serif;
    font-size: .82rem;
    outline: none;
    cursor: pointer;
    max-width: 220px;
}
.rbi-select option { background: #0d1e2d; }

.rbi-view-toggle {
    display: flex;
    gap: .25rem;
    background: rgba(255,255,255,0.05);
    border-radius: 8px;
    padding: .2rem;
    flex-shrink: 0;
}
.rbi-view-btn {
    background: none;
    border: none;
    color: rgba(255,255,255,.4);
    padding: .4rem .55rem;
    border-radius: 6px;
    cursor: pointer;
    font-size: .95rem;
    line-height: 1;
    transition: background .15s, color .15s;
}
.rbi-view-btn:hover { color: rgba(255,255,255,.75); }
.rbi-view-btn.active { background: #0DC2FF; color: #061920; }

/* Cards row — modo grade */
.rbi-cards-row.view-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1rem;
    align-items: start;
}
.rbi-cards-row.view-grid .rbi-card {
    display: flex;
    flex-direction: column;
    border-radius: 12px;
    overflow: hidden;
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    cursor: pointer;
    transition: border-color .15s, background .15s;
}
.rbi-cards-row.view-grid .rbi-thumb {
    height: 130px;
    width: 100%;
    overflow: hidden;
    background: #0a1620;
    position: relative;
    flex-shrink: 0;
}
.rbi-cards-row.view-grid .rbi-card-body {
    padding: .75rem .85rem .15rem;
}
.rbi-cards-row.view-grid .rbi-user-chip {
    margin: .5rem .85rem .8rem;
}

/* Cards row — modo lista */
.rbi-cards-row.view-list {
    display: flex;
    flex-direction: column;
    gap: .5rem;
}
.rbi-cards-row.view-list .rbi-card {
    display: flex;
    align-items: center;
    gap: .85rem;
    border-radius: 10px;
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    padding: .5rem .75rem;
    cursor: pointer;
    transition: border-color .15s, background .15s;
}
.rbi-cards-row.view-list .rbi-thumb {
    width: 64px;
    height: 48px;
    flex-shrink: 0;
    overflow: hidden;
    border-radius: 6px;
    background: #0a1620;
    position: relative;
}
.rbi-cards-row.view-list .rbi-card-body {
    flex: 1;
    min-width: 0;
}
.rbi-cards-row.view-list .rbi-user-chip {
    flex-shrink: 0;
}

/* Card — elementos compartilhados entre grade e lista */
.rbi-card { position: relative; }
.rbi-card:hover {
    border-color: rgba(13,194,255,.4);
    background: rgba(255,255,255,0.08);
}
.rbi-thumb-iframe {
    position: absolute;
    top: 0; left: 0;
    width: 320px;
    height: 170px;
    border: none;
    transform-origin: top left;
    pointer-events: none;
}
.rbi-thumb-fallback {
    position: absolute; inset: 0;
    display: flex; align-items: center; justify-content: center;
    background: rgba(13,194,255,0.10);
}
.rbi-thumb-fallback i { font-size: 1.7rem; color: #0DC2FF; }

.rbi-card-name {
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    font-weight: 500;
    color: #fff;
    margin-bottom: .45rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.rbi-empresas {
    display: flex;
    flex-wrap: wrap;
    gap: .3rem;
}
.rbi-empresa-badge {
    font-size: .62rem;
    padding: .15rem .5rem;
    border-radius: 20px;
    background: rgba(255,255,255,0.08);
    color: rgba(255,255,255,.6);
    white-space: nowrap;
}
.rbi-user-chip {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    font-size: .72rem;
    color: rgba(255,255,255,.5);
    cursor: default;
}
.rbi-user-chip i { font-size: .78rem; }

/* Tooltip flutuante de usuários */
.rbi-user-tooltip {
    position: fixed;
    z-index: 99999;
    background: #0d1e2d;
    border: 1.5px solid rgba(13,194,255,.3);
    border-radius: 10px;
    padding: .55rem .7rem;
    box-shadow: 0 12px 32px rgba(0,0,0,.4);
    max-width: 240px;
    pointer-events: none;
}
.rbi-tooltip-row {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .2rem 0;
    font-size: .78rem;
    color: rgba(255,255,255,.8);
    white-space: nowrap;
}
.rbi-tooltip-badge {
    font-size: .6rem;
    font-weight: 700;
    padding: .1rem .35rem;
    border-radius: 4px;
    flex-shrink: 0;
    min-width: 16px;
    text-align: center;
}
.rbi-tooltip-badge.v  { background: rgba(38,255,147,.15); color: #26FF93; }
.rbi-tooltip-badge.vp { background: rgba(13,194,255,.15); color: #0DC2FF; }
.rbi-tooltip-empty {
    font-size: .75rem;
    color: rgba(255,255,255,.35);
    white-space: nowrap;
}

/* Empty state */
.rbi-empty {
    color: rgba(255,255,255,0.25);
    font-size: .875rem;
    font-family: 'Inter', sans-serif;
    padding: 1rem 0;
}

/* ── Config Modal ─────────────────────────────────────────────────────────── */
.rbi-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(6,25,32,0.72);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.rbi-overlay.open {
    display: flex;
}

.rbi-modal {
    background: #0d1e2d;
    border: 1.5px solid rgba(13,194,255,0.25);
    border-radius: 16px;
    padding: 1.75rem 1.75rem 1.5rem;
    width: 100%;
    max-width: 440px;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    animation: rbiPop .18s ease;
}
@keyframes rbiPop {
    from { transform: scale(.92); opacity: 0; }
    to   { transform: scale(1);   opacity: 1; }
}

.rbi-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.rbi-modal-title {
    font-family: 'Rubik', sans-serif;
    font-size: 1rem;
    font-weight: 600;
    color: #fff;
}
.rbi-modal-close {
    background: none;
    border: none;
    color: rgba(255,255,255,0.40);
    font-size: 1.1rem;
    cursor: pointer;
    padding: 2px 4px;
    line-height: 1;
    transition: color .12s;
}
.rbi-modal-close:hover { color: #fff; }

.rbi-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.rbi-field-label {
    font-family: 'Rubik', sans-serif;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: rgba(255,255,255,0.40);
}
.rbi-field-input {
    background: rgba(255,255,255,0.07);
    border: 1.5px solid rgba(255,255,255,0.12);
    border-radius: 8px;
    padding: .55rem .8rem;
    color: #fff;
    font-family: 'Inter', sans-serif;
    font-size: .875rem;
    outline: none;
    transition: border-color .15s;
    width: 100%;
    box-sizing: border-box;
}
.rbi-field-input:focus { border-color: #0DC2FF; }

/* Visibility toggle */
.rbi-vis-row {
    display: flex;
    gap: 8px;
}
.rbi-vis-btn {
    flex: 1;
    padding: .45rem .75rem;
    border-radius: 8px;
    border: 1.5px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.05);
    color: rgba(255,255,255,0.45);
    font-family: 'Inter', sans-serif;
    font-size: .8rem;
    font-weight: 500;
    cursor: pointer;
    transition: border-color .15s, background .15s, color .15s;
    text-align: center;
}
.rbi-vis-btn.active-vis {
    border-color: #0DC2FF;
    background: rgba(13,194,255,0.12);
    color: #0DC2FF;
    font-weight: 600;
}
.rbi-vis-btn.active-oculto {
    border-color: rgba(255,255,255,0.25);
    background: rgba(255,255,255,0.08);
    color: rgba(255,255,255,0.65);
    font-weight: 600;
}

/* Modal footer */
.rbi-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding-top: .25rem;
}
.rbi-btn-cancel {
    background: transparent;
    border: 1.5px solid rgba(255,255,255,0.15);
    border-radius: 8px;
    color: rgba(255,255,255,0.50);
    font-family: 'Inter', sans-serif;
    font-size: .82rem;
    font-weight: 500;
    padding: .45rem 1rem;
    cursor: pointer;
    transition: border-color .15s, color .15s;
}
.rbi-btn-cancel:hover { border-color: rgba(255,255,255,0.35); color: #fff; }

.rbi-btn-save {
    background: #0DC2FF;
    border: none;
    border-radius: 8px;
    color: #061920;
    font-family: 'Inter', sans-serif;
    font-size: .82rem;
    font-weight: 700;
    padding: .45rem 1.1rem;
    cursor: pointer;
    transition: background .15s;
}
.rbi-btn-save:hover    { background: #08aadd; }
.rbi-btn-save:disabled { opacity: .55; cursor: not-allowed; }

.rbi-btn-open {
    display: block;
    width: 100%;
    background: #0DC2FF;
    border: none;
    border-radius: 8px;
    color: #061920;
    font-family: 'Inter', sans-serif;
    font-size: .82rem;
    font-weight: 700;
    padding: .55rem 1rem;
    cursor: pointer;
    text-align: center;
    transition: background .15s;
}
.rbi-btn-open:hover { background: #08aadd; }

/* ── Tabs do modal de configuração (Geral | Conexão — Conexão admin_interno only) ── */
.rbi-tab-bar {
    display: flex;
    gap: 4px;
    background: rgba(255,255,255,0.05);
    border-radius: 8px;
    padding: .25rem;
}
.rbi-tab-btn {
    flex: 1;
    background: none;
    border: none;
    border-radius: 6px;
    color: rgba(255,255,255,0.45);
    font-family: 'Inter', sans-serif;
    font-size: .8rem;
    font-weight: 500;
    padding: .45rem .5rem;
    cursor: pointer;
    transition: background .15s, color .15s;
}
.rbi-tab-btn:hover { color: rgba(255,255,255,0.7); }
.rbi-tab-btn.active {
    background: #0DC2FF;
    color: #061920;
    font-weight: 700;
}
.rbi-tab-content {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}
.rbi-tab-content.rbi-tab-hidden { display: none; }

/* ── Bloco "Infraestrutura" (somente leitura) no topo da aba Conexão ─────────── */
.rbi-infra-box {
    display: flex;
    flex-direction: column;
    gap: 6px;
    background: rgba(255,255,255,0.04);
    border: 1px dashed rgba(255,255,255,0.15);
    border-radius: 8px;
    padding: .65rem .8rem;
}
.rbi-infra-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem;
}
.rbi-infra-label {
    font-family: 'Rubik', sans-serif;
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: rgba(255,255,255,0.35);
}
.rbi-infra-value {
    font-family: 'Inter', sans-serif;
    font-size: .78rem;
    color: rgba(255,255,255,0.75);
    text-align: right;
    word-break: break-all;
}

/* ── Conteúdo da aba "Conexão" ─────────────────────────────────────────────── */
.rbi-conn-tipo-row {
    display: flex;
    gap: 8px;
}
.rbi-conn-tipo-btn {
    flex: 1;
    padding: .45rem .5rem;
    border-radius: 8px;
    border: 1.5px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.05);
    color: rgba(255,255,255,0.45);
    font-family: 'Inter', sans-serif;
    font-size: .78rem;
    font-weight: 500;
    cursor: pointer;
    text-align: center;
    transition: border-color .15s, background .15s, color .15s;
}
.rbi-conn-tipo-btn.active {
    border-color: #0DC2FF;
    background: rgba(13,194,255,0.12);
    color: #0DC2FF;
    font-weight: 600;
}
.rbi-conn-tipo-btn:disabled {
    cursor: not-allowed;
    opacity: .4;
}
.rbi-conn-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
.rbi-conn-grid .rbi-field.full { grid-column: 1 / -1; }
.rbi-conn-pass-wrap { position: relative; }
.rbi-conn-pass-wrap .rbi-field-input { padding-right: 2.3rem; }
.rbi-conn-pass-toggle {
    position: absolute;
    right: .6rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: rgba(255,255,255,0.4);
    cursor: pointer;
    font-size: .85rem;
    padding: 2px;
}
.rbi-conn-pass-toggle:hover { color: #fff; }
.rbi-conn-msg {
    font-family: 'Inter', sans-serif;
    font-size: .78rem;
    padding: .5rem .7rem;
    border-radius: 8px;
    display: none;
}
.rbi-conn-msg.show { display: block; }
.rbi-conn-msg.erro {
    background: rgba(229,62,62,0.12);
    color: #ff8080;
    border: 1px solid rgba(229,62,62,0.3);
}
.rbi-conn-msg.ok {
    background: rgba(38,255,147,0.10);
    color: #26FF93;
    border: 1px solid rgba(38,255,147,0.25);
}

/* ── Botão "+" (criar relatório — admin_interno only) ─────────────────────── */
.rbi-btn-add {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    background: #0DC2FF;
    border: none;
    border-radius: 8px;
    color: #061920;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    flex-shrink: 0;
    transition: background .15s;
}
.rbi-btn-add:hover { background: #08aadd; }

/* ── Badge "Em construção" no card ─────────────────────────────────────────── */
.rbi-badge-construcao {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    font-size: .6rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: .2rem .55rem;
    border-radius: 20px;
    background: rgba(255,184,0,0.15);
    color: #ffb800;
    white-space: nowrap;
}
.rbi-cards-row.view-grid .rbi-badge-construcao {
    position: absolute;
    top: .5rem;
    left: .5rem;
    z-index: 2;
}
.rbi-cards-row.view-list .rbi-badge-construcao {
    flex-shrink: 0;
    order: 98;
}

/* ── Toggle genérico (reaproveitado por Visibilidade e Em construção) ────────── */
.rbi-toggle-row {
    display: flex;
    gap: 8px;
}
.rbi-toggle-btn {
    flex: 1;
    padding: .45rem .75rem;
    border-radius: 8px;
    border: 1.5px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.05);
    color: rgba(255,255,255,0.45);
    font-family: 'Inter', sans-serif;
    font-size: .8rem;
    font-weight: 500;
    cursor: pointer;
    transition: border-color .15s, background .15s, color .15s;
    text-align: center;
}
.rbi-toggle-btn.active-a {
    border-color: #0DC2FF;
    background: rgba(13,194,255,0.12);
    color: #0DC2FF;
    font-weight: 600;
}
.rbi-toggle-btn.active-b {
    border-color: rgba(255,184,0,0.5);
    background: rgba(255,184,0,0.12);
    color: #ffb800;
    font-weight: 600;
}

/* ── Modal "Criar relatório" ───────────────────────────────────────────────── */
.rbi-create-modal { max-width: 480px; max-height: 85vh; overflow-y: auto; }
.rbi-slug-preview {
    font-family: 'Inter', sans-serif;
    font-size: .72rem;
    color: rgba(255,255,255,0.4);
}
.rbi-slug-preview.ok  { color: #26FF93; }
.rbi-slug-preview.bad { color: #ff8080; }

.rbi-excel-tabela-row {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    border: 1px dashed rgba(255,255,255,0.15);
    border-radius: 8px;
    padding: .6rem;
}
.rbi-excel-tabela-row .rbi-field { flex: 1; min-width: 0; }
.rbi-excel-remove {
    background: none;
    border: 1.5px solid rgba(229,62,62,0.3);
    border-radius: 8px;
    color: #ff8080;
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    cursor: pointer;
    transition: border-color .15s, background .15s;
}
.rbi-excel-remove:hover { background: rgba(229,62,62,0.12); }

.rbi-btn-add-tabela {
    background: rgba(13,194,255,0.10);
    border: 1.5px dashed rgba(13,194,255,0.4);
    border-radius: 8px;
    color: #0DC2FF;
    font-family: 'Inter', sans-serif;
    font-size: .8rem;
    font-weight: 600;
    padding: .5rem;
    cursor: pointer;
    transition: background .15s;
}
.rbi-btn-add-tabela:hover { background: rgba(13,194,255,0.18); }

.rbi-create-msg {
    font-family: 'Inter', sans-serif;
    font-size: .78rem;
    padding: .5rem .7rem;
    border-radius: 8px;
    display: none;
}
.rbi-create-msg.show { display: block; }
.rbi-create-msg.erro {
    background: rgba(229,62,62,0.12);
    color: #ff8080;
    border: 1px solid rgba(229,62,62,0.3);
}
.rbi-create-msg.ok {
    background: rgba(38,255,147,0.10);
    color: #26FF93;
    border: 1px solid rgba(38,255,147,0.25);
}
</style>

<div class="rbi-wrap">

    <!-- Top bar -->
    <div class="rbi-topbar">
        <div class="rbi-page-header">
            <i class="ti ti-chart-bar rbi-page-icon"></i>
            <span class="rbi-page-title">Relatórios BI</span>
        </div>
        <div class="rbi-controls">
            <div class="rbi-search-wrap">
                <i class="ti ti-search"></i>
                <input type="text" id="rbi-search" placeholder="Buscar..." autocomplete="off">
            </div>
            <select class="rbi-select" id="rbi-empresa-filter">
                <option value="">Todas as empresas</option>
            </select>
            <div class="rbi-view-toggle">
                <button type="button" class="rbi-view-btn" id="rbi-view-grid" title="Visualização em grade">
                    <i class="ti ti-layout-grid"></i>
                </button>
                <button type="button" class="rbi-view-btn" id="rbi-view-list" title="Visualização em lista">
                    <i class="ti ti-layout-list"></i>
                </button>
            </div>
            <?php if ($_rtIsAdmin): ?>
            <button type="button" class="rbi-btn-add" id="rbi-btn-add-relatorio" title="Criar relatório">
                <i class="ti ti-plus"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cards -->
    <div class="rbi-cards-row view-grid" id="rbi-cards-row">
        <span class="rbi-empty">Carregando...</span>
    </div>

</div>

<!-- Config modal — Geral (todos) + Conexão (admin_interno only, aba extra) -->
<div class="rbi-overlay" id="rbi-overlay">
    <div class="rbi-modal" id="rbi-modal">
        <div class="rbi-modal-head">
            <span class="rbi-modal-title">Configurar relatório</span>
            <button class="rbi-modal-close" id="rbi-modal-close" title="Fechar">&times;</button>
        </div>

        <?php if ($_rtIsAdmin): ?>
        <div class="rbi-tab-bar" id="rbi-tab-bar">
            <button type="button" class="rbi-tab-btn active" id="rbi-tab-btn-geral">Geral</button>
            <button type="button" class="rbi-tab-btn" id="rbi-tab-btn-conexao">Conexão</button>
        </div>
        <?php endif; ?>

        <!-- Aba Geral (nome amigável, visibilidade, abrir relatório) -->
        <div class="rbi-tab-content" id="rbi-tab-geral">
            <input type="hidden" id="rbi-edit-id">
            <input type="hidden" id="rbi-edit-slug">

            <div class="rbi-field">
                <label class="rbi-field-label">Nome amigável</label>
                <input type="text" class="rbi-field-input" id="rbi-edit-nome" autocomplete="off">
            </div>

            <div class="rbi-field">
                <label class="rbi-field-label">Visibilidade</label>
                <div class="rbi-vis-row">
                    <button class="rbi-vis-btn" id="rbi-vis-visivel" data-val="true">Visível</button>
                    <button class="rbi-vis-btn" id="rbi-vis-oculto"  data-val="false">Oculto</button>
                </div>
            </div>

            <?php if ($_rtIsAdmin): ?>
            <div class="rbi-field">
                <label class="rbi-field-label">Em construção</label>
                <div class="rbi-toggle-row" id="rbi-construcao-row">
                    <button type="button" class="rbi-toggle-btn" id="rbi-construcao-sim" data-val="true">Sim — só admin vê</button>
                    <button type="button" class="rbi-toggle-btn" id="rbi-construcao-nao" data-val="false">Não — publicado</button>
                </div>
            </div>
            <?php endif; ?>

            <button class="rbi-btn-open" id="rbi-btn-open">
                <i class="ti ti-external-link" style="margin-right:.35rem"></i>Abrir relatório
            </button>

            <div class="rbi-modal-footer">
                <button class="rbi-btn-cancel" id="rbi-btn-cancel">Cancelar</button>
                <button class="rbi-btn-save"   id="rbi-btn-save">Salvar</button>
            </div>
        </div>

        <?php if ($_rtIsAdmin): ?>
        <!-- Aba Conexão (tipo de conexão + campos de acesso ao banco) -->
        <div class="rbi-tab-content rbi-tab-hidden" id="rbi-tab-conexao">
            <input type="hidden" id="rbi-conn-relatorio-id">

            <!-- Infraestrutura — somente leitura, calculada a partir de slug/id (nunca editável) -->
            <div class="rbi-infra-box">
                <div class="rbi-infra-row"><span class="rbi-infra-label">Pasta</span><span class="rbi-infra-value" id="rbi-infra-pasta">—</span></div>
                <div class="rbi-infra-row"><span class="rbi-infra-label">Serviço</span><span class="rbi-infra-value" id="rbi-infra-servico">—</span></div>
                <div class="rbi-infra-row"><span class="rbi-infra-label">Porta interna</span><span class="rbi-infra-value" id="rbi-infra-porta">—</span></div>
            </div>

            <div class="rbi-field">
                <label class="rbi-field-label">Tipo de conexão</label>
                <div class="rbi-conn-tipo-row">
                    <button type="button" class="rbi-conn-tipo-btn active" id="rbi-conn-tipo-sql" data-val="sql">SQL</button>
                    <button type="button" class="rbi-conn-tipo-btn" disabled title="Em breve">Webhook</button>
                    <button type="button" class="rbi-conn-tipo-btn" disabled title="Em breve">Excel</button>
                </div>
            </div>

            <div class="rbi-conn-grid">
                <div class="rbi-field full">
                    <label class="rbi-field-label">Host</label>
                    <input type="text" class="rbi-field-input" id="rbi-conn-host" autocomplete="off">
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Porta</label>
                    <input type="number" class="rbi-field-input" id="rbi-conn-port" autocomplete="off" value="5432">
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Banco</label>
                    <input type="text" class="rbi-field-input" id="rbi-conn-dbname" autocomplete="off">
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Usuário</label>
                    <input type="text" class="rbi-field-input" id="rbi-conn-user" autocomplete="off">
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Senha</label>
                    <div class="rbi-conn-pass-wrap">
                        <input type="password" class="rbi-field-input" id="rbi-conn-password" autocomplete="new-password">
                        <button type="button" class="rbi-conn-pass-toggle" id="rbi-conn-pass-toggle" title="Mostrar/ocultar"><i class="ti ti-eye"></i></button>
                    </div>
                </div>
            </div>

            <div class="rbi-conn-msg" id="rbi-conn-msg"></div>

            <div class="rbi-modal-footer">
                <button class="rbi-btn-cancel" id="rbi-conn-cancel">Cancelar</button>
                <button class="rbi-btn-save"   id="rbi-conn-save">Testar e salvar</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($_rtIsAdmin): ?>
<!-- Modal "Criar relatório" (Etapa 2 do self-service, admin_interno only) -->
<div class="rbi-overlay" id="rbi-create-overlay">
    <div class="rbi-modal rbi-create-modal" id="rbi-create-modal">
        <div class="rbi-modal-head">
            <span class="rbi-modal-title">Criar relatório</span>
            <button class="rbi-modal-close" id="rbi-create-close" title="Fechar">&times;</button>
        </div>

        <div class="rbi-field">
            <label class="rbi-field-label">Nome amigável</label>
            <input type="text" class="rbi-field-input" id="rbi-create-nome" autocomplete="off">
        </div>

        <div class="rbi-field">
            <label class="rbi-field-label">Slug (URL, imutável após criar)</label>
            <input type="text" class="rbi-field-input" id="rbi-create-slug" autocomplete="off">
            <span class="rbi-slug-preview" id="rbi-create-slug-msg"></span>
        </div>

        <div class="rbi-field">
            <label class="rbi-field-label">Tipo de conexão</label>
            <div class="rbi-conn-tipo-row">
                <button type="button" class="rbi-conn-tipo-btn active" id="rbi-create-tipo-sql" data-val="sql">SQL</button>
                <button type="button" class="rbi-conn-tipo-btn" disabled title="Em breve">Webhook</button>
                <button type="button" class="rbi-conn-tipo-btn" id="rbi-create-tipo-excel" data-val="excel">Excel</button>
            </div>
        </div>

        <!-- Campos SQL -->
        <div id="rbi-create-sql-fields">
            <div class="rbi-conn-grid">
                <div class="rbi-field full">
                    <label class="rbi-field-label">Host</label>
                    <input type="text" class="rbi-field-input" id="rbi-create-host" autocomplete="off">
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Porta</label>
                    <input type="number" class="rbi-field-input" id="rbi-create-port" autocomplete="off" value="5432">
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Banco</label>
                    <input type="text" class="rbi-field-input" id="rbi-create-dbname" autocomplete="off">
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Usuário</label>
                    <input type="text" class="rbi-field-input" id="rbi-create-user" autocomplete="off">
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Senha</label>
                    <div class="rbi-conn-pass-wrap">
                        <input type="password" class="rbi-field-input" id="rbi-create-password" autocomplete="new-password">
                        <button type="button" class="rbi-conn-pass-toggle" id="rbi-create-pass-toggle" title="Mostrar/ocultar"><i class="ti ti-eye"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Campos Excel -->
        <div id="rbi-create-excel-fields" style="display:none">
            <div class="rbi-field">
                <label class="rbi-field-label">Tabelas</label>
                <div id="rbi-excel-tabelas-list" style="display:flex;flex-direction:column;gap:.6rem"></div>
            </div>
            <button type="button" class="rbi-btn-add-tabela" id="rbi-btn-add-tabela" style="margin-top:.6rem;width:100%">
                <i class="ti ti-plus" style="margin-right:.3rem"></i>Adicionar tabela
            </button>
        </div>

        <div class="rbi-create-msg" id="rbi-create-msg"></div>

        <div class="rbi-modal-footer">
            <button class="rbi-btn-cancel" id="rbi-create-cancel">Cancelar</button>
            <button class="rbi-btn-save"   id="rbi-create-save">Criar relatório</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tooltip de usuários (chip "N usuários") -->
<div class="rbi-user-tooltip" id="rbi-user-tooltip" style="display:none"></div>

<script>
(function () {
    const row        = document.getElementById('rbi-cards-row');
    const overlay    = document.getElementById('rbi-overlay');
    const editId     = document.getElementById('rbi-edit-id');
    const editSlug   = document.getElementById('rbi-edit-slug');
    const editNome   = document.getElementById('rbi-edit-nome');
    const btnSave    = document.getElementById('rbi-btn-save');
    const btnOpen    = document.getElementById('rbi-btn-open');
    const tooltip    = document.getElementById('rbi-user-tooltip');
    const searchInp  = document.getElementById('rbi-search');
    const empresaSel = document.getElementById('rbi-empresa-filter');
    const btnGrid    = document.getElementById('rbi-view-grid');
    const btnList    = document.getElementById('rbi-view-list');

    // ── Tabs do modal (Geral | Conexão — Conexão só existe no DOM para admin_interno) ──
    const tabBtnGeral   = document.getElementById('rbi-tab-btn-geral');
    const tabBtnConexao = document.getElementById('rbi-tab-btn-conexao');
    const tabGeral      = document.getElementById('rbi-tab-geral');
    const tabConexao    = document.getElementById('rbi-tab-conexao');

    // ── Aba de conexão (admin_interno only — elementos null quando não renderizados) ──
    const connRelId     = document.getElementById('rbi-conn-relatorio-id');
    const connHost      = document.getElementById('rbi-conn-host');
    const connPort      = document.getElementById('rbi-conn-port');
    const connDbname    = document.getElementById('rbi-conn-dbname');
    const connUser      = document.getElementById('rbi-conn-user');
    const connPassword  = document.getElementById('rbi-conn-password');
    const connMsg       = document.getElementById('rbi-conn-msg');
    const connBtnSave   = document.getElementById('rbi-conn-save');
    const connPassToggle = document.getElementById('rbi-conn-pass-toggle');
    const infraPasta    = document.getElementById('rbi-infra-pasta');
    const infraServico  = document.getElementById('rbi-infra-servico');
    const infraPorta    = document.getElementById('rbi-infra-porta');

    // ── Toggle "Em construção" na aba Geral (admin_interno only) ─────────────
    const construcaoSimBtn = document.getElementById('rbi-construcao-sim');
    const construcaoNaoBtn = document.getElementById('rbi-construcao-nao');

    // ── Modal "Criar relatório" (admin_interno only) ─────────────────────────
    const createOverlay   = document.getElementById('rbi-create-overlay');
    const createNome       = document.getElementById('rbi-create-nome');
    const createSlug        = document.getElementById('rbi-create-slug');
    const createSlugMsg     = document.getElementById('rbi-create-slug-msg');
    const createTipoSql     = document.getElementById('rbi-create-tipo-sql');
    const createTipoExcel   = document.getElementById('rbi-create-tipo-excel');
    const createSqlFields    = document.getElementById('rbi-create-sql-fields');
    const createExcelFields  = document.getElementById('rbi-create-excel-fields');
    const createHost        = document.getElementById('rbi-create-host');
    const createPort        = document.getElementById('rbi-create-port');
    const createDbname      = document.getElementById('rbi-create-dbname');
    const createUser        = document.getElementById('rbi-create-user');
    const createPassword    = document.getElementById('rbi-create-password');
    const createPassToggle  = document.getElementById('rbi-create-pass-toggle');
    const excelTabelasList   = document.getElementById('rbi-excel-tabelas-list');
    const btnAddTabela       = document.getElementById('rbi-btn-add-tabela');
    const createMsg         = document.getElementById('rbi-create-msg');
    const createSaveBtn     = document.getElementById('rbi-create-save');
    const btnAddRelatorio    = document.getElementById('rbi-btn-add-relatorio');

    let visivel      = true;
    let emConstrucao = true;
    let _empresaFiltroSalvo = null; // id de empresa restaurado do sessionStorage, aplicado após popular o <select>

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Visualização grade / lista (persistida em localStorage) ─────────────
    function setView(view) {
        row.className = 'rbi-cards-row view-' + view;
        btnGrid.classList.toggle('active', view === 'grid');
        btnList.classList.toggle('active', view === 'list');
        try { localStorage.setItem('bi_hub_view', view); } catch (e) {}
        aplicarEscalaThumbs();
    }
    btnGrid.addEventListener('click', function () { setView('grid'); });
    btnList.addEventListener('click', function () { setView('list'); });

    // ── Modal de configuração (comportamento existente, preservado) ─────────
    function setVis(v) {
        visivel = v;
        document.getElementById('rbi-vis-visivel').className = 'rbi-vis-btn' + (v ? ' active-vis' : '');
        document.getElementById('rbi-vis-oculto').className  = 'rbi-vis-btn' + (!v ? ' active-oculto' : '');
    }

    // "Em construção" — admin_interno only, elementos null pra quem não é admin.
    function setConstrucao(v) {
        emConstrucao = v;
        if (!construcaoSimBtn || !construcaoNaoBtn) return;
        construcaoSimBtn.className = 'rbi-toggle-btn' + (v ? ' active-b' : '');
        construcaoNaoBtn.className = 'rbi-toggle-btn' + (!v ? ' active-a' : '');
    }
    if (construcaoSimBtn) construcaoSimBtn.addEventListener('click', function () { setConstrucao(true); });
    if (construcaoNaoBtn) construcaoNaoBtn.addEventListener('click', function () { setConstrucao(false); });

    // ── Troca de aba (Geral | Conexão) — no-op se a aba Conexão não existir no DOM
    // (usuário não admin_interno, PHP não renderiza a aba). Não recarrega dados ao
    // trocar de aba — só mostra/esconde, então nada digitado na outra aba se perde.
    function switchTab(tab) {
        if (!tabGeral || !tabConexao) return;
        const isConexao = tab === 'conexao';
        tabGeral.classList.toggle('rbi-tab-hidden', isConexao);
        tabConexao.classList.toggle('rbi-tab-hidden', !isConexao);
        if (tabBtnGeral)   tabBtnGeral.classList.toggle('active', !isConexao);
        if (tabBtnConexao) tabBtnConexao.classList.toggle('active', isConexao);
    }
    if (tabBtnGeral)   tabBtnGeral.addEventListener('click',   function () { switchTab('geral'); });
    if (tabBtnConexao) tabBtnConexao.addEventListener('click', function () { switchTab('conexao'); });

    function openModal(card) {
        editId.value   = card.id;
        editSlug.value = card.slug;
        editNome.value = card.nome_amigavel;
        setVis(card.visivel !== false);
        setConstrucao(card.em_construcao === true);
        switchTab('geral'); // sempre abre na aba Geral, independente da aba deixada aberta da última vez
        if (window.RBI_IS_ADMIN && connRelId) {
            connRelId.value = card.id;
            loadConexaoConfig(card.id);
        }
        overlay.classList.add('open');
        editNome.focus();
    }
    function closeModal() {
        overlay.classList.remove('open');
    }

    // ── Aba de conexão (admin_interno only) ──────────────────────────────────
    function connShowMsg(texto, tipo) {
        if (!connMsg) return;
        connMsg.textContent = texto;
        connMsg.className = 'rbi-conn-msg show ' + (tipo || 'erro');
    }
    function connClearMsg() {
        if (!connMsg) return;
        connMsg.className = 'rbi-conn-msg';
        connMsg.textContent = '';
    }
    // Busca a config atual do relatório uma única vez por abertura do modal (openModal
    // chama isto antes do usuário poder trocar de aba) — trocar de aba depois não refaz
    // a busca, então uma edição em andamento nunca é sobrescrita.
    function loadConexaoConfig(relatorioId) {
        if (!connHost) return;
        connHost.value = ''; connPort.value = '5432'; connDbname.value = '';
        connUser.value = ''; connPassword.value = '';
        infraPasta.textContent = '—'; infraServico.textContent = '—'; infraPorta.textContent = '—';
        connClearMsg();
        fetch('/api/relatorio-conexao.php?action=get&relatorio_id=' + encodeURIComponent(relatorioId))
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.erro) { connShowMsg(res.erro, 'erro'); return; }
                var cfg = (res.conexao && res.conexao.config) || {};
                connHost.value     = cfg.host || '';
                connPort.value     = cfg.port || 5432;
                connDbname.value   = cfg.dbname || '';
                connUser.value     = cfg.user || '';
                connPassword.value = cfg.password || '';

                var infra = res.infraestrutura || {};
                infraPasta.textContent   = infra.pasta   || '—';
                infraServico.textContent = infra.servico || '—';
                infraPorta.textContent   = infra.porta   || '—';
            })
            .catch(function () { connShowMsg('Erro de rede ao carregar configuração.', 'erro'); });
    }
    if (connPassToggle) {
        connPassToggle.addEventListener('click', function () {
            connPassword.type = connPassword.type === 'password' ? 'text' : 'password';
        });
    }
    const connCancelBtn = document.getElementById('rbi-conn-cancel');
    if (connCancelBtn) connCancelBtn.addEventListener('click', closeModal);

    if (connBtnSave) connBtnSave.addEventListener('click', function () {
        connClearMsg();
        var payload = {
            relatorio_id: parseInt(connRelId.value, 10),
            tipo_conexao: 'sql',
            config: {
                host: connHost.value.trim(),
                port: parseInt(connPort.value, 10) || 5432,
                dbname: connDbname.value.trim(),
                user: connUser.value.trim(),
                password: connPassword.value
            }
        };
        if (!payload.config.host || !payload.config.dbname || !payload.config.user) {
            connShowMsg('Host, banco e usuário são obrigatórios.', 'erro');
            return;
        }
        connBtnSave.disabled = true;
        connBtnSave.textContent = 'Testando conexão...';
        fetch('/api/relatorio-conexao.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.sucesso) {
                connShowMsg('Conexão testada e salva com sucesso.', 'ok');
                setTimeout(closeModal, 900);
            } else {
                connShowMsg(res.erro || 'Erro ao salvar.', 'erro');
            }
        })
        .catch(function () { connShowMsg('Erro de rede ao salvar.', 'erro'); })
        .finally(function () {
            connBtnSave.disabled = false;
            connBtnSave.textContent = 'Testar e salvar';
        });
    });

    // ── Modal "Criar relatório" (admin_interno only — elementos null pra quem não é) ──
    function slugifyClient(texto) {
        var t = String(texto || '').trim().normalize('NFD').replace(new RegExp('[\\u0300-\\u036f]', 'g'), '');
        t = t.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        return t;
    }

    var slugEditadoManualmente = false;
    var slugCheckTimer = null;
    var slugDisponivel = false;

    function createShowMsg(texto, tipo) {
        if (!createMsg) return;
        createMsg.textContent = texto;
        createMsg.className = 'rbi-create-msg show ' + (tipo || 'erro');
    }
    function createClearMsg() {
        if (!createMsg) return;
        createMsg.className = 'rbi-create-msg';
        createMsg.textContent = '';
    }

    function checarSlug(slug) {
        if (!createSlugMsg) return;
        if (!slug) { createSlugMsg.textContent = ''; createSlugMsg.className = 'rbi-slug-preview'; slugDisponivel = false; return; }
        fetch('/api/relatorio-criar.php?action=check-slug&slug=' + encodeURIComponent(slug))
            .then(function (r) { return r.json(); })
            .then(function (res) {
                slugDisponivel = !!res.disponivel;
                createSlugMsg.textContent = res.disponivel ? ('Disponível: /' + slug) : (res.erro || 'Indisponível');
                createSlugMsg.className = 'rbi-slug-preview ' + (res.disponivel ? 'ok' : 'bad');
            })
            .catch(function () {
                slugDisponivel = false;
                createSlugMsg.textContent = 'Erro ao checar disponibilidade.';
                createSlugMsg.className = 'rbi-slug-preview bad';
            });
    }

    if (createNome) {
        createNome.addEventListener('input', function () {
            if (slugEditadoManualmente) return;
            createSlug.value = slugifyClient(createNome.value);
            clearTimeout(slugCheckTimer);
            slugCheckTimer = setTimeout(function () { checarSlug(createSlug.value); }, 350);
        });
    }
    if (createSlug) {
        createSlug.addEventListener('input', function () {
            slugEditadoManualmente = true;
            createSlug.value = createSlug.value.toLowerCase();
            clearTimeout(slugCheckTimer);
            slugCheckTimer = setTimeout(function () { checarSlug(createSlug.value.trim()); }, 350);
        });
    }

    // ── Tipo de conexão (SQL | Excel — Webhook desabilitado) ─────────────────
    function setCreateTipo(tipo) {
        if (createTipoSql)   createTipoSql.classList.toggle('active', tipo === 'sql');
        if (createTipoExcel) createTipoExcel.classList.toggle('active', tipo === 'excel');
        if (createSqlFields)   createSqlFields.style.display   = tipo === 'sql'   ? '' : 'none';
        if (createExcelFields) createExcelFields.style.display = tipo === 'excel' ? '' : 'none';
    }
    if (createTipoSql)   createTipoSql.addEventListener('click',   function () { setCreateTipo('sql'); });
    if (createTipoExcel) createTipoExcel.addEventListener('click', function () { setCreateTipo('excel'); });

    // ── Linhas de tabela Excel (repetíveis) ───────────────────────────────────
    function criarLinhaTabelaExcel() {
        var linha = document.createElement('div');
        linha.className = 'rbi-excel-tabela-row';
        linha.innerHTML =
            '<div class="rbi-field">' +
                '<label class="rbi-field-label">Nome da tabela</label>' +
                '<input type="text" class="rbi-field-input rbi-excel-nome" autocomplete="off">' +
            '</div>' +
            '<div class="rbi-field">' +
                '<label class="rbi-field-label">Arquivo (.xlsx)</label>' +
                '<input type="file" class="rbi-field-input rbi-excel-arquivo" accept=".xlsx">' +
            '</div>' +
            '<button type="button" class="rbi-excel-remove" title="Remover"><i class="ti ti-trash"></i></button>';
        linha.querySelector('.rbi-excel-remove').addEventListener('click', function () { linha.remove(); });
        return linha;
    }
    if (btnAddTabela) {
        btnAddTabela.addEventListener('click', function () {
            excelTabelasList.appendChild(criarLinhaTabelaExcel());
        });
    }

    if (createPassToggle) {
        createPassToggle.addEventListener('click', function () {
            createPassword.type = createPassword.type === 'password' ? 'text' : 'password';
        });
    }

    function openCreateModal() {
        if (!createOverlay) return;
        createNome.value = '';
        createSlug.value = '';
        slugEditadoManualmente = false;
        slugDisponivel = false;
        createSlugMsg.textContent = '';
        createSlugMsg.className = 'rbi-slug-preview';
        setCreateTipo('sql');
        createHost.value = ''; createPort.value = '5432'; createDbname.value = '';
        createUser.value = ''; createPassword.value = '';
        excelTabelasList.innerHTML = '';
        excelTabelasList.appendChild(criarLinhaTabelaExcel());
        createClearMsg();
        createOverlay.classList.add('open');
        createNome.focus();
    }
    function closeCreateModal() {
        if (createOverlay) createOverlay.classList.remove('open');
    }
    if (btnAddRelatorio) btnAddRelatorio.addEventListener('click', openCreateModal);
    var createCloseBtn  = document.getElementById('rbi-create-close');
    var createCancelBtn = document.getElementById('rbi-create-cancel');
    if (createCloseBtn)  createCloseBtn.addEventListener('click', closeCreateModal);
    if (createCancelBtn) createCancelBtn.addEventListener('click', closeCreateModal);
    if (createOverlay) {
        createOverlay.addEventListener('click', function (e) { if (e.target === createOverlay) closeCreateModal(); });
    }

    if (createSaveBtn) createSaveBtn.addEventListener('click', function () {
        createClearMsg();
        var nome = createNome.value.trim();
        var slug = createSlug.value.trim();
        var tipo = createTipoExcel && createTipoExcel.classList.contains('active') ? 'excel' : 'sql';

        if (!nome) { createShowMsg('Nome amigável é obrigatório.', 'erro'); createNome.focus(); return; }
        if (!slug) { createShowMsg('Slug é obrigatório.', 'erro'); createSlug.focus(); return; }
        if (!slugDisponivel) { createShowMsg('Escolha um slug disponível antes de salvar.', 'erro'); createSlug.focus(); return; }

        var formData = new FormData();
        formData.append('nome_amigavel', nome);
        formData.append('slug', slug);
        formData.append('tipo_conexao', tipo);

        if (tipo === 'sql') {
            var host = createHost.value.trim(), dbname = createDbname.value.trim(), usuario = createUser.value.trim();
            if (!host || !dbname || !usuario) { createShowMsg('Host, banco e usuário são obrigatórios.', 'erro'); return; }
            formData.append('host', host);
            formData.append('porta', createPort.value || '5432');
            formData.append('banco', dbname);
            formData.append('usuario', usuario);
            formData.append('senha', createPassword.value);
        } else {
            var linhas = excelTabelasList.querySelectorAll('.rbi-excel-tabela-row');
            var algumaValida = false;
            for (var i = 0; i < linhas.length; i++) {
                var nomeTab = linhas[i].querySelector('.rbi-excel-nome').value.trim();
                var arquivoInput = linhas[i].querySelector('.rbi-excel-arquivo');
                var arquivo = arquivoInput.files[0];
                if (!nomeTab && !arquivo) continue; // linha em branco, ignora
                if (!nomeTab || !arquivo) {
                    createShowMsg('Toda tabela precisa de nome e arquivo juntos.', 'erro');
                    return;
                }
                formData.append('tabela_nome[]', nomeTab);
                formData.append('tabela_arquivo[]', arquivo);
                algumaValida = true;
            }
            if (!algumaValida) { createShowMsg('Adicione pelo menos uma tabela (nome + arquivo).', 'erro'); return; }
        }

        createSaveBtn.disabled = true;
        createSaveBtn.textContent = tipo === 'sql' ? 'Testando conexão...' : 'Processando arquivos...';
        fetch('/api/relatorio-criar.php?action=create', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.sucesso) {
                    createShowMsg('Relatório criado com sucesso.', 'ok');
                    setTimeout(function () { closeCreateModal(); loadCards(); }, 900);
                } else {
                    createShowMsg(res.erro || 'Erro ao criar relatório.', 'erro');
                }
            })
            .catch(function () { createShowMsg('Erro de rede ao criar relatório.', 'erro'); })
            .finally(function () {
                createSaveBtn.disabled = false;
                createSaveBtn.textContent = 'Criar relatório';
            });
    });

    // ── Tooltip de usuários (hover no chip "N usuários") ─────────────────────
    function montarTooltip(usuarios) {
        if (!usuarios || !usuarios.length) {
            return '<div class="rbi-tooltip-empty">Nenhum usuário com acesso.</div>';
        }
        return usuarios.map(function (u) {
            var cls = u.nivel === 'VP' ? 'vp' : 'v';
            return '<div class="rbi-tooltip-row"><span class="rbi-tooltip-badge ' + cls + '">' + u.nivel + '</span>' + escHtml(u.nome) + '</div>';
        }).join('');
    }
    function posicionarTooltip(e) {
        var pad = 14;
        var x = e.clientX + pad, y = e.clientY + pad;
        var rect = tooltip.getBoundingClientRect();
        if (x + rect.width  > window.innerWidth)  x = e.clientX - rect.width  - pad;
        if (y + rect.height > window.innerHeight) y = e.clientY - rect.height - pad;
        tooltip.style.left = x + 'px';
        tooltip.style.top  = y + 'px';
    }
    function mostrarTooltip(e, usuarios) {
        tooltip.innerHTML = montarTooltip(usuarios);
        tooltip.style.display = 'block';
        posicionarTooltip(e);
    }
    function esconderTooltip() {
        tooltip.style.display = 'none';
    }

    // ── Thumbnail (iframe estático escalado, ou ícone de fallback) ──────────
    function thumbHtml(slug) {
        var disponiveis = window.RBI_THUMBS_DISPONIVEIS || [];
        if (disponiveis.indexOf(slug) === -1) {
            return '<div class="rbi-thumb-fallback"><i class="ti ti-chart-bar"></i></div>';
        }
        return '<iframe class="rbi-thumb-iframe" src="/assets/img/relatorios/thumbs/' + encodeURIComponent(slug) + '.html" tabindex="-1" title=""></iframe>';
    }
    // Escala cada iframe (canvas fixo 320px) para preencher a largura real do container.
    function aplicarEscalaThumbs() {
        document.querySelectorAll('.rbi-thumb').forEach(function (thumbEl) {
            var iframe = thumbEl.querySelector('.rbi-thumb-iframe');
            if (!iframe) return;
            var scale = thumbEl.offsetWidth / 320;
            iframe.style.transform = 'scale(' + scale + ')';
        });
    }
    let _resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(_resizeTimer);
        _resizeTimer = setTimeout(aplicarEscalaThumbs, 150);
    });

    // ── Construção dos cards ──────────────────────────────────────────────────
    function buildCard(r) {
        const card = document.createElement('div');
        card.className = 'rbi-card';
        card.setAttribute('data-slug', r.slug);
        card.setAttribute('data-nome', (r.nome_amigavel || '').toLowerCase());
        card.setAttribute('data-empresas', (r.empresas || []).map(function (e) { return e.id; }).join('|'));

        const empresas = r.empresas || [];
        const empresasHtml = empresas.length
            ? empresas.map(function (e) { return '<span class="rbi-empresa-badge">' + escHtml(e.nome) + '</span>'; }).join('')
            : '<span class="rbi-empresa-badge" style="opacity:.5">Nenhuma empresa</span>';

        const userCount = r.user_count || 0;
        const badgeConstrucao = r.em_construcao
            ? '<span class="rbi-badge-construcao"><i class="ti ti-tool"></i>Em construção</span>'
            : '';

        card.innerHTML =
            badgeConstrucao +
            '<div class="rbi-thumb">' + thumbHtml(r.slug) + '</div>' +
            '<div class="rbi-card-body">' +
                '<div class="rbi-card-name">' + escHtml(r.nome_amigavel) + '</div>' +
                '<div class="rbi-empresas">' + empresasHtml + '</div>' +
            '</div>' +
            '<div class="rbi-user-chip"><i class="ti ti-user"></i>&nbsp;' + userCount + (userCount === 1 ? ' usuário' : ' usuários') + '</div>';

        // Card click — abre o modal de configuração (Geral + aba Conexão para admin_interno).
        card.addEventListener('click', function (e) {
            if (e.target.closest('.rbi-user-chip')) return;
            openModal(r);
        });

        const chip = card.querySelector('.rbi-user-chip');
        chip.addEventListener('mouseenter', function (e) { mostrarTooltip(e, r.usuarios); });
        chip.addEventListener('mousemove',  posicionarTooltip);
        chip.addEventListener('mouseleave', esconderTooltip);

        return card;
    }

    // ── Busca por texto + filtro de empresa (100% client-side) ──────────────
    function salvarFiltros() {
        try {
            sessionStorage.setItem('bi_filter_search', searchInp.value || '');
            sessionStorage.setItem('bi_filter_empresa_id', empresaSel.value || '');
        } catch (e) {}
    }
    function aplicarFiltros() {
        const termo   = (searchInp.value || '').trim().toLowerCase();
        const empresa = empresaSel.value;
        document.querySelectorAll('.rbi-card').forEach(function (card) {
            const nomeOk    = !termo || card.getAttribute('data-nome').indexOf(termo) !== -1;
            const empresas  = (card.getAttribute('data-empresas') || '').split('|');
            const empresaOk = !empresa || empresas.indexOf(empresa) !== -1;
            card.style.display = (nomeOk && empresaOk) ? '' : 'none';
        });
        salvarFiltros();
    }
    searchInp.addEventListener('input', aplicarFiltros);
    empresaSel.addEventListener('change', aplicarFiltros);

    // ── Dropdown "Todas as empresas" — agregado (por id, deduplicado) a partir dos relatórios carregados ──
    function popularFiltroEmpresas(data) {
        const mapa = new Map();
        data.forEach(function (r) { (r.empresas || []).forEach(function (e) { mapa.set(String(e.id), e.nome); }); });
        const ordenado = Array.from(mapa.entries()).sort(function (a, b) { return a[1].localeCompare(b[1], 'pt-BR'); });
        empresaSel.innerHTML = '<option value="">Todas as empresas</option>' +
            ordenado.map(function (e) { return '<option value="' + escHtml(e[0]) + '">' + escHtml(e[1]) + '</option>'; }).join('');
        // Restaura o filtro de empresa salvo (sessionStorage), se ainda existir entre as opções atuais.
        if (_empresaFiltroSalvo !== null) {
            const existe = Array.from(empresaSel.options).some(function (o) { return o.value === _empresaFiltroSalvo; });
            if (existe) empresaSel.value = _empresaFiltroSalvo;
            _empresaFiltroSalvo = null;
        }
    }

    // ── Carregar relatórios ────────────────────────────────────────────────────
    function loadCards() {
        row.innerHTML = '<span class="rbi-empty">Carregando...</span>';
        const permitidos = window.REL_TESTE_VISIVEIS; // null = admin_interno (sem filtro)
        if (permitidos !== null && !permitidos.length) {
            row.innerHTML = '<span class="rbi-empty">Nenhum relatório disponível para sua conta.</span>';
            empresaSel.innerHTML = '<option value="">Todas as empresas</option>';
            return;
        }
        fetch('/api/relatorios-bi.php?action=list')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                let data = res.data || [];
                if (permitidos !== null) {
                    data = data.filter(function (r) { return permitidos.indexOf(r.slug) !== -1; });
                }
                if (!res.success || !data.length) {
                    row.innerHTML = '<span class="rbi-empty">Nenhum relatório disponível.</span>';
                    empresaSel.innerHTML = '<option value="">Todas as empresas</option>';
                    return;
                }

                // Ordem alfabética por nome amigável, sempre.
                data.sort(function (a, b) { return (a.nome_amigavel || '').localeCompare(b.nome_amigavel || '', 'pt-BR'); });

                popularFiltroEmpresas(data);

                row.innerHTML = '';
                data.forEach(function (r) { row.appendChild(buildCard(r)); });
                aplicarEscalaThumbs();
                aplicarFiltros();
            })
            .catch(function () {
                row.innerHTML = '<span class="rbi-empty" style="color:#e53e3e">Erro ao carregar relatórios.</span>';
            });
    }

    // Visibility toggle buttons
    document.getElementById('rbi-vis-visivel').addEventListener('click', function () { setVis(true); });
    document.getElementById('rbi-vis-oculto').addEventListener('click',  function () { setVis(false); });

    // Close modal
    document.getElementById('rbi-modal-close').addEventListener('click', closeModal);
    document.getElementById('rbi-btn-cancel').addEventListener('click', closeModal);

    // Open report in new tab
    btnOpen.addEventListener('click', function () {
        const slug = editSlug.value;
        if (slug) window.open('https://app.kw24.com.br/relatorios-bi/' + slug, '_blank', 'noopener');
    });

    // Overlay click outside modal
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
    });

    // Save
    btnSave.addEventListener('click', function () {
        const nome = editNome.value.trim();
        if (!nome) { editNome.focus(); return; }

        btnSave.disabled = true;
        var payloadGeral = { id: parseInt(editId.value), nome_amigavel: nome, visivel: visivel };
        if (window.RBI_IS_ADMIN) payloadGeral.em_construcao = emConstrucao;
        fetch('/api/relatorios-bi.php?action=update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payloadGeral)
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                editSlug.value = res.slug;
                closeModal();
                loadCards();
            } else {
                alert('Erro ao salvar: ' + (res.erro || 'desconhecido'));
            }
        })
        .catch(function () { alert('Erro de rede ao salvar.'); })
        .finally(function () { btnSave.disabled = false; });
    });

    // ── Init ───────────────────────────────────────────────────────────────
    let viewSalva = 'grid';
    try { viewSalva = localStorage.getItem('bi_hub_view') || 'grid'; } catch (e) {}
    setView(viewSalva === 'list' ? 'list' : 'grid');

    // Restaura busca/empresa salvos pelo bridge de sessionStorage (compartilhado com portais-bi.php).
    try {
        const savedSearch = sessionStorage.getItem('bi_filter_search');
        if (savedSearch !== null) searchInp.value = savedSearch;
        _empresaFiltroSalvo = sessionStorage.getItem('bi_filter_empresa_id');
    } catch (e) {}

    loadCards();
})();
</script>
