<?php

declare(strict_types=1);

require_once __DIR__ . '/liturgical.php';

function oflc_church_year_ensure_settings_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS church_year_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            schedule_type ENUM("church_year", "calendar_year") NOT NULL DEFAULT "church_year",
            start_period_key VARCHAR(64) NOT NULL,
            start_period_label VARCHAR(100) NOT NULL,
            end_period_key VARCHAR(64) NOT NULL,
            end_period_label VARCHAR(100) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $legacyDateColumns = [];
    foreach (['start_date', 'end_date'] as $columnName) {
        $statement = $pdo->query("SHOW COLUMNS FROM church_year_settings LIKE " . $pdo->quote($columnName));
        if ($statement !== false && $statement->fetch() !== false) {
            $legacyDateColumns[] = 'DROP COLUMN `' . $columnName . '`';
        }
    }

    if ($legacyDateColumns !== []) {
        $pdo->exec('ALTER TABLE church_year_settings ' . implode(', ', $legacyDateColumns));
    }
}

function oflc_church_year_build_calendar_options(): array
{
    $monthNames = [
        'january',
        'february',
        'march',
        'april',
        'may',
        'june',
        'july',
        'august',
        'september',
        'october',
        'november',
        'december',
    ];
    $options = [];

    foreach ($monthNames as $index => $monthName) {
        $options[] = [
            'key' => $monthName,
            'label' => ucfirst($monthName),
            'order' => $index,
        ];
    }

    return $options;
}

function oflc_church_year_build_church_options(): array
{
    $seasonNames = [
        'advent' => 'Advent',
        'christmas' => 'Christmas',
        'epiphany' => 'Epiphany',
        'lent' => 'Lent',
        'easter' => 'Easter',
        'trinity' => 'Trinity',
    ];
    $options = [];
    $index = 0;

    foreach ($seasonNames as $key => $label) {
        $options[] = [
            'key' => $key,
            'label' => $label,
            'order' => $index,
        ];
        $index += 1;
    }

    return $options;
}

function oflc_church_year_get_configuration(PDO $pdo): array
{
    return [
        'church_year' => oflc_church_year_build_church_options(),
        'calendar_year' => oflc_church_year_build_calendar_options(),
        'defaults' => [
            'church_year' => [
                'start_period_key' => 'advent',
                'end_period_key' => 'trinity',
            ],
            'calendar_year' => [
                'start_period_key' => 'january',
                'end_period_key' => 'december',
            ],
        ],
    ];
}

function oflc_church_year_index_options(array $options): array
{
    $indexed = [];

    foreach ($options as $option) {
        $indexed[(string) $option['key']] = $option;
    }

    return $indexed;
}

function oflc_church_year_normalize_legacy_period_key(string $scheduleType, string $key): string
{
    $key = strtolower(trim($key));
    if ($key === '') {
        return '';
    }

    if ($scheduleType === 'church_year' && preg_match('/(?:^|:)(advent|christmas|epiphany|lent|easter|trinity)$/', $key, $matches)) {
        return $matches[1];
    }

    if ($scheduleType === 'calendar_year') {
        if (preg_match('/(?:^|:)(january|february|march|april|may|june|july|august|september|october|november|december)$/', $key, $matches)) {
            return $matches[1];
        }

        if (preg_match('/(\d{2})$/', $key, $matches)) {
            $monthNumber = (int) $matches[1];
            if ($monthNumber >= 1 && $monthNumber <= 12) {
                return strtolower((new DateTimeImmutable(sprintf('2000-%02d-01', $monthNumber)))->format('F'));
            }
        }
    }

    return $key;
}

function oflc_church_year_is_valid_range(array $options, string $startKey, string $endKey): bool
{
    $indexedOptions = oflc_church_year_index_options($options);

    return isset($indexedOptions[$startKey], $indexedOptions[$endKey]);
}

function oflc_church_year_fetch_saved_settings(PDO $pdo): ?array
{
    oflc_church_year_ensure_settings_table($pdo);

    $statement = $pdo->query(
        'SELECT
            id,
            schedule_type,
            start_period_key,
            start_period_label,
            end_period_key,
            end_period_label
         FROM church_year_settings
         WHERE id = 1'
    );
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function oflc_church_year_resolve_effective_settings(?array $savedSettings, array $configuration): array
{
    $scheduleType = isset($savedSettings['schedule_type']) ? (string) $savedSettings['schedule_type'] : 'church_year';
    if (!isset($configuration[$scheduleType]) || $configuration[$scheduleType] === []) {
        $scheduleType = 'church_year';
    }

    $options = $configuration[$scheduleType];
    $indexedOptions = oflc_church_year_index_options($options);
    $defaultKeys = $configuration['defaults'][$scheduleType];
    $startKey = isset($savedSettings['start_period_key'])
        ? oflc_church_year_normalize_legacy_period_key($scheduleType, (string) $savedSettings['start_period_key'])
        : $defaultKeys['start_period_key'];
    $endKey = isset($savedSettings['end_period_key'])
        ? oflc_church_year_normalize_legacy_period_key($scheduleType, (string) $savedSettings['end_period_key'])
        : $defaultKeys['end_period_key'];

    if (!isset($indexedOptions[$startKey])) {
        $startKey = $defaultKeys['start_period_key'];
    }

    if (!isset($indexedOptions[$endKey])) {
        $endKey = $defaultKeys['end_period_key'];
    }

    if (!oflc_church_year_is_valid_range($options, $startKey, $endKey)) {
        $startKey = $defaultKeys['start_period_key'];
        $endKey = $defaultKeys['end_period_key'];
    }

    $startOption = $indexedOptions[$startKey] ?? $options[0];
    $endOption = $indexedOptions[$endKey] ?? $options[count($options) - 1];

    return [
        'schedule_type' => $scheduleType,
        'start_period_key' => (string) $startOption['key'],
        'start_period_label' => (string) $startOption['label'],
        'end_period_key' => (string) $endOption['key'],
        'end_period_label' => (string) $endOption['label'],
    ];
}

function oflc_church_year_save_settings(PDO $pdo, array $settings): void
{
    oflc_church_year_ensure_settings_table($pdo);

    $statement = $pdo->prepare(
        'INSERT INTO church_year_settings (
            id,
            schedule_type,
            start_period_key,
            start_period_label,
            end_period_key,
            end_period_label
        ) VALUES (
            1,
            :schedule_type,
            :start_period_key,
            :start_period_label,
            :end_period_key,
            :end_period_label
        )
        ON DUPLICATE KEY UPDATE
            schedule_type = VALUES(schedule_type),
            start_period_key = VALUES(start_period_key),
            start_period_label = VALUES(start_period_label),
            end_period_key = VALUES(end_period_key),
            end_period_label = VALUES(end_period_label)'
    );
    $statement->execute([
        ':schedule_type' => (string) $settings['schedule_type'],
        ':start_period_key' => (string) $settings['start_period_key'],
        ':start_period_label' => (string) $settings['start_period_label'],
        ':end_period_key' => (string) $settings['end_period_key'],
        ':end_period_label' => (string) $settings['end_period_label'],
    ]);
}

function oflc_church_year_fetch_service_date_bounds(PDO $pdo): ?array
{
    $statement = $pdo->query(
        'SELECT MIN(service_date) AS min_date, MAX(service_date) AS max_date
         FROM service_db
         WHERE is_active = 1'
    );
    $row = $statement->fetch();

    $minDate = DateTimeImmutable::createFromFormat('Y-m-d', trim((string) ($row['min_date'] ?? '')));
    $maxDate = DateTimeImmutable::createFromFormat('Y-m-d', trim((string) ($row['max_date'] ?? '')));

    if (!$minDate instanceof DateTimeImmutable || !$maxDate instanceof DateTimeImmutable) {
        return null;
    }

    return [
        'min_date' => $minDate,
        'max_date' => $maxDate,
    ];
}

function oflc_church_year_get_calendar_period_keys(): array
{
    return [
        1 => 'january',
        2 => 'february',
        3 => 'march',
        4 => 'april',
        5 => 'may',
        6 => 'june',
        7 => 'july',
        8 => 'august',
        9 => 'september',
        10 => 'october',
        11 => 'november',
        12 => 'december',
    ];
}

function oflc_church_year_get_calendar_month_number(string $key): ?int
{
    foreach (oflc_church_year_get_calendar_period_keys() as $monthNumber => $monthKey) {
        if ($monthKey === $key) {
            return $monthNumber;
        }
    }

    return null;
}

function oflc_church_year_get_calendar_interval(string $startKey, string $endKey, int $startYear): ?array
{
    $startMonth = oflc_church_year_get_calendar_month_number($startKey);
    $endMonth = oflc_church_year_get_calendar_month_number($endKey);
    if ($startMonth === null || $endMonth === null) {
        return null;
    }

    $startDate = new DateTimeImmutable(sprintf('%04d-%02d-01', $startYear, $startMonth));
    $currentMonth = $startMonth;
    $currentYear = $startYear;

    for ($step = 0; $step < 12; $step++) {
        if ($currentMonth === $endMonth) {
            $endDate = (new DateTimeImmutable(sprintf('%04d-%02d-01', $currentYear, $currentMonth)))->modify('last day of this month');

            return [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ];
        }

        $currentMonth += 1;
        if ($currentMonth > 12) {
            $currentMonth = 1;
            $currentYear += 1;
        }
    }

    return null;
}

function oflc_church_year_get_church_period_start(string $key, int $year): ?DateTimeImmutable
{
    switch ($key) {
        case 'advent':
            return oflc_get_advent($year);
        case 'christmas':
            return new DateTimeImmutable(sprintf('%04d-12-25', $year));
        case 'epiphany':
            return new DateTimeImmutable(sprintf('%04d-01-06', $year));
        case 'lent':
            return oflc_get_easter($year)->modify('-46 days');
        case 'easter':
            return oflc_get_easter($year);
        case 'trinity':
            return oflc_get_easter($year)->modify('+56 days');
        default:
            return null;
    }
}

function oflc_church_year_get_next_church_period(string $key, int $year): ?array
{
    switch ($key) {
        case 'advent':
            return ['key' => 'christmas', 'year' => $year];
        case 'christmas':
            return ['key' => 'epiphany', 'year' => $year + 1];
        case 'epiphany':
            return ['key' => 'lent', 'year' => $year];
        case 'lent':
            return ['key' => 'easter', 'year' => $year];
        case 'easter':
            return ['key' => 'trinity', 'year' => $year];
        case 'trinity':
            return ['key' => 'advent', 'year' => $year];
        default:
            return null;
    }
}

function oflc_church_year_get_church_period_end(string $key, int $year): ?DateTimeImmutable
{
    switch ($key) {
        case 'lent':
            return oflc_get_easter($year)->modify('-2 days');
        case 'trinity':
            return oflc_get_advent($year)->modify('-7 days');
        default:
            $nextPeriod = oflc_church_year_get_next_church_period($key, $year);
            if ($nextPeriod === null) {
                return null;
            }

            $nextStart = oflc_church_year_get_church_period_start((string) $nextPeriod['key'], (int) $nextPeriod['year']);
            if (!$nextStart instanceof DateTimeImmutable) {
                return null;
            }

            return $nextStart->modify('-1 day');
    }
}

function oflc_church_year_get_church_interval(string $startKey, string $endKey, int $startYear): ?array
{
    $startDate = oflc_church_year_get_church_period_start($startKey, $startYear);
    if (!$startDate instanceof DateTimeImmutable) {
        return null;
    }

    $currentKey = $startKey;
    $currentYear = $startYear;

    for ($step = 0; $step < 12; $step++) {
        if ($currentKey === $endKey) {
            $endDate = oflc_church_year_get_church_period_end($currentKey, $currentYear);
            if ($endDate instanceof DateTimeImmutable) {
                return [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ];
            }

            return null;
        }

        $nextPeriod = oflc_church_year_get_next_church_period($currentKey, $currentYear);
        if ($nextPeriod === null) {
            return null;
        }

        $currentKey = (string) $nextPeriod['key'];
        $currentYear = (int) $nextPeriod['year'];
    }

    return null;
}

function oflc_church_year_format_filter_year_label(DateTimeImmutable $startDate, DateTimeImmutable $endDate): string
{
    $startYear = $startDate->format('Y');
    $endYear = $endDate->format('Y');

    return $startYear === $endYear ? $startYear : $startYear . '/' . $endYear;
}

function oflc_church_year_interval_intersects_bounds(array $interval, DateTimeImmutable $minDate, DateTimeImmutable $maxDate): bool
{
    return $interval['start_date'] <= $maxDate && $interval['end_date'] >= $minDate;
}

function oflc_church_year_build_filter_options(PDO $pdo, array $settings): array
{
    $bounds = oflc_church_year_fetch_service_date_bounds($pdo);
    if ($bounds === null) {
        return [];
    }

    $minYear = (int) $bounds['min_date']->format('Y');
    $maxYear = (int) $bounds['max_date']->format('Y');
    $options = [];

    for ($startYear = $minYear - 1; $startYear <= $maxYear + 1; $startYear++) {
        if ($settings['schedule_type'] === 'church_year') {
            $interval = oflc_church_year_get_church_interval(
                (string) $settings['start_period_key'],
                (string) $settings['end_period_key'],
                $startYear
            );
        } else {
            $interval = oflc_church_year_get_calendar_interval(
                (string) $settings['start_period_key'],
                (string) $settings['end_period_key'],
                $startYear
            );
        }

        if ($interval === null || !oflc_church_year_interval_intersects_bounds($interval, $bounds['min_date'], $bounds['max_date'])) {
            continue;
        }

        $key = (string) $startYear;
        $options[$key] = [
            'key' => $key,
            'label' => oflc_church_year_format_filter_year_label($interval['start_date'], $interval['end_date']),
            'start_date' => $interval['start_date']->format('Y-m-d'),
            'end_date' => $interval['end_date']->format('Y-m-d'),
            'display_range' => $interval['start_date']->format('F j, Y') . ' through ' . $interval['end_date']->format('F j, Y'),
        ];
    }

    uasort($options, static function (array $left, array $right): int {
        return strcmp((string) $right['start_date'], (string) $left['start_date']);
    });

    return array_values($options);
}

function oflc_church_year_find_filter_option(array $options, string $selectedKey): ?array
{
    foreach ($options as $option) {
        if ((string) ($option['key'] ?? '') === $selectedKey) {
            return $option;
        }
    }

    return null;
}

function oflc_church_year_find_filter_option_for_date(array $options, DateTimeImmutable $date): ?array
{
    $target = $date->format('Y-m-d');

    foreach ($options as $option) {
        $startDate = trim((string) ($option['start_date'] ?? ''));
        $endDate = trim((string) ($option['end_date'] ?? ''));
        if ($startDate === '' || $endDate === '') {
            continue;
        }

        if ($startDate <= $target && $target <= $endDate) {
            return $option;
        }
    }

    return null;
}
