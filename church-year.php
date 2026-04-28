<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$page_title = 'Church Year';
$body_class = 'update-service-page church-year-editor-page';
$stylesheet_files = [
    'css/main.css',
    'css/hymns.css',
    'css/services.css',
    'css/database.css',
    'css/church-year.css',
];

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/db/church-year-db-read.php';
require_once __DIR__ . '/includes/db/church-year-db-write.php';
require_once __DIR__ . '/includes/liturgical_colors.php';

function oflc_church_year_request_value(array $data, string $key, string $default = ''): string
{
    return isset($data[$key]) ? trim((string) $data[$key]) : $default;
}

function oflc_church_year_valid_section(string $section): string
{
    return in_array($section, ['festival_half', 'church_half', 'festivals', 'midweeks'], true) ? $section : 'festival_half';
}

function oflc_church_year_section_label(string $section): string
{
    switch ($section) {
        case 'church_half':
            return 'Church Half';
        case 'festivals':
            return 'Festivals';
        case 'midweeks':
            return 'Midweeks';
        case 'festival_half':
        default:
            return 'Festival Half';
    }
}

function oflc_church_year_format_summary_meta(array $entry): string
{
    $parts = [];
    if (!empty($entry['is_missing'])) {
        $parts[] = 'Unset';
    }

    $monthDay = trim((string) ($entry['month_day'] ?? ''));
    if ($monthDay !== '') {
        $parts[] = $monthDay;
    }

    foreach (['season', 'liturgical_color', 'logic_key'] as $key) {
        $value = trim((string) ($entry[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    $calendarDate = trim((string) ($entry['calendar_date'] ?? ''));
    if ($calendarDate !== '') {
        $parts[] = $calendarDate;
    }

    $year = trim((string) ($entry['year'] ?? ''));
    if ($year !== '') {
        $parts[] = $year;
    }

    return implode(' / ', $parts);
}

function oflc_church_year_color_class($color): string
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

function oflc_church_year_light_color_class($color): string
{
    $color = strtolower(trim((string) $color));

    switch ($color) {
        case 'gold':
            return 'church-year-row-light-gold';
        case 'green':
            return 'church-year-row-light-green';
        case 'violet':
        case 'purple':
            return 'church-year-row-light-violet';
        case 'blue':
            return 'church-year-row-light-blue';
        case 'rose':
        case 'pink':
            return 'church-year-row-light-rose';
        case 'scarlet':
            return 'church-year-row-light-scarlet';
        case 'red':
            return 'church-year-row-light-red';
        case 'black':
            return 'church-year-row-light-black';
        case 'white':
        case '':
        default:
            return 'church-year-row-light-dark';
    }
}

function oflc_church_year_render_text_field(string $name, string $placeholder, $value, string $type = 'text'): string
{
    return '<input type="' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '" class="service-card-text church-year-field" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '">';
}

function oflc_church_year_render_season_field(string $selectedSeason, array $seasonOptions): string
{
    $html = '<select class="service-card-select church-year-field" name="entry[season]">';
    $html .= '<option value="">Choose season</option>';

    foreach ($seasonOptions as $season) {
        $season = trim((string) $season);
        if ($season === '') {
            continue;
        }

        $html .= '<option value="' . htmlspecialchars($season, ENT_QUOTES, 'UTF-8') . '"' . ($selectedSeason === $season ? ' selected' : '') . '>';
        $html .= htmlspecialchars($season, ENT_QUOTES, 'UTF-8');
        $html .= '</option>';
    }

    $html .= '</select>';

    return $html;
}

function oflc_church_year_render_color_field(string $selectedColor, array $liturgicalColorOptions): string
{
    $html = '<select name="entry[liturgical_color]" class="service-card-select church-year-field">';
    $html .= '<option value="">Choose color</option>';

    foreach ($liturgicalColorOptions as $colorOption) {
        $html .= '<option value="' . htmlspecialchars($colorOption, ENT_QUOTES, 'UTF-8') . '"' . ($selectedColor === $colorOption ? ' selected' : '') . '>';
        $html .= htmlspecialchars($colorOption, ENT_QUOTES, 'UTF-8');
        $html .= '</option>';
    }

    $html .= '</select>';

    return $html;
}

function oflc_church_year_render_year_pattern_field(string $name, $value): string
{
    $selectedValue = trim((string) $value);
    $options = ['Odd', 'Even'];
    $html = '<select class="service-card-select church-year-field" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<option value="">Odd or Even</option>';

    foreach ($options as $option) {
        $html .= '<option value="' . htmlspecialchars($option, ENT_QUOTES, 'UTF-8') . '"' . ($selectedValue === $option ? ' selected' : '') . '>';
        $html .= htmlspecialchars($option, ENT_QUOTES, 'UTF-8');
        $html .= '</option>';
    }

    if ($selectedValue !== '' && !in_array($selectedValue, $options, true)) {
        $html .= '<option value="' . htmlspecialchars($selectedValue, ENT_QUOTES, 'UTF-8') . '" selected>';
        $html .= htmlspecialchars($selectedValue, ENT_QUOTES, 'UTF-8');
        $html .= '</option>';
    }

    $html .= '</select>';

    return $html;
}

function oflc_church_year_render_set_name_field(string $name, $value): string
{
    $selectedValue = trim((string) $value);
    $options = ['Primary', 'Alternate'];
    $html = '<select class="service-card-select church-year-field" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<option value="">Primary or Alternate</option>';

    foreach ($options as $option) {
        $html .= '<option value="' . htmlspecialchars($option, ENT_QUOTES, 'UTF-8') . '"' . ($selectedValue === $option ? ' selected' : '') . '>';
        $html .= htmlspecialchars($option, ENT_QUOTES, 'UTF-8');
        $html .= '</option>';
    }

    if ($selectedValue !== '' && !in_array($selectedValue, $options, true)) {
        $html .= '<option value="' . htmlspecialchars($selectedValue, ENT_QUOTES, 'UTF-8') . '" selected>';
        $html .= htmlspecialchars($selectedValue, ENT_QUOTES, 'UTF-8');
        $html .= '</option>';
    }

    $html .= '</select>';

    return $html;
}

function oflc_church_year_render_entry_fields(array $entry, array $seasonOptions, array $liturgicalColorOptions, bool $isCreate = false): string
{
    $html = '<div class="church-year-edit-grid">';
    $html .= oflc_church_year_render_text_field('entry[name]', 'Name', $entry['name'] ?? '');
    $html .= oflc_church_year_render_text_field('entry[latin_name]', 'Latin Name', $entry['latin_name'] ?? '');
    $html .= oflc_church_year_render_season_field((string) ($entry['season'] ?? ''), $seasonOptions);
    $html .= oflc_church_year_render_color_field((string) ($entry['liturgical_color'] ?? ''), $liturgicalColorOptions);
    $html .= oflc_church_year_render_text_field('entry[year]', 'Year YYYY', $entry['year'] ?? '', 'number');
    $html .= '<label class="church-year-midweek-toggle"><input type="checkbox" name="entry[is_midweek]" value="1"' . (!$isCreate && (int) ($entry['is_midweek'] ?? 0) === 1 ? ' checked' : '') . '> <span>Midweek</span></label>';
    $html .= '<input type="hidden" name="entry[notes]" value="' . htmlspecialchars($isCreate ? '' : (string) ($entry['notes'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
    $html .= '</div>';

    return $html;
}

function oflc_church_year_render_reading_fields(string $prefix, array $readingSet, string $heading = '', bool $includeMetaFields = false): string
{
    $html = '<div class="church-year-reading-set">';
    if ($heading !== '') {
        $html .= '<div class="church-year-reading-set-title">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    $html .= oflc_church_year_render_text_field($prefix . '[psalm]', 'Psalm', $readingSet['psalm'] ?? '');
    $html .= oflc_church_year_render_text_field($prefix . '[old_testament]', 'Old Testament', $readingSet['old_testament'] ?? '');
    $html .= oflc_church_year_render_text_field($prefix . '[epistle]', 'Epistle', $readingSet['epistle'] ?? '');
    $html .= oflc_church_year_render_text_field($prefix . '[gospel]', 'Gospel', $readingSet['gospel'] ?? '');
    if ($includeMetaFields) {
        $html .= oflc_church_year_render_set_name_field($prefix . '[set_name]', $readingSet['set_name'] ?? '');
        $html .= oflc_church_year_render_year_pattern_field($prefix . '[year_pattern]', $readingSet['year_pattern'] ?? '');
    } else {
        $html .= '<input type="hidden" name="' . htmlspecialchars($prefix . '[set_name]', ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) ($readingSet['set_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="' . htmlspecialchars($prefix . '[year_pattern]', ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) ($readingSet['year_pattern'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
    }
    $html .= '</div>';

    return $html;
}

function oflc_church_year_render_readings_panel(array $readingSets, bool $showBlankSet = false): string
{
    $readingSets = array_values($readingSets);
    $readingSetCount = count($readingSets);
    $html = '<div class="church-year-readings">';
    $html .= '<h4>Readings</h4>';

    if ($readingSetCount === 0 || $showBlankSet) {
        $html .= oflc_church_year_render_reading_fields('new_reading_set', [], '', true);
    } else {
        foreach ($readingSets as $index => $readingSet) {
            $heading = '';
            if ($readingSetCount > 1) {
                $heading = $index === 0 ? 'Set One' : 'Set Two';
            }

            $hasMeta = trim((string) ($readingSet['set_name'] ?? '')) !== ''
                || trim((string) ($readingSet['year_pattern'] ?? '')) !== ''
                || $readingSetCount > 1;

            $html .= oflc_church_year_render_reading_fields(
                'reading_sets[' . (int) ($readingSet['id'] ?? 0) . ']',
                $readingSet,
                $heading,
                $hasMeta
            );
        }

        $html .= '<button type="button" class="church-year-add-reading-button" data-church-year-reading-toggle data-open-label="- Click to hide new reading set" data-closed-label="+ Click to add another reading set">+ Click to add another reading set</button>';
        $html .= '<div class="church-year-new-reading-set" hidden>';
        $html .= oflc_church_year_render_reading_fields('new_reading_set', [], '', true);
        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}

function oflc_church_year_format_row_name(array $entry, string $section, array $fixedMonthDayByLogicKey): string
{
    $name = trim((string) ($entry['name'] ?? ''));
    if ($section !== 'festivals') {
        return $name;
    }

    $monthDay = trim((string) ($entry['month_day'] ?? ''));
    if ($monthDay === '') {
        $monthDay = $fixedMonthDayByLogicKey[trim((string) ($entry['logic_key'] ?? ''))] ?? '';
    }

    return $monthDay !== '' ? $name . ' (' . $monthDay . ')' : $name;
}

$activeSection = oflc_church_year_valid_section(oflc_church_year_request_value($_GET, 'section', 'festival_half'));
$midweekYears = oflc_church_year_db_fetch_active_midweek_years($pdo);
$selectedMidweekYear = null;
if ($midweekYears !== []) {
    $requestedYear = oflc_church_year_request_value($_GET, 'midweek_year', 'all');
    if ($requestedYear === 'all') {
        $selectedMidweekYear = null;
    } else {
        $selectedMidweekYear = ctype_digit($requestedYear) ? (int) $requestedYear : $midweekYears[0];
    }
    if ($selectedMidweekYear !== null && !in_array($selectedMidweekYear, $midweekYears, true)) {
        $selectedMidweekYear = $midweekYears[0];
    }
}
$selectedMidweekYearValue = $selectedMidweekYear === null ? 'all' : (string) $selectedMidweekYear;

$updatedEntryId = 0;
$formErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_church_year'])) {
    $activeSection = oflc_church_year_valid_section(oflc_church_year_request_value($_POST, 'return_section', $activeSection));
    $postedYear = oflc_church_year_request_value($_POST, 'return_midweek_year', '');
    if ($postedYear === 'all') {
        $selectedMidweekYear = null;
    } elseif ($postedYear !== '' && ctype_digit($postedYear)) {
        $selectedMidweekYear = (int) $postedYear;
    }
    $selectedMidweekYearValue = $selectedMidweekYear === null ? 'all' : (string) $selectedMidweekYear;

    $entryId = ctype_digit((string) ($_POST['entry_id'] ?? '')) ? (int) $_POST['entry_id'] : 0;
    $entryData = is_array($_POST['entry'] ?? null) ? $_POST['entry'] : [];
    $entryName = oflc_church_year_request_value($entryData, 'name');

    if ($entryId <= 0) {
        $formErrors[] = 'Select a valid church year row.';
    }
    if ($entryName === '') {
        $formErrors[] = 'Name is required.';
    }

    if ($formErrors === []) {
        $entryData['is_midweek'] = isset($entryData['is_midweek']);
        $pdo->beginTransaction();
        try {
            oflc_church_year_db_update_entry($pdo, $entryId, $entryData);

            $readingSets = is_array($_POST['reading_sets'] ?? null) ? $_POST['reading_sets'] : [];
            foreach ($readingSets as $readingSetId => $readingSetData) {
                if (ctype_digit((string) $readingSetId) && is_array($readingSetData)) {
                    oflc_church_year_db_update_reading_set($pdo, (int) $readingSetId, $entryId, $readingSetData);
                }
            }

            $newReadingSet = is_array($_POST['new_reading_set'] ?? null) ? $_POST['new_reading_set'] : [];
            oflc_church_year_db_insert_reading_set($pdo, $entryId, $newReadingSet);
            $pdo->commit();
            $updatedEntryId = $entryId;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            $formErrors[] = 'The church year row could not be updated.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_church_year'])) {
    $activeSection = oflc_church_year_valid_section(oflc_church_year_request_value($_POST, 'return_section', $activeSection));
    $postedYear = oflc_church_year_request_value($_POST, 'return_midweek_year', '');
    if ($postedYear === 'all') {
        $selectedMidweekYear = null;
    } elseif ($postedYear !== '' && ctype_digit($postedYear)) {
        $selectedMidweekYear = (int) $postedYear;
    }
    $selectedMidweekYearValue = $selectedMidweekYear === null ? 'all' : (string) $selectedMidweekYear;

    $entryData = is_array($_POST['entry'] ?? null) ? $_POST['entry'] : [];
    $entryName = oflc_church_year_request_value($entryData, 'name');

    if ($entryName === '') {
        $formErrors[] = 'Name is required.';
    }

    if ($formErrors === []) {
        $entryData['is_midweek'] = isset($entryData['is_midweek']);
        $pdo->beginTransaction();
        try {
            $createdEntryId = oflc_church_year_db_insert_entry($pdo, $entryData);
            $newReadingSet = is_array($_POST['new_reading_set'] ?? null) ? $_POST['new_reading_set'] : [];
            oflc_church_year_db_insert_reading_set($pdo, $createdEntryId, $newReadingSet);
            $pdo->commit();
            $updatedEntryId = $createdEntryId;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            $formErrors[] = 'The church year row could not be added.';
        }
    }
}

$entries = oflc_church_year_db_fetch_entries($pdo, $activeSection, $activeSection === 'midweeks' ? $selectedMidweekYear : null);
$readingSetsByEntry = oflc_church_year_db_fetch_reading_sets_for_entries($pdo, array_map(static function (array $entry): int {
    return (int) ($entry['id'] ?? 0);
}, $entries));
$liturgicalColorOptions = oflc_get_liturgical_color_options();
$seasonOptions = oflc_church_year_db_fetch_unique_seasons($pdo);
$fixedMonthDayByLogicKey = oflc_church_year_db_get_fixed_logic_key_month_day_map();

include 'includes/header.php';
?>

<div id="update-service-content-root" class="church-year-editor">
    <h3>Church Year</h3>

    <?php if ($formErrors !== []): ?>
        <p class="planning-error"><?php echo htmlspecialchars(implode(' ', $formErrors), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <form class="church-year-toolbar" method="get" action="church-year.php">
        <input type="hidden" name="section" value="<?php echo htmlspecialchars($activeSection, ENT_QUOTES, 'UTF-8'); ?>">
        <?php foreach (['festival_half', 'church_half', 'festivals', 'midweeks'] as $section): ?>
            <button type="submit" class="church-year-section-button<?php echo $activeSection === $section ? ' is-active' : ''; ?>" onclick="this.form.elements.section.value='<?php echo htmlspecialchars($section, ENT_QUOTES, 'UTF-8'); ?>';">
                <?php echo htmlspecialchars(oflc_church_year_section_label($section), ENT_QUOTES, 'UTF-8'); ?>
            </button>
        <?php endforeach; ?>
        <select name="midweek_year" class="service-card-select church-year-midweek-year" onchange="this.form.elements.section.value='midweeks'; this.form.submit();">
            <?php if ($midweekYears === []): ?>
                <option value="">No midweek years</option>
            <?php else: ?>
                <option value="all"<?php echo $selectedMidweekYear === null ? ' selected' : ''; ?>>All</option>
                <?php foreach ($midweekYears as $midweekYear): ?>
                    <option value="<?php echo (int) $midweekYear; ?>"<?php echo $selectedMidweekYear === $midweekYear ? ' selected' : ''; ?>>
                        <?php echo (int) $midweekYear; ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
    </form>

    <div class="update-service-list church-year-list">
        <?php if ($entries === []): ?>
            <p class="schedule-secondary-text">No rows found for <?php echo htmlspecialchars(strtolower(oflc_church_year_section_label($activeSection)), ENT_QUOTES, 'UTF-8'); ?>.</p>
        <?php endif; ?>

        <?php foreach ($entries as $entry): ?>
            <?php
            $entryId = (int) ($entry['id'] ?? 0);
            $isMissingEntry = !empty($entry['is_missing']);
            $isUnmatchedEntry = !empty($entry['is_unmatched']);
            $isUntaggedMidweek = !empty($entry['is_untagged_midweek']);
            $isUnsetMidweekYear = $activeSection === 'midweeks' && (int) ($entry['year'] ?? 0) <= 0;
            $colorClass = $isMissingEntry
                ? 'church-year-row-missing'
                : ($isUnsetMidweekYear
                    ? 'church-year-row-unmatched'
                    : ($isUntaggedMidweek
                        ? oflc_church_year_light_color_class($entry['liturgical_color'] ?? '')
                        : ($isUnmatchedEntry ? 'church-year-row-unmatched' : oflc_church_year_color_class($entry['liturgical_color'] ?? ''))));
            ?>
            <details class="update-service-row church-year-row <?php echo htmlspecialchars($colorClass, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $updatedEntryId > 0 && $updatedEntryId === $entryId ? ' open' : ''; ?>>
                <summary class="update-service-summary">
                    <span class="update-service-summary-text">
                        <?php echo htmlspecialchars(oflc_church_year_format_row_name($entry, $activeSection, $fixedMonthDayByLogicKey), ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </summary>
                <div class="update-service-forms">
                    <?php if ($updatedEntryId > 0 && $updatedEntryId === $entryId): ?>
                        <p class="planning-success church-year-row-success">Church year row updated.</p>
                    <?php endif; ?>
                    <?php if ($isMissingEntry): ?>
                        <div class="church-year-missing-panel">
                            No active liturgical calendar row is set for
                            <strong><?php echo htmlspecialchars((string) ($entry['logic_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>.
                        </div>
                        <form class="service-card update-service-edit-card church-year-missing-form" method="post" action="church-year.php?section=<?php echo htmlspecialchars($activeSection, ENT_QUOTES, 'UTF-8'); ?>&amp;midweek_year=<?php echo htmlspecialchars($selectedMidweekYearValue, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="create_church_year" value="1">
                            <input type="hidden" name="return_section" value="<?php echo htmlspecialchars($activeSection, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="return_midweek_year" value="<?php echo htmlspecialchars($selectedMidweekYearValue, ENT_QUOTES, 'UTF-8'); ?>">

                            <?php echo oflc_church_year_render_entry_fields($entry, $seasonOptions, $liturgicalColorOptions, true); ?>
                            <?php echo oflc_church_year_render_readings_panel([], true); ?>

                            <div class="update-service-panel-actions">
                                <button type="submit" class="add-hymn-button">Add</button>
                            </div>
                        </form>
                    <?php else: ?>
                    <?php if ($isUnmatchedEntry): ?>
                        <div class="church-year-unmatched-panel">
                            This active DB row does not match this section's expected helper keys. Update the name to regenerate its logic key.
                        </div>
                    <?php endif; ?>
                    <form class="service-card update-service-edit-card <?php echo htmlspecialchars($colorClass, ENT_QUOTES, 'UTF-8'); ?>" method="post" action="church-year.php?section=<?php echo htmlspecialchars($activeSection, ENT_QUOTES, 'UTF-8'); ?>&amp;midweek_year=<?php echo htmlspecialchars($selectedMidweekYearValue, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="update_church_year" value="1">
                        <input type="hidden" name="entry_id" value="<?php echo $entryId; ?>">
                        <input type="hidden" name="return_section" value="<?php echo htmlspecialchars($activeSection, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="return_midweek_year" value="<?php echo htmlspecialchars($selectedMidweekYearValue, ENT_QUOTES, 'UTF-8'); ?>">

                        <?php echo oflc_church_year_render_entry_fields($entry, $seasonOptions, $liturgicalColorOptions); ?>
                        <?php echo oflc_church_year_render_readings_panel($readingSetsByEntry[$entryId] ?? []); ?>

                        <div class="update-service-panel-actions">
                            <button type="submit" class="add-hymn-button">Update</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-church-year-reading-toggle]');
    var wrap;
    var isOpening;

    if (!button) {
        return;
    }

    wrap = button.nextElementSibling;
    if (wrap) {
        isOpening = wrap.hidden;
        wrap.hidden = !isOpening;
        button.textContent = isOpening
            ? (button.getAttribute('data-open-label') || '-')
            : (button.getAttribute('data-closed-label') || '+ Click to add another reading set');
    }
});

document.addEventListener('toggle', function (event) {
    var row = event.target;
    var list;

    if (!row.matches || !row.matches('.church-year-row') || !row.open) {
        return;
    }

    list = row.closest('.church-year-list');
    if (!list) {
        return;
    }

    Array.prototype.forEach.call(list.querySelectorAll('.church-year-row[open]'), function (otherRow) {
        if (otherRow !== row) {
            otherRow.open = false;
        }
    });
}, true);
</script>

<?php include 'includes/footer.php'; ?>
