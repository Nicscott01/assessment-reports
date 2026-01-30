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

    public function render_shortcode($atts)
    {
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

    public function get_entry_hash($entry_id)
    {
        $entry_id = absint($entry_id);
        if (! $entry_id) {
            return '';
        }

        $payload = $entry_id . '|' . $this->build_signature($entry_id);
        return strtr(base64_encode($payload), '+/=', '-_,');
    }

    private function get_entry_id_from_request()
    {
        if (empty($_GET['entry'])) {
            return 0;
        }

        $hash = sanitize_text_field(wp_unslash($_GET['entry']));
        return $this->decode_entry_hash($hash);
    }

    private function decode_entry_hash($hash)
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

        if (! hash_equals($this->build_signature($entry_id), $signature)) {
            return 0;
        }

        return absint($entry_id);
    }

    private function build_signature($entry_id)
    {
        return hash_hmac('sha256', (string) $entry_id, $this->get_hash_salt());
    }

    private function get_hash_salt()
    {
        if (defined('ASSESSMENT_REPORT_HASH_SALT')) {
            return ASSESSMENT_REPORT_HASH_SALT;
        }

        return wp_salt('assessment_reports');
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
}
