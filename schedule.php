<?php
declare(strict_types=1);

$page_title = 'Service Schedule';
require_once __DIR__ . '/includes/db.php';

$oflcScheduleEmbedded = $oflcScheduleEmbedded ?? false;
$oflcScheduleShowHeading = $oflcScheduleShowHeading ?? true;
$oflcScheduleShowPrintLink = $oflcScheduleShowPrintLink ?? true;
$oflcScheduleShowFilters = $oflcScheduleShowFilters ?? true;

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

    foreach ($services as $service) {
        $serviceId = (int) ($service['id'] ?? 0);
        foreach ($hymnsByService[$serviceId] ?? [] as $hymn) {
            if (!in_array($hymn, $merged, true)) {
                $merged[] = $hymn;
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

$filterStartInput = trim((string) ($_GET['start_date'] ?? ''));
$filterEndInput = trim((string) ($_GET['end_date'] ?? ''));
$filterEntireSchedule = isset($_GET['entire_schedule']) && (string) $_GET['entire_schedule'] === '1';
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
    && !$filterEntireSchedule
    && $filterStartDate instanceof DateTimeImmutable
    && $filterEndDate instanceof DateTimeImmutable
    && $filterStartDate > $filterEndDate
) {
    $scheduleFilterError = 'Start date cannot be after end date.';
}

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
     ORDER BY s.service_date ASC, s.service_order ASC, s.id ASC'
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
            hd.hymnal,
            hd.hymn_number,
            hd.hymn_title,
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

        $hymnsByService[$serviceId][] = oflc_format_hymn_label($hymnRow);
    }
}

$scheduleGroups = oflc_group_schedule_services($services);
if ($scheduleFilterError === null && !$filterEntireSchedule) {
    $scheduleGroups = array_values(array_filter($scheduleGroups, static function (array $group) use ($filterStartDate, $filterEndDate): bool {
        return oflc_group_within_date_range($group, $filterStartDate, $filterEndDate);
    }));
}

$printScheduleQuery = [];
if ($filterStartInput !== '') {
    $printScheduleQuery['start_date'] = $filterStartInput;
}
if ($filterEndInput !== '') {
    $printScheduleQuery['end_date'] = $filterEndInput;
}
if ($filterEntireSchedule) {
    $printScheduleQuery['entire_schedule'] = '1';
}
$printScheduleUrl = 'print-schedule.php' . ($printScheduleQuery !== [] ? '?' . http_build_query($printScheduleQuery) : '');

if (!$oflcScheduleEmbedded) {
    include 'includes/header.php';
}
?>

<?php if ($oflcScheduleShowHeading): ?>
    <h3>Service Schedule</h3>
<?php endif; ?>

<?php if ($oflcScheduleShowFilters): ?>
    <form method="get" action="schedule.php" class="schedule-filter-form">
        <label class="schedule-filter-field">
            <span>Start Date</span>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($filterStartInput, ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label class="schedule-filter-field">
            <span>End Date</span>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($filterEndInput, ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label class="schedule-filter-checkbox">
            <input type="checkbox" name="entire_schedule" value="1"<?php echo $filterEntireSchedule ? ' checked' : ''; ?>>
            <span>Entire Schedule</span>
        </label>
        <div class="schedule-filter-actions">
            <button type="submit" class="schedule-filter-button">Apply</button>
            <a href="schedule.php" class="schedule-filter-reset">Reset</a>
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
                                        <div><?php echo htmlspecialchars($hymn, ENT_QUOTES, 'UTF-8'); ?></div>
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

<?php
if (!$oflcScheduleEmbedded) {
    include 'includes/footer.php';
}
?>
