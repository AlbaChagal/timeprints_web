<?php
declare(strict_types=1);

final class DB {
    private \PDO $pdo;
    private string $projectsTable;
    private string $jobsTable;
    private string $eventsTable;

    public function __construct(array $cfg) {
        $charset   = $cfg['charset']   ?? 'latin1';
        $collation = $cfg['collation'] ?? 'latin1_german1_ci';

        $host   = $cfg['host'];
        $port   = (int)$cfg['port'];
        $dbname = $cfg['dbname'];
        $user   = $cfg['user'];
        $pass   = $cfg['pass'];

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        if (defined('\PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND] =
                "SET NAMES {$charset} COLLATE {$collation}, " .
                "SESSION collation_connection = {$collation}, " .
                "SESSION character_set_results = {$charset}";
        }

        $this->pdo = new PDO($dsn, $user, $pass, $options);

        $this->projectsTable = $cfg['table_projects'] ?? 'projekte';
        $this->jobsTable     = $cfg['table_jobs']     ?? 'jobs';
        $this->eventsTable   = $cfg['table_events']   ?? 'events';
    }

    public function getProject(int $id): ?array {
        $sql = "SELECT * FROM `{$this->projectsTable}` WHERE id = :id LIMIT 1";
        $st  = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function getJob(int $id): ?array {
        $sql = "SELECT * FROM `{$this->jobsTable}` WHERE id = :id LIMIT 1";
        $st  = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function getEvent(int $id): ?array {
        $sql = "SELECT * FROM `{$this->eventsTable}` WHERE id = :id LIMIT 1";
        $st  = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function listJobsByProject(int $projectId, array &$debug = []): array {
        $cols = "id,
                 IFNULL(jobnummer,'')      AS jobnummer,
                 IFNULL(datum,'')          AS datum,
                 IFNULL(uhrzeit_beginn,'') AS uhrzeit_beginn,
                 IFNULL(uhrzeit_ende,'')   AS uhrzeit_ende,
                 IFNULL(ort,'')            AS ort";

        // Try likely FK names; accept the first that returns >0 rows.
        $candidates = [
            'projekt_id','project_id','projekte_id',
            'projekt','projektID','projektid',
            'projekt_nr','projektnummer'
        ];

        $debug = []; // [['fk'=>..., 'ok'=>bool, 'count'=>int]]
        foreach ($candidates as $fk) {
            $sql = "SELECT {$cols}
                    FROM `{$this->jobsTable}`
                    WHERE `{$fk}` = :pid
                    ORDER BY id ASC";
            try {
                $st = $this->pdo->prepare($sql);
                $st->execute([':pid' => $projectId]);
                $rows = $st->fetchAll();
                $cnt  = is_array($rows) ? count($rows) : 0;
                $debug[] = ['fk' => $fk, 'ok' => true, 'count' => $cnt];
                if ($cnt > 0) {
                    return $rows;
                }
            } catch (\PDOException $e) {
                // 1054 Unknown column → record and continue
                $debug[] = ['fk' => $fk, 'ok' => false, 'count' => 0, 'err' => $e->errorInfo[1] ?? null];
                if (($e->errorInfo[1] ?? 0) !== 1054) {
                    // Different SQL error → bubble up
                    throw $e;
                }
            }
        }

        // Nothing matched → empty list
        return [];
    }

    public function getPdo(): \PDO {
        return $this->pdo;
    }
}
