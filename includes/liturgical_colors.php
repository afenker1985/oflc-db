<?php
declare(strict_types=1);

function oflc_get_liturgical_color_options(): array
{
    return [
        'Black',
        'Blue',
        'Gold',
        'Green',
        'Red',
        'Rose',
        'Scarlet',
        'Violet',
        'White',
    ];
}

function oflc_is_valid_liturgical_color(string $color): bool
{
    return in_array(trim($color), oflc_get_liturgical_color_options(), true);
}
