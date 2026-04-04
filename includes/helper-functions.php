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
    $entry_id = ar_get_entry_id_from_hash($hash);
    
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

    if (ar_get_report_mode_by_entry_id($entry_id) === 'score_driven') {
        $section_ids = ar_get_selected_section_ids_by_entry_id($entry_id);
        $report_id = ar_get_report_id_by_entry_id($entry_id);
        if (! $report_id) {
            return null;
        }

        return ar_build_section_records($section_ids, $report_id);
    }

    $section_scores = ar_get_section_scores_by_entry_id($entry_id);
    if ($section_scores) {
        $report_id = absint(Helper::getSubmissionMeta($entry_id, 'ar_report_id'));
        if (! $report_id && ! empty($section_scores[0]['parent_id'])) {
            $report_id = absint($section_scores[0]['parent_id']);
        }

        if ($report_id) {
            return ar_get_display_section_records($section_scores, $report_id);
        }
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
                'max_score'    => $section['max_score'] ?? null,
                'percent'      => $section['percent'] ?? null,
                'graph_key'    => $section['graph_key'] ?? '',
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
    $entry_id = ar_get_entry_id_from_hash($hash);
    if (! $entry_id) {
        return null;
    }

    $report = get_post(ar_get_report_id_by_entry_id($entry_id));
    
    if ($report && $report->post_type === Post_Type::POST_TYPE && $report->post_status === 'publish') {
        return $report;
    }

    return null;
}

/**
 * Get report parent ID by entry ID.
 *
 * @param int $entry_id The FluentForm submission/entry ID
 * @return int Report parent ID or 0 if not found
 */
function ar_get_report_id_by_entry_id($entry_id)
{
    $entry_id = absint($entry_id);
    if (! $entry_id) {
        return 0;
    }

    $meta_report_id = absint(Helper::getSubmissionMeta($entry_id, 'ar_report_id'));
    if ($meta_report_id) {
        return $meta_report_id;
    }

    $score_payload = ar_get_score_payload_by_entry_id($entry_id);
    if (! empty($score_payload['report_id'])) {
        return absint($score_payload['report_id']);
    }

    if (ar_get_report_mode_by_entry_id($entry_id) === 'score_driven') {
        return 0;
    }

    $section_scores = ar_get_section_scores_by_entry_id($entry_id);
    if ($section_scores && ! empty($section_scores[0]['parent_id'])) {
        return absint($section_scores[0]['parent_id']);
    }

    $sections = get_top_sections_by_entry_id($entry_id);
    if (! $sections || empty($sections[0]['parent_id'])) {
        return 0;
    }

    return absint($sections[0]['parent_id']);
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

    if (! ar_get_report_id_by_entry_id($entry_id)) {
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

    return add_query_arg('entry_hash', $hash, $page_url);
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
    $entry_id = ar_get_entry_id_from_hash($hash);
    
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
    return ar_get_report_id_by_entry_id($entry_id) > 0;
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
    if ($hash === null) {
        $hash = get_current_entry_hash();
    }

    $entry_id = ar_get_entry_id_from_hash($hash);

    return $entry_id ? ar_get_entry_by_id($entry_id) : null;
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

    $path = [];
    if (is_string($field_name) && strpos($field_name, '.') !== false) {
        $path = array_values(array_filter(explode('.', $field_name), 'strlen'));
        $field_name = $path[0] ?? $field_name;
    }

    // Convert response to array if it's an object
    $response = $entry->response ?? null;
    if (is_object($response)) {
        $response = (array) $response;
    }

    // Check in response data
    if (is_array($response) && isset($response[$field_name])) {
        $raw_value = $response[$field_name];
        if ($path) {
            $raw_value = ar_get_nested_value($raw_value, array_slice($path, 1));
        }
        $value = normalize_dynamic_value($raw_value, $field_name);
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
        $raw_value = $user_inputs[$field_name];
        if ($path) {
            $raw_value = ar_get_nested_value($raw_value, array_slice($path, 1));
        }
        $value = normalize_dynamic_value($raw_value, $field_name);
        if ($value !== null) {
            return (string) $value;
        }
    }

    return (string) $default;
}

/**
 * Resolve a nested value from an array/object using a path of keys.
 *
 * @param mixed $value
 * @param array $path
 * @return mixed|null
 */
function ar_get_nested_value($value, array $path)
{
    if (empty($path)) {
        return $value;
    }

    if (is_object($value)) {
        $value = (array) $value;
    }

    foreach ($path as $key) {
        if (is_array($value) && array_key_exists($key, $value)) {
            $value = $value[$key];
            if (is_object($value)) {
                $value = (array) $value;
            }
            continue;
        }

        return null;
    }

    return $value;
}

/**
 * Normalize values returned from Fluent Forms to a string-safe scalar.
 *
 * @param mixed $value
 * @return mixed
 * @since 1.0.0
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
    if ($hash === null) {
        $hash = get_current_entry_hash();
    }

    $entry_id = ar_get_entry_id_from_hash($hash);
    
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
    if ($hash === null) {
        $hash = get_current_entry_hash();
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
 * Build a report-focused debug export for a submission hash.
 *
 * Accepts either Fluent Forms' `_entry_uid_hash` value or the encoded `entry`
 * hash used by Assessment Reports URLs.
 *
 * @param string|null $hash Optional submission hash. Falls back to the current request.
 * @return array<string, mixed>
 */
function ar_get_report_debug_export($hash = null)
{
    if ($hash === null) {
        $hash = get_current_entry_hash();
    }

    $hash = is_scalar($hash) ? sanitize_text_field((string) $hash) : '';

    if ($hash === '') {
        return [
            'error' => __('No entry hash was provided.', 'assessment-reports'),
        ];
    }

    $entry_id = ar_get_entry_id_from_hash($hash);
    if (! $entry_id) {
        return [
            'error' => __('No entry matched the provided hash.', 'assessment-reports'),
            'hash' => [
                'input' => $hash,
            ],
        ];
    }

    $entry = ar_get_entry_by_id($entry_id);
    if (! $entry) {
        return [
            'error' => __('The entry was resolved, but the submission could not be loaded.', 'assessment-reports'),
            'hash' => [
                'input' => $hash,
            ],
            'entry_id' => $entry_id,
        ];
    }

    $fluent_hash = Helper::getSubmissionMeta($entry_id, '_entry_uid_hash');
    $encoded_hash = ar_encode_entry_hash($entry_id);
    $report_id = ar_get_report_id_by_entry_id($entry_id);
    $report = $report_id ? get_post($report_id) : null;
    $score_profile_id = (string) Helper::getSubmissionMeta($entry_id, 'ar_score_profile_id');
    $score_profile = null;

    if ($score_profile_id !== '' && class_exists(__NAMESPACE__ . '\Score_Profiles')) {
        $score_profile = Score_Profiles::get_profile($score_profile_id);
    }

    $top_sections = get_top_sections_by_entry_id($entry_id);
    $display_sections = [];

    if (is_array($top_sections)) {
        foreach ($top_sections as $section) {
            $section_id = absint($section['section_id'] ?? 0);
            if (! $section_id) {
                continue;
            }

            $section_post = get_post($section_id);

            $display_sections[] = [
                'section_id' => $section_id,
                'score' => $section['score'] ?? 0,
                'max_score' => $section['max_score'] ?? null,
                'percent' => $section['percent'] ?? null,
                'graph_key' => $section['graph_key'] ?? '',
                'parent_id' => absint($section['parent_id'] ?? 0),
                'post' => ar_get_report_debug_post_snapshot($section_post),
                'field_mappings' => ar_normalize_report_debug_value(get_post_meta($section_id, '_field_mappings', true)),
                'score_section_rules' => ar_normalize_report_debug_value(get_post_meta($section_id, '_score_section_rules', true)),
                'show_with_zero_score' => ! empty(get_post_meta($section_id, '_show_with_zero_score', true)),
                'section_max_score' => get_post_meta($section_id, '_section_max_score', true),
                'configured_graph_key' => sanitize_key((string) get_post_meta($section_id, '_graph_key', true)),
            ];
        }
    }

    $report_url = '';
    if ($report_id) {
        $report_url = add_query_arg('entry_hash', $fluent_hash ?: $encoded_hash, get_permalink($report_id));
    }

    if (! $report_url) {
        $report_url = get_report_link_by_entry_id($entry_id, '') ?: '';
    }

    return [
        'hash' => [
            'input' => $hash,
            'fluent_entry_uid_hash' => $fluent_hash,
            'encoded_entry_hash' => $encoded_hash,
        ],
        'entry' => [
            'id' => isset($entry->id) ? absint($entry->id) : $entry_id,
            'form_id' => isset($entry->form_id) ? absint($entry->form_id) : 0,
            'user_id' => isset($entry->user_id) ? absint($entry->user_id) : 0,
            'status' => $entry->status ?? '',
            'created_at' => $entry->created_at ?? '',
            'response' => ar_normalize_report_debug_value($entry->response ?? []),
            'user_inputs' => ar_normalize_report_debug_value($entry->user_inputs ?? []),
        ],
        'report' => [
            'mode' => ar_get_report_mode_by_entry_id($entry_id),
            'id' => $report_id,
            'post' => ar_get_report_debug_post_snapshot($report),
            'form_id' => $report_id ? absint(get_post_meta($report_id, '_report_form_id', true)) : 0,
            'score_profile_id' => $score_profile_id,
            'score_profile' => ar_normalize_report_debug_value($score_profile),
            'children_display_limit' => $report_id ? ar_get_children_display_limit($report_id) : null,
            'children_display_order' => $report_id ? ar_get_children_display_order($report_id) : '',
            'closing_content' => $report_id ? get_post_meta($report_id, '_report_closing_content', true) : '',
            'ai_content_blocks' => $report_id ? ar_normalize_report_debug_value(get_post_meta($report_id, '_ai_content_blocks', true)) : [],
        ],
        'sections' => [
            'display' => $display_sections,
            'stored_top_sections' => ar_normalize_report_debug_value($top_sections),
            'stored_section_scores' => ar_get_section_scores_by_entry_id($entry_id),
            'selected_section_ids' => ar_get_selected_section_ids_by_entry_id($entry_id),
        ],
        'scores' => [
            'quiz_score' => ar_get_quiz_score($entry_id),
            'score_payload' => ar_get_score_payload_by_entry_id($entry_id),
        ],
        'ai' => [
            'status' => Helper::getSubmissionMeta($entry_id, 'ai_generation_status'),
            'error' => Helper::getSubmissionMeta($entry_id, 'ai_generation_error'),
            'enqueued' => Helper::getSubmissionMeta($entry_id, 'ai_generation_enqueued'),
            'generated_content' => ar_get_ai_generated_content($entry_id),
        ],
        'meta' => [
            '_entry_uid_hash' => $fluent_hash,
            'ar_report_id' => Helper::getSubmissionMeta($entry_id, 'ar_report_id'),
            'ar_report_mode' => Helper::getSubmissionMeta($entry_id, 'ar_report_mode'),
            'ar_score_profile_id' => Helper::getSubmissionMeta($entry_id, 'ar_score_profile_id'),
            'ar_selected_section_ids' => ar_get_selected_section_ids_by_entry_id($entry_id),
            'ar_section_scores' => ar_get_section_scores_by_entry_id($entry_id),
            'ar_score_payload' => ar_get_score_payload_by_entry_id($entry_id),
            'top_report_sections' => ar_normalize_report_debug_value(Helper::getSubmissionMeta($entry_id, 'top_report_sections')),
            'ai_generation_status' => Helper::getSubmissionMeta($entry_id, 'ai_generation_status'),
            'ai_generation_error' => Helper::getSubmissionMeta($entry_id, 'ai_generation_error'),
            'ai_generation_enqueued' => Helper::getSubmissionMeta($entry_id, 'ai_generation_enqueued'),
            'ai_generated_content' => ar_get_ai_generated_content($entry_id),
        ],
        'links' => [
            'report_url' => $report_url,
            'report_permalink' => $report_id ? get_permalink($report_id) : '',
        ],
    ];
}

/**
 * Load a Fluent Forms submission by entry ID.
 *
 * @param int $entry_id
 * @return object|null
 */
function ar_get_entry_by_id($entry_id)
{
    $entry_id = absint($entry_id);

    if (! $entry_id) {
        return null;
    }

    if (function_exists('fluentFormApi')) {
        try {
            return fluentFormApi('submissions')->find($entry_id);
        } catch (\Exception $e) {
            return null;
        }
    }

    if (class_exists('\FluentForm\App\Models\Submission')) {
        return \FluentForm\App\Models\Submission::find($entry_id);
    }

    return null;
}

/**
 * Normalize report debug values into array/scalar data that can be JSON encoded.
 *
 * @param mixed $value
 * @return mixed
 */
function ar_normalize_report_debug_value($value)
{
    if (is_object($value)) {
        $value = (array) $value;
    }

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[ $key ] = ar_normalize_report_debug_value($item);
        }

        return $value;
    }

    if (is_string($value)) {
        $trimmed = trim($value);

        if ($trimmed !== '' && in_array($trimmed[0], ['{', '['], true)) {
            $decoded = json_decode($trimmed, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return ar_normalize_report_debug_value($decoded);
            }
        }
    }

    return $value;
}

/**
 * Normalize a report or section post into a compact debug snapshot.
 *
 * @param \WP_Post|null $post
 * @return array<string, mixed>|null
 */
function ar_get_report_debug_post_snapshot($post)
{
    if (! $post instanceof \WP_Post) {
        return null;
    }

    return [
        'id' => absint($post->ID),
        'title' => $post->post_title,
        'slug' => $post->post_name,
        'status' => $post->post_status,
        'parent_id' => absint($post->post_parent),
        'menu_order' => (int) $post->menu_order,
        'permalink' => get_permalink($post),
        'content' => $post->post_content,
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
 * Resolve an entry ID from either entry_hash (_entry_uid_hash) or entry (encoded hash).
 *
 * @param string|null $hash Optional hash string. Uses current request if not provided.
 * @return int The entry ID or 0 if not found.
 */
function ar_get_entry_id_from_hash($hash = null)
{
    if ($hash === null) {
        $hash = get_current_entry_hash();
    }

    if (! $hash) {
        return 0;
    }

    $decoded = ar_decode_entry_hash($hash);
    if ($decoded) {
        return absint($decoded);
    }

    $entry_id = get_submission_id_by_hash($hash);
    if ($entry_id) {
        return absint($entry_id);
    }

    return 0;
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

/**
 * Retrieve cached AI-generated content from entry meta.
 *
 * @param int $entry_id
 * @return array
 */
function ar_get_ai_generated_content($entry_id)
{
    $raw = Helper::getSubmissionMeta($entry_id, 'ai_generated_content');
    if (! $raw) {
        return [];
    }

    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        return [];
    }

    return is_array($raw) ? $raw : [];
}

/**
 * Persist AI-generated content to entry meta.
 *
 * @param int $entry_id
 * @param array $content
 */
function ar_set_ai_generated_content($entry_id, array $content)
{
    $encoded = wp_json_encode($content);
    if (! $encoded) {
        return;
    }

    Helper::setSubmissionMeta($entry_id, 'ai_generated_content', $encoded);
}

/**
 * Retrieve AI-generated content for the current request's entry hash.
 *
 * @param string|null $key Optional. Specific AI content key to return.
 * @return mixed Array of all AI content, a specific value, or null if not found.
 */
function get_ai_generated_content($key = null)
{
    $hash = get_current_entry_hash();

    if (! $hash) {
        return "";
    }

    $entry_id = ar_get_entry_id_from_hash($hash);
    if (! $entry_id) {
        return "";
    }

    $content = ar_get_ai_generated_content($entry_id);
    if (! is_array($content) || empty($content)) {
        return "";
    }

    if ($key === null) {
        return wpautop($content);
    }

    return wpautop($content[$key] ?? "");
}

/**
 * Encode entry ID into the public hash used on report URLs.
 *
 * @param int $entry_id
 * @return string
 */
function ar_encode_entry_hash($entry_id)
{
    $entry_id = absint($entry_id);
    if (! $entry_id) {
        return '';
    }

    $payload = $entry_id . '|' . ar_build_entry_signature($entry_id);
    return strtr(base64_encode($payload), '+/=', '-_,');
}

/**
 * Decode the public entry hash back to an entry ID.
 *
 * @param string $hash
 * @return int
 */
function ar_decode_entry_hash($hash)
{
    if (! $hash) {
        return 0;
    }

    $decoded = base64_decode(strtr($hash, '-_,', '+/='), true);
    if (! $decoded) {
        return 0;
    }

    [$entry_id, $signature] = array_pad(explode('|', $decoded, 2), 2, '');
    if (! $entry_id || ! $signature) {
        return 0;
    }

    if (! hash_equals(ar_build_entry_signature($entry_id), $signature)) {
        return 0;
    }

    return absint($entry_id);
}

/**
 * Build the signature used for entry hashes.
 *
 * @param int $entry_id
 * @return string
 */
function ar_build_entry_signature($entry_id)
{
    return hash_hmac('sha256', (string) $entry_id, ar_get_entry_hash_salt());
}

/**
 * Determine the salt used for entry hash signatures.
 *
 * @return string
 */
function ar_get_entry_hash_salt()
{
    if (defined('ASSESSMENT_REPORT_HASH_SALT')) {
        return ASSESSMENT_REPORT_HASH_SALT;
    }

    return wp_salt('assessment_reports');
}

/**
 * Resolve a field label from a Fluent Form.
 *
 * @param int $form_id
 * @param string $field_name
 * @return string
 */
function ar_get_field_label($form_id, $field_name)
{
    $form = fluentFormApi('forms')->find($form_id);
    if (! $form || empty($form->form_fields)) {
        return $field_name;
    }

    $fields = json_decode($form->form_fields, true);
    if (! is_array($fields)) {
        return $field_name;
    }

    foreach ($fields['fields'] ?? [] as $field) {
        $name = $field['attributes']['name'] ?? '';
        if ($name === $field_name) {
            return $field['settings']['label'] ?? $field['attributes']['label'] ?? $field_name;
        }
    }

    return $field_name;
}

/**
 * Normalize a stored field mapping configuration.
 *
 * @param mixed $mapping
 * @return array{enabled: bool, points: float, multiplier: float}
 */
function ar_normalize_choice_mapping($mapping)
{
    if (is_array($mapping)) {
        $enabled = array_key_exists('enabled', $mapping) ? ! empty($mapping['enabled']) : true;
        $points = array_key_exists('points', $mapping) && $mapping['points'] !== '' ? (float) $mapping['points'] : 1.0;
        $multiplier = array_key_exists('multiplier', $mapping) && $mapping['multiplier'] !== '' ? (float) $mapping['multiplier'] : 1.0;

        return [
            'enabled' => $enabled,
            'points' => $points,
            'multiplier' => $multiplier,
        ];
    }

    if ($mapping === null || $mapping === '') {
        return [
            'enabled' => false,
            'points' => 1.0,
            'multiplier' => 1.0,
        ];
    }

    return [
        'enabled' => true,
        'points' => 1.0,
        'multiplier' => (float) $mapping,
    ];
}

/**
 * Normalize a stored per-question score contribution record.
 *
 * @param string $field_name
 * @param mixed $record
 * @return array|null
 */
function ar_normalize_section_question_point_record($field_name, $record)
{
    $field_name = sanitize_text_field((string) $field_name);
    if ($field_name === '') {
        return null;
    }

    if (is_numeric($record)) {
        return [
            'field_name' => $field_name,
            'field_label' => $field_name,
            'submitted_values' => [],
            'matched_values' => [],
            'score' => (float) $record,
        ];
    }

    if (! is_array($record)) {
        return null;
    }

    $submitted_values = [];
    foreach ((array) ($record['submitted_values'] ?? []) as $submitted_value) {
        if ($submitted_value === null || $submitted_value === '' || is_array($submitted_value) || is_object($submitted_value)) {
            continue;
        }

        $submitted_values[] = (string) $submitted_value;
    }

    $matched_values = [];
    foreach ((array) ($record['matched_values'] ?? []) as $matched_value) {
        if (! is_array($matched_value)) {
            continue;
        }

        $value = $matched_value['value'] ?? '';
        if ($value === null || $value === '' || is_array($value) || is_object($value)) {
            continue;
        }

        $matched_values[] = [
            'value' => (string) $value,
            'points' => (float) ($matched_value['points'] ?? 0),
            'multiplier' => (float) ($matched_value['multiplier'] ?? 1),
            'score' => (float) ($matched_value['score'] ?? 0),
        ];
    }

    $field_label = isset($record['field_label']) ? wp_strip_all_tags((string) $record['field_label']) : '';

    return [
        'field_name' => sanitize_text_field((string) ($record['field_name'] ?? $field_name)),
        'field_label' => $field_label !== '' ? $field_label : $field_name,
        'submitted_values' => array_values(array_unique($submitted_values)),
        'matched_values' => $matched_values,
        'score' => (float) ($record['score'] ?? 0),
    ];
}

/**
 * Normalize a stored per-section question point dataset.
 *
 * @param mixed $question_points
 * @return array<string, array<string, mixed>>
 */
function ar_normalize_section_question_points($question_points)
{
    if (! is_array($question_points)) {
        return [];
    }

    $normalized = [];
    foreach ($question_points as $field_name => $record) {
        if (is_array($record) && ! empty($record['field_name'])) {
            $field_name = (string) $record['field_name'];
        }

        $field_name = sanitize_text_field((string) $field_name);
        if ($field_name === '') {
            continue;
        }

        $normalized_record = ar_normalize_section_question_point_record($field_name, $record);
        if (! $normalized_record) {
            continue;
        }

        $normalized[ $field_name ] = $normalized_record;
    }

    return $normalized;
}

/**
 * Build a per-question score breakdown from submission data and section mappings.
 *
 * @param array $submission_data
 * @param array $mappings
 * @param int $form_id
 * @return array<string, array<string, mixed>>
 */
function ar_build_section_question_points(array $submission_data, array $mappings, $form_id = 0)
{
    $question_points = [];

    foreach ($mappings as $field_name => $choices) {
        if (! isset($submission_data[ $field_name ])) {
            continue;
        }

        $submitted = $submission_data[ $field_name ];
        if (is_null($submitted)) {
            continue;
        }

        $submitted_values = is_array($submitted) ? $submitted : [ $submitted ];
        $normalized_submitted_values = [];
        $matched_values = [];
        $field_score = 0.0;

        foreach ($submitted_values as $value) {
            if ($value === '' || $value === null || is_array($value) || is_object($value)) {
                continue;
            }

            $value = (string) $value;
            if ($value === '') {
                continue;
            }

            $normalized_submitted_values[] = $value;

            if (! isset($choices[ $value ])) {
                continue;
            }

            $mapping = ar_normalize_choice_mapping($choices[ $value ]);
            if (! $mapping['enabled']) {
                continue;
            }

            $answer_score = $mapping['points'] * $mapping['multiplier'];

            $matched_values[] = [
                'value' => $value,
                'points' => (float) $mapping['points'],
                'multiplier' => (float) $mapping['multiplier'],
                'score' => (float) $answer_score,
            ];

            $field_score += $answer_score;
        }

        if (! $matched_values && ! $normalized_submitted_values) {
            continue;
        }

        $question_points[ $field_name ] = [
            'field_name' => (string) $field_name,
            'field_label' => ar_get_field_label($form_id, $field_name),
            'submitted_values' => array_values(array_unique($normalized_submitted_values)),
            'matched_values' => $matched_values,
            'score' => (float) $field_score,
        ];
    }

    return ar_normalize_section_question_points($question_points);
}

/**
 * Normalize a Fluent Forms submission payload into an array shape.
 *
 * @param mixed $submission_data
 * @return array
 */
function ar_normalize_submission_data($submission_data)
{
    if (is_object($submission_data)) {
        $submission_data = (array) $submission_data;
    }

    if (! is_array($submission_data)) {
        return [];
    }

    foreach ($submission_data as $key => $value) {
        if (is_object($value)) {
            $submission_data[ $key ] = (array) $value;
        }
    }

    return $submission_data;
}

/**
 * Compute a section question-point breakdown directly from a stored entry.
 *
 * @param int $entry_id
 * @param int $section_id
 * @return array<string, array<string, mixed>>
 */
function ar_compute_section_question_points_by_entry_id($entry_id, $section_id)
{
    $entry_id = absint($entry_id);
    $section_id = absint($section_id);

    if (! $entry_id || ! $section_id) {
        return [];
    }

    $mappings = get_post_meta($section_id, '_field_mappings', true);
    if (! is_array($mappings) || ! $mappings) {
        return [];
    }

    $entry = ar_get_entry_by_id($entry_id);
    if (! $entry) {
        return [];
    }

    $submission_data = ar_normalize_submission_data($entry->response ?? []);
    if (! $submission_data) {
        return [];
    }

    return ar_build_section_question_points($submission_data, $mappings, absint($entry->form_id ?? 0));
}

/**
 * Normalize a stored section score record.
 *
 * @param mixed $record
 * @return array|null
 */
function ar_normalize_section_score_record($record)
{
    if (! is_array($record)) {
        return null;
    }

    $section_id = absint($record['section_id'] ?? 0);
    if (! $section_id) {
        return null;
    }

    $max_score = null;
    if (array_key_exists('max_score', $record) && $record['max_score'] !== null && $record['max_score'] !== '') {
        $max_score = (float) $record['max_score'];
    }

    $percent = null;
    if (array_key_exists('percent', $record) && $record['percent'] !== null && $record['percent'] !== '') {
        $percent = (float) $record['percent'];
    }

    return [
        'section_id' => $section_id,
        'score' => (float) ($record['score'] ?? 0),
        'max_score' => $max_score,
        'percent' => $percent,
        'graph_key' => sanitize_key((string) ($record['graph_key'] ?? '')),
        'question_points' => ar_normalize_section_question_points($record['question_points'] ?? []),
        'parent_id' => absint($record['parent_id'] ?? 0),
        'menu_order' => isset($record['menu_order']) ? (int) $record['menu_order'] : 0,
    ];
}

/**
 * Resolve the per-report display limit for child sections.
 *
 * @param int $report_id
 * @return int|string
 */
function ar_get_children_display_limit($report_id)
{
    $report_id = absint($report_id);
    if (! $report_id) {
        return 3;
    }

    $raw = get_post_meta($report_id, '_children_display_limit', true);
    if ($raw === '' || $raw === null) {
        return 3;
    }

    if (is_string($raw) && strtolower(trim($raw)) === 'all') {
        return 'all';
    }

    $limit = absint($raw);

    return $limit > 0 ? $limit : 3;
}

/**
 * Resolve the per-report display ordering for child sections.
 *
 * @param int $report_id
 * @return string
 */
function ar_get_children_display_order($report_id)
{
    $report_id = absint($report_id);
    if (! $report_id) {
        return 'score_desc';
    }

    $order = sanitize_key((string) get_post_meta($report_id, '_children_display_order', true));

    return in_array($order, ['menu_order', 'score_asc', 'score_desc'], true) ? $order : 'score_desc';
}

/**
 * Get the full stored section score dataset for an entry.
 *
 * @param int $entry_id
 * @return array<int, array>
 */
function ar_get_section_scores_by_entry_id($entry_id)
{
    $entry_id = absint($entry_id);
    if (! $entry_id) {
        return [];
    }

    $raw = Helper::getSubmissionMeta($entry_id, 'ar_section_scores');
    if (! $raw) {
        return [];
    }

    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    if (! is_array($raw)) {
        return [];
    }

    $records = [];
    foreach ($raw as $record) {
        $normalized = ar_normalize_section_score_record($record);
        if (! $normalized) {
            continue;
        }

        $records[] = $normalized;
    }

    return $records;
}

/**
 * Get the full stored section score dataset by entry hash.
 *
 * @param string|null $hash
 * @return array<int, array>
 */
function ar_get_section_scores_by_hash($hash = null)
{
    $entry_id = ar_get_entry_id_from_hash($hash);

    return $entry_id ? ar_get_section_scores_by_entry_id($entry_id) : [];
}

/**
 * Apply parent display settings to a section score dataset.
 *
 * @param array<int, array> $records
 * @param int $report_id
 * @return array<int, array>
 */
function ar_get_display_section_records(array $records, $report_id)
{
    $report_id = absint($report_id);
    if (! $report_id || ! $records) {
        return [];
    }

    $normalized = [];
    foreach ($records as $record) {
        $record = ar_normalize_section_score_record($record);
        if (! $record) {
            continue;
        }

        $normalized[] = $record;
    }

    $order = ar_get_children_display_order($report_id);

    usort($normalized, static function ($a, $b) use ($order) {
        if ($order === 'menu_order') {
            $comparison = $a['menu_order'] <=> $b['menu_order'];
            return $comparison !== 0 ? $comparison : ($a['section_id'] <=> $b['section_id']);
        }

        if ($order === 'score_asc') {
            if ($a['score'] === $b['score']) {
                $comparison = $a['menu_order'] <=> $b['menu_order'];
                return $comparison !== 0 ? $comparison : ($a['section_id'] <=> $b['section_id']);
            }

            return $a['score'] <=> $b['score'];
        }

        if ($a['score'] === $b['score']) {
            $comparison = $a['menu_order'] <=> $b['menu_order'];
            return $comparison !== 0 ? $comparison : ($a['section_id'] <=> $b['section_id']);
        }

        return $b['score'] <=> $a['score'];
    });

    $limit = ar_get_children_display_limit($report_id);
    if ($limit === 'all') {
        return $normalized;
    }

    return array_slice($normalized, 0, max(0, (int) $limit));
}

/**
 * Get ordered display section IDs by entry ID.
 *
 * @param int $entry_id
 * @return array<int, int>
 */
function ar_get_display_section_ids_by_entry_id($entry_id)
{
    $sections = get_top_sections_by_entry_id($entry_id);
    if (! is_array($sections)) {
        return [];
    }

    return array_values(array_filter(array_map('absint', wp_list_pluck($sections, 'section_id'))));
}

/**
 * Get ordered display section IDs by entry hash.
 *
 * @param string|null $hash
 * @return array<int, int>
 */
function ar_get_display_section_ids_by_hash($hash = null)
{
    $entry_id = ar_get_entry_id_from_hash($hash);

    return $entry_id ? ar_get_display_section_ids_by_entry_id($entry_id) : [];
}

/**
 * Find a section score record by section ID.
 *
 * @param int $section_id
 * @param string|null $hash
 * @return array|null
 */
function ar_get_section_score_record_by_section_id($section_id, $hash = null)
{
    $section_id = absint($section_id);
    if (! $section_id) {
        return null;
    }

    foreach (ar_get_section_scores_by_hash($hash) as $record) {
        if ((int) $record['section_id'] === $section_id) {
            return $record;
        }
    }

    return null;
}

/**
 * Find a section score record by graph key.
 *
 * @param string $graph_key
 * @param string|null $hash
 * @return array|null
 */
function ar_get_section_score_record_by_graph_key($graph_key, $hash = null)
{
    $graph_key = sanitize_key((string) $graph_key);
    if ($graph_key === '') {
        return null;
    }

    foreach (ar_get_section_scores_by_hash($hash) as $record) {
        if (($record['graph_key'] ?? '') === $graph_key) {
            return $record;
        }
    }

    return null;
}

/**
 * Get a section score by section ID.
 *
 * @param int $section_id
 * @param string|null $hash
 * @param mixed $default
 * @return mixed
 */
function ar_get_section_score_by_section_id($section_id, $hash = null, $default = null)
{
    $record = ar_get_section_score_record_by_section_id($section_id, $hash);

    return $record ? $record['score'] : $default;
}

/**
 * Get a section percent by section ID.
 *
 * @param int $section_id
 * @param string|null $hash
 * @param mixed $default
 * @return mixed
 */
function ar_get_section_percent_by_section_id($section_id, $hash = null, $default = null)
{
    $record = ar_get_section_score_record_by_section_id($section_id, $hash);

    return $record && $record['percent'] !== null ? $record['percent'] : $default;
}

/**
 * Get a section score by graph key.
 *
 * @param string $graph_key
 * @param string|null $hash
 * @param mixed $default
 * @return mixed
 */
function ar_get_section_score_by_graph_key($graph_key, $hash = null, $default = null)
{
    $record = ar_get_section_score_record_by_graph_key($graph_key, $hash);

    return $record ? $record['score'] : $default;
}

/**
 * Get a section percent by graph key.
 *
 * @param string $graph_key
 * @param string|null $hash
 * @param mixed $default
 * @return mixed
 */
function ar_get_section_percent_by_graph_key($graph_key, $hash = null, $default = null)
{
    $record = ar_get_section_score_record_by_graph_key($graph_key, $hash);

    return $record && $record['percent'] !== null ? $record['percent'] : $default;
}

/**
 * Get raw per-question points for a section by section ID.
 *
 * @param int $section_id
 * @param string|null $hash
 * @return array<string, array<string, mixed>>
 */
function ar_get_section_question_points_by_section_id($section_id, $hash = null)
{
    $record = ar_get_section_score_record_by_section_id($section_id, $hash);
    $question_points = is_array($record['question_points'] ?? null) ? $record['question_points'] : [];
    if ($question_points) {
        return $question_points;
    }

    $entry_id = ar_get_entry_id_from_hash($hash);

    return $entry_id ? ar_compute_section_question_points_by_entry_id($entry_id, $section_id) : [];
}

/**
 * Get raw per-question points for a section by graph key.
 *
 * @param string $graph_key
 * @param string|null $hash
 * @return array<string, array<string, mixed>>
 */
function ar_get_section_question_points_by_graph_key($graph_key, $hash = null)
{
    $record = ar_get_section_score_record_by_graph_key($graph_key, $hash);
    $question_points = is_array($record['question_points'] ?? null) ? $record['question_points'] : [];
    if ($question_points) {
        return $question_points;
    }

    $section_id = absint($record['section_id'] ?? 0);
    if (! $section_id) {
        return [];
    }

    $entry_id = ar_get_entry_id_from_hash($hash);

    return $entry_id ? ar_compute_section_question_points_by_entry_id($entry_id, $section_id) : [];
}

/**
 * Get each child section score record for a parent report.
 *
 * Returns an associative array keyed by child post ID with the child score and
 * configured graph key for the current entry. Children without a recorded match
 * remain at `0`.
 *
 * @param int $parent_id Parent report post ID.
 * @param string|null $entry_hash Optional submission hash. Falls back to the current request.
 * @return array<int, array<string, mixed>>
 */
function ar_get_child_section_score_records($parent_id, $entry_hash = null)
{
    $parent_id = absint($parent_id);
    if (! $parent_id) {
        return [];
    }

    $children = get_posts([
        'post_type' => Post_Type::POST_TYPE,
        'post_parent' => $parent_id,
        'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
        'numberposts' => -1,
        'orderby' => [
            'menu_order' => 'ASC',
            'date' => 'ASC',
        ],
    ]);

    if (! $children) {
        return [];
    }

    $records = [];

    foreach ($children as $child_post) {
        $child_id = absint($child_post->ID);
        if (! $child_id) {
            continue;
        }

        $graph_key = sanitize_key((string) get_post_meta($child_id, '_graph_key', true));
        if ($graph_key === '') {
            $graph_key = sanitize_title($child_post->post_name ?: $child_post->post_title ?: 'section-' . $child_id);
        }

        $records[ $child_id ] = [
            'score' => 0.0,
            'graph_key' => $graph_key,
        ];
    }

    $entry_id = ar_get_entry_id_from_hash($entry_hash);
    if (! $entry_id) {
        return $records;
    }

    $section_scores = ar_get_section_scores_by_entry_id($entry_id);
    foreach ($section_scores as $record) {
        $section_id = absint($record['section_id'] ?? 0);
        $record_parent_id = absint($record['parent_id'] ?? 0);

        if ($record_parent_id !== $parent_id || ! isset($records[ $section_id ])) {
            continue;
        }

        $records[ $section_id ]['score'] = (float) ($record['score'] ?? 0);

        if (! empty($record['graph_key'])) {
            $records[ $section_id ]['graph_key'] = sanitize_key((string) $record['graph_key']);
        }
    }

    if ($section_scores) {
        return $records;
    }

    if (ar_get_report_mode_by_entry_id($entry_id) === 'score_driven') {
        foreach (ar_get_selected_section_ids_by_entry_id($entry_id) as $section_id) {
            $section_id = absint($section_id);
            if (! isset($records[ $section_id ])) {
                continue;
            }

            $rules = get_post_meta($section_id, '_score_section_rules', true);
            $records[ $section_id ]['score'] = is_array($rules) && isset($rules['priority'])
                ? (float) $rules['priority']
                : 0.0;
        }

        return $records;
    }

    foreach ((array) get_top_sections_by_entry_id($entry_id) as $record) {
        $section_id = absint($record['section_id'] ?? 0);
        $record_parent_id = absint($record['parent_id'] ?? 0);

        if ($record_parent_id !== $parent_id || ! isset($records[ $section_id ])) {
            continue;
        }

        $records[ $section_id ]['score'] = (float) ($record['score'] ?? 0);

        if (! empty($record['graph_key'])) {
            $records[ $section_id ]['graph_key'] = sanitize_key((string) $record['graph_key']);
        }
    }

    return $records;
}

/**
 * Get each child section score for a parent report.
 *
 * Returns an associative array keyed by child post ID. Children without a
 * recorded match remain at `0`.
 *
 * @param int $parent_id Parent report post ID.
 * @param string|null $entry_hash Optional submission hash. Falls back to the current request.
 * @return array<int, float>
 */
function ar_get_child_section_scores($parent_id, $entry_hash = null)
{
    $scores = [];

    foreach (ar_get_child_section_score_records($parent_id, $entry_hash) as $child_id => $record) {
        $scores[ $child_id ] = (float) ($record['score'] ?? 0);
    }

    return $scores;
}

/**
 * Template-friendly alias for parent child section score records.
 *
 * @param int $parent_id Parent report post ID.
 * @param string|null $entry_hash Optional submission hash. Falls back to the current request.
 * @return array<int, array<string, mixed>>
 */
function get_child_section_score_records($parent_id, $entry_hash = null)
{
    return ar_get_child_section_score_records($parent_id, $entry_hash);
}

/**
 * Template-friendly alias for parent child section scores.
 *
 * @param int $parent_id Parent report post ID.
 * @param string|null $entry_hash Optional submission hash. Falls back to the current request.
 * @return array<int, float>
 */
function get_child_section_scores($parent_id, $entry_hash = null)
{
    return ar_get_child_section_scores($parent_id, $entry_hash);
}

/**
 * Get a loose quiz score from the reported sections.
 *
 * @param int $entry_id
 * @return int|null
 */
function ar_get_quiz_score($entry_id)
{
    if (ar_get_report_mode_by_entry_id($entry_id) === 'score_driven') {
        $payload = ar_get_score_payload_by_entry_id($entry_id);
        if (! empty($payload['summary']['score'])) {
            return (float) $payload['summary']['score'];
        }
    }

    $sections = ar_get_section_scores_by_entry_id($entry_id);
    if (! $sections) {
        $sections = get_top_sections_by_entry_id($entry_id);
    }

    if (! $sections) {
        return null;
    }

    $score = 0.0;
    foreach ($sections as $section) {
        $score += (float) ($section['score'] ?? 0);
    }

    return $score;
}

/**
 * Get the overall score for an entry hash.
 *
 * @param string|null $entry_hash Optional submission hash. Falls back to `$_GET['entry_hash']`.
 * @param mixed $default Default value when the score is unavailable.
 * @return mixed
 */
function ar_get_overall_score($entry_hash = null, $default = null)
{
    if ($entry_hash === null && isset($_GET['entry_hash'])) {
        $entry_hash = sanitize_text_field(wp_unslash($_GET['entry_hash']));
    }

    $entry_id = ar_get_entry_id_from_hash($entry_hash);
    if (! $entry_id) {
        return $default;
    }

    $score = ar_get_quiz_score($entry_id);

    return $score !== null ? $score : $default;
}

/**
 * Get the overall score percent for an entry hash.
 *
 * This is calculated as the sum of section scores divided by the sum of
 * section max scores.
 *
 * @param string|null $entry_hash Optional submission hash. Falls back to `$_GET['entry_hash']`.
 * @param mixed $default Default value when the percent is unavailable.
 * @return mixed
 */
function ar_get_overall_percent($entry_hash = null, $default = null)
{
    if ($entry_hash === null && isset($_GET['entry_hash'])) {
        $entry_hash = sanitize_text_field(wp_unslash($_GET['entry_hash']));
    }

    $entry_id = ar_get_entry_id_from_hash($entry_hash);
    if (! $entry_id) {
        return $default;
    }

    $sections = ar_get_section_scores_by_entry_id($entry_id);
    if (! $sections) {
        $sections = get_top_sections_by_entry_id($entry_id);
    }

    if (! $sections) {
        return $default;
    }

    $score_total = 0.0;
    $max_total = 0.0;

    foreach ($sections as $section) {
        $score_total += (float) ($section['score'] ?? 0);
        $max_total += (float) ($section['max_score'] ?? 0);
    }

    if ($max_total <= 0) {
        return $default;
    }

    return round(($score_total / $max_total) * 100, 2);
}

/**
 * Template-friendly alias for retrieving the overall score by hash.
 *
 * @param string|null $entry_hash Optional submission hash. Falls back to `$_GET['entry_hash']`.
 * @param mixed $default Default value when the score is unavailable.
 * @return mixed
 */
function get_overall_score($entry_hash = null, $default = null)
{
    return ar_get_overall_score($entry_hash, $default);
}

/**
 * Template-friendly alias for retrieving the overall score percent by hash.
 *
 * @param string|null $entry_hash Optional submission hash. Falls back to `$_GET['entry_hash']`.
 * @param mixed $default Default value when the percent is unavailable.
 * @return mixed
 */
function get_overall_percent($entry_hash = null, $default = null)
{
    return ar_get_overall_percent($entry_hash, $default);
}

/**
 * Enqueue AI generation for a report/entry pair via ActionScheduler.
 *
 * @param int $report_id
 * @param int $entry_id
 */
function ar_enqueue_ai_generation($report_id, $entry_id)
{
    $report_id = absint($report_id);
    $entry_id = absint($entry_id);
    if (! $report_id || ! $entry_id) {
        return;
    }

    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('assessment_reports_generate_ai', [$report_id, $entry_id], 'assessment-reports');
        return;
    }

    wp_schedule_single_event(time(), 'assessment_reports_generate_ai', [$report_id, $entry_id]);
}

/**
 * Get the current entry report mode.
 *
 * @param int $entry_id
 * @return string
 */
function ar_get_report_mode_by_entry_id($entry_id)
{
    $entry_id = absint($entry_id);
    if (! $entry_id) {
        return 'legacy_response_mapped';
    }

    $mode = Helper::getSubmissionMeta($entry_id, 'ar_report_mode');

    return $mode === 'score_driven' ? 'score_driven' : 'legacy_response_mapped';
}

/**
 * Get a stored score payload for an entry.
 *
 * @param int $entry_id
 * @return array
 */
function ar_get_score_payload_by_entry_id($entry_id)
{
    $entry_id = absint($entry_id);
    if (! $entry_id) {
        return [];
    }

    $raw = Helper::getSubmissionMeta($entry_id, 'ar_score_payload');
    if (! $raw) {
        return [];
    }

    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return is_array($raw) ? $raw : [];
}

/**
 * Get a stored score payload by entry hash.
 *
 * @param string|null $hash
 * @return array
 */
function ar_get_score_payload_by_hash($hash = null)
{
    $entry_id = ar_get_entry_id_from_hash($hash);

    return $entry_id ? ar_get_score_payload_by_entry_id($entry_id) : [];
}

/**
 * Resolve a nested value from the stored score payload.
 *
 * @param string $path
 * @param string|null $hash
 * @param mixed $default
 * @return mixed
 */
function ar_get_score_value($path, $hash = null, $default = null)
{
    $payload = ar_get_score_payload_by_hash($hash);
    if (! $payload || ! is_string($path) || $path === '') {
        return $default;
    }

    $value = ar_get_nested_value($payload, explode('.', $path));

    return $value !== null ? $value : $default;
}

/**
 * Return chart-ready payload for a chart key.
 *
 * @param string $chart_key
 * @param string|null $hash
 * @return array
 */
function ar_get_chart_data($chart_key, $hash = null)
{
    $chart_key = sanitize_key((string) $chart_key);
    $payload = ar_get_score_payload_by_hash($hash);

    if ($chart_key === '' || empty($payload[ $chart_key ]) || ! is_array($payload[ $chart_key ])) {
        return [];
    }

    return $payload[ $chart_key ];
}

/**
 * Resolve a report group term from a term object, ID, slug, or name.
 *
 * @param mixed $term
 * @return \WP_Term|null
 */
function ar_get_report_group_term($term)
{
    if ($term instanceof \WP_Term) {
        return $term->taxonomy === Post_Type::GROUP_TAXONOMY ? $term : null;
    }

    if (is_numeric($term)) {
        $resolved = get_term(absint($term), Post_Type::GROUP_TAXONOMY);
        return $resolved instanceof \WP_Term ? $resolved : null;
    }

    if (! is_scalar($term)) {
        return null;
    }

    $term = trim((string) $term);
    if ($term === '') {
        return null;
    }

    $resolved = get_term_by('slug', sanitize_title($term), Post_Type::GROUP_TAXONOMY);
    if ($resolved instanceof \WP_Term) {
        return $resolved;
    }

    $resolved = get_term_by('name', $term, Post_Type::GROUP_TAXONOMY);

    return $resolved instanceof \WP_Term ? $resolved : null;
}

/**
 * Aggregate scores for a tagged report group for the current entry.
 *
 * Group membership is based on the `assessment_report_group` taxonomy assigned
 * to child `assessment_report` posts beneath the resolved parent report.
 *
 * @param mixed $term Term object, term ID, slug, or name.
 * @param string|null $entry_hash Optional submission hash. Falls back to the current request.
 * @return array<string, mixed>
 */
function ar_get_group_score_data($term, $entry_hash = null)
{
    $group_term = ar_get_report_group_term($term);
    if (! $group_term) {
        return [];
    }

    $entry_id = ar_get_entry_id_from_hash($entry_hash);
    if (! $entry_id) {
        return [];
    }

    $report_id = ar_get_report_id_by_entry_id($entry_id);
    if (! $report_id) {
        return [];
    }

    $group_section_ids = get_posts([
        'post_type' => Post_Type::POST_TYPE,
        'post_parent' => $report_id,
        'post_status' => ['publish', 'draft', 'private', 'pending', 'future'],
        'numberposts' => -1,
        'fields' => 'ids',
        'tax_query' => [
            [
                'taxonomy' => Post_Type::GROUP_TAXONOMY,
                'field' => 'term_id',
                'terms' => [$group_term->term_id],
            ],
        ],
    ]);

    $group_section_ids = array_values(array_filter(array_map('absint', (array) $group_section_ids)));
    if (! $group_section_ids) {
        return [
            'term_id' => (int) $group_term->term_id,
            'slug' => $group_term->slug,
            'name' => $group_term->name,
            'report_id' => $report_id,
            'entry_id' => $entry_id,
            'section_ids' => [],
            'section_count' => 0,
            'score' => 0.0,
            'max_score' => 0.0,
            'percent' => null,
        ];
    }

    $score_records = ar_get_child_section_score_records($report_id, $entry_hash);
    $legacy_records = [];

    foreach (ar_get_section_scores_by_entry_id($entry_id) as $record) {
        $section_id = absint($record['section_id'] ?? 0);
        if (! $section_id) {
            continue;
        }

        $legacy_records[ $section_id ] = $record;
    }

    $score_total = 0.0;
    $max_total = 0.0;

    foreach ($group_section_ids as $section_id) {
        if (isset($score_records[ $section_id ])) {
            $score_total += (float) ($score_records[ $section_id ]['score'] ?? 0);
        }

        $max_score = null;
        if (isset($legacy_records[ $section_id ]) && $legacy_records[ $section_id ]['max_score'] !== null) {
            $max_score = (float) $legacy_records[ $section_id ]['max_score'];
        } else {
            $configured_max = get_post_meta($section_id, '_section_max_score', true);
            if ($configured_max !== '') {
                $max_score = (float) $configured_max;
            }
        }

        if ($max_score !== null && $max_score > 0) {
            $max_total += $max_score;
        }
    }

    $score_total = round($score_total, 2);
    $max_total = round($max_total, 2);

    return [
        'term_id' => (int) $group_term->term_id,
        'slug' => $group_term->slug,
        'name' => $group_term->name,
        'report_id' => $report_id,
        'entry_id' => $entry_id,
        'section_ids' => $group_section_ids,
        'section_count' => count($group_section_ids),
        'score' => $score_total,
        'max_score' => $max_total,
        'percent' => $max_total > 0 ? round(($score_total / $max_total) * 100, 2) : null,
    ];
}

/**
 * Get the aggregate score for a tagged report group.
 *
 * @param mixed $term Term object, term ID, slug, or name.
 * @param string|null $entry_hash Optional submission hash. Falls back to the current request.
 * @param mixed $default Default value when the group score is unavailable.
 * @return mixed
 */
function ar_get_group_score($term, $entry_hash = null, $default = null)
{
    $group_data = ar_get_group_score_data($term, $entry_hash);

    return $group_data ? $group_data['score'] : $default;
}

/**
 * Get the aggregate percent for a tagged report group.
 *
 * @param mixed $term Term object, term ID, slug, or name.
 * @param string|null $entry_hash Optional submission hash. Falls back to the current request.
 * @param mixed $default Default value when the group percent is unavailable.
 * @return mixed
 */
function ar_get_group_percent($term, $entry_hash = null, $default = null)
{
    $group_data = ar_get_group_score_data($term, $entry_hash);

    return $group_data && $group_data['percent'] !== null ? $group_data['percent'] : $default;
}

/**
 * Template-friendly alias for retrieving an aggregate group score by hash.
 *
 * @param mixed $term Term object, term ID, slug, or name.
 * @param string|null $entry_hash Optional submission hash. Falls back to the current request.
 * @param mixed $default Default value when the group score is unavailable.
 * @return mixed
 */
function get_group_score($term, $entry_hash = null, $default = null)
{
    return ar_get_group_score($term, $entry_hash, $default);
}

/**
 * Template-friendly alias for retrieving an aggregate group percent by hash.
 *
 * @param mixed $term Term object, term ID, slug, or name.
 * @param string|null $entry_hash Optional submission hash. Falls back to the current request.
 * @param mixed $default Default value when the group percent is unavailable.
 * @return mixed
 */
function get_group_percent($term, $entry_hash = null, $default = null)
{
    return ar_get_group_percent($term, $entry_hash, $default);
}

/**
 * Get selected score-driven section IDs for an entry.
 *
 * @param int $entry_id
 * @return array<int, int>
 */
function ar_get_selected_section_ids_by_entry_id($entry_id)
{
    $entry_id = absint($entry_id);
    if (! $entry_id) {
        return [];
    }

    $raw = Helper::getSubmissionMeta($entry_id, 'ar_selected_section_ids');
    if (! $raw) {
        return [];
    }

    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    if (! is_array($raw)) {
        return [];
    }

    return array_values(array_filter(array_map('absint', $raw)));
}

/**
 * Get selected score-driven section IDs by entry hash.
 *
 * @param string|null $hash
 * @return array<int, int>
 */
function ar_get_selected_section_ids_by_hash($hash = null)
{
    $entry_id = ar_get_entry_id_from_hash($hash);

    return $entry_id ? ar_get_selected_section_ids_by_entry_id($entry_id) : [];
}

/**
 * Get selected section posts by hash for score-driven reports.
 *
 * @param string|null $hash
 * @return array|null
 */
function ar_get_selected_sections_by_hash($hash = null)
{
    $entry_id = ar_get_entry_id_from_hash($hash);
    if (! $entry_id) {
        return null;
    }

    return get_report_sections_by_hash($hash);
}

/**
 * Build normalized section records from selected IDs.
 *
 * @param array<int, int> $section_ids
 * @param int $report_id
 * @return array<int, array<string, int>>
 */
function ar_build_section_records(array $section_ids, $report_id)
{
    $records = [];
    $report_id = absint($report_id);

    foreach ($section_ids as $index => $section_id) {
        $section_id = absint($section_id);
        if (! $section_id) {
            continue;
        }

        $records[] = [
            'section_id' => $section_id,
            'score' => max(1, count($section_ids) - $index),
            'parent_id' => $report_id,
        ];
    }

    return $records;
}
