<?php
/**
 * Endpoint consolidado somente-leitura para consumo de máquina (ex.: assistente "Secretária"
 * externa) — combina os 5 blocos do Monitoramento KW24 (Equipe, Chamados abertos, Tarefas,
 * Funil, Atendimento) numa única resposta, pra não precisar de 5 chamadas HTTP separadas.
 * Mesma autenticação por token estático dos outros endpoints de máquina (NÃO depende de sessão
 * PHP) — reusa configuracoes_sistema.monitoramento_equipe_token, mesmo consumidor autorizado.
 *
 * Reaproveita os mesmos services já usados pelos endpoints individuais
 * (monitoramento-equipe.php, monitoramento-chamados.php etc.) — nenhuma consulta duplicada
 * aqui, só composição do resultado de cada um. Os 5 endpoints individuais continuam existindo
 * sem mudança, pra quem já os consome separadamente.
 */
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../dao/ConfiguracaoDAO.php';
require_once __DIR__ . '/../services/MonitoramentoEquipeService.php';
require_once __DIR__ . '/../services/MonitoramentoChamadosService.php';
require_once __DIR__ . '/../services/MonitoramentoTarefasService.php';
require_once __DIR__ . '/../services/MonitoramentoFunilService.php';
require_once __DIR__ . '/../services/MonitoramentoAtendimentoService.php';

header('Content-Type: application/json');

$dao           = new ConfiguracaoDAO();
$tokenEsperado = $dao->get('monitoramento_equipe_token') ?? '';
$tokenRecebido = $_SERVER['HTTP_X_PAINEL_TOKEN'] ?? '';

if ($tokenEsperado === '' || $tokenRecebido === '' || !hash_equals($tokenEsperado, $tokenRecebido)) {
    http_response_code(401);
    echo json_encode(['erro' => 'Token inválido ou ausente']);
    exit;
}

/** Executa getDados() de um service, isolando falha de um bloco sem derrubar os outros —
 *  se o Bitrix24 estiver indisponível ou um bloco específico der erro, os outros 4 ainda
 *  respondem normalmente. */
function monBlocoResumo(callable $factory): array {
    try {
        $service = $factory();
        if (!$service->isConfigured()) {
            return ['erro' => 'Webhook Bitrix24 não configurado'];
        }
        return array_merge(['sucesso' => true], $service->getDados());
    } catch (Exception $e) {
        return ['erro' => $e->getMessage()];
    }
}

echo json_encode([
    'sucesso'          => true,
    'equipe'           => monBlocoResumo(fn() => new MonitoramentoEquipeService()),
    'chamados_abertos' => monBlocoResumo(fn() => new MonitoramentoChamadosService()),
    'tarefas'          => monBlocoResumo(fn() => new MonitoramentoTarefasService()),
    'funil'            => monBlocoResumo(fn() => new MonitoramentoFunilService()),
    'atendimento'      => monBlocoResumo(fn() => new MonitoramentoAtendimentoService()),
]);
