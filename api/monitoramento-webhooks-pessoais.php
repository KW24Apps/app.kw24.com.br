<?php
/**
 * CRUD (sessão PHP, admin_interno) dos webhooks Bitrix24 pessoais usados pelo painel
 * Atendimento — ver services/WebhooksPessoaisAtendimento.php. Nunca retorna a URL completa,
 * só a versão mascarada (listarMascarado()).
 */
session_start();
require_once __DIR__ . '/../services/AuthenticationService.php';
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../dao/ConfiguracaoDAO.php';
require_once __DIR__ . '/../services/WebhooksPessoaisAtendimento.php';

header('Content-Type: application/json');

$auth = new AuthenticationService();
if (!$auth->validateSession()) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$user = $auth->getCurrentUser();
if (!$user || ($user['perfil'] ?? '') !== 'admin_interno') {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso negado']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$acao   = $body['acao'] ?? 'listar';
$service = new WebhooksPessoaisAtendimento();

try {
    switch ($acao) {
        case 'listar':
            echo json_encode(['sucesso' => true, 'pessoas' => $service->listarMascarado()]);
            break;

        // Preenchimento automático do nome no modal (ver monValidarWebhookPessoal() no
        // frontend) — chamada ao colar/sair do campo de URL, antes de salvar. Não persiste
        // nada, só valida o webhook e devolve o nome real da conta pra pré-preencher o campo
        // (que continua editável depois).
        case 'validar':
            $url = trim((string)($body['webhookUrl'] ?? ''));
            if ($url === '' || strpos($url, 'https://') !== 0) {
                echo json_encode(['erro' => 'Webhook deve começar com https://']);
                break;
            }
            $validacao = $service->buscarNomeConta($url);
            echo $validacao['sucesso']
                ? json_encode(['sucesso' => true, 'nome' => $validacao['nome']])
                : json_encode(['erro' => $validacao['erro']]);
            break;

        case 'adicionar':
            $nome = trim((string)($body['nome'] ?? ''));
            $url  = trim((string)($body['webhookUrl'] ?? ''));
            if ($nome === '' || $url === '') {
                echo json_encode(['erro' => 'Nome e webhook são obrigatórios']);
                break;
            }
            if (strpos($url, 'https://') !== 0) {
                echo json_encode(['erro' => 'Webhook deve começar com https://']);
                break;
            }
            // Revalida aqui (não só no modal) — cobre quem pular a validação ao vivo e
            // garante que nunca é salvo um webhook que não funciona. bitrixUserId vem dessa
            // mesma chamada (user.current) — ver WebhooksPessoaisAtendimento::mapaWebhookPorUid().
            $validacao = $service->buscarNomeConta($url);
            if (!$validacao['sucesso']) {
                echo json_encode(['erro' => $validacao['erro']]);
                break;
            }
            $service->adicionar($nome, $url, $validacao['bitrixUserId'] ?? 0);
            echo json_encode(['sucesso' => true, 'pessoas' => $service->listarMascarado()]);
            break;

        case 'editar':
            $id   = trim((string)($body['id'] ?? ''));
            $nome = trim((string)($body['nome'] ?? ''));
            $url  = trim((string)($body['webhookUrl'] ?? ''));
            if ($id === '' || $nome === '') {
                echo json_encode(['erro' => 'Id e nome são obrigatórios']);
                break;
            }
            $bitrixUserId = 0;
            if ($url !== '') {
                if (strpos($url, 'https://') !== 0) {
                    echo json_encode(['erro' => 'Webhook deve começar com https://']);
                    break;
                }
                // Só revalida quando uma URL nova é de fato enviada — editar só o nome,
                // mantendo o webhook (e bitrixUserId) já salvos, não precisa revalidar.
                $validacao = $service->buscarNomeConta($url);
                if (!$validacao['sucesso']) {
                    echo json_encode(['erro' => $validacao['erro']]);
                    break;
                }
                $bitrixUserId = $validacao['bitrixUserId'] ?? 0;
            }
            $service->editar($id, $nome, $url !== '' ? $url : null, $bitrixUserId);
            echo json_encode(['sucesso' => true, 'pessoas' => $service->listarMascarado()]);
            break;

        case 'remover':
            $id = trim((string)($body['id'] ?? ''));
            if ($id === '') {
                echo json_encode(['erro' => 'Id é obrigatório']);
                break;
            }
            $service->remover($id);
            echo json_encode(['sucesso' => true, 'pessoas' => $service->listarMascarado()]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['erro' => 'Ação desconhecida']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
