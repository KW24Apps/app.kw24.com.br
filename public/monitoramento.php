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
/* ===== MONITORAMENTO KW24 — Equipe ===== */
.mon-updated {
    font-size: .72rem;
    color: rgba(255,255,255,.35);
}
.mon-equipe-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}
@media (max-width: 900px) { .mon-equipe-grid { grid-template-columns: 1fr; } }

.mon-membro-card {
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    padding: 1.25rem 1.4rem;
}
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
    height: 34px;
    border-radius: 8px;
    overflow: hidden;
    background: rgba(255,255,255,.04);
}
.mon-seg {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .72rem;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0 .5rem;
    cursor: pointer;
    transition: flex-grow .3s ease, filter .15s ease;
}
.mon-seg:hover { filter: brightness(1.12); }
.mon-seg.suporte { background: linear-gradient(90deg,#0DC2FF,#0080aa); color: #061920; }
.mon-seg.dev      { background: linear-gradient(90deg,#b794f4,#805ad5); color: #fff; }

.mon-empty {
    grid-column: 1 / -1;
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

<div class="mon-equipe-grid" id="mon-equipe-grid">
    <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
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

<script>
(function () {

    var AUTO_REFRESH_MS = 30 * 60 * 1000; // 30 minutos
    var MIN_PCT = 8; // largura mínima visível de cada segmento, em %
    var lastData = null;

    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Proporção entre dois valores, com piso mínimo para nunca omitir um segmento.
    function calcPct(a, b) {
        var total = a + b;
        var pctA  = total > 0 ? Math.round((a / total) * 100) : 50;
        var pctB  = 100 - pctA;
        if (pctA < MIN_PCT) { pctA = MIN_PCT; pctB = 100 - MIN_PCT; }
        if (pctB < MIN_PCT) { pctB = MIN_PCT; pctA = 100 - MIN_PCT; }
        return [pctA, pctB];
    }

    function barHtml(pctA, pctB, labelA, labelB, personIdx, rowKey) {
        return '<div class="mon-bar">'
            + '<div class="mon-seg suporte" style="flex:' + pctA + ' 1 0" onclick="monAbrirDrill(' + personIdx + ',\'' + rowKey + '\',\'suporte\')">' + escHtml(labelA) + '</div>'
            + '<div class="mon-seg dev" style="flex:' + pctB + ' 1 0" onclick="monAbrirDrill(' + personIdx + ',\'' + rowKey + '\',\'desenvolvimento\')">' + escHtml(labelB) + '</div>'
            + '</div>';
    }

    function membroCardHtml(m, idx) {
        var and    = m.andamento  || {};
        var andSup = (and.suporte && and.suporte.count) || 0;
        var andDev = (and.desenvolvimento && and.desenvolvimento.count) || 0;
        var pctAnd = calcPct(andSup, andDev);

        var fin       = m.finalizado || {};
        var finSupMin = (fin.suporte && fin.suporte.minutos) || 0;
        var finDevMin = (fin.desenvolvimento && fin.desenvolvimento.minutos) || 0;
        var finSupCnt = (fin.suporte && fin.suporte.count) || 0;
        var finDevCnt = (fin.desenvolvimento && fin.desenvolvimento.count) || 0;
        var pctFin    = calcPct(finSupMin, finDevMin);

        return '<div class="mon-membro-card">'
            + '<div class="mon-membro-nome"><i class="fas fa-user-circle"></i>' + escHtml(m.nome) + '</div>'
            + '<div class="mon-row">'
                + '<div class="mon-row-label">Em andamento</div>'
                + barHtml(pctAnd[0], pctAnd[1], 'Suporte · ' + andSup, 'Desenvolvimento · ' + andDev, idx, 'andamento')
            + '</div>'
            + '<div class="mon-row">'
                + '<div class="mon-row-label">Finalizado no ciclo</div>'
                + barHtml(pctFin[0], pctFin[1],
                    'Suporte · ' + finSupCnt + ' · ' + Math.round(finSupMin / 60) + 'h',
                    'Desenvolvimento · ' + finDevCnt + ' · ' + Math.round(finDevMin / 60) + 'h',
                    idx, 'finalizado')
            + '</div>'
            + '</div>';
    }

    function render(data) {
        var grid = document.getElementById('mon-equipe-grid');

        if (data.aviso) {
            grid.innerHTML = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            return;
        }

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

    function carregar() {
        var icon = document.getElementById('mon-refresh-icon');
        if (icon) icon.classList.add('fa-spin');

        fetch('/api/monitoramento-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (icon) icon.classList.remove('fa-spin');

                if (data.erro) {
                    document.getElementById('mon-equipe-grid').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    return;
                }

                lastData = data;
                render(data);

                var upd = document.getElementById('mon-updated');
                if (upd) upd.textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR');
            })
            .catch(function () {
                if (icon) icon.classList.remove('fa-spin');
                document.getElementById('mon-equipe-grid').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
            });
    }

    window.monAtualizar = carregar;

    carregar();
    setInterval(carregar, AUTO_REFRESH_MS);

})();
</script>
