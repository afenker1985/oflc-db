<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

const OFLC_DEFAULT_SERVICE_CSV = '/Users/aaronfenker/Desktop/OF Hymn DB/OF Hymn DB/service_db-Table 1.csv';
const OFLC_DEFAULT_USAGE_CSV = '/Users/aaronfenker/Desktop/OF Hymn DB/hymn_usage_db_filled_from_tsv.csv';
const OFLC_DEFAULT_MIGRATION_SQL = __DIR__ . '/../sql/2026-04-18_add_service_and_hymn_usage_tables.sql';

const OFLC_SERVICE_HEADERS = [
    'id',
    'service_date',
    'liturgical_calendar_id',
    'passion_reading_id',
    'service_setting_id',
    'service_order',
    'copied_from_service_id',
    'last_updated',
    'is_active',
];

const OFLC_USAGE_HEADERS = [
    'id',
    'sunday_id',
    'hymn_id',
    'slot_id',
    'sort_order',
    'version_number',
    'created_at',
    'last_updated',
    'is_active',
];

function oflc_parse_cli_options(array $argv): array
{
    $options = [
        'service-csv' => OFLC_DEFAULT_SERVICE_CSV,
        'usage-csv' => OFLC_DEFAULT_USAGE_CSV,
        'migration-sql' => OFLC_DEFAULT_MIGRATION_SQL,
        'dry-run' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') {
            $options['dry-run'] = true;
            continue;
        }

        if ($argument === '--help') {
            oflc_print_usage();
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

function oflc_print_usage(): void
{
    echo <<<TXT
Usage:
  php scripts/import_service_tables.php [--service-csv=/path/to/service.csv] [--usage-csv=/path/to/usage.csv] [--migration-sql=/path/to/migration.sql] [--dry-run]

TXT;
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

function oflc_read_csv_rows(string $path, array $expected_headers): array
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

    if ($header !== $expected_headers) {
        fclose($handle);
        throw new RuntimeException(
            'Unexpected CSV headers in ' . $path . '. Expected [' . implode(', ', $expected_headers) . '] but found [' . implode(', ', $header) . '].'
        );
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

function oflc_nullable_int(array $row, string $field): ?int
{
    $value = trim((string) ($row[$field] ?? ''));
    if ($value === '') {
        return null;
    }

    if (!ctype_digit($value)) {
        throw new RuntimeException("Field {$field} must be an integer; received '{$value}'.");
    }

    return (int) $value;
}

function oflc_required_int(array $row, string $field): int
{
    $value = oflc_nullable_int($row, $field);
    if ($value === null) {
        throw new RuntimeException("Field {$field} is required.");
    }

    return $value;
}

function oflc_required_date(array $row, string $field): string
{
    $value = trim((string) ($row[$field] ?? ''));
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if ($value === '' || !$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException("Field {$field} must use YYYY-MM-DD; received '{$value}'.");
    }

    return $value;
}

function oflc_fetch_id_set(PDO $pdo, string $table): array
{
    $ids = $pdo->query("SELECT id FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    $set = [];
    foreach ($ids as $id) {
        $set[(int) $id] = true;
    }

    return $set;
}

function oflc_validate_service_rows(array $rows, array $liturgicalIds, array $passionIds, array $settingIds): void
{
    $serviceIds = [];
    foreach ($rows as $row) {
        $id = oflc_required_int($row, 'id');
        $serviceIds[$id] = true;
        oflc_required_date($row, 'service_date');
        oflc_required_int($row, 'service_order');
        oflc_required_date($row, 'last_updated');
        oflc_required_int($row, 'is_active');

        $liturgicalId = oflc_nullable_int($row, 'liturgical_calendar_id');
        if ($liturgicalId !== null && !isset($liturgicalIds[$liturgicalId])) {
            throw new RuntimeException("service_db row {$id} references missing liturgical_calendar_id {$liturgicalId}.");
        }

        $passionId = oflc_nullable_int($row, 'passion_reading_id');
        if ($passionId !== null && !isset($passionIds[$passionId])) {
            throw new RuntimeException("service_db row {$id} references missing passion_reading_id {$passionId}.");
        }

        $settingId = oflc_nullable_int($row, 'service_setting_id');
        if ($settingId !== null && !isset($settingIds[$settingId])) {
            throw new RuntimeException("service_db row {$id} references missing service_setting_id {$settingId}.");
        }
    }

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $copiedFromId = oflc_nullable_int($row, 'copied_from_service_id');
        if ($copiedFromId !== null && !isset($serviceIds[$copiedFromId])) {
            throw new RuntimeException("service_db row {$id} references missing copied_from_service_id {$copiedFromId}.");
        }
    }
}

function oflc_validate_usage_rows(array $rows, array $serviceIds, array $hymnIds, array $slotIds): void
{
    foreach ($rows as $row) {
        $id = oflc_required_int($row, 'id');
        $serviceId = oflc_required_int($row, 'sunday_id');
        if (!isset($serviceIds[$serviceId])) {
            throw new RuntimeException("hymn_usage_db row {$id} references missing service id {$serviceId}.");
        }

        $hymnId = oflc_required_int($row, 'hymn_id');
        if (!isset($hymnIds[$hymnId])) {
            throw new RuntimeException("hymn_usage_db row {$id} references missing hymn_id {$hymnId}.");
        }

        $slotId = oflc_required_int($row, 'slot_id');
        if (!isset($slotIds[$slotId])) {
            throw new RuntimeException("hymn_usage_db row {$id} references missing slot_id {$slotId}.");
        }

        oflc_required_int($row, 'sort_order');
        oflc_required_int($row, 'version_number');
        oflc_required_date($row, 'created_at');
        oflc_required_date($row, 'last_updated');
        oflc_required_int($row, 'is_active');
    }
}

function oflc_import_service_rows(PDO $pdo, array $rows): array
{
    $insert = $pdo->prepare(
        'INSERT INTO service_db (
            id,
            service_date,
            liturgical_calendar_id,
            passion_reading_id,
            service_setting_id,
            service_order,
            copied_from_service_id,
            last_updated,
            is_active
        ) VALUES (
            :id,
            :service_date,
            :liturgical_calendar_id,
            :passion_reading_id,
            :service_setting_id,
            :service_order,
            NULL,
            :last_updated,
            :is_active
        )
        ON DUPLICATE KEY UPDATE
            service_date = VALUES(service_date),
            liturgical_calendar_id = VALUES(liturgical_calendar_id),
            passion_reading_id = VALUES(passion_reading_id),
            service_setting_id = VALUES(service_setting_id),
            service_order = VALUES(service_order),
            last_updated = VALUES(last_updated),
            is_active = VALUES(is_active)'
    );

    $updateCopiedFrom = $pdo->prepare(
        'UPDATE service_db
         SET copied_from_service_id = :copied_from_service_id
         WHERE id = :id'
    );

    $inserted = 0;
    $copiedReferences = 0;

    foreach ($rows as $row) {
        $insert->execute([
            ':id' => oflc_required_int($row, 'id'),
            ':service_date' => oflc_required_date($row, 'service_date'),
            ':liturgical_calendar_id' => oflc_nullable_int($row, 'liturgical_calendar_id'),
            ':passion_reading_id' => oflc_nullable_int($row, 'passion_reading_id'),
            ':service_setting_id' => oflc_nullable_int($row, 'service_setting_id'),
            ':service_order' => oflc_required_int($row, 'service_order'),
            ':last_updated' => oflc_required_date($row, 'last_updated'),
            ':is_active' => oflc_required_int($row, 'is_active'),
        ]);
        $inserted++;
    }

    foreach ($rows as $row) {
        $copiedFrom = oflc_nullable_int($row, 'copied_from_service_id');
        if ($copiedFrom === null) {
            continue;
        }

        $updateCopiedFrom->execute([
            ':id' => oflc_required_int($row, 'id'),
            ':copied_from_service_id' => $copiedFrom,
        ]);
        $copiedReferences++;
    }

    return [
        'rows' => $inserted,
        'copied_refs' => $copiedReferences,
    ];
}

function oflc_import_usage_rows(PDO $pdo, array $rows): int
{
    $insert = $pdo->prepare(
        'INSERT INTO hymn_usage_db (
            id,
            sunday_id,
            hymn_id,
            slot_id,
            sort_order,
            version_number,
            created_at,
            last_updated,
            is_active
        ) VALUES (
            :id,
            :sunday_id,
            :hymn_id,
            :slot_id,
            :sort_order,
            :version_number,
            :created_at,
            :last_updated,
            :is_active
        )
        ON DUPLICATE KEY UPDATE
            sunday_id = VALUES(sunday_id),
            hymn_id = VALUES(hymn_id),
            slot_id = VALUES(slot_id),
            sort_order = VALUES(sort_order),
            version_number = VALUES(version_number),
            created_at = VALUES(created_at),
            last_updated = VALUES(last_updated),
            is_active = VALUES(is_active)'
    );

    $count = 0;
    foreach ($rows as $row) {
        $insert->execute([
            ':id' => oflc_required_int($row, 'id'),
            ':sunday_id' => oflc_required_int($row, 'sunday_id'),
            ':hymn_id' => oflc_required_int($row, 'hymn_id'),
            ':slot_id' => oflc_required_int($row, 'slot_id'),
            ':sort_order' => oflc_required_int($row, 'sort_order'),
            ':version_number' => oflc_required_int($row, 'version_number'),
            ':created_at' => oflc_required_date($row, 'created_at'),
            ':last_updated' => oflc_required_date($row, 'last_updated'),
            ':is_active' => oflc_required_int($row, 'is_active'),
        ]);
        $count++;
    }

    return $count;
}

function oflc_reset_auto_increment(PDO $pdo, string $table): void
{
    $nextId = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM `{$table}`")->fetchColumn();
    $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = {$nextId}");
}

try {
    $options = oflc_parse_cli_options($argv);
    $serviceRows = oflc_read_csv_rows($options['service-csv'], OFLC_SERVICE_HEADERS);
    $usageRows = oflc_read_csv_rows($options['usage-csv'], OFLC_USAGE_HEADERS);

    oflc_execute_sql_file($pdo, $options['migration-sql']);

    $liturgicalIds = oflc_fetch_id_set($pdo, 'liturgical_calendar');
    $passionIds = oflc_fetch_id_set($pdo, 'passion_reading_db');
    $settingIds = oflc_fetch_id_set($pdo, 'service_settings_db');
    $hymnIds = oflc_fetch_id_set($pdo, 'hymn_db');
    $slotIds = oflc_fetch_id_set($pdo, 'hymn_slot_db');

    oflc_validate_service_rows($serviceRows, $liturgicalIds, $passionIds, $settingIds);

    $serviceIdSet = [];
    foreach ($serviceRows as $row) {
        $serviceIdSet[(int) $row['id']] = true;
    }

    oflc_validate_usage_rows($usageRows, $serviceIdSet, $hymnIds, $slotIds);

    if ($options['dry-run']) {
        echo 'Dry run complete.', PHP_EOL;
        echo 'service_db rows ready: ' . count($serviceRows) . PHP_EOL;
        echo 'hymn_usage_db rows ready: ' . count($usageRows) . PHP_EOL;
        exit(0);
    }

    $pdo->beginTransaction();
    $serviceImport = oflc_import_service_rows($pdo, $serviceRows);
    $usageImportCount = oflc_import_usage_rows($pdo, $usageRows);
    $pdo->commit();
    oflc_reset_auto_increment($pdo, 'service_db');
    oflc_reset_auto_increment($pdo, 'hymn_usage_db');

    echo 'Imported service_db rows: ' . $serviceImport['rows'] . PHP_EOL;
    echo 'Imported service_db copied-from links: ' . $serviceImport['copied_refs'] . PHP_EOL;
    echo 'Imported hymn_usage_db rows: ' . $usageImportCount . PHP_EOL;
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
