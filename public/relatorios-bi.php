<?php
if (!defined('SYSTEM_ACCESS') && !isset($user_data)) {
    header('Location: /public/login.php'); exit;
}
// admin_interno vê todos os relatórios; demais usuários são filtrados pelos slugs
// liberados via aplicação (calculados na sessão em index.php: $_SESSION['relatorios_visiveis']).
$_rtIsAdmin  = ($user_data['perfil'] ?? '') === 'admin_interno';
$_rtVisiveis = $_rtIsAdmin ? null : ($_SESSION['relatorios_visiveis'] ?? []);

// Slugs com miniatura estática disponível (assets/img/relatorios/thumbs/{slug}.html) —
// checagem simples de filesystem, sem tocar em relatorios-bi/ (Python/Dash, fora de escopo).
$_rtThumbsDisponiveis = [];
$_rtThumbsDir = __DIR__ . '/../assets/img/relatorios/thumbs';
if (is_dir($_rtThumbsDir)) {
    foreach (glob($_rtThumbsDir . '/*.html') as $_rtThumbFile) {
        $_rtThumbsDisponiveis[] = basename($_rtThumbFile, '.html');
    }
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<script>
window.REL_TESTE_VISIVEIS     = <?= $_rtIsAdmin ? 'null' : json_encode($_rtVisiveis) ?>;
window.RBI_THUMBS_DISPONIVEIS = <?= json_encode($_rtThumbsDisponiveis) ?>;
window.RBI_IS_ADMIN           = <?= json_encode($_rtIsAdmin) ?>;
</script>
<link rel="stylesheet" href="/assets/css/relatorios-bi.css">

<div class="rbi-wrap">

    <!-- Top bar -->
    <div class="rbi-topbar">
        <div class="rbi-page-header">
            <i class="ti ti-chart-bar rbi-page-icon"></i>
            <span class="rbi-page-title">Relatórios BI</span>
        </div>
        <div class="rbi-controls">
            <div class="rbi-search-wrap">
                <i class="ti ti-search"></i>
                <input type="text" id="rbi-search" placeholder="Buscar..." autocomplete="off">
            </div>
            <select class="rbi-select" id="rbi-empresa-filter">
                <option value="">Todas as empresas</option>
            </select>
            <div class="rbi-view-toggle">
                <button type="button" class="rbi-view-btn" id="rbi-view-grid" title="Visualização em grade">
                    <i class="ti ti-layout-grid"></i>
                </button>
                <button type="button" class="rbi-view-btn" id="rbi-view-list" title="Visualização em lista">
                    <i class="ti ti-layout-list"></i>
                </button>
            </div>
            <?php if ($_rtIsAdmin): ?>
            <button type="button" class="rbi-btn-add" id="rbi-btn-add-relatorio" title="Criar relatório">
                <i class="ti ti-plus"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cards -->
    <div class="rbi-cards-row view-grid" id="rbi-cards-row">
        <span class="rbi-empty">Carregando...</span>
    </div>

</div>

<!-- Config modal — Geral (todos) + Conexão (admin_interno only, aba extra) -->
<div class="rbi-overlay" id="rbi-overlay">
    <div class="rbi-modal" id="rbi-modal">
        <div class="rbi-modal-head">
            <span class="rbi-modal-title">Configurar relatório</span>
            <button class="rbi-modal-close" id="rbi-modal-close" title="Fechar">&times;</button>
        </div>

        <?php if ($_rtIsAdmin): ?>
        <div class="rbi-tab-bar" id="rbi-tab-bar">
            <button type="button" class="rbi-tab-btn active" id="rbi-tab-btn-geral">Geral</button>
            <button type="button" class="rbi-tab-btn" id="rbi-tab-btn-conexao">Conexão</button>
        </div>
        <?php endif; ?>

        <!-- Aba Geral (nome amigável, visibilidade, abrir relatório) -->
        <div class="rbi-tab-content" id="rbi-tab-geral">
            <input type="hidden" id="rbi-edit-id">
            <input type="hidden" id="rbi-edit-slug">

            <div class="rbi-field">
                <label class="rbi-field-label">Nome amigável</label>
                <input type="text" class="rbi-field-input" id="rbi-edit-nome" autocomplete="off">
            </div>

            <?php if ($_rtIsAdmin): ?>
            <!-- Logo padrão do relatório — usado no card de login do Portal BI por qualquer
                 portal deste relatório que não tenha seu próprio logo (ver portais-bi.php).
                 Upload é imediato (não depende do botão Salvar) — o próprio input dispara o POST. -->
            <div class="rbi-field">
                <label class="rbi-field-label">Logo do relatório <span style="text-transform:none;font-weight:400;color:rgba(255,255,255,.3)">(padrão para portais sem logo próprio)</span></label>
                <div class="rbi-logo-row">
                    <img id="rbi-logo-preview" class="rbi-logo-preview" style="display:none" alt="Logo atual">
                    <span id="rbi-logo-empty" class="rbi-logo-empty">Nenhum — usa o logo KW24</span>
                    <label class="rbi-btn-logo-upload">
                        <i class="ti ti-upload"></i> Enviar logo
                        <input type="file" id="rbi-logo-input" accept=".png,.jpg,.jpeg,.svg" style="display:none">
                    </label>
                    <button type="button" class="rbi-btn-logo-remove" id="rbi-logo-remove-btn" style="display:none">Remover</button>
                </div>
                <div id="rbi-logo-msg" class="rbi-logo-msg" style="display:none"></div>
            </div>
            <?php endif; ?>

            <div class="rbi-field">
                <label class="rbi-field-label">Visibilidade</label>
                <div class="rbi-vis-row">
                    <button class="rbi-vis-btn" id="rbi-vis-visivel" data-val="true">Visível</button>
                    <button class="rbi-vis-btn" id="rbi-vis-oculto"  data-val="false">Oculto</button>
                </div>
            </div>

            <?php if ($_rtIsAdmin): ?>
            <div class="rbi-field">
                <label class="rbi-field-label">Status</label>
                <!-- Enquanto em_construcao=true: só o botão "Publicar" (ação única, irreversível).
                     Depois de publicado: rótulo neutro confirmando — nunca mais um botão pra voltar. -->
                <button type="button" class="rbi-btn-publicar" id="rbi-btn-publicar" style="display:none">
                    <i class="ti ti-rocket" style="margin-right:.35rem"></i>Publicar relatório
                </button>
                <div class="rbi-publicado-label" id="rbi-publicado-label" style="display:none">
                    <i class="ti ti-circle-check-filled"></i> Publicado — visível conforme a Visibilidade abaixo
                </div>
            </div>
            <?php endif; ?>

            <button class="rbi-btn-open" id="rbi-btn-open">
                <i class="ti ti-external-link" style="margin-right:.35rem"></i>Abrir relatório
            </button>

            <?php if ($_rtIsAdmin): ?>
            <!-- Mover para lixeira — disponível pra QUALQUER relatório (publicado ou rascunho).
                 Reversível: fica na tela Lixeira por 30 dias (restaurar ou excluir definitivamente
                 de lá) — nunca apaga dado aqui. Resumo (empresas/usuários/portais) buscado ao abrir
                 o modal (ver fetchResumoLixeira em openModal). -->
            <div class="rbi-lixeira-box" id="rbi-lixeira-box">
                <label class="rbi-field-label" style="color:#ffb800">Mover para lixeira</label>
                <p class="rbi-lixeira-resumo" id="rbi-lixeira-resumo">Carregando resumo...</p>
                <p class="rbi-delete-warn">Reversível — fica na Lixeira por 30 dias antes da exclusão automática (ou exclua definitivamente de lá quando quiser). Digite o nome amigável exato para confirmar.</p>
                <input type="text" class="rbi-field-input" id="rbi-lixeira-confirm-input" placeholder="Digite o nome amigável para confirmar" autocomplete="off">
                <button type="button" class="rbi-btn-lixeira" id="rbi-btn-lixeira-confirm" disabled>
                    <i class="ti ti-trash" style="margin-right:.35rem"></i>Mover para lixeira
                </button>
            </div>
            <?php endif; ?>

            <div class="rbi-modal-footer">
                <button class="rbi-btn-cancel" id="rbi-btn-cancel">Cancelar</button>
                <button class="rbi-btn-save"   id="rbi-btn-save">Salvar</button>
            </div>
        </div>

        <?php if ($_rtIsAdmin): ?>
        <!-- Aba Conexão — detecta tipo_conexao do relatório e renderiza SQL ou Excel
             (não é mais um seletor editável aqui; trocar de tipo depois de criado está
             fora de escopo). -->
        <div class="rbi-tab-content rbi-tab-hidden" id="rbi-tab-conexao">
            <input type="hidden" id="rbi-conn-relatorio-id">

            <!-- Infraestrutura — somente leitura, calculada a partir de slug/id (nunca editável) -->
            <div class="rbi-infra-box">
                <div class="rbi-infra-row"><span class="rbi-infra-label">Pasta</span><span class="rbi-infra-value" id="rbi-infra-pasta">—</span></div>
                <div class="rbi-infra-row"><span class="rbi-infra-label">Serviço</span><span class="rbi-infra-value" id="rbi-infra-servico">—</span></div>
                <div class="rbi-infra-row"><span class="rbi-infra-label">Porta interna</span><span class="rbi-infra-value" id="rbi-infra-porta">—</span></div>
            </div>

            <div class="rbi-field">
                <label class="rbi-field-label">Tipo de conexão</label>
                <span class="rbi-infra-value" id="rbi-conn-tipo-label" style="text-align:left">—</span>
            </div>

            <!-- Bloco SQL -->
            <div id="rbi-conn-sql-block">
                <div class="rbi-conn-grid">
                    <div class="rbi-field full">
                        <label class="rbi-field-label">Host</label>
                        <input type="text" class="rbi-field-input" id="rbi-conn-host" autocomplete="off">
                    </div>
                    <div class="rbi-field">
                        <label class="rbi-field-label">Porta</label>
                        <input type="number" class="rbi-field-input" id="rbi-conn-port" autocomplete="off" value="5432">
                    </div>
                    <div class="rbi-field">
                        <label class="rbi-field-label">Banco</label>
                        <input type="text" class="rbi-field-input" id="rbi-conn-dbname" autocomplete="off">
                    </div>
                    <div class="rbi-field">
                        <label class="rbi-field-label">Usuário</label>
                        <input type="text" class="rbi-field-input" id="rbi-conn-user" autocomplete="off">
                    </div>
                    <div class="rbi-field">
                        <label class="rbi-field-label">Senha</label>
                        <div class="rbi-conn-pass-wrap">
                            <input type="password" class="rbi-field-input" id="rbi-conn-password" autocomplete="new-password">
                            <button type="button" class="rbi-conn-pass-toggle" id="rbi-conn-pass-toggle" title="Mostrar/ocultar"><i class="ti ti-eye"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bloco Excel -->
            <div id="rbi-conn-excel-block" style="display:none">
                <div class="rbi-field">
                    <label class="rbi-field-label">Tabelas existentes</label>
                    <div id="rbi-conn-tabelas-existentes" style="display:flex;flex-direction:column;gap:.5rem"></div>
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Adicionar tabela</label>
                    <div id="rbi-conn-tabelas-novas" style="display:flex;flex-direction:column;gap:.6rem"></div>
                </div>
                <button type="button" class="rbi-btn-add-tabela" id="rbi-conn-btn-add-tabela" style="width:100%">
                    <i class="ti ti-plus" style="margin-right:.3rem"></i>Adicionar tabela
                </button>
            </div>

            <div class="rbi-conn-msg" id="rbi-conn-msg"></div>

            <div class="rbi-modal-footer">
                <button class="rbi-btn-cancel" id="rbi-conn-cancel">Cancelar</button>
                <button class="rbi-btn-save"   id="rbi-conn-save">Testar e salvar</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($_rtIsAdmin): ?>
<!-- Modal "Criar relatório" (Etapa 2 do self-service, admin_interno only) -->
<div class="rbi-overlay" id="rbi-create-overlay">
    <div class="rbi-modal rbi-create-modal" id="rbi-create-modal">
        <div class="rbi-modal-head">
            <span class="rbi-modal-title">Criar relatório</span>
            <button class="rbi-modal-close" id="rbi-create-close" title="Fechar">&times;</button>
        </div>

        <div class="rbi-field">
            <label class="rbi-field-label">Nome amigável</label>
            <input type="text" class="rbi-field-input" id="rbi-create-nome" autocomplete="off">
        </div>

        <div class="rbi-field">
            <label class="rbi-field-label">Slug (URL, imutável após criar)</label>
            <input type="text" class="rbi-field-input" id="rbi-create-slug" autocomplete="off">
            <span class="rbi-slug-preview" id="rbi-create-slug-msg"></span>
        </div>

        <div class="rbi-field">
            <label class="rbi-field-label">Tipo de conexão</label>
            <div class="rbi-conn-tipo-row">
                <button type="button" class="rbi-conn-tipo-btn active" id="rbi-create-tipo-sql" data-val="sql">SQL</button>
                <button type="button" class="rbi-conn-tipo-btn" disabled title="Em breve">Webhook</button>
                <button type="button" class="rbi-conn-tipo-btn" id="rbi-create-tipo-excel" data-val="excel">Excel</button>
            </div>
        </div>

        <!-- Campos SQL -->
        <div id="rbi-create-sql-fields">
            <div class="rbi-conn-grid">
                <div class="rbi-field full">
                    <label class="rbi-field-label">Host</label>
                    <input type="text" class="rbi-field-input" id="rbi-create-host" autocomplete="off">
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Porta</label>
                    <input type="number" class="rbi-field-input" id="rbi-create-port" autocomplete="off" value="5432">
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Banco</label>
                    <input type="text" class="rbi-field-input" id="rbi-create-dbname" autocomplete="off">
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Usuário</label>
                    <input type="text" class="rbi-field-input" id="rbi-create-user" autocomplete="off">
                </div>
                <div class="rbi-field">
                    <label class="rbi-field-label">Senha</label>
                    <div class="rbi-conn-pass-wrap">
                        <input type="password" class="rbi-field-input" id="rbi-create-password" autocomplete="new-password">
                        <button type="button" class="rbi-conn-pass-toggle" id="rbi-create-pass-toggle" title="Mostrar/ocultar"><i class="ti ti-eye"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Campos Excel -->
        <div id="rbi-create-excel-fields" style="display:none">
            <div class="rbi-field">
                <label class="rbi-field-label">Tabelas</label>
                <div id="rbi-excel-tabelas-list" style="display:flex;flex-direction:column;gap:.6rem"></div>
            </div>
            <button type="button" class="rbi-btn-add-tabela" id="rbi-btn-add-tabela" style="margin-top:.6rem;width:100%">
                <i class="ti ti-plus" style="margin-right:.3rem"></i>Adicionar tabela
            </button>
        </div>

        <div class="rbi-create-msg" id="rbi-create-msg"></div>

        <div class="rbi-modal-footer">
            <button class="rbi-btn-cancel" id="rbi-create-cancel">Cancelar</button>
            <button class="rbi-btn-save"   id="rbi-create-save">Criar relatório</button>
        </div>
    </div>
</div>

<!-- Modal "Atualizar dados da tabela" (Excel) — 3 passos: anexo+modo -> revisão de
     colunas sem correspondência exata (só quando necessário) -> confirmação. -->
<div class="rbi-overlay" id="rbi-atualizar-overlay">
    <div class="rbi-modal rbi-create-modal" id="rbi-atualizar-modal">
        <div class="rbi-modal-head">
            <span class="rbi-modal-title">Atualizar dados da tabela</span>
            <button class="rbi-modal-close" id="rbi-atualizar-close" title="Fechar">&times;</button>
        </div>

        <input type="hidden" id="rbi-atualizar-relatorio-id">

        <div class="rbi-field">
            <label class="rbi-field-label">Tabela</label>
            <span class="rbi-infra-value" id="rbi-atualizar-tabela-label" style="text-align:left">—</span>
        </div>

        <!-- Passo 1: anexo do arquivo novo + escolha de modo -->
        <div id="rbi-atualizar-passo-1">
            <div class="rbi-field">
                <label class="rbi-field-label">Arquivo novo (.xlsx)</label>
                <div class="rbi-file-attach">
                    <input type="file" class="rbi-file-attach-input" id="rbi-atualizar-arquivo" accept=".xlsx">
                    <button type="button" class="rbi-file-attach-btn" id="rbi-atualizar-arquivo-btn"><i class="ti ti-upload"></i> Escolher</button>
                    <span class="rbi-file-attach-name" id="rbi-atualizar-arquivo-name">
                        <i class="ti ti-circle-check-filled"></i>
                        <span class="rbi-file-attach-name-text"></span>
                        <button type="button" class="rbi-file-attach-clear" title="Remover arquivo" id="rbi-atualizar-arquivo-clear"><i class="ti ti-x"></i></button>
                    </span>
                </div>
            </div>
            <div class="rbi-field">
                <label class="rbi-field-label">Modo</label>
                <div class="rbi-vis-row">
                    <button type="button" class="rbi-vis-btn active-vis" id="rbi-atualizar-modo-atualizar" data-val="atualizar">Atualizar<br><small style="font-weight:400">soma às linhas atuais</small></button>
                    <button type="button" class="rbi-vis-btn" id="rbi-atualizar-modo-substituir" data-val="substituir">Substituir<br><small style="font-weight:400">apaga e recria as linhas</small></button>
                </div>
            </div>
        </div>

        <!-- Passo 2: revisão de colunas do arquivo novo sem correspondência exata -->
        <div id="rbi-atualizar-passo-2" style="display:none">
            <p class="rbi-delete-warn" style="margin:0">As colunas abaixo do arquivo novo não têm correspondência exata com nenhuma coluna já existente na tabela. Para cada uma, escolha vincular a uma coluna existente (ex.: renomeio/erro de digitação) ou criar como coluna nova. Nenhuma coluna existente é removida em nenhum caso.</p>
            <div id="rbi-atualizar-revisao-list" style="display:flex;flex-direction:column;gap:.6rem;margin-top:.75rem"></div>
        </div>

        <!-- Passo 3: resumo + confirmação (Substituir exige digitar o nome da tabela) -->
        <div id="rbi-atualizar-passo-3" style="display:none">
            <div class="rbi-infra-box" id="rbi-atualizar-resumo"></div>
            <div class="rbi-delete-box" id="rbi-atualizar-substituir-confirm" style="display:none;margin-top:.75rem">
                <label class="rbi-field-label" style="color:#ff8080">Confirmar substituição</label>
                <p class="rbi-delete-warn">Ação permanente — todas as linhas atuais desta tabela serão apagadas e substituídas pelas do arquivo novo. Nenhuma coluna é removida. Digite o nome da tabela para confirmar.</p>
                <input type="text" class="rbi-field-input" id="rbi-atualizar-substituir-input" placeholder="Digite o nome da tabela para confirmar" autocomplete="off">
            </div>
        </div>

        <div class="rbi-conn-msg" id="rbi-atualizar-msg"></div>

        <div class="rbi-modal-footer">
            <button class="rbi-btn-cancel" id="rbi-atualizar-cancel">Cancelar</button>
            <button class="rbi-btn-save"   id="rbi-atualizar-btn-next">Analisar arquivo</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tooltip de usuários (chip "N usuários") -->
<div class="rbi-user-tooltip" id="rbi-user-tooltip" style="display:none"></div>

<script src="/assets/js/relatorios-bi.js"></script>
