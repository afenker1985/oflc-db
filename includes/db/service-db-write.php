<?php
declare(strict_types=1);

// Inserts active Small Catechism selections for a service in the submitted order.
function oflc_service_db_insert_service_small_catechism_links(PDO $pdo, int $serviceId, array $smallCatechismIds, string $today): void
{
    if ($serviceId <= 0 || $smallCatechismIds === []) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO service_small_catechism_db (
            service_id,
            small_catechism_id,
            sort_order,
            created_at,
            last_updated,
            is_active
         ) VALUES (
            :service_id,
            :small_catechism_id,
            :sort_order,
            :created_at,
            :last_updated,
            1
         )'
    );

    foreach (array_values($smallCatechismIds) as $index => $smallCatechismId) {
        $stmt->execute([
            ':service_id' => $serviceId,
            ':small_catechism_id' => (int) $smallCatechismId,
            ':sort_order' => $index + 1,
            ':created_at' => $today,
            ':last_updated' => $today,
        ]);
    }
}

// Creates the service-to-Passion-reading link table if it is missing.
function oflc_service_db_ensure_service_passion_reading_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS service_passion_reading_db (
            id INT AUTO_INCREMENT PRIMARY KEY,
            service_id INT NOT NULL,
            passion_reading_id INT NOT NULL,
            sort_order INT NOT NULL,
            created_at DATE NOT NULL,
            last_updated DATE NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            INDEX idx_service_passion_reading_active_service (is_active, service_id)
        )'
    );
}

// Inserts active Passion reading selections for a service in the submitted order.
function oflc_service_db_insert_service_passion_reading_links(PDO $pdo, int $serviceId, array $passionReadingIds, string $today): void
{
    if ($serviceId <= 0 || $passionReadingIds === []) {
        return;
    }

    oflc_service_db_ensure_service_passion_reading_table($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO service_passion_reading_db (
            service_id,
            passion_reading_id,
            sort_order,
            created_at,
            last_updated,
            is_active
         ) VALUES (
            :service_id,
            :passion_reading_id,
            :sort_order,
            :created_at,
            :last_updated,
            1
         )'
    );

    foreach (array_values($passionReadingIds) as $index => $passionReadingId) {
        $stmt->execute([
            ':service_id' => $serviceId,
            ':passion_reading_id' => (int) $passionReadingId,
            ':sort_order' => $index + 1,
            ':created_at' => $today,
            ':last_updated' => $today,
        ]);
    }
}

// Updates the text fields for an active reading set belonging to an observance.
function oflc_service_db_update_existing_reading_set(PDO $pdo, int $readingSetId, int $liturgicalCalendarId, array $draft): void
{
    if ($readingSetId <= 0 || $liturgicalCalendarId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE reading_sets
         SET psalm = :psalm,
             old_testament = :old_testament,
             epistle = :epistle,
             gospel = :gospel
         WHERE id = :id
           AND liturgical_calendar_id = :liturgical_calendar_id
           AND is_active = 1'
    );
    $stmt->execute([
        ':id' => $readingSetId,
        ':liturgical_calendar_id' => $liturgicalCalendarId,
        ':psalm' => trim((string) ($draft['psalm'] ?? '')),
        ':old_testament' => trim((string) ($draft['old_testament'] ?? '')),
        ':epistle' => trim((string) ($draft['epistle'] ?? '')),
        ':gospel' => trim((string) ($draft['gospel'] ?? '')),
    ]);
}

// Inserts one service row and returns its new id.
function oflc_service_db_insert_service(PDO $pdo, array $serviceData): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO service_db (
            service_date,
            liturgical_calendar_id,
            passion_reading_id,
            small_catechism_id,
            selected_reading_set_id,
            service_setting_id,
            leader_id,
            service_order,
            copied_from_service_id,
            last_updated,
            is_active
         ) VALUES (
            :service_date,
            :liturgical_calendar_id,
            :passion_reading_id,
            :small_catechism_id,
            :selected_reading_set_id,
            :service_setting_id,
            :leader_id,
            1,
            :copied_from_service_id,
            :last_updated,
            1
         )'
    );
    $stmt->execute([
        ':service_date' => $serviceData['service_date'] ?? null,
        ':liturgical_calendar_id' => $serviceData['liturgical_calendar_id'] ?? null,
        ':passion_reading_id' => $serviceData['passion_reading_id'] ?? null,
        ':small_catechism_id' => $serviceData['small_catechism_id'] ?? null,
        ':selected_reading_set_id' => $serviceData['selected_reading_set_id'] ?? null,
        ':service_setting_id' => $serviceData['service_setting_id'] ?? null,
        ':leader_id' => $serviceData['leader_id'] ?? null,
        ':copied_from_service_id' => $serviceData['copied_from_service_id'] ?? null,
        ':last_updated' => $serviceData['last_updated'] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

// Inserts hymn usage rows for a service with the supplied version number.
function oflc_service_db_insert_hymn_usage_rows(PDO $pdo, int $serviceId, array $hymnEntries, string $today, int $versionNumber = 1): void
{
    if ($serviceId <= 0 || $hymnEntries === []) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO hymn_usage_db (
            sunday_id,
            hymn_id,
            slot_id,
            sort_order,
            stanzas,
            version_number,
            created_at,
            last_updated,
            is_active
         ) VALUES (
            :sunday_id,
            :hymn_id,
            :slot_id,
            :sort_order,
            :stanzas,
            :version_number,
            :created_at,
            :last_updated,
            1
         )'
    );

    foreach ($hymnEntries as $entry) {
        $stmt->execute([
            ':sunday_id' => $serviceId,
            ':hymn_id' => $entry['hymn_id'],
            ':slot_id' => $entry['slot_id'],
            ':sort_order' => $entry['sort_order'],
            ':stanzas' => $entry['stanzas'],
            ':version_number' => $versionNumber,
            ':created_at' => $today,
            ':last_updated' => $today,
        ]);
    }
}

// Updates one active service row with submitted service details.
function oflc_service_db_update_service(PDO $pdo, int $serviceId, array $serviceData): void
{
    if ($serviceId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE service_db
         SET service_date = :service_date,
             liturgical_calendar_id = :liturgical_calendar_id,
             passion_reading_id = :passion_reading_id,
             small_catechism_id = :small_catechism_id,
             selected_reading_set_id = :selected_reading_set_id,
             service_setting_id = :service_setting_id,
             leader_id = :leader_id,
             copied_from_service_id = :copied_from_service_id,
             last_updated = :last_updated
         WHERE id = :id
           AND is_active = 1'
    );
    $stmt->execute([
        ':id' => $serviceId,
        ':service_date' => $serviceData['service_date'] ?? null,
        ':liturgical_calendar_id' => $serviceData['liturgical_calendar_id'] ?? null,
        ':passion_reading_id' => $serviceData['passion_reading_id'] ?? null,
        ':small_catechism_id' => $serviceData['small_catechism_id'] ?? null,
        ':selected_reading_set_id' => $serviceData['selected_reading_set_id'] ?? null,
        ':service_setting_id' => $serviceData['service_setting_id'] ?? null,
        ':leader_id' => $serviceData['leader_id'] ?? null,
        ':copied_from_service_id' => $serviceData['copied_from_service_id'] ?? null,
        ':last_updated' => $serviceData['last_updated'] ?? null,
    ]);
}

// Deactivates active hymn usage rows for a service.
function oflc_service_db_deactivate_hymn_usage(PDO $pdo, int $serviceId, string $today): void
{
    $stmt = $pdo->prepare(
        'UPDATE hymn_usage_db
         SET is_active = 0,
             last_updated = :last_updated
         WHERE sunday_id = :service_id
           AND is_active = 1'
    );
    $stmt->execute([
        ':last_updated' => $today,
        ':service_id' => $serviceId,
    ]);
}

// Deactivates active Small Catechism links for a service.
function oflc_service_db_deactivate_service_small_catechism_links(PDO $pdo, int $serviceId, string $today): void
{
    $stmt = $pdo->prepare(
        'UPDATE service_small_catechism_db
         SET is_active = 0,
             last_updated = :last_updated
         WHERE service_id = :service_id
           AND is_active = 1'
    );
    $stmt->execute([
        ':last_updated' => $today,
        ':service_id' => $serviceId,
    ]);
}

// Deactivates active Passion reading links for a service when the link table exists.
function oflc_service_db_deactivate_service_passion_reading_links(PDO $pdo, int $serviceId, string $today): void
{
    if (!function_exists('oflc_service_db_service_passion_reading_table_exists')
        || !oflc_service_db_service_passion_reading_table_exists($pdo)
    ) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE service_passion_reading_db
         SET is_active = 0,
             last_updated = :last_updated
         WHERE service_id = :service_id
           AND is_active = 1'
    );
    $stmt->execute([
        ':last_updated' => $today,
        ':service_id' => $serviceId,
    ]);
}

// Gets the next hymn usage version number for a service.
function oflc_service_db_fetch_next_hymn_usage_version(PDO $pdo, int $serviceId): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(version_number), 0) + 1
         FROM hymn_usage_db
         WHERE sunday_id = ?'
    );
    $stmt->execute([$serviceId]);

    return max((int) $stmt->fetchColumn(), 1);
}

// Restores a previously inactive service and its latest inactive related rows.
function oflc_service_db_restore_service(PDO $pdo, int $serviceId, string $today): void
{
    $serviceStmt = $pdo->prepare(
        'UPDATE service_db
         SET is_active = 1,
             last_updated = :today
         WHERE id = :id
           AND is_active = 0'
    );
    $hymnStmt = $pdo->prepare(
        'UPDATE hymn_usage_db
         SET is_active = 1,
             last_updated = :today
         WHERE sunday_id = :service_id
           AND is_active = 0
           AND version_number = (
               SELECT version_number
               FROM (
                   SELECT MAX(version_number) AS version_number
                   FROM hymn_usage_db
                   WHERE sunday_id = :service_id_for_version
               ) AS latest_version
           )'
    );
    $catechismStmt = $pdo->prepare(
        'UPDATE service_small_catechism_db
         SET is_active = 1,
             last_updated = :today
         WHERE service_id = :service_id
           AND is_active = 0
           AND last_updated = (
               SELECT latest_last_updated
               FROM (
                   SELECT MAX(last_updated) AS latest_last_updated
                   FROM service_small_catechism_db
                   WHERE service_id = :service_id_for_last_updated
               ) AS latest_catechism_rows
           )'
    );

    $serviceStmt->execute([
        ':today' => $today,
        ':id' => $serviceId,
    ]);
    $hymnStmt->execute([
        ':today' => $today,
        ':service_id' => $serviceId,
        ':service_id_for_version' => $serviceId,
    ]);
    $catechismStmt->execute([
        ':today' => $today,
        ':service_id' => $serviceId,
        ':service_id_for_last_updated' => $serviceId,
    ]);
}

// Deactivates a service and its active related rows, then unlinks copied services.
function oflc_service_db_deactivate_service(PDO $pdo, int $serviceId, string $today): void
{
    $serviceStmt = $pdo->prepare(
        'UPDATE service_db
         SET is_active = 0,
             last_updated = :today
         WHERE id = :id
           AND is_active = 1'
    );
    $unlinkCopiesStmt = $pdo->prepare(
        'UPDATE service_db
         SET copied_from_service_id = NULL,
             last_updated = :today
         WHERE copied_from_service_id = :service_id'
    );

    $serviceStmt->execute([
        ':today' => $today,
        ':id' => $serviceId,
    ]);
    oflc_service_db_deactivate_hymn_usage($pdo, $serviceId, $today);
    oflc_service_db_deactivate_service_small_catechism_links($pdo, $serviceId, $today);
    $unlinkCopiesStmt->execute([
        ':today' => $today,
        ':service_id' => $serviceId,
    ]);
}

// Permanently deletes a service row by id.
function oflc_service_db_delete_service(PDO $pdo, int $serviceId): void
{
    $stmt = $pdo->prepare(
        'DELETE FROM service_db
         WHERE id = ?'
    );
    $stmt->execute([$serviceId]);
}
