<?php

namespace AssessmentReports;

use FluentForm\App\Helpers\Helper;

class Submission_Handler
{
    public function __construct()
    {
        add_action('fluentform_submission_inserted', [$this, 'handle_submission'], 10, 3);
        add_action('fluentform/submission_inserted', [$this, 'handle_submission'], 10, 3);
    }

    public function handle_submission($entry_id, $form_data, $form)
    {
        $entry_id = absint($entry_id);
        if (! $entry_id || empty($form)) {
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
            error_log(sprintf(
                'AR debug entry=%d form=%d',
                $entry_id,
                isset($form->id) ? absint($form->id) : 0
            ));
        }

        $form_id = isset($form->id) ? absint($form->id) : 0;
        if (! $form_id) {
            return;
        }

        $report = $this->get_report_by_form($form_id);
        if (! $report) {
            $this->log_debug('no report', $entry_id, $form_id);
            return;
        }

        $sections = get_posts([
            'post_type'      => Post_Type::POST_TYPE,
            'post_parent'    => $report->ID,
            'post_status'    => 'publish',
            'numberposts'    => -1,
        ]);

        if (! $sections) {
            $this->log_debug('no sections', $entry_id, $form_id, ['report_id' => $report->ID]);
            return;
        }

        $normalized_data = $this->normalize_submission_data($form_data);
        $section_scores = [];

        foreach ($sections as $section) {
            $mappings = get_post_meta($section->ID, '_field_mappings', true);
            if (! is_array($mappings)) {
                continue;
            }

            $score = $this->calculate_section_score($normalized_data, $mappings);
            if ($score <= 0) {
                continue;
            }

            $section_scores[] = [
                'section_id' => $section->ID,
                'score'      => $score,
                'parent_id'  => $report->ID,
            ];
        }

        if (! $section_scores) {
            $this->log_debug('no matches', $entry_id, $form_id, ['report_id' => $report->ID]);
            return;
        }

        usort($section_scores, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $top_sections = array_slice($section_scores, 0, 3);
        $encoded = wp_json_encode($top_sections);
        if ($encoded) {
            Helper::setSubmissionMeta($entry_id, 'top_report_sections', $encoded, $form_id);
        }

        // Kick off AI generation asynchronously if not already completed.
        $ai_status = Helper::getSubmissionMeta($entry_id, 'ai_generation_status');
        if (! in_array($ai_status, ['ready', 'running'], true)) {
            Helper::setSubmissionMeta($entry_id, 'ai_generation_status', 'pending', $form_id);
            Helper::setSubmissionMeta($entry_id, 'ai_generation_error', '', $form_id);
            // Prevent duplicate queueing across hooks/requests.
            $already_enqueued = Helper::getSubmissionMeta($entry_id, 'ai_generation_enqueued');
            if (! did_action('assessment_reports_ai_enqueued_' . $entry_id) && $already_enqueued !== 'yes') {
                ar_enqueue_ai_generation($report->ID, $entry_id);
                do_action('assessment_reports_ai_enqueued_' . $entry_id);
                Helper::setSubmissionMeta($entry_id, 'ai_generation_enqueued', 'yes', $form_id);
            }

            if (! did_action('assessment_reports_pending_contact_' . $entry_id)) {
                do_action('assessment_reports_submission_pending', $entry_id, $form_data, $form);
                do_action('assessment_reports_pending_contact_' . $entry_id);
            }
        }
    }

    private function get_report_by_form($form_id)
    {
        $reports = get_posts([
            'post_type'   => Post_Type::POST_TYPE,
            'post_parent' => 0,
            'post_status' => 'publish',
            'numberposts' => 1,
            'meta_key'    => '_report_form_id',
            'meta_value'  => $form_id,
        ]);

        return $reports ? $reports[0] : null;
    }

    private function log_debug($message, $entry_id, $form_id, array $context = [])
    {
        if (! (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log'))) {
            return;
        }

        $context['entry_id'] = $entry_id;
        $context['form_id'] = $form_id;
        $context_string = json_encode($context);

        error_log(sprintf('Assessment Reports debug: %s | %s', $message, $context_string));
    }

    private function calculate_section_score(array $submission_data, array $mappings)
    {
        $score = 0;

        foreach ($mappings as $field_name => $choices) {
            if (! isset($submission_data[$field_name])) {
                continue;
            }

            $submitted = $submission_data[$field_name];
            if (is_null($submitted)) {
                continue;
            }

            $submitted_values = is_array($submitted) ? $submitted : [$submitted];
            foreach ($submitted_values as $value) {
                if ($value === '' || $value === null) {
                    continue;
                }

                $value = (string) $value;
                if (! isset($choices[$value])) {
                    continue;
                }

                $score += absint($choices[$value]);
            }
        }

        return $score;
    }

    private function normalize_submission_data($form_data)
    {
        if (is_object($form_data)) {
            $form_data = (array) $form_data;
        }

        if (! is_array($form_data)) {
            return [];
        }

        foreach ($form_data as $key => &$value) {
            if (is_object($value)) {
                $value = (array) $value;
            }
        }

        return $form_data;
    }




    /**
     * Get submission ID from entry UID hash
     *
     * @param string $hash The _entry_uid_hash value
     * @return int|null The submission ID or null if not found
     */
    private function get_submission_id_by_hash($hash)
    {
        if (! $hash) {
            return null;
        }

        // Query the submission meta table for the hash
        $meta = \FluentForm\App\Models\SubmissionMeta::where('meta_key', '_entry_uid_hash')
            ->where('value', $hash)
            ->first();

        if ($meta && isset($meta->response_id)) {
            return absint($meta->response_id);
        }

        return null;
    }

    /**
     * Get top report sections by entry hash
     *
     * @param string $hash The _entry_uid_hash value
     * @return array|null The top report sections data or null if not found
     */
    public function get_top_sections_by_hash($hash)
    {
        $entry_id = $this->get_submission_id_by_hash($hash);
        
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
    
}
