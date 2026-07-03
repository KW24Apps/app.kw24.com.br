<?php
if (!defined('SYSTEM_ACCESS') && !isset($user_data)) {
    header('Location: /public/login.php');
    exit;
}
?>
<link rel="stylesheet" href="/assets/css/painel-cliente.css">
<?php

require_once __DIR__ . '/../helpers/Database.php';

try {
    $db     = Database::getInstance();
    $busca  = trim($_GET['busca'] ?? '');
    $perfil = $user_data['perfil'] ?? '';

    $where  = [];
    $params = [];

    if ($busca) {
        $where[]     = '(nome ILIKE :b OR cnpj ILIKE :b OR email ILIKE :b)';
        $params['b'] = "%{$busca}%";
    }

    // Admin Interno vê todos os clientes. Admin Cliente e Usuário Cliente só veem
    // os clientes vinculados a eles via cliente_usuarios.
    if ($perfil !== 'admin_interno') {
        $where[]       = 'id IN (SELECT cliente_id FROM cliente_usuarios WHERE usuario_id = :uid)';
        $params['uid'] = $user_data['id'] ?? 0;
    }

    $sql = 'SELECT id, nome, cnpj, telefone, email FROM clientes';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY nome ASC';

    $clientes = $db->fetchAll($sql, $params);
    $total    = count($clientes);

} catch (Exception $e) {
    echo '<div style="color:#e53e3e;padding:2rem">Erro ao carregar clientes: ' . htmlspecialchars($e->getMessage()) . '</div>';
    return;
}
?>
<div class="page-header">
    <h1 class="page-title"><i class="fas fa-building"></i> Clientes</h1>
    <div class="page-header-actions">
        <form method="GET" style="display:contents">
            <input type="hidden" name="page" value="cadastro">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" name="busca" placeholder="Buscar por nome, CNPJ ou e-mail..."
                       value="<?= htmlspecialchars($busca) ?>" autocomplete="off">
            </div>
        </form>
        <button onclick="abrirNovoCliente()" class="btn-primary">
            <i class="fas fa-plus"></i> Novo Cliente
        </button>
    </div>
</div>

<div class="table-panel">
    <div class="table-scroll">
    <table class="clientes-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Cliente</th>
                <th>CNPJ</th>
                <th>Telefone</th>
                <th>E-mail</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($clientes)): ?>
            <tr><td colspan="8">
                <div class="empty-state">
                    <i class="fas fa-building"></i>
                    <p>Nenhum cliente encontrado.</p>
                </div>
            </td></tr>
        <?php else: ?>
            <?php foreach ($clientes as $c): ?>
            <tr onclick="abrirCliente(<?= $c['id'] ?>)" style="cursor:pointer">
                <td style="color:#4a5568;font-size:.85rem"><?= $c['id'] ?></td>
                <td>
                    <div class="cliente-info">
                        <div class="cliente-avatar"><?= mb_strtoupper(mb_substr($c['nome'], 0, 2)) ?></div>
                        <span class="cliente-nome">
                            <?= htmlspecialchars($c['nome']) ?>
                        </span>
                    </div>
                </td>
                <td><?= htmlspecialchars($c['cnpj'] ?? '—') ?></td>
                <td><?= htmlspecialchars($c['telefone'] ?? '—') ?></td>
                <td><?= htmlspecialchars($c['email'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div><!-- /table-scroll -->
    <div class="table-footer"><?= $total ?> cliente<?= $total !== 1 ? 's' : '' ?> encontrado<?= $total !== 1 ? 's' : '' ?></div>
</div>

<!-- Modal de configuração de app -->
<div id="app-config-overlay" class="cliente-overlay" onclick="fecharModalApp()" style="z-index:1100"></div>
<div id="app-config-modal" class="app-config-modal">
    <div class="app-modal-header">
        <div class="app-modal-icon" id="app-modal-icon"><i class="fas fa-puzzle-piece"></i></div>
        <div>
            <h3 id="app-modal-nome" style="margin:0;font-size:1rem;font-weight:700;color:#1a202c"></h3>
            <p id="app-modal-slug" style="margin:0;font-size:.75rem;color:#a0aec0"></p>
        </div>
        <button class="panel-close" onclick="fecharModalApp()" style="margin-left:auto"><i class="fas fa-times"></i></button>
    </div>
    <div class="app-modal-body" id="app-modal-body">
        <p style="color:#718096;font-size:.9rem">Configurações em construção.</p>
    </div>
</div>

<!-- Modal de ativar aplicação -->
<div id="ativar-overlay" class="cliente-overlay" onclick="fecharModalAtivar()" style="z-index:1100"></div>
<div id="ativar-modal" class="app-config-modal" style="width:480px">
    <div class="app-modal-header">
        <div><h3 style="margin:0;font-size:1rem;font-weight:700;color:#1a202c">Ativar Aplicação</h3></div>
        <button class="panel-close" onclick="fecharModalAtivar()" style="margin-left:auto"><i class="fas fa-times"></i></button>
    </div>
    <div class="app-modal-body" id="ativar-lista"></div>
</div>

<!-- Overlay -->
<div id="cliente-overlay" class="cliente-overlay" onclick="fecharPainel()"></div>

<!-- Painel lateral -->
<div id="cliente-panel" class="cliente-panel">
    <div class="panel-header">
        <div class="panel-avatar" id="panel-avatar">--</div>
        <div class="panel-header-info">
            <h2 class="panel-title" id="panel-nome">Carregando...</h2>
            <p class="panel-subtitle" id="panel-cnpj"></p>
        </div>
        <div style="position:relative;margin-left:auto">
            <button id="btn-menu-cliente" onclick="toggleMenuCliente(event)"
                style="width:36px;height:36px;border:none;background:#f0f4f8;border-radius:50%;cursor:pointer;font-size:1.1rem;color:#718096;display:flex;align-items:center;justify-content:center;transition:background .15s"
                onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f0f4f8'">
                &#8942;
            </button>
            <div id="menu-cliente-dropdown" style="display:none;position:absolute;right:0;top:42px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.12);min-width:180px;z-index:100;overflow:hidden">
                <button onclick="excluirCliente()" style="width:100%;padding:.7rem 1rem;border:none;background:none;text-align:left;cursor:pointer;color:#c53030;font-size:.875rem;display:flex;align-items:center;gap:.6rem;transition:background .15s"
                    onmouseover="this.style.background='#fff5f5'" onmouseout="this.style.background='none'">
                    <i class="fas fa-trash" style="width:16px"></i> Excluir cliente
                </button>
            </div>
        </div>
    </div>

    <div class="panel-body">
        <div id="panel-loading" class="panel-loading">
            <i class="fas fa-spinner fa-spin"></i> Carregando...
        </div>

        <!-- Modo Novo Cliente -->
        <div id="panel-novo" style="display:none">
            <div class="panel-section-title">Dados do Cliente</div>
            <div style="display:grid;gap:.75rem">
                <div class="panel-field no-edit"><label>Nome / Razão Social *</label>
                    <input type="text" id="novo-nome" class="form-input" placeholder="Nome completo da empresa" required>
                </div>
                <div class="panel-field no-edit"><label>CNPJ *</label>
                    <input type="text" id="novo-cnpj" class="form-input" placeholder="00.000.000/0001-00" required>
                </div>
                <div class="panel-field no-edit"><label>Telefone *</label>
                    <input type="text" id="novo-telefone" class="form-input" placeholder="55 48 99999-0000" required>
                </div>
                <div class="panel-field no-edit"><label>E-mail *</label>
                    <input type="email" id="novo-email" class="form-input" placeholder="contato@empresa.com.br" required>
                </div>
                <div class="panel-field no-edit"><label>Endereço *</label>
                    <input type="text" id="novo-endereco" class="form-input" placeholder="Rua, número - Bairro, Cidade-UF - CEP" required>
                </div>
                <div class="panel-field no-edit"><label>Organização</label>
                    <select id="novo-org-id" class="form-input">
                        <option value="">— Nenhuma —</option>
                    </select>
                </div>
                <div class="panel-divider"></div>
                <div class="panel-section-title">Integração Bitrix24</div>
                <div class="panel-field no-edit"><label>Link Bitrix24 *</label>
                    <input type="url" id="novo-link-bitrix" class="form-input" placeholder="https://suaempresa.bitrix24.com.br/" required>
                </div>
                <div class="panel-field no-edit"><label>ID Bitrix24</label>
                    <input type="number" id="novo-id-bitrix" class="form-input" placeholder="Ex: 2407">
                </div>
                <div id="novo-cliente-erro" style="color:#e53e3e;font-size:.85rem;display:none"></div>
            </div>
        </div>

        <div id="panel-conteudo" style="display:none">
            <div class="panel-grid">
                <!-- Coluna esquerda: dois cards -->
                <div>
                    <!-- Card A — Dados do Cliente -->
                    <div class="info-card">
                        <div class="info-card-header">
                            <i class="far fa-building"></i> Dados do Cliente
                        </div>
                        <div class="info-card-body">
                            <div class="panel-field no-edit"><label>ID</label><span id="pf-id"></span></div>
                            <div class="panel-field" data-campo="nome" onclick="editarCampo(this)"><label>Nome</label><span id="pf-nome"></span></div>
                            <div class="panel-field" data-campo="cnpj" onclick="editarCampo(this)"><label>CNPJ</label><span id="pf-cnpj"></span></div>
                            <div class="panel-field" data-campo="telefone" onclick="editarCampo(this)"><label>Telefone</label><span id="pf-telefone"></span></div>
                            <div class="panel-field" data-campo="email" onclick="editarCampo(this)"><label>E-mail</label><span id="pf-email"></span></div>
                            <div class="panel-field" data-campo="endereco" data-tipo="textarea" onclick="editarCampo(this)"><label>Endereço</label><span id="pf-endereco"></span></div>
                        </div>
                    </div>

                    <!-- Card B — Integração Bitrix24 -->
                    <div class="info-card bitrix">
                        <div class="info-card-header">
                            <i class="fas fa-plug"></i> Integração Bitrix24
                        </div>
                        <div class="info-card-body">
                            <div class="panel-field no-edit"><label>Organização</label>
                                <select id="pf-org-select" class="form-input" onchange="orgDropdownChange(this.value)" style="font-size:.85rem;padding:.35rem .5rem">
                                    <option value="">— Nenhuma —</option>
                                </select>
                            </div>
                            <div class="panel-field" data-campo="link_bitrix" onclick="editarCampo(this)"><label>Link Bitrix24</label><span id="pf-bitrix"></span></div>
                            <div class="panel-field no-edit"><label>Chave de Acesso</label>
                                <div id="pf-chave-wrap"></div>
                            </div>
                            <div class="panel-field" data-campo="id_bitrix" onclick="editarCampo(this)"><label>ID Bitrix24</label><span id="pf-id-bitrix"></span></div>
                        </div>
                    </div>
                </div>

                <!-- Coluna direita: tabs Aplicações / Usuários -->
                <div>
                    <div class="right-tabs">
                        <button class="right-tab-btn active" onclick="switchRightTab('apps', this)">
                            <i class="fas fa-puzzle-piece"></i> Aplicações
                        </button>
                        <button class="right-tab-btn" onclick="switchRightTab('users', this)">
                            <i class="fas fa-users"></i> Usuários
                        </button>
                        <div style="margin-left:auto">
                            <button id="right-tab-action-apps" class="btn-ativar-app" onclick="abrirModalAtivar()">
                                <i class="fas fa-plus"></i> Ativar
                            </button>
                            <button id="right-tab-action-users" class="btn-ativar-app" onclick="abrirVincularUsuario()" style="display:none">
                                <i class="fas fa-plus"></i> Vincular
                            </button>
                        </div>
                    </div>

                    <div class="right-tab-content active" id="right-tab-apps">
                        <div id="panel-apps-lista"></div>
                    </div>
                    <div class="right-tab-content" id="right-tab-users">
                        <div id="panel-usuarios-lista"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Barra de salvar (aparece quando há edições) -->
    <div class="panel-save-bar" id="panel-save-bar">
        <button class="btn-salvar" onclick="salvarEdicoes()"><i class="fas fa-check"></i> Salvar</button>
        <button class="btn-cancelar-edit" onclick="cancelarEdicoes()">Cancelar</button>
        <span class="save-bar-msg" id="save-msg"></span>
    </div>
</div>

<!-- JS em assets/js/painel-cliente.js -->
