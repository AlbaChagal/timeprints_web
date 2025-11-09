# PHP DOCX→PDF Generator for `projekte` + `jobs`

Purpose:
- Input: **project_id**, **job_id**
- Source: MySQL tables `projekte` and `jobs`
- Fill: `templates/doc_template.docx` placeholders like `{{projekttitel}}`, `{{datum}}`, `{{uhrzeit_beginn}}`, `{{uhrzeit_ende}}`, `{{ort}}`, `{{technik__}}`.
- Output: **PDF** via PhpWord + Dompdf; fallback: DOCX download.

## Install

1) Configure DB in `config/config.php`:
```php
<?php
return [
  'host' => 'projektliste.timeprints.de',
  'port' => 3306,
  'dbname' => 'd01f7d36',
  'user' => 'd01f7d36',
  'pass' => 'CHANGE_ME',
  'charset' => 'utf8mb4',
  'table_projects' => 'projekte',
  'table_jobs' => 'jobs',
];
```

2) Install dependencies:
```bash
cd /mnt/data/timeprints_web
composer install
```

3) Run:
```bash
php -S 127.0.0.1:8080 -t public
```

## Mapping defaults

- `projekttitel` ← `projekte.projekttitel`
- `datum` ← `jobs.datum` if set else `projekte.beginn`
- `uhrzeit_beginn` ← `jobs.uhrzeit_beginn`
- `uhrzeit_ende` ← `jobs.uhrzeit_ende`
- `ort` ← `jobs.ort` else `projekte.ort` if exists
- `technik__` ← `jobs.technik`
- `farbraum`, `addresse`, `Ansprechpartnerin`, `Ansprechpartnerin_tel`, `Ansprechpartnerin_mail` ← blank unless you extend `lib/placeholders.php`.
