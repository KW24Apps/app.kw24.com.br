<?php
if (!defined('SYSTEM_ACCESS') && !isset($user_data)) {
    header('Location: /public/login.php'); exit;
}
require_once __DIR__ . '/../helpers/Database.php';

try {
    $db = Database::getInstance();
    $totalClientes   = $db->fetchOne("SELECT COUNT(*) AS n FROM clientes")['n'] ?? 0;
    $totalAppsAtivas = $db->fetchOne("SELECT COUNT(*) AS n FROM cliente_aplicacoes WHERE ativo = TRUE")['n'] ?? 0;
    $valorTotal      = $db->fetchOne("SELECT COALESCE(SUM(valor),0) AS v FROM cliente_aplicacoes WHERE ativo = TRUE")['v'] ?? 0;
} catch (Exception $e) {
    $totalClientes = $totalAppsAtivas = 0;
    $valorTotal = 0;
}
$valorFmt = 'R$ ' . number_format((float)$valorTotal, 2, ',', '.');
?>

<div class="page-header" style="margin-bottom:1.5rem">
    <div>
        <h1 class="page-title" style="margin-bottom:.15rem"><i class="fas fa-home"></i> Dashboard</h1>
        <p style="font-size:.82rem;color:#a0aec0;margin:0">Bem-vindo, <?= htmlspecialchars($user_data['nome']) ?></p>
    </div>
</div>

<!-- KPIs gerais -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.75rem">
    <?php
    $kpis = [
        ['fas fa-users',    '#0DC2FF', '#e0f7ff', 'Clientes',       $totalClientes],
        ['fas fa-th',       '#086B8D', '#e6f2f7', 'Apps Ativas',    $totalAppsAtivas],
        ['fas fa-dollar-sign','#26FF93','#e0fff3', 'Valor em Carteira', $valorFmt],
    ];
    foreach ($kpis as [$icon, $cor, $bg, $label, $val]):
    ?>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1rem">
        <div style="width:48px;height:48px;border-radius:10px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="<?= $icon ?>" style="font-size:1.2rem;color:<?= $cor ?>"></i>
        </div>
        <div>
            <div style="font-size:1.5rem;font-weight:800;color:#1a202c;line-height:1"><?= $val ?></div>
            <div style="font-size:.72rem;color:#a0aec0;margin-top:.25rem;text-transform:uppercase;letter-spacing:.05em"><?= $label ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Painel de Atividades Recentes (interlaced: sync Banco de Dados + demais apps do ecossistema) -->
<div style="display:grid;grid-template-columns:1fr;gap:1rem;align-items:start">
    <div class="table-panel" style="padding:0">
        <div style="padding:.75rem 1.25rem;border-bottom:1px solid #f0f4f8;display:flex;align-items:center;justify-content:space-between">
            <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#a0aec0">
                <i class="fas fa-stream" style="color:#0DC2FF;margin-right:.3rem"></i> Atividades Recentes
            </span>
            <button onclick="ativRecarregar()" style="border:none;background:none;color:#a0aec0;cursor:pointer;display:flex;align-items:center;gap:.3rem">
                <i class="fas fa-sync-alt" id="ativ-refresh-icon" style="font-size:.78rem"></i>
                <span style="font-size:.72rem">Atualizar</span>
            </button>
        </div>
        <div id="ativ-lista" style="padding:.5rem 1rem">
            <div style="text-align:center;color:#a0aec0;padding:2rem"><i class="fas fa-spinner fa-spin"></i></div>
        </div>
    </div>
</div>

<script>
let ativData = [];

function ativCarregar() {
    fetch('/api/atividades-recentes.php', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.erro) return;
            ativData = data.atividades || [];
            ativRender(ativData);
        });
}

function ativRecarregar() {
    const icon = document.getElementById('ativ-refresh-icon');
    if (icon) icon.classList.add('fa-spin');
    fetch('/api/atividades-recentes.php', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (icon) icon.classList.remove('fa-spin');
            if (data.erro) return;
            ativData = data.atividades || [];
            ativRender(ativData);
        })
        .catch(() => { if (icon) icon.classList.remove('fa-spin'); });
}

function ativRender(atividades) {
    const lista = document.getElementById('ativ-lista');
    if (!atividades.length) {
        lista.innerHTML = '<p style="color:#a0aec0;font-size:.82rem;text-align:center;padding:1.5rem">Nenhuma atividade registrada.</p>';
        return;
    }
    lista.innerHTML = atividades.map((a, i) => ativRow(a, i)).join('');
}

// Ícone/cor por tipo de atividade (a cor do status — pill — é sempre a mesma, independente do tipo)
function ativIconeTipo(tipo) {
    return tipo === 'sync'
        ? { icone: 'fa-database', cor: '#0DC2FF' }
        : { icone: 'fa-handshake', cor: '#805ad5' };
}

function ativNomeLinha(a) {
    const nomeCliente = (a.cliente_nome || '').length > 32 ? a.cliente_nome.substring(0,30)+'…' : (a.cliente_nome || '');
    return a.tipo === 'sync' ? nomeCliente : (a.titulo + (nomeCliente ? ' — ' + nomeCliente : ''));
}

function ativRow(a, i) {
    const id  = 'ativ-' + i;
    const { icone, cor: corTipo } = ativIconeTipo(a.tipo);
    const nomeLinha = ativNomeLinha(a);

    // Em andamento — mesmo tratamento visual animado, independente do tipo de atividade
    if (a.status === 'rodando') {
        const inicio = a.iniciado_em ? new Date(a.iniciado_em).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}) : '—';
        return `
        <div style="border-bottom:1px solid #f0f4f8;padding:.75rem .25rem;display:flex;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:.65rem">
                <i class="fas fa-spinner fa-spin" style="color:#d69e2e;font-size:.95rem;width:18px;text-align:center"></i>
                <div>
                    <div style="font-weight:600;font-size:.875rem;color:#2d3748">${nomeLinha}</div>
                    <div style="font-size:.72rem;color:#a0aec0">${inicio}</div>
                </div>
            </div>
            <span style="font-size:.72rem;font-weight:700;padding:.25rem .7rem;border-radius:20px;background:#fffff0;color:#d69e2e">
                <i class="fas fa-spinner fa-spin" style="margin-right:.25rem"></i>Em andamento
            </span>
        </div>`;
    }

    const hasError = a.status === 'erro';
    const cor      = hasError ? '#c53030' : '#38a169';
    const ic       = hasError ? 'fa-times-circle' : 'fa-check-circle';
    const label    = hasError ? 'Com erros' : 'Concluído';
    const bg       = hasError ? '#fff5f5' : '#f0fff4';
    const fmtHM    = dt => new Date(dt).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});

    let resumo, detalheHtml;

    if (a.tipo === 'sync') {
        // Comportamento de expand idêntico ao painel de sync original
        const r        = a.sync_detail;
        const dataStr  = new Date(r.iniciou_em).toLocaleDateString('pt-BR');
        const horaIni  = fmtHM(r.iniciou_em).replace(':', 'h');
        const durSec   = (new Date(r.terminou_em) - new Date(r.iniciou_em)) / 1000;
        const durStr   = durSec >= 60 ? Math.round(durSec/60) + ' min' : Math.round(durSec) + 's';
        resumo = `${dataStr} — Início: ${horaIni} · Tabelas: ${r.total_tabelas} · Duração: ${durStr}`;

        const entidadesHtml = (r.entidades || []).map(e => `
            <div style="display:flex;align-items:center;gap:.5rem;padding:.25rem 0;border-bottom:1px solid #f0f4f8;font-size:.75rem">
                <span style="color:#4a5568;flex:1">${e.entidade_label || e.entidade}</span>
                <span style="color:#718096;min-width:40px;text-align:right">${e.registros}</span>
                <span style="font-weight:700;min-width:28px;text-align:center;color:${e.status==='ok'?'#38a169':'#c53030'}">${e.status==='ok'?'OK':'Err'}</span>
            </div>`).join('');

        detalheHtml = `
            <div style="display:flex;gap:1.25rem;font-size:.72rem;color:#718096;padding:.3rem 0 .5rem;border-bottom:1px solid #e2e8f0;margin-bottom:.35rem">
                <span><i class="fas fa-play" style="color:#38a169;margin-right:.25rem"></i>Início <strong>${fmtHM(r.iniciou_em)}</strong></span>
                <span><i class="fas fa-flag-checkered" style="color:#086B8D;margin-right:.25rem"></i>Fim <strong>${fmtHM(r.terminou_em)}</strong></span>
                <span><i class="fas fa-clock" style="color:#a0aec0;margin-right:.25rem"></i><strong>${durStr}</strong></span>
            </div>
            ${entidadesHtml}`;
    } else {
        // Demais tipos (ex.: nimbus_parceiros) — resumo + detalhe estruturado gravados pelo próprio job
        const dataStr = new Date(a.iniciado_em).toLocaleDateString('pt-BR');
        const horaIni = fmtHM(a.iniciado_em).replace(':', 'h');
        const durSec  = a.finalizado_em ? (new Date(a.finalizado_em) - new Date(a.iniciado_em)) / 1000 : null;
        const durStr  = durSec !== null ? (durSec >= 60 ? Math.round(durSec/60) + ' min' : Math.round(durSec) + 's') : '—';
        resumo = `${dataStr} — Início: ${horaIni} · Duração: ${durStr}`;

        const resumoHtml = a.resumo
            ? `<div style="font-size:.8rem;color:#2d3748;padding:.35rem 0">${a.resumo}</div>`
            : '';
        detalheHtml = `
            <div style="display:flex;gap:1.25rem;font-size:.72rem;color:#718096;padding:.3rem 0 .5rem;border-bottom:1px solid #e2e8f0;margin-bottom:.35rem">
                <span><i class="fas fa-play" style="color:#38a169;margin-right:.25rem"></i>Início <strong>${fmtHM(a.iniciado_em)}</strong></span>
                <span><i class="fas fa-flag-checkered" style="color:#086B8D;margin-right:.25rem"></i>Fim <strong>${a.finalizado_em ? fmtHM(a.finalizado_em) : '—'}</strong></span>
                <span><i class="fas fa-clock" style="color:#a0aec0;margin-right:.25rem"></i><strong>${durStr}</strong></span>
            </div>
            ${resumoHtml}`;
    }

    return `
    <div style="border-bottom:1px solid #f0f4f8">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem .25rem;cursor:pointer"
             onclick="ativToggle('${id}', this)">
            <div style="display:flex;align-items:center;gap:.65rem;min-width:0">
                <i class="fas ${icone}" style="color:${corTipo};font-size:.95rem;flex-shrink:0;width:18px;text-align:center"></i>
                <div style="min-width:0">
                    <div style="font-weight:600;font-size:.875rem;color:#2d3748;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${nomeLinha}</div>
                    <div style="font-size:.72rem;color:#a0aec0">${resumo}</div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;flex-shrink:0;margin-left:.75rem">
                <span style="font-size:.72rem;font-weight:700;padding:.25rem .7rem;border-radius:20px;background:${bg};color:${cor}">
                    <i class="fas ${ic}" style="margin-right:.25rem"></i>${label}
                </span>
                <i class="fas fa-chevron-down ativ-chevron" style="color:#cbd5e0;font-size:.7rem;transition:transform .2s"></i>
            </div>
        </div>
        <div id="${id}" style="display:none;padding:.4rem .75rem .75rem;background:#f8fafc;border-radius:8px;margin-bottom:.5rem">
            ${detalheHtml}
        </div>
    </div>`;
}

function ativToggle(id, header) {
    const detail  = document.getElementById(id);
    const chevron = header.querySelector('.ativ-chevron');
    const open    = detail.style.display === 'block';
    detail.style.display  = open ? 'none' : 'block';
    if (chevron) chevron.style.transform = open ? '' : 'rotate(180deg)';
}

ativCarregar();
setInterval(ativRecarregar, 60000); // auto-refresh 1 min
</script>
