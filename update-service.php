<?php
declare(strict_types=1);

$page_title = 'Update a Service';
require_once __DIR__ . '/includes/db.php';
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
    foreach ($definitions as $definition) {
        $hymns[(int) $definition['index']] = '';
    }

    $state = [
        'hymns' => $hymns,
        'opening_processional' => false,
        'closing_recessional' => false,
    ];

    $remainingRows = [];

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
        }

        if ($targetIndex !== null && isset($state['hymns'][$targetIndex]) && $state['hymns'][$targetIndex] === '') {
            $state['hymns'][$targetIndex] = $label;
            continue;
        }

        $remainingRows[] = $label;
    }

    foreach ($remainingRows as $label) {
        foreach ($state['hymns'] as $index => $value) {
            if ($value === '') {
                $state['hymns'][$index] = $label;
                break;
            }
        }
    }

    return $state;
}

function oflc_update_build_form_state_from_service(array $service, array $usageRows, ?array $serviceSettingDetail, array $hymnSlots): array
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
        'new_reading_sets' => oflc_service_normalize_new_reading_set_drafts([]),
        'selected_new_reading_set' => '',
        'selected_hymns' => $hymnState['hymns'],
        'opening_processional' => $hymnState['opening_processional'],
        'closing_recessional' => $hymnState['closing_recessional'],
        'copy_to_previous_thursday' => false,
        'link_action' => '',
    ];
}

function oflc_update_merge_form_state(array $baseState, array $overrideState): array
{
    foreach (['service_date', 'observance_id', 'observance_name', 'new_observance_color', 'service_setting', 'selected_reading_set_id', 'preacher', 'thursday_preacher', 'selected_new_reading_set'] as $key) {
        if (isset($overrideState[$key])) {
            $baseState[$key] = $overrideState[$key];
        }
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
    }
}

$hymnCatalog = oflc_update_fetch_hymn_catalog($pdo);
$hymnFieldDefinitionsByService = ['' => []];
$liturgicalColorOptions = oflc_get_liturgical_color_options();
foreach ($serviceSettings as $serviceSetting) {
    $serviceId = (string) $serviceSetting['id'];
    $hymnFieldDefinitionsByService[$serviceId] = oflc_update_build_hymn_field_definitions($serviceSetting, $hymnSlots);
}

$formStateByServiceId = [];
$formErrorsByServiceId = [];
$activeExpandedServiceId = isset($_GET['expanded_service']) ? (int) $_GET['expanded_service'] : 0;
$updatedServiceId = isset($_GET['updated_service']) ? (int) $_GET['updated_service'] : 0;

if ($requestMethod === 'POST' && isset($_POST['update_service'])) {
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
        'new_reading_sets' => oflc_service_normalize_new_reading_set_drafts($_POST),
        'preacher' => oflc_update_request_value($_POST, 'preacher'),
        'thursday_preacher' => oflc_update_request_value($_POST, 'thursday_preacher'),
        'selected_hymns' => [],
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

    $leaderLastName = $submittedState['preacher'];
    $leaderId = null;
    if ($leaderLastName !== '') {
        if (!isset($leadersByLastName[$leaderLastName]) || (int) $leadersByLastName[$leaderLastName] <= 0) {
            $errors[] = 'Leader must match an active last name.';
        } else {
            $leaderId = (int) $leadersByLastName[$leaderLastName];
        }
    }

    $thursdayLeaderLastName = $submittedState['thursday_preacher'];
    $thursdayLeaderId = null;
    $hasExplicitThursdayLeader = false;
    if ($thursdayLeaderLastName !== '') {
        $hasExplicitThursdayLeader = true;
        if (!isset($leadersByLastName[$thursdayLeaderLastName]) || (int) $leadersByLastName[$thursdayLeaderLastName] <= 0) {
            $errors[] = 'Thursday leader must match an active last name.';
        } else {
            $thursdayLeaderId = (int) $leadersByLastName[$thursdayLeaderLastName];
        }
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
    $hymnEntries = [];
    foreach ($definitions as $definition) {
        $index = (int) ($definition['index'] ?? 0);
        $hymnValue = $submittedState['selected_hymns'][$index] ?? '';
        $hymnId = oflc_update_resolve_hymn_id($hymnValue, $hymnCatalog['lookup_by_key']);

        if (trim($hymnValue) === '') {
            continue;
        }

        if ($hymnId === null) {
            $errors[] = 'Hymn field ' . $index . ' must match a hymn from the suggestions.';
            continue;
        }

        $slotName = (string) ($definition['slot_name'] ?? '');
        if (($definition['toggle_name'] ?? null) === 'opening_processional' && $submittedState['opening_processional']) {
            $slotName = 'Processional Hymn';
        }
        if (($definition['toggle_name'] ?? null) === 'closing_recessional' && $submittedState['closing_recessional']) {
            $slotName = 'Recessional Hymn';
        }

        if (!isset($hymnSlots[$slotName]['id'])) {
            $errors[] = 'Missing hymn slot configuration for ' . $slotName . '.';
            continue;
        }

        $hymnEntries[] = [
            'hymn_id' => $hymnId,
            'slot_id' => (int) $hymnSlots[$slotName]['id'],
            'sort_order' => (int) ($definition['sort_order'] ?? 1),
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

            header('Location: update-service.php?' . http_build_query([
                'expanded_service' => (string) $serviceId,
                'updated_service' => (string) $serviceId,
            ]), true, 303);
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
     ORDER BY s.service_date ASC, s.service_order ASC, s.id ASC'
);
$services = $serviceStatement->fetchAll();

$serviceIds = array_map(static function (array $row): int {
    return (int) $row['id'];
}, $services);

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

$activeObservanceDetails = oflc_service_fetch_active_observance_details($pdo);
$observanceCatalogPayload = oflc_update_build_observance_catalog_payload($activeObservanceDetails);
$dateObservanceSuggestionsCache = [];

include 'includes/header.php';
?>

<h3>Update a Service</h3>

<?php if ($hymnCatalog['suggestions'] !== []): ?>
    <datalist id="hymn-options">
        <?php foreach ($hymnCatalog['suggestions'] as $suggestion): ?>
            <option value="<?php echo htmlspecialchars($suggestion, ENT_QUOTES, 'UTF-8'); ?>"></option>
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
            $combinedDate = oflc_update_format_combined_service_date($group);
            $colorClass = oflc_update_get_liturgical_color_text_class($primaryService['liturgical_color'] ?? null);
            $observanceName = trim((string) ($primaryService['observance_name'] ?? ''));
            $summaryDate = $combinedDate !== '' ? $combinedDate : 'Undated service';
            $summaryObservance = $observanceName !== '' ? $observanceName : 'Unassigned observance';
            $groupServiceIds = array_map(static function (array $service): int {
                return (int) ($service['id'] ?? 0);
            }, $group);
            $rowIsOpen = $activeExpandedServiceId > 0 && in_array($activeExpandedServiceId, $groupServiceIds, true);
            ?>
            <details class="update-service-row <?php echo htmlspecialchars($colorClass, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $rowIsOpen ? ' open' : ''; ?>>
                <summary class="update-service-summary">
                    <span class="update-service-summary-text">
                        <?php echo htmlspecialchars($summaryDate, ENT_QUOTES, 'UTF-8'); ?>
                        <span class="update-service-summary-separator" aria-hidden="true">&bull;</span>
                        <?php echo htmlspecialchars($summaryObservance, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </summary>
                <div class="update-service-forms">
                    <?php foreach (oflc_update_build_group_edit_targets($group) as $editTarget): ?>
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
                            $hymnSlots
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
                        ?>
                        <div class="update-service-form-wrap" id="service-<?php echo $serviceId; ?>">
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
                                data-hymn-suggestions-id="hymn-options"
                            >
                                <input type="hidden" name="update_service" value="1">
                                <input type="hidden" name="service_id" value="<?php echo $serviceId; ?>">
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
                                        <div class="service-card-readings js-observance-readings"><?php echo count($selectedOptionDetail['reading_sets'] ?? []) > 0 ? oflc_update_render_observance_readings_html($selectedOptionDetail, $selectedReadingSetId) : oflc_update_render_new_reading_set_editor_html($formState['new_reading_sets'] ?? oflc_service_normalize_new_reading_set_drafts([]), (string) ($formState['selected_new_reading_set'] ?? '')); ?></div>
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

<script>
(function () {
    var hymnDefinitionsByService = <?php echo json_encode($hymnFieldDefinitionsByService, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var observanceCatalog = <?php echo json_encode($observanceCatalogPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var emptyReadingDrafts = <?php echo json_encode(array_values(oflc_service_normalize_new_reading_set_drafts([])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function initializeHymnLookupBehavior(scope, hymnSuggestionsId) {
        var hymnInputs = scope.querySelectorAll('.service-card-hymn-lookup');

        Array.prototype.forEach.call(hymnInputs, function (input) {
            input.addEventListener('focus', function () {
                input.removeAttribute('list');
            });

            input.addEventListener('input', function () {
                if (input.value.trim() === '') {
                    input.removeAttribute('list');
                    return;
                }

                if (hymnSuggestionsId) {
                    input.setAttribute('list', hymnSuggestionsId);
                }
            });

            input.addEventListener('blur', function () {
                window.setTimeout(function () {
                    input.removeAttribute('list');
                }, 0);
            });
        });
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

    function initializeUpdateServiceForm(form) {
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
        var dateObservanceSuggestions = [];
        var allObservanceSuggestions = Array.prototype.map.call(Object.keys(observanceCatalog.by_id || {}), function (key) {
            return observanceCatalog.by_id[key] && observanceCatalog.by_id[key].name ? observanceCatalog.by_id[key].name : '';
        });
        var selectedReadingSetId = form.getAttribute('data-selected-reading-set-id') || '';
        var selectedNewReadingSet = form.getAttribute('data-selected-new-reading-set') || '';
        var readingDraftState = cloneReadingDrafts([]);
        var hymnState = {
            hymns: {},
            opening_processional: false,
            closing_recessional: false
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

        if (!settingSelect || !settingSummary || !hymnPane || !observanceSuggestionList || !newObservanceColorWrap || !newObservanceColorSelect) {
            return;
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

        function captureHymnState() {
            var inputs = hymnPane.querySelectorAll('.service-card-hymn-lookup');
            var openingProcessional = hymnPane.querySelector('input[name="opening_processional"]');
            var closingRecessional = hymnPane.querySelector('input[name="closing_recessional"]');

            hymnState.hymns = {};
            Array.prototype.forEach.call(inputs, function (input) {
                hymnState.hymns[input.name.replace('hymn_', '')] = input.value;
            });

            hymnState.opening_processional = !!(openingProcessional && openingProcessional.checked);
            hymnState.closing_recessional = !!(closingRecessional && closingRecessional.checked);
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
                        '<label class="service-card-hymn-inline-toggle">' +
                            '<input type="checkbox" name="' + escapeHtml(definition.toggle_name) + '" value="1"' + (isChecked ? ' checked' : '') + '>' +
                        '</label>';
                }

                html +=
                    '<div class="service-card-hymn-row">' +
                        '<input type="text" name="hymn_' + hymnIndex + '" value="' + escapeHtml(value) + '" placeholder="' + escapeHtml(definition.label) + '" data-list-id="' + escapeHtml(hymnSuggestionsId) + '" autocomplete="off" class="service-card-hymn-lookup">' +
                        toggleHtml +
                    '</div>';
            });

            hymnPane.innerHTML = html;
            initializeHymnLookupBehavior(hymnPane, hymnSuggestionsId);

            var openingProcessional = hymnPane.querySelector('input[name="opening_processional"]');
            var closingRecessional = hymnPane.querySelector('input[name="closing_recessional"]');

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

        function renderNewReadingSetEditor() {
            var html = '<div class="update-service-reading-editor-note">No appointed readings are stored for this observance yet.</div>';

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
        }

        function renderBlankReadingsPane() {
            if (!readingsPane) {
                return;
            }

            selectedReadingSetId = '';
            selectedNewReadingSet = '';
            form.setAttribute('data-selected-reading-set-id', '');
            form.setAttribute('data-selected-new-reading-set', '');
            readingsPane.innerHTML = '&nbsp;';
        }

        function renderReadingsPane(readingSets) {
            var html = '';
            var hasSelectedReadingSet = false;

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
                form.setAttribute('data-selected-reading-set-id', '');
            }

            selectedNewReadingSet = '';
            form.setAttribute('data-selected-new-reading-set', '');

            readingsPane.innerHTML = html;
            bindReadingSelectionBehavior(readingsPane, function (value) {
                selectedReadingSetId = value || '';
                form.setAttribute('data-selected-reading-set-id', selectedReadingSetId);
            });
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

        initializeHymnLookupBehavior(form, hymnSuggestionsId);
        hideObservanceSuggestionOptions();
        updateSettingSummary();
        updateObservanceDetails(false);
        syncPreviousThursdayState();

        settingSelect.addEventListener('change', function () {
            captureHymnState();
            updateSettingSummary();
            renderHymnPane(settingSelect.value);
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
}());
</script>

<?php include 'includes/footer.php'; ?>
