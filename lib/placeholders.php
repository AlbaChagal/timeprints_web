<?php
declare(strict_types=1);

final class PlaceholderResolver {
    public static function resolve(array $project, array $job): array {
        $get = function(array $row, string $key, $default = '') {
            return array_key_exists($key, $row) ? (string)($row[$key] ?? '') : $default;
        };

        $date = $get($job, 'datum', $get($project, 'beginn', ''));
        $normDate = $date ? date('Y-m-d', strtotime($date)) : '';

        return [
            'projekttitel' => $get($project, 'projekttitel', ''),
            'datum' => $normDate,
            'uhrzeit_beginn' => $get($job, 'uhrzeit_beginn', ''),
            'uhrzeit_ende' => $get($job, 'uhrzeit_ende', ''),
            'ort' => $get($job, 'ort', $get($project, 'ort', '')),
            'technik__' => $get($job, 'technik', ''),
            'farbraum' => '',
            'addresse' => '',
            'Ansprechpartnerin' => $get($project, 'ansprechpartner_intern', ''),
            'Ansprechpartnerin_tel' => '',
            'Ansprechpartnerin_mail' => '',
        ];
    }
}
