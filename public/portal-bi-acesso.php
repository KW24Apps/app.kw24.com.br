<?php
/**
 * Portal BI — Acesso externo com filtro por parceiro ou oportunidade.
 * Chamado pelo portal-router.php com $relatorio_slug e $portal_slug definidos.
 * Sem auth_request nginx — o acesso tem autenticação própria (senha ou embed_token).
 */
if (!defined('PORTAL_ACCESS')) { http_response_code(403); exit; }

require_once __DIR__ . '/../helpers/Database.php';

$pdo    = Database::getInstance()->getConnection();
$rSlug  = $relatorio_slug ?? '';
$pSlug  = $portal_slug    ?? '';
$error  = '';

// Carrega o portal
$stmt = $pdo->prepare(
    'SELECT * FROM portais_bi WHERE relatorio_slug = ? AND slug = ? LIMIT 1'
);
$stmt->execute([$rSlug, $pSlug]);
$portal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$portal) {
    http_response_code(404);
    echo '<p style="font-family:sans-serif;color:#ccc;padding:2rem">Portal não encontrado.</p>';
    exit;
}

if (!$portal['ativo']) {
    $error = 'Este portal está desativado.';
}

$reportUrl = '/relatorios-bi/' . $rSlug . '/';

// ── Sessão de portal BI já existe e é válida para este portal ──────────────
if (isset($_SESSION['portal_bi'])
    && ($_SESSION['portal_bi']['portal_id']       ?? 0)  === (int)$portal['id']
    && ($_SESSION['portal_bi']['relatorio_slug']  ?? '') === $rSlug
) {
    $pb      = $_SESSION['portal_bi'];
    $expires = $pb['expires'] ?? 0;
    if ($expires === 0 || time() <= $expires) {
        header('Location: ' . $reportUrl);
        exit;
    }
    // Sessão expirada — limpa e continua para o formulário
    unset($_SESSION['portal_bi']);
}

// ── Acesso via embed_token (?embed=TOKEN) ──────────────────────────────────
$embedParam = trim($_GET['embed'] ?? '');
if ($embedParam && $portal['ativo']) {
    if (hash_equals($portal['embed_token'], $embedParam)) {
        // Embed não expira (8h de sessão PHP, renovada a cada visita)
        $_SESSION['portal_bi'] = [
            'portal_id'      => (int)$portal['id'],
            'relatorio_slug' => $rSlug,
            'filter_type'    => $portal['filter_type'],
            'filter_values'  => json_decode($portal['filter_values'], true) ?? [],
            'nome'           => $portal['nome'] ?? '',
            'expires'        => 0,   // 0 = sem expiração de sessão para embed
            // relatorio-contabilidade
            'ct_completo'         => (bool)($portal['ct_completo'] ?? false),
            'ct_indicador_values' => json_decode($portal['ct_indicador_values'] ?? '[]', true) ?? [],
            'ct_contab_values'    => json_decode($portal['ct_contab_values']    ?? '[]', true) ?? [],
        ];
        header('Location: ' . $reportUrl);
        exit;
    }
    $error = 'Token de incorporação inválido.';
}

// ── POST: validação de senha ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $portal['ativo'] && !$error) {
    $senha = $_POST['senha'] ?? '';
    if ($senha && password_verify($senha, $portal['senha_hash'])) {
        $_SESSION['portal_bi'] = [
            'portal_id'      => (int)$portal['id'],
            'relatorio_slug' => $rSlug,
            'filter_type'    => $portal['filter_type'],
            'filter_values'  => json_decode($portal['filter_values'], true) ?? [],
            'nome'           => $portal['nome'] ?? '',
            'expires'        => time() + 7200,  // 2 horas
            // relatorio-contabilidade
            'ct_completo'         => (bool)($portal['ct_completo'] ?? false),
            'ct_indicador_values' => json_decode($portal['ct_indicador_values'] ?? '[]', true) ?? [],
            'ct_contab_values'    => json_decode($portal['ct_contab_values']    ?? '[]', true) ?? [],
        ];
        header('Location: ' . $reportUrl);
        exit;
    }
    $error = 'Senha incorreta. Tente novamente.';
}

// Nome amigável do portal (fallback para slug)
$nomeExibido = $portal['nome'] ?: $portal['slug'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($nomeExibido) ?> — Relatório | KW24</title>
    <link rel="stylesheet" href="/assets/css/login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
    .portal-name-badge {
        text-align: center;
        font-size: .875rem;
        font-weight: 600;
        color: rgba(255,255,255,.8);
        margin-bottom: 1.5rem;
        padding: .5rem 1rem;
        background: rgba(255,255,255,0.06);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 10px;
        word-break: break-word;
        overflow-wrap: break-word;
    }

    /* ─── Layout de duas colunas (Portal BI — só nesta página) ─── */
    .portal-access-layout {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4rem;
        width: 100%;
        max-width: 1360px;
        padding: 2rem;
        margin: 0 auto;
    }

    .portal-access-layout .login-container {
        flex: 0 0 auto;
    }

    .mkt-panel {
        position: relative;
        z-index: 100;
        flex: 1 1 480px;
        max-width: 540px;
        color: #fff;
    }

    .mkt-pill {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .35rem .9rem;
        border-radius: 999px;
        background: rgba(38,255,147,0.12);
        border: 1px solid rgba(38,255,147,0.35);
        color: #26FF93;
        font-size: .72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        margin-bottom: 1.25rem;
    }

    .mkt-headline {
        font-size: 2rem;
        font-weight: 700;
        line-height: 1.25;
        margin: 0 0 .85rem 0;
        color: #fff;
        text-shadow: 0 2px 10px rgba(0,0,0,.15);
    }

    .mkt-subheadline {
        font-size: 1rem;
        line-height: 1.6;
        color: rgba(255,255,255,.85);
        margin: 0 0 1.75rem 0;
        max-width: 480px;
    }

    .mkt-features {
        display: flex;
        flex-wrap: wrap;
        gap: .6rem;
        margin-bottom: 2rem;
    }

    .mkt-feature-chip {
        display: flex;
        align-items: center;
        gap: .5rem;
        padding: .55rem .9rem;
        border-radius: 10px;
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(255,255,255,0.14);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        font-size: .82rem;
        font-weight: 500;
        color: #fff;
        white-space: nowrap;
    }

    .mkt-feature-chip i { color: #26FF93; font-size: .95rem; }

    /* Card de preview do relatório (exemplo estático, sem dados reais) */
    .mkt-preview-card {
        background: rgba(255,255,255,0.05);
        border: 1.5px solid rgba(255,255,255,0.12);
        border-radius: 16px;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        box-shadow: 0 10px 40px rgba(0,0,0,.15);
        padding: 1.25rem 1.4rem 1.5rem;
    }

    .mkt-preview-caption {
        font-size: .68rem;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: rgba(255,255,255,.45);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: .4rem;
    }

    .mkt-kpi-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: .6rem;
        margin-bottom: 1.4rem;
    }

    .mkt-kpi {
        background: rgba(255,255,255,0.045);
        border: 1px solid rgba(255,255,255,0.10);
        border-radius: 10px;
        padding: .6rem .55rem;
        position: relative;
        overflow: hidden;
    }

    .mkt-kpi::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
    }
    .mkt-kpi-a::before { background: linear-gradient(90deg,#f6ad55,#f6e05e); }
    .mkt-kpi-b::before { background: linear-gradient(90deg,#0DC2FF,#0080aa); }
    .mkt-kpi-c::before { background: linear-gradient(90deg,#b794f4,#805ad5); }
    .mkt-kpi-d::before { background: linear-gradient(90deg,#26FF93,#059669); }

    .mkt-kpi-label {
        font-size: .6rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: rgba(255,255,255,.5);
        margin-bottom: .3rem;
    }
    .mkt-kpi-value { font-size: .85rem; font-weight: 700; color: #fff; }

    .mkt-donut-row {
        display: flex;
        align-items: center;
        gap: 1.5rem;
    }

    .mkt-donut {
        position: relative;
        width: 92px;
        height: 92px;
        flex-shrink: 0;
    }
    .mkt-donut::before {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: 50%;
        background: conic-gradient(#0DC2FF 0deg 198deg, #26FF93 198deg 306deg, #b794f4 306deg 360deg);
        -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 16px), #000 calc(100% - 15px));
        mask: radial-gradient(farthest-side, transparent calc(100% - 16px), #000 calc(100% - 15px));
    }
    .mkt-donut-number {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        font-weight: 700;
        color: #fff;
    }

    .mkt-legend { display: flex; flex-direction: column; gap: .45rem; }
    .mkt-legend-item { display: flex; align-items: center; gap: .5rem; font-size: .78rem; color: rgba(255,255,255,.8); white-space: nowrap; }
    .mkt-legend-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
    .mkt-legend-dot.alfa { background: #0DC2FF; }
    .mkt-legend-dot.beta { background: #26FF93; }
    .mkt-legend-dot.gama { background: #b794f4; }

    /* Abaixo de 992px: some o painel de marketing, mantém a experiência atual do login */
    @media (max-width: 992px) {
        .mkt-panel { display: none; }
        .portal-access-layout { padding: 0; gap: 0; }
    }
    </style>
</head>
<body>
    <canvas id="kw24-bg-login"></canvas>

    <?php if ($error): ?>
    <div class="alert-top">
        <i class="fas fa-exclamation-circle"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div class="portal-access-layout">
        <div class="login-container">
            <div class="login-header">
                <img src="/assets/img/03_KW24_BRANCO1.png" alt="KW24 - Sistemas Harmônicos">
            </div>

            <div class="portal-name-badge">
                <?= htmlspecialchars($nomeExibido) ?>
            </div>

            <?php if ($portal['ativo'] && !$embedParam): ?>
            <form method="POST" action="" class="login-form">
                <div class="input-group">
                    <input
                        type="password"
                        id="senha"
                        name="senha"
                        placeholder="Senha de acesso"
                        required
                        autocomplete="current-password">
                    <i class="fas fa-lock input-icon"></i>
                    <button type="button" class="toggle-password" aria-label="Mostrar/Ocultar senha">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <button type="submit" class="login-button">
                    <span>Acessar relatório</span>
                </button>
            </form>
            <?php endif; ?>

            <div class="login-footer">
                <p>&copy; <?= date('Y') ?> KW24 - Sistemas Harmônicos</p>
            </div>
        </div>

        <aside class="mkt-panel" aria-hidden="true">
            <span class="mkt-pill"><i class="fas fa-chart-line"></i> KW24 · Relatórios BI</span>
            <h2 class="mkt-headline">Visão completa do seu negócio, sempre atualizada</h2>
            <p class="mkt-subheadline">Conectamos aos seus dados onde eles estiverem e transformamos tudo em relatórios visuais, interativos e feitos sob medida para a sua empresa.</p>

            <div class="mkt-features">
                <div class="mkt-feature-chip"><i class="fas fa-database"></i> Qualquer banco de dados</div>
                <div class="mkt-feature-chip"><i class="fas fa-file-excel"></i> Planilhas Excel</div>
                <div class="mkt-feature-chip"><i class="fas fa-sliders"></i> Feito sob medida</div>
            </div>

            <div class="mkt-preview-card">
                <div class="mkt-preview-caption"><i class="fas fa-circle-info"></i> Exemplo ilustrativo, dados fictícios</div>

                <div class="mkt-kpi-row">
                    <div class="mkt-kpi mkt-kpi-a">
                        <div class="mkt-kpi-label">Receita total</div>
                        <div class="mkt-kpi-value">R$ 12.480</div>
                    </div>
                    <div class="mkt-kpi mkt-kpi-b">
                        <div class="mkt-kpi-label">Contas ativas</div>
                        <div class="mkt-kpi-value">R$ 9.320</div>
                    </div>
                    <div class="mkt-kpi mkt-kpi-c">
                        <div class="mkt-kpi-label">Indicações</div>
                        <div class="mkt-kpi-value">R$ 1.150</div>
                    </div>
                    <div class="mkt-kpi mkt-kpi-d">
                        <div class="mkt-kpi-label">Ticket médio</div>
                        <div class="mkt-kpi-value">R$ 890</div>
                    </div>
                </div>

                <div class="mkt-donut-row">
                    <div class="mkt-donut">
                        <span class="mkt-donut-number">32</span>
                    </div>
                    <div class="mkt-legend">
                        <div class="mkt-legend-item"><span class="mkt-legend-dot alfa"></span> Unidade Alfa · 55%</div>
                        <div class="mkt-legend-item"><span class="mkt-legend-dot beta"></span> Unidade Beta · 30%</div>
                        <div class="mkt-legend-item"><span class="mkt-legend-dot gama"></span> Unidade Gama · 15%</div>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <script src="/assets/js/login.js"></script>
    <script src="/assets/js/bg-login.js"></script>
    <script>
        (function(w,d,u){
                var s=d.createElement('script');s.async=true;s.src=u+'?'+(Date.now()/60000|0);
                var h=d.getElementsByTagName('script')[0];h.parentNode.insertBefore(s,h);
        })(window,document,'https://cdn.bitrix24.com.br/b19990279/crm/site_button/loader_7_pmlhcu.js');
    </script>
</body>
</html>
