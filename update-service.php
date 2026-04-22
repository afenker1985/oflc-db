<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$page_title = 'Update a Service';
$body_class = 'update-service-page';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/church_year.php';
require_once __DIR__ . '/includes/liturgical.php';
require_once __DIR__ . '/includes/service_observances.php';
require_once __DIR__ . '/includes/liturgical_colors.php';

function oflc_update_request_value(array $data, string $key, string $default = ''): string
{
    if (!isset($data[$key])) {
        return $default;
    }

    return trim((string) $data[$key]);
}

function oflc_update_get_liturgical_color_display($color): string
{
    $color = trim((string) $color);

    return $color === '' ? '' : strtoupper($color);
}

function oflc_update_get_liturgical_color_text_class($color): string
{
    $color = strtolower(trim((string) $color));

    switch ($color) {
        case 'gold':
            return 'service-card-color-gold';
        case 'green':
            return 'service-card-color-green';
        case 'violet':
        case 'purple':
            return 'service-card-color-violet';
        case 'blue':
            return 'service-card-color-blue';
        case 'rose':
        case 'pink':
            return 'service-card-color-rose';
        case 'scarlet':
        case 'red':
            return 'service-card-color-red';
        case 'black':
            return 'service-card-color-black';
        case 'white':
        case '':
        default:
            return 'service-card-color-dark';
    }
}

function oflc_update_parse_filter_date(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dateObject instanceof DateTimeImmutable || $dateObject->format('Y-m-d') !== $value) {
        return null;
    }

    return $dateObject;
}

function oflc_update_group_within_date_range(array $group, ?DateTimeImmutable $startDate, ?DateTimeImmutable $endDate): bool
{
    if (!$startDate instanceof DateTimeImmutable && !$endDate instanceof DateTimeImmutable) {
        return true;
    }

    foreach ($group as $service) {
        $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($service['service_date'] ?? ''));
        if (!$dateObject instanceof DateTimeImmutable) {
            continue;
        }

        if ($startDate instanceof DateTimeImmutable && $dateObject < $startDate) {
            continue;
        }

        if ($endDate instanceof DateTimeImmutable && $dateObject > $endDate) {
            continue;
        }

        return true;
    }

    return false;
}

function oflc_update_clean_reading_text($text, bool $removeAntiphon = false): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/\s*\n\s*/', ' ', $text) ?? $text;

    if ($removeAntiphon) {
        $text = preg_replace('/\s*\(antiphon:[^)]+\)/i', '', $text) ?? $text;
    }

    return trim($text);
}

function oflc_update_fetch_service_settings(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, setting_name, abbreviation, page_number
         FROM service_settings_db
         WHERE is_active = 1
         ORDER BY id'
    );

    return $stmt->fetchAll();
}

function oflc_update_fetch_hymn_slots(PDO $pdo): array
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
        if ($slotName === '') {
            continue;
        }

        $slots[$slotName] = $row;
    }

    return $slots;
}

function oflc_update_fetch_active_leaders(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, first_name, last_name
         FROM leaders
         WHERE is_active = 1
         ORDER BY last_name, first_name, id'
    );

    return $stmt->fetchAll();
}

function oflc_update_request_values(array $data, string $key): array
{
    if (!isset($data[$key])) {
        return [];
    }

    $value = $data[$key];
    if (!is_array($value)) {
        $value = [$value];
    }

    return array_values(array_filter(array_map(static function ($item): string {
        return trim((string) $item);
    }, $value), static function (string $item): bool {
        return $item !== '';
    }));
}

function oflc_update_get_passion_cycle_year_for_service_date(?DateTimeImmutable $serviceDate): ?int
{
    if (!$serviceDate instanceof DateTimeImmutable) {
        return null;
    }

    $year = (int) $serviceDate->format('Y');

    return (($year - 2025) % 4 + 4) % 4 + 1;
}

function oflc_update_fetch_small_catechism_options(PDO $pdo): array
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

        $chiefPart = trim((string) ($row['chief_part'] ?? ''));
        $question = trim((string) ($row['question'] ?? ''));
        $abbreviation = trim((string) ($row['abbreviation'] ?? ''));

        $labelParts = array_values(array_filter([$chiefPart, $question], static function ($value): bool {
            return $value !== '';
        }));
        $label = implode(' - ', $labelParts);
        if ($abbreviation !== '') {
            $label .= ($label !== '' ? ' ' : '') . '(' . $abbreviation . ')';
        }

        $row['label'] = $label !== '' ? $label : (string) $id;
        $options[] = $row;
    }

    return $options;
}

function oflc_update_build_small_catechism_lookup(array $options): array
{
    $lookup = [];

    foreach ($options as $option) {
        $id = (int) ($option['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $label = trim((string) ($option['label'] ?? ''));
        if ($label !== '') {
            $lookup[strtolower($label)] = $id;
        }

        $abbreviation = trim((string) ($option['abbreviation'] ?? ''));
        if ($abbreviation !== '' && !isset($lookup[strtolower($abbreviation)])) {
            $lookup[strtolower($abbreviation)] = $id;
        }
    }

    return $lookup;
}

function oflc_update_fetch_passion_reading_options(PDO $pdo): array
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
        $options[] = $row;
    }

    return $options;
}

function oflc_update_filter_passion_reading_options_for_service_date(array $options, ?DateTimeImmutable $serviceDate): array
{
    $cycleYear = oflc_update_get_passion_cycle_year_for_service_date($serviceDate);
    if ($cycleYear === null) {
        return $options;
    }

    $filtered = array_values(array_filter($options, static function (array $option) use ($cycleYear): bool {
        return (int) ($option['cycle_year'] ?? 0) === $cycleYear;
    }));

    return $filtered !== [] ? $filtered : $options;
}

function oflc_update_is_advent_midweek_observance_name(string $name): bool
{
    $normalizedName = strtolower(trim($name));

    return $normalizedName !== ''
        && strpos($normalizedName, 'advent') !== false
        && strpos($normalizedName, 'midweek') !== false;
}

function oflc_update_is_lent_midweek_observance_name(string $name): bool
{
    $normalizedName = strtolower(trim($name));

    return $normalizedName !== ''
        && strpos($normalizedName, 'lent') !== false
        && strpos($normalizedName, 'midweek') !== false;
}

function oflc_update_parse_hymn_row_order(array $data): array
{
    $raw = trim((string) ($data['hymn_row_order'] ?? ''));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $order = [];
    foreach ($decoded as $key) {
        $key = trim((string) $key);
        if ($key !== '') {
            $order[] = $key;
        }
    }

    return array_values(array_unique($order));
}

function oflc_update_normalize_extra_hymn_rows(array $data): array
{
    $keys = $data['extra_hymn_keys'] ?? [];
    $values = $data['extra_hymn_values'] ?? [];
    $slots = $data['extra_hymn_slots'] ?? [];

    if (!is_array($keys)) {
        $keys = [$keys];
    }
    if (!is_array($values)) {
        $values = [];
    }
    if (!is_array($slots)) {
        $slots = [];
    }

    $rows = [];
    foreach ($keys as $key) {
        $normalizedKey = trim((string) $key);
        if ($normalizedKey === '') {
            continue;
        }

        $slotName = trim((string) ($slots[$normalizedKey] ?? 'Other Hymn'));
        if ($slotName !== 'Distribution Hymn' && $slotName !== 'Other Hymn') {
            $slotName = 'Other Hymn';
        }

        $rows[] = [
            'key' => $normalizedKey,
            'value' => trim((string) ($values[$normalizedKey] ?? '')),
            'slot_name' => $slotName,
        ];
    }

    return $rows;
}

function oflc_update_format_small_catechism_label(array $row): string
{
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

    return $label !== '' ? $label : 'Small Catechism';
}

function oflc_update_fetch_small_catechism_labels_by_service(PDO $pdo, array $serviceIds): array
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

        $labelsByService[$serviceId][] = oflc_update_format_small_catechism_label($row);
    }

    return $labelsByService;
}

function oflc_update_insert_service_small_catechism_links(PDO $pdo, int $serviceId, array $smallCatechismIds, string $today): void
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

function oflc_update_render_reading_supplements_html(
    bool $showSmallCatechism,
    array $selectedSmallCatechismLabels,
    bool $showPassionReading,
    array $filteredPassionReadingOptions,
    string $selectedPassionReadingId
): string {
    $html = '';

    if ($showSmallCatechism) {
        if ($selectedSmallCatechismLabels === []) {
            $selectedSmallCatechismLabels = [''];
        }

        foreach ($selectedSmallCatechismLabels as $index => $label) {
            $html .= '<div class="service-card-inline-field-row">';
            $html .= '<input type="text" class="service-card-text update-service-reading-input js-small-catechism-input" name="small_catechism_labels[]" value="' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '" placeholder="Select Luther\'s Small Catechism" list="small-catechism-options" autocomplete="off">';
            if ($index === count($selectedSmallCatechismLabels) - 1) {
                $html .= '<button type="button" class="service-card-add-inline-button js-small-catechism-add">+</button>';
            }
            $html .= '</div>';
        }
    }

    if ($showPassionReading) {
        $html .= '<select class="service-card-select update-service-reading-input js-passion-reading-select" name="passion_reading_id">';
        $html .= '<option value="">Select passion reading</option>';
        foreach ($filteredPassionReadingOptions as $option) {
            $optionId = (string) ($option['id'] ?? '');
            $html .= '<option value="' . htmlspecialchars($optionId, ENT_QUOTES, 'UTF-8') . '"' . ($selectedPassionReadingId === $optionId ? ' selected' : '') . '>';
            $html .= htmlspecialchars((string) ($option['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $html .= '</option>';
        }
        $html .= '</select>';
    }

    return $html;
}

function oflc_update_format_search_label(array $service, array $linkedServices = []): string
{
    $dateLabel = '';
    if ($linkedServices !== []) {
        $dateLabel = oflc_update_format_combined_service_date($linkedServices);
    }
    if ($dateLabel === '') {
        $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($service['service_date'] ?? ''));
        $dateLabel = $dateObject instanceof DateTimeImmutable
            ? $dateObject->format('Y-m-d')
            : trim((string) ($service['service_date'] ?? ''));
    }

    $labelParts = [$dateLabel];
    $observanceName = trim((string) ($service['observance_name'] ?? ''));
    if ($observanceName !== '') {
        $labelParts[] = $observanceName;
    }

    $settingName = trim((string) ($service['setting_name'] ?? ''));
    if ($settingName !== '') {
        $labelParts[] = $settingName;
    }

    return implode(' - ', array_values(array_filter($labelParts, static function (string $value): bool {
        return $value !== '';
    })));
}

function oflc_update_register_search_lookup(array &$lookup, string $key, int $serviceId): void
{
    $key = strtolower(trim($key));
    if ($key === '') {
        return;
    }

    if (!isset($lookup[$key])) {
        $lookup[$key] = $serviceId;
        return;
    }

    if ($lookup[$key] !== $serviceId) {
        $lookup[$key] = 0;
    }
}

function oflc_update_format_hymn_suggestion_label(array $row): string
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

function oflc_update_register_hymn_lookup_key(array &$lookup, string $key, int $id): void
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

function oflc_update_fetch_hymn_catalog(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, hymnal, hymn_number, hymn_title, hymn_tune, insert_use
         FROM hymn_db
         WHERE is_active = 1
         ORDER BY hymnal, hymn_number, hymn_title'
    );

    $suggestions = [];
    $lookupByKey = [];
    $tuneById = [];

    foreach ($stmt->fetchAll() as $row) {
        $hymnId = (int) ($row['id'] ?? 0);
        if ($hymnId <= 0) {
            continue;
        }

        $tuneById[$hymnId] = trim((string) ($row['hymn_tune'] ?? ''));

        $fullLabel = oflc_update_format_hymn_suggestion_label($row);
        if ($fullLabel !== '') {
            $suggestions[$fullLabel] = $fullLabel;
            oflc_update_register_hymn_lookup_key($lookupByKey, $fullLabel, $hymnId);
        }

        $hymnal = trim((string) ($row['hymnal'] ?? ''));
        $hymnNumber = trim((string) ($row['hymn_number'] ?? ''));
        $title = trim((string) ($row['hymn_title'] ?? ''));

        if ($hymnal !== '' && $hymnNumber !== '') {
            oflc_update_register_hymn_lookup_key($lookupByKey, $hymnal . ' ' . $hymnNumber, $hymnId);
        }

        if ($hymnal === 'LSB' && $hymnNumber !== '') {
            oflc_update_register_hymn_lookup_key($lookupByKey, $hymnNumber, $hymnId);
        }

        if ($title !== '') {
            oflc_update_register_hymn_lookup_key($lookupByKey, $title, $hymnId);
        }
    }

    return [
        'suggestions' => array_values($suggestions),
        'lookup_by_key' => $lookupByKey,
        'tune_by_id' => $tuneById,
    ];
}

function oflc_update_fetch_observances_by_logic_keys(PDO $pdo, array $logicKeys): array
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

function oflc_update_planning_logic_columns_ready(PDO $pdo): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    $columns = $pdo->query('SHOW COLUMNS FROM liturgical_calendar')->fetchAll(PDO::FETCH_COLUMN);
    $ready = in_array('logic_key', $columns, true);

    return $ready;
}

function oflc_update_humanize_logic_key(string $logicKey): string
{
    $label = str_replace('_', ' ', $logicKey);
    $label = ucwords($label);
    $label = str_replace([' And ', ' Of ', ' Our ', ' The '], [' and ', ' of ', ' our ', ' the '], $label);

    return $label;
}

function oflc_update_format_short_weekday(DateTimeImmutable $date): string
{
    switch ($date->format('w')) {
        case '0':
            return 'S';
        case '1':
            return 'M';
        case '2':
            return 'T';
        case '3':
            return 'W';
        case '4':
            return 'R';
        case '5':
            return 'F';
        case '6':
            return 'Sa';
        default:
            return '';
    }
}

function oflc_update_fetch_logic_key_name_map(PDO $pdo, array $logicKeys): array
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

function oflc_update_fetch_observance_detail(PDO $pdo, string $logicKey): ?array
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

function oflc_update_fetch_recently_celebrated_observance_ids(PDO $pdo, DateTimeImmutable $selectedDate): array
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

function oflc_update_normalize_selected_reading_set_id($value): ?int
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    if (!ctype_digit($value)) {
        return null;
    }

    $readingSetId = (int) $value;

    return $readingSetId > 0 ? $readingSetId : null;
}

function oflc_update_get_reading_sets_to_show(?array $observanceDetail): array
{
    if ($observanceDetail === null || !isset($observanceDetail['reading_sets']) || !is_array($observanceDetail['reading_sets'])) {
        return [];
    }

    return array_slice($observanceDetail['reading_sets'], 0, 2);
}

function oflc_update_resolve_selected_reading_set_id_for_detail(?array $observanceDetail, ?int $selectedReadingSetId): ?int
{
    if ($selectedReadingSetId === null) {
        return null;
    }

    foreach (oflc_update_get_reading_sets_to_show($observanceDetail) as $readingSet) {
        if ((int) ($readingSet['id'] ?? 0) === $selectedReadingSetId) {
            return $selectedReadingSetId;
        }
    }

    return null;
}

function oflc_update_build_observance_reading_set_data(?array $observanceDetail): array
{
    $readingSetData = [];

    foreach (oflc_update_get_reading_sets_to_show($observanceDetail) as $readingSet) {
        $readingSetId = (int) ($readingSet['id'] ?? 0);
        if ($readingSetId <= 0) {
            continue;
        }

        $readingSetData[] = [
            'id' => $readingSetId,
            'psalm' => oflc_update_clean_reading_text($readingSet['psalm'] ?? null, true),
            'old_testament' => oflc_update_clean_reading_text($readingSet['old_testament'] ?? null),
            'epistle' => oflc_update_clean_reading_text($readingSet['epistle'] ?? null),
            'gospel' => oflc_update_clean_reading_text($readingSet['gospel'] ?? null),
        ];
    }

    return $readingSetData;
}

function oflc_update_render_observance_readings_html(?array $observanceDetail, ?int $selectedReadingSetId = null, string $inputName = 'selected_reading_set_id'): string
{
    $readingSetData = oflc_update_build_observance_reading_set_data($observanceDetail);
    $selectedReadingSetId = oflc_update_resolve_selected_reading_set_id_for_detail($observanceDetail, $selectedReadingSetId);

    if ($readingSetData === []) {
        return '<div class="schedule-secondary-text">No readings assigned.</div>';
    }

    $chunks = [];

    foreach ($readingSetData as $readingIndex => $readingSet) {
        $classes = 'service-card-reading-set' . ($readingIndex > 0 ? ' service-card-reading-set-secondary' : '');
        $lines = [];
        $psalmText = (string) ($readingSet['psalm'] ?? '');
        $oldTestament = (string) ($readingSet['old_testament'] ?? '');
        $epistle = (string) ($readingSet['epistle'] ?? '');
        $gospel = (string) ($readingSet['gospel'] ?? '');
        $readingSetId = (int) ($readingSet['id'] ?? 0);

        if ($psalmText !== '' && $readingSetId > 0) {
            $lines[] = '<label class="service-card-reading-psalm"><input type="radio" name="' . htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $readingSetId, ENT_QUOTES, 'UTF-8') . '" class="service-card-reading-radio"' . ($selectedReadingSetId !== null && $selectedReadingSetId === $readingSetId ? ' checked' : '') . '><span>' . htmlspecialchars($psalmText, ENT_QUOTES, 'UTF-8') . '</span></label>';
        }

        foreach ([$oldTestament, $epistle, $gospel] as $line) {
            if ($line !== '') {
                $lines[] = '<div>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</div>';
            }
        }

        $chunks[] = '<div class="' . $classes . '">' . implode('', $lines) . '</div>';
    }

    return implode('', $chunks);
}

function oflc_update_render_new_reading_set_editor_html(array $drafts, string $selectedDraft = ''): string
{
    $chunks = ['<div class="update-service-reading-editor-note">No appointed readings are stored for this observance yet.</div>'];

    foreach ($drafts as $draft) {
        if (!is_array($draft)) {
            continue;
        }

        $index = (int) ($draft['index'] ?? 0);
        if ($index <= 0) {
            continue;
        }

        $chunks[] =
            '<div class="service-card-reading-set update-service-reading-editor">' .
                '<input type="text" class="service-card-text update-service-reading-input" name="new_reading_set_' . $index . '_set_name" value="' . htmlspecialchars((string) ($draft['set_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '" placeholder="Set Name (optional)">' .
                '<input type="text" class="service-card-text update-service-reading-input" name="new_reading_set_' . $index . '_year_pattern" value="' . htmlspecialchars((string) ($draft['year_pattern'] ?? ''), ENT_QUOTES, 'UTF-8') . '" placeholder="Year Pattern (optional)">' .
                '<input type="text" class="service-card-text update-service-reading-input" name="new_reading_set_' . $index . '_psalm" value="' . htmlspecialchars((string) ($draft['psalm'] ?? ''), ENT_QUOTES, 'UTF-8') . '" placeholder="Psalm">' .
                '<input type="text" class="service-card-text update-service-reading-input" name="new_reading_set_' . $index . '_old_testament" value="' . htmlspecialchars((string) ($draft['old_testament'] ?? ''), ENT_QUOTES, 'UTF-8') . '" placeholder="Old Testament">' .
                '<input type="text" class="service-card-text update-service-reading-input" name="new_reading_set_' . $index . '_epistle" value="' . htmlspecialchars((string) ($draft['epistle'] ?? ''), ENT_QUOTES, 'UTF-8') . '" placeholder="Epistle">' .
                '<input type="text" class="service-card-text update-service-reading-input" name="new_reading_set_' . $index . '_gospel" value="' . htmlspecialchars((string) ($draft['gospel'] ?? ''), ENT_QUOTES, 'UTF-8') . '" placeholder="Gospel">' .
            '</div>';
    }

    return implode('', $chunks);
}

function oflc_update_build_observance_catalog_payload(array $detailsById): array
{
    $payload = [
        'by_id' => [],
        'name_lookup' => [],
    ];

    foreach ($detailsById as $observanceId => $detail) {
        $observanceId = (int) $observanceId;
        if ($observanceId <= 0 || !is_array($detail)) {
            continue;
        }

        $name = trim((string) ($detail['observance']['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $payload['by_id'][$observanceId] = [
            'id' => $observanceId,
            'name' => $name,
            'latin_name' => trim((string) ($detail['observance']['latin_name'] ?? '')),
            'color_display' => oflc_update_get_liturgical_color_display($detail['observance']['liturgical_color'] ?? null),
            'color_class' => oflc_update_get_liturgical_color_text_class($detail['observance']['liturgical_color'] ?? null),
            'reading_sets' => oflc_update_build_observance_reading_set_data($detail),
        ];

        $lowerName = strtolower($name);
        if (!isset($payload['name_lookup'][$lowerName])) {
            $payload['name_lookup'][$lowerName] = $observanceId;
        }
    }

    return $payload;
}

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
        ? oflc_update_fetch_recently_celebrated_observance_ids($pdo, $selectedDateObject)
        : [];

    if (oflc_update_planning_logic_columns_ready($pdo)) {
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

        $logicKeyNameMap = oflc_update_fetch_logic_key_name_map($pdo, $logicKeysForNames);
        $sundayChoices = [];
        $feastChoices = [];

        foreach ($window['entries'] as $entry) {
            $weekdayDate = DateTimeImmutable::createFromFormat('Y-m-d', $entry['date']);
            $festivalKeys = oflc_resolve_fixed_logic_keys($entry['month'], $entry['day']);
            foreach ($festivalKeys as $logicKey) {
                $festivalMatches = oflc_update_fetch_observances_by_logic_keys($pdo, [$logicKey]);
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
        $detail = oflc_update_fetch_observance_detail($pdo, $selectedOptionKey);
        if ($detail !== null) {
            $choices[$selectedOptionKey] = [
                'logic_key' => $selectedOptionKey,
                'label' => trim((string) ($detail['observance']['name'] ?? $selectedOptionKey)),
            ];
        }
    }

    foreach (array_keys($choices) as $logicKey) {
        $detail = oflc_update_fetch_observance_detail($pdo, $logicKey);
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

function oflc_update_build_date_observance_suggestions(PDO $pdo, string $selectedDate): array
{
    $optionData = oflc_update_build_service_option_data($pdo, $selectedDate);
    $names = [];

    foreach (($optionData['choices'] ?? []) as $choice) {
        $name = trim((string) ($choice['suggestion_label'] ?? $choice['label'] ?? ''));
        if ($name !== '') {
            $names[$name] = $name;
        }
    }

    return array_values($names);
}

function oflc_update_build_hymn_field_definitions(?array $serviceSettingDetail, array $hymnSlots): array
{
    $abbreviation = trim((string) ($serviceSettingDetail['abbreviation'] ?? ''));
    $definitions = [];

    $slotLabel = static function (string $slotName, string $fallback) use ($hymnSlots): string {
        return isset($hymnSlots[$slotName]['slot_name']) && trim((string) $hymnSlots[$slotName]['slot_name']) !== ''
            ? (string) $hymnSlots[$slotName]['slot_name']
            : $fallback;
    };

    if (in_array($abbreviation, ['DS1', 'DS2', 'DS3', 'DS4'], true)) {
        $definitions[] = [
            'index' => 1,
            'label' => $slotLabel('Opening Hymn', 'Opening Hymn'),
            'slot_name' => 'Opening Hymn',
            'sort_order' => 1,
            'toggle_name' => 'opening_processional',
        ];
        $definitions[] = [
            'index' => 2,
            'label' => $slotLabel('Chief Hymn', 'Chief Hymn'),
            'slot_name' => 'Chief Hymn',
            'sort_order' => 1,
            'toggle_name' => null,
        ];

        for ($distributionIndex = 3; $distributionIndex <= 7; $distributionIndex++) {
            $definitions[] = [
                'index' => $distributionIndex,
                'label' => $slotLabel('Distribution Hymn', 'Distribution Hymn') . ' ' . ($distributionIndex - 2),
                'slot_name' => 'Distribution Hymn',
                'sort_order' => $distributionIndex - 2,
                'toggle_name' => null,
            ];
        }

        $definitions[] = [
            'index' => 8,
            'label' => $slotLabel('Closing Hymn', 'Closing Hymn'),
            'slot_name' => 'Closing Hymn',
            'sort_order' => 1,
            'toggle_name' => 'closing_recessional',
        ];

        return $definitions;
    }

    if (in_array($abbreviation, ['Matins', 'Vespers'], true)) {
        return [
            [
                'index' => 1,
                'label' => $slotLabel('Opening Hymn', 'Opening Hymn'),
                'slot_name' => 'Opening Hymn',
                'sort_order' => 1,
                'toggle_name' => null,
            ],
            [
                'index' => 2,
                'label' => $slotLabel('Office Hymn', 'Office Hymn'),
                'slot_name' => 'Office Hymn',
                'sort_order' => 1,
                'toggle_name' => null,
            ],
            [
                'index' => 3,
                'label' => $slotLabel('Closing Hymn', 'Closing Hymn'),
                'slot_name' => 'Closing Hymn',
                'sort_order' => 1,
                'toggle_name' => null,
            ],
        ];
    }

    for ($index = 1; $index <= 8; $index++) {
        $definitions[] = [
            'index' => $index,
            'label' => $slotLabel('Other Hymn', 'Other Hymn') . ' ' . $index,
            'slot_name' => 'Other Hymn',
            'sort_order' => $index,
            'toggle_name' => null,
        ];
    }

    return $definitions;
}

function oflc_update_find_definition_index(array $definitions, string $slotName, int $sortOrder = 1): ?int
{
    foreach ($definitions as $definition) {
        if ((string) ($definition['slot_name'] ?? '') === $slotName && (int) ($definition['sort_order'] ?? 1) === $sortOrder) {
            return (int) ($definition['index'] ?? 0);
        }
    }

    return null;
}

function oflc_update_build_hymn_editor_state(array $definitions, array $usageRows): array
{
    $hymns = [];
    $baseOrder = [];
    foreach ($definitions as $definition) {
        $definitionIndex = (int) ($definition['index'] ?? 0);
        if ($definitionIndex <= 0) {
            continue;
        }

        $hymns[$definitionIndex] = '';
        $baseOrder[] = 'base:' . $definitionIndex;
    }

    $state = [
        'hymns' => $hymns,
        'opening_processional' => false,
        'closing_recessional' => false,
        'extra_rows' => [],
        'order' => $baseOrder,
        'next_extra_id' => 1,
    ];

    foreach ($usageRows as $row) {
        $slotName = trim((string) ($row['slot_name'] ?? ''));
        $sortOrder = (int) ($row['sort_order'] ?? 1);
        $label = oflc_update_format_hymn_suggestion_label($row);

        if ($label === '') {
            continue;
        }

        $targetIndex = null;

        if ($slotName === 'Processional Hymn') {
            $targetIndex = oflc_update_find_definition_index($definitions, 'Opening Hymn', 1);
            $state['opening_processional'] = true;
        } elseif ($slotName === 'Recessional Hymn') {
            $targetIndex = oflc_update_find_definition_index($definitions, 'Closing Hymn', 1);
            $state['closing_recessional'] = true;
        } else {
            $targetIndex = oflc_update_find_definition_index($definitions, $slotName, $sortOrder);
            if ($targetIndex === null) {
                $targetIndex = oflc_update_find_definition_index($definitions, $slotName, 1);
            }
            if ($targetIndex === null && $sortOrder > 0 && isset($definitions[$sortOrder - 1]['index'])) {
                $targetIndex = (int) $definitions[$sortOrder - 1]['index'];
            }
        }

        if ($targetIndex !== null && isset($state['hymns'][$targetIndex]) && $state['hymns'][$targetIndex] === '') {
            $state['hymns'][$targetIndex] = $label;
            continue;
        }

        $extraKey = 'extra:' . $state['next_extra_id'];
        $state['next_extra_id']++;
        $state['extra_rows'][] = [
            'key' => $extraKey,
            'value' => $label,
            'slot_name' => $slotName === 'Distribution Hymn' ? 'Distribution Hymn' : 'Other Hymn',
        ];
    }

    $state['order'] = array_merge(
        $baseOrder,
        array_map(static function (array $row): string {
            return (string) ($row['key'] ?? '');
        }, $state['extra_rows'])
    );

    return $state;
}

function oflc_update_build_form_state_from_service(
    array $service,
    array $usageRows,
    ?array $serviceSettingDetail,
    array $hymnSlots,
    array $smallCatechismLabels = []
): array
{
    $definitions = oflc_update_build_hymn_field_definitions($serviceSettingDetail, $hymnSlots);
    $hymnState = oflc_update_build_hymn_editor_state($definitions, $usageRows);

    return [
        'service_date' => trim((string) ($service['service_date'] ?? '')),
        'observance_id' => trim((string) ($service['liturgical_calendar_id'] ?? '')),
        'observance_name' => trim((string) ($service['observance_name'] ?? '')),
        'new_observance_color' => '',
        'service_setting' => trim((string) ($service['service_setting_id'] ?? '')),
        'selected_reading_set_id' => trim((string) ($service['selected_reading_set_id'] ?? '')),
        'preacher' => trim((string) ($service['leader_last_name'] ?? '')),
        'thursday_preacher' => '',
        'selected_small_catechism_labels' => array_values($smallCatechismLabels),
        'selected_passion_reading_id' => trim((string) ($service['passion_reading_id'] ?? '')),
        'new_reading_sets' => oflc_service_normalize_new_reading_set_drafts([]),
        'selected_new_reading_set' => '',
        'selected_hymns' => $hymnState['hymns'],
        'extra_hymn_rows' => $hymnState['extra_rows'],
        'hymn_row_order' => $hymnState['order'],
        'opening_processional' => $hymnState['opening_processional'],
        'closing_recessional' => $hymnState['closing_recessional'],
        'copy_to_previous_thursday' => false,
        'link_action' => '',
    ];
}

function oflc_update_merge_form_state(array $baseState, array $overrideState): array
{
    foreach ([
        'service_date',
        'observance_id',
        'observance_name',
        'new_observance_color',
        'service_setting',
        'selected_reading_set_id',
        'preacher',
        'thursday_preacher',
        'selected_new_reading_set',
        'selected_passion_reading_id',
    ] as $key) {
        if (isset($overrideState[$key])) {
            $baseState[$key] = $overrideState[$key];
        }
    }

    if (isset($overrideState['selected_small_catechism_labels']) && is_array($overrideState['selected_small_catechism_labels'])) {
        $baseState['selected_small_catechism_labels'] = array_values($overrideState['selected_small_catechism_labels']);
    }

    if (isset($overrideState['new_reading_sets']) && is_array($overrideState['new_reading_sets'])) {
        foreach ($overrideState['new_reading_sets'] as $index => $draft) {
            if (!isset($baseState['new_reading_sets'][$index]) || !is_array($draft)) {
                continue;
            }

            foreach (['set_name', 'year_pattern', 'old_testament', 'psalm', 'epistle', 'gospel', 'has_content'] as $draftKey) {
                if (array_key_exists($draftKey, $draft)) {
                    $baseState['new_reading_sets'][$index][$draftKey] = $draft[$draftKey];
                }
            }
        }
    }

    if (isset($overrideState['selected_hymns']) && is_array($overrideState['selected_hymns'])) {
        foreach ($overrideState['selected_hymns'] as $index => $value) {
            $baseState['selected_hymns'][(int) $index] = (string) $value;
        }
    }

    if (isset($overrideState['extra_hymn_rows']) && is_array($overrideState['extra_hymn_rows'])) {
        $baseState['extra_hymn_rows'] = array_values($overrideState['extra_hymn_rows']);
    }

    if (isset($overrideState['hymn_row_order']) && is_array($overrideState['hymn_row_order'])) {
        $baseState['hymn_row_order'] = array_values($overrideState['hymn_row_order']);
    }

    if (isset($overrideState['opening_processional'])) {
        $baseState['opening_processional'] = (bool) $overrideState['opening_processional'];
    }

    if (isset($overrideState['closing_recessional'])) {
        $baseState['closing_recessional'] = (bool) $overrideState['closing_recessional'];
    }

    if (array_key_exists('copy_to_previous_thursday', $overrideState)) {
        $baseState['copy_to_previous_thursday'] = (bool) $overrideState['copy_to_previous_thursday'];
    }

    if (array_key_exists('link_action', $overrideState)) {
        $baseState['link_action'] = (string) $overrideState['link_action'];
    }

    return $baseState;
}

function oflc_update_format_combined_service_date(array $services): string
{
    $thursday = null;
    $sunday = null;
    $fallback = [];

    foreach ($services as $service) {
        $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($service['service_date'] ?? ''));
        if (!$dateObject instanceof DateTimeImmutable) {
            continue;
        }

        $fallback[] = $dateObject;
        $weekday = $dateObject->format('w');
        if ($weekday === '4') {
            $thursday = $dateObject;
        } elseif ($weekday === '0') {
            $sunday = $dateObject;
        }
    }

    usort($fallback, static function (DateTimeImmutable $left, DateTimeImmutable $right): int {
        return $left <=> $right;
    });

    if ($thursday instanceof DateTimeImmutable && $sunday instanceof DateTimeImmutable) {
        return 'Thur, ' . $thursday->format('F j') . ' and Sunday, ' . $sunday->format('F j');
    }

    if (count($fallback) === 1) {
        $onlyDate = $fallback[0];
        $label = $onlyDate->format('w') === '4' ? 'Thur' : $onlyDate->format('l');

        return $label . ', ' . $onlyDate->format('F j');
    }

    if (count($fallback) >= 2) {
        return $fallback[0]->format('F j, Y') . ' - ' . $fallback[count($fallback) - 1]->format('F j, Y');
    }

    return '';
}

function oflc_update_group_schedule_services(array $services): array
{
    $groups = [];

    foreach ($services as $service) {
        if ($groups === []) {
            $groups[] = [$service];
            continue;
        }

        $lastGroupIndex = count($groups) - 1;
        $lastGroup = $groups[$lastGroupIndex];
        $lastService = $lastGroup[count($lastGroup) - 1];

        $sameObservance = (int) ($lastService['liturgical_calendar_id'] ?? 0) !== 0
            && (int) ($lastService['liturgical_calendar_id'] ?? 0) === (int) ($service['liturgical_calendar_id'] ?? 0);
        $lastDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($lastService['service_date'] ?? ''));
        $currentDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($service['service_date'] ?? ''));
        $withinWindow = $lastDate instanceof DateTimeImmutable
            && $currentDate instanceof DateTimeImmutable
            && abs((int) $currentDate->diff($lastDate)->format('%r%a')) <= 4;

        if ($sameObservance && $withinWindow) {
            $groups[$lastGroupIndex][] = $service;
            continue;
        }

        $groups[] = [$service];
    }

    return $groups;
}

function oflc_update_get_service_date_object(array $service): ?DateTimeImmutable
{
    $date = trim((string) ($service['service_date'] ?? ''));
    $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $date);

    return $dateObject instanceof DateTimeImmutable ? $dateObject : null;
}

function oflc_update_is_shared_thursday_sunday_group(array $group): bool
{
    if (count($group) !== 2) {
        return false;
    }

    $hasThursday = false;
    $hasSunday = false;
    $thursdayDate = null;
    $sundayDate = null;

    foreach ($group as $service) {
        $dateObject = oflc_update_get_service_date_object($service);
        if (!$dateObject instanceof DateTimeImmutable) {
            return false;
        }

        if ($dateObject->format('w') === '4') {
            $hasThursday = true;
            $thursdayDate = $dateObject;
        } elseif ($dateObject->format('w') === '0') {
            $hasSunday = true;
            $sundayDate = $dateObject;
        }
    }

    return $hasThursday
        && $hasSunday
        && $thursdayDate instanceof DateTimeImmutable
        && $sundayDate instanceof DateTimeImmutable
        && (int) $thursdayDate->diff($sundayDate)->format('%r%a') === 3;
}

function oflc_update_build_group_edit_targets(array $group): array
{
    if (!oflc_update_is_shared_thursday_sunday_group($group)) {
        return array_map(static function (array $service): array {
            $serviceId = (int) ($service['id'] ?? 0);

            return [
                'form_key' => $serviceId,
                'services' => [$service],
                'display_service' => $service,
                'show_copy_toggle' => false,
                'is_linked' => false,
                'thursday_service' => null,
                'sunday_service' => null,
            ];
        }, $group);
    }

    $thursdayService = null;
    $sundayService = null;

    foreach ($group as $service) {
        $dateObject = oflc_update_get_service_date_object($service);
        if (!$dateObject instanceof DateTimeImmutable) {
            continue;
        }

        if ($dateObject->format('w') === '4') {
            $thursdayService = $service;
        } elseif ($dateObject->format('w') === '0') {
            $sundayService = $service;
        }
    }

    if ($thursdayService === null || $sundayService === null) {
        return [];
    }

    $isLinked = (int) ($thursdayService['copied_from_service_id'] ?? 0) === (int) ($sundayService['id'] ?? 0);

    if ($isLinked) {
        return [[
            'form_key' => (int) ($sundayService['id'] ?? 0),
            'services' => [$thursdayService, $sundayService],
            'display_service' => $sundayService,
            'show_copy_toggle' => true,
            'is_linked' => true,
            'thursday_service' => $thursdayService,
            'sunday_service' => $sundayService,
        ]];
    }

    return [
        [
            'form_key' => (int) ($thursdayService['id'] ?? 0),
            'services' => [$thursdayService],
            'display_service' => $thursdayService,
            'show_copy_toggle' => false,
            'is_linked' => false,
            'thursday_service' => $thursdayService,
            'sunday_service' => $sundayService,
        ],
        [
            'form_key' => (int) ($sundayService['id'] ?? 0),
            'services' => [$sundayService],
            'display_service' => $sundayService,
            'show_copy_toggle' => true,
            'is_linked' => false,
            'thursday_service' => $thursdayService,
            'sunday_service' => $sundayService,
        ],
    ];
}

function oflc_update_resolve_hymn_id(string $value, array $lookupByKey): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (!isset($lookupByKey[$value]) || (int) $lookupByKey[$value] <= 0) {
        return null;
    }

    return (int) $lookupByKey[$value];
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$serviceSettings = oflc_update_fetch_service_settings($pdo);
$serviceSettingsById = [];
foreach ($serviceSettings as $serviceSetting) {
    $serviceSettingsById[(string) $serviceSetting['id']] = $serviceSetting;
}

$hymnSlots = oflc_update_fetch_hymn_slots($pdo);
$leaders = oflc_update_fetch_active_leaders($pdo);
$leadersByLastName = [];
foreach ($leaders as $leader) {
    $lastName = trim((string) ($leader['last_name'] ?? ''));
    if ($lastName !== '') {
        $leadersByLastName[$lastName] = (int) ($leader['id'] ?? 0);
        $leadersByLastName[strtolower($lastName)] = (int) ($leader['id'] ?? 0);
    }
}

$hymnCatalog = oflc_update_fetch_hymn_catalog($pdo);
$smallCatechismOptions = oflc_update_fetch_small_catechism_options($pdo);
$smallCatechismLookup = oflc_update_build_small_catechism_lookup($smallCatechismOptions);
$passionReadingOptions = oflc_update_fetch_passion_reading_options($pdo);
$passionReadingById = [];
foreach ($passionReadingOptions as $passionReadingOption) {
    $passionReadingId = (int) ($passionReadingOption['id'] ?? 0);
    if ($passionReadingId > 0) {
        $passionReadingById[$passionReadingId] = $passionReadingOption;
    }
}
$hymnFieldDefinitionsByService = ['' => []];
$liturgicalColorOptions = oflc_get_liturgical_color_options();
foreach ($serviceSettings as $serviceSetting) {
    $serviceId = (string) $serviceSetting['id'];
    $hymnFieldDefinitionsByService[$serviceId] = oflc_update_build_hymn_field_definitions($serviceSetting, $hymnSlots);
}

$formStateByServiceId = [];
$formErrorsByServiceId = [];
$churchYearConfiguration = oflc_church_year_get_configuration($pdo);
$churchYearSettings = oflc_church_year_resolve_effective_settings(
    oflc_church_year_fetch_saved_settings($pdo),
    $churchYearConfiguration
);
$rubricYearOptions = oflc_church_year_build_filter_options($pdo, $churchYearSettings);
$today = new DateTimeImmutable('today');
$currentRubricYearOption = oflc_church_year_find_filter_option_for_date($rubricYearOptions, $today);
$currentMonthStartDate = $today->modify('first day of this month');
$currentMonthEndDate = $today->modify('last day of this month');
$defaultUpdateServiceFilters = [
    'rubric_year' => (string) ($currentRubricYearOption['key'] ?? ''),
    'start_date' => $currentMonthStartDate->format('Y-m-d'),
    'end_date' => $currentMonthEndDate->format('Y-m-d'),
    'sort_order' => 'desc',
];
$storedUpdateServiceFilters = isset($_SESSION['oflc_update_service_filters']) && is_array($_SESSION['oflc_update_service_filters'])
    ? $_SESSION['oflc_update_service_filters']
    : [];
$hasExplicitRangeFilters = isset($_GET['rubric_year']) || isset($_GET['start_date']) || isset($_GET['end_date']) || isset($_GET['sort_order']);
$activeExpandedServiceId = isset($_GET['expanded_service']) ? (int) $_GET['expanded_service'] : 0;
$updatedServiceId = isset($_GET['updated_service']) ? (int) $_GET['updated_service'] : 0;
$selectedRubricYear = trim((string) ($storedUpdateServiceFilters['rubric_year'] ?? $defaultUpdateServiceFilters['rubric_year']));
$filterStartInput = trim((string) ($storedUpdateServiceFilters['start_date'] ?? $defaultUpdateServiceFilters['start_date']));
$filterEndInput = trim((string) ($storedUpdateServiceFilters['end_date'] ?? $defaultUpdateServiceFilters['end_date']));
$sortOrderInput = strtolower(trim((string) ($storedUpdateServiceFilters['sort_order'] ?? $defaultUpdateServiceFilters['sort_order'])));
$sortOrder = $sortOrderInput === 'asc' ? 'asc' : 'desc';

if ($hasExplicitRangeFilters) {
    $selectedRubricYear = trim((string) ($_GET['rubric_year'] ?? $selectedRubricYear));
    $filterStartInput = trim((string) ($_GET['start_date'] ?? $filterStartInput));
    $filterEndInput = trim((string) ($_GET['end_date'] ?? $filterEndInput));
    $sortOrderInput = strtolower(trim((string) ($_GET['sort_order'] ?? $sortOrder)));
    $sortOrder = $sortOrderInput === 'asc' ? 'asc' : 'desc';
}

if ($requestMethod === 'POST') {
    $selectedRubricYear = oflc_update_request_value($_POST, 'return_rubric_year', $selectedRubricYear);
    $filterStartInput = oflc_update_request_value($_POST, 'return_start_date', $filterStartInput);
    $filterEndInput = oflc_update_request_value($_POST, 'return_end_date', $filterEndInput);
    $sortOrderInput = strtolower(oflc_update_request_value($_POST, 'return_sort_order', $sortOrder));
    $sortOrder = $sortOrderInput === 'asc' ? 'asc' : 'desc';
}

$selectedRubricYearOption = oflc_church_year_find_filter_option($rubricYearOptions, $selectedRubricYear);
if (
    $selectedRubricYearOption !== null
    && $filterStartInput === ''
    && $filterEndInput === ''
) {
    $filterStartInput = (string) $selectedRubricYearOption['start_date'];
    $filterEndInput = (string) $selectedRubricYearOption['end_date'];
}

$filterStartDate = oflc_update_parse_filter_date($filterStartInput);
$filterEndDate = oflc_update_parse_filter_date($filterEndInput);
$scheduleFilterError = null;

if ($filterStartInput !== '' && !$filterStartDate instanceof DateTimeImmutable) {
    $scheduleFilterError = 'Start date must use YYYY-MM-DD.';
}

if ($scheduleFilterError === null && $filterEndInput !== '' && !$filterEndDate instanceof DateTimeImmutable) {
    $scheduleFilterError = 'End date must use YYYY-MM-DD.';
}

if (
    $scheduleFilterError === null
    && $filterStartDate instanceof DateTimeImmutable
    && $filterEndDate instanceof DateTimeImmutable
    && $filterStartDate > $filterEndDate
) {
    $scheduleFilterError = 'Start date cannot be after end date.';
}

$rubricFilterStartDate = $filterStartDate;
$rubricFilterEndDate = $filterEndDate;

$normalizedUpdateServiceFilters = [
    'rubric_year' => $selectedRubricYear,
    'start_date' => $filterStartInput,
    'end_date' => $filterEndInput,
    'sort_order' => $sortOrder,
];

$cleanUpdateServiceQuery = [];
if ($activeExpandedServiceId > 0) {
    $cleanUpdateServiceQuery['expanded_service'] = (string) $activeExpandedServiceId;
}
if ($updatedServiceId > 0) {
    $cleanUpdateServiceQuery['updated_service'] = (string) $updatedServiceId;
}
$cleanUpdateServiceUrl = 'update-service.php' . ($cleanUpdateServiceQuery !== [] ? '?' . http_build_query($cleanUpdateServiceQuery) : '');

if ($hasExplicitRangeFilters && $requestMethod === 'GET' && $scheduleFilterError === null) {
    $_SESSION['oflc_update_service_filters'] = $normalizedUpdateServiceFilters;
}

if ($requestMethod === 'POST' && isset($_POST['update_service_filters'])) {
    if ($scheduleFilterError === null) {
        $_SESSION['oflc_update_service_filters'] = $normalizedUpdateServiceFilters;
        header('Location: ' . $cleanUpdateServiceUrl, true, 303);
        exit;
    }
}

$updateServiceResetQuery = [
    'expanded_service' => $activeExpandedServiceId > 0 ? (string) $activeExpandedServiceId : null,
    'updated_service' => $updatedServiceId > 0 ? (string) $updatedServiceId : null,
];
$updateServiceResetQuery = array_filter($updateServiceResetQuery, static function ($value): bool {
    return $value !== null && $value !== '';
});
$updateServiceResetUrl = 'update-service.php' . ($updateServiceResetQuery !== [] ? '?' . http_build_query($updateServiceResetQuery) : '');

if ($requestMethod === 'POST' && isset($_POST['update_service'])) {
    $_SESSION['oflc_update_service_filters'] = $normalizedUpdateServiceFilters;
    $serviceId = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
    $activeExpandedServiceId = $serviceId;
    $targetServiceIds = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string) ($_POST['service_ids'] ?? (string) $serviceId))),
        static function (int $value): bool {
            return $value > 0;
        }
    )));

    if ($targetServiceIds === [] && $serviceId > 0) {
        $targetServiceIds = [$serviceId];
    }

    $submittedState = [
        'service_date' => oflc_update_request_value($_POST, 'service_date'),
        'observance_id' => oflc_update_request_value($_POST, 'observance_id'),
        'observance_name' => oflc_update_request_value($_POST, 'observance_name'),
        'new_observance_color' => oflc_update_request_value($_POST, 'new_observance_color'),
        'service_setting' => oflc_update_request_value($_POST, 'service_setting'),
        'selected_reading_set_id' => oflc_update_request_value($_POST, 'selected_reading_set_id'),
        'selected_new_reading_set' => oflc_update_request_value($_POST, 'selected_new_reading_set'),
        'selected_small_catechism_labels' => oflc_update_request_values($_POST, 'small_catechism_labels'),
        'selected_passion_reading_id' => oflc_update_request_value($_POST, 'passion_reading_id'),
        'new_reading_sets' => oflc_service_normalize_new_reading_set_drafts($_POST),
        'preacher' => oflc_update_request_value($_POST, 'preacher'),
        'thursday_preacher' => oflc_update_request_value($_POST, 'thursday_preacher'),
        'selected_hymns' => [],
        'extra_hymn_rows' => oflc_update_normalize_extra_hymn_rows($_POST),
        'hymn_row_order' => oflc_update_parse_hymn_row_order($_POST),
        'opening_processional' => isset($_POST['opening_processional']),
        'closing_recessional' => isset($_POST['closing_recessional']),
        'copy_to_previous_thursday' => isset($_POST['copy_to_previous_thursday']),
        'link_action' => oflc_update_request_value($_POST, 'link_action'),
    ];

    for ($hymnIndex = 1; $hymnIndex <= 8; $hymnIndex++) {
        $submittedState['selected_hymns'][$hymnIndex] = oflc_update_request_value($_POST, 'hymn_' . $hymnIndex);
    }

    $formStateByServiceId[$serviceId] = $submittedState;

    $errors = [];

    $servicePlaceholders = implode(', ', array_fill(0, count($targetServiceIds), '?'));
    $serviceStmt = $pdo->prepare(
        'SELECT id, service_date, service_order, leader_id
         FROM service_db
         WHERE id IN (' . $servicePlaceholders . ')
           AND is_active = 1'
    );
    $serviceStmt->execute($targetServiceIds);
    $serviceRows = $serviceStmt->fetchAll();
    $serviceRowsById = [];
    foreach ($serviceRows as $serviceRow) {
        $serviceRowsById[(int) ($serviceRow['id'] ?? 0)] = $serviceRow;
    }

    if (count($serviceRowsById) !== count($targetServiceIds)) {
        $errors[] = 'That service could not be found.';
    }

    $serviceDateObject = oflc_update_parse_filter_date($submittedState['service_date']);
    if (!$serviceDateObject instanceof DateTimeImmutable) {
        $errors[] = 'Service date must use YYYY-MM-DD.';
    }

    $observanceName = trim((string) $submittedState['observance_name']);
    $submittedObservanceId = ctype_digit((string) $submittedState['observance_id'])
        ? (int) $submittedState['observance_id']
        : 0;
    $observanceDetail = $submittedObservanceId > 0
        ? oflc_service_fetch_observance_detail_by_id($pdo, $submittedObservanceId)
        : null;
    if ($observanceDetail === null && $observanceName !== '') {
        $observanceDetail = oflc_service_fetch_observance_detail_by_name($pdo, $observanceName);
    }

    $createObservanceName = '';
    if ($observanceName === '') {
        $errors[] = 'Enter a liturgical observance.';
    } elseif ($observanceDetail === null) {
        $createObservanceName = $observanceName;
        if (!oflc_is_valid_liturgical_color($submittedState['new_observance_color'])) {
            $errors[] = 'Select a liturgical color for the new observance.';
        }
    }

    $selectedReadingSetId = oflc_update_normalize_selected_reading_set_id($submittedState['selected_reading_set_id']);
    if ($submittedState['selected_reading_set_id'] !== '' && $selectedReadingSetId === null) {
        $errors[] = 'Select a valid reading set.';
    } elseif ($selectedReadingSetId !== null && oflc_update_resolve_selected_reading_set_id_for_detail($observanceDetail, $selectedReadingSetId) === null) {
        $errors[] = 'Selected reading set does not match the observance.';
    }

    $hasDraftReadings = false;
    foreach ($submittedState['new_reading_sets'] as $draft) {
        if (!empty($draft['has_content'])) {
            $hasDraftReadings = true;
            break;
        }
    }

    $observanceHasReadings = $observanceDetail !== null && count($observanceDetail['reading_sets'] ?? []) > 0;
    if (!$observanceHasReadings && $hasDraftReadings) {
        $draftOne = $submittedState['new_reading_sets'][1] ?? null;
        if (!is_array($draftOne) || empty($draftOne['has_content'])) {
            $errors[] = 'Enter readings for the new observance.';
        }
    }

    $serviceSettingId = $submittedState['service_setting'];
    $serviceSettingDetail = $serviceSettingId !== '' && isset($serviceSettingsById[$serviceSettingId])
        ? $serviceSettingsById[$serviceSettingId]
        : null;
    if ($serviceSettingId !== '' && $serviceSettingDetail === null) {
        $errors[] = 'Select a valid service setting.';
    }

    $leaderLastName = trim((string) $submittedState['preacher']);
    $leaderId = null;
    if ($leaderLastName !== '') {
        $normalizedLeaderLastName = strtolower($leaderLastName);
        if (!isset($leadersByLastName[$normalizedLeaderLastName]) || (int) $leadersByLastName[$normalizedLeaderLastName] <= 0) {
            $errors[] = 'Leader must match an active last name.';
        } else {
            $leaderId = (int) $leadersByLastName[$normalizedLeaderLastName];
        }
    }

    $thursdayLeaderLastName = trim((string) $submittedState['thursday_preacher']);
    $thursdayLeaderId = null;
    $hasExplicitThursdayLeader = false;
    if ($thursdayLeaderLastName !== '') {
        $hasExplicitThursdayLeader = true;
        $normalizedThursdayLeaderLastName = strtolower($thursdayLeaderLastName);
        if (!isset($leadersByLastName[$normalizedThursdayLeaderLastName]) || (int) $leadersByLastName[$normalizedThursdayLeaderLastName] <= 0) {
            $errors[] = 'Thursday leader must match an active last name.';
        } else {
            $thursdayLeaderId = (int) $leadersByLastName[$normalizedThursdayLeaderLastName];
        }
    }

    $smallCatechismIds = [];
    foreach ($submittedState['selected_small_catechism_labels'] as $smallCatechismLabel) {
        $normalizedSmallCatechismLabel = strtolower(trim((string) $smallCatechismLabel));
        if ($normalizedSmallCatechismLabel === '') {
            continue;
        }

        if (!isset($smallCatechismLookup[$normalizedSmallCatechismLabel])) {
            $errors[] = 'Select a valid Small Catechism portion.';
            break;
        }

        $smallCatechismIds[] = (int) $smallCatechismLookup[$normalizedSmallCatechismLabel];
    }
    $smallCatechismIds = array_values(array_unique(array_filter($smallCatechismIds, static function (int $id): bool {
        return $id > 0;
    })));
    $smallCatechismId = $smallCatechismIds !== [] ? $smallCatechismIds[0] : null;

    $passionReadingId = null;
    if ($submittedState['selected_passion_reading_id'] !== '') {
        if (!ctype_digit($submittedState['selected_passion_reading_id']) || !isset($passionReadingById[(int) $submittedState['selected_passion_reading_id']])) {
            $errors[] = 'Select a valid passion reading.';
        } else {
            $passionReadingId = (int) $submittedState['selected_passion_reading_id'];
        }
    }

    $submittedObservanceName = trim((string) ($observanceDetail['observance']['name'] ?? $observanceName));
    $isAdventMidweek = oflc_update_is_advent_midweek_observance_name($submittedObservanceName);
    $isLentMidweek = oflc_update_is_lent_midweek_observance_name($submittedObservanceName);

    if (!$isAdventMidweek && !$isLentMidweek) {
        $smallCatechismId = null;
        $smallCatechismIds = [];
        $passionReadingId = null;
    } elseif (!$isLentMidweek) {
        $passionReadingId = null;
    }

    $pairThursdayId = isset($_POST['pair_thursday_id']) ? (int) $_POST['pair_thursday_id'] : 0;
    $pairSundayId = isset($_POST['pair_sunday_id']) ? (int) $_POST['pair_sunday_id'] : 0;
    $hasPairCandidate = $pairThursdayId > 0 && $pairSundayId > 0;
    $originalCopyState = isset($_POST['original_copy_to_previous_thursday']) && (string) $_POST['original_copy_to_previous_thursday'] === '1';
    $currentCopyState = $submittedState['copy_to_previous_thursday'];
    $isSundayPairEditor = $hasPairCandidate && $serviceId === $pairSundayId;
    $shouldLinkPair = false;
    $shouldUnlinkPair = false;

    if ($isSundayPairEditor) {
        if ($originalCopyState && !$currentCopyState) {
            if ($submittedState['link_action'] !== 'unlink') {
                $errors[] = 'Choose whether to separate Thursday from Sunday.';
            } else {
                $shouldUnlinkPair = true;
            }
        } elseif (!$originalCopyState && $currentCopyState) {
            if ($submittedState['link_action'] !== 'link') {
                $errors[] = 'Choose whether to unite Thursday with Sunday.';
            } else {
                $shouldLinkPair = true;
            }
        }
    }

    if ($isSundayPairEditor) {
        $targetServiceIds = $originalCopyState || $shouldLinkPair || $shouldUnlinkPair
            ? [$pairThursdayId, $pairSundayId]
            : [$serviceId];
    }

    $targetServiceIds = array_values(array_unique(array_filter($targetServiceIds, static function (int $value): bool {
        return $value > 0;
    })));
    $serviceRowsById = array_intersect_key($serviceRowsById, array_flip($targetServiceIds));
    $servicePlaceholders = implode(', ', array_fill(0, count($targetServiceIds), '?'));

    $targetServiceDates = [];
    if ($errors === [] && $serviceDateObject instanceof DateTimeImmutable && $serviceRowsById !== []) {
        if (count($targetServiceIds) === 2) {
            foreach ($serviceRowsById as $targetRowId => $serviceRow) {
                $existingDateObject = oflc_update_get_service_date_object($serviceRow);
                if (!$existingDateObject instanceof DateTimeImmutable) {
                    $errors[] = 'One of the paired services has an invalid date.';
                    break;
                }

                if ($existingDateObject->format('w') === '0') {
                    $targetServiceDates[$targetRowId] = $serviceDateObject->format('Y-m-d');
                } elseif ($existingDateObject->format('w') === '4') {
                    $targetServiceDates[$targetRowId] = $serviceDateObject->modify('-3 days')->format('Y-m-d');
                } else {
                    $targetServiceDates[$targetRowId] = $serviceDateObject->format('Y-m-d');
                }
            }
        } else {
            $targetServiceDates[$serviceId] = $serviceDateObject->format('Y-m-d');
        }

        if ($errors === []) {
            $conflictStmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM service_db
                 WHERE is_active = 1
                   AND service_date = ?
                   AND service_order = ?
                   AND id NOT IN (' . $servicePlaceholders . ')'
            );

            foreach ($serviceRowsById as $targetRowId => $serviceRow) {
                $conflictStmt->execute(array_merge([
                    $targetServiceDates[$targetRowId] ?? $serviceDateObject->format('Y-m-d'),
                    (int) $serviceRow['service_order'],
                ], $targetServiceIds));

                if ((int) $conflictStmt->fetchColumn() > 0) {
                    $errors[] = 'Another active service already uses one of those dates and orders.';
                    break;
                }
            }
        }
    }

    $definitions = oflc_update_build_hymn_field_definitions($serviceSettingDetail, $hymnSlots);
    $baseHymnRows = [];
    foreach ($definitions as $definition) {
        $index = (int) ($definition['index'] ?? 0);
        if ($index <= 0) {
            continue;
        }

        $slotName = (string) ($definition['slot_name'] ?? '');
        if (($definition['toggle_name'] ?? null) === 'opening_processional' && $submittedState['opening_processional']) {
            $slotName = 'Processional Hymn';
        }
        if (($definition['toggle_name'] ?? null) === 'closing_recessional' && $submittedState['closing_recessional']) {
            $slotName = 'Recessional Hymn';
        }

        $baseHymnRows['base:' . $index] = [
            'label' => (string) ($definition['label'] ?? ('Hymn ' . $index)),
            'value' => $submittedState['selected_hymns'][$index] ?? '',
            'slot_name' => $slotName,
        ];
    }

    $extraHymnRowsByKey = [];
    foreach ($submittedState['extra_hymn_rows'] as $extraHymnRow) {
        $extraKey = trim((string) ($extraHymnRow['key'] ?? ''));
        if ($extraKey === '') {
            continue;
        }

        $extraHymnRowsByKey[$extraKey] = [
            'label' => 'Additional hymn',
            'value' => trim((string) ($extraHymnRow['value'] ?? '')),
            'slot_name' => trim((string) ($extraHymnRow['slot_name'] ?? 'Other Hymn')),
        ];
    }

    $orderedHymnRowKeys = $submittedState['hymn_row_order'];
    if ($orderedHymnRowKeys === []) {
        $orderedHymnRowKeys = array_merge(array_keys($baseHymnRows), array_keys($extraHymnRowsByKey));
    }

    $allHymnRows = $baseHymnRows + $extraHymnRowsByKey;
    foreach (array_keys($allHymnRows) as $rowKey) {
        if (!in_array($rowKey, $orderedHymnRowKeys, true)) {
            $orderedHymnRowKeys[] = $rowKey;
        }
    }

    $hymnEntries = [];
    $orderedBaseRowKeys = array_values(array_filter($orderedHymnRowKeys, static function (string $rowKey): bool {
        return strpos($rowKey, 'base:') === 0;
    }));
    $baseRowRankByKey = [];
    foreach ($orderedBaseRowKeys as $baseRowRank => $baseRowKey) {
        $baseRowRankByKey[$baseRowKey] = $baseRowRank;
    }

    $slotSortOrderCounts = [];
    foreach ($orderedHymnRowKeys as $rowKey) {
        if (!isset($allHymnRows[$rowKey])) {
            continue;
        }

        $row = $allHymnRows[$rowKey];
        $hymnValue = trim((string) ($row['value'] ?? ''));
        if ($hymnValue === '') {
            continue;
        }

        $hymnId = oflc_update_resolve_hymn_id($hymnValue, $hymnCatalog['lookup_by_key']);
        if ($hymnId === null) {
            $errors[] = trim((string) ($row['label'] ?? 'Hymn')) . ' must match a hymn from the suggestions.';
            continue;
        }

        $slotName = trim((string) ($row['slot_name'] ?? ''));
        if (strpos($rowKey, 'base:') === 0) {
            $baseRank = $baseRowRankByKey[$rowKey] ?? null;
            $definitionForPosition = $baseRank !== null ? ($definitions[$baseRank] ?? null) : null;
            if (is_array($definitionForPosition)) {
                $slotName = trim((string) ($definitionForPosition['slot_name'] ?? $slotName));
                if (($definitionForPosition['toggle_name'] ?? null) === 'opening_processional' && $submittedState['opening_processional']) {
                    $slotName = 'Processional Hymn';
                }
                if (($definitionForPosition['toggle_name'] ?? null) === 'closing_recessional' && $submittedState['closing_recessional']) {
                    $slotName = 'Recessional Hymn';
                }
            }
        }

        if (!isset($hymnSlots[$slotName]['id'])) {
            $errors[] = 'Missing hymn slot configuration for ' . $slotName . '.';
            continue;
        }

        $slotSortOrderCounts[$slotName] = ($slotSortOrderCounts[$slotName] ?? 0) + 1;
        $hymnEntries[] = [
            'hymn_id' => $hymnId,
            'slot_id' => (int) $hymnSlots[$slotName]['id'],
            'sort_order' => $slotSortOrderCounts[$slotName],
        ];
    }

    if ($errors === []) {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        try {
            $pdo->beginTransaction();

            $persistedObservanceDetail = $observanceDetail;
            if ($persistedObservanceDetail === null && $createObservanceName !== '') {
                $createdObservanceId = oflc_service_create_observance($pdo, $createObservanceName, $submittedState['new_observance_color']);
                $persistedObservanceDetail = oflc_service_fetch_observance_detail_by_id($pdo, $createdObservanceId);
            }

            if ($persistedObservanceDetail === null) {
                throw new RuntimeException('Unable to resolve observance.');
            }

            $insertedReadingSetIds = [];
            if (count($persistedObservanceDetail['reading_sets'] ?? []) === 0) {
                $insertedReadingSetIds = oflc_service_insert_new_reading_set_drafts(
                    $pdo,
                    (int) ($persistedObservanceDetail['observance']['id'] ?? 0),
                    $submittedState['new_reading_sets']
                );
                if ($insertedReadingSetIds !== []) {
                    $persistedObservanceDetail = oflc_service_fetch_observance_detail_by_id(
                        $pdo,
                        (int) ($persistedObservanceDetail['observance']['id'] ?? 0)
                    ) ?? $persistedObservanceDetail;
                }
            }

            $persistedSelectedReadingSetId = $selectedReadingSetId;
            if ($persistedSelectedReadingSetId === null && count($insertedReadingSetIds) === 1) {
                $persistedSelectedReadingSetId = (int) reset($insertedReadingSetIds);
            }

            $updateServiceStmt = $pdo->prepare(
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

            $deactivateUsageStmt = $pdo->prepare(
                'UPDATE hymn_usage_db
                 SET is_active = 0,
                     last_updated = :last_updated
                 WHERE sunday_id = :service_id
                   AND is_active = 1'
            );

            $deactivateSmallCatechismStmt = $pdo->prepare(
                'UPDATE service_small_catechism_db
                 SET is_active = 0,
                     last_updated = :last_updated
                 WHERE service_id = :service_id
                   AND is_active = 1'
            );

            $insertUsageStmt = $pdo->prepare(
                'INSERT INTO hymn_usage_db (
                    sunday_id,
                    hymn_id,
                    slot_id,
                    sort_order,
                    version_number,
                    created_at,
                    last_updated,
                    is_active
                 ) VALUES (
                    :sunday_id,
                    :hymn_id,
                    :slot_id,
                    :sort_order,
                    :version_number,
                    :created_at,
                    :last_updated,
                    1
                 )'
            );

            $nextVersionStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(version_number), 0) + 1
                 FROM hymn_usage_db
                 WHERE sunday_id = ?'
            );

            foreach ($targetServiceIds as $targetRowId) {
                $targetServiceRow = $serviceRowsById[$targetRowId] ?? null;
                $targetServiceDateObject = is_array($targetServiceRow) ? oflc_update_get_service_date_object($targetServiceRow) : null;
                $liturgicalCalendarId = (int) ($persistedObservanceDetail['observance']['id'] ?? 0) ?: null;
                $copiedFromServiceId = null;
                $targetLeaderId = $leaderId;
                if (($originalCopyState || $shouldLinkPair) && $targetServiceDateObject instanceof DateTimeImmutable && $targetServiceDateObject->format('w') === '4') {
                    $copiedFromServiceId = $pairSundayId > 0 ? $pairSundayId : null;
                    if ($originalCopyState) {
                        $targetLeaderId = $hasExplicitThursdayLeader
                            ? $thursdayLeaderId
                            : null;
                    } elseif ($shouldLinkPair) {
                        $targetLeaderId = $hasExplicitThursdayLeader
                            ? $thursdayLeaderId
                            : (is_array($targetServiceRow) ? ((int) ($targetServiceRow['leader_id'] ?? 0) ?: null) : null);
                    }
                }

                $updateServiceStmt->execute([
                    ':id' => $targetRowId,
                    ':service_date' => $targetServiceDates[$targetRowId] ?? ($serviceDateObject instanceof DateTimeImmutable ? $serviceDateObject->format('Y-m-d') : null),
                    ':liturgical_calendar_id' => $liturgicalCalendarId,
                    ':passion_reading_id' => $passionReadingId,
                    ':small_catechism_id' => $smallCatechismId,
                    ':selected_reading_set_id' => $persistedSelectedReadingSetId,
                    ':service_setting_id' => $serviceSettingId !== '' ? (int) $serviceSettingId : null,
                    ':leader_id' => $targetLeaderId,
                    ':copied_from_service_id' => $copiedFromServiceId,
                    ':last_updated' => $today,
                ]);

                $nextVersionStmt->execute([$targetRowId]);
                $nextVersion = (int) $nextVersionStmt->fetchColumn();
                if ($nextVersion <= 0) {
                    $nextVersion = 1;
                }

                $deactivateUsageStmt->execute([
                    ':last_updated' => $today,
                    ':service_id' => $targetRowId,
                ]);
                $deactivateSmallCatechismStmt->execute([
                    ':last_updated' => $today,
                    ':service_id' => $targetRowId,
                ]);
                oflc_update_insert_service_small_catechism_links($pdo, $targetRowId, $smallCatechismIds, $today);

                foreach ($hymnEntries as $entry) {
                    $insertUsageStmt->execute([
                        ':sunday_id' => $targetRowId,
                        ':hymn_id' => $entry['hymn_id'],
                        ':slot_id' => $entry['slot_id'],
                        ':sort_order' => $entry['sort_order'],
                        ':version_number' => $nextVersion,
                        ':created_at' => $today,
                        ':last_updated' => $today,
                    ]);
                }
            }

            $pdo->commit();

            $redirectQuery = [
                'expanded_service' => (string) $serviceId,
                'updated_service' => (string) $serviceId,
            ];

            header('Location: update-service.php?' . http_build_query($redirectQuery), true, 303);
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'The service could not be updated.';
        }
    }

    $formErrorsByServiceId[$serviceId] = $errors;
}

$serviceStatement = $pdo->query(
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
     ORDER BY s.service_date ' . ($sortOrder === 'asc' ? 'ASC' : 'DESC') . ', s.service_order ' . ($sortOrder === 'asc' ? 'ASC' : 'DESC') . ', s.id ' . ($sortOrder === 'asc' ? 'ASC' : 'DESC')
);
$services = $serviceStatement->fetchAll();

$serviceIds = array_map(static function (array $row): int {
    return (int) $row['id'];
}, $services);

$smallCatechismLabelsByService = oflc_update_fetch_small_catechism_labels_by_service($pdo, $serviceIds);

$hymnRowsByService = [];
if ($serviceIds !== []) {
    $placeholders = implode(', ', array_fill(0, count($serviceIds), '?'));
    $hymnStatement = $pdo->prepare(
        'SELECT
            hu.sunday_id AS service_id,
            hu.slot_id,
            hs.slot_name,
            hu.sort_order,
            hu.version_number,
            hd.id AS hymn_id,
            hd.hymnal,
            hd.hymn_number,
            hd.hymn_title,
            hd.insert_use
         FROM hymn_usage_db hu
         LEFT JOIN hymn_slot_db hs ON hs.id = hu.slot_id
         LEFT JOIN hymn_db hd ON hd.id = hu.hymn_id
         WHERE hu.is_active = 1
           AND hu.sunday_id IN (' . $placeholders . ')
         ORDER BY hu.sunday_id ASC, hs.default_sort_order ASC, hu.sort_order ASC, hu.id ASC'
    );
    $hymnStatement->execute($serviceIds);

    foreach ($hymnStatement->fetchAll() as $hymnRow) {
        $serviceId = (int) ($hymnRow['service_id'] ?? 0);
        if (!isset($hymnRowsByService[$serviceId])) {
            $hymnRowsByService[$serviceId] = [];
        }

        $hymnRowsByService[$serviceId][] = $hymnRow;
    }
}

$scheduleGroups = oflc_update_group_schedule_services($services);
if ($scheduleFilterError === null) {
    $scheduleGroups = array_values(array_filter($scheduleGroups, static function (array $group) use ($rubricFilterStartDate, $rubricFilterEndDate): bool {
        return oflc_update_group_within_date_range($group, $rubricFilterStartDate, $rubricFilterEndDate);
    }));
}

$activeObservanceDetails = oflc_service_fetch_active_observance_details($pdo);
$observanceCatalogPayload = oflc_update_build_observance_catalog_payload($activeObservanceDetails);
$dateObservanceSuggestionsCache = [];
$searchPayload = [
    'forms_by_id' => [],
    'search_labels' => [],
    'id_by_label' => [],
    'id_by_lookup' => [],
];

include 'includes/header.php';
?>

<h3>Update a Service</h3>

<div
    id="update-service-filter-root"
    data-reset-rubric-year="<?php echo htmlspecialchars($currentRubricYearOption['key'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
    data-reset-start-date="<?php echo htmlspecialchars($currentMonthStartDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"
    data-reset-end-date="<?php echo htmlspecialchars($currentMonthEndDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"
    data-reset-sort-order="desc"
>
<?php if ($rubricYearOptions !== []): ?>
    <form method="post" action="update-service.php" class="schedule-filter-form update-service-filter-form" id="update-service-filter-form">
        <input type="hidden" name="update_service_filters" value="1">
        <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sortOrder, ENT_QUOTES, 'UTF-8'); ?>" data-update-service-sort-input="1">
        <label class="schedule-filter-field">
            <span>Schedule Year</span>
            <select name="rubric_year" data-update-service-rubric-year-select="1">
                <option value="">Choose Year</option>
                <?php foreach ($rubricYearOptions as $rubricYearOption): ?>
                    <option
                        value="<?php echo htmlspecialchars((string) $rubricYearOption['key'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-start-date="<?php echo htmlspecialchars((string) $rubricYearOption['start_date'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-end-date="<?php echo htmlspecialchars((string) $rubricYearOption['end_date'], ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo $selectedRubricYear === (string) $rubricYearOption['key'] ? ' selected' : ''; ?>
                    >
                        <?php echo htmlspecialchars((string) $rubricYearOption['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="schedule-filter-field">
            <span>Start Date</span>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($filterStartInput, ENT_QUOTES, 'UTF-8'); ?>" data-update-service-date-field="start">
        </label>
        <label class="schedule-filter-field">
            <span>End Date</span>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($filterEndInput, ENT_QUOTES, 'UTF-8'); ?>" data-update-service-date-field="end">
        </label>
        <div class="schedule-filter-actions">
            <button
                type="button"
                class="schedule-sort-button is-active schedule-sort-button-<?php echo htmlspecialchars($sortOrder, ENT_QUOTES, 'UTF-8'); ?>"
                data-update-service-sort-toggle="1"
                data-next-sort-order="<?php echo htmlspecialchars($sortOrder === 'asc' ? 'desc' : 'asc', ENT_QUOTES, 'UTF-8'); ?>"
                aria-label="<?php echo htmlspecialchars($sortOrder === 'asc' ? 'Switch to descending order' : 'Switch to ascending order', ENT_QUOTES, 'UTF-8'); ?>"
            >
                <?php echo $sortOrder === 'asc' ? 'Asc ↓' : 'Desc ↑'; ?>
            </button>
            <a href="<?php echo htmlspecialchars($updateServiceResetUrl, ENT_QUOTES, 'UTF-8'); ?>" class="schedule-filter-reset" data-update-service-reset="1">Reset</a>
        </div>
    </form>
<?php endif; ?>

<?php if ($scheduleFilterError !== null): ?>
    <p class="planning-error"><?php echo htmlspecialchars($scheduleFilterError, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>
</div>

<div class="remove-service-search-panel">
    <label for="update-service-search">Search by Date or Observance</label>
    <input
        type="text"
        id="update-service-search"
        class="service-card-text"
        placeholder="Start typing a date or observance"
        autocomplete="off"
        value=""
    >
</div>

<?php if ($hymnCatalog['suggestions'] !== []): ?>
    <datalist id="hymn-options">
        <?php foreach ($hymnCatalog['suggestions'] as $suggestion): ?>
            <option value="<?php echo htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8'); ?>"></option>
        <?php endforeach; ?>
    </datalist>
<?php endif; ?>

<?php if ($smallCatechismOptions !== []): ?>
    <datalist id="small-catechism-options">
        <?php foreach ($smallCatechismOptions as $smallCatechismOption): ?>
            <?php $smallCatechismLabel = trim((string) ($smallCatechismOption['label'] ?? '')); ?>
            <?php if ($smallCatechismLabel !== ''): ?>
                <option value="<?php echo htmlspecialchars($smallCatechismLabel, ENT_QUOTES, 'UTF-8'); ?>"></option>
            <?php endif; ?>
        <?php endforeach; ?>
    </datalist>
<?php endif; ?>

<?php if ($leaders !== []): ?>
    <datalist id="leader-options">
        <?php foreach ($leaders as $leader): ?>
            <?php $leaderLastName = trim((string) ($leader['last_name'] ?? '')); ?>
            <?php if ($leaderLastName !== ''): ?>
                <option value="<?php echo htmlspecialchars($leaderLastName, ENT_QUOTES, 'UTF-8'); ?>"></option>
            <?php endif; ?>
        <?php endforeach; ?>
    </datalist>
<?php endif; ?>

<?php if ($scheduleGroups !== []): ?>
    <div class="update-service-list">
        <?php foreach ($scheduleGroups as $group): ?>
            <?php
            $primaryService = $group[0];
            $editTargets = oflc_update_build_group_edit_targets($group);
            $combinedDate = oflc_update_format_combined_service_date($group);
            $colorClass = oflc_update_get_liturgical_color_text_class($primaryService['liturgical_color'] ?? null);
            $observanceName = trim((string) ($primaryService['observance_name'] ?? ''));
            $summaryDate = $combinedDate !== '' ? $combinedDate : 'Undated service';
            $summaryObservance = $observanceName !== '' ? $observanceName : 'Unassigned observance';
            $groupServiceIds = array_map(static function (array $service): int {
                return (int) ($service['id'] ?? 0);
            }, $group);
            $rowIsOpen = $activeExpandedServiceId > 0 && in_array($activeExpandedServiceId, $groupServiceIds, true);
            $groupSearchTextParts = [$summaryDate, $summaryObservance];
            foreach ($editTargets as $editTarget) {
                $displayService = $editTarget['display_service'] ?? [];
                $searchLabel = oflc_update_format_search_label(
                    is_array($displayService) ? $displayService : [],
                    is_array($editTarget['services'] ?? null) ? $editTarget['services'] : []
                );
                if ($searchLabel !== '') {
                    $groupSearchTextParts[] = $searchLabel;
                }
                if (is_array($displayService)) {
                    foreach ([
                        trim((string) ($displayService['service_date'] ?? '')),
                        trim((string) ($displayService['observance_name'] ?? '')),
                        trim((string) ($displayService['setting_name'] ?? '')),
                    ] as $searchValue) {
                        if ($searchValue !== '') {
                            $groupSearchTextParts[] = $searchValue;
                        }
                    }
                }
            }
            $groupSearchText = strtolower(implode(' ', array_values(array_unique(array_filter($groupSearchTextParts, static function (string $value): bool {
                return $value !== '';
            })))));
            ?>
            <details class="update-service-row <?php echo htmlspecialchars($colorClass, ENT_QUOTES, 'UTF-8'); ?>" data-search-text="<?php echo htmlspecialchars($groupSearchText, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $rowIsOpen ? ' open' : ''; ?>>
                <summary class="update-service-summary">
                    <span class="update-service-summary-text">
                        <?php echo htmlspecialchars($summaryDate, ENT_QUOTES, 'UTF-8'); ?>
                        <span class="update-service-summary-separator" aria-hidden="true">&bull;</span>
                        <?php echo htmlspecialchars($summaryObservance, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </summary>
                <div class="update-service-forms">
                    <?php foreach ($editTargets as $editTarget): ?>
                        <?php
                        $displayService = $editTarget['display_service'];
                        $linkedServices = $editTarget['services'];
                        $serviceId = (int) ($editTarget['form_key'] ?? 0);
                        $showPreviousThursdayToggle = (bool) ($editTarget['show_copy_toggle'] ?? false);
                        $originalCopyToPreviousThursday = (bool) ($editTarget['is_linked'] ?? false);
                        $thursdayService = $editTarget['thursday_service'] ?? null;
                        $sundayService = $editTarget['sunday_service'] ?? null;
                        $defaultSettingDetail = isset($serviceSettingsById[(string) ($displayService['service_setting_id'] ?? '')])
                            ? $serviceSettingsById[(string) $displayService['service_setting_id']]
                            : null;
                        $baseFormState = oflc_update_build_form_state_from_service(
                            $displayService,
                            $hymnRowsByService[$serviceId] ?? [],
                            $defaultSettingDetail,
                            $hymnSlots,
                            $smallCatechismLabelsByService[$serviceId] ?? []
                        );
                        if ($originalCopyToPreviousThursday && is_array($thursdayService)) {
                            $baseFormState['thursday_preacher'] = trim((string) ($thursdayService['leader_last_name'] ?? ''));
                            if ($baseFormState['selected_reading_set_id'] === '') {
                                $baseFormState['selected_reading_set_id'] = trim((string) ($thursdayService['selected_reading_set_id'] ?? ''));
                            }
                        }
                        $formState = isset($formStateByServiceId[$serviceId])
                            ? oflc_update_merge_form_state($baseFormState, $formStateByServiceId[$serviceId])
                            : $baseFormState;
                        $selectedServiceSetting = $formState['service_setting'];
                        $selectedServiceSettingDetail = $selectedServiceSetting !== '' && isset($serviceSettingsById[$selectedServiceSetting])
                            ? $serviceSettingsById[$selectedServiceSetting]
                            : null;
                        $hymnFieldDefinitions = oflc_update_build_hymn_field_definitions($selectedServiceSettingDetail, $hymnSlots);
                        $formDate = $formState['service_date'];
                        if (!isset($dateObservanceSuggestionsCache[$formDate])) {
                            $dateObservanceSuggestionsCache[$formDate] = oflc_update_build_date_observance_suggestions($pdo, $formDate);
                        }
                        $dateObservanceSuggestions = $dateObservanceSuggestionsCache[$formDate];
                        $selectedObservanceId = ctype_digit((string) ($formState['observance_id'] ?? ''))
                            ? (int) $formState['observance_id']
                            : 0;
                        $selectedOptionDetail = $selectedObservanceId > 0 && isset($activeObservanceDetails[$selectedObservanceId])
                            ? $activeObservanceDetails[$selectedObservanceId]
                            : null;
                        if ($selectedOptionDetail === null && trim((string) ($formState['observance_name'] ?? '')) !== '') {
                            $selectedOptionDetail = oflc_service_fetch_observance_detail_by_name($pdo, (string) $formState['observance_name']);
                        }
                        $selectedReadingSetId = oflc_update_resolve_selected_reading_set_id_for_detail(
                            $selectedOptionDetail,
                            oflc_update_normalize_selected_reading_set_id($formState['selected_reading_set_id'] ?? '')
                        );
                        $serviceCardColorClass = $selectedOptionDetail !== null
                            ? oflc_update_get_liturgical_color_text_class($selectedOptionDetail['observance']['liturgical_color'] ?? null)
                            : oflc_update_get_liturgical_color_text_class($displayService['liturgical_color'] ?? null);
                        $serviceDateObject = DateTimeImmutable::createFromFormat('Y-m-d', $formDate);
                        $serviceDateDisplay = $serviceDateObject instanceof DateTimeImmutable
                            ? $serviceDateObject->format('l, F j')
                            : '&nbsp;';
                        $selectedServiceSettingSummary = '&nbsp;';
                        if ($selectedServiceSettingDetail !== null) {
                            $selectedServiceSettingSummary = trim((string) ($selectedServiceSettingDetail['abbreviation'] ?? ''));
                            $pageNumber = trim((string) ($selectedServiceSettingDetail['page_number'] ?? ''));
                            if ($pageNumber !== '') {
                                $selectedServiceSettingSummary .= ($selectedServiceSettingSummary !== '' ? ', ' : '') . 'p. ' . $pageNumber;
                            }
                            if ($selectedServiceSettingSummary === '') {
                                $selectedServiceSettingSummary = '&nbsp;';
                            }
                        }
                        $selectedLatinName = trim((string) ($selectedOptionDetail['observance']['latin_name'] ?? ''));
                        $selectedColorDisplay = oflc_update_get_liturgical_color_display($selectedOptionDetail['observance']['liturgical_color'] ?? null);
                        $hasPostedState = isset($formStateByServiceId[$serviceId]);
                        $copyToPreviousThursday = $showPreviousThursdayToggle
                            ? ($hasPostedState ? (bool) ($formState['copy_to_previous_thursday'] ?? false) : $originalCopyToPreviousThursday)
                            : false;
                        $linkAction = $showPreviousThursdayToggle
                            ? ($hasPostedState ? (string) ($formState['link_action'] ?? '') : '')
                            : '';
                        $previousThursdayLabel = null;
                        if ($showPreviousThursdayToggle) {
                            if (is_array($thursdayService)) {
                                $linkedDateObject = oflc_update_get_service_date_object($thursdayService);
                                if ($linkedDateObject instanceof DateTimeImmutable) {
                                    $previousThursdayLabel = $linkedDateObject->format('m/d');
                                }
                            }
                        }
                        $selectedObservanceName = trim((string) ($formState['observance_name'] ?? ''));
                        $activeObservanceName = trim((string) ($selectedOptionDetail['observance']['name'] ?? $selectedObservanceName));
                        $showSmallCatechismDropdown = oflc_update_is_advent_midweek_observance_name($activeObservanceName) || oflc_update_is_lent_midweek_observance_name($activeObservanceName);
                        $showPassionReadingDropdown = oflc_update_is_lent_midweek_observance_name($activeObservanceName);
                        $filteredPassionReadingOptions = oflc_update_filter_passion_reading_options_for_service_date($passionReadingOptions, $serviceDateObject instanceof DateTimeImmutable ? $serviceDateObject : null);
                        $readingSupplementsHtml = oflc_update_render_reading_supplements_html(
                            $showSmallCatechismDropdown,
                            $formState['selected_small_catechism_labels'] ?? [],
                            $showPassionReadingDropdown,
                            $filteredPassionReadingOptions,
                            (string) ($formState['selected_passion_reading_id'] ?? '')
                        );
                        $searchLabel = oflc_update_format_search_label($displayService, $linkedServices);
                        if ($serviceId > 0 && !isset($searchPayload['forms_by_id'][$serviceId])) {
                            $searchPayload['forms_by_id'][$serviceId] = [
                                'id' => $serviceId,
                                'search_label' => $searchLabel,
                            ];
                            $searchPayload['search_labels'][] = $searchLabel;
                            $searchPayload['id_by_label'][$searchLabel] = $serviceId;

                            foreach (array_filter([
                                $searchLabel,
                                trim((string) ($displayService['service_date'] ?? '')),
                                trim((string) ($displayService['observance_name'] ?? '')),
                                trim((string) ($displayService['setting_name'] ?? '')),
                                trim((string) ($summaryDate ?? '')),
                            ], static function (string $value): bool {
                                return $value !== '';
                            }) as $lookupValue) {
                                oflc_update_register_search_lookup($searchPayload['id_by_lookup'], $lookupValue, $serviceId);
                            }
                        }
                        ?>
                        <div class="update-service-form-wrap" id="service-<?php echo $serviceId; ?>" data-search-id="<?php echo $serviceId; ?>">
                            <?php if ($updatedServiceId === $serviceId): ?>
                                <p class="planning-success">Service updated.</p>
                            <?php endif; ?>
                            <?php if (!empty($formErrorsByServiceId[$serviceId])): ?>
                                <p class="planning-error"><?php echo htmlspecialchars(implode(' ', $formErrorsByServiceId[$serviceId]), ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <form
                                class="service-card update-service-edit-card <?php echo htmlspecialchars($serviceCardColorClass, ENT_QUOTES, 'UTF-8'); ?> js-update-service-form"
                                method="post"
                                action="update-service.php"
                                data-selected-reading-set-id="<?php echo htmlspecialchars($selectedReadingSetId !== null ? (string) $selectedReadingSetId : '', ENT_QUOTES, 'UTF-8'); ?>"
                                data-selected-new-reading-set="<?php echo htmlspecialchars((string) ($formState['selected_new_reading_set'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-initial-reading-editor="<?php echo htmlspecialchars(json_encode($formState['new_reading_sets'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                data-date-observance-suggestions="<?php echo htmlspecialchars(json_encode(array_values($dateObservanceSuggestions), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                data-small-catechism-options="<?php echo htmlspecialchars(json_encode(array_values($smallCatechismOptions), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                data-passion-reading-options="<?php echo htmlspecialchars(json_encode(array_values($passionReadingOptions), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                data-selected-small-catechism-labels="<?php echo htmlspecialchars(json_encode(array_values($formState['selected_small_catechism_labels'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                data-selected-passion-reading-id="<?php echo htmlspecialchars((string) ($formState['selected_passion_reading_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-initial-hymn-state="<?php echo htmlspecialchars(json_encode([
                                    'hymns' => array_map('strval', $formState['selected_hymns'] ?? []),
                                    'opening_processional' => !empty($formState['opening_processional']),
                                    'closing_recessional' => !empty($formState['closing_recessional']),
                                    'extra_rows' => array_values($formState['extra_hymn_rows'] ?? []),
                                    'order' => array_values($formState['hymn_row_order'] ?? []),
                                    'next_extra_id' => count($formState['extra_hymn_rows'] ?? []) + 1,
                                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                data-hymn-suggestions-id="hymn-options"
                            >
                                <input type="hidden" name="update_service" value="1">
                                <input type="hidden" name="service_id" value="<?php echo $serviceId; ?>">
                                <input type="hidden" name="return_rubric_year" value="<?php echo htmlspecialchars($selectedRubricYear, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="return_start_date" value="<?php echo htmlspecialchars($filterStartInput, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="return_end_date" value="<?php echo htmlspecialchars($filterEndInput, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="return_sort_order" value="<?php echo htmlspecialchars($sortOrder, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="hymn_row_order" value="<?php echo htmlspecialchars(json_encode(array_values($formState['hymn_row_order'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>" class="js-hymn-row-order-input">
                                <input type="hidden" name="service_ids" value="<?php echo htmlspecialchars(
                                    $showPreviousThursdayToggle && is_array($thursdayService) && is_array($sundayService)
                                        ? (string) ((int) ($thursdayService['id'] ?? 0)) . ',' . (string) ((int) ($sundayService['id'] ?? 0))
                                        : implode(',', array_map(static function (array $linkedService): string {
                                            return (string) ((int) ($linkedService['id'] ?? 0));
                                        }, $linkedServices)),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>">
                                <?php if ($showPreviousThursdayToggle && is_array($thursdayService) && is_array($sundayService)): ?>
                                    <input type="hidden" name="pair_thursday_id" value="<?php echo (int) ($thursdayService['id'] ?? 0); ?>">
                                    <input type="hidden" name="pair_sunday_id" value="<?php echo (int) ($sundayService['id'] ?? 0); ?>">
                                    <input type="hidden" name="original_copy_to_previous_thursday" value="<?php echo $originalCopyToPreviousThursday ? '1' : '0'; ?>">
                                    <input type="hidden" name="link_action" value="<?php echo htmlspecialchars($linkAction, ENT_QUOTES, 'UTF-8'); ?>" class="js-link-action-flag">
                                <?php endif; ?>
                                <div class="service-card-grid">
                                    <section class="service-card-panel">
                                        <div class="service-card-date-row">
                                            <input
                                                type="date"
                                                id="service_date_<?php echo $serviceId; ?>"
                                                name="service_date"
                                                class="service-card-text"
                                                value="<?php echo htmlspecialchars($formDate, ENT_QUOTES, 'UTF-8'); ?>"
                                            >
                                        </div>
                                        <div class="service-card-display-date"><?php echo $serviceDateDisplay === '&nbsp;' ? '&nbsp;' : htmlspecialchars($serviceDateDisplay, ENT_QUOTES, 'UTF-8'); ?></div>
                                        <input type="hidden" name="observance_id" value="<?php echo htmlspecialchars((string) ($selectedObservanceId > 0 ? $selectedObservanceId : ''), ENT_QUOTES, 'UTF-8'); ?>" class="js-observance-id-input">
                                        <div class="service-card-suggestion-anchor">
                                            <input
                                                type="text"
                                                id="observance_name_<?php echo $serviceId; ?>"
                                                name="observance_name"
                                                class="service-card-text js-observance-name-input"
                                                value="<?php echo htmlspecialchars($selectedObservanceName, ENT_QUOTES, 'UTF-8'); ?>"
                                                placeholder="Liturgical observance"
                                                autocomplete="off"
                                            >
                                            <div class="service-card-suggestion-list js-observance-suggestion-list" hidden></div>
                                        </div>
                                        <div class="service-card-latin-name js-observance-latin-name">
                                            <?php echo $selectedLatinName !== '' ? htmlspecialchars($selectedLatinName, ENT_QUOTES, 'UTF-8') : '&nbsp;'; ?>
                                        </div>
                                        <div class="service-card-meta">
                                            <select id="service_setting_<?php echo $serviceId; ?>" name="service_setting" class="service-card-select js-service-setting-select">
                                                <option value="">Select a service</option>
                                                <?php foreach ($serviceSettings as $serviceSetting): ?>
                                                    <option
                                                        value="<?php echo htmlspecialchars((string) $serviceSetting['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-abbreviation="<?php echo htmlspecialchars(trim((string) ($serviceSetting['abbreviation'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-page-number="<?php echo htmlspecialchars(trim((string) ($serviceSetting['page_number'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                                                        <?php echo $selectedServiceSetting === (string) $serviceSetting['id'] ? ' selected' : ''; ?>
                                                    >
                                                        <?php echo htmlspecialchars((string) $serviceSetting['setting_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="service-card-service-summary js-service-setting-summary"><?php echo $selectedServiceSettingSummary === '&nbsp;' ? '&nbsp;' : htmlspecialchars($selectedServiceSettingSummary, ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="service-card-color-slot">
                                                <div class="service-card-color-line js-observance-color-line<?php echo $selectedOptionDetail === null && $selectedObservanceName !== '' ? ' is-hidden' : ''; ?>"><?php echo $selectedColorDisplay !== '' ? htmlspecialchars($selectedColorDisplay, ENT_QUOTES, 'UTF-8') : '&nbsp;'; ?></div>
                                                <div class="update-service-new-observance-color js-new-observance-color-wrap<?php echo $selectedOptionDetail === null && $selectedObservanceName !== '' ? ' is-visible' : ''; ?>">
                                                    <select id="new_observance_color_<?php echo $serviceId; ?>" name="new_observance_color" class="service-card-select js-new-observance-color-select">
                                                        <option value="">Choose color</option>
                                                        <?php foreach ($liturgicalColorOptions as $liturgicalColorOption): ?>
                                                            <option value="<?php echo htmlspecialchars($liturgicalColorOption, ENT_QUOTES, 'UTF-8'); ?>"<?php echo ($formState['new_observance_color'] ?? '') === $liturgicalColorOption ? ' selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($liturgicalColorOption, ENT_QUOTES, 'UTF-8'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <?php if ($showPreviousThursdayToggle && $previousThursdayLabel !== null): ?>
                                                <label class="service-card-checkbox update-service-thursday-toggle">
                                                    <input
                                                        type="checkbox"
                                                        name="copy_to_previous_thursday"
                                                        value="1"
                                                        class="js-copy-to-previous-thursday"
                                                        data-original-copy="<?php echo $originalCopyToPreviousThursday ? '1' : '0'; ?>"
                                                        <?php echo $copyToPreviousThursday ? 'checked' : ''; ?>
                                                    >
                                                    <span>Copy this service to the previous Thursday (<?php echo htmlspecialchars($previousThursdayLabel, ENT_QUOTES, 'UTF-8'); ?>)?</span>
                                                </label>
                                                <div class="update-service-separate-alert js-separate-thursday-alert">
                                                    <div class="update-service-separate-alert-title js-separate-thursday-alert-title">Would you like to separate Thursday from Sunday?</div>
                                                    <div class="update-service-separate-alert-actions">
                                                        <button type="button" class="update-service-confirm-button update-service-confirm-button-yes js-separate-thursday-yes">Yes</button>
                                                        <button type="button" class="update-service-confirm-button update-service-confirm-button-no js-separate-thursday-no">No</button>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </section>

                                    <section class="service-card-panel">
                                        <div class="service-card-readings js-observance-readings"><?php echo $readingSupplementsHtml . (count($selectedOptionDetail['reading_sets'] ?? []) > 0 ? oflc_update_render_observance_readings_html($selectedOptionDetail, $selectedReadingSetId) : oflc_update_render_new_reading_set_editor_html($formState['new_reading_sets'] ?? oflc_service_normalize_new_reading_set_drafts([]), (string) ($formState['selected_new_reading_set'] ?? ''))); ?></div>
                                    </section>

                                    <section class="service-card-panel">
                                        <div class="service-card-hymns js-update-service-hymns">
                                            <?php if ($hymnFieldDefinitions !== []): ?>
                                                <div class="service-card-hymn-instruction">Check boxes mark hymns as procession / recession.</div>
                                            <?php endif; ?>
                                            <?php foreach ($hymnFieldDefinitions as $hymnField): ?>
                                                <?php $hymnIndex = (int) $hymnField['index']; ?>
                                                <div class="service-card-hymn-row">
                                                    <input
                                                        type="text"
                                                        id="hymn_<?php echo $serviceId; ?>_<?php echo $hymnIndex; ?>"
                                                        name="hymn_<?php echo $hymnIndex; ?>"
                                                        value="<?php echo htmlspecialchars($formState['selected_hymns'][$hymnIndex] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                        placeholder="<?php echo htmlspecialchars((string) $hymnField['label'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-list-id="hymn-options"
                                                        autocomplete="off"
                                                        class="service-card-hymn-lookup"
                                                    >
                                                    <?php if (!empty($hymnField['toggle_name'])): ?>
                                                        <?php $toggleName = (string) $hymnField['toggle_name']; ?>
                                                        <label class="service-card-hymn-inline-toggle" for="<?php echo htmlspecialchars($toggleName . '_' . $serviceId, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input
                                                                type="checkbox"
                                                                id="<?php echo htmlspecialchars($toggleName . '_' . $serviceId, ENT_QUOTES, 'UTF-8'); ?>"
                                                                name="<?php echo htmlspecialchars($toggleName, ENT_QUOTES, 'UTF-8'); ?>"
                                                                value="1"
                                                                <?php echo !empty($formState[$toggleName]) ? 'checked' : ''; ?>
                                                            >
                                                        </label>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>

                                    <section class="service-card-panel">
                                        <label class="service-card-label" for="preacher_<?php echo $serviceId; ?>"><?php echo $originalCopyToPreviousThursday ? 'Sunday Leader' : 'Leader'; ?></label>
                                        <input
                                            type="text"
                                            id="preacher_<?php echo $serviceId; ?>"
                                            name="preacher"
                                            class="service-card-text"
                                            value="<?php echo htmlspecialchars($formState['preacher'], ENT_QUOTES, 'UTF-8'); ?>"
                                            placeholder="Fenker"
                                            list="leader-options"
                                        >
                                        <?php if ($originalCopyToPreviousThursday): ?>
                                            <label class="service-card-label" for="thursday_preacher_<?php echo $serviceId; ?>">Thursday Leader</label>
                                            <input
                                                type="text"
                                                id="thursday_preacher_<?php echo $serviceId; ?>"
                                                name="thursday_preacher"
                                                class="service-card-text"
                                                value="<?php echo htmlspecialchars($formState['thursday_preacher'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                placeholder="Blank = same as Sunday"
                                                list="leader-options"
                                            >
                                        <?php endif; ?>
                                        <div class="update-service-panel-actions">
                                            <button type="submit" class="add-hymn-button">Update Service</button>
                                        </div>
                                    </section>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <p>No services are currently scheduled.</p>
<?php endif; ?>

<script type="application/json" id="update-service-search-data">
<?php echo json_encode($searchPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>

<script>
(function () {
    var hymnLookupByKey = <?php echo json_encode($hymnCatalog['lookup_by_key'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var hymnTunesById = <?php echo json_encode($hymnCatalog['tune_by_id'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var hymnDefinitionsByService = <?php echo json_encode($hymnFieldDefinitionsByService, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var observanceCatalog = <?php echo json_encode($observanceCatalogPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var emptyReadingDrafts = <?php echo json_encode(array_values(oflc_service_normalize_new_reading_set_drafts([])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var initialSearchServiceId = <?php echo json_encode($activeExpandedServiceId > 0 ? $activeExpandedServiceId : $updatedServiceId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var searchDataElement = document.getElementById('update-service-search-data');
    var searchInput = document.getElementById('update-service-search');
    var searchRows = document.querySelectorAll('.update-service-row');
    var filterRoot = document.getElementById('update-service-filter-root');
    var filterForm = document.getElementById('update-service-filter-form');
    var searchData = { forms_by_id: {}, search_labels: [], id_by_label: {}, id_by_lookup: {} };

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    try {
        searchData = JSON.parse((searchDataElement && searchDataElement.textContent) || '{}');
    } catch (error) {
        searchData = { forms_by_id: {}, search_labels: [], id_by_label: {}, id_by_lookup: {} };
    }

    function resolveSearchForm(value) {
        var normalizedValue = String(value || '').trim();
        var lookupId;

        if (normalizedValue === '') {
            return null;
        }

        if (searchData.id_by_label && searchData.id_by_label[normalizedValue]) {
            return searchData.forms_by_id[String(searchData.id_by_label[normalizedValue])] || searchData.forms_by_id[searchData.id_by_label[normalizedValue]] || null;
        }

        lookupId = searchData.id_by_lookup ? searchData.id_by_lookup[normalizedValue.toLowerCase()] : null;
        if (lookupId && searchData.forms_by_id) {
            return searchData.forms_by_id[String(lookupId)] || searchData.forms_by_id[lookupId] || null;
        }

        return null;
    }

    function applySearchFilter() {
        var query = String((searchInput && searchInput.value) || '').trim().toLowerCase();

        Array.prototype.forEach.call(searchRows, function (row) {
            var haystack = String(row.getAttribute('data-search-text') || '').toLowerCase();
            var isVisible = query === '' || haystack.indexOf(query) !== -1;

            row.hidden = !isVisible;
        });
    }

    function syncSearchSelection() {
        var formMeta = resolveSearchForm(searchInput ? searchInput.value : '');
        var targetWrap;
        var targetDetails;
        var targetInput;

        if (!formMeta) {
            return;
        }

        targetWrap = document.getElementById('service-' + String(formMeta.id || ''));
        if (!targetWrap) {
            return;
        }

        targetDetails = targetWrap.closest('details');
        if (targetDetails) {
            targetDetails.open = true;
        }

        if (formMeta.search_label && searchInput) {
            searchInput.value = String(formMeta.search_label);
        }

        targetWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        targetInput = targetWrap.querySelector('input[name="observance_name"], input[name="service_date"]');
        if (targetInput) {
            window.setTimeout(function () {
                targetInput.focus();
            }, 150);
        }
    }

    function bindReadingSelectionBehavior(scope, onChange) {
        var labels = scope.querySelectorAll('.service-card-reading-psalm');

        Array.prototype.forEach.call(labels, function (label) {
            var radio = label.querySelector('.service-card-reading-radio');

            if (!radio) {
                return;
            }

            radio.addEventListener('change', function () {
                onChange(radio.checked ? radio.value : '');
            });

            label.addEventListener('mousedown', function () {
                radio.dataset.wasChecked = radio.checked ? '1' : '0';
            });

            label.addEventListener('click', function (event) {
                if (radio.dataset.wasChecked === '1') {
                    event.preventDefault();
                    radio.checked = false;
                    onChange('');
                } else {
                    window.setTimeout(function () {
                        onChange(radio.checked ? radio.value : '');
                    }, 0);
                }

                delete radio.dataset.wasChecked;
            });
        });
    }

    function cloneReadingDrafts(drafts) {
        return Array.prototype.map.call(drafts || emptyReadingDrafts, function (draft, index) {
            return {
                index: draft && draft.index ? draft.index : index + 1,
                set_name: draft && draft.set_name ? draft.set_name : '',
                year_pattern: draft && draft.year_pattern ? draft.year_pattern : '',
                old_testament: draft && draft.old_testament ? draft.old_testament : '',
                psalm: draft && draft.psalm ? draft.psalm : '',
                epistle: draft && draft.epistle ? draft.epistle : '',
                gospel: draft && draft.gospel ? draft.gospel : ''
            };
        });
    }

    function findObservanceDetailByName(name) {
        var normalizedName = String(name || '').trim().replace(/\s+\((?:Sa|[SMTWRF])\s+\d{1,2}(?:\/\d{1,2})?\)\s*$/, '').trim().toLowerCase();
        var observanceId;

        if (normalizedName === '' || !observanceCatalog.name_lookup) {
            return null;
        }

        observanceId = observanceCatalog.name_lookup[normalizedName];
        if (!observanceId || !observanceCatalog.by_id || !observanceCatalog.by_id[observanceId]) {
            return null;
        }

        return observanceCatalog.by_id[observanceId];
    }

    function isAdventMidweekObservanceName(name) {
        var normalizedName = String(name || '').trim().toLowerCase();

        return normalizedName !== ''
            && normalizedName.indexOf('advent') !== -1
            && normalizedName.indexOf('midweek') !== -1;
    }

    function isLentMidweekObservanceName(name) {
        var normalizedName = String(name || '').trim().toLowerCase();

        return normalizedName !== ''
            && normalizedName.indexOf('lent') !== -1
            && normalizedName.indexOf('midweek') !== -1;
    }

    function getPassionCycleYear(serviceDateValue) {
        var year;

        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(serviceDateValue || '').trim())) {
            return null;
        }

        year = parseInt(String(serviceDateValue).slice(0, 4), 10);
        if (!Number.isFinite(year)) {
            return null;
        }

        return ((year - 2025) % 4 + 4) % 4 + 1;
    }

    function initializeUpdateServiceForm(form) {
        var serviceIdValue = (form.querySelector('input[name="service_id"]') || {}).value || '0';
        var serviceDateInput = form.querySelector('input[name="service_date"]');
        var displayDate = form.querySelector('.service-card-display-date');
        var settingSelect = form.querySelector('.js-service-setting-select');
        var settingSummary = form.querySelector('.js-service-setting-summary');
        var hymnPane = form.querySelector('.js-update-service-hymns');
        var observanceNameInput = form.querySelector('.js-observance-name-input');
        var observanceIdInput = form.querySelector('.js-observance-id-input');
        var observanceSuggestionList = form.querySelector('.js-observance-suggestion-list');
        var latinName = form.querySelector('.js-observance-latin-name');
        var colorLine = form.querySelector('.js-observance-color-line');
        var newObservanceColorWrap = form.querySelector('.js-new-observance-color-wrap');
        var newObservanceColorSelect = form.querySelector('.js-new-observance-color-select');
        var readingsPane = form.querySelector('.js-observance-readings');
        var previousThursdayToggle = form.querySelector('.js-copy-to-previous-thursday');
        var linkActionFlag = form.querySelector('.js-link-action-flag');
        var separateThursdayAlert = form.querySelector('.js-separate-thursday-alert');
        var separateThursdayAlertTitle = form.querySelector('.js-separate-thursday-alert-title');
        var separateThursdayYes = form.querySelector('.js-separate-thursday-yes');
        var separateThursdayNo = form.querySelector('.js-separate-thursday-no');
        var hymnSuggestionsId = form.getAttribute('data-hymn-suggestions-id') || '';
        var hymnRowOrderInput = form.querySelector('.js-hymn-row-order-input');
        var dateObservanceSuggestions = [];
        var smallCatechismOptions = [];
        var passionReadingOptions = [];
        var allObservanceSuggestions = Array.prototype.map.call(Object.keys(observanceCatalog.by_id || {}), function (key) {
            return observanceCatalog.by_id[key] && observanceCatalog.by_id[key].name ? observanceCatalog.by_id[key].name : '';
        });
        var selectedReadingSetId = form.getAttribute('data-selected-reading-set-id') || '';
        var selectedNewReadingSet = form.getAttribute('data-selected-new-reading-set') || '';
        var selectedSmallCatechismLabels = [];
        var selectedPassionReadingId = form.getAttribute('data-selected-passion-reading-id') || '';
        var readingDraftState = cloneReadingDrafts([]);
        var hymnState = {
            hymns: {},
            opening_processional: false,
            closing_recessional: false,
            extra_rows: [],
            order: [],
            next_extra_id: 1
        };

        try {
            readingDraftState = cloneReadingDrafts(JSON.parse(form.getAttribute('data-initial-reading-editor') || '[]'));
        } catch (error) {
            readingDraftState = cloneReadingDrafts([]);
        }

        try {
            dateObservanceSuggestions = JSON.parse(form.getAttribute('data-date-observance-suggestions') || '[]');
        } catch (error) {
            dateObservanceSuggestions = [];
        }

        try {
            smallCatechismOptions = JSON.parse(form.getAttribute('data-small-catechism-options') || '[]');
        } catch (error) {
            smallCatechismOptions = [];
        }

        try {
            passionReadingOptions = JSON.parse(form.getAttribute('data-passion-reading-options') || '[]');
        } catch (error) {
            passionReadingOptions = [];
        }

        try {
            selectedSmallCatechismLabels = JSON.parse(form.getAttribute('data-selected-small-catechism-labels') || '[]');
        } catch (error) {
            selectedSmallCatechismLabels = [];
        }

        try {
            hymnState = JSON.parse(form.getAttribute('data-initial-hymn-state') || '{"hymns":{},"opening_processional":false,"closing_recessional":false,"extra_rows":[],"order":[],"next_extra_id":1}');
        } catch (error) {
            hymnState = {
                hymns: {},
                opening_processional: false,
                closing_recessional: false,
                extra_rows: [],
                order: [],
                next_extra_id: 1
            };
        }

        if (!settingSelect || !settingSummary || !hymnPane || !observanceSuggestionList || !newObservanceColorWrap || !newObservanceColorSelect || !readingsPane) {
            return;
        }

        function ensureSmallCatechismRows() {
            if (!Array.isArray(selectedSmallCatechismLabels) || selectedSmallCatechismLabels.length === 0) {
                selectedSmallCatechismLabels = [''];
            }
        }

        function buildOptionHtml(options, selectedValue, placeholder) {
            var html = '';

            if (placeholder) {
                html += '<option value="">' + escapeHtml(placeholder) + '</option>';
            }

            Array.prototype.forEach.call(options || [], function (option) {
                var optionValue = String(option && option.id ? option.id : '');

                html += '<option value="' + escapeHtml(optionValue) + '"' + (optionValue === String(selectedValue || '') ? ' selected' : '') + '>';
                html += escapeHtml(option && option.label ? option.label : optionValue);
                html += '</option>';
            });

            return html;
        }

        function getFilteredPassionReadingOptions() {
            var cycleYear = getPassionCycleYear(serviceDateInput ? serviceDateInput.value : '');
            var filteredOptions;

            if (cycleYear === null) {
                return passionReadingOptions;
            }

            filteredOptions = Array.prototype.filter.call(passionReadingOptions, function (option) {
                return parseInt(option && option.cycle_year ? option.cycle_year : '0', 10) === cycleYear;
            });

            return filteredOptions.length > 0 ? filteredOptions : passionReadingOptions;
        }

        function buildSmallCatechismFieldsHtml() {
            var html = '';

            ensureSmallCatechismRows();
            Array.prototype.forEach.call(selectedSmallCatechismLabels, function (label, index) {
                html +=
                    '<div class="service-card-inline-field-row">' +
                        '<input type="text" class="service-card-text update-service-reading-input js-small-catechism-input" name="small_catechism_labels[]" value="' + escapeHtml(label || '') + '" placeholder="Select Luther\'s Small Catechism" list="small-catechism-options" autocomplete="off">' +
                        (index === selectedSmallCatechismLabels.length - 1
                            ? '<button type="button" class="service-card-add-inline-button js-small-catechism-add">+</button>'
                            : '') +
                    '</div>';
            });

            return html;
        }

        function buildPassionReadingFieldHtml() {
            return '<select class="service-card-select update-service-reading-input js-passion-reading-select" name="passion_reading_id">' +
                buildOptionHtml(getFilteredPassionReadingOptions(), selectedPassionReadingId, 'Select passion reading') +
                '</select>';
        }

        function getReadingSupplementsHtml() {
            var activeObservanceName = String(observanceNameInput ? observanceNameInput.value : '').trim();
            var html = '';

            if (isAdventMidweekObservanceName(activeObservanceName) || isLentMidweekObservanceName(activeObservanceName)) {
                html += buildSmallCatechismFieldsHtml();
            }

            if (isLentMidweekObservanceName(activeObservanceName)) {
                html += buildPassionReadingFieldHtml();
            }

            return html;
        }

        function bindSupplementalReadingControls() {
            Array.prototype.forEach.call(readingsPane.querySelectorAll('.js-small-catechism-input'), function (input, index) {
                input.addEventListener('input', function () {
                    selectedSmallCatechismLabels[index] = input.value;
                });
            });

            Array.prototype.forEach.call(readingsPane.querySelectorAll('.js-small-catechism-add'), function (button) {
                button.addEventListener('click', function () {
                    selectedSmallCatechismLabels.push('');
                    rerenderCurrentReadingsPane();
                });
            });

            Array.prototype.forEach.call(readingsPane.querySelectorAll('.js-passion-reading-select'), function (input) {
                input.addEventListener('change', function () {
                    selectedPassionReadingId = input.value;
                });
            });
        }

        function applyFormColorClass(colorClass) {
            var classes = [
                'service-card-color-dark',
                'service-card-color-gold',
                'service-card-color-green',
                'service-card-color-violet',
                'service-card-color-blue',
                'service-card-color-rose',
                'service-card-color-red',
                'service-card-color-black'
            ];

            Array.prototype.forEach.call(classes, function (className) {
                form.classList.remove(className);
            });

            form.classList.add(colorClass || 'service-card-color-dark');
        }

        function getObservanceSuggestionSource(preferDateSuggestions) {
            var query = String(observanceNameInput ? observanceNameInput.value : '').trim().toLowerCase();
            var source = dateObservanceSuggestions;

            if (source.length === 0) {
                source = allObservanceSuggestions;
            } else if (!preferDateSuggestions && query !== '') {
                source = Array.prototype.some.call(dateObservanceSuggestions, function (name) {
                    return String(name || '').toLowerCase().indexOf(query) !== -1;
                }) ? dateObservanceSuggestions : allObservanceSuggestions;
            }

            if (preferDateSuggestions && query !== '') {
                source = Array.prototype.filter.call(source, function (name) {
                    return String(name || '').trim().toLowerCase() !== query;
                });
            }

            if (!preferDateSuggestions && query !== '') {
                source = Array.prototype.filter.call(source, function (name) {
                    return String(name || '').toLowerCase().indexOf(query) !== -1;
                });
            }

            return Array.prototype.filter.call(source, function (name, index) {
                return String(name || '').trim() !== '' && source.indexOf(name) === index;
            });
        }

        function renderObservanceSuggestionOptions(preferDateSuggestions) {
            var source = getObservanceSuggestionSource(preferDateSuggestions);

            observanceSuggestionList.innerHTML = '';
            Array.prototype.forEach.call(source, function (name) {
                var button = document.createElement('button');

                button.type = 'button';
                button.className = 'service-card-suggestion-item';
                button.textContent = name;
                button.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                });
                button.addEventListener('click', function () {
                    if (observanceNameInput) {
                        observanceNameInput.value = name;
                    }
                    updateObservanceDetails(true);
                    hideObservanceSuggestionOptions();
                    if (observanceNameInput) {
                        observanceNameInput.focus();
                        if (typeof observanceNameInput.setSelectionRange === 'function') {
                            observanceNameInput.setSelectionRange(observanceNameInput.value.length, observanceNameInput.value.length);
                        }
                    }
                });

                observanceSuggestionList.appendChild(button);
            });

            observanceSuggestionList.hidden = source.length === 0;
            observanceSuggestionList.classList.toggle('is-visible', source.length > 0);
        }

        function showObservanceSuggestionOptions(preferDateSuggestions) {
            if (!observanceNameInput) {
                return;
            }

            renderObservanceSuggestionOptions(!!preferDateSuggestions);
        }

        function hideObservanceSuggestionOptions() {
            observanceSuggestionList.hidden = true;
            observanceSuggestionList.classList.remove('is-visible');
            observanceSuggestionList.innerHTML = '';
        }

        function resolveHymnId(value) {
            var key = String(value || '').trim();
            var hymnId;

            if (key === '') {
                return 0;
            }

            hymnId = parseInt((hymnLookupByKey && hymnLookupByKey[key]) || '0', 10);
            return Number.isFinite(hymnId) ? hymnId : 0;
        }

        function normalizeHymnTune(value) {
            return String(value || '').trim().toLowerCase();
        }

        function getDuplicateTuneKeys(scope) {
            var hymnRows;
            var duplicateTuneKeys = {};
            var tuneGroups = {};

            if (!scope) {
                return duplicateTuneKeys;
            }

            hymnRows = scope.querySelectorAll('.service-card-hymn-row');
            Array.prototype.forEach.call(hymnRows, function (row) {
                var input = row.querySelector('.service-card-hymn-lookup');
                var hymnId;
                var tuneKey;

                if (!input) {
                    return;
                }

                hymnId = resolveHymnId(input.value);
                tuneKey = normalizeHymnTune(hymnTunesById && hymnId > 0 ? hymnTunesById[hymnId] : '');
                if (tuneKey === '') {
                    return;
                }

                if (!tuneGroups[tuneKey]) {
                    tuneGroups[tuneKey] = { hymn_ids: {} };
                }
                tuneGroups[tuneKey].hymn_ids[String(hymnId)] = true;
            });

            Object.keys(tuneGroups).forEach(function (tuneKey) {
                if (Object.keys(tuneGroups[tuneKey].hymn_ids).length > 1) {
                    duplicateTuneKeys[tuneKey] = true;
                }
            });

            return duplicateTuneKeys;
        }

        function hasDuplicateTuneSelections(scope) {
            return Object.keys(getDuplicateTuneKeys(scope)).length > 0;
        }

        function updateDuplicateTuneHighlights(scope) {
            var hymnRows;
            var duplicateTuneKeys = getDuplicateTuneKeys(scope);

            if (!scope) {
                return;
            }

            hymnRows = scope.querySelectorAll('.service-card-hymn-row');
            Array.prototype.forEach.call(hymnRows, function (row) {
                var input = row.querySelector('.service-card-hymn-lookup');
                var hymnId;
                var tuneKey;
                var isDuplicate;

                if (!input) {
                    return;
                }

                hymnId = resolveHymnId(input.value);
                tuneKey = normalizeHymnTune(hymnTunesById && hymnId > 0 ? hymnTunesById[hymnId] : '');
                isDuplicate = tuneKey !== '' && !!duplicateTuneKeys[tuneKey];

                row.classList.toggle('has-duplicate-tune', isDuplicate);
                input.classList.toggle('service-card-hymn-lookup-duplicate-tune', isDuplicate);

                if (isDuplicate) {
                    input.title = 'Another hymn in this service uses the same tune.';
                } else if (input.getAttribute('title') === 'Another hymn in this service uses the same tune.') {
                    input.removeAttribute('title');
                }
            });
        }

        function normalizeHymnState(serviceId) {
            var definitions = hymnDefinitionsByService[serviceId] || [];
            var baseKeys = definitions.map(function (definition) {
                return 'base:' + String(definition.index);
            });
            var extraKeyMap = {};
            var order = [];

            if (!hymnState || typeof hymnState !== 'object') {
                hymnState = {};
            }
            if (!hymnState.hymns || typeof hymnState.hymns !== 'object') {
                hymnState.hymns = {};
            }
            if (!Array.isArray(hymnState.extra_rows)) {
                hymnState.extra_rows = [];
            }
            hymnState.extra_rows = hymnState.extra_rows.filter(function (row) {
                return row && row.key;
            }).map(function (row) {
                return {
                    key: String(row.key),
                    value: String(row.value || ''),
                    slot_name: row.slot_name === 'Distribution Hymn' ? 'Distribution Hymn' : 'Other Hymn'
                };
            });
            hymnState.extra_rows.forEach(function (row) {
                extraKeyMap[row.key] = true;
            });

            if (!Array.isArray(hymnState.order)) {
                hymnState.order = [];
            }

            hymnState.order.forEach(function (key) {
                key = String(key || '');
                if (!key) {
                    return;
                }
                if ((baseKeys.indexOf(key) !== -1 || extraKeyMap[key]) && order.indexOf(key) === -1) {
                    order.push(key);
                }
            });

            baseKeys.forEach(function (key) {
                if (order.indexOf(key) === -1) {
                    order.push(key);
                }
            });
            hymnState.extra_rows.forEach(function (row) {
                if (order.indexOf(row.key) === -1) {
                    order.push(row.key);
                }
            });

            hymnState.order = order;
            hymnState.next_extra_id = Math.max(
                parseInt(hymnState.next_extra_id || '1', 10) || 1,
                hymnState.extra_rows.length + 1
            );

            if (hymnRowOrderInput) {
                hymnRowOrderInput.value = JSON.stringify(hymnState.order);
            }

            return definitions;
        }

        function findExtraHymnRow(key) {
            return hymnState.extra_rows.find(function (row) {
                return String(row.key) === String(key);
            }) || null;
        }

        function captureHymnState() {
            var hymnRows = hymnPane.querySelectorAll('.service-card-hymn-row');
            var openingProcessional = hymnPane.querySelector('input[name="opening_processional"]');
            var closingRecessional = hymnPane.querySelector('input[name="closing_recessional"]');
            var nextExtraRows = [];
            var nextOrder = [];

            hymnState.hymns = hymnState.hymns || {};
            Array.prototype.forEach.call(hymnRows, function (row) {
                var rowKey = row.getAttribute('data-row-key') || '';
                var input = row.querySelector('.service-card-hymn-lookup');

                if (!rowKey || !input) {
                    return;
                }

                nextOrder.push(rowKey);
                if (rowKey.indexOf('base:') === 0) {
                    hymnState.hymns[rowKey.replace('base:', '')] = input.value;
                    return;
                }

                nextExtraRows.push({
                    key: rowKey,
                    value: input.value,
                    slot_name: row.getAttribute('data-extra-slot-name') === 'Distribution Hymn' ? 'Distribution Hymn' : 'Other Hymn'
                });
            });

            hymnState.extra_rows = nextExtraRows;
            hymnState.order = nextOrder;
            hymnState.opening_processional = !!(openingProcessional && openingProcessional.checked);
            hymnState.closing_recessional = !!(closingRecessional && closingRecessional.checked);

            if (hymnRowOrderInput) {
                hymnRowOrderInput.value = JSON.stringify(hymnState.order);
            }

            updateDuplicateTuneHighlights(hymnPane);
        }

        function bindHymnLookupBehavior(scope) {
            var hymnInputs = scope.querySelectorAll('.service-card-hymn-lookup');

            Array.prototype.forEach.call(hymnInputs, function (input) {
                input.addEventListener('focus', function () {
                    input.removeAttribute('list');
                });

                input.addEventListener('input', function () {
                    if (input.value.trim() === '') {
                        input.removeAttribute('list');
                    } else if (hymnSuggestionsId) {
                        input.setAttribute('list', hymnSuggestionsId);
                    }
                    captureHymnState();
                });

                input.addEventListener('blur', function () {
                    window.setTimeout(function () {
                        input.removeAttribute('list');
                    }, 0);
                });
            });
        }

        function bindExtraHymnSlotBehavior(scope, serviceId) {
            var slotInputs = scope.querySelectorAll('.js-extra-hymn-slot-input');

            Array.prototype.forEach.call(slotInputs, function (input) {
                var anchor = input.closest('.service-card-suggestion-anchor');
                var list = anchor ? anchor.querySelector('.js-extra-hymn-slot-suggestion-list') : null;
                var hidden = anchor ? anchor.querySelector('.js-extra-hymn-slot-hidden') : null;

                function closeSlotOptions() {
                    if (list) {
                        list.hidden = true;
                        list.classList.remove('is-visible');
                        list.innerHTML = '';
                    }
                }

                function syncSlot(value) {
                    var slotValue = String(value || '').trim().toLowerCase() === 'distribution' ? 'Distribution Hymn' : 'Other Hymn';
                    var row = input.closest('.service-card-hymn-row');
                    var previousSlotValue = row ? (row.getAttribute('data-extra-slot-name') || 'Other Hymn') : 'Other Hymn';

                    input.value = slotValue === 'Distribution Hymn' ? 'Distribution' : 'Other';
                    if (hidden) {
                        hidden.value = slotValue;
                    }
                    if (row) {
                        row.setAttribute('data-slot-name', slotValue);
                        row.setAttribute('data-extra-slot-name', slotValue);
                    }
                    captureHymnState();

                    return previousSlotValue !== slotValue;
                }

                function syncSlotAndRefresh(value) {
                    var slotChanged = syncSlot(value);

                    closeSlotOptions();
                    if (slotChanged) {
                        renderHymnPane(serviceId);
                    }
                }

                function showSlotOptions() {
                    var options = ['Distribution', 'Other'];

                    if (!list) {
                        return;
                    }

                    list.innerHTML = '';
                    Array.prototype.forEach.call(options, function (name) {
                        var button = document.createElement('button');

                        button.type = 'button';
                        button.className = 'service-card-suggestion-item';
                        button.textContent = name;
                        button.addEventListener('mousedown', function (event) {
                            event.preventDefault();
                        });
                        button.addEventListener('click', function () {
                            syncSlotAndRefresh(name);
                        });
                        list.appendChild(button);
                    });

                    list.hidden = false;
                    list.classList.add('is-visible');
                }

                input.addEventListener('focus', showSlotOptions);
                input.addEventListener('click', showSlotOptions);
                input.addEventListener('input', showSlotOptions);
                input.addEventListener('change', function () {
                    syncSlotAndRefresh(input.value);
                });
                input.addEventListener('blur', function () {
                    window.setTimeout(function () {
                        syncSlotAndRefresh(input.value);
                    }, 120);
                });
            });
        }

        function bindHymnDragBehavior(scope, serviceId) {
            var draggedRowKey = null;

            Array.prototype.forEach.call(scope.querySelectorAll('.service-card-hymn-row'), function (row) {
                var handle = row.querySelector('.service-card-drag-handle');

                if (!handle) {
                    return;
                }

                handle.addEventListener('dragstart', function (event) {
                    draggedRowKey = row.getAttribute('data-row-key');
                    row.classList.add('is-dragging');
                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', draggedRowKey || '');
                    }
                });

                handle.addEventListener('dragend', function () {
                    draggedRowKey = null;
                    row.classList.remove('is-dragging');
                    Array.prototype.forEach.call(scope.querySelectorAll('.service-card-hymn-row'), function (candidate) {
                        candidate.classList.remove('is-drop-target');
                    });
                });

                row.addEventListener('dragover', function (event) {
                    event.preventDefault();
                    row.classList.add('is-drop-target');
                });

                row.addEventListener('dragleave', function () {
                    row.classList.remove('is-drop-target');
                });

                row.addEventListener('drop', function (event) {
                    var targetKey = row.getAttribute('data-row-key');
                    var fromIndex;
                    var toIndex;

                    event.preventDefault();
                    row.classList.remove('is-drop-target');
                    if (!draggedRowKey || !targetKey || draggedRowKey === targetKey) {
                        return;
                    }

                    captureHymnState();
                    fromIndex = hymnState.order.indexOf(draggedRowKey);
                    toIndex = hymnState.order.indexOf(targetKey);
                    if (fromIndex === -1 || toIndex === -1) {
                        return;
                    }

                    hymnState.order.splice(fromIndex, 1);
                    hymnState.order.splice(toIndex, 0, draggedRowKey);
                    renderHymnPane(serviceId);
                });
            });
        }

        function addExtraHymnRow(defaultSlotName) {
            hymnState.extra_rows.push({
                key: 'extra:' + String(hymnState.next_extra_id || 1),
                value: '',
                slot_name: defaultSlotName === 'Distribution Hymn' ? 'Distribution Hymn' : 'Other Hymn'
            });
            hymnState.next_extra_id = (parseInt(hymnState.next_extra_id || '1', 10) || 1) + 1;
            hymnState.order.push(hymnState.extra_rows[hymnState.extra_rows.length - 1].key);
        }

        function renderHymnPane(serviceId) {
            var definitions = normalizeHymnState(serviceId);
            var orderedBaseKeys = [];
            var baseRankByKey = {};
            var rowMetaByKey = {};
            var distributionIndex = 0;
            var html = '';

            hymnState.order.forEach(function (rowKey) {
                if (rowKey.indexOf('base:') === 0) {
                    orderedBaseKeys.push(rowKey);
                }
            });
            orderedBaseKeys.forEach(function (rowKey, index) {
                baseRankByKey[rowKey] = index;
            });

            if (definitions.length > 0) {
                html += '<div class="service-card-hymn-instruction">Check boxes mark hymns as procession / recession.</div>';
                if (hasDuplicateTuneSelections(hymnPane)) {
                    html += '<div class="service-card-hymn-warning">Selected hymns have the same tune.</div>';
                }
            }

            hymnState.order.forEach(function (rowKey) {
                var definition;
                var extraRow;
                var slotName;

                if (rowKey.indexOf('base:') === 0) {
                    definition = definitions[baseRankByKey[rowKey]];
                    if (!definition) {
                        return;
                    }

                    slotName = String(definition.slot_name || '');
                    if (definition.toggle_name === 'opening_processional' && hymnState.opening_processional) {
                        slotName = 'Processional Hymn';
                    }
                    if (definition.toggle_name === 'closing_recessional' && hymnState.closing_recessional) {
                        slotName = 'Recessional Hymn';
                    }

                    if (slotName === 'Distribution Hymn') {
                        distributionIndex += 1;
                    }

                    rowMetaByKey[rowKey] = {
                        kind: 'base',
                        originalIndex: rowKey.replace('base:', ''),
                        value: hymnState.hymns[rowKey.replace('base:', '')] || '',
                        toggleName: definition.toggle_name || '',
                        label: slotName === 'Distribution Hymn'
                            ? 'Distribution Hymn ' + String(distributionIndex)
                            : String(definition.label || '')
                    };
                    return;
                }

                extraRow = findExtraHymnRow(rowKey);
                if (!extraRow) {
                    return;
                }

                if (extraRow.slot_name === 'Distribution Hymn') {
                    distributionIndex += 1;
                }

                rowMetaByKey[rowKey] = {
                    kind: 'extra',
                    extraRow: extraRow,
                    label: extraRow.slot_name === 'Distribution Hymn'
                        ? 'Distribution Hymn ' + String(distributionIndex)
                        : 'Additional hymn'
                };
            });

            hymnState.order.forEach(function (rowKey) {
                var meta = rowMetaByKey[rowKey];
                var toggleHtml = '';
                var toggleId;

                if (!meta) {
                    return;
                }

                if (meta.kind === 'base') {
                    if (meta.toggleName) {
                        toggleId = 'toggle_' + serviceIdValue + '_' + meta.toggleName;
                        toggleHtml =
                            '<label class="service-card-hymn-inline-toggle" for="' + escapeHtml(toggleId) + '">' +
                                '<input type="checkbox" id="' + escapeHtml(toggleId) + '" name="' + escapeHtml(meta.toggleName) + '" value="1"' + ((meta.toggleName === 'opening_processional' ? hymnState.opening_processional : hymnState.closing_recessional) ? ' checked' : '') + '>' +
                            '</label>';
                    }

                    html +=
                        '<div class="service-card-hymn-row" data-row-key="' + escapeHtml(rowKey) + '" data-row-kind="base" draggable="false">' +
                            '<button type="button" class="service-card-drag-handle" draggable="true" aria-label="Reorder hymn">::</button>' +
                            '<input type="text" name="hymn_' + escapeHtml(meta.originalIndex) + '" value="' + escapeHtml(meta.value) + '" placeholder="' + escapeHtml(meta.label) + '" data-list-id="' + escapeHtml(hymnSuggestionsId) + '" autocomplete="off" class="service-card-hymn-lookup">' +
                            toggleHtml +
                        '</div>';
                    return;
                }

                html +=
                    '<div class="service-card-hymn-row service-card-hymn-row-extra" data-row-key="' + escapeHtml(meta.extraRow.key) + '" data-row-kind="extra" data-extra-slot-name="' + escapeHtml(meta.extraRow.slot_name) + '" data-slot-name="' + escapeHtml(meta.extraRow.slot_name) + '" draggable="false">' +
                        '<button type="button" class="service-card-drag-handle" draggable="true" aria-label="Reorder hymn">::</button>' +
                        '<input type="hidden" name="extra_hymn_keys[]" value="' + escapeHtml(meta.extraRow.key) + '">' +
                        '<input type="text" name="extra_hymn_values[' + escapeHtml(meta.extraRow.key) + ']" value="' + escapeHtml(meta.extraRow.value || '') + '" placeholder="' + escapeHtml(meta.label) + '" data-list-id="' + escapeHtml(hymnSuggestionsId) + '" autocomplete="off" class="service-card-hymn-lookup service-card-hymn-lookup-extra">' +
                        '<div class="service-card-suggestion-anchor service-card-hymn-slot-anchor">' +
                            '<input type="hidden" class="js-extra-hymn-slot-hidden" name="extra_hymn_slots[' + escapeHtml(meta.extraRow.key) + ']" value="' + escapeHtml(meta.extraRow.slot_name) + '">' +
                            '<input type="text" class="service-card-text service-card-hymn-slot-input js-extra-hymn-slot-input" value="' + escapeHtml(meta.extraRow.slot_name === 'Distribution Hymn' ? 'Distribution' : 'Other') + '" autocomplete="off">' +
                            '<div class="service-card-suggestion-list js-extra-hymn-slot-suggestion-list" hidden></div>' +
                        '</div>' +
                        '<button type="button" class="service-card-remove-hymn-button" data-remove-row-key="' + escapeHtml(meta.extraRow.key) + '" aria-label="Remove hymn">&times;</button>' +
                    '</div>';
            });

            if (definitions.length > 0 || hymnState.extra_rows.length > 0) {
                html += '<button type="button" class="service-card-hymn-add-link service-card-reading-rubric js-add-extra-hymn-link">To add an additional hymn, click here</button>';
            }

            hymnPane.innerHTML = html;
            bindHymnLookupBehavior(hymnPane);
            bindExtraHymnSlotBehavior(hymnPane, serviceId);
            bindHymnDragBehavior(hymnPane, serviceId);
            updateDuplicateTuneHighlights(hymnPane);

            Array.prototype.forEach.call(hymnPane.querySelectorAll('.service-card-remove-hymn-button'), function (button) {
                button.addEventListener('click', function () {
                    var rowKey = button.getAttribute('data-remove-row-key') || '';

                    captureHymnState();
                    hymnState.extra_rows = hymnState.extra_rows.filter(function (row) {
                        return String(row.key) !== rowKey;
                    });
                    hymnState.order = hymnState.order.filter(function (key) {
                        return String(key) !== rowKey;
                    });
                    renderHymnPane(serviceId);
                });
            });

            Array.prototype.forEach.call(hymnPane.querySelectorAll('.js-add-extra-hymn-link'), function (button) {
                button.addEventListener('click', function () {
                    captureHymnState();
                    addExtraHymnRow('Other Hymn');
                    renderHymnPane(serviceId);
                });
            });

            Array.prototype.forEach.call(hymnPane.querySelectorAll('input[name="opening_processional"], input[name="closing_recessional"]'), function (toggle) {
                toggle.addEventListener('change', function () {
                    hymnState.opening_processional = !!(hymnPane.querySelector('input[name="opening_processional"]') || {}).checked;
                    hymnState.closing_recessional = !!(hymnPane.querySelector('input[name="closing_recessional"]') || {}).checked;
                });
            });
        }

        function updateSettingSummary() {
            var option = settingSelect.options[settingSelect.selectedIndex];

            if (!option || !option.value) {
                settingSummary.innerHTML = '&nbsp;';
                captureHymnState();
                renderHymnPane('');
                return;
            }

            var abbreviation = (option.getAttribute('data-abbreviation') || '').trim();
            var pageNumber = (option.getAttribute('data-page-number') || '').trim();
            var text = abbreviation;

            if (pageNumber !== '') {
                text += (text !== '' ? ', ' : '') + 'p. ' + pageNumber;
            }

            settingSummary.textContent = text !== '' ? text : ' ';
        }

        function captureReadingDraftState() {
            var readingDraftInputs = readingsPane ? readingsPane.querySelectorAll('.js-new-reading-set-input') : [];
            Array.prototype.forEach.call(readingDraftInputs, function (input) {
                var index = parseInt(input.getAttribute('data-draft-index') || '0', 10);
                var field = input.getAttribute('data-draft-field') || '';
                if (!index || !field || !readingDraftState[index - 1]) {
                    return;
                }

                readingDraftState[index - 1][field] = input.value;
            });
        }

        function rerenderCurrentReadingsPane() {
            var detail = findObservanceDetailByName(observanceNameInput ? observanceNameInput.value : '');

            if (!detail) {
                if (String(observanceNameInput && observanceNameInput.value ? observanceNameInput.value : '').trim() === '') {
                    renderBlankReadingsPane();
                } else {
                    renderNewReadingSetEditor();
                }
                return;
            }

            renderReadingsPane(detail.reading_sets || []);
        }

        function renderNewReadingSetEditor() {
            var html = getReadingSupplementsHtml() + '<div class="update-service-reading-editor-note">No appointed readings are stored for this observance yet.</div>';

            if (!readingsPane) {
                return;
            }

            selectedReadingSetId = '';
            selectedNewReadingSet = '';
            form.setAttribute('data-selected-reading-set-id', '');
            form.setAttribute('data-selected-new-reading-set', '');

            Array.prototype.forEach.call(readingDraftState, function (draft, draftIndex) {
                var index = draftIndex + 1;

                html +=
                    '<div class="service-card-reading-set update-service-reading-editor">' +
                        '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="set_name" name="new_reading_set_' + index + '_set_name" value="' + escapeHtml(draft.set_name || '') + '" placeholder="Set Name (optional)">' +
                        '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="year_pattern" name="new_reading_set_' + index + '_year_pattern" value="' + escapeHtml(draft.year_pattern || '') + '" placeholder="Year Pattern (optional)">' +
                        '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="psalm" name="new_reading_set_' + index + '_psalm" value="' + escapeHtml(draft.psalm || '') + '" placeholder="Psalm">' +
                        '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="old_testament" name="new_reading_set_' + index + '_old_testament" value="' + escapeHtml(draft.old_testament || '') + '" placeholder="Old Testament">' +
                        '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="epistle" name="new_reading_set_' + index + '_epistle" value="' + escapeHtml(draft.epistle || '') + '" placeholder="Epistle">' +
                        '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="gospel" name="new_reading_set_' + index + '_gospel" value="' + escapeHtml(draft.gospel || '') + '" placeholder="Gospel">' +
                    '</div>';
            });

            readingsPane.innerHTML = html;

            Array.prototype.forEach.call(readingsPane.querySelectorAll('.js-new-reading-set-input'), function (input) {
                input.addEventListener('input', captureReadingDraftState);
            });
            bindSupplementalReadingControls();
        }

        function renderBlankReadingsPane() {
            if (!readingsPane) {
                return;
            }

            selectedReadingSetId = '';
            selectedNewReadingSet = '';
            form.setAttribute('data-selected-reading-set-id', '');
            form.setAttribute('data-selected-new-reading-set', '');
            readingsPane.innerHTML = String(observanceNameInput && observanceNameInput.value ? observanceNameInput.value : '').trim() === ''
                ? '&nbsp;'
                : getReadingSupplementsHtml();
            bindSupplementalReadingControls();
        }

        function renderReadingsPane(readingSets) {
            var html = getReadingSupplementsHtml();
            var hasSelectedReadingSet = false;
            var hasRenderedReadingSet = false;

            if (!readingsPane) {
                return;
            }

            Array.prototype.forEach.call(readingSets || [], function (readingSet, index) {
                var classes = 'service-card-reading-set' + (index > 0 ? ' service-card-reading-set-secondary' : '');
                var readingSetId = readingSet && readingSet.id ? String(readingSet.id) : '';
                var isChecked = selectedReadingSetId !== '' && readingSetId === selectedReadingSetId;

                if (isChecked) {
                    hasSelectedReadingSet = true;
                }

                hasRenderedReadingSet = true;
                html += '<div class="' + classes + '">';

                if ((readingSet.psalm || '') !== '' && readingSetId !== '') {
                    html +=
                        '<label class="service-card-reading-psalm">' +
                            '<input type="radio" name="selected_reading_set_id" value="' + escapeHtml(readingSetId) + '" class="service-card-reading-radio"' + (isChecked ? ' checked' : '') + '>' +
                            '<span>' + escapeHtml(readingSet.psalm) + '</span>' +
                        '</label>';
                }

                Array.prototype.forEach.call(['old_testament', 'epistle', 'gospel'], function (field) {
                    var text = ((readingSet && readingSet[field]) || '').trim();
                    if (text !== '') {
                        html += '<div>' + escapeHtml(text) + '</div>';
                    }
                });

                html += '</div>';
            });

            if (!hasRenderedReadingSet) {
                renderNewReadingSetEditor();
                return;
            }

            if (!hasSelectedReadingSet) {
                selectedReadingSetId = '';
                form.setAttribute('data-selected-reading-set-id', '');
            }

            selectedNewReadingSet = '';
            form.setAttribute('data-selected-new-reading-set', '');

            readingsPane.innerHTML = html;
            bindReadingSelectionBehavior(readingsPane, function (value) {
                selectedReadingSetId = value || '';
                form.setAttribute('data-selected-reading-set-id', selectedReadingSetId);
            });
            bindSupplementalReadingControls();
        }

        function updateObservanceDetails(resetReadingSelection) {
            var observanceName = observanceNameInput ? observanceNameInput.value : '';
            var detail = findObservanceDetailByName(observanceName);

            if (resetReadingSelection) {
                captureReadingDraftState();
                selectedReadingSetId = '';
                form.setAttribute('data-selected-reading-set-id', '');
                selectedNewReadingSet = '';
                form.setAttribute('data-selected-new-reading-set', '');
            }

            if (observanceSuggestionList.classList.contains('is-visible')) {
                renderObservanceSuggestionOptions(false);
            }

            if (observanceIdInput) {
                observanceIdInput.value = detail && detail.id ? String(detail.id) : '';
            }

            if (!detail) {
                if (latinName) {
                    latinName.innerHTML = '&nbsp;';
                }
                if (colorLine) {
                    colorLine.innerHTML = '&nbsp;';
                    colorLine.classList.toggle('is-hidden', observanceName !== '');
                }
                if (observanceName === '') {
                    newObservanceColorWrap.classList.remove('is-visible');
                    selectedSmallCatechismLabels = [];
                    selectedPassionReadingId = '';
                    renderBlankReadingsPane();
                } else {
                    newObservanceColorWrap.classList.add('is-visible');
                    renderReadingsPane([]);
                }
                applyFormColorClass('service-card-color-dark');
                return;
            }

            newObservanceColorWrap.classList.remove('is-visible');
            if (latinName) {
                latinName.textContent = detail.latin_name !== '' ? detail.latin_name : ' ';
            }
            if (colorLine) {
                colorLine.classList.remove('is-hidden');
                colorLine.textContent = detail.color_display !== '' ? detail.color_display : ' ';
            }
            renderReadingsPane(detail.reading_sets || []);
            applyFormColorClass(detail.color_class || 'service-card-color-dark');
        }

        function updateDisplayDate() {
            var serviceDateValue = String(serviceDateInput && serviceDateInput.value ? serviceDateInput.value : '').trim();
            var parts;
            var localDate;

            if (!displayDate) {
                return;
            }

            if (!/^\d{4}-\d{2}-\d{2}$/.test(serviceDateValue)) {
                displayDate.innerHTML = '&nbsp;';
                return;
            }

            parts = serviceDateValue.split('-');
            localDate = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
            if (isNaN(localDate.getTime())) {
                displayDate.innerHTML = '&nbsp;';
                return;
            }

            displayDate.textContent = localDate.toLocaleDateString(undefined, {
                weekday: 'long',
                month: 'long',
                day: 'numeric'
            });
        }

        function hideSeparateThursdayAlert() {
            if (separateThursdayAlert) {
                separateThursdayAlert.classList.remove('is-visible');
            }
        }

        function showSeparateThursdayAlert() {
            if (separateThursdayAlert) {
                separateThursdayAlert.classList.add('is-visible');
            }
        }

        function getOriginalCopyState() {
            return !!(previousThursdayToggle && previousThursdayToggle.getAttribute('data-original-copy') === '1');
        }

        function setAlertMessage() {
            if (!separateThursdayAlertTitle || !previousThursdayToggle) {
                return;
            }

            separateThursdayAlertTitle.textContent = getOriginalCopyState()
                ? 'Would you like to separate Thursday from Sunday?'
                : 'Would you like to unite Thursday with Sunday?';
        }

        function syncPreviousThursdayState() {
            if (!previousThursdayToggle || !linkActionFlag) {
                return;
            }

            if (previousThursdayToggle.checked === getOriginalCopyState()) {
                linkActionFlag.value = '';
                hideSeparateThursdayAlert();
                return;
            }

            if (linkActionFlag.value !== '') {
                hideSeparateThursdayAlert();
                return;
            }

            setAlertMessage();
            showSeparateThursdayAlert();
        }

        hideObservanceSuggestionOptions();
        renderHymnPane(settingSelect.value || '');
        updateSettingSummary();
        updateObservanceDetails(false);
        updateDisplayDate();
        syncPreviousThursdayState();

        settingSelect.addEventListener('change', function () {
            captureHymnState();
            updateSettingSummary();
        });

        if (observanceNameInput) {
            observanceNameInput.addEventListener('input', function () {
                updateObservanceDetails(true);
                showObservanceSuggestionOptions(false);
            });
            observanceNameInput.addEventListener('change', function () {
                updateObservanceDetails(true);
            });
            observanceNameInput.addEventListener('focus', function () {
                showObservanceSuggestionOptions(true);
            });
            observanceNameInput.addEventListener('click', function () {
                showObservanceSuggestionOptions(true);
            });
            observanceNameInput.addEventListener('blur', function () {
                window.setTimeout(hideObservanceSuggestionOptions, 120);
            });
        }

        if (newObservanceColorSelect) {
            newObservanceColorSelect.addEventListener('change', function () {
                if (!newObservanceColorWrap.classList.contains('is-visible')) {
                    return;
                }

                applyFormColorClass(({
                    gold: 'service-card-color-gold',
                    green: 'service-card-color-green',
                    violet: 'service-card-color-violet',
                    purple: 'service-card-color-violet',
                    blue: 'service-card-color-blue',
                    rose: 'service-card-color-rose',
                    pink: 'service-card-color-rose',
                    scarlet: 'service-card-color-red',
                    red: 'service-card-color-red',
                    black: 'service-card-color-black',
                    white: 'service-card-color-dark'
                })[(newObservanceColorSelect.value || '').trim().toLowerCase()] || 'service-card-color-dark');
            });
        }

        if (serviceDateInput) {
            serviceDateInput.addEventListener('change', function () {
                updateDisplayDate();
                rerenderCurrentReadingsPane();
            });
        }

        if (previousThursdayToggle) {
            previousThursdayToggle.addEventListener('change', syncPreviousThursdayState);
        }

        if (separateThursdayYes) {
            separateThursdayYes.addEventListener('click', function () {
                if (linkActionFlag && previousThursdayToggle) {
                    linkActionFlag.value = getOriginalCopyState() ? 'unlink' : 'link';
                }
                hideSeparateThursdayAlert();
            });
        }

        if (separateThursdayNo) {
            separateThursdayNo.addEventListener('click', function () {
                if (linkActionFlag) {
                    linkActionFlag.value = '';
                }
                if (previousThursdayToggle) {
                    previousThursdayToggle.checked = getOriginalCopyState();
                }
                hideSeparateThursdayAlert();
            });
        }

        form.addEventListener('submit', function (event) {
            if (!previousThursdayToggle || !linkActionFlag) {
                return;
            }

            if (previousThursdayToggle.checked !== getOriginalCopyState() && linkActionFlag.value === '') {
                event.preventDefault();
                setAlertMessage();
                showSeparateThursdayAlert();
            }
        });
    }

    var forms = document.querySelectorAll('.js-update-service-form');
    Array.prototype.forEach.call(forms, initializeUpdateServiceForm);

    if (filterRoot && filterForm) {
        (function initializeUpdateServiceFilters() {
            var rubricSelect = filterForm.querySelector('[data-update-service-rubric-year-select="1"]');
            var startDateInput = filterForm.querySelector('[data-update-service-date-field="start"]');
            var endDateInput = filterForm.querySelector('[data-update-service-date-field="end"]');
            var sortOrderInput = filterForm.querySelector('[data-update-service-sort-input="1"]');
            var sortToggleButton = filterForm.querySelector('[data-update-service-sort-toggle="1"]');
            var resetLink = filterForm.querySelector('[data-update-service-reset="1"]');
            var isProgrammaticUpdate = false;

            function submitFilters() {
                filterForm.submit();
            }

            if (rubricSelect) {
                rubricSelect.addEventListener('change', function () {
                    var selectedOption = rubricSelect.options[rubricSelect.selectedIndex];

                    isProgrammaticUpdate = true;
                    if (selectedOption && selectedOption.value !== '') {
                        if (startDateInput) {
                            startDateInput.value = selectedOption.getAttribute('data-start-date') || '';
                        }
                        if (endDateInput) {
                            endDateInput.value = selectedOption.getAttribute('data-end-date') || '';
                        }
                    }
                    isProgrammaticUpdate = false;
                    submitFilters();
                });
            }

            [startDateInput, endDateInput].forEach(function (input) {
                if (!input) {
                    return;
                }

                input.addEventListener('change', function () {
                    if (!isProgrammaticUpdate && rubricSelect) {
                        rubricSelect.value = '';
                    }
                    submitFilters();
                });
            });

            if (sortToggleButton) {
                sortToggleButton.addEventListener('click', function () {
                    if (sortOrderInput) {
                        sortOrderInput.value = sortToggleButton.getAttribute('data-next-sort-order') || 'desc';
                    }
                    submitFilters();
                });
            }

            if (resetLink) {
                resetLink.addEventListener('click', function (event) {
                    event.preventDefault();
                    isProgrammaticUpdate = true;
                    if (rubricSelect) {
                        rubricSelect.value = filterRoot.getAttribute('data-reset-rubric-year') || '';
                    }
                    if (startDateInput) {
                        startDateInput.value = filterRoot.getAttribute('data-reset-start-date') || '';
                    }
                    if (endDateInput) {
                        endDateInput.value = filterRoot.getAttribute('data-reset-end-date') || '';
                    }
                    if (sortOrderInput) {
                        sortOrderInput.value = filterRoot.getAttribute('data-reset-sort-order') || 'desc';
                    }
                    isProgrammaticUpdate = false;
                    submitFilters();
                });
            }
        }());
    }

    if (searchInput) {
        if (String(searchInput.value || '').trim() === '' && initialSearchServiceId && searchData.forms_by_id) {
            if (searchData.forms_by_id[String(initialSearchServiceId)] && searchData.forms_by_id[String(initialSearchServiceId)].search_label) {
                searchInput.value = String(searchData.forms_by_id[String(initialSearchServiceId)].search_label);
            }
        }

        searchInput.addEventListener('change', function () {
            applySearchFilter();
            syncSearchSelection();
        });
        searchInput.addEventListener('input', function () {
            applySearchFilter();
        });
        searchInput.addEventListener('blur', syncSearchSelection);

        if (String(searchInput.value || '').trim() !== '') {
            applySearchFilter();
            syncSearchSelection();
        } else {
            applySearchFilter();
        }
    }
}());
</script>

<?php include 'includes/footer.php'; ?>
