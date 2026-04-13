<?php
require_once '../includes/db.php';

$filter = $_GET['filter'] ?? 'all';

$where = "1=1";

if ($filter === 'active') {
    $where = "is_active = 1";
} elseif ($filter === 'inactive') {
    $where = "is_active = 0";
}

$stmt = $pdo->query("
    SELECT id, hymnal, hymn_number, hymn_title, kernlieder_target, insert_use, is_active
    FROM hymn_db
    WHERE $where
    ORDER BY hymnal, hymn_number
");

$hymns = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<table class="hymn-table">
    <tr>
        <th>Hymnal</th>
        <th>Number</th>
        <th>Title</th>
        <th>Kernlieder Target</th>
        <th>Active</th>
    </tr>

    <?php foreach ($hymns as $hymn): ?>
        <?php
            $rowClass = $hymn['is_active'] ? '' : 'inactive-row';
            $number = htmlspecialchars($hymn['hymn_number']);
            if ($hymn['insert_use']) {
                $number .= '*';
            }
        ?>
        <tr class="<?= $rowClass ?>">
            <td><?= htmlspecialchars($hymn['hymnal']) ?></td>
            <td><?= $number ?></td>
            <td><?= htmlspecialchars($hymn['hymn_title']) ?></td>
            <td><?= (int)$hymn['kernlieder_target'] ?></td>
            <td><?= $hymn['is_active'] ? 'Yes' : 'No' ?></td>
        </tr>
    <?php endforeach; ?>
</table>