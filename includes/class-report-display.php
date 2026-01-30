<?php

namespace AssessmentReports;

use FluentForm\App\Helpers\Helper;

class Report_Display
{
    private const SHORTCODE = 'assessment_report';

    public function __construct()
    {
        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
    }

    public function get_entry_hash($entry_id)
    {
        return ar_encode_entry_hash($entry_id);
    }

    public function render_shortcode($atts)
    {
        $entry_hash = get_current_entry_hash();
        $entry_id = $this->get_entry_id_from_request();
        if (! $entry_id) {
            return $this->render_message(__('Report not found.', 'assessment-reports'));
        }

        $report_data = $this->get_top_sections($entry_id);
        if (empty($report_data)) {
            return $this->render_message(__('No report data is available yet.', 'assessment-reports'));
        }

        $parent_id = $report_data[0]['parent_id'] ?? 0;
        $report = get_post($parent_id);
        if (! $report || $report->post_type !== Post_Type::POST_TYPE) {
            return $this->render_message(__('Unable to locate the selected report.', 'assessment-reports'));
        }

        $ai_blocks = get_post_meta($parent_id, '_ai_content_blocks', true);
        if (is_string($ai_blocks)) {
            $decoded_blocks = json_decode($ai_blocks, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_blocks)) {
                $ai_blocks = $decoded_blocks;
            }
        }

        $requires_ai = is_array($ai_blocks) && ! empty($ai_blocks);
        $ai_content = ar_get_ai_generated_content($entry_id);
        $ai_status = \FluentForm\App\Helpers\Helper::getSubmissionMeta($entry_id, 'ai_generation_status');

        if ($requires_ai && (empty($ai_content) || in_array($ai_status, ['pending', 'running'], true))) {
            if (! $this->is_ai_ready()) {
                return $this->render_message(__('AI personalization is not available yet. Please try again later.', 'assessment-reports'));
            }

            return $this->render_loading($entry_hash ?: '');
        }

        $opening_content = apply_filters('the_content', $report->post_content);
        $closing_content = get_post_meta($parent_id, '_report_closing_content', true);
        $closing_content = $closing_content ? apply_filters('the_content', $closing_content) : '';

        $sections_output = '';
        foreach ($report_data as $section) {
            $section_post = get_post($section['section_id'] ?? 0);
            if (! $section_post || $section_post->post_status !== 'publish') {
                continue;
            }

            $sections_output .= '<section class="assessment-report-section" data-score="' . esc_attr($section['score']) . '">';
            $sections_output .= '<h2>' . esc_html($section_post->post_title) . '</h2>';
            $sections_output .= apply_filters('the_content', $section_post->post_content);
            $sections_output .= '</section>';
        }

        if (! $sections_output) {
            $sections_output = '<p class="assessment-report-empty">' . esc_html__('We could not match any sections for your submission.', 'assessment-reports') . '</p>';
        }

        $output = '<div class="assessment-report-wrapper">';
        $output .= '<div class="assessment-report-opening">' . $opening_content . '</div>';
        $output .= $sections_output;
        if ($closing_content) {
            $output .= '<div class="assessment-report-closing">' . $closing_content . '</div>';
        }
        $output .= '</div>';

        return $output;
    }

    private function get_entry_id_from_request()
    {
        return ar_get_entry_id_from_hash();
    }

    private function get_top_sections($entry_id)
    {
        $raw = Helper::getSubmissionMeta($entry_id, 'top_report_sections');
        if (! $raw) {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            return $decoded;
        }

        return is_array($raw) ? $raw : [];
    }

    private function render_message($message)
    {
        return '<p class="assessment-report-message">' . esc_html($message) . '</p>';
    }

    private function render_loading($entry_hash)
    {
        $this->enqueue_ai_loader_assets($entry_hash);

        return '<div class="assessment-report-loading" data-entry-hash="' . esc_attr($entry_hash) . '">' .
            '<div class="ar-spinner" aria-hidden="true"></div>' .
            '<p>' . esc_html__('Generating your personalized report…', 'assessment-reports') . '</p>' .
            '</div>';
    }

    private function enqueue_ai_loader_assets($entry_hash)
    {
        $js_path = ASSESSMENT_REPORTS_PLUGIN_DIR . 'assets/report-ai.js';
        $css_path = ASSESSMENT_REPORTS_PLUGIN_DIR . 'assets/report-ai.css';

        if (file_exists($css_path)) {
            wp_enqueue_style(
                'assessment-reports-report-ai',
                ASSESSMENT_REPORTS_PLUGIN_URL . 'assets/report-ai.css',
                [],
                filemtime($css_path)
            );
        }

        if (file_exists($js_path)) {
            wp_enqueue_script(
                'assessment-reports-report-ai',
                ASSESSMENT_REPORTS_PLUGIN_URL . 'assets/report-ai.js',
                [],
                filemtime($js_path),
                true
            );

            wp_localize_script('assessment-reports-report-ai', 'AssessmentReportsAI', [
                'entryHash' => $entry_hash,
                'generateUrl' => rest_url('assessment-reports/v1/ai-generate'),
                'statusUrl' => rest_url('assessment-reports/v1/ai-status'),
            ]);
        }
    }

    private function is_ai_ready()
    {
        return class_exists('\WordPress\\AI_Client\\AI_Client') || class_exists('\WP_AI_Client');
    }
}
