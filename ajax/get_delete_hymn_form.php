<?php
require_once '../includes/db.php';

$sections = $pdo->query("
    SELECT DISTINCT hymn_section
    FROM hymn_db
    WHERE hymn_section IS NOT NULL AND hymn_section <> ''
    ORDER BY hymn_section
")->fetchAll(PDO::FETCH_COLUMN);

$hymnals = $pdo->query("
    SELECT DISTINCT hymnal
    FROM hymn_db
    WHERE hymnal IS NOT NULL AND hymnal <> ''
    ORDER BY
        CASE
            WHEN hymnal = 'LSB' THEN 1
            WHEN hymnal = 'TLH' THEN 2
            ELSE 3
        END,
        hymnal
")->fetchAll(PDO::FETCH_COLUMN);

$hymns = $pdo->query("
    SELECT id, hymn_title, hymnal, hymn_number, hymn_tune, hymn_section, kernlieder_target, insert_use, is_active
    FROM hymn_db
    ORDER BY
        CASE
            WHEN hymnal = 'LSB' THEN 1
            WHEN hymnal = 'TLH' THEN 2
            ELSE 3
        END,
        hymnal,
        CAST(hymn_number AS UNSIGNED),
        hymn_number
")->fetchAll(PDO::FETCH_ASSOC);

$searchData = array(
    'hymns' => array(),
    'options' => array(),
);

foreach ($hymns as $hymn) {
    $id = (int) $hymn['id'];
    $sectionName = trim((string) ($hymn['hymn_section'] ?? ''));
    $sectionName = $sectionName === '' ? 'Uncategorized' : $sectionName;
    $numberLabel = trim($hymn['hymnal'] . ' ' . $hymn['hymn_number']);
    $searchLabel = $numberLabel . ' - ' . $hymn['hymn_title'] . ' - ' . $sectionName;

    $searchData['hymns'][$id] = $hymn;
    $searchData['options'][$searchLabel] = $id;
}

$formId = 'delete-hymn-form';
$formTitle = 'Delete Hymn';
$submitLabel = 'Delete Hymn';
$submitClass = 'delete-hymn-button';
$includeIdField = true;
$readOnlyFields = true;
$submitButtonType = 'button';
$submitButtonOnclick = 'submitDeleteForm()';
$hymn = array(
    'id' => 0,
    'kernlieder_target' => 0,
    'insert_use' => 0,
    'is_active' => 1,
);
?>

<div class="delete-warning-banner">WARNING: THIS ACTION CANNOT BE UNDONE!</div>

<div class="hymn-search-panel">
    <h4>Search Hymns</h4>

    <div class="hymn-search-grid hymn-search-grid-single">
        <label>
            Search by Number, Title, or Section
            <input type="text" class="hymn-search-input" data-form-id="delete-hymn-form" list="delete-hymn-search-options">
        </label>
    </div>
</div>

<?php include __DIR__ . '/partials/hymn_form.php'; ?>

<datalist id="delete-hymn-search-options">
    <?php foreach (array_keys($searchData['options']) as $option): ?>
        <option value="<?= htmlspecialchars($option) ?>"></option>
    <?php endforeach; ?>
</datalist>

<script type="application/json" id="delete-hymn-search-data">
<?= json_encode($searchData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>
