<?php
/**
 * Leitor mínimo de .xlsx (primeira planilha) — sem Composer/PhpSpreadsheet.
 * Usa ZipArchive + SimpleXML (extensões php8.1-zip/php8.1-xml, instaladas em
 * produção especificamente pra Etapa 2 do self-service de Relatórios BI — ver
 * RELATORIOS_BI.md). Lê só o que essa etapa precisa: cabeçalho (linha 1) +
 * linhas de dados da PRIMEIRA planilha do arquivo. Não lida com estilos,
 * fórmulas, múltiplas planilhas nem células mescladas.
 */

class XlsxLerException extends Exception {}

class XlsxReader {

    /**
     * @return array{cabecalho: array, linhas: array<array>}
     */
    public static function ler(string $caminhoArquivo): array {
        $zip = new ZipArchive();
        if ($zip->open($caminhoArquivo) !== true) {
            throw new XlsxLerException('Não foi possível abrir o arquivo — não parece ser um .xlsx válido.');
        }

        $sharedStrings = self::lerSharedStrings($zip);
        $sheetXmlRaw   = self::lerPrimeiraPlanilha($zip);
        $zip->close();

        libxml_use_internal_errors(true);
        $sheetXml = simplexml_load_string($sheetXmlRaw);
        if ($sheetXml === false || !isset($sheetXml->sheetData)) {
            throw new XlsxLerException('Não foi possível interpretar a planilha (XML inválido ou inesperado).');
        }

        $linhasBrutas = [];
        $maxCol = -1;
        foreach ($sheetXml->sheetData->row as $rowEl) {
            $linha = [];
            foreach ($rowEl->c as $cEl) {
                $ref    = (string)$cEl['r'];
                $colIdx = self::colunaParaIndice($ref);
                $tipo   = (string)$cEl['t'];
                $linha[$colIdx] = self::valorCelula($cEl, $tipo, $sharedStrings);
                if ($colIdx > $maxCol) $maxCol = $colIdx;
            }
            $linhasBrutas[] = $linha;
        }

        if (!$linhasBrutas) {
            throw new XlsxLerException('Planilha vazia — nenhuma linha encontrada.');
        }

        $numCols = $maxCol + 1;
        $todasLinhas = array_map(function ($linha) use ($numCols) {
            $out = [];
            for ($i = 0; $i < $numCols; $i++) $out[] = $linha[$i] ?? null;
            return $out;
        }, $linhasBrutas);

        $cabecalho = array_shift($todasLinhas);
        return ['cabecalho' => $cabecalho, 'linhas' => $todasLinhas];
    }

    private static function lerSharedStrings(ZipArchive $zip): array {
        $raw = $zip->getFromName('xl/sharedStrings.xml');
        if ($raw === false) return [];
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        if ($xml === false) return [];
        $out = [];
        foreach ($xml->si as $si) {
            // <si><t>texto</t></si> — caso simples. <si><r><t>...</t></r>...</si> — rich text.
            if (isset($si->t)) {
                $out[] = (string)$si->t;
            } else {
                $partes = [];
                foreach ($si->r as $r) { $partes[] = (string)$r->t; }
                $out[] = implode('', $partes);
            }
        }
        return $out;
    }

    private static function lerPrimeiraPlanilha(ZipArchive $zip): string {
        $raw = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($raw !== false) return $raw;

        // Fallback: primeiro sheetN.xml encontrado, em ordem alfabética de nome.
        $candidatos = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $nome)) $candidatos[] = $nome;
        }
        if (!$candidatos) {
            throw new XlsxLerException('Nenhuma planilha encontrada dentro do arquivo .xlsx.');
        }
        sort($candidatos);
        $raw = $zip->getFromName($candidatos[0]);
        if ($raw === false) {
            throw new XlsxLerException('Não foi possível ler a planilha dentro do arquivo .xlsx.');
        }
        return $raw;
    }

    private static function valorCelula($cEl, string $tipo, array $sharedStrings) {
        if ($tipo === 's') {
            $idx = isset($cEl->v) ? (int)$cEl->v : -1;
            return $sharedStrings[$idx] ?? '';
        }
        if ($tipo === 'str') {
            return isset($cEl->v) ? (string)$cEl->v : '';
        }
        if ($tipo === 'inlineStr') {
            return isset($cEl->is->t) ? (string)$cEl->is->t : '';
        }
        if ($tipo === 'b') {
            return (isset($cEl->v) && (string)$cEl->v === '1') ? 'true' : 'false';
        }
        // Numérico (t ausente ou "n") — retorna como veio, cru (sem interpretar
        // number-format/estilo — datas nativas do Excel chegam como número serial,
        // ver limitação documentada em RELATORIOS_BI.md).
        return isset($cEl->v) ? (string)$cEl->v : null;
    }

    /** "B3" -> "B" -> índice 1 (0-based). Suporta múltiplas letras (AA, AB, ...). */
    private static function colunaParaIndice(string $ref): int {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $letras = $m[1] ?? 'A';
        $idx = 0;
        foreach (str_split($letras) as $ch) {
            $idx = $idx * 26 + (ord($ch) - ord('A') + 1);
        }
        return $idx - 1;
    }
}
