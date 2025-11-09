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

    // 1) existing vars
    $vars = PlaceholderResolver::resolve($project, $job);

    // 2) parse ${ort} with OpenAI and overlay fields used by your template
    require_once $ROOT . '/lib/ai.php';
    $parsed = parse_ort_to_fields($job['ort'] ?? ($project['ort'] ?? ''));

    // Merge: parsed fields should win if non-empty
    foreach ($parsed as $k => $v) {
        if (is_string($v) && $v !== '') {
            $vars[$k] = $v;
        }
    }

    // Optional: if your template also uses split placeholders, you can expose these:
    $vars['ort'] = $job['ort'] ?? ($project['ort'] ?? '');


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
