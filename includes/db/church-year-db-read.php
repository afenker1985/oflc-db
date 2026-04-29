<?php
declare(strict_types=1);

require_once __DIR__ . '/../liturgical_keys.php';

function oflc_church_year_db_get_movable_logic_keys_by_range(int $startWeek, int $endWeek): array
{
    $keys = [];
    foreach (oflc_get_one_year_logic_keys() as $week => $weekdayKeys) {
        $week = (int) $week;
        if ($week < $startWeek || $week > $endWeek || !is_array($weekdayKeys)) {
            continue;
        }

        foreach ($weekdayKeys as $value) {
            foreach (is_array($value) ? $value : [$value] as $logicKey) {
                $logicKey = trim((string) $logicKey);
                if ($logicKey !== '') {
                    $keys[] = $logicKey;
                }
            }
        }
    }

    return array_values(array_unique($keys));
}

function oflc_church_year_db_get_fixed_logic_keys(): array
{
    $keys = [];
    foreach (oflc_get_fixed_logic_keys() as $value) {
        foreach (is_array($value) ? $value : [$value] as $logicKey) {
            $logicKey = trim((string) $logicKey);
            if ($logicKey !== '') {
                $keys[] = $logicKey;
            }
        }
    }

    return array_values(array_unique($keys));
}

function oflc_church_year_db_is_christmas_logic_key(string $logicKey): bool
{
    return strpos(trim($logicKey), 'christmas') === 0;
}

function oflc_church_year_db_get_christmas_fixed_logic_keys(): array
{
    return array_values(array_filter(oflc_church_year_db_get_fixed_logic_keys(), static function (string $logicKey): bool {
        return oflc_church_year_db_is_christmas_logic_key($logicKey);
    }));
}

function oflc_church_year_db_get_festival_fixed_logic_keys(): array
{
    return array_values(array_filter(oflc_church_year_db_get_fixed_logic_keys(), static function (string $logicKey): bool {
        return !oflc_church_year_db_is_christmas_logic_key($logicKey);
    }));
}

function oflc_church_year_db_get_festival_half_logic_keys(): array
{
    $movableKeys = oflc_church_year_db_get_movable_logic_keys_by_range(1, 29);
    $christmasKeys = oflc_church_year_db_get_christmas_fixed_logic_keys();
    $adventFourIndex = array_search('advent_4', $movableKeys, true);

    if ($adventFourIndex === false) {
        return array_values(array_unique(array_merge($movableKeys, $christmasKeys)));
    }

    return array_values(array_unique(array_merge(
        array_slice($movableKeys, 0, $adventFourIndex + 1),
        $christmasKeys,
        array_slice($movableKeys, $adventFourIndex + 1)
    )));
}

function oflc_church_year_db_get_sort_logic_key(string $logicKey): string
{
    $logicKey = trim($logicKey);
    $aliases = [
        'christmas_2' => 'sunday_after_new_years',
    ];

    return $aliases[$logicKey] ?? $logicKey;
}

function oflc_church_year_db_humanize_logic_key(string $logicKey): string
{
    $label = str_replace('_', ' ', trim($logicKey));
    $label = ucwords($label);
    $label = str_replace([' And ', ' Of ', ' Our ', ' The '], [' and ', ' of ', ' our ', ' the '], $label);

    return $label;
}

function oflc_church_year_db_normalize_key_match_value(string $value): string
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))) ?? '';
}

function oflc_church_year_db_midweek_name_sql(): string
{
    return "(LOWER(name) LIKE '%midweek%' OR LOWER(name) LIKE '%midwk%')";
}

function oflc_church_year_db_midweek_anchor_logic_key_from_name(string $name): ?string
{
    $normalized = strtolower(trim($name));
    if ($normalized === '') {
        return null;
    }

    if (preg_match('/\badvent\s+([1-4])\b.*\bmid(?:week|wk)\b/', $normalized, $matches)) {
        return 'advent_' . (int) $matches[1];
    }

    if (preg_match('/\blent\s+([1-5])\b.*\bmid(?:week|wk)\b/', $normalized, $matches)) {
        return 'lent_' . (int) $matches[1];
    }

    if (preg_match('/\bchristmas\s+([12])\b.*\bmid(?:week|wk)\b/', $normalized, $matches)) {
        return 'christmas_' . (int) $matches[1];
    }

    if (preg_match('/\bepiphany\s+([1-5])\b.*\bmid(?:week|wk)\b/', $normalized, $matches)) {
        return 'epiphany_' . (int) $matches[1];
    }

    return null;
}

function oflc_church_year_db_midweek_year_from_name(string $name): ?int
{
    if (preg_match('/\b(19|20)\d{2}\b/', $name, $matches)) {
        return (int) $matches[0];
    }

    return null;
}

function oflc_church_year_db_midweek_sort_tuple(array $entry): array
{
    $name = strtolower(trim((string) ($entry['name'] ?? '')));
    $year = (int) ($entry['year'] ?? 0);
    if ($year <= 0) {
        $year = oflc_church_year_db_midweek_year_from_name((string) ($entry['name'] ?? '')) ?? 0;
    }

    $seasonOrder = 99;
    $weekNumber = 99;

    if (preg_match('/\blent\s+([1-5])\b/', $name, $matches)) {
        $seasonOrder = 1;
        $weekNumber = (int) $matches[1];
    } elseif (preg_match('/\badvent\s+([1-4])\b/', $name, $matches)) {
        $seasonOrder = 2;
        $weekNumber = (int) $matches[1];
    }

    return [$year, $seasonOrder, $weekNumber, $name, (int) ($entry['id'] ?? 0)];
}

function oflc_church_year_db_get_fixed_festival_definitions(bool $includeChristmas = true): array
{
    $definitions = [];

    foreach (oflc_get_fixed_logic_keys() as $monthDay => $value) {
        $parts = explode('-', (string) $monthDay, 2);
        $month = isset($parts[0]) ? (int) $parts[0] : 0;
        $day = isset($parts[1]) ? (int) $parts[1] : 0;

        foreach (is_array($value) ? $value : [$value] as $logicKey) {
            $logicKey = trim((string) $logicKey);
            if ($logicKey === '') {
                continue;
            }
            if (!$includeChristmas && oflc_church_year_db_is_christmas_logic_key($logicKey)) {
                continue;
            }

            $definitions[] = [
                'logic_key' => $logicKey,
                'name' => oflc_church_year_db_humanize_logic_key($logicKey),
                'month_day' => $month > 0 && $day > 0 ? sprintf('%02d/%02d', $month, $day) : '',
            ];
        }
    }

    return $definitions;
}

function oflc_church_year_db_get_fixed_logic_key_month_day_map(): array
{
    $map = [];

    foreach (oflc_church_year_db_get_fixed_festival_definitions(true) as $definition) {
        $logicKey = trim((string) ($definition['logic_key'] ?? ''));
        $monthDay = trim((string) ($definition['month_day'] ?? ''));
        if ($logicKey !== '' && $monthDay !== '') {
            $map[$logicKey] = $monthDay;
        }
    }

    return $map;
}

function oflc_church_year_db_build_festival_sort_map(array $fixedKeys): array
{
    $sortKeys = array_values($fixedKeys);
    $saintAndrewIndex = array_search('saint_andrew', $sortKeys, true);

    if ($saintAndrewIndex !== false) {
        $sortKeys = array_merge(
            array_slice($sortKeys, $saintAndrewIndex),
            array_slice($sortKeys, 0, $saintAndrewIndex)
        );
    }

    return array_flip($sortKeys);
}

function oflc_church_year_db_fetch_active_midweek_years(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT DISTINCT year, name
         FROM liturgical_calendar
         WHERE is_active = 1
           AND is_midweek = 1
         ORDER BY year DESC'
    );

    $years = [];
    foreach ($stmt->fetchAll() as $row) {
        $year = isset($row['year']) ? (int) $row['year'] : 0;
        if ($year <= 0) {
            $year = oflc_church_year_db_midweek_year_from_name((string) ($row['name'] ?? '')) ?? 0;
        }

        if ($year > 0) {
            $years[$year] = $year;
        }
    }

    rsort($years, SORT_NUMERIC);

    return array_values(array_filter($years, static function (int $year): bool {
        return $year > 0;
    }));
}

function oflc_church_year_db_fetch_unique_seasons(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT DISTINCT season
         FROM liturgical_calendar
         WHERE is_active = 1
           AND season IS NOT NULL
           AND season <> ""
         ORDER BY season'
    );

    return array_values(array_filter(array_map(static function ($season): string {
        return trim((string) $season);
    }, $stmt->fetchAll(PDO::FETCH_COLUMN)), static function (string $season): bool {
        return $season !== '';
    }));
}

function oflc_church_year_db_filter_discrepancy_entries(array $entries, array $expectedLogicKeys): array
{
    $expectedByNormalized = [];
    foreach ($expectedLogicKeys as $expectedLogicKey) {
        $expectedLogicKey = trim((string) $expectedLogicKey);
        if ($expectedLogicKey === '') {
            continue;
        }

        $expectedByNormalized[oflc_church_year_db_normalize_key_match_value($expectedLogicKey)] = $expectedLogicKey;
    }

    $discrepancies = [];
    foreach ($entries as $entry) {
        $logicKey = trim((string) ($entry['logic_key'] ?? ''));
        $logicKeyMatchValue = oflc_church_year_db_normalize_key_match_value($logicKey);
        $nameMatchValue = oflc_church_year_db_normalize_key_match_value((string) ($entry['name'] ?? ''));
        $expectedLogicKey = $expectedByNormalized[$logicKeyMatchValue] ?? $expectedByNormalized[$nameMatchValue] ?? null;

        if ($expectedLogicKey !== null && $logicKey !== $expectedLogicKey) {
            $entry['expected_logic_key'] = $expectedLogicKey;
            $discrepancies[] = $entry;
        }
    }

    return $discrepancies;
}

function oflc_church_year_db_fetch_discrepancy_entries(PDO $pdo, string $section, array $entryIds, array $expectedLogicKeys): array
{
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static function (int $id): bool {
        return $id > 0;
    })));

    $params = [];
    $sql = 'SELECT id, name, latin_name, season, liturgical_color, calendar_date, logic_key, is_midweek, year, notes, is_active
            FROM liturgical_calendar
            WHERE is_active = 1
              AND is_midweek = 0';

    if ($section === 'church_half') {
        $sql .= ' AND (calendar_date IS NULL OR CAST(calendar_date AS CHAR) = "0000-00-00") AND season = ?';
        $params[] = 'Trinity';
    } elseif ($section === 'festivals') {
        $fixedMonthDays = array_keys(oflc_get_fixed_logic_keys());
        $sql .= ' AND calendar_date IS NOT NULL
                  AND CAST(calendar_date AS CHAR) <> "0000-00-00"
                  AND CONCAT(MONTH(calendar_date), "-", DAY(calendar_date)) IN (' . implode(', ', array_fill(0, count($fixedMonthDays), '?')) . ')';
        $params = array_merge($params, $fixedMonthDays);
    } elseif ($section === 'festival_half') {
        $festivalSeasons = ['Advent', 'Christmas', 'Epiphany', 'Gesimastide', 'Lent', 'Easter', 'Pentecost'];
        $sql .= ' AND (calendar_date IS NULL OR CAST(calendar_date AS CHAR) = "0000-00-00") AND season IN (' . implode(', ', array_fill(0, count($festivalSeasons), '?')) . ')';
        $params = array_merge($params, $festivalSeasons);
    } else {
        return [];
    }

    if ($entryIds !== []) {
        $sql .= ' AND id NOT IN (' . implode(', ', array_fill(0, count($entryIds), '?')) . ')';
        $params = array_merge($params, $entryIds);
    }

    $sql .= ' ORDER BY name, id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return oflc_church_year_db_filter_discrepancy_entries($stmt->fetchAll(), $expectedLogicKeys);
}

function oflc_church_year_db_fetch_entries(PDO $pdo, string $section, ?int $midweekYear = null): array
{
    $fixedKeys = oflc_church_year_db_get_festival_fixed_logic_keys();
    $festivalHalfKeys = oflc_church_year_db_get_festival_half_logic_keys();
    $churchHalfKeys = oflc_church_year_db_get_movable_logic_keys_by_range(30, 57);

    $params = [];
    $sql = 'SELECT id, name, latin_name, season, liturgical_color, calendar_date, logic_key, is_midweek, year, notes, is_active
            FROM liturgical_calendar
            WHERE is_active = 1';

    if ($section === 'midweeks') {
        $sql .= ' AND is_midweek = 1';
        if ($midweekYear !== null && $midweekYear > 0) {
            $sql .= ' AND (year = ? OR (year IS NULL AND name LIKE ?))';
            $params[] = $midweekYear;
            $params[] = '%' . $midweekYear . '%';
        }
    } elseif ($section === 'church_half') {
        $sql .= ' AND is_midweek = 0 AND logic_key IN (' . implode(', ', array_fill(0, count($churchHalfKeys), '?')) . ')';
        $params = array_merge($params, $churchHalfKeys);
    } elseif ($section === 'festivals') {
        $sql .= ' AND is_midweek = 0 AND (logic_key IN (' . implode(', ', array_fill(0, count($fixedKeys), '?')) . ') OR (calendar_date IS NOT NULL AND CAST(calendar_date AS CHAR) <> "0000-00-00" AND (logic_key IS NULL OR logic_key = "")))';
        $params = array_merge($params, $fixedKeys);
    } else {
        $sql .= ' AND is_midweek = 0 AND (logic_key IN (' . implode(', ', array_fill(0, count($festivalHalfKeys), '?')) . ') OR (season = ? AND NOT ' . oflc_church_year_db_midweek_name_sql() . ') OR ' . oflc_church_year_db_midweek_name_sql() . ')';
        $params = array_merge($params, $festivalHalfKeys);
        $params[] = 'Christmas';
    }

    $sql .= ' ORDER BY name, id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entries = $stmt->fetchAll();

    if ($section === 'festivals') {
        $entriesByLogicKey = [];
        foreach ($entries as $entry) {
            $logicKey = trim((string) ($entry['logic_key'] ?? ''));
            if ($logicKey !== '' && !isset($entriesByLogicKey[$logicKey])) {
                $entriesByLogicKey[$logicKey] = true;
            }
        }

        foreach (oflc_church_year_db_get_fixed_festival_definitions(false) as $definition) {
            $logicKey = (string) ($definition['logic_key'] ?? '');
            if ($logicKey === '' || isset($entriesByLogicKey[$logicKey])) {
                continue;
            }

            $entries[] = [
                'id' => 0,
                'name' => (string) ($definition['name'] ?? $logicKey),
                'latin_name' => null,
                'season' => null,
                'liturgical_color' => null,
                'calendar_date' => null,
                'logic_key' => $logicKey,
                'is_midweek' => 0,
                'year' => null,
                'notes' => 'Not set in liturgical_calendar.',
                'is_active' => 0,
                'is_missing' => true,
                'month_day' => (string) ($definition['month_day'] ?? ''),
            ];
        }
    }

    if ($section !== 'midweeks') {
        $matchedEntryIds = array_map(static function (array $entry): int {
            return (int) ($entry['id'] ?? 0);
        }, $entries);

        $expectedDiscrepancyKeys = [];
        if ($section === 'church_half') {
            $expectedDiscrepancyKeys = $churchHalfKeys;
        } elseif ($section === 'festivals') {
            $expectedDiscrepancyKeys = $fixedKeys;
        } elseif ($section === 'festival_half') {
            $expectedDiscrepancyKeys = $festivalHalfKeys;
        }

        foreach (oflc_church_year_db_fetch_discrepancy_entries($pdo, $section, $matchedEntryIds, $expectedDiscrepancyKeys) as $entry) {
            $entry['is_unmatched'] = true;
            $entries[] = $entry;
        }
    }

    if ($section === 'festival_half') {
        foreach ($entries as $index => $entry) {
            $name = strtolower(trim((string) ($entry['name'] ?? '')));
            if ((int) ($entry['is_midweek'] ?? 0) === 0 && (strpos($name, 'midweek') !== false || strpos($name, 'midwk') !== false)) {
                $entries[$index]['is_untagged_midweek'] = true;
                $anchorLogicKey = oflc_church_year_db_midweek_anchor_logic_key_from_name((string) ($entry['name'] ?? ''));
                if ($anchorLogicKey !== null) {
                    $entries[$index]['anchor_logic_key'] = $anchorLogicKey;
                }
            }
        }
    }

    $sortMap = [];
    if ($section === 'church_half') {
        $sortMap = array_flip($churchHalfKeys);
    } elseif ($section === 'festivals') {
        $sortMap = oflc_church_year_db_build_festival_sort_map($fixedKeys);
    } elseif ($section !== 'midweeks') {
        $sortMap = array_flip($festivalHalfKeys);
    }

    usort($entries, static function (array $left, array $right) use ($sortMap, $section): int {
        if ($section === 'midweeks') {
            return oflc_church_year_db_midweek_sort_tuple($left) <=> oflc_church_year_db_midweek_sort_tuple($right);
        }

        $leftSortKey = !empty($left['is_unmatched']) && trim((string) ($left['expected_logic_key'] ?? '')) !== ''
            ? (string) $left['expected_logic_key']
            : (string) ($left['logic_key'] ?? '');
        $rightSortKey = !empty($right['is_unmatched']) && trim((string) ($right['expected_logic_key'] ?? '')) !== ''
            ? (string) $right['expected_logic_key']
            : (string) ($right['logic_key'] ?? '');
        if (!empty($left['is_untagged_midweek']) && trim((string) ($left['anchor_logic_key'] ?? '')) !== '') {
            $leftSortKey = (string) $left['anchor_logic_key'];
        }
        if (!empty($right['is_untagged_midweek']) && trim((string) ($right['anchor_logic_key'] ?? '')) !== '') {
            $rightSortKey = (string) $right['anchor_logic_key'];
        }
        $leftSortKey = oflc_church_year_db_get_sort_logic_key($leftSortKey);
        $rightSortKey = oflc_church_year_db_get_sort_logic_key($rightSortKey);
        $leftOrder = $sortMap[$leftSortKey] ?? PHP_INT_MAX;
        $rightOrder = $sortMap[$rightSortKey] ?? PHP_INT_MAX;
        $leftUnmatched = !empty($left['is_unmatched']) ? 1 : 0;
        $rightUnmatched = !empty($right['is_unmatched']) ? 1 : 0;
        $leftAfterAnchor = !empty($left['is_untagged_midweek']) ? 1 : 0;
        $rightAfterAnchor = !empty($right['is_untagged_midweek']) ? 1 : 0;

        return [$leftOrder, $leftAfterAnchor, $leftUnmatched, $left['calendar_date'] ?? '', $left['name'] ?? '', $left['id'] ?? 0]
            <=> [$rightOrder, $rightAfterAnchor, $rightUnmatched, $right['calendar_date'] ?? '', $right['name'] ?? '', $right['id'] ?? 0];
    });

    return $entries;
}

function oflc_church_year_db_fetch_reading_sets_for_entries(PDO $pdo, array $entryIds): array
{
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static function (int $id): bool {
        return $id > 0;
    })));

    if ($entryIds === []) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT id, liturgical_calendar_id, set_name, year_pattern, old_testament, psalm, epistle, gospel, is_active
         FROM reading_sets
         WHERE is_active = 1
           AND liturgical_calendar_id IN (' . implode(', ', array_fill(0, count($entryIds), '?')) . ')
         ORDER BY liturgical_calendar_id, id'
    );
    $stmt->execute($entryIds);

    $setsByEntry = [];
    foreach ($stmt->fetchAll() as $row) {
        $entryId = (int) ($row['liturgical_calendar_id'] ?? 0);
        if ($entryId > 0) {
            $setsByEntry[$entryId][] = $row;
        }
    }

    return $setsByEntry;
}
