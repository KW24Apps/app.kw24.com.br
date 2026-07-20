"""
Consultas do Relatório Contabilidade (ContaFarma).

Banco:  bx_sync_contabilidade
Tabela: tbl_onboard

Campos usados:
  - criado_em                  → data (filtro de período De/Até)
  - etapa                      → estágio (classifica a aba: Vendas Fechadas / Em Negociação)
  - contrato_mensal_valor_aprovado                       → mensalidade aprovada  ┐ soma = valor
  - constituicao_alteracao_servico_extra_valor_aprovado  → constituição/serviço  ┘ monetário (ver VALOR)
  - responsavel_pela_execucao  → vendedor (linha da tabela do Bloco 2)
  - parceiro_indicacao         → origem do negócio (própria x indicada — ver REGRAS)
  - tipo_de_contrato           → tipo de contrato (Bloco 3)

⚠️ VALOR MONETÁRIO (regra definida pelo usuário em 2026-07-20): o campo antigo
   `valor` NÃO é mais usado (dados não confiáveis). O valor de CADA negócio, para
   TODOS os tipos de contrato, é a soma de contrato_mensal_valor_aprovado +
   constituicao_alteracao_servico_extra_valor_aprovado (ver constante VALOR).

✅ Schema VERIFICADO (via túnel SSH para o banco real; 2026-06-26 e 2026-07-20):
     - Colunas de etapa/vendedor/parceiro/tipo/contab existem em tbl_onboard.
     - `contrato_mensal_valor_aprovado` e `constituicao_alteracao_servico_extra_valor_aprovado`
       são NUMERIC nullable → COALESCE(...,0) nos dois (ver VALOR).
     - `criado_em` é TIMESTAMP → ::date no filtro de período está correto.
     - parceiro_indicacao: os dois nomes de venda própria batem EXATAMENTE
       ('CAPITON CONTABILIDADE S/S', 'FF CONTABILIDADE LTDA'); 9 NULLs, 0 vazios.
     - Mesmo verificado, as queries seguem DEFENSIVAS (UPPER/TRIM no parceiro,
       NULL/'' tratados, NULLIF/COALESCE) — robustas a novas linhas.
   ⚠️ Nota de dados: a etapa 'Não Fechados' (~142 linhas) NÃO está em nenhuma
      das duas abas (fora do escopo, por especificação). 'Solicitação' (aba Em
      Negociação) existe na spec mas hoje tem 0 linhas.

Notas técnicas:
  - psycopg2 com parâmetros nomeados %(nome)s; o mesmo parâmetro pode repetir.
  - Sem multi-tenant/portal aqui (relatório interno) — sem cláusula de parceiro.
"""

from db import fetch_all, fetch_one

# ── Conjuntos de etapa por aba ────────────────────────────────────────────────
# A estrutura das duas abas é idêntica; só muda o filtro de etapa.
ETAPAS = {
    "fechadas": [
        "Boas Vindas", "Constituição Empresa", "Delegação de Tarefas",
        "Conferência", "Concluídos",
    ],
    "negociacao": [
        "Solicitação", "Orçamento", "Gerar Proposta", "Gerar Contrato", "Click Sign",
    ],
}

# Tipos de contrato excluídos de todas as visões (não são vendas).
TIPOS_EXCLUIDOS = ("Distrato",)

# ── Classificação de origem (própria x indicada) ─────────────────────────────
# Regra de negócio:
#   'FF CONTABILIDADE LTDA'      → própria
#   'CAPITON CONTABILIDADE S/S'  → própria
#   NULL ou string vazia         → própria (não perder o registro)
#   qualquer outro valor não-vazio → indicada (o próprio valor é o indicador)
PARCEIROS_PROPRIOS = ("FF CONTABILIDADE LTDA", "CAPITON CONTABILIDADE S/S")

# Expressão SQL booleana: TRUE quando o registro é venda PRÓPRIA.
# Comparação case-insensitive e tolerante a espaços nas pontas.
EH_PROPRIA = """(
    COALESCE(TRIM(t.parceiro_indicacao), '') = ''
    OR UPPER(TRIM(t.parceiro_indicacao)) IN ('FF CONTABILIDADE LTDA', 'CAPITON CONTABILIDADE S/S')
)"""
# Indicada = não-própria (qualquer valor não-vazio fora da lista de próprios).
EH_INDICADA = f"NOT {EH_PROPRIA}"

# ── Breakdown por contabilidade (SOMENTE para a divisão visual dos cards KPI) ──
# Usa contabilidade_responsavel_operacional (NÃO parceiro_indicacao).
# NÃO altera PARCEIROS_PROPRIOS nem a classificação Ativo/Indicado acima.
#   ContaFarma = FF CONTABILIDADE LTDA + CONTAFARMA CONTABILIDADE S/S
#   Capiton    = CAPITON CONTABILIDADE S/S
EH_CONTAFARMA = "UPPER(TRIM(t.contabilidade_responsavel_operacional)) IN ('FF CONTABILIDADE LTDA', 'CONTAFARMA CONTABILIDADE S/S')"
EH_CAPITON    = "UPPER(TRIM(t.contabilidade_responsavel_operacional)) = 'CAPITON CONTABILIDADE S/S'"
# Grupo de contabilidade por negócio (usado no `detalhe` p/ as sublinhas da tabela
# de vendedores serem re-derivadas no cross-filter). 'contafarma' | 'capiton' | 'outro'.
CONTAB_GRUPO_CASE = (f"CASE WHEN {EH_CONTAFARMA} THEN 'contafarma' "
                     f"WHEN {EH_CAPITON} THEN 'capiton' ELSE 'outro' END")

# ── Valor monetário do negócio ────────────────────────────────────────────────
# REGRA (definida pelo usuário, 20/07/2026): o campo antigo `valor` NÃO é mais
# usado (dados não confiáveis, preenchidos incorretamente pelo time do funil). O
# valor total de CADA negócio, para TODOS os tipos de contrato (sem exceção), é a
# soma de:
#   contrato_mensal_valor_aprovado  (mensalidade aprovada)
#   + constituicao_alteracao_servico_extra_valor_aprovado  (constituição/alteração/serviço extra)
# Ambos NUMERIC nullable → COALESCE(...,0) nos dois. Usar esta expressão em toda
# soma/leitura monetária (KPIs, vendedores, contratos, detalhamento, indicadas).
VALOR = ("(COALESCE(t.contrato_mensal_valor_aprovado, 0) + "
         "COALESCE(t.constituicao_alteracao_servico_extra_valor_aprovado, 0))")


# ── Helpers de cláusula ──────────────────────────────────────────────────────
def _etapa_clause(aba):
    """Cláusula de etapa para a aba. Gera placeholders nomeados %(et_0)s,..."""
    etapas = ETAPAS.get(aba, ETAPAS["fechadas"])
    params = {f"et_{i}": v for i, v in enumerate(etapas)}
    placeholders = ", ".join(f"%(et_{i})s" for i in range(len(etapas)))
    return f"t.etapa IN ({placeholders})", params


def _data_clause(data_de, data_ate):
    """Filtro por período em criado_em (aplicado SÓ quando AMBAS as datas vêm
    preenchidas). Usa ::date para incluir o dia inteiro nas duas pontas — mesmo
    comportamento do datepicker do relatorio-parceiros-tax."""
    if data_de and data_ate:
        return (" AND t.criado_em::date >= %(data_de)s AND t.criado_em::date <= %(data_ate)s",
                {"data_de": data_de, "data_ate": data_ate})
    return "", {}


def _ct_clause(ct_completo, ct_indicador, ct_contab):
    """Filtros do Portal BI (headers X-CT-* injetados pelo nginx/auth-check).

    - indicador (parceiro_indicacao): só quando ct_completo=False E a lista não
      está vazia. ct_completo=True = Relatório Completo (sem filtro de indicador).
    - contabilidade (contabilidade_responsavel_operacional): sempre que a lista
      não estiver vazia (vazia = todas).
    Usuário interno (sem headers) → ct_completo=True + listas vazias → sem filtro."""
    where, params = "", {}
    if not ct_completo and ct_indicador:
        where += " AND TRIM(t.parceiro_indicacao) = ANY(%(ct_indicador)s)"
        params["ct_indicador"] = list(ct_indicador)
    if ct_contab:
        where += " AND TRIM(t.contabilidade_responsavel_operacional) = ANY(%(ct_contab)s)"
        params["ct_contab"] = list(ct_contab)
    return where, params


def _base(aba, data_de, data_ate, ct_completo=True, ct_indicador=None, ct_contab=None):
    """Monta (where, params) base = etapa da aba + período + filtros de portal.
    Reutilizado por todas as visões da aba para garantir o MESMO escopo."""
    ec, ep = _etapa_clause(aba)
    dw, dp = _data_clause(data_de, data_ate)
    cw, cp = _ct_clause(ct_completo, ct_indicador, ct_contab)
    excl = " AND COALESCE(NULLIF(TRIM(t.tipo_de_contrato), ''), '(Sem tipo)') NOT IN ({})".format(
        ", ".join(f"%(excl_{i})s" for i in range(len(TIPOS_EXCLUIDOS)))
    )
    excl_params = {f"excl_{i}": v for i, v in enumerate(TIPOS_EXCLUIDOS)}
    return f"{ec}{dw}{cw}{excl}", {**ep, **dp, **cp, **excl_params}


# ── Bloco 1: KPIs (Total / Internas / Indicadas) ─────────────────────────────
# Obs.: os aliases SQL seguem `propria_*` (identificadores estáveis); o rótulo
# de NEGÓCIO exibido é "Interno/Internas" (ver app.py). propria == interno.
def get_kpis(aba, data_de=None, data_ate=None, ct_completo=True, ct_indicador=None, ct_contab=None):
    """Quantidade e soma de valor — total, internas (próprias) e indicadas. O
    ticket médio (total_valor / total_qtd) é calculado na apresentação (app.py)."""
    where, params = _base(aba, data_de, data_ate, ct_completo, ct_indicador, ct_contab)

    # 3 grupos, cada um com as MESMAS 6 métricas (total/própria/indicada × qtd/valor):
    #   ""     → todos (KPIs atuais, inalterados)
    #   "cf_"  → só ContaFarma      "cap_" → só Capiton  (breakdown dos cards)
    _grupos = {"": "TRUE", "cf_": EH_CONTAFARMA, "cap_": EH_CAPITON}
    _cols = []
    for pref, cond in _grupos.items():
        _cols += [
            f"COUNT(*) FILTER (WHERE {cond}) AS {pref}total_qtd",
            f"COALESCE(SUM({VALOR}) FILTER (WHERE {cond}), 0) AS {pref}total_valor",
            f"COUNT(*) FILTER (WHERE {cond} AND {EH_PROPRIA}) AS {pref}propria_qtd",
            f"COALESCE(SUM({VALOR}) FILTER (WHERE {cond} AND {EH_PROPRIA}), 0) AS {pref}propria_valor",
            f"COUNT(*) FILTER (WHERE {cond} AND {EH_INDICADA}) AS {pref}indicada_qtd",
            f"COALESCE(SUM({VALOR}) FILTER (WHERE {cond} AND {EH_INDICADA}), 0) AS {pref}indicada_valor",
        ]
    sql = f"SELECT {', '.join(_cols)} FROM tbl_onboard t WHERE {where}"
    row = fetch_one(sql, params) or {}

    _campos = ["total_qtd", "total_valor", "propria_qtd", "propria_valor", "indicada_qtd", "indicada_valor"]
    def _bloco(pref):
        return {k: (row.get(pref + k) or 0) for k in _campos}

    kpis = _bloco("")                 # KPIs atuais (flat) — mesma estrutura de antes
    kpis["contafarma"] = _bloco("cf_")  # breakdown ContaFarma (para os cards)
    kpis["capiton"]    = _bloco("cap_") # breakdown Capiton
    return kpis


# ── Bloco 2: Tabela por vendedor (responsavel_pela_execucao) ─────────────────
def get_vendedores(aba, data_de=None, data_ate=None, ct_completo=True, ct_indicador=None, ct_contab=None):
    """Uma linha por vendedor: qtd/valor próprios, qtd/valor indicados e total.
    Ordena pelo valor total desc. Linha expansível na UI (ver get_indicadas)."""
    where, params = _base(aba, data_de, data_ate, ct_completo, ct_indicador, ct_contab)
    sql = f"""
        SELECT
            COALESCE(NULLIF(TRIM(t.responsavel_pela_execucao), ''), '(Sem responsável)') AS responsavel,
            COUNT(*) FILTER (WHERE {EH_PROPRIA})                  AS propria_qtd,
            COALESCE(SUM({VALOR}) FILTER (WHERE {EH_PROPRIA}), 0)  AS propria_valor,
            COUNT(*) FILTER (WHERE {EH_INDICADA})                 AS indicada_qtd,
            COALESCE(SUM({VALOR}) FILTER (WHERE {EH_INDICADA}), 0) AS indicada_valor,
            COUNT(*)                                              AS total_qtd,
            COALESCE(SUM({VALOR}), 0)                             AS total_valor,
            -- Breakdown por contabilidade (sublinhas ContaFarma/Capiton)
            COUNT(*) FILTER (WHERE {EH_CONTAFARMA})                  AS cf_qtd,
            COALESCE(SUM({VALOR}) FILTER (WHERE {EH_CONTAFARMA}), 0)  AS cf_valor,
            COUNT(*) FILTER (WHERE {EH_CAPITON})                     AS cap_qtd,
            COALESCE(SUM({VALOR}) FILTER (WHERE {EH_CAPITON}), 0)     AS cap_valor
        FROM tbl_onboard t
        WHERE {where}
        GROUP BY 1
        ORDER BY total_valor DESC, responsavel ASC
    """
    return fetch_all(sql, params)


# Campo do nome do cliente por aba (difere em tbl_onboard):
#   Vendas Fechadas → empresa        ·  Em Negociação → nome_da_empresa
NEGOCIO_COL = {
    "fechadas":   "empresa",
    "negociacao": "nome_da_empresa",
}


def get_indicadas(aba, data_de=None, data_ate=None, ct_completo=True, ct_indicador=None, ct_contab=None):
    """Negócios INDICADOS (não-próprios) do escopo da aba — usados na expansão de
    cada linha de vendedor (Bloco 2). Retorna, por negócio: o vendedor, o nome do
    cliente (`negocio`), o indicador (parceiro_indicacao), o tipo de contrato, a
    data e o valor.

    O campo do nome do cliente muda por aba (ver NEGOCIO_COL): `empresa` em Vendas
    Fechadas, `nome_da_empresa` em Em Negociação. É exposto como `negocio` — chave
    que o app.py já exibe na primeira coluna da expansão."""
    if aba not in ETAPAS:
        aba = "fechadas"
    negocio_col = NEGOCIO_COL[aba]
    where, params = _base(aba, data_de, data_ate, ct_completo, ct_indicador, ct_contab)
    sql = f"""
        SELECT
            COALESCE(NULLIF(TRIM(t.responsavel_pela_execucao), ''), '(Sem responsável)') AS responsavel,
            NULLIF(TRIM(t.{negocio_col}), '')                                AS negocio,
            TRIM(t.parceiro_indicacao)                                       AS indicador,
            COALESCE(NULLIF(TRIM(t.tipo_de_contrato), ''), '(Sem tipo)')     AS tipo_de_contrato,
            TO_CHAR(t.criado_em, 'DD/MM/YYYY')                               AS data,
            {VALOR}                                                          AS valor
        FROM tbl_onboard t
        WHERE {where} AND {EH_INDICADA}
        ORDER BY {VALOR} DESC, responsavel ASC
    """
    return fetch_all(sql, params)


# ── Bloco 3: Tabela por tipo de contrato (tipo_de_contrato) ──────────────────
def get_contratos(aba, data_de=None, data_ate=None, ct_completo=True, ct_indicador=None, ct_contab=None):
    """Tabela plana: tipo de contrato | quantidade | valor total.

    Mantida por compatibilidade. A UI passou a montar esta tabela no cliente, a
    partir do conjunto `detalhe` (get_detalhamento) — assim o cross-filter por
    vendedor/tipo-de-venda reagrupa sem novo round-trip ao banco."""
    where, params = _base(aba, data_de, data_ate, ct_completo, ct_indicador, ct_contab)
    sql = f"""
        SELECT
            COALESCE(NULLIF(TRIM(t.tipo_de_contrato), ''), '(Sem tipo)') AS tipo_de_contrato,
            COUNT(*)                  AS qtd,
            COALESCE(SUM({VALOR}), 0) AS valor_soma,
            -- Breakdown por contabilidade (sublinhas ContaFarma/Capiton)
            COUNT(*) FILTER (WHERE {EH_CONTAFARMA})                  AS cf_qtd,
            COALESCE(SUM({VALOR}) FILTER (WHERE {EH_CONTAFARMA}), 0)  AS cf_valor,
            COUNT(*) FILTER (WHERE {EH_CAPITON})                     AS cap_qtd,
            COALESCE(SUM({VALOR}) FILTER (WHERE {EH_CAPITON}), 0)     AS cap_valor
        FROM tbl_onboard t
        WHERE {where}
        GROUP BY 1
        ORDER BY valor_soma DESC, tipo_de_contrato ASC
    """
    return fetch_all(sql, params)


# ── Detalhamento — uma linha por negócio (deal) ──────────────────────────────
# Classificação de Tipo de Venda (mesma regra de parceiro_indicacao):
#   interno  = venda própria  (EH_PROPRIA)
#   indicado = venda indicada (EH_INDICADA)
def get_detalhamento(date_from, date_to, tab, vendedor_filter=None, tipo_venda_filter=None,
                     ct_completo=True, ct_indicador=None, ct_contab=None):
    """Tabela Detalhamento (full): um registro por negócio do escopo da aba.

    Colunas: bitrix_id, link_deal (URL do card no Bitrix), cliente
    (empresa | nome_da_empresa por aba), vendedor, tipo_venda ('interno'|'indicado'
    — chave; a UI mapeia p/ 'Interno'/'Indicado'), etapa, tipo_de_contrato, valor.

    Filtros OPCIONAIS (cross-filter — o servidor sabe filtrar, mas a UI hoje
    aplica o filtro no cliente para evitar novo round-trip por clique):
      - vendedor_filter:    responsavel_pela_execucao normalizado (exato)
      - tipo_venda_filter:  'interno' ou 'indicado'
    """
    if tab not in ETAPAS:
        tab = "fechadas"
    negocio_col = NEGOCIO_COL[tab]
    where, params = _base(tab, date_from, date_to, ct_completo, ct_indicador, ct_contab)

    extra = ""
    if vendedor_filter:
        extra += (" AND COALESCE(NULLIF(TRIM(t.responsavel_pela_execucao), ''), "
                  "'(Sem responsável)') = %(vendf)s")
        params["vendf"] = vendedor_filter
    if tipo_venda_filter == "interno":
        extra += f" AND {EH_PROPRIA}"
    elif tipo_venda_filter == "indicado":
        extra += f" AND {EH_INDICADA}"

    sql = f"""
        SELECT
            t.bitrix_id                                                  AS bitrix_id,
            'https://gnapp.bitrix24.com.br/page/contabilidade/contabilidade/type/191/details/'
                || t.bitrix_id || '/'                                    AS link_deal,
            COALESCE(NULLIF(TRIM(t.{negocio_col}), ''), '—')             AS cliente,
            COALESCE(NULLIF(TRIM(t.responsavel_pela_execucao), ''), '(Sem responsável)') AS vendedor,
            CASE WHEN {EH_PROPRIA} THEN 'interno' ELSE 'indicado' END    AS tipo_venda,
            COALESCE(NULLIF(TRIM(t.etapa), ''), '—')                     AS etapa,
            COALESCE(NULLIF(TRIM(t.tipo_de_contrato), ''), '(Sem tipo)') AS tipo_de_contrato,
            {CONTAB_GRUPO_CASE}                                          AS contab_grupo,
            -- Parceiro indicador (Dashboard → card "Parceiros Indicadores")
            NULLIF(TRIM(t.parceiro_indicacao), '')                       AS parceiro_indicacao,
            -- Dias em negociação (só usado na aba "Em Negociação"): TODAY - criado_em
            (CURRENT_DATE - t.criado_em::date)                           AS dias_negociacao,
            {VALOR}                                                      AS valor
        FROM tbl_onboard t
        WHERE {where}{extra}
        ORDER BY {VALOR} DESC, t.bitrix_id DESC
    """
    return fetch_all(sql, params)


# ── Agregador — roda todas as visões de uma aba de uma vez ───────────────────
def get_aba(aba="fechadas", data_de=None, data_ate=None,
            ct_completo=True, ct_indicador=None, ct_contab=None):
    """Carrega Bloco 1 (KPIs), Bloco 2 (vendedores + indicadas para expansão) e o
    conjunto `detalhe` (todos os negócios da aba/período) para a aba pedida
    ('fechadas' | 'negociacao'). A UI deriva, no cliente, a tabela por tipo de
    contrato e a tabela Detalhamento a partir de `detalhe` (cross-filter sem novo
    round-trip). A MESMA lógica serve às duas abas — só muda o conjunto de etapas.

    ct_completo/ct_indicador/ct_contab: filtros do Portal BI (headers X-CT-*);
    usuário interno (sem headers) usa os defaults → sem filtro (vê tudo)."""
    if aba not in ETAPAS:
        aba = "fechadas"
    ct = {"ct_completo": ct_completo, "ct_indicador": ct_indicador, "ct_contab": ct_contab}
    return {
        "kpis":       get_kpis(aba, data_de, data_ate, **ct),
        "vendedores": get_vendedores(aba, data_de, data_ate, **ct),
        "indicadas":  get_indicadas(aba, data_de, data_ate, **ct),
        "detalhe":    get_detalhamento(data_de, data_ate, aba, **ct),
    }
