<?php
/**
 * Cron: purga automática da Lixeira de Relatórios BI — retenção de 30 dias. Relatório
 * movido pra lixeira há mais de 30 dias é excluído definitivamente automaticamente,
 * mesma cascata do botão manual "Excluir definitivamente" (schema Excel/credencial SQL,
 * acesso, portais, tentativa best-effort de limpar campos Bitrix). O botão manual
 * continua existindo pra quem não quiser esperar os 30 dias.
 *
 * Instalar no servidor:
 *   crontab -e
 *   0 3 * * * php /var/www/app.kw24.com.br/crons/lixeira-purge.php >> /var/log/kw24-lixeira-purge.log 2>&1
 *
 * Execução manual:
 *   php /var/www/app.kw24.com.br/crons/lixeira-purge.php
 */

define('SYSTEM_ACCESS', true);

require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../helpers/RelatoriosBiHelper.php';

$inicio = date('Y-m-d H:i:s');
echo "[{$inicio}] Iniciando purga automática da Lixeira (Relatórios BI, retenção 30 dias)...\n";

$db = Database::getInstance();
$expirados = $db->fetchAll(
    "SELECT id, slug, nome_amigavel FROM relatorios_bi
      WHERE lixeira_em IS NOT NULL AND lixeira_em < NOW() - INTERVAL '30 days'"
);

if (!$expirados) {
    echo "Nenhum relatório expirado na lixeira.\n";
    exit(0);
}

$exitCode = 0;
foreach ($expirados as $r) {
    echo "Excluindo definitivamente: {$r['nome_amigavel']} (slug={$r['slug']}, id={$r['id']})...\n";
    try {
        $res = excluirRelatorioDefinitivamente($db, $r);
        if ($res['sucesso']) {
            echo "  OK.\n";
            foreach (($res['bitrix_limpeza'] ?? []) as $b) {
                $status = isset($b['erro']) ? ('FALHOU (' . $b['erro'] . ')') : 'ok';
                echo '  Bitrix cleanup portal ' . $b['portal_slug'] . ': ' . $status . "\n";
            }
            if (!($res['nginx_ok'] ?? true)) {
                echo "  AVISO: falha ao regenerar o map do nginx após a exclusão.\n";
            }
        } else {
            echo '  ERRO: ' . ($res['erro'] ?? 'desconhecido') . "\n";
            $exitCode = 1;
        }
    } catch (Exception $e) {
        echo '  ERRO FATAL: ' . $e->getMessage() . "\n";
        $exitCode = 1;
    }
}

echo "[" . date('H:i:s') . "] Concluído.\n";
exit($exitCode);
