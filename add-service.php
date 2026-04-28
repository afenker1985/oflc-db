<?php
declare(strict_types=1);

session_start();

$page_title = 'Add a Service';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/db/service-db-read.php';
require_once __DIR__ . '/includes/db/service-db-write.php';
require_once __DIR__ . '/includes/liturgical.php';
require_once __DIR__ . '/includes/service_observances.php';
require_once __DIR__ . '/includes/service_observance_suggestions.php';
require_once __DIR__ . '/includes/liturgical_colors.php';
require_once __DIR__ . '/includes/hymn_layout.php';

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

function oflc_normalize_stanza_text($value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return trim($value);
}

function oflc_request_stanza_map(array $request_data, string $key): array
{
    $value = $request_data[$key] ?? [];
    if (!is_array($value)) {
        return [];
    }

    $map = [];
    foreach ($value as $row_key => $row_value) {
        $normalized_key = trim((string) $row_key);
        if ($normalized_key === '') {
            continue;
        }

        $map[$normalized_key] = oflc_normalize_stanza_text($row_value);
    }

    return $map;
}

function oflc_get_passion_cycle_year_for_service_date(?DateTimeImmutable $serviceDate): ?int
{
    if (!$serviceDate instanceof DateTimeImmutable) {
        return null;
    }

    $year = (int) $serviceDate->format('Y');
    return (($year - 2025) % 4 + 4) % 4 + 1;
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
    $calendar_day_label = $date instanceof DateTimeImmutable ? $date->format('n/j') : $entry['date'];

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
            return 'service-card-color-scarlet';
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

function oflc_build_leader_catalog_payload(array $leaders): array
{
    $payload = [
        'by_id' => [],
        'name_lookup' => [],
        'suggestions' => [],
    ];

    foreach ($leaders as $leader) {
        $leader_id = (int) ($leader['id'] ?? 0);
        $last_name = trim((string) ($leader['last_name'] ?? ''));
        if ($leader_id <= 0 || $last_name === '') {
            continue;
        }

        $payload['by_id'][$leader_id] = [
            'id' => $leader_id,
            'first_name' => trim((string) ($leader['first_name'] ?? '')),
            'last_name' => $last_name,
        ];

        $normalized_last_name = strtolower($last_name);
        if (!isset($payload['name_lookup'][$normalized_last_name])) {
            $payload['name_lookup'][$normalized_last_name] = $leader_id;
            $payload['suggestions'][] = $last_name;
        }
    }

    return $payload;
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

function oflc_get_copy_service_config(?DateTimeImmutable $serviceDate): ?array
{
    if (!$serviceDate instanceof DateTimeImmutable) {
        return null;
    }

    $weekday = $serviceDate->format('w');

    if ($weekday === '0') {
        return [
            'toggle_name' => 'copy_to_previous_thursday',
            'direction' => 'previous_thursday',
            'primary_key' => 'sunday',
            'secondary_key' => 'thursday',
            'primary_label' => 'Sunday',
            'secondary_label' => 'Thursday',
            'secondary_placeholder' => 'Blank = same as Sunday',
            'secondary_date' => $serviceDate->modify('-3 days')->format('Y-m-d'),
            'secondary_date_label' => $serviceDate->modify('-3 days')->format('m/d'),
            'toggle_label' => 'Copy this service to the previous Thursday',
        ];
    }

    if ($weekday === '4') {
        return [
            'toggle_name' => 'copy_to_upcoming_sunday',
            'direction' => 'upcoming_sunday',
            'primary_key' => 'thursday',
            'secondary_key' => 'sunday',
            'primary_label' => 'Thursday',
            'secondary_label' => 'Sunday',
            'secondary_placeholder' => 'Blank = same as Thursday',
            'secondary_date' => $serviceDate->modify('+3 days')->format('Y-m-d'),
            'secondary_date_label' => $serviceDate->modify('+3 days')->format('m/d'),
            'toggle_label' => 'Copy this service to the upcoming Sunday',
        ];
    }

    return null;
}

function oflc_parse_hymn_row_order(array $request_data): array
{
    $raw = trim((string) ($request_data['hymn_row_order'] ?? ''));
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

function oflc_normalize_extra_hymn_rows(array $request_data): array
{
    $keys = $request_data['extra_hymn_keys'] ?? [];
    $values = $request_data['extra_hymn_values'] ?? [];
    $slots = $request_data['extra_hymn_slots'] ?? [];
    $stanzas = $request_data['extra_hymn_stanzas'] ?? [];

    if (!is_array($keys)) {
        $keys = [$keys];
    }
    if (!is_array($values)) {
        $values = [];
    }
    if (!is_array($slots)) {
        $slots = [];
    }
    if (!is_array($stanzas)) {
        $stanzas = [];
    }

    $rows = [];
    foreach ($keys as $key) {
        $normalized_key = trim((string) $key);
        if ($normalized_key === '') {
            continue;
        }

        $value = trim((string) ($values[$normalized_key] ?? ''));
        $slot_name = trim((string) ($slots[$normalized_key] ?? 'Other Hymn'));
        if ($slot_name !== 'Distribution Hymn' && $slot_name !== 'Other Hymn') {
            $slot_name = 'Other Hymn';
        }

        $rows[] = [
            'key' => $normalized_key,
            'value' => $value,
            'slot_name' => $slot_name,
            'stanzas' => oflc_normalize_stanza_text($stanzas[$normalized_key] ?? ''),
        ];
    }

    return $rows;
}

function oflc_find_definition_index(array $definitions, string $slotName, int $sortOrder = 1): ?int
{
    foreach ($definitions as $definition) {
        if ((string) ($definition['slot_name'] ?? '') === $slotName && (int) ($definition['sort_order'] ?? 1) === $sortOrder) {
            return (int) ($definition['index'] ?? 0);
        }
    }

    return null;
}

function oflc_find_definition_index_by_display_order(array $definitions, int $displaySortOrder): ?int
{
    if ($displaySortOrder <= 0) {
        return null;
    }

    foreach ($definitions as $definition) {
        if ((int) ($definition['index'] ?? 0) === $displaySortOrder) {
            return $displaySortOrder;
        }
    }

    return null;
}

function oflc_normalize_hymn_slot_name(string $slotName): string
{
    if ($slotName === 'Processional Hymn') {
        return 'Opening Hymn';
    }

    if ($slotName === 'Recessional Hymn') {
        return 'Closing Hymn';
    }

    return $slotName;
}

function oflc_build_hymn_editor_state(array $definitions, array $usageRows): array
{
    $hymns = [];
    $order = [];
    foreach ($definitions as $definition) {
        $definitionIndex = (int) ($definition['index'] ?? 0);
        if ($definitionIndex <= 0) {
            continue;
        }

        $hymns[$definitionIndex] = '';
        $order[] = 'base:' . $definitionIndex;
    }

    $state = [
        'hymns' => $hymns,
        'stanzas' => [],
        'extra_rows' => [],
        'order' => $order,
        'next_extra_id' => 1,
    ];
    $slotOccurrenceCounts = [];
    $definitionCount = count($definitions);
    $canRepairOtherHymnRowsByPosition = $definitionCount > 0 && count($usageRows) <= $definitionCount;

    foreach ($usageRows as $row) {
        $slotName = oflc_normalize_hymn_slot_name(trim((string) ($row['slot_name'] ?? '')));
        $displaySortOrder = (int) ($row['sort_order'] ?? 0);
        $label = oflc_service_db_format_hymn_label($row);
        $targetIndex = null;

        if ($label === '') {
            continue;
        }

        if ($slotName !== '' && $slotName !== 'Other Hymn') {
            $slotOccurrenceCounts[$slotName] = ($slotOccurrenceCounts[$slotName] ?? 0) + 1;
            $targetIndex = oflc_find_definition_index($definitions, $slotName, $slotOccurrenceCounts[$slotName]);
            if ($targetIndex === null) {
                $targetIndex = oflc_find_definition_index($definitions, $slotName, 1);
            }
        } elseif ($canRepairOtherHymnRowsByPosition) {
            $targetIndex = oflc_find_definition_index_by_display_order($definitions, $displaySortOrder);
        }

        if ($targetIndex !== null && isset($state['hymns'][$targetIndex]) && $state['hymns'][$targetIndex] === '') {
            $state['hymns'][$targetIndex] = $label;
            $state['stanzas'][$targetIndex] = oflc_normalize_stanza_text($row['stanzas'] ?? '');
            continue;
        }

        $extraKey = 'extra:' . $state['next_extra_id'];
        $state['next_extra_id']++;
        $state['extra_rows'][] = [
            'key' => $extraKey,
            'value' => $label,
            'slot_name' => $slotName === 'Distribution Hymn' ? 'Distribution Hymn' : 'Other Hymn',
            'stanzas' => oflc_normalize_stanza_text($row['stanzas'] ?? ''),
        ];
        if ($displaySortOrder > 0) {
            array_splice(
                $state['order'],
                min(max($displaySortOrder - 1, 0), count($state['order'])),
                0,
                [$extraKey]
            );
        } else {
            $state['order'][] = $extraKey;
        }
    }

    return $state;
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
        && (strpos($normalized_name, 'midweek') !== false || strpos($normalized_name, 'midwk') !== false);
}

function oflc_is_lent_midweek_observance_name(string $name): bool
{
    $normalized_name = strtolower(trim($name));
    return $normalized_name !== ''
        && strpos($normalized_name, 'lent') !== false
        && (strpos($normalized_name, 'midweek') !== false || strpos($normalized_name, 'midwk') !== false);
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
            'toggle_name' => null,
            'toggle_label' => null,
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
            'toggle_name' => null,
            'toggle_label' => null,
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

    for ($index = 1; $index <= 8; $index++) {
        $definitions[] = [
            'index' => $index,
            'label' => $slot_label('Other Hymn', 'Other Hymn') . ' ' . $index,
            'slot_name' => 'Other Hymn',
            'sort_order' => $index,
            'toggle_name' => null,
            'toggle_label' => null,
        ];
    }

    return $definitions;
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
        $preview_service_date = trim((string) ($_POST['preview_service_date'] ?? ''));
        if ($preview_service_date !== '') {
            $request_data['service_date'] = $preview_service_date;
        }
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

$suggested_service_date = oflc_service_db_get_suggested_service_date($pdo);
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
$selected_secondary_preacher = oflc_request_value(
    $request_data,
    'secondary_preacher',
    oflc_request_value($request_data, 'thursday_preacher')
);
$selected_reading_set_id = oflc_request_value($request_data, 'selected_reading_set_id');
$selected_new_reading_set = oflc_request_value($request_data, 'selected_new_reading_set');
$selected_new_observance_color = oflc_request_value($request_data, 'new_observance_color');
$selected_small_catechism_id = oflc_request_value($request_data, 'small_catechism_id');
$selected_passion_reading_id = oflc_request_value($request_data, 'passion_reading_id');
$selected_passion_reading_ids = oflc_request_values($request_data, 'passion_reading_ids');
$selected_small_catechism_labels = oflc_request_values($request_data, 'small_catechism_labels');
$new_reading_sets = oflc_service_normalize_new_reading_set_drafts($request_data);
$extra_hymn_rows = oflc_normalize_extra_hymn_rows($request_data);
$submitted_hymn_row_order = oflc_parse_hymn_row_order($request_data);
$selected_hymns = [];
$selected_hymn_stanzas = oflc_request_stanza_map($request_data, 'hymn_stanzas');
$selected_option_detail = null;
$selected_service_date_obj = DateTimeImmutable::createFromFormat('Y-m-d', $selected_date) ?: null;
$selected_option_is_sunday = $selected_service_date_obj instanceof DateTimeImmutable
    ? $selected_service_date_obj->format('w') === '0'
    : false;
$copy_service_config = oflc_get_copy_service_config($selected_service_date_obj);
$logic_columns_ready = false;
$date_error = null;
$recently_celebrated_observance_ids = [];
$hymn_catalog = oflc_service_db_fetch_hymn_catalog($pdo);
$hymn_suggestions = $hymn_catalog['suggestions'];
$service_settings = oflc_service_db_fetch_service_settings($pdo);
$service_setting_catalog_payload = oflc_build_service_setting_catalog_payload($service_settings);
$small_catechism_options = oflc_service_db_fetch_small_catechism_options($pdo);
$passion_reading_options = oflc_service_db_fetch_passion_reading_options($pdo);
$hymn_slots = oflc_service_db_fetch_hymn_slots($pdo);
$leaders = oflc_service_db_fetch_leaders($pdo);
$leader_catalog_payload = oflc_build_leader_catalog_payload($leaders);
$hymn_fill_templates = [];
$service_settings_by_id = [];
$small_catechism_by_id = [];
$small_catechism_lookup = [];
$passion_reading_by_id = [];
$logic_key_name_map = [];
$date_observance_suggestions = [];
$date_observance_suggestion_lookup = [];
$leaders_by_last_name = [];
$leaders_by_normalized_last_name = [];
$active_observance_details = oflc_service_fetch_active_observance_details($pdo);
$active_observance_details_by_logic_key = oflc_service_index_observance_details_by_logic_key($active_observance_details);
$liturgical_color_options = oflc_get_liturgical_color_options();
$observance_catalog_payload = [
    'by_id' => [],
    'name_lookup' => [],
];

foreach ($service_settings as $service_setting) {
    $service_settings_by_id[(string) $service_setting['id']] = $service_setting;
}

$hymn_fill_templates = oflc_service_db_fetch_hymn_fill_templates(
    $pdo,
    $service_settings_by_id,
    $hymn_slots,
    'oflc_normalize_stanza_text',
    'oflc_build_hymn_field_definitions',
    'oflc_build_hymn_editor_state'
);

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
        $leaders_by_normalized_last_name[strtolower($last_name)] = (int) ($leader['id'] ?? 0);
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

$logic_columns_ready = oflc_service_db_planning_logic_columns_ready($pdo);

if ($selected_date !== '') {
    if ($selected_service_date_obj instanceof DateTimeImmutable) {
        $recently_celebrated_observance_ids = oflc_service_db_fetch_recently_celebrated_observance_ids($pdo, $selected_service_date_obj);
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
        $logic_key_name_map = oflc_service_db_fetch_logic_key_name_map($pdo, $logic_keys_for_names);

        $selected_movable_matches = oflc_service_db_fetch_observances_by_logic_keys(
            $pdo,
            oflc_resolve_movable_logic_keys($liturgical_window['selected']['week'], (int) $liturgical_window['selected']['weekday'])
        );
        $selected_fixed_matches = oflc_service_db_fetch_observances_by_logic_keys(
            $pdo,
            oflc_resolve_fixed_logic_keys((int) $liturgical_window['selected']['month'], (int) $liturgical_window['selected']['day'])
        );

        foreach ($liturgical_window['sunday_options'] as $index => $option) {
            $liturgical_window['sunday_options'][$index]['matches'] = oflc_service_db_fetch_observances_by_logic_keys(
                $pdo,
                oflc_resolve_movable_logic_keys($option['week'], 0)
            );
        }

        foreach ($liturgical_window['entries'] as $index => $entry) {
            $liturgical_window['entries'][$index]['fixed_matches'] = oflc_service_db_fetch_observances_by_logic_keys(
                $pdo,
                oflc_resolve_fixed_logic_keys((int) $entry['month'], (int) $entry['day'])
            );

            if ($entry['is_sunday']) {
                $liturgical_window['entries'][$index]['sunday_matches'] = oflc_service_db_fetch_observances_by_logic_keys(
                    $pdo,
                    oflc_resolve_movable_logic_keys($entry['week'], 0)
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
                $festival_calendar_day_label = $festival_date instanceof DateTimeImmutable ? $festival_date->format('n/j') : $entry['date'];
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

        $date_observance_suggestions = oflc_service_build_date_observance_suggestions($service_option_choices);
        $date_observance_suggestion_lookup = oflc_service_build_observance_suggestion_lookup(
            $service_option_choices,
            $active_observance_details_by_logic_key
        );
    }

    if (ctype_digit($selected_observance_id)) {
        $selected_option_detail = oflc_service_fetch_observance_detail_by_id($pdo, (int) $selected_observance_id);
    }
    if ($selected_option_detail === null && $selected_observance_name !== '') {
        $selected_option_detail = oflc_service_fetch_observance_detail_by_name($pdo, $selected_observance_name);
    }
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
if ($selected_passion_reading_ids === [] && $selected_passion_reading_id !== '') {
    $selected_passion_reading_ids[] = $selected_passion_reading_id;
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
$default_hymn_row_order = array_map(static function (array $definition): string {
    return 'base:' . (int) ($definition['index'] ?? 0);
}, $hymn_field_definitions);
foreach ($extra_hymn_rows as $extra_hymn_row) {
    if (!empty($extra_hymn_row['key'])) {
        $default_hymn_row_order[] = (string) $extra_hymn_row['key'];
    }
}
$submitted_hymn_row_order = array_values(array_filter($submitted_hymn_row_order, static function (string $key): bool {
    return $key !== '';
}));
if ($submitted_hymn_row_order === []) {
    $submitted_hymn_row_order = $default_hymn_row_order;
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
    $normalized_primary_preacher = strtolower(trim($selected_preacher));
    if ($normalized_primary_preacher !== '') {
        if (!isset($leaders_by_normalized_last_name[$normalized_primary_preacher]) || (int) $leaders_by_normalized_last_name[$normalized_primary_preacher] <= 0) {
            $form_errors[] = 'Leader must match an active last name.';
        } else {
            $leader_id = (int) $leaders_by_normalized_last_name[$normalized_primary_preacher];
        }
    }

    $copy_service_enabled = $copy_service_config !== null
        && isset($request_data[(string) ($copy_service_config['toggle_name'] ?? '')]);
    $secondary_leader_id = null;
    $normalized_secondary_preacher = strtolower(trim($selected_secondary_preacher));
    if ($copy_service_enabled && $normalized_secondary_preacher !== '') {
        if (!isset($leaders_by_normalized_last_name[$normalized_secondary_preacher]) || (int) $leaders_by_normalized_last_name[$normalized_secondary_preacher] <= 0) {
            $secondary_label = trim((string) ($copy_service_config['secondary_label'] ?? 'Secondary leader'));
            $form_errors[] = $secondary_label . ' must match an active last name.';
        } else {
            $secondary_leader_id = (int) $leaders_by_normalized_last_name[$normalized_secondary_preacher];
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
    $draft_one = $new_reading_sets[1] ?? null;
    foreach ($new_reading_sets as $draft) {
        if (!empty($draft['has_content'])) {
            $has_draft_readings = true;
            break;
        }
    }
    $observance_has_readings = $persisted_observance_detail !== null && count($persisted_observance_detail['reading_sets'] ?? []) > 0;
    if (!$observance_has_readings && $has_draft_readings) {
        if (!is_array($draft_one) || empty($draft_one['has_content'])) {
            $form_errors[] = 'Enter readings for the new observance.';
        }
    }
    if ($observance_has_readings && $has_draft_readings && $resolved_selected_reading_set_id === null) {
        $form_errors[] = 'Select a valid reading set before editing readings.';
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

    $passion_reading_ids = [];
    foreach ($selected_passion_reading_ids as $selected_passion_reading_value) {
        if (!ctype_digit($selected_passion_reading_value) || !isset($passion_reading_by_id[(int) $selected_passion_reading_value])) {
            $form_errors[] = 'Select a valid passion reading.';
            break;
        }

        $passion_reading_ids[] = (int) $selected_passion_reading_value;
    }
    $passion_reading_ids = array_values(array_unique(array_filter($passion_reading_ids, static function (int $id): bool {
        return $id > 0;
    })));
    $passion_reading_id = $passion_reading_ids !== [] ? $passion_reading_ids[0] : null;

    $submitted_observance_name = trim((string) ($persisted_observance_detail['observance']['name'] ?? $observance_name));
    $is_advent_midweek = oflc_is_advent_midweek_observance_name($submitted_observance_name);
    $is_lent_midweek = oflc_is_lent_midweek_observance_name($submitted_observance_name);

    if (!$is_advent_midweek && !$is_lent_midweek) {
        $small_catechism_id = null;
        $small_catechism_ids = [];
        $passion_reading_id = null;
        $passion_reading_ids = [];
    } elseif (!$is_lent_midweek) {
        $passion_reading_id = null;
        $passion_reading_ids = [];
    }

    $base_hymn_rows = [];
    foreach ($hymn_field_definitions as $definition) {
        $index = (int) ($definition['index'] ?? 0);
        if ($index <= 0) {
            continue;
        }

        $slot_name = (string) ($definition['slot_name'] ?? '');

        $base_hymn_rows['base:' . $index] = [
            'label' => (string) ($definition['label'] ?? ('Hymn ' . $index)),
            'value' => $selected_hymns[$index] ?? '',
            'slot_name' => $slot_name,
            'stanzas' => $selected_hymn_stanzas[(string) $index] ?? '',
        ];
    }

    $extra_hymn_rows_by_key = [];
    foreach ($extra_hymn_rows as $extra_hymn_row) {
        $extra_key = trim((string) ($extra_hymn_row['key'] ?? ''));
        if ($extra_key === '') {
            continue;
        }

        $extra_hymn_rows_by_key[$extra_key] = [
            'label' => 'Additional hymn',
            'value' => trim((string) ($extra_hymn_row['value'] ?? '')),
            'slot_name' => trim((string) ($extra_hymn_row['slot_name'] ?? 'Other Hymn')),
            'stanzas' => oflc_normalize_stanza_text($extra_hymn_row['stanzas'] ?? ''),
        ];
    }

    $ordered_hymn_row_keys = $submitted_hymn_row_order;
    if ($ordered_hymn_row_keys === []) {
        $ordered_hymn_row_keys = array_merge(array_keys($base_hymn_rows), array_keys($extra_hymn_rows_by_key));
    }

    $canonical_hymn_rows = oflc_hymn_layout_build_canonical_rows(
        $hymn_field_definitions,
        $base_hymn_rows,
        $extra_hymn_rows_by_key,
        $ordered_hymn_row_keys
    );

    $hymn_entries = [];
    $definitions_by_index = [];
    foreach ($hymn_field_definitions as $definition) {
        $definition_index = (int) ($definition['index'] ?? 0);
        if ($definition_index > 0) {
            $definitions_by_index[$definition_index] = $definition;
        }
    }

    foreach ($canonical_hymn_rows as $display_position => $row) {
        $row_key = (string) ($row['key'] ?? '');
        $hymn_value = trim((string) ($row['value'] ?? ''));
        if ($hymn_value === '') {
            continue;
        }

        $hymn_id = oflc_resolve_hymn_id($hymn_value, $hymn_catalog['lookup_by_key']);
        if ($hymn_id === null) {
            $form_errors[] = trim((string) ($row['label'] ?? 'Hymn')) . ' must match a hymn from the suggestions.';
            continue;
        }

        $slot_name = trim((string) ($row['slot_name'] ?? ''));
        if (strpos($row_key, 'base:') === 0) {
            $definition_index = (int) substr($row_key, 5);
            $definition_for_position = $definitions_by_index[$definition_index] ?? null;

            if (is_array($definition_for_position)) {
                $slot_name = trim((string) ($definition_for_position['slot_name'] ?? $slot_name));
            }
        }

        if (!isset($hymn_slots[$slot_name]['id'])) {
            $form_errors[] = 'Missing hymn slot configuration for ' . $slot_name . '.';
            continue;
        }

        $hymn_entries[] = [
            'hymn_id' => $hymn_id,
            'slot_id' => (int) $hymn_slots[$slot_name]['id'],
            'sort_order' => $display_position + 1,
            'stanzas' => oflc_normalize_stanza_text($row['stanzas'] ?? ''),
        ];
    }

    if ($selected_service_date_obj === false || !$selected_service_date_obj instanceof DateTimeImmutable) {
        $form_errors[] = 'Service date must use YYYY-MM-DD.';
    }

    $target_dates = [];
    if ($selected_service_date_obj instanceof DateTimeImmutable) {
        if ($copy_service_config !== null) {
            $target_dates[(string) $copy_service_config['primary_key']] = $selected_service_date_obj->format('Y-m-d');
            if ($copy_service_enabled) {
                $target_dates[(string) $copy_service_config['secondary_key']] = (string) ($copy_service_config['secondary_date'] ?? '');
            }
        } else {
            $target_dates['sunday'] = $selected_service_date_obj->format('Y-m-d');
        }

        foreach ($target_dates as $target_date) {
            if (oflc_service_db_has_active_service_conflict($pdo, $target_date)) {
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
            } elseif ($has_draft_readings && is_array($draft_one) && $resolved_selected_reading_set_id !== null) {
                oflc_service_db_update_existing_reading_set(
                    $pdo,
                    $resolved_selected_reading_set_id,
                    (int) ($persisted_observance_detail['observance']['id'] ?? 0),
                    $draft_one
                );
            }

            if ($resolved_selected_reading_set_id === null && count($inserted_reading_set_ids) === 1) {
                $resolved_selected_reading_set_id = (int) reset($inserted_reading_set_ids);
            }

            $liturgical_calendar_id = (int) ($persisted_observance_detail['observance']['id'] ?? 0) ?: null;

            $insertServiceWithHymns = static function (
                string $serviceDate,
                ?int $leaderId,
                ?int $copiedFromServiceId
            ) use (
                $liturgical_calendar_id,
                $passion_reading_id,
                $passion_reading_ids,
                $small_catechism_id,
                $resolved_selected_reading_set_id,
                $service_setting_id,
                $today,
                $small_catechism_ids,
                $hymn_entries,
                $pdo
            ): int {
                $serviceId = oflc_service_db_insert_service($pdo, [
                    'service_date' => $serviceDate,
                    'liturgical_calendar_id' => $liturgical_calendar_id,
                    'passion_reading_id' => $passion_reading_id,
                    'small_catechism_id' => $small_catechism_id,
                    'selected_reading_set_id' => $resolved_selected_reading_set_id,
                    'service_setting_id' => $service_setting_id,
                    'leader_id' => $leaderId,
                    'copied_from_service_id' => $copiedFromServiceId,
                    'last_updated' => $today,
                ]);

                oflc_service_db_insert_service_small_catechism_links($pdo, $serviceId, $small_catechism_ids, $today);
                oflc_service_db_insert_service_passion_reading_links($pdo, $serviceId, $passion_reading_ids, $today);
                oflc_service_db_insert_hymn_usage_rows($pdo, $serviceId, $hymn_entries, $today);

                return $serviceId;
            };

            if ($copy_service_enabled && isset($target_dates['thursday']) && isset($target_dates['sunday'])) {
                if (($copy_service_config['primary_key'] ?? '') === 'thursday') {
                    $sunday_service_id = $insertServiceWithHymns(
                        (string) $target_dates['sunday'],
                        $secondary_leader_id ?? $leader_id,
                        null
                    );
                    $insertServiceWithHymns(
                        (string) $target_dates['thursday'],
                        $leader_id,
                        $sunday_service_id
                    );
                } else {
                    $sunday_service_id = $insertServiceWithHymns(
                        (string) $target_dates['sunday'],
                        $leader_id,
                        null
                    );
                    $insertServiceWithHymns(
                        (string) $target_dates['thursday'],
                        $secondary_leader_id ?? $leader_id,
                        $sunday_service_id
                    );
                }
            } else {
                $primary_service_date = $selected_service_date_obj->format('Y-m-d');
                $insertServiceWithHymns($primary_service_date, $leader_id, null);
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
        data-observance-suggestion-lookup="<?php echo htmlspecialchars(json_encode($date_observance_suggestion_lookup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-initial-suggested-date="<?php echo htmlspecialchars($suggested_service_date, ENT_QUOTES, 'UTF-8'); ?>"
        data-selected-reading-set-id="<?php echo htmlspecialchars((string) $selected_reading_set_id, ENT_QUOTES, 'UTF-8'); ?>"
        data-selected-new-reading-set="<?php echo htmlspecialchars((string) $selected_new_reading_set, ENT_QUOTES, 'UTF-8'); ?>"
        data-initial-reading-editor="<?php echo htmlspecialchars(json_encode(array_values($new_reading_sets), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-small-catechism-options="<?php echo htmlspecialchars(json_encode(array_values($small_catechism_options), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-passion-reading-options="<?php echo htmlspecialchars(json_encode(array_values($passion_reading_options), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-leader-catalog="<?php echo htmlspecialchars(json_encode($leader_catalog_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-hymn-fill-templates="<?php echo htmlspecialchars(json_encode($hymn_fill_templates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-selected-small-catechism-labels="<?php echo htmlspecialchars(json_encode(array_values($selected_small_catechism_labels), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-selected-passion-reading-ids="<?php echo htmlspecialchars(json_encode(array_values($selected_passion_reading_ids), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
        data-selected-passion-reading-id="<?php echo htmlspecialchars((string) $selected_passion_reading_id, ENT_QUOTES, 'UTF-8'); ?>"
        data-initial-hymn-state="<?php echo htmlspecialchars(json_encode([
            'hymns' => array_map('strval', $selected_hymns),
            'stanzas' => array_map('strval', $selected_hymn_stanzas),
            'extra_rows' => array_values($extra_hymn_rows),
            'order' => array_values($submitted_hymn_row_order),
            'next_extra_id' => count($extra_hymn_rows) + 1,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
    >
        <input type="hidden" name="auto_preview" id="auto-preview-flag" value="">
        <input type="hidden" name="preview_service_date" id="preview_service_date" value="<?php echo htmlspecialchars($selected_date, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="hymn_row_order" id="hymn_row_order" value="<?php echo htmlspecialchars(json_encode(array_values($submitted_hymn_row_order), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
        <?php if ($hymn_suggestions !== []): ?>
            <datalist id="hymn-options">
                <?php foreach ($hymn_suggestions as $suggestion): ?>
                    <option value="<?php echo htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8'); ?>"></option>
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
                    <?php if ($copy_service_config !== null): ?>
                        <label class="service-card-checkbox">
                            <input type="checkbox" id="<?php echo htmlspecialchars((string) $copy_service_config['toggle_name'], ENT_QUOTES, 'UTF-8'); ?>" name="<?php echo htmlspecialchars((string) $copy_service_config['toggle_name'], ENT_QUOTES, 'UTF-8'); ?>" value="1"<?php echo isset($request_data[(string) $copy_service_config['toggle_name']]) ? ' checked' : ''; ?>>
                            <span><?php echo htmlspecialchars((string) $copy_service_config['toggle_label'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string) $copy_service_config['secondary_date_label'], ENT_QUOTES, 'UTF-8'); ?>)?</span>
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
                        <div class="service-card-hymn-instruction">Click "s" to input stanzas for a hymn.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="service-card-panel">
                <?php
                $show_secondary_preacher = $copy_service_config !== null && isset($request_data[(string) $copy_service_config['toggle_name']]);
                $show_secondary_first = $copy_service_config !== null && ($copy_service_config['primary_key'] ?? '') === 'sunday';
                ?>
                <?php if ($show_secondary_first): ?>
                    <div class="service-card-optional-field<?php echo $show_secondary_preacher ? ' is-visible' : ''; ?>" id="secondary-preacher-wrap">
                        <label class="service-card-label" for="secondary_preacher"><?php echo htmlspecialchars((string) ($copy_service_config['secondary_label'] ?? 'Second Leader'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <div class="service-card-suggestion-anchor">
                            <input type="text" id="secondary_preacher" name="secondary_preacher" class="service-card-text" value="<?php echo htmlspecialchars($selected_secondary_preacher, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars((string) ($copy_service_config['secondary_placeholder'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                            <div class="service-card-suggestion-list service-card-suggestion-list-fixed js-secondary-leader-suggestion-list" hidden></div>
                        </div>
                    </div>
                <?php endif; ?>

                <label class="service-card-label" for="preacher"><?php echo htmlspecialchars((string) ($copy_service_config['primary_label'] ?? 'Leader'), ENT_QUOTES, 'UTF-8'); ?></label>
                <div class="service-card-suggestion-anchor">
                    <input type="text" id="preacher" name="preacher" class="service-card-text" value="<?php echo htmlspecialchars($selected_preacher, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Fenker" autocomplete="off">
                    <div class="service-card-suggestion-list service-card-suggestion-list-fixed js-leader-suggestion-list" hidden></div>
                </div>

                <?php if (!$show_secondary_first): ?>
                    <div class="service-card-optional-field<?php echo $show_secondary_preacher ? ' is-visible' : ''; ?>" id="secondary-preacher-wrap">
                        <label class="service-card-label" for="secondary_preacher"><?php echo htmlspecialchars((string) ($copy_service_config['secondary_label'] ?? 'Second Leader'), ENT_QUOTES, 'UTF-8'); ?></label>
                        <div class="service-card-suggestion-anchor">
                            <input type="text" id="secondary_preacher" name="secondary_preacher" class="service-card-text" value="<?php echo htmlspecialchars($selected_secondary_preacher, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars((string) ($copy_service_config['secondary_placeholder'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                            <div class="service-card-suggestion-list service-card-suggestion-list-fixed js-secondary-leader-suggestion-list" hidden></div>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </form>
    <div class="service-card-actions">
        <div class="service-card-actions-fill" id="fill-hymns-controls">
            <div class="service-card-fill-row">
                <input type="hidden" id="fill_hymns_service_id" value="">
                <div class="service-card-suggestion-anchor service-card-fill-anchor">
                    <button type="button" id="fill_hymns_service_label" class="service-card-selectlike">
                        <span class="service-card-selectlike-label">Select matching service</span>
                        <span class="service-card-selectlike-arrow" aria-hidden="true">&#9662;</span>
                    </button>
                    <div class="service-card-suggestion-list service-card-suggestion-list-capped-three js-fill-hymns-suggestion-list" hidden></div>
                </div>
                <button type="button" class="fill-hymns-button" id="fill_hymns_button">Fill Hymns</button>
                <button type="button" class="clear-list-button" id="clear_hymns_button">Clear Hymns</button>
            </div>
            <div class="service-card-fill-note">Fill hymns from a previous year.</div>
        </div>
        <div class="service-card-actions-buttons">
            <button type="button" class="clear-list-button" id="clear_service_button" onclick="oflcClearPlanner(document.getElementById('add-service-form'));">Clear Service</button>
            <button type="submit" name="add_service" value="1" class="add-hymn-button" id="add_service_button" form="add-service-form">Add Service</button>
        </div>
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

<script src="js/service-observance.js"></script>
<script src="js/service-observance-dropdown.js"></script>
<script>
function oflcResetReadingSelection(form) {
    var radios = form.querySelectorAll('.service-card-reading-radio');

    Array.prototype.forEach.call(radios, function (radio) {
        radio.checked = false;
    });
}

function oflcSubmitPlannerPreview(form, resetReadings, overrides) {
    var autoPreview = form.querySelector('#auto-preview-flag');
    var formData;

    if (resetReadings) {
        oflcResetReadingSelection(form);
    }

    if (autoPreview) {
        autoPreview.value = '1';
    }

    formData = new FormData(form);
    Object.keys(overrides || {}).forEach(function (key) {
        formData.set(key, overrides[key]);
    });

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

function oflcSubmitPlannerRefresh(form) {
    var autoPreview = form.querySelector('#auto-preview-flag');

    oflcResetReadingSelection(form);
    if (autoPreview) {
        autoPreview.value = '';
    }

    form.submit();
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

var hymnLookupByKey = <?php echo json_encode($hymn_catalog['lookup_by_key'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
var hymnTunesById = <?php echo json_encode($hymn_catalog['tune_by_id'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

window.oflcInitializePlannerUI = function (root) {
    var form = root.querySelector('#add-service-form');
    var serviceDateInput = root.querySelector('#service_date');
    var previewServiceDateInput = root.querySelector('#preview_service_date');
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
    var copyServiceToggle = root.querySelector('#copy_to_previous_thursday') || root.querySelector('#copy_to_upcoming_sunday');
    var primaryLeaderInput = root.querySelector('#preacher');
    var primaryLeaderSuggestionList = root.querySelector('.js-leader-suggestion-list');
    var secondaryLeaderInput = root.querySelector('#secondary_preacher');
    var secondaryLeaderSuggestionList = root.querySelector('.js-secondary-leader-suggestion-list');
    var secondaryPreacherWrap = root.querySelector('#secondary-preacher-wrap');
    var hymnRowOrderInput = root.querySelector('#hymn_row_order');
    var fillHymnsIdInput = root.querySelector('#fill_hymns_service_id');
    var fillHymnsLabelInput = root.querySelector('#fill_hymns_service_label');
    var fillHymnsSuggestionList = root.querySelector('.js-fill-hymns-suggestion-list');
    var fillHymnsButton = root.querySelector('#fill_hymns_button');
    var clearHymnsButton = root.querySelector('#clear_hymns_button');
    var clearServiceButton = root.querySelector('#clear_service_button');
    var addServiceButton = root.querySelector('#add_service_button');
    var hymnSuggestionsId = form ? (form.getAttribute('data-hymn-suggestions-id') || 'hymn-options') : 'hymn-options';
    var hymnDefinitionsByService = {};
    var serviceSettingCatalog = { by_id: {}, name_lookup: {} };
    var leaderCatalog = { by_id: {}, name_lookup: {}, suggestions: [] };
    var observanceCatalog = { by_id: {}, name_lookup: {} };
    var hymnFillTemplates = [];
    var matchingHymnFillTemplates = [];
    var dateObservanceSuggestions = [];
    var allObservanceSuggestions = [];
    var observanceSuggestionLookup = {};
    var observanceDropdown = null;
    var selectedReadingSetId = form ? (form.getAttribute('data-selected-reading-set-id') || '') : '';
    var selectedNewReadingSet = form ? (form.getAttribute('data-selected-new-reading-set') || '') : '';
    var smallCatechismOptions = [];
    var passionReadingOptions = [];
    var selectedSmallCatechismLabels = [];
    var selectedPassionReadingIds = [];
    var readingDraftState = [];
    var hymnState = {
        hymns: {},
        stanzas: {},
        extra_rows: [],
        order: [],
        next_extra_id: 1
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

    function resolveObservanceDetail(name) {
        if (window.oflcObservanceUi && typeof window.oflcObservanceUi.resolveDetail === 'function') {
            return window.oflcObservanceUi.resolveDetail(name, observanceSuggestionLookup, observanceCatalog);
        }

        return findObservanceDetailByName(name);
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
        return normalizedName !== ''
            && normalizedName.indexOf('advent') !== -1
            && (normalizedName.indexOf('midweek') !== -1 || normalizedName.indexOf('midwk') !== -1);
    }

    function isLentMidweekObservanceName(name) {
        var normalizedName = String(name || '').trim().toLowerCase();
        return normalizedName !== ''
            && normalizedName.indexOf('lent') !== -1
            && (normalizedName.indexOf('midweek') !== -1 || normalizedName.indexOf('midwk') !== -1);
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
        if (!Array.isArray(selectedPassionReadingIds) || selectedPassionReadingIds.length === 0) {
            selectedPassionReadingIds = [''];
        }

        return Array.prototype.map.call(selectedPassionReadingIds, function (selectedValue, index) {
            return '<div class="service-card-inline-field-row">' +
                '<select class="service-card-select update-service-reading-input js-passion-reading-select" name="passion_reading_ids[]">' +
                    buildOptionHtml(getFilteredPassionReadingOptions(), selectedValue, 'Select passion reading') +
                '</select>' +
                (index === selectedPassionReadingIds.length - 1
                    ? '<button type="button" class="service-card-add-inline-button js-passion-reading-add">+</button>'
                    : '') +
            '</div>';
        }).join('');
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

        Array.prototype.forEach.call(readingsPane.querySelectorAll('.js-passion-reading-select'), function (input, index) {
            input.addEventListener('change', function () {
                selectedPassionReadingIds[index] = input.value;
            });
        });
        Array.prototype.forEach.call(readingsPane.querySelectorAll('.js-passion-reading-add'), function (button) {
            button.addEventListener('click', function () {
                selectedPassionReadingIds.push('');
                rerenderCurrentReadingsPane();
            });
        });
    }

    function rerenderCurrentReadingsPane() {
        var detail = resolveObservanceDetail(observanceInput.value);

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
        return observanceDropdown ? observanceDropdown.getSource(preferDateSuggestions) : [];
    }

    function renderObservanceSuggestionOptions(preferDateSuggestions) {
        if (observanceDropdown) {
            observanceDropdown.render(preferDateSuggestions);
        }
    }

    function showObservanceSuggestionOptions(preferDateSuggestions) {
        if (observanceDropdown) {
            observanceDropdown.show(preferDateSuggestions);
        }
    }

    function hideObservanceSuggestionOptions() {
        if (observanceDropdown) {
            observanceDropdown.hide();
        }
    }

    function chooseObservanceSuggestion(name) {
        if (observanceDropdown) {
            observanceDropdown.choose(name);
        }
    }

    function bindObservanceSuggestionList() {
        if (observanceDropdown) {
            observanceDropdown.bind();
        }
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
            button.setAttribute('data-service-setting-suggestion', name);

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

    function chooseServiceSettingSuggestion(name) {
        var detail = findServiceSettingDetailByName(name);

        serviceSettingInput.value = name;
        serviceSettingIdInput.value = detail && detail.id ? String(detail.id) : '';
        hideServiceSettingSuggestionOptions();
        updateSummary();
    }

    function bindServiceSettingSuggestionList() {
        if (!serviceSettingSuggestionList || serviceSettingSuggestionList.dataset.bound === '1') {
            return;
        }

        serviceSettingSuggestionList.dataset.bound = '1';
        serviceSettingSuggestionList.addEventListener('mousedown', function (event) {
            var button = event.target.closest('[data-service-setting-suggestion]');
            if (!button) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            chooseServiceSettingSuggestion(button.getAttribute('data-service-setting-suggestion') || '');
        });
        serviceSettingSuggestionList.addEventListener('click', function (event) {
            if (event.target.closest('[data-service-setting-suggestion]')) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
    }

    function cloneReadingDrafts() {
        return Array.prototype.map.call(readingDraftState || [], function (draft, index) {
            return {
                index: draft && draft.index ? draft.index : index + 1,
                old_testament: draft && draft.old_testament ? draft.old_testament : '',
                psalm: draft && draft.psalm ? draft.psalm : '',
                epistle: draft && draft.epistle ? draft.epistle : '',
                gospel: draft && draft.gospel ? draft.gospel : ''
            };
        });
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
        leaderCatalog = JSON.parse(form.getAttribute('data-leader-catalog') || '{"by_id":{},"name_lookup":{},"suggestions":[]}');
    } catch (error) {
        leaderCatalog = { by_id: {}, name_lookup: {}, suggestions: [] };
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
    try {
        observanceSuggestionLookup = JSON.parse(form.getAttribute('data-observance-suggestion-lookup') || '{}');
    } catch (error) {
        observanceSuggestionLookup = {};
    }
    observanceDropdown = window.oflcCreateObservanceDropdown({
        input: observanceInput,
        hiddenInput: observanceIdInput,
        list: observanceSuggestionList,
        serviceDateInput: serviceDateInput,
        dateSuggestions: dateObservanceSuggestions,
        allSuggestions: allObservanceSuggestions,
        requireDate: true,
        resolveDetail: resolveObservanceDetail,
        onInput: function () {
            updateObservanceDetails(true);
        },
        onChange: function () {
            updateObservanceDetails(true);
        },
        onSelect: function (selection) {
            if (previewServiceDateInput) {
                previewServiceDateInput.value = selection.serviceDate;
            }
            oflcSubmitPlannerPreview(form, true, {
                service_date: selection.serviceDate,
                preview_service_date: selection.serviceDate,
                observance_name: selection.name,
                observance_id: selection.observanceId
            });
        }
    });

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
        selectedPassionReadingIds = JSON.parse(form.getAttribute('data-selected-passion-reading-ids') || '[]');
    } catch (error) {
        selectedPassionReadingIds = [];
    }
    if ((!Array.isArray(selectedPassionReadingIds) || selectedPassionReadingIds.length === 0) && form.getAttribute('data-selected-passion-reading-id')) {
        selectedPassionReadingIds = [form.getAttribute('data-selected-passion-reading-id')];
    }

    try {
        readingDraftState = JSON.parse(form.getAttribute('data-initial-reading-editor') || '[]');
    } catch (error) {
        readingDraftState = [];
    }

    try {
        hymnFillTemplates = JSON.parse(form.getAttribute('data-hymn-fill-templates') || '[]');
    } catch (error) {
        hymnFillTemplates = [];
    }

    try {
        hymnState = JSON.parse(form.getAttribute('data-initial-hymn-state') || '{"hymns":{},"stanzas":{},"extra_rows":[],"order":[],"next_extra_id":1}');
    } catch (error) {
        hymnState = {
            hymns: {},
            stanzas: {},
            extra_rows: [],
            order: [],
            next_extra_id: 1
        };
    }

    var activeStanzaRowKey = '';
    var stanzaModal = null;
    var stanzaModalTitle = null;
    var stanzaModalInput = null;

    function normalizeStanzaText(value) {
        return String(value || '').trim().replace(/\s+/g, ' ');
    }

    function getLeaderSuggestionSource(query) {
        var normalizedQuery = String(query || '').trim().toLowerCase();
        var source = Array.isArray(leaderCatalog.suggestions) ? leaderCatalog.suggestions.slice() : [];

        if (normalizedQuery !== '') {
            source = source.filter(function (name) {
                return String(name || '').toLowerCase().indexOf(normalizedQuery) !== -1;
            });
        }

        return source.filter(function (name, index) {
            return String(name || '').trim() !== '' && source.indexOf(name) === index;
        });
    }

    function bindSuggestionButtonSelection(button, onSelect) {
        button.addEventListener('mousedown', function (event) {
            event.preventDefault();
            onSelect();
        });
        button.addEventListener('click', function (event) {
            event.preventDefault();
        });
    }

    function renderSimpleSuggestionOptions(input, list, options, onSelect) {
        if (!list) {
            return;
        }

        list.innerHTML = '';
        Array.prototype.forEach.call(options, function (name) {
            var button = document.createElement('button');

            button.type = 'button';
            button.className = 'service-card-suggestion-item';
            button.textContent = name;
            bindSuggestionButtonSelection(button, function () {
                onSelect(name);
                list.hidden = true;
                list.classList.remove('is-visible');
                list.innerHTML = '';
                input.focus();
                if (typeof input.setSelectionRange === 'function') {
                    input.setSelectionRange(input.value.length, input.value.length);
                }
            });

            list.appendChild(button);
        });

        list.hidden = options.length === 0;
        list.classList.toggle('is-visible', options.length > 0);
    }

    function bindLeaderSuggestionInput(input, list) {
        if (!input || !list) {
            return;
        }

        function showSuggestions(preferAll) {
            var query = preferAll ? '' : input.value;
            renderSimpleSuggestionOptions(input, list, getLeaderSuggestionSource(query), function (name) {
                input.value = name;
            });
        }

        input.addEventListener('input', function () {
            showSuggestions(false);
        });
        input.addEventListener('focus', function () {
            showSuggestions(true);
        });
        input.addEventListener('click', function () {
            showSuggestions(true);
        });
        input.addEventListener('blur', function () {
            window.setTimeout(function () {
                list.hidden = true;
                list.classList.remove('is-visible');
                list.innerHTML = '';
            }, 120);
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
        if (!hymnState.stanzas || typeof hymnState.stanzas !== 'object') {
            hymnState.stanzas = {};
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
                slot_name: row.slot_name === 'Distribution Hymn' ? 'Distribution Hymn' : 'Other Hymn',
                stanzas: normalizeStanzaText(row.stanzas || '')
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
            if (baseKeys.indexOf(key) !== -1 || extraKeyMap[key]) {
                if (order.indexOf(key) === -1) {
                    order.push(key);
                }
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

    function findHymnDefinitionIndex(definitions, slotName, sortOrder) {
        var match = null;

        Array.prototype.some.call(definitions || [], function (definition) {
            if (String(definition && definition.slot_name ? definition.slot_name : '') !== String(slotName || '')) {
                return false;
            }
            if (parseInt(definition && definition.sort_order ? definition.sort_order : '1', 10) !== parseInt(sortOrder || '1', 10)) {
                return false;
            }

            match = parseInt(definition && definition.index ? definition.index : '0', 10) || 0;
            return true;
        });

        return match && match > 0 ? match : null;
    }

    function findHymnDefinitionIndexByDisplayOrder(definitions, displaySortOrder) {
        var requestedOrder = parseInt(displaySortOrder || '0', 10) || 0;
        var match = null;

        if (requestedOrder <= 0) {
            return null;
        }

        Array.prototype.some.call(definitions || [], function (definition) {
            if ((parseInt(definition && definition.index ? definition.index : '0', 10) || 0) !== requestedOrder) {
                return false;
            }

            match = requestedOrder;
            return true;
        });

        return match;
    }

    function normalizeTemplateHymnSlotName(slotName) {
        if (slotName === 'Processional Hymn') {
            return 'Opening Hymn';
        }

        if (slotName === 'Recessional Hymn') {
            return 'Closing Hymn';
        }

        return slotName;
    }

    function buildHymnStateFromUsageRows(definitions, usageRows) {
        var nextState = {
            hymns: {},
            stanzas: {},
            extra_rows: [],
            order: [],
            next_extra_id: 1
        };
        var slotOccurrenceCounts = {};
        var canRepairOtherHymnRowsByPosition = Array.isArray(definitions)
            && definitions.length > 0
            && Array.isArray(usageRows)
            && usageRows.length <= definitions.length;

        Array.prototype.forEach.call(definitions || [], function (definition) {
            var definitionIndex = parseInt(definition && definition.index ? definition.index : '0', 10) || 0;

            if (definitionIndex <= 0) {
                return;
            }

            nextState.hymns[String(definitionIndex)] = '';
            nextState.order.push('base:' + String(definitionIndex));
        });

        Array.prototype.forEach.call(usageRows || [], function (row) {
            var slotName = normalizeTemplateHymnSlotName(String(row && row.slot_name ? row.slot_name : '').trim());
            var displaySortOrder = parseInt(row && row.sort_order ? row.sort_order : '0', 10) || 0;
            var label = String(row && row.label ? row.label : '').trim();
            var stanzas = normalizeStanzaText(row && row.stanzas ? row.stanzas : '');
            var targetIndex = null;
            var extraKey;

            if (label === '') {
                return;
            }

            if (slotName !== '' && slotName !== 'Other Hymn') {
                slotOccurrenceCounts[slotName] = (slotOccurrenceCounts[slotName] || 0) + 1;
                targetIndex = findHymnDefinitionIndex(definitions, slotName, slotOccurrenceCounts[slotName]);
                if (targetIndex === null) {
                    targetIndex = findHymnDefinitionIndex(definitions, slotName, 1);
                }
            } else if (canRepairOtherHymnRowsByPosition) {
                targetIndex = findHymnDefinitionIndexByDisplayOrder(definitions, displaySortOrder);
            }

            if (targetIndex !== null
                && Object.prototype.hasOwnProperty.call(nextState.hymns, String(targetIndex))
                && String(nextState.hymns[String(targetIndex)] || '').trim() === '') {
                nextState.hymns[String(targetIndex)] = label;
                if (stanzas !== '') {
                    nextState.stanzas[String(targetIndex)] = stanzas;
                }
                return;
            }

            extraKey = 'extra:' + String(nextState.next_extra_id);
            nextState.next_extra_id += 1;
            nextState.extra_rows.push({
                key: extraKey,
                value: label,
                slot_name: slotName === 'Distribution Hymn' ? 'Distribution Hymn' : 'Other Hymn',
                stanzas: stanzas
            });

            if (displaySortOrder > 0) {
                nextState.order.splice(
                    Math.min(Math.max(displaySortOrder - 1, 0), nextState.order.length),
                    0,
                    extraKey
                );
            } else {
                nextState.order.push(extraKey);
            }
        });

        return nextState;
    }

    function findExtraHymnRow(key) {
        return hymnState.extra_rows.find(function (row) {
            return String(row.key) === String(key);
        }) || null;
    }

    function hasSelectedHymns() {
        var hasBaseHymn = Object.keys(hymnState.hymns || {}).some(function (key) {
            return String((hymnState.hymns || {})[key] || '').trim() !== '';
        });
        var hasExtraHymn = (hymnState.extra_rows || []).some(function (row) {
            return String(row && row.value ? row.value : '').trim() !== '';
        });

        return hasBaseHymn || hasExtraHymn;
    }

    function hasMeaningfulServiceState() {
        var observanceValue = String(observanceInput && observanceInput.value ? observanceInput.value : '').trim();
        var serviceSettingValue = String(serviceSettingInput && serviceSettingInput.value ? serviceSettingInput.value : '').trim();
        var primaryLeaderValue = String(primaryLeaderInput && primaryLeaderInput.value ? primaryLeaderInput.value : '').trim();
        var secondaryLeaderValue = String(secondaryLeaderInput && secondaryLeaderInput.value ? secondaryLeaderInput.value : '').trim();
        var hasSelectedReading = String(selectedReadingSetId || '').trim() !== '';
        var hasSelectedPassionReading = (selectedPassionReadingIds || []).some(function (value) {
            return String(value || '').trim() !== '';
        });
        var hasSelectedCatechism = (selectedSmallCatechismLabels || []).some(function (label) {
            return String(label || '').trim() !== '';
        });
        var hasReadingDraft = (readingDraftState || []).some(function (draft) {
            return !!draft && ['psalm', 'old_testament', 'epistle', 'gospel'].some(function (field) {
                return String(draft[field] || '').trim() !== '';
            });
        });
        var hasNewObservanceColor = !!(newObservanceColorWrap
            && newObservanceColorWrap.classList.contains('is-visible')
            && newObservanceColorSelect
            && String(newObservanceColorSelect.value || '').trim() !== '');
        var hasCopyToggle = !!(copyServiceToggle && copyServiceToggle.checked);

        return observanceValue !== ''
            || serviceSettingValue !== ''
            || primaryLeaderValue !== ''
            || secondaryLeaderValue !== ''
            || hasSelectedReading
            || hasSelectedPassionReading
            || hasSelectedCatechism
            || hasReadingDraft
            || hasNewObservanceColor
            || hasCopyToggle
            || hasSelectedHymns();
    }

    function syncClearHymnsButtonState() {
        if (!clearHymnsButton) {
            return;
        }

        clearHymnsButton.disabled = !hasSelectedHymns();
    }

    function syncPrimaryActionButtonsState() {
        var hasMeaningfulState = hasMeaningfulServiceState();

        if (clearServiceButton) {
            clearServiceButton.disabled = !hasMeaningfulState;
        }
        if (addServiceButton) {
            addServiceButton.disabled = !hasMeaningfulState;
        }
    }

    function captureHymnState() {
        var hymnRows = hymnPane.querySelectorAll('.service-card-hymn-row');
        var nextExtraRows = [];
        var nextOrder = [];
        var nextStanzas = {};

        Array.prototype.forEach.call(hymnRows, function (row) {
            var rowKey = row.getAttribute('data-row-key') || '';
            var input = row.querySelector('.service-card-hymn-lookup');
            var stanzasInput = row.querySelector('.js-hymn-stanza-input');
            var stanzasValue = normalizeStanzaText(stanzasInput ? stanzasInput.value : '');

            if (!rowKey || !input) {
                return;
            }

            nextOrder.push(rowKey);
            if (rowKey.indexOf('base:') === 0) {
                hymnState.hymns[rowKey.replace('base:', '')] = input.value;
                if (stanzasValue !== '') {
                    nextStanzas[rowKey.replace('base:', '')] = stanzasValue;
                }
                return;
            }

            nextExtraRows.push({
                key: rowKey,
                value: input.value,
                slot_name: row.getAttribute('data-extra-slot-name') === 'Distribution Hymn' ? 'Distribution Hymn' : 'Other Hymn',
                stanzas: stanzasValue
            });
        });

        hymnState.extra_rows = nextExtraRows;
        hymnState.order = nextOrder;
        hymnState.stanzas = nextStanzas;

        if (hymnRowOrderInput) {
            hymnRowOrderInput.value = JSON.stringify(hymnState.order);
        }

        updateDuplicateTuneHighlights(hymnPane);
        syncClearHymnsButtonState();
        syncPrimaryActionButtonsState();
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
                } else {
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

    function getHymnLayoutMode(definitions) {
        var slotNames = Array.prototype.map.call(definitions || [], function (definition) {
            return String(definition && definition.slot_name ? definition.slot_name : '').trim();
        }).filter(function (slotName) {
            return slotName !== '';
        });

        if (slotNames.indexOf('Chief Hymn') !== -1 && slotNames.indexOf('Closing Hymn') !== -1 && slotNames.indexOf('Distribution Hymn') !== -1) {
            return 'divine_service';
        }

        if (slotNames.indexOf('Office Hymn') !== -1 && slotNames.indexOf('Closing Hymn') !== -1) {
            return 'office';
        }

        return 'generic';
    }

    function normalizeExtraHymnSlotNameForLayout(slotName, layoutMode) {
        var normalized = String(slotName || '').trim();

        if (layoutMode === 'divine_service') {
            return normalized === 'Distribution Hymn' ? 'Distribution Hymn' : 'Other Hymn';
        }

        return 'Other Hymn';
    }

    function buildCanonicalDisplayRows(definitions) {
        var layoutMode = getHymnLayoutMode(definitions);
        var baseRowsByKey = {};
        var extraRowsByKey = {};
        var orderedKeys = [];
        var openingOtherRows = [];
        var middleOtherRows = [];
        var postDistributionOtherRows = [];
        var extraDistributionRows = [];
        var lastBaseIndex = 0;
        var canonicalRows = [];
        var appendBaseRow = function (definitionIndex) {
            var rowKey = 'base:' + String(definitionIndex);
            if (baseRowsByKey[rowKey]) {
                canonicalRows.push(baseRowsByKey[rowKey]);
            }
        };

        Array.prototype.forEach.call(definitions || [], function (definition) {
            var definitionIndex = parseInt(definition && definition.index ? definition.index : '0', 10) || 0;

            if (definitionIndex <= 0) {
                return;
            }

            baseRowsByKey['base:' + String(definitionIndex)] = {
                key: 'base:' + String(definitionIndex),
                kind: 'base',
                originalIndex: String(definitionIndex),
                slotName: String(definition && definition.slot_name ? definition.slot_name : ''),
                value: String((hymnState.hymns || {})[String(definitionIndex)] || ''),
                stanzas: normalizeStanzaText((hymnState.stanzas || {})[String(definitionIndex)] || ''),
                label: String(definition && definition.label ? definition.label : '')
            };
        });

        hymnState.extra_rows = Array.prototype.map.call(hymnState.extra_rows || [], function (row) {
            return {
                key: String(row && row.key ? row.key : ''),
                value: String(row && row.value ? row.value : ''),
                slot_name: normalizeExtraHymnSlotNameForLayout(row && row.slot_name ? row.slot_name : '', layoutMode),
                stanzas: normalizeStanzaText(row && row.stanzas ? row.stanzas : '')
            };
        }).filter(function (row) {
            return row.key !== '';
        });

        hymnState.extra_rows.forEach(function (row) {
            extraRowsByKey[row.key] = {
                key: row.key,
                kind: 'extra',
                value: String(row.value || ''),
                slotName: String(row.slot_name || 'Other Hymn'),
                stanzas: normalizeStanzaText(row.stanzas || '')
            };
        });

        Array.prototype.forEach.call(hymnState.order || [], function (rowKey) {
            rowKey = String(rowKey || '');
            if (!rowKey) {
                return;
            }
            if ((baseRowsByKey[rowKey] || extraRowsByKey[rowKey]) && orderedKeys.indexOf(rowKey) === -1) {
                orderedKeys.push(rowKey);
            }
        });
        Object.keys(baseRowsByKey).forEach(function (rowKey) {
            if (orderedKeys.indexOf(rowKey) === -1) {
                orderedKeys.push(rowKey);
            }
        });
        Object.keys(extraRowsByKey).forEach(function (rowKey) {
            if (orderedKeys.indexOf(rowKey) === -1) {
                orderedKeys.push(rowKey);
            }
        });

        if (layoutMode === 'generic') {
            canonicalRows = orderedKeys.map(function (rowKey) {
                return baseRowsByKey[rowKey] || extraRowsByKey[rowKey] || null;
            }).filter(function (row) {
                return !!row;
            });
            canonicalRows.forEach(function (row) {
                if (row.kind === 'extra') {
                    row.slotMenuDirection = 'down';
                }
            });
        } else {
            orderedKeys.forEach(function (rowKey) {
                var row;

                if (rowKey.indexOf('base:') === 0) {
                    lastBaseIndex = parseInt(rowKey.replace('base:', ''), 10) || 0;
                    return;
                }

                row = extraRowsByKey[rowKey];
                if (!row) {
                    return;
                }

                if (layoutMode === 'divine_service' && row.slotName === 'Distribution Hymn') {
                    extraDistributionRows.push(row);
                    return;
                }

                if (layoutMode === 'divine_service') {
                    if (lastBaseIndex <= 1) {
                        openingOtherRows.push(row);
                    } else if (lastBaseIndex <= 2) {
                        middleOtherRows.push(row);
                    } else {
                        postDistributionOtherRows.push(row);
                    }
                    return;
                }

                if (lastBaseIndex <= 1) {
                    openingOtherRows.push(row);
                } else {
                    middleOtherRows.push(row);
                }
            });

            if (layoutMode === 'divine_service') {
                appendBaseRow(1);
                openingOtherRows.forEach(function (row) { row.slotMenuDirection = 'down'; canonicalRows.push(row); });
                appendBaseRow(2);
                middleOtherRows.forEach(function (row) { row.slotMenuDirection = 'down'; canonicalRows.push(row); });
                [3, 4, 5, 6, 7].forEach(appendBaseRow);
                extraDistributionRows.forEach(function (row) { row.slotMenuDirection = 'up'; canonicalRows.push(row); });
                postDistributionOtherRows.forEach(function (row) { row.slotMenuDirection = 'up'; canonicalRows.push(row); });
                appendBaseRow(8);
            } else {
                appendBaseRow(1);
                openingOtherRows.forEach(function (row) { row.slotMenuDirection = 'down'; canonicalRows.push(row); });
                appendBaseRow(2);
                middleOtherRows.forEach(function (row) { row.slotMenuDirection = 'down'; canonicalRows.push(row); });
                appendBaseRow(3);
            }
        }

        (function assignLabels() {
            var distributionIndex = 0;

            canonicalRows.forEach(function (row) {
                if (row.slotName === 'Distribution Hymn') {
                    distributionIndex += 1;
                    row.label = 'Distribution Hymn ' + String(distributionIndex);
                } else if (row.kind === 'extra') {
                    row.label = 'Additional hymn';
                }
            });
        }());

        hymnState.order = canonicalRows.map(function (row) {
            return String(row.key || '');
        }).filter(function (rowKey) {
            return rowKey !== '';
        });
        hymnState.next_extra_id = Math.max(
            parseInt(hymnState.next_extra_id || '1', 10) || 1,
            (hymnState.extra_rows || []).length + 1
        );

        if (hymnRowOrderInput) {
            hymnRowOrderInput.value = JSON.stringify(hymnState.order);
        }

        return {
            layoutMode: layoutMode,
            rows: canonicalRows
        };
    }

    function bindExtraHymnSlotBehavior(scope) {
        var slotButtons = scope.querySelectorAll('.js-extra-hymn-slot-button');
        var currentServiceId = serviceSettingIdInput ? (serviceSettingIdInput.value || '') : '';
        var definitions = hymnDefinitionsByService[currentServiceId] || [];
        var layoutMode = getHymnLayoutMode(definitions);

        function closeAllSlotOptions(exceptAnchor) {
            Array.prototype.forEach.call(scope.querySelectorAll('.js-extra-hymn-slot-suggestion-list'), function (candidateList) {
                var candidateAnchor = candidateList.closest('.service-card-suggestion-anchor');

                if (exceptAnchor && candidateAnchor === exceptAnchor) {
                    return;
                }

                candidateList.hidden = true;
                candidateList.classList.remove('is-visible');
                candidateList.innerHTML = '';
            });
        }

        if (scope.getAttribute('data-extra-slot-dismiss-bound') !== '1') {
            document.addEventListener('mousedown', function (event) {
                if (!scope.contains(event.target)) {
                    closeAllSlotOptions(null);
                    return;
                }

                if (!event.target.closest('.service-card-hymn-slot-anchor')) {
                    closeAllSlotOptions(null);
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeAllSlotOptions(null);
                }
            });

            scope.setAttribute('data-extra-slot-dismiss-bound', '1');
        }

        Array.prototype.forEach.call(slotButtons, function (button) {
            var anchor = button.closest('.service-card-suggestion-anchor');
            var list = anchor ? anchor.querySelector('.js-extra-hymn-slot-suggestion-list') : null;
            var hidden = anchor ? anchor.querySelector('.js-extra-hymn-slot-hidden') : null;

            function syncSlot(value) {
                var slotValue = String(value || '').trim().toLowerCase() === 'distribution' ? 'Distribution Hymn' : 'Other Hymn';
                var row = button.closest('.service-card-hymn-row');
                var previousSlotValue = row ? (row.getAttribute('data-extra-slot-name') || 'Other Hymn') : 'Other Hymn';

                slotValue = normalizeExtraHymnSlotNameForLayout(slotValue, layoutMode);
                button.textContent = slotValue === 'Distribution Hymn' ? 'D' : 'O';
                button.setAttribute('title', slotValue === 'Distribution Hymn' ? 'Distribution hymn' : 'Other hymn');
                button.setAttribute('aria-label', slotValue === 'Distribution Hymn' ? 'Distribution hymn' : 'Other hymn');
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

            function closeSlotOptions() {
                if (list) {
                    list.hidden = true;
                    list.classList.remove('is-visible');
                    list.innerHTML = '';
                }
            }

            function syncSlotAndRefresh(value) {
                var row = button.closest('.service-card-hymn-row');
                var labelInput = row ? row.querySelector('.service-card-hymn-lookup-extra') : null;
                var slotChanged = syncSlot(value);

                closeSlotOptions();
                if (slotChanged) {
                    if (labelInput) {
                        labelInput.setAttribute('placeholder', hidden && hidden.value === 'Distribution Hymn' ? 'Distribution Hymn' : 'Additional hymn');
                    }
                    captureHymnState();
                }
            }

            function showSlotOptions() {
                if (list && list.classList.contains('is-visible')) {
                    closeSlotOptions();
                    return;
                }

                closeAllSlotOptions(anchor);
                renderSimpleSuggestionOptions(button, list, layoutMode === 'divine_service' ? ['Distribution', 'Other'] : ['Other'], function (name) {
                    syncSlotAndRefresh(name);
                });
            }

            syncSlot(hidden ? hidden.value : 'Other Hymn');
            button.addEventListener('click', showSlotOptions);
        });
    }

    function ensureStanzaModal() {
        if (stanzaModal) {
            return;
        }

        stanzaModal = document.createElement('div');
        stanzaModal.className = 'service-card-stanza-modal';
        stanzaModal.hidden = true;
        stanzaModal.innerHTML =
            '<div class="service-card-stanza-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="service-card-stanza-title">' +
                '<div class="service-card-stanza-modal-header">' +
                    '<div class="service-card-stanza-modal-title" id="service-card-stanza-title">Set hymn stanzas</div>' +
                    '<button type="button" class="service-card-stanza-modal-close js-stanza-modal-cancel" aria-label="Close stanza editor">&times;</button>' +
                '</div>' +
                '<label class="service-card-stanza-modal-label" for="service-card-stanza-input">Stanzas</label>' +
                '<textarea id="service-card-stanza-input" class="service-card-stanza-modal-input js-stanza-modal-input" placeholder="1, 3-4"></textarea>' +
                '<div class="service-card-stanza-modal-actions">' +
                    '<button type="button" class="service-card-stanza-modal-button service-card-stanza-modal-button-muted js-stanza-modal-clear">Clear</button>' +
                    '<button type="button" class="service-card-stanza-modal-button service-card-stanza-modal-button-primary js-stanza-modal-save">Save</button>' +
                '</div>' +
            '</div>';

        form.appendChild(stanzaModal);

        stanzaModalTitle = stanzaModal.querySelector('.service-card-stanza-modal-title');
        stanzaModalInput = stanzaModal.querySelector('.js-stanza-modal-input');

        stanzaModal.addEventListener('click', function (event) {
            if (event.target === stanzaModal) {
                closeStanzaModal();
            }
        });

        Array.prototype.forEach.call(stanzaModal.querySelectorAll('.js-stanza-modal-cancel'), function (button) {
            button.addEventListener('click', function () {
                closeStanzaModal();
            });
        });

        Array.prototype.forEach.call(stanzaModal.querySelectorAll('.js-stanza-modal-clear'), function (button) {
            button.addEventListener('click', function () {
                if (!stanzaModalInput) {
                    return;
                }

                stanzaModalInput.value = '';
                stanzaModalInput.focus();
            });
        });

        Array.prototype.forEach.call(stanzaModal.querySelectorAll('.js-stanza-modal-save'), function (button) {
            button.addEventListener('click', function () {
                saveStanzaModalValue();
            });
        });

        if (stanzaModalInput) {
            stanzaModalInput.addEventListener('keydown', function (event) {
                if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                    event.preventDefault();
                    saveStanzaModalValue();
                }

                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeStanzaModal();
                }
            });
        }
    }

    function getStanzaValueForRow(rowKey) {
        if (rowKey.indexOf('base:') === 0) {
            return normalizeStanzaText((hymnState.stanzas || {})[rowKey.replace('base:', '')] || '');
        }

        var extraRow = findExtraHymnRow(rowKey);
        return extraRow ? normalizeStanzaText(extraRow.stanzas || '') : '';
    }

    function setStanzaValueForRow(rowKey, value) {
        var normalizedValue = normalizeStanzaText(value);
        var extraRow;

        if (rowKey.indexOf('base:') === 0) {
            if (normalizedValue === '') {
                delete hymnState.stanzas[rowKey.replace('base:', '')];
            } else {
                hymnState.stanzas[rowKey.replace('base:', '')] = normalizedValue;
            }
            return;
        }

        extraRow = findExtraHymnRow(rowKey);
        if (extraRow) {
            extraRow.stanzas = normalizedValue;
        }
    }

    function syncStanzaButtonState(rowKey) {
        var row = hymnPane.querySelector('[data-row-key="' + rowKey.replace(/"/g, '\\"') + '"]');
        var button;
        var input;
        var value;

        if (!row) {
            return;
        }

        button = row.querySelector('.js-hymn-stanza-button');
        input = row.querySelector('.js-hymn-stanza-input');
        value = getStanzaValueForRow(rowKey);

        if (input) {
            input.value = value;
        }
        if (button) {
            button.classList.toggle('is-set', value !== '');
            button.setAttribute('title', value !== '' ? 'Stanzas: ' + value : 'Click to add stanzas');
        }
    }

    function openStanzaModal(rowKey, label) {
        ensureStanzaModal();
        captureHymnState();
        activeStanzaRowKey = rowKey;

        if (stanzaModalTitle) {
            stanzaModalTitle.textContent = 'Set stanzas for ' + String(label || 'hymn');
        }
        if (stanzaModalInput) {
            stanzaModalInput.value = getStanzaValueForRow(rowKey);
        }

        stanzaModal.hidden = false;
        stanzaModal.classList.add('is-visible');

        if (stanzaModalInput) {
            stanzaModalInput.focus();
            stanzaModalInput.select();
        }
    }

    function closeStanzaModal() {
        if (!stanzaModal) {
            return;
        }

        stanzaModal.hidden = true;
        stanzaModal.classList.remove('is-visible');
        activeStanzaRowKey = '';
    }

    function saveStanzaModalValue() {
        if (!activeStanzaRowKey || !stanzaModalInput) {
            closeStanzaModal();
            return;
        }

        setStanzaValueForRow(activeStanzaRowKey, stanzaModalInput.value);
        syncStanzaButtonState(activeStanzaRowKey);
        closeStanzaModal();
        captureHymnState();
    }

    function bindStanzaButtonBehavior(scope) {
        Array.prototype.forEach.call(scope.querySelectorAll('.js-hymn-stanza-button'), function (button) {
            button.addEventListener('click', function () {
                openStanzaModal(
                    button.getAttribute('data-row-key') || '',
                    button.getAttribute('data-row-label') || 'hymn'
                );
            });
        });
    }

    function bindHymnDragBehavior(scope, serviceId) {
        var draggedRowKey = null;
        var draggedRowKind = '';

        Array.prototype.forEach.call(scope.querySelectorAll('.service-card-hymn-row'), function (row) {
            var handle = row.querySelector('.service-card-drag-handle');
            var rowKind = row.getAttribute('data-row-kind') || '';

            if (handle) {
                handle.addEventListener('dragstart', function (event) {
                    draggedRowKey = row.getAttribute('data-row-key');
                    draggedRowKind = rowKind;
                    row.classList.add('is-dragging');
                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', draggedRowKey || '');
                    }
                });

                handle.addEventListener('dragend', function () {
                    draggedRowKey = null;
                    draggedRowKind = '';
                    row.classList.remove('is-dragging');
                    Array.prototype.forEach.call(scope.querySelectorAll('.service-card-hymn-row'), function (candidate) {
                        candidate.classList.remove('is-drop-target');
                    });
                });
            }

            row.addEventListener('dragover', function (event) {
                if (!draggedRowKey) {
                    return;
                }
                event.preventDefault();
                row.classList.add('is-drop-target');
            });

            row.addEventListener('dragleave', function () {
                row.classList.remove('is-drop-target');
            });

            row.addEventListener('drop', function (event) {
                var targetKey;
                var targetKind;
                var sourceIndex;
                var targetIndex;
                var sourceValue;
                var targetValue;
                var sourceStanzas;
                var targetStanzas;
                var fromIndex;
                var toIndex;

                event.preventDefault();
                row.classList.remove('is-drop-target');
                targetKey = row.getAttribute('data-row-key');
                targetKind = row.getAttribute('data-row-kind') || '';

                if (!draggedRowKey || !targetKey || draggedRowKey === targetKey) {
                    return;
                }

                captureHymnState();
                if (draggedRowKind === 'base') {
                    if (targetKind !== 'base') {
                        return;
                    }

                    sourceIndex = String(draggedRowKey).replace('base:', '');
                    targetIndex = String(targetKey).replace('base:', '');
                    sourceValue = String((hymnState.hymns || {})[sourceIndex] || '');
                    targetValue = String((hymnState.hymns || {})[targetIndex] || '');
                    sourceStanzas = normalizeStanzaText((hymnState.stanzas || {})[sourceIndex] || '');
                    targetStanzas = normalizeStanzaText((hymnState.stanzas || {})[targetIndex] || '');

                    hymnState.hymns[sourceIndex] = targetValue;
                    hymnState.hymns[targetIndex] = sourceValue;
                    if (targetStanzas === '') {
                        delete hymnState.stanzas[sourceIndex];
                    } else {
                        hymnState.stanzas[sourceIndex] = targetStanzas;
                    }
                    if (sourceStanzas === '') {
                        delete hymnState.stanzas[targetIndex];
                    } else {
                        hymnState.stanzas[targetIndex] = sourceStanzas;
                    }

                    renderHymnPane(serviceId);
                    return;
                }

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
            slot_name: defaultSlotName === 'Distribution Hymn' ? 'Distribution Hymn' : 'Other Hymn',
            stanzas: ''
        });
        hymnState.next_extra_id = (parseInt(hymnState.next_extra_id || '1', 10) || 1) + 1;
        hymnState.order.push(hymnState.extra_rows[hymnState.extra_rows.length - 1].key);
    }

    function setFillHymnsLabel(label) {
        var labelNode;

        if (!fillHymnsLabelInput) {
            return;
        }

        labelNode = fillHymnsLabelInput.querySelector('.service-card-selectlike-label');
        if (!labelNode) {
            return;
        }

        labelNode.textContent = String(label || '').trim() !== '' ? String(label) : 'Select matching service';
    }

    function populateFillHymnsOptions() {
        var matchingObservance = resolveObservanceDetail(observanceInput.value);
        var serviceSettingDetail = findServiceSettingDetailByName(serviceSettingInput.value);
        var currentSelectedId = String(fillHymnsIdInput && fillHymnsIdInput.value ? fillHymnsIdInput.value : '');
        var observanceId = matchingObservance && matchingObservance.id ? parseInt(matchingObservance.id, 10) : 0;
        var observanceName = String(observanceInput.value || '').trim().replace(/\s+\((?:Sa|[SMTWRF])\s+\d{1,2}(?:\/\d{1,2})?\)\s*$/, '').trim().toLowerCase();
        var serviceSettingId = serviceSettingDetail && serviceSettingDetail.id ? parseInt(serviceSettingDetail.id, 10) : 0;
        var hasSelectedService = serviceSettingId > 0;
        var preferredOptions = [];
        var fallbackOptions = [];
        var labelsSeen = {};

        if (!fillHymnsLabelInput || !fillHymnsIdInput) {
            return;
        }

        if (hasSelectedService) {
            hymnFillTemplates.forEach(function (template) {
                var templateObservanceId = parseInt(template && template.liturgical_calendar_id ? template.liturgical_calendar_id : '0', 10);
                var templateSettingId = parseInt(template && template.service_setting_id ? template.service_setting_id : '0', 10);
                var templateName = String(template && template.observance_name ? template.observance_name : '').trim().toLowerCase();
                var observanceMatches = observanceId > 0 ? templateObservanceId === observanceId : (observanceName !== '' && templateName === observanceName);
                var serviceMatches = templateSettingId === serviceSettingId;

                if (observanceMatches) {
                    if (!labelsSeen[String(template.label || '').toLowerCase()]) {
                        labelsSeen[String(template.label || '').toLowerCase()] = true;
                        if (serviceMatches) {
                            preferredOptions.push(template);
                        } else {
                            fallbackOptions.push(template);
                        }
                    }
                }
            });
        }

        matchingHymnFillTemplates = preferredOptions.concat(fallbackOptions);
        if (currentSelectedId !== '' && matchingHymnFillTemplates.some(function (template) {
            return String(template && template.id ? template.id : '') === currentSelectedId;
        })) {
            fillHymnsIdInput.value = currentSelectedId;
            setFillHymnsLabel(String((matchingHymnFillTemplates.find(function (template) {
                return String(template && template.id ? template.id : '') === currentSelectedId;
            }) || {}).label || ''));
        } else {
            fillHymnsIdInput.value = '';
            setFillHymnsLabel('');
        }
        fillHymnsLabelInput.disabled = !hasSelectedService || matchingHymnFillTemplates.length === 0;
        if (fillHymnsButton) {
            fillHymnsButton.disabled = !hasSelectedService || matchingHymnFillTemplates.length === 0;
        }
        if (fillHymnsSuggestionList) {
            fillHymnsSuggestionList.hidden = true;
            fillHymnsSuggestionList.classList.remove('is-visible');
            fillHymnsSuggestionList.innerHTML = '';
        }
    }

    function applyHymnFillTemplate(serviceId) {
        var template = hymnFillTemplates.find(function (candidate) {
            return String(candidate && candidate.id ? candidate.id : '') === String(serviceId || '');
        });
        var definitions;
        var templateState;
        var usageRows;
        var rebuiltState;

        if (!template) {
            return;
        }

        definitions = normalizeHymnState(serviceSettingIdInput.value || '');
        usageRows = Array.isArray(template && template.usage_rows) ? template.usage_rows : [];
        rebuiltState = buildHymnStateFromUsageRows(definitions, usageRows);
        templateState = rebuiltState && typeof rebuiltState === 'object' && Object.keys(rebuiltState.hymns || {}).length > 0
            ? rebuiltState
            : (template && template.hymn_state && typeof template.hymn_state === 'object' ? template.hymn_state : null);

        if (templateState) {
            hymnState.hymns = Object.keys(templateState.hymns || {}).reduce(function (result, key) {
                result[String(key)] = String((templateState.hymns || {})[key] || '');
                return result;
            }, {});
            hymnState.stanzas = Object.keys(templateState.stanzas || {}).reduce(function (result, key) {
                result[String(key)] = normalizeStanzaText((templateState.stanzas || {})[key] || '');
                return result;
            }, {});
            hymnState.extra_rows = Array.prototype.map.call(templateState.extra_rows || [], function (row) {
                return {
                    key: String(row && row.key ? row.key : ''),
                    value: String(row && row.value ? row.value : ''),
                    slot_name: row && row.slot_name === 'Distribution Hymn' ? 'Distribution Hymn' : 'Other Hymn',
                    stanzas: normalizeStanzaText(row && row.stanzas ? row.stanzas : '')
                };
            }).filter(function (row) {
                return row.key !== '';
            });
            hymnState.order = Array.prototype.map.call(templateState.order || [], function (key) {
                return String(key || '');
            }).filter(function (key) {
                return key !== '';
            });
            hymnState.next_extra_id = Math.max(
                parseInt(templateState.next_extra_id || '1', 10) || 1,
                hymnState.extra_rows.length + 1
            );
        } else {
            hymnState.hymns = {};
            hymnState.stanzas = {};
            hymnState.extra_rows = [];
            hymnState.order = [];
            hymnState.next_extra_id = 1;
        }

        renderHymnPane(serviceSettingIdInput.value || '');
    }

    function clearHymnStateForCurrentService() {
        var serviceId = serviceSettingIdInput.value || '';

        normalizeHymnState(serviceId);
        hymnState.hymns = {};
        hymnState.stanzas = {};
        hymnState.extra_rows = [];
        hymnState.order = [];
        if (fillHymnsIdInput) {
            fillHymnsIdInput.value = '';
        }
        setFillHymnsLabel('');
        if (fillHymnsSuggestionList) {
            fillHymnsSuggestionList.hidden = true;
            fillHymnsSuggestionList.classList.remove('is-visible');
            fillHymnsSuggestionList.innerHTML = '';
        }
        renderHymnPane(serviceId);
        populateFillHymnsOptions();
        syncPrimaryActionButtonsState();
    }

    function getFillHymnsSuggestionSource() {
        return matchingHymnFillTemplates.slice();
    }

    function renderFillHymnsSuggestionOptions() {
        var source = getFillHymnsSuggestionSource();

        if (!fillHymnsSuggestionList || !fillHymnsLabelInput || !fillHymnsIdInput) {
            return;
        }

        fillHymnsSuggestionList.innerHTML = '';
        source.forEach(function (template) {
            var button = document.createElement('button');

            button.type = 'button';
            button.className = 'service-card-suggestion-item';
            button.textContent = String(template && template.label ? template.label : '');
            bindSuggestionButtonSelection(button, function () {
                setFillHymnsLabel(String(template && template.label ? template.label : ''));
                fillHymnsIdInput.value = String(template && template.id ? template.id : '');
                fillHymnsSuggestionList.hidden = true;
                fillHymnsSuggestionList.classList.remove('is-visible');
                fillHymnsSuggestionList.innerHTML = '';
                fillHymnsLabelInput.focus();
            });

            fillHymnsSuggestionList.appendChild(button);
        });

        fillHymnsSuggestionList.hidden = source.length === 0;
        fillHymnsSuggestionList.classList.toggle('is-visible', source.length > 0);
    }

    function renderHymnPane(serviceId) {
        var definitions = normalizeHymnState(serviceId);
        var displayState = buildCanonicalDisplayRows(definitions);
        var layoutMode = displayState.layoutMode;
        var displayRows = displayState.rows;
        var html = '';

        if (definitions.length > 0) {
            html += '<div class="service-card-hymn-instruction">Click "s" to input stanzas for a hymn.</div>';
            if (hasDuplicateTuneSelections(hymnPane)) {
                html += '<div class="service-card-hymn-warning">Selected hymns have the same tune.</div>';
            }
        }

        displayRows.forEach(function (meta) {
            var originalIndex;
            var value;
            var extraRow;
            var stanzaHtml = '';

            if (meta.kind === 'base') {
                originalIndex = meta.originalIndex;
                value = meta.value;

                stanzaHtml =
                    '<input type="hidden" name="hymn_stanzas[' + originalIndex + ']" value="' + escapeHtml(meta.stanzas || '') + '" class="js-hymn-stanza-input">' +
                    '<button type="button" class="service-card-stanza-button js-hymn-stanza-button' + (meta.stanzas ? ' is-set' : '') + '" data-row-key="' + escapeHtml(meta.key) + '" data-row-label="' + escapeHtml(meta.label) + '" aria-label="Edit stanzas for ' + escapeHtml(meta.label) + '" title="' + escapeHtml(meta.stanzas ? 'Stanzas: ' + meta.stanzas : 'Click to add stanzas') + '">S</button>';

                html +=
                    '<div class="service-card-hymn-row" data-row-key="' + escapeHtml(meta.key) + '" data-row-kind="base" draggable="false">' +
                        '<button type="button" class="service-card-drag-handle" draggable="true" aria-label="Move hymn to a different slot">::</button>' +
                        '<input type="text" id="hymn_' + originalIndex + '" name="hymn_' + originalIndex + '" value="' + escapeHtml(value) + '" placeholder="' + escapeHtml(meta.label) + '" data-list-id="' + hymnSuggestionsId + '" autocomplete="off" class="service-card-hymn-lookup">' +
                        stanzaHtml +
                    '</div>';
                return;
            }

            extraRow = {
                key: meta.key,
                slot_name: meta.slotName,
                value: meta.value,
                stanzas: meta.stanzas
            };

            html +=
                '<div class="service-card-hymn-row service-card-hymn-row-extra" data-row-key="' + escapeHtml(extraRow.key) + '" data-row-kind="extra" data-extra-slot-name="' + escapeHtml(extraRow.slot_name) + '" data-slot-name="' + escapeHtml(extraRow.slot_name) + '" draggable="false">' +
                    '<button type="button" class="service-card-drag-handle" draggable="true" aria-label="Reorder hymn">::</button>' +
                    '<input type="hidden" name="extra_hymn_keys[]" value="' + escapeHtml(extraRow.key) + '">' +
                    '<input type="text" name="extra_hymn_values[' + escapeHtml(extraRow.key) + ']" value="' + escapeHtml(extraRow.value || '') + '" placeholder="' + escapeHtml(meta.label) + '" data-list-id="' + hymnSuggestionsId + '" autocomplete="off" class="service-card-hymn-lookup service-card-hymn-lookup-extra">' +
                    '<input type="hidden" name="extra_hymn_stanzas[' + escapeHtml(extraRow.key) + ']" value="' + escapeHtml(meta.stanzas || '') + '" class="js-hymn-stanza-input">' +
                    '<button type="button" class="service-card-stanza-button js-hymn-stanza-button' + (meta.stanzas ? ' is-set' : '') + '" data-row-key="' + escapeHtml(extraRow.key) + '" data-row-label="' + escapeHtml(meta.label) + '" aria-label="Edit stanzas for ' + escapeHtml(meta.label) + '" title="' + escapeHtml(meta.stanzas ? 'Stanzas: ' + meta.stanzas : 'Click to add stanzas') + '">S</button>' +
                    '<div class="service-card-suggestion-anchor service-card-hymn-slot-anchor' + (meta.slotMenuDirection === 'up' ? ' service-card-hymn-slot-anchor-open-up' : '') + '">' +
                        '<input type="hidden" class="js-extra-hymn-slot-hidden" name="extra_hymn_slots[' + escapeHtml(extraRow.key) + ']" value="' + escapeHtml(extraRow.slot_name) + '">' +
                        '<button type="button" class="service-card-hymn-slot-button js-extra-hymn-slot-button" aria-label="' + escapeHtml(extraRow.slot_name === 'Distribution Hymn' ? 'Distribution hymn' : 'Other hymn') + '" title="' + escapeHtml(extraRow.slot_name === 'Distribution Hymn' ? 'Distribution hymn' : 'Other hymn') + '">' + escapeHtml(extraRow.slot_name === 'Distribution Hymn' ? 'D' : 'O') + '</button>' +
                        '<div class="service-card-suggestion-list js-extra-hymn-slot-suggestion-list" hidden></div>' +
                    '</div>' +
                    '<button type="button" class="service-card-remove-hymn-button" data-remove-row-key="' + escapeHtml(extraRow.key) + '" aria-label="Remove hymn">&times;</button>' +
                    '</div>';
        });

        if (definitions.length > 0 || hymnState.extra_rows.length > 0 || layoutMode !== 'generic') {
            html += '<button type="button" class="service-card-hymn-add-link service-card-reading-rubric" id="add-extra-hymn-link">To add an additional hymn, click here</button>';
        }
        hymnPane.innerHTML = html;

        bindHymnLookupBehavior(hymnPane);
        bindStanzaButtonBehavior(hymnPane);
        bindExtraHymnSlotBehavior(hymnPane);
        bindHymnDragBehavior(hymnPane, serviceId);
        updateDuplicateTuneHighlights(hymnPane);

        var addExtraHymnLink = hymnPane.querySelector('#add-extra-hymn-link');

        if (addExtraHymnLink) {
            addExtraHymnLink.addEventListener('click', function () {
                captureHymnState();
                addExtraHymnRow('Other Hymn');
                renderHymnPane(serviceId);
            });
        }

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

        if (hymnRowOrderInput) {
            hymnRowOrderInput.value = JSON.stringify(hymnState.order);
        }

        syncClearHymnsButtonState();
        syncPrimaryActionButtonsState();
    }

    function updateSummary() {
        var detail = findServiceSettingDetailByName(serviceSettingInput.value);

        if (!detail || !detail.id) {
            serviceSettingIdInput.value = '';
            summary.innerHTML = '&nbsp;';
            captureHymnState();
            renderHymnPane('');
            populateFillHymnsOptions();
            syncPrimaryActionButtonsState();
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
        populateFillHymnsOptions();
        syncPrimaryActionButtonsState();
    }

    function syncSecondaryLeaderVisibility() {
        if (!secondaryPreacherWrap) {
            return;
        }

        secondaryPreacherWrap.classList.toggle('is-visible', !!(copyServiceToggle && copyServiceToggle.checked));
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

        syncPrimaryActionButtonsState();
    }

    function hasReadingDraftContent(draft) {
        return !!(draft && (
            String(draft.psalm || '').trim() !== '' ||
            String(draft.old_testament || '').trim() !== '' ||
            String(draft.epistle || '').trim() !== '' ||
            String(draft.gospel || '').trim() !== ''
        ));
    }

    function buildReadingEditorFieldsHtml(draft, index) {
        return '' +
            '<div class="service-card-reading-set update-service-reading-editor">' +
                '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="psalm" name="new_reading_set_' + index + '_psalm" value="' + escapeHtml((draft && draft.psalm) || '') + '" placeholder="Psalm">' +
                '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="old_testament" name="new_reading_set_' + index + '_old_testament" value="' + escapeHtml((draft && draft.old_testament) || '') + '" placeholder="Old Testament">' +
                '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="epistle" name="new_reading_set_' + index + '_epistle" value="' + escapeHtml((draft && draft.epistle) || '') + '" placeholder="Epistle">' +
                '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="gospel" name="new_reading_set_' + index + '_gospel" value="' + escapeHtml((draft && draft.gospel) || '') + '" placeholder="Gospel">' +
            '</div>';
    }

    function getReadingEditorDraft(readingSets) {
        var draft = readingDraftState[0] || { index: 1, psalm: '', old_testament: '', epistle: '', gospel: '' };
        var selectedReadingSet = null;

        if (hasReadingDraftContent(draft)) {
            return draft;
        }

        Array.prototype.some.call(readingSets || [], function (readingSet) {
            if (String(readingSet && readingSet.id ? readingSet.id : '') === String(selectedReadingSetId || '')) {
                selectedReadingSet = readingSet;
                return true;
            }

            return false;
        });

        if (!selectedReadingSet && readingSets && readingSets.length > 0) {
            selectedReadingSet = readingSets[0];
        }

        return {
            index: draft.index || 1,
            psalm: selectedReadingSet && selectedReadingSet.psalm ? selectedReadingSet.psalm : '',
            old_testament: selectedReadingSet && selectedReadingSet.old_testament ? selectedReadingSet.old_testament : '',
            epistle: selectedReadingSet && selectedReadingSet.epistle ? selectedReadingSet.epistle : '',
            gospel: selectedReadingSet && selectedReadingSet.gospel ? selectedReadingSet.gospel : ''
        };
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
            selectedPassionReadingIds = [];
        }

        Array.prototype.forEach.call(drafts, function (draft, draftIndex) {
            var index = draftIndex + 1;

            html +=
                    '<div class="service-card-reading-set update-service-reading-editor">' +
                    '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="psalm" name="new_reading_set_' + index + '_psalm" value="' + escapeHtml(draft.psalm || '') + '" placeholder="Psalm">' +
                    '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="old_testament" name="new_reading_set_' + index + '_old_testament" value="' + escapeHtml(draft.old_testament || '') + '" placeholder="Old Testament">' +
                    '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="epistle" name="new_reading_set_' + index + '_epistle" value="' + escapeHtml(draft.epistle || '') + '" placeholder="Epistle">' +
                    '<input type="text" class="service-card-text update-service-reading-input js-new-reading-set-input" data-draft-index="' + index + '" data-draft-field="gospel" name="new_reading_set_' + index + '_gospel" value="' + escapeHtml(draft.gospel || '') + '" placeholder="Gospel">' +
                '</div>';
        });

        if (showPassionReadingSelect) {
            html += buildPassionReadingFieldHtml();
        }
        if (showSmallCatechismSelect) {
            html += buildSmallCatechismFieldsHtml();
        }

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
        selectedPassionReadingIds = [];
        readingsPane.innerHTML = '&nbsp;';
    }

    function renderReadingsPane(readingSets) {
        var html = '';
        var hasSelectedReadingSet = false;
        var defaultReadingSetId = '';
        var readingEditorDraft;
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
        }
        if (!showPassionReadingSelect) {
            selectedPassionReadingIds = [];
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
            if (showSmallCatechismSelect || showPassionReadingSelect) {
                renderNewReadingSetEditor();
            } else {
                readingsPane.innerHTML = '&nbsp;';
            }
            return;
        }

        if (!hasSelectedReadingSet) {
            selectedReadingSetId = '';
        }

        selectedNewReadingSet = '';
        if (showSmallCatechismSelect || showPassionReadingSelect) {
            readingEditorDraft = getReadingEditorDraft(readingSets);
            html += buildReadingEditorFieldsHtml(readingEditorDraft, parseInt(readingEditorDraft.index || '1', 10) || 1);
            if (showPassionReadingSelect) {
                html += buildPassionReadingFieldHtml();
            }
            if (showSmallCatechismSelect) {
                html += buildSmallCatechismFieldsHtml();
            }
        }
        readingsPane.innerHTML = html;
        if (showSmallCatechismSelect || showPassionReadingSelect) {
            readingDraftState[0] = {
                index: parseInt(readingEditorDraft.index || '1', 10) || 1,
                psalm: readingEditorDraft.psalm || '',
                old_testament: readingEditorDraft.old_testament || '',
                epistle: readingEditorDraft.epistle || '',
                gospel: readingEditorDraft.gospel || ''
            };
        }
        bindReadingSelectionBehavior(readingsPane, function (value) {
            selectedReadingSetId = value || '';
            renderReadingsPane(readingSets || []);
        });
        Array.prototype.forEach.call(readingsPane.querySelectorAll('.js-new-reading-set-input'), function (input) {
            input.addEventListener('input', captureReadingDraftState);
        });
        bindSupplementalReadingControls();
    }

    function updateObservanceDetails(resetReadingSelection) {
        var observanceName = String(observanceInput.value || '').trim();
        var detail = resolveObservanceDetail(observanceInput.value);

        captureReadingDraftState();
        if (observanceSuggestionList.classList.contains('is-visible')) {
            renderObservanceSuggestionOptions(false);
        }

        if (resetReadingSelection) {
            selectedReadingSetId = '';
            selectedNewReadingSet = '';
            selectedSmallCatechismLabels = [''];
            selectedPassionReadingIds = [];
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
            populateFillHymnsOptions();
            syncPrimaryActionButtonsState();
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
        populateFillHymnsOptions();
        syncPrimaryActionButtonsState();
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
    if (copyServiceToggle) {
        copyServiceToggle.addEventListener('change', function () {
            syncSecondaryLeaderVisibility();
            syncPrimaryActionButtonsState();
        });
    }
    if (serviceDateInput) {
        if (previewServiceDateInput) {
            previewServiceDateInput.value = String(serviceDateInput.value || '').trim();
        }
        serviceDateInput.addEventListener('input', function () {
            if (previewServiceDateInput) {
                previewServiceDateInput.value = String(serviceDateInput.value || '').trim();
            }
        });
        serviceDateInput.addEventListener('change', function () {
            var selectedDate = String(serviceDateInput.value || '').trim();
            if (previewServiceDateInput) {
                previewServiceDateInput.value = selectedDate;
            }
            window.setTimeout(function () {
                oflcSubmitPlannerRefresh(form);
            }, 0);
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
            scarlet: 'service-card-color-scarlet',
            red: 'service-card-color-red',
            black: 'service-card-color-black',
            white: 'service-card-color-dark'
        })[(newObservanceColorSelect.value || '').trim().toLowerCase()] || 'service-card-color-dark';
        syncPrimaryActionButtonsState();
    });
    Array.prototype.forEach.call([primaryLeaderInput, secondaryLeaderInput], function (input) {
        if (!input) {
            return;
        }

        input.addEventListener('input', syncPrimaryActionButtonsState);
        input.addEventListener('change', syncPrimaryActionButtonsState);
    });
    bindLeaderSuggestionInput(primaryLeaderInput, primaryLeaderSuggestionList);
    bindLeaderSuggestionInput(secondaryLeaderInput, secondaryLeaderSuggestionList);
    if (fillHymnsLabelInput && fillHymnsSuggestionList && fillHymnsIdInput) {
        fillHymnsLabelInput.addEventListener('click', function () {
            if (fillHymnsLabelInput.disabled) {
                return;
            }
            renderFillHymnsSuggestionOptions();
        });
        fillHymnsLabelInput.addEventListener('blur', function () {
            window.setTimeout(function () {
                fillHymnsSuggestionList.hidden = true;
                fillHymnsSuggestionList.classList.remove('is-visible');
                fillHymnsSuggestionList.innerHTML = '';
            }, 120);
        });
    }
    if (fillHymnsButton && fillHymnsIdInput) {
        fillHymnsButton.addEventListener('click', function () {
            if (fillHymnsIdInput.value !== '') {
                applyHymnFillTemplate(fillHymnsIdInput.value);
            }
        });
    }
    if (clearHymnsButton) {
        clearHymnsButton.addEventListener('click', function () {
            clearHymnStateForCurrentService();
        });
    }
    hideServiceSettingSuggestionOptions();
    hideObservanceSuggestionOptions();
    bindObservanceSuggestionList();
    bindServiceSettingSuggestionList();
    updateSummary();
    updateObservanceDetails(false);
    syncSecondaryLeaderVisibility();
    populateFillHymnsOptions();
    syncPrimaryActionButtonsState();
};

window.oflcInitializePlannerUI(document);
</script>

<?php include 'includes/footer.php'; ?>
