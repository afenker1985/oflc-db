<?php
declare(strict_types=1);

function oflc_church_year_db_nullable_string($value): ?string
{
    $value = trim((string) $value);

    return $value === '' ? null : $value;
}

function oflc_church_year_db_nullable_int($value): ?int
{
    $value = trim((string) $value);
    if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
        return null;
    }

    return (int) $value;
}

function oflc_church_year_db_logic_key_from_name(string $name): ?string
{
    $name = strtolower(trim($name));
    if ($name === '') {
        return null;
    }

    $name = preg_replace('/\bst[.]?\b/', 'saint', $name) ?? $name;
    $name = preg_replace('/[^a-z0-9]+/', '_', $name) ?? $name;
    $name = trim($name, '_');
    $name = preg_replace('/_+/', '_', $name) ?? $name;

    return $name === '' ? null : $name;
}

function oflc_church_year_db_year_from_name(string $name): ?int
{
    if (preg_match('/\b(19|20)\d{2}\b/', $name, $matches)) {
        return (int) $matches[0];
    }

    return null;
}

function oflc_church_year_db_resolve_year(array $data): ?int
{
    $year = oflc_church_year_db_nullable_int($data['year'] ?? '');
    if ($year !== null) {
        return $year;
    }

    if (!empty($data['is_midweek'])) {
        return oflc_church_year_db_year_from_name((string) ($data['name'] ?? ''));
    }

    return null;
}

function oflc_church_year_db_update_entry(PDO $pdo, int $entryId, array $data): void
{
    if ($entryId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE liturgical_calendar
         SET name = :name,
             latin_name = :latin_name,
             season = :season,
             liturgical_color = :liturgical_color,
             logic_key = :logic_key,
             is_midweek = :is_midweek,
             year = :year,
             notes = :notes
         WHERE id = :id
           AND is_active = 1'
    );
    $stmt->execute([
        ':id' => $entryId,
        ':name' => trim((string) ($data['name'] ?? '')),
        ':latin_name' => oflc_church_year_db_nullable_string($data['latin_name'] ?? ''),
        ':season' => oflc_church_year_db_nullable_string($data['season'] ?? ''),
        ':liturgical_color' => oflc_church_year_db_nullable_string($data['liturgical_color'] ?? ''),
        ':logic_key' => oflc_church_year_db_logic_key_from_name((string) ($data['name'] ?? '')),
        ':is_midweek' => !empty($data['is_midweek']) ? 1 : 0,
        ':year' => oflc_church_year_db_resolve_year($data),
        ':notes' => oflc_church_year_db_nullable_string($data['notes'] ?? ''),
    ]);
}

function oflc_church_year_db_insert_entry(PDO $pdo, array $data): int
{
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
            :latin_name,
            :season,
            :liturgical_color,
            :calendar_date,
            :logic_key,
            :is_midweek,
            :year,
            :notes,
            1
         )'
    );
    $stmt->execute([
        ':name' => trim((string) ($data['name'] ?? '')),
        ':latin_name' => oflc_church_year_db_nullable_string($data['latin_name'] ?? ''),
        ':season' => oflc_church_year_db_nullable_string($data['season'] ?? ''),
        ':liturgical_color' => oflc_church_year_db_nullable_string($data['liturgical_color'] ?? ''),
        ':calendar_date' => null,
        ':logic_key' => oflc_church_year_db_logic_key_from_name((string) ($data['name'] ?? '')),
        ':is_midweek' => !empty($data['is_midweek']) ? 1 : 0,
        ':year' => oflc_church_year_db_resolve_year($data),
        ':notes' => oflc_church_year_db_nullable_string($data['notes'] ?? ''),
    ]);

    return (int) $pdo->lastInsertId();
}

function oflc_church_year_db_update_reading_set(PDO $pdo, int $readingSetId, int $entryId, array $data): void
{
    if ($readingSetId <= 0 || $entryId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE reading_sets
         SET set_name = :set_name,
             year_pattern = :year_pattern,
             old_testament = :old_testament,
             psalm = :psalm,
             epistle = :epistle,
             gospel = :gospel
         WHERE id = :id
           AND liturgical_calendar_id = :liturgical_calendar_id
           AND is_active = 1'
    );
    $stmt->execute([
        ':id' => $readingSetId,
        ':liturgical_calendar_id' => $entryId,
        ':set_name' => oflc_church_year_db_nullable_string($data['set_name'] ?? ''),
        ':year_pattern' => oflc_church_year_db_nullable_string($data['year_pattern'] ?? ''),
        ':old_testament' => oflc_church_year_db_nullable_string($data['old_testament'] ?? ''),
        ':psalm' => oflc_church_year_db_nullable_string($data['psalm'] ?? ''),
        ':epistle' => oflc_church_year_db_nullable_string($data['epistle'] ?? ''),
        ':gospel' => oflc_church_year_db_nullable_string($data['gospel'] ?? ''),
    ]);
}

function oflc_church_year_db_insert_reading_set(PDO $pdo, int $entryId, array $data): ?int
{
    if ($entryId <= 0) {
        return null;
    }

    $hasContent = false;
    foreach (['set_name', 'year_pattern', 'old_testament', 'psalm', 'epistle', 'gospel'] as $key) {
        if (trim((string) ($data[$key] ?? '')) !== '') {
            $hasContent = true;
            break;
        }
    }

    if (!$hasContent) {
        return null;
    }

    $stmt = $pdo->prepare(
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
    $stmt->execute([
        ':liturgical_calendar_id' => $entryId,
        ':set_name' => oflc_church_year_db_nullable_string($data['set_name'] ?? ''),
        ':year_pattern' => oflc_church_year_db_nullable_string($data['year_pattern'] ?? ''),
        ':old_testament' => oflc_church_year_db_nullable_string($data['old_testament'] ?? ''),
        ':psalm' => oflc_church_year_db_nullable_string($data['psalm'] ?? ''),
        ':epistle' => oflc_church_year_db_nullable_string($data['epistle'] ?? ''),
        ':gospel' => oflc_church_year_db_nullable_string($data['gospel'] ?? ''),
    ]);

    return (int) $pdo->lastInsertId();
}
