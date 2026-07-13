<?php
// $allowedPagesByProfile vem do index.php (null = irrestrito, array = lista de páginas permitidas)
function _sidebarOk(string $key): bool {
    global $allowedPagesByProfile;
    return $allowedPagesByProfile === null || in_array($key, $allowedPagesByProfile, true);
}
function _sidebarGroupOk(array $keys): bool {
    global $allowedPagesByProfile;
    if ($allowedPagesByProfile === null) return true;
    foreach ($keys as $k) {
        if (in_array($k, $allowedPagesByProfile, true)) return true;
    }
    return false;
}
$_sidebarAllowedJson = $allowedPagesByProfile === null ? 'null' : json_encode($allowedPagesByProfile);

// Relatórios BI — item do menu some por completo se não houver nenhum relatório
// liberado para o usuário via aplicação (cliente_aplicacoes), exceto para admin_interno.
$_sidebarMostraRelatoriosBi = ($user_data['perfil'] ?? '') === 'admin_interno'
    || !empty($_SESSION['relatorios_visiveis']);
?>
<nav class="sidebar" id="sidebar"
     data-perfil="<?= htmlspecialchars($user_data['perfil'] ?? '') ?>"
     data-allowed-menus="<?= htmlspecialchars($_sidebarAllowedJson) ?>">
    <!-- Header -->
    <div class="sidebar-header">
        <div class="sidebar-link">
            <div class="sidebar-link-inner">
                <span class="sidebar-link-icon">
                    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                </span>
                <span class="sidebar-link-text">KW24</span>
            </div>
        </div>
    </div>

    <!-- Menu Principal -->
    <ul class="sidebar-menu">
        <?php if (_sidebarOk('dashboard')): ?>
        <li>
            <a href="?page=dashboard" class="sidebar-link active">
                <div class="sidebar-link-inner">
                    <span class="sidebar-link-icon"><i class="fas fa-home"></i></span>
                    <span class="sidebar-link-text">Dashboard</span>
                </div>
            </a>
        </li>
        <?php endif; ?>
        <?php if (_sidebarGroupOk(['cadastro', 'usuarios', 'aplicacoes', 'permissoes', 'organizacoes'])): ?>
        <li>
            <a href="?page=organizacoes" class="sidebar-link">
                <div class="sidebar-link-inner">
                    <span class="sidebar-link-icon"><i class="fas fa-plus-circle"></i></span>
                    <span class="sidebar-link-text">Cadastro</span>
                </div>
            </a>
        </li>
        <?php endif; ?>
        <?php if (_sidebarGroupOk(['financeiro', 'financeiro-relatorios', 'portais'])): ?>
        <li>
            <a href="?page=financeiro" class="sidebar-link">
                <div class="sidebar-link-inner">
                    <span class="sidebar-link-icon"><i class="fas fa-dollar-sign"></i></span>
                    <span class="sidebar-link-text">Financeiro</span>
                </div>
            </a>
        </li>
        <?php endif; ?>
        <li style="padding:.4rem 1rem" aria-hidden="true">
            <div style="display:flex;align-items:center;gap:.5rem">
                <span style="flex:1;height:1px;background:rgba(255,255,255,.13)"></span>
                <span style="font-size:.62rem;font-weight:700;letter-spacing:.08em;color:rgba(255,255,255,.35);text-transform:uppercase;white-space:nowrap">Aplicações</span>
                <span style="flex:1;height:1px;background:rgba(255,255,255,.13)"></span>
            </div>
        </li>
        <?php if (_sidebarGroupOk(['relatorios-bi', 'portais-bi']) && $_sidebarMostraRelatoriosBi): ?>
        <li>
            <a href="?page=relatorios-bi" class="sidebar-link">
                <div class="sidebar-link-inner">
                    <span class="sidebar-link-icon"><i class="fas fa-chart-bar"></i></span>
                    <span class="sidebar-link-text">Relatórios BI</span>
                </div>
            </a>
        </li>
        <?php endif; ?>
        <?php if (_sidebarOk('mcp-bitrix24')): ?>
        <li>
            <a href="?page=mcp-bitrix24" class="sidebar-link">
                <div class="sidebar-link-inner">
                    <span class="sidebar-link-icon"><i class="fas fa-robot"></i></span>
                    <span class="sidebar-link-text">MCP Bitrix24</span>
                </div>
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <!-- Menu Admin no FINAL DA SIDEBAR (separado) -->
    <?php if (isset($user_data['perfil']) && $user_data['perfil'] === 'admin_interno'): ?>
    <div class="sidebar-footer">
        <ul class="sidebar-admin-menu">
            <li>
                <a href="?page=monitoramento" class="sidebar-link sidebar-admin-item">
                    <div class="sidebar-link-inner">
                        <span class="sidebar-link-icon"><i class="fas fa-satellite-dish"></i></span>
                        <span class="sidebar-link-text">Monitoramento KW24</span>
                    </div>
                </a>
            </li>
            <li>
                <a href="?page=configuracoes" class="sidebar-link sidebar-admin-item">
                    <div class="sidebar-link-inner">
                        <span class="sidebar-link-icon"><i class="fas fa-cog"></i></span>
                        <span class="sidebar-link-text">Configurações</span>
                    </div>
                </a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</nav>
