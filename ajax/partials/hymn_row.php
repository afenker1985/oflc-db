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
<tbody class="hymn-entry-spacer" data-hymn-spacer-id="<?= $hymnId ?>">
    <tr class="hymn-spacer-row" aria-hidden="true">
        <td colspan="4"></td>
    </tr>
</tbody>
<tbody class="hymn-entry" data-hymn-entry-id="<?= $hymnId ?>">
    <tr class="hymn-summary-row <?= $rowClass ?>" data-hymn-id="<?= $hymnId ?>" data-insert-use="<?= ((int)$hymn['insert_use'] === 1) ? '1' : '0' ?>">
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
    <tr class="hymn-detail-row is-collapsed" data-hymn-detail-id="<?= $hymnId ?>">
        <td colspan="4">
            <div class="hymn-detail-grid">
                <div class="hymn-detail-grid-row hymn-detail-grid-row-primary">
                    <label class="hymn-detail-field hymn-detail-field-hymnal">
                        Hymnal
                        <input type="text" class="hymn-edit-input" data-hymn-id="<?= $hymnId ?>" data-field="hymnal" data-original-value="<?= $hymnalValue ?>" value="<?= $hymnalValue ?>">
                    </label>
                    <label class="hymn-detail-field hymn-detail-field-number">
                        Number
                        <input type="text" class="hymn-edit-input" data-hymn-id="<?= $hymnId ?>" data-field="hymn_number" data-original-value="<?= $hymnNumberValue ?>" value="<?= $hymnNumberValue ?>">
                    </label>
                    <label class="hymn-detail-field hymn-detail-field-title">
                        Title
                        <input type="text" class="hymn-edit-input" data-hymn-id="<?= $hymnId ?>" data-field="hymn_title" data-original-value="<?= $hymnTitleValue ?>" value="<?= $hymnTitleValue ?>">
                    </label>
                    <label class="hymn-detail-field hymn-detail-checkbox hymn-detail-field-insert">
                        <span>Insert</span>
                        <input type="checkbox" class="hymn-edit-input" data-hymn-id="<?= $hymnId ?>" data-field="insert_use" data-original-value="<?= ((int)$hymn['insert_use'] === 1) ? '1' : '0' ?>" <?= ((int)$hymn['insert_use'] === 1) ? 'checked' : '' ?>>
                    </label>
                </div>
                <div class="hymn-detail-grid-row hymn-detail-grid-row-secondary">
                    <label class="hymn-detail-field hymn-detail-field-tune">
                        Tune
                        <input type="text" class="hymn-edit-input" data-hymn-id="<?= $hymnId ?>" data-field="hymn_tune" data-original-value="<?= $hymnTuneValue ?>" value="<?= $hymnTuneValue ?>">
                    </label>
                    <label class="hymn-detail-field hymn-detail-field-section">
                        Section
                        <input type="text" class="hymn-edit-input" data-hymn-id="<?= $hymnId ?>" data-field="hymn_section" data-original-value="<?= $hymnSectionValue ?>" value="<?= $hymnSectionValue ?>">
                    </label>
                    <div class="hymn-detail-actions">
                        <button type="button" class="add-hymn-button js-hymn-save-button" data-hymn-id="<?= $hymnId ?>">Save</button>
                        <button type="button" class="clear-list-button js-hymn-cancel-button" data-hymn-id="<?= $hymnId ?>">Cancel</button>
                    </div>
                </div>
            </div>
        </td>
    </tr>
</tbody>
