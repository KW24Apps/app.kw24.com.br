<?php
if (!defined('SYSTEM_ACCESS') && !isset($user_data)) {
    header('Location: /public/login.php');
    exit;
}
if (!isset($user_data) || ($user_data['perfil'] ?? '') !== 'admin_interno') {
    echo '<div style="color:#e53e3e;padding:2rem">Acesso restrito a administradores.</div>';
    return;
}
?>
<style>
.org-badge-ativo   { display:inline-block;padding:.2rem .6rem;border-radius:20px;font-size:.72rem;font-weight:700;background:#d1fae5;color:#065f46 }
.org-badge-inativo { display:inline-block;padding:.2rem .6rem;border-radius:20px;font-size:.72rem;font-weight:700;background:#f0f4f8;color:#a0aec0 }
.org-motor-cell    { font-family:monospace;font-size:.78rem;color:#718096;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap }
.org-toggle-row    { display:flex;align-items:center;gap:.75rem;padding:.5rem 0 }
</style>

<div class="page-header">
    <h1 class="page-title"><i class="fas fa-sitemap"></i> Organizações</h1>
    <button onclick="orgAbrirNovo()" class="btn-primary"><i class="fas fa-plus"></i> Nova Organização</button>
</div>

<div class="table-panel">
    <div id="org-loading" class="panel-loading" style="padding:2rem"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>
    <div class="table-scroll" id="org-table-wrap" style="display:none">
        <table class="clientes-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Status</th>
                    <th>Webhook Bitrix24</th>
                    <th>Criada em</th>
                </tr>
            </thead>
            <tbody id="org-tbody"></tbody>
        </table>
    </div>
    <div class="table-footer" id="org-footer"></div>
</div>

<!-- Overlay -->
<div id="org-overlay" class="cliente-overlay" onclick="orgFecharPainel()"></div>

<!-- Painel lateral -->
<div id="org-panel" class="cliente-panel" style="width:min(640px,calc(100vw - 160px))">
    <div class="panel-header">
        <div class="panel-avatar" style="background:linear-gradient(135deg,#086B8D,#0DC2FF)">
            <i class="fas fa-sitemap" style="font-size:.9rem"></i>
        </div>
        <div class="panel-header-info">
            <h2 class="panel-title" id="org-panel-titulo">Carregando...</h2>
            <p class="panel-subtitle" id="org-panel-sub"></p>
        </div>
        <button class="panel-close" onclick="orgFecharPainel()"><i class="fas fa-times"></i></button>
    </div>

    <div class="panel-body">
        <div id="org-panel-loading" class="panel-loading">
            <i class="fas fa-spinner fa-spin"></i> Carregando...
        </div>

        <!-- Modo Nova Organização -->
        <div id="org-panel-novo" style="display:none">
            <div class="panel-section-title">Nova Organização</div>
            <div style="display:grid;gap:.75rem">
                <div class="panel-field no-edit">
                    <label>Nome *</label>
                    <input type="text" id="org-novo-nome" class="form-input" placeholder="Nome da organização">
                </div>
                <div class="panel-field no-edit">
                    <label>Webhook Bitrix24 <small style="color:#a0aec0;font-weight:400">— webhook para sync de metadados via api_kw24</small></label>
                    <input type="text" id="org-novo-webhook" class="form-input" placeholder="https://...">
                </div>
                <div class="org-toggle-row">
                    <label class="toggle-switch">
                        <input type="checkbox" id="org-novo-ativo" checked>
                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    </label>
                    <span id="org-novo-ativo-label" style="font-size:.875rem;color:#2d3748;font-weight:500">Organização ativa</span>
                </div>
                <div id="org-novo-erro" style="color:#e53e3e;font-size:.85rem;display:none"></div>
            </div>
        </div>

        <!-- Modo Visualizar/Editar -->
        <div id="org-panel-conteudo" style="display:none">
            <div class="panel-section-title">Dados da Organização</div>
            <div class="panel-field no-edit"><label>ID</label><span id="org-pf-id"></span></div>
            <div class="panel-field" data-org-campo="nome" onclick="editarCampoOrg(this)">
                <label>Nome</label><span id="org-pf-nome"></span>
            </div>
            <div class="panel-field no-edit" style="pointer-events:none">
                <label>Status</label>
                <div class="org-toggle-row" style="padding:.15rem 0;pointer-events:all">
                    <label class="toggle-switch" onclick="orgToggleAtivoPanel();event.preventDefault()">
                        <input type="checkbox" id="org-pf-ativo" readonly>
                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    </label>
                    <span id="org-pf-ativo-label" style="font-size:.875rem;color:#2d3748;font-weight:500">—</span>
                </div>
            </div>
            <div class="panel-field no-edit"><label>Criada em</label><span id="org-pf-criada"></span></div>
            <div class="panel-divider"></div>
            <div class="panel-section-title">Webhook Bitrix24</div>
            <div id="org-pf-webhook-wrap" style="margin-bottom:1.25rem"></div>
        </div>
    </div>

    <div class="panel-save-bar" id="org-save-bar">
        <button class="btn-salvar" onclick="salvarEdicoesOrg()"><i class="fas fa-check"></i> Salvar</button>
        <button class="btn-cancelar-edit" onclick="cancelarEdicoesOrg()">Cancelar</button>
        <span class="save-bar-msg" id="org-save-msg"></span>
    </div>
</div>

<script>
let orgIdAtual        = null;
let orgEdicoesPendentes = {};
let orgModoNovo       = false;

/* ── helpers ────────────────────────────────────────────────────── */
function htmlEsc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function _maskWh(url) {
    if (!url) return '<span style="color:#cbd5e0">—</span>';
    try {
        const u = new URL(url);
        return '<span style="font-family:monospace;font-size:.78rem;color:#718096">' + htmlEsc(u.protocol + '//' + u.hostname) + '/••••••••</span>';
    } catch { return '<span style="color:#718096;font-family:monospace">••••••••</span>'; }
}

/* ── carregar tabela ─────────────────────────────────────────────── */
function orgCarregar() {
    fetch('/api/organizacoes.php?action=list', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(rows => {
            document.getElementById('org-loading').style.display = 'none';
            document.getElementById('org-table-wrap').style.display = 'block';
            const tbody = document.getElementById('org-tbody');
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="empty-state"><i class="fas fa-sitemap"></i><p>Nenhuma organização cadastrada.</p></div></td></tr>';
                document.getElementById('org-footer').textContent = '0 organizações';
                return;
            }
            tbody.innerHTML = rows.map(o => `
                <tr onclick="orgAbrirPainel(${o.id})" style="cursor:pointer">
                    <td style="font-weight:600;color:#1a202c">${htmlEsc(o.nome)}</td>
                    <td><span class="${o.ativo ? 'org-badge-ativo' : 'org-badge-inativo'}">${o.ativo ? 'Ativo' : 'Inativo'}</span></td>
                    <td class="org-motor-cell">${_maskWh(o.webhook_motor)}</td>
                    <td style="color:#718096;font-size:.82rem">${htmlEsc(o.created_fmt || '—')}</td>
                </tr>`).join('');
            document.getElementById('org-footer').textContent = `${rows.length} organização${rows.length !== 1 ? 'ões' : ''}`;
        })
        .catch(() => {
            document.getElementById('org-loading').innerHTML = '<span style="color:#e53e3e">Erro ao carregar organizações.</span>';
        });
}

/* ── abrir painel (visualizar/editar) ───────────────────────────── */
function orgAbrirPainel(id) {
    orgIdAtual      = id;
    orgModoNovo     = false;
    orgEdicoesPendentes = {};
    cancelarEdicoesOrg();

    const overlay = document.getElementById('org-overlay');
    const panel   = document.getElementById('org-panel');
    overlay.classList.add('open');
    panel.classList.add('open');

    document.getElementById('org-panel-loading').style.display = 'flex';
    document.getElementById('org-panel-conteudo').style.display = 'none';
    document.getElementById('org-panel-novo').style.display     = 'none';
    document.getElementById('org-save-bar').classList.remove('visivel');
    document.getElementById('org-panel-titulo').textContent = 'Carregando...';
    document.getElementById('org-panel-sub').textContent    = '';

    fetch(`/api/organizacoes.php?action=get&id=${id}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(o => {
            if (o.erro) { alert(o.erro); orgFecharPainel(); return; }
            _preencherPainelOrg(o);
        })
        .catch(() => orgFecharPainel());
}

function _preencherPainelOrg(o) {
    document.getElementById('org-panel-titulo').textContent = o.nome || '—';
    document.getElementById('org-panel-sub').textContent    = o.ativo ? 'Organização ativa' : 'Organização inativa';
    document.getElementById('org-pf-id').textContent        = o.id;
    document.getElementById('org-pf-nome').textContent      = o.nome || '—';
    document.getElementById('org-pf-criada').textContent    = o.created_fmt || '—';

    const ativoEl = document.getElementById('org-pf-ativo');
    if (ativoEl) ativoEl.checked = !!o.ativo;
    const ativoLb = document.getElementById('org-pf-ativo-label');
    if (ativoLb) ativoLb.textContent = o.ativo ? 'Organização ativa' : 'Organização inativa';

    /* webhook mascarado */
    const whWrap = document.getElementById('org-pf-webhook-wrap');
    if (whWrap) {
        const temWh = !!o.webhook_motor;
        whWrap.innerHTML = `
            ${temWh
                ? `<div style="display:flex;align-items:center;gap:.5rem;background:#f8fafc;border-radius:6px;padding:.4rem .65rem;border:1px solid #e2e8f0;margin-bottom:.5rem">
                       ${_maskWh(o.webhook_motor)}
                   </div>`
                : `<p style="color:#a0aec0;font-size:.85rem;margin:0 0 .5rem">Nenhum webhook configurado.</p>`}
            <button onclick="orgMostrarEditarWebhook()"
                style="background:none;border:1px solid ${temWh ? '#e2e8f0' : '#0DC2FF'};border-radius:6px;padding:.3rem .7rem;font-size:.78rem;cursor:pointer;color:${temWh ? '#4a5568' : '#0DC2FF'};font-weight:${temWh ? '500' : '600'}">
                <i class="fas fa-${temWh ? 'pen' : 'plus'}"></i> ${temWh ? 'Alterar webhook' : 'Adicionar webhook'}
            </button>
            <div id="org-webhook-edit-wrap" style="display:none;margin-top:.75rem">
                <input type="text" id="org-webhook-novo-val" class="form-input" placeholder="https://... (novo valor)">
                <div style="display:flex;gap:.5rem;margin-top:.5rem">
                    <button onclick="orgSalvarWebhook()"
                        style="padding:.4rem .8rem;border:none;border-radius:6px;background:#0DC2FF;color:#fff;font-size:.8rem;cursor:pointer;font-weight:600">
                        <i class="fas fa-check"></i> Confirmar
                    </button>
                    <button onclick="document.getElementById('org-webhook-edit-wrap').style.display='none'"
                        style="padding:.4rem .8rem;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#718096;font-size:.8rem;cursor:pointer">
                        Cancelar
                    </button>
                </div>
                <span id="org-webhook-msg" style="font-size:.8rem;color:#718096;display:block;margin-top:.35rem"></span>
            </div>`;
    }

    document.getElementById('org-panel-loading').style.display  = 'none';
    document.getElementById('org-panel-conteudo').style.display = 'block';
}

function orgMostrarEditarWebhook() {
    const wrap = document.getElementById('org-webhook-edit-wrap');
    if (wrap) { wrap.style.display = 'block'; document.getElementById('org-webhook-novo-val')?.focus(); }
}

async function orgSalvarWebhook() {
    const val = document.getElementById('org-webhook-novo-val')?.value.trim();
    const msg = document.getElementById('org-webhook-msg');
    if (!val) { if (msg) msg.textContent = 'Informe o novo webhook.'; return; }
    if (msg) msg.textContent = 'Salvando...';

    const nomeAtual = document.getElementById('org-pf-nome').textContent;
    const ativoAtual = document.getElementById('org-pf-ativo')?.checked ?? true;

    const res = await fetch('/api/organizacoes.php?action=update', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: orgIdAtual, nome: nomeAtual, ativo: ativoAtual, webhook_motor: val })
    }).then(r => r.json());

    if (res.sucesso) {
        if (msg) msg.textContent = '✓ Webhook atualizado';
        document.getElementById('org-webhook-edit-wrap').style.display = 'none';
        orgCarregar();
        // Recarrega painel para mostrar mascara atualizada
        fetch(`/api/organizacoes.php?action=get&id=${orgIdAtual}`, { credentials: 'same-origin' })
            .then(r => r.json()).then(o => _preencherPainelOrg(o));
    } else {
        if (msg) msg.textContent = res.erro || 'Erro ao salvar.';
    }
}

/* ── fechar painel ──────────────────────────────────────────────── */
function orgFecharPainel() {
    document.getElementById('org-overlay')?.classList.remove('open');
    document.getElementById('org-panel')?.classList.remove('open');
    cancelarEdicoesOrg();
    orgIdAtual  = null;
    orgModoNovo = false;
    const bar = document.getElementById('org-save-bar');
    if (bar) {
        bar.classList.remove('visivel');
        const btn = bar.querySelector('.btn-salvar');
        if (btn) btn.innerHTML = '<i class="fas fa-check"></i> Salvar';
    }
}

/* ── edição inline ──────────────────────────────────────────────── */
function editarCampoOrg(fieldEl) {
    if (fieldEl.classList.contains('editando') || fieldEl.classList.contains('no-edit')) return;
    fieldEl.classList.add('editando');
    const campo = fieldEl.getAttribute('data-org-campo');
    const span  = fieldEl.querySelector('span');
    const val   = span.textContent === '—' ? '' : span.textContent;
    span.style.display = 'none';
    const input = Object.assign(document.createElement('input'), { type: 'text', value: val, className: 'form-input' });
    input.style.cssText = 'font-size:.875rem;padding:.4rem .6rem';
    fieldEl.appendChild(input);
    input.focus();
    document.getElementById('org-save-bar').classList.add('visivel');
    orgEdicoesPendentes[campo] = val;
    input.addEventListener('input', () => { orgEdicoesPendentes[campo] = input.value; });
}

function cancelarEdicoesOrg() {
    document.querySelectorAll('#org-panel .panel-field.editando').forEach(f => {
        const span  = f.querySelector('span');
        const input = f.querySelector('input:not([type=checkbox])');
        if (input) input.remove();
        if (span)  span.style.display = '';
        f.classList.remove('editando');
    });
    orgEdicoesPendentes = {};
    document.getElementById('org-save-bar')?.classList.remove('visivel');
    const msg = document.getElementById('org-save-msg');
    if (msg) msg.textContent = '';
}

async function salvarEdicoesOrg() {
    if (orgModoNovo) { salvarNovaOrg(); return; }
    if (!orgIdAtual || !Object.keys(orgEdicoesPendentes).length) return;

    const msg = document.getElementById('org-save-msg');
    if (msg) msg.textContent = 'Salvando...';

    const nomeAtual = document.getElementById('org-pf-nome').textContent;
    const payload = {
        id:    orgIdAtual,
        nome:  orgEdicoesPendentes.nome || nomeAtual,
        ativo: document.getElementById('org-pf-ativo')?.checked ?? true
    };

    const res = await fetch('/api/organizacoes.php?action=update', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(r => r.json());

    if (res.sucesso) {
        document.querySelectorAll('#org-panel .panel-field.editando').forEach(f => {
            const campo = f.getAttribute('data-org-campo');
            const span  = f.querySelector('span');
            const input = f.querySelector('input:not([type=checkbox])');
            span.textContent = orgEdicoesPendentes[campo] || '—';
            if (input) input.remove();
            span.style.display = '';
            f.classList.remove('editando');
        });
        if (orgEdicoesPendentes.nome) {
            document.getElementById('org-panel-titulo').textContent = orgEdicoesPendentes.nome;
        }
        orgEdicoesPendentes = {};
        document.getElementById('org-save-bar').classList.remove('visivel');
        if (msg) msg.textContent = '';
        orgCarregar();
    } else {
        if (msg) msg.textContent = res.erro || 'Erro ao salvar.';
    }
}

/* ── toggle ativo no painel ─────────────────────────────────────── */
async function orgToggleAtivoPanel() {
    if (!orgIdAtual) return;
    const ativoEl = document.getElementById('org-pf-ativo');
    const ativo   = ativoEl?.checked;
    const acao    = ativo ? 'desativar' : 'ativar';
    const ok = await kwConfirm(
        `Deseja ${acao} esta organização?`,
        `${acao.charAt(0).toUpperCase() + acao.slice(1)} organização`,
        ativo ? 'danger' : 'success'
    );
    if (!ok) {
        if (ativoEl) ativoEl.checked = ativo; // reverte
        return;
    }

    const res = await fetch('/api/organizacoes.php?action=toggle-ativo', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: orgIdAtual })
    }).then(r => r.json());

    if (res.sucesso) {
        if (ativoEl) ativoEl.checked = !!res.ativo;
        const lb = document.getElementById('org-pf-ativo-label');
        if (lb) lb.textContent = res.ativo ? 'Organização ativa' : 'Organização inativa';
        document.getElementById('org-panel-sub').textContent = res.ativo ? 'Organização ativa' : 'Organização inativa';
        orgCarregar();
    } else {
        alert(res.erro || 'Erro.');
        if (ativoEl) ativoEl.checked = ativo; // reverte
    }
}

/* ── nova organização ───────────────────────────────────────────── */
function orgAbrirNovo() {
    orgIdAtual  = null;
    orgModoNovo = true;
    orgEdicoesPendentes = {};

    document.getElementById('org-novo-nome').value    = '';
    document.getElementById('org-novo-webhook').value = '';
    document.getElementById('org-novo-ativo').checked = true;
    document.getElementById('org-novo-ativo-label').textContent = 'Organização ativa';
    document.getElementById('org-novo-erro').style.display = 'none';

    document.getElementById('org-panel-titulo').textContent  = 'Nova Organização';
    document.getElementById('org-panel-sub').textContent     = '';
    document.getElementById('org-panel-loading').style.display  = 'none';
    document.getElementById('org-panel-conteudo').style.display = 'none';
    document.getElementById('org-panel-novo').style.display     = 'block';

    const bar = document.getElementById('org-save-bar');
    bar.classList.add('visivel');
    const btn = bar.querySelector('.btn-salvar');
    if (btn) btn.innerHTML = '<i class="fas fa-check"></i> Cadastrar';

    document.getElementById('org-overlay').classList.add('open');
    document.getElementById('org-panel').classList.add('open');

    document.getElementById('org-novo-ativo').onchange = function () {
        document.getElementById('org-novo-ativo-label').textContent = this.checked ? 'Organização ativa' : 'Organização inativa';
    };

    setTimeout(() => document.getElementById('org-novo-nome').focus(), 60);
}

async function salvarNovaOrg() {
    const nome    = document.getElementById('org-novo-nome')?.value.trim();
    const webhook = document.getElementById('org-novo-webhook')?.value.trim();
    const ativo   = document.getElementById('org-novo-ativo')?.checked ?? true;
    const erroEl  = document.getElementById('org-novo-erro');

    if (!nome) {
        erroEl.textContent = 'Nome é obrigatório.';
        erroEl.style.display = 'block';
        return;
    }
    erroEl.style.display = 'none';

    const msg = document.getElementById('org-save-msg');
    if (msg) msg.textContent = 'Cadastrando...';

    const payload = { nome, ativo };
    if (webhook) payload.webhook_motor = webhook;

    const res = await fetch('/api/organizacoes.php?action=create', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(r => r.json());

    if (res.sucesso) {
        orgFecharPainel();
        orgCarregar();
    } else {
        erroEl.textContent = res.erro || 'Erro ao cadastrar.';
        erroEl.style.display = 'block';
        if (msg) msg.textContent = '';
    }
}

/* ── ESC fecha painel ───────────────────────────────────────────── */
document.addEventListener('keydown', e => { if (e.key === 'Escape') orgFecharPainel(); });

orgCarregar();
</script>
