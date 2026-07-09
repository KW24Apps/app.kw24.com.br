/**
 * KW24 - Configuração específica: Arkivu
 */

function renderArkivu(app, clienteId) {
    const config   = app.config_extra ? (typeof app.config_extra === 'string' ? JSON.parse(app.config_extra) : app.config_extra) : {};
    const username = config.arkivu_username || '';
    const password = config.arkivu_password || '';

    return `
        <div id="arkivu-config" data-cliente="${clienteId}" data-app="${app.aplicacao_id}" style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid #e2e8f0">
            <span style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#a0aec0;display:block;margin-bottom:1rem">Credenciais Arkivu</span>
            <div style="display:grid;gap:1rem">
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Usuário Arkivu</label>
                    <input id="arkivu-username" type="text" class="form-input" value="${_esc(username)}" placeholder="usuário de acesso ao Arkivu">
                </div>
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">Senha Arkivu</label>
                    <div style="display:flex;align-items:center;gap:.5rem">
                        <input id="arkivu-password" type="password" class="form-input" value="${_esc(password)}" placeholder="senha de acesso ao Arkivu" style="flex:1">
                        <button type="button" onclick="arkivuTogglePassword()" id="arkivu-password-toggle" title="Mostrar/ocultar senha"
                            style="flex-shrink:0;background:#f0f4f8;border:1px solid #e2e8f0;color:#718096;border-radius:6px;padding:.55rem .7rem;cursor:pointer">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:.75rem">
                    <button onclick="arkivuSalvarConfig()" class="btn-salvar" style="padding:.5rem 1.25rem"><i class="fas fa-check"></i> Salvar credenciais</button>
                    <span id="arkivu-save-msg" style="font-size:.8rem;color:#718096"></span>
                </div>
            </div>
        </div>`;
}

function arkivuTogglePassword() {
    const input = document.getElementById('arkivu-password');
    const icon  = document.querySelector('#arkivu-password-toggle i');
    if (!input || !icon) return;
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    icon.className = showing ? 'fas fa-eye' : 'fas fa-eye-slash';
}

function arkivuSalvarConfig() {
    const el  = document.getElementById('arkivu-config');
    const cId = el?.getAttribute('data-cliente');
    const aId = el?.getAttribute('data-app');
    const msg = document.getElementById('arkivu-save-msg');

    if (!cId || !aId) return;
    msg.textContent = 'Salvando...';

    // Lê valor da seção de integração (acima do config), igual ao padrão do BancoDados
    const valor    = document.getElementById('app-valor-input')?.value || null;
    const username = document.getElementById('arkivu-username')?.value.trim() || '';
    const password = document.getElementById('arkivu-password')?.value || '';

    fetch('/api/cliente-app-config.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            cliente_id:     parseInt(cId),
            aplicacao_id:   parseInt(aId),
            valor:          valor,
            config_extra: {
                arkivu_username: username,
                arkivu_password: password
            }
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.sucesso) {
            msg.textContent = '✓ Salvo';
            setTimeout(() => { if (msg) msg.textContent = ''; }, 2500);
            const novoConfig = { arkivu_username: username, arkivu_password: password };
            if (typeof appsAtivas !== 'undefined') {
                const idx = appsAtivas.findIndex(a => String(a.id) === String(aId));
                if (idx !== -1) {
                    appsAtivas[idx].config_extra = novoConfig;
                    appsAtivas[idx].valor        = valor;
                }
            }
        } else { msg.textContent = data.erro || 'Erro ao salvar.'; }
    })
    .catch(() => { msg.textContent = 'Erro de conexão.'; });
}
