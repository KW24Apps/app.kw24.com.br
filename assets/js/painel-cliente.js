/**
 * KW24 - Painel lateral de cliente
 * Carregado no index.php para estar sempre disponível
 */

// ===== CONFIRMAÇÃO CUSTOMIZADA =====
function kwConfirm(msg, titulo = 'Confirmar ação', tipo = 'danger') {
    return new Promise(resolve => {
        const overlay = document.getElementById('kw-confirm-overlay');
        const box     = document.getElementById('kw-confirm-box');
        const icon    = document.getElementById('kw-confirm-icon');
        const titleEl = document.getElementById('kw-confirm-title');
        const msgEl   = document.getElementById('kw-confirm-msg');
        const btnOk   = document.getElementById('kw-confirm-ok');
        const btnCancel = document.getElementById('kw-confirm-cancel');

        titleEl.textContent = titulo;
        msgEl.textContent   = msg;

        // Estilos por tipo
        if (tipo === 'danger') {
            icon.style.background  = '#fee2e2';
            icon.style.color       = '#c53030';
            icon.innerHTML         = '<i class="fas fa-exclamation-triangle"></i>';
            btnOk.style.background = '#e53e3e';
            btnOk.onmouseover      = () => btnOk.style.background = '#c53030';
            btnOk.onmouseout       = () => btnOk.style.background = '#e53e3e';
        } else {
            icon.style.background  = '#d1fae5';
            icon.style.color       = '#065f46';
            icon.innerHTML         = '<i class="fas fa-check-circle"></i>';
            btnOk.style.background = '#0DC2FF';
            btnOk.onmouseover      = () => btnOk.style.background = '#086B8D';
            btnOk.onmouseout       = () => btnOk.style.background = '#0DC2FF';
        }

        overlay.style.display = 'flex';

        const close = (result) => {
            overlay.style.display = 'none';
            btnOk.onclick     = null;
            btnCancel.onclick = null;
            resolve(result);
        };

        btnOk.onclick     = () => close(true);
        btnCancel.onclick = () => close(false);
        overlay.onclick   = (e) => { if (e.target === overlay) close(false); };
    });
}

const iconeApp = {
    clicksign:   'fas fa-file-signature',
    crm:         'fas fa-handshake',
    task:        'fas fa-tasks',
    company:     'fas fa-building',
    omie:        'fas fa-calculator',
    receita:     'fas fa-search',
    import:      'fas fa-upload',
    disk:        'fas fa-hdd',
    calcdata:    'fas fa-calendar-alt',
    mediahora:   'fas fa-clock',
    scheduler:   'fas fa-robot',
    geraroptnd:  'fas fa-magic',
    extenso:     'fas fa-font',
    validar_cnpj:'fas fa-id-card',
    'relatorios-bi': 'fas fa-chart-bar'
};

let clienteIdAtual        = null;
let edicoesPendentes      = {};
let todasApps             = [];
let appsAtivas            = [];
let _appFiltroAtual       = null;
let _rightTabUsersLoaded  = false;

// Estado do modal Relatórios BI (per-report per-user) — só persiste no servidor ao Salvar.
let biRelatorios          = []; // relatórios configurados: [{slug, nome_amigavel, permissoes:[{usuario_id,pode_ver,pode_criar_portal}]}]
let biCatalogo            = []; // catálogo completo de relatorios_bi: [{id, slug, nome_amigavel}]
let biUsuariosCliente     = []; // usuários vinculados ao cliente atual: [{usuario_id, nome, username}]
let biEditandoSlug        = null; // null = form em modo "Adicionar"; slug = editando esse relatório

function _mascaraCNPJ(v) {
    v = v.replace(/\D/g, '').slice(0, 14);
    if (v.length <= 2)  return v;
    if (v.length <= 5)  return v.slice(0,2) + '.' + v.slice(2);
    if (v.length <= 8)  return v.slice(0,2) + '.' + v.slice(2,5) + '.' + v.slice(5);
    if (v.length <= 12) return v.slice(0,2) + '.' + v.slice(2,5) + '.' + v.slice(5,8) + '/' + v.slice(8);
    return v.slice(0,2) + '.' + v.slice(2,5) + '.' + v.slice(5,8) + '/' + v.slice(8,12) + '-' + v.slice(12);
}
function _mascaraTelefone(v) {
    v = v.replace(/\D/g, '').slice(0, 11);
    if (v.length <= 2)  return '(' + v;
    if (v.length <= 6)  return '(' + v.slice(0,2) + ') ' + v.slice(2);
    if (v.length <= 10) return '(' + v.slice(0,2) + ') ' + v.slice(2,6) + '-' + v.slice(6);
    return '(' + v.slice(0,2) + ') ' + v.slice(2,7) + '-' + v.slice(7);
}
function _validarCNPJ(cnpj) {
    cnpj = cnpj.replace(/\D/g, '');
    if (cnpj.length !== 14 || /^(\d)\1+$/.test(cnpj)) return false;
    let s = 0, r;
    [5,4,3,2,9,8,7,6,5,4,3,2].forEach((w,i) => { s += w * +cnpj[i]; });
    r = s % 11 < 2 ? 0 : 11 - s % 11;
    if (+cnpj[12] !== r) return false;
    s = 0;
    [6,5,4,3,2,9,8,7,6,5,4,3,2].forEach((w,i) => { s += w * +cnpj[i]; });
    r = s % 11 < 2 ? 0 : 11 - s % 11;
    return +cnpj[13] === r;
}
function _validarTelefone(t) { return t.replace(/\D/g, '').length >= 10; }
function _validarEmail(e) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e); }
function _erroInput(inputEl, msg) {
    const parent = inputEl.closest('.panel-field') || inputEl.parentNode;
    let el = parent.querySelector('.campo-erro');
    if (!el) {
        el = document.createElement('div');
        el.className = 'campo-erro';
        el.style.cssText = 'color:#c53030;font-size:.73rem;margin-top:.2rem';
        parent.appendChild(el);
    }
    el.textContent = msg;
    inputEl.style.borderColor = msg ? '#c53030' : '';
    if (msg) inputEl.focus();
}

function _formatDate(ts) {
    if (!ts) return '—';
    const d = new Date(ts);
    return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

// Modal de ativação com webhook + descricao
function kwAtivarApp(appNome) {
    return new Promise(resolve => {
        const overlay   = document.getElementById('kw-ativar-overlay');
        const titleEl   = document.getElementById('kw-ativar-title');
        const msgEl     = document.getElementById('kw-ativar-msg');
        const input     = document.getElementById('kw-ativar-webhook');
        const erro      = document.getElementById('kw-ativar-erro');
        const btnOk     = document.getElementById('kw-ativar-ok');
        const btnCancel = document.getElementById('kw-ativar-cancel');

        // Injeta campo de descrição se ainda não existir
        let descInput = document.getElementById('kw-ativar-descricao');
        if (!descInput) {
            const descWrap = document.createElement('div');
            descWrap.style.cssText = 'margin-top:.75rem';
            descWrap.innerHTML = `
                <label style="display:block;font-size:.75rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4rem">Descrição <small style="font-weight:400;color:#a0aec0;text-transform:none">— ex: Comercial, Operacional</small></label>
                <input id="kw-ativar-descricao" type="text" placeholder="Ex: Comercial"
                    style="width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:.6rem .75rem;font-size:.875rem;color:#2d3748;outline:none;font-family:inherit;box-sizing:border-box"
                    onfocus="this.style.borderColor='#0DC2FF'" onblur="this.style.borderColor='#e2e8f0'">
                <span id="kw-ativar-descricao-erro" style="display:none;color:#c53030;font-size:.78rem;margin-top:.3rem">Descrição é obrigatória.</span>`;
            input.parentNode.insertAdjacentElement('afterend', descWrap);
            descInput = document.getElementById('kw-ativar-descricao');
        }

        titleEl.textContent   = 'Ativar aplicação';
        msgEl.textContent     = `Adicionar "${appNome}" para este cliente?`;
        input.value           = '';
        descInput.value       = '';
        erro.style.display    = 'none';
        overlay.style.display = 'flex';
        input.focus();

        const close = (result) => {
            overlay.style.display = 'none';
            btnOk.onclick     = null;
            btnCancel.onclick = null;
            overlay.onclick   = null;
            resolve(result);
        };

        btnOk.onclick = () => {
            const wh   = input.value.trim();
            const desc = descInput.value.trim();
            const erroDesc = document.getElementById('kw-ativar-descricao-erro');
            if (!wh)   { erro.style.display = 'block'; return; }
            erro.style.display = 'none';
            if (!desc) { if (erroDesc) erroDesc.style.display = 'block'; return; }
            if (erroDesc) erroDesc.style.display = 'none';
            close({ webhook: wh, descricao: desc });
        };

        btnCancel.onclick = () => close(null);
        overlay.onclick   = (e) => { if (e.target === overlay) close(null); };
        input.onkeydown   = (e) => { if (e.key === 'Enter') btnOk.click(); };
    });
}

// ===== ABRIR / FECHAR PAINEL =====

function abrirCliente(id) {
    clienteIdAtual   = id;
    edicoesPendentes = {};
    cancelarEdicoes();
    _appFiltroAtual  = null; // força recalcular o tab default (1º grupo real) para este cliente

    const overlay = document.getElementById('cliente-overlay');
    const panel   = document.getElementById('cliente-panel');
    if (!overlay || !panel) return;

    overlay.classList.add('open');
    panel.classList.add('open');
    document.getElementById('panel-loading').style.display  = 'flex';
    document.getElementById('panel-conteudo').style.display = 'none';

    fetch('/api/cliente-detalhe.php?id=' + id, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.erro) { alert(data.erro); fecharPainel(); return; }
            preencherPainel(data.cliente, data.aplicacoes);
        })
        .catch(err => { console.error('Painel erro:', err); fecharPainel(); });
}

function fecharPainel() {
    const overlay = document.getElementById('cliente-overlay');
    const panel   = document.getElementById('cliente-panel');
    if (overlay) overlay.classList.remove('open');
    if (panel)   panel.classList.remove('open');
    cancelarEdicoes();
    modoNovo = false;
    // Restaura largura original do painel
    document.getElementById('cliente-panel').style.width = '';
    // Restaura menu ⋮
    const btnMenu = document.getElementById('btn-menu-cliente');
    if (btnMenu) btnMenu.style.visibility = '';
    // Restaura botão salvar
    const btnSalvar = document.querySelector('#panel-save-bar .btn-salvar');
    if (btnSalvar) btnSalvar.innerHTML = '<i class="fas fa-check"></i> Salvar';
}

function salvarNovoCliente() {
    const campos = {
        nome:        document.getElementById('novo-nome')?.value.trim(),
        cnpj:        document.getElementById('novo-cnpj')?.value.trim(),
        telefone:    document.getElementById('novo-telefone')?.value.trim(),
        email:       document.getElementById('novo-email')?.value.trim(),
        endereco:    document.getElementById('novo-endereco')?.value.trim(),
        link_bitrix: document.getElementById('novo-link-bitrix')?.value.trim(),
        id_bitrix:   document.getElementById('novo-id-bitrix')?.value.trim() || null,
        org_id:      document.getElementById('novo-org-id')?.value || null,
    };

    const obrigatorios = ['nome','cnpj','telefone','email','endereco','link_bitrix'];
    for (const c of obrigatorios) {
        if (!campos[c]) {
            const erro = document.getElementById('novo-cliente-erro');
            erro.textContent = `Campo obrigatório: ${c.replace(/_/g,' ')}`;
            erro.style.display = 'block';
            return;
        }
    }

    const cnpjInput  = document.getElementById('novo-cnpj');
    const telInput   = document.getElementById('novo-telefone');
    const emailInput = document.getElementById('novo-email');
    if (cnpjInput  && !_validarCNPJ(campos.cnpj))       { _erroInput(cnpjInput,  'CNPJ inválido');     return; }
    if (telInput   && !_validarTelefone(campos.telefone)){ _erroInput(telInput,   'Telefone inválido'); return; }
    if (emailInput && !_validarEmail(campos.email))      { _erroInput(emailInput, 'E-mail inválido');   return; }

    const msg = document.getElementById('save-msg');
    msg.textContent = 'Cadastrando...';

    fetch('/api/cliente-criar.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(campos)
    })
    .then(r => r.json())
    .then(res => {
        if (res.sucesso) {
            fecharPainel();
            // Mostra chave gerada antes de redirecionar
            if (res.chave_acesso) {
                const overlay = document.createElement('div');
                overlay.style.cssText = 'position:fixed;inset:0;background:rgba(6,25,32,.6);backdrop-filter:blur(4px);z-index:9998;display:flex;align-items:center;justify-content:center';
                overlay.innerHTML = `
                    <div style="background:#fff;border-radius:16px;padding:2rem;width:440px;max-width:92vw;box-shadow:0 24px 60px rgba(0,0,0,.25);animation:kwPop .18s ease">
                        <div style="width:48px;height:48px;border-radius:50%;background:#d1fae5;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;font-size:1.3rem;color:#065f46"><i class="fas fa-check-circle"></i></div>
                        <h3 style="text-align:center;font-family:'Rubik',sans-serif;font-size:1rem;font-weight:700;color:#1a202c;margin:0 0 .35rem">Cliente cadastrado!</h3>
                        <p style="text-align:center;font-size:.85rem;color:#718096;margin:0 0 .75rem">Chave de acesso gerada automaticamente:</p>
                        <div style="display:flex;align-items:center;gap:.5rem;background:#f8fafc;border-radius:8px;padding:.6rem .75rem;border:1px solid #e2e8f0;margin-bottom:1.25rem">
                            <span style="font-family:monospace;font-size:.82rem;color:#2d3748;word-break:break-all;flex:1">${_esc(res.chave_acesso)}</span>
                            <button onclick="copiarChaveApp('${_esc(res.chave_acesso)}')" style="background:#0DC2FF;color:#fff;border:none;border-radius:6px;padding:.35rem .65rem;font-size:.8rem;cursor:pointer;font-weight:600;flex-shrink:0"><i class="fas fa-copy"></i></button>
                        </div>
                        <button onclick="this.closest('[style*=fixed]').remove();window.location.href='?page=cadastro'"
                            style="width:100%;padding:.65rem;border:none;border-radius:8px;background:#0DC2FF;color:#fff;font-size:.875rem;cursor:pointer;font-weight:700">OK, ir para Cadastro</button>
                    </div>`;
                document.body.appendChild(overlay);
            } else {
                window.location.href = '?page=cadastro';
            }
        } else {
            const erro = document.getElementById('novo-cliente-erro');
            erro.textContent = res.erro || 'Erro ao cadastrar.';
            erro.style.display = 'block';
            msg.textContent = '';
        }
    })
    .catch(() => { msg.textContent = 'Erro de conexão.'; });
}

// ===== PREENCHER PAINEL =====

let _clienteOrgIdAtual = null;

function preencherPainel(c, apps) {
    document.getElementById('panel-avatar').textContent  = (c.nome || '--').substring(0, 2).toUpperCase();
    document.getElementById('panel-nome').textContent    = c.nome || '—';
    document.getElementById('panel-cnpj').textContent    = c.cnpj ? 'CNPJ: ' + c.cnpj : '';

    document.getElementById('pf-id').textContent         = c.id;
    document.getElementById('pf-nome').textContent       = c.nome         || '—';
    document.getElementById('pf-cnpj').textContent       = c.cnpj         || '—';
    document.getElementById('pf-telefone').textContent   = c.telefone     || '—';
    document.getElementById('pf-email').textContent      = c.email        || '—';
    document.getElementById('pf-endereco').textContent   = c.endereco     || '—';
    document.getElementById('pf-bitrix').textContent     = c.link_bitrix  || '—';
    document.getElementById('pf-id-bitrix').textContent  = c.id_bitrix    || '—';

    const _pfChaveWrap = document.getElementById('pf-chave-wrap');
    if (_pfChaveWrap) {
        if (c.chave_acesso) {
            const _cha = _esc(c.chave_acesso);
            _pfChaveWrap.innerHTML = `<div style="display:flex;align-items:center;gap:.5rem;background:#f8fafc;border-radius:6px;padding:.4rem .65rem;border:1px solid #e2e8f0"><span style="font-family:monospace;font-size:.78rem;color:#2d3748;word-break:break-all;flex:1">${_cha}</span><button onclick="copiarChaveApp('${_cha}')" title="Copiar chave de acesso" style="background:none;border:none;cursor:pointer;color:#0DC2FF;font-size:.8rem;padding:.1rem .25rem;flex-shrink:0"><i class="fas fa-copy"></i></button></div><button onclick="alterarChaveAcesso()" style="margin-top:.35rem;background:none;border:none;cursor:pointer;color:#718096;font-size:.75rem;padding:0;font-weight:600"><i class="fas fa-edit" style="margin-right:.25rem"></i>Alterar chave de acesso</button>`;
        } else {
            _pfChaveWrap.innerHTML = `<button onclick="gerarChaveAcesso()" style="background:none;border:1px solid #0DC2FF;color:#0DC2FF;border-radius:6px;padding:.3rem .7rem;font-size:.78rem;cursor:pointer;font-weight:600"><i class="fas fa-magic"></i> Gerar chave</button>`;
        }
    }

    _clienteOrgIdAtual = c.org_id || null;
    preencherOrgDropdown('pf-org-select', c.org_id);

    appsAtivas = apps || [];
    renderAppsAtivas(appsAtivas);

    // Resetar tabs da coluna direita
    _rightTabUsersLoaded = false;
    document.querySelectorAll('.right-tab-btn').forEach((b, i) => b.classList.toggle('active', i === 0));
    document.querySelectorAll('.right-tab-content').forEach((c, i) => c.classList.toggle('active', i === 0));
    const _aApps  = document.getElementById('right-tab-action-apps');
    const _aUsers = document.getElementById('right-tab-action-users');
    if (_aApps)  _aApps.style.display  = '';
    if (_aUsers) _aUsers.style.display = 'none';

    document.getElementById('panel-loading').style.display  = 'none';
    document.getElementById('panel-conteudo').style.display = 'block';
}

function switchRightTab(tab, btnEl) {
    document.querySelectorAll('.right-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.right-tab-content').forEach(c => c.classList.remove('active'));
    btnEl.classList.add('active');
    document.getElementById('right-tab-' + tab).classList.add('active');

    const aApps  = document.getElementById('right-tab-action-apps');
    const aUsers = document.getElementById('right-tab-action-users');
    if (aApps)  aApps.style.display  = tab === 'apps'  ? '' : 'none';
    if (aUsers) aUsers.style.display = tab === 'users' ? '' : 'none';

    if (tab === 'users' && !_rightTabUsersLoaded && clienteIdAtual) {
        carregarClienteUsuarios(clienteIdAtual);
        _rightTabUsersLoaded = true;
    }
}

function preencherOrgDropdown(selectId, orgIdSelecionado) {
    const sel = document.getElementById(selectId);
    if (!sel) return;
    fetch('/api/organizacoes.php?action=list', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(orgs => {
            sel.innerHTML = '<option value="">— Nenhuma —</option>';
            (orgs || []).forEach(o => {
                const opt = document.createElement('option');
                opt.value       = o.id;
                opt.textContent = o.nome + (o.ativo ? '' : ' (inativa)');
                if (String(o.id) === String(orgIdSelecionado)) opt.selected = true;
                sel.appendChild(opt);
            });
        })
        .catch(() => {});
}

function gerarChaveAcesso() {
    if (!clienteIdAtual) return;
    fetch('/api/cliente-gerar-chave.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cliente_id: clienteIdAtual })
    }).then(r => r.json()).then(data => {
        if (data.sucesso && data.chave_acesso) {
            const wrap = document.getElementById('pf-chave-wrap');
            if (wrap) {
                const cha = _esc(data.chave_acesso);
                wrap.innerHTML = `<div style="display:flex;align-items:center;gap:.5rem;background:#f8fafc;border-radius:6px;padding:.4rem .65rem;border:1px solid #e2e8f0"><span style="font-family:monospace;font-size:.78rem;color:#2d3748;word-break:break-all;flex:1">${cha}</span><button onclick="copiarChaveApp('${cha}')" title="Copiar chave de acesso" style="background:none;border:none;cursor:pointer;color:#0DC2FF;font-size:.8rem;padding:.1rem .25rem;flex-shrink:0"><i class="fas fa-copy"></i></button></div><button onclick="alterarChaveAcesso()" style="margin-top:.35rem;background:none;border:none;cursor:pointer;color:#718096;font-size:.75rem;padding:0;font-weight:600"><i class="fas fa-edit" style="margin-right:.25rem"></i>Alterar chave de acesso</button>`;
            }
            mostrarChaveGerada(data.chave_acesso, 'Cliente');
        } else {
            alert(data.erro || 'Erro ao gerar chave.');
        }
    }).catch(() => alert('Erro de conexão.'));
}

function alterarChaveAcesso() {
    if (!clienteIdAtual) return;
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(6,25,32,.55);backdrop-filter:blur(3px);z-index:9999;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:16px;padding:2rem;width:480px;max-width:92vw;box-shadow:0 24px 60px rgba(0,0,0,.25);animation:kwPop .18s ease">
            <div style="width:48px;height:48px;border-radius:50%;background:#fff8e1;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;font-size:1.25rem;color:#b7791f"><i class="fas fa-key"></i></div>
            <h3 style="text-align:center;font-size:1rem;font-weight:700;color:#1a202c;margin:0 0 .5rem">Alterar chave de acesso</h3>
            <p style="font-size:.82rem;color:#718096;text-align:center;margin:0 0 1.25rem">
                <strong style="color:#c53030">Atenção:</strong> todas as chaves das aplicações deste cliente serão regeneradas.
                As integrações no Bitrix24 precisarão ser atualizadas.
            </p>
            <div style="margin-bottom:1.1rem">
                <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Nova chave de acesso *</label>
                <input id="alterar-chave-input" type="text" placeholder="ex: empresa-nova-2025" class="form-input" style="font-family:monospace;font-size:.85rem">
                <div id="alterar-chave-erro" style="display:none;color:#c53030;font-size:.78rem;margin-top:.3rem"></div>
            </div>
            <div style="display:flex;gap:.75rem">
                <button id="alterar-chave-cancel" style="flex:1;padding:.6rem;border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#718096;font-size:.875rem;cursor:pointer;font-weight:600">Cancelar</button>
                <button id="alterar-chave-ok" style="flex:2;padding:.6rem;border:none;border-radius:8px;background:#c53030;color:#fff;font-size:.875rem;cursor:pointer;font-weight:700"><i class="fas fa-key"></i> Alterar e regenerar</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    const input     = overlay.querySelector('#alterar-chave-input');
    const erroEl    = overlay.querySelector('#alterar-chave-erro');
    const btnOk     = overlay.querySelector('#alterar-chave-ok');
    const btnCancel = overlay.querySelector('#alterar-chave-cancel');
    input.focus();

    const close = () => overlay.remove();
    btnCancel.onclick = close;
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });

    const submeter = () => {
        const novaChave = input.value.trim();
        if (!novaChave) {
            erroEl.textContent = 'Informe a nova chave de acesso.';
            erroEl.style.display = 'block';
            input.focus();
            return;
        }
        btnOk.disabled = true;
        btnOk.textContent = 'Salvando...';
        erroEl.style.display = 'none';

        fetch('/api/cliente-alterar-chave.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cliente_id: clienteIdAtual, nova_chave_acesso: novaChave })
        })
        .then(r => r.json())
        .then(data => {
            if (!data.sucesso) {
                btnOk.disabled = false;
                btnOk.innerHTML = '<i class="fas fa-key"></i> Alterar e regenerar';
                erroEl.textContent = data.erro || 'Erro ao alterar chave.';
                erroEl.style.display = 'block';
                return;
            }
            close();
            const wrap = document.getElementById('pf-chave-wrap');
            if (wrap) {
                const cha = _esc(novaChave);
                wrap.innerHTML = `<div style="display:flex;align-items:center;gap:.5rem;background:#f8fafc;border-radius:6px;padding:.4rem .65rem;border:1px solid #e2e8f0"><span style="font-family:monospace;font-size:.78rem;color:#2d3748;word-break:break-all;flex:1">${cha}</span><button onclick="copiarChaveApp('${cha}')" title="Copiar chave de acesso" style="background:none;border:none;cursor:pointer;color:#0DC2FF;font-size:.8rem;padding:.1rem .25rem;flex-shrink:0"><i class="fas fa-copy"></i></button></div><button onclick="alterarChaveAcesso()" style="margin-top:.35rem;background:none;border:none;cursor:pointer;color:#718096;font-size:.75rem;padding:0;font-weight:600"><i class="fas fa-edit" style="margin-right:.25rem"></i>Alterar chave de acesso</button>`;
            }
            mostrarNovasChaves(novaChave, data.chaves || []);
            fetch('/api/cliente-detalhe.php?id=' + clienteIdAtual, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => renderAppsAtivas(d.aplicacoes));
        })
        .catch(() => {
            btnOk.disabled = false;
            btnOk.innerHTML = '<i class="fas fa-key"></i> Alterar e regenerar';
            erroEl.textContent = 'Erro de conexão.';
            erroEl.style.display = 'block';
        });
    };

    btnOk.onclick = submeter;
    input.addEventListener('keydown', e => { if (e.key === 'Enter') submeter(); if (e.key === 'Escape') close(); });
}

function mostrarNovasChaves(novaChaveAcesso, chaves) {
    const rows = (chaves || []).map(c => `
        <tr>
            <td style="padding:.5rem .6rem;font-size:.8rem;color:#2d3748;border-bottom:1px solid #e2e8f0">${_esc(c.app_nome)}</td>
            <td style="padding:.5rem .6rem;font-size:.8rem;color:#718096;border-bottom:1px solid #e2e8f0">${_esc(c.descricao || '—')}</td>
            <td style="padding:.5rem .6rem;border-bottom:1px solid #e2e8f0">
                <div style="display:flex;align-items:center;gap:.35rem">
                    <span style="font-family:monospace;font-size:.75rem;color:#2d3748;word-break:break-all">${_esc(c.nova_chave)}</span>
                    <button onclick="copiarChaveApp('${_esc(c.nova_chave)}')" style="flex-shrink:0;background:none;border:none;cursor:pointer;color:#0DC2FF;font-size:.75rem;padding:.1rem .2rem"><i class="fas fa-copy"></i></button>
                </div>
            </td>
        </tr>`).join('');

    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(6,25,32,.6);backdrop-filter:blur(4px);z-index:9998;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:16px;padding:2rem;width:600px;max-width:94vw;max-height:80vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.25);animation:kwPop .18s ease">
            <div style="width:48px;height:48px;border-radius:50%;background:#d1fae5;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;font-size:1.3rem;color:#065f46"><i class="fas fa-check-circle"></i></div>
            <h3 style="text-align:center;font-size:1rem;font-weight:700;color:#1a202c;margin:0 0 .35rem">Chave alterada com sucesso</h3>
            <p style="text-align:center;font-size:.82rem;color:#718096;margin:0 0 1.25rem">Nova chave de acesso: <strong style="font-family:monospace;color:#2d3748">${_esc(novaChaveAcesso)}</strong></p>
            ${chaves && chaves.length > 0 ? `
            <p style="font-size:.78rem;color:#718096;margin:0 0 .5rem">Novas chaves por aplicação — atualize as integrações no Bitrix24:</p>
            <table style="width:100%;border-collapse:collapse">
                <thead><tr>
                    <th style="text-align:left;font-size:.68rem;color:#a0aec0;text-transform:uppercase;padding:.4rem .6rem;border-bottom:2px solid #e2e8f0">App</th>
                    <th style="text-align:left;font-size:.68rem;color:#a0aec0;text-transform:uppercase;padding:.4rem .6rem;border-bottom:2px solid #e2e8f0">Descrição</th>
                    <th style="text-align:left;font-size:.68rem;color:#a0aec0;text-transform:uppercase;padding:.4rem .6rem;border-bottom:2px solid #e2e8f0">Nova Chave</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>` : '<p style="text-align:center;color:#a0aec0;font-size:.85rem">Nenhuma aplicação ativa para este cliente.</p>'}
            <button onclick="this.closest('[style*=fixed]').remove()"
                style="margin-top:1.25rem;width:100%;padding:.65rem;border:none;border-radius:8px;background:#0DC2FF;color:#fff;font-size:.875rem;cursor:pointer;font-weight:700">Fechar</button>
        </div>`;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
}

function orgDropdownChange(val) {
    edicoesPendentes['org_id'] = val || null;
    document.getElementById('panel-save-bar').classList.add('visivel');
}

// ===== USUÁRIOS DO CLIENTE =====

function carregarClienteUsuarios(clienteId) {
    const lista = document.getElementById('panel-usuarios-lista');
    if (!lista) return;
    lista.innerHTML = '<p style="color:#a0aec0;font-size:.8rem">Carregando...</p>';
    fetch('/api/cliente-usuarios.php?cliente_id=' + clienteId, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => renderClienteUsuarios(data.usuarios || []))
        .catch(() => { lista.innerHTML = '<p style="color:#c53030;font-size:.8rem">Erro ao carregar.</p>'; });
}

function renderClienteUsuarios(usuarios) {
    const lista = document.getElementById('panel-usuarios-lista');
    if (!lista) return;
    if (!usuarios.length) {
        lista.innerHTML = '<p style="color:#a0aec0;font-size:.82rem">Nenhum usuário vinculado.</p>';
        return;
    }
    lista.innerHTML = usuarios.map(u => `
        <div style="display:flex;align-items:center;gap:.5rem;padding:.45rem .5rem;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0;margin-bottom:.4rem">
            <div style="width:30px;height:30px;border-radius:50%;background:#0DC2FF;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0">${_esc(u.nome.substring(0,2).toUpperCase())}</div>
            <div style="flex:1;min-width:0">
                <div style="font-size:.82rem;font-weight:600;color:#2d3748;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${_esc(u.nome)}</div>
                <div style="font-size:.72rem;color:#a0aec0;font-family:monospace">@${_esc(u.username)}</div>
            </div>
            <button onclick="desvincularUsuarioCliente(${u.id},'${_esc(u.nome)}')"
                style="background:none;border:none;cursor:pointer;color:#a0aec0;font-size:.75rem;padding:.2rem .35rem;border-radius:4px;flex-shrink:0"
                title="Desvincular" onmouseover="this.style.color='#c53030'" onmouseout="this.style.color='#a0aec0'">
                <i class="fas fa-unlink"></i>
            </button>
        </div>`).join('');
}

function abrirVincularUsuario() {
    if (!clienteIdAtual) return;
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(6,25,32,.55);backdrop-filter:blur(3px);z-index:9999;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:16px;padding:2rem;width:440px;max-width:92vw;box-shadow:0 24px 60px rgba(0,0,0,.25);animation:kwPop .18s ease">
            <h3 style="font-size:1rem;font-weight:700;color:#1a202c;margin:0 0 1rem">Vincular usuário</h3>
            <div style="margin-bottom:1rem">
                <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Usuário *</label>
                <select id="vuz-select" class="form-input" style="font-size:.85rem">
                    <option value="">Carregando...</option>
                </select>
            </div>
            <div id="vuz-erro" style="display:none;color:#c53030;font-size:.78rem;margin-bottom:.5rem"></div>
            <div style="display:flex;gap:.75rem">
                <button id="vuz-cancel" style="flex:1;padding:.6rem;border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#718096;font-size:.875rem;cursor:pointer;font-weight:600">Cancelar</button>
                <button id="vuz-ok" style="flex:2;padding:.6rem;border:none;border-radius:8px;background:#0DC2FF;color:#fff;font-size:.875rem;cursor:pointer;font-weight:700"><i class="fas fa-link"></i> Vincular</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    const sel    = overlay.querySelector('#vuz-select');
    const erroEl = overlay.querySelector('#vuz-erro');
    const close  = () => overlay.remove();
    overlay.querySelector('#vuz-cancel').onclick = close;
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });

    fetch('/api/cliente-usuarios.php?cliente_id=' + clienteIdAtual + '&todos=1', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            const users = data.usuarios || [];
            if (!users.length) {
                sel.innerHTML = '<option value="">Nenhum usuário disponível</option>';
                overlay.querySelector('#vuz-ok').disabled = true;
                return;
            }
            sel.innerHTML = '<option value="">— Selecione —</option>' +
                users.map(u => `<option value="${u.id}">${_esc(u.nome)} (@${_esc(u.username)})</option>`).join('');
        }).catch(() => { sel.innerHTML = '<option value="">Erro ao carregar</option>'; });

    overlay.querySelector('#vuz-ok').onclick = () => {
        const uid = parseInt(sel.value, 10);
        if (!uid) { erroEl.textContent = 'Selecione um usuário.'; erroEl.style.display = 'block'; return; }
        const btn = overlay.querySelector('#vuz-ok');
        btn.disabled = true; btn.textContent = 'Vinculando...';
        fetch('/api/cliente-vincular-usuario.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cliente_id: clienteIdAtual, usuario_id: uid })
        })
        .then(r => r.json())
        .then(data => {
            if (!data.sucesso) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-link"></i> Vincular'; erroEl.textContent = data.erro || 'Erro.'; erroEl.style.display = 'block'; return; }
            close();
            carregarClienteUsuarios(clienteIdAtual);
        })
        .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-link"></i> Vincular'; erroEl.textContent = 'Erro de conexão.'; erroEl.style.display = 'block'; });
    };
}

async function desvincularUsuarioCliente(usuarioId, nome) {
    if (!clienteIdAtual) return;
    const ok = await kwConfirm(`Desvincular "${nome}" deste cliente?`, 'Desvincular usuário');
    if (!ok) return;
    fetch('/api/cliente-desvincular-usuario.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cliente_id: clienteIdAtual, usuario_id: usuarioId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) carregarClienteUsuarios(clienteIdAtual);
        else alert(data.erro || 'Erro ao desvincular.');
    });
}

function _esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function _maskWebhook(url) {
    if (!url) return '—';
    try {
        const u = new URL(url);
        return _esc(u.protocol + '//' + u.hostname) + '/••••••••';
    } catch { return '••••••••'; }
}

function _chaveDisplay(chave) {
    if (!chave) return '—';
    if (chave.length <= 5) return `<strong style="color:#0DC2FF">${_esc(chave)}</strong>`;
    return _esc(chave.slice(0, -5)) + `<strong style="color:#0DC2FF">${_esc(chave.slice(-5))}</strong>`;
}

function renderAppsAtivas(apps) {
    appsAtivas = apps || [];
    const lista = document.getElementById('panel-apps-lista');
    if (!lista) return;

    if (!apps || !apps.length) {
        lista.innerHTML = '<p style="color:#a0aec0;font-size:.85rem">Nenhuma aplicação ativa.<br>Clique em <strong>Ativar</strong> para adicionar.</p>';
        _appFiltroAtual = null;
        return;
    }

    // Descrições reais únicas (ordem = primeira ocorrência em apps) + grupo "(Sem descrição)"
    // para apps com descricao nula/vazia. Grupos reais sempre vêm antes de "Sem descrição".
    const SEM_DESCRICAO = '__sem_descricao__';
    const descs = [...new Set(apps.filter(a => a.descricao).map(a => a.descricao))];
    const temSemDescricao = apps.some(a => !a.descricao);
    const tabs = temSemDescricao ? [...descs, SEM_DESCRICAO] : descs;

    // Default = primeiro tab real (ou "Sem descrição" se não houver nenhuma descrição).
    // Aplica-se identicamente a todos os clientes — nada específico por cliente aqui.
    if (_appFiltroAtual === null || !tabs.includes(_appFiltroAtual)) {
        _appFiltroAtual = tabs[0];
    }

    const _pill = (active) =>
        `padding:.3rem .75rem;border:1px solid ${active ? '#0DC2FF' : '#e2e8f0'};border-radius:20px;background:${active ? '#0DC2FF' : '#fff'};color:${active ? '#fff' : '#718096'};font-size:.75rem;font-weight:600;cursor:pointer`;

    // Pills de filtro — só exibe quando há mais de 1 tab (com 1 único tab, todos os apps já
    // pertencem a ele, então o filtro não teria efeito visível).
    let filterHtml = '';
    if (tabs.length > 1) {
        filterHtml = `<div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.75rem">
            ${tabs.map(t => `<button data-filter-desc="${_esc(t)}" style="${_pill(_appFiltroAtual === t)}">${t === SEM_DESCRICAO ? '(Sem descrição)' : _esc(t)}</button>`).join('')}
        </div>`;
    }

    // Aplica filtro
    const filtered = _appFiltroAtual === SEM_DESCRICAO
        ? apps.filter(a => !a.descricao)
        : apps.filter(a => a.descricao === _appFiltroAtual);

    const cardsHtml = filtered.map(a => `
        <div class="app-card" data-app-caid="${a.ca_id}" style="${!a.ativo ? 'opacity:.55;filter:grayscale(.5)' : ''}">
            <div class="app-card-icon"><i class="${iconeApp[a.slug] || 'fas fa-puzzle-piece'}"></i></div>
            <div class="app-card-info">
                <div class="app-card-name">${_esc(a.nome)}${a.descricao ? ' <small style="color:#a0aec0;font-weight:400">· ' + _esc(a.descricao) + '</small>' : ''}</div>
                <div class="app-card-slug">${_esc(a.slug)}</div>
                ${a.created_at ? `<div style="font-size:.7rem;color:#a0aec0;margin-top:.15rem">Ativo desde ${_formatDate(a.created_at)}</div>` : ''}
                ${a.chave ? `<div style="display:flex;align-items:center;gap:.35rem;margin-top:.25rem">
                    <span style="font-family:monospace;font-size:.7rem;background:#f0f4f8;padding:.1rem .4rem;border-radius:4px;letter-spacing:.03em;color:#718096">${_chaveDisplay(a.chave)}</span>
                    <button onclick="event.stopPropagation();copiarChaveApp('${_esc(a.chave)}')" title="Copiar chave" style="background:none;border:none;cursor:pointer;color:#0DC2FF;font-size:.75rem;padding:.1rem .2rem"><i class="fas fa-copy"></i></button>
                </div>` : ''}
            </div>
            ${a.ativo
                ? '<span class="badge-app">Ativo</span>'
                : '<span style="font-size:.7rem;font-weight:600;color:#a0aec0;background:#f0f4f8;padding:.2rem .6rem;border-radius:20px">Bloqueado</span>'}
        </div>`).join('');

    lista.innerHTML = filterHtml + cardsHtml;

    // Listeners das pills — comportamento de aba (sempre uma selecionada, sem toggle-off).
    lista.querySelectorAll('[data-filter-desc]').forEach(pill => {
        pill.addEventListener('click', () => {
            _appFiltroAtual = pill.getAttribute('data-filter-desc');
            renderAppsAtivas(appsAtivas);
        });
    });

    // Listeners dos cards (por ca_id para funcionar com filtro ativo)
    lista.querySelectorAll('.app-card').forEach(card => {
        card.addEventListener('click', () => {
            const caId = card.getAttribute('data-app-caid');
            const app  = appsAtivas.find(a => String(a.ca_id) === String(caId));
            if (app) abrirModalApp(app);
        });
    });

}

function copiarChaveApp(chave) {
    navigator.clipboard.writeText(chave).then(() => {
        // feedback temporário
        const tmp = document.createElement('div');
        tmp.textContent = '✓ Chave copiada';
        tmp.style.cssText = 'position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);background:#1a202c;color:#fff;padding:.5rem 1.1rem;border-radius:8px;font-size:.82rem;z-index:9999;pointer-events:none;opacity:1;transition:opacity .4s';
        document.body.appendChild(tmp);
        setTimeout(() => { tmp.style.opacity = '0'; setTimeout(() => tmp.remove(), 400); }, 1800);
    });
}

// ===== MODAL CONFIG APP =====

function abrirModalApp(app) {
    // Relatórios BI — acesso controlado por aplicação, modal próprio (sem webhook/valor).
    if (app.slug === 'relatorios-bi') { abrirModalBiAcesso(app.nome); return; }

    document.getElementById('app-modal-icon').innerHTML    = `<i class="${iconeApp[app.slug] || 'fas fa-puzzle-piece'}"></i>`;
    document.getElementById('app-modal-nome').textContent  = app.nome;
    document.getElementById('app-modal-slug').textContent  = app.slug + (app.created_at ? ` · Ativo desde ${_formatDate(app.created_at)}` : '');
    // Roteamento por slug — apps com config específica
    let configHtml = '';
    if (app.slug === 'BancoDados' && typeof renderBancoDados === 'function') {
        bdInicializar(app, clienteIdAtual);
        configHtml = renderBancoDados(app, clienteIdAtual);
    } else if (app.slug === 'arkivu' && typeof renderArkivu === 'function') {
        configHtml = renderArkivu(app, clienteIdAtual);
    }

    // Chave de acesso desta instância (read-only)
    const chaveHtml = app.chave ? `
        <div style="margin-bottom:1.5rem;padding:.9rem 1rem;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0">
            <label style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#a0aec0;display:block;margin-bottom:.5rem">
                <i class="fas fa-key" style="margin-right:.3rem;color:#0DC2FF"></i> Chave de acesso
            </label>
            <div style="display:flex;align-items:center;gap:.5rem">
                <span style="font-family:monospace;font-size:.8rem;color:#2d3748;word-break:break-all;flex:1">${_chaveDisplay(app.chave)}</span>
                <button onclick="copiarChaveApp('${_esc(app.chave)}')" title="Copiar chave"
                    style="flex-shrink:0;background:#0DC2FF;color:#fff;border:none;border-radius:6px;padding:.4rem .7rem;font-size:.8rem;cursor:pointer;font-weight:600">
                    <i class="fas fa-copy"></i>
                </button>
            </div>
        </div>` : '';

    // Configuração da integração
    const integracaoHtml = `
        <div style="margin-bottom:1.5rem">
            <span style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#a0aec0;display:block;margin-bottom:1rem">Configuração da integração</span>
            <div style="display:grid;gap:1rem">
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Descrição *</label>
                    <input id="app-descricao-input" type="text" class="form-input" value="${_esc(app.descricao || '')}" maxlength="80" placeholder="Ex: Comercial, Operacional">
                </div>
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Webhook Bitrix24</label>
                    ${app.webhook_bitrix ? `<div style="display:flex;align-items:center;gap:.5rem;background:#f0f4f8;border:1px solid #e2e8f0;border-radius:6px;padding:.4rem .7rem;margin-bottom:.4rem"><span style="font-family:monospace;font-size:.75rem;color:#718096;flex:1">${_maskWebhook(app.webhook_bitrix)}</span></div>` : ''}
                    <input id="app-webhook-input" type="text" class="form-input" value="" placeholder="${app.webhook_bitrix ? 'Novo valor (deixe vazio para não alterar)' : 'https://...'}">
                </div>
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Valor (R$)</label>
                    <input id="app-valor-input" type="number" step="0.01" min="0" class="form-input" value="${app.valor || ''}" placeholder="0,00">
                </div>
                <div>
                    <button onclick="salvarDadosApp(${clienteIdAtual}, ${app.ca_id})"
                        style="background:#0DC2FF;color:#fff;border:none;border-radius:8px;padding:.6rem 1.25rem;font-size:.875rem;cursor:pointer;font-weight:600">
                        <i class="fas fa-check"></i> Salvar integração
                    </button>
                    <span id="app-integracao-msg" style="display:inline-block;font-size:.8rem;color:#718096;margin-left:.6rem"></span>
                </div>
            </div>
        </div>`;

    // Rodapé: toggle + desativar
    const acoes = `
        <div style="border-top:1px solid #e2e8f0;padding-top:1.1rem;margin-top:.5rem;display:flex;align-items:center;justify-content:space-between">
            <label class="toggle-switch" onclick="bloquearApp(${app.ca_id},'${app.nome.replace(/'/g,"\\'")}',${app.ativo});event.preventDefault()">
                <input type="checkbox" ${app.ativo ? 'checked' : ''} readonly>
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-label">${app.ativo ? 'Aplicação ativa' : 'Aplicação bloqueada'}</span>
            </label>
            <button onclick="desativarApp(${app.ca_id},'${app.nome.replace(/'/g,"\\'")}')"
                style="padding:.5rem .9rem;border:1px solid #fed7d7;border-radius:8px;background:#fff;color:#c53030;font-size:.8rem;font-weight:600;cursor:pointer">
                <i class="fas fa-trash"></i> Desativar
            </button>
        </div>`;

    // Nimbus Partners — formulário customizado substitui integracaoHtml
    if (app.slug === 'nimbus_parceiros') {
        const extra = typeof app.config_extra === 'string'
            ? JSON.parse(app.config_extra || '{}')
            : (app.config_extra || {});
        const diasAtivos = Array.isArray(extra.dias_semana) ? extra.dias_semana : [];
        const diasOpts = [['Dom',0],['Seg',1],['Ter',2],['Qua',3],['Qui',4],['Sex',5],['Sáb',6]];
        const diasHtml = diasOpts.map(([label, val]) =>
            `<label style="display:inline-flex;align-items:center;gap:.3rem;margin-right:.5rem;font-size:.83rem;cursor:pointer"><input type="checkbox" name="nimbus-dia" value="${val}"${diasAtivos.includes(val) ? ' checked' : ''}> ${label}</label>`
        ).join('');

        const nimbusIntegracao = `
            <div style="margin-bottom:1.5rem">
                <span style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#a0aec0;display:block;margin-bottom:1rem">Configuração da integração</span>
                <div style="display:grid;gap:1rem">
                    <div>
                        <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Descrição *</label>
                        <input id="app-descricao-input" type="text" class="form-input" value="${_esc(app.descricao || '')}" maxlength="80" placeholder="Ex: Comercial, Operacional">
                    </div>
                    <div>
                        <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Webhook Bitrix24</label>
                        ${app.webhook_bitrix ? `<div style="display:flex;align-items:center;gap:.5rem;background:#f0f4f8;border:1px solid #e2e8f0;border-radius:6px;padding:.4rem .7rem;margin-bottom:.4rem"><span style="font-family:monospace;font-size:.75rem;color:#718096;flex:1">${_maskWebhook(app.webhook_bitrix)}</span></div>` : ''}
                        <input id="app-webhook-input" type="text" class="form-input" value="" placeholder="${app.webhook_bitrix ? 'Novo valor (deixe vazio para não alterar)' : 'https://...'}">
                    </div>
                    <div>
                        <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Valor (R$)</label>
                        <input id="app-valor-input" type="number" step="0.01" min="0" class="form-input" value="${app.valor || ''}" placeholder="0,00">
                    </div>
                </div>
            </div>`;

        const nimbusAgendamento = `
            <div style="margin-bottom:1.5rem">
                <span style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#a0aec0;display:block;margin-bottom:1rem">Agendamento</span>
                <div style="display:grid;gap:1rem">
                    <div>
                        <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.5rem">Dias da semana</label>
                        <div style="display:flex;flex-wrap:wrap;gap:.2rem">${diasHtml}</div>
                    </div>
                    <div>
                        <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Horário</label>
                        <input id="nimbus-horario-input" type="time" class="form-input" value="${_esc(extra.horario || '')}" style="max-width:10rem">
                    </div>
                    <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
                        <button onclick="salvarNimbus(${app.ca_id})"
                            style="background:#0DC2FF;color:#fff;border:none;border-radius:8px;padding:.6rem 1.25rem;font-size:.875rem;cursor:pointer;font-weight:600">
                            <i class="fas fa-check"></i> Salvar integração
                        </button>
                        <button id="nimbus-disparar-btn" onclick="dispararNimbus(${app.ca_id})"
                            style="background:#065f46;color:#fff;border:none;border-radius:8px;padding:.6rem 1.25rem;font-size:.875rem;cursor:pointer;font-weight:600">
                            <i class="fas fa-bolt"></i> Disparar agora
                        </button>
                        <span id="app-integracao-msg" style="display:inline-block;font-size:.8rem;color:#718096"></span>
                    </div>
                </div>
            </div>`;

        document.getElementById('app-modal-body').innerHTML = chaveHtml + nimbusIntegracao + nimbusAgendamento + acoes;
        document.getElementById('app-config-overlay').classList.add('open');
        document.getElementById('app-config-modal').classList.add('open');
        return;
    }

    document.getElementById('app-modal-body').innerHTML = chaveHtml + integracaoHtml + configHtml + acoes;
    document.getElementById('app-config-overlay').classList.add('open');
    document.getElementById('app-config-modal').classList.add('open');
}

async function bloquearApp(caId, appNome, ativo) {
    const msg = ativo
        ? `Bloquear "${appNome}" para este cliente?\nA app ficará registrada mas inativa.`
        : `Desbloquear "${appNome}" para este cliente?`;
    const ok = await kwConfirm(msg, ativo ? 'Bloquear aplicação' : 'Desbloquear aplicação', ativo ? 'danger' : 'success');
    if (!ok) return;

    fetch('/api/cliente-bloquear-app.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cliente_id: clienteIdAtual, ca_id: caId, ativo: !ativo })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.sucesso) { alert(data.erro || 'Erro.'); return; }

        const idx = appsAtivas.findIndex(a => String(a.ca_id) === String(caId));
        if (idx !== -1) appsAtivas[idx].ativo = !ativo;

        const toggle = document.querySelector('#app-config-modal .toggle-switch input');
        const label  = document.querySelector('#app-config-modal .toggle-label');
        if (toggle) toggle.checked = !ativo;
        if (label)  label.textContent = !ativo ? 'Aplicação ativa' : 'Aplicação bloqueada';

        renderAppsAtivas(appsAtivas);
    });
}

async function desativarApp(caId, appNome) {
    const ok = await kwConfirm(
        `Desativar "${appNome}"?\n\nA configuração será removida permanentemente.`,
        'Desativar aplicação'
    );
    if (!ok) return;

    fetch('/api/cliente-desativar-app.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cliente_id: clienteIdAtual, ca_id: caId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            fecharModalApp();
            fetch('/api/cliente-detalhe.php?id=' + clienteIdAtual, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => renderAppsAtivas(d.aplicacoes));
        } else { alert(data.erro || 'Erro.'); }
    });
}

function salvarDadosApp(clienteId, caId) {
    const webhook   = document.getElementById('app-webhook-input')?.value.trim();
    const valor     = document.getElementById('app-valor-input')?.value;
    const descricao = document.getElementById('app-descricao-input')?.value.trim();
    const msg       = document.getElementById('app-integracao-msg');

    if (!descricao) {
        const inp = document.getElementById('app-descricao-input');
        if (inp) { inp.style.borderColor = '#c53030'; inp.focus(); }
        if (msg) msg.textContent = 'Descrição é obrigatória.';
        return;
    }

    if (msg) msg.textContent = 'Salvando...';

    fetch('/api/cliente-app-atualizar.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cliente_id: clienteId, ca_id: caId, webhook_bitrix: webhook, valor: valor || null, descricao: descricao })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            const app = appsAtivas.find(a => String(a.ca_id) === String(caId));
            if (app) { app.descricao = descricao; renderAppsAtivas(appsAtivas); }
            if (msg) { msg.textContent = '✓ Salvo'; setTimeout(() => { if (msg) msg.textContent = ''; }, 2500); }
        } else {
            if (msg) msg.textContent = data.erro || 'Erro.';
        }
    })
    .catch(() => { if (msg) msg.textContent = 'Erro de conexão.'; });
}

function fecharModalApp() {
    document.getElementById('app-config-overlay').classList.remove('open');
    document.getElementById('app-config-modal').classList.remove('open');
}

// ===== MODAL RELATÓRIOS BI (acesso por cliente, per-report per-user) =====
// Reaproveita o overlay/modal padrão (#app-config-overlay/#app-config-modal), mas com
// conteúdo próprio — sem webhook/valor, pois esta "aplicação" é só controle de acesso.
// Interação segue o mesmo padrão do modal BancoDados (Consultas Configuradas /
// Adicionar Consulta — ver app-bancodados.js): estado fica em memória (biRelatorios) e só
// é persistido no servidor ao clicar em "Salvar" (bulk replace), não código compartilhado.

function abrirModalBiAcesso(appNome) {
    document.getElementById('app-modal-icon').innerHTML    = `<i class="${iconeApp['relatorios-bi']}"></i>`;
    document.getElementById('app-modal-nome').textContent  = appNome || 'Relatórios BI';
    document.getElementById('app-modal-slug').textContent  = 'relatorios-bi';
    document.getElementById('app-modal-body').innerHTML    = '<div class="panel-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';
    document.getElementById('app-config-overlay').classList.add('open');
    document.getElementById('app-config-modal').classList.add('open');

    fetch('/api/relatorio-acesso.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get', cliente_id: clienteIdAtual })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.sucesso) {
            document.getElementById('app-modal-body').innerHTML =
                `<p style="color:#c53030">${_esc(data.erro || 'Erro ao carregar.')}</p>`;
            return;
        }
        renderBiAcessoModal(data);
    })
    .catch(() => {
        document.getElementById('app-modal-body').innerHTML = '<p style="color:#c53030">Erro de conexão.</p>';
    });
}

function renderBiAcessoModal(data) {
    biCatalogo        = data.catalogo || [];
    biRelatorios      = JSON.parse(JSON.stringify(data.relatorios || [])); // cópia local editável
    biUsuariosCliente = data.usuarios || [];
    biEditandoSlug    = null;

    document.getElementById('app-modal-body').innerHTML = `
        <div style="margin-bottom:1.5rem">
            <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Descrição *</label>
            <input id="bi-descricao-input" type="text" class="form-input" value="${_esc(data.descricao || '')}" maxlength="80" placeholder="Ex: Comercial, Operacional">
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
            <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#a0aec0">Relatórios configurados</span>
            <button onclick="biAbrirForm()" class="btn-primary" style="padding:.35rem .8rem;font-size:.8rem">
                <i class="fas fa-plus"></i> Adicionar Relatório
            </button>
        </div>

        <div id="bi-relatorios-lista"></div>

        <!-- Formulário adicionar/editar relatório (compartilhado, como bd-form em app-bancodados.js) -->
        <div id="bi-form" style="display:none;border:1px dashed #0DC2FF;border-radius:8px;padding:1rem;background:#f0f9ff;margin-top:.75rem">
            <p id="bi-form-titulo" style="font-size:.8rem;font-weight:700;color:#086B8D;margin-bottom:.75rem">
                <i class="fas fa-plus-circle"></i> Adicionar Relatório
            </p>
            <div style="display:grid;gap:.65rem">
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.25rem">Relatório *</label>
                    <select id="bi-relatorio-select" class="form-input">
                        <option value="">Selecione...</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.4rem">Acesso de usuários</label>
                    <div id="bi-form-usuarios-lista"></div>
                </div>
                <div id="bi-form-erro" style="color:#e53e3e;font-size:.8rem;display:none"></div>
                <div style="display:flex;gap:.6rem;justify-content:flex-end">
                    <button onclick="biFecharForm()" class="btn-cancelar-edit" style="padding:.45rem .9rem;font-size:.82rem">Cancelar</button>
                    <button onclick="biSalvarEntrada()" class="btn-primary" style="padding:.45rem .9rem;font-size:.82rem">
                        <i class="fas fa-check"></i> <span id="bi-btn-salvar-label">Adicionar</span>
                    </button>
                </div>
            </div>
        </div>

        <div style="border-top:1px solid #e2e8f0;padding-top:1.1rem;margin-top:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem">
            <button onclick="desativarBiAcesso()"
                style="padding:.5rem .9rem;border:1px solid #fed7d7;border-radius:8px;background:#fff;color:#c53030;font-size:.8rem;font-weight:600;cursor:pointer">
                <i class="fas fa-ban"></i> Desativar
            </button>
            <div style="display:flex;align-items:center;gap:.75rem">
                <button onclick="salvarBiAcesso()"
                    style="background:#0DC2FF;color:#fff;border:none;border-radius:8px;padding:.6rem 1.25rem;font-size:.875rem;cursor:pointer;font-weight:600">
                    <i class="fas fa-check"></i> Salvar
                </button>
                <span id="bi-acesso-msg" style="font-size:.8rem;color:#718096"></span>
            </div>
        </div>`;

    biRenderLista();
}

// ── Cards de relatório configurado ──────────────────────────────────────────
function biRenderLista() {
    const lista = document.getElementById('bi-relatorios-lista');
    if (!lista) return;
    lista.innerHTML = biRelatorios.length
        ? biRelatorios.map((r, i) => _biCardHtml(r, i)).join('')
        : '<p style="color:#a0aec0;font-size:.85rem;text-align:center;padding:1rem 0">Nenhum relatório configurado ainda.</p>';
}

function _biCardHtml(rel, index) {
    const nVer    = (rel.permissoes || []).filter(p => p.pode_ver).length;
    const nPortal = (rel.permissoes || []).filter(p => p.pode_criar_portal).length;
    return `
        <div style="border:1px solid #e2e8f0;border-radius:8px;padding:.85rem 1rem;margin-bottom:.6rem;background:#f8fafc">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem">
                <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:.9rem;color:#2d3748">
                        <i class="fas fa-chart-bar" style="color:#0DC2FF;margin-right:.4rem"></i>
                        ${_esc(rel.nome_amigavel)}
                    </div>
                    <div style="font-size:.78rem;color:#a0aec0;margin-top:.25rem">
                        ${nVer} usuário(s) com acesso · ${nPortal} pode(m) criar portal
                    </div>
                </div>
                <div style="display:flex;gap:.4rem;flex-shrink:0">
                    <button onclick="biEditarRelatorio(${index})"
                        style="border:none;background:#e9f5ff;color:#086B8D;border-radius:6px;padding:.3rem .6rem;cursor:pointer;font-size:.75rem">
                        <i class="fas fa-pencil-alt"></i>
                    </button>
                    <button onclick="biRemoverRelatorio(${index})"
                        style="border:none;background:#fee2e2;color:#c53030;border-radius:6px;padding:.3rem .6rem;cursor:pointer;font-size:.75rem">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>`;
}

// ── Formulário adicionar/editar (compartilhado) ─────────────────────────────
function biAbrirForm() {
    biEditandoSlug = null;
    document.getElementById('bi-form-titulo').innerHTML        = '<i class="fas fa-plus-circle"></i> Adicionar Relatório';
    document.getElementById('bi-btn-salvar-label').textContent = 'Adicionar';
    document.getElementById('bi-form').style.display           = 'block';
    document.getElementById('bi-form-erro').style.display      = 'none';

    // Só relatórios ainda não configurados para este cliente aparecem no dropdown.
    const jaConfigurados = new Set(biRelatorios.map(r => r.slug));
    const disponiveis    = biCatalogo.filter(r => !jaConfigurados.has(r.slug));
    const sel = document.getElementById('bi-relatorio-select');
    sel.disabled  = false;
    sel.innerHTML = '<option value="">Selecione...</option>' +
        disponiveis.map(r => `<option value="${_esc(r.slug)}">${_esc(r.nome_amigavel)}</option>`).join('');

    biRenderFormUsuarios([]);
}

function biEditarRelatorio(index) {
    const rel = biRelatorios[index];
    if (!rel) return;
    biEditandoSlug = rel.slug;

    document.getElementById('bi-form-titulo').innerHTML        = '<i class="fas fa-pencil-alt"></i> Editar Relatório';
    document.getElementById('bi-btn-salvar-label').textContent = 'Salvar';
    document.getElementById('bi-form').style.display           = 'block';
    document.getElementById('bi-form-erro').style.display      = 'none';

    // Relatório fixo durante a edição — trocar de relatório exige remover e adicionar de novo.
    const sel = document.getElementById('bi-relatorio-select');
    sel.innerHTML = `<option value="${_esc(rel.slug)}">${_esc(rel.nome_amigavel)}</option>`;
    sel.value    = rel.slug;
    sel.disabled = true;

    biRenderFormUsuarios(rel.permissoes || []);
}

function biRenderFormUsuarios(permissoesAtuais) {
    const mapa = {};
    (permissoesAtuais || []).forEach(p => { mapa[p.usuario_id] = p; });

    const lista = document.getElementById('bi-form-usuarios-lista');
    lista.innerHTML = biUsuariosCliente.length
        ? biUsuariosCliente.map(u => {
            const p = mapa[u.usuario_id] || {};
            return `
            <div class="bi-form-usuario-row" data-uid="${u.usuario_id}" style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.4rem 0;border-bottom:1px solid #e2e8f0">
                <span style="font-size:.82rem;color:#2d3748;flex:1">${_esc(u.nome)} <small style="color:#a0aec0">@${_esc(u.username)}</small></span>
                <label style="display:flex;align-items:center;gap:.35rem;font-size:.75rem;color:#718096;cursor:pointer;white-space:nowrap">
                    <input type="checkbox" class="bi-form-ver-chk" ${p.pode_ver ? 'checked' : ''} onchange="biSyncVerCriar(this)"> Ver
                </label>
                <label style="display:flex;align-items:center;gap:.35rem;font-size:.75rem;color:#718096;cursor:pointer;white-space:nowrap">
                    <input type="checkbox" class="bi-form-portal-chk" ${p.pode_criar_portal ? 'checked' : ''} onchange="biSyncVerCriar(this)"> Criar portal
                </label>
            </div>`;
        }).join('')
        : '<p style="color:#a0aec0;font-size:.8rem">Nenhum usuário vinculado a este cliente.</p>';

    // Aplica a regra "Criar portal" → "Ver" travado no estado inicial.
    lista.querySelectorAll('.bi-form-portal-chk').forEach(chk => biSyncVerCriar(chk));
}

function biSyncVerCriar(chk) {
    const row    = chk.closest('.bi-form-usuario-row');
    const ver    = row.querySelector('.bi-form-ver-chk');
    const portal = row.querySelector('.bi-form-portal-chk');
    if (portal.checked) {
        ver.checked  = true;
        ver.disabled = true;
    } else {
        ver.disabled = false;
    }
}

function biFecharForm() {
    biEditandoSlug = null;
    document.getElementById('bi-form').style.display      = 'none';
    document.getElementById('bi-form-erro').style.display = 'none';
    const sel = document.getElementById('bi-relatorio-select');
    if (sel) { sel.value = ''; sel.disabled = false; }
}

function biSalvarEntrada() {
    const sel  = document.getElementById('bi-relatorio-select');
    const slug = sel.value;
    const erro = document.getElementById('bi-form-erro');

    if (!slug) {
        erro.textContent = 'Selecione um relatório.';
        erro.style.display = 'block'; return;
    }

    const permissoes = Array.from(document.querySelectorAll('#bi-form-usuarios-lista .bi-form-usuario-row')).map(row => ({
        usuario_id:        parseInt(row.getAttribute('data-uid'), 10),
        pode_ver:           row.querySelector('.bi-form-ver-chk').checked,
        pode_criar_portal:  row.querySelector('.bi-form-portal-chk').checked,
    }));

    const catalogoItem = biCatalogo.find(r => r.slug === slug);
    const entrada = { slug, nome_amigavel: catalogoItem ? catalogoItem.nome_amigavel : slug, permissoes };

    if (biEditandoSlug !== null) {
        const idx = biRelatorios.findIndex(r => r.slug === biEditandoSlug);
        if (idx !== -1) biRelatorios[idx] = entrada; else biRelatorios.push(entrada);
    } else {
        biRelatorios.push(entrada);
    }

    biFecharForm();
    biRenderLista();
}

async function biRemoverRelatorio(index) {
    const rel = biRelatorios[index];
    if (!rel) return;
    const ok = await kwConfirm(`Remover o relatório "${rel.nome_amigavel}" deste cliente?`, 'Remover relatório');
    if (!ok) return;
    biRelatorios.splice(index, 1);
    biRenderLista();
}

// ── Salvar tudo ──────────────────────────────────────────────────────────────
function salvarBiAcesso() {
    const msg        = document.getElementById('bi-acesso-msg');
    const descInput  = document.getElementById('bi-descricao-input');
    const descricao  = descInput?.value.trim();

    if (!descricao) {
        if (descInput) { descInput.style.borderColor = '#c53030'; descInput.focus(); }
        if (msg) msg.textContent = 'Descrição é obrigatória.';
        return;
    }
    if (descInput) descInput.style.borderColor = '';

    if (msg) msg.textContent = 'Salvando...';
    fetch('/api/relatorio-acesso.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save', cliente_id: clienteIdAtual, descricao, relatorios: biRelatorios })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.sucesso) { if (msg) msg.textContent = data.erro || 'Erro ao salvar.'; return; }
        if (msg) { msg.textContent = '✓ Salvo'; setTimeout(() => { if (msg) msg.textContent = ''; }, 2500); }
        // Atualiza appsAtivas — o card "Relatórios BI" pode ter acabado de ser criado agora.
        fetch('/api/cliente-detalhe.php?id=' + clienteIdAtual, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => renderAppsAtivas(d.aplicacoes));
    })
    .catch(() => { if (msg) msg.textContent = 'Erro de conexão.'; });
}

async function desativarBiAcesso() {
    const ok = await kwConfirm('Desativar Relatórios BI para este cliente?', 'Desativar Relatórios BI');
    if (!ok) return;

    fetch('/api/relatorio-acesso.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'deactivate', cliente_id: clienteIdAtual })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.sucesso) { alert(data.erro || 'Erro.'); return; }
        fecharModalApp();
        fetch('/api/cliente-detalhe.php?id=' + clienteIdAtual, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => renderAppsAtivas(d.aplicacoes));
    })
    .catch(() => alert('Erro de conexão.'));
}

// ===== MODAL ATIVAR APP =====

function abrirModalAtivar() {
    document.getElementById('ativar-overlay').classList.add('open');
    document.getElementById('ativar-modal').classList.add('open');

    const lista = document.getElementById('ativar-lista');
    lista.innerHTML = '<div class="panel-loading"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';

    Promise.all([
        fetch('/api/cliente-detalhe.php?id=' + clienteIdAtual, { credentials: 'same-origin' }).then(r => r.json()),
        fetch('/api/aplicacoes-lista.php', { credentials: 'same-origin' }).then(r => r.json())
    ]).then(([detalhe, todas]) => {
        // Conta instâncias ativas por aplicacao_id
        const contagemPorApp = {};
        (detalhe.aplicacoes || []).forEach(a => {
            const aid = parseInt(a.aplicacao_id);
            contagemPorApp[aid] = (contagemPorApp[aid] || 0) + 1;
        });
        lista.innerHTML = todas.map(a => {
            const count = contagemPorApp[parseInt(a.id)] || 0;
            const badge = count > 0
                ? `<span class="badge-app">${count === 1 ? 'Ativa' : count + ' ativas'}</span>`
                : '<span style="font-size:.75rem;color:#0DC2FF;font-weight:600">Ativar →</span>';
            // Relatórios BI não usa o fluxo padrão de ativação (sem webhook) — abre o modal próprio direto.
            const onclickAttr = a.slug === 'relatorios-bi'
                ? `fecharModalAtivar();abrirModalBiAcesso('${a.nome.replace(/'/g,"\\'")}')`
                : `ativarApp(${a.id}, '${a.nome.replace(/'/g,"\\'")}')`;
            return `
            <div class="app-disponivel" onclick="${onclickAttr}">
                <div class="app-card-icon"><i class="${iconeApp[a.slug] || 'fas fa-puzzle-piece'}"></i></div>
                <div class="app-card-info">
                    <div class="app-card-name">${_esc(a.nome)}</div>
                    <div class="app-card-slug">${_esc(a.slug)}</div>
                </div>
                ${badge}
            </div>`;
        }).join('');
    });
}

function fecharModalAtivar() {
    document.getElementById('ativar-overlay').classList.remove('open');
    document.getElementById('ativar-modal').classList.remove('open');
}

async function ativarApp(appId, appNome) {
    const resultado = await kwAtivarApp(appNome);
    if (!resultado) return;

    fetch('/api/cliente-ativar-app.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            cliente_id:    clienteIdAtual,
            aplicacao_id:  appId,
            webhook_bitrix: resultado.webhook,
            descricao:     resultado.descricao || null
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            fecharModalAtivar();
            // Mostra a chave gerada ao admin
            if (data.chave) mostrarChaveGerada(data.chave, appNome);
            fetch('/api/cliente-detalhe.php?id=' + clienteIdAtual, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => renderAppsAtivas(d.aplicacoes));
        } else { alert(data.erro || 'Erro ao ativar.'); }
    });
}

function mostrarChaveGerada(chave, appNome) {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(6,25,32,.6);backdrop-filter:blur(4px);z-index:9998;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:16px;padding:2rem;width:440px;max-width:92vw;box-shadow:0 24px 60px rgba(0,0,0,.25);animation:kwPop .18s ease">
            <div style="width:48px;height:48px;border-radius:50%;background:#d1fae5;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;font-size:1.3rem;color:#065f46"><i class="fas fa-key"></i></div>
            <h3 style="text-align:center;font-family:'Rubik',sans-serif;font-size:1rem;font-weight:700;color:#1a202c;margin:0 0 .35rem">Aplicação ativada!</h3>
            <p style="text-align:center;font-size:.85rem;color:#718096;margin:0 0 1rem">${_esc(appNome)} — chave de acesso desta instância:</p>
            <div style="display:flex;align-items:center;gap:.5rem;background:#f8fafc;border-radius:8px;padding:.6rem .75rem;border:1px solid #e2e8f0;margin-bottom:1.25rem">
                <span id="_chave-gerada-txt" style="font-family:monospace;font-size:.82rem;color:#2d3748;word-break:break-all;flex:1">${_esc(chave)}</span>
                <button onclick="copiarChaveApp('${_esc(chave)}')" style="background:#0DC2FF;color:#fff;border:none;border-radius:6px;padding:.35rem .65rem;font-size:.8rem;cursor:pointer;font-weight:600;flex-shrink:0"><i class="fas fa-copy"></i></button>
            </div>
            <button onclick="this.closest('[style*=fixed]').remove()"
                style="width:100%;padding:.65rem;border:none;border-radius:8px;background:#0DC2FF;color:#fff;font-size:.875rem;cursor:pointer;font-weight:700">Entendido</button>
        </div>`;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
}

// ===== BUSCA DE CNPJ =====

function _buscarCNPJ(cnpjRaw, onNome, onEndereco, statusEl) {
    const cnpj = cnpjRaw.replace(/\D/g, '');
    if (cnpj.length !== 14) return;
    if (statusEl) {
        statusEl.textContent = 'Consultando Receita Federal...';
        statusEl.style.color = '#718096';
        statusEl.style.display = 'block';
    }
    fetch('/api/consultar-cnpj.php?cnpj=' + encodeURIComponent(cnpj), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.erro) {
                if (statusEl) { statusEl.textContent = data.erro; statusEl.style.color = '#c53030'; }
                return;
            }
            if (statusEl) { statusEl.style.display = 'none'; }
            if (data.razao_social && onNome) onNome(data.razao_social);
            if (data.endereco && onEndereco) onEndereco(data.endereco);
        })
        .catch(() => {
            if (statusEl) { statusEl.textContent = 'Erro ao consultar CNPJ.'; statusEl.style.color = '#c53030'; }
        });
}

// ===== EDIÇÃO INLINE =====

function editarCampo(fieldEl) {
    if (fieldEl.classList.contains('editando') || fieldEl.classList.contains('no-edit')) return;
    fieldEl.classList.add('editando');

    const campo      = fieldEl.getAttribute('data-campo');
    const tipo       = fieldEl.getAttribute('data-tipo') || 'input';
    const span       = fieldEl.querySelector('span');
    const valorAtual = span.textContent === '—' ? '' : span.textContent;

    span.style.display = 'none';

    const input = tipo === 'textarea'
        ? document.createElement('textarea')
        : Object.assign(document.createElement('input'), { type: 'text' });

    input.value = valorAtual;
    if (campo === 'cnpj')     { input.value = _mascaraCNPJ(valorAtual); }
    if (campo === 'telefone') { input.value = _mascaraTelefone(valorAtual); }
    fieldEl.appendChild(input);
    input.focus();

    document.getElementById('panel-save-bar').classList.add('visivel');
    edicoesPendentes[campo] = input.value;
    input.addEventListener('input', () => {
        if (campo === 'cnpj')     input.value = _mascaraCNPJ(input.value);
        if (campo === 'telefone') input.value = _mascaraTelefone(input.value);
        edicoesPendentes[campo] = input.value;
        const erroEl = fieldEl.querySelector('.campo-erro');
        if (erroEl) { erroEl.textContent = ''; input.style.borderColor = ''; }
    });

    // Auto-fill de CNPJ ao sair do campo
    if (campo === 'cnpj') {
        let statusEl = fieldEl.querySelector('.cnpj-edit-status');
        if (!statusEl) {
            statusEl = document.createElement('div');
            statusEl.className = 'cnpj-edit-status';
            statusEl.style.cssText = 'font-size:.75rem;margin-top:.25rem;display:none';
            fieldEl.appendChild(statusEl);
        }
        input.addEventListener('blur', () => {
            _buscarCNPJ(input.value,
                (nome) => {
                    edicoesPendentes['nome'] = nome;
                    const sp = document.getElementById('pf-nome');
                    const nf = sp && sp.closest('.panel-field');
                    if (nf) {
                        const ni = nf.querySelector('input');
                        if (ni) ni.value = nome; else sp.textContent = nome;
                    }
                },
                (end) => {
                    edicoesPendentes['endereco'] = end;
                    const sp = document.getElementById('pf-endereco');
                    const ef = sp && sp.closest('.panel-field');
                    if (ef) {
                        const ei = ef.querySelector('input, textarea');
                        if (ei) ei.value = end; else sp.textContent = end;
                    }
                },
                statusEl
            );
        });
    }
}

function cancelarEdicoes() {
    document.querySelectorAll('.panel-field.editando').forEach(f => {
        const span  = f.querySelector('span');
        const input = f.querySelector('input, textarea');
        if (input) input.remove();
        if (span)  span.style.display = '';
        f.classList.remove('editando');
    });
    // Restaura dropdown de org ao valor original caso tenha sido alterado
    if ('org_id' in edicoesPendentes) {
        const sel = document.getElementById('pf-org-select');
        if (sel) sel.value = _clienteOrgIdAtual || '';
    }
    edicoesPendentes = {};
    const bar = document.getElementById('panel-save-bar');
    if (bar) bar.classList.remove('visivel');
}

function salvarEdicoes() {
    if (modoNovo) { salvarNovoCliente(); return; }
    if (!clienteIdAtual || !Object.keys(edicoesPendentes).length) return;
    const msg = document.getElementById('save-msg');

    if ('cnpj' in edicoesPendentes) {
        const fEl = document.querySelector('.panel-field[data-campo="cnpj"] input');
        if (fEl && !_validarCNPJ(edicoesPendentes.cnpj)) { _erroInput(fEl, 'CNPJ inválido'); return; }
    }
    if ('telefone' in edicoesPendentes) {
        const fEl = document.querySelector('.panel-field[data-campo="telefone"] input');
        if (fEl && !_validarTelefone(edicoesPendentes.telefone)) { _erroInput(fEl, 'Telefone inválido'); return; }
    }
    if ('email' in edicoesPendentes) {
        const fEl = document.querySelector('.panel-field[data-campo="email"] input');
        if (fEl && !_validarEmail(edicoesPendentes.email)) { _erroInput(fEl, 'E-mail inválido'); return; }
    }

    msg.textContent = 'Salvando...';

    fetch('/api/cliente-atualizar.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: clienteIdAtual, ...edicoesPendentes })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            document.querySelectorAll('.panel-field.editando').forEach(f => {
                const campo    = f.getAttribute('data-campo');
                const span     = f.querySelector('span');
                const input    = f.querySelector('input, textarea');
                span.textContent = edicoesPendentes[campo] || '—';
                if (input) input.remove();
                span.style.display = '';
                f.classList.remove('editando');
            });
            // Atualiza org_id local após salvar
            if ('org_id' in edicoesPendentes) {
                _clienteOrgIdAtual = edicoesPendentes['org_id'];
            }
            edicoesPendentes = {};
            document.getElementById('panel-save-bar').classList.remove('visivel');
            msg.textContent = '';
        } else {
            msg.textContent = data.erro || 'Erro ao salvar.';
        }
    })
    .catch(() => { msg.textContent = 'Erro de conexão.'; });
}

// Fechar com ESC
document.addEventListener('keydown', e => { if (e.key === 'Escape') fecharPainel(); });

// Menu ⋮ do painel
function toggleMenuCliente(e) {
    e.stopPropagation();
    const menu = document.getElementById('menu-cliente-dropdown');
    if (!menu) return;
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

// Fecha o menu ao clicar em qualquer lugar
document.addEventListener('click', () => {
    const menu = document.getElementById('menu-cliente-dropdown');
    if (menu) menu.style.display = 'none';
});

// ===== NOVO CLIENTE (usa o mesmo painel lateral) =====
let modoNovo = false;

function abrirNovoCliente() {
    modoNovo = true;
    clienteIdAtual = null;
    cancelarEdicoes();

    ['novo-nome','novo-cnpj','novo-telefone','novo-email',
     'novo-endereco','novo-link-bitrix','novo-id-bitrix'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    const erroEl = document.getElementById('novo-cliente-erro');
    if (erroEl) erroEl.style.display = 'none';

    // Popula dropdown de organizações
    preencherOrgDropdown('novo-org-id', null);

    document.getElementById('panel-avatar').textContent = '+';
    document.getElementById('panel-nome').textContent   = 'Novo Cliente';
    document.getElementById('panel-cnpj').textContent   = 'Preencha os dados abaixo';

    document.getElementById('panel-loading').style.display  = 'none';
    document.getElementById('panel-conteudo').style.display = 'none';
    document.getElementById('panel-novo').style.display     = 'block';

    // Barra salvar com "Cadastrar"
    document.getElementById('panel-save-bar').classList.add('visivel');
    document.querySelector('#panel-save-bar .btn-salvar').innerHTML = '<i class="fas fa-check"></i> Cadastrar';

    // Esconde menu ⋮
    const btnMenu = document.getElementById('btn-menu-cliente');
    if (btnMenu) btnMenu.style.visibility = 'hidden';

    // Painel mais estreito no modo novo (sem coluna de apps)
    document.getElementById('cliente-panel').style.width = '520px';

    document.getElementById('cliente-overlay').classList.add('open');
    document.getElementById('cliente-panel').classList.add('open');

    const nomeEl = document.getElementById('novo-nome');
    if (nomeEl) nomeEl.focus();

    // Auto-fill de CNPJ no formulário de novo cliente
    const cnpjEl = document.getElementById('novo-cnpj');
    if (cnpjEl) {
        let statusEl = document.getElementById('novo-cnpj-status');
        if (!statusEl) {
            statusEl = Object.assign(document.createElement('div'), { id: 'novo-cnpj-status' });
            statusEl.style.cssText = 'font-size:.75rem;margin-top:.25rem;display:none';
            cnpjEl.parentNode.insertBefore(statusEl, cnpjEl.nextSibling);
        } else {
            statusEl.style.display = 'none';
            statusEl.textContent = '';
        }
        cnpjEl.oninput = () => {
            cnpjEl.value = _mascaraCNPJ(cnpjEl.value);
            const erroEl = cnpjEl.closest('.panel-field')?.querySelector('.campo-erro');
            if (erroEl) { erroEl.textContent = ''; cnpjEl.style.borderColor = ''; }
        };
        cnpjEl.onblur = () => {
            _buscarCNPJ(cnpjEl.value,
                (nome) => { if (nomeEl) nomeEl.value = nome; },
                (end)  => {
                    const el = document.getElementById('novo-endereco');
                    if (el) el.value = end;
                },
                statusEl
            );
        };
    }

    const telEl = document.getElementById('novo-telefone');
    if (telEl) {
        telEl.oninput = () => {
            telEl.value = _mascaraTelefone(telEl.value);
            const erroEl = telEl.closest('.panel-field')?.querySelector('.campo-erro');
            if (erroEl) { erroEl.textContent = ''; telEl.style.borderColor = ''; }
        };
    }
}

function fecharNovoCliente() { fecharPainel(); }

// ===== NIMBUS PARTNERS =====

function salvarNimbus(caId) {
    const descricao  = document.getElementById('app-descricao-input')?.value.trim();
    const webhook    = document.getElementById('app-webhook-input')?.value.trim();
    const valor      = document.getElementById('app-valor-input')?.value;
    const horario    = document.getElementById('nimbus-horario-input')?.value;
    const diasSemana = Array.from(document.querySelectorAll('input[name="nimbus-dia"]:checked'))
        .map(cb => parseInt(cb.value, 10));
    const msg = document.getElementById('app-integracao-msg');

    if (!descricao) {
        const inp = document.getElementById('app-descricao-input');
        if (inp) { inp.style.borderColor = '#c53030'; inp.focus(); }
        if (msg) msg.textContent = 'Descrição é obrigatória.';
        return;
    }

    if (msg) msg.textContent = 'Salvando...';

    fetch('/api/nimbus-config-salvar.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ca_id: caId, webhook: webhook || null, descricao, dias_semana: diasSemana, horario: horario || null, valor: valor || null })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            const app = appsAtivas.find(a => String(a.ca_id) === String(caId));
            if (app) { app.descricao = descricao; renderAppsAtivas(appsAtivas); }
            if (msg) { msg.textContent = '✓ Salvo'; setTimeout(() => { if (msg) msg.textContent = ''; }, 2500); }
        } else {
            if (msg) msg.textContent = data.erro || 'Erro.';
        }
    })
    .catch(() => { if (msg) msg.textContent = 'Erro de conexão.'; });
}

function dispararNimbus(caId) {
    const btn = document.getElementById('nimbus-disparar-btn');
    const msg = document.getElementById('app-integracao-msg');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Disparando...'; }
    if (msg) { msg.textContent = ''; msg.style.color = '#718096'; }

    fetch('/api/nimbus-executar.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ca_id: caId })
    })
    .then(r => r.json())
    .then(data => {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-bolt"></i> Disparar agora'; }
        if (data.sucesso) {
            if (msg) { msg.style.color = '#065f46'; msg.textContent = '✓ ' + (data.message || 'Disparado com sucesso'); }
        } else {
            if (msg) { msg.style.color = '#c53030'; msg.textContent = data.erro || 'Erro ao disparar.'; }
        }
        setTimeout(() => { if (msg) { msg.textContent = ''; msg.style.color = '#718096'; } }, 5000);
    })
    .catch(() => {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-bolt"></i> Disparar agora'; }
        if (msg) { msg.style.color = '#c53030'; msg.textContent = 'Erro de conexão.'; }
    });
}

async function excluirCliente() {
    if (!clienteIdAtual) return;
    const nome = document.getElementById('panel-nome').textContent;
    const ok = await kwConfirm(`Deseja excluir o cliente "${nome}"?\n\nTodas as aplicações vinculadas também serão removidas.`, 'Excluir cliente');
    if (!ok) return;

    fetch('/api/cliente-excluir.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: clienteIdAtual })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            fecharPainel();
            window.location.href = '?page=cadastro';
        } else { alert(data.erro || 'Erro ao excluir.'); }
    });
}

