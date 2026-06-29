<?php
/**
 * Export Assessment Reports scoring mappings to a human-readable CSV.
 *
 * Usage from the Bedrock root:
 * wp eval-file web/app/plugins/assessment-reports-dev/scripts/export-report-scoring-csv.php 15 report-15-question-response-scores.csv
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "This script must be run through WP-CLI so WordPress is loaded.\n");
    exit(1);
}

$script_args = isset($args) && is_array($args) ? $args : array_slice($GLOBALS['argv'] ?? [], 1);

$report_id = 15;
$output_path = '';

foreach ($script_args as $arg) {
    if (strpos($arg, '--report-id=') === 0) {
        $report_id = absint(substr($arg, strlen('--report-id=')));
        continue;
    }

    if (strpos($arg, '--output=') === 0) {
        $output_path = trim(substr($arg, strlen('--output=')));
    }
}

if (! empty($script_args[0]) && strpos((string) $script_args[0], '--') !== 0) {
    $report_id = absint($script_args[0]);
}

if (! empty($script_args[1]) && strpos((string) $script_args[1], '--') !== 0) {
    $output_path = trim((string) $script_args[1]);
}

if (! $report_id) {
    WP_CLI::error('A valid report ID is required.');
}

if ($output_path === '') {
    $output_path = sprintf('report-%d-question-response-scores.csv', $report_id);
}

if (! ar_export_scoring_is_absolute_path($output_path)) {
    $output_path = getcwd() . '/' . $output_path;
}

$report = get_post($report_id);
if (! $report) {
    WP_CLI::error(sprintf('Report %d was not found.', $report_id));
}

$form_id = absint(get_post_meta($report_id, '_report_form_id', true));
if (! $form_id) {
    WP_CLI::error(sprintf('Report %d does not have a _report_form_id value.', $report_id));
}

$form = fluentFormApi('forms')->find($form_id);
if (! $form || empty($form->form_fields)) {
    WP_CLI::error(sprintf('Fluent Form %d was not found or has no fields.', $form_id));
}

$form_fields = json_decode($form->form_fields, true);
if (! is_array($form_fields)) {
    WP_CLI::error(sprintf('Fluent Form %d fields are not valid JSON.', $form_id));
}

$fields_by_name = [];
ar_export_scoring_collect_fields($form_fields['fields'] ?? [], $fields_by_name);

$sections = get_posts([
    'post_type' => $report->post_type,
    'post_parent' => $report_id,
    'post_status' => 'publish',
    'numberposts' => -1,
    'orderby' => [
        'menu_order' => 'ASC',
        'ID' => 'ASC',
    ],
    'order' => 'ASC',
]);

$rows = [[
    'Parent Report ID',
    'Parent Report Title',
    'Fluent Form ID',
    'Fluent Form Title',
    'Child Report ID',
    'Child Report Title',
    'Graph Key',
    'Section Max Score',
    'Question Field Name',
    'Question Label',
    'Question Admin Label',
    'Response Stored Value',
    'Response Label',
    'Enabled',
    'Points',
    'Multiplier',
    'Score',
]];

foreach ($sections as $section) {
    $mappings = get_post_meta($section->ID, '_field_mappings', true);
    if (! is_array($mappings)) {
        continue;
    }

    $graph_key = (string) get_post_meta($section->ID, '_graph_key', true);
    $max_score = get_post_meta($section->ID, '_section_max_score', true);

    foreach ($mappings as $field_name => $choices) {
        if (! is_array($choices)) {
            continue;
        }

        $field_name = (string) $field_name;
        $field = $fields_by_name[$field_name] ?? [];
        $question_label = $field['settings']['label'] ?? $field['attributes']['label'] ?? $field_name;
        $admin_label = $field['settings']['admin_field_label'] ?? '';
        $choice_labels = $field ? ar_export_scoring_field_choices($field) : [];

        foreach ($choices as $choice_value => $mapping) {
            $choice_value = (string) $choice_value;
            $normalized = ar_export_scoring_normalize_mapping($mapping);
            $points = (float) $normalized['points'];
            $multiplier = (float) $normalized['multiplier'];

            $rows[] = [
                $report_id,
                $report->post_title,
                $form_id,
                $form->title ?? '',
                $section->ID,
                $section->post_title,
                $graph_key,
                $max_score,
                $field_name,
                wp_strip_all_tags((string) $question_label),
                wp_strip_all_tags((string) $admin_label),
                $choice_value,
                $choice_labels[$choice_value] ?? $choice_value,
                $normalized['enabled'] ? 'yes' : 'no',
                $points,
                $multiplier,
                $points * $multiplier,
            ];
        }
    }
}

$output_directory = dirname($output_path);
if (! is_dir($output_directory)) {
    WP_CLI::error(sprintf('Output directory does not exist: %s', $output_directory));
}

$handle = fopen($output_path, 'w');
if (! $handle) {
    WP_CLI::error(sprintf('Unable to write CSV: %s', $output_path));
}

foreach ($rows as $row) {
    fputcsv($handle, $row, ',', '"', '\\');
}

fclose($handle);

WP_CLI::success(sprintf('Wrote %d scoring rows to %s', count($rows) - 1, $output_path));

function ar_export_scoring_collect_fields(array $fields, array &$flat_fields): void
{
    foreach ($fields as $field) {
        if (! is_array($field)) {
            continue;
        }

        if (isset($field['columns']) && is_array($field['columns'])) {
            foreach ($field['columns'] as $column) {
                if (is_array($column)) {
                    ar_export_scoring_collect_fields($column['fields'] ?? [], $flat_fields);
                }
            }
        }

        if (isset($field['fields']) && is_array($field['fields'])) {
            ar_export_scoring_collect_fields($field['fields'], $flat_fields);
        }

        $name = $field['attributes']['name'] ?? '';
        if ($name !== '') {
            $flat_fields[$name] = $field;
        }
    }
}

function ar_export_scoring_field_choices(array $field): array
{
    $choices = [];

    foreach (($field['settings']['advanced_options'] ?? []) as $choice) {
        if (! is_array($choice)) {
            continue;
        }

        $value = isset($choice['value']) ? (string) $choice['value'] : '';
        $label = isset($choice['label']) ? (string) $choice['label'] : $value;
        if ($value !== '') {
            $choices[$value] = $label;
        }
    }

    foreach (($field['settings']['options'] ?? []) as $choice) {
        if (! is_array($choice)) {
            continue;
        }

        $value = isset($choice['value']) ? (string) $choice['value'] : '';
        $label = isset($choice['label']) ? (string) $choice['label'] : $value;
        if ($value !== '') {
            $choices[$value] = $label;
        }
    }

    if (! empty($field['options']) && is_array($field['options'])) {
        foreach ($field['options'] as $value => $label) {
            $value = (string) $value;
            if ($value !== '') {
                $choices[$value] = is_scalar($label) ? (string) $label : $value;
            }
        }
    }

    return $choices;
}

function ar_export_scoring_normalize_mapping($mapping): array
{
    if (function_exists('AssessmentReports\\ar_normalize_choice_mapping')) {
        return AssessmentReports\ar_normalize_choice_mapping($mapping);
    }

    if (is_array($mapping)) {
        return [
            'enabled' => ! empty($mapping['enabled']),
            'points' => isset($mapping['points']) ? (float) $mapping['points'] : 1.0,
            'multiplier' => isset($mapping['multiplier']) ? (float) $mapping['multiplier'] : 1.0,
        ];
    }

    return [
        'enabled' => ! empty($mapping),
        'points' => 1.0,
        'multiplier' => 1.0,
    ];
}

function ar_export_scoring_is_absolute_path(string $path): bool
{
    return $path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1);
}
