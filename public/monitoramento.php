<?php
if (!defined('SYSTEM_ACCESS') && !isset($user_data)) {
    header('Location: /public/login.php'); exit;
}
// Defesa em profundidade: garante admin_interno mesmo no caminho AJAX do index.php
// (o guard genérico de lá não bloqueia usuário sem profile_id atribuído).
if (($user_data['perfil'] ?? '') !== 'admin_interno') {
    header('Location: ?page=dashboard'); exit;
}
?>
<style>
/* ===== MONITORAMENTO KW24 — escala fluida (clamp) =====
 * Piso pensado pra 1366x768 (laptop comum, onde o layout quebrava); teto = valor original
 * validado no monitor grande (~1920px+), sem regressão lá. Interpola linearmente entre os
 * dois — nada de salto único de breakpoint, escala junto com a largura da janela.
 * Fórmula: min + (100vw - 1366px) * F, onde F = (max-min)*16/554 (554 = 1920-1366, 16 =
 * px por rem). Abaixo de 1366px ou acima de 1920px, clamp() trava no piso/teto.
 */
:root {
    --mon-fs-2xs:  clamp(0.53rem, 0.53rem + (100vw - 1366px) * 0.0026,  0.62rem);
    --mon-fs-xs:   clamp(0.58rem, 0.58rem + (100vw - 1366px) * 0.00289, 0.68rem);
    --mon-fs-sm:   clamp(0.64rem, 0.64rem + (100vw - 1366px) * 0.00318, 0.75rem);
    --mon-fs-base: clamp(0.68rem, 0.68rem + (100vw - 1366px) * 0.00346, 0.80rem);
    --mon-fs-md:   clamp(0.72rem, 0.72rem + (100vw - 1366px) * 0.00375, 0.85rem);
    --mon-fs-lg:   clamp(0.81rem, 0.81rem + (100vw - 1366px) * 0.00404, 0.95rem);
    --mon-fs-num:  clamp(0.85rem, 0.85rem + (100vw - 1366px) * 0.00433, 1.00rem);
    --mon-fs-icon: clamp(1.02rem, 1.02rem + (100vw - 1366px) * 0.0052,  1.20rem);
    --mon-fs-xl:   clamp(1.28rem, 1.28rem + (100vw - 1366px) * 0.00635, 1.50rem);
    --mon-fs-2xl:  clamp(1.70rem, 1.70rem + (100vw - 1366px) * 0.00866, 2.00rem);

    --mon-sp-3xs: clamp(0.14rem, 0.14rem + (100vw - 1366px) * 0.00115, 0.18rem);
    --mon-sp-2xs: clamp(0.20rem, 0.20rem + (100vw - 1366px) * 0.00144, 0.25rem);
    --mon-sp-xs:  clamp(0.28rem, 0.28rem + (100vw - 1366px) * 0.00202, 0.35rem);
    --mon-sp-sm:  clamp(0.40rem, 0.40rem + (100vw - 1366px) * 0.00289, 0.50rem);
    --mon-sp-base:clamp(0.52rem, 0.52rem + (100vw - 1366px) * 0.00375, 0.65rem);
    --mon-sp-md:  clamp(0.64rem, 0.64rem + (100vw - 1366px) * 0.00462, 0.80rem);
    --mon-sp-lg:  clamp(0.80rem, 0.80rem + (100vw - 1366px) * 0.00578, 1.00rem);
    --mon-sp-xl:  clamp(1.00rem, 1.00rem + (100vw - 1366px) * 0.00722, 1.25rem);
    --mon-sp-2xl: clamp(1.20rem, 1.20rem + (100vw - 1366px) * 0.00866, 1.50rem);
}

/* ===== MONITORAMENTO KW24 — layout geral =====
 * Decisão revertida nesta rodada: em telas menores, conteúdo ficava cortado sem nenhuma forma
 * de alcançar (a versão anterior evitava de propósito qualquer scroll de página, só scroll
 * interno por painel). Agora os dois mecanismos convivem, pra propósitos diferentes:
 *   1) Scroll da PÁGINA (.content-area, escopado via :has(.mon-updated) igual ao padrão já
 *      usado pro .page-header, pra não afetar outras páginas) — deixa alcançar as seções do
 *      relatório (Atendimento/Funil/Equipe/Chamados-Tarefas) quando juntas não cabem na tela.
 *      Sidebar/topbar ficam FORA dessa área, em outra célula do grid do shell, então continuam
 *      fixos sem precisar de nenhuma regra extra.
 *   2) Scroll PRÓPRIO do Chamados abertos/Tarefas (.cha-list/.tsk-list) — a lista pode ter
 *      muito mais que 12 itens (ex.: 35 chamados abertos); o painel mostra um piso de ~12
 *      linhas, cresce se houver espaço de sobra (mostra mais sem precisar rolar), mas nunca
 *      tenta crescer o suficiente pra caber a lista inteira de uma vez — o que sobra além da
 *      altura que ele de fato ocupa (12 ou mais) rola dentro do próprio painel.
 * Atendimento tem uma terceira regra, diferente das duas acima: altura FIXA (igual ao Funil,
 * via align-items:stretch — ver .ate-list), com scroll próprio só quando a lista realmente não
 * cabe nessa altura fixa. Equipe cresce naturalmente com o conteúdo, sem scroll próprio (é
 * sempre curto — roster fixo de 4 pessoas).
 */
.content-area:has(.mon-updated) {
    overflow-y: auto;
    overflow-x: hidden;
}
.mon-updated {
    font-size: var(--mon-fs-sm);
    color: rgba(255,255,255,.35);
}
/* Cabeçalho mais compacto só nesta tela — .page-header/.page-title são globais
 * (clientes.css, usados em toda a aplicação), então a sobrescrita é escopada via
 * :has(.mon-updated) em vez de editar o arquivo compartilhado. */
.page-header:has(.mon-updated) {
    margin-bottom: var(--mon-sp-md);
}
.page-header:has(.mon-updated) .page-title {
    font-size: var(--mon-fs-icon);
}
.mon-config-btn {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
    color: rgba(255,255,255,.7);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--mon-fs-lg);
    transition: background .15s, color .15s;
}
.mon-config-btn:hover { background: rgba(255,255,255,0.15); color: #fff; }
/* Linha compacta (altura de conteúdo, não flex:1) — Equipe sozinho, largura cheia (a
 * distribuição do Funil voltou pra dentro do .fun-box, ver relatório da tarefa). Quem ocupa o
 * espaço vertical que resta na página é .mon-right-col (abaixo). */
.mon-panels-row {
    display: flex;
    gap: var(--mon-sp-lg);
    align-items: stretch;
    flex-shrink: 0;
    margin-bottom: var(--mon-sp-lg);
}
/* Chamados abertos/Tarefas — linha própria, largura cheia, ocupa todo o espaço vertical que
 * sobra na página. */
.mon-right-col {
    display: flex;
    flex-direction: column;
    gap: var(--mon-sp-lg);
    flex: 1 1 auto;
    min-width: 320px;
    min-height: 0;
}
@media (max-width: 1024px) {
    .mon-panels-row { flex-direction: column; }
    .mon-equipe-card { flex: 0 0 auto !important; max-height: 45vh; }
    .mon-right-col { flex: 1 1 auto; }
}

/* ===== Painel Equipe — card único, membros empilhados ===== */
.mon-equipe-card {
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    flex: 1 1 0;
    min-width: 260px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.mon-equipe-header {
    padding: var(--mon-sp-base) var(--mon-sp-lg);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    font-family: 'Rubik', sans-serif;
    font-size: var(--mon-fs-lg);
    font-weight: 600;
    color: #fff;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mon-sp-sm);
}
.mon-equipe-header i { color: #0DC2FF; margin-right: .5rem; }
/* Totais agregados (Suporte/Dev no período) — antes uma linha própria abaixo do título,
 * agora compactados ao lado dele mesmo (ver renderEquipeTotal()). */
.mon-equipe-total {
    display: flex;
    gap: var(--mon-sp-md);
    font-size: var(--mon-fs-sm);
    font-weight: 600;
    color: rgba(255,255,255,.75);
    flex-shrink: 0;
    white-space: nowrap;
}
.mon-equipe-total b { font-family: 'Inter', monospace; }
/* Grid de 3 colunas (nome | Suporte | Dev) compartilhado por TODAS as linhas — cada coluna
 * tem a mesma largura (a do conteúdo mais largo dela) em todas as pessoas, então Suporte e Dev
 * sempre começam na mesma posição horizontal, linha após linha (fácil de comparar as 4 pessoas
 * de uma olhada, em vez de cada linha flutuar conforme o tamanho do próprio conteúdo). Truque:
 * .mon-membro-row/.mon-membro-metricas usam display:contents (não geram caixa própria, só
 * "promovem" os filhos pro grid do container) — por isso a borda divisória vai em cada célula
 * (nome/Suporte/Dev), não na linha, mas como as 3 têm o mesmo padding/altura, formam uma única
 * linha visual contínua.
 * --eq-row (custom property, herda através do display:contents mesmo sem caixa própria — ver
 * membroCardHtml()) fixa a LINHA de cada pessoa explicitamente. Sem isso, o algoritmo de
 * auto-placement do grid (cada célula só com grid-column definido, sem grid-row) inflava um
 * espaço vertical enorme entre pessoas — regressão corrigida fixando a linha em vez de deixar
 * o browser decidir. */
.mon-equipe-body {
    flex: 1;
    padding: var(--mon-sp-base) var(--mon-sp-lg);
    display: grid;
    grid-template-columns: 1fr auto auto;
    grid-auto-rows: min-content;
    column-gap: var(--mon-sp-md);
    align-items: center;
}
.mon-equipe-body > .mon-empty { grid-column: 1 / -1; }
.mon-membro-row, .mon-membro-metricas { display: contents; }
.mon-membro-nome-plain {
    grid-column: 1;
    grid-row: var(--eq-row);
    font-family: 'Rubik', sans-serif;
    font-size: var(--mon-fs-num);
    font-weight: 600;
    color: #fff;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: var(--mon-sp-sm) 0;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.mon-membro-metrica {
    grid-row: var(--eq-row);
    cursor: pointer;
    white-space: nowrap;
    text-align: right;
    font-size: var(--mon-fs-sm);
    font-weight: 600;
    padding: var(--mon-sp-sm) 0;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    transition: filter .15s ease;
}
.mon-membro-metrica:hover { filter: brightness(1.25); }
.mon-membro-metrica.suporte { grid-column: 2; color: #0DC2FF; }
.mon-membro-metrica.dev     { grid-column: 3; color: #f6ad55; }
.mon-membro-row:last-child > .mon-membro-nome-plain,
.mon-membro-row:last-child .mon-membro-metrica {
    border-bottom: none;
    padding-bottom: 0;
}

.mon-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: rgba(255,255,255,.3);
}
.mon-empty i { font-size: var(--mon-fs-2xl); margin-bottom: .75rem; display: block; color: rgba(13,194,255,.4); }

/* Drill-down: lista de chamados de um segmento */
#mon-drill-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(6,25,32,.7);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
#mon-drill-box {
    background: #0d1e2d;
    border: 1.5px solid rgba(255,255,255,.12);
    border-radius: 14px;
    padding: var(--mon-sp-2xl);
    width: 520px;
    max-width: 92vw;
    max-height: 72vh;
    display: flex;
    flex-direction: column;
    animation: monDrillPop .18s ease;
}
@keyframes monDrillPop { from { opacity:0; transform:scale(.94) } to { opacity:1; transform:scale(1) } }
#mon-drill-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--mon-sp-lg);
    margin-bottom: 1rem;
    flex-shrink: 0;
}
#mon-drill-title {
    margin: 0;
    color: #fff;
    font-family: 'Rubik', sans-serif;
    font-size: var(--mon-fs-num);
    font-weight: 600;
}
#mon-drill-subtitle {
    margin: .2rem 0 0;
    color: rgba(255,255,255,.4);
    font-size: var(--mon-fs-sm);
}
#mon-drill-close {
    background: none;
    border: none;
    color: rgba(255,255,255,.5);
    font-size: var(--mon-fs-icon);
    cursor: pointer;
    line-height: 1;
    padding: 0 var(--mon-sp-2xs);
}
#mon-drill-close:hover { color: #fff; }
#mon-drill-list {
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    /* scrollbar-gutter reserva o espaço da barra sem empurrar o layout quando ela aparece/some
     * (lista curta sem scroll vs. longa com scroll não pulam de largura); o padding extra dá
     * uma folga visual entre a thumb e o ícone de link externo na borda direita das linhas. */
    scrollbar-gutter: stable;
    padding-right: var(--mon-sp-sm);
    scrollbar-width: thin;
    scrollbar-color: rgba(13,194,255,.3) rgba(255,255,255,.03);
}
#mon-drill-list::-webkit-scrollbar { width: 7px; }
#mon-drill-list::-webkit-scrollbar-track { background: rgba(255,255,255,.03); border-radius: 4px; }
#mon-drill-list::-webkit-scrollbar-thumb { background: rgba(13,194,255,.3); border-radius: 4px; }
#mon-drill-list::-webkit-scrollbar-thumb:hover { background: rgba(13,194,255,.5); }
.mon-drill-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mon-sp-md);
    padding: var(--mon-sp-base) 0;
    border-bottom: 1px solid rgba(255,255,255,.06);
    text-decoration: none;
    color: #0DC2FF;
    font-size: var(--mon-fs-md);
    transition: color .15s;
}
.mon-drill-item:hover { color: #26d4ff; }
.mon-drill-item:last-child { border-bottom: none; }
.mon-drill-item-main { display: flex; flex-direction: column; gap: var(--mon-sp-3xs); min-width: 0; }
.mon-drill-id { font-family: 'Inter', monospace; font-weight: 700; }
.mon-drill-titletext {
    color: rgba(255,255,255,.6);
    font-size: var(--mon-fs-sm);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mon-drill-time {
    flex-shrink: 0;
    color: rgba(255,255,255,.5);
    font-size: var(--mon-fs-sm);
    font-family: 'Inter', monospace;
}

/* Modal de chat de uma tarefa */
#tsk-chat-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(6,25,32,.7);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
#tsk-chat-box {
    background: #0d1e2d;
    border: 1.5px solid rgba(255,255,255,.12);
    border-radius: 14px;
    padding: var(--mon-sp-2xl);
    width: 480px;
    max-width: 92vw;
    max-height: 72vh;
    display: flex;
    flex-direction: column;
    animation: monDrillPop .18s ease;
}
#tsk-chat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--mon-sp-lg);
    margin-bottom: 1rem;
    flex-shrink: 0;
}
#tsk-chat-title {
    margin: 0;
    color: #fff;
    font-family: 'Rubik', sans-serif;
    font-size: var(--mon-fs-num);
    font-weight: 600;
}
#tsk-chat-close {
    background: none;
    border: none;
    color: rgba(255,255,255,.5);
    font-size: var(--mon-fs-icon);
    cursor: pointer;
    line-height: 1;
    padding: 0 var(--mon-sp-2xs);
}
#tsk-chat-close:hover { color: #fff; }
#tsk-chat-list {
    overflow-y: auto;
    flex: 1;
    min-height: 0;
}

/* Modal de config dos webhooks pessoais (Atendimento) — CRUD, ver WebhooksPessoaisAtendimento */
#mon-webhooks-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(6,25,32,.7);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
#mon-webhooks-box {
    background: #0d1e2d;
    border: 1.5px solid rgba(255,255,255,.12);
    border-radius: 14px;
    padding: var(--mon-sp-2xl);
    width: 460px;
    max-width: 92vw;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    animation: monDrillPop .18s ease;
}
#mon-webhooks-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: var(--mon-sp-lg);
    margin-bottom: var(--mon-sp-lg);
    flex-shrink: 0;
}
#mon-webhooks-title {
    margin: 0;
    color: #fff;
    font-family: 'Rubik', sans-serif;
    font-size: var(--mon-fs-lg);
    font-weight: 600;
}
#mon-webhooks-subtitle {
    margin: .3rem 0 0;
    color: rgba(255,255,255,.4);
    font-size: var(--mon-fs-sm);
    line-height: 1.4;
}
#mon-webhooks-close {
    background: none;
    border: none;
    color: rgba(255,255,255,.5);
    font-size: var(--mon-fs-icon);
    cursor: pointer;
    line-height: 1;
    padding: 0 var(--mon-sp-2xs);
    flex-shrink: 0;
}
#mon-webhooks-close:hover { color: #fff; }
#mon-webhooks-list {
    overflow-y: auto;
    flex: 1;
    min-height: 0;
    margin-bottom: var(--mon-sp-lg);
}
.mon-webhook-row {
    display: flex;
    align-items: center;
    gap: var(--mon-sp-sm);
    padding: var(--mon-sp-sm) 0;
    border-bottom: 1px solid rgba(255,255,255,.06);
}
.mon-webhook-row:last-child { border-bottom: none; }
.mon-webhook-nome {
    color: #fff;
    font-size: var(--mon-fs-base);
    font-weight: 500;
    flex-shrink: 0;
    width: 110px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mon-webhook-url {
    color: rgba(255,255,255,.45);
    font-size: var(--mon-fs-sm);
    font-family: 'Inter', monospace;
    flex: 1;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mon-webhook-acoes { display: flex; gap: var(--mon-sp-2xs); flex-shrink: 0; }
.mon-webhook-acoes button {
    background: none;
    border: none;
    color: rgba(255,255,255,.4);
    cursor: pointer;
    font-size: var(--mon-fs-sm);
    padding: var(--mon-sp-2xs);
}
.mon-webhook-acoes button:hover { color: #fff; }
#mon-webhooks-form {
    display: flex;
    flex-direction: column;
    gap: var(--mon-sp-sm);
    flex-shrink: 0;
    border-top: 1px solid rgba(255,255,255,.08);
    padding-top: var(--mon-sp-lg);
}
#mon-webhooks-form input {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 8px;
    padding: var(--mon-sp-sm);
    font-size: var(--mon-fs-base);
    color: #fff;
    font-family: 'Inter', sans-serif;
    outline: none;
}
#mon-webhooks-form input::placeholder { color: rgba(255,255,255,0.30); }
#mon-webhooks-form input:focus { border-color: #0DC2FF; }
#mon-webhooks-form button {
    background: linear-gradient(90deg,#0DC2FF,#0080aa);
    border: none;
    border-radius: 8px;
    color: #061920;
    font-weight: 600;
    font-size: var(--mon-fs-base);
    padding: var(--mon-sp-sm);
    cursor: pointer;
}
#mon-webhooks-feedback { font-size: var(--mon-fs-sm); margin-top: var(--mon-sp-xs); flex-shrink: 0; min-height: 1.2em; }
#mon-webhooks-feedback.ok { color: #26FF93; }
#mon-webhooks-feedback.erro { color: #fc8181; }

/* ===== MONITORAMENTO KW24 — painel único com abas (Chamados abertos / Tarefas) =====
 * Antes eram 2 caixas em accordion (só uma expandida por vez); agora é 1 caixa só com uma
 * barra de abas no topo — só o conteúdo (filtros + thead + lista) da aba ativa é exibido
 * (display:none no outro), sem precisar de lógica de "colapsar". Ver monTrocarAba(). */
.mon-tabs-section {
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    flex: 1 1 0;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.mon-tabs-bar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    flex-shrink: 0;
}
.mon-tab {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--mon-sp-xs);
    padding: var(--mon-sp-base) var(--mon-sp-lg);
    cursor: pointer;
    opacity: .45;
    border-bottom: 2px solid transparent;
    transition: opacity .15s, background .15s;
}
.mon-tab:hover { opacity: .75; }
.mon-tab.active { opacity: 1; background: rgba(255,255,255,0.03); }
#mon-tab-cha.active { border-bottom-color: #0DC2FF; }
#mon-tab-tsk.active { border-bottom-color: #26FF93; }
.mon-tab-title {
    font-family: 'Rubik', sans-serif;
    font-size: var(--mon-fs-md);
    font-weight: 600;
    color: #fff;
    white-space: nowrap;
}
.mon-tab-title i { margin-right: var(--mon-sp-2xs); }
#mon-tab-cha .mon-tab-title i { color: #0DC2FF; }
#mon-tab-tsk .mon-tab-title i { color: #26FF93; }
.mon-tab-count {
    font-size: var(--mon-fs-2xs);
    color: rgba(255,255,255,.45);
    white-space: nowrap;
}
.mon-tab-content {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
}
.mon-tab-filters {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--mon-sp-2xs);
    margin-left: auto;
    padding: 0 var(--mon-sp-lg);
}
/* Dropdowns de filtro (Tipo/Responsável) do Chamados abertos — trigger compacto "Rótulo · N"
 * + painel flutuante com checkboxes, ver chaToggleDropdown()/chaRenderFiltroTipos()/
 * chaRenderFiltroPessoas(). Reaproveita o mesmo Set multi-select de sempre por baixo — só a
 * apresentação virou dropdown em vez de uma linha de pills, pra não competir por espaço
 * horizontal com as abas Chamados abertos/Tarefas. */
.cha-dropdown { position: relative; }
.cha-dropdown-trigger {
    display: flex;
    align-items: center;
    gap: var(--mon-sp-2xs);
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 8px;
    padding: var(--mon-sp-3xs) var(--mon-sp-sm);
    color: rgba(255,255,255,.75);
    font-size: var(--mon-fs-2xs);
    font-weight: 600;
    font-family: 'Inter', sans-serif;
    cursor: pointer;
    transition: background .15s, color .15s;
}
.cha-dropdown-trigger:hover { background: rgba(255,255,255,0.1); color: #fff; }
.cha-dropdown.open .cha-dropdown-trigger { background: rgba(13,194,255,0.12); border-color: rgba(13,194,255,0.4); color: #fff; }
.cha-dropdown-trigger i { font-size: .55rem; color: rgba(255,255,255,.4); transition: transform .15s; }
.cha-dropdown.open .cha-dropdown-trigger i { transform: rotate(180deg); }
.cha-dropdown-panel {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + var(--mon-sp-2xs));
    background: #0d1e2d;
    border: 1.5px solid rgba(255,255,255,.12);
    border-radius: 10px;
    padding: var(--mon-sp-xs);
    min-width: 200px;
    max-height: 280px;
    overflow-y: auto;
    z-index: 60;
    box-shadow: 0 8px 24px rgba(0,0,0,.4);
}
.cha-dropdown.open .cha-dropdown-panel { display: block; }
.cha-dropdown-item {
    display: flex;
    align-items: center;
    gap: var(--mon-sp-xs);
    padding: var(--mon-sp-2xs) var(--mon-sp-xs);
    border-radius: 6px;
    cursor: pointer;
    font-size: var(--mon-fs-sm);
    color: rgba(255,255,255,.8);
    white-space: nowrap;
}
.cha-dropdown-item:hover { background: rgba(255,255,255,.06); }
.cha-dropdown-item input[type="checkbox"] { accent-color: #0DC2FF; cursor: pointer; flex-shrink: 0; }
.cha-dropdown-item .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.cha-dropdown-empty { padding: var(--mon-sp-sm); font-size: var(--mon-fs-sm); color: rgba(255,255,255,.35); white-space: normal; }
.cha-thead {
    display: grid;
    grid-template-columns: clamp(21px,1.54vw,26px) minmax(clamp(112px,8.2vw,140px),1.4fr) minmax(clamp(80px,5.86vw,100px),0.8fr) minmax(clamp(88px,6.44vw,110px),0.9fr) minmax(clamp(64px,4.69vw,82px),0.6fr) minmax(clamp(80px,5.86vw,100px),0.8fr) minmax(clamp(72px,5.27vw,90px),0.8fr) clamp(62px,4.54vw,78px) clamp(40px,2.93vw,50px);
    gap: var(--mon-sp-base);
    align-items: center;
    padding: var(--mon-sp-sm) var(--mon-sp-lg);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    flex-shrink: 0;
}
.cha-th {
    font-size: var(--mon-fs-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: rgba(255,255,255,.35);
    white-space: nowrap;
}
/* Cabeçalhos de coluna clicáveis (ordenação) — reutilizado por Chamados abertos e Tarefas,
 * ver .mon-sort-icon e chaOrdenarPor()/tskOrdenarPor(). */
.cha-th-sort, .tsk-th-sort {
    display: flex;
    align-items: center;
    gap: var(--mon-sp-2xs);
    cursor: pointer;
    user-select: none;
}
.cha-th-sort:hover, .tsk-th-sort:hover { color: rgba(255,255,255,.6); }
.mon-sort-icon { font-size: var(--mon-fs-2xs); color: rgba(255,255,255,.25); }
.mon-sort-icon.active { color: #0DC2FF; }
/* Piso de ~12 linhas visíveis (thead + 12 * ~34px de linha) — cresce com flex:1 quando há
 * espaço de sobra na página (mostra mais de 12 sem precisar rolar). Dois mecanismos
 * independentes, não um substituto do outro: min-height é o piso (nunca encolhe abaixo dele —
 * se nem ele couber na tela, o scroll da página inteira, .content-area:has(.mon-updated),
 * assume o excesso); overflow-y:auto é o scroll PRÓPRIO da lista, pra quando ela tem mais
 * linhas do que cabem na altura que ela de fato ocupa (12 ou mais, dependendo do espaço
 * disponível) — sem isso, uma lista de 35 chamados tentaria crescer pra caber todo mundo de
 * uma vez, em vez de rolar internamente. */
.cha-list {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 440px;
    overflow-y: auto;
}
.cha-list::-webkit-scrollbar { width: 5px; }
.cha-list::-webkit-scrollbar-track { background: rgba(255,255,255,0.03); }
.cha-list::-webkit-scrollbar-thumb { background: rgba(13,194,255,0.25); border-radius: 3px; }
.cha-row { border-bottom: 1px solid rgba(255,255,255,0.06); }
.cha-row:last-child { border-bottom: none; }
.cha-row-main {
    display: grid;
    grid-template-columns: clamp(21px,1.54vw,26px) minmax(clamp(112px,8.2vw,140px),1.4fr) minmax(clamp(80px,5.86vw,100px),0.8fr) minmax(clamp(88px,6.44vw,110px),0.9fr) minmax(clamp(64px,4.69vw,82px),0.6fr) minmax(clamp(80px,5.86vw,100px),0.8fr) minmax(clamp(72px,5.27vw,90px),0.8fr) clamp(62px,4.54vw,78px) clamp(40px,2.93vw,50px);
    gap: var(--mon-sp-base);
    align-items: center;
    padding: var(--mon-sp-xs) var(--mon-sp-lg);
    cursor: pointer;
}
.cha-row-main:hover { background: rgba(255,255,255,0.03); }
.cha-chevron-btn {
    background: none;
    border: none;
    color: rgba(255,255,255,.28);
    cursor: pointer;
    padding: var(--mon-sp-xs);
    flex-shrink: 0;
    transition: color .15s, transform .2s;
    line-height: 1;
}
.cha-chevron-btn.open { color: #0DC2FF; transform: rotate(90deg); }
.cha-row-chamado { display: flex; align-items: center; gap: var(--mon-sp-sm); min-width: 0; }
.cha-row-id {
    font-family: 'Inter', monospace;
    font-size: var(--mon-fs-base);
    font-weight: 700;
    color: #0DC2FF;
    text-decoration: none;
    flex-shrink: 0;
}
.cha-row-id:hover { color: #26d4ff; }
.cha-row-title {
    color: #fff;
    font-size: var(--mon-fs-md);
    font-weight: 500;
    flex: 1 1 0;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.cha-badge {
    font-size: var(--mon-fs-xs);
    font-weight: 700;
    padding: var(--mon-sp-3xs) var(--mon-sp-sm);
    border-radius: 20px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
    min-width: 0;
    justify-self: start;
}
.cha-etapa {
    font-size: var(--mon-fs-sm);
    color: rgba(255,255,255,.5);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
}
.cha-solicitante {
    font-size: var(--mon-fs-base);
    color: rgba(255,255,255,.65);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
}
.cha-empresa {
    font-size: var(--mon-fs-sm);
    color: rgba(255,255,255,.5);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    min-width: 0;
}
.cha-avatares { display: flex; gap: var(--mon-sp-2xs); }
.cha-avatar {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: linear-gradient(135deg,#0DC2FF,#086B8D);
    color: #061920;
    font-size: var(--mon-fs-2xs);
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.cha-sem-resp { font-size: var(--mon-fs-xs); color: rgba(255,255,255,.3); white-space: nowrap; }
.cha-acoes { display: flex; gap: var(--mon-sp-sm); align-items: center; justify-content: flex-end; }
.cha-chat-icon { color: rgba(255,255,255,.35); flex-shrink: 0; font-size: var(--mon-fs-base); cursor: pointer; }
.cha-chat-icon:hover { color: #b794f4; }
.cha-link-icon { color: rgba(255,255,255,.35); flex-shrink: 0; font-size: var(--mon-fs-base); text-decoration: none; }
.cha-link-icon:hover { color: #0DC2FF; }
.cha-row-detail { display: none; }
.cha-row-detail.open { display: block; }
.cha-detail-inner {
    padding: var(--mon-sp-base) var(--mon-sp-lg) var(--mon-sp-lg) 2.4rem;
    background: rgba(13,194,255,.03);
    border-top: 1px solid rgba(13,194,255,.10);
}
.cha-detail-label {
    font-size: var(--mon-fs-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #0DC2FF;
    margin-bottom: .35rem;
}
.cha-detail-text { color: rgba(255,255,255,.75); font-size: var(--mon-fs-md); line-height: 1.5; }

/* ===== MONITORAMENTO KW24 — Tarefas ===== */
.tsk-thead {
    display: grid;
    grid-template-columns: clamp(21px,1.54vw,26px) clamp(56px,4.1vw,70px) minmax(clamp(88px,6.44vw,110px),1.6fr) minmax(clamp(56px,4.1vw,70px),0.8fr) minmax(clamp(56px,4.1vw,70px),0.8fr) minmax(clamp(48px,3.51vw,60px),0.7fr) minmax(clamp(48px,3.51vw,60px),0.7fr) minmax(clamp(64px,4.69vw,80px),0.7fr) clamp(16px,1.17vw,20px);
    gap: var(--mon-sp-base);
    align-items: center;
    padding: var(--mon-sp-sm) var(--mon-sp-lg);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    flex-shrink: 0;
}
.tsk-th {
    font-size: var(--mon-fs-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: rgba(255,255,255,.35);
    white-space: nowrap;
}
.tsk-filter-pill {
    font-size: var(--mon-fs-2xs);
    font-weight: 600;
    padding: var(--mon-sp-3xs) var(--mon-sp-xs);
    border-radius: 12px;
    cursor: pointer;
    border: 1px solid rgba(183,148,244,.35);
    color: rgba(255,255,255,.5);
    background: transparent;
    transition: background .15s, color .15s, border-color .15s;
    user-select: none;
}
.tsk-filter-pill:hover { border-color: rgba(183,148,244,.6); color: rgba(255,255,255,.8); }
.tsk-filter-pill.active {
    background: linear-gradient(90deg,#b794f4,#805ad5);
    color: #fff;
    border-color: transparent;
}
/* Pills de filtro por pessoa (Tarefas) — verde. O filtro por Tipo/Responsável de Chamados
 * abertos usa outro componente (dropdown com checkboxes, ver .cha-dropdown-*), não pills. */
.tsk-filter-pill.pessoa { border-color: rgba(38,255,147,.35); }
.tsk-filter-pill.pessoa:hover { border-color: rgba(38,255,147,.6); color: rgba(255,255,255,.8); }
.tsk-filter-pill.pessoa.active {
    background: linear-gradient(90deg,#26FF93,#1a9c5a);
    color: #061920;
    border-color: transparent;
}
/* Mesmo piso + scroll próprio de .cha-list (ver comentário lá) — mesmo painel com abas,
 * mesma regra de altura pras duas. */
.tsk-list {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 440px;
    overflow-y: auto;
}
.tsk-list::-webkit-scrollbar { width: 5px; }
.tsk-list::-webkit-scrollbar-track { background: rgba(255,255,255,0.03); }
.tsk-list::-webkit-scrollbar-thumb { background: rgba(183,148,244,0.25); border-radius: 3px; }
.tsk-row {
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.tsk-row:last-child { border-bottom: none; }
.tsk-row-main {
    display: grid;
    grid-template-columns: clamp(21px,1.54vw,26px) clamp(56px,4.1vw,70px) minmax(clamp(88px,6.44vw,110px),1.6fr) minmax(clamp(56px,4.1vw,70px),0.8fr) minmax(clamp(56px,4.1vw,70px),0.8fr) minmax(clamp(48px,3.51vw,60px),0.7fr) minmax(clamp(48px,3.51vw,60px),0.7fr) minmax(clamp(64px,4.69vw,80px),0.7fr) clamp(16px,1.17vw,20px);
    gap: var(--mon-sp-base);
    align-items: center;
    padding: var(--mon-sp-xs) var(--mon-sp-lg);
    cursor: pointer;
}
.tsk-row-main:hover { background: rgba(255,255,255,0.03); }
.tsk-chevron-btn {
    background: none;
    border: none;
    color: rgba(255,255,255,.28);
    cursor: pointer;
    padding: var(--mon-sp-xs);
    flex-shrink: 0;
    transition: color .15s, transform .2s;
    line-height: 1;
}
.tsk-chevron-btn.open { color: #b794f4; transform: rotate(90deg); }
.tsk-row-id {
    font-family: 'Inter', monospace;
    font-size: var(--mon-fs-base);
    font-weight: 700;
    color: #0DC2FF;
    text-decoration: none;
    flex-shrink: 0;
}
.tsk-row-id:hover { color: #26d4ff; }
.tsk-row-title {
    color: #fff;
    font-size: var(--mon-fs-md);
    font-weight: 500;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding-right: var(--mon-sp-lg); /* respiro fluido antes de Criador — não tira largura da coluna vizinha */
}
.tsk-th-prazo { justify-content: flex-end; } /* alinha com .tsk-deadline (text-align:right) */
.tsk-pessoa-cell { min-width: 0; overflow: hidden; }
.tsk-outros { display: flex; flex-wrap: wrap; gap: var(--mon-sp-xs); min-width: 0; }
.tsk-badge {
    display: inline-block;
    max-width: 100%;
    font-size: var(--mon-fs-xs);
    font-weight: 600;
    padding: var(--mon-sp-3xs) var(--mon-sp-sm);
    border-radius: 20px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: top;
}
.tsk-badge.forte  { background: linear-gradient(90deg,#26FF93,#1a9c5a); color: #061920; }
.tsk-badge.media  { background: rgba(38,255,147,.3); color: #fff; }
.tsk-badge.fraca  { background: transparent; border: 1px solid rgba(38,255,147,.4); color: #26FF93; }
/* Criador/Responsável quando NÃO é um dos 4 da equipe — visível, mas neutro (sem destaque),
 * pra separar visualmente de quem é "da casa" (ver tskPessoaChipHtml()). */
.tsk-badge.externo { background: transparent; border: 1px solid rgba(255,255,255,.15); color: rgba(255,255,255,.55); }
.tsk-deadline {
    font-size: var(--mon-fs-base);
    color: rgba(255,255,255,.5);
    white-space: nowrap;
    text-align: right;
}
.tsk-deadline.atrasada { color: #fc8181; font-weight: 600; }
.tsk-chat-icon { color: rgba(255,255,255,.35); flex-shrink: 0; font-size: var(--mon-fs-base); }
.tsk-row-detail { display: none; }
.tsk-row-detail.open { display: block; }
.tsk-detail-inner {
    padding: var(--mon-sp-base) var(--mon-sp-lg) var(--mon-sp-lg) 2.4rem;
    background: rgba(183,148,244,.03);
    border-top: 1px solid rgba(183,148,244,.10);
}
.tsk-detail-label {
    font-size: var(--mon-fs-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #b794f4;
    margin-bottom: .35rem;
    margin-top: .85rem;
}
.tsk-detail-label:first-child { margin-top: 0; }
.tsk-detail-text { color: rgba(255,255,255,.75); font-size: var(--mon-fs-md); line-height: 1.5; }
.tsk-chat-msg {
    display: flex;
    flex-direction: column;
    gap: var(--mon-sp-3xs);
    padding: var(--mon-sp-sm) 0;
    border-bottom: 1px solid rgba(255,255,255,.05);
}
.tsk-chat-msg:last-child { border-bottom: none; }
.tsk-chat-msg-head {
    display: flex;
    justify-content: space-between;
    font-size: var(--mon-fs-sm);
}
.tsk-chat-msg-autor { color: #b794f4; font-weight: 600; }
.tsk-chat-msg-data { color: rgba(255,255,255,.35); }
.tsk-chat-msg-texto { color: rgba(255,255,255,.7); font-size: var(--mon-fs-base); line-height: 1.45; white-space: pre-wrap; }

/* ===== MONITORAMENTO KW24 — linha do topo (Funil + Atendimento lado a lado) ===== */
.topo-row {
    display: flex;
    gap: var(--mon-sp-lg);
    margin-bottom: var(--mon-sp-lg);
    flex-shrink: 0;
}
@media (max-width: 1024px) {
    .topo-row { flex-direction: column; }
}

/* ===== MONITORAMENTO KW24 — Funil (volume: criados / finalizados, SPA 1054 / Funil 208) ===== */
.fun-box {
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    flex: 1 1 0;
    min-width: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.fun-box-header {
    padding: var(--mon-sp-base) var(--mon-sp-lg);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: baseline;
    gap: var(--mon-sp-base);
    font-family: 'Rubik', sans-serif;
    font-size: var(--mon-fs-lg);
    font-weight: 600;
    color: #fff;
}
.fun-box-header i { color: #0DC2FF; margin-right: .2rem; }
.fun-box-ciclo {
    font-size: var(--mon-fs-sm);
    font-weight: 500;
    color: rgba(255,255,255,.4);
    font-family: 'Inter', monospace;
}
.fun-cards {
    display: flex;
    flex-direction: row;
}
.fun-card {
    flex: 1 1 0;
    min-width: 0;
    display: flex;
    flex-direction: column;
    border-right: 1px solid rgba(255,255,255,0.08);
}
.fun-card:last-child { border-right: none; }
.fun-card-header {
    padding: var(--mon-sp-sm) var(--mon-sp-lg);
    font-family: 'Rubik', sans-serif;
    font-size: var(--mon-fs-md);
    font-weight: 600;
    color: #fff;
}
.fun-card-header i { margin-right: .5rem; }
.fun-card-header.criados i { color: #0DC2FF; }
.fun-card-header.finalizados i { color: #48bb78; }
.fun-card-body {
    padding: var(--mon-sp-sm) var(--mon-sp-lg) var(--mon-sp-sm);
    display: flex;
    flex-wrap: wrap;
    gap: var(--mon-sp-md);
}
.fun-stat { display: flex; flex-direction: column; gap: var(--mon-sp-2xs); }
.fun-stat-value {
    font-size: var(--mon-fs-num);
    font-weight: 700;
    color: #fff;
    font-family: 'Inter', monospace;
}
.fun-stat-label {
    font-size: var(--mon-fs-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: rgba(255,255,255,.4);
}
.fun-dist {
    padding: var(--mon-sp-sm) var(--mon-sp-lg) var(--mon-sp-sm);
    border-top: 1px solid rgba(255,255,255,0.08);
}
.fun-dist-header {
    font-size: var(--mon-fs-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: rgba(255,255,255,.4);
    margin-bottom: .65rem;
}
.fun-dist-row {
    display: flex;
    align-items: center;
    gap: var(--mon-sp-base);
    margin-bottom: .4rem;
}
.fun-dist-row:last-child { margin-bottom: 0; }
.fun-dist-label {
    flex: 0 0 clamp(110px, 11vw, 150px);
    min-width: 0;
    font-size: var(--mon-fs-sm);
    color: rgba(255,255,255,.65);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.fun-dist-track {
    flex: 1;
    height: 10px;
    background: rgba(255,255,255,.06);
    border-radius: 5px;
    overflow: hidden;
}
.fun-dist-fill { display: block; height: 100%; border-radius: 5px; }
.fun-dist-value {
    flex: 0 0 26px;
    text-align: right;
    font-size: var(--mon-fs-sm);
    font-weight: 700;
    color: #fff;
    font-family: 'Inter', monospace;
}

/* ===== MONITORAMENTO KW24 — Atendimento (Contact Center / Open Lines, fila "Geral KW24 - Suporte") ===== */
.ate-section {
    background: rgba(255,255,255,0.05);
    border: 1.5px solid rgba(255,255,255,0.10);
    border-radius: 12px;
    flex: 1 1 0;
    min-width: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.ate-kpis {
    padding: var(--mon-sp-sm) var(--mon-sp-lg);
    border-bottom: 1px solid rgba(255,255,255,0.08);
    display: flex;
    gap: var(--mon-sp-lg);
    flex-wrap: wrap;
}
.ate-kpi { display: flex; flex-direction: column; gap: var(--mon-sp-3xs); }
.ate-kpi-value {
    font-size: var(--mon-fs-num);
    font-weight: 700;
    color: #fff;
    font-family: 'Inter', monospace;
}
.ate-kpi-value.alerta { color: #fc8181; }
.ate-kpi-label {
    font-size: var(--mon-fs-2xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: rgba(255,255,255,.4);
}
/* Altura FIXA, igual à do Funil (o vizinho de linha estável, sem novas etapas previstas) —
 * não à toa, sem nenhum valor hardcoded: .topo-row usa align-items:stretch (default) e o
 * Funil já é naturalmente mais alto que o conteúdo mínimo do Atendimento (tabs-bar+kpis, já
 * que .ate-list tem min-height:0 e pode encolher a ~0) — então a LINHA toda fica com a altura
 * do Funil, e .ate-section (flex:1 1 0 na coluna) estica pra acompanhar, em ambas as abas
 * (Conversas/Grupos) igualmente, sem variar com a quantidade de itens. Excesso de conteúdo
 * rola dentro do próprio .ate-list (única exceção à regra "sem scroll por painel" da Parte 1
 * — aqui o objetivo é manter a altura do card fixa, não deixá-la crescer). */
.ate-list {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
}
.ate-list::-webkit-scrollbar { width: 5px; }
.ate-list::-webkit-scrollbar-track { background: rgba(255,255,255,0.03); }
.ate-list::-webkit-scrollbar-thumb { background: rgba(38,255,147,0.25); border-radius: 3px; }
.ate-row {
    display: flex;
    align-items: center;
    gap: var(--mon-sp-base);
    padding: var(--mon-sp-2xs) var(--mon-sp-lg);
    border-bottom: 1px solid rgba(255,255,255,0.06);
    text-decoration: none;
}
.ate-row:last-child { border-bottom: none; }
.ate-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.ate-dot.aguardando { background: #fc8181; }
.ate-dot.respondido { background: #48bb78; }
.ate-row-main { display: flex; flex-direction: column; gap: var(--mon-sp-3xs); min-width: 0; flex: 1; }
.ate-row-titulo {
    color: #fff;
    font-size: var(--mon-fs-md);
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ate-row-msg {
    color: rgba(255,255,255,.45);
    font-size: var(--mon-fs-sm);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ate-row-tempo {
    flex-shrink: 0;
    font-size: var(--mon-fs-sm);
    color: rgba(255,255,255,.5);
    font-family: 'Inter', monospace;
    white-space: nowrap;
}
.ate-row-tempo.aguardando { color: #fc8181; font-weight: 600; }
/* Agrupamento "Aguardando atendimento" (ninguém reclamou) vs "Sendo atendida" (ver
 * statusFila/agrupamentoPorResponsavel em MonitoramentoAtendimentoService) — só aparece
 * quando há 2+ webhooks pessoais cadastrados; com 1 só (ou fallback automação), a lista
 * continua plana como antes. */
.ate-group-header {
    font-size: var(--mon-fs-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: rgba(255,255,255,.4);
    padding: var(--mon-sp-xs) var(--mon-sp-lg) var(--mon-sp-2xs);
    background: rgba(255,255,255,.02);
}
.ate-group-header.alerta { color: #fc8181; }
.ate-row-responsavel {
    flex-shrink: 0;
    font-size: var(--mon-fs-xs);
    color: rgba(38,255,147,.85);
    font-weight: 600;
    white-space: nowrap;
}
/* Abas "Conversas" / "Grupos" dentro do Atendimento — mesmo padrão visual/interação de
 * .mon-tabs-bar/.mon-tab (Chamados abertos/Tarefas), reaproveitado aqui via IDs próprios
 * (ateTrocarAba(), independente de monTrocarAba()). Grupo de WhatsApp fica isolado das
 * métricas de "Conversas" — ver ehGrupo() em MonitoramentoAtendimentoService. */
#mon-tab-ate-conv.active  { border-bottom-color: #26FF93; }
#mon-tab-ate-grupo.active { border-bottom-color: #25D366; }
#mon-tab-ate-conv .mon-tab-title i  { color: #26FF93; }
#mon-tab-ate-grupo .mon-tab-title i { color: #25D366; }
</style>

<div class="page-header">
    <h1 class="page-title"><i class="fas fa-satellite-dish" style="color:#0DC2FF;margin-right:.5rem"></i>Monitoramento KW24</h1>
    <div class="page-header-actions">
        <span class="mon-updated" id="mon-updated">—</span>
        <button class="mon-config-btn" id="mon-config-btn" onclick="monAbrirConfigWebhooks()" title="Webhooks pessoais (Atendimento)">
            <i class="fas fa-cog"></i>
        </button>
        <button class="btn-primary" id="mon-refresh-btn" onclick="monAtualizar()">
            <i class="fas fa-sync-alt" id="mon-refresh-icon"></i> Atualizar
        </button>
    </div>
</div>

<div class="topo-row">
    <div class="ate-section">
        <div class="mon-tabs-bar">
            <div class="mon-tab active" id="mon-tab-ate-conv" onclick="ateTrocarAba('conv')">
                <span class="mon-tab-title"><i class="fas fa-comments"></i>Conversas</span>
            </div>
            <div class="mon-tab" id="mon-tab-ate-grupo" onclick="ateTrocarAba('grupo')">
                <span class="mon-tab-title"><i class="fab fa-whatsapp"></i>Grupos</span>
                <span class="mon-tab-count" id="ate-grupo-count">0</span>
            </div>
        </div>
        <div class="mon-tab-content" id="ate-tab-content-conv">
            <div class="ate-kpis" id="ate-kpis"></div>
            <div class="ate-list" id="ate-list">
                <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
            </div>
        </div>
        <div class="mon-tab-content" id="ate-tab-content-grupo" style="display:none">
            <div class="ate-list" id="ate-grupo-list">
                <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
            </div>
        </div>
    </div>

    <div class="fun-box" id="fun-section">
        <div class="fun-box-header"><i class="fas fa-filter"></i>Funil<span class="fun-box-ciclo" id="fun-ciclo"></span></div>
        <div class="fun-cards">
            <div class="fun-card">
                <div class="fun-card-header criados"><i class="fas fa-inbox"></i>Chamados criados</div>
                <div class="fun-card-body" id="fun-criados-body">
                    <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
                </div>
            </div>
            <div class="fun-card">
                <div class="fun-card-header finalizados"><i class="fas fa-check-circle"></i>Chamados finalizados</div>
                <div class="fun-card-body" id="fun-finalizados-body">
                    <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
                </div>
            </div>
        </div>
        <div class="fun-dist">
            <div class="fun-dist-header">Distribuição dos chamados abertos</div>
            <div id="fun-dist-rows"></div>
        </div>
    </div>
</div>

<div class="mon-panels-row">
    <div class="mon-equipe-card">
        <div class="mon-equipe-header">
            <span><i class="fas fa-users"></i>Equipe</span>
            <span class="mon-equipe-total" id="mon-equipe-total"></span>
        </div>
        <div class="mon-equipe-body" id="mon-equipe-grid">
            <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
        </div>
    </div>
</div>

<div class="mon-right-col">
    <div class="mon-tabs-section">
            <div class="mon-tabs-bar">
                <div class="mon-tab active" id="mon-tab-cha" onclick="monTrocarAba('cha')">
                    <span class="mon-tab-title"><i class="fas fa-inbox"></i>Chamados abertos</span>
                    <span class="mon-tab-count" id="cha-count">Carregando…</span>
                </div>
                <div class="mon-tab" id="mon-tab-tsk" onclick="monTrocarAba('tsk')">
                    <span class="mon-tab-title"><i class="fas fa-list-check"></i>Tarefas</span>
                    <span class="mon-tab-count" id="tsk-count">Carregando…</span>
                </div>
                <div class="mon-tab-filters" id="cha-filtros">
                    <div class="cha-dropdown" id="cha-dropdown-tipo">
                        <button class="cha-dropdown-trigger" onclick="chaToggleDropdown('tipo')">
                            <span>Tipo · <span id="cha-dropdown-tipo-count">0</span></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="cha-dropdown-panel" id="cha-dropdown-tipo-panel"></div>
                    </div>
                    <div class="cha-dropdown" id="cha-dropdown-pessoa">
                        <button class="cha-dropdown-trigger" onclick="chaToggleDropdown('pessoa')">
                            <span>Responsável · <span id="cha-dropdown-pessoa-count">0</span></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="cha-dropdown-panel" id="cha-dropdown-pessoa-panel"></div>
                    </div>
                </div>
                <div class="mon-tab-filters" id="tsk-filter-row" style="display:none"></div>
            </div>

            <div class="mon-tab-content" id="mon-tab-content-cha">
                <div class="cha-thead">
                    <span></span>
                    <span class="cha-th">Chamado</span>
                    <span class="cha-th">Empresa</span>
                    <span class="cha-th cha-th-sort" data-col="tipo" onclick="chaOrdenarPor('tipo')">Tipo<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="cha-th cha-th-sort" data-col="prioridade" onclick="chaOrdenarPor('prioridade')">Prior.<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="cha-th cha-th-sort" data-col="etapa" onclick="chaOrdenarPor('etapa')">Etapa<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="cha-th">Solicitante</span>
                    <span class="cha-th">Resp.</span>
                    <span></span>
                </div>
                <div class="cha-list" id="cha-list">
                    <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
                </div>
            </div>

            <div class="mon-tab-content" id="mon-tab-content-tsk" style="display:none">
                <div class="tsk-thead">
                    <span></span>
                    <span></span>
                    <span class="tsk-th">Tarefa</span>
                    <span class="tsk-th tsk-th-sort" data-col="criador" onclick="tskOrdenarPor('criador')">Criador<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="tsk-th tsk-th-sort" data-col="responsavel" onclick="tskOrdenarPor('responsavel')">Responsável<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="tsk-th tsk-th-sort" data-col="participantes" onclick="tskOrdenarPor('participantes')">Participantes<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="tsk-th tsk-th-sort" data-col="observadores" onclick="tskOrdenarPor('observadores')">Observadores<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span class="tsk-th tsk-th-sort tsk-th-prazo" data-col="prazo" onclick="tskOrdenarPor('prazo')">Prazo<i class="fas fa-sort mon-sort-icon"></i></span>
                    <span></span>
                </div>
                <div class="tsk-list" id="tsk-list">
                    <div class="mon-empty"><i class="fas fa-spinner fa-spin"></i><div>Carregando…</div></div>
                </div>
            </div>
        </div>
    </div>
<!-- Drill-down: chamados por trás de um segmento da barra -->
<div id="mon-drill-overlay" onclick="if(event.target===this) monFecharDrill()">
    <div id="mon-drill-box">
        <div id="mon-drill-header">
            <div>
                <h3 id="mon-drill-title"></h3>
                <p id="mon-drill-subtitle"></p>
            </div>
            <button id="mon-drill-close" onclick="monFecharDrill()" aria-label="Fechar">&times;</button>
        </div>
        <div id="mon-drill-list"></div>
    </div>
</div>

<!-- Modal de chat de uma tarefa (clique no ícone de chat) -->
<div id="tsk-chat-overlay" onclick="if(event.target===this) tskFecharChat()">
    <div id="tsk-chat-box">
        <div id="tsk-chat-header">
            <h3 id="tsk-chat-title"></h3>
            <button id="tsk-chat-close" onclick="tskFecharChat()" aria-label="Fechar">&times;</button>
        </div>
        <div id="tsk-chat-list"></div>
    </div>
</div>

<!-- Config dos webhooks pessoais (Atendimento) — um webhook Bitrix24 (escopo im) por
     colaborador, pra o painel ver as conversas que cada um participa. -->
<div id="mon-webhooks-overlay" onclick="if(event.target===this) monFecharConfigWebhooks()">
    <div id="mon-webhooks-box">
        <div id="mon-webhooks-header">
            <div>
                <h3 id="mon-webhooks-title">Webhooks pessoais — Atendimento</h3>
                <p id="mon-webhooks-subtitle">Cada colaborador gera seu próprio webhook Bitrix24
                    (escopo <code>im</code>) no perfil dele — cadastre aqui pra o painel
                    Atendimento agregar as conversas de todos, não só as do automação.</p>
            </div>
            <button id="mon-webhooks-close" onclick="monFecharConfigWebhooks()" aria-label="Fechar">&times;</button>
        </div>
        <div id="mon-webhooks-list"></div>
        <div id="mon-webhooks-form">
            <input type="text" id="mon-webhook-nome" placeholder="Preenchido automaticamente ao validar o webhook">
            <input type="url" id="mon-webhook-url" placeholder="https://.../rest/.../TOKEN/" onblur="monValidarWebhookPessoal()">
            <button onclick="monSalvarWebhookPessoal()"><span id="mon-webhooks-submit-label">Adicionar</span></button>
        </div>
        <div id="mon-webhooks-feedback"></div>
    </div>
</div>

<script>
(function () {

    var AUTO_REFRESH_MS = 30 * 60 * 1000; // 30 minutos
    var lastData = null;

    // ── Abas Chamados abertos/Tarefas — painel único, só uma aba visível por vez ──
    // Padrão: Chamados abertos (o mais checado com mais frequência).
    var monAbaAtiva = 'cha';

    function monAtualizarAbas() {
        var tabCha  = document.getElementById('mon-tab-cha');
        var tabTsk  = document.getElementById('mon-tab-tsk');
        var contCha = document.getElementById('mon-tab-content-cha');
        var contTsk = document.getElementById('mon-tab-content-tsk');
        var filCha  = document.getElementById('cha-filtros');
        var filTsk  = document.getElementById('tsk-filter-row');
        if (!tabCha || !tabTsk || !contCha || !contTsk) return;

        tabCha.classList.toggle('active', monAbaAtiva === 'cha');
        tabTsk.classList.toggle('active', monAbaAtiva === 'tsk');
        contCha.style.display = monAbaAtiva === 'cha' ? 'flex' : 'none';
        contTsk.style.display = monAbaAtiva === 'tsk' ? 'flex' : 'none';
        if (filCha) filCha.style.display = monAbaAtiva === 'cha' ? 'flex' : 'none';
        if (filTsk) filTsk.style.display = monAbaAtiva === 'tsk' ? 'flex' : 'none';
    }

    window.monTrocarAba = function (aba) {
        if (monAbaAtiva === aba) return;
        monAbaAtiva = aba;
        monAtualizarAbas();
    };

    monAtualizarAbas();

    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // "H:MM" exato a partir dos minutos totais — sem arredondar pra hora cheia (20min não pode
    // virar "0h", escondendo o valor real). Minutos sempre com 2 dígitos (ex.: "0:05", "1:30").
    function fmtHM(totalMinutos) {
        var min = Math.max(0, Math.round(totalMinutos || 0));
        var h   = Math.floor(min / 60);
        var m   = min % 60;
        return h + ':' + (m < 10 ? '0' + m : m);
    }

    // Linha em texto puro (sem bar/gráfico) — nome à esquerda, contadores clicáveis à
    // direita, abrindo o mesmo drill-down de antes. "Em andamento" foi removido daqui (só
    // da UI — a query em MonitoramentoEquipeService.php continua intacta, ver relatório).
    function membroCardHtml(m, idx) {
        var fin       = m.finalizado || {};
        var finSupMin = (fin.suporte && fin.suporte.minutos) || 0;
        var finDevMin = (fin.desenvolvimento && fin.desenvolvimento.minutos) || 0;
        var finSupCnt = (fin.suporte && fin.suporte.count) || 0;
        var finDevCnt = (fin.desenvolvimento && fin.desenvolvimento.count) || 0;

        return '<div class="mon-membro-row" style="--eq-row:' + (idx + 1) + '">'
            + '<span class="mon-membro-nome-plain">' + escHtml(m.nome) + '</span>'
            + '<span class="mon-membro-metricas">'
                + '<span class="mon-membro-metrica suporte" onclick="monAbrirDrill(' + idx + ',\'finalizado\',\'suporte\')">Suporte ' + finSupCnt + '·' + fmtHM(finSupMin) + '</span>'
                + '<span class="mon-membro-metrica dev" onclick="monAbrirDrill(' + idx + ',\'finalizado\',\'desenvolvimento\')">Dev ' + finDevCnt + '·' + fmtHM(finDevMin) + '</span>'
            + '</span>'
            + '</div>';
    }

    function renderEquipeTotal(totalMinutos) {
        var el = document.getElementById('mon-equipe-total');
        if (!el) return;
        if (!totalMinutos) { el.innerHTML = ''; return; }

        el.innerHTML =
            '<span><b>' + fmtHM(totalMinutos.suporte || 0) + '</b> Suporte</span>'
            + '<span><b>' + fmtHM(totalMinutos.desenvolvimento || 0) + '</b> Dev</span>';
    }

    function render(data) {
        var grid = document.getElementById('mon-equipe-grid');

        if (data.aviso) {
            grid.innerHTML = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            renderEquipeTotal(null);
            return;
        }

        renderEquipeTotal(data.totalFinalizadoMinutos);

        var equipe = data.equipe || [];
        if (!equipe.length) {
            grid.innerHTML = '<div class="mon-empty"><i class="fas fa-inbox"></i><div>Nenhum dado disponível.</div></div>';
            return;
        }

        grid.innerHTML = equipe.map(membroCardHtml).join('');
    }

    // ── Drill-down: lista de chamados (com ID clicável para o Bitrix24) por trás de um segmento ──
    var ROW_LABELS    = { finalizado: 'Finalizado no ciclo' };
    var BUCKET_LABELS = { suporte: 'Suporte', desenvolvimento: 'Desenvolvimento' };

    function bitrixCardUrl(id) {
        var base = (lastData && lastData.bitrixBase) || '';
        return base ? (base + '/crm/type/1054/details/' + id + '/') : '';
    }

    function drillItemHtml(card, rowKey) {
        var url = bitrixCardUrl(card.id);
        var timeHtml = (rowKey === 'finalizado')
            ? '<span class="mon-drill-time">' + (card.minutos || 0) + ' min</span>'
            : '';
        var body = '<span class="mon-drill-item-main">'
            + '<span class="mon-drill-id">#' + card.id + '</span>'
            + '<span class="mon-drill-titletext">' + escHtml(card.title || '') + '</span>'
            + '</span>'
            + timeHtml
            + '<i class="fas fa-external-link-alt" style="flex-shrink:0"></i>';

        return url
            ? '<a class="mon-drill-item" href="' + escHtml(url) + '" target="_blank" rel="noopener">' + body + '</a>'
            : '<div class="mon-drill-item" style="cursor:default">' + body + '</div>';
    }

    window.monAbrirDrill = function (personIdx, rowKey, bucketKey) {
        if (!lastData || !lastData.equipe || !lastData.equipe[personIdx]) return;

        var membro = lastData.equipe[personIdx];
        var bucket = (membro[rowKey] || {})[bucketKey] || {};
        var cards  = bucket.cards || [];

        document.getElementById('mon-drill-title').textContent =
            membro.nome + ' — ' + ROW_LABELS[rowKey] + ' — ' + BUCKET_LABELS[bucketKey];
        document.getElementById('mon-drill-subtitle').textContent =
            cards.length + ' chamado' + (cards.length !== 1 ? 's' : '');

        var listEl = document.getElementById('mon-drill-list');
        listEl.innerHTML = cards.length
            ? cards.map(function (c) { return drillItemHtml(c, rowKey); }).join('')
            : '<div style="color:rgba(255,255,255,.35);font-size:.82rem;padding:.5rem 0">Nenhum chamado encontrado.</div>';

        document.getElementById('mon-drill-overlay').style.display = 'flex';
    };

    window.monFecharDrill = function () {
        document.getElementById('mon-drill-overlay').style.display = 'none';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') monFecharDrill();
    });

    // ── Painel Tarefas (Bitrix24 Tasks — fonte separada do SPA 1054) ──────────────
    var lastTarefas     = null;
    var tskSelectedUids = null; // Set — inicializado no primeiro carregamento (todos selecionados)

    function fmtDataHora(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }

    function primeiroNome(nome) {
        return (nome || '').split(' ')[0];
    }

    // Papel não repetido no chip — a coluna (Participantes/Observadores, ver .tsk-thead) já
    // diz qual é. Uma pessoa com os dois papéis na mesma tarefa aparece nas duas colunas.
    function tskBadgeHtml(b) {
        return '<span class="tsk-badge ' + (b.intensidade || 'media') + '">'
            + escHtml(primeiroNome(b.nome)) + '</span>';
    }

    // Criador/Responsável aparecem sempre, cada um na sua própria coluna (ver .tsk-thead) —
    // mesmo quando não são um dos 4 da equipe. Só o destaque visual (forte = verde) muda
    // conforme pessoa.ehEquipe (ver MonitoramentoTarefasService).
    function tskPessoaChipHtml(pessoa) {
        if (!pessoa || !pessoa.bitrixUserId) {
            return '<span style="color:rgba(255,255,255,.3);font-size:.72rem">—</span>';
        }
        var classe = pessoa.ehEquipe ? 'forte' : 'externo';
        return '<span class="tsk-badge ' + classe + '" title="' + escHtml(pessoa.nome) + '">'
            + escHtml(primeiroNome(pessoa.nome)) + '</span>';
    }

    function tskChatMsgHtml(c) {
        return '<div class="tsk-chat-msg">'
            + '<div class="tsk-chat-msg-head">'
                + '<span class="tsk-chat-msg-autor">' + escHtml(c.autor) + '</span>'
                + '<span class="tsk-chat-msg-data">' + escHtml(fmtDataHora(c.data)) + '</span>'
            + '</div>'
            + '<div class="tsk-chat-msg-texto">' + escHtml(c.mensagem) + '</div>'
            + '</div>';
    }

    function tskRowHtml(t) {
        var url = (lastTarefas && lastTarefas.bitrixBase && t.responsibleId)
            ? lastTarefas.bitrixBase + '/company/personal/user/' + t.responsibleId + '/tasks/task/view/' + t.id + '/'
            : '';
        var idHtml = url
            ? '<a class="tsk-row-id" href="' + escHtml(url) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()">#' + t.id + '</a>'
            : '<span class="tsk-row-id">#' + t.id + '</span>';

        // Cor de alerta já comunica atraso — sem rótulo de texto redundante.
        var deadlineHtml = t.deadline
            ? '<span class="tsk-deadline' + (t.atrasada ? ' atrasada' : '') + '">' + escHtml(fmtDataHora(t.deadline)) + '</span>'
            : '<span class="tsk-deadline">Sem prazo</span>';

        var chatIcon = t.temChat
            ? '<i class="fas fa-comment-dots tsk-chat-icon" title="Ver mensagens" onclick="event.stopPropagation();tskAbrirChat(' + t.id + ')"></i>'
            : '';
        var participantesHtml = (t.badges || [])
            .filter(function (b) { return (b.papeis || []).indexOf('Participante') !== -1; })
            .map(tskBadgeHtml).join('');
        var observadoresHtml = (t.badges || [])
            .filter(function (b) { return (b.papeis || []).indexOf('Observador') !== -1; })
            .map(tskBadgeHtml).join('');

        var descricaoHtml = t.descricao
            ? '<div class="tsk-detail-label">Descrição</div><div class="tsk-detail-text">' + escHtml(t.descricao) + '</div>'
            : '<div class="tsk-detail-text" style="color:rgba(255,255,255,.35)">Sem descrição.</div>';

        var prazoDetalheHtml = t.deadline ? escHtml(fmtDataHora(t.deadline)) : 'Sem prazo definido';

        return '<div class="tsk-row">'
            + '<div class="tsk-row-main" onclick="tskToggle(' + t.id + ')">'
                + '<button class="tsk-chevron-btn" id="tsk-btn-' + t.id + '"><i class="fas fa-chevron-right" style="font-size:.7rem"></i></button>'
                + idHtml
                + '<span class="tsk-row-title">' + escHtml(t.titulo) + '</span>'
                + '<span class="tsk-pessoa-cell">' + tskPessoaChipHtml(t.criador) + '</span>'
                + '<span class="tsk-pessoa-cell">' + tskPessoaChipHtml(t.responsavel) + '</span>'
                + '<span class="tsk-outros">' + participantesHtml + '</span>'
                + '<span class="tsk-outros">' + observadoresHtml + '</span>'
                + deadlineHtml
                + chatIcon
            + '</div>'
            + '<div class="tsk-row-detail" id="tsk-detail-' + t.id + '">'
                + '<div class="tsk-detail-inner">'
                    + descricaoHtml
                    + '<div class="tsk-detail-label">Prazo</div><div class="tsk-detail-text">' + prazoDetalheHtml + '</div>'
                + '</div>'
            + '</div>'
            + '</div>';
    }

    // ── Modal de chat — componente compartilhado entre Tarefas e Chamados abertos ────
    function abrirChatModal(titulo, comentarios, chatErro) {
        document.getElementById('tsk-chat-title').textContent = titulo;
        var listEl = document.getElementById('tsk-chat-list');

        if (chatErro) {
            listEl.innerHTML = '<div style="color:rgba(255,255,255,.35);font-size:.82rem;padding:.5rem 0">Sem permissão para acessar este chat.</div>';
        } else if (comentarios && comentarios.length) {
            listEl.innerHTML = comentarios.map(tskChatMsgHtml).join('');
        } else {
            listEl.innerHTML = '<div style="color:rgba(255,255,255,.35);font-size:.82rem;padding:.5rem 0">Nenhuma mensagem.</div>';
        }

        document.getElementById('tsk-chat-overlay').style.display = 'flex';
    }

    window.tskAbrirChat = function (id) {
        if (!lastTarefas || !lastTarefas.tarefas) return;
        var tarefa = lastTarefas.tarefas.filter(function (t) { return t.id === id; })[0];
        if (!tarefa) return;
        abrirChatModal(tarefa.titulo, tarefa.comentarios, null); // chat de tarefa não tem chatErro — sempre acessível
    };

    window.chaAbrirChat = function (id) {
        if (!lastChamados || !lastChamados.chamados) return;
        var chamado = lastChamados.chamados.filter(function (c) { return c.id === id; })[0];
        if (!chamado) return;
        abrirChatModal(chamado.titulo, chamado.comentarios, chamado.chatErro);
    };

    window.tskFecharChat = function () {
        document.getElementById('tsk-chat-overlay').style.display = 'none';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') tskFecharChat();
    });

    // ── Config dos webhooks pessoais (Atendimento) — CRUD via modal ───────────────
    var monWebhookEditandoId = null;

    function monWebhookFeedback(msg, classe) {
        var fb = document.getElementById('mon-webhooks-feedback');
        fb.textContent = msg;
        fb.className = classe;
        if (classe === 'ok') setTimeout(function () { fb.textContent = ''; }, 4000);
    }

    function monWebhookLimparForm() {
        monWebhookEditandoId = null;
        document.getElementById('mon-webhook-nome').value = '';
        document.getElementById('mon-webhook-url').value = '';
        document.getElementById('mon-webhook-url').placeholder = 'https://.../rest/.../TOKEN/';
        document.getElementById('mon-webhooks-submit-label').textContent = 'Adicionar';
    }

    function monWebhookRowHtml(p) {
        var nomeEscapado = escHtml(p.nome).replace(/'/g, "\\'");
        return '<div class="mon-webhook-row">'
            + '<span class="mon-webhook-nome" title="' + escHtml(p.nome) + '">' + escHtml(p.nome) + '</span>'
            + '<span class="mon-webhook-url" title="' + escHtml(p.webhookMascarado) + '">' + escHtml(p.webhookMascarado) + '</span>'
            + '<span class="mon-webhook-acoes">'
                + '<button onclick="monEditarWebhookPessoal(\'' + p.id + '\',\'' + nomeEscapado + '\')" title="Editar"><i class="fas fa-pen"></i></button>'
                + '<button onclick="monRemoverWebhookPessoal(\'' + p.id + '\')" title="Remover"><i class="fas fa-trash"></i></button>'
            + '</span>'
            + '</div>';
    }

    function monRenderWebhooksPessoais(pessoas) {
        var el = document.getElementById('mon-webhooks-list');
        el.innerHTML = (pessoas && pessoas.length)
            ? pessoas.map(monWebhookRowHtml).join('')
            : '<div class="mon-empty" style="padding:1.5rem 0"><div>Nenhum webhook pessoal cadastrado ainda — o Atendimento usa só o webhook de automação até você cadastrar o primeiro.</div></div>';
    }

    function monCarregarWebhooksPessoais() {
        return fetch('/api/monitoramento-webhooks-pessoais.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'listar' })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('mon-webhooks-list').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181">' + escHtml(data.erro) + '</div>';
                    return;
                }
                monRenderWebhooksPessoais(data.pessoas || []);
            })
            .catch(function () {
                document.getElementById('mon-webhooks-list').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181">Erro de comunicação.</div>';
            });
    }

    window.monAbrirConfigWebhooks = function () {
        monWebhookLimparForm();
        document.getElementById('mon-webhooks-feedback').textContent = '';
        document.getElementById('mon-webhooks-overlay').style.display = 'flex';
        monCarregarWebhooksPessoais();
    };

    window.monFecharConfigWebhooks = function () {
        document.getElementById('mon-webhooks-overlay').style.display = 'none';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') monFecharConfigWebhooks();
    });

    window.monEditarWebhookPessoal = function (id, nomeAtual) {
        monWebhookEditandoId = id;
        document.getElementById('mon-webhook-nome').value = nomeAtual;
        document.getElementById('mon-webhook-url').value = '';
        document.getElementById('mon-webhook-url').placeholder = 'Deixe em branco pra manter o webhook atual';
        document.getElementById('mon-webhooks-submit-label').textContent = 'Salvar edição';
        document.getElementById('mon-webhooks-feedback').textContent = '';
    };

    window.monRemoverWebhookPessoal = function (id) {
        fetch('/api/monitoramento-webhooks-pessoais.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'remover', id: id })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) { monWebhookFeedback(data.erro, 'erro'); return; }
                monRenderWebhooksPessoais(data.pessoas || []);
                if (monWebhookEditandoId === id) monWebhookLimparForm();
            })
            .catch(function () { monWebhookFeedback('Erro de comunicação.', 'erro'); });
    };

    // Dispara ao sair do campo de URL (colar/tab) — valida o webhook de verdade via
    // user.current (ver WebhooksPessoaisAtendimento::buscarNomeConta()) e pré-preenche o nome
    // com a conta real dona do webhook, em vez de depender do que foi digitado a mão. O campo
    // continua editável depois — isso só sugere o valor correto. Em modo edição mantendo o
    // webhook atual (campo em branco), não há nada pra validar.
    window.monValidarWebhookPessoal = function () {
        var url = document.getElementById('mon-webhook-url').value.trim();
        if (!url) return;
        if (url.indexOf('https://') !== 0) {
            monWebhookFeedback('Webhook deve começar com https://', 'erro');
            return;
        }

        monWebhookFeedback('Verificando webhook…', '');
        fetch('/api/monitoramento-webhooks-pessoais.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ acao: 'validar', webhookUrl: url })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) { monWebhookFeedback(data.erro, 'erro'); return; }
                document.getElementById('mon-webhook-nome').value = data.nome;
                monWebhookFeedback('Nome preenchido automaticamente: ' + data.nome + ' (pode editar antes de salvar)', 'ok');
            })
            .catch(function () { monWebhookFeedback('Erro de comunicação ao validar o webhook.', 'erro'); });
    };

    window.monSalvarWebhookPessoal = function () {
        var nome = document.getElementById('mon-webhook-nome').value.trim();
        var url  = document.getElementById('mon-webhook-url').value.trim();

        if (!nome) { monWebhookFeedback('Nome é obrigatório.', 'erro'); return; }
        if (!monWebhookEditandoId && !url) { monWebhookFeedback('Webhook é obrigatório.', 'erro'); return; }

        var acao    = monWebhookEditandoId ? 'editar' : 'adicionar';
        var payload = { acao: acao, nome: nome, webhookUrl: url };
        if (monWebhookEditandoId) payload.id = monWebhookEditandoId;

        fetch('/api/monitoramento-webhooks-pessoais.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) { monWebhookFeedback(data.erro, 'erro'); return; }
                monRenderWebhooksPessoais(data.pessoas || []);
                monWebhookLimparForm();
                monWebhookFeedback('Salvo com sucesso.', 'ok');
            })
            .catch(function () { monWebhookFeedback('Erro de comunicação.', 'erro'); });
    };

    // ── Filtro por pessoa (pills multi-select, 1 a 4 ativos) ──────────────────────
    function renderFiltroPessoas(equipe) {
        var el = document.getElementById('tsk-filter-row');
        if (!el) return;
        if (!equipe || !equipe.length) { el.innerHTML = ''; return; }

        if (tskSelectedUids === null) {
            tskSelectedUids = new Set(equipe.map(function (p) { return p.bitrixUserId; }));
        }

        el.innerHTML = equipe.map(function (p) {
            var ativo = tskSelectedUids.has(p.bitrixUserId);
            return '<span class="tsk-filter-pill pessoa' + (ativo ? ' active' : '') + '" onclick="tskToggleFiltro(' + p.bitrixUserId + ')">'
                + escHtml(primeiroNome(p.nome)) + '</span>';
        }).join('');
    }

    window.tskToggleFiltro = function (uid) {
        if (!tskSelectedUids) return;
        if (tskSelectedUids.has(uid)) {
            if (tskSelectedUids.size <= 1) return; // nunca deixa ficar com 0 selecionados
            tskSelectedUids.delete(uid);
        } else {
            tskSelectedUids.add(uid);
        }
        if (lastTarefas) {
            renderFiltroPessoas(lastTarefas.equipe);
            renderTarefas(lastTarefas);
        }
    };

    function tskEnvolveSelecionados(t) {
        return (t.badges || []).some(function (b) { return tskSelectedUids.has(b.bitrixUserId); });
    }

    // ── Ordenação por coluna (Criador/Responsável/Participantes/Observadores/Prazo) ──
    var tskSortColuna = null; // 'criador' | 'responsavel' | 'participantes' | 'observadores' | 'prazo' | null
    var tskSortAsc    = true;

    function tskPrimeiroPorPapel(t, papel) {
        var lista = (t.badges || []).filter(function (b) { return (b.papeis || []).indexOf(papel) !== -1; });
        return lista.length ? lista[0].nome : '';
    }

    function tskValorOrdenacao(t, coluna) {
        if (coluna === 'criador')       return (t.criador && t.criador.nome) || '';
        if (coluna === 'responsavel')   return (t.responsavel && t.responsavel.nome) || '';
        if (coluna === 'participantes') return tskPrimeiroPorPapel(t, 'Participante');
        if (coluna === 'observadores')  return tskPrimeiroPorPapel(t, 'Observador');
        return '';
    }

    function tskAtualizarIconesOrdenacao() {
        document.querySelectorAll('#mon-tab-content-tsk .tsk-th-sort').forEach(function (el) {
            var icon = el.querySelector('.mon-sort-icon');
            if (!icon) return;
            if (tskSortColuna === el.getAttribute('data-col')) {
                icon.className = 'fas ' + (tskSortAsc ? 'fa-sort-up' : 'fa-sort-down') + ' mon-sort-icon active';
            } else {
                icon.className = 'fas fa-sort mon-sort-icon';
            }
        });
    }

    window.tskOrdenarPor = function (coluna) {
        if (tskSortColuna === coluna) {
            tskSortAsc = !tskSortAsc;
        } else {
            tskSortColuna = coluna;
            tskSortAsc = true;
        }
        tskAtualizarIconesOrdenacao();
        if (lastTarefas) renderTarefas(lastTarefas);
    };

    // Prazo ordena por timestamp (não string) — 1º clique = mais cedo/mais atrasado primeiro,
    // já que isso é o que mais importa pra decidir o que olhar primeiro.
    function tskTimestampPrazo(t) {
        if (!t.deadline) return null;
        var ts = Date.parse(t.deadline);
        return isNaN(ts) ? null : ts;
    }

    function tskAplicarOrdenacao(tarefas) {
        if (!tskSortColuna) return tarefas; // padrão: mantém a ordem original (atrasada/prazo)

        if (tskSortColuna === 'prazo') {
            return tarefas.slice().sort(function (a, b) {
                var va = tskTimestampPrazo(a);
                var vb = tskTimestampPrazo(b);
                if (va === null && vb === null) return 0;
                if (va === null) return 1;  // sem prazo sempre vai pro fim
                if (vb === null) return -1;
                var cmp = va - vb;
                return tskSortAsc ? cmp : -cmp;
            });
        }

        return tarefas.slice().sort(function (a, b) {
            var va = tskValorOrdenacao(a, tskSortColuna).toLowerCase();
            var vb = tskValorOrdenacao(b, tskSortColuna).toLowerCase();
            if (va === '' && vb === '') return 0;
            if (va === '') return 1;  // sem pessoa nessa coluna sempre vai pro fim
            if (vb === '') return -1;
            var cmp = va < vb ? -1 : (va > vb ? 1 : 0);
            return tskSortAsc ? cmp : -cmp;
        });
    }

    function renderTarefas(data) {
        var listEl  = document.getElementById('tsk-list');
        var countEl = document.getElementById('tsk-count');

        renderFiltroPessoas(data.equipe);

        if (data.aviso) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            countEl.textContent = '';
            return;
        }

        var todasTarefas = data.tarefas || [];
        var tarefas = (tskSelectedUids && tskSelectedUids.size)
            ? todasTarefas.filter(tskEnvolveSelecionados)
            : todasTarefas;
        tarefas = tskAplicarOrdenacao(tarefas);

        countEl.textContent = tarefas.length + ' em aberto';

        if (!tarefas.length) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-check-circle"></i><div>Nenhuma tarefa em aberto.</div></div>';
            return;
        }

        listEl.innerHTML = tarefas.map(tskRowHtml).join('');
    }

    window.tskToggle = function (id) {
        var detail = document.getElementById('tsk-detail-' + id);
        var btn    = document.getElementById('tsk-btn-' + id);
        if (!detail) return;

        var isOpen = detail.classList.contains('open');
        detail.classList.toggle('open', !isOpen);
        if (btn) btn.classList.toggle('open', !isOpen);
    };

    function carregarTarefas() {
        return fetch('/api/monitoramento-tarefas-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('tsk-list').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    return;
                }
                lastTarefas = data;
                renderTarefas(data);
            })
            .catch(function () {
                document.getElementById('tsk-list').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
            });
    }

    // ── Painel Chamados abertos (SPA 1054 / Funil 208 — fila inteira, sem escopo de equipe) ──
    var lastChamados    = null;
    var chaSelectedTipos = null; // Set — inicializado no primeiro carregamento (todos selecionados)

    function iniciais(nome) {
        var partes = (nome || '').trim().split(/\s+/).filter(Boolean);
        if (!partes.length) return '?';
        if (partes.length === 1) return partes[0].substring(0, 2).toUpperCase();
        return (partes[0][0] + partes[partes.length - 1][0]).toUpperCase();
    }

    function chaAvatarHtml(r) {
        return '<span class="cha-avatar" title="' + escHtml(r.nome) + '">' + escHtml(iniciais(r.nome)) + '</span>';
    }

    function chaRowHtml(c) {
        var url = (lastChamados && lastChamados.bitrixBase)
            ? lastChamados.bitrixBase + '/crm/type/1054/details/' + c.id + '/'
            : '';
        var idHtml = url
            ? '<a class="cha-row-id" href="' + escHtml(url) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()">#' + c.id + '</a>'
            : '<span class="cha-row-id">#' + c.id + '</span>';
        var linkIcon = url
            ? '<a href="' + escHtml(url) + '" target="_blank" rel="noopener" class="cha-link-icon" onclick="event.stopPropagation()" title="Abrir no Bitrix24"><i class="fas fa-external-link-alt"></i></a>'
            : '';
        var chatIcon = c.temChat
            ? '<i class="fas fa-comment-dots cha-chat-icon" title="Ver mensagens" onclick="event.stopPropagation();chaAbrirChat(' + c.id + ')"></i>'
            : '';
        var avatares = (c.responsaveis && c.responsaveis.length)
            ? c.responsaveis.map(chaAvatarHtml).join('')
            : '<span class="cha-sem-resp">—</span>';

        var resumoHtml = c.resumo
            ? escHtml(c.resumo)
            : '<span style="color:rgba(255,255,255,.35)">Sem resumo</span>';

        return '<div class="cha-row">'
            + '<div class="cha-row-main" onclick="chaToggle(' + c.id + ')">'
                + '<button class="cha-chevron-btn" id="cha-btn-' + c.id + '"><i class="fas fa-chevron-right" style="font-size:.7rem"></i></button>'
                + '<div class="cha-row-chamado">' + idHtml + '<span class="cha-row-title" title="' + escHtml(c.titulo) + '">' + escHtml(c.titulo) + '</span></div>'
                + '<span class="cha-empresa" title="' + escHtml(c.empresaNome || '') + '">' + escHtml(c.empresaNome || '—') + '</span>'
                + '<span class="cha-badge" title="' + escHtml(c.tipoLabel) + '" style="background:' + c.tipoCor + '22;color:' + c.tipoCor + ';border:1px solid ' + c.tipoCor + '55">' + escHtml(c.tipoLabel) + '</span>'
                + (c.prioridadeLabel
                    ? '<span class="cha-badge" title="' + escHtml(c.prioridadeLabel) + '" style="background:' + c.prioridadeCor + '22;color:' + c.prioridadeCor + ';border:1px solid ' + c.prioridadeCor + '55">' + escHtml(c.prioridadeLabel) + '</span>'
                    : '<span class="cha-etapa">—</span>')
                + '<span class="cha-etapa" title="' + escHtml(c.etapaLabel) + '">' + escHtml(c.etapaLabel) + '</span>'
                + '<span class="cha-solicitante" title="' + escHtml(c.solicitante || '') + '">' + escHtml(c.solicitante || '—') + '</span>'
                + '<span class="cha-avatares">' + avatares + '</span>'
                + '<span class="cha-acoes">' + chatIcon + linkIcon + '</span>'
            + '</div>'
            + '<div class="cha-row-detail" id="cha-detail-' + c.id + '">'
                + '<div class="cha-detail-inner">'
                    + '<div class="cha-detail-label">Resumo</div><div class="cha-detail-text">' + resumoHtml + '</div>'
                + '</div>'
            + '</div>'
            + '</div>';
    }

    window.chaToggle = function (id) {
        var detail = document.getElementById('cha-detail-' + id);
        var btn    = document.getElementById('cha-btn-' + id);
        if (!detail) return;

        var isOpen = detail.classList.contains('open');
        detail.classList.toggle('open', !isOpen);
        if (btn) btn.classList.toggle('open', !isOpen);
    };

    // ── Dropdowns de filtro (Tipo / Responsável) — trigger "Rótulo · N" + painel com
    // checkboxes, mesmo Set multi-select de sempre por baixo (só a apresentação virou dropdown
    // em vez de pills, pra não ocupar a linha inteira de largura). Só um aberto por vez;
    // fecha ao clicar fora. ──────────────────────────────────────────────────────────
    window.chaToggleDropdown = function (nome) {
        ['tipo', 'pessoa'].forEach(function (n) {
            var el = document.getElementById('cha-dropdown-' + n);
            if (!el) return;
            el.classList.toggle('open', n === nome ? !el.classList.contains('open') : false);
        });
    };

    document.addEventListener('click', function (e) {
        if (e.target.closest('.cha-dropdown')) return;
        document.querySelectorAll('.cha-dropdown.open').forEach(function (el) { el.classList.remove('open'); });
    });

    // ── Filtro por Tipo — cada tipo nomeado vem do backend (TipoChamadoCatalogo::paraPills(),
    // fonte única de verdade compartilhada com a classificação Suporte/Desenvolvimento do
    // painel Equipe) — nada de mapa duplicado aqui. "Outros" é um bucket catch-all (chave
    // especial, não um tipo real) que cobre qualquer tipo fora da lista vinda do backend,
    // incluindo tipos novos que apareçam no futuro sem precisar mexer neste código.
    var chaCatalogoTipos = null; // [{tipo, label, ativoPadrao}] — vem de data.catalogoTipos

    function chaChaveTipo(c) {
        var key = String(c.tipo);
        var conhecido = (chaCatalogoTipos || []).some(function (p) { return String(p.tipo) === key; });
        return conhecido ? key : 'outros';
    }

    function chaRenderFiltroTipos(catalogo) {
        var painel  = document.getElementById('cha-dropdown-tipo-panel');
        var badgeEl = document.getElementById('cha-dropdown-tipo-count');
        if (!painel) return;

        chaCatalogoTipos = catalogo || [];

        // Default idêntico ao das pills de antes — mesmos tipos pré-selecionados, mesmos
        // excluídos por padrão. Só calculado uma vez (primeiro carregamento).
        if (chaSelectedTipos === null) {
            chaSelectedTipos = new Set(
                chaCatalogoTipos.filter(function (p) { return p.ativoPadrao; })
                                .map(function (p) { return String(p.tipo); })
            );
        }

        var itens = chaCatalogoTipos.map(function (p) { return { key: String(p.tipo), label: p.label, cor: p.cor }; });
        itens.push({ key: 'outros', label: 'Outros', cor: '#a0aec0' });

        painel.innerHTML = itens.map(function (p) {
            var ativo = chaSelectedTipos.has(p.key);
            return '<label class="cha-dropdown-item">'
                + '<input type="checkbox"' + (ativo ? ' checked' : '') + ' onchange="chaToggleTipoFiltro(\'' + p.key + '\')">'
                + '<span class="dot" style="background:' + (p.cor || '#a0aec0') + '"></span>'
                + escHtml(p.label)
                + '</label>';
        }).join('');

        if (badgeEl) badgeEl.textContent = chaSelectedTipos.size;
    }

    window.chaToggleTipoFiltro = function (tipo) {
        if (!chaSelectedTipos) return;
        if (chaSelectedTipos.has(tipo)) {
            if (chaSelectedTipos.size <= 1) { chaRenderFiltroTipos(chaCatalogoTipos); return; } // nunca deixa ficar com 0 selecionados
            chaSelectedTipos.delete(tipo);
        } else {
            chaSelectedTipos.add(tipo);
        }
        chaRenderFiltroTipos(chaCatalogoTipos);
        if (lastChamados) renderChamados(lastChamados);
    };

    // ── Filtro por Responsável — roster dinâmico (qualquer colaborador que apareça em
    // F_RESP dos chamados visíveis, não uma lista fixa) — default: todos marcados. Pessoas
    // novas que apareçam num recarregamento entram já marcadas (mesma semântica do default:
    // "mostrar todo mundo" se aplica também a quem aparece depois do primeiro carregamento).
    var chaSelectedPessoas   = null;          // Set de bitrixUserId (string) — null = ainda não inicializado
    var chaPessoasConhecidas = new Set();     // uids já vistos em qualquer carregamento anterior

    function chaRenderFiltroPessoas(chamados) {
        var painel  = document.getElementById('cha-dropdown-pessoa-panel');
        var badgeEl = document.getElementById('cha-dropdown-pessoa-count');
        if (!painel) return;

        var porUid = {};
        (chamados || []).forEach(function (c) {
            (c.responsaveis || []).forEach(function (r) { porUid[String(r.bitrixUserId)] = r.nome; });
        });

        if (chaSelectedPessoas === null) chaSelectedPessoas = new Set();

        // Uid genuinamente novo (nunca visto antes) entra marcado por padrão — "mostrar todo
        // mundo" também vale pra quem aparece só depois do primeiro carregamento; uid que o
        // usuário desmarcou manualmente não volta a ser marcado nos recarregamentos seguintes.
        Object.keys(porUid).forEach(function (uid) {
            if (!chaPessoasConhecidas.has(uid)) {
                chaPessoasConhecidas.add(uid);
                chaSelectedPessoas.add(uid);
            }
        });

        var pessoas = Object.keys(porUid)
            .map(function (uid) { return { uid: uid, nome: porUid[uid] }; })
            .sort(function (a, b) { return a.nome.localeCompare(b.nome); });

        painel.innerHTML = pessoas.length
            ? pessoas.map(function (p) {
                var ativo = chaSelectedPessoas.has(p.uid);
                return '<label class="cha-dropdown-item">'
                    + '<input type="checkbox"' + (ativo ? ' checked' : '') + ' onchange="chaTogglePessoaFiltro(\'' + p.uid + '\')">'
                    + escHtml(p.nome)
                    + '</label>';
            }).join('')
            : '<div class="cha-dropdown-empty">Nenhum responsável nos chamados visíveis.</div>';

        if (badgeEl) badgeEl.textContent = chaSelectedPessoas.size;
    }

    window.chaTogglePessoaFiltro = function (uid) {
        if (!chaSelectedPessoas) return;
        if (chaSelectedPessoas.has(uid)) {
            if (chaSelectedPessoas.size <= 1) { chaRenderFiltroPessoas(lastChamados && lastChamados.chamados); return; }
            chaSelectedPessoas.delete(uid);
        } else {
            chaSelectedPessoas.add(uid);
        }
        chaRenderFiltroPessoas(lastChamados && lastChamados.chamados);
        if (lastChamados) renderChamados(lastChamados);
    };

    function chaPassaFiltroPessoa(c) {
        if (!chaSelectedPessoas || !chaSelectedPessoas.size) return true;
        var resp = c.responsaveis || [];
        if (!resp.length) return true; // sem responsável nenhum — não é escondido por este filtro
        return resp.some(function (r) { return chaSelectedPessoas.has(String(r.bitrixUserId)); });
    }

    // ── Ordenação por coluna (Tipo/Prioridade/Etapa) ──────────────────────────────
    var chaSortColuna = null; // 'tipo' | 'prioridade' | 'etapa' | null
    var chaSortAsc    = true;

    // Ordena por urgência real (Urgente primeiro), não alfabética — "Alta" não pode vir antes
    // de "Urgente" só porque a letra A é menor que U.
    var CHA_PRIORIDADE_RANK = { 'Urgente': 1, 'Alta': 2, 'Média': 3, 'Baixa': 4 };

    function chaValorOrdenacao(c, coluna) {
        if (coluna === 'tipo')       return c.tipoLabel  || '';
        if (coluna === 'etapa')      return c.etapaLabel || '';
        if (coluna === 'prioridade') return String(CHA_PRIORIDADE_RANK[c.prioridadeLabel] || 9);
        return '';
    }

    function chaAtualizarIconesOrdenacao() {
        document.querySelectorAll('#mon-tab-content-cha .cha-th-sort').forEach(function (el) {
            var icon = el.querySelector('.mon-sort-icon');
            if (!icon) return;
            if (chaSortColuna === el.getAttribute('data-col')) {
                icon.className = 'fas ' + (chaSortAsc ? 'fa-sort-up' : 'fa-sort-down') + ' mon-sort-icon active';
            } else {
                icon.className = 'fas fa-sort mon-sort-icon';
            }
        });
    }

    window.chaOrdenarPor = function (coluna) {
        if (chaSortColuna === coluna) {
            chaSortAsc = !chaSortAsc;
        } else {
            chaSortColuna = coluna;
            chaSortAsc = true;
        }
        chaAtualizarIconesOrdenacao();
        if (lastChamados) renderChamados(lastChamados);
    };

    function chaAplicarOrdenacao(chamados) {
        if (!chaSortColuna) return chamados; // padrão: mantém a ordem original (mais antigo primeiro)

        return chamados.slice().sort(function (a, b) {
            var va = chaValorOrdenacao(a, chaSortColuna).toLowerCase();
            var vb = chaValorOrdenacao(b, chaSortColuna).toLowerCase();
            var cmp = va < vb ? -1 : (va > vb ? 1 : 0);
            return chaSortAsc ? cmp : -cmp;
        });
    }

    function renderChamados(data) {
        var listEl  = document.getElementById('cha-list');
        var countEl = document.getElementById('cha-count');

        if (data.aviso) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            countEl.textContent = '';
            return;
        }

        var todos = data.chamados || [];
        chaRenderFiltroTipos(data.catalogoTipos);
        chaRenderFiltroPessoas(todos);

        var visiveis = todos.filter(function (c) {
            return chaSelectedTipos.has(chaChaveTipo(c)) && chaPassaFiltroPessoa(c);
        });
        visiveis = chaAplicarOrdenacao(visiveis);

        countEl.textContent = visiveis.length + ' em aberto';

        if (!visiveis.length) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-check-circle"></i><div>Nenhum chamado em aberto.</div></div>';
            return;
        }

        listEl.innerHTML = visiveis.map(chaRowHtml).join('');
    }

    function carregarChamados() {
        return fetch('/api/monitoramento-chamados-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('cha-list').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    return;
                }
                lastChamados = data;
                renderChamados(data);
            })
            .catch(function () {
                document.getElementById('cha-list').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
            });
    }

    // ── Painel Funil (SPA 1054 / Funil 208 — volume de criados/finalizados; sem Tarefas) ──
    function funStatHtml(valor, label) {
        return '<div class="fun-stat"><span class="fun-stat-value">' + (valor != null ? valor : '—') + '</span><span class="fun-stat-label">' + escHtml(label) + '</span></div>';
    }

    function fmtDiaMes(iso) {
        var p = (iso || '').split('-');
        return p.length === 3 ? (p[2] + '/' + p[1]) : '';
    }

    var FUN_DIST_CORES = {
        'Fila - Desenvolvimento': '#b794f4',
        'Fila - Suporte':         '#0DC2FF',
        'Pendente Cliente':       '#f6ad55',
        'Treinamento/Validação':  '#a0aec0',
        'Demandas - KW24':        '#26FF93',
        // As 4 barras por pessoa (antes um único bucket "Atribuído a um colaborador")
        // compartilham a mesma cor — se agrupam visualmente como "atribuído a alguém".
        'Gabriel Acker':   '#ecc94b',
        'Jeferson Santos': '#ecc94b',
        'Tainá Oliveira':  '#ecc94b',
        'Michael Botelho': '#ecc94b',
        'Outros':                 '#718096',
    };

    function funDistRowHtml(bucket, maxTotal) {
        var cor    = FUN_DIST_CORES[bucket.label] || '#a0aec0';
        var largura = maxTotal > 0 ? Math.round((bucket.total / maxTotal) * 100) : 0;
        return '<div class="fun-dist-row">'
            + '<span class="fun-dist-label" title="' + escHtml(bucket.label) + '">' + escHtml(bucket.label) + '</span>'
            + '<span class="fun-dist-track"><span class="fun-dist-fill" style="width:' + largura + '%;background:' + cor + '"></span></span>'
            + '<span class="fun-dist-value">' + bucket.total + '</span>'
            + '</div>';
    }

    function renderFunilDistribuicao(buckets) {
        var el = document.getElementById('fun-dist-rows');
        if (!el) return;

        var visiveis = (buckets || []).filter(function (b) { return b.label !== 'Outros' || b.total > 0; });
        var maxTotal = visiveis.reduce(function (m, b) { return Math.max(m, b.total); }, 0);

        el.innerHTML = visiveis.map(function (b) { return funDistRowHtml(b, maxTotal); }).join('');
    }

    function renderFunil(data) {
        var cicloEl          = document.getElementById('fun-ciclo');
        var criadosBody     = document.getElementById('fun-criados-body');
        var finalizadosBody = document.getElementById('fun-finalizados-body');
        if (!criadosBody || !finalizadosBody) return;

        if (data.aviso) {
            if (cicloEl) cicloEl.textContent = '';
            var avisoHtml = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            criadosBody.innerHTML     = avisoHtml;
            finalizadosBody.innerHTML = avisoHtml;
            renderFunilDistribuicao([]);
            return;
        }

        if (cicloEl && data.periodo) {
            cicloEl.textContent = 'ciclo ' + fmtDiaMes(data.periodo.inicio) + '-' + fmtDiaMes(data.periodo.fim);
        }

        var criados     = data.chamadosCriados     || {};
        var finalizados = data.chamadosFinalizados || {};

        criadosBody.innerHTML =
            funStatHtml(criados.semana, 'Nesta semana') + funStatHtml(criados.periodo, 'No período');
        finalizadosBody.innerHTML =
            funStatHtml(finalizados.semana, 'Nesta semana') + funStatHtml(finalizados.periodo, 'No período');

        renderFunilDistribuicao(data.distribuicaoAbertos || []);
    }

    function carregarFunil() {
        return fetch('/api/monitoramento-funil-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    var erroHtml = '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    document.getElementById('fun-criados-body').innerHTML     = erroHtml;
                    document.getElementById('fun-finalizados-body').innerHTML = erroHtml;
                    return;
                }
                renderFunil(data);
            })
            .catch(function () {
                var erroHtml = '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
                document.getElementById('fun-criados-body').innerHTML     = erroHtml;
                document.getElementById('fun-finalizados-body').innerHTML = erroHtml;
            });
    }

    // ── Painel Atendimento (Contact Center / Open Lines — fila "Geral KW24 - Suporte") ──
    function fmtMinutos(min) {
        if (min == null) return '';
        if (min < 60) return min + 'min';
        var h = Math.floor(min / 60);
        var m = min % 60;
        return h + 'h' + (m ? ' ' + m + 'min' : '');
    }

    function ateRowHtml(c) {
        var dotClasse   = c.aguardando ? 'aguardando' : 'respondido';
        var tempoClasse = c.aguardando ? ' aguardando' : '';
        var msg         = c.ultimaMensagemTexto
            ? escHtml(c.ultimaMensagemTexto)
            : '<span style="color:rgba(255,255,255,.35)">Sem mensagens ainda</span>';
        var tempoTexto  = c.minutosDesdeUltimaAtividade != null
            ? (c.aguardando ? 'aguardando há ' : 'há ') + fmtMinutos(c.minutosDesdeUltimaAtividade)
            : '';
        var responsavelHtml = c.reclamadaPor
            ? '<span class="ate-row-responsavel">' + escHtml(c.reclamadaPor) + '</span>'
            : '';

        var body = '<span class="ate-dot ' + dotClasse + '"></span>'
            + '<span class="ate-row-main">'
                + '<span class="ate-row-titulo">' + escHtml(c.titulo) + '</span>'
                + '<span class="ate-row-msg">' + msg + '</span>'
            + '</span>'
            + responsavelHtml
            + '<span class="ate-row-tempo' + tempoClasse + '">' + escHtml(tempoTexto) + '</span>';

        return c.urlBitrix24
            ? '<a class="ate-row" href="' + escHtml(c.urlBitrix24) + '" target="_blank" rel="noopener">' + body + '</a>'
            : '<div class="ate-row">' + body + '</div>';
    }

    // Abas "Conversas" / "Grupos" — mesmo padrão de monTrocarAba(), independente dele.
    var ateAbaAtiva = 'conv';

    function ateAtualizarAbas() {
        var tabConv  = document.getElementById('mon-tab-ate-conv');
        var tabGrupo = document.getElementById('mon-tab-ate-grupo');
        var contConv  = document.getElementById('ate-tab-content-conv');
        var contGrupo = document.getElementById('ate-tab-content-grupo');
        if (!tabConv || !tabGrupo || !contConv || !contGrupo) return;

        tabConv.classList.toggle('active', ateAbaAtiva === 'conv');
        tabGrupo.classList.toggle('active', ateAbaAtiva === 'grupo');
        contConv.style.display  = ateAbaAtiva === 'conv'  ? 'flex' : 'none';
        contGrupo.style.display = ateAbaAtiva === 'grupo' ? 'flex' : 'none';
    }

    window.ateTrocarAba = function (aba) {
        if (ateAbaAtiva === aba) return;
        ateAbaAtiva = aba;
        ateAtualizarAbas();
    };

    ateAtualizarAbas();

    function renderAtendimento(data) {
        var kpisEl      = document.getElementById('ate-kpis');
        var listEl      = document.getElementById('ate-list');
        var grupoListEl = document.getElementById('ate-grupo-list');
        var grupoCntEl  = document.getElementById('ate-grupo-count');
        if (!kpisEl || !listEl) return;

        if (data.aviso) {
            kpisEl.innerHTML = '';
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-plug"></i><div>' + escHtml(data.aviso) + '</div></div>';
            if (grupoListEl) grupoListEl.innerHTML = '';
            if (grupoCntEl) grupoCntEl.textContent = '0';
            return;
        }

        var grupos = data.grupos || [];
        if (grupoCntEl) grupoCntEl.textContent = grupos.length;
        if (grupoListEl) {
            grupoListEl.innerHTML = grupos.length
                ? grupos.map(ateRowHtml).join('')
                : '<div class="mon-empty"><i class="fas fa-check-circle"></i><div>Nenhum grupo de WhatsApp ativo no momento.</div></div>';
        }

        var ativas   = data.conversasAtivas || { total: 0, aguardando: 0 };
        var tempoTxt = data.tempoMedioRespostaMinutos != null
            ? fmtMinutos(data.tempoMedioRespostaMinutos)
            : 'sem dados suficientes';

        kpisEl.innerHTML =
            '<div class="ate-kpi"><span class="ate-kpi-value">' + ativas.total + '</span><span class="ate-kpi-label">Conversas ativas</span></div>'
            + '<div class="ate-kpi"><span class="ate-kpi-value' + (ativas.aguardando ? ' alerta' : '') + '">' + ativas.aguardando + '</span><span class="ate-kpi-label">Aguardando resposta</span></div>'
            + '<div class="ate-kpi"><span class="ate-kpi-value">' + escHtml(tempoTxt) + '</span><span class="ate-kpi-label">Tempo médio de resposta</span></div>';

        var conversas = data.conversas || [];
        if (!conversas.length) {
            listEl.innerHTML = '<div class="mon-empty"><i class="fas fa-check-circle"></i><div>Nenhuma conversa ativa no momento.</div></div>';
            return;
        }

        // Agrupamento "Aguardando atendimento" × "Sendo atendida" só é confiável com 2+
        // webhooks pessoais cadastrados (ver agrupamentoPorResponsavel/statusFila no
        // service) — sem isso, mantém a lista plana de sempre.
        if (!data.agrupamentoPorResponsavel) {
            listEl.innerHTML = conversas.map(ateRowHtml).join('');
            return;
        }

        var semDono = conversas.filter(function (c) { return c.statusFila === 'aguardando_atendimento'; });
        var comDono = conversas.filter(function (c) { return c.statusFila === 'em_atendimento'; });

        var html = '';
        if (semDono.length) {
            html += '<div class="ate-group-header alerta">Aguardando atendimento (' + semDono.length + ')</div>'
                + semDono.map(ateRowHtml).join('');
        }
        if (comDono.length) {
            html += '<div class="ate-group-header">Sendo atendida (' + comDono.length + ')</div>'
                + comDono.map(ateRowHtml).join('');
        }
        listEl.innerHTML = html;
    }

    function carregarAtendimento() {
        return fetch('/api/monitoramento-atendimento-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('ate-kpis').innerHTML = '';
                    document.getElementById('ate-list').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    document.getElementById('ate-grupo-list').innerHTML = '';
                    return;
                }
                renderAtendimento(data);
            })
            .catch(function () {
                document.getElementById('ate-kpis').innerHTML = '';
                document.getElementById('ate-list').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
                document.getElementById('ate-grupo-list').innerHTML = '';
            });
    }

    // ── Carregamento geral (Equipe + Chamados abertos + Tarefas + Funil + Atendimento) ──
    function carregar() {
        var icon = document.getElementById('mon-refresh-icon');
        if (icon) icon.classList.add('fa-spin');

        var pEquipe = fetch('/api/monitoramento-cards.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    document.getElementById('mon-equipe-grid').innerHTML =
                        '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>'
                        + escHtml(data.erro) + '</div></div>';
                    return;
                }
                lastData = data;
                render(data);
            })
            .catch(function () {
                document.getElementById('mon-equipe-grid').innerHTML =
                    '<div class="mon-empty" style="color:#fc8181"><i class="fas fa-exclamation-circle"></i><div>Erro de comunicação.</div></div>';
            });

        Promise.all([pEquipe, carregarChamados(), carregarTarefas(), carregarFunil(), carregarAtendimento()]).then(function () {
            if (icon) icon.classList.remove('fa-spin');
            var upd = document.getElementById('mon-updated');
            if (upd) upd.textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR');
        });
    }

    window.monAtualizar = carregar;

    carregar();
    setInterval(carregar, AUTO_REFRESH_MS);

})();
</script>
