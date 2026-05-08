<?php
declare(strict_types=1);

function oflc_chapel_schedule_db_ensure_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS chapel_schedule_db (
            id INT AUTO_INCREMENT PRIMARY KEY,
            week_number INT NOT NULL,
            `date` DATE NULL,
            psalm VARCHAR(255) NOT NULL DEFAULT \'\',
            `text` TEXT NULL,
            observance_name VARCHAR(255) NOT NULL DEFAULT \'\',
            school_year VARCHAR(20) NOT NULL DEFAULT \'\',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            INDEX idx_chapel_schedule_active_school_year (is_active, school_year),
            INDEX idx_chapel_schedule_active_week (is_active, week_number)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS chapel_hymn_usage_db (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chapel_schedule_id INT NOT NULL,
            hymn_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 1,
            created_at DATE NOT NULL,
            last_updated DATE NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            INDEX idx_chapel_hymn_active_schedule (is_active, chapel_schedule_id),
            INDEX idx_chapel_hymn_hymn (hymn_id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS chapel_small_catechism_usage_db (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chapel_schedule_id INT NOT NULL,
            small_catechism_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 1,
            created_at DATE NOT NULL,
            last_updated DATE NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            INDEX idx_chapel_sc_active_schedule (is_active, chapel_schedule_id),
            INDEX idx_chapel_sc_small_catechism (small_catechism_id)
        )'
    );

    $statement = $pdo->query("SHOW COLUMNS FROM chapel_schedule_db LIKE 'observance_name'");
    if ($statement !== false && $statement->fetch() === false) {
        $pdo->exec("ALTER TABLE chapel_schedule_db ADD COLUMN observance_name VARCHAR(255) NOT NULL DEFAULT '' AFTER `text`");
    }
}

function oflc_chapel_schedule_db_format_school_year(string $date): string
{
    $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$dateObject instanceof DateTimeImmutable) {
        $dateObject = new DateTimeImmutable('today');
    }

    $year = (int) $dateObject->format('Y');
    $month = (int) $dateObject->format('n');
    $startYear = $month >= 8 ? $year : $year - 1;

    return sprintf('%02d-%02d', $startYear % 100, ($startYear + 1) % 100);
}

function oflc_chapel_schedule_db_display_school_year(string $schoolYear): string
{
    $schoolYear = trim($schoolYear);
    if (!preg_match('/^(\d{2})-(\d{2})$/', $schoolYear, $matches)) {
        return $schoolYear;
    }

    return '20' . $matches[1] . '/20' . $matches[2];
}

function oflc_chapel_schedule_db_is_baptismal_remembrance_date(string $date, string $nextChapelDate = ''): bool
{
    $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$dateObject instanceof DateTimeImmutable || $dateObject->format('Y-m-d') !== $date) {
        return false;
    }

    if ($dateObject->format('w') !== '3') {
        return false;
    }

    $nextChapelDate = trim($nextChapelDate);
    if ($nextChapelDate !== '') {
        $nextDateObject = DateTimeImmutable::createFromFormat('Y-m-d', $nextChapelDate);
        if ($nextDateObject instanceof DateTimeImmutable && $nextDateObject->format('Y-m-d') === $nextChapelDate && $nextDateObject > $dateObject) {
            return $nextDateObject->format('Y-m') !== $dateObject->format('Y-m');
        }
    }

    return $dateObject->modify('+7 days')->format('Y-m') !== $dateObject->format('Y-m');
}

function oflc_chapel_schedule_db_build_next_date_lookup(array $rows): array
{
    $dates = [];
    foreach ($rows as $row) {
        $date = trim((string) ($row['date'] ?? ''));
        $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dateObject instanceof DateTimeImmutable && $dateObject->format('Y-m-d') === $date) {
            $dates[$date] = $date;
        }
    }

    sort($dates, SORT_STRING);

    $nextDateByDate = [];
    foreach (array_values($dates) as $index => $date) {
        $nextDateByDate[$date] = $dates[$index + 1] ?? '';
    }

    return $nextDateByDate;
}

function oflc_chapel_schedule_db_clean_psalm_text($text): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/\s*\n\s*/', ' ', $text) ?? $text;
    $text = preg_replace('/\s*\(antiphon:[^)]+\)/i', '', $text) ?? $text;

    return trim($text);
}

function oflc_chapel_schedule_db_get_psalm_for_logic_key(PDO $pdo, string $logicKey): string
{
    $detail = oflc_service_db_fetch_observance_detail($pdo, $logicKey);
    if ($detail === null) {
        return '';
    }

    foreach ($detail['reading_sets'] ?? [] as $readingSet) {
        $psalm = oflc_chapel_schedule_db_clean_psalm_text($readingSet['psalm'] ?? '');
        if ($psalm !== '') {
            return $psalm;
        }
    }

    return '';
}

function oflc_chapel_schedule_db_add_observance_suggestion(
    PDO $pdo,
    array &$suggestions,
    array &$psalmsBySuggestion,
    string $label,
    string $logicKey
): void {
    $label = trim($label);
    $logicKey = trim($logicKey);
    if ($label === '' || $logicKey === '' || in_array($label, $suggestions, true)) {
        return;
    }

    $suggestions[] = $label;
    $psalm = oflc_chapel_schedule_db_get_psalm_for_logic_key($pdo, $logicKey);
    if ($psalm !== '') {
        $psalmsBySuggestion[$label] = $psalm;
    }
}

function oflc_chapel_schedule_db_build_observance_suggestion_payload(PDO $pdo, string $date): array
{
    $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$dateObject instanceof DateTimeImmutable || $dateObject->format('Y-m-d') !== $date) {
        return [
            'suggestions' => [],
            'psalms_by_suggestion' => [],
        ];
    }

    if (!oflc_service_db_planning_logic_columns_ready($pdo)) {
        return [
            'suggestions' => [],
            'psalms_by_suggestion' => [],
        ];
    }

    $previousSunday = oflc_get_sunday($dateObject);
    $logicKeys = [];
    $sundayLogicKeys = oflc_resolve_movable_logic_keys(oflc_get_one_year_week($previousSunday), 0);
    foreach ($sundayLogicKeys as $logicKey) {
        $logicKeys[] = $logicKey;
    }

    $window = oflc_get_liturgical_window($date, 7, 7);
    foreach ($window['entries'] ?? [] as $entry) {
        foreach (oflc_resolve_movable_logic_keys($entry['week'] ?? null, (int) ($entry['weekday'] ?? -1)) as $logicKey) {
            $logicKeys[] = $logicKey;
        }
        foreach (oflc_resolve_fixed_logic_keys((int) $entry['month'], (int) $entry['day']) as $logicKey) {
            $logicKeys[] = $logicKey;
        }
    }

    $logicKeyNameMap = oflc_service_db_fetch_logic_key_name_map($pdo, $logicKeys);
    $suggestions = [];
    $psalmsBySuggestion = [];

    foreach ($sundayLogicKeys as $logicKey) {
        $name = trim((string) ($logicKeyNameMap[$logicKey] ?? ''));
        oflc_chapel_schedule_db_add_observance_suggestion($pdo, $suggestions, $psalmsBySuggestion, $name, $logicKey);
    }

    foreach ($window['entries'] ?? [] as $entry) {
        $entryDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($entry['date'] ?? ''));
        $dateLabel = $entryDate instanceof DateTimeImmutable ? $entryDate->format('D m/d') : (string) ($entry['date'] ?? '');
        foreach (oflc_resolve_movable_logic_keys($entry['week'] ?? null, (int) ($entry['weekday'] ?? -1)) as $logicKey) {
            if (in_array($logicKey, $sundayLogicKeys, true)) {
                continue;
            }

            $name = trim((string) ($logicKeyNameMap[$logicKey] ?? ''));
            if ($name === '') {
                continue;
            }

            oflc_chapel_schedule_db_add_observance_suggestion(
                $pdo,
                $suggestions,
                $psalmsBySuggestion,
                $name . ' (' . $dateLabel . ')',
                $logicKey
            );
        }

        foreach (oflc_resolve_fixed_logic_keys((int) $entry['month'], (int) $entry['day']) as $logicKey) {
            $name = trim((string) ($logicKeyNameMap[$logicKey] ?? ''));
            if ($name === '') {
                continue;
            }

            oflc_chapel_schedule_db_add_observance_suggestion(
                $pdo,
                $suggestions,
                $psalmsBySuggestion,
                $name . ' (' . $dateLabel . ')',
                $logicKey
            );
        }
    }

    return [
        'suggestions' => $suggestions,
        'psalms_by_suggestion' => $psalmsBySuggestion,
    ];
}

function oflc_chapel_schedule_db_build_observance_suggestions(PDO $pdo, string $date): array
{
    $payload = oflc_chapel_schedule_db_build_observance_suggestion_payload($pdo, $date);

    return $payload['suggestions'];
}

function oflc_chapel_schedule_db_fetch_school_years(PDO $pdo): array
{
    oflc_chapel_schedule_db_ensure_tables($pdo);

    return $pdo
        ->query(
            "SELECT DISTINCT school_year
             FROM chapel_schedule_db
             WHERE is_active = 1
               AND school_year <> ''
             ORDER BY school_year ASC"
        )
        ->fetchAll(PDO::FETCH_COLUMN);
}

function oflc_chapel_schedule_db_custom_small_catechism_options_path(): string
{
    return __DIR__ . '/../../chapel-small-catechism-custom-options.json';
}

function oflc_chapel_schedule_db_custom_small_catechism_usage_path(): string
{
    return __DIR__ . '/../../chapel-small-catechism-custom-usage.json';
}

function oflc_chapel_schedule_db_legacy_custom_small_catechism_path(): string
{
    return __DIR__ . '/../../chapel-small-catechism-custom.json';
}

function oflc_chapel_schedule_db_clean_label_list(array $labels): array
{
    return array_values(array_filter(array_map(static function ($label): string {
        return trim((string) $label);
    }, $labels), static function (string $label): bool {
        return $label !== '';
    }));
}

function oflc_chapel_schedule_db_read_json_file(string $path, array $fallback): array
{
    if (!is_file($path)) {
        return $fallback;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $fallback;
    }

    return $decoded;
}

function oflc_chapel_schedule_db_write_json_file(string $path, array $data): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
        LOCK_EX
    );
}

function oflc_chapel_schedule_db_read_custom_small_catechism_options_data(): array
{
    $data = oflc_chapel_schedule_db_read_json_file(oflc_chapel_schedule_db_custom_small_catechism_options_path(), [
        'next_id' => 1,
        'options' => [],
    ]);
    $options = [];
    $nextId = max(1, (int) ($data['next_id'] ?? 1));

    foreach (($data['options'] ?? []) as $option) {
        if (!is_array($option)) {
            continue;
        }

        $id = (int) ($option['id'] ?? 0);
        $label = trim((string) ($option['label'] ?? ''));
        if ($id <= 0 || $label === '') {
            continue;
        }

        $options[] = [
            'id' => $id,
            'label' => $label,
        ];
        $nextId = max($nextId, $id + 1);
    }

    usort($options, static function (array $left, array $right): int {
        return strnatcasecmp((string) $left['label'], (string) $right['label']);
    });

    return [
        'next_id' => $nextId,
        'options' => $options,
    ];
}

function oflc_chapel_schedule_db_write_custom_small_catechism_options_data(array $data): void
{
    oflc_chapel_schedule_db_write_json_file(oflc_chapel_schedule_db_custom_small_catechism_options_path(), [
        'next_id' => max(1, (int) ($data['next_id'] ?? 1)),
        'options' => array_values($data['options'] ?? []),
    ]);
}

function oflc_chapel_schedule_db_read_custom_small_catechism_usage_data(): array
{
    $data = oflc_chapel_schedule_db_read_json_file(oflc_chapel_schedule_db_custom_small_catechism_usage_path(), [
        'usage' => [],
    ]);
    $usage = [];

    foreach (($data['usage'] ?? []) as $scheduleId => $optionIds) {
        $scheduleId = (int) $scheduleId;
        if ($scheduleId <= 0 || !is_array($optionIds)) {
            continue;
        }

        $usage[(string) $scheduleId] = array_values(array_filter(array_map('intval', $optionIds), static function (int $id): bool {
            return $id > 0;
        }));
    }

    ksort($usage);

    return [
        'usage' => $usage,
    ];
}

function oflc_chapel_schedule_db_write_custom_small_catechism_usage_data(array $data): void
{
    $usage = [];
    foreach (($data['usage'] ?? []) as $scheduleId => $optionIds) {
        $scheduleId = (int) $scheduleId;
        if ($scheduleId <= 0 || !is_array($optionIds)) {
            continue;
        }

        $cleanOptionIds = array_values(array_filter(array_map('intval', $optionIds), static function (int $id): bool {
            return $id > 0;
        }));
        if ($cleanOptionIds !== []) {
            $usage[(string) $scheduleId] = $cleanOptionIds;
        }
    }
    ksort($usage);

    oflc_chapel_schedule_db_write_json_file(oflc_chapel_schedule_db_custom_small_catechism_usage_path(), [
        'usage' => (object) $usage,
    ]);
}

function oflc_chapel_schedule_db_find_or_create_custom_small_catechism_option_id(string $label, array &$optionsData): int
{
    $label = trim($label);
    if ($label === '') {
        return 0;
    }

    foreach ($optionsData['options'] as $option) {
        if (strcasecmp((string) ($option['label'] ?? ''), $label) === 0) {
            return (int) ($option['id'] ?? 0);
        }
    }

    $id = max(1, (int) ($optionsData['next_id'] ?? 1));
    $optionsData['options'][] = [
        'id' => $id,
        'label' => $label,
    ];
    $optionsData['next_id'] = $id + 1;

    return $id;
}

function oflc_chapel_schedule_db_fetch_custom_small_catechism_options(): array
{
    $data = oflc_chapel_schedule_db_read_custom_small_catechism_options_data();

    return oflc_chapel_schedule_db_clean_label_list(array_map(static function (array $option): string {
        return (string) ($option['label'] ?? '');
    }, $data['options']));
}

function oflc_chapel_schedule_db_fetch_custom_small_catechism_labels_by_schedule(array $chapelScheduleIds): array
{
    $idLookup = array_flip(array_map('strval', array_filter(array_map('intval', $chapelScheduleIds), static function (int $id): bool {
        return $id > 0;
    })));
    if ($idLookup === []) {
        return [];
    }

    $optionsData = oflc_chapel_schedule_db_read_custom_small_catechism_options_data();
    $labelByOptionId = [];
    foreach ($optionsData['options'] as $option) {
        $labelByOptionId[(int) ($option['id'] ?? 0)] = trim((string) ($option['label'] ?? ''));
    }

    $usageData = oflc_chapel_schedule_db_read_custom_small_catechism_usage_data();
    $labelsBySchedule = [];
    foreach ($usageData['usage'] as $scheduleId => $optionIds) {
        if (!isset($idLookup[(string) $scheduleId])) {
            continue;
        }

        foreach ($optionIds as $optionId) {
            $label = $labelByOptionId[(int) $optionId] ?? '';
            if ($label !== '') {
                $labelsBySchedule[(int) $scheduleId][] = $label;
            }
        }
    }

    return $labelsBySchedule;
}

function oflc_chapel_schedule_db_replace_custom_small_catechism_labels(int $chapelScheduleId, array $labels): void
{
    if ($chapelScheduleId <= 0) {
        return;
    }

    $labels = oflc_chapel_schedule_db_clean_label_list($labels);
    $usageData = oflc_chapel_schedule_db_read_custom_small_catechism_usage_data();
    if ($labels === []) {
        unset($usageData['usage'][(string) $chapelScheduleId]);
        oflc_chapel_schedule_db_write_custom_small_catechism_usage_data($usageData);
        return;
    }

    $optionsData = oflc_chapel_schedule_db_read_custom_small_catechism_options_data();
    $optionIds = [];
    foreach ($labels as $label) {
        $optionId = oflc_chapel_schedule_db_find_or_create_custom_small_catechism_option_id($label, $optionsData);
        if ($optionId > 0) {
            $optionIds[] = $optionId;
        }
    }

    $usageData['usage'][(string) $chapelScheduleId] = $optionIds;
    oflc_chapel_schedule_db_write_custom_small_catechism_options_data($optionsData);
    oflc_chapel_schedule_db_write_custom_small_catechism_usage_data($usageData);
}

function oflc_chapel_schedule_db_delete_custom_small_catechism_labels(int $chapelScheduleId): void
{
    if ($chapelScheduleId <= 0) {
        return;
    }

    $usageData = oflc_chapel_schedule_db_read_custom_small_catechism_usage_data();
    unset($usageData['usage'][(string) $chapelScheduleId]);
    oflc_chapel_schedule_db_write_custom_small_catechism_usage_data($usageData);
}

function oflc_chapel_schedule_db_fetch_rows(PDO $pdo, string $schoolYear = '', string $dateSort = 'asc'): array
{
    oflc_chapel_schedule_db_ensure_tables($pdo);
    $dateSortDirection = strtolower($dateSort) === 'desc' ? 'DESC' : 'ASC';
    $secondarySortDirection = $dateSortDirection === 'DESC' ? 'DESC' : 'ASC';

    $sql = 'SELECT id, week_number, `date`, psalm, `text`, observance_name, school_year, is_active
            FROM chapel_schedule_db
            WHERE is_active = 1';
    $params = [];
    if ($schoolYear !== '') {
        $sql .= ' AND school_year = ?';
        $params[] = $schoolYear;
    }
    $sql .= ' ORDER BY (`date` IS NULL) ASC, `date` ' . $dateSortDirection . ', week_number ' . $secondarySortDirection . ', id ' . $secondarySortDirection;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $chapelScheduleIds = array_values(array_filter(array_map(static function (array $row): int {
        return (int) ($row['id'] ?? 0);
    }, $rows), static function (int $id): bool {
        return $id > 0;
    }));

    $hymnsBySchedule = oflc_chapel_schedule_db_fetch_hymn_labels_by_schedule($pdo, $chapelScheduleIds);
    $smallCatechismBySchedule = oflc_chapel_schedule_db_fetch_small_catechism_labels_by_schedule($pdo, $chapelScheduleIds);

    foreach ($rows as &$row) {
        $id = (int) ($row['id'] ?? 0);
        $row['hymn_labels'] = $hymnsBySchedule[$id] ?? [];
        $row['small_catechism_labels'] = $smallCatechismBySchedule[$id] ?? [];
    }
    unset($row);

    return $rows;
}

function oflc_chapel_schedule_db_fetch_hymn_labels_by_schedule(PDO $pdo, array $chapelScheduleIds): array
{
    $chapelScheduleIds = array_values(array_filter(array_map('intval', $chapelScheduleIds), static function (int $id): bool {
        return $id > 0;
    }));
    if ($chapelScheduleIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($chapelScheduleIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT chu.chapel_schedule_id,
                hd.hymnal,
                hd.hymn_number,
                hd.hymn_title,
                hd.hymn_tune
         FROM chapel_hymn_usage_db chu
         INNER JOIN hymn_db hd ON hd.id = chu.hymn_id
         WHERE chu.is_active = 1
           AND chu.chapel_schedule_id IN (' . $placeholders . ')
         ORDER BY chu.chapel_schedule_id ASC, chu.sort_order ASC, chu.id ASC'
    );
    $stmt->execute($chapelScheduleIds);

    $labelsBySchedule = [];
    foreach ($stmt->fetchAll() as $row) {
        $scheduleId = (int) ($row['chapel_schedule_id'] ?? 0);
        if ($scheduleId <= 0) {
            continue;
        }

        $label = oflc_service_db_format_hymn_label($row);
        if ($label !== '') {
            $labelsBySchedule[$scheduleId][] = $label;
        }
    }

    return $labelsBySchedule;
}

function oflc_chapel_schedule_db_fetch_small_catechism_labels_by_schedule(PDO $pdo, array $chapelScheduleIds): array
{
    $chapelScheduleIds = array_values(array_filter(array_map('intval', $chapelScheduleIds), static function (int $id): bool {
        return $id > 0;
    }));
    if ($chapelScheduleIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($chapelScheduleIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT csc.chapel_schedule_id,
                csc.small_catechism_id,
                sm.chief_part,
                sm.question,
                sm.abbreviation
         FROM chapel_small_catechism_usage_db csc
         LEFT JOIN small_catechism_mysql sm ON sm.id = csc.small_catechism_id
         WHERE csc.is_active = 1
           AND csc.chapel_schedule_id IN (' . $placeholders . ')
         ORDER BY csc.chapel_schedule_id ASC, csc.sort_order ASC, csc.id ASC'
    );
    $stmt->execute($chapelScheduleIds);

    $labelsBySchedule = [];
    $customLabelsBySchedule = oflc_chapel_schedule_db_fetch_custom_small_catechism_labels_by_schedule($chapelScheduleIds);
    $customLabelIndexBySchedule = [];
    foreach ($stmt->fetchAll() as $row) {
        $scheduleId = (int) ($row['chapel_schedule_id'] ?? 0);
        if ($scheduleId <= 0) {
            continue;
        }

        if ((int) ($row['small_catechism_id'] ?? 0) === 999) {
            $customIndex = $customLabelIndexBySchedule[$scheduleId] ?? 0;
            $customLabel = trim((string) ($customLabelsBySchedule[$scheduleId][$customIndex] ?? ''));
            $customLabelIndexBySchedule[$scheduleId] = $customIndex + 1;
            if ($customLabel !== '') {
                $labelsBySchedule[$scheduleId][] = $customLabel;
            }
            continue;
        }

        $abbreviation = trim((string) ($row['abbreviation'] ?? ''));
        if ($abbreviation !== '') {
            $labelsBySchedule[$scheduleId][] = $abbreviation;
        }
    }

    return $labelsBySchedule;
}

function oflc_chapel_schedule_db_save_row(PDO $pdo, array $row): int
{
    oflc_chapel_schedule_db_ensure_tables($pdo);

    $id = (int) ($row['id'] ?? 0);
    $date = trim((string) ($row['date'] ?? ''));
    $schoolYear = oflc_chapel_schedule_db_format_school_year($date);

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE chapel_schedule_db
             SET week_number = :week_number,
                 `date` = :date,
                 psalm = :psalm,
                 `text` = :text,
                 observance_name = :observance_name,
                 school_year = :school_year
             WHERE id = :id
               AND is_active = 1'
        );
        $stmt->execute([
            ':id' => $id,
            ':week_number' => max(1, (int) ($row['week_number'] ?? 1)),
            ':date' => $date !== '' ? $date : null,
            ':psalm' => trim((string) ($row['psalm'] ?? '')),
            ':text' => trim((string) ($row['text'] ?? '')),
            ':observance_name' => trim((string) ($row['observance_name'] ?? '')),
            ':school_year' => $schoolYear,
        ]);

        return $id;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO chapel_schedule_db (
            week_number,
            `date`,
            psalm,
            `text`,
            observance_name,
            school_year,
            is_active
         ) VALUES (
            :week_number,
            :date,
            :psalm,
            :text,
            :observance_name,
            :school_year,
            1
         )'
    );
    $stmt->execute([
        ':week_number' => max(1, (int) ($row['week_number'] ?? 1)),
        ':date' => $date !== '' ? $date : null,
        ':psalm' => trim((string) ($row['psalm'] ?? '')),
        ':text' => trim((string) ($row['text'] ?? '')),
        ':observance_name' => trim((string) ($row['observance_name'] ?? '')),
        ':school_year' => $schoolYear,
    ]);

    return (int) $pdo->lastInsertId();
}

function oflc_chapel_schedule_db_replace_hymn_links(PDO $pdo, int $chapelScheduleId, array $hymnIds, string $today): void
{
    $deactivateStmt = $pdo->prepare(
        'UPDATE chapel_hymn_usage_db
         SET is_active = 0,
             last_updated = :today
         WHERE chapel_schedule_id = :chapel_schedule_id
           AND is_active = 1'
    );
    $deactivateStmt->execute([
        ':today' => $today,
        ':chapel_schedule_id' => $chapelScheduleId,
    ]);

    if ($chapelScheduleId <= 0 || $hymnIds === []) {
        return;
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO chapel_hymn_usage_db (
            chapel_schedule_id,
            hymn_id,
            sort_order,
            created_at,
            last_updated,
            is_active
         ) VALUES (
            :chapel_schedule_id,
            :hymn_id,
            :sort_order,
            :created_at,
            :last_updated,
            1
         )'
    );

    foreach (array_values($hymnIds) as $index => $hymnId) {
        $insertStmt->execute([
            ':chapel_schedule_id' => $chapelScheduleId,
            ':hymn_id' => (int) $hymnId,
            ':sort_order' => $index + 1,
            ':created_at' => $today,
            ':last_updated' => $today,
        ]);
    }
}

function oflc_chapel_schedule_db_replace_small_catechism_links(PDO $pdo, int $chapelScheduleId, array $smallCatechismIds, string $today): void
{
    $deactivateStmt = $pdo->prepare(
        'UPDATE chapel_small_catechism_usage_db
         SET is_active = 0,
             last_updated = :today
         WHERE chapel_schedule_id = :chapel_schedule_id
           AND is_active = 1'
    );
    $deactivateStmt->execute([
        ':today' => $today,
        ':chapel_schedule_id' => $chapelScheduleId,
    ]);

    if ($chapelScheduleId <= 0 || $smallCatechismIds === []) {
        return;
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO chapel_small_catechism_usage_db (
            chapel_schedule_id,
            small_catechism_id,
            sort_order,
            created_at,
            last_updated,
            is_active
         ) VALUES (
            :chapel_schedule_id,
            :small_catechism_id,
            :sort_order,
            :created_at,
            :last_updated,
            1
         )'
    );

    foreach (array_values($smallCatechismIds) as $index => $smallCatechismId) {
        $insertStmt->execute([
            ':chapel_schedule_id' => $chapelScheduleId,
            ':small_catechism_id' => (int) $smallCatechismId,
            ':sort_order' => $index + 1,
            ':created_at' => $today,
            ':last_updated' => $today,
        ]);
    }
}

function oflc_chapel_schedule_db_delete_row(PDO $pdo, int $chapelScheduleId): void
{
    if ($chapelScheduleId <= 0) {
        return;
    }

    $pdo->prepare('DELETE FROM chapel_hymn_usage_db WHERE chapel_schedule_id = ?')->execute([$chapelScheduleId]);
    $pdo->prepare('DELETE FROM chapel_small_catechism_usage_db WHERE chapel_schedule_id = ?')->execute([$chapelScheduleId]);
    $pdo->prepare('DELETE FROM chapel_schedule_db WHERE id = ?')->execute([$chapelScheduleId]);
    oflc_chapel_schedule_db_delete_custom_small_catechism_labels($chapelScheduleId);
}
