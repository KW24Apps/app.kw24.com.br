<?php

/**
 * Catálogo único do campo "Tipo de Chamado" (ufCrm41_1737476320, SPA 1054) — fonte única de
 * verdade pra qualquer código que precise saber "que tipo de chamado é esse": rótulo, cor, ou
 * categoria (Suporte/Desenvolvimento/Projeto/Outros).
 *
 * Consumido por MonitoramentoChamadosService (rótulo/cor do badge + pills de filtro do painel
 * Chamados abertos) e MonitoramentoEquipeService (classificação Suporte/Desenvolvimento do
 * painel Equipe, tanto "em andamento" quanto "finalizado no ciclo").
 *
 * Motivo de existir: antes cada consumidor tinha seu próprio mapa parcial — o Equipe só
 * conhecia Suporte/Dev via TIPOS_SUPORTE=[21204,21206]/TIPOS_DEV=[21208,21210], sem incluir
 * Desenvolvimento-Correção (24458) na categoria Dev. Isso fazia cards desse tipo desaparecerem
 * silenciosamente das contagens de Suporte/Dev do Equipe (mesmo mecanismo que corretamente
 * exclui INFRA/Cobrança/Orçamento, só que por engano também pegava 24458, que deveria contar
 * como Desenvolvimento). Consolidado aqui pra nunca mais divergir entre painéis.
 *
 * Escopo: só o módulo "Monitoramento KW24" (Chamados abertos/Equipe/Funil). O módulo Financeiro
 * (services/FinanceiroSync.php, api/financeiro-webhook.php, api/relatorios-cards.php) tem seus
 * próprios mapas TIPOS_SUPORTE/TIPOS_DEV com a mesma lacuna do 24458 — não tocados aqui
 * (fora do escopo desta tarefa; mexer em cálculo de faturamento é uma mudança de risco maior
 * e não foi pedida).
 */
class TipoChamadoCatalogo {
    public const CATEGORIA_SUPORTE         = 'suporte';
    public const CATEGORIA_DESENVOLVIMENTO = 'desenvolvimento';
    public const CATEGORIA_PROJETO         = 'projeto';
    public const CATEGORIA_OUTROS          = 'outros';

    /**
     * Tipo (valor do campo ufCrm41_1737476320) => dados de exibição + categoria.
     * 'pill' controla só a apresentação do filtro de Chamados abertos: quem tem pill=false
     * não ganha pill própria — cai no catch-all "Outros" (junto com qualquer tipo futuro fora
     * deste catálogo, ver paraPills()). Não afeta 'categoria' — Desenvolvimento - Correção
     * (24458) continua contando como Desenvolvimento na classificação do painel Equipe, só não
     * tem pill dedicada (Gabriel pediu 6 pills no total, não 1 por tipo).
     */
    private const CATALOGO = [
        21204 => ['categoria' => self::CATEGORIA_SUPORTE,         'label' => 'Suporte Bitrix24',                'pillLabel' => 'Bitrix24',            'cor' => '#0DC2FF', 'pill' => true],
        21206 => ['categoria' => self::CATEGORIA_SUPORTE,         'label' => 'Suporte Técnico',                  'pillLabel' => 'Suporte técnico',    'cor' => '#0DC2FF', 'pill' => true],
        21208 => ['categoria' => self::CATEGORIA_DESENVOLVIMENTO, 'label' => 'Desenvolvimento - Melhoria',       'pillLabel' => 'Dev · Melhoria',      'cor' => '#b794f4', 'pill' => true],
        21210 => ['categoria' => self::CATEGORIA_DESENVOLVIMENTO, 'label' => 'Desenvolvimento - Implementação', 'pillLabel' => 'Dev · Implementação', 'cor' => '#b794f4', 'pill' => true],
        24458 => ['categoria' => self::CATEGORIA_DESENVOLVIMENTO, 'label' => 'Desenvolvimento - Correção',       'pillLabel' => 'Dev · Correção',      'cor' => '#b794f4', 'pill' => false],
        28354 => ['categoria' => self::CATEGORIA_PROJETO,         'label' => 'Projeto',                          'pillLabel' => 'Projeto',             'cor' => '#26FF93', 'pill' => true],
        21216 => ['categoria' => self::CATEGORIA_OUTROS,          'label' => 'INFRA',                            'pillLabel' => 'INFRA',               'cor' => '#f6ad55', 'pill' => false],
        23322 => ['categoria' => self::CATEGORIA_OUTROS,          'label' => 'Cobrança',                         'pillLabel' => 'Cobrança',            'cor' => '#fc8181', 'pill' => false],
        24456 => ['categoria' => self::CATEGORIA_OUTROS,          'label' => 'Orçamento',                        'pillLabel' => 'Orçamento',           'cor' => '#a0aec0', 'pill' => false],
    ];

    public static function categoria(int $tipo): string {
        return self::CATALOGO[$tipo]['categoria'] ?? self::CATEGORIA_OUTROS;
    }

    public static function label(int $tipo): string {
        return self::CATALOGO[$tipo]['label'] ?? ('Tipo #' . $tipo);
    }

    public static function cor(int $tipo): string {
        return self::CATALOGO[$tipo]['cor'] ?? '#a0aec0';
    }

    /** Todos os tipos conhecidos — pra filtro/select do crm.item.list sem esquecer nenhum. */
    public static function todosOsTipos(): array {
        return array_keys(self::CATALOGO);
    }

    /**
     * Estrutura pronta pras pills de filtro de Tipo do painel Chamados abertos — só os tipos
     * com pill=true (5 hoje: os 2 de Suporte, os 2 de Dev "principais" e Projeto) ganham pill
     * própria, ativoPadrao=true pra Suporte/Desenvolvimento e false pra Projeto. Qualquer tipo
     * com pill=false (Dev · Correção, INFRA, Cobrança, Orçamento) — e qualquer tipo futuro fora
     * deste catálogo inteiro — cai no catch-all "Outros", construído no frontend (não incluído
     * aqui, ver chaChaveTipo()/chaRenderFiltroTipos() em monitoramento.php): 5 pills nomeadas
     * retornadas + 1 "Outros" montada no cliente = 6 no total.
     */
    public static function paraPills(): array {
        $pills = [];
        foreach (self::CATALOGO as $tipo => $info) {
            if (!$info['pill']) continue;
            $pills[] = [
                'tipo'        => $tipo,
                'label'       => $info['pillLabel'],
                'ativoPadrao' => in_array(
                    $info['categoria'],
                    [self::CATEGORIA_SUPORTE, self::CATEGORIA_DESENVOLVIMENTO],
                    true
                ),
            ];
        }
        return $pills;
    }
}
