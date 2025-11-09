<?php
declare(strict_types=1);

// Do NOT echo PHP warnings/notices (they break JSON)
ini_set('display_errors','0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../lib/db.php';

// latin1→UTF-8 for a single row
function _norm(array $row): array {
    foreach ($row as $k => $v) {
        if (is_string($v)) {
            $row[$k] = mb_convert_encoding($v, 'UTF-8', 'Windows-1252,ISO-8859-1,ISO-8859-15,latin1,UTF-8');
        }
    }
    return $row;
}

try {
    $config = require __DIR__ . '/../config/config.php';
    $pid = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
    if ($pid <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid project_id']);
        exit;
    }

    $db = new DB($config);
    $items = $db->listJobsByProject($pid); // new robust method below

    // Normalize encoding per row
    $items = array_map('_norm', $items);

    echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
