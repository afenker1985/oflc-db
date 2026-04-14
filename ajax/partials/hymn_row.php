<?php
$rowClass = $hymn['is_active'] ? '' : 'inactive-row';
$hymnLabel = htmlspecialchars($hymn['hymnal']) . ' ' . htmlspecialchars($hymn['hymn_number']);

if ((int)$hymn['insert_use'] === 1) {
    $hymnLabel .= '*';
}

$kernliederValue = htmlspecialchars((string) $hymn['kernlieder_target']);
?>
<tr class="<?= $rowClass ?>">
    <td class="hymn-column"><?= $hymnLabel ?></td>
    <td><?= htmlspecialchars($hymn['hymn_title']) ?></td>
    <td class="kernlieder-column">
        <input
            type="number"
            class="kernlieder-input"
            data-hymn-id="<?= (int)$hymn['id'] ?>"
            value="<?= $kernliederValue ?>"
        >
    </td>
    <td class="active-column">
        <input
            type="checkbox"
            class="active-toggle"
            data-hymn-id="<?= (int)$hymn['id'] ?>"
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
