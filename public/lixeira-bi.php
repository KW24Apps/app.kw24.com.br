<?php
if (!defined('SYSTEM_ACCESS') && !isset($user_data)) {
    header('Location: /public/login.php'); exit;
}
// Defesa em profundidade: admin_interno only, mesmo padrão de public/monitoramento.php —
// o guard genérico do index.php não cobre o caminho AJAX pra usuário sem profile_id.
if (($user_data['perfil'] ?? '') !== 'admin_interno') {
    header('Location: ?page=relatorios-bi'); exit;
}
?>
<style>
.lix-wrap {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    padding: 1.5rem;
}
.lix-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.lix-title-row {
    display: flex;
    align-items: center;
    gap: .6rem;
}
.lix-title-icon {
    color: #ff8080;
    font-size: 1.3rem;
}
.lix-title {
    font-family: 'Rubik', sans-serif;
    font-size: 1.15rem;
    font-weight: 700;
    color: #fff;
}
.lix-subtitle {
    font-family: 'Inter', sans-serif;
    font-size: .82rem;
    color: rgba(255,255,255,0.45);
    margin: .2rem 0 0;
}
.lix-back-link {
    font-family: 'Inter', sans-serif;
    font-size: .82rem;
    color: #0DC2FF;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: .3rem;
}
.lix-back-link:hover { text-decoration: underline; }

.lix-table-wrap {
    background: #0d1e2d;
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    overflow-x: auto;
}
.lix-table {
    width: 100%;
    border-collapse: collapse;
    font-family: 'Inter', sans-serif;
    font-size: .82rem;
    min-width: 780px;
}
.lix-table th {
    text-align: left;
    padding: .75rem 1rem;
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: rgba(255,255,255,0.4);
    border-bottom: 1px solid rgba(255,255,255,0.10);
    white-space: nowrap;
}
.lix-table td {
    padding: .7rem 1rem;
    color: rgba(255,255,255,0.85);
    border-bottom: 1px solid rgba(255,255,255,0.06);
    vertical-align: middle;
}
.lix-table tr:last-child td { border-bottom: none; }
.lix-nome { font-weight: 600; color: #fff; }
.lix-slug { color: rgba(255,255,255,0.4); font-size: .76rem; }
.lix-badge {
    display: inline-flex;
    align-items: center;
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: .2rem .55rem;
    border-radius: 20px;
    white-space: nowrap;
}
.lix-badge.rascunho  { background: rgba(255,184,0,0.15); color: #ffb800; }
.lix-badge.publicado { background: rgba(38,255,147,0.12); color: #26FF93; }
.lix-dias {
    font-size: .76rem;
    color: rgba(255,255,255,0.55);
}
.lix-dias.urgente { color: #ff8080; font-weight: 600; }
.lix-acoes {
    display: flex;
    gap: .5rem;
    white-space: nowrap;
}
.lix-btn {
    border-radius: 7px;
    font-family: 'Inter', sans-serif;
    font-size: .76rem;
    font-weight: 600;
    padding: .4rem .7rem;
    cursor: pointer;
    transition: background .15s;
    white-space: nowrap;
}
.lix-btn-restaurar {
    background: rgba(13,194,255,0.12);
    border: 1px solid rgba(13,194,255,0.4);
    color: #0DC2FF;
}
.lix-btn-restaurar:hover:not(:disabled) { background: rgba(13,194,255,0.22); }
.lix-btn-excluir {
    background: rgba(229,62,62,0.12);
    border: 1px solid rgba(229,62,62,0.4);
    color: #ff8080;
}
.lix-btn-excluir:hover:not(:disabled) { background: rgba(229,62,62,0.22); }
.lix-btn:disabled { opacity: .4; cursor: not-allowed; }
.lix-empty {
    padding: 2.5rem 1rem;
    text-align: center;
    color: rgba(255,255,255,0.35);
    font-family: 'Inter', sans-serif;
    font-size: .85rem;
}
.lix-msg {
    font-family: 'Inter', sans-serif;
    font-size: .8rem;
    padding: .6rem .9rem;
    border-radius: 8px;
    display: none;
}
.lix-msg.show { display: block; }
.lix-msg.ok   { background: rgba(38,255,147,0.10); color: #26FF93; border: 1px solid rgba(38,255,147,0.25); }
.lix-msg.erro { background: rgba(229,62,62,0.12); color: #ff8080; border: 1px solid rgba(229,62,62,0.3); }
.lix-msg.aviso{ background: rgba(255,184,0,0.10); color: #ffb800; border: 1px solid rgba(255,184,0,0.3); }

/* ── Modal "Excluir definitivamente" ─────────────────────────────────────── */
.lix-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(6,25,32,0.72);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.lix-overlay.open { display: flex; }
.lix-modal {
    background: #0d1e2d;
    border: 1.5px solid rgba(229,62,62,0.3);
    border-radius: 16px;
    padding: 1.5rem;
    width: 100%;
    max-width: 420px;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.lix-modal-title {
    font-family: 'Rubik', sans-serif;
    font-size: 1rem;
    font-weight: 700;
    color: #fff;
}
.lix-modal-warn {
    font-family: 'Inter', sans-serif;
    font-size: .8rem;
    color: rgba(255,255,255,0.6);
    margin: 0;
}
.lix-modal-input {
    background: rgba(255,255,255,0.07);
    border: 1.5px solid rgba(255,255,255,0.12);
    border-radius: 8px;
    padding: .55rem .8rem;
    color: #fff;
    font-family: 'Inter', sans-serif;
    font-size: .875rem;
    outline: none;
    width: 100%;
    box-sizing: border-box;
}
.lix-modal-input:focus { border-color: #ff8080; }
.lix-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
.lix-btn-cancel {
    background: transparent;
    border: 1.5px solid rgba(255,255,255,0.15);
    border-radius: 8px;
    color: rgba(255,255,255,0.50);
    font-family: 'Inter', sans-serif;
    font-size: .82rem;
    font-weight: 500;
    padding: .45rem 1rem;
    cursor: pointer;
}
</style>

<div class="lix-wrap">
    <div class="lix-header">
        <div>
            <div class="lix-title-row">
                <i class="ti ti-trash lix-title-icon"></i>
                <span class="lix-title">Lixeira</span>
            </div>
            <p class="lix-subtitle">Relatórios movidos pra cá ficam 30 dias antes da exclusão automática. Restaurar devolve tudo (acesso, portais, serviço) exatamente como estava.</p>
        </div>
        <a href="?page=relatorios-bi" class="lix-back-link"><i class="ti ti-arrow-left"></i> Voltar pra Relatórios BI</a>
    </div>

    <div class="lix-msg" id="lix-msg"></div>

    <div class="lix-table-wrap">
        <table class="lix-table">
            <thead>
                <tr>
                    <th>Relatório</th>
                    <th>Estado antes</th>
                    <th>Na lixeira desde</th>
                    <th>Purga automática em</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody id="lix-tbody">
                <tr><td colspan="5" class="lix-empty">Carregando...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="lix-overlay" id="lix-excluir-overlay">
    <div class="lix-modal">
        <span class="lix-modal-title">Excluir definitivamente</span>
        <p class="lix-modal-warn">Ação permanente e irreversível — remove o relatório, dados/tabelas, acesso e portais associados (tenta também limpar campos sincronizados no Bitrix). Digite o nome amigável ou slug exato para confirmar.</p>
        <input type="text" class="lix-modal-input" id="lix-excluir-input" placeholder="Digite o nome amigável ou slug" autocomplete="off">
        <div class="lix-modal-footer">
            <button type="button" class="lix-btn-cancel" id="lix-excluir-cancel">Cancelar</button>
            <button type="button" class="lix-btn lix-btn-excluir" id="lix-excluir-confirmar" disabled>Excluir definitivamente</button>
        </div>
    </div>
</div>

<script>
(function () {
    const tbody          = document.getElementById('lix-tbody');
    const msgEl           = document.getElementById('lix-msg');
    const excluirOverlay  = document.getElementById('lix-excluir-overlay');
    const excluirInput    = document.getElementById('lix-excluir-input');
    const excluirCancel   = document.getElementById('lix-excluir-cancel');
    const excluirConfirmar= document.getElementById('lix-excluir-confirmar');

    let alvoExcluir = null; // { id, nome_amigavel, slug }

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function showMsg(texto, tipo) {
        msgEl.textContent = texto;
        msgEl.className = 'lix-msg show ' + (tipo || 'erro');
    }
    function clearMsg() {
        msgEl.className = 'lix-msg';
        msgEl.textContent = '';
    }

    function loadLixeira() {
        tbody.innerHTML = '<tr><td colspan="5" class="lix-empty">Carregando...</td></tr>';
        fetch('/api/relatorio-excluir.php?action=lixeira-list')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.sucesso) {
                    tbody.innerHTML = '<tr><td colspan="5" class="lix-empty">' + escHtml(res.erro || 'Erro ao carregar.') + '</td></tr>';
                    return;
                }
                renderTabela(res.relatorios || []);
            })
            .catch(function () {
                tbody.innerHTML = '<tr><td colspan="5" class="lix-empty">Erro de rede ao carregar a lixeira.</td></tr>';
            });
    }

    function renderTabela(relatorios) {
        if (!relatorios.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="lix-empty">Nenhum relatório na lixeira.</td></tr>';
            return;
        }
        tbody.innerHTML = relatorios.map(function (r) {
            const badge = r.em_construcao
                ? '<span class="lix-badge rascunho">Rascunho</span>'
                : '<span class="lix-badge publicado">Publicado</span>';
            const dataLixeira = r.lixeira_em ? new Date(r.lixeira_em.replace(' ', 'T')).toLocaleDateString('pt-BR') : '—';
            const diasClasse = r.dias_restantes <= 5 ? 'lix-dias urgente' : 'lix-dias';
            const diasTxt = r.dias_restantes === 0 ? 'vencido, aguardando purga' : (r.dias_restantes + ' dia(s)');
            return '<tr data-id="' + r.id + '">' +
                '<td><div class="lix-nome">' + escHtml(r.nome_amigavel) + '</div><div class="lix-slug">' + escHtml(r.slug) + '</div></td>' +
                '<td>' + badge + '</td>' +
                '<td>' + escHtml(dataLixeira) + '</td>' +
                '<td><span class="' + diasClasse + '">' + escHtml(diasTxt) + '</span></td>' +
                '<td class="lix-acoes">' +
                    '<button type="button" class="lix-btn lix-btn-restaurar" data-action="restaurar">Restaurar</button>' +
                    '<button type="button" class="lix-btn lix-btn-excluir" data-action="excluir">Excluir definitivamente</button>' +
                '</td>' +
            '</tr>';
        }).join('');

        tbody.querySelectorAll('button[data-action="restaurar"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const tr = btn.closest('tr');
                const id = parseInt(tr.getAttribute('data-id'), 10);
                const nome = tr.querySelector('.lix-nome').textContent;
                if (!confirm('Restaurar "' + nome + '"? Ele volta a aparecer no hub, com o serviço e os portais no mesmo estado de antes.')) return;
                restaurar(id, btn);
            });
        });
        tbody.querySelectorAll('button[data-action="excluir"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const tr = btn.closest('tr');
                alvoExcluir = {
                    id: parseInt(tr.getAttribute('data-id'), 10),
                    nome: tr.querySelector('.lix-nome').textContent,
                    slug: tr.querySelector('.lix-slug').textContent,
                };
                excluirInput.value = '';
                excluirConfirmar.disabled = true;
                excluirOverlay.classList.add('open');
                excluirInput.focus();
            });
        });
    }

    function restaurar(id, btn) {
        clearMsg();
        btn.disabled = true;
        fetch('/api/relatorio-excluir.php?action=restaurar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.sucesso) {
                showMsg('Erro ao restaurar: ' + (res.erro || 'desconhecido'), 'erro');
                btn.disabled = false;
                return;
            }
            const avisos = [];
            if (!res.systemd_ok) avisos.push('não foi possível reiniciar o serviço systemd (pode não existir ainda) — verifique manualmente se o relatório tiver dashboard próprio');
            if (!res.nginx_ok)   avisos.push('falha ao regenerar o map do nginx: ' + (res.nginx_erro || ''));
            showMsg(avisos.length ? ('Restaurado, com aviso(s): ' + avisos.join('; ')) : 'Relatório restaurado com sucesso.', avisos.length ? 'aviso' : 'ok');
            loadLixeira();
        })
        .catch(function () {
            showMsg('Erro de rede ao restaurar.', 'erro');
            btn.disabled = false;
        });
    }

    if (excluirInput) {
        excluirInput.addEventListener('input', function () {
            const digitado = excluirInput.value.trim();
            const bate = alvoExcluir && digitado !== '' && (digitado === alvoExcluir.nome.trim() || digitado === alvoExcluir.slug.trim());
            excluirConfirmar.disabled = !bate;
        });
    }
    if (excluirCancel) excluirCancel.addEventListener('click', function () { excluirOverlay.classList.remove('open'); alvoExcluir = null; });
    if (excluirConfirmar) excluirConfirmar.addEventListener('click', function () {
        if (!alvoExcluir) return;
        clearMsg();
        excluirConfirmar.disabled = true;
        excluirConfirmar.textContent = 'Excluindo...';
        fetch('/api/relatorio-excluir.php?action=excluir-definitivo', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: alvoExcluir.id })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            excluirConfirmar.textContent = 'Excluir definitivamente';
            if (!res.sucesso) {
                showMsg('Erro ao excluir definitivamente: ' + (res.erro || 'desconhecido'), 'erro');
                excluirConfirmar.disabled = false;
                return;
            }
            excluirOverlay.classList.remove('open');
            const falhasBitrix = (res.bitrix_limpeza || []).filter(function (b) { return b.erro; });
            const avisos = [];
            if (falhasBitrix.length) avisos.push('limpeza de campos Bitrix falhou pra ' + falhasBitrix.length + ' portal(is) — verifique manualmente');
            if (!res.nginx_ok) avisos.push('falha ao regenerar o map do nginx');
            showMsg(avisos.length ? ('Excluído, com aviso(s): ' + avisos.join('; ')) : 'Relatório excluído definitivamente.', avisos.length ? 'aviso' : 'ok');
            alvoExcluir = null;
            loadLixeira();
        })
        .catch(function () {
            excluirConfirmar.textContent = 'Excluir definitivamente';
            showMsg('Erro de rede ao excluir definitivamente.', 'erro');
            excluirConfirmar.disabled = false;
        });
    });

    loadLixeira();
})();
</script>
