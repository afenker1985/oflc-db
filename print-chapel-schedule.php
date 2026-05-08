<?php
declare(strict_types=1);

$page_title = 'Print Chapel Schedule';
$stylesheet_files = [
    'css/services.css',
];

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/db/service-db-read.php';
require_once __DIR__ . '/includes/db/chapel-schedule-db.php';

$selectedSchoolYear = trim((string) ($_GET['school_year'] ?? ''));
$schoolYearOptions = oflc_chapel_schedule_db_fetch_school_years($pdo);
if ($selectedSchoolYear === '') {
    $selectedSchoolYear = oflc_chapel_schedule_db_format_school_year(date('Y-m-d'));
}
if ($selectedSchoolYear !== '' && !in_array($selectedSchoolYear, $schoolYearOptions, true)) {
    $selectedSchoolYear = '';
}
$chapelRows = oflc_chapel_schedule_db_fetch_rows($pdo, $selectedSchoolYear, 'asc');
$nextChapelDateByDate = oflc_chapel_schedule_db_build_next_date_lookup($chapelRows);
$printTitle = 'Chapel Schedule';
$printSchoolYears = [];
foreach ($chapelRows as $chapelRow) {
    $schoolYear = trim((string) ($chapelRow['school_year'] ?? ''));
    if ($schoolYear !== '') {
        $printSchoolYears[$schoolYear] = $schoolYear;
    }
}
ksort($printSchoolYears);
$printSchoolYearLabel = '';
if ($selectedSchoolYear !== '') {
    $printSchoolYearLabel = oflc_chapel_schedule_db_display_school_year($selectedSchoolYear);
} elseif (count($printSchoolYears) === 1) {
    $printSchoolYearLabel = oflc_chapel_schedule_db_display_school_year((string) reset($printSchoolYears));
} elseif (count($printSchoolYears) > 1) {
    $firstSchoolYear = (string) reset($printSchoolYears);
    $lastSchoolYear = (string) end($printSchoolYears);
    $printSchoolYearLabel = oflc_chapel_schedule_db_display_school_year($firstSchoolYear)
        . ' - '
        . oflc_chapel_schedule_db_display_school_year($lastSchoolYear);
}
$printLargeTitle = 'Chapel Schedule';
if ($printSchoolYearLabel !== '') {
    $printLargeTitle .= ' ' . $printSchoolYearLabel;
}

function oflc_print_chapel_multiline(array $values): string
{
    $values = array_values(array_filter(array_map(static function ($value): string {
        return trim((string) $value);
    }, $values), static function (string $value): bool {
        return $value !== '';
    }));

    return implode("\n", $values);
}

function oflc_print_chapel_hymn_numbers(array $hymnLabels): string
{
    $hymnNumbers = array_map(static function ($label): string {
        $label = trim((string) $label);
        if ($label === '') {
            return '';
        }

        $labelWithoutTitle = trim((string) preg_replace('/\s+-\s+.*$/', '', $label));
        if (preg_match('/(?:^|\s)([0-9]+[A-Za-z]?(?::[0-9]+)?)(?:\s*$)/', $labelWithoutTitle, $matches)) {
            return $matches[1];
        }

        return $labelWithoutTitle;
    }, $hymnLabels);

    return oflc_print_chapel_multiline($hymnNumbers);
}

function oflc_print_chapel_abbreviate_psalm(string $psalm): string
{
    return preg_replace('/\bPsalm\b/i', 'Ps', $psalm) ?? $psalm;
}

function oflc_print_chapel_format_date(string $date): string
{
    $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $dateObject instanceof DateTimeImmutable ? $dateObject->format('n/j/y') : $date;
}

function oflc_print_chapel_format_observance(string $observance): string
{
    $observance = trim($observance);
    return trim((string) preg_replace('/\s+\([A-Za-z]{3}\s+\d{1,2}\/\d{1,2}\)$/', '', $observance));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php foreach ($stylesheet_files as $stylesheet_file): ?>
        <?php
        $stylesheet_path = __DIR__ . '/' . $stylesheet_file;
        $stylesheet_version = file_exists($stylesheet_path) ? filemtime($stylesheet_path) : time();
        ?>
        <link rel="stylesheet" type="text/css" href="<?php echo htmlspecialchars($stylesheet_file, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo rawurlencode((string) $stylesheet_version); ?>">
    <?php endforeach; ?>
    <style>
        @page {
            size: Letter;
            margin: 0.5in;
        }

        body {
            margin: 0;
            background: #ffffff;
        }

        #print-chapel-schedule-root {
            width: 6.5in;
            margin: 0 auto;
        }

        .print-schedule-page {
            width: 6.5in;
            min-height: 9in;
            margin: 0 auto;
            box-sizing: border-box;
            break-after: page;
            page-break-after: always;
        }

        .print-schedule-page:last-child {
            break-after: auto;
            page-break-after: auto;
        }

        .print-schedule-page-header {
            font-family: "Times New Roman", Times, serif;
            line-height: 1.2;
            margin: 0 0 0.08in;
            text-align: right;
        }

        .print-chapel-large-title {
            margin: 0 0 0.18in;
            font-family: "Times New Roman", Times, serif;
            font-size: 22pt;
            font-weight: bold;
            line-height: 1.1;
            text-align: center;
        }

        .planning-table-wrap {
            margin-top: 0;
            overflow: visible;
        }

        .chapel-schedule-table {
            width: 6.5in;
            min-width: 6.5in;
            max-width: 6.5in;
            margin: 0 auto;
        }

        .chapel-schedule-col-week {
            width: 0.42in;
        }

        .chapel-schedule-col-date {
            width: 2in;
        }

        .chapel-schedule-col-psalm {
            width: 1.13in;
        }

        .chapel-schedule-col-text {
            width: 1.34in;
        }

        .chapel-schedule-col-hymns {
            width: 0.75in;
        }

        .chapel-schedule-col-sc {
            width: 0.86in;
        }

        .planning-table thead th {
            background-color: #ffffff;
            display: table-cell;
        }

        .planning-table,
        .planning-table th,
        .planning-table td {
            border-color: #000000;
        }

        .chapel-print-cell-text {
            white-space: pre-line;
        }

        .chapel-print-observance,
        .chapel-baptismal-remembrance {
            margin-top: 4px;
        }

        thead {
            display: table-header-group;
        }

        tr,
        td,
        th {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        @media print {
            html,
            body {
                width: 6.5in;
            }
        }
    </style>
</head>
<body>
<div id="print-chapel-schedule-root">
    <div class="print-schedule-page-header">
        <h1 class="print-chapel-large-title"><?php echo htmlspecialchars($printLargeTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <div class="print-schedule-page-label"><?php echo htmlspecialchars($printTitle, ENT_QUOTES, 'UTF-8'); ?>, p. 1</div>
    </div>
    <?php if ($chapelRows === []): ?>
        <p>No chapel weeks are currently scheduled.</p>
    <?php else: ?>
        <div class="planning-table-wrap chapel-schedule-table-wrap">
            <table class="planning-table schedule-table chapel-schedule-table">
                <colgroup>
                    <col class="chapel-schedule-col-week">
                    <col class="chapel-schedule-col-date">
                    <col class="chapel-schedule-col-psalm">
                    <col class="chapel-schedule-col-text">
                    <col class="chapel-schedule-col-hymns">
                    <col class="chapel-schedule-col-sc">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Wk.</th>
                        <th scope="col">Date</th>
                        <th scope="col">Ps</th>
                        <th scope="col">Text</th>
                        <th scope="col">Hymns</th>
                        <th scope="col">SC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chapelRows as $chapelRow): ?>
                        <?php
                        $chapelRowDate = trim((string) ($chapelRow['date'] ?? ''));
                        $showBaptismalRemembrance = $chapelRowDate !== ''
                            && oflc_chapel_schedule_db_is_baptismal_remembrance_date($chapelRowDate, $nextChapelDateByDate[$chapelRowDate] ?? '');
                        ?>
                        <tr class="service-card-color-dark">
                            <td><?php echo htmlspecialchars((string) ($chapelRow['week_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <div><?php echo htmlspecialchars(oflc_print_chapel_format_date($chapelRowDate), ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php $printObservanceName = oflc_print_chapel_format_observance((string) ($chapelRow['observance_name'] ?? '')); ?>
                                <?php if ($printObservanceName !== ''): ?>
                                    <div class="chapel-print-observance"><?php echo htmlspecialchars($printObservanceName, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                                <?php if ($showBaptismalRemembrance): ?>
                                    <div class="chapel-baptismal-remembrance">Baptismal Remembrance</div>
                                <?php endif; ?>
                            </td>
                            <td class="chapel-print-cell-text"><?php echo htmlspecialchars(oflc_print_chapel_abbreviate_psalm((string) ($chapelRow['psalm'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="chapel-print-cell-text"><?php echo htmlspecialchars((string) ($chapelRow['text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="chapel-print-cell-text"><?php echo htmlspecialchars(oflc_print_chapel_hymn_numbers($chapelRow['hymn_labels'] ?? []), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="chapel-print-cell-text"><?php echo htmlspecialchars(oflc_print_chapel_multiline($chapelRow['small_catechism_labels'] ?? []), ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    function measureInches(inches) {
        var probe = document.createElement('div');
        probe.style.position = 'absolute';
        probe.style.left = '-9999px';
        probe.style.top = '0';
        probe.style.width = '0';
        probe.style.height = inches + 'in';
        document.body.appendChild(probe);
        var height = probe.getBoundingClientRect().height;
        probe.remove();

        return height;
    }

    function buildTableSkeleton(sourceTable) {
        var table = document.createElement('table');
        table.className = sourceTable.className;

        var colgroup = sourceTable.querySelector('colgroup');
        if (colgroup) {
            table.appendChild(colgroup.cloneNode(true));
        }

        var thead = sourceTable.querySelector('thead');
        if (thead) {
            table.appendChild(thead.cloneNode(true));
        }

        table.appendChild(document.createElement('tbody'));

        return table;
    }

    function buildPageHeader(sourceHeader, pageNumber) {
        if (!sourceHeader) {
            return null;
        }

        var header = sourceHeader.cloneNode(true);
        var pageLabel = header.querySelector('.print-schedule-page-label');
        var largeTitle = header.querySelector('.print-chapel-large-title');

        if (pageLabel) {
            pageLabel.textContent = <?php echo json_encode($printLargeTitle); ?> + ', p. ' + pageNumber;
            if (pageNumber === 1) {
                pageLabel.remove();
            }
        }
        if (largeTitle && pageNumber > 1) {
            largeTitle.remove();
        }

        return header;
    }

    function paginateChapelSchedule() {
        var root = document.getElementById('print-chapel-schedule-root');
        var sourceTable = root ? root.querySelector('.chapel-schedule-table') : null;

        if (!root || !sourceTable) {
            return;
        }

        var sourceRows = Array.prototype.slice.call(sourceTable.querySelectorAll('tbody > tr'));
        if (sourceRows.length === 0) {
            return;
        }

        var sourceHeader = root.querySelector('.print-schedule-page-header');
        var pageHeight = measureInches(9);
        var measurementHost = document.createElement('div');
        measurementHost.style.position = 'absolute';
        measurementHost.style.left = '-9999px';
        measurementHost.style.top = '0';
        measurementHost.style.visibility = 'hidden';
        measurementHost.style.pointerEvents = 'none';
        measurementHost.style.width = window.getComputedStyle(sourceTable).width;

        var measurementTable = buildTableSkeleton(sourceTable);
        measurementHost.appendChild(measurementTable);
        document.body.appendChild(measurementHost);

        var measurementBody = measurementTable.querySelector('tbody');
        var measurementHeader = measurementTable.querySelector('thead');
        var headerHeight = measurementHeader ? measurementHeader.getBoundingClientRect().height : 0;
        var firstPageHeaderHeight = 0;
        var continuedPageHeaderHeight = 0;
        if (sourceHeader) {
            var firstPageHeader = buildPageHeader(sourceHeader, 1);
            var continuedPageHeader = buildPageHeader(sourceHeader, 2);
            if (firstPageHeader) {
                measurementHost.insertBefore(firstPageHeader, measurementTable);
                firstPageHeaderHeight = firstPageHeader.getBoundingClientRect().height;
            }
            if (continuedPageHeader) {
                measurementHost.insertBefore(continuedPageHeader, measurementTable);
                continuedPageHeaderHeight = continuedPageHeader.getBoundingClientRect().height;
                continuedPageHeader.remove();
            }
        }

        var pages = [];
        var currentTable = buildTableSkeleton(sourceTable);
        var currentBody = currentTable.querySelector('tbody');
        var currentHeight = headerHeight;

        sourceRows.forEach(function (row) {
            var rowClone = row.cloneNode(true);

            measurementBody.replaceChildren(rowClone.cloneNode(true));
            var rowHeight = measurementBody.firstElementChild.getBoundingClientRect().height;
            var currentPageHeaderHeight = pages.length === 0 ? firstPageHeaderHeight : continuedPageHeaderHeight;
            var availableHeight = pageHeight - currentPageHeaderHeight;

            if (currentBody.children.length > 0 && currentHeight + rowHeight > availableHeight) {
                pages.push(currentTable);
                currentTable = buildTableSkeleton(sourceTable);
                currentBody = currentTable.querySelector('tbody');
                currentHeight = headerHeight;
            }

            currentBody.appendChild(rowClone);
            currentHeight += rowHeight;
        });

        if (currentBody.children.length > 0) {
            pages.push(currentTable);
        }

        measurementHost.remove();
        root.replaceChildren();

        pages.forEach(function (table, index) {
            var page = document.createElement('div');
            page.className = 'print-schedule-page';
            var pageHeader = buildPageHeader(sourceHeader, index + 1);
            if (pageHeader) {
                page.appendChild(pageHeader);
            }
            page.appendChild(table);
            root.appendChild(page);
        });
    }

    window.addEventListener('load', paginateChapelSchedule);
})();
</script>
</body>
</html>
