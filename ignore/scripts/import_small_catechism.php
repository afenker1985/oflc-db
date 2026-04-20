<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

const OFLC_DEFAULT_SMALL_CATECHISM_CSV = '/Users/aaronfenker/Desktop/OF Hymn DB/Exported CSV/catechism_recitation-Table 1.csv';
const OFLC_DEFAULT_SMALL_CATECHISM_SQL = __DIR__ . '/../sql/2026-04-20_add_small_catechism_and_lessons_and_carols.sql';
const OFLC_SMALL_CATECHISM_HEADERS = [
    'id',
    'chief_part',
    'chief_part_id',
    'question',
    'abbreviation',
    'question_order',
    'is_active',
];

function oflc_small_catechism_usage(): void
{
    echo <<<TXT
Usage:
  php scripts/import_small_catechism.php [--csv=/path/to/catechism.csv] [--migration-sql=/path/to/migration.sql]

TXT;
}

function oflc_small_catechism_options(array $argv): array
{
    $options = [
        'csv' => OFLC_DEFAULT_SMALL_CATECHISM_CSV,
        'migration-sql' => OFLC_DEFAULT_SMALL_CATECHISM_SQL,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help') {
            oflc_small_catechism_usage();
            exit(0);
        }

        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('Unknown argument: ' . $argument);
        }

        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!array_key_exists($name, $options)) {
            throw new InvalidArgumentException('Unknown option: --' . $name);
        }

        $options[$name] = $value;
    }

    return $options;
}

function oflc_execute_sql_file(PDO $pdo, string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('Migration SQL file not found: ' . $path);
    }

    $statement = '';
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read migration SQL file: ' . $path);
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }

        $statement .= $line . PHP_EOL;
        if (str_ends_with($trimmed, ';')) {
            $pdo->exec($statement);
            $statement = '';
        }
    }

    if (trim($statement) !== '') {
        $pdo->exec($statement);
    }
}

function oflc_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function oflc_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function oflc_read_small_catechism_csv(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('CSV file not found: ' . $path);
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open CSV file: ' . $path);
    }

    $header = fgetcsv($handle, 0, ',', '"', '\\');
    if ($header === false) {
        fclose($handle);
        throw new RuntimeException('CSV file is empty: ' . $path);
    }

    $header = array_map(static function ($value): string {
        return trim((string) $value, "\xEF\xBB\xBF \t\n\r\0\x0B");
    }, $header);

    if ($header !== OFLC_SMALL_CATECHISM_HEADERS) {
        fclose($handle);
        throw new RuntimeException('Unexpected CSV headers in ' . $path . '.');
    }

    $rows = [];
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if ($row === [null] || $row === []) {
            continue;
        }

        $row = array_pad($row, count($header), '');
        $rows[] = array_combine($header, $row);
    }

    fclose($handle);

    return $rows;
}

function oflc_small_catechism_required_int(array $row, string $field): int
{
    $value = trim((string) ($row[$field] ?? ''));
    if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
        throw new RuntimeException("Field {$field} must be an integer; received '{$value}'.");
    }

    return (int) $value;
}

function oflc_small_catechism_required_string(array $row, string $field): string
{
    $value = trim((string) ($row[$field] ?? ''));
    if ($value === '') {
        throw new RuntimeException("Field {$field} is required.");
    }

    return $value;
}

try {
    $options = oflc_small_catechism_options($argv);
    $rows = oflc_read_small_catechism_csv($options['csv']);

    if (!oflc_table_exists($pdo, 'small_catechism_mysql') || !oflc_column_exists($pdo, 'service_db', 'small_catechism_id')) {
        oflc_execute_sql_file($pdo, $options['migration-sql']);
    }

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM `small_catechism_mysql`');

    $insert_stmt = $pdo->prepare(
        'INSERT INTO `small_catechism_mysql` (
            `id`,
            `chief_part`,
            `chief_part_id`,
            `question`,
            `abbreviation`,
            `question_order`,
            `is_active`
         ) VALUES (
            :id,
            :chief_part,
            :chief_part_id,
            :question,
            :abbreviation,
            :question_order,
            :is_active
         )'
    );

    foreach ($rows as $row) {
        $insert_stmt->execute([
            ':id' => oflc_small_catechism_required_int($row, 'id'),
            ':chief_part' => oflc_small_catechism_required_string($row, 'chief_part'),
            ':chief_part_id' => oflc_small_catechism_required_int($row, 'chief_part_id'),
            ':question' => oflc_small_catechism_required_string($row, 'question'),
            ':abbreviation' => oflc_small_catechism_required_string($row, 'abbreviation'),
            ':question_order' => oflc_small_catechism_required_int($row, 'question_order'),
            ':is_active' => oflc_small_catechism_required_int($row, 'is_active'),
        ]);
    }

    $pdo->commit();
    echo 'Imported ', count($rows), " small catechism rows.\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
