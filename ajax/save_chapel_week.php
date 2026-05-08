<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/db/service-db-read.php';
require_once __DIR__ . '/../includes/db/chapel-schedule-db.php';

header('Content-Type: application/json');

function oflc_chapel_ajax_parse_values($value): array
{
    if (is_array($value)) {
        $items = $value;
    } else {
        $items = preg_split('/\r\n|\r|\n/', (string) $value) ?: [];
    }

    return array_values(array_filter(array_map(static function ($item): string {
        return trim((string) $item);
    }, $items), static function (string $item): bool {
        return $item !== '';
    }));
}

function oflc_chapel_ajax_build_small_catechism_lookup(array $options): array
{
    $lookup = [];

    foreach ($options as $option) {
        $id = (int) ($option['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        foreach (['label', 'abbreviation'] as $field) {
            $value = strtolower(trim((string) ($option[$field] ?? '')));
            if ($value !== '' && !isset($lookup[$value])) {
                $lookup[$value] = $id;
            }
        }
    }

    return $lookup;
}

function oflc_chapel_ajax_resolve_hymn_ids(array $labels, array $lookupByKey, array &$errors, int $weekNumber): array
{
    $hymnIds = [];
    $caseInsensitiveLookup = [];

    foreach ($lookupByKey as $label => $id) {
        $caseInsensitiveLookup[strtolower((string) $label)] = (int) $id;
    }

    foreach ($labels as $label) {
        $hymnId = (int) ($lookupByKey[$label] ?? $caseInsensitiveLookup[strtolower($label)] ?? 0);
        if ($hymnId <= 0) {
            $errors[] = 'Week ' . $weekNumber . ': hymn "' . $label . '" must match hymn database text.';
            continue;
        }

        $hymnIds[] = $hymnId;
    }

    return array_values(array_unique($hymnIds));
}

function oflc_chapel_ajax_resolve_small_catechism_ids(array $labels, array $lookupByKey): array
{
    $ids = [];

    foreach ($labels as $label) {
        $id = (int) ($lookupByKey[strtolower($label)] ?? 0);
        if ($id <= 0) {
            $ids[] = 999;
            continue;
        }

        $ids[] = $id;
    }

    return $ids;
}

function oflc_chapel_ajax_filter_custom_small_catechism_labels(array $labels, array $lookupByKey): array
{
    return array_values(array_filter($labels, static function (string $label) use ($lookupByKey): bool {
        return (int) ($lookupByKey[strtolower($label)] ?? 0) <= 0;
    }));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    oflc_chapel_schedule_db_ensure_tables($pdo);

    $errors = [];
    $weekNumber = max(1, (int) ($_POST['week_number'] ?? 1));
    $date = trim((string) ($_POST['date'] ?? ''));
    $psalm = trim((string) ($_POST['psalm'] ?? ''));
    $text = trim((string) ($_POST['text'] ?? ''));
    $observanceName = trim((string) ($_POST['observance_name'] ?? ''));
    $hymnLabels = oflc_chapel_ajax_parse_values($_POST['hymns'] ?? []);
    $smallCatechismLabels = oflc_chapel_ajax_parse_values($_POST['small_catechism'] ?? []);

    if ($date === '' && $psalm === '' && $text === '' && $hymnLabels === [] && $smallCatechismLabels === []) {
        $errors[] = 'Week ' . $weekNumber . ': add at least one chapel schedule detail before saving.';
    }

    if ($date !== '') {
        $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$dateObject instanceof DateTimeImmutable || $dateObject->format('Y-m-d') !== $date) {
            $errors[] = 'Week ' . $weekNumber . ': date must use YYYY-MM-DD.';
        }
    }

    $hymnCatalog = oflc_service_db_fetch_hymn_catalog($pdo);
    $smallCatechismOptions = oflc_service_db_fetch_small_catechism_options($pdo);
    $smallCatechismLookup = oflc_chapel_ajax_build_small_catechism_lookup($smallCatechismOptions);
    $hymnIds = oflc_chapel_ajax_resolve_hymn_ids($hymnLabels, $hymnCatalog['lookup_by_key'], $errors, $weekNumber);
    $smallCatechismIds = oflc_chapel_ajax_resolve_small_catechism_ids($smallCatechismLabels, $smallCatechismLookup);
    $customSmallCatechismLabels = oflc_chapel_ajax_filter_custom_small_catechism_labels($smallCatechismLabels, $smallCatechismLookup);

    if ($errors !== []) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    $chapelScheduleId = oflc_chapel_schedule_db_save_row($pdo, [
        'id' => (int) ($_POST['id'] ?? 0),
        'week_number' => $weekNumber,
        'date' => $date,
        'psalm' => $psalm,
        'text' => $text,
        'observance_name' => $observanceName,
    ]);
    $today = date('Y-m-d');
    oflc_chapel_schedule_db_replace_hymn_links($pdo, $chapelScheduleId, $hymnIds, $today);
    oflc_chapel_schedule_db_replace_small_catechism_links($pdo, $chapelScheduleId, $smallCatechismIds, $today);
    oflc_chapel_schedule_db_replace_custom_small_catechism_labels($chapelScheduleId, $customSmallCatechismLabels);

    $schoolYear = oflc_chapel_schedule_db_format_school_year($date);
    echo json_encode([
        'success' => true,
        'id' => $chapelScheduleId,
        'school_year' => $schoolYear,
        'school_year_display' => oflc_chapel_schedule_db_display_school_year($schoolYear),
        'message' => 'Chapel week saved.',
    ]);
} catch (Throwable $e) {
    error_log('Chapel week save failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save chapel week: ' . $e->getMessage()]);
}
