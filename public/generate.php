<?php
declare(strict_types=1);

/* ---------- Runtime hardening ---------- */
ini_set('display_errors', '0');                 // never leak warnings into binary response
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('zlib.output_compression', '0');        // avoid Safari issues with compressed output
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');
setlocale(LC_ALL, 'de_DE.UTF-8','de_DE','C');

/* Buffer all output so headers are clean */
ob_start();

/* ---------- Resolve paths ---------- */
$ROOT = dirname(__DIR__);
$autoload = $ROOT . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    // flush any buffered notices
    ob_get_length() && ob_end_clean();
    echo "Composer autoload not found at: {$autoload}\nRun: composer install";
    exit;
}
require_once $autoload;

require_once $ROOT . '/lib/db.php';
require_once $ROOT . '/lib/placeholders.php';
require_once $ROOT . '/lib/docx.php';
require_once $ROOT . '/lib/encoding.php';
require_once dirname(__DIR__) . '/lib/weather.php';

$config = require $ROOT . '/config/config.php';

/* ---------- Input ---------- */
$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$jobId     = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
if ($projectId <= 0 || $jobId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    ob_get_length() && ob_end_clean();
    echo "Bad input";
    exit;
}

// Build a clean ${ort} string from parsed fields, fallback to a sanitized slice of raw
function buildCleanOrt(array $parsed, string $raw): string {
    $addr    = trim((string)($parsed['address'] ?? ''));
    $postal  = trim((string)($parsed['postal_code'] ?? ''));
    $city    = trim((string)($parsed['city'] ?? ''));
    $country = trim((string)($parsed['country'] ?? ''));

    // Prefer structured address
    if ($addr || $postal || $city || $country) {
        $tail = trim(($postal . ' ' . $city));
        if ($addr && $tail)   return trim($addr . ' ' . $tail);
        if ($addr)            return $addr;
        if ($tail)            return $tail;
        if ($country)         return $country;
    }

    // Fallback: sanitize raw — strip emails/phones/labels, collapse whitespace, limit length
    $s = $raw;
    // strip email addresses
    $s = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '', $s);
    // strip phone patterns + leading labels
    $s = preg_replace('/\b(Tel\.?|Telefon|Phone|Mob\.?|Mobile|Handy)\s*[:\-]?\s*/iu', '', $s);
    $s = preg_replace('/\+?\d[\d\-\s().]{6,}/u', '', $s);
    // collapse whitespace & take first sentence/line-ish
    $s = preg_replace('/\s+/u', ' ', trim($s));
    $s = mb_substr($s, 0, 160, 'UTF-8'); // keep it short
    return $s;
}



/* ---------- Run ---------- */
try {
    $db = new DB($config);

    $project = $db->getProject($projectId);
    if (!$project) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        ob_get_length() && ob_end_clean();
        echo "Projekt nicht gefunden: {$projectId}";
        exit;
    }

    $job = $db->getJob($jobId);
    if (!$job) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        ob_get_length() && ob_end_clean();
        echo "Job nicht gefunden: {$jobId}";
        exit;
    }

    // latin1 → UTF-8 normalization
    $project = normalize_utf8_array($project);
    $job     = normalize_utf8_array($job);

    // Base vars from DB
    $vars = PlaceholderResolver::resolve($project, $job);

    // Parse using ORT + PROJEKTDETAILS
    require_once $ROOT . '/lib/ai.php';
    $rawOrt  = $job['ort'] ?? ($project['ort'] ?? '');
    $rawProj = $project['projektdetails'] ?? ($job['projektdetails'] ?? '');
    $parsed  = parse_ort_to_fields($rawOrt, $rawProj);

    // Overwrite with parsed values (external contact, address, farbraum, technik__)
    foreach (['addresse','Ansprechpartnerin','Ansprechpartnerin_tel','Ansprechpartnerin_mail','farbraum','technik__'] as $k) {
        if (!empty($parsed[$k]) && is_string($parsed[$k])) {
            $vars[$k] = $parsed[$k];
        }
    }

    // Build clean one-liner for ${ort} from parser's “motiv” (fallback = sanitized raw)
    $clean = (string)($parsed['motiv'] ?? '');
    if ($clean === '') {
        $s = $rawOrt;
        $s = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '', $s);
        $s = preg_replace('/\b(Tel\.?|Telefon|Phone|Mob\.?|Mobile|Handy)\s*[:\-]?\s*/iu', '', $s);
        $s = preg_replace('/\+?\d[\d\-\s().]{6,}/u', '', $s);
        $s = preg_replace('/\s+/u', ' ', trim($s));
        $clean = mb_substr($s, 0, 120, 'UTF-8');
    }
    $clean = preg_replace('/\s*[\r\n]+\s*/u', ', ', trim($clean));
    $vars['ort']   = $clean;
    $vars['motiv'] = $clean; // keep in sync if the template uses ${motiv}

    // ---------- Weather injection ----------
    $pt = [
        'lat' => null,
        'lon' => null,
    ];

    // If you already parse coordinates from DB/Ort, keep that here:
    if (!empty($vars['lat']) && !empty($vars['lon'])) {
        $pt['lat'] = (float)$vars['lat'];
        $pt['lon'] = (float)$vars['lon'];
    } elseif (!empty($job['lat']) && !empty($job['lon'])) {
        $pt['lat'] = (float)$job['lat'];
        $pt['lon'] = (float)$job['lon'];
    }

    // Hard fallback to Berlin if still missing
    if (empty($pt['lat']) || empty($pt['lon'])) {
        $pt['lat'] = 52.5200;
        $pt['lon'] = 13.4050;
    }


    // Use Berlin tz (matches your workflow)
    $tz = new DateTimeZone('Europe/Berlin');

    // Resolve the target date from your vars / DB
    $whenDateStr = $vars['datum']
        ?: (isset($job['datum']) ? date('Y-m-d', strtotime($job['datum'])) : date('Y-m-d'));

    $startHHMM = $vars['start'] ?: ($vars['uhrzeit_beginn'] ?? '');
    $endHHMM   = $vars['end']   ?: ($vars['uhrzeit_ende']   ?? '');

    // Build DateTime objects
    try {
        $target = new DateTime($whenDateStr, $tz);
    } catch (Throwable $e) {
        $target = new DateTime('today', $tz);
    }
    $today   = new DateTime('today', $tz);

    // If target is in the past → use tomorrow's forecast for the same location
    if ($target < $today) {
        $target = new DateTime('tomorrow', $tz);
    }

    $wx = [];
    if (!empty($pt['lat']) && !empty($pt['lon'])) {
        // Always fetch for the (possibly adjusted) target date
        $wx = fetch_forecast(
            (float)$pt['lat'],
            (float)$pt['lon'],
            $target->format('Y-m-d'),
            $startHHMM ?: null,
            $endHHMM   ?: null
        );
    }

    // Overlay placeholders used by your template
    foreach (['hoch','tief','wind','sonne_max','regen%'] as $k) {
        if (!empty($wx[$k])) {
            $vars[$k] = $wx[$k];
        } else {
            // Ensure placeholders never leak through
            if (!isset($vars[$k])) $vars[$k] = '';
        }
    }

    $tz = new DateTimeZone('Europe/Berlin');

    $whenDateStr = $vars['datum']
        ?: (isset($job['datum']) ? date('Y-m-d', strtotime($job['datum'])) : date('Y-m-d'));

    $startHHMM = $vars['start'] ?: ($vars['uhrzeit_beginn'] ?? '');
    $endHHMM   = $vars['end']   ?: ($vars['uhrzeit_ende']   ?? '');

    try {
        $target = new DateTime($whenDateStr, $tz);
    } catch (Throwable $e) {
        $target = new DateTime('today', $tz);
    }
    $today = new DateTime('today', $tz);

    // Past date → use tomorrow
    if ($target < $today) {
        $target = new DateTime('tomorrow', $tz);
    }

    $wx = fetch_forecast(
        (float)$pt['lat'],
        (float)$pt['lon'],
        $target->format('Y-m-d'),
        $startHHMM ?: null,
        $endHHMM   ?: null
    );

    // Overlay with guaranteed keys, no placeholder leakage
    foreach (['hoch','tief','wind','sonne_max','regen%'] as $k) {
        $vars[$k] = !empty($wx[$k]) ? $wx[$k] : '';
    }



    $service = new DocxService($ROOT . '/templates/doc_template.docx');
    $outBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . sprintf(
        'disp_%d_%d_%s', $projectId, $jobId, date('Ymd_His')
    );
    $pdfPath = $outBase . '.pdf';

    $result = $service->build($vars, $pdfPath);

    /* --------- build custom filename --------- */
    $projName = preg_replace('/[^A-Za-z0-9_-]+/', '_',
        $project['projekttitel'] ?? $project['projektname'] ?? ''
    );
    $dateTag  = date('ymd');
    $baseName = sprintf('dispo_%d_%s_%s', $projectId, $projName, $dateTag);
    /* ---------------------------------------- */

    // Clean ALL buffers before sending headers/body
    while (ob_get_level() > 0) { ob_end_clean(); }

    if ($result['type'] === 'pdf') {
        $path = $result['path'];
        if (!is_file($path)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "PDF not found after build.";
            exit;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$baseName.'"');
        header('Content-Length: ' . filesize($path));
        $fp = fopen($path, 'rb');
        fpassthru($fp);
        fclose($fp);
        @unlink($path);
        if (!empty($result['tmpdocx']) && is_file($result['tmpdocx'])) { @unlink($result['tmpdocx']); }
        exit;
    }

    // Fallback: DOCX
    $docx = $result['path'];
    if (!is_file($docx)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "DOCX not found after build.";
        exit;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="'.$baseName.'"');
    header('Content-Length: ' . filesize($docx));
    $fp = fopen($docx, 'rb');
    fpassthru($fp);
    fclose($fp);
    @unlink($docx);
    exit;

} catch (Throwable $e) {
    // Final safe error
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error: ' . $e->getMessage();
    exit;
}
