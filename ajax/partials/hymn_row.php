<?php
$rowClass = $hymn['is_active'] ? '' : 'inactive-row';
$hymnLabel = htmlspecialchars($hymn['hymnal']) . ' ' . htmlspecialchars($hymn['hymn_number']);
$hymnId = (int) $hymn['id'];

if ((int)$hymn['insert_use'] === 1) {
    $hymnLabel .= '*';
}

$kernliederValue = htmlspecialchars((string) $hymn['kernlieder_target']);
$hymnalValue = htmlspecialchars((string) $hymn['hymnal']);
$hymnNumberValue = htmlspecialchars((string) $hymn['hymn_number']);
$hymnTitleValue = htmlspecialchars((string) $hymn['hymn_title']);
$hymnTuneValue = htmlspecialchars((string) $hymn['hymn_tune']);
$hymnSectionValue = htmlspecialchars((string) $hymn['hymn_section']);
?>
<tr class="hymn-summary-row <?= $rowClass ?>" data-hymn-id="<?= $hymnId ?>">
    <td class="hymn-column">
        <button type="button" class="hymn-expand-toggle" data-hymn-id="<?= $hymnId ?>" aria-expanded="false" tabindex="-1">
            <span data-hymn-display-id="<?= $hymnId ?>"><?= $hymnLabel ?></span>
        </button>
    </td>
    <td data-hymn-title-id="<?= $hymnId ?>"><?= $hymnTitleValue ?></td>
    <td class="kernlieder-column">
        <input
            type="number"
            class="hymn-edit-input kernlieder-input"
            data-hymn-id="<?= $hymnId ?>"
            data-field="kernlieder_target"
            data-original-value="<?= $kernliederValue ?>"
            value="<?= $kernliederValue ?>"
        >
    </td>
    <td class="active-column">
        <input
            type="checkbox"
            class="hymn-edit-input active-toggle"
            data-hymn-id="<?= $hymnId ?>"
            data-field="is_active"
            data-original-value="<?= $hymn['is_active'] ? '1' : '0' ?>"
            <?= $hymn['is_active'] ? 'checked' : '' ?>
        >
    </td>
    <?php /*
    <td class="insert-column">
        <input
            type="checkbox"
            class="insert-toggle"
            data-hymn-id="<?= (int)$hymn['id'] ?>"
            <?= ((int)$hymn['insert_use'] === 1) ? 'checked' : '' ?>
        >
    </td>
    */ ?>
</tr>
<tr class="hymn-detail-row" data-hymn-detail-id="<?= $hymnId ?>" style="display: none;">
    <td colspan="4">
        <div class="hymn-detail-grid">
            <label>
                Hymnal
                <input type="text" class="hymn-edit-input" data-hymn-id="<?= $hymnId ?>" data-field="hymnal" data-original-value="<?= $hymnalValue ?>" value="<?= $hymnalValue ?>">
            </label>
            <label>
                Number
                <input type="text" class="hymn-edit-input" data-hymn-id="<?= $hymnId ?>" data-field="hymn_number" data-original-value="<?= $hymnNumberValue ?>" value="<?= $hymnNumberValue ?>">
            </label>
            <label>
                Title
                <input type="text" class="hymn-edit-input" data-hymn-id="<?= $hymnId ?>" data-field="hymn_title" data-original-value="<?= $hymnTitleValue ?>" value="<?= $hymnTitleValue ?>">
            </label>
            <label>
                Tune
                <input type="text" class="hymn-edit-input" data-hymn-id="<?= $hymnId ?>" data-field="hymn_tune" data-original-value="<?= $hymnTuneValue ?>" value="<?= $hymnTuneValue ?>">
            </label>
            <label>
                Section
                <input type="text" class="hymn-edit-input" data-hymn-id="<?= $hymnId ?>" data-field="hymn_section" data-original-value="<?= $hymnSectionValue ?>" value="<?= $hymnSectionValue ?>">
            </label>
            <label class="hymn-detail-checkbox">
                <span>Need Insert?</span>
                <input type="checkbox" class="hymn-edit-input" data-hymn-id="<?= $hymnId ?>" data-field="insert_use" data-original-value="<?= ((int)$hymn['insert_use'] === 1) ? '1' : '0' ?>" <?= ((int)$hymn['insert_use'] === 1) ? 'checked' : '' ?>>
            </label>
        </div>
    </td>
</tr>
