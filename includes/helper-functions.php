<?php

namespace AssessmentReports;

use FluentForm\App\Helpers\Helper;

/**
 * Get submission ID from entry UID hash
 *
 * @param string $hash The _entry_uid_hash value
 * @return int|null The submission ID or null if not found
 */
function get_submission_id_by_hash($hash)
{
    if (! $hash) {
        return null;
    }

    // Try using FluentForm's model if available
    if (class_exists('\FluentForm\App\Models\SubmissionMeta')) {
        $meta = \FluentForm\App\Models\SubmissionMeta::where('meta_key', '_entry_uid_hash')
            ->where('value', $hash)
            ->first();

        if ($meta && isset($meta->response_id)) {
            return absint($meta->response_id);
        }
    } else {
        // Fallback to direct database query
        global $wpdb;
        $table_name = $wpdb->prefix . 'fluentform_submission_meta';
        
        $submission_id = $wpdb->get_var($wpdb->prepare(
            "SELECT response_id FROM {$table_name} WHERE meta_key = %s AND value = %s LIMIT 1",
            '_entry_uid_hash',
            $hash
        ));
        
        if ($submission_id) {
            return absint($submission_id);
        }
    }

    return null;
}

/**
 * Get top report sections by entry hash
 *
 * @param string $hash The _entry_uid_hash value
 * @return array|null The top report sections data or null if not found
 */
function get_top_sections_by_hash($hash)
{
    $entry_id = get_submission_id_by_hash($hash);
    
    if (! $entry_id) {
        return null;
    }

    return get_top_sections_by_entry_id($entry_id);
}

/**
 * Get top report sections by entry ID
 *
 * @param int $entry_id The FluentForm submission/entry ID
 * @return array|null The top report sections data or null if not found
 */
function get_top_sections_by_entry_id($entry_id)
{
    $entry_id = absint($entry_id);
    
    if (! $entry_id) {
        return null;
    }

    $raw = Helper::getSubmissionMeta($entry_id, 'top_report_sections');
    
    if (! $raw) {
        return null;
    }

    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return $decoded;
    }

    return is_array($raw) ? $raw : null;
}

/**
 * Get report sections with full post data by entry hash
 *
 * @param string $hash The _entry_uid_hash value
 * @return array|null Array of section posts with scores or null if not found
 */
function get_report_sections_by_hash($hash)
{
    $sections_data = get_top_sections_by_hash($hash);
    
    if (! $sections_data) {
        return null;
    }

    $sections = [];
    foreach ($sections_data as $section) {
        $section_post = get_post($section['section_id'] ?? 0);
        if ($section_post && $section_post->post_status === 'publish') {
            $sections[] = [
                'id'           => $section_post->ID,
                'title'        => $section_post->post_title,
                'content'      => apply_filters('the_content', $section_post->post_content),
                'score'        => $section['score'] ?? 0,
                'parent_id'    => $section['parent_id'] ?? 0,
                'post'         => $section_post,
            ];
        }
    }

    return $sections ?: null;
}

/**
 * Get the report parent post by entry hash
 *
 * @param string $hash The _entry_uid_hash value
 * @return \WP_Post|null The report parent post or null if not found
 */
function get_report_by_hash($hash)
{
    $sections_data = get_top_sections_by_hash($hash);
    
    if (! $sections_data || empty($sections_data[0]['parent_id'])) {
        return null;
    }

    $report = get_post($sections_data[0]['parent_id']);
    
    if ($report && $report->post_type === Post_Type::POST_TYPE && $report->post_status === 'publish') {
        return $report;
    }

    return null;
}

/**
 * Get report display link for an entry ID
 *
 * @param int $entry_id The FluentForm submission/entry ID
 * @param string $page_url Optional. The URL of the page with the [assessment_report] shortcode
 * @return string|null The report display URL or null if entry not found
 */
function get_report_link_by_entry_id($entry_id, $page_url = '')
{
    $entry_id = absint($entry_id);
    
    if (! $entry_id) {
        return null;
    }

    // Check if entry has report sections
    $sections = get_top_sections_by_entry_id($entry_id);
    if (! $sections) {
        return null;
    }

    // Get the Report_Display instance to generate the hash
    static $report_display = null;
    if ($report_display === null) {
        $report_display = new Report_Display();
    }

    $hash = $report_display->get_entry_hash($entry_id);
    
    if (! $hash) {
        return null;
    }

    // If no page URL provided, use current site URL
    if (! $page_url) {
        $page_url = home_url('/');
    }

    return add_query_arg('entry', $hash, $page_url);
}

/**
 * Get report display link by hash
 *
 * @param string $hash The _entry_uid_hash value
 * @param string $page_url Optional. The URL of the page with the [assessment_report] shortcode
 * @return string|null The report display URL or null if entry not found
 */
function get_report_link_by_hash($hash, $page_url = '')
{
    $entry_id = get_submission_id_by_hash($hash);
    
    if (! $entry_id) {
        return null;
    }

    return get_report_link_by_entry_id($entry_id, $page_url);
}

/**
 * Check if entry has report sections
 *
 * @param int $entry_id The FluentForm submission/entry ID
 * @return bool True if entry has report sections, false otherwise
 */
function entry_has_report($entry_id)
{
    $sections = get_top_sections_by_entry_id($entry_id);
    return ! empty($sections);
}

/**
 * Get report closing content by hash
 *
 * @param string $hash The _entry_uid_hash value
 * @return string The closing content HTML or empty string
 */
function get_report_closing_content_by_hash($hash)
{
    $report = get_report_by_hash($hash);
    
    if (! $report) {
        return '';
    }

    $closing_content = get_post_meta($report->ID, '_report_closing_content', true);
    
    return $closing_content ? apply_filters('the_content', $closing_content) : '';
}

/**
 * Get the full submission/entry object by hash
 *
 * @param string|null $hash Optional. The _entry_uid_hash value. Uses $_GET['entry_hash'] if not provided
 * @return object|null The FluentForm submission object or null if not found
 */
function get_entry_by_hash($hash = null)
{
    if ($hash === null && isset($_GET['entry_hash'])) {
        $hash = sanitize_text_field(wp_unslash($_GET['entry_hash']));
    }

    $entry_id = get_submission_id_by_hash($hash);
    
    if (! $entry_id) {
        return null;
    }

    // Get the submission using FluentForm's API
    if (function_exists('fluentFormApi')) {
        try {
            $submission = fluentFormApi('submissions')->find($entry_id);
            return $submission;
        } catch (\Exception $e) {
            return null;
        }
    }

    // Fallback to model query
    if (class_exists('\FluentForm\App\Models\Submission')) {
        return \FluentForm\App\Models\Submission::find($entry_id);
    }

    return null;
}

/**
 * Get a specific field value from an entry by hash
 *
 * @param string $field_name The form field name to retrieve
 * @param string|null $hash Optional. The _entry_uid_hash value. Uses $_GET['entry_hash'] if not provided
 * @param mixed $default Default value to return if field not found
 * @return mixed The field value or default if not found
 */
function get_entry_field($field_name, $hash = null, $default = '')
{
    if ($default === null) {
        $default = '';
    }

    if ($hash === null && isset($_GET['entry_hash'])) {
        $hash = sanitize_text_field(wp_unslash($_GET['entry_hash']));
    }

    $entry = get_entry_by_hash($hash);
    
    if (! $entry) {
        return $default;
    }

    // Convert response to array if it's an object
    $response = $entry->response ?? null;
    if (is_object($response)) {
        $response = (array) $response;
    }

    // Check in response data
    if (is_array($response) && isset($response[$field_name])) {
        $value = normalize_dynamic_value($response[$field_name], $field_name);
        if ($value !== null) {
            return (string) $value;
        }
    }

    // Convert user_inputs to array if it's an object
    $user_inputs = $entry->user_inputs ?? null;
    if (is_object($user_inputs)) {
        $user_inputs = (array) $user_inputs;
    }

    // Check in user_inputs (parsed/formatted data)
    if (is_array($user_inputs) && isset($user_inputs[$field_name])) {
        $value = normalize_dynamic_value($user_inputs[$field_name], $field_name);
        if ($value !== null) {
            return (string) $value;
        }
    }

    return (string) $default;
}

/**
 * Normalize values returned from Fluent Forms to a string-safe scalar.
 *
 * @param mixed $value
 * @return mixed
 @since 1.0.0
 */
function normalize_dynamic_value($value, $field_name = '')
{
    if (is_string($value) || is_numeric($value) || is_bool($value)) {
        return $value;
    }

    if (is_object($value)) {
        $value = get_object_vars($value);
    }

    if (is_array($value)) {
        $name = flatten_name_components($value, $field_name);
        if ($name !== null) {
            return $name;
        }

        foreach (['value', 'text', 'label'] as $sub_key) {
            if (array_key_exists($sub_key, $value)) {
                return normalize_dynamic_value($value[$sub_key], $field_name);
            }
        }

        return wp_json_encode($value);
    }

    return $value;
}

function flatten_name_components(array $value, $field_name = '')
{
    $first = get_first_available_value($value, ['first', 'first_name', 'firstName']);
    $last = get_first_available_value($value, ['last', 'last_name', 'lastName']);
    $middle = get_first_available_value($value, ['middle', 'middle_name', 'middleName']);
    $prefix = get_first_available_value($value, ['prefix', 'title', 'salutation', 'honorific']);
    $suffix = get_first_available_value($value, ['suffix', 'suffix_name', 'suffixName']);

    if (! $first && ! $last && ! $prefix && ! $middle && ! $suffix) {
        return null;
    }

    $parts = [];
    if ($prefix) {
        $parts[] = $prefix;
    }
    if ($first) {
        $parts[] = $first;
    }
    if ($middle) {
        $parts[] = $middle;
    }
    if ($last) {
        $parts[] = $last;
    }

    $name = trim(implode(' ', array_filter($parts, static fn ($part) => $part !== null && $part !== '')));
    if (! $name) {
        return null;
    }

    if ($suffix) {
        $suffix_str = trim(is_array($suffix) ? implode(' ', $suffix) : $suffix);
        if ($suffix_str) {
            $name = trim($name . ' ' . $suffix_str);
        }
    }

    return $name;
}

function get_first_available_value(array $data, array $keys)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
            return $data[$key];
        }
    }

    foreach ($keys as $key) {
        $lower = strtolower($key);
        foreach ($data as $candidate_key => $value) {
            if (strtolower($candidate_key) === $lower && $value !== null && $value !== '') {
                return $value;
            }
        }
    }

    return null;
}

/**
 * Get entry meta value by hash
 *
 * @param string $meta_key The meta key to retrieve
 * @param string|null $hash Optional. The _entry_uid_hash value. Uses $_GET['entry_hash'] if not provided
 * @param mixed $default Default value to return if meta not found
 * @return mixed The meta value or default if not found
 */
function get_entry_meta($meta_key, $hash = null, $default = null)
{
    if ($hash === null && isset($_GET['entry_hash'])) {
        $hash = sanitize_text_field(wp_unslash($_GET['entry_hash']));
    }

    $entry_id = get_submission_id_by_hash($hash);
    
    if (! $entry_id) {
        return $default;
    }

    $value = Helper::getSubmissionMeta($entry_id, $meta_key, $default);
    
    return $value !== null ? $value : $default;
}

/**
 * Get all entry data (response fields + meta) by hash
 *
 * @param string|null $hash Optional. The _entry_uid_hash value. Uses $_GET['entry_hash'] if not provided
 * @return array|null Array with 'fields' and 'meta' keys or null if not found
 */
function get_all_entry_data($hash = null)
{
    if ($hash === null && isset($_GET['entry_hash'])) {
        $hash = sanitize_text_field(wp_unslash($_GET['entry_hash']));
    }

    $entry = get_entry_by_hash($hash);
    
    if (! $entry) {
        return null;
    }

    return [
        'id'           => $entry->id ?? null,
        'form_id'      => $entry->form_id ?? null,
        'user_id'      => $entry->user_id ?? null,
        'status'       => $entry->status ?? null,
        'created_at'   => $entry->created_at ?? null,
        'response'     => $entry->response ?? [],
        'user_inputs'  => $entry->user_inputs ?? [],
    ];
}

/**
 * Get current hash from URL
 *
 * @return string|null The hash from $_GET['entry_hash'] or null
 */
function get_current_entry_hash()
{
    if (isset($_GET['entry_hash'])) {
        return sanitize_text_field(wp_unslash($_GET['entry_hash']));
    }
    
    if (isset($_GET['entry'])) {
        return sanitize_text_field(wp_unslash($_GET['entry']));
    }

    return null;
}

/**
 * Check if current request has an entry hash
 *
 * @return bool True if hash exists in URL
 */
function has_entry_hash()
{
    return get_current_entry_hash() !== null;
}
