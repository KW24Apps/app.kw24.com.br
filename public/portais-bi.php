<?php
if (!defined('SYSTEM_ACCESS') && !isset($user_data)) {
    header('Location: /public/login.php'); exit;
}
// admin_interno vê todos os relatórios no dropdown "Criar Portal"; demais usuários só os
// liberados via aplicação (mesma sessão calculada em index.php: relatorios_visiveis).
$_pbiIsAdmin  = ($user_data['perfil'] ?? '') === 'admin_interno';
$_pbiVisiveis = $_pbiIsAdmin ? null : ($_SESSION['relatorios_visiveis'] ?? []);
?>
<script>window.PBI_REL_VISIVEIS = <?= $_pbiIsAdmin ? 'null' : json_encode($_pbiVisiveis) ?>;</script>
<style>
/* ── Portais BI Admin ── */
.portais-card {
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 1.25rem;
}
.portais-card-header {
    padding: .75rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex; align-items: center; gap: .6rem;
    font-size: .62rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: rgba(255,255,255,.5);
}
.pbi-filter-controls {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: .6rem;
}
.pbi-search-wrap { position: relative; }
.pbi-search-wrap i {
    position: absolute;
    left: .6rem; top: 50%; transform: translateY(-50%);
    color: rgba(255,255,255,.3);
    font-size: .65rem;
    pointer-events: none;
}
#pbi-search {
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 7px;
    padding: .4rem .6rem .4rem 1.7rem;
    color: #fff;
    font-family: inherit;
    font-size: .7rem;
    font-weight: 400;
    text-transform: none;
    letter-spacing: normal;
    outline: none;
    width: 160px;
    box-sizing: border-box;
    transition: border-color .15s;
}
#pbi-search:focus { border-color: rgba(13,194,255,0.5); }
#pbi-search::placeholder { color: rgba(255,255,255,.3); }
.pbi-empresa-select {
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 7px;
    padding: .4rem .6rem;
    color: #fff;
    font-family: inherit;
    font-size: .7rem;
    font-weight: 400;
    text-transform: none;
    letter-spacing: normal;
    outline: none;
    cursor: pointer;
    max-width: 180px;
}
.pbi-empresa-select option { background: #0d1e2d; }
.pbi-count { color: rgba(255,255,255,.2); font-size: .6rem; white-space: nowrap; }
.portais-card-body { padding: 1.25rem; }
.portais-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
.portais-field { display: flex; flex-direction: column; gap: .35rem; }
.portais-field label {
    font-size: .62rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: rgba(255,255,255,.4);
}
.portais-input, .portais-select {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 7px; color: #fff;
    font-size: .75rem; padding: .45rem .75rem;
    outline: none; font-family: inherit;
    width: 100%; box-sizing: border-box;
}
.portais-input:focus, .portais-select:focus { border-color: rgba(13,194,255,0.5); }
.portais-select option { background: #0d1e2d; }
.portais-input-row { display: flex; gap: .5rem; }
.portais-input-row .portais-input { flex: 1; }

/* Multi-select custom */
.portais-multisel {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 7px; padding: .35rem .5rem;
    max-height: 130px; overflow-y: auto; min-height: 50px;
}
.portais-multisel .pm-item {
    display: flex; align-items: center; gap: .45rem;
    padding: .22rem .3rem; border-radius: 4px;
    cursor: pointer; transition: background .1s;
    font-size: .73rem; color: rgba(255,255,255,.75);
    user-select: none;
}
.portais-multisel .pm-item:hover { background: rgba(255,255,255,0.06); }
.portais-multisel .pm-item input[type=checkbox] { accent-color: #0DC2FF; margin: 0; }
.portais-multisel .pm-item.selected { color: #0DC2FF; }
.portais-multisel-empty {
    font-size: .7rem; color: rgba(255,255,255,.25);
    padding: .4rem .2rem;
}

/* Filter type toggle */
.portais-toggle-row { display: flex; gap: .5rem; }
.portais-toggle-btn {
    flex: 1; padding: .4rem .5rem;
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 6px; background: rgba(255,255,255,0.05);
    color: rgba(255,255,255,.5); font-size: .72rem;
    font-weight: 600; cursor: pointer; text-align: center;
    transition: all .15s;
}
.portais-toggle-btn.active { border-color: #0DC2FF; background: rgba(13,194,255,0.12); color: #0DC2FF; }

.portais-btn {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .45rem .9rem; border: none; border-radius: 7px;
    font-size: .72rem; font-weight: 700; cursor: pointer;
    transition: background .15s; white-space: nowrap;
}
.portais-btn-primary { background: #0DC2FF; color: #061920; }
.portais-btn-primary:hover { background: #08aadd; }
.portais-btn-gen { background: rgba(255,255,255,0.1); color: rgba(255,255,255,.8); }
.portais-btn-gen:hover { background: rgba(255,255,255,0.18); }
.portais-btn-cancel { background: rgba(255,255,255,0.06); color: rgba(255,255,255,.5); }
.portais-btn-cancel:hover { background: rgba(255,255,255,0.12); }
.portais-form-actions { display: flex; gap: .65rem; margin-top: 1rem; }
.portais-msg { font-size: .72rem; padding: .5rem .85rem; border-radius: 7px; margin-top: .75rem; }
.portais-msg-ok  { background: rgba(38,255,147,0.12); color: #26FF93; border: 1px solid rgba(38,255,147,0.2); }
.portais-msg-err { background: rgba(229,62,62,0.12);  color: #fc8181; border: 1px solid rgba(229,62,62,0.2); }

/* Table */
.portais-table { width: 100%; border-collapse: collapse; font-size: .72rem; }
.portais-table thead th {
    padding: .55rem .9rem; text-align: left;
    font-size: .62rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .05em; color: rgba(255,255,255,.35);
    border-bottom: 1px solid rgba(255,255,255,0.07); white-space: nowrap;
}
.portais-table thead th:last-child { text-align: right; }
.portais-table td {
    padding: .7rem .9rem; border-bottom: 1px solid rgba(255,255,255,0.05);
    color: rgba(255,255,255,.82); vertical-align: middle;
    position: relative;
}
.portais-table tbody td:not(:last-child)::after {
    content: '';
    position: absolute;
    right: 0; top: 25%;
    height: 50%; width: 1px;
    background: rgba(255,255,255,0.10);
}
.portais-table tbody tr:last-child td { border-bottom: none; }
.portais-table tbody tr:hover td { background: rgba(255,255,255,0.02); }
.portais-acoes-cell { text-align: right; }
.portais-acoes-inner { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
.portais-badge {
    display: inline-block; font-size: .6rem; font-weight: 700;
    padding: .18rem .5rem; border-radius: 20px; white-space: nowrap;
}
.portais-badge-ativo   { background: rgba(38,255,147,0.15); color: #26FF93; }
.portais-badge-inativo { background: rgba(255,255,255,0.07); color: rgba(255,255,255,.35); }
.portais-badge-tipo {
    display: inline-block; font-size: .58rem; font-weight: 700;
    padding: .15rem .45rem; border-radius: 20px;
    background: rgba(13,194,255,0.12); color: rgba(13,194,255,.8);
    text-transform: uppercase; letter-spacing: .04em;
}
.portais-link-text {
    color: rgba(13,194,255,.7); font-size: .68rem; font-family: monospace;
    text-decoration: none; max-width: 220px; display: inline-block;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: middle;
}
.portais-link-text:hover { color: #0DC2FF; }
.portais-copy-btn {
    background: none; border: none; color: rgba(255,255,255,.3);
    cursor: pointer; font-size: .72rem; padding: .2rem .35rem;
    border-radius: 4px; transition: color .15s, background .15s; vertical-align: middle;
}
.portais-copy-btn:hover { color: #0DC2FF; background: rgba(13,194,255,0.08); }
.portais-action-btn {
    background: none; border: 1px solid rgba(255,255,255,0.12);
    color: rgba(255,255,255,.6); font-size: .65rem; font-weight: 600;
    padding: 6px 12px; border-radius: 5px; cursor: pointer;
    transition: all .15s; white-space: nowrap;
}
.portais-action-btn:hover { background: rgba(255,255,255,0.08); color: #fff; }
.portais-action-btn.danger { border-color: rgba(229,62,62,0.3); color: #fc8181; }
.portais-action-btn.danger:hover { background: rgba(229,62,62,0.12); border-color: rgba(229,62,62,0.5); }
.portais-action-btn.warn { border-color: rgba(246,173,85,0.3); color: #f6ad55; }
.portais-action-btn.warn:hover { background: rgba(246,173,85,0.1); }
.portais-empty { text-align:center; padding: 2.5rem 1rem; color: rgba(255,255,255,.25); font-size: .72rem; }
.portais-tbl-scroll { overflow-x: auto; }
.portais-tags { display: flex; flex-wrap: wrap; gap: .25rem; }
.portais-tag {
    font-size: .6rem; padding: .12rem .4rem; border-radius: 4px;
    background: rgba(255,255,255,0.08); color: rgba(255,255,255,.55);
    white-space: nowrap; max-width: 140px;
    overflow: hidden; text-overflow: ellipsis;
}

/* FAB — botão flutuante criar */
.portais-fab {
    position: fixed;
    bottom: 2rem; right: 2rem;
    width: 3.25rem; height: 3.25rem;
    border-radius: 50%;
    background: #0DC2FF;
    color: #061920;
    border: none;
    font-size: 1.2rem;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 20px rgba(13,194,255,0.35);
    z-index: 100;
    transition: background .15s, box-shadow .15s;
}
.portais-fab:hover { background: #08aadd; box-shadow: 0 6px 28px rgba(13,194,255,0.5); }

/* Modal overlay */
.portais-modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.65);
    z-index: 200;
    display: flex; align-items: center; justify-content: center;
    padding: 1rem;
}
.portais-modal {
    background: #0d2035;
    border: 1.5px solid rgba(255,255,255,0.12);
    border-radius: 14px;
    width: 100%;
    max-width: 680px;
    max-height: 88vh;
    overflow-y: auto;
    box-shadow: 0 24px 64px rgba(0,0,0,0.5);
}
.portais-modal-header {
    padding: .85rem 1.25rem;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex; align-items: center; justify-content: space-between;
}
.portais-modal-title {
    font-size: .72rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: rgba(255,255,255,.6);
    display: flex; align-items: center; gap: .5rem;
}
.portais-modal-close {
    background: none; border: none;
    color: rgba(255,255,255,.35); font-size: .9rem;
    cursor: pointer; padding: .25rem .45rem;
    border-radius: 5px; transition: color .15s, background .15s; line-height: 1;
}
.portais-modal-close:hover { color: #fff; background: rgba(255,255,255,0.08); }
.portais-modal-body { padding: 1.25rem; }

/* Lista de portais — altura total disponível com scroll interno */
.portais-list-card {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 160px);
    min-height: 260px;
}
.portais-list-card .portais-tbl-scroll {
    flex: 1;
    overflow-y: auto;
    overflow-x: auto;
    min-height: 0;
}
</style>


<!-- ── Lista de portais ── -->
<div class="portais-card portais-list-card">
    <div class="portais-card-header">
        <i class="fas fa-list" style="color:#0DC2FF"></i>
        <span>Portais ativos</span>
        <div class="pbi-filter-controls">
            <div class="pbi-search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="pbi-search" placeholder="Buscar..." autocomplete="off">
            </div>
            <select class="pbi-empresa-select" id="pbi-empresa-filter">
                <option value="">Todas as empresas</option>
            </select>
            <span id="pbi-count" class="pbi-count"></span>
        </div>
    </div>
    <div class="portais-tbl-scroll">
        <table class="portais-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th>Filtros</th>
                    <th>Status</th>
                    <th>Link</th>
                    <th>Embed</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody id="pbi-tbody">
                <tr><td colspan="7" class="portais-empty"><i class="fas fa-circle-notch fa-spin"></i> Carregando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ── FAB — criar portal ── -->
<button class="portais-fab" id="pbi-fab" onclick="pbiOpenCreate()" title="Criar portal">
    <i class="fas fa-plus"></i>
</button>

<!-- ── Modal criar / editar ── -->
<div class="portais-modal-overlay" id="pbi-modal-overlay" style="display:none" onclick="pbiModalOverlayClick(event)">
    <div class="portais-modal" id="pbi-modal">
        <div class="portais-modal-header">
            <span class="portais-modal-title">
                <i class="fas fa-globe" style="color:#0DC2FF"></i>
                <span id="portais-form-title">Criar Portal</span>
            </span>
            <button class="portais-modal-close" onclick="pbiCloseModal()" title="Fechar"><i class="fas fa-times"></i></button>
        </div>
        <div class="portais-modal-body">
            <input type="hidden" id="pbi-edit-id" value="">

            <!-- Relatório: sempre visível -->
            <div class="portais-field" style="margin-bottom:1rem">
                <label>Relatório</label>
                <select class="portais-select" id="pbi-relatorio">
                    <option value="">Carregando…</option>
                </select>
            </div>

            <!-- Campos extras: ocultos até selecionar relatório -->
            <div id="pbi-extra-fields" style="display:none">
                <div class="portais-form-grid">

                    <div class="portais-field" id="pbi-tipo-field">
                        <label>Tipo de filtro</label>
                        <div class="portais-toggle-row">
                            <button type="button" class="portais-toggle-btn active" id="pbi-tipo-parceiro" onclick="pbiSetTipo('parceiro')">Parceiro</button>
                            <button type="button" class="portais-toggle-btn"        id="pbi-tipo-oportunidade" onclick="pbiSetTipo('oportunidade')">Oportunidade</button>
                        </div>
                    </div>

                    <!-- Slug gerado automaticamente a partir do Nome (não editável pelo usuário) -->
                    <input type="hidden" id="pbi-slug" value="">

                    <div class="portais-field">
                        <label>Nome</label>
                        <input type="text" class="portais-input" id="pbi-nome" placeholder="Referência interna">
                    </div>

                    <div class="portais-field" id="pbi-senha-field">
                        <label>Senha</label>
                        <div class="portais-input-row">
                            <input type="text" class="portais-input" id="pbi-senha" placeholder="••••••••" autocomplete="off">
                            <button type="button" class="portais-btn portais-btn-gen" onclick="pbiGerarSenha('pbi-senha')">
                                <i class="fas fa-dice"></i> Gerar
                            </button>
                        </div>
                    </div>

                    <div class="portais-field" id="pbi-nova-senha-field" style="display:none">
                        <label>Nova senha <span style="color:rgba(255,255,255,.25)">(vazio = manter)</span></label>
                        <div class="portais-input-row">
                            <input type="text" class="portais-input" id="pbi-nova-senha" placeholder="(sem alteração)" autocomplete="off">
                            <button type="button" class="portais-btn portais-btn-gen" onclick="pbiGerarSenha('pbi-nova-senha')">
                                <i class="fas fa-dice"></i> Gerar
                            </button>
                        </div>
                    </div>

                    <!-- Lista de filtro compartilhada — conteúdo trocado por pbiSetTipo() conforme o
                         toggle "Tipo de filtro" acima (Parceiro/Oportunidade ou Indicador/Contabilidade). -->
                    <div class="portais-field" id="pbi-filtros-field" style="grid-column: 1 / -1">
                        <label id="pbi-filtros-label">Parceiros <span style="color:rgba(255,255,255,.25)">(selecione um ou mais)</span></label>
                        <input type="text" class="portais-input" id="pbi-filtros-search" placeholder="Buscar..." autocomplete="off" style="margin-bottom:.4rem">
                        <div class="portais-multisel" id="pbi-filtros-list">
                            <span class="portais-multisel-empty">Carregando…</span>
                        </div>
                        <div id="pbi-filtros-erro" class="portais-msg portais-msg-err" style="display:none"></div>
                    </div>

                </div>
            </div>

            <div class="portais-form-actions">
                <button type="button" class="portais-btn portais-btn-primary" onclick="pbiSubmit()">
                    <i class="fas fa-save"></i>
                    <span id="pbi-submit-label">Criar portal</span>
                </button>
                <button type="button" class="portais-btn portais-btn-cancel" id="pbi-cancel-btn" style="display:none" onclick="pbiResetForm()">
                    Cancelar edição
                </button>
            </div>
            <div id="pbi-msg" style="display:none" class="portais-msg"></div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var BASE          = 'https://app.kw24.com.br';
    var _portais      = {};
    var _tipo         = 'parceiro';
    var _filterItems  = [];
    var _empresaFiltroSalvo = null; // id de empresa restaurado do sessionStorage (bridge com relatorios-bi.php)
    var searchInp     = document.getElementById('pbi-search');
    var empresaSel    = document.getElementById('pbi-empresa-filter');

    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Relatórios dropdown ────────────────────────────────────────────────
    function loadRelatorios() {
        fetch('/api/relatorios-bi.php?action=list')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var sel = document.getElementById('pbi-relatorio');
                sel.innerHTML = '<option value="">— Selecione —</option>';
                var permitidos = window.PBI_REL_VISIVEIS; // null = admin_interno (sem filtro)
                var data = d.data || [];
                if (permitidos !== null) {
                    data = data.filter(function (r) { return permitidos.indexOf(r.slug) !== -1; });
                }
                data.forEach(function (r) {
                    var o = document.createElement('option');
                    o.value = r.slug;
                    o.textContent = r.nome_amigavel;
                    sel.appendChild(o);
                });
            })
            .catch(function () {
                var sel = document.getElementById('pbi-relatorio');
                sel.innerHTML = '<option value="">Erro ao carregar relatórios</option>';
            });
    }

    var CT_SLUG     = 'relatorio-contabilidade';
    var NIMBUS_SLUG = 'relatorio-parceiros-tax';

    // Regra exclusiva do "Relatório Completo" (usada na contabilidade e no NimbusTax):
    //   Completo marcado → desmarca+desabilita os outros; outro marcado → desmarca Completo.
    function pbiExclusiveCompleto(cb) {
        var listEl = cb.closest('.portais-multisel');
        if (!listEl) return;
        var boxes = listEl.querySelectorAll('input[type=checkbox]');
        if (cb.value === '__completo__') {
            boxes.forEach(function (b) {
                if (b.value === '__completo__') return;
                if (cb.checked) {
                    b.checked  = false;
                    b.disabled = true;
                    b.closest('.pm-item').className = 'pm-item';
                } else {
                    b.disabled = false;
                }
            });
        } else if (cb.checked) {
            var comp = listEl.querySelector('input[value="__completo__"]');
            if (comp && comp.checked) {
                comp.checked = false;
                comp.closest('.pm-item').className = 'pm-item';
            }
        }
        cb.closest('.pm-item').className = 'pm-item' + (cb.checked ? ' selected' : '');
    }

    // Reset ao trocar de relatório no dropdown (sempre sem pré-seleção — edição usa pbiEdit).
    function pbiApplyRelatorioUI(slug) {
        pbiSetTipo(slug === CT_SLUG ? 'indicador' : 'parceiro');
    }

    // Show/hide extra fields based on report selection
    document.getElementById('pbi-relatorio').addEventListener('change', function () {
        if (this.value) {
            document.getElementById('pbi-extra-fields').style.display = '';
            pbiApplyRelatorioUI(this.value);
        } else {
            document.getElementById('pbi-extra-fields').style.display = 'none';
        }
    });

    // ── Tipo de filtro ─────────────────────────────────────────────────────
    // Os dois botões físicos (pbi-tipo-parceiro / pbi-tipo-oportunidade) são reaproveitados
    // para as duas famílias de relatório — só texto/valor mudam conforme o relatório atual.
    // NimbusTax  → Parceiro | Oportunidade      Contabilidade → Indicador | Contabilidade
    var TIPO_LABEL  = { parceiro: 'Parceiro', oportunidade: 'Oportunidade', indicador: 'Indicador', contabilidade: 'Contabilidade' };
    var LISTA_LABEL = { parceiro: 'Parceiros', oportunidade: 'Oportunidades', indicador: 'Indicador', contabilidade: 'Contabilidade' };
    var API_TYPE    = { parceiro: 'parceiro', oportunidade: 'oportunidade', indicador: 'ct-indicador', contabilidade: 'ct-contab' };

    window.pbiSetTipo = function (tipo, selectedIds) {
        _tipo = tipo;
        var isCtGroup = (tipo === 'indicador' || tipo === 'contabilidade');
        var tipo1 = isCtGroup ? 'indicador' : 'parceiro';
        var tipo2 = isCtGroup ? 'contabilidade' : 'oportunidade';
        var btn1  = document.getElementById('pbi-tipo-parceiro');
        var btn2  = document.getElementById('pbi-tipo-oportunidade');
        btn1.textContent = TIPO_LABEL[tipo1];
        btn2.textContent = TIPO_LABEL[tipo2];
        btn1.onclick = function () { pbiSetTipo(tipo1); };
        btn2.onclick = function () { pbiSetTipo(tipo2); };
        btn1.className = 'portais-toggle-btn' + (tipo === tipo1 ? ' active' : '');
        btn2.className = 'portais-toggle-btn' + (tipo === tipo2 ? ' active' : '');
        document.getElementById('pbi-filtros-label').innerHTML = LISTA_LABEL[tipo]
            + ' <span style="color:rgba(255,255,255,.25)">(selecione um ou mais)</span>';
        // Only load if extra fields are visible (report already selected or editing)
        if (document.getElementById('pbi-extra-fields').style.display !== 'none') {
            loadFilterItems(tipo, selectedIds || []);
        }
    };

    function loadFilterItems(tipo, selectedIds) {
        selectedIds = selectedIds || [];
        var list   = document.getElementById('pbi-filtros-list');
        var search = document.getElementById('pbi-filtros-search');
        var erroEl = document.getElementById('pbi-filtros-erro');
        if (search) search.value = '';
        if (erroEl) erroEl.style.display = 'none';
        list.innerHTML = '<span class="portais-multisel-empty">Carregando…</span>';
        fetch('/api/portais-bi.php?action=list-filters&type=' + API_TYPE[tipo])
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.erro) {
                    _filterItems = [];
                    list.innerHTML = '<span class="portais-multisel-empty">Nenhum item encontrado.</span>';
                    if (erroEl) { erroEl.textContent = d.erro; erroEl.style.display = 'block'; }
                    return;
                }
                _filterItems = d.items || [];
                renderFilterList(_filterItems, selectedIds);
            })
            .catch(function () {
                list.innerHTML = '<span class="portais-multisel-empty" style="color:#fc8181">Erro ao carregar.</span>';
                if (erroEl) { erroEl.textContent = 'Erro de conexão ao carregar os filtros.'; erroEl.style.display = 'block'; }
            });
    }

    function renderFilterList(items, selectedIds) {
        var list = document.getElementById('pbi-filtros-list');
        selectedIds = selectedIds || [];
        // NimbusTax e Contabilidade ganham a opção "Relatório Completo" no topo (exclusiva).
        var relatorioAtual = document.getElementById('pbi-relatorio').value;
        var mostraCompleto = (relatorioAtual === NIMBUS_SLUG) || (relatorioAtual === CT_SLUG);
        var completo = mostraCompleto && selectedIds.indexOf('__completo__') !== -1;

        if (!items.length && !mostraCompleto) {
            list.innerHTML = '<span class="portais-multisel-empty">Nenhum item encontrado.</span>';
            return;
        }
        var html = '';
        if (mostraCompleto) {
            html += '<label class="pm-item' + (completo ? ' selected' : '') + '"'
                + ' style="border-bottom:1px solid rgba(255,255,255,.12);margin-bottom:.25rem;padding-bottom:.35rem">'
                + '<input type="checkbox" value="__completo__" data-nome="Relatório Completo"'
                + (completo ? ' checked' : '') + '>'
                + '<strong>Relatório Completo</strong>'
                + '</label>';
        }
        items.forEach(function (item) {
            var checked = !completo && selectedIds.indexOf(String(item.id)) !== -1;
            html += '<label class="pm-item' + (checked ? ' selected' : '') + '">'
                + '<input type="checkbox" value="' + esc(item.id) + '" data-nome="' + esc(item.nome) + '"'
                + (checked ? ' checked' : '') + (completo ? ' disabled' : '') + '>'
                + esc(item.nome)
                + '</label>';
        });
        list.innerHTML = html;
        list.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                pbiExclusiveCompleto(cb);
                pbiAutoFillFromSelection();
            });
        });
    }

    // ── Search filter ──────────────────────────────────────────────────────
    document.getElementById('pbi-filtros-search').addEventListener('input', function () {
        var q = (this.value || '').toLowerCase();
        document.querySelectorAll('#pbi-filtros-list .pm-item').forEach(function (item) {
            var cb   = item.querySelector('input[type=checkbox]');
            var nome = (cb ? (cb.dataset.nome || '') : item.textContent || '').toLowerCase();
            item.style.display = (!q || nome.indexOf(q) !== -1) ? '' : 'none';
        });
    });

    function getSelectedFilters() {
        var completo = false, values = [], labels = [];
        document.querySelectorAll('#pbi-filtros-list input[type=checkbox]:checked').forEach(function (cb) {
            if (cb.value === '__completo__') { completo = true; }
            else { values.push(String(cb.value)); labels.push(String(cb.dataset.nome || cb.value)); }
        });
        // "Relatório Completo": sentinela legível na listagem; auth-check zera os headers.
        if (completo) return { values: ['__completo__'], labels: ['Relatório Completo'] };
        return { values: values, labels: labels };
    }

    // ── Auto-fill slug/nome na primeira seleção de parceiro/oportunidade ──
    // Regras: min\u00fasculas \u00b7 sem acento \u00b7 espa\u00e7os/underscores \u2192 h\u00edfen \u00b7 s\u00f3 [a-z0-9-]
    // \u00b7 colapsa hifens consecutivos \u00b7 sem h\u00edfen nas pontas.
    // Ex.: "Claude Sonett"\u2192"claude-sonett" \u00b7 "Nymbus T\u00e1xi"\u2192"nymbus-taxi" \u00b7 "ContaFarma!"\u2192"contafarma"
    function pbiSlugFromNome(name) {
        return String(name || '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')  // remove acentos
            .toLowerCase()
            .replace(/[\s_]+/g, '-')       // espa\u00e7os e underscores \u2192 h\u00edfen
            .replace(/[^a-z0-9-]/g, '')    // remove o que n\u00e3o for [a-z0-9-]
            .replace(/-+/g, '-')           // colapsa hifens consecutivos
            .replace(/^-+|-+$/g, '');      // remove hifens das pontas
    }
    // Regenera o slug a partir do Nome. S\u00d3 em cria\u00e7\u00e3o \u2014 na edi\u00e7\u00e3o o slug \u00e9 imut\u00e1vel
    // (mud\u00e1-lo quebraria o link do portal j\u00e1 em uso).
    function pbiSyncSlug() {
        if (document.getElementById('pbi-edit-id').value) return;
        document.getElementById('pbi-slug').value =
            pbiSlugFromNome(document.getElementById('pbi-nome').value);
    }
    document.getElementById('pbi-nome').addEventListener('input', pbiSyncSlug);

    function pbiCleanNome(name) {
        return name.replace(/^[\d\.\-\/\s]+/, '').trim();
    }
    // Ao selecionar 1 \u00fanico parceiro/oportunidade, preenche o Nome (se vazio) e gera o slug.
    function pbiAutoFillFromSelection() {
        var allChecked = document.querySelectorAll('#pbi-filtros-list input[type=checkbox]:checked');
        if (allChecked.length !== 1) return;
        if (allChecked[0].value === '__completo__') return;
        var nomeField = document.getElementById('pbi-nome');
        if (nomeField && !nomeField.value.trim()) {
            nomeField.value = pbiCleanNome(allChecked[0].dataset.nome || '');
            pbiSyncSlug();
        }
    }

    // ── Gerar senha ────────────────────────────────────────────────────────
    window.pbiGerarSenha = function (fieldId) {
        var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        var pwd = '';
        var arr = new Uint8Array(12);
        crypto.getRandomValues(arr);
        arr.forEach(function (b) { pwd += chars[b % chars.length]; });
        document.getElementById(fieldId).value = pwd;
    };

    // ── Submit ─────────────────────────────────────────────────────────────
    window.pbiSubmit = function () {
        var editId    = document.getElementById('pbi-edit-id').value;
        var relatorio = document.getElementById('pbi-relatorio').value;
        var slug      = document.getElementById('pbi-slug').value.trim().toLowerCase();
        var nome      = document.getElementById('pbi-nome').value.trim();
        var fil       = getSelectedFilters();

        var isCt = (relatorio === CT_SLUG);

        if (!relatorio) { pbiShowMsg('Selecione um relatório.', true); return; }
        if (!slug)       { pbiShowMsg('Informe o nome do portal (usado para gerar o link).', true); return; }
        if (!fil.values.length) { pbiShowMsg('Selecione pelo menos um filtro.', true); return; }

        var body = {
            relatorio_slug: relatorio,
            filter_type:    _tipo,
            filter_values:  isCt ? [] : fil.values,
            filter_labels:  isCt ? [] : fil.labels,
            slug:           slug,
            nome:           nome,
        };

        // Contabilidade: só a aba ativa (Indicador OU Contabilidade) é gravada — a outra
        // dimensão é limpa, mantendo o mesmo comportamento de filtro único do NimbusTax.
        if (isCt) {
            var ctCompleto   = (fil.values.length === 1 && fil.values[0] === '__completo__');
            var isIndicador  = (_tipo === 'indicador');
            body.ct_completo         = ctCompleto;
            body.ct_indicador_values = (!ctCompleto && isIndicador) ? fil.values : [];
            body.ct_indicador_labels = (!ctCompleto && isIndicador) ? fil.labels : [];
            body.ct_contab_values    = (!ctCompleto && !isIndicador) ? fil.values : [];
            body.ct_contab_labels    = (!ctCompleto && !isIndicador) ? fil.labels : [];
        }

        if (editId) {
            body.id = parseInt(editId);
            var novaSenha = document.getElementById('pbi-nova-senha').value.trim();
            if (novaSenha) body.senha = novaSenha;
        } else {
            var senha = document.getElementById('pbi-senha').value.trim();
            if (!senha) { pbiShowMsg('Informe ou gere uma senha.', true); return; }
            body.senha = senha;
        }

        fetch('/api/portais-bi.php?' + (editId ? 'action=update' : 'action=create'), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.erro) { pbiShowMsg(d.erro, true); return; }
            if (!editId && d.embed_token) {
                var link  = BASE + '/portal/' + relatorio + '/' + slug;
                var embed = '<iframe src="' + link + '?embed=' + d.embed_token
                    + '" width="100%" height="100vh" frameborder="0" style="border:none"><\/iframe>';
                pbiShowMsg('Portal criado! Link: ' + link + '\nEmbed copiado para a área de transferência.', false);
                navigator.clipboard.writeText(embed).catch(function () {});
            } else {
                pbiShowMsg('Portal atualizado.', false);
            }
            pbiResetForm();
            loadPortais();
        })
        .catch(function () { pbiShowMsg('Erro de rede.', true); });
    };

    // ── Busca por texto + filtro de empresa (100% client-side, com sessionStorage
    // bridge compartilhado com relatorios-bi.php — mesmas chaves, sem tabs/topbar) ──────
    function pbiSalvarFiltros() {
        try {
            sessionStorage.setItem('bi_filter_search', searchInp.value || '');
            sessionStorage.setItem('bi_filter_empresa_id', empresaSel.value || '');
        } catch (e) {}
    }
    function pbiAplicarFiltros() {
        var termo   = (searchInp.value || '').trim().toLowerCase();
        var empresa = empresaSel.value;
        document.querySelectorAll('#pbi-tbody tr[data-id]').forEach(function (tr) {
            var nomeOk    = !termo || (tr.getAttribute('data-nome') || '').indexOf(termo) !== -1;
            var empresas  = (tr.getAttribute('data-empresas') || '').split('|');
            var empresaOk = !empresa || empresas.indexOf(empresa) !== -1;
            tr.style.display = (nomeOk && empresaOk) ? '' : 'none';
        });
        pbiSalvarFiltros();
    }
    searchInp.addEventListener('input', pbiAplicarFiltros);
    empresaSel.addEventListener('change', pbiAplicarFiltros);

    function pbiPopularFiltroEmpresas(portais) {
        var mapa = new Map();
        portais.forEach(function (p) { (p.empresas || []).forEach(function (e) { mapa.set(String(e.id), e.nome); }); });
        var ordenado = Array.from(mapa.entries()).sort(function (a, b) { return a[1].localeCompare(b[1], 'pt-BR'); });
        empresaSel.innerHTML = '<option value="">Todas as empresas</option>' +
            ordenado.map(function (e) { return '<option value="' + esc(e[0]) + '">' + esc(e[1]) + '</option>'; }).join('');
        if (_empresaFiltroSalvo !== null) {
            var existe = Array.from(empresaSel.options).some(function (o) { return o.value === _empresaFiltroSalvo; });
            if (existe) empresaSel.value = _empresaFiltroSalvo;
            _empresaFiltroSalvo = null;
        }
    }

    // ── Carregar lista ─────────────────────────────────────────────────────
    function loadPortais() {
        fetch('/api/portais-bi.php?action=list')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                _portais = {};
                var portais = d.portais || [];
                portais.forEach(function (p) { _portais[p.id] = p; });
                pbiPopularFiltroEmpresas(portais);
                renderTable(portais);
                pbiAplicarFiltros();
            })
            .catch(function () {
                document.getElementById('pbi-tbody').innerHTML =
                    '<tr><td colspan="7" class="portais-empty" style="color:#fc8181">Erro ao carregar portais.</td></tr>';
            });
    }

    function renderTable(portais) {
        var count = document.getElementById('pbi-count');
        var tbody = document.getElementById('pbi-tbody');
        count.textContent = portais.length + ' portal' + (portais.length !== 1 ? 'is' : '');
        if (!portais.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="portais-empty">Nenhum portal criado ainda</td></tr>';
            return;
        }
        var html = '';
        portais.forEach(function (p) {
            var link  = BASE + '/portal/' + p.relatorio_slug + '/' + p.slug;
            var embed = '<iframe src="' + link + '?embed=' + p.embed_token
                + '" width="100%" height="100vh" frameborder="0" style="border:none"><\/iframe>';
            var badge = p.ativo
                ? '<span class="portais-badge portais-badge-ativo">Ativo</span>'
                : '<span class="portais-badge portais-badge-inativo">Inativo</span>';
            var nomePortal    = p.nome || p.slug || '';
            var empresasAttr  = (p.empresas || []).map(function (e) { return e.id; }).join('|');
            var filtros, tipoTxt;
            if (p.relatorio_slug === CT_SLUG) {
                var indTxt  = p.ct_completo ? 'Relatório Completo' : (p.ct_indicador_labels || []).join(', ');
                var contTxt = (p.ct_contab_labels || []).join(', ');
                filtros = indTxt + (contTxt ? ' | ' + contTxt : '');
                // Portais salvos pela UI nova já gravam 'indicador'/'contabilidade' em filter_type;
                // portais antigos (pré-toggle) mantêm o rótulo genérico de antes.
                tipoTxt = (p.filter_type === 'indicador' || p.filter_type === 'contabilidade')
                    ? p.filter_type : 'contabilidade';
            } else {
                filtros = (p.filter_labels || []).join(', ');
                tipoTxt = p.filter_type;
            }

            html += '<tr data-id="' + p.id + '" data-nome="' + esc(nomePortal.toLowerCase()) + '" data-empresas="' + esc(empresasAttr) + '">'
                + '<td>'
                    + '<div style="font-weight:600;color:#fff;font-size:.85rem">' + esc(p.relatorio_nome || p.relatorio_slug) + '</div>'
                    + '<div style="font-size:.65rem;color:rgba(255,255,255,.4);margin-top:.15rem">' + esc(nomePortal) + '</div>'
                + '</td>'
                + '<td><span class="portais-badge-tipo">' + esc(tipoTxt) + '</span></td>'
                + '<td style="font-size:.68rem;color:rgba(255,255,255,.7);max-width:200px;word-break:break-word;white-space:normal">' + esc(filtros) + '</td>'
                + '<td>' + badge + '</td>'
                + '<td style="text-align:center">'
                    + '<button class="portais-copy-btn" data-copy="' + esc(link) + '" data-orig-icon="fas fa-link" title="Copiar link"><i class="fas fa-link"></i></button>'
                + '</td>'
                + '<td style="text-align:center">'
                    + '<button class="portais-copy-btn" data-copy="' + esc(embed) + '" data-orig-icon="fas fa-code" title="Copiar embed"><i class="fas fa-code"></i></button>'
                + '</td>'
                + '<td class="portais-acoes-cell">'
                    + '<div class="portais-acoes-inner">'
                        + '<button class="portais-action-btn" data-action="edit" data-id="' + p.id + '">Editar</button>'
                        + '<button class="portais-action-btn" data-action="preview" data-slug="' + esc(p.slug) + '" title="Abrir relatório filtrado em nova aba">Visualizar</button>'
                        + '<button class="portais-action-btn warn" data-action="toggle" data-id="' + p.id + '">' + (p.ativo ? 'Desativar' : 'Ativar') + '</button>'
                        + '<button class="portais-action-btn danger" data-action="delete" data-id="' + p.id + '" data-nome="' + esc(p.nome || p.slug) + '">Excluir</button>'
                    + '</div>'
                + '</td>'
                + '</tr>';
        });
        tbody.innerHTML = html;
    }

    // ── Delegação de eventos para ações da tabela ──────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var tbody = document.getElementById('pbi-tbody');
        if (!tbody || !tbody.contains(btn)) return;
        var action = btn.dataset.action;
        var id     = parseInt(btn.dataset.id);
        if (action === 'edit')   window.pbiEdit(id);
        else if (action === 'preview') window.open('/api/portal-bi-preview.php?slug=' + encodeURIComponent(btn.dataset.slug), '_blank');
        else if (action === 'toggle') window.pbiToggle(id);
        else if (action === 'delete') window.pbiDelete(id, btn.dataset.nome);
    });

    // ── Ações ──────────────────────────────────────────────────────────────
    window.pbiEdit = function (id) {
        var p = _portais[id];
        if (!p) return;

        document.getElementById('pbi-edit-id').value   = p.id;
        document.getElementById('pbi-relatorio').value = p.relatorio_slug;
        document.getElementById('pbi-slug').value      = p.slug;
        document.getElementById('pbi-nome').value      = p.nome || '';

        // Show extra fields, then load filter list with selections applied inside pbiSetTipo
        document.getElementById('pbi-extra-fields').style.display = '';

        if (p.relatorio_slug === CT_SLUG) {
            // Contabilidade: escolhe a aba inicial (Indicador ou Contabilidade) a partir de
            // qual dimensão tem dados salvos — Indicador tem prioridade quando ambos existem
            // (portais antigos, salvos antes deste toggle existir, podiam ter as duas).
            var indSel  = p.ct_completo ? ['__completo__'] : (p.ct_indicador_values || []).map(String);
            var contSel = p.ct_completo ? ['__completo__'] : (p.ct_contab_values    || []).map(String);
            var temIndicador = p.ct_completo || (p.ct_indicador_values && p.ct_indicador_values.length);
            var temContab    = p.ct_contab_values && p.ct_contab_values.length;
            var tipoInicial  = (!temIndicador && temContab) ? 'contabilidade' : 'indicador';
            pbiSetTipo(tipoInicial, tipoInicial === 'indicador' ? indSel : contSel);
        } else {
            var tipoNimbus  = (p.filter_type === 'oportunidade') ? 'oportunidade' : 'parceiro';
            var selectedIds = (p.filter_values || []).map(String);
            pbiSetTipo(tipoNimbus, selectedIds);
        }

        document.getElementById('portais-form-title').textContent      = 'Editar Portal';
        document.getElementById('pbi-submit-label').textContent        = 'Salvar alterações';
        document.getElementById('pbi-cancel-btn').style.display        = '';
        document.getElementById('pbi-senha-field').style.display       = 'none';
        document.getElementById('pbi-nova-senha-field').style.display  = '';
        pbiOpenModal();
    };

    window.pbiToggle = function (id) {
        fetch('/api/portais-bi.php?action=toggle', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: id}),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.sucesso) loadPortais(); else pbiShowMsg(d.erro || 'Erro', true); });
    };

    window.pbiDelete = function (id, nome) {
        if (!confirm('Excluir portal "' + nome + '"? Esta ação não pode ser desfeita.')) return;
        fetch('/api/portais-bi.php?action=delete', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: id}),
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.sucesso) { pbiShowMsg('Portal excluído.', false); loadPortais(); }
            else pbiShowMsg(d.erro || 'Erro ao excluir', true);
        })
        .catch(function () { pbiShowMsg('Erro de rede ao excluir.', true); });
    };

    // ── Modal ─────────────────────────────────────────────────────────────────
    window.pbiOpenCreate = function () {
        pbiResetForm();
        pbiOpenModal();
    };

    window.pbiOpenModal = function () {
        document.getElementById('pbi-modal-overlay').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    window.pbiCloseModal = function () {
        document.getElementById('pbi-modal-overlay').style.display = 'none';
        document.body.style.overflow = '';
    };

    window.pbiModalOverlayClick = function (e) {
        if (e.target === document.getElementById('pbi-modal-overlay')) pbiCloseModal();
    };

    window.pbiResetForm = function () {
        document.getElementById('pbi-edit-id').value    = '';
        document.getElementById('pbi-relatorio').value  = '';
        document.getElementById('pbi-slug').value       = '';
        document.getElementById('pbi-nome').value       = '';
        document.getElementById('pbi-senha').value      = '';
        document.getElementById('pbi-nova-senha').value = '';

        // Volta ao estado padrão do toggle (Parceiro/Oportunidade) sem disparar fetch —
        // o modal já vai fechar em seguida.
        _tipo = 'parceiro';
        document.getElementById('pbi-tipo-parceiro').textContent    = 'Parceiro';
        document.getElementById('pbi-tipo-oportunidade').textContent = 'Oportunidade';
        document.getElementById('pbi-tipo-parceiro').onclick     = function () { pbiSetTipo('parceiro'); };
        document.getElementById('pbi-tipo-oportunidade').onclick = function () { pbiSetTipo('oportunidade'); };
        document.getElementById('pbi-tipo-parceiro').className    = 'portais-toggle-btn active';
        document.getElementById('pbi-tipo-oportunidade').className = 'portais-toggle-btn';
        document.getElementById('pbi-filtros-label').innerHTML =
            'Parceiros <span style="color:rgba(255,255,255,.25)">(selecione um ou mais)</span>';

        document.getElementById('pbi-extra-fields').style.display      = 'none';
        document.getElementById('portais-form-title').textContent      = 'Criar Portal';
        document.getElementById('pbi-submit-label').textContent        = 'Criar portal';
        document.getElementById('pbi-cancel-btn').style.display        = 'none';
        document.getElementById('pbi-senha-field').style.display       = '';
        document.getElementById('pbi-nova-senha-field').style.display  = 'none';
        pbiHideMsg();
        pbiCloseModal();
    };

    // ── Copy (delegado) ────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.portais-copy-btn');
        if (!btn || !('copy' in btn.dataset)) return;
        navigator.clipboard.writeText(btn.dataset.copy).catch(function () {
            var el = document.createElement('textarea');
            el.value = btn.dataset.copy; document.body.appendChild(el);
            el.select(); document.execCommand('copy'); document.body.removeChild(el);
        });
        var icon = btn.querySelector('i');
        if (icon) {
            var orig = btn.dataset.origIcon || 'fas fa-copy';
            icon.className = 'fas fa-check';
            setTimeout(function () { icon.className = orig; }, 1500);
        }
    });

    function pbiShowMsg(text, isErr) {
        var el = document.getElementById('pbi-msg');
        el.textContent = text;
        el.className = 'portais-msg ' + (isErr ? 'portais-msg-err' : 'portais-msg-ok');
        el.style.display = '';
    }
    function pbiHideMsg() { document.getElementById('pbi-msg').style.display = 'none'; }

    // ── Init ───────────────────────────────────────────────────────────────
    // Restaura busca/empresa salvos pelo bridge de sessionStorage (compartilhado com relatorios-bi.php).
    try {
        var savedSearch = sessionStorage.getItem('bi_filter_search');
        if (savedSearch !== null) searchInp.value = savedSearch;
        _empresaFiltroSalvo = sessionStorage.getItem('bi_filter_empresa_id');
    } catch (e) {}

    loadRelatorios();
    loadPortais();
})();
</script>
