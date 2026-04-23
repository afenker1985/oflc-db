<?php
declare(strict_types=1);

$page_title = 'Remove/Restore Service';

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/service_observances.php';

function oflc_remove_get_liturgical_color_display($color): string
{
    $color = trim((string) $color);

    return $color === '' ? '' : strtoupper($color);
}

function oflc_remove_get_liturgical_color_text_class($color): string
{
    $color = strtolower(trim((string) $color));

    switch ($color) {
        case 'gold':
            return 'service-card-color-gold';
        case 'green':
            return 'service-card-color-green';
        case 'violet':
        case 'purple':
            return 'service-card-color-violet';
        case 'blue':
            return 'service-card-color-blue';
        case 'rose':
        case 'pink':
            return 'service-card-color-rose';
        case 'scarlet':
        case 'red':
            return 'service-card-color-red';
        case 'black':
            return 'service-card-color-black';
        case 'white':
        case '':
        default:
            return 'service-card-color-dark';
    }
}

function oflc_remove_clean_reading_text($text, bool $removeAntiphon = false): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/\s*\n\s*/', ' ', $text) ?? $text;

    if ($removeAntiphon) {
        $text = preg_replace('/\s*\(antiphon:[^)]+\)/i', '', $text) ?? $text;
    }

    return trim($text);
}

function oflc_remove_fetch_small_catechism_options(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, chief_part, chief_part_id, question, abbreviation, question_order
         FROM small_catechism_mysql
         WHERE is_active = 1
         ORDER BY chief_part_id, question_order, id'
    );

    $options = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $chiefPart = trim((string) ($row['chief_part'] ?? ''));
        $question = trim((string) ($row['question'] ?? ''));
        $abbreviation = trim((string) ($row['abbreviation'] ?? ''));

        $labelParts = array_values(array_filter([$chiefPart, $question], static function ($value): bool {
            return $value !== '';
        }));
        $label = implode(' - ', $labelParts);
        if ($abbreviation !== '') {
            $label .= ($label !== '' ? ' ' : '') . '(' . $abbreviation . ')';
        }

        $row['label'] = $label !== '' ? $label : (string) $id;
        $options[$id] = $row;
    }

    return $options;
}

function oflc_remove_fetch_passion_reading_options(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, gospel, cycle_year, week_number, section_title, reference
         FROM passion_reading_db
         WHERE is_active = 1
         ORDER BY gospel, cycle_year, week_number, id'
    );

    $options = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $gospel = trim((string) ($row['gospel'] ?? ''));
        $cycleYear = trim((string) ($row['cycle_year'] ?? ''));
        $weekNumber = trim((string) ($row['week_number'] ?? ''));
        $sectionTitle = trim((string) ($row['section_title'] ?? ''));
        $reference = trim((string) ($row['reference'] ?? ''));

        $label = $gospel;
        if ($cycleYear !== '') {
            $label .= ($label !== '' ? ' ' : '') . 'Year ' . $cycleYear;
        }
        if ($weekNumber !== '') {
            $label .= ' Week ' . $weekNumber;
        }
        if ($sectionTitle !== '') {
            $label .= ': ' . $sectionTitle;
        }
        if ($reference !== '') {
            $label .= ' (' . $reference . ')';
        }

        $row['label'] = $label !== '' ? $label : (string) $id;
        $options[$id] = $row;
    }

    return $options;
}

function oflc_remove_format_service_setting_summary(array $service): string
{
    $summary = trim((string) ($service['abbreviation'] ?? ''));
    $pageNumber = trim((string) ($service['page_number'] ?? ''));

    if ($pageNumber !== '') {
        $summary .= ($summary !== '' ? ', ' : '') . 'p. ' . $pageNumber;
    }

    return $summary;
}

function oflc_remove_format_hymn_label(array $row): string
{
    $parts = array_filter([
        trim((string) ($row['hymnal'] ?? '')),
        trim((string) ($row['hymn_number'] ?? '')),
    ], static function ($value): bool {
        return $value !== '';
    });

    $label = implode(' ', $parts);
    $title = trim((string) ($row['hymn_title'] ?? ''));

    if ($title !== '') {
        $label = $label !== '' ? $label . ' - ' . $title : $title;
    }

    return $label;
}

function oflc_remove_format_hymn_slot_label(array $row, array &$slotCounts): string
{
    $slotName = trim((string) ($row['slot_name'] ?? 'Hymn'));
    $slotCounts[$slotName] = ($slotCounts[$slotName] ?? 0) + 1;

    if (in_array($slotName, ['Distribution Hymn', 'Other Hymn'], true)) {
        return $slotName . ' ' . $slotCounts[$slotName];
    }

    return $slotName;
}

function oflc_remove_render_readings_html(array $service, ?array $observanceDetail, array $smallCatechismLabels, ?string $passionReadingLabel): string
{
    $html = '';

    foreach ($smallCatechismLabels as $label) {
        $label = trim($label);
        if ($label === '') {
            continue;
        }

        $html .= '<div class="service-card-inline-field-row">';
        $html .= '<input type="text" class="service-card-text update-service-reading-input" value="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" readonly>';
        $html .= '</div>';
    }

    if ($passionReadingLabel !== null && trim($passionReadingLabel) !== '') {
        $html .= '<div class="service-card-inline-field-row">';
        $html .= '<input type="text" class="service-card-text update-service-reading-input" value="' . htmlspecialchars($passionReadingLabel, ENT_QUOTES, 'UTF-8') . '" readonly>';
        $html .= '</div>';
    }

    $readingSets = $observanceDetail['reading_sets'] ?? [];
    if (!is_array($readingSets) || $readingSets === []) {
        if ($html !== '') {
            return $html;
        }

        return trim((string) ($service['observance_name'] ?? '')) !== ''
            ? '<div class="update-service-reading-editor-note">No appointed readings are stored for this observance yet.</div>'
            : '&nbsp;';
    }

    $selectedReadingSetId = (int) ($service['selected_reading_set_id'] ?? 0);
    foreach ($readingSets as $index => $readingSet) {
        $readingSetId = (int) ($readingSet['id'] ?? 0);
        $html .= '<div class="service-card-reading-set' . ($index > 0 ? ' service-card-reading-set-secondary' : '') . '">';

        $psalmText = oflc_remove_clean_reading_text($readingSet['psalm'] ?? null, true);
        if ($psalmText !== '') {
            $html .= '<label class="service-card-reading-psalm">';
            $html .= '<input type="radio" class="service-card-reading-radio" disabled' . ($readingSetId > 0 && $readingSetId === $selectedReadingSetId ? ' checked' : '') . '>';
            $html .= '<span>' . htmlspecialchars($psalmText, ENT_QUOTES, 'UTF-8') . '</span>';
            $html .= '</label>';
        }

        foreach (['old_testament', 'epistle', 'gospel'] as $field) {
            $text = oflc_remove_clean_reading_text($readingSet[$field] ?? null);
            if ($text !== '') {
                $html .= '<div>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
            }
        }

        $html .= '</div>';
    }

    return $html === '' ? '&nbsp;' : $html;
}

function oflc_remove_render_hymns_html(array $hymnRows): string
{
    if ($hymnRows === []) {
        return '&nbsp;';
    }

    $html = '';
    $slotCounts = [];

    foreach ($hymnRows as $row) {
        $label = oflc_remove_format_hymn_label($row);
        if ($label === '') {
            continue;
        }

        $slotLabel = oflc_remove_format_hymn_slot_label($row, $slotCounts);
        $html .= '<div class="service-card-hymn-row">';
        $html .= '<input type="text" class="service-card-hymn-lookup" value="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" placeholder="' . htmlspecialchars($slotLabel, ENT_QUOTES, 'UTF-8') . '" readonly>';
        $html .= '</div>';
    }

    return $html === '' ? '&nbsp;' : $html;
}

function oflc_remove_format_search_label(array $service): string
{
    $labelParts = [
        trim((string) ($service['service_date'] ?? '')),
    ];

    $serviceOrder = (int) ($service['service_order'] ?? 1);
    if ($serviceOrder > 1) {
        $labelParts[] = 'Service ' . $serviceOrder;
    }

    $observanceName = trim((string) ($service['observance_name'] ?? ''));
    if ($observanceName !== '') {
        $labelParts[] = $observanceName;
    }

    $settingName = trim((string) ($service['setting_name'] ?? ''));
    if ($settingName !== '') {
        $labelParts[] = $settingName;
    }

    $label = implode(' - ', $labelParts);
    if (!(bool) ($service['is_active'] ?? false)) {
        $label .= ' [Inactive]';
    }

    return $label;
}

function oflc_remove_register_lookup(array &$lookup, string $key, int $serviceId): void
{
    $key = strtolower(trim($key));
    if ($key === '') {
        return;
    }

    if (!isset($lookup[$key])) {
        $lookup[$key] = $serviceId;
        return;
    }

    if ($lookup[$key] !== $serviceId) {
        $lookup[$key] = 0;
    }
}

$successMessage = null;
$errorMessage = null;
$selectedServiceId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedServiceId = isset($_POST['service_id']) && ctype_digit((string) $_POST['service_id'])
        ? (int) $_POST['service_id']
        : 0;
    $serviceAction = trim((string) ($_POST['service_action'] ?? ''));
    $confirmationText = trim((string) ($_POST['confirmation_text'] ?? ''));
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    if ($selectedServiceId <= 0) {
        $errorMessage = 'Select a service first.';
    } elseif (!in_array($serviceAction, ['deactivate', 'restore', 'delete'], true)) {
        $errorMessage = 'Choose a valid service action.';
    } elseif ($serviceAction === 'delete' && $confirmationText !== 'DELETE') {
        $errorMessage = 'Type DELETE to permanently delete a service.';
    } else {
        try {
            $pdo->beginTransaction();

            $serviceLookupStmt = $pdo->prepare(
                'SELECT id, is_active
                 FROM service_db
                 WHERE id = ?
                 LIMIT 1'
            );
            $serviceLookupStmt->execute([$selectedServiceId]);
            $serviceRow = $serviceLookupStmt->fetch();

            if (!$serviceRow) {
                throw new RuntimeException('That service could not be found.');
            }

            if ($serviceAction === 'restore') {
                if ((int) ($serviceRow['is_active'] ?? 0) === 1) {
                    $successMessage = 'Service is already active.';
                } else {
                    $restoreServiceStmt = $pdo->prepare(
                        'UPDATE service_db
                         SET is_active = 1,
                             last_updated = :today
                         WHERE id = :id
                           AND is_active = 0'
                    );
                    $restoreHymnUsageStmt = $pdo->prepare(
                        'UPDATE hymn_usage_db
                         SET is_active = 1,
                             last_updated = :today
                         WHERE sunday_id = :service_id
                           AND is_active = 0
                           AND version_number = (
                               SELECT version_number
                               FROM (
                                   SELECT MAX(version_number) AS version_number
                                   FROM hymn_usage_db
                                   WHERE sunday_id = :service_id_for_version
                               ) AS latest_version
                           )'
                    );
                    $restoreCatechismStmt = $pdo->prepare(
                        'UPDATE service_small_catechism_db
                         SET is_active = 1,
                             last_updated = :today
                         WHERE service_id = :service_id
                           AND is_active = 0
                           AND last_updated = (
                               SELECT latest_last_updated
                               FROM (
                                   SELECT MAX(last_updated) AS latest_last_updated
                                   FROM service_small_catechism_db
                                   WHERE service_id = :service_id_for_last_updated
                               ) AS latest_catechism_rows
                           )'
                    );

                    $restoreServiceStmt->execute([
                        ':today' => $today,
                        ':id' => $selectedServiceId,
                    ]);
                    $restoreHymnUsageStmt->execute([
                        ':today' => $today,
                        ':service_id' => $selectedServiceId,
                        ':service_id_for_version' => $selectedServiceId,
                    ]);
                    $restoreCatechismStmt->execute([
                        ':today' => $today,
                        ':service_id' => $selectedServiceId,
                        ':service_id_for_last_updated' => $selectedServiceId,
                    ]);

                    $successMessage = 'Service restored.';
                }
            } elseif ($serviceAction === 'deactivate') {
                if ((int) ($serviceRow['is_active'] ?? 0) === 0) {
                    $successMessage = 'Service is already inactive.';
                } else {
                    $deactivateServiceStmt = $pdo->prepare(
                        'UPDATE service_db
                         SET is_active = 0,
                             last_updated = :today
                         WHERE id = :id
                           AND is_active = 1'
                    );
                    $deactivateHymnUsageStmt = $pdo->prepare(
                        'UPDATE hymn_usage_db
                         SET is_active = 0,
                             last_updated = :today
                         WHERE sunday_id = :service_id
                           AND is_active = 1'
                    );
                    $deactivateCatechismStmt = $pdo->prepare(
                        'UPDATE service_small_catechism_db
                         SET is_active = 0,
                             last_updated = :today
                         WHERE service_id = :service_id
                           AND is_active = 1'
                    );
                    $unlinkCopiesStmt = $pdo->prepare(
                        'UPDATE service_db
                         SET copied_from_service_id = NULL,
                             last_updated = :today
                         WHERE copied_from_service_id = :service_id'
                    );

                    $deactivateServiceStmt->execute([
                        ':today' => $today,
                        ':id' => $selectedServiceId,
                    ]);
                    $deactivateHymnUsageStmt->execute([
                        ':today' => $today,
                        ':service_id' => $selectedServiceId,
                    ]);
                    $deactivateCatechismStmt->execute([
                        ':today' => $today,
                        ':service_id' => $selectedServiceId,
                    ]);
                    $unlinkCopiesStmt->execute([
                        ':today' => $today,
                        ':service_id' => $selectedServiceId,
                    ]);

                    $successMessage = 'Service deactivated.';
                }
            } else {
                $deleteServiceStmt = $pdo->prepare(
                    'DELETE FROM service_db
                     WHERE id = ?'
                );
                $deleteServiceStmt->execute([$selectedServiceId]);
                $successMessage = 'Service deleted.';
                $selectedServiceId = 0;
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errorMessage = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'The service could not be removed.';
        }
    }
}

$servicesStmt = $pdo->query(
    'SELECT
        s.id,
        s.service_date,
        s.service_order,
        s.service_setting_id,
        s.selected_reading_set_id,
        s.copied_from_service_id,
        s.liturgical_calendar_id,
        s.passion_reading_id,
        s.is_active,
        lc.name AS observance_name,
        lc.latin_name,
        lc.liturgical_color,
        ss.setting_name,
        ss.abbreviation,
        ss.page_number,
        l.last_name AS leader_last_name
     FROM service_db s
     LEFT JOIN liturgical_calendar lc ON lc.id = s.liturgical_calendar_id
     LEFT JOIN service_settings_db ss ON ss.id = s.service_setting_id
     LEFT JOIN leaders l ON l.id = s.leader_id
     ORDER BY s.service_date DESC, s.service_order DESC, s.id DESC'
);
$services = $servicesStmt->fetchAll();

$serviceIds = array_values(array_filter(array_map(static function (array $service): int {
    return (int) ($service['id'] ?? 0);
}, $services), static function (int $serviceId): bool {
    return $serviceId > 0;
}));

$hymnRowsByService = [];
if ($serviceIds !== []) {
    $placeholders = implode(', ', array_fill(0, count($serviceIds), '?'));
    $hymnStmt = $pdo->prepare(
        'SELECT
            hu.sunday_id AS service_id,
            hs.slot_name,
            hu.sort_order,
            hd.hymnal,
            hd.hymn_number,
            hd.hymn_title
         FROM hymn_usage_db hu
         LEFT JOIN hymn_slot_db hs ON hs.id = hu.slot_id
         LEFT JOIN hymn_db hd ON hd.id = hu.hymn_id
         WHERE hu.is_active = 1
           AND hu.sunday_id IN (' . $placeholders . ')
         ORDER BY hu.sunday_id ASC, hs.default_sort_order ASC, hu.sort_order ASC, hu.id ASC'
    );
    $hymnStmt->execute($serviceIds);

    foreach ($hymnStmt->fetchAll() as $row) {
        $serviceId = (int) ($row['service_id'] ?? 0);
        if ($serviceId <= 0) {
            continue;
        }

        if (!isset($hymnRowsByService[$serviceId])) {
            $hymnRowsByService[$serviceId] = [];
        }

        $hymnRowsByService[$serviceId][] = $row;
    }
}

$smallCatechismLabelsByService = [];
if ($serviceIds !== []) {
    $placeholders = implode(', ', array_fill(0, count($serviceIds), '?'));
    $smallCatechismStmt = $pdo->prepare(
        'SELECT
            sc.service_id,
            sm.chief_part,
            sm.question,
            sm.abbreviation
         FROM service_small_catechism_db sc
         INNER JOIN small_catechism_mysql sm ON sm.id = sc.small_catechism_id
         WHERE sc.is_active = 1
           AND sc.service_id IN (' . $placeholders . ')
         ORDER BY sc.service_id ASC, sc.sort_order ASC, sc.id ASC'
    );
    $smallCatechismStmt->execute($serviceIds);

    foreach ($smallCatechismStmt->fetchAll() as $row) {
        $serviceId = (int) ($row['service_id'] ?? 0);
        if ($serviceId <= 0) {
            continue;
        }

        $labelParts = array_values(array_filter([
            trim((string) ($row['chief_part'] ?? '')),
            trim((string) ($row['question'] ?? '')),
        ], static function ($value): bool {
            return $value !== '';
        }));
        $label = implode(' - ', $labelParts);
        $abbreviation = trim((string) ($row['abbreviation'] ?? ''));
        if ($abbreviation !== '') {
            $label .= ($label !== '' ? ' ' : '') . '(' . $abbreviation . ')';
        }

        $smallCatechismLabelsByService[$serviceId][] = $label !== '' ? $label : 'Small Catechism';
    }
}

$observanceDetailsById = oflc_service_fetch_active_observance_details($pdo);
$passionReadingOptionsById = oflc_remove_fetch_passion_reading_options($pdo);
$searchPayload = [
    'services_by_id' => [],
    'search_labels' => [],
    'id_by_label' => [],
    'id_by_lookup' => [],
];

foreach ($services as $service) {
    $serviceId = (int) ($service['id'] ?? 0);
    if ($serviceId <= 0) {
        continue;
    }

    $observanceId = (int) ($service['liturgical_calendar_id'] ?? 0);
    $observanceDetail = $observanceId > 0 && isset($observanceDetailsById[$observanceId])
        ? $observanceDetailsById[$observanceId]
        : null;
    $colorClass = oflc_remove_get_liturgical_color_text_class($service['liturgical_color'] ?? null);
    $searchLabel = oflc_remove_format_search_label($service);
    $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($service['service_date'] ?? ''));
    $displayDate = $dateObject instanceof DateTimeImmutable
        ? $dateObject->format('l, F j, Y')
        : trim((string) ($service['service_date'] ?? ''));
    $latinName = trim((string) ($service['latin_name'] ?? ''));
    $serviceSettingName = trim((string) ($service['setting_name'] ?? ''));
    $serviceSettingSummary = oflc_remove_format_service_setting_summary($service);
    $leaderName = trim((string) ($service['leader_last_name'] ?? ''));
    $smallCatechismLabels = $smallCatechismLabelsByService[$serviceId] ?? [];
    $passionReadingId = (int) ($service['passion_reading_id'] ?? 0);
    $passionReadingLabel = $passionReadingId > 0 && isset($passionReadingOptionsById[$passionReadingId])
        ? trim((string) ($passionReadingOptionsById[$passionReadingId]['label'] ?? ''))
        : null;

    $searchPayload['services_by_id'][$serviceId] = [
        'id' => $serviceId,
        'search_label' => $searchLabel,
        'service_date' => trim((string) ($service['service_date'] ?? '')),
        'display_date' => $displayDate,
        'observance_name' => trim((string) ($service['observance_name'] ?? '')),
        'latin_name' => $latinName,
        'service_setting_name' => $serviceSettingName,
        'service_setting_summary' => $serviceSettingSummary,
        'leader_name' => $leaderName,
        'status_text' => (int) ($service['is_active'] ?? 0) === 1 ? 'Active service' : 'Inactive service',
        'is_active' => (int) ($service['is_active'] ?? 0) === 1,
        'color_display' => oflc_remove_get_liturgical_color_display($service['liturgical_color'] ?? null),
        'color_class' => $colorClass,
        'readings_html' => oflc_remove_render_readings_html($service, $observanceDetail, $smallCatechismLabels, $passionReadingLabel),
        'hymns_html' => oflc_remove_render_hymns_html($hymnRowsByService[$serviceId] ?? []),
    ];
    $searchPayload['search_labels'][] = $searchLabel;
    $searchPayload['id_by_label'][$searchLabel] = $serviceId;

    foreach ([
        $searchLabel,
        (string) ($service['service_date'] ?? ''),
        trim((string) ($service['observance_name'] ?? '')),
        trim((string) ($service['setting_name'] ?? '')),
        $displayDate,
    ] as $lookupValue) {
        oflc_remove_register_lookup($searchPayload['id_by_lookup'], $lookupValue, $serviceId);
    }
}

include __DIR__ . '/includes/header.php';

$initialSearchValue = '';
$initialIncludeInactive = false;
if ($selectedServiceId > 0 && isset($searchPayload['services_by_id'][$selectedServiceId])) {
    $initialSearchValue = (string) ($searchPayload['services_by_id'][$selectedServiceId]['search_label'] ?? '');
    $initialIncludeInactive = !(bool) ($searchPayload['services_by_id'][$selectedServiceId]['is_active'] ?? true);
}
?>

<div id="remove-service-root">
    <h3>Remove/Restore Service</h3>

    <p>Search for a service by date or observance, review the populated card, then deactivate, restore, or permanently delete it.</p>

    <?php if ($successMessage !== null): ?>
        <p class="planning-success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <p class="planning-error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="remove-service-search-panel">
        <div class="remove-service-search-row">
            <div class="remove-service-search-field">
                <label for="remove-service-search">Search by Date or Observance</label>
                <div class="service-card-suggestion-anchor">
                    <input
                        type="text"
                        id="remove-service-search"
                        class="service-card-text"
                        placeholder="Search active services"
                        autocomplete="off"
                        value="<?php echo htmlspecialchars($initialSearchValue, ENT_QUOTES, 'UTF-8'); ?>"
                    >
                    <div class="service-card-suggestion-list service-card-suggestion-list-fixed js-remove-service-suggestion-list" hidden></div>
                </div>
            </div>
            <label class="service-card-checkbox remove-service-search-toggle" for="remove-service-search-inactive">
                <input type="checkbox" id="remove-service-search-inactive"<?php echo $initialIncludeInactive ? ' checked' : ''; ?>>
                <span>Search inactive services</span>
            </label>
        </div>
    </div>

    <form id="remove-service-form" method="post" action="remove-service.php">
        <input type="hidden" id="remove_service_id" name="service_id" value="">
        <input type="hidden" id="remove_service_action" name="service_action" value="">
        <input type="hidden" id="remove_service_confirmation" name="confirmation_text" value="">

        <div class="remove-service-status" id="remove-service-status">&nbsp;</div>

        <div class="service-card service-card-color-dark remove-service-card" id="remove-service-card">
            <div class="service-card-grid">
                <section class="service-card-panel">
                    <div class="service-card-date-row">
                        <input type="date" id="remove_service_date" class="service-card-text" value="" disabled>
                    </div>
                    <div class="service-card-display-date" id="remove_service_display_date">&nbsp;</div>
                    <div class="service-card-suggestion-anchor">
                        <input type="text" id="remove_observance_name" class="service-card-text" value="" placeholder="Liturgical observance" readonly>
                    </div>
                    <div class="service-card-latin-name" id="remove_observance_latin_name">&nbsp;</div>
                    <div class="service-card-meta">
                        <input type="text" id="remove_service_setting_name" class="service-card-text" value="" placeholder="Service type" readonly>
                        <div class="service-card-service-summary" id="remove_service_setting_summary">&nbsp;</div>
                        <div class="service-card-color-slot">
                            <div class="service-card-color-line" id="remove_service_color">&nbsp;</div>
                        </div>
                    </div>
                </section>

                <section class="service-card-panel">
                    <div class="service-card-readings" id="remove_service_readings">&nbsp;</div>
                </section>

                <section class="service-card-panel">
                    <div class="service-card-hymns" id="remove_service_hymns">&nbsp;</div>
                </section>

                <section class="service-card-panel">
                    <label class="service-card-label" for="remove_preacher">Leader</label>
                    <input type="text" id="remove_preacher" class="service-card-text" value="" placeholder="Fenker" readonly>
                </section>
            </div>
        </div>

        <div class="remove-service-actions">
            <button type="button" class="fill-hymns-button" id="deactivate_service_button" disabled>Deactivate Service</button>
            <div class="remove-service-delete-wrap">
                <button type="button" class="delete-hymn-button remove-service-delete-button" id="delete_service_button" disabled>Delete Service</button>
                <div class="remove-service-warning">WARNING: THIS ACTION CANNOT BE UNDONE.</div>
            </div>
        </div>
    </form>
</div>

<script type="application/json" id="remove-service-data">
<?php echo json_encode($searchPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
</script>

<script>
(function () {
    var dataElement = document.getElementById('remove-service-data');
    var searchInput = document.getElementById('remove-service-search');
    var searchInactiveToggle = document.getElementById('remove-service-search-inactive');
    var searchSuggestionList = document.querySelector('.js-remove-service-suggestion-list');
    var form = document.getElementById('remove-service-form');
    var serviceIdInput = document.getElementById('remove_service_id');
    var serviceActionInput = document.getElementById('remove_service_action');
    var confirmationInput = document.getElementById('remove_service_confirmation');
    var statusNode = document.getElementById('remove-service-status');
    var card = document.getElementById('remove-service-card');
    var deactivateButton = document.getElementById('deactivate_service_button');
    var deleteButton = document.getElementById('delete_service_button');
    var serviceDateInput = document.getElementById('remove_service_date');
    var displayDateNode = document.getElementById('remove_service_display_date');
    var observanceInput = document.getElementById('remove_observance_name');
    var latinNameNode = document.getElementById('remove_observance_latin_name');
    var serviceSettingInput = document.getElementById('remove_service_setting_name');
    var serviceSettingSummaryNode = document.getElementById('remove_service_setting_summary');
    var colorNode = document.getElementById('remove_service_color');
    var readingsNode = document.getElementById('remove_service_readings');
    var hymnsNode = document.getElementById('remove_service_hymns');
    var leaderInput = document.getElementById('remove_preacher');
    var searchData = { services_by_id: {}, id_by_label: {}, id_by_lookup: {} };
    var colorClasses = [
        'service-card-color-dark',
        'service-card-color-gold',
        'service-card-color-green',
        'service-card-color-violet',
        'service-card-color-blue',
        'service-card-color-rose',
        'service-card-color-red',
        'service-card-color-black'
    ];

    if (!dataElement || !searchInput || !form || !serviceIdInput) {
        return;
    }

    try {
        searchData = JSON.parse(dataElement.textContent || '{}');
    } catch (error) {
        searchData = { services_by_id: {}, id_by_label: {}, id_by_lookup: {} };
    }

    function setCardColor(colorClass) {
        colorClasses.forEach(function (className) {
            card.classList.remove(className);
        });

        card.classList.add(colorClass || 'service-card-color-dark');
    }

    function clearSelectedService() {
        serviceIdInput.value = '';
        serviceActionInput.value = '';
        confirmationInput.value = '';
        serviceDateInput.value = '';
        displayDateNode.innerHTML = '&nbsp;';
        observanceInput.value = '';
        latinNameNode.innerHTML = '&nbsp;';
        serviceSettingInput.value = '';
        serviceSettingSummaryNode.innerHTML = '&nbsp;';
        colorNode.innerHTML = '&nbsp;';
        readingsNode.innerHTML = '&nbsp;';
        hymnsNode.innerHTML = '&nbsp;';
        leaderInput.value = '';
        statusNode.innerHTML = '&nbsp;';
        deactivateButton.disabled = true;
        deactivateButton.textContent = 'Deactivate Service';
        deleteButton.disabled = true;
        setCardColor('service-card-color-dark');
    }

    function populateSelectedService(service) {
        serviceIdInput.value = service && service.id ? String(service.id) : '';
        serviceActionInput.value = '';
        confirmationInput.value = '';
        serviceDateInput.value = service && service.service_date ? String(service.service_date) : '';
        displayDateNode.textContent = service && service.display_date ? String(service.display_date) : ' ';
        observanceInput.value = service && service.observance_name ? String(service.observance_name) : '';
        latinNameNode.textContent = service && service.latin_name ? String(service.latin_name) : ' ';
        serviceSettingInput.value = service && service.service_setting_name ? String(service.service_setting_name) : '';
        serviceSettingSummaryNode.textContent = service && service.service_setting_summary ? String(service.service_setting_summary) : ' ';
        colorNode.textContent = service && service.color_display ? String(service.color_display) : ' ';
        readingsNode.innerHTML = service && service.readings_html ? String(service.readings_html) : '&nbsp;';
        hymnsNode.innerHTML = service && service.hymns_html ? String(service.hymns_html) : '&nbsp;';
        leaderInput.value = service && service.leader_name ? String(service.leader_name) : '';
        statusNode.textContent = service && service.status_text ? String(service.status_text) : ' ';
        deactivateButton.disabled = !service;
        deactivateButton.textContent = service && service.is_active ? 'Deactivate Service' : 'Restore Service';
        deleteButton.disabled = !service;
        setCardColor(service && service.color_class ? String(service.color_class) : 'service-card-color-dark');
    }

    function includeInactiveServices() {
        return !!(searchInactiveToggle && searchInactiveToggle.checked);
    }

    function isSearchableService(service) {
        return !!service && (includeInactiveServices() || !!service.is_active);
    }

    function resolveService(value) {
        var normalizedValue = String(value || '').trim();
        var lookupId;
        var service = null;

        if (normalizedValue === '') {
            return null;
        }

        if (searchData.id_by_label && searchData.id_by_label[normalizedValue]) {
            service = searchData.services_by_id[String(searchData.id_by_label[normalizedValue])] || searchData.services_by_id[searchData.id_by_label[normalizedValue]] || null;
            return isSearchableService(service) ? service : null;
        }

        lookupId = searchData.id_by_lookup ? searchData.id_by_lookup[normalizedValue.toLowerCase()] : null;
        if (lookupId && searchData.services_by_id) {
            service = searchData.services_by_id[String(lookupId)] || searchData.services_by_id[lookupId] || null;
            return isSearchableService(service) ? service : null;
        }

        return null;
    }

    function getSuggestionSource(preferAllSuggestions) {
        var query = String(searchInput.value || '').trim().toLowerCase();
        var labels = Array.isArray(searchData.search_labels) ? searchData.search_labels : [];
        var source = labels;

        if (!preferAllSuggestions && query !== '') {
            source = Array.prototype.filter.call(labels, function (label) {
                var service = resolveService(label);

                return !!service && String(label || '').toLowerCase().indexOf(query) !== -1;
            });

            if (source.length === 0) {
                source = Array.prototype.filter.call(labels, function (label) {
                    return !!resolveService(label);
                });
            }
        } else {
            source = Array.prototype.filter.call(labels, function (label) {
                return !!resolveService(label);
            });
        }

        return Array.prototype.filter.call(source, function (label, index) {
            return String(label || '').trim() !== '' && source.indexOf(label) === index;
        });
    }

    function hideSuggestionOptions() {
        if (!searchSuggestionList) {
            return;
        }

        searchSuggestionList.hidden = true;
        searchSuggestionList.classList.remove('is-visible');
        searchSuggestionList.innerHTML = '';
    }

    function renderSuggestionOptions(preferAllSuggestions) {
        var source;

        if (!searchSuggestionList) {
            return;
        }

        source = getSuggestionSource(!!preferAllSuggestions);
        searchSuggestionList.innerHTML = '';

        Array.prototype.forEach.call(source, function (label) {
            var button = document.createElement('button');

            button.type = 'button';
            button.className = 'service-card-suggestion-item';
            button.textContent = label;
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            button.addEventListener('click', function () {
                searchInput.value = label;
                syncSelection();
                hideSuggestionOptions();
                searchInput.focus();
                if (typeof searchInput.setSelectionRange === 'function') {
                    searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
                }
            });

            searchSuggestionList.appendChild(button);
        });

        searchSuggestionList.hidden = source.length === 0;
        searchSuggestionList.classList.toggle('is-visible', source.length > 0);
    }

    function showSuggestionOptions(preferAllSuggestions) {
        renderSuggestionOptions(!!preferAllSuggestions);
    }

    function syncSelection() {
        var service = resolveService(searchInput.value);

        if (!service) {
            if (String(searchInput.value || '').trim() === '') {
                clearSelectedService();
            } else if (!includeInactiveServices()) {
                clearSelectedService();
            }
            return;
        }

        populateSelectedService(service);
        if (service.search_label) {
            searchInput.value = String(service.search_label);
        }
    }

    deactivateButton.addEventListener('click', function () {
        var selectedService = resolveService(searchInput.value);

        if (!serviceIdInput.value) {
            window.alert('Select a service first.');
            return;
        }

        if (!selectedService) {
            window.alert('Select a service first.');
            return;
        }

        if (selectedService.is_active) {
            if (!window.confirm('Deactivate this service?')) {
                return;
            }

            serviceActionInput.value = 'deactivate';
        } else {
            if (!window.confirm('Restore this service?')) {
                return;
            }

            serviceActionInput.value = 'restore';
        }

        confirmationInput.value = '';
        form.submit();
    });

    if (searchInactiveToggle) {
        searchInactiveToggle.addEventListener('change', function () {
            if (String(searchInput.value || '').trim() === '') {
                clearSelectedService();
            } else {
                syncSelection();
            }

            showSuggestionOptions(false);
        });
    }

    deleteButton.addEventListener('click', function () {
        var confirmation;

        if (!serviceIdInput.value) {
            window.alert('Select a service first.');
            return;
        }

        confirmation = window.prompt('Are you sure? This cannot be undone. Type "DELETE" to permanently delete the service.');
        if (confirmation !== 'DELETE') {
            return;
        }

        serviceActionInput.value = 'delete';
        confirmationInput.value = confirmation;
        form.submit();
    });

    searchInput.addEventListener('change', syncSelection);
    searchInput.addEventListener('blur', syncSelection);
    searchInput.addEventListener('input', function () {
        if (String(searchInput.value || '').trim() === '') {
            clearSelectedService();
        }

        showSuggestionOptions(false);
    });
    searchInput.addEventListener('focus', function () {
        showSuggestionOptions(true);
    });
    searchInput.addEventListener('click', function () {
        showSuggestionOptions(true);
    });
    searchInput.addEventListener('blur', function () {
        window.setTimeout(hideSuggestionOptions, 120);
    });

    if (String(searchInput.value || '').trim() !== '') {
        syncSelection();
    } else {
        clearSelectedService();
    }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
