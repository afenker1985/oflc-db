<?php
declare(strict_types=1);

// Returns the next Sunday after the latest active service, falling back to today when no service exists.
function oflc_service_db_get_suggested_service_date(PDO $pdo): string
{
    $latestServiceDate = $pdo
        ->query('SELECT MAX(service_date) FROM service_db WHERE is_active = 1')
        ->fetchColumn();

    $baseDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) $latestServiceDate);
    if (!$baseDate instanceof DateTimeImmutable) {
        $baseDate = new DateTimeImmutable('today');
    }

    if ($baseDate->format('w') === '0') {
        return $baseDate->modify('+7 days')->format('Y-m-d');
    }

    return $baseDate->modify('next sunday')->format('Y-m-d');
}

// Fetches the most recent active observance ids before the selected service date.
function oflc_service_db_fetch_recently_celebrated_observance_ids(PDO $pdo, DateTimeImmutable $selectedDate): array
{
    $stmt = $pdo->prepare(
        'SELECT liturgical_calendar_id
         FROM service_db
         WHERE is_active = 1
           AND liturgical_calendar_id IS NOT NULL
           AND service_date < ?
         ORDER BY service_date DESC, id DESC
         LIMIT 2'
    );
    $stmt->execute([$selectedDate->format('Y-m-d')]);

    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    return $ids;
}

// Finds active liturgical calendar rows for one or more planning logic keys.
function oflc_service_db_fetch_observances_by_logic_keys(PDO $pdo, array $logicKeys): array
{
    $logicKeys = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $logicKeys), static function (string $value): bool {
        return $value !== '';
    })));

    if ($logicKeys === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($logicKeys), '?'));
    $stmt = $pdo->prepare(
        'SELECT lc.id, lc.name, lc.logic_key, COUNT(rs.id) AS reading_set_count
         FROM liturgical_calendar lc
         LEFT JOIN reading_sets rs ON rs.liturgical_calendar_id = lc.id AND rs.is_active = 1
         WHERE lc.is_active = 1
           AND lc.logic_key IN (' . $placeholders . ')
         GROUP BY lc.id, lc.name, lc.logic_key
         ORDER BY lc.name'
    );
    $stmt->execute($logicKeys);

    return $stmt->fetchAll();
}

// Checks whether the liturgical calendar has the logic-key column needed for planner lookups.
function oflc_service_db_planning_logic_columns_ready(PDO $pdo): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    $columns = $pdo->query('SHOW COLUMNS FROM liturgical_calendar')->fetchAll(PDO::FETCH_COLUMN);
    $ready = in_array('logic_key', $columns, true);

    return $ready;
}

// Builds a logic-key to observance-name map for active liturgical calendar entries.
function oflc_service_db_fetch_logic_key_name_map(PDO $pdo, array $logicKeys): array
{
    $logicKeys = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $logicKeys), static function (string $value): bool {
        return $value !== '';
    })));

    if ($logicKeys === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($logicKeys), '?'));
    $stmt = $pdo->prepare(
        'SELECT logic_key, name
         FROM liturgical_calendar
         WHERE is_active = 1
           AND logic_key IN (' . $placeholders . ')
         ORDER BY id'
    );
    $stmt->execute($logicKeys);

    $nameMap = [];
    foreach ($stmt->fetchAll() as $row) {
        $logicKey = trim((string) ($row['logic_key'] ?? ''));
        if ($logicKey !== '' && !isset($nameMap[$logicKey])) {
            $nameMap[$logicKey] = (string) ($row['name'] ?? '');
        }
    }

    return $nameMap;
}

// Fetches one active observance by logic key with its active reading sets.
function oflc_service_db_fetch_observance_detail(PDO $pdo, string $logicKey): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, latin_name, logic_key, season, liturgical_color, notes
         FROM liturgical_calendar
         WHERE is_active = 1
           AND logic_key = ?
         ORDER BY id
         LIMIT 1'
    );
    $stmt->execute([$logicKey]);
    $observance = $stmt->fetch();

    if (!$observance) {
        return null;
    }

    $readingsStmt = $pdo->prepare(
        'SELECT id, set_name, year_pattern, old_testament, psalm, epistle, gospel
         FROM reading_sets
         WHERE liturgical_calendar_id = ?
           AND is_active = 1
         ORDER BY id'
    );
    $readingsStmt->execute([(int) $observance['id']]);

    return [
        'observance' => $observance,
        'reading_sets' => $readingsStmt->fetchAll(),
    ];
}

// Fetches leaders, optionally including inactive rows for editing past services.
function oflc_service_db_fetch_leaders(PDO $pdo, bool $includeInactive = false): array
{
    $sql = 'SELECT id, first_name, last_name, is_active
         FROM leaders';

    if (!$includeInactive) {
        $sql .= '
         WHERE is_active = 1';
    }

    $sql .= '
         ORDER BY is_active DESC, last_name, first_name, id';

    return $pdo->query($sql)->fetchAll();
}

// Fetches active service settings used by the service planner forms.
function oflc_service_db_fetch_service_settings(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, setting_name, abbreviation, page_number
         FROM service_settings_db
         WHERE is_active = 1
         ORDER BY id'
    );

    return $stmt->fetchAll();
}

// Fetches active hymn slots keyed by slot name for hymn editor layout.
function oflc_service_db_fetch_hymn_slots(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, slot_name, max_hymns, default_sort_order
         FROM hymn_slot_db
         WHERE is_active = 1
         ORDER BY default_sort_order, id'
    );

    $slots = [];
    foreach ($stmt->fetchAll() as $row) {
        $slotName = trim((string) ($row['slot_name'] ?? ''));
        if ($slotName !== '') {
            $slots[$slotName] = $row;
        }
    }

    return $slots;
}

// Formats a hymn row into the lookup label shared by add and update service screens.
function oflc_service_db_format_hymn_label(array $row): string
{
    $parts = array_filter([
        trim((string) ($row['hymnal'] ?? '')),
        trim((string) ($row['hymn_number'] ?? '')),
    ], static function ($value): bool {
        return $value !== '';
    });

    $label = implode(' ', $parts);
    $title = trim((string) ($row['hymn_title'] ?? ''));

    if ($title !== '') {
        $label = $label !== '' ? $label . ' - ' . $title : $title;
    }

    return $label;
}

// Adds a hymn lookup key while marking ambiguous duplicate keys as unusable.
function oflc_service_db_register_hymn_lookup_key(array &$lookup, string $key, int $id): void
{
    $key = trim($key);
    if ($key === '') {
        return;
    }

    if (!isset($lookup[$key])) {
        $lookup[$key] = $id;
        return;
    }

    if ($lookup[$key] !== $id) {
        $lookup[$key] = 0;
    }
}

// Fetches hymn suggestions, lookup ids, and tunes for service hymn inputs.
function oflc_service_db_fetch_hymn_catalog(PDO $pdo, bool $includeInactive = false): array
{
    $sql = 'SELECT id, hymnal, hymn_number, hymn_title, hymn_tune, insert_use, is_active
         FROM hymn_db';

    if (!$includeInactive) {
        $sql .= '
         WHERE is_active = 1';
    }

    $sql .= '
         ORDER BY is_active DESC, hymnal, hymn_number, hymn_title';

    $stmt = $pdo->query($sql);

    $suggestions = [];
    $lookupByKey = [];
    $tuneById = [];

    foreach ($stmt->fetchAll() as $row) {
        $hymnId = (int) ($row['id'] ?? 0);
        if ($hymnId <= 0) {
            continue;
        }

        $tuneById[$hymnId] = trim((string) ($row['hymn_tune'] ?? ''));

        $fullLabel = oflc_service_db_format_hymn_label($row);
        if ($fullLabel !== '') {
            $suggestions[$fullLabel] = $fullLabel;
            oflc_service_db_register_hymn_lookup_key($lookupByKey, $fullLabel, $hymnId);
        }

        $hymnal = trim((string) ($row['hymnal'] ?? ''));
        $hymnNumber = trim((string) ($row['hymn_number'] ?? ''));
        $title = trim((string) ($row['hymn_title'] ?? ''));

        if ($hymnal !== '' && $hymnNumber !== '') {
            oflc_service_db_register_hymn_lookup_key($lookupByKey, $hymnal . ' ' . $hymnNumber, $hymnId);
        }

        if ($hymnal === 'LSB' && $hymnNumber !== '') {
            oflc_service_db_register_hymn_lookup_key($lookupByKey, $hymnNumber, $hymnId);
        }

        if ($title !== '') {
            oflc_service_db_register_hymn_lookup_key($lookupByKey, $title, $hymnId);
        }
    }

    return [
        'suggestions' => array_values($suggestions),
        'lookup_by_key' => $lookupByKey,
        'tune_by_id' => $tuneById,
    ];
}

// Fetches recent services and their hymns as fill templates for the add-service form.
function oflc_service_db_fetch_hymn_fill_templates(
    PDO $pdo,
    array $serviceSettingsById,
    array $hymnSlots,
    callable $normalizeStanzaText,
    callable $buildHymnFieldDefinitions,
    callable $buildHymnEditorState
): array {
    $stmt = $pdo->query(
        'SELECT
            s.id AS service_id,
            s.service_date,
            s.service_setting_id,
            s.liturgical_calendar_id,
            s.copied_from_service_id,
            lc.name AS observance_name,
            hu.hymn_id,
            hu.sort_order,
            hs.slot_name,
            hu.stanzas,
            h.hymnal,
            h.hymn_number,
            h.hymn_title
         FROM service_db s
         LEFT JOIN liturgical_calendar lc
            ON lc.id = s.liturgical_calendar_id
         LEFT JOIN hymn_usage_db hu
            ON hu.sunday_id = s.id
           AND hu.is_active = 1
         LEFT JOIN hymn_slot_db hs
            ON hs.id = hu.slot_id
         LEFT JOIN hymn_db h
            ON h.id = hu.hymn_id
         WHERE s.is_active = 1
           AND s.copied_from_service_id IS NULL
         ORDER BY s.service_date DESC, s.id DESC, hu.sort_order ASC, hu.id ASC'
    );

    $templates = [];
    foreach ($stmt->fetchAll() as $row) {
        $serviceId = (int) ($row['service_id'] ?? 0);
        if ($serviceId <= 0) {
            continue;
        }

        if (!isset($templates[$serviceId])) {
            $serviceDate = trim((string) ($row['service_date'] ?? ''));
            $serviceYear = $serviceDate !== '' ? substr($serviceDate, 0, 4) : '';
            $observanceName = trim((string) ($row['observance_name'] ?? ''));

            $templates[$serviceId] = [
                'id' => $serviceId,
                'service_date' => $serviceDate,
                'service_setting_id' => (int) ($row['service_setting_id'] ?? 0),
                'liturgical_calendar_id' => (int) ($row['liturgical_calendar_id'] ?? 0),
                'observance_name' => $observanceName,
                'label' => trim($observanceName . ' ' . $serviceYear),
                'usage_rows' => [],
            ];
        }

        $hymnId = (int) ($row['hymn_id'] ?? 0);
        if ($hymnId <= 0) {
            continue;
        }

        $label = oflc_service_db_format_hymn_label($row);
        if ($label === '') {
            continue;
        }

        $templates[$serviceId]['usage_rows'][] = [
            'label' => $label,
            'slot_name' => trim((string) ($row['slot_name'] ?? 'Other Hymn')),
            'sort_order' => (int) ($row['sort_order'] ?? 1),
            'stanzas' => $normalizeStanzaText($row['stanzas'] ?? ''),
            'hymnal' => trim((string) ($row['hymnal'] ?? '')),
            'hymn_number' => trim((string) ($row['hymn_number'] ?? '')),
            'hymn_title' => trim((string) ($row['hymn_title'] ?? '')),
        ];
    }

    foreach ($templates as &$template) {
        $serviceSettingId = (string) ((int) ($template['service_setting_id'] ?? 0));
        $serviceSettingDetail = $serviceSettingsById[$serviceSettingId] ?? null;
        $definitions = $buildHymnFieldDefinitions($serviceSettingDetail, $hymnSlots);
        $template['hymn_state'] = $buildHymnEditorState($definitions, $template['usage_rows'] ?? []);
    }
    unset($template);

    return array_values($templates);
}

// Fetches Small Catechism options with display labels for datalists and selection lookups.
function oflc_service_db_fetch_small_catechism_options(PDO $pdo, bool $keyById = false): array
{
    $stmt = $pdo->query(
        'SELECT id, chief_part, chief_part_id, question, abbreviation, question_order
         FROM small_catechism_mysql
         WHERE is_active = 1
         ORDER BY chief_part_id, question_order, id'
    );

    $options = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $labelParts = array_values(array_filter([
            trim((string) ($row['chief_part'] ?? '')),
            trim((string) ($row['question'] ?? '')),
        ], static function ($value): bool {
            return $value !== '';
        }));
        $label = implode(' - ', $labelParts);
        $abbreviation = trim((string) ($row['abbreviation'] ?? ''));
        if ($abbreviation !== '') {
            $label .= ($label !== '' ? ' ' : '') . '(' . $abbreviation . ')';
        }

        $row['label'] = $label !== '' ? $label : (string) $id;
        if ($keyById) {
            $options[$id] = $row;
        } else {
            $options[] = $row;
        }
    }

    return $options;
}

// Fetches Passion reading options with display labels for Lent and Passiontide services.
function oflc_service_db_fetch_passion_reading_options(PDO $pdo, bool $keyById = false): array
{
    $stmt = $pdo->query(
        'SELECT id, gospel, cycle_year, week_number, section_title, reference
         FROM passion_reading_db
         WHERE is_active = 1
         ORDER BY gospel, cycle_year, week_number, id'
    );

    $options = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $gospel = trim((string) ($row['gospel'] ?? ''));
        $cycleYear = trim((string) ($row['cycle_year'] ?? ''));
        $weekNumber = trim((string) ($row['week_number'] ?? ''));
        $sectionTitle = trim((string) ($row['section_title'] ?? ''));
        $reference = trim((string) ($row['reference'] ?? ''));

        $label = $gospel;
        if ($cycleYear !== '') {
            $label .= ($label !== '' ? ' ' : '') . 'Year ' . $cycleYear;
        }
        if ($weekNumber !== '') {
            $label .= ' Week ' . $weekNumber;
        }
        if ($sectionTitle !== '') {
            $label .= ': ' . $sectionTitle;
        }
        if ($reference !== '') {
            $label .= ' (' . $reference . ')';
        }

        $row['label'] = $label !== '' ? $label : (string) $id;
        if ($keyById) {
            $options[$id] = $row;
        } else {
            $options[] = $row;
        }
    }

    return $options;
}

// Fetches Small Catechism labels grouped by service id.
function oflc_service_db_fetch_small_catechism_labels_by_service(PDO $pdo, array $serviceIds): array
{
    $serviceIds = array_values(array_filter(array_map('intval', $serviceIds), static function (int $serviceId): bool {
        return $serviceId > 0;
    }));
    if ($serviceIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($serviceIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT
            sc.service_id,
            sm.chief_part,
            sm.question,
            sm.abbreviation
         FROM service_small_catechism_db sc
         INNER JOIN small_catechism_mysql sm ON sm.id = sc.small_catechism_id
         WHERE sc.is_active = 1
           AND sc.service_id IN (' . $placeholders . ')
         ORDER BY sc.service_id ASC, sc.sort_order ASC, sc.id ASC'
    );
    $stmt->execute($serviceIds);

    $labelsByService = [];
    foreach ($stmt->fetchAll() as $row) {
        $serviceId = (int) ($row['service_id'] ?? 0);
        if ($serviceId <= 0) {
            continue;
        }

        $labelParts = array_values(array_filter([
            trim((string) ($row['chief_part'] ?? '')),
            trim((string) ($row['question'] ?? '')),
        ], static function ($value): bool {
            return $value !== '';
        }));
        $label = implode(' - ', $labelParts);
        $abbreviation = trim((string) ($row['abbreviation'] ?? ''));
        if ($abbreviation !== '') {
            $label .= ($label !== '' ? ' ' : '') . '(' . $abbreviation . ')';
        }

        $labelsByService[$serviceId][] = $label !== '' ? $label : 'Small Catechism';
    }

    return $labelsByService;
}

// Checks whether the optional service-to-Passion-reading link table exists.
function oflc_service_db_service_passion_reading_table_exists(PDO $pdo): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'service_passion_reading_db'");
    $exists = $stmt !== false && $stmt->fetchColumn() !== false;

    return $exists;
}

// Fetches selected Passion reading ids grouped by service id.
function oflc_service_db_fetch_passion_reading_ids_by_service(PDO $pdo, array $serviceIds): array
{
    $serviceIds = array_values(array_filter(array_map('intval', $serviceIds), static function (int $serviceId): bool {
        return $serviceId > 0;
    }));
    if ($serviceIds === [] || !oflc_service_db_service_passion_reading_table_exists($pdo)) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($serviceIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT service_id, passion_reading_id
         FROM service_passion_reading_db
         WHERE is_active = 1
           AND service_id IN (' . $placeholders . ')
         ORDER BY service_id ASC, sort_order ASC, id ASC'
    );
    $stmt->execute($serviceIds);

    $idsByService = [];
    foreach ($stmt->fetchAll() as $row) {
        $serviceId = (int) ($row['service_id'] ?? 0);
        $passionReadingId = (int) ($row['passion_reading_id'] ?? 0);
        if ($serviceId > 0 && $passionReadingId > 0) {
            $idsByService[$serviceId][] = $passionReadingId;
        }
    }

    return $idsByService;
}

// Fetches active services for the schedule and print schedule views.
function oflc_service_db_fetch_schedule_services(PDO $pdo, string $sortOrder = 'asc'): array
{
    $direction = strtolower(trim($sortOrder)) === 'desc' ? 'DESC' : 'ASC';
    $stmt = $pdo->query(
        'SELECT
            s.id,
            s.service_date,
            s.service_order,
            s.passion_reading_id,
            s.liturgical_calendar_id,
            lc.name AS observance_name,
            lc.latin_name,
            lc.liturgical_color,
            ss.setting_name,
            ss.abbreviation,
            ss.page_number,
            l.last_name AS leader_last_name
         FROM service_db s
         LEFT JOIN liturgical_calendar lc ON lc.id = s.liturgical_calendar_id
         LEFT JOIN service_settings_db ss ON ss.id = s.service_setting_id
         LEFT JOIN leaders l ON l.id = s.leader_id
         WHERE s.is_active = 1
         ORDER BY s.service_date ' . $direction . ', s.service_order ' . $direction . ', s.id ' . $direction
    );

    return $stmt->fetchAll();
}

// Fetches active Small Catechism abbreviations grouped by service id.
function oflc_service_db_fetch_small_catechism_abbreviations_by_service(PDO $pdo, array $serviceIds): array
{
    $serviceIds = array_values(array_filter(array_map('intval', $serviceIds), static function (int $serviceId): bool {
        return $serviceId > 0;
    }));
    if ($serviceIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($serviceIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT
            sc.service_id,
            sm.abbreviation
         FROM service_small_catechism_db sc
         INNER JOIN small_catechism_mysql sm ON sm.id = sc.small_catechism_id
         WHERE sc.is_active = 1
           AND sc.service_id IN (' . $placeholders . ')
         ORDER BY sc.service_id ASC, sc.sort_order ASC, sc.id ASC'
    );
    $stmt->execute($serviceIds);

    $abbreviationsByService = [];
    foreach ($stmt->fetchAll() as $row) {
        $serviceId = (int) ($row['service_id'] ?? 0);
        $abbreviation = trim((string) ($row['abbreviation'] ?? ''));
        if ($serviceId <= 0 || $abbreviation === '') {
            continue;
        }

        if (!isset($abbreviationsByService[$serviceId])) {
            $abbreviationsByService[$serviceId] = [];
        }

        if (!in_array($abbreviation, $abbreviationsByService[$serviceId], true)) {
            $abbreviationsByService[$serviceId][] = $abbreviation;
        }
    }

    return $abbreviationsByService;
}

// Fetches active Passion reading rows keyed by id.
function oflc_service_db_fetch_passion_readings_by_id(PDO $pdo, array $passionReadingIds): array
{
    $passionReadingIds = array_values(array_unique(array_filter(array_map('intval', $passionReadingIds), static function (int $id): bool {
        return $id > 0;
    })));
    if ($passionReadingIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($passionReadingIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT id, gospel, section_title, reference
         FROM passion_reading_db
         WHERE is_active = 1
           AND id IN (' . $placeholders . ')'
    );
    $stmt->execute($passionReadingIds);

    $readingsById = [];
    foreach ($stmt->fetchAll() as $row) {
        $passionReadingId = (int) ($row['id'] ?? 0);
        if ($passionReadingId > 0) {
            $readingsById[$passionReadingId] = $row;
        }
    }

    return $readingsById;
}

// Fetches active reading sets grouped by liturgical calendar id.
function oflc_service_db_fetch_reading_sets_by_calendar(PDO $pdo, array $liturgicalCalendarIds): array
{
    $liturgicalCalendarIds = array_values(array_unique(array_filter(array_map('intval', $liturgicalCalendarIds), static function (int $id): bool {
        return $id > 0;
    })));
    if ($liturgicalCalendarIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($liturgicalCalendarIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT
            liturgical_calendar_id,
            set_name,
            year_pattern,
            old_testament,
            psalm,
            epistle,
            gospel
         FROM reading_sets
         WHERE is_active = 1
           AND liturgical_calendar_id IN (' . $placeholders . ')
         ORDER BY liturgical_calendar_id ASC, id ASC'
    );
    $stmt->execute($liturgicalCalendarIds);

    $readingSetsByCalendar = [];
    foreach ($stmt->fetchAll() as $row) {
        $calendarId = (int) ($row['liturgical_calendar_id'] ?? 0);
        if ($calendarId <= 0) {
            continue;
        }

        $readingSetsByCalendar[$calendarId][] = $row;
    }

    return $readingSetsByCalendar;
}

// Fetches active hymn usage rows grouped by service id for schedule display.
function oflc_service_db_fetch_schedule_hymns_by_service(PDO $pdo, array $serviceIds, callable $formatHymnLabel): array
{
    $serviceIds = array_values(array_filter(array_map('intval', $serviceIds), static function (int $serviceId): bool {
        return $serviceId > 0;
    }));
    if ($serviceIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($serviceIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT
            hu.sunday_id AS service_id,
            hu.sort_order,
            hu.stanzas,
            hd.id AS hymn_id,
            hd.hymnal,
            hd.hymn_number,
            hd.hymn_title,
            hd.hymn_tune,
            hd.insert_use
         FROM hymn_usage_db hu
         LEFT JOIN hymn_db hd ON hd.id = hu.hymn_id
         WHERE hu.is_active = 1
           AND hu.sunday_id IN (' . $placeholders . ')
         ORDER BY hu.sunday_id ASC, hu.sort_order ASC, hu.id ASC'
    );
    $stmt->execute($serviceIds);

    $hymnsByService = [];
    foreach ($stmt->fetchAll() as $row) {
        $serviceId = (int) ($row['service_id'] ?? 0);
        if ($serviceId <= 0) {
            continue;
        }

        $hymnsByService[$serviceId][] = [
            'hymn_id' => (int) ($row['hymn_id'] ?? 0),
            'label' => $formatHymnLabel($row),
            'tune' => trim((string) ($row['hymn_tune'] ?? '')),
        ];
    }

    return $hymnsByService;
}

// Checks whether an active service already uses a date and service order, optionally excluding service ids.
function oflc_service_db_has_active_service_conflict(PDO $pdo, string $serviceDate, int $serviceOrder = 1, array $excludeServiceIds = []): bool
{
    $excludeServiceIds = array_values(array_filter(array_map('intval', $excludeServiceIds), static function (int $serviceId): bool {
        return $serviceId > 0;
    }));

    $sql = 'SELECT COUNT(*)
         FROM service_db
         WHERE is_active = 1
           AND service_date = ?
           AND service_order = ?';
    $params = [$serviceDate, $serviceOrder];

    if ($excludeServiceIds !== []) {
        $sql .= ' AND id NOT IN (' . implode(', ', array_fill(0, count($excludeServiceIds), '?')) . ')';
        $params = array_merge($params, $excludeServiceIds);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn() > 0;
}

// Fetches active service rows by id for update operations.
function oflc_service_db_fetch_active_service_rows_by_id(PDO $pdo, array $serviceIds): array
{
    $serviceIds = array_values(array_filter(array_map('intval', $serviceIds), static function (int $serviceId): bool {
        return $serviceId > 0;
    }));
    if ($serviceIds === []) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT id, service_date, service_order, leader_id
         FROM service_db
         WHERE id IN (' . implode(', ', array_fill(0, count($serviceIds), '?')) . ')
           AND is_active = 1'
    );
    $stmt->execute($serviceIds);

    return $stmt->fetchAll();
}

// Fetches active services with display details for the update-service page.
function oflc_service_db_fetch_update_services(PDO $pdo, string $sortOrder = 'asc'): array
{
    $direction = strtolower(trim($sortOrder)) === 'asc' ? 'ASC' : 'DESC';
    $stmt = $pdo->query(
        'SELECT
            s.id,
            s.service_date,
            s.service_order,
            s.service_setting_id,
            s.leader_id,
            s.small_catechism_id,
            s.passion_reading_id,
            s.selected_reading_set_id,
            s.copied_from_service_id,
            s.liturgical_calendar_id,
            lc.name AS observance_name,
            lc.latin_name,
            lc.logic_key,
            lc.liturgical_color,
            ss.setting_name,
            ss.abbreviation,
            ss.page_number,
            l.last_name AS leader_last_name
         FROM service_db s
         LEFT JOIN liturgical_calendar lc ON lc.id = s.liturgical_calendar_id
         LEFT JOIN service_settings_db ss ON ss.id = s.service_setting_id
         LEFT JOIN leaders l ON l.id = s.leader_id
         WHERE s.is_active = 1
         ORDER BY s.service_date ' . $direction . ', s.service_order ' . $direction . ', s.id ' . $direction
    );

    return $stmt->fetchAll();
}

// Fetches active hymn usage rows grouped by service id for the update-service page.
function oflc_service_db_fetch_update_hymn_rows_by_service(PDO $pdo, array $serviceIds): array
{
    $serviceIds = array_values(array_filter(array_map('intval', $serviceIds), static function (int $serviceId): bool {
        return $serviceId > 0;
    }));
    if ($serviceIds === []) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT
            hu.sunday_id AS service_id,
            hu.slot_id,
            hs.slot_name,
            hu.sort_order,
            hu.version_number,
            hu.stanzas,
            hd.id AS hymn_id,
            hd.hymnal,
            hd.hymn_number,
            hd.hymn_title,
            hd.insert_use
         FROM hymn_usage_db hu
         LEFT JOIN hymn_slot_db hs ON hs.id = hu.slot_id
         LEFT JOIN hymn_db hd ON hd.id = hu.hymn_id
         WHERE hu.is_active = 1
           AND hu.sunday_id IN (' . implode(', ', array_fill(0, count($serviceIds), '?')) . ')
         ORDER BY hu.sunday_id ASC, hu.sort_order ASC, hu.id ASC'
    );
    $stmt->execute($serviceIds);

    $hymnRowsByService = [];
    foreach ($stmt->fetchAll() as $row) {
        $serviceId = (int) ($row['service_id'] ?? 0);
        if ($serviceId <= 0) {
            continue;
        }

        $hymnRowsByService[$serviceId][] = $row;
    }

    return $hymnRowsByService;
}

// Fetches a service row by id for remove, restore, and delete actions.
function oflc_service_db_fetch_service_activation_row(PDO $pdo, int $serviceId): ?array
{
    if ($serviceId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, is_active
         FROM service_db
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$serviceId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

// Fetches services with display details for the remove-service page.
function oflc_service_db_fetch_remove_services(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT
            s.id,
            s.service_date,
            s.service_order,
            s.service_setting_id,
            s.selected_reading_set_id,
            s.copied_from_service_id,
            s.liturgical_calendar_id,
            s.passion_reading_id,
            s.is_active,
            lc.name AS observance_name,
            lc.latin_name,
            lc.liturgical_color,
            ss.setting_name,
            ss.abbreviation,
            ss.page_number,
            l.last_name AS leader_last_name
         FROM service_db s
         LEFT JOIN liturgical_calendar lc ON lc.id = s.liturgical_calendar_id
         LEFT JOIN service_settings_db ss ON ss.id = s.service_setting_id
         LEFT JOIN leaders l ON l.id = s.leader_id
         ORDER BY s.service_date DESC, s.service_order DESC, s.id DESC'
    );

    return $stmt->fetchAll();
}

// Fetches active hymn usage rows grouped by service id for the remove-service page.
function oflc_service_db_fetch_remove_hymn_rows_by_service(PDO $pdo, array $serviceIds): array
{
    $serviceIds = array_values(array_filter(array_map('intval', $serviceIds), static function (int $serviceId): bool {
        return $serviceId > 0;
    }));
    if ($serviceIds === []) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT
            hu.sunday_id AS service_id,
            hs.slot_name,
            hu.sort_order,
            hd.hymnal,
            hd.hymn_number,
            hd.hymn_title
         FROM hymn_usage_db hu
         LEFT JOIN hymn_slot_db hs ON hs.id = hu.slot_id
         LEFT JOIN hymn_db hd ON hd.id = hu.hymn_id
         WHERE hu.is_active = 1
           AND hu.sunday_id IN (' . implode(', ', array_fill(0, count($serviceIds), '?')) . ')
         ORDER BY hu.sunday_id ASC, hs.default_sort_order ASC, hu.sort_order ASC, hu.id ASC'
    );
    $stmt->execute($serviceIds);

    $hymnRowsByService = [];
    foreach ($stmt->fetchAll() as $row) {
        $serviceId = (int) ($row['service_id'] ?? 0);
        if ($serviceId <= 0) {
            continue;
        }

        $hymnRowsByService[$serviceId][] = $row;
    }

    return $hymnRowsByService;
}

// Builds the date-based observance choices and details used by the update-service form.
function oflc_update_build_service_option_data(PDO $pdo, string $selectedDate, string $selectedOptionKey = ''): array
{
    $choices = [];
    $detailsByKey = [];
    $dateError = null;

    if ($selectedDate === '') {
        return [
            'choices' => $choices,
            'details_by_key' => $detailsByKey,
            'date_error' => $dateError,
        ];
    }

    $window = oflc_get_liturgical_window($selectedDate, 6, 6);
    if ($window === null) {
        return [
            'choices' => $choices,
            'details_by_key' => $detailsByKey,
            'date_error' => 'Please enter a valid date in YYYY-MM-DD format.',
        ];
    }

    $selectedDateObject = DateTimeImmutable::createFromFormat('Y-m-d', $selectedDate) ?: null;
    $recentlyCelebratedObservanceIds = $selectedDateObject instanceof DateTimeImmutable
        ? oflc_service_db_fetch_recently_celebrated_observance_ids($pdo, $selectedDateObject)
        : [];

    if (oflc_service_db_planning_logic_columns_ready($pdo)) {
        $logicKeysForNames = [];

        foreach ($window['entries'] as $entry) {
            foreach (oflc_resolve_fixed_logic_keys($entry['month'], $entry['day']) as $logicKey) {
                $logicKeysForNames[] = $logicKey;
            }

            if ($entry['is_sunday']) {
                foreach (oflc_resolve_movable_logic_keys($entry['week'], 0) as $logicKey) {
                    $logicKeysForNames[] = $logicKey;
                }
            }
        }

        $logicKeyNameMap = oflc_service_db_fetch_logic_key_name_map($pdo, $logicKeysForNames);
        $sundayChoices = [];
        $feastChoices = [];

        foreach ($window['entries'] as $entry) {
            $weekdayDate = DateTimeImmutable::createFromFormat('Y-m-d', $entry['date']);
            $festivalKeys = oflc_resolve_fixed_logic_keys($entry['month'], $entry['day']);
            foreach ($festivalKeys as $logicKey) {
                $festivalMatches = oflc_service_db_fetch_observances_by_logic_keys($pdo, [$logicKey]);
                $festivalObservanceId = (int) ($festivalMatches[0]['id'] ?? 0);
                if ($festivalObservanceId > 0 && isset($recentlyCelebratedObservanceIds[$festivalObservanceId])) {
                    continue;
                }

                $festivalName = $logicKeyNameMap[$logicKey] ?? oflc_update_humanize_logic_key($logicKey);
                $festivalDayLabel = $weekdayDate instanceof DateTimeImmutable ? oflc_update_format_short_weekday($weekdayDate) : '';
                $festivalCalendarDayLabel = $weekdayDate instanceof DateTimeImmutable ? $weekdayDate->format('n/j') : $entry['date'];
                $feastChoices[$logicKey] = [
                    'logic_key' => $logicKey,
                    'is_sunday' => false,
                    'label' => $festivalName . ' (' . trim($festivalDayLabel . ' ' . $festivalCalendarDayLabel) . ')',
                    'suggestion_label' => $festivalName . ' (' . trim($festivalDayLabel . ' ' . $festivalCalendarDayLabel) . ')',
                    'date' => $entry['date'],
                ];
            }

            if ($entry['is_sunday']) {
                $sundayKeys = oflc_resolve_movable_logic_keys($entry['week'], 0);
                foreach ($sundayKeys as $logicKey) {
                    $sundayName = $logicKeyNameMap[$logicKey] ?? oflc_update_humanize_logic_key($logicKey);
                    $sundayChoices[$logicKey] = [
                        'logic_key' => $logicKey,
                        'is_sunday' => true,
                        'label' => $sundayName . ' (' . ($weekdayDate instanceof DateTimeImmutable ? $weekdayDate->format('m/d') : $entry['date']) . ')',
                        'suggestion_label' => $sundayName,
                        'date' => $entry['date'],
                    ];
                }
            }
        }

        $choiceSort = static function (array $first, array $second): int {
            $dateCompare = strcmp((string) ($first['date'] ?? ''), (string) ($second['date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcmp((string) ($first['label'] ?? ''), (string) ($second['label'] ?? ''));
        };
        uasort($sundayChoices, $choiceSort);
        uasort($feastChoices, $choiceSort);
        $choices = $sundayChoices + $feastChoices;
    }

    if ($selectedOptionKey !== '' && !isset($choices[$selectedOptionKey])) {
        $detail = oflc_service_db_fetch_observance_detail($pdo, $selectedOptionKey);
        if ($detail !== null) {
            $choices[$selectedOptionKey] = [
                'logic_key' => $selectedOptionKey,
                'label' => trim((string) ($detail['observance']['name'] ?? $selectedOptionKey)),
            ];
        }
    }

    foreach (array_keys($choices) as $logicKey) {
        $detail = oflc_service_db_fetch_observance_detail($pdo, $logicKey);
        if ($detail !== null) {
            $detailsByKey[$logicKey] = $detail;
        }
    }

    return [
        'choices' => array_values($choices),
        'details_by_key' => $detailsByKey,
        'date_error' => $dateError,
    ];
}

// Builds the frontend observance suggestion payload for a selected update-service date.
function oflc_update_build_date_observance_suggestion_payload(PDO $pdo, string $selectedDate): array
{
    $optionData = oflc_update_build_service_option_data($pdo, $selectedDate);
    $choices = $optionData['choices'] ?? [];
    $detailsByKey = $optionData['details_by_key'] ?? [];

    return [
        'suggestions' => oflc_service_build_date_observance_suggestions($choices),
        'lookup' => oflc_service_build_observance_suggestion_lookup($choices, $detailsByKey),
    ];
}
