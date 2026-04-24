<?php
declare(strict_types=1);

function oflc_hymn_layout_get_mode(array $definitions): string
{
    $slotNames = array_values(array_filter(array_map(static function (array $definition): string {
        return trim((string) ($definition['slot_name'] ?? ''));
    }, $definitions), static function (string $slotName): bool {
        return $slotName !== '';
    }));

    if (in_array('Chief Hymn', $slotNames, true) && in_array('Closing Hymn', $slotNames, true) && in_array('Distribution Hymn', $slotNames, true)) {
        return 'divine_service';
    }

    if (in_array('Office Hymn', $slotNames, true) && in_array('Closing Hymn', $slotNames, true)) {
        return 'office';
    }

    return 'generic';
}

function oflc_hymn_layout_normalize_extra_slot_name(string $slotName, string $layoutMode): string
{
    $slotName = trim($slotName);

    if ($layoutMode === 'divine_service') {
        return $slotName === 'Distribution Hymn' ? 'Distribution Hymn' : 'Other Hymn';
    }

    return 'Other Hymn';
}

function oflc_hymn_layout_build_canonical_rows(
    array $definitions,
    array $baseHymnRows,
    array $extraHymnRowsByKey,
    array $orderedHymnRowKeys
): array {
    $layoutMode = oflc_hymn_layout_get_mode($definitions);
    $rowsByKey = $baseHymnRows;
    $normalizedExtraRowsByKey = [];

    foreach ($extraHymnRowsByKey as $rowKey => $row) {
        $row['slot_name'] = oflc_hymn_layout_normalize_extra_slot_name((string) ($row['slot_name'] ?? ''), $layoutMode);
        $normalizedExtraRowsByKey[$rowKey] = $row;
        $rowsByKey[$rowKey] = $row;
    }

    $orderedKeys = [];
    foreach ($orderedHymnRowKeys as $rowKey) {
        $rowKey = trim((string) $rowKey);
        if ($rowKey !== '' && isset($rowsByKey[$rowKey]) && !in_array($rowKey, $orderedKeys, true)) {
            $orderedKeys[] = $rowKey;
        }
    }
    foreach (array_keys($rowsByKey) as $rowKey) {
        if (!in_array($rowKey, $orderedKeys, true)) {
            $orderedKeys[] = $rowKey;
        }
    }

    if ($layoutMode === 'generic') {
        return array_values(array_filter(array_map(static function (string $rowKey) use ($rowsByKey): ?array {
            if (!isset($rowsByKey[$rowKey])) {
                return null;
            }

            return ['key' => $rowKey] + $rowsByKey[$rowKey];
        }, $orderedKeys)));
    }

    $openingOtherRows = [];
    $middleOtherRows = [];
    $postDistributionOtherRows = [];
    $extraDistributionRows = [];
    $lastBaseIndex = 0;
    $canonicalRows = [];
    $appendBaseRow = static function (int $definitionIndex) use (&$canonicalRows, $baseHymnRows): void {
        $rowKey = 'base:' . $definitionIndex;
        if (isset($baseHymnRows[$rowKey])) {
            $canonicalRows[] = ['key' => $rowKey] + $baseHymnRows[$rowKey];
        }
    };

    foreach ($orderedKeys as $rowKey) {
        if (strpos($rowKey, 'base:') === 0) {
            $lastBaseIndex = (int) substr($rowKey, 5);
            continue;
        }

        if (!isset($normalizedExtraRowsByKey[$rowKey])) {
            continue;
        }

        $row = ['key' => $rowKey] + $normalizedExtraRowsByKey[$rowKey];
        if ($layoutMode === 'divine_service' && ($row['slot_name'] ?? '') === 'Distribution Hymn') {
            $extraDistributionRows[] = $row;
            continue;
        }

        if ($layoutMode === 'divine_service') {
            if ($lastBaseIndex <= 1) {
                $openingOtherRows[] = $row;
            } elseif ($lastBaseIndex <= 2) {
                $middleOtherRows[] = $row;
            } else {
                $postDistributionOtherRows[] = $row;
            }
            continue;
        }

        if ($lastBaseIndex <= 1) {
            $openingOtherRows[] = $row;
        } else {
            $middleOtherRows[] = $row;
        }
    }

    if ($layoutMode === 'divine_service') {
        $appendBaseRow(1);
        foreach ($openingOtherRows as $row) {
            $canonicalRows[] = $row;
        }
        $appendBaseRow(2);
        foreach ($middleOtherRows as $row) {
            $canonicalRows[] = $row;
        }
        foreach ([3, 4, 5, 6, 7] as $definitionIndex) {
            $appendBaseRow($definitionIndex);
        }
        foreach ($extraDistributionRows as $row) {
            $canonicalRows[] = $row;
        }
        foreach ($postDistributionOtherRows as $row) {
            $canonicalRows[] = $row;
        }
        $appendBaseRow(8);

        return $canonicalRows;
    }

    $appendBaseRow(1);
    foreach ($openingOtherRows as $row) {
        $canonicalRows[] = $row;
    }
    $appendBaseRow(2);
    foreach ($middleOtherRows as $row) {
        $canonicalRows[] = $row;
    }
    $appendBaseRow(3);

    return $canonicalRows;
}
