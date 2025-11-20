<?php
declare(strict_types=1);

// TEMP during debugging; set to '0' when everything works
ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

// Capture stray output so JSON stays valid
ob_start();

$out = ['items' => []];

try {
    // same paths as jobs_by_project.php
    require_once __DIR__ . '/../lib/db.php';
    $cfg = require __DIR__ . '/../config/config.php';

    $jid = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
    if ($jid <= 0) {
        $out['error'] = 'invalid job_id';
        $buf = ob_get_clean();
        if ($buf) {
            $out['debug'] = $buf;
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // init DB and get PDO like in jobs_by_project.php
    $db = new DB($cfg);

    $rp = new ReflectionClass($db);
    $p  = $rp->getProperty('pdo');
    $p->setAccessible(true);
    /** @var PDO $pdo */
    $pdo = $p->getValue($db);

    // table name is fixed by your schema: "events"
    $tableEvents = $cfg['table_events'] ?? 'events';

    $sql = "SELECT
                id,
                IFNULL(art,'')                 AS art,
                IFNULL(description,'')         AS description,
                IFNULL(datum_beginn,'')        AS datum_beginn,
                IFNULL(datum_ende,'')          AS datum_ende,
                IFNULL(uhrzeit_beginn,'')      AS uhrzeit_beginn,
                IFNULL(uhrzeit_ende,'')        AS uhrzeit_ende,
                IFNULL(live_uhrzeit_beginn,'') AS live_uhrzeit_beginn,
                IFNULL(live_uhrzeit_ende,'')   AS live_uhrzeit_ende,
                ''                             AS ort   -- events has no 'ort'; keep field for frontend filter
            FROM `{$tableEvents}`
            WHERE job_id = :jid
            ORDER BY datum_beginn ASC, uhrzeit_beginn ASC, id ASC";

    $st = $pdo->prepare($sql);
    $st->execute([':jid' => $jid]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // latin1 -> UTF-8 normalization like in jobs_by_project.php
    foreach ($items as &$row) {
        foreach ($row as $k => $v) {
            if (is_string($v)) {
                $row[$k] = mb_convert_encoding(
                    $v,
                    'UTF-8',
                    'Windows-1252,ISO-8859-1,ISO-8859-15,latin1,UTF-8'
                );
            }
        }
    }

    $out['items'] = $items;

} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

// flush any notices/warnings into debug
$buf = ob_get_clean();
if ($buf) {
    $out['debug'] = $buf;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
