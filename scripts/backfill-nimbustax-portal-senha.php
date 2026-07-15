<?php
/**
 * ONE-OFF — backfill de senha para portais BI NimbusTax já existentes.
 *
 * Contexto: NimbusTaxPortalSync (services/NimbusTaxPortalSync.php) só sincroniza os campos
 * UF_CRM_1784116631/UF_CRM_1784116775 da Company Bitrix24 a partir de agora (create / regenerar
 * senha). Portais criados antes disso nunca dispararam esse sync, e a senha em texto puro deles
 * não pode ser recuperada (portais_bi só guarda senha_hash bcrypt). Este script fecha essa lacuna
 * de uma vez: define a senha de cada portal qualificado como o próprio companyId (decisão do
 * Usuário — ver risco abaixo) e reaproveita NimbusTaxPortalSync::sync() para escrever os campos
 * na Company correspondente, exatamente como create/update fariam.
 *
 * Qualifica: relatorio_slug='relatorio-parceiros-tax' AND filter_type='parceiro' AND
 * jsonb_array_length(filter_values)=1 AND ativo=TRUE. Não toca slug nem embed_token — o link
 * público do portal permanece o mesmo de hoje.
 *
 * RISCO (decisão explícita do Usuário, não decidida por este script): usar o companyId como
 * senha em texto puro é previsível/adivinhável por quem conhece o companyId.
 *
 * Uso:
 *   php scripts/backfill-nimbustax-portal-senha.php            → dry-run (só lista, nada é alterado)
 *   php scripts/backfill-nimbustax-portal-senha.php --apply    → executa de verdade
 *
 * Idempotente: rodar de novo apenas redefine a mesma senha (= companyId) e reenvia o mesmo sync —
 * seguro executar mais de uma vez por engano.
 */
define('SYSTEM_ACCESS', true);
require_once __DIR__ . '/../helpers/Database.php';
require_once __DIR__ . '/../services/NimbusTaxPortalSync.php';

$apply = in_array('--apply', $argv, true);

$pdo = Database::getInstance()->getConnection();

$stmt = $pdo->query(
    "SELECT id, relatorio_slug, filter_type, filter_values, slug, nome, ativo
       FROM portais_bi
      WHERE relatorio_slug = 'relatorio-parceiros-tax'
        AND filter_type = 'parceiro'
        AND ativo = TRUE
        AND jsonb_array_length(filter_values) = 1
      ORDER BY id"
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nenhum portal NimbusTax qualificado encontrado (relatorio-parceiros-tax, parceiro, 1 valor, ativo).\n";
    exit(0);
}

echo "Portais que " . ($apply ? "SERÃO" : "SERIAM") . " alterados (" . count($rows) . "):\n";
foreach ($rows as $r) {
    $values    = json_decode($r['filter_values'], true) ?? [];
    $companyId = $values[0] ?? null;
    echo "  - id={$r['id']} slug={$r['slug']} companyId=" . ($companyId ?? '(vazio)') . " nome=" . ($r['nome'] ?? '') . "\n";
}

if (!$apply) {
    echo "\nDry-run — nada foi alterado. Rode com --apply para executar de verdade.\n";
    exit(0);
}

echo "\nAplicando...\n";
foreach ($rows as $r) {
    $values    = json_decode($r['filter_values'], true) ?? [];
    $companyId = $values[0] ?? null;

    if ($companyId === null || !is_numeric($companyId)) {
        echo "  [PULADO] id={$r['id']} slug={$r['slug']} — filter_values sem companyId numérico válido\n";
        continue;
    }

    $novaSenha = (string)$companyId;
    $senhaHash = password_hash($novaSenha, PASSWORD_BCRYPT);
    $pdo->prepare('UPDATE portais_bi SET senha_hash=? WHERE id=?')->execute([$senhaHash, $r['id']]);

    // Nada além da senha muda — old e new são o mesmo estado, só a senha em texto puro é nova.
    $rowForSync = [
        'relatorio_slug' => $r['relatorio_slug'],
        'filter_type'    => $r['filter_type'],
        'filter_values'  => $values,
        'slug'           => $r['slug'],
        'ativo'          => (bool)$r['ativo'],
    ];
    NimbusTaxPortalSync::sync($rowForSync, $rowForSync, $novaSenha);

    echo "  [OK] id={$r['id']} slug={$r['slug']} companyId={$companyId} — senha=companyId, sync Bitrix disparado (ver error_log para status write/clear)\n";
}
echo "\nConcluído.\n";
