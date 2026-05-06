<?php
declare(strict_types=1);

$page_title = 'Print Schedule';
$stylesheet_files = [
    'css/services.css',
];
$oflcScheduleEmbedded = true;
$oflcScheduleShowHeading = false;
$oflcScheduleShowPrintLink = false;
$oflcScheduleShowFilters = false;
$oflcScheduleShowDuplicateTuneWarnings = false;
require_once __DIR__ . '/includes/service_schedule_last_updated.php';
$oflcScheduleLastUpdated = oflc_service_schedule_format_last_updated();
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
            margin: 1in;
        }

        body {
            margin: 0;
            background: #ffffff;
        }

        .planning-table-wrap {
            margin-top: 0;
            overflow: visible;
        }

        #print-schedule-root {
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

        .schedule-table {
            width: 6.5in;
            min-width: 6.5in;
            max-width: 6.5in;
            margin: 0 auto;
        }

        .schedule-col-date,
        .schedule-table-date {
            width: 1.94in;
        }

        .schedule-col-readings,
        .schedule-table-readings {
            width: 1.65in;
        }

        .schedule-col-hymns,
        .schedule-table-hymns {
            width: 1.46in;
        }

        .schedule-col-pastor,
        .schedule-table-pastor {
            width: 1.45in;
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

        thead {
            display: table-header-group;
        }

        tfoot {
            display: table-footer-group;
        }

        tr,
        td,
        th {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        tbody tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .schedule-primary-text,
        .schedule-secondary-text,
        .schedule-tertiary-text,
        .schedule-reading-list,
        .schedule-hymn-list {
            margin-top: 0;
        }

        .schedule-reading-list div + div,
        .schedule-hymn-list div + div {
            margin-top: 0;
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
<div id="print-schedule-root">
<div class="print-schedule-page-header">
    <div class="print-schedule-page-label">Service Schedule, p. 1</div>
    <?php if ($oflcScheduleLastUpdated !== null): ?>
        <div class="print-schedule-last-updated">Last Updated: <?php echo htmlspecialchars($oflcScheduleLastUpdated, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/schedule.php'; ?>
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

        if (pageLabel) {
            pageLabel.textContent = 'Service Schedule, p. ' + pageNumber;
        }

        return header;
    }

    function paginateSchedule() {
        var root = document.getElementById('print-schedule-root');
        var sourceTable = root ? root.querySelector('.schedule-table') : null;

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

    window.addEventListener('load', paginateSchedule);
})();
</script>
</body>
</html>
