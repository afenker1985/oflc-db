<?php
declare(strict_types=1);

session_start();

$page_title = 'Add a Service';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/liturgical.php';
require_once __DIR__ . '/includes/service_observances.php';
require_once __DIR__ . '/includes/liturgical_colors.php';

function oflc_request_value(array $request_data, string $key, string $default = ''): string
{
    if (!isset($request_data[$key])) {
        return $default;
    }

    return trim((string) $request_data[$key]);
}

function oflc_request_values(array $request_data, string $key): array
{
    if (!isset($request_data[$key])) {
        return [];
    }

    $value = $request_data[$key];
    if (!is_array($value)) {
        $value = [$value];
    }

    return array_values(array_filter(array_map(static function ($item): string {
        return trim((string) $item);
    }, $value), static function (string $item): bool {
        return $item !== '';
    }));
}

function oflc_get_suggested_service_date(PDO $pdo): string
{
    $latest_service_date = $pdo
        ->query('SELECT MAX(service_date) FROM service_db WHERE is_active = 1')
        ->fetchColumn();

    $base_date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $latest_service_date);
    if (!$base_date instanceof DateTimeImmutable) {
        $base_date = new DateTimeImmutable('today');
    }

    if ($base_date->format('w') === '0') {
        return $base_date->modify('+7 days')->format('Y-m-d');
    }

    return $base_date->modify('next sunday')->format('Y-m-d');
}

function oflc_fetch_recently_celebrated_observance_ids(PDO $pdo, DateTimeImmutable $selectedDate): array
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

function oflc_get_passion_cycle_year_for_service_date(?DateTimeImmutable $serviceDate): ?int
{
    if (!$serviceDate instanceof DateTimeImmutable) {
        return null;
    }

    $year = (int) $serviceDate->format('Y');
    return (($year - 2025) % 4 + 4) % 4 + 1;
}

function oflc_fetch_movable_observances(PDO $pdo, $week, $day): array
{
    return oflc_fetch_observances_by_logic_keys($pdo, oflc_resolve_movable_logic_keys($week, (int) $day));
}

function oflc_fetch_fixed_observances(PDO $pdo, int $month, int $day): array
{
    return oflc_fetch_observances_by_logic_keys($pdo, oflc_resolve_fixed_logic_keys($month, $day));
}

function oflc_fetch_observances_by_logic_key(PDO $pdo, string $logic_key): array
{
    return oflc_fetch_observances_by_logic_keys($pdo, [$logic_key]);
}

function oflc_fetch_observances_by_logic_keys(PDO $pdo, array $logic_keys): array
{
    if ($logic_keys === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($logic_keys), '?'));
    $stmt = $pdo->prepare(
        'SELECT lc.id, lc.name, lc.logic_key, COUNT(rs.id) AS reading_set_count
         FROM liturgical_calendar lc
         LEFT JOIN reading_sets rs ON rs.liturgical_calendar_id = lc.id AND rs.is_active = 1
         WHERE lc.is_active = 1
           AND lc.logic_key IN (' . $placeholders . ')
         GROUP BY lc.id, lc.name, lc.logic_key
         ORDER BY lc.name'
    );
    $stmt->execute(array_values($logic_keys));

    return $stmt->fetchAll();
}

function oflc_planning_logic_columns_ready(PDO $pdo): bool
{
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    $required_columns = [
        'logic_key',
    ];

    $columns = $pdo->query('SHOW COLUMNS FROM liturgical_calendar')->fetchAll(PDO::FETCH_COLUMN);
    $ready = count(array_diff($required_columns, $columns)) === 0;

    return $ready;
}

function oflc_format_observance_label(array $observance): string
{
    $label = $observance['name'];

    if (isset($observance['reading_set_count'])) {
        $label .= ' (' . (int) $observance['reading_set_count'] . ' reading set';
        if ((int) $observance['reading_set_count'] !== 1) {
            $label .= 's';
        }
        $label .= ')';
    }

    return $label;
}

function oflc_format_short_weekday(DateTimeImmutable $date): string
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

function oflc_format_festival_list_label(array $entry, array $observance): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $entry['date']);
    $day_label = $date instanceof DateTimeImmutable ? oflc_format_short_weekday($date) : '';
    $calendar_day_label = $date instanceof DateTimeImmutable ? $date->format('j') : $entry['date'];

    return $observance['name'] . ' (' . trim($day_label . ' ' . $calendar_day_label) . ')';
}

function oflc_format_sunday_list_label(array $entry, array $observance): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $entry['date']);
    $month_day_label = $date instanceof DateTimeImmutable ? $date->format('m/d') : $entry['date'];

    return $observance['name'] . ' (' . $month_day_label . ')';
}

function oflc_get_liturgical_color_display($color): string
{
    $color = trim((string) $color);
    return $color === '' ? '' : strtoupper($color);
}

function oflc_get_liturgical_color_text_class($color): string
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

function oflc_clean_reading_text($text, bool $remove_antiphon = false): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/\s*\n\s*/', ' ', $text) ?? $text;

    if ($remove_antiphon) {
        $text = preg_replace('/\s*\(antiphon:[^)]+\)/i', '', $text) ?? $text;
    }

    return trim($text);
}

function oflc_humanize_logic_key(string $logic_key): string
{
    $label = str_replace('_', ' ', $logic_key);
    $label = ucwords($label);
    $label = str_replace([' And ', ' Of ', ' Our ', ' The '], [' and ', ' of ', ' our ', ' the '], $label);

    return $label;
}

function oflc_build_option_label(string $logic_key, string $date, bool $is_festival): string
{
    $date_obj = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    $base_label = oflc_humanize_logic_key($logic_key);

    if (!$date_obj instanceof DateTimeImmutable) {
        return $base_label;
    }

    return $is_festival
        ? $base_label . ' (' . $date_obj->format('D, m/d') . ')'
        : $base_label . ' (' . $date_obj->format('m/d') . ')';
}

function oflc_fetch_logic_key_name_map(PDO $pdo, array $logic_keys): array
{
    if ($logic_keys === []) {
        return [];
    }

    $logic_keys = array_values(array_unique(array_filter($logic_keys, static function ($value) {
        return trim((string) $value) !== '';
    })));
    if ($logic_keys === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($logic_keys), '?'));
    $stmt = $pdo->prepare(
        'SELECT logic_key, name
         FROM liturgical_calendar
         WHERE is_active = 1
           AND logic_key IN (' . $placeholders . ')
         ORDER BY id'
    );
    $stmt->execute($logic_keys);

    $name_map = [];
    foreach ($stmt->fetchAll() as $row) {
        $logic_key = (string) ($row['logic_key'] ?? '');
        if ($logic_key !== '' && !isset($name_map[$logic_key])) {
            $name_map[$logic_key] = (string) ($row['name'] ?? '');
        }
    }

    return $name_map;
}

function oflc_fetch_observance_detail(PDO $pdo, string $logic_key)
{
    $stmt = $pdo->prepare(
        'SELECT id, name, latin_name, logic_key, season, liturgical_color, notes
         FROM liturgical_calendar
         WHERE is_active = 1 AND logic_key = ?
         ORDER BY id
         LIMIT 1'
    );
    $stmt->execute([$logic_key]);
    $observance = $stmt->fetch();

    if (!$observance) {
        return null;
    }

    $readings_stmt = $pdo->prepare(
        'SELECT set_name, year_pattern, old_testament, psalm, epistle, gospel
         FROM reading_sets
         WHERE liturgical_calendar_id = ?
           AND is_active = 1
         ORDER BY id'
    );
    $readings_stmt->execute([(int) $observance['id']]);
    $reading_sets = $readings_stmt->fetchAll();

    return [
        'observance' => $observance,
        'reading_sets' => $reading_sets,
    ];
}

function oflc_fetch_active_leaders(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, first_name, last_name
         FROM leaders
         WHERE is_active = 1
         ORDER BY last_name, first_name, id'
    );

    return $stmt->fetchAll();
}

function oflc_format_hymn_suggestion_label_with_id(array $row): string
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

function oflc_register_hymn_lookup_key(array &$lookup, string $key, int $id): void
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

function oflc_fetch_hymn_catalog(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, hymnal, hymn_number, hymn_title, insert_use
         FROM hymn_db
         WHERE is_active = 1
         ORDER BY hymnal, hymn_number, hymn_title'
    );

    $suggestions = [];
    $lookupByKey = [];

    foreach ($stmt->fetchAll() as $row) {
        $hymnId = (int) ($row['id'] ?? 0);
        if ($hymnId <= 0) {
            continue;
        }

        $fullLabel = oflc_format_hymn_suggestion_label_with_id($row);
        if ($fullLabel !== '') {
            $suggestions[$fullLabel] = $fullLabel;
            oflc_register_hymn_lookup_key($lookupByKey, $fullLabel, $hymnId);
        }

        $hymnal = trim((string) ($row['hymnal'] ?? ''));
        $hymnNumber = trim((string) ($row['hymn_number'] ?? ''));
        $title = trim((string) ($row['hymn_title'] ?? ''));

        if ($hymnal !== '' && $hymnNumber !== '') {
            oflc_register_hymn_lookup_key($lookupByKey, $hymnal . ' ' . $hymnNumber, $hymnId);
        }

        if ($hymnal === 'LSB' && $hymnNumber !== '') {
            oflc_register_hymn_lookup_key($lookupByKey, $hymnNumber, $hymnId);
        }

        if ($title !== '') {
            oflc_register_hymn_lookup_key($lookupByKey, $title, $hymnId);
        }
    }

    return [
        'suggestions' => array_values($suggestions),
        'lookup_by_key' => $lookupByKey,
    ];
}

function oflc_resolve_hymn_id(string $value, array $lookupByKey): ?int
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

function oflc_normalize_selected_reading_set_id($value): ?int
{
    $value = trim((string) $value);
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }

    $reading_set_id = (int) $value;

    return $reading_set_id > 0 ? $reading_set_id : null;
}

function oflc_resolve_selected_reading_set_id_for_detail(?array $observance_detail, ?int $selected_reading_set_id): ?int
{
    if ($observance_detail === null || $selected_reading_set_id === null) {
        return null;
    }

    foreach (array_slice($observance_detail['reading_sets'] ?? [], 0, 2) as $reading_set) {
        if ((int) ($reading_set['id'] ?? 0) === $selected_reading_set_id) {
            return $selected_reading_set_id;
        }
    }

    return null;
}

function oflc_get_default_selected_reading_set_id(?array $observance_detail, ?DateTimeImmutable $service_date): ?int
{
    if ($observance_detail === null) {
        return null;
    }

    $reading_sets = array_values(array_filter(
        array_slice($observance_detail['reading_sets'] ?? [], 0, 2),
        static function (array $reading_set): bool {
            return (int) ($reading_set['id'] ?? 0) > 0;
        }
    ));

    if (count($reading_sets) === 0) {
        return null;
    }

    if (count($reading_sets) === 1) {
        return (int) ($reading_sets[0]['id'] ?? 0);
    }

    if (!$service_date instanceof DateTimeImmutable) {
        return null;
    }

    $year = (int) $service_date->format('Y');
    $default_index = $year % 2 === 0 ? 1 : 0;

    return (int) ($reading_sets[$default_index]['id'] ?? 0);
}

function oflc_build_date_observance_suggestions(array $service_option_choices): array
{
    $names = [];

    foreach ($service_option_choices as $choice) {
        $name = trim((string) ($choice['suggestion_label'] ?? $choice['label'] ?? ''));
        if ($name === '') {
            continue;
        }

        $names[$name] = $name;
    }

    return array_values($names);
}

function oflc_fetch_hymn_suggestions(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT hymnal, hymn_number, hymn_title
         FROM hymn_db
         WHERE is_active = 1
         ORDER BY hymnal, hymn_number, hymn_title'
    );

    $suggestions = [];

    foreach ($stmt->fetchAll() as $row) {
        $parts = array_filter([
            trim((string) ($row['hymnal'] ?? '')),
            trim((string) ($row['hymn_number'] ?? '')),
        ], static function ($value) {
            return $value !== '';
        });

        $label = implode(' ', $parts);
        $title = trim((string) ($row['hymn_title'] ?? ''));

        if ($title !== '') {
            $label = $label !== '' ? $label . ' - ' . $title : $title;
        }

        if ($label !== '') {
            $suggestions[$label] = $label;
        }
    }

    return array_values($suggestions);
}

function oflc_fetch_service_settings(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, setting_name, abbreviation, page_number
         FROM service_settings_db
         WHERE is_active = 1
         ORDER BY id'
    );

    return $stmt->fetchAll();
}

function oflc_find_service_setting_detail(array $service_settings, string $input): ?array
{
    $normalized_input = strtolower(trim($input));
    if ($normalized_input === '') {
        return null;
    }

    foreach ($service_settings as $service_setting) {
        $setting_name = strtolower(trim((string) ($service_setting['setting_name'] ?? '')));
        $abbreviation = strtolower(trim((string) ($service_setting['abbreviation'] ?? '')));

        if ($normalized_input === $setting_name || ($abbreviation !== '' && $normalized_input === $abbreviation)) {
            return $service_setting;
        }
    }

    return null;
}

function oflc_build_service_setting_catalog_payload(array $service_settings): array
{
    $payload = [
        'by_id' => [],
        'name_lookup' => [],
    ];

    foreach ($service_settings as $service_setting) {
        $service_setting_id = (int) ($service_setting['id'] ?? 0);
        $setting_name = trim((string) ($service_setting['setting_name'] ?? ''));
        $abbreviation = trim((string) ($service_setting['abbreviation'] ?? ''));
        $page_number = trim((string) ($service_setting['page_number'] ?? ''));

        if ($service_setting_id <= 0 || $setting_name === '') {
            continue;
        }

        $payload['by_id'][$service_setting_id] = [
            'id' => $service_setting_id,
            'setting_name' => $setting_name,
            'abbreviation' => $abbreviation,
            'page_number' => $page_number,
        ];

        $payload['name_lookup'][strtolower($setting_name)] = $service_setting_id;
        if ($abbreviation !== '' && !isset($payload['name_lookup'][strtolower($abbreviation)])) {
            $payload['name_lookup'][strtolower($abbreviation)] = $service_setting_id;
        }
    }

    return $payload;
}

function oflc_fetch_small_catechism_options(PDO $pdo): array
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

        $chief_part = trim((string) ($row['chief_part'] ?? ''));
        $question = trim((string) ($row['question'] ?? ''));
        $abbreviation = trim((string) ($row['abbreviation'] ?? ''));

        $label_parts = array_values(array_filter([$chief_part, $question], static function ($value): bool {
            return $value !== '';
        }));
        $label = implode(' - ', $label_parts);
        if ($abbreviation !== '') {
            $label .= ($label !== '' ? ' ' : '') . '(' . $abbreviation . ')';
        }

        $row['label'] = $label !== '' ? $label : (string) $id;
        $options[] = $row;
    }

    return $options;
}

function oflc_build_small_catechism_lookup(array $options): array
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

function oflc_fetch_passion_reading_options(PDO $pdo): array
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
        $cycle_year = trim((string) ($row['cycle_year'] ?? ''));
        $week_number = trim((string) ($row['week_number'] ?? ''));
        $section_title = trim((string) ($row['section_title'] ?? ''));
        $reference = trim((string) ($row['reference'] ?? ''));

        $label = $gospel;
        if ($cycle_year !== '') {
            $label .= ($label !== '' ? ' ' : '') . 'Year ' . $cycle_year;
        }
        if ($week_number !== '') {
            $label .= ' Week ' . $week_number;
        }
        if ($section_title !== '') {
            $label .= ': ' . $section_title;
        }
        if ($reference !== '') {
            $label .= ' (' . $reference . ')';
        }

        $row['label'] = $label !== '' ? $label : (string) $id;
        $options[] = $row;
    }

    return $options;
}

function oflc_filter_passion_reading_options_for_service_date(array $options, ?DateTimeImmutable $serviceDate): array
{
    $cycleYear = oflc_get_passion_cycle_year_for_service_date($serviceDate);
    if ($cycleYear === null) {
        return $options;
    }

    $filtered = array_values(array_filter($options, static function (array $option) use ($cycleYear): bool {
        return (int) ($option['cycle_year'] ?? 0) === $cycleYear;
    }));

    return $filtered !== [] ? $filtered : $options;
}

function oflc_is_advent_midweek_observance_name(string $name): bool
{
    $normalized_name = strtolower(trim($name));
    return $normalized_name !== ''
        && strpos($normalized_name, 'advent') !== false
        && strpos($normalized_name, 'midweek') !== false;
}

function oflc_is_lent_midweek_observance_name(string $name): bool
{
    $normalized_name = strtolower(trim($name));
    return $normalized_name !== ''
        && strpos($normalized_name, 'lent') !== false
        && strpos($normalized_name, 'midweek') !== false;
}

function oflc_fetch_hymn_slots(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, slot_name, max_hymns, default_sort_order
         FROM hymn_slot_db
         WHERE is_active = 1
         ORDER BY default_sort_order, id'
    );

    $slots = [];
    foreach ($stmt->fetchAll() as $row) {
        $slot_name = trim((string) ($row['slot_name'] ?? ''));
        if ($slot_name !== '') {
            $slots[$slot_name] = $row;
        }
    }

    return $slots;
}

function oflc_build_hymn_field_definitions($selected_service_setting_detail, array $hymn_slots): array
{
    $abbreviation = trim((string) ($selected_service_setting_detail['abbreviation'] ?? ''));
    $definitions = [];

    $slot_label = static function (string $slot_name, string $fallback) use ($hymn_slots): string {
        return isset($hymn_slots[$slot_name]['slot_name']) && trim((string) $hymn_slots[$slot_name]['slot_name']) !== ''
            ? (string) $hymn_slots[$slot_name]['slot_name']
            : $fallback;
    };

    if (in_array($abbreviation, ['DS1', 'DS2', 'DS3', 'DS4'], true)) {
        $definitions[] = [
            'index' => 1,
            'label' => $slot_label('Opening Hymn', 'Opening Hymn'),
            'slot_name' => 'Opening Hymn',
            'sort_order' => 1,
            'toggle_name' => 'opening_processional',
            'toggle_label' => 'Processional',
        ];
        $definitions[] = [
            'index' => 2,
            'label' => $slot_label('Chief Hymn', 'Chief Hymn'),
            'slot_name' => 'Chief Hymn',
            'sort_order' => 1,
            'toggle_name' => null,
            'toggle_label' => null,
        ];

        for ($distribution_index = 3; $distribution_index <= 7; $distribution_index++) {
            $definitions[] = [
                'index' => $distribution_index,
                'label' => $slot_label('Distribution Hymn', 'Distribution Hymn') . ' ' . ($distribution_index - 2),
                'slot_name' => 'Distribution Hymn',
                'sort_order' => $distribution_index - 2,
                'toggle_name' => null,
                'toggle_label' => null,
            ];
        }

        $definitions[] = [
            'index' => 8,
            'label' => $slot_label('Closing Hymn', 'Closing Hymn'),
            'slot_name' => 'Closing Hymn',
            'sort_order' => 1,
            'toggle_name' => 'closing_recessional',
            'toggle_label' => 'Recessional',
        ];

        return $definitions;
    }

    if (in_array($abbreviation, ['Matins', 'Vespers'], true)) {
        return [
            [
                'index' => 1,
                'label' => $slot_label('Opening Hymn', 'Opening Hymn'),
                'slot_name' => 'Opening Hymn',
                'sort_order' => 1,
                'toggle_name' => null,
                'toggle_label' => null,
            ],
            [
                'index' => 2,
                'label' => $slot_label('Office Hymn', 'Office Hymn'),
                'slot_name' => 'Office Hymn',
                'sort_order' => 1,
                'toggle_name' => null,
                'toggle_label' => null,
            ],
            [
                'index' => 3,
                'label' => $slot_label('Closing Hymn', 'Closing Hymn'),
                'slot_name' => 'Closing Hymn',
                'sort_order' => 1,
                'toggle_name' => null,
                'toggle_label' => null,
            ],
        ];
    }

    return [];
}

function oflc_insert_service_small_catechism_links(PDO $pdo, int $serviceId, array $smallCatechismIds, string $today): void
{
    if ($serviceId <= 0 || $smallCatechismIds === []) {
        return;
    }

    $insertStmt = $pdo->prepare(
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
        $insertStmt->execute([
            ':service_id' => $serviceId,
            ':small_catechism_id' => (int) $smallCatechismId,
            ':sort_order' => $index + 1,
            ':created_at' => $today,
            ':last_updated' => $today,
        ]);
    }
}

$form_state_key = 'planning_form_state';
$request_data = [];
$form_errors = [];
$service_added = isset($_GET['added_service']) && (string) $_GET['added_service'] === '1';
$is_add_submit = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (isset($_POST['clear_service'])) {
        $_SESSION[$form_state_key] = [
            'service_date' => trim((string) ($_POST['clear_service_date'] ?? '')),
        ];
        header('Location: add-service.php', true, 303);
        exit;
    }

    if (isset($_POST['add_service'])) {
        $request_data = $_POST;
        $is_add_submit = true;
    } elseif (isset($_POST['auto_preview'])) {
        $request_data = $_POST;
    } else {
        $_SESSION[$form_state_key] = $_POST;
        header('Location: add-service.php', true, 303);
        exit;
    }
}

if ($request_data === []) {
    $request_data = $_SESSION[$form_state_key] ?? [];
    unset($_SESSION[$form_state_key]);
}

$suggested_service_date = oflc_get_suggested_service_date($pdo);
$selected_date = oflc_request_value($request_data, 'service_date', $suggested_service_date);
$liturgical_window = null;
$selected_movable_matches = [];
$selected_fixed_matches = [];
$combined_window_options = [];
$service_option_choices = [];
$selected_observance_id = oflc_request_value($request_data, 'observance_id');
$selected_observance_name = oflc_request_value($request_data, 'observance_name');
$selected_service_setting_name = oflc_request_value($request_data, 'service_setting_name');
$selected_service_setting = oflc_request_value($request_data, 'service_setting');
$selected_preacher = oflc_request_value($request_data, 'preacher');
$selected_thursday_preacher = oflc_request_value($request_data, 'thursday_preacher');
$selected_reading_set_id = oflc_request_value($request_data, 'selected_reading_set_id');
$selected_new_reading_set = oflc_request_value($request_data, 'selected_new_reading_set');
$selected_new_observance_color = oflc_request_value($request_data, 'new_observance_color');
$selected_small_catechism_id = oflc_request_value($request_data, 'small_catechism_id');
$selected_passion_reading_id = oflc_request_value($request_data, 'passion_reading_id');
$selected_small_catechism_labels = oflc_request_values($request_data, 'small_catechism_labels');
$new_reading_sets = oflc_service_normalize_new_reading_set_drafts($request_data);
$selected_hymns = [];
$selected_option_detail = null;
$selected_service_date_obj = DateTimeImmutable::createFromFormat('Y-m-d', $selected_date) ?: null;
$selected_option_is_sunday = $selected_service_date_obj instanceof DateTimeImmutable
    ? $selected_service_date_obj->format('w') === '0'
    : false;
$selected_option_previous_thursday_label = null;
$logic_columns_ready = false;
$date_error = null;
$recently_celebrated_observance_ids = [];
$hymn_catalog = oflc_fetch_hymn_catalog($pdo);
$hymn_suggestions = $hymn_catalog['suggestions'];
$service_settings = oflc_fetch_service_settings($pdo);
$service_setting_catalog_payload = oflc_build_service_setting_catalog_payload($service_settings);
$small_catechism_options = oflc_fetch_small_catechism_options($pdo);
$passion_reading_options = oflc_fetch_passion_reading_options($pdo);
$hymn_slots = oflc_fetch_hymn_slots($pdo);
$leaders = oflc_fetch_active_leaders($pdo);
$service_settings_by_id = [];
$small_catechism_by_id = [];
$small_catechism_lookup = [];
$passion_reading_by_id = [];
$logic_key_name_map = [];
$date_observance_suggestions = [];
$leaders_by_last_name = [];
$active_observance_details = oflc_service_fetch_active_observance_details($pdo);
$liturgical_color_options = oflc_get_liturgical_color_options();
$observance_catalog_payload = [
    'by_id' => [],
    'name_lookup' => [],
];

foreach ($service_settings as $service_setting) {
    $service_settings_by_id[(string) $service_setting['id']] = $service_setting;
}

foreach ($small_catechism_options as $small_catechism_option) {
    $small_catechism_id = (int) ($small_catechism_option['id'] ?? 0);
    if ($small_catechism_id > 0) {
        $small_catechism_by_id[$small_catechism_id] = $small_catechism_option;
    }
}
$small_catechism_lookup = oflc_build_small_catechism_lookup($small_catechism_options);

foreach ($passion_reading_options as $passion_reading_option) {
    $passion_reading_id = (int) ($passion_reading_option['id'] ?? 0);
    if ($passion_reading_id > 0) {
        $passion_reading_by_id[$passion_reading_id] = $passion_reading_option;
    }
}

foreach ($leaders as $leader) {
    $last_name = trim((string) ($leader['last_name'] ?? ''));
    if ($last_name !== '') {
        $leaders_by_last_name[$last_name] = (int) ($leader['id'] ?? 0);
    }
}

foreach ($active_observance_details as $observance_id => $detail) {
    $observance_id = (int) $observance_id;
    $name = trim((string) ($detail['observance']['name'] ?? ''));
    if ($observance_id <= 0 || $name === '') {
        continue;
    }

    $observance_catalog_payload['by_id'][$observance_id] = [
        'id' => $observance_id,
        'name' => $name,
        'latin_name' => trim((string) ($detail['observance']['latin_name'] ?? '')),
        'color_display' => oflc_get_liturgical_color_display($detail['observance']['liturgical_color'] ?? null),
        'color_class' => oflc_get_liturgical_color_text_class($detail['observance']['liturgical_color'] ?? null),
        'reading_sets' => array_map(static function (array $reading_set): array {
            return [
                'id' => (int) ($reading_set['id'] ?? 0),
                'psalm' => oflc_clean_reading_text($reading_set['psalm'] ?? null, true),
                'old_testament' => oflc_clean_reading_text($reading_set['old_testament'] ?? null),
                'epistle' => oflc_clean_reading_text($reading_set['epistle'] ?? null),
                'gospel' => oflc_clean_reading_text($reading_set['gospel'] ?? null),
            ];
        }, array_slice($detail['reading_sets'] ?? [], 0, 2)),
    ];

    $lower_name = strtolower($name);
    if (!isset($observance_catalog_payload['name_lookup'][$lower_name])) {
        $observance_catalog_payload['name_lookup'][$lower_name] = $observance_id;
    }
}

for ($hymn_index = 1; $hymn_index <= 8; $hymn_index++) {
    $selected_hymns[$hymn_index] = oflc_request_value($request_data, 'hymn_' . $hymn_index);
}

$logic_columns_ready = oflc_planning_logic_columns_ready($pdo);

if ($selected_date !== '') {
    if ($selected_service_date_obj instanceof DateTimeImmutable) {
        $recently_celebrated_observance_ids = oflc_fetch_recently_celebrated_observance_ids($pdo, $selected_service_date_obj);
    }

    $liturgical_window = oflc_get_liturgical_window($selected_date, 6, 6);
    if ($liturgical_window === null) {
        $date_error = 'Please enter a valid date in YYYY-MM-DD format.';
    } elseif ($logic_columns_ready) {
        $logic_keys_for_names = [];
        foreach ($liturgical_window['entries'] as $entry) {
            foreach (oflc_resolve_fixed_logic_keys($entry['month'], $entry['day']) as $logic_key) {
                $logic_keys_for_names[] = $logic_key;
            }

            if ($entry['is_sunday']) {
                foreach (oflc_resolve_movable_logic_keys($entry['week'], 0) as $logic_key) {
                    $logic_keys_for_names[] = $logic_key;
                }
            }
        }
        $logic_key_name_map = oflc_fetch_logic_key_name_map($pdo, $logic_keys_for_names);

        $selected_movable_matches = oflc_fetch_movable_observances(
            $pdo,
            $liturgical_window['selected']['week'],
            $liturgical_window['selected']['weekday']
        );
        $selected_fixed_matches = oflc_fetch_fixed_observances(
            $pdo,
            $liturgical_window['selected']['month'],
            $liturgical_window['selected']['day']
        );

        foreach ($liturgical_window['sunday_options'] as $index => $option) {
            $liturgical_window['sunday_options'][$index]['matches'] = oflc_fetch_movable_observances(
                $pdo,
                $option['week'],
                0
            );
        }

        foreach ($liturgical_window['entries'] as $index => $entry) {
            $liturgical_window['entries'][$index]['fixed_matches'] = oflc_fetch_fixed_observances(
                $pdo,
                $entry['month'],
                $entry['day']
            );

            if ($entry['is_sunday']) {
                $liturgical_window['entries'][$index]['sunday_matches'] = oflc_fetch_movable_observances(
                    $pdo,
                    $entry['week'],
                    0
                );
            } else {
                $liturgical_window['entries'][$index]['sunday_matches'] = [];
            }
        }

        foreach ($liturgical_window['entries'] as $entry) {
            foreach ($entry['sunday_matches'] as $observance) {
                $combined_window_options[] = [
                    'date' => $entry['date'],
                    'sort_date' => $entry['date'],
                    'label' => oflc_format_sunday_list_label($entry, $observance),
                ];
            }

            foreach ($entry['fixed_matches'] as $observance) {
                $combined_window_options[] = [
                    'date' => $entry['date'],
                    'sort_date' => $entry['date'],
                    'label' => oflc_format_festival_list_label($entry, $observance),
                ];
            }
        }

        usort($combined_window_options, static function (array $first, array $second): int {
            return strcmp($first['sort_date'], $second['sort_date']);
        });

    }

    if ($liturgical_window !== null) {
        $sunday_service_option_choices = [];
        $feast_service_option_choices = [];

        foreach ($liturgical_window['entries'] as $entry) {
            $festival_keys = oflc_resolve_fixed_logic_keys($entry['month'], $entry['day']);
            foreach ($festival_keys as $logic_key) {
                $matching_festival = null;
                foreach ($entry['fixed_matches'] as $observance) {
                    if ((string) ($observance['logic_key'] ?? '') === $logic_key) {
                        $matching_festival = $observance;
                        break;
                    }
                }

                $festival_observance_id = (int) ($matching_festival['id'] ?? 0);
                if ($festival_observance_id > 0 && isset($recently_celebrated_observance_ids[$festival_observance_id])) {
                    continue;
                }

                $festival_name = $logic_key_name_map[$logic_key] ?? oflc_humanize_logic_key($logic_key);
                $festival_date = DateTimeImmutable::createFromFormat('Y-m-d', $entry['date']);
                $festival_day_label = $festival_date instanceof DateTimeImmutable ? oflc_format_short_weekday($festival_date) : '';
                $festival_calendar_day_label = $festival_date instanceof DateTimeImmutable ? $festival_date->format('j') : $entry['date'];
                $feast_service_option_choices[$logic_key] = [
                    'logic_key' => $logic_key,
                    'is_sunday' => false,
                    'label' => $festival_name . ' (' . trim($festival_day_label . ' ' . $festival_calendar_day_label) . ')',
                    'suggestion_label' => $festival_name . ' (' . trim($festival_day_label . ' ' . $festival_calendar_day_label) . ')',
                    'date' => $entry['date'],
                ];
            }

            if ($entry['is_sunday']) {
                $sunday_keys = oflc_resolve_movable_logic_keys($entry['week'], 0);
                foreach ($sunday_keys as $logic_key) {
                    $sunday_name = $logic_key_name_map[$logic_key] ?? oflc_humanize_logic_key($logic_key);
                    $sunday_service_option_choices[$logic_key] = [
                        'logic_key' => $logic_key,
                        'is_sunday' => true,
                        'label' => $sunday_name . ' (' . date('m/d', strtotime($entry['date'])) . ')',
                        'suggestion_label' => $sunday_name,
                        'date' => $entry['date'],
                    ];
                }
            }
        }

        $service_option_sort = static function (array $first, array $second): int {
            $date_compare = strcmp($first['date'], $second['date']);
            if ($date_compare !== 0) {
                return $date_compare;
            }

            return strcmp($first['label'], $second['label']);
        };
        uasort($sunday_service_option_choices, $service_option_sort);
        uasort($feast_service_option_choices, $service_option_sort);
        $service_option_choices = $sunday_service_option_choices + $feast_service_option_choices;

        $date_observance_suggestions = oflc_build_date_observance_suggestions($service_option_choices);
    }

    if (ctype_digit($selected_observance_id)) {
        $selected_option_detail = oflc_service_fetch_observance_detail_by_id($pdo, (int) $selected_observance_id);
    }
    if ($selected_option_detail === null && $selected_observance_name !== '') {
        $selected_option_detail = oflc_service_fetch_observance_detail_by_name($pdo, $selected_observance_name);
    }
}

if ($selected_option_is_sunday && $selected_service_date_obj instanceof DateTimeImmutable) {
    $selected_option_previous_thursday_label = $selected_service_date_obj->modify('-3 days')->format('m/d');
}

if ($selected_small_catechism_labels === [] && $selected_small_catechism_id !== '' && ctype_digit($selected_small_catechism_id)) {
    $selected_small_catechism_id_int = (int) $selected_small_catechism_id;
    if (isset($small_catechism_by_id[$selected_small_catechism_id_int])) {
        $selected_small_catechism_labels[] = trim((string) ($small_catechism_by_id[$selected_small_catechism_id_int]['label'] ?? ''));
    }
}
if ($selected_small_catechism_labels === []) {
    $selected_small_catechism_labels[] = '';
}

$selected_service_setting_detail = $selected_service_setting !== '' && isset($service_settings_by_id[$selected_service_setting])
    ? $service_settings_by_id[$selected_service_setting]
    : null;
if ($selected_service_setting_detail === null && $selected_service_setting_name !== '') {
    $selected_service_setting_detail = oflc_find_service_setting_detail($service_settings, $selected_service_setting_name);
    if ($selected_service_setting_detail !== null) {
        $selected_service_setting = (string) ($selected_service_setting_detail['id'] ?? '');
    }
}
if ($selected_service_setting_name === '' && $selected_service_setting_detail !== null) {
    $selected_service_setting_name = trim((string) ($selected_service_setting_detail['setting_name'] ?? ''));
}

$selected_service_setting_summary = '&nbsp;';
if ($selected_service_setting_detail !== null) {
    $selected_service_setting_summary = trim((string) ($selected_service_setting_detail['abbreviation'] ?? ''));
    $page_number = trim((string) ($selected_service_setting_detail['page_number'] ?? ''));

    if ($page_number !== '') {
        $selected_service_setting_summary .= ($selected_service_setting_summary !== '' ? ', ' : '') . 'p. ' . $page_number;
    }

    if ($selected_service_setting_summary === '') {
        $selected_service_setting_summary = '&nbsp;';
    }
}

$hymn_field_definitions = oflc_build_hymn_field_definitions($selected_service_setting_detail, $hymn_slots);
$hymn_field_definitions_by_service = [];
foreach ($service_settings as $service_setting) {
    $service_id = (string) $service_setting['id'];
    $hymn_field_definitions_by_service[$service_id] = oflc_build_hymn_field_definitions($service_setting, $hymn_slots);
}

$selected_date_display = '&nbsp;';
if ($selected_service_date_obj instanceof DateTimeImmutable) {
    $selected_date_display = $selected_service_date_obj->format('l, F j');
}

$service_card_color_class = 'service-card-color-dark';
if ($selected_option_detail !== null) {
    $service_card_color_class = oflc_get_liturgical_color_text_class($selected_option_detail['observance']['liturgical_color'] ?? null);
}

$resolved_initial_selected_reading_set_id = oflc_resolve_selected_reading_set_id_for_detail(
    $selected_option_detail,
    oflc_normalize_selected_reading_set_id($selected_reading_set_id)
);
if ($resolved_initial_selected_reading_set_id === null) {
    $resolved_initial_selected_reading_set_id = oflc_get_default_selected_reading_set_id($selected_option_detail, $selected_service_date_obj);
}
if ($resolved_initial_selected_reading_set_id !== null) {
    $selected_reading_set_id = (string) $resolved_initial_selected_reading_set_id;
}

$active_observance_name = trim((string) ($selected_option_detail['observance']['name'] ?? $selected_observance_name));
$show_small_catechism_dropdown = oflc_is_advent_midweek_observance_name($active_observance_name) || oflc_is_lent_midweek_observance_name($active_observance_name);
$show_passion_reading_dropdown = oflc_is_lent_midweek_observance_name($active_observance_name);
$filtered_passion_reading_options = oflc_filter_passion_reading_options_for_service_date($passion_reading_options, $selected_service_date_obj);

if ($is_add_submit && $date_error === null) {
    $service_setting_id = $selected_service_setting !== '' && isset($service_settings_by_id[$selected_service_setting])
        ? (int) $selected_service_setting
        : null;
    if ($service_setting_id === null && $selected_service_setting_name !== '') {
        $matched_service_setting = oflc_find_service_setting_detail($service_settings, $selected_service_setting_name);
        if ($matched_service_setting !== null) {
            $service_setting_id = (int) ($matched_service_setting['id'] ?? 0);
            $selected_service_setting = (string) $service_setting_id;
            $selected_service_setting_name = trim((string) ($matched_service_setting['setting_name'] ?? $selected_service_setting_name));
        }
    }
    if ($selected_service_setting_name !== '' && $service_setting_id === null) {
        $form_errors[] = 'Select a valid service setting.';
    }

    $leader_id = null;
    if ($selected_preacher !== '') {
        if (!isset($leaders_by_last_name[$selected_preacher]) || (int) $leaders_by_last_name[$selected_preacher] <= 0) {
            $form_errors[] = 'Leader must match an active last name.';
        } else {
            $leader_id = (int) $leaders_by_last_name[$selected_preacher];
        }
    }

    $copy_to_previous_thursday = $selected_option_is_sunday && isset($request_data['copy_to_previous_thursday']);
    $thursday_leader_id = null;
    if ($copy_to_previous_thursday && $selected_thursday_preacher !== '') {
        if (!isset($leaders_by_last_name[$selected_thursday_preacher]) || (int) $leaders_by_last_name[$selected_thursday_preacher] <= 0) {
            $form_errors[] = 'Thursday leader must match an active last name.';
        } else {
            $thursday_leader_id = (int) $leaders_by_last_name[$selected_thursday_preacher];
        }
    }

    $observance_name = trim($selected_observance_name);
    $submitted_observance_id = ctype_digit($selected_observance_id) ? (int) $selected_observance_id : 0;
    $persisted_observance_detail = $submitted_observance_id > 0
        ? oflc_service_fetch_observance_detail_by_id($pdo, $submitted_observance_id)
        : null;
    if ($persisted_observance_detail === null && $observance_name !== '') {
        $persisted_observance_detail = oflc_service_fetch_observance_detail_by_name($pdo, $observance_name);
    }

    $create_observance_name = '';
    if ($observance_name === '') {
        $form_errors[] = 'Enter a liturgical observance.';
    } elseif ($persisted_observance_detail === null) {
        $create_observance_name = $observance_name;
        if (!oflc_is_valid_liturgical_color($selected_new_observance_color)) {
            $form_errors[] = 'Select a liturgical color for the new observance.';
        }
    }

    $resolved_selected_reading_set_id = oflc_normalize_selected_reading_set_id($selected_reading_set_id);
    if ($selected_reading_set_id !== '' && $resolved_selected_reading_set_id === null) {
        $form_errors[] = 'Select a valid reading set.';
    } elseif ($resolved_selected_reading_set_id !== null && oflc_resolve_selected_reading_set_id_for_detail($persisted_observance_detail, $resolved_selected_reading_set_id) === null) {
        $form_errors[] = 'Selected reading set does not match the observance.';
    }

    $has_draft_readings = false;
    foreach ($new_reading_sets as $draft) {
        if (!empty($draft['has_content'])) {
            $has_draft_readings = true;
            break;
        }
    }
    $observance_has_readings = $persisted_observance_detail !== null && count($persisted_observance_detail['reading_sets'] ?? []) > 0;
    if (!$observance_has_readings && $has_draft_readings) {
        $draft_one = $new_reading_sets[1] ?? null;
        if (!is_array($draft_one) || empty($draft_one['has_content'])) {
            $form_errors[] = 'Enter readings for the new observance.';
        }
    }

    $small_catechism_ids = [];
    foreach ($selected_small_catechism_labels as $small_catechism_label) {
        $normalized_small_catechism_label = strtolower(trim((string) $small_catechism_label));
        if ($normalized_small_catechism_label === '') {
            continue;
        }

        if (!isset($small_catechism_lookup[$normalized_small_catechism_label])) {
            $form_errors[] = 'Select a valid Small Catechism portion.';
            break;
        }

        $small_catechism_ids[] = (int) $small_catechism_lookup[$normalized_small_catechism_label];
    }
    $small_catechism_ids = array_values(array_unique(array_filter($small_catechism_ids, static function (int $id): bool {
        return $id > 0;
    })));
    $small_catechism_id = $small_catechism_ids !== [] ? $small_catechism_ids[0] : null;

    $passion_reading_id = null;
    if ($selected_passion_reading_id !== '') {
        if (!ctype_digit($selected_passion_reading_id) || !isset($passion_reading_by_id[(int) $selected_passion_reading_id])) {
            $form_errors[] = 'Select a valid passion reading.';
        } else {
            $passion_reading_id = (int) $selected_passion_reading_id;
        }
    }

    $submitted_observance_name = trim((string) ($persisted_observance_detail['observance']['name'] ?? $observance_name));
    $is_advent_midweek = oflc_is_advent_midweek_observance_name($submitted_observance_name);
    $is_lent_midweek = oflc_is_lent_midweek_observance_name($submitted_observance_name);

    if (!$is_advent_midweek && !$is_lent_midweek) {
        $small_catechism_id = null;
        $small_catechism_ids = [];
        $passion_reading_id = null;
    } elseif (!$is_lent_midweek) {
        $passion_reading_id = null;
    }

    $hymn_entries = [];
    foreach ($hymn_field_definitions as $definition) {
        $index = (int) ($definition['index'] ?? 0);
        $hymn_value = $selected_hymns[$index] ?? '';
        $hymn_id = oflc_resolve_hymn_id($hymn_value, $hymn_catalog['lookup_by_key']);

        if (trim($hymn_value) === '') {
            continue;
        }

        if ($hymn_id === null) {
            $form_errors[] = 'Hymn field ' . $index . ' must match a hymn from the suggestions.';
            continue;
        }

        $slot_name = (string) ($definition['slot_name'] ?? '');
        if (($definition['toggle_name'] ?? null) === 'opening_processional' && isset($request_data['opening_processional'])) {
            $slot_name = 'Processional Hymn';
        }
        if (($definition['toggle_name'] ?? null) === 'closing_recessional' && isset($request_data['closing_recessional'])) {
            $slot_name = 'Recessional Hymn';
        }

        if (!isset($hymn_slots[$slot_name]['id'])) {
            $form_errors[] = 'Missing hymn slot configuration for ' . $slot_name . '.';
            continue;
        }

        $hymn_entries[] = [
            'hymn_id' => $hymn_id,
            'slot_id' => (int) $hymn_slots[$slot_name]['id'],
            'sort_order' => (int) ($definition['sort_order'] ?? 1),
        ];
    }

    if ($selected_service_date_obj === false || !$selected_service_date_obj instanceof DateTimeImmutable) {
        $form_errors[] = 'Service date must use YYYY-MM-DD.';
    }

    $target_dates = [];
    if ($selected_service_date_obj instanceof DateTimeImmutable) {
        $target_dates['sunday'] = $selected_service_date_obj->format('Y-m-d');
        if ($copy_to_previous_thursday) {
            $target_dates['thursday'] = $selected_service_date_obj->modify('-3 days')->format('Y-m-d');
        }

        $conflict_stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM service_db
             WHERE is_active = 1
               AND service_date = ?
               AND service_order = 1'
        );

        foreach ($target_dates as $target_date) {
            $conflict_stmt->execute([$target_date]);
            if ((int) $conflict_stmt->fetchColumn() > 0) {
                $form_errors[] = 'Another active service already exists for ' . $target_date . '.';
            }
        }
    }

    if ($form_errors === []) {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        try {
            $pdo->beginTransaction();

            if ($persisted_observance_detail === null && $create_observance_name !== '') {
                $created_observance_id = oflc_service_create_observance($pdo, $create_observance_name, $selected_new_observance_color);
                $persisted_observance_detail = oflc_service_fetch_observance_detail_by_id($pdo, $created_observance_id);
            }

            if ($persisted_observance_detail === null) {
                throw new RuntimeException('Unable to resolve observance.');
            }

            $inserted_reading_set_ids = [];
            if (count($persisted_observance_detail['reading_sets'] ?? []) === 0) {
                $inserted_reading_set_ids = oflc_service_insert_new_reading_set_drafts(
                    $pdo,
                    (int) ($persisted_observance_detail['observance']['id'] ?? 0),
                    $new_reading_sets
                );
            }

            if ($resolved_selected_reading_set_id === null && count($inserted_reading_set_ids) === 1) {
                $resolved_selected_reading_set_id = (int) reset($inserted_reading_set_ids);
            }

            $insert_service_stmt = $pdo->prepare(
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

            $insert_usage_stmt = $pdo->prepare(
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
                    1,
                    :created_at,
                    :last_updated,
                    1
                 )'
            );

            $liturgical_calendar_id = (int) ($persisted_observance_detail['observance']['id'] ?? 0) ?: null;
            $insert_service_stmt->execute([
                ':service_date' => $target_dates['sunday'],
                ':liturgical_calendar_id' => $liturgical_calendar_id,
                ':passion_reading_id' => $passion_reading_id,
                ':small_catechism_id' => $small_catechism_id,
                ':selected_reading_set_id' => $resolved_selected_reading_set_id,
                ':service_setting_id' => $service_setting_id,
                ':leader_id' => $leader_id,
                ':copied_from_service_id' => null,
                ':last_updated' => $today,
            ]);
            $sunday_service_id = (int) $pdo->lastInsertId();
            oflc_insert_service_small_catechism_links($pdo, $sunday_service_id, $small_catechism_ids, $today);

            foreach ($hymn_entries as $entry) {
                $insert_usage_stmt->execute([
                    ':sunday_id' => $sunday_service_id,
                    ':hymn_id' => $entry['hymn_id'],
                    ':slot_id' => $entry['slot_id'],
                    ':sort_order' => $entry['sort_order'],
                    ':created_at' => $today,
                    ':last_updated' => $today,
                ]);
            }

            if (isset($target_dates['thursday'])) {
                $insert_service_stmt->execute([
                    ':service_date' => $target_dates['thursday'],
                    ':liturgical_calendar_id' => $liturgical_calendar_id,
                    ':passion_reading_id' => $passion_reading_id,
                    ':small_catechism_id' => $small_catechism_id,
                    ':selected_reading_set_id' => $resolved_selected_reading_set_id,
                    ':service_setting_id' => $service_setting_id,
                    ':leader_id' => $thursday_leader_id ?? $leader_id,
                    ':copied_from_service_id' => $sunday_service_id,
                    ':last_updated' => $today,
                ]);
                $thursday_service_id = (int) $pdo->lastInsertId();
                oflc_insert_service_small_catechism_links($pdo, $thursday_service_id, $small_catechism_ids, $today);

                foreach ($hymn_entries as $entry) {
                    $insert_usage_stmt->execute([
                        ':sunday_id' => $thursday_service_id,
                        ':hymn_id' => $entry['hymn_id'],
                        ':slot_id' => $entry['slot_id'],
                        ':sort_order' => $entry['sort_order'],
                        ':created_at' => $today,
                        ':last_updated' => $today,
                    ]);
                }
            }

            $pdo->commit();
            unset($_SESSION[$form_state_key]);
            header('Location: add-service.php?added_service=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $form_errors[] = 'The service could not be added.';
        }
    }
}

include 'includes/header.php';
?>

<div id="planner-root">
<h3>Add a Service</h3>

<p>Use this form to add a service to the schedule.</p>

<?php if ($service_added): ?>
    <p class="planning-success">Service added.</p>
<?php endif; ?>

<?php if ($form_errors !== []): ?>
    <p class="planning-error"><?php echo htmlspecialchars(implode(' ', $form_errors), ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<?php if ($date_error !== null): ?>
    <p class="planning-error"><?php echo htmlspecialchars($date_error, ENT_QUOTES, 'UTF-8'); ?></p>
<?php else: ?>
    <form
        id="add-service-form"
        class="service-card <?php echo htmlspecialchars($service_card_color_class, ENT_QUOTES, 'UTF-8'); ?>"
        method="post"
        action="add-service.php"
        data-hymn-suggestions-id="hymn-options"
        data-hymn-definitions-by-service="<?php echo htmlspecialchars(json_encode($hymn_field_definitions_by_service, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-service-setting-catalog="<?php echo htmlspecialchars(json_encode($service_setting_catalog_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-observance-catalog="<?php echo htmlspecialchars(json_encode($observance_catalog_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-date-observance-suggestions="<?php echo htmlspecialchars(json_encode(array_values($date_observance_suggestions), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-initial-suggested-date="<?php echo htmlspecialchars($suggested_service_date, ENT_QUOTES, 'UTF-8'); ?>"
        data-selected-reading-set-id="<?php echo htmlspecialchars((string) $selected_reading_set_id, ENT_QUOTES, 'UTF-8'); ?>"
        data-selected-new-reading-set="<?php echo htmlspecialchars((string) $selected_new_reading_set, ENT_QUOTES, 'UTF-8'); ?>"
        data-initial-reading-editor="<?php echo htmlspecialchars(json_encode(array_values($new_reading_sets), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-small-catechism-options="<?php echo htmlspecialchars(json_encode(array_values($small_catechism_options), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-passion-reading-options="<?php echo htmlspecialchars(json_encode(array_values($passion_reading_options), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-selected-small-catechism-labels="<?php echo htmlspecialchars(json_encode(array_values($selected_small_catechism_labels), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-selected-passion-reading-id="<?php echo htmlspecialchars((string) $selected_passion_reading_id, ENT_QUOTES, 'UTF-8'); ?>"
        data-initial-hymn-state="<?php echo htmlspecialchars(json_encode([
            'hymns' => array_map('strval', $selected_hymns),
            'opening_processional' => isset($request_data['opening_processional']),
            'closing_recessional' => isset($request_data['closing_recessional']),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
    >
        <input type="hidden" name="auto_preview" id="auto-preview-flag" value="">
        <?php if ($hymn_suggestions !== []): ?>
            <datalist id="hymn-options">
                <?php foreach ($hymn_suggestions as $suggestion): ?>
                    <option value="<?php echo htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8'); ?>"></option>
                <?php endforeach; ?>
            </datalist>
        <?php endif; ?>
        <?php if ($leaders !== []): ?>
            <datalist id="leader-options">
                <?php foreach ($leaders as $leader): ?>
                    <?php $leader_last_name = trim((string) ($leader['last_name'] ?? '')); ?>
                    <?php if ($leader_last_name !== ''): ?>
                        <option value="<?php echo htmlspecialchars($leader_last_name, ENT_QUOTES, 'UTF-8'); ?>"></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </datalist>
        <?php endif; ?>
        <?php if ($small_catechism_options !== []): ?>
            <datalist id="small-catechism-options">
                <?php foreach ($small_catechism_options as $small_catechism_option): ?>
                    <?php $small_catechism_label = trim((string) ($small_catechism_option['label'] ?? '')); ?>
                    <?php if ($small_catechism_label !== ''): ?>
                        <option value="<?php echo htmlspecialchars($small_catechism_label, ENT_QUOTES, 'UTF-8'); ?>"></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </datalist>
        <?php endif; ?>
        <?php if ($observance_catalog_payload['by_id'] !== []): ?>
            <datalist id="observance-options">
                <?php foreach (($date_observance_suggestions !== [] ? $date_observance_suggestions : array_map(static function (array $observance): string { return (string) ($observance['name'] ?? ''); }, $observance_catalog_payload['by_id'])) as $observance_name_option): ?>
                    <?php if (trim((string) $observance_name_option) !== ''): ?>
                        <option value="<?php echo htmlspecialchars((string) $observance_name_option, ENT_QUOTES, 'UTF-8'); ?>"></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </datalist>
        <?php endif; ?>
        <div class="service-card-grid">
            <section class="service-card-panel">
                <div class="service-card-date-row">
                    <input type="date" id="service_date" name="service_date" class="service-card-text" value="<?php echo htmlspecialchars($selected_date, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="service-card-display-date"><?php echo $selected_date_display === '&nbsp;' ? '&nbsp;' : htmlspecialchars($selected_date_display, ENT_QUOTES, 'UTF-8'); ?></div>
                <input type="hidden" id="observance_id" name="observance_id" value="<?php echo htmlspecialchars($selected_observance_id, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="service-card-suggestion-anchor">
                    <input type="text" id="observance_name" name="observance_name" class="service-card-text" value="<?php echo htmlspecialchars($selected_observance_name, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Liturgical observance" autocomplete="off">
                    <div class="service-card-suggestion-list js-observance-suggestion-list" hidden></div>
                </div>
                <div class="service-card-latin-name" id="observance-latin-name">
                    <?php
                    $latin_name = trim((string) ($selected_option_detail['observance']['latin_name'] ?? ''));
                    echo $latin_name !== ''
                        ? htmlspecialchars($latin_name, ENT_QUOTES, 'UTF-8')
                        : '&nbsp;';
                    ?>
                </div>
                <div class="service-card-meta">
                    <input type="hidden" id="service_setting" name="service_setting" value="<?php echo htmlspecialchars($selected_service_setting, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="service-card-suggestion-anchor">
                        <input type="text" id="service_setting_name" name="service_setting_name" class="service-card-text" value="<?php echo htmlspecialchars($selected_service_setting_name, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Service type" autocomplete="off">
                        <div class="service-card-suggestion-list service-card-suggestion-list-fixed js-service-setting-suggestion-list" hidden></div>
                    </div>
                    <div class="service-card-service-summary" id="service-setting-summary"><?php echo $selected_service_setting_summary === '&nbsp;' ? '&nbsp;' : htmlspecialchars($selected_service_setting_summary, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php $liturgical_color_display = oflc_get_liturgical_color_display($selected_option_detail['observance']['liturgical_color'] ?? null); ?>
                    <div class="service-card-color-slot">
                        <div class="service-card-color-line<?php echo $selected_option_detail === null && trim($selected_observance_name) !== '' ? ' is-hidden' : ''; ?>"><?php echo $liturgical_color_display === '' ? '&nbsp;' : htmlspecialchars($liturgical_color_display, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="update-service-new-observance-color<?php echo $selected_option_detail === null && trim($selected_observance_name) !== '' ? ' is-visible' : ''; ?>" id="new-observance-color-wrap">
                            <select id="new_observance_color" name="new_observance_color" class="service-card-select">
                                <option value="">Choose color</option>
                                <?php foreach ($liturgical_color_options as $liturgical_color_option): ?>
                                    <option value="<?php echo htmlspecialchars($liturgical_color_option, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selected_new_observance_color === $liturgical_color_option ? ' selected' : ''; ?>>
                                        <?php echo htmlspecialchars($liturgical_color_option, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php if ($selected_option_is_sunday && $selected_option_previous_thursday_label !== null): ?>
                        <label class="service-card-checkbox">
                            <input type="checkbox" id="copy_to_previous_thursday" name="copy_to_previous_thursday" value="1"<?php echo isset($request_data['copy_to_previous_thursday']) ? ' checked' : ''; ?>>
                            <span>Copy this service to the previous Thursday (<?php echo htmlspecialchars($selected_option_previous_thursday_label, ENT_QUOTES, 'UTF-8'); ?>)?</span>
                        </label>
                    <?php endif; ?>
                </div>
            </section>

            <section class="service-card-panel">
                <div class="service-card-readings" id="service-card-readings">
                    <?php if ($selected_option_detail !== null && count($selected_option_detail['reading_sets']) > 0): ?>
                        <?php if ($show_small_catechism_dropdown): ?>
                            <?php foreach ($selected_small_catechism_labels as $small_catechism_label_index => $small_catechism_label): ?>
                                <div class="service-card-inline-field-row">
                                    <input type="text" class="service-card-text update-service-reading-input" name="small_catechism_labels[]" value="<?php echo htmlspecialchars((string) $small_catechism_label, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Select Luther's Small Catechism" list="small-catechism-options" autocomplete="off">
                                    <?php if ($small_catechism_label_index === count($selected_small_catechism_labels) - 1): ?>
                                        <button type="button" class="service-card-add-inline-button js-small-catechism-add">+</button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if ($show_passion_reading_dropdown): ?>
                            <select class="service-card-select update-service-reading-input" name="passion_reading_id">
                                <option value="">Select passion reading</option>
                                <?php foreach ($filtered_passion_reading_options as $passion_reading_option): ?>
                                    <option value="<?php echo htmlspecialchars((string) ($passion_reading_option['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selected_passion_reading_id === (string) ($passion_reading_option['id'] ?? '') ? ' selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) ($passion_reading_option['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <?php $reading_sets_to_show = array_slice($selected_option_detail['reading_sets'], 0, 2); ?>
                        <?php foreach ($reading_sets_to_show as $reading_index => $reading_set): ?>
                            <?php $reading_set_id = (int) ($reading_set['id'] ?? 0); ?>
                            <div class="service-card-reading-set<?php echo $reading_index > 0 ? ' service-card-reading-set-secondary' : ''; ?>">
                                <?php $psalm_text = oflc_clean_reading_text($reading_set['psalm'] ?? null, true); ?>
                                <?php if ($psalm_text !== '' && $reading_set_id > 0): ?>
                                    <label class="service-card-reading-psalm">
                                        <input
                                            type="radio"
                                            name="selected_reading_set_id"
                                            value="<?php echo htmlspecialchars((string) $reading_set_id, ENT_QUOTES, 'UTF-8'); ?>"
                                            class="service-card-reading-radio"
                                            <?php echo $selected_reading_set_id !== '' && (string) $reading_set_id === $selected_reading_set_id ? 'checked' : ''; ?>
                                        >
                                        <span><?php echo htmlspecialchars($psalm_text, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </label>
                                <?php endif; ?>
                                <?php $old_testament = oflc_clean_reading_text($reading_set['old_testament'] ?? null); ?>
                                <?php $epistle = oflc_clean_reading_text($reading_set['epistle'] ?? null); ?>
                                <?php $gospel = oflc_clean_reading_text($reading_set['gospel'] ?? null); ?>
                                <?php if ($old_testament !== ''): ?>
                                    <div><?php echo htmlspecialchars($old_testament, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <?php if ($epistle !== ''): ?>
                                    <div><?php echo htmlspecialchars($epistle, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <?php if ($gospel !== ''): ?>
                                    <div><?php echo htmlspecialchars($gospel, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif (trim($selected_observance_name) !== ''): ?>
                        <div class="update-service-reading-editor-note">No appointed readings are stored for this observance yet.</div>
                        <?php foreach ($new_reading_sets as $draft): ?>
                            <?php $draft_index = (int) ($draft['index'] ?? 0); ?>
                            <?php if ($draft_index > 0): ?>
                                <div class="service-card-reading-set update-service-reading-editor">
                                    <?php if ($show_small_catechism_dropdown): ?>
                                        <?php foreach ($selected_small_catechism_labels as $small_catechism_label_index => $small_catechism_label): ?>
                                            <div class="service-card-inline-field-row">
                                                <input type="text" class="service-card-text update-service-reading-input" name="small_catechism_labels[]" value="<?php echo htmlspecialchars((string) $small_catechism_label, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Select Luther's Small Catechism" list="small-catechism-options" autocomplete="off">
                                                <?php if ($small_catechism_label_index === count($selected_small_catechism_labels) - 1): ?>
                                                    <button type="button" class="service-card-add-inline-button js-small-catechism-add">+</button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <?php if ($show_passion_reading_dropdown): ?>
                                        <select class="service-card-select update-service-reading-input" name="passion_reading_id">
                                            <option value="">Select passion reading</option>
                                            <?php foreach ($filtered_passion_reading_options as $passion_reading_option): ?>
                                                <option value="<?php echo htmlspecialchars((string) ($passion_reading_option['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selected_passion_reading_id === (string) ($passion_reading_option['id'] ?? '') ? ' selected' : ''; ?>>
                                                    <?php echo htmlspecialchars((string) ($passion_reading_option['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    <input type="text" class="service-card-text update-service-reading-input" name="new_reading_set_<?php echo $draft_index; ?>_psalm" value="<?php echo htmlspecialchars((string) ($draft['psalm'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Psalm">
                                    <input type="text" class="service-card-text update-service-reading-input" name="new_reading_set_<?php echo $draft_index; ?>_old_testament" value="<?php echo htmlspecialchars((string) ($draft['old_testament'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Old Testament">
                                    <input type="text" class="service-card-text update-service-reading-input" name="new_reading_set_<?php echo $draft_index; ?>_epistle" value="<?php echo htmlspecialchars((string) ($draft['epistle'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Epistle">
                                    <input type="text" class="service-card-text update-service-reading-input" name="new_reading_set_<?php echo $draft_index; ?>_gospel" value="<?php echo htmlspecialchars((string) ($draft['gospel'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="Gospel">
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        &nbsp;
                    <?php endif; ?>
                </div>
            </section>

            <section class="service-card-panel">
                <div
                    class="service-card-hymns"
                    id="service-card-hymns"
                    data-selected-service-id="<?php echo htmlspecialchars((string) $selected_service_setting, ENT_QUOTES, 'UTF-8'); ?>"
                >
                    <?php if ($hymn_field_definitions !== []): ?>
                        <div class="service-card-hymn-instruction">Check boxes mark hymns as procession / recession.</div>
                    <?php endif; ?>
                    <?php if ($hymn_field_definitions !== []): ?>
                        <?php foreach ($hymn_field_definitions as $hymn_field): ?>
                            <?php $hymn_index = (int) $hymn_field['index']; ?>
                            <div class="service-card-hymn-row">
                                <input type="text" id="hymn_<?php echo $hymn_index; ?>" name="hymn_<?php echo $hymn_index; ?>" value="<?php echo htmlspecialchars($selected_hymns[$hymn_index], ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars((string) $hymn_field['label'], ENT_QUOTES, 'UTF-8'); ?>" data-list-id="hymn-options" autocomplete="off" class="service-card-hymn-lookup">
                                <?php if (!empty($hymn_field['toggle_name'])): ?>
                                    <label class="service-card-hymn-inline-toggle" for="<?php echo htmlspecialchars((string) $hymn_field['toggle_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <input
                                            type="checkbox"
                                            id="<?php echo htmlspecialchars((string) $hymn_field['toggle_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            name="<?php echo htmlspecialchars((string) $hymn_field['toggle_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            value="1"
                                            <?php echo isset($request_data[(string) $hymn_field['toggle_name']]) ? 'checked' : ''; ?>
                                        >
                                    </label>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="service-card-panel">
                <label class="service-card-label" for="preacher">Leader</label>
                <input type="text" id="preacher" name="preacher" class="service-card-text" value="<?php echo htmlspecialchars($selected_preacher, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Fenker" list="leader-options">
                <div class="service-card-optional-field<?php echo isset($request_data['copy_to_previous_thursday']) ? ' is-visible' : ''; ?>" id="thursday-preacher-wrap">
                    <label class="service-card-label" for="thursday_preacher">Thursday Leader</label>
                    <input type="text" id="thursday_preacher" name="thursday_preacher" class="service-card-text" value="<?php echo htmlspecialchars($selected_thursday_preacher, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Blank = same as Sunday" list="leader-options">
                </div>
            </section>
        </div>
    </form>
    <div class="service-card-actions">
        <button type="button" class="clear-list-button" onclick="oflcClearPlanner(document.getElementById('add-service-form'));">Clear Service</button>
        <button type="submit" name="add_service" value="1" class="add-hymn-button" form="add-service-form">Add Service</button>
    </div>

    <?php if (false): ?>
    <div class="planning-result">
        <h4>Selected Date</h4>
        <dl class="planning-result-grid">
            <div>
                <dt>One-year week</dt>
                <dd><?php echo htmlspecialchars($liturgical_window['selected']['week'] !== null ? (string) $liturgical_window['selected']['week'] : 'None', ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <div>
                <dt>Resolved logic key</dt>
                <dd><?php echo htmlspecialchars(implode(', ', $liturgical_window['selected']['logic_keys']) ?: 'None', ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <div>
                <dt>Calendar month</dt>
                <dd><?php echo htmlspecialchars((string) $liturgical_window['selected']['month'], ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <div>
                <dt>Calendar day</dt>
                <dd><?php echo htmlspecialchars((string) $liturgical_window['selected']['day'], ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <div>
                <dt>Fixed-date key</dt>
                <dd><?php echo htmlspecialchars(implode(', ', $liturgical_window['selected']['fixed_logic_keys']) ?: 'None', ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <div>
                <dt>Weekday</dt>
                <dd><?php echo htmlspecialchars($liturgical_window['selected']['weekday_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string) $liturgical_window['selected']['weekday'], ENT_QUOTES, 'UTF-8'); ?>, Sunday = 0)</dd>
            </div>
            <div>
                <dt>Sunday anchor date</dt>
                <dd><?php echo htmlspecialchars($liturgical_window['selected']['sunday_date'], ENT_QUOTES, 'UTF-8'); ?></dd>
            </div>
            <div>
                <dt>Is Sunday</dt>
                <dd><?php echo $liturgical_window['selected']['is_sunday'] ? 'Yes' : 'No'; ?></dd>
            </div>
        </dl>
    </div>

    <div class="planning-result">
        <h4>Database Matches For Selected Date</h4>
        <?php if (!$logic_columns_ready): ?>
            <p class="planning-error">Run <code>sql/add_logic_key_to_liturgical_calendar.sql</code> against the database first so planning can query rows by <code>logic_key</code>.</p>
        <?php else: ?>
            <div class="planning-match-columns">
                <div>
                    <h5>Movable Observances</h5>
                    <?php if (count($selected_movable_matches) === 0): ?>
                        <p>No movable observances matched the selected week/day.</p>
                    <?php else: ?>
                        <ul class="planning-option-list">
                            <?php foreach ($selected_movable_matches as $observance): ?>
                                <li><?php echo htmlspecialchars(oflc_format_observance_label($observance), ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div>
                    <h5>Fixed Festivals</h5>
                    <?php if (count($selected_fixed_matches) === 0): ?>
                        <p>No fixed festivals matched this calendar date.</p>
                    <?php else: ?>
                        <ul class="planning-option-list">
                            <?php foreach ($selected_fixed_matches as $observance): ?>
                                <li><?php echo htmlspecialchars(oflc_format_observance_label($observance), ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="planning-result">
        <h4>Closest Sundays And Festivals</h4>
        <p>Query any fixed festival records whose month/day falls between <strong><?php echo htmlspecialchars($liturgical_window['window_start'], ENT_QUOTES, 'UTF-8'); ?></strong> and <strong><?php echo htmlspecialchars($liturgical_window['window_end'], ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
        <?php if (!$logic_columns_ready): ?>
            <p>Run the logic-key migration to query Sundays and festivals from MySQL.</p>
        <?php elseif (count($combined_window_options) === 0): ?>
            <p>No Sunday or festival options were found inside this window.</p>
        <?php else: ?>
            <ul class="planning-option-list">
                <?php foreach ($combined_window_options as $option): ?>
                    <li><?php echo htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<script>
function oflcResetReadingSelection(form) {
    var radios = form.querySelectorAll('.service-card-reading-radio');

    Array.prototype.forEach.call(radios, function (radio) {
        radio.checked = false;
    });
}

function oflcSubmitPlannerPreview(form, resetReadings) {
    var autoPreview = form.querySelector('#auto-preview-flag');
    var formData;

    if (resetReadings) {
        oflcResetReadingSelection(form);
    }

    if (autoPreview) {
        autoPreview.value = '1';
    }

    formData = new FormData(form);

    fetch(form.action, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    })
        .then(function (response) {
            return response.text();
        })
        .then(function (html) {
            var parser = new DOMParser();
            var documentFragment = parser.parseFromString(html, 'text/html');
            var nextRoot = documentFragment.getElementById('planner-root');
            var currentRoot = document.getElementById('planner-root');

            if (!nextRoot || !currentRoot) {
                window.location.reload();
                return;
            }

            currentRoot.replaceWith(nextRoot);
            if (typeof window.oflcInitializePlannerUI === 'function') {
                window.oflcInitializePlannerUI(document);
            }
        })
        .catch(function () {
            window.location.reload();
        });
}

function oflcClearPlanner(form) {
    var formData = new FormData(form);
    var clearServiceDate = form.getAttribute('data-initial-suggested-date') || '';

    formData.delete('auto_preview');
    formData.set('clear_service', '1');
    formData.set('clear_service_date', clearServiceDate);

    fetch(form.action, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    })
        .then(function (response) {
            return response.text();
        })
        .then(function (html) {
            var parser = new DOMParser();
            var documentFragment = parser.parseFromString(html, 'text/html');
            var nextRoot = documentFragment.getElementById('planner-root');
            var currentRoot = document.getElementById('planner-root');

            if (!nextRoot || !currentRoot) {
                window.location.reload();
                return;
            }

            currentRoot.replaceWith(nextRoot);
            if (typeof window.oflcInitializePlannerUI === 'function') {
                window.oflcInitializePlannerUI(document);
            }
        })
        .catch(function () {
            window.location.reload();
        });
}

window.oflcInitializePlannerUI = function (root) {
    var form = root.querySelector('#add-service-form');
    var serviceDateInput = root.querySelector('#service_date');
    var serviceSettingInput = root.querySelector('#service_setting_name');
    var serviceSettingIdInput = root.querySelector('#service_setting');
    var serviceSettingSuggestionList = root.querySelector('.js-service-setting-suggestion-list');
    var summary = root.querySelector('#service-setting-summary');
    var hymnPane = root.querySelector('#service-card-hymns');
    var observanceInput = root.querySelector('#observance_name');
    var observanceIdInput = root.querySelector('#observance_id');
    var observanceSuggestionList = root.querySelector('.js-observance-suggestion-list');
    var latinName = root.querySelector('#observance-latin-name');
    var colorLine = root.querySelector('.service-card-color-line');
    var newObservanceColorWrap = root.querySelector('#new-observance-color-wrap');
    var newObservanceColorSelect = root.querySelector('#new_observance_color');
    var readingsPane = root.querySelector('#service-card-readings');
    var copyToPreviousThursdayToggle = root.querySelector('#copy_to_previous_thursday');
    var thursdayPreacherWrap = root.querySelector('#thursday-preacher-wrap');
    var hymnSuggestionsId = form ? (form.getAttribute('data-hymn-suggestions-id') || 'hymn-options') : 'hymn-options';
    var hymnDefinitionsByService = {};
    var serviceSettingCatalog = { by_id: {}, name_lookup: {} };
    var observanceCatalog = { by_id: {}, name_lookup: {} };
    var dateObservanceSuggestions = [];
    var allObservanceSuggestions = [];
    var selectedReadingSetId = form ? (form.getAttribute('data-selected-reading-set-id') || '') : '';
    var selectedNewReadingSet = form ? (form.getAttribute('data-selected-new-reading-set') || '') : '';
    var smallCatechismOptions = [];
    var passionReadingOptions = [];
    var selectedSmallCatechismLabels = [];
    var selectedPassionReadingId = form ? (form.getAttribute('data-selected-passion-reading-id') || '') : '';
    var readingDraftState = [];
    var hymnState = {
        hymns: {},
        opening_processional: false,
        closing_recessional: false
    };
    var datePreviewTimer = null;

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function bindReadingSelectionBehavior(scope, onChange) {
        var labels = scope.querySelectorAll('.service-card-reading-psalm');

        Array.prototype.forEach.call(labels, function (label) {
            var radio = label.querySelector('.service-card-reading-radio');

            if (!radio) {
                return;
            }

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

    function findObservanceDetailByName(name) {
        var normalizedName = String(name || '').trim().replace(/\s+\((?:Sa|[SMTWRF])\s+\d{1,2}\)\s*$/, '').trim().toLowerCase();
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

    function findServiceSettingDetailByName(name) {
        var normalizedName = String(name || '').trim().toLowerCase();
        var serviceSettingId;

        if (normalizedName === '' || !serviceSettingCatalog.name_lookup) {
            return null;
        }

        serviceSettingId = serviceSettingCatalog.name_lookup[normalizedName];
        if (!serviceSettingId || !serviceSettingCatalog.by_id || !serviceSettingCatalog.by_id[serviceSettingId]) {
            return null;
        }

        return serviceSettingCatalog.by_id[serviceSettingId];
    }

    function isAdventMidweekObservanceName(name) {
        var normalizedName = String(name || '').trim().toLowerCase();
        return normalizedName !== '' && normalizedName.indexOf('advent') !== -1 && normalizedName.indexOf('midweek') !== -1;
    }

    function isLentMidweekObservanceName(name) {
        var normalizedName = String(name || '').trim().toLowerCase();
        return normalizedName !== '' && normalizedName.indexOf('lent') !== -1 && normalizedName.indexOf('midweek') !== -1;
    }

    function buildOptionHtml(options, selectedValue, placeholder) {
        var html = '<option value="">' + escapeHtml(placeholder) + '</option>';

        Array.prototype.forEach.call(options || [], function (option) {
            var value = option && option.id ? String(option.id) : '';
            var label = option && option.label ? String(option.label) : '';

            if (value === '' || label.trim() === '') {
                return;
            }

            html += '<option value="' + escapeHtml(value) + '"' + (selectedValue === value ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
        });

        return html;
    }

    function getReadingSetDefaultId(readingSets) {
        var serviceDateValue = String(serviceDateInput && serviceDateInput.value ? serviceDateInput.value : '').trim();
        var normalizedReadingSets = Array.prototype.filter.call(readingSets || [], function (readingSet) {
            return !!(readingSet && readingSet.id);
        });
        var year;

        if (normalizedReadingSets.length === 0) {
            return '';
        }

        if (normalizedReadingSets.length === 1) {
            return String(normalizedReadingSets[0].id || '');
        }

        if (!/^\d{4}-\d{2}-\d{2}$/.test(serviceDateValue)) {
            return '';
        }

        year = parseInt(serviceDateValue.slice(0, 4), 10);
        if (!Number.isFinite(year)) {
            return '';
        }

        return String(normalizedReadingSets[year % 2 === 0 ? 1 : 0].id || '');
    }

    function getPassionCycleYear() {
        var serviceDateValue = String(serviceDateInput && serviceDateInput.value ? serviceDateInput.value : '').trim();
        var year;

        if (!/^\d{4}-\d{2}-\d{2}$/.test(serviceDateValue)) {
            return null;
        }

        year = parseInt(serviceDateValue.slice(0, 4), 10);
        if (!Number.isFinite(year)) {
            return null;
        }

        return ((year - 2025) % 4 + 4) % 4 + 1;
    }

    function getFilteredPassionReadingOptions() {
        var cycleYear = getPassionCycleYear();
        var filteredOptions;

        if (cycleYear === null) {
            return passionReadingOptions;
        }

        filteredOptions = Array.prototype.filter.call(passionReadingOptions, function (option) {
            return parseInt(option && option.cycle_year ? option.cycle_year : '0', 10) === cycleYear;
        });

        return filteredOptions.length > 0 ? filteredOptions : passionReadingOptions;
    }

    function ensureSmallCatechismRows() {
        if (!Array.isArray(selectedSmallCatechismLabels) || selectedSmallCatechismLabels.length === 0) {
            selectedSmallCatechismLabels = [''];
        }
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

    function rerenderCurrentReadingsPane() {
        var detail = findObservanceDetailByName(observanceInput.value);

        if (!detail) {
            if (String(observanceInput.value || '').trim() === '') {
                renderBlankReadingsPane();
            } else {
                renderNewReadingSetEditor();
            }
            return;
        }

        renderReadingsPane(detail.reading_sets || []);
    }

    function getObservanceSuggestionSource(preferDateSuggestions) {
        var query = String(observanceInput.value || '').trim().toLowerCase();
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
                observanceInput.value = name;
                updateObservanceDetails(true);
                hideObservanceSuggestionOptions();
                observanceInput.focus();
                if (typeof observanceInput.setSelectionRange === 'function') {
                    observanceInput.setSelectionRange(observanceInput.value.length, observanceInput.value.length);
                }
            });

            observanceSuggestionList.appendChild(button);
        });

        observanceSuggestionList.hidden = source.length === 0;
        observanceSuggestionList.classList.toggle('is-visible', source.length > 0);
    }

    function showObservanceSuggestionOptions(preferDateSuggestions) {
        if (!serviceDateInput || String(serviceDateInput.value || '').trim() === '') {
            hideObservanceSuggestionOptions();
            return;
        }

        renderObservanceSuggestionOptions(!!preferDateSuggestions);
    }

    function hideObservanceSuggestionOptions() {
        if (!observanceSuggestionList) {
            return;
        }

        observanceSuggestionList.hidden = true;
        observanceSuggestionList.classList.remove('is-visible');
        observanceSuggestionList.innerHTML = '';
    }

    function queueDatePreview(delay) {
        if (!serviceDateInput) {
            return;
        }

        if (datePreviewTimer !== null) {
            window.clearTimeout(datePreviewTimer);
        }

        datePreviewTimer = window.setTimeout(function () {
            datePreviewTimer = null;
            oflcSubmitPlannerPreview(form, true);
        }, delay);
    }

    function getServiceSettingSuggestionSource(preferAllSuggestions) {
        var query = String(serviceSettingInput.value || '').trim().toLowerCase();
        var allNames = Array.prototype.map.call(Object.keys(serviceSettingCatalog.by_id || {}), function (key) {
            return serviceSettingCatalog.by_id[key] && serviceSettingCatalog.by_id[key].setting_name
                ? serviceSettingCatalog.by_id[key].setting_name
                : '';
        });
        var source = allNames;

        if (!preferAllSuggestions && query !== '') {
            source = Array.prototype.filter.call(allNames, function (name) {
                return String(name || '').toLowerCase().indexOf(query) !== -1;
            });
            if (source.length === 0) {
                source = allNames;
            }
        }

        return Array.prototype.filter.call(source, function (name, index) {
            return String(name || '').trim() !== '' && source.indexOf(name) === index;
        });
    }

    function renderServiceSettingSuggestionOptions(preferAllSuggestions) {
        var source = getServiceSettingSuggestionSource(!!preferAllSuggestions);

        serviceSettingSuggestionList.innerHTML = '';
        Array.prototype.forEach.call(source, function (name) {
            var button = document.createElement('button');

            button.type = 'button';
            button.className = 'service-card-suggestion-item';
            button.textContent = name;
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            button.addEventListener('click', function () {
                serviceSettingInput.value = name;
                updateSummary();
                hideServiceSettingSuggestionOptions();
                serviceSettingInput.focus();
                if (typeof serviceSettingInput.setSelectionRange === 'function') {
                    serviceSettingInput.setSelectionRange(serviceSettingInput.value.length, serviceSettingInput.value.length);
                }
            });

            serviceSettingSuggestionList.appendChild(button);
        });

        serviceSettingSuggestionList.hidden = source.length === 0;
        serviceSettingSuggestionList.classList.toggle('is-visible', source.length > 0);
    }

    function showServiceSettingSuggestionOptions(preferAllSuggestions) {
        renderServiceSettingSuggestionOptions(!!preferAllSuggestions);
    }

    function hideServiceSettingSuggestionOptions() {
        if (!serviceSettingSuggestionList) {
            return;
        }

        serviceSettingSuggestionList.hidden = true;
        serviceSettingSuggestionList.classList.remove('is-visible');
        serviceSettingSuggestionList.innerHTML = '';
    }

    function cloneReadingDrafts() {
        return Array.prototype.map.call(readingDraftState || [], function (draft, index) {
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

    if (!form || !serviceSettingInput || !serviceSettingIdInput || !serviceSettingSuggestionList || !summary || !hymnPane || !observanceInput || !observanceIdInput || !observanceSuggestionList || !newObservanceColorWrap || !newObservanceColorSelect || !readingsPane) {
        return;
    }

    try {
        hymnDefinitionsByService = JSON.parse(form.getAttribute('data-hymn-definitions-by-service') || '{}');
    } catch (error) {
        hymnDefinitionsByService = {};
    }

    try {
        serviceSettingCatalog = JSON.parse(form.getAttribute('data-service-setting-catalog') || '{"by_id":{},"name_lookup":{}}');
    } catch (error) {
        serviceSettingCatalog = { by_id: {}, name_lookup: {} };
    }

    try {
        observanceCatalog = JSON.parse(form.getAttribute('data-observance-catalog') || '{"by_id":{},"name_lookup":{}}');
    } catch (error) {
        observanceCatalog = { by_id: {}, name_lookup: {} };
    }

    try {
        dateObservanceSuggestions = JSON.parse(form.getAttribute('data-date-observance-suggestions') || '[]');
    } catch (error) {
        dateObservanceSuggestions = [];
    }

    allObservanceSuggestions = Array.prototype.map.call(Object.keys(observanceCatalog.by_id || {}), function (key) {
        return observanceCatalog.by_id[key] && observanceCatalog.by_id[key].name ? observanceCatalog.by_id[key].name : '';
    });

    try {
        smallCatechismOptions = JSON.parse(form.getAttribute('data-small-catechism-options') || '[]');
    } catch (error) {
        smallCatechismOptions = [];
    }

    try {
        selectedSmallCatechismLabels = JSON.parse(form.getAttribute('data-selected-small-catechism-labels') || '[]');
    } catch (error) {
        selectedSmallCatechismLabels = [];
    }
    if (!Array.isArray(selectedSmallCatechismLabels) || selectedSmallCatechismLabels.length === 0) {
        selectedSmallCatechismLabels = [''];
    }

    try {
        passionReadingOptions = JSON.parse(form.getAttribute('data-passion-reading-options') || '[]');
    } catch (error) {
        passionReadingOptions = [];
    }

    try {
        readingDraftState = JSON.parse(form.getAttribute('data-initial-reading-editor') || '[]');
    } catch (error) {
        readingDraftState = [];
    }

    try {
        hymnState = JSON.parse(form.getAttribute('data-initial-hymn-state') || '{"hymns":{},"opening_processional":false,"closing_recessional":false}');
    } catch (error) {
        hymnState = {
            hymns: {},
            opening_processional: false,
            closing_recessional: false
        };
    }

    function captureHymnState() {
        var inputs = hymnPane.querySelectorAll('.service-card-hymn-lookup');
        var openingProcessional = document.getElementById('opening_processional');
        var closingRecessional = document.getElementById('closing_recessional');

        Array.prototype.forEach.call(inputs, function (input) {
            hymnState.hymns[input.name.replace('hymn_', '')] = input.value;
        });

        hymnState.opening_processional = !!(openingProcessional && openingProcessional.checked);
        hymnState.closing_recessional = !!(closingRecessional && closingRecessional.checked);
    }

    function bindHymnLookupBehavior(scope) {
        var hymnInputs = scope.querySelectorAll('.service-card-hymn-lookup');

        Array.prototype.forEach.call(hymnInputs, function (input) {
            input.addEventListener('focus', function () {
                input.removeAttribute('list');
            });

            input.addEventListener('input', function () {
                hymnState.hymns[input.name.replace('hymn_', '')] = input.value;

                if (input.value.trim() === '') {
                    input.removeAttribute('list');
                    return;
                }

                input.setAttribute('list', hymnSuggestionsId);
            });

            input.addEventListener('blur', function () {
                window.setTimeout(function () {
                    input.removeAttribute('list');
                }, 0);
            });
        });
    }

    function captureReadingDraftState() {
        var readingDraftInputs = readingsPane.querySelectorAll('.js-new-reading-set-input');

        Array.prototype.forEach.call(readingDraftInputs, function (input) {
            var index = parseInt(input.getAttribute('data-draft-index') || '0', 10);
            var field = input.getAttribute('data-draft-field') || '';
            if (!index || !field || !readingDraftState[index - 1]) {
                return;
            }

            readingDraftState[index - 1][field] = input.value;
        });
    }

    function renderHymnPane(serviceId) {
        var definitions = hymnDefinitionsByService[serviceId] || [];
        var html = '';

        if (definitions.length > 0) {
            html += '<div class="service-card-hymn-instruction">Check boxes mark hymns as procession / recession.</div>';
        }

        Array.prototype.forEach.call(definitions, function (definition) {
            var hymnIndex = String(definition.index);
            var value = hymnState.hymns[hymnIndex] || '';
            var toggleHtml = '';

            if (definition.toggle_name) {
                var isChecked = definition.toggle_name === 'opening_processional'
                    ? hymnState.opening_processional
                    : hymnState.closing_recessional;

                toggleHtml =
                    '<label class="service-card-hymn-inline-toggle" for="' + definition.toggle_name + '">' +
                        '<input type="checkbox" id="' + definition.toggle_name + '" name="' + definition.toggle_name + '" value="1"' + (isChecked ? ' checked' : '') + '>' +
                    '</label>';
            }

            html +=
                '<div class="service-card-hymn-row">' +
                    '<input type="text" id="hymn_' + hymnIndex + '" name="hymn_' + hymnIndex + '" value="' + value.replace(/"/g, '&quot;') + '" placeholder="' + definition.label.replace(/"/g, '&quot;') + '" data-list-id="' + hymnSuggestionsId + '" autocomplete="off" class="service-card-hymn-lookup">' +
                    toggleHtml +
                '</div>';
        });

        hymnPane.innerHTML = html;
        bindHymnLookupBehavior(hymnPane);

        var openingProcessional = document.getElementById('opening_processional');
        var closingRecessional = document.getElementById('closing_recessional');

        if (openingProcessional) {
            openingProcessional.addEventListener('change', function () {
                hymnState.opening_processional = openingProcessional.checked;
            });
        }

        if (closingRecessional) {
            closingRecessional.addEventListener('change', function () {
                hymnState.closing_recessional = closingRecessional.checked;
            });
        }
    }

    function updateSummary() {
        var detail = findServiceSettingDetailByName(serviceSettingInput.value);

        if (!detail || !detail.id) {
            serviceSettingIdInput.value = '';
            summary.innerHTML = '&nbsp;';
            captureHymnState();
            renderHymnPane('');
            return;
        }

        serviceSettingIdInput.value = String(detail.id);

        var abbreviation = String(detail.abbreviation || '').trim();
        var pageNumber = String(detail.page_number || '').trim();
        var text = abbreviation;

        if (pageNumber !== '') {
            text += (text !== '' ? ', ' : '') + 'p. ' + pageNumber;
        }

        summary.textContent = text !== '' ? text : ' ';
        captureHymnState();
        renderHymnPane(String(detail.id));
    }

    function syncThursdayLeaderVisibility() {
        if (!thursdayPreacherWrap) {
            return;
        }

        thursdayPreacherWrap.classList.toggle('is-visible', !!(copyToPreviousThursdayToggle && copyToPreviousThursdayToggle.checked));
    }

    function renderNewReadingSetEditor() {
        var html = '<div class="update-service-reading-editor-note">No appointed readings are stored for this observance yet.</div>';
        var drafts = cloneReadingDrafts();
        var observanceName = String(observanceInput.value || '').trim();
        var showSmallCatechismSelect = isAdventMidweekObservanceName(observanceName) || isLentMidweekObservanceName(observanceName);
        var showPassionReadingSelect = isLentMidweekObservanceName(observanceName);

        selectedReadingSetId = '';
        selectedNewReadingSet = '';
        if (!showSmallCatechismSelect) {
            selectedSmallCatechismLabels = [''];
        } else {
            ensureSmallCatechismRows();
        }
        if (!showPassionReadingSelect) {
            selectedPassionReadingId = '';
        }

        Array.prototype.forEach.call(drafts, function (draft, draftIndex) {
            var index = draftIndex + 1;

            html +=
                    '<div class="service-card-reading-set update-service-reading-editor">' +
                    (showSmallCatechismSelect
                        ? buildSmallCatechismFieldsHtml()
                        : '') +
                    (showPassionReadingSelect
                        ? buildPassionReadingFieldHtml()
                        : '') +
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
        selectedReadingSetId = '';
        selectedNewReadingSet = '';
        selectedSmallCatechismLabels = [''];
        selectedPassionReadingId = '';
        readingsPane.innerHTML = '&nbsp;';
    }

    function renderReadingsPane(readingSets) {
        var html = '';
        var hasSelectedReadingSet = false;
        var defaultReadingSetId = '';
        var observanceName = String(observanceInput.value || '').trim();
        var showSmallCatechismSelect = isAdventMidweekObservanceName(observanceName) || isLentMidweekObservanceName(observanceName);
        var showPassionReadingSelect = isLentMidweekObservanceName(observanceName);

        if (selectedReadingSetId === '') {
            defaultReadingSetId = getReadingSetDefaultId(readingSets);
            if (defaultReadingSetId !== '') {
                selectedReadingSetId = defaultReadingSetId;
            }
        }

        if (!showSmallCatechismSelect) {
            selectedSmallCatechismLabels = [''];
        } else {
            ensureSmallCatechismRows();
            html += buildSmallCatechismFieldsHtml();
        }
        if (!showPassionReadingSelect) {
            selectedPassionReadingId = '';
        } else {
            html += buildPassionReadingFieldHtml();
        }

        Array.prototype.forEach.call(readingSets || [], function (readingSet, index) {
            var classes = 'service-card-reading-set' + (index > 0 ? ' service-card-reading-set-secondary' : '');
            var readingSetId = readingSet && readingSet.id ? String(readingSet.id) : '';
            var isChecked = selectedReadingSetId !== '' && readingSetId === String(selectedReadingSetId);

            if (isChecked) {
                hasSelectedReadingSet = true;
            }

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

        if (html === '') {
            renderNewReadingSetEditor();
            return;
        }

        if (!hasSelectedReadingSet) {
            selectedReadingSetId = '';
        }

        selectedNewReadingSet = '';
        readingsPane.innerHTML = html;
        bindReadingSelectionBehavior(readingsPane, function (value) {
            selectedReadingSetId = value || '';
        });
        bindSupplementalReadingControls();
    }

    function updateObservanceDetails(resetReadingSelection) {
        var observanceName = String(observanceInput.value || '').trim();
        var detail = findObservanceDetailByName(observanceInput.value);

        captureReadingDraftState();
        if (observanceSuggestionList.classList.contains('is-visible')) {
            renderObservanceSuggestionOptions(false);
        }

        if (resetReadingSelection) {
            selectedReadingSetId = '';
            selectedNewReadingSet = '';
            selectedSmallCatechismLabels = [''];
            selectedPassionReadingId = '';
        }

        if (detail && detail.id) {
            observanceIdInput.value = String(detail.id);
        } else {
            observanceIdInput.value = '';
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
                renderBlankReadingsPane();
            } else {
                newObservanceColorWrap.classList.add('is-visible');
                renderNewReadingSetEditor();
            }
            form.className = form.className.replace(/service-card-color-\w+/g, '').trim() + ' service-card-color-dark';
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
        form.className = form.className.replace(/service-card-color-\w+/g, '').trim() + ' ' + (detail.color_class || 'service-card-color-dark');
    }

    serviceSettingInput.addEventListener('input', function () {
        updateSummary();
        showServiceSettingSuggestionOptions(false);
    });
    serviceSettingInput.addEventListener('change', function () {
        updateSummary();
        hideServiceSettingSuggestionOptions();
    });
    serviceSettingInput.addEventListener('focus', function () {
        showServiceSettingSuggestionOptions(true);
    });
    serviceSettingInput.addEventListener('click', function () {
        showServiceSettingSuggestionOptions(true);
    });
    serviceSettingInput.addEventListener('blur', function () {
        window.setTimeout(hideServiceSettingSuggestionOptions, 120);
    });
    observanceInput.addEventListener('input', function () {
        updateObservanceDetails(true);
        showObservanceSuggestionOptions(false);
    });
    observanceInput.addEventListener('change', function () {
        updateObservanceDetails(true);
    });
    observanceInput.addEventListener('focus', function () {
        showObservanceSuggestionOptions(true);
    });
    observanceInput.addEventListener('click', function () {
        showObservanceSuggestionOptions(true);
    });
    observanceInput.addEventListener('blur', function () {
        window.setTimeout(hideObservanceSuggestionOptions, 120);
    });
    if (copyToPreviousThursdayToggle) {
        copyToPreviousThursdayToggle.addEventListener('change', syncThursdayLeaderVisibility);
    }
    if (serviceDateInput) {
        serviceDateInput.addEventListener('change', function () {
            queueDatePreview(0);
        });
        serviceDateInput.addEventListener('input', function () {
            if (/^\d{4}-\d{2}-\d{2}$/.test(String(serviceDateInput.value || '').trim()) || String(serviceDateInput.value || '').trim() === '') {
                queueDatePreview(120);
            }
        });
    }
    newObservanceColorSelect.addEventListener('change', function () {
        if (!newObservanceColorWrap.classList.contains('is-visible')) {
            return;
        }

        form.className = form.className.replace(/service-card-color-\w+/g, '').trim() + ' ' + ({
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
        })[(newObservanceColorSelect.value || '').trim().toLowerCase()] || 'service-card-color-dark';
    });
    bindHymnLookupBehavior(document);
    hideServiceSettingSuggestionOptions();
    hideObservanceSuggestionOptions();
    updateSummary();
    updateObservanceDetails(false);
    syncThursdayLeaderVisibility();
};

window.oflcInitializePlannerUI(document);
</script>

<?php include 'includes/footer.php'; ?>
