<?php
require_once '../includes/db.php';

$filter = $_GET['filter'] ?? 'all';
$view = $_GET['view'] ?? 'list';

$where = "1=1";

if ($filter === 'active') {
    $where = "is_active = 1";
} elseif ($filter === 'inactive') {
    $where = "is_active = 0";
}

$stmt = $pdo->query("
    SELECT id, hymnal, hymn_number, hymn_title, hymn_section, kernlieder_target, insert_use, is_active
    FROM hymn_db
    WHERE $where
    ORDER BY
        CASE
            WHEN hymnal = 'LSB' THEN 1
            WHEN hymnal = 'TLH' THEN 2
            ELSE 3
        END,
        hymnal,
        CAST(hymn_number AS UNSIGNED),
        hymn_number,
        COALESCE(NULLIF(hymn_section, ''), 'Uncategorized')
");

$hymns = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<?php if ($view === 'section'): ?>
    <?php
    $groupedHymns = [];

    foreach ($hymns as $hymn) {
        $hymnalName = trim((string) ($hymn['hymnal'] ?? ''));
        $sectionName = trim((string) ($hymn['hymn_section'] ?? ''));

        if ($hymnalName === '') {
            $hymnalName = 'Unknown Hymnal';
        }

        if ($sectionName === '') {
            $sectionName = 'Uncategorized';
        }

        $groupKey = $hymnalName . '|' . $sectionName;

        if (!isset($groupedHymns[$groupKey])) {
            $groupedHymns[$groupKey] = [
                'hymnal' => $hymnalName,
                'section' => $sectionName,
                'hymns' => [],
            ];
        }

        $groupedHymns[$groupKey]['hymns'][] = $hymn;
    }
    ?>

    <?php foreach ($groupedHymns as $group): ?>
        <details class="hymn-section">
            <summary><?= htmlspecialchars($group['hymnal'] . ' - ' . $group['section']) ?> (<?= count($group['hymns']) ?>)</summary>

            <table class="hymn-table">
                <tr>
                    <th class="hymn-column">Hymn</th>
                    <th>Title</th>
                    <th class="kernlieder-column">Kernlieder</th>
                    <th class="active-column">Active</th>
                    <?php /* <th class="insert-column">Insert</th> */ ?>
                </tr>

                <?php foreach ($group['hymns'] as $hymn): ?>
                    <?php include __DIR__ . '/partials/hymn_row.php'; ?>
                <?php endforeach; ?>
            </table>
        </details>
    <?php endforeach; ?>
<?php else: ?>
    <table class="hymn-table">
        <tr>
            <th class="hymn-column">Hymn</th>
            <th>Title</th>
            <th class="kernlieder-column">Kernlieder</th>
            <th class="active-column">Active</th>
            <?php /* <th class="insert-column">Insert</th> */ ?>
        </tr>

        <?php foreach ($hymns as $hymn): ?>
            <?php include __DIR__ . '/partials/hymn_row.php'; ?>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
