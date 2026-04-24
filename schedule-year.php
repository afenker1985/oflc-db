<?php

declare(strict_types=1);

$page_title = 'Schedule Year';
$stylesheet_files = [
    'css/main.css',
    'css/hymns.css',
    'css/services.css',
    'css/database.css',
];

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/church_year.php';

$configuration = oflc_church_year_get_configuration($pdo);
$savedSettings = oflc_church_year_fetch_saved_settings($pdo);
$effectiveSettings = oflc_church_year_resolve_effective_settings($savedSettings, $configuration);
$formError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $scheduleType = isset($_POST['schedule_type']) ? trim((string) $_POST['schedule_type']) : '';
    if (!isset($configuration[$scheduleType])) {
        $scheduleType = 'church_year';
    }

    $options = $configuration[$scheduleType];
    $indexedOptions = oflc_church_year_index_options($options);
    $defaultKeys = $configuration['defaults'][$scheduleType];
    $startKey = isset($_POST['start_period_key']) ? trim((string) $_POST['start_period_key']) : $defaultKeys['start_period_key'];
    $endKey = isset($_POST['end_period_key']) ? trim((string) $_POST['end_period_key']) : $defaultKeys['end_period_key'];

    if (!isset($indexedOptions[$startKey])) {
        $startKey = $defaultKeys['start_period_key'];
    }

    if (!isset($indexedOptions[$endKey])) {
        $endKey = $defaultKeys['end_period_key'];
    }

    $startOption = $indexedOptions[$startKey];
    $endOption = $indexedOptions[$endKey];

    if (!oflc_church_year_is_valid_range($options, $startKey, $endKey)) {
        $formError = 'Please choose valid start and end periods.';
    } else {
        $effectiveSettings = [
            'schedule_type' => $scheduleType,
            'start_period_key' => (string) $startOption['key'],
            'start_period_label' => (string) $startOption['label'],
            'end_period_key' => (string) $endOption['key'],
            'end_period_label' => (string) $endOption['label'],
        ];

        oflc_church_year_save_settings($pdo, $effectiveSettings);
        header('Location: schedule-year.php?saved=1');
        exit;
    }
} elseif ($savedSettings === null) {
    oflc_church_year_save_settings($pdo, $effectiveSettings);
}

$savedMessage = isset($_GET['saved']) && (string) $_GET['saved'] === '1';
$optionPayload = [
    'church_year' => array_map(static function (array $option): array {
        return [
            'key' => (string) $option['key'],
            'label' => (string) $option['label'],
        ];
    }, $configuration['church_year']),
    'calendar_year' => array_map(static function (array $option): array {
        return [
            'key' => (string) $option['key'],
            'label' => (string) $option['label'],
        ];
    }, $configuration['calendar_year']),
];

include 'includes/header.php';
?>

<div class="church-year-page">
    <h3>Schedule Year</h3>

    <p>These settings store the seasonal or monthly boundaries that can be reused when schedule pages are filtered later, including wraparound ranges such as Easter to Lent or July to June.</p>

    <?php if ($savedMessage): ?>
        <p class="church-year-message church-year-message-success">Schedule year settings were saved.</p>
    <?php endif; ?>

    <?php if ($formError !== null): ?>
        <p class="church-year-message church-year-message-error"><?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <form method="post" action="schedule-year.php" class="church-year-form" id="church-year-form">
        <label class="church-year-field" for="schedule_type">
            <span>Are the services scheduled by Church Year or Calendar Year?</span>
            <select name="schedule_type" id="schedule_type">
                <option value="church_year"<?php echo $effectiveSettings['schedule_type'] === 'church_year' ? ' selected' : ''; ?>>Church Year</option>
                <option value="calendar_year"<?php echo $effectiveSettings['schedule_type'] === 'calendar_year' ? ' selected' : ''; ?>>Calendar Year</option>
            </select>
        </label>

        <label class="church-year-field" for="start_period_key">
            <span>Select start date of Service Schedules:</span>
            <select name="start_period_key" id="start_period_key"></select>
        </label>

        <label class="church-year-field" for="end_period_key">
            <span>Select end date of Service Schedules:</span>
            <select name="end_period_key" id="end_period_key"></select>
        </label>

        <div class="church-year-actions">
            <button type="submit" class="church-year-save">Save Settings</button>
        </div>
    </form>
</div>

<script>
(function() {
    var optionSets = <?php echo json_encode($optionPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var defaultKeys = <?php echo json_encode($configuration['defaults'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    var initialSettings = <?php echo json_encode([
        'schedule_type' => $effectiveSettings['schedule_type'],
        'start_period_key' => $effectiveSettings['start_period_key'],
        'end_period_key' => $effectiveSettings['end_period_key'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    var scheduleTypeSelect = document.getElementById('schedule_type');
    var startSelect = document.getElementById('start_period_key');
    var endSelect = document.getElementById('end_period_key');

    function renderOptions(select, items, selectedKey) {
        select.innerHTML = '';

        for (var i = 0; i < items.length; i += 1) {
            var option = document.createElement('option');
            option.value = items[i].key;
            option.textContent = items[i].label;
            if (items[i].key === selectedKey) {
                option.selected = true;
            }
            select.appendChild(option);
        }

        if (select.selectedIndex === -1 && items.length > 0) {
            select.selectedIndex = 0;
        }
    }

    function applySelections(useCurrentValues) {
        var scheduleType = scheduleTypeSelect.value;
        var items = optionSets[scheduleType] || [];
        var defaults = defaultKeys[scheduleType] || {};
        var selectedStart = useCurrentValues ? startSelect.value : initialSettings.start_period_key;
        var selectedEnd = useCurrentValues ? endSelect.value : initialSettings.end_period_key;
        var hasStart = false;
        var hasEnd = false;
        var i;

        for (i = 0; i < items.length; i += 1) {
            if (items[i].key === selectedStart) {
                hasStart = true;
            }
            if (items[i].key === selectedEnd) {
                hasEnd = true;
            }
        }

        if (!hasStart) {
            selectedStart = defaults.start_period_key || '';
        }

        if (!hasEnd) {
            selectedEnd = defaults.end_period_key || '';
        }

        renderOptions(startSelect, items, selectedStart);
        renderOptions(endSelect, items, selectedEnd);
    }

    scheduleTypeSelect.addEventListener('change', function() {
        applySelections(true);
    });

    applySelections(false);
}());
</script>

<?php include 'includes/footer.php'; ?>
