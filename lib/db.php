<?php
declare(strict_types=1);

final class DB {
    private \PDO $pdo;
    private string $projectsTable;
    private string $jobsTable;

    public function __construct(array $cfg) {
        $charset   = $cfg['charset']   ?? 'latin1';
        $collation = $cfg['collation'] ?? 'latin1_german1_ci';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'], (int)$cfg['port'], $cfg['dbname'], $charset
        );

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        if (defined('\PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND] =
                "SET NAMES {$charset} COLLATE {$collation}, ".
                "SESSION collation_connection = {$collation}, ".
                "SESSION character_set_results = {$charset}";
        }

        $this->pdo = new \PDO($dsn, $cfg['user'], $cfg['pass'], $options);
        $this->pdo->exec("SET NAMES {$charset} COLLATE {$collation}");
        $this->pdo->exec("SET SESSION collation_connection = {$collation}");
        $this->pdo->exec("SET SESSION character_set_results = {$charset}");

        $this->projectsTable = $cfg['table_projects'] ?? 'projekte';
        $this->jobsTable     = $cfg['table_jobs'] ?? 'jobs';
    }

    public function getProject(int $id): ?array {
        $sql = "SELECT * FROM `{$this->projectsTable}` WHERE id = :id LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function getJob(int $id): ?array {
        $sql = "SELECT * FROM `{$this->jobsTable}` WHERE id = :id LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** Robustly list jobs by project FK without DESCRIBE or schema privileges. */
    public function listJobsByProject(int $projectId): array {
        $cols = "id, IFNULL(jobnummer,'') jobnummer, IFNULL(datum,'') datum,
                 IFNULL(uhrzeit_beginn,'') uhrzeit_beginn, IFNULL(uhrzeit_ende,'') uhrzeit_ende,
                 IFNULL(ort,'') ort";

        // Try common FK names in order; skip on "Unknown column" errors.
        foreach (['projekt_id','project_id','projekte_id'] as $fk) {
            $sql = "SELECT {$cols} FROM `{$this->jobsTable}` WHERE `{$fk}` = :pid ORDER BY id ASC";
            try {
                $st = $this->pdo->prepare($sql);
                $st->execute([':pid' => $projectId]);
                return $st->fetchAll(); // success for existing column
            } catch (\PDOException $e) {
                // 1054 = Unknown column
                if ($e->errorInfo[1] !== 1054) {
                    throw $e; // real error → bubble up
                }
                // else try next candidate
            }
        }

        // Last resort: return empty list instead of error
        return [];
    }
}
