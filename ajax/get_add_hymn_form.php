<?php
require_once '../includes/db.php';

$sections = $pdo->query("
    SELECT DISTINCT hymn_section
    FROM hymn_db
    WHERE hymn_section IS NOT NULL AND hymn_section <> ''
    ORDER BY hymn_section
")->fetchAll(PDO::FETCH_COLUMN);

$hymnals = $pdo->query("
    SELECT DISTINCT hymnal
    FROM hymn_db
    WHERE hymnal IS NOT NULL AND hymnal <> ''
    ORDER BY
        CASE
            WHEN hymnal = 'LSB' THEN 1
            WHEN hymnal = 'TLH' THEN 2
            ELSE 3
        END,
        hymnal
")->fetchAll(PDO::FETCH_COLUMN);
$formId = 'add-hymn-form';
$formTitle = 'Add Hymn';
$submitLabel = 'Save Hymn';
$submitClass = 'add-hymn-button';
$includeIdField = false;
$readOnlyFields = false;
$submitButtonType = 'submit';
$submitButtonOnclick = '';
$hymn = [
    'kernlieder_target' => 0,
    'insert_use' => 0,
    'is_active' => 1,
];

include __DIR__ . '/partials/hymn_form.php';
