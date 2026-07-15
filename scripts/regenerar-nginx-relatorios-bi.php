<?php
/**
 * Regenera o map nginx slug→porta interna do Gunicorn para todos os relatórios
 * cadastrados em relatorios_bi e recarrega o nginx. Porta = 8100 + relatorio_id
 * (fórmula determinística, sem coluna nova no banco — ver ESTRUTURA_RELATORIOS_BI.md).
 *
 * Rodar sempre que um relatório for criado (Etapa 2 do self-service deve chamar
 * regenerarMapNginxRelatoriosBi() logo após o INSERT em relatorios_bi — nenhum
 * outro fluxo hoje cria/altera id ou slug, que são imutáveis).
 *
 * Uso direto (SSH, no servidor): php scripts/regenerar-nginx-relatorios-bi.php
 */
define('SYSTEM_ACCESS', true);
require_once __DIR__ . '/../helpers/Database.php';

function portaRelatorioBi(int $relatorioId): int {
    return 8100 + $relatorioId;
}

/**
 * @return array{sucesso:bool, erro?:string, relatorios?:array}
 */
function regenerarMapNginxRelatoriosBi(): array {
    $db   = Database::getInstance();
    $rows = $db->fetchAll('SELECT id, slug FROM relatorios_bi ORDER BY id');

    $linhas = ["# Gerado automaticamente por scripts/regenerar-nginx-relatorios-bi.php — NÃO editar à mão.",
               "# slug => porta interna do Gunicorn (8100 + relatorios_bi.id)"];
    foreach ($rows as $r) {
        $linhas[] = $r['slug'] . ' ' . portaRelatorioBi((int)$r['id']) . ';';
    }
    $conteudo = implode("\n", $linhas) . "\n";

    $tmpPath = tempnam(sys_get_temp_dir(), 'rbimap');
    file_put_contents($tmpPath, $conteudo);

    // sudo tee/nginx/systemctl são NOPASSWD para o usuário kw24 (mesmo padrão já
    // documentado em stack_deploy.md) — nenhuma regra de sudo nova é necessária.
    exec('sudo tee /etc/nginx/conf.d/relatorios_bi_ports.map < ' . escapeshellarg($tmpPath) . ' > /dev/null 2>&1', $outTee, $rcTee);
    @unlink($tmpPath);
    if ($rcTee !== 0) {
        return ['sucesso' => false, 'erro' => 'Falha ao gravar o map via sudo tee: ' . implode("\n", $outTee)];
    }

    exec('sudo nginx -t 2>&1', $outTest, $rcTest);
    if ($rcTest !== 0) {
        return ['sucesso' => false, 'erro' => "nginx -t falhou:\n" . implode("\n", $outTest)];
    }

    exec('sudo systemctl reload nginx 2>&1', $outReload, $rcReload);
    if ($rcReload !== 0) {
        return ['sucesso' => false, 'erro' => 'systemctl reload nginx falhou: ' . implode("\n", $outReload)];
    }

    return ['sucesso' => true, 'relatorios' => $rows];
}

// Execução direta via CLI (não quando incluído por outro script/endpoint).
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $res = regenerarMapNginxRelatoriosBi();
    if ($res['sucesso']) {
        echo "OK — map regenerado e nginx recarregado.\n";
        foreach ($res['relatorios'] as $r) {
            echo "  {$r['slug']} (id={$r['id']}) -> porta " . portaRelatorioBi((int)$r['id']) . "\n";
        }
    } else {
        fwrite(STDERR, "ERRO: {$res['erro']}\n");
        exit(1);
    }
}
