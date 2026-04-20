<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

function oflc_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);

    return (bool) $stmt->fetchColumn();
}

function oflc_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);

    return (bool) $stmt->fetchColumn();
}

function oflc_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);

    return (bool) $stmt->fetchColumn();
}

function oflc_foreign_key_exists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ?'
    );
    $stmt->execute([$table, $constraint]);

    return (bool) $stmt->fetchColumn();
}

function oflc_ensure_leaders_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `leaders` (
            `id` int NOT NULL AUTO_INCREMENT,
            `first_name` varchar(100) NOT NULL,
            `last_name` varchar(100) NOT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT \'1\',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
    );

    if (!oflc_index_exists($pdo, 'leaders', 'uq_leaders_name')) {
        $pdo->exec('ALTER TABLE `leaders` ADD UNIQUE KEY `uq_leaders_name` (`first_name`, `last_name`)');
    }

    if (!oflc_index_exists($pdo, 'leaders', 'idx_leaders_active_name')) {
        $pdo->exec('ALTER TABLE `leaders` ADD KEY `idx_leaders_active_name` (`is_active`, `last_name`, `first_name`)');
    }

    if (!oflc_column_exists($pdo, 'service_db', 'leader_id')) {
        $pdo->exec('ALTER TABLE `service_db` ADD COLUMN `leader_id` int DEFAULT NULL AFTER `service_setting_id`');
    }

    if (!oflc_index_exists($pdo, 'service_db', 'idx_service_leader')) {
        $pdo->exec('ALTER TABLE `service_db` ADD KEY `idx_service_leader` (`leader_id`)');
    }

    if (!oflc_foreign_key_exists($pdo, 'service_db', 'fk_service_leader')) {
        $pdo->exec(
            'ALTER TABLE `service_db`
             ADD CONSTRAINT `fk_service_leader`
             FOREIGN KEY (`leader_id`) REFERENCES `leaders` (`id`)
             ON UPDATE CASCADE ON DELETE SET NULL'
        );
    }
}

function oflc_seed_leaders(PDO $pdo): array
{
    $leaders = [
        ['first_name' => 'Brennick', 'last_name' => 'Christiansen'],
        ['first_name' => 'Mark', 'last_name' => 'Nuckols'],
        ['first_name' => 'Aaron', 'last_name' => 'Koch'],
        ['first_name' => 'Karl', 'last_name' => 'Fabrizius'],
        ['first_name' => 'Aaron', 'last_name' => 'Fenker'],
    ];

    $insert = $pdo->prepare(
        'INSERT INTO `leaders` (`first_name`, `last_name`, `is_active`)
         VALUES (:first_name, :last_name, 1)
         ON DUPLICATE KEY UPDATE
           `is_active` = VALUES(`is_active`)'
    );

    foreach ($leaders as $leader) {
        $insert->execute($leader);
    }

    $rows = $pdo->query(
        'SELECT `id`, `first_name`, `last_name`
         FROM `leaders`
         WHERE `is_active` = 1'
    )->fetchAll();

    $byLastName = [];
    foreach ($rows as $row) {
        $byLastName[(string) $row['last_name']] = (int) $row['id'];
    }

    return $byLastName;
}

function oflc_apply_leader_assignments(PDO $pdo, array $leaderIds): array
{
    $required = ['Christiansen', 'Nuckols', 'Koch', 'Fabrizius', 'Fenker'];
    foreach ($required as $lastName) {
        if (!isset($leaderIds[$lastName])) {
            throw new RuntimeException('Missing leader id for ' . $lastName . '.');
        }
    }

    $default = $pdo->prepare('UPDATE `service_db` SET `leader_id` = ? WHERE `is_active` = 1');
    $byDate = $pdo->prepare('UPDATE `service_db` SET `leader_id` = ? WHERE `is_active` = 1 AND `service_date` = ?');
    $byRange = $pdo->prepare('UPDATE `service_db` SET `leader_id` = ? WHERE `is_active` = 1 AND `service_date` BETWEEN ? AND ?');

    $default->execute([$leaderIds['Fenker']]);

    $kochDates = ['2025-07-03', '2025-07-17', '2025-08-07'];
    foreach ($kochDates as $date) {
        $byDate->execute([$leaderIds['Koch'], $date]);
    }

    $byRange->execute([$leaderIds['Christiansen'], '2025-07-06', '2025-07-13']);
    $byRange->execute([$leaderIds['Christiansen'], '2025-07-20', '2025-07-27']);

    $byDate->execute([$leaderIds['Fabrizius'], '2025-07-31']);

    $nuckolsDates = ['2025-08-03', '2025-08-10'];
    foreach ($nuckolsDates as $date) {
        $byDate->execute([$leaderIds['Nuckols'], $date]);
    }

    $summary = [];
    $stmt = $pdo->query(
        'SELECT l.last_name, COUNT(*) AS service_count
         FROM service_db s
         LEFT JOIN leaders l ON l.id = s.leader_id
         WHERE s.is_active = 1
         GROUP BY l.last_name
         ORDER BY l.last_name'
    );
    foreach ($stmt->fetchAll() as $row) {
        $summary[(string) ($row['last_name'] ?? '')] = (int) $row['service_count'];
    }

    return $summary;
}

try {
    if (!oflc_table_exists($pdo, 'service_db')) {
        throw new RuntimeException('service_db must exist before syncing leaders.');
    }

    oflc_ensure_leaders_schema($pdo);
    $pdo->beginTransaction();
    $leaderIds = oflc_seed_leaders($pdo);
    $summary = oflc_apply_leader_assignments($pdo, $leaderIds);
    $pdo->commit();

    echo 'Leaders synced.', PHP_EOL;
    foreach ($summary as $lastName => $count) {
        echo ($lastName !== '' ? $lastName : 'Unassigned') . ': ' . $count . PHP_EOL;
    }
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
