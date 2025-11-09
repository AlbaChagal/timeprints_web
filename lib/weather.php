<?php
// lib/weather.php

function fetch_forecast(float $lat, float $lon, string $dateYmd, ?string $startHHMM = null, ?string $endHHMM = null): array
{
    $startHHMM = $startHHMM ?: '09:00';
    $endHHMM   = $endHHMM   ?: '17:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $startHHMM)) $startHHMM = '09:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $endHHMM))   $endHHMM   = '17:00';

    $params = http_build_query([
        'latitude'   => $lat,
        'longitude'  => $lon,
        'timezone'   => 'Europe/Berlin',
        'daily'      => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunshine_duration,wind_speed_10m_max',
        'hourly'     => 'temperature_2m,precipitation_probability,wind_speed_10m,shortwave_radiation',
        'start_date' => $dateYmd,
        'end_date'   => $dateYmd,
    ]);
    $url = 'https://api.open-meteo.com/v1/forecast?' . $params;

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $resp = curl_exec($ch);
    if ($resp === false) { curl_close($ch); return ['hoch'=>'','tief'=>'','wind'=>'','sonne_max'=>'','regen%'=>'']; }
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) { return ['hoch'=>'','tief'=>'','wind'=>'','sonne_max'=>'','regen%'=>'']; }

    $data = json_decode($resp, true) ?: [];

    // Daily (fallbacks)
    $d = $data['daily'] ?? [];
    $i = 0;
    $tmax  = $d['temperature_2m_max'][$i] ?? null;
    $tmin  = $d['temperature_2m_min'][$i] ?? null;
    $wmax  = $d['wind_speed_10m_max'][$i] ?? null;
    $suns  = $d['sunshine_duration'][$i] ?? null;          // seconds
    $pmax  = $d['precipitation_probability_max'][$i] ?? null;

    // Hourly window
    $h = $data['hourly'] ?? [];
    $times = $h['time'] ?? [];
    $temps = $h['temperature_2m'] ?? [];
    $rprob = $h['precipitation_probability'] ?? [];
    $winds = $h['wind_speed_10m'] ?? [];
    $rad   = $h['shortwave_radiation'] ?? [];

    $startTs = strtotime($dateYmd . ' ' . $startHHMM . ' Europe/Berlin');
    $endTs   = strtotime($dateYmd . ' ' . $endHHMM   . ' Europe/Berlin');
    if ($endTs <= $startTs) $endTs = $startTs + 3600;

    $winT=$winP=$winW=$winR=[];
    foreach ($times as $k => $t) {
        $ts = strtotime($t . ' Europe/Berlin');
        if ($ts >= $startTs && $ts <= $endTs) {
            if (isset($temps[$k])) $winT[] = $temps[$k];
            if (isset($rprob[$k])) $winP[] = $rprob[$k];
            if (isset($winds[$k])) $winW[] = $winds[$k];
            if (isset($rad[$k]))   $winR[] = $rad[$k];
        }
    }

    $out = ['hoch'=>'','tief'=>'','wind'=>'','sonne_max'=>'','regen%'=>''];

    if ($winT || $winP || $winW || $winR) {
        $tHigh = $winT ? max($winT) : $tmax;
        $tLow  = $winT ? min($winT) : $tmin;
        $wMax  = $winW ? max($winW) : $wmax;

        if ($winR) {
            $sunHours = 0.0;
            foreach ($winR as $v) { if ($v > 120) $sunHours += 1.0; }
            $sunStr = number_format($sunHours, 1) . ' h';
        } elseif (is_numeric($suns)) {
            $sunStr = number_format($suns / 3600.0, 1) . ' h';
        } else {
            $sunStr = '';
        }

        $pMax = $winP ? max($winP) : $pmax;

        $out['hoch']      = is_numeric($tHigh) ? round($tHigh) . "°C" : '';
        $out['tief']      = is_numeric($tLow)  ? round($tLow)  . "°C" : '';
        $out['wind']      = is_numeric($wMax)  ? round($wMax)  . " km/h" : '';
        $out['sonne_max'] = $sunStr;
        $out['regen%']    = is_numeric($pMax)  ? round($pMax)  . "%" : '';
        return $out;
    }

    // Daily-only fallback
    $out['hoch']      = is_numeric($tmax) ? round($tmax) . "°C" : '';
    $out['tief']      = is_numeric($tmin) ? round($tmin) . "°C" : '';
    $out['wind']      = is_numeric($wmax) ? round($wmax) . " km/h" : '';
    $out['sonne_max'] = is_numeric($suns) ? number_format($suns / 3600.0, 1) . " h" : '';
    $out['regen%']    = is_numeric($pmax) ? round($pmax) . "%" : '';
    return $out;
}
