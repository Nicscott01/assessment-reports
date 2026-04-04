<?php

namespace AssessmentReports;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Models\Submission;
use FluentForm\App\Models\Form as FluentFormModel;

if (! defined('ABSPATH')) {
    exit;
}

class CLI_Command
{
    public function __construct()
    {
        if (! defined('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('assessment-reports reprocess-entry', [$this, 'reprocess_entry']);
        \WP_CLI::add_command('assessment-reports test-submit', [$this, 'test_submit']);
        \WP_CLI::add_command('assessment-reports rerun-actions', [$this, 'rerun_actions']);
        \WP_CLI::add_command('assessment-reports trigger-complete', [$this, 'trigger_complete']);
        \WP_CLI::add_command('assessment-reports dump-score-payload', [$this, 'dump_score_payload']);
    }

    /**
     * Rebuild report meta for an existing submission from its saved response data.
     *
     * ## OPTIONS
     *
     * [<entry_id>]
     * : The Fluent Forms submission ID.
     *
     * [--entry=<id>]
     * : The Fluent Forms submission ID.
     *
     * [--reset-ai]
     * : Also clear AI generation state/content before reprocessing.
     *
     * [--keep-existing-meta]
     * : Skip clearing existing Assessment Reports submission meta before reprocessing.
     *
     * ## EXAMPLES
     *
     * wp assessment-reports reprocess-entry 123
     * wp assessment-reports reprocess-entry --entry=123 --reset-ai
     */
    public function reprocess_entry($args, $assoc_args)
    {
        $entry_id = isset($assoc_args['entry']) ? absint($assoc_args['entry']) : 0;
        if (! $entry_id) {
            $entry_id = isset($args[0]) ? absint($args[0]) : 0;
        }

        if (! $entry_id) {
            \WP_CLI::error('Please provide a Fluent Forms submission ID.');
            return;
        }

        if (! function_exists('fluentFormApi')) {
            \WP_CLI::error('Fluent Forms is not available.');
            return;
        }

        $submission = fluentFormApi('submissions')->find($entry_id);
        if (! $submission) {
            \WP_CLI::error('Submission #' . $entry_id . ' could not be found.');
            return;
        }

        $form_id = isset($submission->form_id) ? absint($submission->form_id) : 0;
        if (! $form_id) {
            \WP_CLI::error('Submission #' . $entry_id . ' does not have a form ID attached.');
            return;
        }

        $form = fluentFormApi('forms')->find($form_id);
        if (! $form) {
            \WP_CLI::error('Fluent Form #' . $form_id . ' could not be loaded.');
            return;
        }

        $response = $submission->response ?? [];
        if (is_string($response)) {
            $response = json_decode($response, true);
        }

        if (is_object($response)) {
            $response = (array) $response;
        }

        $form_data = is_array($response) ? $response : [];

        if (! isset($assoc_args['keep-existing-meta'])) {
            $this->clear_assessment_reports_meta($entry_id, isset($assoc_args['reset-ai']));
        } elseif (isset($assoc_args['reset-ai'])) {
            $this->clear_ai_meta($entry_id);
        }

        do_action('fluentform_submission_inserted', $entry_id, $form_data, $form);
        do_action('fluentform/submission_inserted', $entry_id, $form_data, $form);

        $summary = [
            'entry_id' => $entry_id,
            'report_id' => ar_get_report_id_by_entry_id($entry_id),
            'report_mode' => ar_get_report_mode_by_entry_id($entry_id),
            'top_sections_count' => count((array) get_top_sections_by_entry_id($entry_id)),
            'selected_section_ids' => ar_get_selected_section_ids_by_entry_id($entry_id),
            'has_score_payload' => ! empty(ar_get_score_payload_by_entry_id($entry_id)),
            'has_section_scores' => ! empty(ar_get_section_scores_by_entry_id($entry_id)),
            'ai_status' => Helper::getSubmissionMeta($entry_id, 'ai_generation_status'),
        ];

        \WP_CLI::success('Reprocessed submission #' . $entry_id . ' through the Assessment Reports handlers.');
        \WP_CLI::line(wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Simulate a Fluent Forms submission for a given form ID with provided JSON data.
     * This bypasses frontend validation/nonces and inserts directly, then fires submission hooks.
     *
     * ## OPTIONS
     *
     * --form=<id>
     * : The Fluent Forms form ID.
     *
     * --data=<json>
     * : JSON string of field values, e.g. '{"email":"test@example.com","first_name":"Test"}'
     *
     * ## EXAMPLES
     * wp assessment-reports test-submit --form=12 --data='{"email":"test@example.com","first_name":"Test","last_name":"User"}'
     */
    public function test_submit($args, $assoc_args)
    {
        $form_id = isset($assoc_args['form']) ? absint($assoc_args['form']) : 0;
        $data_json = $assoc_args['data'] ?? '';

        if (! $form_id || ! $data_json) {
            \WP_CLI::error('Usage: wp assessment-reports test-submit --form=<id> --data=\'{"field":"value"}\'');
            return;
        }

        $data = json_decode($data_json, true);
        if (! is_array($data)) {
            \WP_CLI::error('Invalid JSON for --data');
            return;
        }

        if (! function_exists('fluentFormApi')) {
            \WP_CLI::error('Fluent Forms is not available.');
            return;
        }

        $form = fluentFormApi('forms')->find($form_id);
        if (! $form) {
            \WP_CLI::error('Form #' . $form_id . ' not found.');
            return;
        }

        // Insert submission directly to avoid nonce/recaptcha/akismet requirements in CLI.
        $now = current_time('mysql');
        $insert_id = Submission::insertGetId([
            'form_id'      => $form_id,
            'user_id'      => get_current_user_id(),
            'status'       => 'published',
            'response'     => wp_json_encode($data),
            'source_url'   => home_url('/cli-test'),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        if (! $insert_id) {
            \WP_CLI::error('Failed to insert submission.');
            return;
        }

        $hash = md5(wp_generate_uuid4() . $insert_id);
        Helper::setSubmissionMeta($insert_id, '_entry_uid_hash', $hash, $form_id);

        // Let Fluent Forms post-processing record details and fire actions.
        (new \FluentForm\App\Services\Form\SubmissionHandlerService())->processSubmissionData($insert_id, $data, $form);

        \WP_CLI::success(sprintf(
            'Created submission #%d with hash %s',
            $insert_id,
            $hash ?: '(none)'
        ));

        if ($insert_id) {
            $link = get_report_link_by_entry_id($insert_id, '');
            if ($link) {
                \WP_CLI::log('Report URL: ' . $link);
            }
        }
    }

    /**
     * Re-run submission inserted actions for an existing entry.
     *
     * ## OPTIONS
     *
     * --entry=<id>
     * : The Fluent Forms submission ID.
     *
     * ## EXAMPLES
     * wp assessment-reports rerun-actions --entry=123
     */
    public function rerun_actions($args, $assoc_args)
    {
        $entry_id = isset($assoc_args['entry']) ? absint($assoc_args['entry']) : 0;
        if (! $entry_id) {
            \WP_CLI::error('Please provide --entry=<id>.');
            return;
        }

        if (! function_exists('fluentFormApi')) {
            \WP_CLI::error('Fluent Forms is not available.');
            return;
        }

        $entry = fluentFormApi('submissions')->find($entry_id);
        if (! $entry) {
            \WP_CLI::error('Submission #' . $entry_id . ' not found.');
            return;
        }

        $form = fluentFormApi('forms')->find($entry->form_id);
        if (! $form) {
            \WP_CLI::error('Form #' . $entry->form_id . ' not found.');
            return;
        }

        $response_data = $entry->response ?? [];
        if (is_string($response_data)) {
            $response_data = json_decode($response_data, true);
        }
        if (is_object($response_data)) {
            $response_data = (array) $response_data;
        }
        if (! is_array($response_data)) {
            $response_data = [];
        }

        do_action('fluentform_submission_inserted', $entry_id, $response_data, $form);
        do_action('fluentform/submission_inserted', $entry_id, $response_data, $form);

        \WP_CLI::success('Re-ran submission actions for entry #' . $entry_id . '.');
    }

    /**
     * Manually fire the AI-generation-completed hook for an entry.
     *
     * ## OPTIONS
     *
     * --entry=<id>
     * : The Fluent Forms submission ID.
     *
     * ## EXAMPLES
     * wp assessment-reports trigger-complete --entry=123
     */
    public function trigger_complete($args, $assoc_args)
    {
        $entry_id = isset($assoc_args['entry']) ? absint($assoc_args['entry']) : 0;
        if (! $entry_id) {
            \WP_CLI::error('Please provide --entry=<id>.');
            return;
        }

        $entry = fluentFormApi('submissions')->find($entry_id);
        if (! $entry) {
            \WP_CLI::error('Submission #' . $entry_id . ' not found.');
            return;
        }

        $report_id = ar_get_report_id_by_entry_id($entry_id);
        if (! $report_id) {
            \WP_CLI::error('No report found for entry #' . $entry_id . '.');
            return;
        }

        $hash = Helper::getSubmissionMeta($entry_id, '_entry_uid_hash');
        if (! $hash) {
            $hash = ar_encode_entry_hash($entry_id);
        }

        do_action('assessment_reports_ai_generation_completed', $report_id, $entry_id, $hash);
        \WP_CLI::success(sprintf(
            'Triggered assessment_reports_ai_generation_completed for entry #%d (report #%d, hash %s)',
            $entry_id,
            $report_id,
            $hash
        ));
    }

    /**
     * Dump the stored score payload and selected sections for an entry.
     *
     * ## OPTIONS
     *
     * --entry=<id>
     * : The Fluent Forms submission ID.
     */
    public function dump_score_payload($args, $assoc_args)
    {
        $entry_id = isset($assoc_args['entry']) ? absint($assoc_args['entry']) : 0;
        if (! $entry_id) {
            \WP_CLI::error('Please provide --entry=<id>.');
            return;
        }

        $mode = ar_get_report_mode_by_entry_id($entry_id);
        $report_id = ar_get_report_id_by_entry_id($entry_id);
        $payload = ar_get_score_payload_by_entry_id($entry_id);
        $section_ids = ar_get_selected_section_ids_by_entry_id($entry_id);

        \WP_CLI::log('mode: ' . $mode);
        \WP_CLI::log('report_id: ' . ($report_id ?: '(none)'));
        \WP_CLI::log('selected_section_ids: ' . wp_json_encode($section_ids));

        if (empty($payload)) {
            \WP_CLI::warning('No score payload stored for this entry.');
            return;
        }

        \WP_CLI::line(wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Remove report-related submission meta before rebuilding it.
     *
     * @param int  $entry_id
     * @param bool $reset_ai
     * @return void
     */
    private function clear_assessment_reports_meta($entry_id, $reset_ai = false)
    {
        $entry_id = absint($entry_id);

        foreach ($this->get_report_meta_keys() as $meta_key) {
            $this->delete_submission_meta($entry_id, $meta_key);
        }

        if ($reset_ai) {
            $this->clear_ai_meta($entry_id);
        }
    }

    /**
     * Remove AI-related submission meta.
     *
     * @param int $entry_id
     * @return void
     */
    private function clear_ai_meta($entry_id)
    {
        $entry_id = absint($entry_id);

        foreach ($this->get_ai_meta_keys() as $meta_key) {
            $this->delete_submission_meta($entry_id, $meta_key);
        }
    }

    /**
     * Delete a submission meta row for an entry.
     *
     * @param int    $entry_id
     * @param string $meta_key
     * @return void
     */
    private function delete_submission_meta($entry_id, $meta_key)
    {
        $entry_id = absint($entry_id);
        $meta_key = sanitize_text_field((string) $meta_key);

        if (! $entry_id || $meta_key === '') {
            return;
        }

        if (class_exists('\FluentForm\App\Models\SubmissionMeta')) {
            \FluentForm\App\Models\SubmissionMeta::where('response_id', $entry_id)
                ->where('meta_key', $meta_key)
                ->delete();
            return;
        }

        global $wpdb;

        $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prefix . 'fluentform_submission_meta',
            [
                'response_id' => $entry_id,
                'meta_key' => $meta_key,
            ],
            [
                '%d',
                '%s',
            ]
        );
    }

    /**
     * Report meta keys rebuilt during submission processing.
     *
     * @return array<int, string>
     */
    private function get_report_meta_keys()
    {
        return [
            'ar_report_mode',
            'ar_score_profile_id',
            'ar_report_id',
            'ar_selected_section_ids',
            'ar_score_payload',
            'ar_section_scores',
            'top_report_sections',
        ];
    }

    /**
     * AI meta keys optionally reset during reprocessing.
     *
     * @return array<int, string>
     */
    private function get_ai_meta_keys()
    {
        return [
            'ai_generation_status',
            'ai_generation_error',
            'ai_generation_enqueued',
            'ai_generated_content',
        ];
    }
}
