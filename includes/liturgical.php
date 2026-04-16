<?php

declare(strict_types=1);

require_once __DIR__ . '/liturgical_keys.php';

function oflc_get_liturgical_day(string $date_string)
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $date_string);
    if (!$date || $date->format('Y-m-d') !== $date_string) {
        return null;
    }

    $sunday = oflc_get_sunday($date);
    $week = oflc_get_one_year_week($date);

    return [
        'date' => $date->format('Y-m-d'),
        'weekday' => (int) $date->format('w'),
        'weekday_monday_first' => (int) $date->format('N'),
        'weekday_name' => $date->format('l'),
        'month' => (int) $date->format('n'),
        'day' => (int) $date->format('j'),
        'year' => (int) $date->format('Y'),
        'week' => $week,
        'logic_keys' => oflc_resolve_logic_keys($week, (int) $date->format('w'), (int) $date->format('n'), (int) $date->format('j')),
        'logic_key' => oflc_resolve_logic_key($week, (int) $date->format('w'), (int) $date->format('n'), (int) $date->format('j')),
        'fixed_logic_keys' => oflc_resolve_fixed_logic_keys((int) $date->format('n'), (int) $date->format('j')),
        'fixed_logic_key' => oflc_resolve_fixed_logic_key((int) $date->format('n'), (int) $date->format('j')),
        'sunday_date' => $sunday->format('Y-m-d'),
        'is_sunday' => (int) $date->format('w') === 0,
    ];
}

function oflc_get_liturgical_window(string $date_string, int $days_before = 7, int $days_after = 7)
{
    $selected = oflc_get_liturgical_day($date_string);
    if ($selected === null) {
        return null;
    }

    $selected_date = new DateTimeImmutable($selected['date']);
    $entries = [];
    $sunday_options = [];

    for ($offset = -$days_before; $offset <= $days_after; $offset++) {
        $window_date = $selected_date->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
        $entry = oflc_get_liturgical_day($window_date->format('Y-m-d'));

        if ($entry === null) {
            continue;
        }

        $entry['relative_days'] = $offset;
        $entry['is_selected_date'] = $offset === 0;
        $entry['month_day_key'] = $entry['month'] . '-' . $entry['day'];
        $entries[] = $entry;

        if ($entry['is_sunday']) {
            $sunday_options[$entry['date']] = [
                'date' => $entry['date'],
                'week' => $entry['week'],
                'label' => $entry['date'] . ' (week ' . ($entry['week'] === null ? 'none' : $entry['week']) . ')',
            ];
        }
    }

    return [
        'selected' => $selected,
        'window_start' => $entries[0]['date'] ?? $selected['date'],
        'window_end' => $entries[count($entries) - 1]['date'] ?? $selected['date'],
        'entries' => $entries,
        'sunday_options' => array_values($sunday_options),
    ];
}

function oflc_normalize_logic_keys($value): array
{
    if ($value === null) {
        return [];
    }

    if (is_array($value)) {
        return array_values(array_filter($value, static function ($item): bool {
            return is_string($item) && $item !== '';
        }));
    }

    return is_string($value) && $value !== '' ? [$value] : [];
}

function oflc_resolve_logic_keys($week, int $weekday, int $month, int $day): array
{
    $movable_keys = oflc_resolve_movable_logic_keys($week, $weekday);
    if ($movable_keys !== []) {
        return $movable_keys;
    }

    return oflc_resolve_fixed_logic_keys($month, $day);
}

function oflc_resolve_logic_key($week, int $weekday, int $month, int $day)
{
    $keys = oflc_resolve_logic_keys($week, $weekday, $month, $day);
    return $keys[0] ?? null;
}

function oflc_resolve_movable_logic_keys($week, int $weekday): array
{
    if ($week === null) {
        return [];
    }

    $keys = oflc_get_one_year_logic_keys();
    return oflc_normalize_logic_keys($keys[$week][$weekday] ?? null);
}

function oflc_resolve_movable_logic_key($week, int $weekday)
{
    $keys = oflc_resolve_movable_logic_keys($week, $weekday);
    return $keys[0] ?? null;
}

function oflc_resolve_fixed_logic_keys(int $month, int $day): array
{
    $keys = oflc_get_fixed_logic_keys();
    return oflc_normalize_logic_keys($keys[$month . '-' . $day] ?? null);
}

function oflc_resolve_fixed_logic_key(int $month, int $day)
{
    $keys = oflc_resolve_fixed_logic_keys($month, $day);
    return $keys[0] ?? null;
}

function oflc_get_sunday(DateTimeImmutable $date): DateTimeImmutable
{
    $weekday = (int) $date->format('w');
    return $weekday === 0 ? $date : $date->modify('-' . $weekday . ' days');
}

function oflc_get_one_year_week(DateTimeImmutable $date)
{
    $year = (int) $date->format('Y');
    $sunday = oflc_get_sunday($date);

    if ((int) $sunday->format('n') === 12 && (int) $sunday->format('j') === 25) {
        return null;
    }

    $advent = oflc_get_advent($year);
    $epiphany = new DateTimeImmutable($year . '-01-06');
    $epiphany_sunday = oflc_get_sunday($epiphany);
    $transfiguration = oflc_get_easter($year)->modify('-10 weeks');
    $last_sunday = $advent->modify('-1 week');
    $end_of_year = $advent->modify('-3 weeks');

    if ($sunday >= $advent) {
        return 1 + oflc_get_week_difference($advent, $sunday);
    }

    if ($sunday >= $epiphany && $sunday < $transfiguration) {
        return 6 + oflc_get_week_difference($epiphany_sunday, $sunday);
    }

    if ($sunday < $epiphany) {
        return 6 - oflc_get_week_difference($sunday, $epiphany_sunday);
    }

    if ($sunday >= $transfiguration && $sunday <= $end_of_year) {
        return 12 + oflc_get_week_difference($transfiguration, $sunday);
    }

    return 57 - oflc_get_week_difference($sunday, $last_sunday);
}

function oflc_get_week_difference(DateTimeImmutable $first, DateTimeImmutable $second): int
{
    $days = (int) $first->diff($second)->format('%r%a');
    return (int) floor($days / 7);
}

function oflc_get_advent(int $year): DateTimeImmutable
{
    $christmas = new DateTimeImmutable($year . '-12-25');
    $weekday = (int) $christmas->format('N');
    return $christmas->modify('-3 weeks')->modify('-' . $weekday . ' days');
}

function oflc_get_easter(int $year): DateTimeImmutable
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $n = $h + $l - 7 * $m + 114;

    $month = intdiv($n, 31);
    $day = ($n % 31) + 1;

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}
