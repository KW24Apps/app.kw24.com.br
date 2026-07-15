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
    max-width: 400px;
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

/* ── Botão "Configurar conexão" (admin_interno only) ──────────────────────── */
.rbi-conn-btn {
    position: absolute;
    top: .5rem;
    right: .5rem;
    z-index: 2;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(6,25,32,0.65);
    border: 1.5px solid rgba(255,255,255,0.15);
    border-radius: 8px;
    color: rgba(255,255,255,0.6);
    cursor: pointer;
    font-size: .85rem;
    transition: border-color .15s, color .15s, background .15s;
}
.rbi-conn-btn:hover {
    border-color: #0DC2FF;
    color: #0DC2FF;
    background: rgba(6,25,32,0.85);
}
.rbi-cards-row.view-list .rbi-conn-btn {
    position: static;
    margin-left: .5rem;
    flex-shrink: 0;
    order: 99;
}

/* ── Modal "Configurar conexão" ────────────────────────────────────────────── */
.rbi-conn-modal { max-width: 440px; }
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
        </div>
    </div>

    <!-- Cards -->
    <div class="rbi-cards-row view-grid" id="rbi-cards-row">
        <span class="rbi-empty">Carregando...</span>
    </div>

</div>

<!-- Config modal -->
<div class="rbi-overlay" id="rbi-overlay">
    <div class="rbi-modal" id="rbi-modal">
        <div class="rbi-modal-head">
            <span class="rbi-modal-title">Configurar relatório</span>
            <button class="rbi-modal-close" id="rbi-modal-close" title="Fechar">&times;</button>
        </div>

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

        <button class="rbi-btn-open" id="rbi-btn-open">
            <i class="ti ti-external-link" style="margin-right:.35rem"></i>Abrir relatório
        </button>

        <div class="rbi-modal-footer">
            <button class="rbi-btn-cancel" id="rbi-btn-cancel">Cancelar</button>
            <button class="rbi-btn-save"   id="rbi-btn-save">Salvar</button>
        </div>
    </div>
</div>

<!-- Config modal — conexão de dados (admin_interno only) -->
<div class="rbi-overlay" id="rbi-conn-overlay">
    <div class="rbi-modal rbi-conn-modal" id="rbi-conn-modal">
        <div class="rbi-modal-head">
            <span class="rbi-modal-title">Configurar conexão</span>
            <button class="rbi-modal-close" id="rbi-conn-close" title="Fechar">&times;</button>
        </div>

        <input type="hidden" id="rbi-conn-relatorio-id">

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
</div>

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

    // ── Modal de conexão (admin_interno only) ────────────────────────────────
    const connOverlay   = document.getElementById('rbi-conn-overlay');
    const connRelId     = document.getElementById('rbi-conn-relatorio-id');
    const connHost      = document.getElementById('rbi-conn-host');
    const connPort      = document.getElementById('rbi-conn-port');
    const connDbname    = document.getElementById('rbi-conn-dbname');
    const connUser      = document.getElementById('rbi-conn-user');
    const connPassword  = document.getElementById('rbi-conn-password');
    const connMsg       = document.getElementById('rbi-conn-msg');
    const connBtnSave   = document.getElementById('rbi-conn-save');
    const connPassToggle = document.getElementById('rbi-conn-pass-toggle');

    let visivel      = true;
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
    function openModal(card) {
        editId.value   = card.id;
        editSlug.value = card.slug;
        editNome.value = card.nome_amigavel;
        setVis(card.visivel !== false);
        overlay.classList.add('open');
        editNome.focus();
    }
    function closeModal() {
        overlay.classList.remove('open');
    }

    // ── Modal de conexão (admin_interno only) ────────────────────────────────
    function connShowMsg(texto, tipo) {
        connMsg.textContent = texto;
        connMsg.className = 'rbi-conn-msg show ' + (tipo || 'erro');
    }
    function connClearMsg() {
        connMsg.className = 'rbi-conn-msg';
        connMsg.textContent = '';
    }
    function openConnModal(relatorioId) {
        connRelId.value = relatorioId;
        connHost.value = ''; connPort.value = '5432'; connDbname.value = '';
        connUser.value = ''; connPassword.value = '';
        connClearMsg();
        connOverlay.classList.add('open');
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
            })
            .catch(function () { connShowMsg('Erro de rede ao carregar configuração.', 'erro'); });
    }
    function closeConnModal() {
        connOverlay.classList.remove('open');
    }
    connPassToggle.addEventListener('click', function () {
        connPassword.type = connPassword.type === 'password' ? 'text' : 'password';
    });
    document.getElementById('rbi-conn-close').addEventListener('click', closeConnModal);
    document.getElementById('rbi-conn-cancel').addEventListener('click', closeConnModal);
    connOverlay.addEventListener('click', function (e) { if (e.target === connOverlay) closeConnModal(); });

    connBtnSave.addEventListener('click', function () {
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
                setTimeout(closeConnModal, 900);
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
        const connBtnHtml = window.RBI_IS_ADMIN
            ? '<button type="button" class="rbi-conn-btn" title="Configurar conexão"><i class="ti ti-database-cog"></i></button>'
            : '';

        card.innerHTML =
            connBtnHtml +
            '<div class="rbi-thumb">' + thumbHtml(r.slug) + '</div>' +
            '<div class="rbi-card-body">' +
                '<div class="rbi-card-name">' + escHtml(r.nome_amigavel) + '</div>' +
                '<div class="rbi-empresas">' + empresasHtml + '</div>' +
            '</div>' +
            '<div class="rbi-user-chip"><i class="ti ti-user"></i>&nbsp;' + userCount + (userCount === 1 ? ' usuário' : ' usuários') + '</div>';

        // Card click — abre o modal de configuração (comportamento existente preservado).
        card.addEventListener('click', function (e) {
            if (e.target.closest('.rbi-user-chip') || e.target.closest('.rbi-conn-btn')) return;
            openModal(r);
        });

        const chip = card.querySelector('.rbi-user-chip');
        chip.addEventListener('mouseenter', function (e) { mostrarTooltip(e, r.usuarios); });
        chip.addEventListener('mousemove',  posicionarTooltip);
        chip.addEventListener('mouseleave', esconderTooltip);

        const connBtn = card.querySelector('.rbi-conn-btn');
        if (connBtn) {
            connBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                openConnModal(r.id);
            });
        }

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
        fetch('/api/relatorios-bi.php?action=update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: parseInt(editId.value), nome_amigavel: nome, visivel: visivel })
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
