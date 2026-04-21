<?php
$page_title = 'Leaders Database';
$stylesheet_files = [
    'css/main.css',
    'css/hymns.css',
    'css/services.css',
    'css/database.css',
];
include 'includes/header.php';
include 'includes/db.php';

function oflc_fetch_leaders(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT id, first_name, last_name, is_active
         FROM leaders
         ORDER BY last_name ASC, first_name ASC, id ASC'
    );

    return $statement->fetchAll();
}

$leaders = oflc_fetch_leaders($pdo);
$leaders_js_path = __DIR__ . '/js/leaders.js';
$leaders_js_version = file_exists($leaders_js_path) ? filemtime($leaders_js_path) : time();
?>

<div class="leaders-page">
<h3>Leaders</h3>

<p class="leaders-rubric">Clicking a leader will make him either an active or inactive leader.</p>

<div id="leaders-message" class="leaders-message-slot" aria-live="polite"></div>

<div class="leaders-list-head" aria-hidden="true">
    <div class="leaders-list-head-select"></div>
    <div>First Name</div>
    <div>Last Name</div>
    <div class="leaders-list-head-active">Active</div>
</div>

<div class="leaders-list" id="leaders-table-body">
    <?php if ($leaders === []): ?>
        <div class="leaders-empty-row" id="leaders-empty-row">No leaders were found in the database.</div>
    <?php else: ?>
        <?php foreach ($leaders as $leader): ?>
            <?php
            $leaderId = (int) ($leader['id'] ?? 0);
            $firstName = (string) ($leader['first_name'] ?? '');
            $lastName = (string) ($leader['last_name'] ?? '');
            $fullName = trim($firstName . ' ' . $lastName);
            ?>
            <div
                class="leaders-row"
                data-leader-id="<?php echo $leaderId; ?>"
                data-sort-first="<?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>"
                data-sort-last="<?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?>"
            >
                <div class="leaders-select-column">
                    <input
                        type="radio"
                        class="leaders-select-radio"
                        name="leader_remove_id"
                        value="<?php echo $leaderId; ?>"
                        aria-label="Select <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?> for removal"
                    >
                </div>
                <div class="leaders-first-name-cell"><?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="leaders-last-name-cell"><?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="leaders-active-column">
                    <input
                        type="checkbox"
                        class="leaders-active-checkbox js-leader-active"
                        data-leader-id="<?php echo $leaderId; ?>"
                        aria-label="Set active for <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>"
                        <?php echo (int) ($leader['is_active'] ?? 0) === 1 ? 'checked' : ''; ?>
                    >
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="leaders-form-actions">
    <button type="button" class="add-hymn-button" id="add-leader-button">Add Leader</button>
    <button type="button" class="fill-hymns-button" id="remove-leader-button">Remove Leader</button>
    <button type="button" class="clear-list-button" id="cancel-remove-leader-button" hidden>Cancel Removal</button>
</div>

<div class="leaders-remove-alert" id="leaders-remove-alert" hidden>
    <div class="leaders-remove-alert-title">Are you sure you want to remove <span id="leaders-remove-name" class="leaders-remove-name">this leader</span>? <span class="leaders-remove-alert-warning">THIS ACTION CANNOT BE UNDONE.</span></div>
    <div class="leaders-remove-alert-detail">Removing him will remove him from all services in the database.</div>
    <div class="leaders-remove-alert-actions">
        <button type="button" class="delete-hymn-button" id="leaders-remove-confirm-button">Remove</button>
        <button type="button" class="clear-list-button" id="leaders-remove-cancel-button">Cancel</button>
    </div>
</div>

<script src="js/leaders.js?v=<?php echo rawurlencode((string) $leaders_js_version); ?>"></script>
</div>

<?php include 'includes/footer.php'; ?>
