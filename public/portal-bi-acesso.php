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

    /* ─── Showcase auto-rotativo (substitui o antigo card de preview único) ─── */
    .mkt-showcase {
        position: relative;
        height: 260px;
        background: rgba(255,255,255,0.05);
        border: 1.5px solid rgba(255,255,255,0.12);
        border-radius: 16px;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        box-shadow: 0 10px 40px rgba(0,0,0,.15);
        overflow: hidden;
    }

    .mkt-screen {
        position: absolute;
        inset: 0;
        padding: .8rem 1rem .85rem;
        display: flex;
        flex-direction: column;
        opacity: 0;
        pointer-events: none;
        transition: opacity .6s ease;
    }
    .mkt-screen.active { opacity: 1; pointer-events: auto; }

    .mkt-screen-label {
        flex-shrink: 0;
        font-size: .64rem;
        text-transform: uppercase;
        letter-spacing: .07em;
        color: #26FF93;
        font-weight: 700;
        margin-bottom: .5rem;
    }

    .mkt-kpi-mini-row {
        flex-shrink: 0;
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: .45rem;
        margin-bottom: .6rem;
    }
    .mkt-kpi-mini-row.cols-2 { grid-template-columns: repeat(2, 1fr); }

    .mkt-kpi-mini {
        position: relative;
        background: rgba(255,255,255,0.045);
        border: 1px solid rgba(255,255,255,0.10);
        border-radius: 8px;
        padding: .4rem .45rem;
        overflow: hidden;
    }
    .mkt-kpi-mini::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
    .mkt-kpi-mini.c-cyan::before   { background: linear-gradient(90deg,#0DC2FF,#0080aa); }
    .mkt-kpi-mini.c-green::before  { background: linear-gradient(90deg,#26FF93,#059669); }
    .mkt-kpi-mini.c-purple::before { background: linear-gradient(90deg,#b794f4,#805ad5); }
    .mkt-kpi-mini.c-amber::before  { background: linear-gradient(90deg,#f6ad55,#f6e05e); }
    .mkt-kpi-mini-label {
        font-size: .52rem; text-transform: uppercase; letter-spacing: .03em;
        color: rgba(255,255,255,.5); margin-bottom: .2rem;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .mkt-kpi-mini-value { font-size: .72rem; font-weight: 700; color: #fff; white-space: nowrap; }

    .mkt-two-col { flex: 1; min-height: 0; display: flex; gap: .9rem; }
    .mkt-col { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .mkt-col-center { justify-content: center; align-items: center; }
    .mkt-col-between { justify-content: space-between; }

    .mkt-funnel-list { flex: 1; display: flex; flex-direction: column; justify-content: space-between; }
    .mkt-funnel-list-row { display: flex; justify-content: space-between; align-items: center; font-size: .7rem; gap: .5rem; }
    .mkt-funnel-list-row .lbl { color: rgba(255,255,255,.65); }
    .mkt-funnel-list-row .val { color: #fff; font-weight: 700; white-space: nowrap; }

    .mkt-donut-legend { display: flex; align-items: center; gap: .8rem; justify-content: center; height: 100%; }

    .mkt-donut {
        position: relative;
        width: 62px;
        height: 62px;
        flex-shrink: 0;
    }
    .mkt-donut::before {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: 50%;
        background: var(--donut-bg);
        -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 11px), #000 calc(100% - 10px));
        mask: radial-gradient(farthest-side, transparent calc(100% - 11px), #000 calc(100% - 10px));
    }

    .mkt-legend { display: flex; flex-direction: column; gap: .3rem; }
    .mkt-legend-item { display: flex; align-items: center; gap: .4rem; font-size: .68rem; color: rgba(255,255,255,.8); white-space: nowrap; }
    .mkt-legend-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

    .mkt-bar-ranking { flex: 1; display: flex; flex-direction: column; justify-content: space-between; }
    .mkt-bar-row { display: flex; align-items: center; gap: .45rem; }
    .mkt-bar-label {
        width: 50px; flex-shrink: 0; font-size: .66rem; color: rgba(255,255,255,.7);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .mkt-bar-track { flex: 1; height: 7px; background: rgba(255,255,255,.08); border-radius: 4px; overflow: hidden; }
    .mkt-bar-fill { display: block; height: 100%; border-radius: 4px; }
    .mkt-bar-value { width: 32px; flex-shrink: 0; text-align: right; font-size: .66rem; font-weight: 700; color: #fff; }

    .mkt-bar-fill.c-cyan,   .mkt-funnel-stage-bar.c-cyan   { background: #0DC2FF; }
    .mkt-bar-fill.c-green,  .mkt-funnel-stage-bar.c-green  { background: #26FF93; }
    .mkt-bar-fill.c-purple, .mkt-funnel-stage-bar.c-purple { background: #b794f4; }
    .mkt-bar-fill.c-amber,  .mkt-funnel-stage-bar.c-amber  { background: #f6ad55; }

    .mkt-line-wrap { flex-shrink: 0; margin-bottom: .5rem; }
    .mkt-line-months { display: flex; justify-content: space-between; margin-top: .2rem; padding: 0 .1rem; }
    .mkt-line-months span { font-size: .54rem; color: rgba(255,255,255,.45); }
    .mkt-line-legend { display: flex; gap: .8rem; margin-top: .35rem; }
    .mkt-line-legend-item { display: flex; align-items: center; gap: .35rem; font-size: .62rem; color: rgba(255,255,255,.75); }
    .mkt-line-swatch { width: 14px; height: 2px; border-radius: 2px; display: inline-block; }
    .mkt-line-swatch.solid { background: #0DC2FF; }
    .mkt-line-swatch.dashed { background: repeating-linear-gradient(90deg,#f6ad55 0 4px, transparent 4px 7px); }

    .mkt-callout {
        background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.09);
        border-radius: 8px; padding: .4rem .5rem;
    }
    .mkt-callout-label { font-size: .56rem; text-transform: uppercase; letter-spacing: .03em; color: rgba(255,255,255,.5); margin-bottom: .15rem; }
    .mkt-callout-value { font-size: .74rem; font-weight: 700; color: #fff; }
    .mkt-callout-delta { font-size: .58rem; color: #26FF93; margin-left: .3rem; font-weight: 600; }

    .mkt-sparkline { flex: 1; display: flex; align-items: flex-end; justify-content: space-between; gap: .3rem; padding-bottom: .1rem; }
    .mkt-sparkline-bar { flex: 1; border-radius: 3px 3px 0 0; background: linear-gradient(180deg,#0DC2FF,#086B8D); }

    .mkt-funnel-chart { flex: 1; display: flex; flex-direction: column; justify-content: space-between; }
    .mkt-funnel-stage { display: flex; flex-direction: column; gap: .2rem; }
    .mkt-funnel-stage-head { display: flex; justify-content: space-between; font-size: .62rem; color: rgba(255,255,255,.75); }
    .mkt-funnel-stage-head b { color: #fff; }
    .mkt-funnel-stage-bar { height: 10px; border-radius: 4px; }

    .mkt-gauge { position: relative; width: 72px; height: 72px; flex-shrink: 0; }
    .mkt-gauge::before {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: 50%;
        background: var(--gauge-bg);
        -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 12px), #000 calc(100% - 11px));
        mask: radial-gradient(farthest-side, transparent calc(100% - 12px), #000 calc(100% - 11px));
    }
    .mkt-gauge-inner { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .mkt-gauge-number { font-size: .88rem; font-weight: 700; color: #fff; line-height: 1; }
    .mkt-gauge-caption { font-size: .42rem; color: rgba(255,255,255,.6); text-transform: uppercase; letter-spacing: .03em; margin-top: .15rem; text-align: center; line-height: 1.15; }

    .mkt-cta-text { font-size: .85rem; line-height: 1.5; color: rgba(255,255,255,.85); margin: 1.1rem 0 .75rem 0; }

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

            <div class="mkt-showcase">
                <div class="mkt-screen active" data-screen="1">
                    <div class="mkt-screen-label">Visão geral</div>
                    <div class="mkt-kpi-mini-row">
                        <div class="mkt-kpi-mini c-cyan"><div class="mkt-kpi-mini-label">Receita</div><div class="mkt-kpi-mini-value">R$ 12.480</div></div>
                        <div class="mkt-kpi-mini c-green"><div class="mkt-kpi-mini-label">Ativas</div><div class="mkt-kpi-mini-value">R$ 9.320</div></div>
                        <div class="mkt-kpi-mini c-purple"><div class="mkt-kpi-mini-label">Indicações</div><div class="mkt-kpi-mini-value">R$ 1.150</div></div>
                        <div class="mkt-kpi-mini c-amber"><div class="mkt-kpi-mini-label">Ticket méd.</div><div class="mkt-kpi-mini-value">R$ 890</div></div>
                    </div>
                    <div class="mkt-two-col">
                        <div class="mkt-col">
                            <div class="mkt-funnel-list">
                                <div class="mkt-funnel-list-row"><span class="lbl">Coleta docs</span><span class="val">R$ 4.230</span></div>
                                <div class="mkt-funnel-list-row"><span class="lbl">Triagem</span><span class="val">R$ 1.980</span></div>
                                <div class="mkt-funnel-list-row"><span class="lbl">Proposta</span><span class="val">R$ 3.640</span></div>
                            </div>
                        </div>
                        <div class="mkt-col mkt-col-center">
                            <div class="mkt-donut-legend">
                                <div class="mkt-donut" style="--donut-bg: conic-gradient(#0DC2FF 0% 30%, #26FF93 30% 52%, #b794f4 52% 70%, rgba(255,255,255,.12) 70% 100%);"></div>
                                <div class="mkt-legend">
                                    <div class="mkt-legend-item"><span class="mkt-legend-dot" style="background:#0DC2FF"></span> Prod A · 30%</div>
                                    <div class="mkt-legend-item"><span class="mkt-legend-dot" style="background:#26FF93"></span> Prod B · 22%</div>
                                    <div class="mkt-legend-item"><span class="mkt-legend-dot" style="background:#b794f4"></span> Prod C · 18%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkt-screen" data-screen="2">
                    <div class="mkt-screen-label">Comparativo por vendedor</div>
                    <div class="mkt-kpi-mini-row">
                        <div class="mkt-kpi-mini c-cyan"><div class="mkt-kpi-mini-label">Vendas</div><div class="mkt-kpi-mini-value">R$ 8.814</div></div>
                        <div class="mkt-kpi-mini c-green"><div class="mkt-kpi-mini-label">Ativas</div><div class="mkt-kpi-mini-value">R$ 8.064</div></div>
                        <div class="mkt-kpi-mini c-purple"><div class="mkt-kpi-mini-label">Indic.</div><div class="mkt-kpi-mini-value">R$ 750</div></div>
                        <div class="mkt-kpi-mini c-amber"><div class="mkt-kpi-mini-label">Ticket</div><div class="mkt-kpi-mini-value">R$ 1.101</div></div>
                    </div>
                    <div class="mkt-two-col">
                        <div class="mkt-col">
                            <div class="mkt-bar-ranking">
                                <div class="mkt-bar-row"><span class="mkt-bar-label">Vend. A</span><span class="mkt-bar-track"><span class="mkt-bar-fill c-cyan" style="width:88%"></span></span><span class="mkt-bar-value">88%</span></div>
                                <div class="mkt-bar-row"><span class="mkt-bar-label">Vend. B</span><span class="mkt-bar-track"><span class="mkt-bar-fill c-green" style="width:64%"></span></span><span class="mkt-bar-value">64%</span></div>
                                <div class="mkt-bar-row"><span class="mkt-bar-label">Vend. C</span><span class="mkt-bar-track"><span class="mkt-bar-fill c-purple" style="width:41%"></span></span><span class="mkt-bar-value">41%</span></div>
                            </div>
                        </div>
                        <div class="mkt-col mkt-col-center">
                            <div class="mkt-donut-legend">
                                <div class="mkt-donut" style="--donut-bg: conic-gradient(#26FF93 0% 75%, #0DC2FF 75% 100%);"></div>
                                <div class="mkt-legend">
                                    <div class="mkt-legend-item"><span class="mkt-legend-dot" style="background:#26FF93"></span> Ativo · 75%</div>
                                    <div class="mkt-legend-item"><span class="mkt-legend-dot" style="background:#0DC2FF"></span> Indic. · 25%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkt-screen" data-screen="3">
                    <div class="mkt-screen-label">Evolução mensal</div>
                    <div class="mkt-line-wrap">
                        <svg viewBox="0 0 300 60" width="100%" height="60" preserveAspectRatio="none">
                            <polyline points="10,45 62,39 114,34 166,29 218,23 270,14" fill="none" stroke="#0DC2FF" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                            <polyline points="10,41 62,38 114,35 166,32 218,27 270,24" fill="none" stroke="#f6ad55" stroke-width="2" stroke-dasharray="5,4" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <div class="mkt-line-months"><span>Jan</span><span>Fev</span><span>Mar</span><span>Abr</span><span>Mai</span><span>Jun</span></div>
                        <div class="mkt-line-legend">
                            <div class="mkt-line-legend-item"><span class="mkt-line-swatch solid"></span> Receita</div>
                            <div class="mkt-line-legend-item"><span class="mkt-line-swatch dashed"></span> Meta</div>
                        </div>
                    </div>
                    <div class="mkt-two-col">
                        <div class="mkt-col mkt-col-between">
                            <div class="mkt-callout">
                                <div class="mkt-callout-label">Receita (jun)</div>
                                <div class="mkt-callout-value">R$ 2.890<span class="mkt-callout-delta">+18% vs mai</span></div>
                            </div>
                            <div class="mkt-callout">
                                <div class="mkt-callout-label">Meta (jun)</div>
                                <div class="mkt-callout-value">R$ 2.650<span class="mkt-callout-delta">atingida</span></div>
                            </div>
                        </div>
                        <div class="mkt-col">
                            <div class="mkt-sparkline">
                                <span class="mkt-sparkline-bar" style="height:35%"></span>
                                <span class="mkt-sparkline-bar" style="height:48%"></span>
                                <span class="mkt-sparkline-bar" style="height:55%"></span>
                                <span class="mkt-sparkline-bar" style="height:62%"></span>
                                <span class="mkt-sparkline-bar" style="height:78%"></span>
                                <span class="mkt-sparkline-bar" style="height:100%"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkt-screen" data-screen="4">
                    <div class="mkt-screen-label">Top categorias</div>
                    <div class="mkt-kpi-mini-row cols-2">
                        <div class="mkt-kpi-mini c-cyan"><div class="mkt-kpi-mini-label">Categorias ativas</div><div class="mkt-kpi-mini-value">24</div></div>
                        <div class="mkt-kpi-mini c-green"><div class="mkt-kpi-mini-label">Top categoria</div><div class="mkt-kpi-mini-value">18%</div></div>
                    </div>
                    <div class="mkt-two-col">
                        <div class="mkt-col mkt-col-center">
                            <div class="mkt-donut" style="--donut-bg: conic-gradient(#0DC2FF 0% 18%, #26FF93 18% 31%, #b794f4 31% 44%, #f6ad55 44% 56%, rgba(255,255,255,.12) 56% 100%);"></div>
                        </div>
                        <div class="mkt-col">
                            <div class="mkt-bar-ranking">
                                <div class="mkt-bar-row"><span class="mkt-bar-label">Categ. A</span><span class="mkt-bar-track"><span class="mkt-bar-fill c-cyan" style="width:18%"></span></span><span class="mkt-bar-value">18%</span></div>
                                <div class="mkt-bar-row"><span class="mkt-bar-label">Categ. B</span><span class="mkt-bar-track"><span class="mkt-bar-fill c-green" style="width:13%"></span></span><span class="mkt-bar-value">13%</span></div>
                                <div class="mkt-bar-row"><span class="mkt-bar-label">Categ. C</span><span class="mkt-bar-track"><span class="mkt-bar-fill c-purple" style="width:13%"></span></span><span class="mkt-bar-value">13%</span></div>
                                <div class="mkt-bar-row"><span class="mkt-bar-label">Outros</span><span class="mkt-bar-track"><span class="mkt-bar-fill c-amber" style="width:22%"></span></span><span class="mkt-bar-value">22%</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mkt-screen" data-screen="5">
                    <div class="mkt-screen-label">Funil de vendas</div>
                    <div class="mkt-kpi-mini-row cols-2">
                        <div class="mkt-kpi-mini c-cyan"><div class="mkt-kpi-mini-label">Conversão</div><div class="mkt-kpi-mini-value">14,5%</div></div>
                        <div class="mkt-kpi-mini c-purple"><div class="mkt-kpi-mini-label">Ciclo médio</div><div class="mkt-kpi-mini-value">23 dias</div></div>
                    </div>
                    <div class="mkt-two-col">
                        <div class="mkt-col">
                            <div class="mkt-funnel-chart">
                                <div class="mkt-funnel-stage">
                                    <div class="mkt-funnel-stage-head"><span>Visitantes</span><b>1.240</b></div>
                                    <div class="mkt-funnel-stage-bar c-cyan" style="width:100%"></div>
                                </div>
                                <div class="mkt-funnel-stage">
                                    <div class="mkt-funnel-stage-head"><span>Leads</span><b>860</b></div>
                                    <div class="mkt-funnel-stage-bar c-green" style="width:69%"></div>
                                </div>
                                <div class="mkt-funnel-stage">
                                    <div class="mkt-funnel-stage-head"><span>Oportunidades</span><b>410</b></div>
                                    <div class="mkt-funnel-stage-bar c-purple" style="width:33%"></div>
                                </div>
                                <div class="mkt-funnel-stage">
                                    <div class="mkt-funnel-stage-head"><span>Clientes</span><b>180</b></div>
                                    <div class="mkt-funnel-stage-bar c-amber" style="width:15%"></div>
                                </div>
                            </div>
                        </div>
                        <div class="mkt-col mkt-col-center">
                            <div class="mkt-gauge" style="--gauge-bg: conic-gradient(#26FF93 0% 78%, rgba(255,255,255,.10) 78% 100%);">
                                <div class="mkt-gauge-inner">
                                    <div class="mkt-gauge-number">78%</div>
                                    <div class="mkt-gauge-caption">da meta<br>mensal</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p class="mkt-cta-text">Organize seus dados agora e melhore a visão do seu negócio.</p>
            <script data-b24-form="click/103/443ca3" data-skip-moving="true">(function(w,d,u){var s=d.createElement('script');s.async=true;s.src=u+'?'+(Date.now()/180000|0);var h=d.getElementsByTagName('script')[0];h.parentNode.insertBefore(s,h);})(window,document,'https://cdn.bitrix24.com.br/b19990279/crm/form/loader_103.js');</script>
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
    <script>
        (function () {
            var screens = document.querySelectorAll('.mkt-showcase .mkt-screen');
            if (screens.length < 2) return;
            var idx = 0;
            setInterval(function () {
                screens[idx].classList.remove('active');
                idx = (idx + 1) % screens.length;
                screens[idx].classList.add('active');
            }, 4000);
        })();
    </script>
</body>
</html>
