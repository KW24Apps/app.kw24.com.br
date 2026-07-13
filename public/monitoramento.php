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
<style>
/* ===== MONITORAMENTO KW24 — layout geral ===== */
.mon-updated {
    font-size: .72rem;
    color: rgba(255,255,255,.35);
}
.mon-panels-row {
    display: flex;
    gap: 1.25rem;
    align-items: stretch;
    flex: 1;
    min-height: 0;
}
.mon-right-col {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    flex: 1 1 auto;
    min-width: 320px;
    min-height: 0;
}
@media (max-width: 1024px) {
    .mon-panels-row { flex-direction: column; }
    .mon-equipe-card { flex: 0 0 auto !important; max-height: 45vh; }
    .mon-right-col { flex: 1 1 auto; min-height: 560px; }
    .cha-section { flex: 0 0 260px !important; }
    .tsk-section { flex: 1 1 auto !important; min-height: 280px; }
}

/* ===== Painel Equipe — card único, membros empilhados ===== */
.mon-equipe-card {
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    flex: 0 0 400px;
    min-width: 320px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.mon-equipe-header {
    padding: .9rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    font-family: 'Rubik', sans-serif;
    font-size: .95rem;
    font-weight: 600;
    color: #fff;
    flex-shrink: 0;
}
.mon-equipe-header i { color: #0DC2FF; margin-right: .5rem; }
.mon-equipe-total {
    padding: .8rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex;
    gap: 1.5rem;
    flex-shrink: 0;
}
.mon-equipe-total-item { display: flex; flex-direction: column; gap: .15rem; }
.mon-equipe-total-value {
    font-size: 1rem;
    font-weight: 700;
    color: #fff;
    font-family: 'Inter', monospace;
}
.mon-equipe-total-label {
    font-size: .62rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: rgba(255,255,255,.4);
}
.mon-equipe-body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 1.1rem 1.25rem;
}
.mon-membro-row {
    padding-bottom: 1.1rem;
    margin-bottom: 1.1rem;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.mon-membro-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.mon-membro-nome {
    font-family: 'Rubik', sans-serif;
    font-size: 1rem;
    font-weight: 600;
    color: #fff;
    margin-bottom: 1.1rem;
    display: flex;
    align-items: center;
    gap: .55rem;
}
.mon-membro-nome i { color: #0DC2FF; font-size: 1.1rem; }

.mon-row { margin-bottom: 1.1rem; }
.mon-row:last-child { margin-bottom: 0; }
.mon-row-label {
    font-size: .67rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: rgba(255,255,255,.4);
    margin-bottom: .5rem;
}
.mon-bar {
    display: flex;
    width: 100%;
    height: 24px;
    border-radius: 6px;
    overflow: hidden;
    background: rgba(255,255,255,.04);
}
.mon-seg {
    flex: 1 1 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .62rem;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0 .4rem;
    cursor: pointer;
    transition: filter .15s ease;
}
.mon-seg:hover { filter: brightness(1.12); }
.mon-seg.suporte { background: linear-gradient(90deg,#0DC2FF,#0080aa); color: #061920; }
.mon-seg.dev      { background: linear-gradient(90deg,#b794f4,#805ad5); color: #fff; }

.mon-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: rgba(255,255,255,.3);
}
.mon-empty i { font-size: 2rem; margin-bottom: .75rem; display: block; color: rgba(13,194,255,.4); }

/* Drill-down: lista de chamados de um segmento */
#mon-drill-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(6,25,32,.7);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
#mon-drill-box {
    background: #0d1e2d;
    border: 1.5px solid rgba(255,255,255,.12);
    border-radius: 14px;
    padding: 1.5rem;
    width: 520px;
    max-width: 92vw;
    max-height: 72vh;
    display: flex;
    flex-direction: column;
    animation: monDrillPop .18s ease;
}
@keyframes monDrillPop { from { opacity:0; transform:scale(.94) } to { opacity:1; transform:scale(1) } }
#mon-drill-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1rem;
    flex-shrink: 0;
}
#mon-drill-title {
    margin: 0;
    color: #fff;
    font-family: 'Rubik', sans-serif;
    font-size: 1rem;
    font-weight: 600;
}
#mon-drill-subtitle {
    margin: .2rem 0 0;
    color: rgba(255,255,255,.4);
    font-size: .75rem;
}
#mon-drill-close {
    background: none;
    border: none;
    color: rgba(255,255,255,.5);
    font-size: 1.2rem;
    cursor: pointer;
    line-height: 1;
    padding: 0 .25rem;
}
#mon-drill-close:hover { color: #fff; }
#mon-drill-list {
    overflow-y: auto;
    flex: 1;
    min-height: 0;
}
.mon-drill-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    padding: .6rem 0;
    border-bottom: 1px solid rgba(255,255,255,.06);
    text-decoration: none;
    color: #0DC2FF;
    font-size: .82rem;
    transition: color .15s;
}
.mon-drill-item:hover { color: #26d4ff; }
.mon-drill-item:last-child { border-bottom: none; }
.mon-drill-item-main { display: flex; flex-direction: column; gap: .15rem; min-width: 0; }
.mon-drill-id { font-family: 'Inter', monospace; font-weight: 700; }
.mon-drill-titletext {
    color: rgba(255,255,255,.6);
    font-size: .75rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mon-drill-time {
    flex-shrink: 0;
    color: rgba(255,255,255,.5);
    font-size: .75rem;
    font-family: 'Inter', monospace;
}

/* Modal de chat de uma tarefa */
#tsk-chat-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(6,25,32,.7);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
#tsk-chat-box {
    background: #0d1e2d;
    border: 1.5px solid rgba(255,255,255,.12);
    border-radius: 14px;
    padding: 1.5rem;
    width: 480px;
    max-width: 92vw;
    max-height: 72vh;
    display: flex;
    flex-direction: column;
    animation: monDrillPop .18s ease;
}
#tsk-chat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1rem;
    flex-shrink: 0;
}
#tsk-chat-title {
    margin: 0;
    color: #fff;
    font-family: 'Rubik', sans-serif;
    font-size: 1rem;
    font-weight: 600;
}
#tsk-chat-close {
    background: none;
    border: none;
    color: rgba(255,255,255,.5);
    font-size: 1.2rem;
    cursor: pointer;
    line-height: 1;
    padding: 0 .25rem;
}
#tsk-chat-close:hover { color: #fff; }
#tsk-chat-list {
    overflow-y: auto;
    flex: 1;
    min-height: 0;
}

/* ===== MONITORAMENTO KW24 — Chamados abertos ===== */
.cha-section {
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    flex: 0 0 42%;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.cha-section-header {
    padding: .9rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: .6rem;
    flex-shrink: 0;
}
.cha-section-title {
    font-family: 'Rubik', sans-serif;
    font-size: .95rem;
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
}
.cha-section-title i { color: #0DC2FF; margin-right: .5rem; }
.cha-section-count {
    font-size: .75rem;
    color: rgba(255,255,255,.45);
    white-space: nowrap;
}
.cha-header-filters {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    margin-left: auto;
}
.cha-list {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
}
.cha-list::-webkit-scrollbar { width: 5px; }
.cha-list::-webkit-scrollbar-track { background: rgba(255,255,255,0.03); }
.cha-list::-webkit-scrollbar-thumb { background: rgba(13,194,255,0.25); border-radius: 3px; }
.cha-row {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: .6rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.cha-row:last-child { border-bottom: none; }
.cha-row-id {
    font-family: 'Inter', monospace;
    font-size: .72rem;
    font-weight: 700;
    color: rgba(255,255,255,.4);
    flex-shrink: 0;
}
.cha-row-title {
    color: #fff;
    font-size: .83rem;
    font-weight: 500;
    flex: 1;
    min-width: 100px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.cha-badge {
    font-size: .64rem;
    font-weight: 700;
    padding: .15rem .5rem;
    border-radius: 20px;
    white-space: nowrap;
    flex-shrink: 0;
}
.cha-etapa {
    font-size: .72rem;
    color: rgba(255,255,255,.5);
    flex-shrink: 0;
    white-space: nowrap;
    max-width: 140px;
    overflow: hidden;
    text-overflow: ellipsis;
}
.cha-avatares { display: flex; gap: .25rem; flex-shrink: 0; }
.cha-avatar {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: linear-gradient(135deg,#0DC2FF,#086B8D);
    color: #061920;
    font-size: .6rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.cha-sem-resp { font-size: .68rem; color: rgba(255,255,255,.3); flex-shrink: 0; white-space: nowrap; }
.cha-idade {
    font-size: .72rem;
    color: rgba(255,255,255,.4);
    flex-shrink: 0;
    white-space: nowrap;
    font-family: 'Inter', monospace;
}
.cha-chat-icon { color: rgba(255,255,255,.35); flex-shrink: 0; font-size: .78rem; cursor: pointer; }
.cha-chat-icon:hover { color: #b794f4; }
.cha-link-icon { color: rgba(255,255,255,.35); flex-shrink: 0; font-size: .78rem; text-decoration: none; }
.cha-link-icon:hover { color: #0DC2FF; }

/* ===== MONITORAMENTO KW24 — Tarefas ===== */
.tsk-section {
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    flex: 1 1 auto;
    min-width: 320px;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.tsk-section-header {
    padding: .9rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: .6rem;
    flex-shrink: 0;
}
.tsk-section-title {
    font-family: 'Rubik', sans-serif;
    font-size: .95rem;
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
}
.tsk-section-title i { color: #b794f4; margin-right: .5rem; }
.tsk-section-count {
    font-size: .75rem;
    color: rgba(255,255,255,.45);
    white-space: nowrap;
}
.tsk-header-filters {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    margin-left: auto;
}
.tsk-filter-pill {
    font-size: .72rem;
    font-weight: 600;
    padding: .3rem .75rem;
    border-radius: 20px;
    cursor: pointer;
    border: 1px solid rgba(183,148,244,.35);
    color: rgba(255,255,255,.5);
    background: transparent;
    transition: background .15s, color .15s, border-color .15s;
    user-select: none;
}
.tsk-filter-pill:hover { border-color: rgba(183,148,244,.6); color: rgba(255,255,255,.8); }
.tsk-filter-pill.active {
    background: linear-gradient(90deg,#b794f4,#805ad5);
    color: #fff;
    border-color: transparent;
}
.tsk-list {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
}
.tsk-list::-webkit-scrollbar { width: 5px; }
.tsk-list::-webkit-scrollbar-track { background: rgba(255,255,255,0.03); }
.tsk-list::-webkit-scrollbar-thumb { background: rgba(183,148,244,0.25); border-radius: 3px; }
.tsk-row {
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.tsk-row:last-child { border-bottom: none; }
.tsk-row-main {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .8rem 1.25rem;
    cursor: pointer;
}
.tsk-row-main:hover { background: rgba(255,255,255,0.03); }
.tsk-chevron-btn {
    background: none;
    border: none;
    color: rgba(255,255,255,.28);
    cursor: pointer;
    padding: .3rem;
    flex-shrink: 0;
    transition: color .15s, transform .2s;
    line-height: 1;
}
.tsk-chevron-btn.open { color: #b794f4; transform: rotate(90deg); }
.tsk-row-id {
    font-family: 'Inter', monospace;
    font-size: .78rem;
    font-weight: 700;
    color: #0DC2FF;
    text-decoration: none;
    flex-shrink: 0;
}
.tsk-row-id:hover { color: #26d4ff; }
.tsk-row-title {
    color: #fff;
    font-size: .85rem;
    font-weight: 500;
    flex: 1;
    min-width: 120px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.tsk-badges { display: flex; flex-wrap: wrap; gap: .35rem; flex-shrink: 0; }
.tsk-badge {
    font-size: .68rem;
    font-weight: 600;
    padding: .18rem .55rem;
    border-radius: 20px;
    white-space: nowrap;
}
.tsk-badge.forte  { background: linear-gradient(90deg,#b794f4,#805ad5); color: #fff; }
.tsk-badge.media  { background: rgba(183,148,244,.35); color: #fff; }
.tsk-badge.fraca  { background: transparent; border: 1px solid rgba(183,148,244,.45); color: #b794f4; }
.tsk-deadline {
    font-size: .78rem;
    color: rgba(255,255,255,.5);
    flex-shrink: 0;
    white-space: nowrap;
}
.tsk-deadline.atrasada { color: #fc8181; font-weight: 600; }
.tsk-chat-icon { color: rgba(255,255,255,.35); flex-shrink: 0; font-size: .8rem; }
.tsk-row-detail { display: none; }
.tsk-row-detail.open { display: block; }
.tsk-detail-inner {
    padding: 1rem 1.25rem 1.25rem 3rem;
    background: rgba(183,148,244,.03);
    border-top: 1px solid rgba(183,148,244,.10);
}
.tsk-detail-label {
    font-size: .67rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #b794f4;
    margin-bottom: .35rem;
    margin-top: .85rem;
}
.tsk-detail-label:first-child { margin-top: 0; }
.tsk-detail-text { color: rgba(255,255,255,.75); font-size: .82rem; line-height: 1.5; }
.tsk-chat-msg {
    display: flex;
    flex-direction: column;
    gap: .15rem;
    padding: .5rem 0;
    border-bottom: 1px solid rgba(255,255,255,.05);
}
.tsk-chat-msg:last-child { border-bottom: none; }
.tsk-chat-msg-head {
    display: flex;
    justify-content: space-between;
    font-size: .72rem;
}
.tsk-chat-msg-autor { color: #b794f4; font-weight: 600; }
.tsk-chat-msg-data { color: rgba(255,255,255,.35); }
.tsk-chat-msg-texto { color: rgba(255,255,255,.7); font-size: .8rem; line-height: 1.45; white-space: pre-wrap; }

/* ===== MONITORAMENTO KW24 — Funil (volume: criados / finalizados, SPA 1054 / Funil 208) ===== */
.fun-section {
    display: flex;
    gap: 1.25rem;
    margin-bottom: 1.25rem;
    flex-shrink: 0;
}
.fun-card {
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    flex: 1 1 0;
    min-width: 0;
    overflow: hidden;
}
.fun-card-header {
    padding: .9rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    font-family: 'Rubik', sans-serif;
    font-size: .95rem;
    font-weight: 600;
    color: #fff;
}
.fun-card-header i { margin-right: .5rem; }
.fun-card-header.criados i { color: #0DC2FF; }
.fun-card-header.finalizados i { color: #48bb78; }
.fun-card-body {
    padding: 1.1rem 1.25rem;
    display: flex;
    gap: 2rem;
}
.fun-stat { display: flex; flex-direction: column; gap: .25rem; }
.fun-stat-value {
    font-size: 1.6rem;
    font-weight: 700;
    color: #fff;
    font-family: 'Inter', monospace;
}
.fun-stat-label {
    font-size: .67rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: rgba(255,255,255,.4);
}
@media (max-width: 1024px) {
    .fun-section { flex-direction: column; }
}
</style>

<div class="page-header">
    <h1 class="page-title"><i class="fas fa-satellite-dish" style="color:#0DC2FF;margin-right:.5rem"></i>Monitoramento KW24</h1>
    <div class="page-header-actions">
        <span class="mon-updated" id="mon-updated">—</span>
        <button class="btn-primary" id="mon-refresh-btn" onclick="monAtualizar()">
            <i class="fas fa-sync-alt" id="mon-refresh-icon"></i> Atualizar
        </button>
    </div>
</div>

<div class="fun-section" id="fun-section">
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

<div class="mon-panels-row">
    <div class="mon-equipe-card">
        <div class="mon-equipe-header"><i class="fas fa-users"></i>Equipe</div>
        <div class="mon-equipe-total" id="mon-equipe-total"></div>
        <div class="mon-equipe-body" id="mon-equipe-grid">
            <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
        </div>
    </div>

    <div class="mon-right-col">
        <div class="cha-section">
            <div class="cha-section-header">
                <span class="cha-section-title"><i class="fas fa-inbox"></i>Chamados abertos</span>
                <span class="cha-section-count" id="cha-count">Carregando…</span>
                <div class="cha-header-filters">
                    <span class="tsk-filter-pill" id="cha-toggle-tipos" onclick="chaToggleTipos()">Mostrar todos os tipos</span>
                </div>
            </div>
            <div class="cha-list" id="cha-list">
                <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
            </div>
        </div>

        <div class="tsk-section">
            <div class="tsk-section-header">
                <span class="tsk-section-title"><i class="fas fa-list-check"></i>Tarefas</span>
                <span class="tsk-section-count" id="tsk-count">Carregando…</span>
                <div class="tsk-header-filters" id="tsk-filter-row"></div>
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

<script>
(function () {

    var AUTO_REFRESH_MS = 30 * 60 * 1000; // 30 minutos
    var lastData = null;

    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Segmentos sempre 50/50 (largura fixa) — não proporcional ao valor, pra nunca cortar o
    // texto do label quando um dos dois lados é baixo ou zero.
    function barHtml(labelA, labelB, personIdx, rowKey) {
        return '<div class="mon-bar">'
            + '<div class="mon-seg suporte" onclick="monAbrirDrill(' + personIdx + ',\'' + rowKey + '\',\'suporte\')">' + escHtml(labelA) + '</div>'
            + '<div class="mon-seg dev" onclick="monAbrirDrill(' + personIdx + ',\'' + rowKey + '\',\'desenvolvimento\')">' + escHtml(labelB) + '</div>'
            + '</div>';
    }

    function membroCardHtml(m, idx) {
        var and    = m.andamento  || {};
        var andSup = (and.suporte && and.suporte.count) || 0;
        var andDev = (and.desenvolvimento && and.desenvolvimento.count) || 0;

        var fin       = m.finalizado || {};
        var finSupMin = (fin.suporte && fin.suporte.minutos) || 0;
        var finDevMin = (fin.desenvolvimento && fin.desenvolvimento.minutos) || 0;
        var finSupCnt = (fin.suporte && fin.suporte.count) || 0;
        var finDevCnt = (fin.desenvolvimento && fin.desenvolvimento.count) || 0;

        return '<div class="mon-membro-row">'
            + '<div class="mon-membro-nome"><i class="fas fa-user-circle"></i>' + escHtml(m.nome) + '</div>'
            + '<div class="mon-row">'
                + '<div class="mon-row-label">Em andamento</div>'
                + barHtml('Suporte · ' + andSup, 'Desenvolvimento · ' + andDev, idx, 'andamento')
            + '</div>'
            + '<div class="mon-row">'
                + '<div class="mon-row-label">Finalizado no ciclo</div>'
                + barHtml(
                    'Suporte · ' + finSupCnt + ' · ' + Math.round(finSupMin / 60) + 'h',
                    'Desenvolvimento · ' + finDevCnt + ' · ' + Math.round(finDevMin / 60) + 'h',
                    idx, 'finalizado')
            + '</div>'
            + '</div>';
    }

    function renderEquipeTotal(totalMinutos) {
        var el = document.getElementById('mon-equipe-total');
        if (!el) return;
        if (!totalMinutos) { el.innerHTML = ''; return; }

        var supHoras = Math.round((totalMinutos.suporte || 0) / 60);
        var devHoras = Math.round((totalMinutos.desenvolvimento || 0) / 60);

        el.innerHTML =
            '<div class="mon-equipe-total-item"><span class="mon-equipe-total-value">' + supHoras + 'h</span><span class="mon-equipe-total-label">Suporte no período</span></div>'
            + '<div class="mon-equipe-total-item"><span class="mon-equipe-total-value">' + devHoras + 'h</span><span class="mon-equipe-total-label">Dev no período</span></div>';
    }

    function render(data) {
        var grid = document.getElementById('mon-equipe-grid');

        if (data.aviso) {
            grid.innerHTML = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            renderEquipeTotal(null);
            return;
        }

        renderEquipeTotal(data.totalFinalizadoMinutos);

        var equipe = data.equipe || [];
        if (!equipe.length) {
            grid.innerHTML = '<div class="mon-empty"><i class="fas fa-inbox"></i><div>Nenhum dado disponível.</div></div>';
            return;
        }

        grid.innerHTML = equipe.map(membroCardHtml).join('');
    }

    // ── Drill-down: lista de chamados (com ID clicável para o Bitrix24) por trás de um segmento ──
    var ROW_LABELS    = { andamento: 'Em andamento', finalizado: 'Finalizado no ciclo' };
    var BUCKET_LABELS = { suporte: 'Suporte', desenvolvimento: 'Desenvolvimento' };

    function bitrixCardUrl(id) {
        var base = (lastData && lastData.bitrixBase) || '';
        return base ? (base + '/crm/type/1054/details/' + id + '/') : '';
    }

    function drillItemHtml(card, rowKey) {
        var url = bitrixCardUrl(card.id);
        var timeHtml = (rowKey === 'finalizado')
            ? '<span class="mon-drill-time">' + (card.minutos || 0) + ' min</span>'
            : '';
        var body = '<span class="mon-drill-item-main">'
            + '<span class="mon-drill-id">#' + card.id + '</span>'
            + '<span class="mon-drill-titletext">' + escHtml(card.title || '') + '</span>'
            + '</span>'
            + timeHtml
            + '<i class="fas fa-external-link-alt" style="flex-shrink:0"></i>';

        return url
            ? '<a class="mon-drill-item" href="' + escHtml(url) + '" target="_blank" rel="noopener">' + body + '</a>'
            : '<div class="mon-drill-item" style="cursor:default">' + body + '</div>';
    }

    window.monAbrirDrill = function (personIdx, rowKey, bucketKey) {
        if (!lastData || !lastData.equipe || !lastData.equipe[personIdx]) return;

        var membro = lastData.equipe[personIdx];
        var bucket = (membro[rowKey] || {})[bucketKey] || {};
        var cards  = bucket.cards || [];

        document.getElementById('mon-drill-title').textContent =
            membro.nome + ' — ' + ROW_LABELS[rowKey] + ' — ' + BUCKET_LABELS[bucketKey];
        document.getElementById('mon-drill-subtitle').textContent =
            cards.length + ' chamado' + (cards.length !== 1 ? 's' : '');

        var listEl = document.getElementById('mon-drill-list');
        listEl.innerHTML = cards.length
            ? cards.map(function (c) { return drillItemHtml(c, rowKey); }).join('')
            : '<div style="color:rgba(255,255,255,.35);font-size:.82rem;padding:.5rem 0">Nenhum chamado encontrado.</div>';

        document.getElementById('mon-drill-overlay').style.display = 'flex';
    };

    window.monFecharDrill = function () {
        document.getElementById('mon-drill-overlay').style.display = 'none';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') monFecharDrill();
    });

    // ── Painel Tarefas (Bitrix24 Tasks — fonte separada do SPA 1054) ──────────────
    var lastTarefas     = null;
    var tskSelectedUids = null; // Set — inicializado no primeiro carregamento (todos selecionados)

    function fmtDataHora(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }

    function primeiroNome(nome) {
        return (nome || '').split(' ')[0];
    }

    function tskBadgeHtml(b) {
        var papeis = (b.papeis || []).join(', ');
        return '<span class="tsk-badge ' + (b.intensidade || 'forte') + '">'
            + escHtml(primeiroNome(b.nome)) + ' · ' + escHtml(papeis) + '</span>';
    }

    function tskChatMsgHtml(c) {
        return '<div class="tsk-chat-msg">'
            + '<div class="tsk-chat-msg-head">'
                + '<span class="tsk-chat-msg-autor">' + escHtml(c.autor) + '</span>'
                + '<span class="tsk-chat-msg-data">' + escHtml(fmtDataHora(c.data)) + '</span>'
            + '</div>'
            + '<div class="tsk-chat-msg-texto">' + escHtml(c.mensagem) + '</div>'
            + '</div>';
    }

    function tskRowHtml(t) {
        var url = (lastTarefas && lastTarefas.bitrixBase && t.responsibleId)
            ? lastTarefas.bitrixBase + '/company/personal/user/' + t.responsibleId + '/tasks/task/view/' + t.id + '/'
            : '';
        var idHtml = url
            ? '<a class="tsk-row-id" href="' + escHtml(url) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()">#' + t.id + '</a>'
            : '<span class="tsk-row-id">#' + t.id + '</span>';

        // Cor de alerta já comunica atraso — sem rótulo de texto redundante.
        var deadlineHtml = t.deadline
            ? '<span class="tsk-deadline' + (t.atrasada ? ' atrasada' : '') + '">' + escHtml(fmtDataHora(t.deadline)) + '</span>'
            : '<span class="tsk-deadline">Sem prazo</span>';

        var chatIcon = t.temChat
            ? '<i class="fas fa-comment-dots tsk-chat-icon" title="Ver mensagens" onclick="event.stopPropagation();tskAbrirChat(' + t.id + ')"></i>'
            : '';
        var badges = (t.badges || []).map(tskBadgeHtml).join('');

        var descricaoHtml = t.descricao
            ? '<div class="tsk-detail-label">Descrição</div><div class="tsk-detail-text">' + escHtml(t.descricao) + '</div>'
            : '<div class="tsk-detail-text" style="color:rgba(255,255,255,.35)">Sem descrição.</div>';

        var prazoDetalheHtml = t.deadline ? escHtml(fmtDataHora(t.deadline)) : 'Sem prazo definido';

        return '<div class="tsk-row">'
            + '<div class="tsk-row-main" onclick="tskToggle(' + t.id + ')">'
                + '<button class="tsk-chevron-btn" id="tsk-btn-' + t.id + '"><i class="fas fa-chevron-right" style="font-size:.7rem"></i></button>'
                + idHtml
                + '<span class="tsk-row-title">' + escHtml(t.titulo) + '</span>'
                + '<span class="tsk-badges">' + badges + '</span>'
                + deadlineHtml
                + chatIcon
            + '</div>'
            + '<div class="tsk-row-detail" id="tsk-detail-' + t.id + '">'
                + '<div class="tsk-detail-inner">'
                    + descricaoHtml
                    + '<div class="tsk-detail-label">Prazo</div><div class="tsk-detail-text">' + prazoDetalheHtml + '</div>'
                + '</div>'
            + '</div>'
            + '</div>';
    }

    // ── Modal de chat — componente compartilhado entre Tarefas e Chamados abertos ────
    function abrirChatModal(titulo, comentarios, chatErro) {
        document.getElementById('tsk-chat-title').textContent = titulo;
        var listEl = document.getElementById('tsk-chat-list');

        if (chatErro) {
            listEl.innerHTML = '<div style="color:rgba(255,255,255,.35);font-size:.82rem;padding:.5rem 0">Sem permissão para acessar este chat.</div>';
        } else if (comentarios && comentarios.length) {
            listEl.innerHTML = comentarios.map(tskChatMsgHtml).join('');
        } else {
            listEl.innerHTML = '<div style="color:rgba(255,255,255,.35);font-size:.82rem;padding:.5rem 0">Nenhuma mensagem.</div>';
        }

        document.getElementById('tsk-chat-overlay').style.display = 'flex';
    }

    window.tskAbrirChat = function (id) {
        if (!lastTarefas || !lastTarefas.tarefas) return;
        var tarefa = lastTarefas.tarefas.filter(function (t) { return t.id === id; })[0];
        if (!tarefa) return;
        abrirChatModal(tarefa.titulo, tarefa.comentarios, null); // chat de tarefa não tem chatErro — sempre acessível
    };

    window.chaAbrirChat = function (id) {
        if (!lastChamados || !lastChamados.chamados) return;
        var chamado = lastChamados.chamados.filter(function (c) { return c.id === id; })[0];
        if (!chamado) return;
        abrirChatModal(chamado.titulo, chamado.comentarios, chamado.chatErro);
    };

    window.tskFecharChat = function () {
        document.getElementById('tsk-chat-overlay').style.display = 'none';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') tskFecharChat();
    });

    // ── Filtro por pessoa (pills multi-select, 1 a 4 ativos) ──────────────────────
    function renderFiltroPessoas(equipe) {
        var el = document.getElementById('tsk-filter-row');
        if (!el) return;
        if (!equipe || !equipe.length) { el.innerHTML = ''; return; }

        if (tskSelectedUids === null) {
            tskSelectedUids = new Set(equipe.map(function (p) { return p.bitrixUserId; }));
        }

        el.innerHTML = equipe.map(function (p) {
            var ativo = tskSelectedUids.has(p.bitrixUserId);
            return '<span class="tsk-filter-pill' + (ativo ? ' active' : '') + '" onclick="tskToggleFiltro(' + p.bitrixUserId + ')">'
                + escHtml(primeiroNome(p.nome)) + '</span>';
        }).join('');
    }

    window.tskToggleFiltro = function (uid) {
        if (!tskSelectedUids) return;
        if (tskSelectedUids.has(uid)) {
            if (tskSelectedUids.size <= 1) return; // nunca deixa ficar com 0 selecionados
            tskSelectedUids.delete(uid);
        } else {
            tskSelectedUids.add(uid);
        }
        if (lastTarefas) {
            renderFiltroPessoas(lastTarefas.equipe);
            renderTarefas(lastTarefas);
        }
    };

    function tskEnvolveSelecionados(t) {
        return (t.badges || []).some(function (b) { return tskSelectedUids.has(b.bitrixUserId); });
    }

    function renderTarefas(data) {
        var listEl  = document.getElementById('tsk-list');
        var countEl = document.getElementById('tsk-count');

        renderFiltroPessoas(data.equipe);

        if (data.aviso) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            countEl.textContent = '';
            return;
        }

        var todasTarefas = data.tarefas || [];
        var tarefas = (tskSelectedUids && tskSelectedUids.size)
            ? todasTarefas.filter(tskEnvolveSelecionados)
            : todasTarefas;

        countEl.textContent = tarefas.length + ' em aberto';

        if (!tarefas.length) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-check-circle"></i><div>Nenhuma tarefa em aberto.</div></div>';
            return;
        }

        listEl.innerHTML = tarefas.map(tskRowHtml).join('');
    }

    window.tskToggle = function (id) {
        var detail = document.getElementById('tsk-detail-' + id);
        var btn    = document.getElementById('tsk-btn-' + id);
        if (!detail) return;

        var isOpen = detail.classList.contains('open');
        detail.classList.toggle('open', !isOpen);
        if (btn) btn.classList.toggle('open', !isOpen);
    };

    function carregarTarefas() {
        return fetch('/api/monitoramento-tarefas-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('tsk-list').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    return;
                }
                lastTarefas = data;
                renderTarefas(data);
            })
            .catch(function () {
                document.getElementById('tsk-list').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
            });
    }

    // ── Painel Chamados abertos (SPA 1054 / Funil 208 — fila inteira, sem escopo de equipe) ──
    var lastChamados     = null;
    var chaMostrarTodos  = false;

    function iniciais(nome) {
        var partes = (nome || '').trim().split(/\s+/).filter(Boolean);
        if (!partes.length) return '?';
        if (partes.length === 1) return partes[0].substring(0, 2).toUpperCase();
        return (partes[0][0] + partes[partes.length - 1][0]).toUpperCase();
    }

    function fmtIdade(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        var diffMs = Date.now() - d.getTime();
        var dias = Math.floor(diffMs / (1000 * 60 * 60 * 24));
        if (dias >= 1) return 'há ' + dias + 'd';
        var horas = Math.floor(diffMs / (1000 * 60 * 60));
        return horas >= 1 ? ('há ' + horas + 'h') : 'há poucos min';
    }

    function chaAvatarHtml(r) {
        return '<span class="cha-avatar" title="' + escHtml(r.nome) + '">' + escHtml(iniciais(r.nome)) + '</span>';
    }

    function chaRowHtml(c) {
        var url = (lastChamados && lastChamados.bitrixBase)
            ? lastChamados.bitrixBase + '/crm/type/1054/details/' + c.id + '/'
            : '';
        var linkIcon = url
            ? '<a href="' + escHtml(url) + '" target="_blank" rel="noopener" class="cha-link-icon" onclick="event.stopPropagation()" title="Abrir no Bitrix24"><i class="fas fa-external-link-alt"></i></a>'
            : '';
        var chatIcon = c.temChat
            ? '<i class="fas fa-comment-dots cha-chat-icon" title="Ver mensagens" onclick="chaAbrirChat(' + c.id + ')"></i>'
            : '';
        var avatares = (c.responsaveis && c.responsaveis.length)
            ? c.responsaveis.map(chaAvatarHtml).join('')
            : '<span class="cha-sem-resp">Sem responsável</span>';

        return '<div class="cha-row">'
            + '<span class="cha-row-id">#' + c.id + '</span>'
            + '<span class="cha-row-title">' + escHtml(c.titulo) + '</span>'
            + '<span class="cha-badge" style="background:' + c.tipoCor + '22;color:' + c.tipoCor + ';border:1px solid ' + c.tipoCor + '55">' + escHtml(c.tipoLabel) + '</span>'
            + '<span class="cha-etapa">' + escHtml(c.etapaLabel) + '</span>'
            + '<span class="cha-avatares">' + avatares + '</span>'
            + '<span class="cha-idade">' + fmtIdade(c.createdTime) + '</span>'
            + chatIcon
            + linkIcon
            + '</div>';
    }

    function renderChamados(data) {
        var listEl  = document.getElementById('cha-list');
        var countEl = document.getElementById('cha-count');

        if (data.aviso) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            countEl.textContent = '';
            return;
        }

        var todos    = data.chamados || [];
        var visiveis = chaMostrarTodos ? todos : todos.filter(function (c) { return c.tipoPadrao; });

        countEl.textContent = visiveis.length + ' em aberto';

        if (!visiveis.length) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-check-circle"></i><div>Nenhum chamado em aberto.</div></div>';
            return;
        }

        listEl.innerHTML = visiveis.map(chaRowHtml).join('');
    }

    window.chaToggleTipos = function () {
        chaMostrarTodos = !chaMostrarTodos;
        var btn = document.getElementById('cha-toggle-tipos');
        if (btn) btn.classList.toggle('active', chaMostrarTodos);
        if (lastChamados) renderChamados(lastChamados);
    };

    function carregarChamados() {
        return fetch('/api/monitoramento-chamados-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('cha-list').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    return;
                }
                lastChamados = data;
                renderChamados(data);
            })
            .catch(function () {
                document.getElementById('cha-list').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
            });
    }

    // ── Painel Funil (SPA 1054 / Funil 208 — volume de criados/finalizados; sem Tarefas) ──
    function funStatHtml(valor, label) {
        return '<div class="fun-stat"><span class="fun-stat-value">' + (valor != null ? valor : '—') + '</span><span class="fun-stat-label">' + escHtml(label) + '</span></div>';
    }

    function renderFunil(data) {
        var criadosBody     = document.getElementById('fun-criados-body');
        var finalizadosBody = document.getElementById('fun-finalizados-body');
        if (!criadosBody || !finalizadosBody) return;

        if (data.aviso) {
            var avisoHtml = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            criadosBody.innerHTML     = avisoHtml;
            finalizadosBody.innerHTML = avisoHtml;
            return;
        }

        var criados     = data.chamadosCriados     || {};
        var finalizados = data.chamadosFinalizados || {};

        criadosBody.innerHTML =
            funStatHtml(criados.semana, 'Nesta semana') + funStatHtml(criados.periodo, 'No período');
        finalizadosBody.innerHTML =
            funStatHtml(finalizados.semana, 'Nesta semana') + funStatHtml(finalizados.periodo, 'No período');
    }

    function carregarFunil() {
        return fetch('/api/monitoramento-funil-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    var erroHtml = '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    document.getElementById('fun-criados-body').innerHTML     = erroHtml;
                    document.getElementById('fun-finalizados-body').innerHTML = erroHtml;
                    return;
                }
                renderFunil(data);
            })
            .catch(function () {
                var erroHtml = '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
                document.getElementById('fun-criados-body').innerHTML     = erroHtml;
                document.getElementById('fun-finalizados-body').innerHTML = erroHtml;
            });
    }

    // ── Carregamento geral (Equipe + Chamados abertos + Tarefas + Funil) ──────────
    function carregar() {
        var icon = document.getElementById('mon-refresh-icon');
        if (icon) icon.classList.add('fa-spin');

        var pEquipe = fetch('/api/monitoramento-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('mon-equipe-grid').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    return;
                }
                lastData = data;
                render(data);
            })
            .catch(function () {
                document.getElementById('mon-equipe-grid').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
            });

        Promise.all([pEquipe, carregarChamados(), carregarTarefas(), carregarFunil()]).then(function () {
            if (icon) icon.classList.remove('fa-spin');
            var upd = document.getElementById('mon-updated');
            if (upd) upd.textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR');
        });
    }

    window.monAtualizar = carregar;

    carregar();
    setInterval(carregar, AUTO_REFRESH_MS);

})();
</script>
