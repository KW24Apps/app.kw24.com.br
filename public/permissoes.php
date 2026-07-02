<?php
?>

<style>
/* ── layout ───────────────────────────────────────────────────────────────── */
.perm-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1.25rem;
    margin-top: 1.5rem;
}
.perm-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.25rem 1.4rem;
    cursor: pointer;
    transition: box-shadow .18s, transform .18s;
    position: relative;
}
.perm-card:hover {
    box-shadow: 0 4px 20px rgba(13,194,255,.18);
    transform: translateY(-2px);
}
.perm-card-name { font-size: .975rem; font-weight: 700; color: #1a202c; margin-bottom: .35rem; }
.perm-card-meta { font-size: .75rem; color: #718096; margin-bottom: .7rem; }
.perm-tags { display: flex; flex-wrap: wrap; gap: .3rem; }
.perm-tag {
    font-size: .65rem; font-weight: 600; padding: .15rem .45rem;
    border-radius: 4px; background: #ebf8ff; color: #0369a1; white-space: nowrap;
}
.perm-tag-more { background: #f0fdf4; color: #166534; }
.perm-card-add {
    border: 2px dashed #cbd5e0; background: transparent; color: #718096;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: .5rem; min-height: 120px; font-size: .875rem;
}
.perm-card-add:hover { border-color: #0DC2FF; color: #0DC2FF; background: rgba(13,194,255,.04); }
.perm-card-add i { font-size: 1.4rem; }

/* ── editor ───────────────────────────────────────────────────────────────── */
#perm-editor { display: none; }
#perm-editor.active { display: block; }
.perm-editor-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1.75rem 2rem;
    max-width: 680px;
}
.perm-back-btn {
    display: inline-flex; align-items: center; gap: .4rem;
    font-size: .82rem; color: #718096; cursor: pointer;
    margin-bottom: 1.25rem; transition: color .15s;
}
.perm-back-btn:hover { color: #0DC2FF; }
.perm-editor-title { font-size: 1.1rem; font-weight: 700; color: #1a202c; margin-bottom: 1.25rem; }
.perm-field { margin-bottom: 1rem; }
.perm-field label {
    display: block; font-size: .7rem; font-weight: 700; color: #4a5568;
    text-transform: uppercase; letter-spacing: .05em; margin-bottom: .35rem;
}
.perm-field input {
    width: 100%; padding: .55rem .8rem; border: 1px solid #cbd5e0;
    border-radius: 8px; font-size: .9rem; color: #1a202c;
    font-family: inherit; outline: none; transition: border-color .15s;
}
.perm-field input:focus { border-color: #0DC2FF; }

/* menu tree */
.perm-quick { display: flex; gap: .75rem; margin-bottom: .9rem; }
.perm-quick a { font-size: .78rem; color: #0DC2FF; cursor: pointer; text-decoration: underline; }
.perm-menu-list { display: flex; flex-direction: column; gap: .5rem; margin-bottom: 1.5rem; }
.perm-menu-parent {
    border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;
}
.perm-menu-row {
    display: flex; align-items: center; gap: .7rem; padding: .6rem .85rem;
    background: #f8fafc; font-size: .875rem; color: #2d3748; user-select: none;
}
.perm-menu-row.has-children { cursor: pointer; }
.perm-menu-row.has-children:hover { background: #edf2f7; }
.perm-menu-row input[type=checkbox] { accent-color: #0DC2FF; width: 15px; height: 15px; cursor: pointer; }
.perm-menu-row i.icon { width: 18px; color: #718096; font-size: .82rem; }
.perm-menu-label { flex: 1; }
.perm-chevron { font-size: .7rem; color: #a0aec0; transition: transform .2s; }
.perm-chevron.open { transform: rotate(90deg); }
.perm-children { border-top: 1px solid #e2e8f0; }
.perm-child-row {
    display: flex; align-items: center; gap: .7rem; padding: .5rem .85rem .5rem 2.1rem;
    font-size: .82rem; color: #4a5568;
}
.perm-child-row:not(:last-child) { border-bottom: 1px solid #f0f4f8; }
.perm-child-row input[type=checkbox] { accent-color: #0DC2FF; width: 13px; height: 13px; cursor: pointer; }
.perm-child-row i.icon { width: 16px; color: #a0aec0; font-size: .75rem; }

.perm-editor-actions { display: flex; justify-content: space-between; align-items: center; margin-top: .5rem; }
.perm-save-btn {
    background: #0DC2FF; color: #061920; font-weight: 700; border: none;
    border-radius: 8px; padding: .55rem 1.3rem; font-size: .875rem; cursor: pointer;
    transition: background .15s, transform .15s;
}
.perm-save-btn:hover { background: #26d4ff; transform: translateY(-1px); }
.perm-save-btn:disabled { opacity: .5; cursor: default; transform: none; }
.perm-delete-btn {
    background: none; border: 1px solid #e53e3e; color: #e53e3e;
    border-radius: 8px; padding: .45rem 1rem; font-size: .82rem; cursor: pointer;
    transition: background .15s;
}
.perm-delete-btn:hover { background: #fee2e2; }
.perm-msg {
    font-size: .8rem; margin-top: .6rem; padding: .4rem .75rem;
    border-radius: 6px; display: none;
}
.perm-msg.ok { display: block; background: #d1fae5; color: #065f46; }
.perm-msg.err { display: block; background: #fee2e2; color: #991b1b; }
</style>

<!-- ── grid de perfis ───────────────────────────────────────────────── -->
<div id="perm-list">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-shield-alt"></i> Permissões</h1>
    </div>
    <div class="perm-grid" id="perm-grid">
        <div class="perm-card perm-card-add" onclick="permOpenNew()">
            <i class="fas fa-plus-circle"></i>
            <span>Novo Perfil</span>
        </div>
    </div>
</div>

<!-- ── editor ──────────────────────────────────────────────────────── -->
<div id="perm-editor">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-shield-alt"></i> Permissões</h1>
    </div>
    <div class="perm-editor-panel">
        <div class="perm-back-btn" onclick="permShowList()">
            <i class="fas fa-chevron-left"></i> Voltar
        </div>
        <div class="perm-editor-title" id="perm-editor-title">Novo Perfil</div>

        <div class="perm-field">
            <label>Nome do perfil</label>
            <input type="text" id="perm-nome" placeholder="Ex.: Parceiros, Consultores…" maxlength="100">
        </div>

        <div class="perm-field">
            <label>Páginas permitidas</label>
            <div class="perm-quick">
                <a onclick="permSelectAll()">Todos</a>
                <a onclick="permSelectNone()">Nenhum</a>
            </div>
            <div class="perm-menu-list" id="perm-menu-list"></div>
        </div>

        <div class="perm-editor-actions">
            <div style="display:flex;gap:.75rem;align-items:center;">
                <button class="perm-save-btn" id="perm-save-btn" onclick="permSave()">Salvar</button>
                <button class="perm-delete-btn" id="perm-delete-btn" style="display:none" onclick="permDelete()">Excluir perfil</button>
            </div>
            <span></span>
        </div>
        <div class="perm-msg" id="perm-msg"></div>
    </div>
</div>

<script>
// ── menu tree (derived from sidebar.js + sidebar.php) ────────────────────────
const PERM_MENU_TREE = [
    { key: 'dashboard',        label: 'Dashboard',       icon: 'fas fa-home',                children: [] },
    { key: 'cadastro',         label: 'Cadastro',        icon: 'fas fa-plus-circle',         children: [
        { key: 'cadastro',     label: 'Clientes',        icon: 'fas fa-building' },
        { key: 'usuarios',     label: 'Usuários',        icon: 'fas fa-users' },
        { key: 'permissoes',   label: 'Permissões',      icon: 'fas fa-shield-alt' },
        { key: 'aplicacoes',   label: 'Aplicações',      icon: 'fas fa-th' },
    ]},
    { key: 'relatorio',        label: 'Relatórios',      icon: 'fas fa-chart-bar',           children: [] },
    { key: 'relatorios-bi',    label: 'Relatórios BI',   icon: 'fas fa-chart-line',          children: [
        { key: 'relatorios-bi',  label: 'Hub de Relatórios', icon: 'fas fa-chart-bar' },
        { key: 'portais-bi',     label: 'Portais BI',        icon: 'fas fa-globe' },
    ]},
    { key: 'logs',             label: 'Logs',            icon: 'fas fa-file-alt',            children: [] },
    { key: 'financeiro',       label: 'Financeiro',      icon: 'fas fa-dollar-sign',         children: [
        { key: 'financeiro',            label: 'Dashboard',  icon: 'fas fa-chart-pie' },
        { key: 'financeiro-relatorios', label: 'Relatórios', icon: 'fas fa-file-invoice-dollar' },
    ]},
];

// ── state ─────────────────────────────────────────────────────────────────────
let permCurrentId  = null;
let permProfiles   = [];

// ── load ──────────────────────────────────────────────────────────────────────
async function permLoad() {
    try {
        const res = await fetch('/api/permission-profiles.php?action=list', { credentials: 'same-origin' });
        const json = await res.json();
        if (!res.ok) {
            document.getElementById('perm-grid').innerHTML =
                `<div style="color:#e53e3e;padding:1rem;font-size:.875rem">
                    Erro ao carregar perfis: ${escHtml(json.error || 'HTTP ' + res.status)}
                </div>`;
            return;
        }
        permProfiles = json.data || [];
        permRenderGrid();
    } catch (err) {
        document.getElementById('perm-grid').innerHTML =
            `<div style="color:#e53e3e;padding:1rem;font-size:.875rem">Erro de conexão com a API.</div>`;
    }
}

function permRenderGrid() {
    const grid = document.getElementById('perm-grid');
    // keep the "add" card
    const addCard = grid.querySelector('.perm-card-add');
    grid.innerHTML = '';
    grid.appendChild(addCard);

    permProfiles.forEach(p => {
        const menus = JSON.parse(p.menus || '[]');
        const shown = menus.slice(0, 4);
        const extra = menus.length - shown.length;

        const card = document.createElement('div');
        card.className = 'perm-card';
        card.onclick = () => permOpenEdit(p.id);
        card.innerHTML = `
            <div class="perm-card-name">${escHtml(p.nome)}</div>
            <div class="perm-card-meta">${menus.length} permissão(ões) · ${p.user_count} usuário(s)</div>
            <div class="perm-tags">
                ${shown.map(k => `<span class="perm-tag">${escHtml(k)}</span>`).join('')}
                ${extra > 0 ? `<span class="perm-tag perm-tag-more">+${extra}</span>` : ''}
            </div>`;
        grid.insertBefore(card, addCard);
    });
}

// ── editor ────────────────────────────────────────────────────────────────────
function permBuildMenuList(checkedKeys) {
    const container = document.getElementById('perm-menu-list');
    container.innerHTML = '';

    PERM_MENU_TREE.forEach(item => {
        const wrapper = document.createElement('div');
        wrapper.className = 'perm-menu-parent';

        const allKeys = item.children.length
            ? item.children.map(c => c.key)
            : [item.key];

        const parentChecked = item.children.length
            ? allKeys.some(k => checkedKeys.includes(k))
            : checkedKeys.includes(item.key);

        const hasChildren = item.children.length > 0;

        const row = document.createElement('div');
        row.className = 'perm-menu-row' + (hasChildren ? ' has-children' : '');
        row.innerHTML = `
            <input type="checkbox" data-key="${item.key}" data-parent="1"
                   ${parentChecked ? 'checked' : ''}>
            <i class="icon ${item.icon}"></i>
            <span class="perm-menu-label">${escHtml(item.label)}</span>
            ${hasChildren ? '<i class="fas fa-chevron-right perm-chevron"></i>' : ''}`;

        const cb = row.querySelector('input');

        if (hasChildren) {
            const childContainer = document.createElement('div');
            childContainer.className = 'perm-children';
            childContainer.style.display = parentChecked ? 'block' : 'none';

            item.children.forEach(child => {
                const cr = document.createElement('div');
                cr.className = 'perm-child-row';
                cr.innerHTML = `
                    <input type="checkbox" data-key="${child.key}"
                           ${checkedKeys.includes(child.key) ? 'checked' : ''}>
                    <i class="icon ${child.icon}"></i>
                    <span>${escHtml(child.label)}</span>`;

                cr.querySelector('input').addEventListener('change', () => {
                    const anyChild = [...childContainer.querySelectorAll('input')].some(x => x.checked);
                    cb.checked = anyChild;
                });
                childContainer.appendChild(cr);
            });

            row.addEventListener('click', e => {
                if (e.target.tagName === 'INPUT') return;
                const open = childContainer.style.display !== 'none';
                childContainer.style.display = open ? 'none' : 'block';
                row.querySelector('.perm-chevron').classList.toggle('open', !open);
            });

            cb.addEventListener('change', () => {
                childContainer.querySelectorAll('input').forEach(x => x.checked = cb.checked);
                childContainer.style.display = cb.checked ? 'block' : 'none';
                row.querySelector('.perm-chevron').classList.toggle('open', cb.checked);
            });

            wrapper.appendChild(row);
            wrapper.appendChild(childContainer);
        } else {
            wrapper.appendChild(row);
        }

        container.appendChild(wrapper);
    });
}

function permGetCheckedKeys() {
    const keys = [];
    document.querySelectorAll('#perm-menu-list input[type=checkbox]').forEach(cb => {
        if (cb.checked && !cb.dataset.parent) keys.push(cb.dataset.key);
    });
    // also include leaf-less parents
    document.querySelectorAll('#perm-menu-list input[data-parent="1"]').forEach(cb => {
        const hasChildren = cb.closest('.perm-menu-parent').querySelector('.perm-children');
        if (!hasChildren && cb.checked) keys.push(cb.dataset.key);
    });
    return [...new Set(keys)];
}

function permSelectAll() {
    document.querySelectorAll('#perm-menu-list input[type=checkbox]').forEach(cb => {
        cb.checked = true;
        cb.closest('.perm-menu-parent')?.querySelector('.perm-children') &&
            (cb.closest('.perm-menu-parent').querySelector('.perm-children').style.display = 'block');
        cb.closest('.perm-menu-parent')?.querySelector('.perm-chevron')?.classList.add('open');
    });
}

function permSelectNone() {
    document.querySelectorAll('#perm-menu-list input[type=checkbox]').forEach(cb => {
        cb.checked = false;
        const children = cb.closest('.perm-menu-parent')?.querySelector('.perm-children');
        if (children) children.style.display = 'none';
        cb.closest('.perm-menu-parent')?.querySelector('.perm-chevron')?.classList.remove('open');
    });
}

function permShowList() {
    document.getElementById('perm-list').style.display = '';
    document.getElementById('perm-editor').classList.remove('active');
    permCurrentId = null;
}

function permOpenNew() {
    permCurrentId = null;
    document.getElementById('perm-editor-title').textContent = 'Novo Perfil';
    document.getElementById('perm-nome').value = '';
    document.getElementById('perm-delete-btn').style.display = 'none';
    permBuildMenuList([]);
    permClearMsg();
    document.getElementById('perm-list').style.display = 'none';
    document.getElementById('perm-editor').classList.add('active');
}

function permOpenEdit(id) {
    const p = permProfiles.find(x => x.id == id);
    if (!p) return;
    permCurrentId = id;
    document.getElementById('perm-editor-title').textContent = 'Editar Perfil';
    document.getElementById('perm-nome').value = p.nome;
    document.getElementById('perm-delete-btn').style.display = '';
    permBuildMenuList(JSON.parse(p.menus || '[]'));
    permClearMsg();
    document.getElementById('perm-list').style.display = 'none';
    document.getElementById('perm-editor').classList.add('active');
}

async function permSave() {
    const btn  = document.getElementById('perm-save-btn');
    const nome = document.getElementById('perm-nome').value.trim();
    if (!nome) { permShowMsg('Nome obrigatório.', 'err'); return; }

    const menus = permGetCheckedKeys();
    btn.disabled = true;
    btn.textContent = 'Salvando…';

    try {
        const url = permCurrentId
            ? `/api/permission-profiles.php?action=update&id=${permCurrentId}`
            : '/api/permission-profiles.php?action=create';
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nome, menus }),
        });
        const data = await res.json();
        if (!res.ok || data.error) { permShowMsg(data.error || 'Erro.', 'err'); return; }
        permShowMsg('Salvo com sucesso!', 'ok');
        await permLoad();
    } finally {
        btn.disabled = false;
        btn.textContent = 'Salvar';
    }
}

async function permDelete() {
    if (!permCurrentId) return;
    if (!confirm('Excluir este perfil de permissão?')) return;
    const res  = await fetch(`/api/permission-profiles.php?action=delete&id=${permCurrentId}`, { method: 'POST', credentials: 'same-origin' });
    const data = await res.json();
    if (data.error) { permShowMsg(data.error, 'err'); return; }
    await permLoad();
    permShowList();
}

function permShowMsg(msg, type) {
    const el = document.getElementById('perm-msg');
    el.textContent = msg;
    el.className = 'perm-msg ' + type;
}
function permClearMsg() {
    const el = document.getElementById('perm-msg');
    el.className = 'perm-msg';
    el.textContent = '';
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

permLoad();
</script>
