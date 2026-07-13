/**
 * SIDEBAR JAVASCRIPT - Versão otimizada
 */

class SidebarManager {
    constructor() {
        this.sidebar = null;
        this.toggleBtn = null;
        this.hoverTimeout = null;
        this.isCollapsed = false;
        this.isHovered = false;
        
        this.config = {
            hoverDelay: 500,
            storageKey: 'sidebarState',
            mobileBreakpoint: 768
        };
        
        this.init();
    }

    init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.setupElements());
        } else {
            this.setupElements();
        }
    }

    setupElements() {
        this.sidebar = document.getElementById('sidebar');
        this.toggleBtn = document.getElementById('sidebarToggle');
        
        if (!this.sidebar || !this.toggleBtn) {
            console.warn('[Sidebar] Elementos não encontrados');
            return;
        }

        this.loadSavedState();
        this.bindEvents();
        this.setupAccessibility();
        this.handleResize();
        this.detectActivePage();
    }

    loadSavedState() {
        try {
            const savedState = localStorage.getItem(this.config.storageKey);
            if (savedState === 'collapsed') {
                this.setCollapsed(true, false);
            }
        } catch (error) {
            console.warn('[Sidebar] Erro ao carregar estado:', error);
        }
    }

    bindEvents() {
        this.toggleBtn.addEventListener('click', (e) => {
            this.toggle();
            e.target.blur();
        });
        
        this.sidebar.addEventListener('mouseenter', () => this.handleMouseEnter());
        this.sidebar.addEventListener('mouseleave', () => this.handleMouseLeave());
        this.sidebar.addEventListener('keydown', (e) => this.handleKeydown(e));
        
        window.addEventListener('resize', () => this.handleResize());
        
        this.sidebar.addEventListener('focusin', () => this.handleFocusIn());
        this.sidebar.addEventListener('focusout', (e) => this.handleFocusOut(e));
        
        this.setupMenuItemEvents();
    }

    setupMenuItemEvents() {
        const menuItems = this.sidebar.querySelectorAll('.sidebar-link');
        
        menuItems.forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();

                const menuData = this.extractMenuData(item);
                const submenus = this.getSubmenusForMenu(menuData.id);

                // Atualiza topbar
                document.dispatchEvent(new CustomEvent('sidebar:menuClick', {
                    detail: { menuItem: menuData, submenus: submenus }
                }));

                // Marca item ativo na sidebar
                this.sidebar.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
                item.classList.add('active');

                // Aba padrão ao abrir o menu = primeira aba permitida pelo perfil do usuário
                // (não necessariamente a primeira do array hardcoded nem o href fixo do link).
                const url = window.KW24TopbarPermissions
                    ? window.KW24TopbarPermissions.firstAllowedUrl(submenus, menuData.url)
                    : menuData.url;
                if (!url || url === '#') return;

                const ajaxUrl = url + (url.includes('?') ? '&' : '?') + 'ajax=1';
                const contentArea = document.querySelector('.content-area');

                contentArea.style.opacity = '0.4';

                fetch(ajaxUrl, { credentials: 'same-origin' })
                    .then(r => {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.text();
                    })
                    .then(html => {
                        if (!html.trim()) throw new Error('empty');
                        contentArea.innerHTML = html;
                        contentArea.style.opacity = '1';
                        history.pushState(null, '', url);
                        // Re-execute inline scripts injected via innerHTML
                        contentArea.querySelectorAll('script').forEach(old => {
                            const s = document.createElement('script');
                            [...old.attributes].forEach(a => s.setAttribute(a.name, a.value));
                            s.textContent = old.textContent;
                            old.replaceWith(s);
                        });
                    })
                    .catch(() => {
                        // Fallback: recarrega a página normalmente
                        window.location.href = url;
                    });
            });
        });
    }

    extractMenuData(menuElement) {
        const icon = menuElement.querySelector('i');
        const text = menuElement.querySelector('.sidebar-link-text');
        
        return {
            id: this.generateMenuId(text?.textContent || 'menu'),
            text: text?.textContent || 'Menu',
            icon: icon?.className || 'fas fa-circle',
            url: menuElement.href || '#',
            element: menuElement
        };
    }

    generateMenuId(text) {
        return text.toLowerCase()
            .replace(/\s+/g, '-')
            .replace(/[^a-z0-9-]/g, '')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    }

    getSubmenusForMenu(menuId) {
        const submenusMap = {
            'dashboard': [
                { id: 'dash-overview', text: 'Visão Geral', icon: 'fas fa-chart-line', url: '?page=dashboard&view=overview' },
                { id: 'dash-analytics', text: 'Analytics', icon: 'fas fa-chart-bar', url: '?page=dashboard&view=analytics' },
                { id: 'dash-kpi', text: 'KPIs', icon: 'fas fa-tachometer-alt', url: '?page=dashboard&view=kpi' },
                { id: 'dash-monitoramento', text: 'Monitoramento KW24', icon: 'fas fa-satellite-dish', url: '?page=monitoramento' }
            ],
            'cadastro': [
                { id: 'cad-organizacoes', text: 'Organizações', icon: 'fas fa-sitemap',    url: '?page=organizacoes' },
                { id: 'cad-clientes',     text: 'Clientes',     icon: 'fas fa-building',   url: '?page=cadastro' },
                { id: 'cad-usuarios',     text: 'Usuários',     icon: 'fas fa-users',      url: '?page=usuarios' },
                { id: 'cad-permissoes',   text: 'Permissões',   icon: 'fas fa-shield-alt', url: '?page=permissoes' },
                { id: 'cad-aplicacoes',   text: 'Aplicações',   icon: 'fas fa-th',         url: '?page=aplicacoes' }
            ],
            'usuários': [
                { id: 'usr-lista', text: 'Lista de Usuários', icon: 'fas fa-users',    url: '?page=usuarios' },
                { id: 'usr-novo',  text: 'Novo Usuário',      icon: 'fas fa-user-plus', url: '?page=usuarios&action=novo' }
            ],
            'relatórios': [
                { id: 'rel-clientes', text: 'Relatório de Clientes', icon: 'fas fa-users', url: '?page=relatorio&type=clientes' },
                { id: 'rel-vendas', text: 'Relatório de Vendas', icon: 'fas fa-chart-line', url: '?page=relatorio&type=vendas' },
                { id: 'rel-custom', text: 'Relatório Personalizado', icon: 'fas fa-cogs', url: '?page=relatorio&type=custom' }
            ],
            'logs': [
                { id: 'log-system', text: 'Logs do Sistema', icon: 'fas fa-server', url: '?page=logs&type=system' },
                { id: 'log-user', text: 'Logs de Usuário', icon: 'fas fa-user-clock', url: '?page=logs&type=user' },
                { id: 'log-errors', text: 'Logs de Erro', icon: 'fas fa-exclamation-triangle', url: '?page=logs&type=errors' }
            ],
            'configuracoes': [
                { id: 'config-colaboradores', text: 'Colaboradores', icon: 'fas fa-users-cog', url: '?page=configuracoes&action=colaboradores' },
                { id: 'config-permissoes', text: 'Permissões', icon: 'fas fa-shield-alt', url: '?page=configuracoes&action=permissoes' },
                { id: 'config-sistema', text: 'Sistema', icon: 'fas fa-tools', url: '?page=configuracoes&action=sistema' }
            ],
            'relatrios-bi': [
                { id: 'bi-relatorios', text: 'Relatórios', icon: 'fas fa-chart-bar', url: '?page=relatorios-bi' },
                { id: 'bi-portais',    text: 'Portais',    icon: 'fas fa-globe',     url: '?page=portais-bi' }
            ],
            'financeiro': (() => {
                const items = [
                    { id: 'fin-dashboard',  text: 'Dashboard',  icon: 'fas fa-chart-pie',           url: '?page=financeiro' },
                    { id: 'fin-relatorios', text: 'Relatórios', icon: 'fas fa-file-invoice-dollar', url: '?page=financeiro-relatorios' }
                ];
                if (this.sidebar && this.sidebar.dataset.perfil === 'admin_interno') {
                    items.push({ id: 'fin-portais', text: 'Portais', icon: 'fas fa-globe', url: '?page=portais' });
                }
                return items;
            })()
        };
        
        return submenusMap[menuId] || [];
    }

    setupAccessibility() {
        this.sidebar.setAttribute('role', 'navigation');
        this.sidebar.setAttribute('aria-label', 'Menu principal');
        this.updateAriaStates();
        
        const links = this.sidebar.querySelectorAll('.sidebar-link');
        links.forEach(link => {
            link.setAttribute('tabindex', '0');
        });
    }

    toggle() {
        this.setCollapsed(!this.isCollapsed);
        this.saveState();
    }

    setCollapsed(collapsed, animate = true) {
        this.isCollapsed = collapsed;
        this.setHovered(false);
        
        if (collapsed) {
            this.sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
        } else {
            this.sidebar.classList.remove('collapsed');
            document.body.classList.remove('sidebar-collapsed');
        }

        this.updateAriaStates();
        this.dispatchStateChange();
    }

    handleMouseEnter() {
        if (!this.isCollapsed) return;
        
        if (this.hoverTimeout) {
            clearTimeout(this.hoverTimeout);
        }
        
        this.hoverTimeout = setTimeout(() => {
            this.setHovered(true);
        }, this.config.hoverDelay);
    }

    handleMouseLeave() {
        if (this.hoverTimeout) {
            clearTimeout(this.hoverTimeout);
            this.hoverTimeout = null;
        }
        this.setHovered(false);
    }

    setHovered(hovered) {
        if (this.isHovered === hovered) return;
        
        this.isHovered = hovered;
        
        if (hovered) {
            this.sidebar.classList.add('hovered');
        } else {
            this.sidebar.classList.remove('hovered');
        }
    }

    handleKeydown(event) {
        switch (event.key) {
            case 'Escape':
                if (this.isCollapsed && this.isHovered) {
                    this.setHovered(false);
                    event.preventDefault();
                }
                break;
                
            case 'Enter':
            case ' ':
                if (event.target === this.toggleBtn) {
                    this.toggle();
                    event.preventDefault();
                }
                break;
        }
    }

    handleResize() {
        const isMobile = window.innerWidth <= this.config.mobileBreakpoint;
        
        if (isMobile) {
            this.sidebar.classList.add('mobile');
        } else {
            this.sidebar.classList.remove('mobile');
        }
    }

    detectActivePage() {
        const params  = new URLSearchParams(window.location.search);
        const rawPage = params.get('page') || 'dashboard';
        // Sub-páginas do cadastro ativam o link "Cadastro" na sidebar
        const subpageMap = { 'organizacoes': 'cadastro', 'usuarios': 'cadastro', 'aplicacoes': 'cadastro', 'permissoes': 'cadastro', 'financeiro-relatorios': 'financeiro', 'portais': 'financeiro', 'portais-bi': 'relatorios-bi', 'monitoramento': 'dashboard' };
        const curPage = subpageMap[rawPage] || rawPage;

        const links = this.sidebar.querySelectorAll('.sidebar-link');
        links.forEach(link => {
            try {
                const linkParams = new URLSearchParams(new URL(link.href).search);
                const linkPage   = linkParams.get('page') || 'dashboard';

                if (linkPage === curPage) {
                    links.forEach(l => l.classList.remove('active'));
                    link.classList.add('active');

                    const menuData = this.extractMenuData(link);
                    const submenus = this.getSubmenusForMenu(menuData.id);

                    // Aguarda topbar estar pronto antes de disparar o evento
                    setTimeout(() => {
                        document.dispatchEvent(new CustomEvent('sidebar:menuClick', {
                            detail: { menuItem: menuData, submenus: submenus }
                        }));
                    }, 600);
                }
            } catch(e) {}
        });
    }

    handleFocusIn() {
        if (this.isCollapsed) {
            this.setHovered(true);
        }
    }

    handleFocusOut(event) {
        setTimeout(() => {
            if (!this.sidebar.contains(document.activeElement)) {
                this.setHovered(false);
            }
        }, 100);
    }

    saveState() {
        try {
            const state = this.isCollapsed ? 'collapsed' : 'expanded';
            localStorage.setItem(this.config.storageKey, state);
        } catch (error) {
            console.warn('[Sidebar] Erro ao salvar estado:', error);
        }
    }

    updateAriaStates() {
        this.toggleBtn.setAttribute('aria-expanded', !this.isCollapsed);
        this.sidebar.setAttribute('aria-label', 
            this.isCollapsed ? 'Menu lateral colapsado' : 'Menu lateral expandido'
        );
    }

    dispatchStateChange() {
        const event = new CustomEvent('sidebarStateChange', {
            detail: {
                collapsed: this.isCollapsed,
                hovered: this.isHovered
            }
        });
        document.dispatchEvent(event);
        
        if (this.isCollapsed) {
            const collapseEvent = new CustomEvent('sidebar:collapsed');
            document.dispatchEvent(collapseEvent);
        } else {
            const expandEvent = new CustomEvent('sidebar:expanded');
            document.dispatchEvent(expandEvent);
        }
    }

    getState() {
        return {
            collapsed: this.isCollapsed,
            hovered: this.isHovered
        };
    }

    destroy() {
        if (this.hoverTimeout) {
            clearTimeout(this.hoverTimeout);
        }
        window.removeEventListener('resize', this.handleResize);
    }
}

// Auto-inicialização
let sidebarManager;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        sidebarManager = new SidebarManager();
    });
} else {
    sidebarManager = new SidebarManager();
}

// Export para uso global
window.SidebarManager = SidebarManager;
window.sidebarManager = sidebarManager;
