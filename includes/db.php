<?php

function oflc_db_ensure_hymn_usage_stanzas(PDO $pdo): void
{
    static $isEnsured = false;

    if ($isEnsured) {
        return;
    }

    $isEnsured = true;

    $statement = $pdo->query("SHOW COLUMNS FROM hymn_usage_db LIKE 'stanzas'");
    if ($statement !== false && $statement->fetch() === false) {
        $pdo->exec('ALTER TABLE hymn_usage_db ADD COLUMN stanzas TEXT NULL AFTER sort_order');
    }
}

$csv = array_map(function ($line) {
    return str_getcsv($line, ',', '"', '\\');
}, file(__DIR__ . '/../config/db.csv'));

$header = array_shift($csv);
$data = array_combine($header, $csv[0]);

$dsn = "mysql:host={$data['host']};dbname={$data['dbname']};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $data['user'], $data['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    oflc_db_ensure_hymn_usage_stanzas($pdo);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

?>
