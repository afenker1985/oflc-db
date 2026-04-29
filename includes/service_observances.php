<?php
declare(strict_types=1);

require_once __DIR__ . '/liturgical_colors.php';

function oflc_service_strip_suggestion_marker(string $observanceName): string
{
    $observanceName = trim($observanceName);
    if ($observanceName === '') {
        return '';
    }

    return trim((string) (preg_replace('/\s+\((?:Sa|[SMTWRF])\s+\d{1,2}(?:\/\d{1,2})?\)\s*$/', '', $observanceName) ?? $observanceName));
}

function oflc_service_is_midweek_observance_name(string $observanceName): bool
{
    $normalizedName = strtolower(trim($observanceName));

    return $normalizedName !== ''
        && (strpos($normalizedName, 'midweek') !== false || strpos($normalizedName, 'midwk') !== false);
}

function oflc_service_get_calendar_year_from_service_date(?DateTimeImmutable $serviceDate): ?int
{
    if (!$serviceDate instanceof DateTimeImmutable) {
        return null;
    }

    return (int) $serviceDate->format('Y');
}

function oflc_service_store_midweek_observance_year(
    PDO $pdo,
    int $observanceId,
    string $observanceName,
    ?DateTimeImmutable $serviceDate
): void {
    if ($observanceId <= 0 || !oflc_service_is_midweek_observance_name($observanceName)) {
        return;
    }

    $calendarYear = oflc_service_get_calendar_year_from_service_date($serviceDate);
    if ($calendarYear === null) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE liturgical_calendar
         SET is_midweek = 1,
             year = :year
         WHERE id = :id
           AND is_active = 1'
    );
    $stmt->execute([
        ':id' => $observanceId,
        ':year' => $calendarYear,
    ]);
}

function oflc_service_fetch_observance_detail_from_row(PDO $pdo, array $observance): array
{
    $observanceId = (int) ($observance['id'] ?? 0);
    $readingStmt = $pdo->prepare(
        'SELECT id, set_name, year_pattern, old_testament, psalm, epistle, gospel
         FROM reading_sets
         WHERE liturgical_calendar_id = ?
           AND is_active = 1
         ORDER BY id'
    );
    $readingStmt->execute([$observanceId]);

    return [
        'observance' => $observance,
        'reading_sets' => $readingStmt->fetchAll(),
    ];
}

function oflc_service_fetch_observance_detail_by_id(PDO $pdo, int $observanceId): ?array
{
    if ($observanceId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, latin_name, logic_key, season, liturgical_color, notes
         FROM liturgical_calendar
         WHERE is_active = 1
           AND id = ?
         LIMIT 1'
    );
    $stmt->execute([$observanceId]);
    $observance = $stmt->fetch();

    if (!$observance) {
        return null;
    }

    return oflc_service_fetch_observance_detail_from_row($pdo, $observance);
}

function oflc_service_fetch_observance_detail_by_name(PDO $pdo, string $observanceName): ?array
{
    $observanceName = oflc_service_strip_suggestion_marker($observanceName);
    if ($observanceName === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, latin_name, logic_key, season, liturgical_color, notes
         FROM liturgical_calendar
         WHERE is_active = 1
           AND LOWER(name) = LOWER(?)
         ORDER BY id
         LIMIT 1'
    );
    $stmt->execute([$observanceName]);
    $observance = $stmt->fetch();

    if (!$observance) {
        return null;
    }

    return oflc_service_fetch_observance_detail_from_row($pdo, $observance);
}

function oflc_service_fetch_active_observance_details(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, name, latin_name, logic_key, season, liturgical_color, notes
         FROM liturgical_calendar
         WHERE is_active = 1
         ORDER BY name, id'
    );

    $detailsById = [];
    foreach ($stmt->fetchAll() as $observance) {
        $observanceId = (int) ($observance['id'] ?? 0);
        if ($observanceId <= 0) {
            continue;
        }

        $detailsById[$observanceId] = [
            'observance' => $observance,
            'reading_sets' => [],
        ];
    }

    if ($detailsById === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($detailsById), '?'));
    $readingStmt = $pdo->prepare(
        'SELECT id, liturgical_calendar_id, set_name, year_pattern, old_testament, psalm, epistle, gospel
         FROM reading_sets
         WHERE liturgical_calendar_id IN (' . $placeholders . ')
           AND is_active = 1
         ORDER BY liturgical_calendar_id, id'
    );
    $readingStmt->execute(array_keys($detailsById));

    foreach ($readingStmt->fetchAll() as $readingSet) {
        $observanceId = (int) ($readingSet['liturgical_calendar_id'] ?? 0);
        if (!isset($detailsById[$observanceId])) {
            continue;
        }

        $detailsById[$observanceId]['reading_sets'][] = $readingSet;
    }

    return $detailsById;
}

function oflc_service_fetch_observance_name_suggestions(PDO $pdo, string $observanceName, int $limit = 5): array
{
    $observanceName = oflc_service_strip_suggestion_marker($observanceName);
    if ($observanceName === '') {
        return [];
    }

    $searchValue = '%' . $observanceName . '%';
    $stmt = $pdo->prepare(
        'SELECT DISTINCT name
         FROM liturgical_calendar
         WHERE is_active = 1
           AND name LIKE ?
           AND LOWER(name) <> LOWER(?)
         ORDER BY name
         LIMIT ' . (int) $limit
    );
    $stmt->execute([$searchValue, $observanceName]);

    return array_values(array_filter(array_map(static function ($row): string {
        return trim((string) ($row['name'] ?? ''));
    }, $stmt->fetchAll()), static function (string $value): bool {
        return $value !== '';
    }));
}

function oflc_service_create_observance(
    PDO $pdo,
    string $observanceName,
    ?string $liturgicalColor = null,
    ?DateTimeImmutable $serviceDate = null
): int {
    $observanceName = oflc_service_strip_suggestion_marker($observanceName);
    if ($observanceName === '') {
        throw new InvalidArgumentException('Observance name is required.');
    }

    $liturgicalColor = $liturgicalColor !== null ? trim($liturgicalColor) : null;
    if ($liturgicalColor === '') {
        $liturgicalColor = null;
    }

    $isMidweek = oflc_service_is_midweek_observance_name($observanceName) ? 1 : 0;
    $calendarYear = $isMidweek === 1 ? oflc_service_get_calendar_year_from_service_date($serviceDate) : null;

    $stmt = $pdo->prepare(
        'INSERT INTO liturgical_calendar (
            name,
            latin_name,
            season,
            liturgical_color,
            calendar_date,
            logic_key,
            is_midweek,
            year,
            notes,
            is_active
         ) VALUES (
            :name,
            NULL,
            NULL,
            :liturgical_color,
            NULL,
            NULL,
            :is_midweek,
            :year,
            NULL,
            1
         )'
    );
    $stmt->execute([
        ':name' => $observanceName,
        ':liturgical_color' => $liturgicalColor,
        ':is_midweek' => $isMidweek,
        ':year' => $calendarYear,
    ]);

    return (int) $pdo->lastInsertId();
}

function oflc_service_normalize_new_reading_set_drafts(array $source): array
{
    $drafts = [];

    for ($index = 1; $index <= 1; $index++) {
        $draft = [
            'index' => $index,
            'set_name' => trim((string) ($source['new_reading_set_' . $index . '_set_name'] ?? '')),
            'year_pattern' => trim((string) ($source['new_reading_set_' . $index . '_year_pattern'] ?? '')),
            'old_testament' => trim((string) ($source['new_reading_set_' . $index . '_old_testament'] ?? '')),
            'psalm' => trim((string) ($source['new_reading_set_' . $index . '_psalm'] ?? '')),
            'epistle' => trim((string) ($source['new_reading_set_' . $index . '_epistle'] ?? '')),
            'gospel' => trim((string) ($source['new_reading_set_' . $index . '_gospel'] ?? '')),
        ];
        $draft['has_content'] = $draft['set_name'] !== ''
            || $draft['year_pattern'] !== ''
            || $draft['old_testament'] !== ''
            || $draft['psalm'] !== ''
            || $draft['epistle'] !== ''
            || $draft['gospel'] !== '';

        $drafts[$index] = $draft;
    }

    return $drafts;
}

function oflc_service_insert_new_reading_set_drafts(PDO $pdo, int $observanceId, array $drafts): array
{
    if ($observanceId <= 0) {
        return [];
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO reading_sets (
            liturgical_calendar_id,
            set_name,
            year_pattern,
            old_testament,
            psalm,
            epistle,
            gospel,
            is_active
         ) VALUES (
            :liturgical_calendar_id,
            :set_name,
            :year_pattern,
            :old_testament,
            :psalm,
            :epistle,
            :gospel,
            1
         )'
    );

    $insertedIds = [];
    foreach ($drafts as $draft) {
        if (!is_array($draft) || empty($draft['has_content'])) {
            continue;
        }

        $insertStmt->execute([
            ':liturgical_calendar_id' => $observanceId,
            ':set_name' => $draft['set_name'] !== '' ? $draft['set_name'] : null,
            ':year_pattern' => $draft['year_pattern'] !== '' ? $draft['year_pattern'] : null,
            ':old_testament' => $draft['old_testament'] !== '' ? $draft['old_testament'] : null,
            ':psalm' => $draft['psalm'] !== '' ? $draft['psalm'] : null,
            ':epistle' => $draft['epistle'] !== '' ? $draft['epistle'] : null,
            ':gospel' => $draft['gospel'] !== '' ? $draft['gospel'] : null,
        ]);

        $insertedIds[(int) ($draft['index'] ?? 0)] = (int) $pdo->lastInsertId();
    }

    return $insertedIds;
}
