<?php
declare(strict_types=1);

final class PlaceholderResolver {
    /**
     * $event is optional for backwards-compat; if given, ALL date/time comes from it.
     */
    public static function resolve(array $project, array $job, array $event = []): array {
        $get = function(array $row, string $key, $default = '') {
            return array_key_exists($key, $row) ? (string)($row[$key] ?? '') : $default;
        };

        // ----- DATE from event (datum_beginn) -----
        $date = $get($event, 'datum_beginn', '');
        $normDate = $date ? date('Y-m-d', strtotime($date)) : '';

        // ----- TIMES from event (normal times only) -----
        $fmtTime = function($t) {
            if (!$t) return '';
            if (preg_match('/^(\d{1,2}:\d{2})/', $t, $m)) return $m[1];
            if (preg_match('/^(\d{1,2})(\d{2})$/', $t, $m)) return sprintf('%02d:%02d', $m[1], $m[2]);
            return $t;
        };

        $uBeg = $fmtTime($get($event, 'uhrzeit_beginn', ''));
        $uEnd = $fmtTime($get($event, 'uhrzeit_ende', ''));

        // keep everything else as in your existing file – only the date/time parts are changed
        // (you can paste your existing mapping here and just replace the date/time logic)
        return [
            // project + job fields (unchanged from your current version)
            'projekttitel'    => $get($project, 'projekttitel', ''),
            'projektnummer'   => $get($project, 'projektnummer', ''),
            'auftraggeber'    => $get($project, 'auftraggeber', ''),
            'ort'             => $get($job, 'ort', ''),

            // DATE/TIME from event
            'datum'        => $normDate,
            'datum_beginn' => $date,
            'uhrzeit_beginn' => $uBeg,
            'uhrzeit_ende'   => $uEnd,

            // keep your other keys as before...
            'technik__'      => $get($job, 'technik', ''),
            'farbraum'       => '',

            // these are still parsed from `${ort}` later
            'addresse'               => '',
            'Ansprechpartnerin'      => '',
            'Ansprechpartnerin_tel'  => '',
            'Ansprechpartnerin_mail' => '',

            // aliases
            'start' => $uBeg,
            'end'   => $uEnd,
        ];
    }
}
