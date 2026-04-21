<?php
$hymn = isset($hymn) && is_array($hymn) ? $hymn : [];
$formId = $formId ?? 'hymn-form';
$formTitle = $formTitle ?? 'Hymn Form';
$submitLabel = $submitLabel ?? 'Save Hymn';
$submitClass = $submitClass ?? '';
$includeIdField = $includeIdField ?? false;
$readOnlyFields = $readOnlyFields ?? false;
$submitButtonType = $submitButtonType ?? 'submit';
$submitButtonOnclick = $submitButtonOnclick ?? '';

$hymnTitleValue = htmlspecialchars((string) ($hymn['hymn_title'] ?? ''));
$hymnalValue = htmlspecialchars((string) ($hymn['hymnal'] ?? ''));
$hymnNumberValue = htmlspecialchars((string) ($hymn['hymn_number'] ?? ''));
$hymnTuneValue = htmlspecialchars((string) ($hymn['hymn_tune'] ?? ''));
$hymnSectionValue = htmlspecialchars((string) ($hymn['hymn_section'] ?? ''));
$kernliederValue = htmlspecialchars((string) ($hymn['kernlieder_target'] ?? '0'));
$insertChecked = !empty($hymn['insert_use']) ? 'checked' : '';
$activeChecked = !array_key_exists('is_active', $hymn) || !empty($hymn['is_active']) ? 'checked' : '';
$readOnlyAttribute = $readOnlyFields ? 'readonly' : '';
$disabledAttribute = $readOnlyFields ? 'disabled' : '';
?>

<form id="<?= htmlspecialchars($formId) ?>" class="hymn-form">
    <h4><?= htmlspecialchars($formTitle) ?></h4>

    <?php if ($includeIdField): ?>
        <input type="hidden" name="id" value="<?= (int) ($hymn['id'] ?? 0) ?>">
    <?php endif; ?>

    <div class="hymn-form-row hymn-form-row-primary">
        <label class="hymn-form-field hymn-form-field-hymnal">
            Hymnal
            <input type="text" name="hymnal" list="hymnal-options" value="<?= $hymnalValue ?>" required <?= $readOnlyAttribute ?>>
        </label>

        <label class="hymn-form-field hymn-form-field-number">
            Hymn Number
            <input type="text" name="hymn_number" value="<?= $hymnNumberValue ?>" required <?= $readOnlyAttribute ?>>
        </label>

        <label class="hymn-form-field hymn-form-field-title">
            Title
            <input type="text" name="hymn_title" value="<?= $hymnTitleValue ?>" required <?= $readOnlyAttribute ?>>
        </label>

        <label class="hymn-form-field hymn-form-checkbox-field hymn-form-field-insert">
            <span class="hymn-form-checkbox-label">Insert</span>
            <input type="checkbox" name="insert_use" value="1" <?= $insertChecked ?> <?= $disabledAttribute ?>>
        </label>
    </div>

    <div class="hymn-form-row hymn-form-row-secondary">
        <label class="hymn-form-field hymn-form-field-tune">
            Tune
            <input type="text" name="hymn_tune" value="<?= $hymnTuneValue ?>" <?= $readOnlyAttribute ?>>
        </label>

        <label class="hymn-form-field hymn-form-field-section">
            Section
            <input type="text" name="hymn_section" list="section-options" value="<?= $hymnSectionValue ?>" <?= $readOnlyAttribute ?>>
        </label>

        <label class="hymn-form-field hymn-form-field-kernlieder">
            Kernlieder
            <input type="number" name="kernlieder_target" value="<?= $kernliederValue ?>" min="0" required <?= $readOnlyAttribute ?>>
        </label>

        <label class="hymn-form-field hymn-form-checkbox-field hymn-form-field-active">
            <span class="hymn-form-checkbox-label">Active</span>
            <input type="checkbox" name="is_active" value="1" <?= $activeChecked ?> <?= $disabledAttribute ?>>
        </label>
    </div>

    <div class="hymn-form-actions">
        <button type="<?= htmlspecialchars($submitButtonType) ?>" class="<?= htmlspecialchars($submitClass) ?>"<?= $submitButtonOnclick !== '' ? ' onclick="' . htmlspecialchars($submitButtonOnclick) . '"' : '' ?>><?= htmlspecialchars($submitLabel) ?></button>
        <button type="button" onclick="loadHymns(currentFilter, currentView)">Cancel</button>
    </div>
</form>

<datalist id="section-options">
    <?php foreach ($sections as $section): ?>
        <option value="<?= htmlspecialchars($section) ?>"></option>
    <?php endforeach; ?>
</datalist>

<datalist id="hymnal-options">
    <?php foreach ($hymnals as $hymnal): ?>
        <option value="<?= htmlspecialchars($hymnal) ?>"></option>
    <?php endforeach; ?>
</datalist>
