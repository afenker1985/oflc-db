<?php
declare(strict_types=1);

$page_title = 'Service Schedule';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/church_year.php';

$oflcScheduleEmbedded = $oflcScheduleEmbedded ?? false;
$oflcScheduleShowHeading = $oflcScheduleShowHeading ?? true;
$oflcScheduleShowPrintLink = $oflcScheduleShowPrintLink ?? true;
$oflcScheduleShowFilters = $oflcScheduleShowFilters ?? true;
$oflcScheduleShowDuplicateTuneWarnings = $oflcScheduleShowDuplicateTuneWarnings ?? true;

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

function oflc_parse_schedule_filter_date(string $value): ?DateTimeImmutable
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

function oflc_format_schedule_date(string $date): array
{
    $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$dateObject instanceof DateTimeImmutable) {
        return [
            'display' => $date,
            'weekday' => '',
        ];
    }

    return [
        'display' => $dateObject->format('l, F j, Y'),
        'weekday' => $dateObject->format('D'),
    ];
}

function oflc_clean_reading_text($text, bool $removeAntiphon = false): string
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

function oflc_select_reading_set(array $readingSets, string $serviceDate): ?array
{
    if ($readingSets === []) {
        return null;
    }

    if (count($readingSets) === 1) {
        return $readingSets[0];
    }

    $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $serviceDate);
    $desiredPattern = $dateObject instanceof DateTimeImmutable && ((int) $dateObject->format('Y') % 2 === 0)
        ? 'even'
        : 'odd';

    foreach ($readingSets as $readingSet) {
        if (strtolower(trim((string) ($readingSet['year_pattern'] ?? ''))) === $desiredPattern) {
            return $readingSet;
        }
    }

    foreach ($readingSets as $readingSet) {
        if (trim((string) ($readingSet['year_pattern'] ?? '')) === '') {
            return $readingSet;
        }
    }

    return $readingSets[0];
}

function oflc_format_hymn_label(array $row): string
{
    $hymnal = trim((string) ($row['hymnal'] ?? ''));
    $hymnNumber = trim((string) ($row['hymn_number'] ?? ''));
    $title = trim((string) ($row['hymn_title'] ?? ''));
    $insertUse = (int) ($row['insert_use'] ?? 0) === 1;

    if ($hymnal === 'LSB' && $hymnNumber !== '') {
        return $hymnNumber . ($insertUse ? '*' : '');
    }

    if ($hymnal !== '' && $hymnNumber !== '') {
        return $hymnal . ' ' . $hymnNumber . ($insertUse ? '*' : '');
    }

    if ($hymnNumber !== '') {
        return $hymnNumber . ($insertUse ? '*' : '');
    }

    $label = $title !== '' ? $title : 'Untitled hymn';

    return $label . ($insertUse ? '*' : '');
}

function oflc_normalize_hymn_tune($value): string
{
    return strtolower(trim((string) $value));
}

function oflc_is_sunday_service(array $service): bool
{
    $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($service['service_date'] ?? ''));

    return $dateObject instanceof DateTimeImmutable && $dateObject->format('w') === '0';
}

function oflc_collect_duplicate_sunday_tunes(array $services, array $hymnsByService): array
{
    $tuneToHymnIds = [];

    foreach ($services as $service) {
        if (!oflc_is_sunday_service($service)) {
            continue;
        }

        $serviceId = (int) ($service['id'] ?? 0);
        foreach ($hymnsByService[$serviceId] ?? [] as $hymn) {
            $hymnId = (int) ($hymn['hymn_id'] ?? 0);
            $tuneKey = oflc_normalize_hymn_tune($hymn['tune'] ?? '');
            if ($hymnId <= 0 || $tuneKey === '') {
                continue;
            }

            if (!isset($tuneToHymnIds[$tuneKey])) {
                $tuneToHymnIds[$tuneKey] = [];
            }
            $tuneToHymnIds[$tuneKey][$hymnId] = true;
        }
    }

    $duplicates = [];
    foreach ($tuneToHymnIds as $tuneKey => $hymnIds) {
        if (count($hymnIds) > 1) {
            $duplicates[$tuneKey] = true;
        }
    }

    return $duplicates;
}

function oflc_group_schedule_services(array $services): array
{
    $groups = [];

    foreach ($services as $service) {
        $groupCount = count($groups);
        if ($groupCount === 0) {
            $groups[] = [$service];
            continue;
        }

        $lastGroupIndex = $groupCount - 1;
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

function oflc_format_combined_service_date(array $services): string
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

function oflc_format_combined_leader_name(array $services): string
{
    $entries = [];

    foreach ($services as $service) {
        $lastName = trim((string) ($service['leader_last_name'] ?? ''));
        if ($lastName === '') {
            continue;
        }

        $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($service['service_date'] ?? ''));
        $isThursday = $dateObject instanceof DateTimeImmutable && $dateObject->format('w') === '4';
        $key = $lastName . '|' . ($isThursday ? 'th' : 'other');
        $entries[$key] = [
            'name' => $lastName,
            'is_thursday' => $isThursday,
            'date' => $dateObject instanceof DateTimeImmutable ? $dateObject->format('Y-m-d') : '',
        ];
    }

    if ($entries === []) {
        return '';
    }

    $uniqueNames = array_values(array_unique(array_map(static function (array $entry): string {
        return $entry['name'];
    }, $entries)));

    if (count($uniqueNames) === 1) {
        return $uniqueNames[0];
    }

    usort($entries, static function (array $first, array $second): int {
        if ($first['is_thursday'] !== $second['is_thursday']) {
            return $first['is_thursday'] ? -1 : 1;
        }

        return strcmp($first['date'], $second['date']);
    });

    return implode(' ', array_map(static function (array $entry): string {
        return $entry['name'] . ($entry['is_thursday'] ? ' (Th)' : '');
    }, $entries));
}

function oflc_merge_group_hymns(array $services, array $hymnsByService): array
{
    $merged = [];
    $indexByLabel = [];
    $duplicateSundayTunes = oflc_collect_duplicate_sunday_tunes($services, $hymnsByService);

    foreach ($services as $service) {
        $serviceId = (int) ($service['id'] ?? 0);
        $isSunday = oflc_is_sunday_service($service);
        foreach ($hymnsByService[$serviceId] ?? [] as $hymn) {
            $label = (string) ($hymn['label'] ?? '');
            if ($label === '') {
                continue;
            }

            if (!isset($indexByLabel[$label])) {
                $indexByLabel[$label] = count($merged);
                $merged[] = [
                    'label' => $label,
                    'duplicate_tune' => false,
                ];
            }

            $tuneKey = oflc_normalize_hymn_tune($hymn['tune'] ?? '');
            if ($isSunday && $tuneKey !== '' && isset($duplicateSundayTunes[$tuneKey])) {
                $merged[$indexByLabel[$label]]['duplicate_tune'] = true;
            }
        }
    }

    return $merged;
}

function oflc_group_within_date_range(array $group, ?DateTimeImmutable $startDate, ?DateTimeImmutable $endDate): bool
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
$hasExplicitRangeFilters = isset($_GET['rubric_year']) || isset($_GET['start_date']) || isset($_GET['end_date']);
$filterRubricYear = trim((string) ($_GET['rubric_year'] ?? ''));
$selectedRubricYearOption = oflc_church_year_find_filter_option($rubricYearOptions, $filterRubricYear);
$filterStartInput = trim((string) ($_GET['start_date'] ?? ''));
$filterEndInput = trim((string) ($_GET['end_date'] ?? ''));
$sortOrderInput = strtolower(trim((string) ($_GET['sort_order'] ?? 'asc')));
$sortOrder = $sortOrderInput === 'desc' ? 'desc' : 'asc';

if (!$hasExplicitRangeFilters) {
    $filterRubricYear = (string) ($currentRubricYearOption['key'] ?? '');
    $selectedRubricYearOption = $currentRubricYearOption;
    $filterStartInput = $currentMonthStartDate->format('Y-m-d');
    $filterEndInput = $currentMonthEndDate->format('Y-m-d');
}

if (
    $selectedRubricYearOption !== null
    && $filterStartInput === ''
    && $filterEndInput === ''
) {
    $filterStartInput = (string) $selectedRubricYearOption['start_date'];
    $filterEndInput = (string) $selectedRubricYearOption['end_date'];
}

$filterStartDate = oflc_parse_schedule_filter_date($filterStartInput);
$filterEndDate = oflc_parse_schedule_filter_date($filterEndInput);
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

$scheduleResetQuery = [
    'start_date' => $currentMonthStartDate->format('Y-m-d'),
    'end_date' => $currentMonthEndDate->format('Y-m-d'),
];
if ($currentRubricYearOption !== null) {
    $scheduleResetQuery['rubric_year'] = (string) $currentRubricYearOption['key'];
}
$scheduleResetUrl = 'schedule.php' . ($scheduleResetQuery !== [] ? '?' . http_build_query($scheduleResetQuery) : '');

$serviceStatement = $pdo->query(
    'SELECT
        s.id,
        s.service_date,
        s.service_order,
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
     ORDER BY s.service_date ' . ($sortOrder === 'desc' ? 'DESC' : 'ASC') . ', s.service_order ' . ($sortOrder === 'desc' ? 'DESC' : 'ASC') . ', s.id ' . ($sortOrder === 'desc' ? 'DESC' : 'ASC')
);
$services = $serviceStatement->fetchAll();

$serviceIds = array_map(static function (array $row): int {
    return (int) $row['id'];
}, $services);

$liturgicalCalendarIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
    return (int) ($row['liturgical_calendar_id'] ?? 0);
}, $services))));

$readingSetsByCalendar = [];
if ($liturgicalCalendarIds !== []) {
    $placeholders = implode(', ', array_fill(0, count($liturgicalCalendarIds), '?'));
    $readingStatement = $pdo->prepare(
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
    $readingStatement->execute($liturgicalCalendarIds);

    foreach ($readingStatement->fetchAll() as $readingRow) {
        $calendarId = (int) $readingRow['liturgical_calendar_id'];
        if (!isset($readingSetsByCalendar[$calendarId])) {
            $readingSetsByCalendar[$calendarId] = [];
        }

        $readingSetsByCalendar[$calendarId][] = $readingRow;
    }
}

$hymnsByService = [];
if ($serviceIds !== []) {
    $placeholders = implode(', ', array_fill(0, count($serviceIds), '?'));
    $hymnStatement = $pdo->prepare(
        'SELECT
            hu.sunday_id AS service_id,
            hu.sort_order,
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
         ORDER BY hu.sunday_id ASC, hu.slot_id ASC, hu.sort_order ASC, hu.id ASC'
    );
    $hymnStatement->execute($serviceIds);

    foreach ($hymnStatement->fetchAll() as $hymnRow) {
        $serviceId = (int) $hymnRow['service_id'];
        if (!isset($hymnsByService[$serviceId])) {
            $hymnsByService[$serviceId] = [];
        }

        $hymnsByService[$serviceId][] = [
            'hymn_id' => (int) ($hymnRow['hymn_id'] ?? 0),
            'label' => oflc_format_hymn_label($hymnRow),
            'tune' => trim((string) ($hymnRow['hymn_tune'] ?? '')),
        ];
    }
}

$scheduleGroups = oflc_group_schedule_services($services);
if ($scheduleFilterError === null) {
    $scheduleGroups = array_values(array_filter($scheduleGroups, static function (array $group) use ($filterStartDate, $filterEndDate): bool {
        return oflc_group_within_date_range($group, $filterStartDate, $filterEndDate);
    }));
}

$scheduleHasDuplicateTuneWarnings = false;
foreach ($scheduleGroups as $group) {
    if (oflc_collect_duplicate_sunday_tunes($group, $hymnsByService) !== []) {
        $scheduleHasDuplicateTuneWarnings = true;
        break;
    }
}

$printScheduleQuery = [];
if ($filterStartInput !== '') {
    $printScheduleQuery['start_date'] = $filterStartInput;
}
if ($filterEndInput !== '') {
    $printScheduleQuery['end_date'] = $filterEndInput;
}
if ($selectedRubricYearOption !== null) {
    $printScheduleQuery['rubric_year'] = $filterRubricYear;
}
$printScheduleQuery['sort_order'] = $sortOrder;
$printScheduleUrl = 'print-schedule.php' . ($printScheduleQuery !== [] ? '?' . http_build_query($printScheduleQuery) : '');

if (!$oflcScheduleEmbedded) {
    include 'includes/header.php';
}
?>

<?php if ($oflcScheduleShowHeading): ?>
    <h3>Service Schedule</h3>
<?php endif; ?>

<div
    id="schedule-content-root"
    data-reset-url="<?php echo htmlspecialchars($scheduleResetUrl, ENT_QUOTES, 'UTF-8'); ?>"
    data-reset-rubric-year="<?php echo htmlspecialchars($currentRubricYearOption['key'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
    data-reset-start-date="<?php echo htmlspecialchars($currentMonthStartDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"
    data-reset-end-date="<?php echo htmlspecialchars($currentMonthEndDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"
    data-default-rubric-year="<?php echo htmlspecialchars($currentRubricYearOption['key'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
    data-default-start-date="<?php echo htmlspecialchars($currentMonthStartDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"
    data-default-end-date="<?php echo htmlspecialchars($currentMonthEndDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"
    data-reset-sort-order="asc"
>
<?php if ($oflcScheduleShowFilters): ?>
    <form method="get" action="schedule.php" class="schedule-filter-form" id="schedule-filter-form">
        <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sortOrder, ENT_QUOTES, 'UTF-8'); ?>" data-schedule-sort-input="1">
        <?php if ($rubricYearOptions !== []): ?>
            <label class="schedule-filter-field">
                <span>Schedule Year</span>
                <select name="rubric_year" data-rubric-year-select="1">
                    <option value="">Choose Year</option>
                    <?php foreach ($rubricYearOptions as $rubricYearOption): ?>
                        <option
                            value="<?php echo htmlspecialchars((string) $rubricYearOption['key'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-start-date="<?php echo htmlspecialchars((string) $rubricYearOption['start_date'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-end-date="<?php echo htmlspecialchars((string) $rubricYearOption['end_date'], ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $filterRubricYear === (string) $rubricYearOption['key'] ? ' selected' : ''; ?>
                        >
                            <?php echo htmlspecialchars((string) $rubricYearOption['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label class="schedule-filter-field">
            <span>Start Date</span>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($filterStartInput, ENT_QUOTES, 'UTF-8'); ?>" data-schedule-date-field="start">
        </label>
        <label class="schedule-filter-field">
            <span>End Date</span>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($filterEndInput, ENT_QUOTES, 'UTF-8'); ?>" data-schedule-date-field="end">
        </label>
        <div class="schedule-filter-actions">
            <button
                type="button"
                class="schedule-sort-button is-active schedule-sort-button-<?php echo htmlspecialchars($sortOrder, ENT_QUOTES, 'UTF-8'); ?>"
                data-schedule-sort-toggle="1"
                data-next-sort-order="<?php echo htmlspecialchars($sortOrder === 'asc' ? 'desc' : 'asc', ENT_QUOTES, 'UTF-8'); ?>"
                aria-label="<?php echo htmlspecialchars($sortOrder === 'asc' ? 'Switch to descending order' : 'Switch to ascending order', ENT_QUOTES, 'UTF-8'); ?>"
            >
                <?php echo $sortOrder === 'asc' ? 'Asc ↓' : 'Desc ↑'; ?>
            </button>
            <a href="<?php echo htmlspecialchars($scheduleResetUrl, ENT_QUOTES, 'UTF-8'); ?>" class="schedule-filter-reset" data-schedule-reset="1">Reset</a>
        </div>
    </form>
    <?php if ($scheduleFilterError !== null): ?>
        <p class="planning-error"><?php echo htmlspecialchars($scheduleFilterError, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($oflcScheduleShowPrintLink && $scheduleGroups !== []): ?>
    <p class="schedule-print-link">
        <a href="<?php echo htmlspecialchars($printScheduleUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="schedule-print-button">Print Schedule</a>
    </p>
    <?php if ($oflcScheduleShowDuplicateTuneWarnings && $scheduleHasDuplicateTuneWarnings): ?>
        <p class="schedule-duplicate-tune-warning">Highlighted hymns have the same tune.</p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($scheduleGroups !== []): ?>
    <div class="planning-table-wrap">
        <table class="planning-table schedule-table">
            <colgroup>
                <col class="schedule-col-date">
                <col class="schedule-col-readings">
                <col class="schedule-col-hymns">
                <col class="schedule-col-pastor">
            </colgroup>
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Readings</th>
                    <th scope="col">Hymns</th>
                    <th scope="col">Pastor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scheduleGroups as $group): ?>
                    <?php
                    $primaryService = $group[0];
                    $combinedDate = oflc_format_combined_service_date($group);
                    $serviceHymns = oflc_merge_group_hymns($group, $hymnsByService);
                    $calendarId = (int) ($primaryService['liturgical_calendar_id'] ?? 0);
                    $selectedReadingSet = oflc_select_reading_set($readingSetsByCalendar[$calendarId] ?? [], (string) $primaryService['service_date']);
                    $colorClass = oflc_get_liturgical_color_text_class($primaryService['liturgical_color'] ?? null);
                    $colorDisplay = oflc_get_liturgical_color_display($primaryService['liturgical_color'] ?? null);
                    $observanceName = trim((string) ($primaryService['observance_name'] ?? ''));
                    $latinName = trim((string) ($primaryService['latin_name'] ?? ''));
                    $abbreviation = trim((string) ($primaryService['abbreviation'] ?? ''));
                    $pageNumber = trim((string) ($primaryService['page_number'] ?? ''));
                    $leaderDisplay = oflc_format_combined_leader_name($group);
                    $serviceSummary = $abbreviation;
                    if ($pageNumber !== '') {
                        $serviceSummary .= ($serviceSummary !== '' ? ', ' : '') . 'p. ' . $pageNumber;
                    }
                    ?>
                    <tr class="<?php echo htmlspecialchars($colorClass, ENT_QUOTES, 'UTF-8'); ?>">
                        <td class="schedule-table-date">
                            <div class="schedule-primary-text"><?php echo $combinedDate !== '' ? htmlspecialchars($combinedDate, ENT_QUOTES, 'UTF-8') : '&nbsp;'; ?></div>
                            <div class="schedule-secondary-text">
                                <?php echo $observanceName !== '' ? htmlspecialchars($observanceName, ENT_QUOTES, 'UTF-8') : 'Unassigned observance'; ?>
                            </div>
                            <?php if ($latinName !== ''): ?>
                                <div class="schedule-secondary-text">
                                    <?php echo htmlspecialchars($latinName, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php endif; ?>
                            <div class="schedule-secondary-text">
                                <?php echo $serviceSummary !== '' ? htmlspecialchars($serviceSummary, ENT_QUOTES, 'UTF-8') : '&nbsp;'; ?>
                            </div>
                            <div class="schedule-secondary-text">
                                <?php echo $colorDisplay !== '' ? htmlspecialchars($colorDisplay, ENT_QUOTES, 'UTF-8') : '&nbsp;'; ?>
                            </div>
                        </td>
                        <td class="schedule-table-readings">
                            <?php if ($selectedReadingSet === null): ?>
                                <div class="schedule-secondary-text">No readings assigned.</div>
                            <?php else: ?>
                                <?php $psalm = oflc_clean_reading_text($selectedReadingSet['psalm'] ?? null, true); ?>
                                <?php $oldTestament = oflc_clean_reading_text($selectedReadingSet['old_testament'] ?? null); ?>
                                <?php $epistle = oflc_clean_reading_text($selectedReadingSet['epistle'] ?? null); ?>
                                <?php $gospel = oflc_clean_reading_text($selectedReadingSet['gospel'] ?? null); ?>
                                <div class="schedule-reading-list">
                                    <?php if ($psalm !== ''): ?>
                                        <div><?php echo htmlspecialchars($psalm, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                    <?php if ($oldTestament !== ''): ?>
                                        <div><?php echo htmlspecialchars($oldTestament, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                    <?php if ($epistle !== ''): ?>
                                        <div><?php echo htmlspecialchars($epistle, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                    <?php if ($gospel !== ''): ?>
                                        <div><?php echo htmlspecialchars($gospel, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="schedule-table-hymns">
                            <?php if ($serviceHymns === []): ?>
                                <div class="schedule-secondary-text">No hymns assigned.</div>
                            <?php else: ?>
                                <div class="planning-inline-list schedule-hymn-list">
                                    <?php foreach ($serviceHymns as $hymn): ?>
                                        <div class="schedule-hymn-item<?php echo $oflcScheduleShowDuplicateTuneWarnings && !empty($hymn['duplicate_tune']) ? ' schedule-hymn-item-duplicate-tune' : ''; ?>">
                                            <?php echo htmlspecialchars((string) ($hymn['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="schedule-table-pastor">
                            <?php echo $leaderDisplay !== '' ? htmlspecialchars($leaderDisplay, ENT_QUOTES, 'UTF-8') : '&nbsp;'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <p>No services are currently scheduled.</p>
<?php endif; ?>
</div>

<?php if (!$oflcScheduleEmbedded): ?>
<script>
(function () {
    var requestTimer = null;
    var requestController = null;
    var isProgrammaticUpdate = false;

    function getRoot() {
        return document.getElementById('schedule-content-root');
    }

    function getForm() {
        return document.getElementById('schedule-filter-form');
    }

    function buildUrlFromForm(form) {
        var params = new URLSearchParams(new FormData(form));
        var url = form.getAttribute('action') || 'schedule.php';
        var query = params.toString();

        return url + (query ? '?' + query : '');
    }

    function syncRoot(html, url) {
        var parser = new DOMParser();
        var nextDocument = parser.parseFromString(html, 'text/html');
        var nextRoot = nextDocument.getElementById('schedule-content-root');
        var currentRoot = getRoot();

        if (!nextRoot || !currentRoot) {
            window.location.href = url;
            return;
        }

        currentRoot.replaceWith(nextRoot);
        window.history.replaceState({}, '', url);
        bindScheduleFilters();
    }

    function requestSchedule(url) {
        if (requestController) {
            requestController.abort();
        }

        requestController = new AbortController();

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: requestController.signal
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Request failed');
            }

            return response.text();
        }).then(function (html) {
            syncRoot(html, url);
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                return;
            }

            window.location.href = url;
        });
    }

    function queueScheduleRequest(url, delay) {
        window.clearTimeout(requestTimer);
        requestTimer = window.setTimeout(function () {
            requestSchedule(url);
        }, delay);
    }

    function bindScheduleFilters() {
        var root = getRoot();
        var form = getForm();
        var rubricSelect;
        var startDateInput;
        var endDateInput;
        var sortOrderInput;
        var sortToggleButton;
        var resetLink;

        if (!root || !form) {
            return;
        }

        rubricSelect = form.querySelector('[data-rubric-year-select="1"]');
        startDateInput = form.querySelector('[data-schedule-date-field="start"]');
        endDateInput = form.querySelector('[data-schedule-date-field="end"]');
        sortOrderInput = form.querySelector('[data-schedule-sort-input="1"]');
        sortToggleButton = form.querySelector('[data-schedule-sort-toggle="1"]');
        resetLink = root.querySelector('[data-schedule-reset="1"]');

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            requestSchedule(buildUrlFromForm(form));
        });

        function isDefaultMonthWindow() {
            var defaultRubricYear = root.getAttribute('data-default-rubric-year') || '';
            var defaultStartDate = root.getAttribute('data-default-start-date') || '';
            var defaultEndDate = root.getAttribute('data-default-end-date') || '';

            return !!rubricSelect
                && !!startDateInput
                && !!endDateInput
                && rubricSelect.value === defaultRubricYear
                && startDateInput.value === defaultStartDate
                && endDateInput.value === defaultEndDate;
        }

        function expandSelectedRubricYear() {
            var selectedOption = rubricSelect ? rubricSelect.options[rubricSelect.selectedIndex] : null;

            if (!selectedOption || selectedOption.value === '') {
                return false;
            }

            if (!startDateInput || !endDateInput) {
                return false;
            }

            startDateInput.value = selectedOption.getAttribute('data-start-date') || '';
            endDateInput.value = selectedOption.getAttribute('data-end-date') || '';
            requestSchedule(buildUrlFromForm(form));

            return true;
        }

        if (rubricSelect) {
            rubricSelect.addEventListener('mousedown', function (event) {
                var selectedOption;

                if (!isDefaultMonthWindow()) {
                    return;
                }

                selectedOption = rubricSelect.options[rubricSelect.selectedIndex];
                if (!selectedOption || selectedOption.value === '') {
                    return;
                }

                if (
                    startDateInput
                    && endDateInput
                    && startDateInput.value === (selectedOption.getAttribute('data-start-date') || '')
                    && endDateInput.value === (selectedOption.getAttribute('data-end-date') || '')
                ) {
                    return;
                }

                event.preventDefault();
                expandSelectedRubricYear();
            });

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
                requestSchedule(buildUrlFromForm(form));
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
                queueScheduleRequest(buildUrlFromForm(form), 50);
            });
        });

        if (sortToggleButton) {
            sortToggleButton.addEventListener('click', function () {
                if (sortOrderInput) {
                    sortOrderInput.value = sortToggleButton.getAttribute('data-next-sort-order') || 'asc';
                }
                requestSchedule(buildUrlFromForm(form));
            });
        }

        if (resetLink) {
            resetLink.addEventListener('click', function (event) {
                var resetRubricYear = root.getAttribute('data-reset-rubric-year') || '';
                var resetStartDate = root.getAttribute('data-reset-start-date') || '';
                var resetEndDate = root.getAttribute('data-reset-end-date') || '';
                var resetSortOrder = root.getAttribute('data-reset-sort-order') || 'asc';

                event.preventDefault();
                isProgrammaticUpdate = true;
                if (rubricSelect) {
                    rubricSelect.value = resetRubricYear;
                }
                if (startDateInput) {
                    startDateInput.value = resetStartDate;
                }
                if (endDateInput) {
                    endDateInput.value = resetEndDate;
                }
                if (sortOrderInput) {
                    sortOrderInput.value = resetSortOrder;
                }
                isProgrammaticUpdate = false;
                requestSchedule(buildUrlFromForm(form));
            });
        }
    }

    bindScheduleFilters();
}());
</script>
<?php endif; ?>

<?php
if (!$oflcScheduleEmbedded) {
    include 'includes/footer.php';
}
?>
