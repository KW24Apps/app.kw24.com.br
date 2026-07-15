<?php
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../dao/ConfiguracaoDAO.php';
require_once __DIR__ . '/../services/BitrixService.php';

/**
 * CRUD dos webhooks Bitrix24 pessoais (escopo im, um por colaborador) usados pelo painel
 * Atendimento pra agregar conversas de vários operadores — im.recent.list só retorna o que o
 * DONO do webhook participa (confirmado em pesquisa anterior), então um webhook fixo só (o de
 * automação) nunca via as conversas atendidas direto por Gabriel/Jeferson/Tainá/Michael.
 *
 * Armazenamento: um único JSON em configuracoes_sistema (mesmo padrão de
 * monitoramento_equipe_token/financeiro_webhook_bitrix) — sem tabela nova, sem migration.
 *
 * Sensibilidade: listar() retorna a URL completa em texto puro — só pra uso INTERNO do
 * backend (ex.: MonitoramentoAtendimentoService chamando im.recent.list com cada webhook).
 * Nunca serializar o retorno de listar() direto numa resposta HTTP. listarMascarado() é o que
 * a UI de configuração deve usar — mesmo nível de sensibilidade já dado ao webhook principal
 * documentado em ACESSOS.md.
 */
class WebhooksPessoaisAtendimento {
    private const CHAVE = 'monitoramento_atendimento_webhooks';

    private ConfiguracaoDAO $dao;

    public function __construct() {
        $this->dao = new ConfiguracaoDAO();
    }

    /** Texto puro — só uso interno do backend, nunca expor em resposta HTTP. */
    public function listar(): array {
        $json = $this->dao->get(self::CHAVE);
        if ($json === null || $json === '') return [];
        $lista = json_decode($json, true);
        return is_array($lista) ? $lista : [];
    }

    /** Pra UI — id, nome e URL mascarada (nunca o valor completo). */
    public function listarMascarado(): array {
        return array_map(function ($p) {
            return [
                'id'               => $p['id']   ?? '',
                'nome'             => $p['nome'] ?? '',
                'webhookMascarado' => self::mascarar($p['webhookUrl'] ?? ''),
            ];
        }, $this->listar());
    }

    /**
     * Busca o nome real da conta Bitrix24 dona desse webhook, via user.current — nome digitado
     * a mão não tem nenhuma garantia de bater com o webhook colado (ex.: colar o webhook do
     * Jeferson mas digitar "Gabriel" por engano); usar user.current elimina esse risco E valida
     * de cara que o webhook funciona (escopo "im" mínimo já cobre esse método). Chamada tanto
     * pelo preenchimento automático no modal (ação 'validar') quanto, de novo, no momento de
     * salvar (ações 'adicionar'/'editar' em api/monitoramento-webhooks-pessoais.php) — cobre o
     * caso de alguém pular a validação do modal e enviar direto pra API.
     */
    public function buscarNomeConta(string $webhookUrl): array {
        $bitrix = new BitrixService($webhookUrl);
        if (!$bitrix->isConfigured()) {
            return ['sucesso' => false, 'erro' => 'Webhook inválido ou incompleto'];
        }

        $usuario = $bitrix->call('user.current');
        if ($usuario === null) {
            return ['sucesso' => false, 'erro' => 'Não foi possível validar esse webhook no Bitrix24 — confira a URL'];
        }

        $nome = trim(($usuario['NAME'] ?? '') . ' ' . ($usuario['LAST_NAME'] ?? ''));
        if ($nome === '') $nome = 'Usuário #' . ($usuario['ID'] ?? '?');
        return ['sucesso' => true, 'nome' => $nome, 'bitrixUserId' => (int)($usuario['ID'] ?? 0)];
    }

    /** bitrixUserId (vindo de buscarNomeConta(), ver acima) permite achar o webhook de uma
     *  pessoa a partir do seu ID Bitrix24 — usado por MonitoramentoChamadosService pra decidir
     *  qual identidade usar ao buscar mensagens de chat de um card (ver resolverWebhookPorUid()). */
    public function adicionar(string $nome, string $webhookUrl, int $bitrixUserId = 0): void {
        $lista = $this->listar();
        $lista[] = [
            'id'           => uniqid('pw_', true),
            'nome'         => $nome,
            'webhookUrl'   => $webhookUrl,
            'bitrixUserId' => $bitrixUserId,
        ];
        $this->salvar($lista);
    }

    /** $webhookUrl null = mantém a URL (e o bitrixUserId) já salvos — edição só do nome. */
    public function editar(string $id, string $nome, ?string $webhookUrl, int $bitrixUserId = 0): void {
        $lista = $this->listar();
        foreach ($lista as &$p) {
            if (($p['id'] ?? null) === $id) {
                $p['nome'] = $nome;
                if ($webhookUrl !== null) {
                    $p['webhookUrl']   = $webhookUrl;
                    $p['bitrixUserId'] = $bitrixUserId;
                }
            }
        }
        unset($p);
        $this->salvar($lista);
    }

    /** [bitrixUserId => webhookUrl] — só entradas com bitrixUserId confirmado (>0). Usado por
     *  MonitoramentoChamadosService pra escolher, por chamado, a identidade certa pra ler o
     *  chat vinculado ao card (ver getDados() lá — evita ACCESS_ERROR do webhook de automação). */
    public function mapaWebhookPorUid(): array {
        $mapa = [];
        foreach ($this->listar() as $p) {
            $uid = (int)($p['bitrixUserId'] ?? 0);
            if ($uid > 0 && !empty($p['webhookUrl'])) $mapa[$uid] = $p['webhookUrl'];
        }
        return $mapa;
    }

    public function remover(string $id): void {
        $lista = array_values(array_filter(
            $this->listar(),
            fn($p) => ($p['id'] ?? null) !== $id
        ));
        $this->salvar($lista);
    }

    private function salvar(array $lista): void {
        $this->dao->set(self::CHAVE, json_encode($lista, JSON_UNESCAPED_UNICODE));
    }

    /** "https://portal.bitrix24.com.br/rest/21/TOKEN/" -> mantém domínio+userId, mascara o
     *  token exceto os últimos 4 caracteres. */
    private static function mascarar(string $url): string {
        if ($url === '') return '';
        $comBarra = rtrim($url, '/') . '/';
        return preg_replace_callback(
            '#(/rest/\d+/)([a-z0-9]+)(/)$#i',
            function ($m) {
                $token = $m[2];
                $tail  = strlen($token) > 4 ? substr($token, -4) : $token;
                $head  = strlen($token) > 4 ? str_repeat('*', strlen($token) - 4) : '';
                return $m[1] . $head . $tail . $m[3];
            },
            $comBarra
        );
    }
}
