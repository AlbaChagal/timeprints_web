<?php
declare(strict_types=1);

final class PlaceholderResolver {
    public static function resolve(array $project, array $job): array {
        $get = function(array $row, string $key, $default = '') {
            return array_key_exists($key, $row) ? (string)($row[$key] ?? '') : $default;
        };

        $date = $get($job, 'datum', $get($project, 'beginn', ''));
        $normDate = $date ? date('Y-m-d', strtotime($date)) : '';

        // --- normalize times: keep only HH:MM ---
        $fmtTime = function($t) {
            if (!$t) return '';
            // extract first two colon parts, e.g. "10:30" from "10:30:00"
            if (preg_match('/^(\d{1,2}:\d{2})/', $t, $m)) return $m[1];
            // fallback: try to parse
            $ts = strtotime($t);
            return $ts ? date('H:i', $ts) : trim($t);
        };
        $uBeg = $fmtTime($get($job, 'uhrzeit_beginn', ''));
        $uEnd = $fmtTime($get($job, 'uhrzeit_ende', ''));

        return [
            // existing
            'projekttitel'   => $get($project, 'projekttitel', ''),
            'datum'          => $normDate,
            'uhrzeit_beginn' => $uBeg,
            'uhrzeit_ende'   => $uEnd,
            'ort'            => $get($job, 'ort', $get($project, 'ort', '')),
            'technik__'      => $get($job, 'technik', ''),
            'farbraum'       => '',
            'addresse'       => '',
            'Ansprechpartnerin'      => $get($project, 'ansprechpartner_intern', ''),
            'Ansprechpartnerin_tel'  => '',
            'Ansprechpartnerin_mail' => '',

            // aliases for template placeholders
            'start' => $uBeg,
            'end'   => $uEnd,
        ];
    }
}
