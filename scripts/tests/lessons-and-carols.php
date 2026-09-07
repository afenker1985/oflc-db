<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/hymn_layout.php';
require __DIR__ . '/../../includes/db/service-db-read.php';
$source = file_get_contents(__DIR__ . '/../../update-service.php');
foreach (['oflc_update_normalize_stanza_text', 'oflc_update_build_hymn_field_definitions', 'oflc_update_find_definition_index', 'oflc_update_find_definition_index_by_display_order', 'oflc_update_normalize_hymn_slot_name', 'oflc_update_build_hymn_editor_state'] as $name) {
    $start = strpos($source, 'function ' . $name . '(');
    $end = strpos($source, "\nfunction ", $start + 1);
    eval(substr($source, $start, $end - $start));
}
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$definitions = oflc_update_build_hymn_field_definitions(['abbreviation' => 'Lessons and Carols'], []);
check(count($definitions) === 10, 'Lessons and Carols must have ten base hymns.');
check(count(oflc_update_build_hymn_field_definitions(['abbreviation' => 'DS2'], [])) === 8, 'DS2 layout changed.');
$request = ['hymn_row_order' => '[]', 'hymn_stanzas' => ['10' => '1–3']];
for ($i = 1; $i <= 10; $i++) { $request['hymn_' . $i] = ' ' . (350 + $i) . ' '; }
$submitted = oflc_hymn_layout_read_submitted_hymns($request);
check(count($submitted) === 10 && $submitted[9] === '359' && $submitted[10] === '360', 'Submission dropped late hymns.');
$base = [];
foreach ($definitions as $definition) {
    $i = $definition['index'];
    $base['base:' . $i] = ['value' => $submitted[$i], 'slot_name' => $definition['slot_name'], 'stanzas' => $request['hymn_stanzas'][$i] ?? ''];
}
$canonical = oflc_hymn_layout_build_canonical_rows($definitions, $base, [], array_keys($base));
$usage = [];
foreach ($canonical as $i => $row) {
    $usage[] = ['hymn_number' => $row['value'], 'slot_name' => $row['slot_name'], 'sort_order' => $i + 1, 'stanzas' => $row['stanzas']];
}
$state = oflc_update_build_hymn_editor_state($definitions, $usage);
check($state['hymns'] === $submitted, 'Saved rows did not reload into the ten base fields.');
check($state['stanzas'][10] === '1–3', 'Tenth hymn stanzas were lost.');
$usage[] = ['hymn_number' => '387', 'slot_name' => 'Other Hymn', 'sort_order' => 11];
$state = oflc_update_build_hymn_editor_state($definitions, $usage);
check($state['hymns'] === $submitted && count($state['extra_rows']) === 1, 'Legacy eleventh hymn must remain an extra row without displacing the first ten.');
echo "PASS: ten-hymn submission, save-row construction, reload, stanzas, DS2 layout, and legacy eleventh hymn.\n";
