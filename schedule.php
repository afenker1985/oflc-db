<?php
declare(strict_types=1);

$page_title = 'Service Schedule';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/db/service-db-read.php';
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

function oflc_schedule_has_midweek_marker(string $name): bool
{
    $normalizedName = strtolower(trim($name));

    return $normalizedName !== ''
        && (strpos($normalizedName, 'midweek') !== false || strpos($normalizedName, 'midwk') !== false);
}

function oflc_schedule_is_advent_midweek_observance(string $name): bool
{
    $normalizedName = strtolower(trim($name));

    return $normalizedName !== ''
        && strpos($normalizedName, 'advent') !== false
        && oflc_schedule_has_midweek_marker($normalizedName);
}

function oflc_schedule_is_lent_midweek_observance(string $name): bool
{
    $normalizedName = strtolower(trim($name));

    return $normalizedName !== ''
        && strpos($normalizedName, 'lent') !== false
        && oflc_schedule_has_midweek_marker($normalizedName);
}

function oflc_format_schedule_passion_reading(array $reading): string
{
    $gospel = trim((string) ($reading['gospel'] ?? ''));
    $reference = trim((string) ($reading['reference'] ?? ''));
    $body = trim(($gospel !== '' ? $gospel . ' ' : '') . $reference);
    return $body;
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

function oflc_format_schedule_hymn_label(array $row): string
{
    $label = oflc_format_hymn_label($row);
    $stanzas = trim((string) ($row['stanzas'] ?? ''));
    if ($stanzas !== '') {
        $stanzas = preg_replace('/\s+/', ' ', $stanzas) ?? $stanzas;
    }

    return $stanzas !== '' ? $label . ': ' . trim($stanzas) : $label;
}

function oflc_normalize_hymn_tune($value): string
{
    return strtolower(trim((string) $value));
}

function oflc_collect_duplicate_tunes(array $services, array $hymnsByService): array
{
    $tuneToHymnIds = [];

    foreach ($services as $service) {
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
        $weekdayPair = [];
        if ($lastDate instanceof DateTimeImmutable) {
            $weekdayPair[] = $lastDate->format('w');
        }
        if ($currentDate instanceof DateTimeImmutable) {
            $weekdayPair[] = $currentDate->format('w');
        }
        sort($weekdayPair);
        $isThursdaySundayPair = $lastDate instanceof DateTimeImmutable
            && $currentDate instanceof DateTimeImmutable
            && abs((int) $currentDate->diff($lastDate)->format('%r%a')) === 3
            && $weekdayPair === ['0', '4'];

        if ($sameObservance && $isThursdaySundayPair) {
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
        return htmlspecialchars($uniqueNames[0], ENT_QUOTES, 'UTF-8');
    }

    usort($entries, static function (array $first, array $second): int {
        if ($first['is_thursday'] !== $second['is_thursday']) {
            return $first['is_thursday'] ? -1 : 1;
        }

        return strcmp($first['date'], $second['date']);
    });

    return implode('<br />', array_map(static function (array $entry): string {
        return htmlspecialchars($entry['name'], ENT_QUOTES, 'UTF-8') . ($entry['is_thursday'] ? ' (Th)' : '');
    }, $entries));
}

function oflc_merge_group_hymns(array $services, array $hymnsByService): array
{
    $merged = [];
    $indexByLabel = [];
    $duplicateTunes = oflc_collect_duplicate_tunes($services, $hymnsByService);

    foreach ($services as $service) {
        $serviceId = (int) ($service['id'] ?? 0);
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
            if ($tuneKey !== '' && isset($duplicateTunes[$tuneKey])) {
                $merged[$indexByLabel[$label]]['duplicate_tune'] = true;
            }
        }
    }

    return $merged;
}

function oflc_collect_group_small_catechism_abbreviations(array $services, array $abbreviationsByService): array
{
    $abbreviations = [];

    foreach ($services as $service) {
        $serviceId = (int) ($service['id'] ?? 0);
        foreach ($abbreviationsByService[$serviceId] ?? [] as $abbreviation) {
            $abbreviation = trim((string) $abbreviation);
            if ($abbreviation !== '' && !in_array($abbreviation, $abbreviations, true)) {
                $abbreviations[] = $abbreviation;
            }
        }
    }

    return $abbreviations;
}

function oflc_collect_group_passion_readings(array $services, array $readingsById, array $idsByService): array
{
    $readings = [];

    foreach ($services as $service) {
        $serviceId = (int) ($service['id'] ?? 0);
        $serviceReadingIds = $idsByService[$serviceId] ?? [];
        if ($serviceReadingIds === []) {
            $fallbackId = (int) ($service['passion_reading_id'] ?? 0);
            if ($fallbackId > 0) {
                $serviceReadingIds[] = $fallbackId;
            }
        }

        foreach ($serviceReadingIds as $passionReadingId) {
            if ($passionReadingId > 0 && isset($readingsById[$passionReadingId])) {
                $readings[$passionReadingId] = $readingsById[$passionReadingId];
            }
        }
    }

    return array_values($readings);
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

$services = oflc_service_db_fetch_schedule_services($pdo, $sortOrder);

$serviceIds = array_map(static function (array $row): int {
    return (int) $row['id'];
}, $services);

$smallCatechismAbbreviationsByService = oflc_service_db_fetch_small_catechism_abbreviations_by_service($pdo, $serviceIds);
$passionReadingIdsByService = oflc_service_db_fetch_passion_reading_ids_by_service($pdo, $serviceIds);

$passionReadingIds = array_values(array_unique(array_filter(array_merge(
    array_map(static function (array $row): int {
        return (int) ($row['passion_reading_id'] ?? 0);
    }, $services),
    $passionReadingIdsByService === [] ? [] : array_merge(...array_values($passionReadingIdsByService))
))));
$passionReadingsById = oflc_service_db_fetch_passion_readings_by_id($pdo, $passionReadingIds);

$liturgicalCalendarIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
    return (int) ($row['liturgical_calendar_id'] ?? 0);
}, $services))));

$readingSetsByCalendar = oflc_service_db_fetch_reading_sets_by_calendar($pdo, $liturgicalCalendarIds);
$hymnsByService = oflc_service_db_fetch_schedule_hymns_by_service($pdo, $serviceIds, 'oflc_format_schedule_hymn_label');

$scheduleGroups = oflc_group_schedule_services($services);
if ($scheduleFilterError === null) {
    $scheduleGroups = array_values(array_filter($scheduleGroups, static function (array $group) use ($filterStartDate, $filterEndDate): bool {
        return oflc_group_within_date_range($group, $filterStartDate, $filterEndDate);
    }));
}

$scheduleHasDuplicateTuneWarnings = false;
foreach ($scheduleGroups as $group) {
    if (oflc_collect_duplicate_tunes($group, $hymnsByService) !== []) {
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
                <input type="hidden" name="rubric_year" value="<?php echo htmlspecialchars($filterRubricYear, ENT_QUOTES, 'UTF-8'); ?>" data-rubric-year-input="1">
                <div class="service-card-suggestion-anchor schedule-filter-select-anchor">
                    <button
                        type="button"
                        class="service-card-selectlike"
                        data-rubric-year-toggle="1"
                        aria-haspopup="listbox"
                        aria-expanded="false"
                    >
                        <span class="service-card-selectlike-label" data-rubric-year-label="1"><?php echo htmlspecialchars($selectedRubricYearOption['label'] ?? 'Choose Year', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="service-card-selectlike-arrow" aria-hidden="true">&#9662;</span>
                    </button>
                    <div class="service-card-suggestion-list schedule-filter-rubric-list" data-rubric-year-list="1" hidden>
                        <?php foreach ($rubricYearOptions as $rubricYearOption): ?>
                            <button
                                type="button"
                                class="service-card-suggestion-item"
                                data-rubric-year-option="1"
                                data-value="<?php echo htmlspecialchars((string) $rubricYearOption['key'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-label="<?php echo htmlspecialchars((string) $rubricYearOption['label'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-start-date="<?php echo htmlspecialchars((string) $rubricYearOption['start_date'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-end-date="<?php echo htmlspecialchars((string) $rubricYearOption['end_date'], ENT_QUOTES, 'UTF-8'); ?>"
                            >
                                <?php echo htmlspecialchars((string) $rubricYearOption['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </label>
        <?php endif; ?>
        <label class="schedule-filter-field">
            <span class="schedule-filter-nav-wrap">
                <button type="button" class="schedule-month-nav-button" data-schedule-month-nav="-1">&lt;&lt; prev mo</button>
                <span>Start Date</span>
            </span>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($filterStartInput, ENT_QUOTES, 'UTF-8'); ?>" data-schedule-date-field="start">
        </label>
        <label class="schedule-filter-field">
            <span class="schedule-filter-nav-wrap">
                <span>End Date</span>
                <button type="button" class="schedule-month-nav-button" data-schedule-month-nav="1">next mo &gt;&gt;</button>
            </span>
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
                    $isAdventMidweek = oflc_schedule_is_advent_midweek_observance($observanceName);
                    $isLentMidweek = oflc_schedule_is_lent_midweek_observance($observanceName);
                    $smallCatechismAbbreviations = ($isAdventMidweek || $isLentMidweek)
                        ? oflc_collect_group_small_catechism_abbreviations($group, $smallCatechismAbbreviationsByService)
                        : [];
                    $passionReadingLabels = $isLentMidweek
                        ? array_values(array_filter(array_map(static function (array $reading): string {
                            return oflc_format_schedule_passion_reading($reading);
                        }, oflc_collect_group_passion_readings($group, $passionReadingsById, $passionReadingIdsByService))))
                        : [];
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
                            <?php if ($selectedReadingSet === null && $smallCatechismAbbreviations === [] && $passionReadingLabels === []): ?>
                                <div class="schedule-secondary-text">No readings assigned.</div>
                            <?php else: ?>
                                <?php $psalm = $selectedReadingSet !== null ? oflc_clean_reading_text($selectedReadingSet['psalm'] ?? null, true) : ''; ?>
                                <?php $oldTestament = $selectedReadingSet !== null ? oflc_clean_reading_text($selectedReadingSet['old_testament'] ?? null) : ''; ?>
                                <?php $epistle = $selectedReadingSet !== null ? oflc_clean_reading_text($selectedReadingSet['epistle'] ?? null) : ''; ?>
                                <?php $gospel = $selectedReadingSet !== null ? oflc_clean_reading_text($selectedReadingSet['gospel'] ?? null) : ''; ?>
                                <div class="schedule-reading-list">
                                    <?php foreach ($passionReadingLabels as $passionReadingLabel): ?>
                                        <div><?php echo htmlspecialchars($passionReadingLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endforeach; ?>
                                    <?php if ($smallCatechismAbbreviations !== []): ?>
                                        <div><?php echo htmlspecialchars('SC: ' . implode(', ', $smallCatechismAbbreviations), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
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
                            <?php echo $leaderDisplay !== '' ? $leaderDisplay : '&nbsp;'; ?>
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

    function getCleanScheduleUrl() {
        var form = getForm();
        return form ? (form.getAttribute('action') || 'schedule.php') : 'schedule.php';
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
        window.history.replaceState({}, '', getCleanScheduleUrl());
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
        var rubricInput;
        var rubricToggleButton;
        var rubricLabel;
        var rubricList;
        var rubricOptions;
        var startDateInput;
        var endDateInput;
        var sortOrderInput;
        var sortToggleButton;
        var resetLink;
        var monthNavButtons;

        if (!root || !form) {
            return;
        }

        rubricInput = form.querySelector('[data-rubric-year-input="1"]');
        rubricToggleButton = form.querySelector('[data-rubric-year-toggle="1"]');
        rubricLabel = form.querySelector('[data-rubric-year-label="1"]');
        rubricList = form.querySelector('[data-rubric-year-list="1"]');
        rubricOptions = form.querySelectorAll('[data-rubric-year-option="1"]');
        startDateInput = form.querySelector('[data-schedule-date-field="start"]');
        endDateInput = form.querySelector('[data-schedule-date-field="end"]');
        sortOrderInput = form.querySelector('[data-schedule-sort-input="1"]');
        sortToggleButton = form.querySelector('[data-schedule-sort-toggle="1"]');
        resetLink = root.querySelector('[data-schedule-reset="1"]');
        monthNavButtons = form.querySelectorAll('[data-schedule-month-nav]');

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            requestSchedule(buildUrlFromForm(form));
        });

        function getRubricOptionByValue(value) {
            var matchedOption = null;

            Array.prototype.forEach.call(rubricOptions || [], function (option) {
                if (matchedOption || !option) {
                    return;
                }

                if ((option.getAttribute('data-value') || '') === String(value || '')) {
                    matchedOption = option;
                }
            });

            return matchedOption;
        }

        function syncRubricYearLabel() {
            var selectedOption;
            var labelText = 'Choose Year';

            if (!rubricLabel) {
                return;
            }

            selectedOption = getRubricOptionByValue(rubricInput ? rubricInput.value : '');
            if (selectedOption) {
                labelText = selectedOption.getAttribute('data-label') || labelText;
            }

            rubricLabel.textContent = labelText;
        }

        function parseDateInputValue(value) {
            var parts;
            var localDate;

            if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || '').trim())) {
                return null;
            }

            parts = String(value).split('-');
            localDate = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));

            return isNaN(localDate.getTime()) ? null : localDate;
        }

        function formatDateInputValue(date) {
            if (!(date instanceof Date) || isNaN(date.getTime())) {
                return '';
            }

            return [
                String(date.getFullYear()),
                String(date.getMonth() + 1).padStart(2, '0'),
                String(date.getDate()).padStart(2, '0')
            ].join('-');
        }

        function shiftDisplayedMonth(direction) {
            var delta = parseInt(direction || '0', 10);
            var referenceDate = parseDateInputValue(startDateInput && startDateInput.value)
                || parseDateInputValue(endDateInput && endDateInput.value)
                || parseDateInputValue(root.getAttribute('data-reset-start-date') || '');
            var monthStart;
            var monthEnd;

            if (!referenceDate || !Number.isFinite(delta) || delta === 0) {
                return;
            }

            monthStart = new Date(referenceDate.getFullYear(), referenceDate.getMonth() + delta, 1);
            monthEnd = new Date(referenceDate.getFullYear(), referenceDate.getMonth() + delta + 1, 0);

            isProgrammaticUpdate = true;
            if (rubricInput) {
                rubricInput.value = '';
                syncRubricYearLabel();
            }
            if (startDateInput) {
                startDateInput.value = formatDateInputValue(monthStart);
            }
            if (endDateInput) {
                endDateInput.value = formatDateInputValue(monthEnd);
            }
            isProgrammaticUpdate = false;
            hideRubricYearList();
            requestSchedule(buildUrlFromForm(form));
        }

        function hideRubricYearList() {
            if (!rubricList) {
                return;
            }

            rubricList.hidden = true;
            rubricList.classList.remove('is-visible');
            if (rubricToggleButton) {
                rubricToggleButton.setAttribute('aria-expanded', 'false');
            }
        }

        function showRubricYearList() {
            if (!rubricList) {
                return;
            }

            rubricList.hidden = false;
            rubricList.classList.add('is-visible');
            if (rubricToggleButton) {
                rubricToggleButton.setAttribute('aria-expanded', 'true');
            }
        }

        function toggleRubricYearList() {
            if (!rubricList) {
                return;
            }

            if (rubricList.classList.contains('is-visible')) {
                hideRubricYearList();
            } else {
                showRubricYearList();
            }
        }

        syncRubricYearLabel();

        if (rubricToggleButton) {
            rubricToggleButton.addEventListener('click', function () {
                toggleRubricYearList();
            });

            rubricToggleButton.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    hideRubricYearList();
                }
            });
        }

        Array.prototype.forEach.call(rubricOptions || [], function (option) {
            option.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });

            option.addEventListener('click', function () {
                isProgrammaticUpdate = true;
                if (rubricInput) {
                    rubricInput.value = option.getAttribute('data-value') || '';
                }
                syncRubricYearLabel();
                if (startDateInput) {
                    startDateInput.value = option.getAttribute('data-start-date') || '';
                }
                if (endDateInput) {
                    endDateInput.value = option.getAttribute('data-end-date') || '';
                }
                isProgrammaticUpdate = false;
                hideRubricYearList();
                requestSchedule(buildUrlFromForm(form));
            });
        });

        [startDateInput, endDateInput].forEach(function (input) {
            if (!input) {
                return;
            }

            input.addEventListener('change', function () {
                if (!isProgrammaticUpdate && rubricInput) {
                    rubricInput.value = '';
                    syncRubricYearLabel();
                }
                hideRubricYearList();
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
                if (rubricInput) {
                    rubricInput.value = resetRubricYear;
                    syncRubricYearLabel();
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
                hideRubricYearList();
                requestSchedule(buildUrlFromForm(form));
            });
        }

        Array.prototype.forEach.call(monthNavButtons || [], function (button) {
            button.addEventListener('click', function () {
                shiftDisplayedMonth(button.getAttribute('data-schedule-month-nav') || '0');
            });
        });

        if (document.oflcScheduleOutsideClickHandler) {
            document.removeEventListener('click', document.oflcScheduleOutsideClickHandler);
        }

        document.oflcScheduleOutsideClickHandler = function (event) {
            if (!rubricList || !rubricToggleButton) {
                return;
            }

            if (!form.contains(event.target)) {
                hideRubricYearList();
                return;
            }

            if (
                !rubricList.contains(event.target)
                && !rubricToggleButton.contains(event.target)
            ) {
                hideRubricYearList();
            }
        };

        document.addEventListener('click', document.oflcScheduleOutsideClickHandler);
    }

    bindScheduleFilters();

    if (window.location.search) {
        window.history.replaceState({}, '', getCleanScheduleUrl());
    }
}());
</script>
<?php endif; ?>

<?php
if (!$oflcScheduleEmbedded) {
    include 'includes/footer.php';
}
?>
