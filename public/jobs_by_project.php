<?php
declare(strict_types=1);

// TEMP: show errors to browser to catch fatals; revert to 0 after fix
ini_set('display_errors','1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

// Trap any output/notice that would corrupt JSON
ob_start();

$out = ['items'=>[]];

try {
    require_once __DIR__ . '/../lib/db.php';

    $cfg = require __DIR__ . '/../config/config.php';
    $pid = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
    if ($pid <= 0) {
        $out['error'] = 'invalid project_id';
        $buf = ob_get_clean();
        if ($buf) $out['debug'] = $buf;
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = new DB($cfg);

    // Try likely FK names until we get rows; do not DESCRIBE
    $cands = ['projekt_id','project_id','projekte_id','projekt','projektID','projektid','projekt_nr','projektnummer'];
    $items = [];
    $probe = [];
    foreach ($cands as $fk) {
        $sql = "SELECT id,
                       IFNULL(jobnummer,'') AS jobnummer,
                       IFNULL(datum,'') AS datum,
                       IFNULL(uhrzeit_beginn,'') AS uhrzeit_beginn,
                       IFNULL(uhrzeit_ende,'') AS uhrzeit_ende,
                       IFNULL(ort,'') AS ort
                FROM `{$cfg['table_jobs']}`
                WHERE `{$fk}` = :pid
                ORDER BY id ASC";
        try {
            // Access PDO without reflection: add a getter if you prefer; inline quick access here:
            $rp = new ReflectionClass($db);
            $p  = $rp->getProperty('pdo');
            $p->setAccessible(true);
            /** @var PDO $pdo */
            $pdo = $p->getValue($db);

            $st = $pdo->prepare($sql);
            $st->execute([':pid'=>$pid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $probe[] = ['fk'=>$fk,'ok'=>true,'count'=>count($rows)];
            if ($rows) { $items = $rows; break; }
        } catch (Throwable $e) {
            // Unknown column → continue; other SQL errors → surface
            $code = ($e instanceof PDOException && isset($e->errorInfo[1])) ? (int)$e->errorInfo[1] : 0;
            $probe[] = ['fk'=>$fk,'ok'=>false,'err'=>$code ?: $e->getMessage()];
            if ($code && $code !== 1054) { throw $e; }
        }
    }

    // latin1 -> UTF-8 normalization for safety
    foreach ($items as &$r) {
        foreach ($r as $k => $v) {
            if (is_string($v)) {
                $r[$k] = mb_convert_encoding($v,'UTF-8','Windows-1252,ISO-8859-1,ISO-8859-15,latin1,UTF-8');
            }
        }
    }

    $out['items'] = $items;
    $out['tried'] = $probe;

} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

// Flush any stray output into debug so JSON stays valid
$buf = ob_get_clean();
if ($buf) $out['debug'] = $buf;

echo json_encode($out, JSON_UNESCAPED_UNICODE);
