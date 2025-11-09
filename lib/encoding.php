<?php
declare(strict_types=1);

/**
 * Convert row strings from latin1/Win-1252 to UTF-8 for PhpWord/LibreOffice.
 * Safe if input already UTF-8.
 */
function normalize_utf8_array(array $row): array {
    foreach ($row as $k => $v) {
        if (!is_string($v)) continue;

        // Fast-path: treat as latin1 → UTF-8 always, then verify
        $converted = mb_convert_encoding($v, 'UTF-8', 'Windows-1252,ISO-8859-1,ISO-8859-15,latin1,UTF-8');

        // If conversion produced the replacement char massively, retry with iconv
        if (substr_count($converted, "\xEF\xBF\xBD") > 3) {
            $try = @iconv('ISO-8859-1', 'UTF-8//TRANSLIT', $v);
            if ($try !== false) $converted = $try;
        }

        // Final re-encode to clean any stray bytes
        $row[$k] = mb_convert_encoding($converted, 'UTF-8', 'UTF-8');
    }
    return $row;
}
