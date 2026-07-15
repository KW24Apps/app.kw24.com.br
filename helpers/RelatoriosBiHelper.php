<?php
/**
 * Funções compartilhadas do módulo Relatórios BI — usadas por api/relatorio-conexao.php
 * (editar conexão de um relatório existente) e api/relatorio-criar.php (cadastrar um
 * relatório novo, Etapa 2). Ver RELATORIOS_BI.md para o desenho completo do módulo.
 */

// Tipos de conexão suportados hoje; 'webhook' reservado para o futuro (desabilitado na UI).
const RBI_TIPOS_CONEXAO_HABILITADOS = ['sql', 'excel'];

/**
 * Testa uma conexão Postgres com as credenciais informadas. Nunca lança —
 * retorna [true, null] em sucesso ou [false, mensagem] em falha.
 */
function testarConexaoSql(array $cfg): array {
    $host = trim($cfg['host'] ?? '');
    $port = trim((string)($cfg['port'] ?? '5432'));
    $dbname = trim($cfg['dbname'] ?? '');
    $dbUser = trim($cfg['user'] ?? '');
    $dbPass = (string)($cfg['password'] ?? '');

    if ($host === '' || $dbname === '' || $dbUser === '') {
        return [false, 'Host, banco e usuário são obrigatórios'];
    }

    try {
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};connect_timeout=5";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->query('SELECT 1');
        return [true, null];
    } catch (PDOException $e) {
        // Mensagem do driver pode conter a senha no DSN de erro em alguns casos — usar só getMessage()
        // do PDOException (não expõe params) e nunca logar $cfg completo.
        return [false, 'Falha ao conectar: ' . $e->getMessage()];
    }
}

/**
 * Caminho do relatório no filesystem — slug é imutável e igual ao nome da pasta (ver RELATORIOS_BI.md).
 */
function pastaRelatorio(string $slug): string {
    return __DIR__ . '/../relatorios-bi/' . $slug;
}

/**
 * Porta interna do Gunicorn — determinística (8100 + relatorio_id), nunca gravada
 * no banco. Mesma fórmula usada por scripts/regenerar-nginx-relatorios-bi.php para
 * o map do nginx. Ver RELATORIOS_BI.md.
 */
function portaRelatorio(int $relatorioId): int {
    return 8100 + $relatorioId;
}

/**
 * Bloco somente-leitura "Infraestrutura" da aba Conexão — pasta/serviço/porta
 * computados a partir de slug/id, válidos mesmo antes de o app Python existir.
 */
function infraestruturaRelatorio(int $relatorioId, string $slug): array {
    return [
        'pasta'   => 'relatorios-bi/' . $slug,
        'servico' => 'kw24-relatorio-' . $slug . '.service',
        'porta'   => portaRelatorio($relatorioId),
    ];
}

/**
 * Grava (ou remove) o arquivo local de config que o processo Python (db.py) lê no lugar do .env.
 * Permissão restrita (0600) — mesmo usuário (kw24) roda PHP-FPM e o Gunicorn dos relatórios.
 */
function escreverDbConfigJson(string $slug, array $cfg): bool {
    $dir = pastaRelatorio($slug);
    if (!is_dir($dir)) return false;
    $path = $dir . '/.dbconfig.json';
    $ok = @file_put_contents($path, json_encode($cfg, JSON_PRETTY_PRINT)) !== false;
    if ($ok) @chmod($path, 0600);
    return $ok;
}

/**
 * Homóglifos cirílicos comuns (visualmente idênticos a letras latinas) — comum em
 * exports de ferramentas de terceiros (ex.: Bitrix Contact Center) que acabam
 * misturando alfabetos por engano. Sem este mapa, iconv TRANSLIT//IGNORE
 * simplesmente DESCARTA esses caracteres (não tem equivalente ASCII direto),
 * o que já causou um cabeçalho real "Сolaborador" (С cirílico, U+0421) virar
 * "olaborador" — perdendo a primeira letra silenciosamente. Mapeado ANTES do
 * iconv pra virar a letra latina óbvia em vez de sumir.
 */
const RBI_HOMOGLIFOS_CIRILICOS = [
    'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
    'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'У' => 'Y', 'Х' => 'X',
    'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x',
];

/**
 * Slugify — usado tanto para sugerir o slug do relatório a partir do nome amigável
 * quanto (aplicado a cada tabela) para o nome real de tabela Excel. Minúsculo, sem
 * acento, alfanumérico com hífen/underscore como separador único, sem hífen/underscore
 * nas pontas.
 */
function slugify(string $texto, string $separador = '-'): string {
    $t = trim($texto);
    $t = strtr($t, RBI_HOMOGLIFOS_CIRILICOS);
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    if ($translit !== false) $t = $translit;
    $t = strtolower($t);
    $t = preg_replace('/[^a-z0-9]+/', $separador, $t);
    $t = trim($t, $separador);
    $t = preg_replace('/' . preg_quote($separador, '/') . '{2,}/', $separador, $t);
    return $t;
}

/**
 * Formato válido de slug de relatório: minúsculo, alfanumérico, hífen único como
 * separador (mesmo padrão dos 2 relatórios existentes — ex. relatorio-parceiros-tax).
 */
function slugFormatoValido(string $slug): bool {
    return (bool) preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug);
}

/**
 * true se o slug já existe em relatorios_bi (qualquer linha, inclusive em_construcao).
 */
function slugJaExiste(Database $db, string $slug): bool {
    return (bool) $db->fetchOne('SELECT 1 FROM relatorios_bi WHERE slug = :s', ['s' => $slug]);
}

/**
 * Sanitiza um texto livre (nome de tabela Excel ou cabeçalho de coluna) num
 * identificador Postgres seguro: minúsculo, ASCII, [a-z0-9_], sem underscore
 * nas pontas. Vazio quando o texto não tem nenhum caractere alfanumérico
 * aproveitável (ex.: célula de cabeçalho vazia) — chamador deve tratar como erro.
 */
function sanitizarIdentificador(string $texto): string {
    return slugify($texto, '_');
}

/**
 * Mesma sanitização, mas com fallback posicional (ex.: "coluna_3") quando o texto
 * não sobra com nenhum caractere alfanumérico aproveitável (ex.: cabeçalho "#",
 * comum em exports de terceiros como colunas de ID) — em vez de bloquear a
 * criação inteira da tabela por causa de UMA coluna. Nunca lança/rejeita aqui;
 * o chamador decide o que fazer (ex.: avisar o admin que houve renomeação).
 *
 * @return array{0: string, 1: bool} [nomeFinal, foiFallback]
 */
function sanitizarIdentificadorComFallback(string $texto, string $prefixoFallback, int $posicao1Based): array {
    $nome = sanitizarIdentificador($texto);
    if ($nome !== '') return [$nome, false];
    return [$prefixoFallback . '_' . $posicao1Based, true];
}

/**
 * Infere o tipo de coluna Postgres a partir dos valores de uma coluna do Excel
 * (já como strings cruas vindas do XlsxReader). 'numeric' só se TODOS os valores
 * não-vazios forem numéricos; 'date' só se TODOS baterem com um formato de data
 * reconhecido (YYYY-MM-DD ou DD/MM/YYYY); 'text' (padrão, "quando em dúvida")
 * em qualquer outro caso — inclusive coluna inteiramente vazia.
 *
 * Limitação conhecida: células de data NATIVAS do Excel (formatadas via estilo,
 * não digitadas como texto) chegam do XlsxReader como número serial — esta
 * função não lê xl/styles.xml, então essas colunas viram 'numeric', não 'date'.
 * Aceitável para a Etapa 2 (create-only); reavaliar se virar problema recorrente.
 */
function inferirTipoColuna(array $valores): string {
    $naoVazios = array_values(array_filter($valores, fn($v) => $v !== null && trim((string)$v) !== ''));
    if (!$naoVazios) return 'text';

    $todosNumericos = true;
    foreach ($naoVazios as $v) {
        if (!is_numeric(trim((string)$v))) { $todosNumericos = false; break; }
    }
    if ($todosNumericos) return 'numeric';

    $todosData = true;
    foreach ($naoVazios as $v) {
        $s = trim((string)$v);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && !preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $s)) {
            $todosData = false; break;
        }
    }
    if ($todosData) return 'date';

    return 'text';
}

/**
 * Normaliza um valor de data (DD/MM/YYYY ou YYYY-MM-DD, únicos formatos aceitos
 * por inferirTipoColuna) pro formato YYYY-MM-DD que o Postgres espera.
 */
function normalizarValorData(string $v): string {
    $v = trim($v);
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    return $v; // já está em YYYY-MM-DD
}
