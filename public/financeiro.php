<?php
if (!defined('SYSTEM_ACCESS') && !isset($user_data)) {
    header('Location: /public/login.php'); exit;
}
?>

<style>
/* ===== FINANCEIRO ===== */
.fin-periodo-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: rgba(255,255,255,0.07);
    border: 1.5px solid rgba(13,194,255,0.20);
    border-radius: 12px;
    padding: .9rem 1.25rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
    gap: .75rem;
}
.fin-periodo-info {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    flex-wrap: wrap;
}
.fin-periodo-label {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #0DC2FF;
}
.fin-periodo-val {
    font-size: .875rem;
    font-weight: 600;
    color: #fff;
}
.fin-periodo-range {
    font-size: .78rem;
    color: rgba(255,255,255,.45);
}
.fin-periodo-select {
    background: rgba(255,255,255,0.07);
    border: 1.5px solid rgba(13,194,255,0.20);
    border-radius: 8px;
    color: #fff;
    font-size: .82rem;
    font-weight: 600;
    padding: .5rem .75rem;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
}
.fin-periodo-select:focus { outline: none; border-color: rgba(13,194,255,0.5); }
.fin-periodo-select option { background: #0d1e2d; color: #fff; }
.fin-sync-btn {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: #0DC2FF;
    color: #061920;
    border: none;
    border-radius: 8px;
    padding: .5rem 1rem;
    font-size: .82rem;
    font-weight: 700;
    cursor: pointer;
    transition: background .15s, opacity .15s;
    white-space: nowrap;
    flex-shrink: 0;
}
.fin-sync-btn:hover    { background: #08aadd; }
.fin-sync-btn:disabled { opacity: .55; cursor: not-allowed; }
.fin-sync-feedback {
    font-size: .78rem;
    font-weight: 500;
    margin-top: .35rem;
}
.fin-sync-feedback.ok   { color: #26FF93; }
.fin-sync-feedback.erro { color: #fc8181; }

/* ── KPI cards ── */
.fin-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.25rem;
}
@media (max-width: 900px) { .fin-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 480px) { .fin-kpi-grid { grid-template-columns: 1fr 1fr; } }
.fin-kpi-card {
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    padding: 1rem 1.25rem 1rem;
    position: relative;
    overflow: hidden;
}
.fin-kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 12px 12px 0 0;
}
.fin-kpi-card.kpi-fatura::before  { background: linear-gradient(90deg,#f6ad55,#f6e05e); }
.fin-kpi-card.kpi-suporte::before { background: linear-gradient(90deg,#0DC2FF,#0080aa); }
.fin-kpi-card.kpi-dev::before     { background: linear-gradient(90deg,#b794f4,#805ad5); }
.fin-kpi-card.kpi-infra::before   { background: linear-gradient(90deg,#26FF93,#059669); }
.fin-kpi-label {
    font-size: .67rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: rgba(255,255,255,.4);
    margin-bottom: .4rem;
    margin-top: .2rem;
}
.fin-kpi-value {
    font-size: 1.15rem;
    font-weight: 700;
    color: #fff;
    font-family: 'Inter', monospace;
    letter-spacing: -.01em;
}

/* ── Chart ── */
.fin-chart-panel {
    background: rgba(255,255,255,0.04);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1.25rem;
}
.fin-chart-title {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: rgba(255,255,255,.4);
    margin-bottom: 1rem;
}
.fin-chart-title i { color: #0DC2FF; margin-right: .4rem; }

/* ── Table ── */
.fin-table-panel {
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    overflow: hidden;
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
}

/* Seções fixas acima da tabela não devem encolher */
.fin-periodo-bar,
.fin-kpi-grid,
.fin-chart-panel {
    flex-shrink: 0;
}
.fin-table-header {
    padding: .75rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.fin-table-title {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: rgba(255,255,255,.5);
}
#fin-total { font-size: .75rem; color: rgba(255,255,255,.35); }
.fin-table-scroll {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    overflow-x: auto;
}
.fin-table-scroll::-webkit-scrollbar { width: 5px; }
.fin-table-scroll::-webkit-scrollbar-track { background: rgba(255,255,255,0.03); }
.fin-table-scroll::-webkit-scrollbar-thumb { background: rgba(13,194,255,0.25); border-radius: 3px; }
.fin-table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: #0d1e2d;
}
.fin-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .85rem;
}
.fin-table th {
    padding: .65rem 1rem;
    text-align: left;
    font-size: .67rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: rgba(255,255,255,.4);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    white-space: nowrap;
}
.fin-table td {
    padding: .7rem 1rem;
    color: rgba(255,255,255,.85);
    border-bottom: 1px solid rgba(255,255,255,0.05);
    vertical-align: middle;
}
.fin-table tbody tr.fin-main-row:last-of-type td { border-bottom: none; }
.fin-table tbody tr.fin-main-row:hover > td { background: rgba(255,255,255,0.03); }
.fin-table tbody tr.fin-main-row.open > td { background: rgba(13,194,255,0.09); border-bottom-color: rgba(13,194,255,0.12) !important; }
.fin-table td.empresa { font-weight: 600; color: #fff; }
.fin-table tbody tr.fin-main-row.open td.empresa { color: #0DC2FF; }
.fin-table td.valor {
    font-family: 'Inter', monospace;
    text-align: right;
    font-size: .8rem;
}
.fin-table td.valor-total {
    font-family: 'Inter', monospace;
    text-align: right;
    font-size: .85rem;
    font-weight: 700;
    color: #fff;
}
.fin-table td.chevron-cell {
    width: 36px;
    padding: .5rem .5rem .5rem 1rem;
}

/* Chevron button */
.fin-chevron-btn {
    background: none;
    border: none;
    color: rgba(255,255,255,.28);
    cursor: pointer;
    padding: .3rem;
    border-radius: 6px;
    transition: color .15s, transform .2s;
    line-height: 1;
    display: flex;
    align-items: center;
}
.fin-chevron-btn:hover { color: rgba(255,255,255,.7); }
.fin-chevron-btn.open  { color: #0DC2FF; transform: rotate(90deg); }

/* Detail row */
.fin-detail-row > td {
    padding: 0 !important;
    background: transparent;
    border-bottom: 1px solid rgba(255,255,255,0.05) !important;
}
.fin-detail-inner {
    padding: 1.25rem 1.5rem 1.25rem 3rem;
    background: rgba(13,194,255,0.025);
    border-top: 1px solid rgba(13,194,255,0.10);
    animation: finDetailIn .15s ease;
    max-height: 260px;
    overflow-y: auto;
}
@keyframes finDetailIn {
    from { opacity:0; transform:translateY(-4px); }
    to   { opacity:1; transform:translateY(0); }
}
.fin-detail-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2rem;
}
@media (max-width: 768px) { .fin-detail-grid { grid-template-columns: 1fr; } }
.fin-detail-col-title {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: #0DC2FF;
    margin-bottom: .65rem;
    padding-bottom: .45rem;
    border-bottom: 1px solid rgba(13,194,255,0.18);
}
.fin-detail-item {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: .28rem 0;
    font-size: .79rem;
    gap: .5rem;
}
.fin-detail-item-label { color: rgba(255,255,255,.5); flex-shrink: 0; }
.fin-detail-item-value {
    color: #fff;
    font-weight: 600;
    font-family: 'Inter', monospace;
    font-size: .77rem;
    text-align: right;
}
.fin-detail-link {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    font-size: .78rem;
    color: #0DC2FF;
    text-decoration: none;
    margin-top: .85rem;
    opacity: .85;
    transition: opacity .15s;
}
.fin-detail-link:hover { opacity: 1; }

/* Stage badge */
.fin-stage-badge {
    display: inline-block;
    font-size: .7rem;
    font-weight: 700;
    padding: .2rem .55rem;
    border-radius: 20px;
    white-space: nowrap;
}

/* Empty / loading */
.fin-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: rgba(255,255,255,.3);
}
.fin-empty i { font-size: 2rem; margin-bottom: .75rem; display: block; color: rgba(13,194,255,.4); }
.fin-empty-msg { font-size: .875rem; }
</style>

<!-- Barra de período + botão sincronizar -->
<div class="fin-periodo-bar">
    <div class="fin-periodo-info">
        <div>
            <div class="fin-periodo-label">Período de faturamento</div>
            <div class="fin-periodo-val" id="fin-periodo-ref">—</div>
        </div>
        <div class="fin-periodo-range" id="fin-periodo-range">Carregando…</div>
    </div>
    <select class="fin-periodo-select" id="fin-periodo-select" onchange="finTrocarPeriodo(this.value)">
        <option value="">Carregando…</option>
    </select>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.3rem">
        <?php if (isset($user_data['perfil']) && $user_data['perfil'] === 'admin_interno'): ?>
        <button class="fin-sync-btn" id="finSyncBtn" onclick="finSincronizar()">
            <i class="fas fa-sync-alt" id="finSyncIcon"></i> Sincronizar
        </button>
        <div class="fin-sync-feedback" id="finSyncFeedback"></div>
        <?php endif; ?>
    </div>
</div>

<!-- KPI cards -->
<div class="fin-kpi-grid" id="fin-kpi-grid" style="display:none">
    <div class="fin-kpi-card kpi-fatura">
        <div class="fin-kpi-label">Total da Fatura</div>
        <div class="fin-kpi-value" id="kpi-fatura">—</div>
    </div>
    <div class="fin-kpi-card kpi-suporte">
        <div class="fin-kpi-label">Total Suporte</div>
        <div class="fin-kpi-value" id="kpi-suporte">—</div>
    </div>
    <div class="fin-kpi-card kpi-dev">
        <div class="fin-kpi-label">Total Dev</div>
        <div class="fin-kpi-value" id="kpi-dev">—</div>
    </div>
    <div class="fin-kpi-card kpi-infra">
        <div class="fin-kpi-label">Total Infra</div>
        <div class="fin-kpi-value" id="kpi-infra">—</div>
    </div>
</div>

<!-- Gráfico de histórico -->
<div class="fin-chart-panel" id="fin-chart-panel" style="display:none">
    <div class="fin-chart-title">
        <i class="fas fa-chart-line"></i> Total da Fatura — últimos 6 meses
    </div>
    <canvas id="fin-chart" style="max-height:130px"></canvas>
</div>

<!-- Tabela de cards financeiros -->
<div class="fin-table-panel">
    <div class="fin-table-header">
        <span class="fin-table-title"><i class="fas fa-file-invoice-dollar" style="color:#0DC2FF;margin-right:.4rem"></i> Cards Financeiros — <span id="fin-table-periodo-label">período atual</span></span>
        <span id="fin-total"></span>
    </div>
    <div class="fin-table-scroll">
        <table class="fin-table">
            <thead>
                <tr>
                    <th style="width:36px"></th>
                    <th>Empresa</th>
                    <th style="text-align:right">Valor Suporte</th>
                    <th style="text-align:right">Valor Dev</th>
                    <th style="text-align:right">Valor Infra</th>
                    <th style="text-align:right">Total da Fatura</th>
                </tr>
            </thead>
            <tbody id="fin-tbody">
                <tr><td colspan="6" class="fin-empty">
                    <i class="fas fa-spinner fa-spin"></i>
                    <div class="fin-empty-msg">Carregando…</div>
                </td></tr>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
(function () {

    var bitrixBase    = '';
    var openDetailId  = null;
    var chartInstance = null;

    var STAGE_MAP = {
        'DT1054_210:NEW':         { label: 'Dentro do Ciclo',       cor: '#a0aec0', bg: 'rgba(160,174,192,.12)' },
        'DT1054_210:UC_WY3NCL':   { label: 'Conferência',            cor: '#f6ad55', bg: 'rgba(246,173,85,.12)'  },
        'DT1054_210:UC_AOW0O3':   { label: 'Conferência',            cor: '#f6ad55', bg: 'rgba(246,173,85,.12)'  },
        'DT1054_210:UC_8D832V':   { label: 'Validação c/ Cliente',   cor: '#0DC2FF', bg: 'rgba(13,194,255,.10)'  },
        'DT1054_210:PREPARATION': { label: 'Faturado',               cor: '#b794f4', bg: 'rgba(183,148,244,.12)' },
        'DT1054_210:CLIENT':      { label: 'Faturado',               cor: '#b794f4', bg: 'rgba(183,148,244,.12)' },
        'DT1054_210:UC_1E2K98':   { label: 'Faturado',               cor: '#b794f4', bg: 'rgba(183,148,244,.12)' },
        'DT1054_210:FAIL':        { label: 'Faturado',               cor: '#b794f4', bg: 'rgba(183,148,244,.12)' },
        'DT1054_210:SUCCESS':     { label: 'Pago',                   cor: '#26FF93', bg: 'rgba(38,255,147,.12)'  },
    };

    // ── Formatação ─────────────────────────────────────────────────────────────
    var brlFmt = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    function fmtBRL(v)   { return brlFmt.format(v || 0); }
    function fmtHoras(m) {
        var h = (m || 0) / 60;
        return h.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'h';
    }
    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Stage badge ────────────────────────────────────────────────────────────
    function stageBadge(stageId) {
        var e = STAGE_MAP[stageId] || { label: stageId || '—', cor: '#718096', bg: 'rgba(113,128,150,.10)' };
        return '<span class="fin-stage-badge" style="color:' + e.cor + ';background:' + e.bg + '">'
            + escHtml(e.label) + '</span>';
    }

    // ── KPI cards ──────────────────────────────────────────────────────────────
    function renderKpi(kpi) {
        document.getElementById('kpi-fatura').textContent   = fmtBRL(kpi.totalFatura);
        document.getElementById('kpi-suporte').textContent  = fmtBRL(kpi.totalSuporte);
        document.getElementById('kpi-dev').textContent      = fmtBRL(kpi.totalDev);
        document.getElementById('kpi-infra').textContent    = fmtBRL(kpi.totalInfra);
        document.getElementById('fin-kpi-grid').style.display = '';
    }

    // ── Detail row ─────────────────────────────────────────────────────────────
    function buildInfraLines(c) {
        var items = [
            ['Servidor RDP',           c.srvRdp],
            ['Servidor VM',            c.srvVm],
            ['Servidor de Dados',      c.srvDados],
            ['Servidor Sistema Dom.',  c.srvSis],
            ['Hospedagem de Dom.',     c.hospedagem],
            ['Gestão E-mail e Sites',  c.gestao],
            ['API Validador CNPJ',     c.apiCnpj],
            ['API ClickSign',          c.apiClick],
            ['API Receita Federal',    c.apiRfb],
            ['API WhatsApp',           c.apiZap],
        ];
        var html = '';
        items.forEach(function (it) {
            if ((it[1] || 0) > 0) {
                html += detailItem(it[0], fmtBRL(it[1]));
            }
        });
        if ((c.qtdRdp || 0) > 0) {
            html += detailItem('Qtd. Usuários RDP', c.qtdRdp);
        }
        if (c.dominios) {
            html += detailItem('Domínios', escHtml(c.dominios));
        }
        return html || '<div style="color:rgba(255,255,255,.3);font-size:.78rem">Nenhum produto de infra</div>';
    }

    function detailItem(label, value) {
        return '<div class="fin-detail-item">'
            + '<span class="fin-detail-item-label">' + escHtml(String(label)) + '</span>'
            + '<span class="fin-detail-item-value">' + value + '</span>'
            + '</div>';
    }

    function buildDetailHtml(c) {
        var link = bitrixBase
            ? '<a class="fin-detail-link" href="' + escHtml(bitrixBase) + '/crm/type/1054/details/' + c.id + '/" target="_blank" rel="noopener">'
              + '<i class="fas fa-external-link-alt"></i> Abrir no Bitrix</a>'
            : '';

        return '<td colspan="6"><div class="fin-detail-inner"><div class="fin-detail-grid">'
            // Suporte
            + '<div>'
                + '<div class="fin-detail-col-title">Suporte</div>'
                + detailItem('Horas contrato',   fmtHoras(c.supHCont))
                + detailItem('Horas gastas',      fmtHoras(c.supHGasto))
                + detailItem('Horas extra',       fmtHoras(c.supHExtra))
                + detailItem('Valor/hora',        fmtBRL(c.supVH))
                + detailItem('Valor contratado',  fmtBRL(c.supVCont))
                + detailItem('Valor extra',       fmtBRL(c.supVExtra))
            + '</div>'
            // Dev
            + '<div>'
                + '<div class="fin-detail-col-title">Desenvolvimento</div>'
                + detailItem('Horas contrato',   fmtHoras(c.devHCont))
                + detailItem('Horas gastas',      fmtHoras(c.devHGasto))
                + detailItem('Horas extra',       fmtHoras(c.devHExtra))
                + detailItem('Valor/hora',        fmtBRL(c.devVH))
                + detailItem('Valor contratado',  fmtBRL(c.devVCont))
                + detailItem('Valor extra',       fmtBRL(c.devVExtra))
            + '</div>'
            // Infra
            + '<div>'
                + '<div class="fin-detail-col-title">Infra</div>'
                + buildInfraLines(c)
                + link
            + '</div>'
            + '</div></div></td>';
    }

    // ── Toggle detail ──────────────────────────────────────────────────────────
    window.toggleDetail = function (id) {
        var detailRow = document.getElementById('fin-detail-' + id);
        var btn       = document.getElementById('fin-btn-'    + id);
        if (!detailRow) return;

        // Fechar o que estava aberto
        if (openDetailId !== null && openDetailId !== id) {
            var prevRow     = document.getElementById('fin-detail-' + openDetailId);
            var prevBtn     = document.getElementById('fin-btn-'    + openDetailId);
            var prevMainRow = document.getElementById('fin-row-'    + openDetailId);
            if (prevRow)     prevRow.style.display = 'none';
            if (prevBtn)     prevBtn.classList.remove('open');
            if (prevMainRow) prevMainRow.classList.remove('open');
            openDetailId = null;
        }

        var mainRow = document.getElementById('fin-row-' + id);
        var isOpen  = detailRow.style.display !== 'none';
        if (isOpen) {
            detailRow.style.display = 'none';
            if (btn)     btn.classList.remove('open');
            if (mainRow) mainRow.classList.remove('open');
            openDetailId = null;
        } else {
            detailRow.style.display = '';
            if (btn)     btn.classList.add('open');
            if (mainRow) mainRow.classList.add('open');
            openDetailId = id;
            scrollDetailIntoView(detailRow);
        }
    };

    function scrollDetailIntoView(row) {
        setTimeout(function () {
            var scrollEl = document.querySelector('.fin-table-scroll');
            if (!scrollEl) return;

            var containerRect = scrollEl.getBoundingClientRect();
            var rowRect       = row.getBoundingClientRect();

            if (rowRect.bottom > containerRect.bottom - 8) {
                var delta = rowRect.bottom - (containerRect.bottom - 8);
                scrollEl.scrollBy({ top: delta, behavior: 'smooth' });
            }
        }, 160);
    }

    // ── Render tabela ──────────────────────────────────────────────────────────
    function renderTabela(cards) {
        var tbody = document.getElementById('fin-tbody');
        var total = document.getElementById('fin-total');

        if (!cards || !cards.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="fin-empty">'
                + '<i class="fas fa-inbox"></i>'
                + '<div class="fin-empty-msg">Nenhum card financeiro encontrado para este período.</div>'
                + '</td></tr>';
            if (total) total.textContent = '';
            return;
        }

        if (total) total.textContent = cards.length + ' empresa' + (cards.length !== 1 ? 's' : '');

        var html = '';
        cards.forEach(function (c) {
            // Linha principal
            html += '<tr class="fin-main-row" id="fin-row-' + c.id + '" style="cursor:pointer" onclick="toggleDetail(' + c.id + ')">'
                + '<td class="chevron-cell">'
                    + '<button class="fin-chevron-btn" id="fin-btn-' + c.id + '" onclick="event.stopPropagation();toggleDetail(' + c.id + ')">'
                    + '<i class="fas fa-chevron-right" style="font-size:.72rem"></i></button>'
                + '</td>'
                + '<td class="empresa">' + escHtml(c.empresa) + '</td>'
                + '<td class="valor">'  + fmtBRL(c.valSuporte)  + '</td>'
                + '<td class="valor">'  + fmtBRL(c.valDev)      + '</td>'
                + '<td class="valor">'  + fmtBRL(c.valInfra)    + '</td>'
                + '<td class="valor-total">' + fmtBRL(c.opportunity) + '</td>'
                + '</tr>';
            // Linha de detalhe (oculta)
            html += '<tr class="fin-detail-row" id="fin-detail-' + c.id + '" style="display:none">'
                + buildDetailHtml(c)
                + '</tr>';
        });

        tbody.innerHTML = html;
    }

    // ── Gráfico ────────────────────────────────────────────────────────────────
    function initChart(history) {
        var panel = document.getElementById('fin-chart-panel');
        var ctx   = document.getElementById('fin-chart').getContext('2d');

        if (chartInstance) { chartInstance.destroy(); chartInstance = null; }

        chartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels:   history.map(function(h) { return h.mes; }),
                datasets: [{
                    label:           'Total da Fatura',
                    data:            history.map(function(h) { return h.total; }),
                    borderColor:     '#0DC2FF',
                    backgroundColor: 'rgba(13,194,255,0.08)',
                    borderWidth:     2,
                    fill:            true,
                    tension:         0.4,
                    pointBackgroundColor: '#0DC2FF',
                    pointRadius:     4,
                    pointHoverRadius: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return fmtBRL(ctx.raw); }
                        },
                        backgroundColor: 'rgba(6,25,32,0.9)',
                        borderColor:     'rgba(13,194,255,0.3)',
                        borderWidth:     1,
                        titleColor:      'rgba(255,255,255,0.8)',
                        bodyColor:       '#fff',
                    },
                },
                scales: {
                    x: {
                        grid:  { color: 'rgba(255,255,255,0.06)' },
                        ticks: { color: 'rgba(255,255,255,0.5)', font: { size: 11 } },
                    },
                    y: {
                        grid:  { color: 'rgba(255,255,255,0.06)' },
                        ticks: {
                            color: 'rgba(255,255,255,0.5)',
                            font:  { size: 11 },
                            stepSize: 5000,
                            callback: function(v) { return fmtBRL(v); },
                        },
                        beginAtZero: true,
                    },
                },
            },
        });

        panel.style.display = '';
    }

    function carregarHistorico() {
        fetch('/api/financeiro-cards.php?history=1', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.history && data.history.length) initChart(data.history);
            })
            .catch(function(e) { console.error('histórico:', e); });
    }

    // ── Períodos (seletor) ─────────────────────────────────────────────────────
    function popularSelectPeriodos(periodos) {
        var sel = document.getElementById('fin-periodo-select');
        if (!sel) return;
        sel.innerHTML = periodos.map(function (p) {
            var label = (p.referencia || 'Período atual') + (p.atual ? ' (atual)' : '');
            return '<option value="' + escHtml(p.referencia || '') + '"' + (p.atual ? ' selected' : '') + '>'
                + escHtml(label) + '</option>';
        }).join('');
    }

    function carregarPeriodos() {
        fetch('/api/financeiro-cards.php?periodos=1', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var periodos = (data && data.periodos) || [];
                if (!periodos.length) throw new Error('lista de períodos vazia');
                popularSelectPeriodos(periodos);
            })
            .catch(function(e) {
                console.error('periodos:', e);
                popularSelectPeriodos([{ referencia: '', atual: true }]);
            });
    }

    // ── Trocar período selecionado ─────────────────────────────────────────────
    window.finTrocarPeriodo = function (ref) {
        openDetailId = null;
        carregarCards(ref || null);
    };

    // ── Carregar cards ─────────────────────────────────────────────────────────
    function carregarCards(ref) {
        var url = '/api/financeiro-cards.php' + (ref ? ('?ref=' + encodeURIComponent(ref)) : '');
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.erro) {
                    document.getElementById('fin-tbody').innerHTML =
                        '<tr><td colspan="6" class="fin-empty">'
                        + '<i class="fas fa-exclamation-circle" style="color:#fc8181"></i>'
                        + '<div class="fin-empty-msg" style="color:#fc8181">' + escHtml(data.erro) + '</div>'
                        + '</td></tr>';
                    return;
                }

                var p = data.periodo || {};
                var refEl   = document.getElementById('fin-periodo-ref');
                var rangeEl = document.getElementById('fin-periodo-range');
                if (refEl)   refEl.textContent  = p.referencia || '—';
                if (rangeEl) rangeEl.textContent = (p.inicio && p.fim) ? p.inicio + ' → ' + p.fim : '';

                var tituloEl = document.getElementById('fin-table-periodo-label');
                if (tituloEl) tituloEl.textContent = p.referencia || 'período atual';

                bitrixBase = data.bitrixBase || '';

                if (data.aviso) {
                    document.getElementById('fin-tbody').innerHTML =
                        '<tr><td colspan="6" class="fin-empty">'
                        + '<i class="fas fa-plug" style="color:rgba(255,255,255,.3)"></i>'
                        + '<div class="fin-empty-msg">' + escHtml(data.aviso) + '</div>'
                        + '</td></tr>';
                    return;
                }

                if (data.kpi) renderKpi(data.kpi);
                renderTabela(data.cards || []);
            })
            .catch(function(e) { console.error(e); });
    }

    // ── Sincronizar ────────────────────────────────────────────────────────────
    window.finSincronizar = function () {
        var btn  = document.getElementById('finSyncBtn');
        var icon = document.getElementById('finSyncIcon');
        var fb   = document.getElementById('finSyncFeedback');

        if (btn)  btn.disabled = true;
        if (icon) icon.classList.add('fa-spin');
        if (fb)   { fb.textContent = ''; fb.className = 'fin-sync-feedback'; }

        var sel        = document.getElementById('fin-periodo-select');
        var refSelect  = sel ? sel.value : '';
        var body       = {};
        if (/^\d{2}\/\d{4}$/.test(refSelect)) {
            var partes = refSelect.split('/');
            body.period = partes[1] + '-' + partes[0]; // YYYY-MM
        }

        fetch('/api/financeiro-sync.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify(body),
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (btn)  btn.disabled = false;
            if (icon) icon.classList.remove('fa-spin');

            if (data.erro) {
                if (fb) { fb.textContent = data.erro; fb.className = 'fin-sync-feedback erro'; }
                return;
            }

            var dem = data.demandas  || {};
            var inf = data.infra     || {};
            var fin = data.financeiro || {};
            var erros = (dem.erros || 0) + (inf.errors || 0) + (fin.errors || 0);

            var msg = 'Demandas: ' + (dem.atualizados || 0) + ' emp., ' + (dem.demandas_total || 0) + ' dem.'
                    + ' · Infra: ' + (inf.created || 0) + ' criados'
                    + ' · Fin: ' + (fin.updated || 0) + ' atual., ' + (fin.created || 0) + ' criados';
            if (erros > 0) msg += ' (' + erros + ' erro(s))';

            if (fb) { fb.textContent = msg; fb.className = 'fin-sync-feedback ' + (erros > 0 ? 'erro' : 'ok'); }

            openDetailId = null;
            carregarCards(refSelect || null);
            carregarHistorico();
        })
        .catch(function() {
            if (btn)  btn.disabled = false;
            if (icon) icon.classList.remove('fa-spin');
            if (fb)   { fb.textContent = 'Erro de comunicação.'; fb.className = 'fin-sync-feedback erro'; }
        });
    };

    // ── Init ───────────────────────────────────────────────────────────────────
    carregarPeriodos();
    carregarCards();
    carregarHistorico();

})();
</script>
