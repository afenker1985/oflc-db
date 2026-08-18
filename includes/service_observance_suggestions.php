<?php
declare(strict_types=1);

function oflc_service_build_date_observance_suggestions(array $serviceOptionChoices): array
{
    $names = [];

    foreach ($serviceOptionChoices as $choice) {
        $name = trim((string) ($choice['suggestion_label'] ?? $choice['label'] ?? ''));
        if ($name === '') {
            continue;
        }

        $names[$name] = $name;
    }

    return array_values($names);
}

function oflc_service_index_observance_details_by_logic_key(array $details): array
{
    $indexed = [];

    foreach ($details as $detail) {
        if (!is_array($detail)) {
            continue;
        }

        $logicKey = trim((string) ($detail['observance']['logic_key'] ?? ''));
        if ($logicKey !== '' && function_exists('oflc_service_db_normalize_logic_key')) {
            $logicKey = oflc_service_db_normalize_logic_key($logicKey);
        }
        if ($logicKey === '' || isset($indexed[$logicKey])) {
            continue;
        }

        $indexed[$logicKey] = $detail;
    }

    return $indexed;
}

function oflc_service_build_observance_suggestion_lookup(array $serviceOptionChoices, array $detailsByLogicKey): array
{
    $lookup = [];

    foreach ($serviceOptionChoices as $choice) {
        $label = trim((string) ($choice['suggestion_label'] ?? $choice['label'] ?? ''));
        $logicKey = trim((string) ($choice['logic_key'] ?? ''));
        $detail = $logicKey !== '' && isset($detailsByLogicKey[$logicKey]) && is_array($detailsByLogicKey[$logicKey])
            ? $detailsByLogicKey[$logicKey]
            : null;
        $observanceId = (int) ($detail['observance']['id'] ?? 0);
        $canonicalName = trim((string) ($detail['observance']['name'] ?? ''));

        if ($label === '' || $observanceId <= 0 || isset($lookup[$label])) {
            continue;
        }

        $lookup[$label] = $observanceId;
        if ($canonicalName !== '' && !isset($lookup[$canonicalName])) {
            $lookup[$canonicalName] = $observanceId;
        }
    }

    return $lookup;
}
