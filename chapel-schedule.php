<?php
declare(strict_types=1);

session_start();

$page_title = 'Chapel Schedule';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/liturgical.php';
require_once __DIR__ . '/includes/db/service-db-read.php';
require_once __DIR__ . '/includes/db/chapel-schedule-db.php';

function oflc_chapel_schedule_parse_multiline_values($value): array
{
    if (is_array($value)) {
        $value = implode("\n", $value);
    }

    return array_values(array_filter(array_map(static function ($item): string {
        return trim((string) $item);
    }, preg_split('/\r\n|\r|\n/', (string) $value) ?: []), static function (string $item): bool {
        return $item !== '';
    }));
}

function oflc_chapel_schedule_build_small_catechism_lookup(array $options): array
{
    $lookup = [];

    foreach ($options as $option) {
        $id = (int) ($option['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        foreach (['label', 'abbreviation'] as $field) {
            $value = strtolower(trim((string) ($option[$field] ?? '')));
            if ($value !== '' && !isset($lookup[$value])) {
                $lookup[$value] = $id;
            }
        }
    }

    return $lookup;
}

function oflc_chapel_schedule_resolve_hymn_ids(array $labels, array $lookupByKey, array &$errors, int $weekNumber): array
{
    $hymnIds = [];
    $caseInsensitiveLookup = [];
    foreach ($lookupByKey as $label => $id) {
        $caseInsensitiveLookup[strtolower((string) $label)] = (int) $id;
    }

    foreach ($labels as $label) {
        $hymnId = (int) ($lookupByKey[$label] ?? $caseInsensitiveLookup[strtolower($label)] ?? 0);
        if ($hymnId <= 0) {
            $errors[] = 'Week ' . $weekNumber . ': hymn "' . $label . '" must match hymn database text.';
            continue;
        }

        $hymnIds[] = $hymnId;
    }

    return array_values(array_unique($hymnIds));
}

function oflc_chapel_schedule_resolve_small_catechism_ids(array $labels, array $lookupByKey): array
{
    $ids = [];

    foreach ($labels as $label) {
        $id = (int) ($lookupByKey[strtolower($label)] ?? 0);
        if ($id <= 0) {
            $ids[] = 999;
            continue;
        }

        $ids[] = $id;
    }

    return $ids;
}

function oflc_chapel_schedule_filter_custom_small_catechism_labels(array $labels, array $lookupByKey): array
{
    return array_values(array_filter($labels, static function (string $label) use ($lookupByKey): bool {
        return (int) ($lookupByKey[strtolower($label)] ?? 0) <= 0;
    }));
}

oflc_chapel_schedule_db_ensure_tables($pdo);

$formErrors = [];
$formNotice = '';
$hymnCatalog = oflc_service_db_fetch_hymn_catalog($pdo);
$smallCatechismOptions = oflc_service_db_fetch_small_catechism_options($pdo);
$customSmallCatechismOptions = oflc_chapel_schedule_db_fetch_custom_small_catechism_options();
$smallCatechismLookup = oflc_chapel_schedule_build_small_catechism_lookup($smallCatechismOptions);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedRows = isset($_POST['weeks']) && is_array($_POST['weeks']) ? $_POST['weeks'] : [];
    $chapelAction = trim((string) ($_POST['chapel_action'] ?? ''));
    $targetRowKey = trim((string) ($_POST['chapel_row_key'] ?? ''));
    $today = date('Y-m-d');
    $submittedRow = isset($submittedRows[$targetRowKey]) && is_array($submittedRows[$targetRowKey])
        ? $submittedRows[$targetRowKey]
        : null;

    if ($chapelAction === 'delete_week') {
        $chapelScheduleId = is_array($submittedRow) ? (int) ($submittedRow['id'] ?? 0) : 0;
        if ($chapelScheduleId > 0) {
            oflc_chapel_schedule_db_delete_row($pdo, $chapelScheduleId);
            $formNotice = 'Chapel week deleted.';
        } else {
            $formErrors[] = 'Unable to delete an unsaved chapel week.';
        }
    } elseif ($chapelAction === 'save_week') {
        if ($submittedRow === null) {
            $formErrors[] = 'Unable to find the selected chapel week.';
        } else {
            $weekNumber = max(1, (int) ($submittedRow['week_number'] ?? 1));
            $date = trim((string) ($submittedRow['date'] ?? ''));
            $psalm = trim((string) ($submittedRow['psalm'] ?? ''));
            $text = trim((string) ($submittedRow['text'] ?? ''));
            $hymnLabels = oflc_chapel_schedule_parse_multiline_values($submittedRow['hymns'] ?? '');
            $smallCatechismLabels = oflc_chapel_schedule_parse_multiline_values($submittedRow['small_catechism'] ?? '');

            if ($date === '' && $psalm === '' && $text === '' && $hymnLabels === [] && $smallCatechismLabels === []) {
                $formErrors[] = 'Week ' . $weekNumber . ': add at least one chapel schedule detail before saving.';
            } elseif ($date !== '' && DateTimeImmutable::createFromFormat('Y-m-d', $date) === false) {
                $formErrors[] = 'Week ' . $weekNumber . ': date must use YYYY-MM-DD.';
            }

            $hymnIds = oflc_chapel_schedule_resolve_hymn_ids($hymnLabels, $hymnCatalog['lookup_by_key'], $formErrors, $weekNumber);
            $smallCatechismIds = oflc_chapel_schedule_resolve_small_catechism_ids($smallCatechismLabels, $smallCatechismLookup);
            $customSmallCatechismLabels = oflc_chapel_schedule_filter_custom_small_catechism_labels($smallCatechismLabels, $smallCatechismLookup);

            if ($formErrors === []) {
                $chapelScheduleId = oflc_chapel_schedule_db_save_row($pdo, [
                    'id' => (int) ($submittedRow['id'] ?? 0),
                    'week_number' => $weekNumber,
                    'date' => $date,
                    'psalm' => $psalm,
                    'text' => $text,
                    'observance_name' => trim((string) ($submittedRow['observance_name'] ?? '')),
                ]);
                oflc_chapel_schedule_db_replace_hymn_links($pdo, $chapelScheduleId, $hymnIds, $today);
                oflc_chapel_schedule_db_replace_small_catechism_links($pdo, $chapelScheduleId, $smallCatechismIds, $today);
                oflc_chapel_schedule_db_replace_custom_small_catechism_labels($chapelScheduleId, $customSmallCatechismLabels);
                $formNotice = 'Chapel week saved.';
            }
        }
    }
}

$selectedSchoolYear = trim((string) ($_GET['school_year'] ?? ''));
$selectedDateSort = strtolower(trim((string) ($_GET['date_sort'] ?? 'asc')));
if (!in_array($selectedDateSort, ['asc', 'desc'], true)) {
    $selectedDateSort = 'asc';
}
$schoolYearOptions = oflc_chapel_schedule_db_fetch_school_years($pdo);
if ($selectedSchoolYear !== '' && !in_array($selectedSchoolYear, $schoolYearOptions, true)) {
    $selectedSchoolYear = '';
}
$chapelRows = oflc_chapel_schedule_db_fetch_rows($pdo, $selectedSchoolYear, $selectedDateSort);
$nextWeekNumber = 1;
foreach ($chapelRows as $chapelRow) {
    $nextWeekNumber = max($nextWeekNumber, (int) ($chapelRow['week_number'] ?? 0) + 1);
}
$latestChapelDate = '';
foreach ($chapelRows as $chapelRow) {
    $rowDate = trim((string) ($chapelRow['date'] ?? ''));
    if ($rowDate !== '' && $rowDate > $latestChapelDate) {
        $latestChapelDate = $rowDate;
    }
}
$latestChapelDateObject = $latestChapelDate !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $latestChapelDate) : null;
$suggestedChapelDate = $latestChapelDateObject instanceof DateTimeImmutable
    ? $latestChapelDateObject->modify('+7 days')->format('Y-m-d')
    : date('Y-m-d');
$nextChapelDateByDate = oflc_chapel_schedule_db_build_next_date_lookup($chapelRows);
$printChapelScheduleParams = [];
if ($selectedSchoolYear !== '') {
    $printChapelScheduleParams['school_year'] = $selectedSchoolYear;
}
$printChapelScheduleUrl = 'print-chapel-schedule.php' . ($printChapelScheduleParams !== [] ? '?' . http_build_query($printChapelScheduleParams) : '');

include 'includes/header.php';
?>

<h3>Chapel Schedule</h3>

<?php if ($formNotice !== ''): ?>
    <p class="planning-success"><?php echo htmlspecialchars($formNotice, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endif; ?>

<?php foreach ($formErrors as $formError): ?>
    <p class="planning-error"><?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></p>
<?php endforeach; ?>

<datalist id="chapel-hymn-options">
    <?php foreach ($hymnCatalog['suggestions'] as $hymnSuggestion): ?>
        <option value="<?php echo htmlspecialchars((string) $hymnSuggestion, ENT_QUOTES, 'UTF-8'); ?>"></option>
    <?php endforeach; ?>
</datalist>

<datalist id="chapel-small-catechism-options">
    <?php foreach ($smallCatechismOptions as $smallCatechismOption): ?>
        <?php $smallCatechismAbbreviation = trim((string) ($smallCatechismOption['abbreviation'] ?? '')); ?>
        <?php if ($smallCatechismAbbreviation !== ''): ?>
            <option value="<?php echo htmlspecialchars($smallCatechismAbbreviation, ENT_QUOTES, 'UTF-8'); ?>"></option>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php foreach ($customSmallCatechismOptions as $customSmallCatechismOption): ?>
        <option value="<?php echo htmlspecialchars((string) $customSmallCatechismOption, ENT_QUOTES, 'UTF-8'); ?>"></option>
    <?php endforeach; ?>
</datalist>

<div class="chapel-schedule-actions">
    <button type="button" class="schedule-print-button" id="chapel-add-week-button">Add a Week</button>
    <a href="<?php echo htmlspecialchars($printChapelScheduleUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="schedule-print-button" id="chapel-print-button">Print Schedule</a>
</div>

<form method="get" action="chapel-schedule.php" class="chapel-school-year-filter">
    <label>
        <span>School Year</span>
        <select name="school_year" onchange="this.form.submit()">
            <option value="">All school years</option>
            <?php foreach ($schoolYearOptions as $schoolYearOption): ?>
                <option value="<?php echo htmlspecialchars((string) $schoolYearOption, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selectedSchoolYear === (string) $schoolYearOption ? ' selected' : ''; ?>>
                    <?php echo htmlspecialchars(oflc_chapel_schedule_db_display_school_year((string) $schoolYearOption), ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        <span>Date Sort</span>
        <select name="date_sort" onchange="this.form.submit()">
            <option value="asc"<?php echo $selectedDateSort === 'asc' ? ' selected' : ''; ?>>Ascending</option>
            <option value="desc"<?php echo $selectedDateSort === 'desc' ? ' selected' : ''; ?>>Descending</option>
        </select>
    </label>
</form>

<form method="post" action="chapel-schedule.php" id="chapel-schedule-form" data-next-week-number="<?php echo htmlspecialchars((string) $nextWeekNumber, ENT_QUOTES, 'UTF-8'); ?>" data-suggested-date="<?php echo htmlspecialchars($suggestedChapelDate, ENT_QUOTES, 'UTF-8'); ?>" data-date-sort="<?php echo htmlspecialchars($selectedDateSort, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="chapel_row_key" id="chapel-row-key" value="">
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
                    <th scope="col">Psalm</th>
                    <th scope="col">Text</th>
                    <th scope="col">Hymns</th>
                    <th scope="col">SC</th>
                </tr>
            </thead>
            <tbody id="chapel-schedule-body">
                <?php foreach ($chapelRows as $rowIndex => $chapelRow): ?>
                    <?php
                    $rowKey = 'saved_' . (int) ($chapelRow['id'] ?? 0);
                    $hymnLabels = array_values($chapelRow['hymn_labels'] ?? []);
                    $smallCatechismLabels = array_values($chapelRow['small_catechism_labels'] ?? []);
                    ?>
                    <tr class="service-card-color-dark chapel-schedule-row" data-row-key="<?php echo htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8'); ?>" data-is-saved="1">
                        <td>
                            <input type="hidden" name="weeks[<?php echo htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8'); ?>][id]" value="<?php echo (int) ($chapelRow['id'] ?? 0); ?>">
                            <input type="text" class="chapel-schedule-input" name="weeks[<?php echo htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8'); ?>][week_number]" value="<?php echo htmlspecialchars((string) ($chapelRow['week_number'] ?? ($rowIndex + 1)), ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td>
                            <input type="date" class="chapel-schedule-input chapel-schedule-date-input" name="weeks[<?php echo htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8'); ?>][date]" value="<?php echo htmlspecialchars((string) ($chapelRow['date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="service-card-suggestion-anchor chapel-observance-anchor">
                                <input
                                    type="text"
                                    class="chapel-schedule-input chapel-observance-input"
                                    name="weeks[<?php echo htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8'); ?>][observance_name]"
                                    value="<?php echo htmlspecialchars((string) ($chapelRow['observance_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Observance"
                                    autocomplete="off"
                                >
                                <div class="service-card-suggestion-list chapel-observance-suggestion-list" hidden></div>
                            </div>
                            <?php
                            $chapelRowDate = trim((string) ($chapelRow['date'] ?? ''));
                            $showBaptismalRemembrance = $chapelRowDate !== ''
                                && oflc_chapel_schedule_db_is_baptismal_remembrance_date($chapelRowDate, $nextChapelDateByDate[$chapelRowDate] ?? '');
                            ?>
                            <div class="chapel-baptismal-remembrance"<?php echo $showBaptismalRemembrance ? '' : ' hidden'; ?>>Baptismal Remembrance</div>
                        </td>
                        <td>
                            <textarea class="chapel-schedule-input chapel-schedule-textarea chapel-schedule-psalm-input" name="weeks[<?php echo htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8'); ?>][psalm]" rows="2"><?php echo htmlspecialchars((string) ($chapelRow['psalm'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </td>
                        <td>
                            <textarea class="chapel-schedule-input chapel-schedule-textarea" name="weeks[<?php echo htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8'); ?>][text]" rows="2"><?php echo htmlspecialchars((string) ($chapelRow['text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </td>
                        <td>
                            <?php for ($hymnSlot = 0; $hymnSlot < 2; $hymnSlot++): ?>
                                <input
                                    type="text"
                                    class="chapel-schedule-input chapel-schedule-hymn-input"
                                    name="weeks[<?php echo htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8'); ?>][hymns][]"
                                    value="<?php echo htmlspecialchars((string) ($hymnLabels[$hymnSlot] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Select hymn"
                                    list="chapel-hymn-options"
                                    autocomplete="off"
                                >
                            <?php endfor; ?>
                        </td>
                        <td class="chapel-schedule-sc-cell">
                            <?php for ($smallCatechismSlot = 0; $smallCatechismSlot < 3; $smallCatechismSlot++): ?>
                                <input
                                    type="text"
                                    class="chapel-schedule-input chapel-schedule-small-catechism-input"
                                    name="weeks[<?php echo htmlspecialchars($rowKey, ENT_QUOTES, 'UTF-8'); ?>][small_catechism][]"
                                    value="<?php echo htmlspecialchars((string) ($smallCatechismLabels[$smallCatechismSlot] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    placeholder="Select SC"
                                    list="chapel-small-catechism-options"
                                    autocomplete="off"
                                >
                            <?php endfor; ?>
                            <div class="chapel-schedule-actions-cell">
                                <button type="button" class="chapel-week-save-button is-disabled" aria-disabled="true">Save</button>
                                <button type="button" class="chapel-week-delete-button" aria-label="Delete chapel week">×</button>
                                <span class="chapel-save-status" aria-live="polite"></span>
                                <div class="chapel-delete-confirm" hidden>
                                    <div>Delete this week?</div>
                                    <button type="submit" class="chapel-delete-confirm-yes chapel-row-submit" name="chapel_action" value="delete_week">Yes</button>
                                    <button type="button" class="chapel-delete-confirm-no">No</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>

<script>
(function () {
    var form = document.getElementById('chapel-schedule-form');
    var tableBody = document.getElementById('chapel-schedule-body');
    var addWeekButton = document.getElementById('chapel-add-week-button');
    var rowKeyInput = document.getElementById('chapel-row-key');
    var suggestionCache = {};
    var nextUnsavedId = 1;

    function getRow(element) {
        return element ? element.closest('tr') : null;
    }

    function getDateInput(row) {
        return row ? row.querySelector('.chapel-schedule-date-input') : null;
    }

    function addDaysToDate(date, days) {
        var parts = String(date || '').split('-');
        var dateObject;

        if (parts.length !== 3) {
            return '';
        }

        dateObject = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10) + days);
        if (Number.isNaN(dateObject.getTime())) {
            return '';
        }

        return [
            String(dateObject.getFullYear()).padStart(4, '0'),
            String(dateObject.getMonth() + 1).padStart(2, '0'),
            String(dateObject.getDate()).padStart(2, '0')
        ].join('-');
    }

    function getLatestChapelDate() {
        var latest = '';
        Array.prototype.forEach.call(document.querySelectorAll('.chapel-schedule-date-input'), function (dateInput) {
            var value = String(dateInput.value || '').trim();
            if (value !== '' && value > latest) {
                latest = value;
            }
        });

        return latest;
    }

    function getSuggestedDateForNewRow() {
        var latest = getLatestChapelDate();
        return latest !== '' ? addDaysToDate(latest, 7) : (form ? form.getAttribute('data-suggested-date') || '' : '');
    }

    function getNextScheduledChapelDate(date) {
        var nextDate = '';
        Array.prototype.forEach.call(document.querySelectorAll('.chapel-schedule-date-input'), function (dateInput) {
            var value = String(dateInput.value || '').trim();
            if (value !== '' && value > date && (nextDate === '' || value < nextDate)) {
                nextDate = value;
            }
        });

        return nextDate;
    }

    function getNextWeekNumber() {
        var largestWeekNumber = 0;
        var fallbackWeekNumber = form ? parseInt(form.getAttribute('data-next-week-number') || '1', 10) : 1;

        Array.prototype.forEach.call(tableBody ? tableBody.querySelectorAll('tr.chapel-schedule-row') : [], function (row) {
            var weekInput = getNamedRowField(row, 'week_number');
            var weekNumber = weekInput ? parseInt(weekInput.value || '0', 10) : 0;
            if (Number.isFinite(weekNumber) && weekNumber > largestWeekNumber) {
                largestWeekNumber = weekNumber;
            }
        });

        if (largestWeekNumber > 0) {
            return largestWeekNumber + 1;
        }

        return Number.isFinite(fallbackWeekNumber) && fallbackWeekNumber > 0 ? fallbackWeekNumber : 1;
    }

    function isBaptismalRemembranceDate(date) {
        var parts = String(date || '').split('-');
        var year;
        var month;
        var day;
        var dateObject;
        var nextWeek;

        if (parts.length !== 3) {
            return false;
        }

        year = parseInt(parts[0], 10);
        month = parseInt(parts[1], 10);
        day = parseInt(parts[2], 10);
        if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) {
            return false;
        }

        dateObject = new Date(year, month - 1, day);
        if (dateObject.getFullYear() !== year || dateObject.getMonth() !== month - 1 || dateObject.getDate() !== day) {
            return false;
        }

        if (dateObject.getDay() !== 3) {
            return false;
        }

        nextWeek = new Date(year, month - 1, day + 7);
        var nextScheduledDate = getNextScheduledChapelDate(date);
        if (nextScheduledDate !== '') {
            var nextScheduledParts = nextScheduledDate.split('-');
            var nextScheduledYear = parseInt(nextScheduledParts[0], 10);
            var nextScheduledMonth = parseInt(nextScheduledParts[1], 10);
            if (!Number.isFinite(nextScheduledYear) || !Number.isFinite(nextScheduledMonth)) {
                return false;
            }

            return nextScheduledYear !== year || nextScheduledMonth !== month;
        }

        return nextWeek.getFullYear() !== dateObject.getFullYear()
            || nextWeek.getMonth() !== dateObject.getMonth();
    }

    function updateBaptismalRemembrance(row) {
        var dateInput = getDateInput(row);
        var notice = row ? row.querySelector('.chapel-baptismal-remembrance') : null;
        if (notice) {
            notice.hidden = !isBaptismalRemembranceDate(dateInput ? dateInput.value : '');
        }
    }

    function updateAllBaptismalRemembrances() {
        Array.prototype.forEach.call(document.querySelectorAll('.chapel-schedule-row'), updateBaptismalRemembrance);
    }

    function updateDateDerivedDisplays(row) {
        updateBaptismalRemembrance(row);
    }

    function setRowDirty(row, isDirty) {
        var saveButton = row ? row.querySelector('.chapel-week-save-button') : null;
        var status = row ? row.querySelector('.chapel-save-status') : null;
        if (!saveButton) {
            return;
        }

        saveButton.disabled = false;
        saveButton.classList.toggle('is-disabled', !isDirty);
        saveButton.setAttribute('aria-disabled', isDirty ? 'false' : 'true');
        saveButton.textContent = 'Save';
        if (status && isDirty) {
            status.textContent = '';
            status.classList.remove('is-error');
        }
        row.classList.toggle('is-dirty', !!isDirty);
    }

    function getNamedRowField(row, field) {
        return row ? row.querySelector('[name$="[' + field + ']"]') : null;
    }

    function setRowSaving(row, isSaving) {
        var saveButton = row ? row.querySelector('.chapel-week-save-button') : null;
        if (!saveButton) {
            return;
        }

        saveButton.disabled = !!isSaving;
        saveButton.textContent = isSaving ? 'Saving...' : 'Save';
        if (isSaving) {
            saveButton.classList.remove('is-disabled');
            saveButton.setAttribute('aria-disabled', 'true');
        }
        row.classList.toggle('is-saving', !!isSaving);
    }

    function setRowSaveStatus(row, message, isError) {
        var status = row ? row.querySelector('.chapel-save-status') : null;
        if (!status) {
            return;
        }

        status.textContent = message || '';
        status.classList.toggle('is-error', !!isError);
    }

    function collectRowFormData(row) {
        var data = new FormData();
        var idInput = getNamedRowField(row, 'id');
        var weekNumberInput = getNamedRowField(row, 'week_number');
        var dateInput = getNamedRowField(row, 'date');
        var psalmInput = getNamedRowField(row, 'psalm');
        var textInput = getNamedRowField(row, 'text');
        var observanceInput = getNamedRowField(row, 'observance_name');

        data.append('id', idInput ? idInput.value : '');
        data.append('week_number', weekNumberInput ? weekNumberInput.value : '');
        data.append('date', dateInput ? dateInput.value : '');
        data.append('psalm', psalmInput ? psalmInput.value : '');
        data.append('text', textInput ? textInput.value : '');
        data.append('observance_name', observanceInput ? observanceInput.value : '');

        Array.prototype.forEach.call(row.querySelectorAll('.chapel-schedule-hymn-input'), function (input) {
            data.append('hymns[]', input.value || '');
        });
        Array.prototype.forEach.call(row.querySelectorAll('.chapel-schedule-small-catechism-input'), function (input) {
            data.append('small_catechism[]', input.value || '');
        });

        return data;
    }

    function saveWeekRow(row) {
        var idInput = getNamedRowField(row, 'id');

        if (!row || row.classList.contains('is-saving')) {
            return;
        }

        setRowSaving(row, true);
        setRowSaveStatus(row, '', false);

        fetch('ajax/save_chapel_week.php', {
            method: 'POST',
            headers: {
                'Accept': 'application/json'
            },
            body: collectRowFormData(row)
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return { success: false, message: 'Unable to save chapel week.' };
                }).then(function (payload) {
                    if (!response.ok || !payload.success) {
                        throw new Error(payload.message || 'Unable to save chapel week.');
                    }

                    return payload;
                });
            })
            .then(function (payload) {
                var confirm = row.querySelector('.chapel-delete-confirm');
                var confirmMessage = confirm ? confirm.querySelector('div') : null;
                var confirmYes = confirm ? confirm.querySelector('.chapel-delete-confirm-yes') : null;
                if (idInput && payload.id) {
                    idInput.value = payload.id;
                }
                row.setAttribute('data-is-saved', '1');
                if (confirmMessage) {
                    confirmMessage.textContent = 'Delete this week?';
                }
                if (confirmYes) {
                    confirmYes.type = 'submit';
                }
                setRowDirty(row, false);
                setRowSaveStatus(row, 'Saved', false);
                window.setTimeout(function () {
                    if (!row.classList.contains('is-dirty')) {
                        setRowSaveStatus(row, '', false);
                    }
                }, 1800);
            })
            .catch(function (error) {
                setRowSaveStatus(row, error.message || 'Unable to save.', true);
                setRowDirty(row, true);
            })
            .finally(function () {
                row.classList.remove('is-saving');
            });
    }

    function handleSaveButtonPress(event) {
        var saveButton = event.target.closest('.chapel-week-save-button');
        var row;

        if (!saveButton || (typeof event.button === 'number' && event.button !== 0)) {
            return;
        }

        event.preventDefault();
        saveButton.setAttribute('data-save-pressed', '1');
        window.setTimeout(function () {
            saveButton.removeAttribute('data-save-pressed');
        }, 0);

        row = getRow(saveButton);
        saveWeekRow(row);
    }

    function setRowKeyForSubmit(button) {
        var row = getRow(button);
        if (rowKeyInput && row) {
            rowKeyInput.value = row.getAttribute('data-row-key') || '';
        }
    }

    function createCell(row) {
        var cell = document.createElement('td');
        row.appendChild(cell);
        return cell;
    }

    function appendInput(cell, rowKey, field, type, className, value) {
        var input = document.createElement('input');
        input.type = type || 'text';
        input.className = className || 'chapel-schedule-input';
        input.name = 'weeks[' + rowKey + '][' + field + ']';
        input.value = value || '';
        cell.appendChild(input);
        return input;
    }

    function appendHiddenInput(cell, rowKey, field, value) {
        return appendInput(cell, rowKey, field, 'hidden', '', value || '');
    }

    function appendTextarea(cell, rowKey, field, className) {
        var textarea = document.createElement('textarea');
        textarea.className = className || 'chapel-schedule-input chapel-schedule-textarea';
        textarea.name = 'weeks[' + rowKey + '][' + field + ']';
        textarea.rows = 2;
        cell.appendChild(textarea);
        return textarea;
    }

    function appendSuggestionAnchor(cell, rowKey) {
        var anchor = document.createElement('div');
        var input = document.createElement('input');
        var list = document.createElement('div');

        anchor.className = 'service-card-suggestion-anchor chapel-observance-anchor';
        input.type = 'text';
        input.className = 'chapel-schedule-input chapel-observance-input';
        input.name = 'weeks[' + rowKey + '][observance_name]';
        input.placeholder = 'Observance';
        input.autocomplete = 'off';
        list.className = 'service-card-suggestion-list chapel-observance-suggestion-list';
        list.hidden = true;

        anchor.appendChild(input);
        anchor.appendChild(list);
        cell.appendChild(anchor);
        var baptismalRemembrance = document.createElement('div');
        baptismalRemembrance.className = 'chapel-baptismal-remembrance';
        baptismalRemembrance.textContent = 'Baptismal Remembrance';
        baptismalRemembrance.hidden = true;
        cell.appendChild(baptismalRemembrance);
    }

    function appendHymnInputs(cell, rowKey) {
        var index;
        var input;
        for (index = 0; index < 2; index++) {
            input = document.createElement('input');
            input.type = 'text';
            input.className = 'chapel-schedule-input chapel-schedule-hymn-input';
            input.name = 'weeks[' + rowKey + '][hymns][]';
            input.placeholder = 'Select hymn';
            input.setAttribute('list', 'chapel-hymn-options');
            input.autocomplete = 'off';
            cell.appendChild(input);
        }
    }

    function appendSmallCatechismInputs(cell, rowKey) {
        var index;
        var input;
        for (index = 0; index < 3; index++) {
            input = document.createElement('input');
            input.type = 'text';
            input.className = 'chapel-schedule-input chapel-schedule-small-catechism-input';
            input.name = 'weeks[' + rowKey + '][small_catechism][]';
            input.placeholder = 'Select SC';
            input.setAttribute('list', 'chapel-small-catechism-options');
            input.autocomplete = 'off';
            cell.appendChild(input);
        }
    }

    function appendActions(cell, rowKey, isSaved) {
        var saveButton = document.createElement('button');
        var deleteButton = document.createElement('button');
        var confirm = document.createElement('div');
        var message = document.createElement('div');
        var yesButton = document.createElement('button');
        var noButton = document.createElement('button');

        cell.classList.add('chapel-schedule-sc-cell');
        saveButton.type = 'button';
        saveButton.className = 'chapel-week-save-button is-disabled';
        saveButton.setAttribute('aria-disabled', 'true');
        saveButton.textContent = 'Save';

        deleteButton.type = 'button';
        deleteButton.className = 'chapel-week-delete-button';
        deleteButton.setAttribute('aria-label', 'Delete chapel week');
        deleteButton.textContent = '×';

        confirm.className = 'chapel-delete-confirm';
        confirm.hidden = true;
        message.textContent = isSaved ? 'Delete this week?' : 'Remove this unsaved week?';
        yesButton.type = isSaved ? 'submit' : 'button';
        yesButton.className = 'chapel-delete-confirm-yes chapel-row-submit';
        yesButton.name = 'chapel_action';
        yesButton.value = 'delete_week';
        yesButton.textContent = 'Yes';
        noButton.type = 'button';
        noButton.className = 'chapel-delete-confirm-no';
        noButton.textContent = 'No';

        confirm.appendChild(message);
        confirm.appendChild(yesButton);
        confirm.appendChild(noButton);
        var actions = document.createElement('div');
        actions.className = 'chapel-schedule-actions-cell';
        actions.appendChild(saveButton);
        actions.appendChild(deleteButton);
        var status = document.createElement('span');
        status.className = 'chapel-save-status';
        status.setAttribute('aria-live', 'polite');
        actions.appendChild(status);
        actions.appendChild(confirm);
        cell.appendChild(actions);
    }

    function addWeekRow() {
        var row;
        var rowKey;
        var weekNumber;
        var suggestedDate;
        var cell;

        if (!form || !tableBody) {
            return;
        }

        rowKey = 'new_' + Date.now() + '_' + nextUnsavedId;
        nextUnsavedId += 1;
        weekNumber = getNextWeekNumber();
        form.setAttribute('data-next-week-number', String(weekNumber + 1));
        suggestedDate = getSuggestedDateForNewRow();

        row = document.createElement('tr');
        row.className = 'service-card-color-dark chapel-schedule-row';
        row.setAttribute('data-row-key', rowKey);
        row.setAttribute('data-is-saved', '0');
        if (suggestedDate !== '') {
            form.setAttribute('data-suggested-date', addDaysToDate(suggestedDate, 7) || suggestedDate);
        }

        cell = createCell(row);
        appendHiddenInput(cell, rowKey, 'id', '');
        appendInput(cell, rowKey, 'week_number', 'text', 'chapel-schedule-input', String(weekNumber));
        cell = createCell(row);
        appendInput(cell, rowKey, 'date', 'date', 'chapel-schedule-input chapel-schedule-date-input', suggestedDate);
        appendSuggestionAnchor(cell, rowKey);
        cell = createCell(row);
        appendTextarea(cell, rowKey, 'psalm', 'chapel-schedule-input chapel-schedule-textarea chapel-schedule-psalm-input');
        cell = createCell(row);
        appendTextarea(cell, rowKey, 'text');
        cell = createCell(row);
        appendHymnInputs(cell, rowKey);
        cell = createCell(row);
        appendSmallCatechismInputs(cell, rowKey);
        appendActions(cell, rowKey, false);

        if ((form.getAttribute('data-date-sort') || 'asc') === 'desc') {
            tableBody.insertBefore(row, tableBody.firstChild);
        } else {
            tableBody.appendChild(row);
        }
        updateAllBaptismalRemembrances();
    }

    function hideList(list) {
        if (!list) {
            return;
        }

        list.hidden = true;
        list.classList.remove('is-visible');
        list.innerHTML = '';
    }

    function chooseSuggestion(input, list, suggestion, psalm) {
        var row = getRow(input);
        var psalmInput = row ? row.querySelector('.chapel-schedule-psalm-input') : null;

        input.value = suggestion;
        if (psalmInput && psalm) {
            psalmInput.value = psalm;
        }
        setRowDirty(row, true);
        hideList(list);
    }

    function renderSuggestions(input, list, suggestions, psalmsBySuggestion) {
        list.innerHTML = '';

        suggestions.forEach(function (suggestion) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'service-card-suggestion-item';
            button.textContent = suggestion;
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
                chooseSuggestion(input, list, suggestion, psalmsBySuggestion[suggestion] || '');
            });
            list.appendChild(button);
        });

        list.hidden = suggestions.length === 0;
        list.classList.toggle('is-visible', suggestions.length > 0);
    }

    function fetchSuggestions(date) {
        if (suggestionCache[date]) {
            return Promise.resolve(suggestionCache[date]);
        }

        return fetch('ajax/get_chapel_observance_suggestions.php?date=' + encodeURIComponent(date), {
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    return [];
                }

                return response.json();
            })
            .then(function (payload) {
                var normalizedPayload = {
                    suggestions: Array.isArray(payload.suggestions) ? payload.suggestions : [],
                    psalms_by_suggestion: payload.psalms_by_suggestion && typeof payload.psalms_by_suggestion === 'object'
                        ? payload.psalms_by_suggestion
                        : {}
                };
                suggestionCache[date] = normalizedPayload;
                return normalizedPayload;
            })
            .catch(function () {
                return {
                    suggestions: [],
                    psalms_by_suggestion: {}
                };
            });
    }

    function showSuggestions(input) {
        var row = getRow(input);
        var dateInput = getDateInput(row);
        var list = row ? row.querySelector('.chapel-observance-suggestion-list') : null;
        var date = dateInput ? String(dateInput.value || '').trim() : '';

        if (date === '' && dateInput) {
            dateInput.value = <?php echo json_encode($suggestedChapelDate); ?>;
            date = dateInput.value;
        }

        if (!list || date === '') {
            hideList(list);
            return;
        }

        fetchSuggestions(date).then(function (payload) {
            renderSuggestions(input, list, payload.suggestions, payload.psalms_by_suggestion);
        });
    }

    if (addWeekButton) {
        addWeekButton.addEventListener('click', addWeekRow);
    }

    if (form) {
        var markRowDirtyFromFieldEvent = function (event) {
            var row = getRow(event.target);
            if (row && event.target.closest('.chapel-schedule-row')) {
                setRowDirty(row, true);
            }
        };

        form.addEventListener('input', markRowDirtyFromFieldEvent);
        form.addEventListener('change', markRowDirtyFromFieldEvent);
        form.addEventListener('focusout', markRowDirtyFromFieldEvent);

        form.addEventListener('submit', function (event) {
            var submitter = event.submitter || document.activeElement;
            if (submitter && submitter.classList && submitter.classList.contains('chapel-row-submit')) {
                setRowKeyForSubmit(submitter);
                return;
            }

            event.preventDefault();
        });
    }

    document.addEventListener('change', function (event) {
        var row;
        var observanceInput;
        var list;

        if (!event.target || !event.target.classList.contains('chapel-schedule-date-input')) {
            return;
        }

        row = getRow(event.target);
        observanceInput = row ? row.querySelector('.chapel-observance-input') : null;
        list = row ? row.querySelector('.chapel-observance-suggestion-list') : null;
        if (observanceInput) {
            observanceInput.value = '';
        }
        updateAllBaptismalRemembrances();
        hideList(list);
        event.target.blur();
    });

    document.addEventListener('mousedown', handleSaveButtonPress, true);

    document.addEventListener('click', function (event) {
        var saveButton = event.target.closest('.chapel-week-save-button');
        var deleteButton = event.target.closest('.chapel-week-delete-button');
        var deleteConfirmNo = event.target.closest('.chapel-delete-confirm-no');
        var row;
        var confirm;

        if (saveButton) {
            event.preventDefault();
            if (saveButton.getAttribute('data-save-pressed') === '1') {
                return;
            }
            row = getRow(saveButton);
            saveWeekRow(row);
            return;
        }

        if (deleteButton) {
            row = getRow(deleteButton);
            confirm = row ? row.querySelector('.chapel-delete-confirm') : null;
            if (confirm) {
                confirm.hidden = false;
            }
            return;
        }

        if (deleteConfirmNo) {
            confirm = deleteConfirmNo.closest('.chapel-delete-confirm');
            if (confirm) {
                confirm.hidden = true;
            }
            return;
        }

        if (event.target.closest('.chapel-delete-confirm-yes')) {
            row = getRow(event.target);
            if (row && row.getAttribute('data-is-saved') !== '1') {
                event.preventDefault();
                row.remove();
            }
            return;
        }

        if (event.target && event.target.classList.contains('chapel-observance-input')) {
            showSuggestions(event.target);
            return;
        }

        if (event.target.closest('.chapel-observance-anchor')) {
            return;
        }

        Array.prototype.forEach.call(document.querySelectorAll('.chapel-observance-suggestion-list'), hideList);
    });
}());
</script>

<?php include 'includes/footer.php'; ?>
