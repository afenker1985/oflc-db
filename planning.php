<?php
declare(strict_types=1);

session_start();

$page_title = 'Add a Service';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/liturgical.php';

function oflc_request_value(array $request_data, string $key, string $default = ''): string
{
    if (!isset($request_data[$key])) {
        return $default;
    }

    return trim((string) $request_data[$key]);
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

function oflc_format_festival_list_label(array $entry, array $observance): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $entry['date']);
    $day_label = $date instanceof DateTimeImmutable ? $date->format('D') : '';
    $month_day_label = $date instanceof DateTimeImmutable ? $date->format('m/d') : $entry['date'];

    return $observance['name'] . ' (' . $day_label . ', ' . $month_day_label . ')';
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
            'toggle_name' => 'opening_processional',
            'toggle_label' => 'Processional',
        ];
        $definitions[] = [
            'index' => 2,
            'label' => $slot_label('Chief Hymn', 'Chief Hymn'),
            'toggle_name' => null,
            'toggle_label' => null,
        ];

        for ($distribution_index = 3; $distribution_index <= 7; $distribution_index++) {
            $definitions[] = [
                'index' => $distribution_index,
                'label' => $slot_label('Distribution Hymn', 'Distribution Hymn') . ' ' . ($distribution_index - 2),
                'toggle_name' => null,
                'toggle_label' => null,
            ];
        }

        $definitions[] = [
            'index' => 8,
            'label' => $slot_label('Closing Hymn', 'Closing Hymn'),
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
                'toggle_name' => null,
                'toggle_label' => null,
            ],
            [
                'index' => 2,
                'label' => $slot_label('Office Hymn', 'Office Hymn'),
                'toggle_name' => null,
                'toggle_label' => null,
            ],
            [
                'index' => 3,
                'label' => $slot_label('Closing Hymn', 'Closing Hymn'),
                'toggle_name' => null,
                'toggle_label' => null,
            ],
        ];
    }

    return [];
}

$form_state_key = 'planning_form_state';
$request_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['clear_service'])) {
        $_SESSION[$form_state_key] = [
            'service_date' => '',
        ];
        header('Location: planning.php', true, 303);
        exit;
    }

    if (isset($_POST['add_service'])) {
        $_SESSION[$form_state_key] = $_POST;
        header('Location: planning.php', true, 303);
        exit;
    }

    if (isset($_POST['auto_preview'])) {
        $request_data = $_POST;
    } else {
        $_SESSION[$form_state_key] = $_POST;
        header('Location: planning.php', true, 303);
        exit;
    }
}

if ($request_data === []) {
    $request_data = $_SESSION[$form_state_key] ?? [];
    unset($_SESSION[$form_state_key]);
}

$selected_date = oflc_request_value($request_data, 'service_date', '');
$liturgical_window = null;
$selected_movable_matches = [];
$selected_fixed_matches = [];
$combined_window_options = [];
$service_option_choices = [];
$selected_option_key = oflc_request_value($request_data, 'option_key');
$selected_service_setting = oflc_request_value($request_data, 'service_setting');
$selected_preacher = oflc_request_value($request_data, 'preacher');
$selected_hymns = [];
$selected_option_detail = null;
$selected_option_date = null;
$selected_service_date_obj = DateTimeImmutable::createFromFormat('Y-m-d', $selected_date) ?: null;
$selected_option_is_sunday = $selected_service_date_obj instanceof DateTimeImmutable
    ? $selected_service_date_obj->format('w') === '0'
    : false;
$selected_option_previous_thursday_label = null;
$logic_columns_ready = false;
$date_error = null;
$default_reading_set_year = $selected_date !== '' ? (int) date('Y', strtotime($selected_date)) : (int) date('Y');
$default_reading_set_index = $default_reading_set_year % 2 === 0 ? 1 : 0;
$hymn_suggestions = oflc_fetch_hymn_suggestions($pdo);
$service_settings = oflc_fetch_service_settings($pdo);
$hymn_slots = oflc_fetch_hymn_slots($pdo);
$service_settings_by_id = [];
$logic_key_name_map = [];

foreach ($service_settings as $service_setting) {
    $service_settings_by_id[(string) $service_setting['id']] = $service_setting;
}

for ($hymn_index = 1; $hymn_index <= 8; $hymn_index++) {
    $selected_hymns[$hymn_index] = oflc_request_value($request_data, 'hymn_' . $hymn_index);
}

$logic_columns_ready = oflc_planning_logic_columns_ready($pdo);

if ($selected_date !== '') {
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
        foreach ($liturgical_window['entries'] as $entry) {
            $festival_keys = oflc_resolve_fixed_logic_keys($entry['month'], $entry['day']);
            foreach ($festival_keys as $logic_key) {
                $service_option_choices[$logic_key] = [
                    'logic_key' => $logic_key,
                    'label' => ($logic_key_name_map[$logic_key] ?? oflc_humanize_logic_key($logic_key)) . ' (' . date('D, m/d', strtotime($entry['date'])) . ')',
                    'date' => $entry['date'],
                ];
            }

            if ($entry['is_sunday']) {
                $sunday_keys = oflc_resolve_movable_logic_keys($entry['week'], 0);
                foreach ($sunday_keys as $logic_key) {
                    $service_option_choices[$logic_key] = [
                        'logic_key' => $logic_key,
                        'label' => ($logic_key_name_map[$logic_key] ?? oflc_humanize_logic_key($logic_key)) . ' (' . date('m/d', strtotime($entry['date'])) . ')',
                        'date' => $entry['date'],
                    ];
                }
            }
        }

        uasort($service_option_choices, static function (array $first, array $second): int {
            $date_compare = strcmp($first['date'], $second['date']);
            if ($date_compare !== 0) {
                return $date_compare;
            }

            return strcmp($first['label'], $second['label']);
        });
    }

    if ($selected_option_key !== '' && $logic_columns_ready) {
        $selected_option_detail = oflc_fetch_observance_detail($pdo, $selected_option_key);
    }

    if ($selected_option_key !== '' && isset($service_option_choices[$selected_option_key])) {
        $selected_option_date = $service_option_choices[$selected_option_key]['date'];
        $selected_option_date_obj = DateTimeImmutable::createFromFormat('Y-m-d', $selected_option_date);

        if ($selected_option_date_obj instanceof DateTimeImmutable) {
            $default_reading_set_year = (int) $selected_option_date_obj->format('Y');
            $default_reading_set_index = $default_reading_set_year % 2 === 0 ? 1 : 0;
        }
    }
}

if ($selected_option_is_sunday && $selected_service_date_obj instanceof DateTimeImmutable) {
    $selected_option_previous_thursday_label = $selected_service_date_obj->modify('-3 days')->format('m/d');
}

$selected_service_setting_detail = $selected_service_setting !== '' && isset($service_settings_by_id[$selected_service_setting])
    ? $service_settings_by_id[$selected_service_setting]
    : null;

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

include 'includes/header.php';
?>

<div id="planner-root">
<h3>Add a Service</h3>

<p>Use this form to add a service to the schedule.</p>

<?php if ($date_error !== null): ?>
    <p class="planning-error"><?php echo htmlspecialchars($date_error, ENT_QUOTES, 'UTF-8'); ?></p>
<?php else: ?>
    <form id="add-service-form" class="service-card <?php echo htmlspecialchars($service_card_color_class, ENT_QUOTES, 'UTF-8'); ?>" method="post" action="planning.php">
        <input type="hidden" name="auto_preview" id="auto-preview-flag" value="">
        <?php if ($hymn_suggestions !== []): ?>
            <datalist id="hymn-options">
                <?php foreach ($hymn_suggestions as $suggestion): ?>
                    <option value="<?php echo htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8'); ?>"></option>
                <?php endforeach; ?>
            </datalist>
        <?php endif; ?>
        <div class="service-card-grid">
            <section class="service-card-panel">
                <div class="service-card-date-row">
                    <input type="date" id="service_date" name="service_date" class="service-card-text" value="<?php echo htmlspecialchars($selected_date, ENT_QUOTES, 'UTF-8'); ?>" onchange="oflcSubmitPlannerPreview(this.form, true);">
                </div>
                <div class="service-card-display-date"><?php echo $selected_date_display === '&nbsp;' ? '&nbsp;' : htmlspecialchars($selected_date_display, ENT_QUOTES, 'UTF-8'); ?></div>
                <select id="option_key" name="option_key" class="service-card-select" onchange="oflcSubmitPlannerPreview(this.form, true)">
                    <option value="">Select an option</option>
                    <?php foreach ($service_option_choices as $choice): ?>
                        <option value="<?php echo htmlspecialchars($choice['logic_key'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selected_option_key === $choice['logic_key'] ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($choice['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="service-card-latin-name">
                    <?php
                    $latin_name = trim((string) ($selected_option_detail['observance']['latin_name'] ?? ''));
                    echo $latin_name !== ''
                        ? htmlspecialchars($latin_name, ENT_QUOTES, 'UTF-8')
                        : '&nbsp;';
                    ?>
                </div>
                <div class="service-card-meta">
                    <select id="service_setting" name="service_setting" class="service-card-select">
                        <option value="">Select a service</option>
                        <?php foreach ($service_settings as $service_setting): ?>
                            <option
                                value="<?php echo htmlspecialchars((string) $service_setting['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-abbreviation="<?php echo htmlspecialchars(trim((string) ($service_setting['abbreviation'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                                data-page-number="<?php echo htmlspecialchars(trim((string) ($service_setting['page_number'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $selected_service_setting === (string) $service_setting['id'] ? ' selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars((string) $service_setting['setting_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="service-card-service-summary" id="service-setting-summary"><?php echo $selected_service_setting_summary === '&nbsp;' ? '&nbsp;' : htmlspecialchars($selected_service_setting_summary, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div><?php echo htmlspecialchars(oflc_get_liturgical_color_display($selected_option_detail['observance']['liturgical_color'] ?? null), ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if ($selected_option_is_sunday && $selected_option_previous_thursday_label !== null): ?>
                        <label class="service-card-checkbox">
                            <input type="checkbox" name="copy_to_previous_thursday" value="1"<?php echo isset($request_data['copy_to_previous_thursday']) ? ' checked' : ''; ?>>
                            <span>Copy this service to the previous Thursday (<?php echo htmlspecialchars($selected_option_previous_thursday_label, ENT_QUOTES, 'UTF-8'); ?>)?</span>
                        </label>
                    <?php endif; ?>
                </div>
            </section>

            <section class="service-card-panel">
                <div class="service-card-readings">
                    <?php if ($selected_option_detail !== null && count($selected_option_detail['reading_sets']) > 0): ?>
                        <?php $reading_sets_to_show = array_slice($selected_option_detail['reading_sets'], 0, 2); ?>
                        <?php $effective_default_reading_set_index = count($reading_sets_to_show) === 1 ? 0 : min($default_reading_set_index, count($reading_sets_to_show) - 1); ?>
                        <?php foreach ($reading_sets_to_show as $reading_index => $reading_set): ?>
                            <div class="service-card-reading-set<?php echo $reading_index > 0 ? ' service-card-reading-set-secondary' : ''; ?>">
                                <?php $psalm_text = oflc_clean_reading_text($reading_set['psalm'] ?? null, true); ?>
                                <?php if ($psalm_text !== ''): ?>
                                    <label class="service-card-reading-psalm">
                                        <input
                                            type="radio"
                                            name="selected_reading_set"
                                            value="<?php echo htmlspecialchars((string) $reading_index, ENT_QUOTES, 'UTF-8'); ?>"
                                            class="service-card-reading-radio"
                                            <?php echo (!isset($request_data['selected_reading_set']) && $reading_index === $effective_default_reading_set_index) || (isset($request_data['selected_reading_set']) && (string) $request_data['selected_reading_set'] === (string) $reading_index) ? 'checked' : ''; ?>
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
                <input type="text" id="preacher" name="preacher" class="service-card-text" value="<?php echo htmlspecialchars($selected_preacher, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Fenker">
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

    formData.delete('auto_preview');
    formData.set('clear_service', '1');

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
    var labels = root.querySelectorAll('.service-card-reading-psalm');
    var select = root.querySelector('#service_setting');
    var summary = root.querySelector('#service-setting-summary');
    var hymnPane = root.querySelector('#service-card-hymns');
    var hymnSuggestionsId = 'hymn-options';
    var hymnDefinitionsByService = <?php echo json_encode($hymn_field_definitions_by_service, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var hymnState = <?php
        echo json_encode([
            'hymns' => array_map('strval', $selected_hymns),
            'opening_processional' => isset($request_data['opening_processional']),
            'closing_recessional' => isset($request_data['closing_recessional']),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?>;

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
            }

            delete radio.dataset.wasChecked;
        });
    });

    if (!select || !summary || !hymnPane) {
        return;
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
        var option = select.options[select.selectedIndex];

        if (!option || !option.value) {
            summary.innerHTML = '&nbsp;';
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

        summary.textContent = text !== '' ? text : ' ';
    }

    select.addEventListener('change', updateSummary);
    select.addEventListener('change', function () {
        captureHymnState();
        renderHymnPane(select.value);
    });
    bindHymnLookupBehavior(document);
    updateSummary();
    renderHymnPane(select.value);
};

window.oflcInitializePlannerUI(document);
</script>

<?php include 'includes/footer.php'; ?>
