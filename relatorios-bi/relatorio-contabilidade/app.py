"""
ContaFarma — Relatório Contabilidade (Dash)

Duas abas de estrutura IDÊNTICA — só muda o conjunto de etapas:
  • Vendas Fechadas  → etapa IN (Boas Vindas, Constituição Empresa,
                        Delegação de Tarefas, Conferência, Concluídos)
  • Em Negociação    → etapa IN (Solicitação, Orçamento, Gerar Proposta,
                        Gerar Contrato, Click Sign)

Layout (por aba):
  Bloco 1 — 4 cards de KPI, largura cheia (Total · Internas · Indicadas · Ticket)
  Donut   — sunburst de dois anéis (vendedor → Interno/Indicado) + legenda
  Bloco 2 — duas tabelas lado a lado:
              esquerda  (~65%) Negócios por Vendedor (expansível)
              direita   (~35%) Negócios por Tipo de Contrato
  Bloco 3 — Detalhamento (tabela full-width, 1 linha por negócio, ID clicável)

Cross-filter (dcc.Store id="cf-store" = {vendedor, tipo_venda}):
  clique no donut (anel interno → vendedor; anéis externos → vendedor+tipo),
  na legenda ou na linha de vendedor filtra as 3 tabelas; reclicar limpa (toggle).

Terminologia: "Interno/Internas" = venda própria; "Indicado/Indicadas" = indicação.
(Internamente os aliases SQL/keys seguem `propria_*` — interno == propria.)

Rodar local:   python run_local.py   (http://localhost:8051 — sem o prefixo de produção)
Produção:      gunicorn app:server -b 127.0.0.1:8051
               (servido pelo nginx sob /relatorios-bi/relatorio-contabilidade/)
"""

import os
import base64
import calendar
from datetime import date
from urllib.parse import unquote

import flask
import plotly.graph_objects as go
from dash import Dash, dcc, html, dash_table, Input, Output, State, callback, no_update, ALL, ctx

import queries


# ── Índice ASCII-safe para ids pattern-matching ──────────────────────────────
# O Dash quebra o matching de component-id quando o valor tem caracteres
# não-ASCII (acentos): o clique não dispara o callback. Por isso o "index" do
# elemento é o valor codificado em base64 (ASCII), e o callback decodifica.
def _enc(v):
    return base64.urlsafe_b64encode(str(v).encode("utf-8")).decode("ascii")


def _dec(v):
    return base64.urlsafe_b64decode(str(v).encode("ascii")).decode("utf-8")


# ── Helpers de formatação ────────────────────────────────────────────────────
def fmt_brl(v):
    try:
        v = float(v or 0)
    except (TypeError, ValueError):
        v = 0.0
    return "R$ " + f"{v:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


def fmt_num(v):
    try:
        v = int(float(v or 0))
    except (TypeError, ValueError):
        v = 0
    return f"{v:,}".replace(",", ".")


def _f(v):
    """Decimal/None → float (Decimal não é serializável no dcc.Store)."""
    try:
        return float(v or 0)
    except (TypeError, ValueError):
        return 0.0


def _i(v):
    try:
        return int(float(v or 0))
    except (TypeError, ValueError):
        return 0


# ── Abas + terminologia ───────────────────────────────────────────────────────
ABAS = [("fechadas", "Vendas Fechadas"), ("negociacao", "Em Negociação")]
ABA_DEFAULT = "fechadas"
TIPO_VENDA_LABEL = {"interno": "Ativo", "indicado": "Indicado"}

# Cross-filter vazio (3 dimensões). vendedor/tipo_venda e tipo_contrato são
# mutuamente exclusivos (ativar um zera o outro) — ver _cf_toggle / _cf_toggle_tipo.
CF_EMPTY = {"vendedor": None, "tipo_venda": None, "tipo_contrato": None}


def mes_atual_range():
    """(primeiro dia, último dia) do mês corrente em ISO 'YYYY-MM-DD' — calculado
    dinamicamente (calendar.monthrange p/ o último dia)."""
    hoje = date.today()
    ultimo = calendar.monthrange(hoje.year, hoje.month)[1]
    return (date(hoje.year, hoje.month, 1).isoformat(),
            date(hoje.year, hoje.month, ultimo).isoformat())


MES_INI, MES_FIM = mes_atual_range()

# ── Cores do donut ─────────────────────────────────────────────────────────────
COR_INTERNO = "#00BBBC"   # anel externo — porção Interna (teal ContaFarma)
COR_INDICADO = "#f6ad55"  # anel externo — porção Indicada (âmbar)
# Paleta DIVERSA para o anel interno (um por vendedor). Cores claramente
# distintas entre si E do teal/âmbar do anel externo (índigo, rosa, roxo, vermelho…).
VEND_COLORS = [
    "#6366F1", "#EC4899", "#A855F7", "#EF4444", "#3B82F6",
    "#D946EF", "#F43F5E", "#8B5CF6", "#0EA5E9", "#FB7185",
]

# Bandas radiais do donut por vendedor (radialaxis range 0..1).
# Anel externo com a MESMA espessura do interno (0.20) + pequeno gap entre eles →
# diâmetro externo menor, sobrando espaço ao redor p/ os rótulos das linhas-guia.
_R_INNER_BASE, _R_INNER_TOP = 0.40, 0.60   # interno: largura 0.20
_R_OUTER_BASE, _R_OUTER_TOP = 0.62, 0.82   # externo: largura 0.20 em DADOS — compensa
                                           # o raio maior p/ parecer a MESMA espessura
                                           # em PIXELS do anel interno
_R_NAME = (_R_INNER_BASE + _R_INNER_TOP) / 2   # raio do nome do vendedor
_R_PCT = (_R_OUTER_BASE + _R_OUTER_TOP) / 2    # raio do rótulo de %
_GAP_DEG = 0.0          # gap branco angular entre vendedores (graus)
_PCT_MIN_DEG = 13.0     # esconde o rótulo de % se o sub-arco for menor que isto


def vend_color(i):
    return VEND_COLORS[i % len(VEND_COLORS)]


def _hex_to_rgba(hex_color, alpha):
    h = hex_color.lstrip("#")
    r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
    return f"rgba({r},{g},{b},{alpha})"


def empty_fig(msg="Sem dados"):
    fig = go.Figure()
    fig.add_annotation(text=msg, showarrow=False, font=dict(size=14, color="#a0aec0"))
    fig.update_layout(margin=dict(l=0, r=0, t=0, b=0),
                      xaxis=dict(visible=False), yaxis=dict(visible=False),
                      paper_bgcolor="rgba(0,0,0,0)", plot_bgcolor="rgba(0,0,0,0)")
    return fig


# ── Componentes de layout ────────────────────────────────────────────────────
def card(title, children, icon="fa-table", extra_class=""):
    return html.Div(className=f"rt-card {extra_class}", children=[
        html.Div(className="rt-card-head", children=[
            html.I(className=f"fas {icon}"),
            html.Span(title),
        ]),
        html.Div(className="rt-card-body", children=children),
    ])


def kpi_card(label, icon, value_id, sub_id, accent_class):
    # base = total|propria|indicada|ticket → deriva os ids do breakdown por card
    base = accent_class.replace("kpi-accent-", "")
    return html.Div(className=f"rt-kpi {accent_class}", children=[
        # linha de cima: ícone + valor total (conteúdo original, inalterado)
        html.Div(className="rt-kpi-top", children=[
            html.Div(className="rt-kpi-icon", children=html.I(className=f"fas {icon}")),
            html.Div(className="rt-kpi-body", children=[
                html.Div(label, className="rt-kpi-label"),
                html.Div("—", id=value_id, className="rt-kpi-value"),
                html.Div("—", id=sub_id, className="rt-kpi-sub"),
            ]),
        ]),
        # ── Breakdown por contabilidade (largura cheia: ContaFarma | Capiton) ──
        html.Div(className="rt-kpi-split", children=[
            html.Div(className="rt-kpi-half", children=[
                html.Div("ContaFarma", className="rt-kpi-half-label"),
                html.Div("—", id=f"kpi-{base}-cf-val", className="rt-kpi-half-val"),
                html.Div("—", id=f"kpi-{base}-cf-qtd", className="rt-kpi-half-qtd"),
            ]),
            html.Div(className="rt-kpi-half rt-kpi-half-right", children=[
                html.Div("Capiton", className="rt-kpi-half-label"),
                html.Div("—", id=f"kpi-{base}-cap-val", className="rt-kpi-half-val"),
                html.Div("—", id=f"kpi-{base}-cap-qtd", className="rt-kpi-half-qtd"),
            ]),
        ]),
    ])


def kpi_row():
    return html.Div(className="rt-kpi-row", children=[
        kpi_card("Vendas Total",     "fa-layer-group", "kpi-total-valor",    "kpi-total-qtd",    "kpi-accent-total"),
        kpi_card("Vendas Ativas",  "fa-house",       "kpi-propria-valor",  "kpi-propria-qtd",  "kpi-accent-propria"),
        kpi_card("Vendas Indicadas", "fa-handshake",   "kpi-indicada-valor", "kpi-indicada-qtd", "kpi-accent-indicada"),
        kpi_card("Ticket Médio", "fa-receipt",     "kpi-ticket",         "kpi-ticket-sub",   "kpi-accent-ticket"),
    ])


def data_filter_bar():
    """Barra de filtro de período (botão abre painel com De/Até + Limpar)."""
    return html.Div(className="rt-header-right", children=[
        html.Div(className="rt-datawrap", children=[
            html.Button([html.I(className="fas fa-calendar-days"), " Filtro Data"],
                        id="ct-data-btn", className="rt-refresh"),
            html.Div(id="ct-data-panel", className="rt-data-panel", children=[
                html.Div(className="rt-data-fields", children=[
                    html.Div(className="rt-data-field", children=[
                        html.Label("De (Início)", className="rt-data-flabel"),
                        dcc.DatePickerSingle(id="ct-data-de", display_format="DD/MM/YYYY",
                                             placeholder="dd/mm/aaaa", clearable=True,
                                             date=MES_INI),   # pré-carrega o mês corrente
                    ]),
                    html.Div(className="rt-data-field", children=[
                        html.Label("Até (Fim)", className="rt-data-flabel"),
                        dcc.DatePickerSingle(id="ct-data-ate", display_format="DD/MM/YYYY",
                                             placeholder="dd/mm/aaaa", clearable=True,
                                             date=MES_FIM),   # pré-carrega o mês corrente
                    ]),
                ]),
                html.Div(id="ct-data-limpar-wrap", style={"display": "none"}, children=[
                    html.Button("Limpar", id="ct-data-limpar", className="rt-data-limpar"),
                ]),
            ]),
        ]),
        html.Button([html.I(className="fas fa-rotate"), " Atualizar"],
                    id="btn-refresh", className="rt-refresh"),
    ])


# ── Donut por vendedor (Barpolar: 2 anéis + textos polares) ──────────────────
def _vend_donut_rows(vendedores):
    """Vendedores com pelo menos 1 negócio, ordenados por nº de negócios desc.
    A MESMA ordem é usada nos dois anéis e na legenda — o item N da legenda
    corresponde à barra interna N e às externas 2N/2N+1 (mapeamento usado pelo
    JS de hover e pelos índices de clique)."""
    vs = [v for v in vendedores if _i(v["total_qtd"]) > 0]
    return sorted(vs, key=lambda r: _i(r["total_qtd"]), reverse=True)


def build_donut(vendedores, cf):
    """Donut por vendedor em coordenadas POLARES (go.Barpolar) — escolhido p/ dar
    controle total dos ângulos/raios e um pull radial EXATO no hover (a barra é
    movida pra fora ao longo do seu próprio theta, sem o efeito "torto" dos pies
    aninhados de domínios diferentes).

      trace 0 = anel INTERNO  — N barras IGUAIS (cada uma 360/N graus), cor distinta
                                por vendedor.
      trace 1 = anel EXTERNO  — 2N barras (Interno/Indicado por vendedor). Cada
                                vendedor ocupa o MESMO arco do seu interno (alinhado),
                                dividido proporcionalmente ao split interno/indicado.
      trace 2 = textos de % no anel externo (Scatterpolar)
    Os NOMES dos vendedores ficam FORA do donut (linhas-guia desenhadas em SVG pelo
    donut_hover.js); os dados vêm por fig.layout.meta. `cf` destaca o vendedor
    selecionado (esmaece os demais)."""
    vs = _vend_donut_rows(vendedores)
    if not vs:
        return empty_fig("Sem dados para o período")
    n = len(vs)
    arc = 360.0 / n
    names  = [v["responsavel"] for v in vs]
    totals = [_i(v["total_qtd"]) for v in vs]
    intern = [_i(v["propria_qtd"]) for v in vs]
    indic  = [_i(v["indicada_qtd"]) for v in vs]
    grand  = sum(totals) or 1

    sel = (cf or {}).get("vendedor")
    sel_tipo = (cf or {}).get("tipo_venda")

    def keep(name, tipo=None):
        if not sel:
            return True
        if name != sel:
            return False
        return (not sel_tipo) or (tipo is None) or (sel_tipo == tipo)

    def col(c, on):
        return c if on else _hex_to_rgba(c, 0.28)

    # Anel interno — barras IGUAIS (width = arc − gap)
    in_theta, in_w, in_base, in_r, in_col, in_custom = [], [], [], [], [], []
    for i in range(n):
        center = i * arc + arc / 2
        in_theta.append(center)
        in_w.append(arc - _GAP_DEG)
        in_base.append(_R_INNER_BASE)
        in_r.append(_R_INNER_TOP - _R_INNER_BASE)   # = 0.20 (comprimento da barra)
        in_col.append(col(vend_color(i), keep(names[i])))
        in_custom.append([names[i]])

    # Anel externo — 2N barras (interno, indicado) alinhadas ao arco do vendedor.
    # SEMPRE 2 barras por vendedor (a de valor 0 fica com width 0) → índices
    # previsíveis 2i / 2i+1 para o JS de hover e para o clique.
    ou_theta, ou_w, ou_base, ou_r, ou_col, ou_custom = [], [], [], [], [], []
    pct_theta, pct_r, pct_txt = [], [], []
    for i in range(n):
        tot = totals[i] or 1
        usable = arc - _GAP_DEG
        start = i * arc + _GAP_DEG / 2
        iw = usable * (intern[i] / tot)
        dw = usable * (indic[i] / tot)
        # interno
        ic = start + iw / 2
        ou_theta.append(ic); ou_w.append(iw)
        ou_base.append(_R_OUTER_BASE); ou_r.append(_R_OUTER_TOP - _R_OUTER_BASE)   # = 0.20
        ou_col.append(col(COR_INTERNO, keep(names[i], "interno")))
        ou_custom.append([names[i], "interno"])
        # indicado
        dc = start + iw + dw / 2
        ou_theta.append(dc); ou_w.append(dw)
        ou_base.append(_R_OUTER_BASE); ou_r.append(_R_OUTER_TOP - _R_OUTER_BASE)   # = 0.20
        ou_col.append(col(COR_INDICADO, keep(names[i], "indicado")))
        ou_custom.append([names[i], "indicado"])
        # rótulos de % (esconde se o sub-arco for pequeno demais)
        pct_theta.append(ic); pct_r.append(_R_PCT)
        pct_txt.append(f"{intern[i] / tot * 100:.0f}%" if iw >= _PCT_MIN_DEG else "")
        pct_theta.append(dc); pct_r.append(_R_PCT)
        pct_txt.append(f"{indic[i] / tot * 100:.0f}%" if dw >= _PCT_MIN_DEG else "")

    fig = go.Figure()
    fig.add_trace(go.Barpolar(
        theta=in_theta, width=in_w, base=in_base, r=in_r,
        marker=dict(color=in_col, line=dict(color="#ffffff", width=1)),
        customdata=in_custom, name="inner",
        hovertemplate="%{customdata[0]}<extra></extra>",
    ))
    fig.add_trace(go.Barpolar(
        theta=ou_theta, width=ou_w, base=ou_base, r=ou_r,
        marker=dict(color=ou_col, line=dict(color="#ffffff", width=1)),
        customdata=ou_custom, name="outer",
        hovertemplate="%{customdata[0]} · %{customdata[1]}<extra></extra>",
    ))
    fig.add_trace(go.Scatterpolar(
        theta=pct_theta, r=pct_r, mode="text", text=pct_txt,
        textfont=dict(color="#1f2937", size=10, family="Inter"),
        hoverinfo="skip", showlegend=False, name="pcts", cliponaxis=False,
    ))

    # Os NOMES dos vendedores são desenhados FORA do donut (linhas-guia + rótulos)
    # pelo donut_hover.js, em SVG puro, no plotly_afterplot. O JS recebe os dados
    # necessários via fig.layout.meta (nome, ângulo da fatia e cor de cada vendedor),
    # sem qualquer conversão paper↔polar.
    fig.update_layout(
        margin=dict(t=8, b=8, l=8, r=8),
        paper_bgcolor="rgba(0,0,0,0)", showlegend=False,
        polar=dict(
            # Domínio padrão (área cheia) → donut no tamanho original. O espaço p/ os
            # rótulos externos vem da metade esquerda (.ct-donut-left, 50% do card) +
            # overflow:visible do SVG (style.css), não de reduzir o donut.
            domain=dict(x=[0, 1], y=[0, 1]),
            bgcolor="rgba(0,0,0,0)",
            radialaxis=dict(range=[0, 1], visible=False),
            angularaxis=dict(visible=False, rotation=90, direction="clockwise"),
            hole=0,
        ),
        meta=dict(
            vendedores=[
                dict(name=names[i], theta=i * arc + arc / 2, color=vend_color(i))
                for i in range(n)
            ],
            n=n,
            r_outer_top=_R_OUTER_TOP,   # 0.96 — raio (em dados) do topo do anel externo
        ),
        annotations=[
            dict(text=f"<b>{fmt_num(grand)}</b>", x=0.5, y=0.53, xref="paper", yref="paper",
                 showarrow=False, font=dict(size=30, color="#263846", family="Rubik")),
            dict(text="NEGÓCIOS", x=0.5, y=0.435, xref="paper", yref="paper",
                 showarrow=False, font=dict(size=11, color="#64748b", family="Inter")),
        ],
    )
    return fig


def build_donut_legend(vendedores, cf):
    """Legenda HTML (direita do donut). Por vendedor: bolinha (cor do anel interno),
    nome, barra de MESMA largura dividida teal/âmbar pelo split interno/indicado, e
    o % do TOTAL de negócios à direita. Abaixo: itens Interno/Indicado + legenda."""
    vs = _vend_donut_rows(vendedores)
    if not vs:
        return [html.Div("Sem dados para o período.", className="rt-empty")]
    grand = sum(_i(v["total_qtd"]) for v in vs) or 1
    sel = (cf or {}).get("vendedor")
    items = []
    for i, v in enumerate(vs):
        name = v["responsavel"]
        tot = _i(v["total_qtd"])
        interno = _i(v["propria_qtd"])
        indicado = _i(v["indicada_qtd"])
        ipct = (interno / tot * 100) if tot else 0
        dpct = (indicado / tot * 100) if tot else 0
        dim = bool(sel) and name != sel
        active = name == sel
        items.append(html.Div(
            id={"type": "ct-leg", "index": _enc(name)}, n_clicks=0,
            className="ct-leg-item" + (" ct-dim" if dim else "") + (" ct-leg-active" if active else ""),
            children=[
                html.Span(className="ct-leg-dot", style={"backgroundColor": vend_color(i)}),
                html.Div(className="ct-leg-main", children=[
                    html.Div(className="ct-leg-top", children=[
                        html.Span(name, className="ct-leg-name", title=name),
                        html.Span(f"{tot / grand * 100:.0f}%", className="ct-leg-pct"),
                    ]),
                    html.Div(className="ct-leg-bar", children=[
                        html.Span(className="ct-leg-bar-seg",
                                  style={"width": f"{ipct:.1f}%", "backgroundColor": COR_INTERNO}),
                        html.Span(className="ct-leg-bar-seg",
                                  style={"width": f"{dpct:.1f}%", "backgroundColor": COR_INDICADO}),
                    ]),
                ]),
            ],
        ))
    # Rodapé: o que significam as cores do anel externo + legenda do arco
    items.append(html.Div(className="ct-leg-foot", children=[
        html.Div(className="ct-leg-foot-row", children=[
            html.Span(className="ct-leg-dot", style={"backgroundColor": COR_INTERNO}),
            html.Span("Ativo", className="ct-leg-foot-name"),
            html.Span(className="ct-leg-dot", style={"backgroundColor": COR_INDICADO, "marginLeft": "14px"}),
            html.Span("Indicado", className="ct-leg-foot-name"),
        ]),
        html.Div("Tamanho do arco externo = split ativo/indicado", className="ct-leg-foot-cap"),
    ]))
    return items


# ── Donut da equipe (2 fatias: Interno × Indicado) — informativo ─────────────
def build_team_donut(detalhe, cf):
    """Donut simples Interno × Indicado do TIME, derivado de `detalhe` JÁ filtrado
    pelo cross-filter (se um vendedor está selecionado, mostra só o dele). Não
    dispara cross-filter (sem callback de clique)."""
    filt = _filter_detalhe(detalhe, cf)
    interno = sum(1 for d in filt if d.get("tipo_venda") == "interno")
    indicado = sum(1 for d in filt if d.get("tipo_venda") == "indicado")
    total = interno + indicado
    if total == 0:
        return empty_fig("Sem dados")
    fig = go.Figure(go.Pie(
        labels=["Ativo", "Indicado"], values=[interno, indicado],
        marker=dict(colors=[COR_INTERNO, COR_INDICADO], line=dict(color="#ffffff", width=2)),
        hole=0.76, sort=False, direction="clockwise", rotation=0,
        texttemplate="%{percent:.0%}", textposition="inside",
        insidetextfont=dict(color="#06343a", size=13),
        hovertemplate="%{label}<br>%{value} negócios (%{percent})<extra></extra>",
    ))
    fig.update_layout(
        margin=dict(t=6, b=6, l=6, r=6), paper_bgcolor="rgba(0,0,0,0)", showlegend=False,
        annotations=[
            dict(text=f"<b>{fmt_num(total)}</b>", x=0.5, y=0.54, xref="paper", yref="paper",
                 showarrow=False, font=dict(size=24, color="#263846", family="Rubik")),
            dict(text="NEGÓCIOS", x=0.5, y=0.44, xref="paper", yref="paper",
                 showarrow=False, font=dict(size=10, color="#64748b", family="Inter")),
        ],
    )
    return fig


def build_team_legend(detalhe, cf):
    filt = _filter_detalhe(detalhe, cf)
    interno = sum(1 for d in filt if d.get("tipo_venda") == "interno")
    indicado = sum(1 for d in filt if d.get("tipo_venda") == "indicado")
    total = (interno + indicado) or 1

    def item(label, color, n):
        return html.Div(className="ct-teamleg-item", children=[
            html.Span(className="ct-leg-dot", style={"backgroundColor": color}),
            html.Span(label, className="ct-teamleg-name"),
            html.Span(f"{n / total * 100:.0f}%", className="ct-teamleg-pct"),
        ])

    return [item("Ativo", COR_INTERNO, interno), item("Indicado", COR_INDICADO, indicado)]


# ── Tabela por vendedor (Bloco 2 esquerda) — HTML clicável (sem expansão) ────
def build_vendedores_table(vendedores, cf):
    """Tabela do Bloco 2. Mostra TODOS os vendedores; a linha do vendedor do
    cross-filter ativo fica destacada. Clicar numa linha apenas aplica/limpa o
    cross-filter por vendedor (toggle no cf-store) — sem expansão / sub-linha.
    Os dados de indicadas já estão nas colunas (Qtd/Valor Indicadas)."""
    if not vendedores:
        return html.P("Sem dados para o período.", className="rt-empty")

    sel = (cf or {}).get("vendedor")
    head = html.Thead(html.Tr([
        html.Th("Vendedor", style={"textAlign": "left"}),
        html.Th("Qtd Ativas", style={"textAlign": "right"}),
        html.Th("Valor Ativas", style={"textAlign": "right"}),
        html.Th("Qtd Indicadas", style={"textAlign": "right"}),
        html.Th("Valor Indicadas", style={"textAlign": "right"}),
        html.Th("Total", style={"textAlign": "right"}),
    ]))

    body = []
    for r in vendedores:
        resp = r["responsavel"]
        active = (resp == sel)
        body.append(html.Tr(
            id={"type": "ct-vend-row", "index": _enc(resp)},
            n_clicks=0,
            className="rt-vend-row" + (" rt-vend-active" if active else ""),
            children=[
                html.Td(resp, style={"textAlign": "left"}),
                html.Td(fmt_num(r["propria_qtd"]),    style={"textAlign": "right"}),
                html.Td(fmt_brl(r["propria_valor"]),  style={"textAlign": "right"}),
                html.Td(fmt_num(r["indicada_qtd"]),   style={"textAlign": "right"}),
                html.Td(fmt_brl(r["indicada_valor"]), style={"textAlign": "right"}),
                html.Td(fmt_brl(r["total_valor"]),    style={"textAlign": "right", "fontWeight": 700}),
            ],
        ))
        # Sublinhas por contabilidade (subordinadas à linha do vendedor; não clicáveis)
        _subs = (("ContaFarma", r.get("cf_qtd", 0),  r.get("cf_valor", 0)),
                 ("Capiton",    r.get("cap_qtd", 0), r.get("cap_valor", 0)))
        for i, (lbl, q, v) in enumerate(_subs):
            cls = "rt-vend-sub" + (" rt-vend-sub-end" if i == len(_subs) - 1 else "")
            body.append(html.Tr(className=cls, children=[
                html.Td(lbl, style={"textAlign": "left"}),
                html.Td(f"{fmt_num(q)} neg.", colSpan=4, style={"textAlign": "right"}),
                html.Td(fmt_brl(v), style={"textAlign": "right"}),
            ]))

    return html.Table([head, html.Tbody(body)], className="rt-table rt-table-click")


# ── Tabela por tipo de contrato (Bloco 2 direita) — fonte do filtro tipo_contrato ─
def build_contratos_table(detalhe, cf):
    """Reagrupa `detalhe` por tipo_de_contrato. É a FONTE do filtro tipo_contrato,
    então NÃO filtra por tipo_contrato (mostra todas as linhas p/ seleção); aplica
    só vendedor/tipo_venda e destaca a linha do tipo_contrato ativo. Clicar numa
    linha aplica/limpa o filtro por tipo de contrato (toggle)."""
    filt = _filter_detalhe(detalhe, cf, dims=("vendedor", "tipo_venda"))
    if not filt:
        return html.P("Sem dados para o filtro atual.", className="rt-empty")
    agg = {}
    for d in filt:
        tipo = d.get("tipo_de_contrato") or "(Sem tipo)"
        a = agg.setdefault(tipo, {"qtd": 0, "valor": 0.0})
        a["qtd"] += 1
        a["valor"] += _f(d.get("valor"))
    linhas = sorted(agg.items(), key=lambda kv: (-kv[1]["valor"], kv[0]))
    sel_tc = (cf or {}).get("tipo_contrato")
    head = html.Thead(html.Tr([
        html.Th("Tipo de Contrato", style={"textAlign": "left"}),
        html.Th("Qtd", style={"textAlign": "right"}),
        html.Th("Valor Total", style={"textAlign": "right"}),
    ]))
    body = [html.Tr(
        id={"type": "ct-tipo-row", "index": _enc(tipo)},
        n_clicks=0,
        className="rt-tipo-row" + (" rt-tipo-active" if tipo == sel_tc else ""),
        children=[
            html.Td(tipo, style={"textAlign": "left"}),
            html.Td(fmt_num(v["qtd"]), style={"textAlign": "right"}),
            html.Td(fmt_brl(v["valor"]), style={"textAlign": "right"}),
        ],
    ) for tipo, v in linhas]
    return html.Table([head, html.Tbody(body)], className="rt-table rt-table-click")


# ── Detalhamento (Bloco 3) — DataTable com ID em markdown clicável ───────────
# A URL do card (link_deal) é montada na SQL (queries.get_detalhamento).
DETALHE_COLS = [
    {"name": "ID", "id": "id", "presentation": "markdown"},
    {"name": "Cliente", "id": "cliente"},
    {"name": "Vendedor", "id": "vendedor"},
    {"name": "Tipo de Venda", "id": "tipo_venda"},
    {"name": "Etapa", "id": "etapa"},
    {"name": "Tipo de Contrato", "id": "tipo_de_contrato"},
    {"name": "Valor", "id": "valor"},
]
DETALHE_STYLE_CELL_COND = [
    {"if": {"column_id": "valor"}, "textAlign": "right"},
    {"if": {"column_id": "id"}, "textAlign": "left", "minWidth": "70px"},
    {"if": {"column_id": "cliente"}, "minWidth": "180px"},
    {"if": {"column_id": "vendedor"}, "minWidth": "150px"},
]


def _filter_detalhe(detalhe, cf, dims=("vendedor", "tipo_venda", "tipo_contrato")):
    """Filtra `detalhe` pelas dimensões do cross-filter pedidas em `dims`.
    Cada componente aplica só as dimensões que NÃO são a sua própria fonte:
      - Detalhamento / donut equipe → todas as dimensões.
      - Tabela por tipo de contrato → vendedor + tipo_venda (não filtra por
        tipo_contrato, pois ela É a fonte desse filtro; só destaca a linha ativa).
      - Agregado de vendedores / donut por vendedor → só tipo_contrato."""
    cf = cf or {}
    v = cf.get("vendedor") if "vendedor" in dims else None
    t = cf.get("tipo_venda") if "tipo_venda" in dims else None
    tc = cf.get("tipo_contrato") if "tipo_contrato" in dims else None
    out = []
    for d in detalhe or []:
        if v and d.get("vendedor") != v:
            continue
        if t and d.get("tipo_venda") != t:
            continue
        if tc and d.get("tipo_de_contrato") != tc:
            continue
        out.append(d)
    return out


def aggregate_vendedores(detalhe):
    """Reagrupa `detalhe` por vendedor (mesmo formato de queries.get_vendedores):
    qtd/valor internos (tipo_venda='interno'), indicados e total. Usado para que o
    cross-filter por tipo_contrato re-derive a tabela e o donut sem novo round-trip."""
    agg = {}
    for d in detalhe or []:
        resp = d.get("vendedor") or "(Sem responsável)"
        a = agg.get(resp)
        if a is None:
            a = agg[resp] = {"responsavel": resp, "propria_qtd": 0, "propria_valor": 0.0,
                             "indicada_qtd": 0, "indicada_valor": 0.0,
                             "total_qtd": 0, "total_valor": 0.0,
                             # sublinhas por contabilidade
                             "cf_qtd": 0, "cf_valor": 0.0, "cap_qtd": 0, "cap_valor": 0.0}
        val = _f(d.get("valor"))
        a["total_qtd"] += 1
        a["total_valor"] += val
        if d.get("tipo_venda") == "interno":
            a["propria_qtd"] += 1
            a["propria_valor"] += val
        else:
            a["indicada_qtd"] += 1
            a["indicada_valor"] += val
        grp = d.get("contab_grupo")
        if grp == "contafarma":
            a["cf_qtd"] += 1
            a["cf_valor"] += val
        elif grp == "capiton":
            a["cap_qtd"] += 1
            a["cap_valor"] += val
    rows = list(agg.values())
    rows.sort(key=lambda r: (-r["total_valor"], r["responsavel"]))
    return rows


def build_detalhamento_data(detalhe, cf):
    rows = []
    for d in _filter_detalhe(detalhe, cf):
        bid = d.get("bitrix_id")
        link = d.get("link_deal")
        rows.append({
            "id": f"[{bid}]({link})" if (bid is not None and link) else (str(bid) if bid is not None else "—"),
            "cliente": d.get("cliente") or "—",
            "vendedor": d.get("vendedor") or "—",
            "tipo_venda": TIPO_VENDA_LABEL.get(d.get("tipo_venda"), "—"),
            "etapa": d.get("etapa") or "—",
            "tipo_de_contrato": d.get("tipo_de_contrato") or "—",
            "valor": fmt_brl(d.get("valor")),
        })
    return rows


# ── App ──────────────────────────────────────────────────────────────────────
app = Dash(
    __name__,
    title="ContaFarma — Relatório Contabilidade",
    external_stylesheets=[
        "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css",
    ],
    suppress_callback_exceptions=True,
    requests_pathname_prefix="/relatorios-bi/relatorio-contabilidade/",
)
server = app.server  # alvo do gunicorn

app.layout = html.Div(className="rt-app", children=[
    dcc.Location(id="url"),
    # Aba ativa (conjunto de etapas).
    dcc.Store(id="ct-aba", data=ABA_DEFAULT),
    # Dataset serializado da aba/período (vendedores + indicadas agrupadas + detalhe).
    dcc.Store(id="ct-data", data={"vendedores": [], "indicadas": {}, "detalhe": []}),
    # Cross-filter central: {vendedor, tipo_venda}. Resetado ao trocar aba/período.
    dcc.Store(id="cf-store", data=dict(CF_EMPTY)),

    # Cabeçalho
    html.Div(className="rt-header", children=[
        html.Div(className="rt-brand", children="Relatório Comercial"),
        html.Div(className="rt-tabs", children=[
            html.Button(rotulo, className="rt-tab" + (" rt-tab-active" if chave == ABA_DEFAULT else ""),
                        id={"type": "ct-tab", "index": chave})
            for chave, rotulo in ABAS
        ]),
        data_filter_bar(),
    ]),

    html.Div(id="error-banner"),

    # ── Bloco 1: KPIs (largura cheia, 4 colunas iguais) ──────────────────────
    kpi_row(),

    # ── Linha dos donuts: EQUIPE (esq.) + por vendedor (dir.) ────────────────
    html.Div(className="ct-donut-row", children=[
        # Donut 1 — por vendedor (filtra)
        html.Div(className="rt-card ct-donut-card", children=[
            html.Div(className="rt-card-head", children=[
                html.I(className="fas fa-chart-pie"),
                html.Span("Distribuição por Vendedor (Ativo × Indicado)"),
                html.Div(id="cf-chip-wrap", className="ct-chip-wrap", style={"display": "none"}, children=[
                    html.I(className="fas fa-filter"),
                    html.Span(id="cf-chip-text", className="ct-chip-text"),
                    html.Button("×", id="cf-chip-clear", className="ct-chip-x", title="Limpar filtro"),
                ]),
            ]),
            html.Div(className="rt-card-body", children=[
                # Duas metades 50/50: esquerda = donut centrado; direita = legenda.
                html.Div(className="ct-donut", children=[
                    html.Div(className="ct-donut-left", children=[
                        html.Div(className="ct-donut-circle", children=dcc.Graph(
                            id="ct-donut", figure=empty_fig("Carregando…"),
                            config={"displayModeBar": False},
                            style={"height": "360px", "width": "360px"})),
                    ]),
                    html.Div(className="ct-donut-right", children=[
                        html.Div(id="ct-donut-legend", className="ct-donut-legend"),
                    ]),
                ]),
            ]),
        ]),
        # Donut 2 — EQUIPE (informativo, não filtra)
        html.Div(className="rt-card ct-team-card", children=[
            html.Div(className="rt-card-head", children=[
                html.I(className="fas fa-users"),
                html.Span("Equipe — Ativo × Indicado"),
            ]),
            html.Div(className="rt-card-body ct-team-body", children=[
                html.Div(className="ct-team-circle", children=dcc.Graph(
                    id="ct-donut2", figure=empty_fig("Carregando…"),
                    config={"displayModeBar": False, "staticPlot": True},
                    style={"height": "300px", "width": "300px"})),
                html.Div(id="ct-donut2-legend", className="ct-teamleg"),
            ]),
        ]),
    ]),

    # ── Bloco 2: duas tabelas lado a lado ────────────────────────────────────
    html.Div(className="ct-two-col", children=[
        card("Negócios por Vendedor", icon="fa-user-tie", extra_class="ct-col-vend", children=[
            html.Div(id="ct-vendedores"),
        ]),
        card("Negócios por Tipo de Contrato", icon="fa-file-signature", extra_class="ct-col-contrato", children=[
            html.Div(id="ct-contratos"),
        ]),
    ]),

    # ── Bloco 3: Detalhamento (full width) ───────────────────────────────────
    card("Detalhamento", icon="fa-table-list", extra_class="rt-col-full", children=[
        dash_table.DataTable(
            id="tbl-detalhamento",
            columns=DETALHE_COLS,
            data=[],
            markdown_options={"link_target": "_blank"},
            cell_selectable=False,
            page_size=25,
            sort_action="native",
            style_as_list_view=True,
            style_table={"overflowX": "auto"},
            style_cell={"fontFamily": "Inter, sans-serif", "fontSize": "12.5px",
                        "padding": "8px 10px", "border": "none", "textAlign": "left"},
            style_header={"backgroundColor": "#f8fafc", "fontWeight": "600",
                          "color": "#475569", "textTransform": "uppercase",
                          "fontSize": "10.5px", "letterSpacing": "0.04em"},
            style_data={"borderBottom": "1px solid #f1f5f9"},
            style_cell_conditional=DETALHE_STYLE_CELL_COND,
        ),
    ]),
])


# ── Troca de aba: muda o conjunto de etapas (reseta cross-filter via load_data) ─
@callback(
    Output("ct-aba", "data"),
    Input({"type": "ct-tab", "index": ALL}, "n_clicks"),
    State("ct-aba", "data"),
    prevent_initial_call=True,
)
def switch_tab(_n, atual):
    if not ctx.triggered or not ctx.triggered[0]["value"]:
        return no_update
    nova = ctx.triggered_id["index"]
    return no_update if nova == atual else nova


@callback(
    Output({"type": "ct-tab", "index": ALL}, "className"),
    Input("ct-aba", "data"),
)
def highlight_tab(aba):
    return ["rt-tab" + (" rt-tab-active" if chave == aba else "") for chave, _ in ABAS]


# ── Filtro de data: abrir/fechar painel, mostrar "Limpar", limpar ────────────
@callback(
    Output("ct-data-panel", "className"),
    Input("ct-data-btn", "n_clicks"),
    State("ct-data-panel", "className"),
    prevent_initial_call=True,
)
def toggle_data_panel(_n, cls):
    return "rt-data-panel" if (cls and "open" in cls) else "rt-data-panel open"


@callback(
    Output("ct-data-limpar-wrap", "style"),
    Input("ct-data-de", "date"),
    Input("ct-data-ate", "date"),
)
def toggle_limpar(de, ate):
    return {"display": "block"} if (de or ate) else {"display": "none"}


@callback(
    Output("ct-data-de", "date"),
    Output("ct-data-ate", "date"),
    Input("ct-data-limpar", "n_clicks"),
    prevent_initial_call=True,
)
def limpar_datas(_n):
    return None, None


# ── Carga de dados: KPIs (período) + dataset da aba; reseta o cross-filter ────
@callback(
    Output("kpi-total-valor", "children"),
    Output("kpi-total-qtd", "children"),
    Output("kpi-propria-valor", "children"),
    Output("kpi-propria-qtd", "children"),
    Output("kpi-indicada-valor", "children"),
    Output("kpi-indicada-qtd", "children"),
    Output("kpi-ticket", "children"),
    Output("kpi-ticket-sub", "children"),
    # Breakdown ContaFarma × Capiton (4 cards × val/qtd de cada lado)
    Output("kpi-total-cf-val", "children"),    Output("kpi-total-cf-qtd", "children"),
    Output("kpi-total-cap-val", "children"),   Output("kpi-total-cap-qtd", "children"),
    Output("kpi-propria-cf-val", "children"),  Output("kpi-propria-cf-qtd", "children"),
    Output("kpi-propria-cap-val", "children"), Output("kpi-propria-cap-qtd", "children"),
    Output("kpi-indicada-cf-val", "children"), Output("kpi-indicada-cf-qtd", "children"),
    Output("kpi-indicada-cap-val", "children"),Output("kpi-indicada-cap-qtd", "children"),
    Output("kpi-ticket-cf-val", "children"),   Output("kpi-ticket-cf-qtd", "children"),
    Output("kpi-ticket-cap-val", "children"),  Output("kpi-ticket-cap-qtd", "children"),
    Output("ct-data", "data"),
    Output("cf-store", "data"),
    Output("error-banner", "children"),
    Input("ct-aba", "data"),
    Input("ct-data-de", "date"),
    Input("ct-data-ate", "date"),
    Input("btn-refresh", "n_clicks"),
)
def load_data(aba, data_de, data_ate, _n):
    # Período: ambos → intervalo De..Até; só um → aquele dia exato; nenhum → sem filtro.
    if data_de and data_ate:
        dd, da = data_de, data_ate
    elif data_de:
        dd = da = data_de
    elif data_ate:
        dd = da = data_ate
    else:
        dd = da = None

    # Filtros do Portal BI (headers X-CT-* injetados pelo nginx via auth-check).
    # Usuário interno (sem headers) → ct_completo=True + listas vazias → vê tudo.
    hdr = flask.request.headers
    has_ct_headers = hdr.get("X-Ct-Completo") is not None
    if has_ct_headers:
        ct_completo  = hdr.get("X-Ct-Completo", "0").strip() == "1"
        ct_indicador = unquote(hdr.get("X-Ct-Indicador", "") or "").strip()
        ct_contab    = unquote(hdr.get("X-Ct-Contab", "") or "").strip()
    else:
        ct_completo, ct_indicador, ct_contab = True, "", ""
    indicador_list = [v.strip() for v in ct_indicador.split(",") if v.strip()] if ct_indicador else []
    contab_list    = [v.strip() for v in ct_contab.split(",")    if v.strip()] if ct_contab    else []

    cf_reset = dict(CF_EMPTY)
    try:
        d = queries.get_aba(aba or ABA_DEFAULT, data_de=dd, data_ate=da,
                            ct_completo=ct_completo,
                            ct_indicador=indicador_list,
                            ct_contab=contab_list)
    except Exception as e:
        banner = html.Div(className="rt-error", children=[
            html.I(className="fas fa-triangle-exclamation"),
            f" Erro ao carregar os dados: {e}",
        ])
        return ("—", "—", "—", "—", "—", "—", "—", "—",
                *(["—"] * 16),
                {"vendedores": [], "indicadas": {}, "detalhe": []}, cf_reset, banner)

    k = d["kpis"]
    total_qtd = _i(k.get("total_qtd"))
    total_valor = _f(k.get("total_valor"))
    ticket = (total_valor / total_qtd) if total_qtd else 0.0

    vendedores = [{
        "responsavel":    r["responsavel"],
        "propria_qtd":    _i(r["propria_qtd"]),
        "propria_valor":  _f(r["propria_valor"]),
        "indicada_qtd":   _i(r["indicada_qtd"]),
        "indicada_valor": _f(r["indicada_valor"]),
        "total_qtd":      _i(r["total_qtd"]),
        "total_valor":    _f(r["total_valor"]),
    } for r in d["vendedores"]]

    indicadas_por_resp = {}
    for it in d["indicadas"]:
        indicadas_por_resp.setdefault(it["responsavel"], []).append({
            "negocio":          it.get("negocio"),
            "indicador":        it.get("indicador"),
            "tipo_de_contrato": it.get("tipo_de_contrato"),
            "data":             it.get("data"),
            "valor":            _f(it.get("valor")),
        })

    detalhe = [{
        "bitrix_id":        r.get("bitrix_id"),
        "link_deal":        r.get("link_deal"),
        "cliente":          r.get("cliente"),
        "vendedor":         r.get("vendedor"),
        "tipo_venda":       r.get("tipo_venda"),   # 'interno' | 'indicado'
        "etapa":            r.get("etapa"),
        "tipo_de_contrato": r.get("tipo_de_contrato"),
        "contab_grupo":     r.get("contab_grupo"),  # 'contafarma' | 'capiton' | 'outro'
        "valor":            _f(r.get("valor")),
    } for r in d["detalhe"]]

    store = {"vendedores": vendedores, "indicadas": indicadas_por_resp, "detalhe": detalhe}

    # ── Breakdown ContaFarma × Capiton por card ──────────────────────────────
    cf  = k.get("contafarma", {})
    cap = k.get("capiton", {})
    def _tk(g):  # ticket médio de um grupo (valor total / qtd total)
        tq = _i(g.get("total_qtd")); tv = _f(g.get("total_valor"))
        return (tv / tq) if tq else 0.0
    def _neg(n): return f"{fmt_num(n)} neg."
    bd = (
        # Vendas Total
        fmt_brl(cf.get("total_valor")),    _neg(cf.get("total_qtd")),
        fmt_brl(cap.get("total_valor")),   _neg(cap.get("total_qtd")),
        # Vendas Ativas (própria)
        fmt_brl(cf.get("propria_valor")),  _neg(cf.get("propria_qtd")),
        fmt_brl(cap.get("propria_valor")), _neg(cap.get("propria_qtd")),
        # Vendas Indicadas
        fmt_brl(cf.get("indicada_valor")), _neg(cf.get("indicada_qtd")),
        fmt_brl(cap.get("indicada_valor")),_neg(cap.get("indicada_qtd")),
        # Ticket Médio
        fmt_brl(_tk(cf)),  _neg(cf.get("total_qtd")),
        fmt_brl(_tk(cap)), _neg(cap.get("total_qtd")),
    )

    return (
        fmt_brl(total_valor), f"{fmt_num(total_qtd)} negócios",
        fmt_brl(k.get("propria_valor")), f"{fmt_num(k.get('propria_qtd'))} negócios",
        fmt_brl(k.get("indicada_valor")), f"{fmt_num(k.get('indicada_qtd'))} negócios",
        fmt_brl(ticket), "valor médio por negócio",
        *bd,
        store, cf_reset, None,
    )


# ── Render central: 3 tabelas + donut + legenda + chip (lê ct-data e cf-store) ─
@callback(
    Output("ct-vendedores", "children"),
    Output("ct-contratos", "children"),
    Output("tbl-detalhamento", "data"),
    Output("ct-donut", "figure"),
    Output("ct-donut-legend", "children"),
    Output("ct-donut2", "figure"),
    Output("ct-donut2-legend", "children"),
    Output("cf-chip-text", "children"),
    Output("cf-chip-wrap", "style"),
    Input("ct-data", "data"),
    Input("cf-store", "data"),
)
def render_views(data, cf):
    data = data or {}
    cf = cf or dict(CF_EMPTY)
    detalhe = data.get("detalhe", [])

    # Vendedores (tabela + donut) re-derivados de `detalhe` filtrado SÓ por
    # tipo_contrato → assim o filtro por tipo de contrato reflete na tabela e no
    # donut; o destaque de vendedor/tipo_venda vem do próprio cf (highlight/pull).
    det_vend = _filter_detalhe(detalhe, cf, dims=("tipo_contrato",))
    vend_agg = aggregate_vendedores(det_vend)

    vend_tbl = build_vendedores_table(vend_agg, cf)
    contr_tbl = build_contratos_table(detalhe, cf)
    det_data = build_detalhamento_data(detalhe, cf)
    donut = build_donut(vend_agg, cf)
    legend = build_donut_legend(vend_agg, cf)
    team_donut = build_team_donut(detalhe, cf)
    team_legend = build_team_legend(detalhe, cf)

    if cf.get("vendedor"):
        tip = cf.get("tipo_venda")
        txt = f" {cf['vendedor']}" + (f" · {TIPO_VENDA_LABEL.get(tip, '')}" if tip else "")
        chip_style = {"display": "inline-flex"}
    elif cf.get("tipo_contrato"):
        txt = f" {cf['tipo_contrato']}"
        chip_style = {"display": "inline-flex"}
    else:
        txt = ""
        chip_style = {"display": "none"}

    return (vend_tbl, contr_tbl, det_data, donut, legend,
            team_donut, team_legend, txt, chip_style)


# ── Cross-filter: toggles centrais (vendedor e tipo_contrato são exclusivos) ──
def _cf_toggle(cur, vendedor, tipo):
    """Aplica filtro por vendedor (+tipo_venda). Sempre ZERA tipo_contrato."""
    cur = cur or {}
    if cur.get("vendedor") == vendedor and cur.get("tipo_venda") == tipo:
        return dict(CF_EMPTY)
    return {"vendedor": vendedor, "tipo_venda": tipo, "tipo_contrato": None}


def _cf_toggle_tipo(cur, tipo_contrato):
    """Aplica filtro por tipo de contrato. Sempre ZERA vendedor/tipo_venda."""
    cur = cur or {}
    if cur.get("tipo_contrato") == tipo_contrato:
        return dict(CF_EMPTY)
    return {"vendedor": None, "tipo_venda": None, "tipo_contrato": tipo_contrato}


# A. Clique no donut (anel interno → vendedor; anel externo → vendedor + tipo).
@callback(
    Output("cf-store", "data", allow_duplicate=True),
    Output("ct-donut", "clickData"),
    Input("ct-donut", "clickData"),
    State("cf-store", "data"),
    prevent_initial_call=True,
)
def cf_from_donut(click, cur):
    if not click or not click.get("points"):
        return no_update, no_update
    cd = click["points"][0].get("customdata")
    if not isinstance(cd, list) or not cd:
        return no_update, None
    vendedor = cd[0]
    tipo = cd[1] if len(cd) >= 2 else None
    return _cf_toggle(cur, vendedor, tipo), None


# B. Clique num item da legenda → vendedor (tipo=None).
@callback(
    Output("cf-store", "data", allow_duplicate=True),
    Input({"type": "ct-leg", "index": ALL}, "n_clicks"),
    State("cf-store", "data"),
    prevent_initial_call=True,
)
def cf_from_legend(_n, cur):
    if not ctx.triggered or not ctx.triggered[0]["value"]:
        return no_update
    return _cf_toggle(cur, _dec(ctx.triggered_id["index"]), None)


# C. Clique na linha de vendedor (tabela) → vendedor (tipo=None).
@callback(
    Output("cf-store", "data", allow_duplicate=True),
    Input({"type": "ct-vend-row", "index": ALL}, "n_clicks"),
    State("cf-store", "data"),
    prevent_initial_call=True,
)
def cf_from_row(_n, cur):
    if not ctx.triggered or not ctx.triggered[0]["value"]:
        return no_update
    return _cf_toggle(cur, _dec(ctx.triggered_id["index"]), None)


# D. Clique numa linha de "Negócios por Tipo de Contrato" → tipo_contrato.
@callback(
    Output("cf-store", "data", allow_duplicate=True),
    Input({"type": "ct-tipo-row", "index": ALL}, "n_clicks"),
    State("cf-store", "data"),
    prevent_initial_call=True,
)
def cf_from_tipo(_n, cur):
    if not ctx.triggered or not ctx.triggered[0]["value"]:
        return no_update
    return _cf_toggle_tipo(cur, _dec(ctx.triggered_id["index"]))


# E. Limpar filtro pelo "×" do chip.
@callback(
    Output("cf-store", "data", allow_duplicate=True),
    Input("cf-chip-clear", "n_clicks"),
    prevent_initial_call=True,
)
def cf_clear(_n):
    return dict(CF_EMPTY)


if __name__ == "__main__":
    app.run(
        host=os.getenv("APP_HOST", "127.0.0.1"),
        port=int(os.getenv("APP_PORT", "8051")),
        debug=os.getenv("APP_DEBUG", "true").lower() == "true",
    )
