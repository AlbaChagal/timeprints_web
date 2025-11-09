<?php
declare(strict_types=1);

mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');
setlocale(LC_ALL, 'de_DE.UTF-8', 'de_DE', 'deu_deu', 'deu', 'de', 'C');
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/placeholders.php';
require_once __DIR__ . '/../lib/docx.php';
require_once __DIR__ . '/../lib/encoding.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

$config = require __DIR__ . '/../config/config.php';

$projectId = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$jobId     = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;

if ($projectId <= 0 || $jobId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Bad input";
    exit;
}

try {
    $db = new DB($config);

    $project = $db->getProject($projectId);
    if (!$project) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Projekt nicht gefunden: {$projectId}";
        exit;
    }

    $job = $db->getJob($jobId);
    if (!$job) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Job nicht gefunden: {$jobId}";
        exit;
    }

    // normalize AFTER ensuring we have arrays
    $project = normalize_utf8_array($project);
    $job     = normalize_utf8_array($job);

    $vars = PlaceholderResolver::resolve($project, $job);

    $service = new DocxService(__DIR__ . '/../templates/doc_template.docx');
    $outBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . sprintf('disp_%d_%d_%s', $projectId, $jobId, date('Ymd_His'));
    $pdfPath = $outBase . '.pdf';

    $result = $service->build($vars, $pdfPath);

    if ($result['type'] === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($result['path']) . '"');
        header('Content-Length: ' . filesize($result['path']));
        readfile($result['path']);
        @unlink($result['path']);
        if (!empty($result['tmpdocx']) && file_exists($result['tmpdocx'])) { @unlink($result['tmpdocx']); }
        exit;
    }

    $docx = $result['path'];
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . basename($docx) . '"');
    header('Content-Length: ' . filesize($docx));
    readfile($docx);
    @unlink($docx);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Error: ' . $e->getMessage();
    exit;
}
