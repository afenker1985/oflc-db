<?php
declare(strict_types=1);

function oflc_service_planner_escape_attr($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function oflc_service_planner_render_attrs(array $attrs): string
{
    $parts = [];

    foreach ($attrs as $name => $value) {
        if ($value === null) {
            continue;
        }

        if (is_bool($value)) {
            if ($value) {
                $parts[] = $name;
            }
            continue;
        }

        $parts[] = $name . '="' . oflc_service_planner_escape_attr($value) . '"';
    }

    return $parts === [] ? '' : ' ' . implode(' ', $parts);
}

function oflc_service_planner_render_hidden_input(array $attrs): string
{
    return '<input type="hidden"' . oflc_service_planner_render_attrs($attrs) . '>';
}

function oflc_service_planner_render_text_input(array $attrs): string
{
    return '<input type="text"' . oflc_service_planner_render_attrs($attrs) . '>';
}

function oflc_service_planner_render_date_input(array $attrs): string
{
    return '<input type="date"' . oflc_service_planner_render_attrs($attrs) . '>';
}

function oflc_service_planner_render_select(array $attrs, array $options, string $selectedValue = '', ?string $placeholder = null): string
{
    $html = '<select' . oflc_service_planner_render_attrs($attrs) . '>';

    if ($placeholder !== null) {
        $html .= '<option value="">' . oflc_service_planner_escape_attr($placeholder) . '</option>';
    }

    foreach ($options as $option) {
        $value = trim((string) $option);
        if ($value === '') {
            continue;
        }

        $html .= '<option value="' . oflc_service_planner_escape_attr($value) . '"' . ($selectedValue === $value ? ' selected' : '') . '>';
        $html .= oflc_service_planner_escape_attr($value);
        $html .= '</option>';
    }

    $html .= '</select>';

    return $html;
}

function oflc_service_planner_render_grid(array $config): string
{
    $left = $config['left_panel'] ?? [];
    $readings = $config['readings_panel'] ?? [];
    $hymns = $config['hymns_panel'] ?? [];
    $leadersHtml = (string) ($config['leaders_panel_html'] ?? '');

    $observanceSuggestionClass = trim((string) ($left['observance_suggestion_class'] ?? 'service-card-suggestion-list js-observance-suggestion-list'));
    $serviceSettingSuggestionClass = trim((string) ($left['service_setting_suggestion_class'] ?? 'service-card-suggestion-list service-card-suggestion-list-fixed js-service-setting-suggestion-list'));
    $colorLineClass = trim((string) ($left['color_line_class'] ?? 'service-card-color-line js-observance-color-line'));
    $newColorWrapClass = trim((string) ($left['new_color_wrap_class'] ?? 'update-service-new-observance-color js-new-observance-color-wrap'));
    $readingsClass = trim((string) ($readings['wrapper_class'] ?? 'service-card-readings js-observance-readings'));
    $hymnsClass = trim((string) ($hymns['wrapper_class'] ?? 'service-card-hymns js-update-service-hymns'));
    $selectedServiceId = (string) ($hymns['selected_service_id'] ?? '');

    $html = '<div class="service-card-grid">';

    $html .= '<section class="service-card-panel">';
    $html .= '<div class="service-card-date-row">';
    $html .= oflc_service_planner_render_date_input($left['date_input_attrs'] ?? []);
    $html .= '</div>';
    $html .= '<div class="service-card-display-date">' . (string) ($left['display_date_html'] ?? '&nbsp;') . '</div>';
    $html .= oflc_service_planner_render_hidden_input($left['observance_hidden_attrs'] ?? []);
    $html .= '<div class="service-card-suggestion-anchor">';
    $html .= oflc_service_planner_render_text_input($left['observance_input_attrs'] ?? []);
    $html .= '<div class="' . oflc_service_planner_escape_attr($observanceSuggestionClass) . '" hidden></div>';
    $html .= '</div>';
    $html .= '<div class="service-card-latin-name js-observance-latin-name"' . oflc_service_planner_render_attrs(array_filter([
        'id' => $left['latin_name_id'] ?? null,
    ], static function ($value) {
        return $value !== null && $value !== '';
    })) . '>';
    $html .= (string) ($left['latin_name_html'] ?? '&nbsp;');
    $html .= '</div>';
    $html .= '<div class="service-card-meta">';
    $html .= oflc_service_planner_render_hidden_input($left['service_setting_hidden_attrs'] ?? []);
    $html .= '<div class="service-card-suggestion-anchor">';
    $html .= oflc_service_planner_render_text_input($left['service_setting_input_attrs'] ?? []);
    $html .= '<div class="' . oflc_service_planner_escape_attr($serviceSettingSuggestionClass) . '" hidden></div>';
    $html .= '</div>';
    $html .= '<div class="service-card-service-summary js-service-setting-summary"' . oflc_service_planner_render_attrs(array_filter([
        'id' => $left['service_setting_summary_id'] ?? null,
    ], static function ($value) {
        return $value !== null && $value !== '';
    })) . '>';
    $html .= (string) ($left['service_setting_summary_html'] ?? '&nbsp;');
    $html .= '</div>';
    $html .= '<div class="service-card-color-slot">';
    $html .= '<div class="' . oflc_service_planner_escape_attr($colorLineClass) . '">' . (string) ($left['color_line_html'] ?? '&nbsp;') . '</div>';
    $html .= '<div class="' . oflc_service_planner_escape_attr($newColorWrapClass) . '">';
    $html .= oflc_service_planner_render_select(
        $left['new_color_select_attrs'] ?? [],
        $left['new_color_options'] ?? [],
        (string) ($left['selected_color'] ?? ''),
        (string) ($left['new_color_placeholder'] ?? 'Choose color')
    );
    $html .= '</div>';
    $html .= '</div>';
    $html .= (string) ($left['meta_append_html'] ?? '');
    $html .= '</div>';
    $html .= '</section>';

    $html .= '<section class="service-card-panel">';
    $html .= '<div class="' . oflc_service_planner_escape_attr($readingsClass) . '"' . oflc_service_planner_render_attrs(array_filter([
        'id' => $readings['wrapper_id'] ?? null,
    ], static function ($value) {
        return $value !== null && $value !== '';
    })) . '>';
    $html .= (string) ($readings['html'] ?? '&nbsp;');
    $html .= '</div>';
    $html .= '</section>';

    $html .= '<section class="service-card-panel">';
    $html .= '<div class="' . oflc_service_planner_escape_attr($hymnsClass) . '"' . oflc_service_planner_render_attrs(array_filter([
        'id' => $hymns['wrapper_id'] ?? null,
        'data-selected-service-id' => $selectedServiceId !== '' ? $selectedServiceId : null,
    ], static function ($value) {
        return $value !== null && $value !== '';
    })) . '>';
    $html .= (string) ($hymns['html'] ?? '');
    $html .= '</div>';
    $html .= '</section>';

    $html .= '<section class="service-card-panel">';
    $html .= $leadersHtml;
    $html .= '</section>';

    $html .= '</div>';

    return $html;
}
