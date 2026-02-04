<?php

namespace AssessmentReports;

if (! defined('ABSPATH')) {
    exit;
}

class Content_Filters
{
    private $show_loader = false;
    private $loader_entry_hash = '';

    public function __construct()
    {
        add_filter('the_content', [$this, 'replace_content_tokens'], 999);
        add_filter('get_post_metadata', [$this, 'replace_meta_tokens'], 10, 4);
        add_action('wp_footer', [$this, 'render_loader_modal']);
    }

    public function replace_content_tokens($content)
    {
        if (! $this->should_replace()) {
            return $content;
        }

        $entry_id = $this->get_current_entry_id();
        if (! $entry_id) {
            return $content;
        }

        $loading = $this->get_loading_markup($entry_id, get_the_ID());
        if ($loading !== null) {
            return $content;
        }

        $ai_content = $this->get_ai_content_for_entry($entry_id);
        if (empty($ai_content)) {
            return $content;
        }

        foreach ($ai_content as $token => $generated_text) {
            $content = str_replace('{ai.' . $token . '}', $generated_text, $content);
        }

        return $content;
    }

    public function replace_meta_tokens($value, $object_id, $meta_key, $single)
    {
        if ($meta_key !== '_report_closing_content') {
            return $value;
        }

        if (! $this->should_replace()) {
            return $value;
        }

        $entry_id = $this->get_current_entry_id();
        if (! $entry_id) {
            return $value;
        }

        $loading = $this->get_loading_markup($entry_id, $object_id, true);
        if ($loading !== null) {
            return $value;
        }

        $ai_content = $this->get_ai_content_for_entry($entry_id);
        if (empty($ai_content)) {
            return $value;
        }

        remove_filter('get_post_metadata', [$this, 'replace_meta_tokens'], 10, 4);
        $actual_value = get_post_meta($object_id, $meta_key, $single);
        add_filter('get_post_metadata', [$this, 'replace_meta_tokens'], 10, 4);

        if (! is_string($actual_value)) {
            return $value;
        }

        foreach ($ai_content as $token => $generated_text) {
            $actual_value = str_replace('{ai.' . $token . '}', $generated_text, $actual_value);
        }

        return $actual_value;
    }

    private function should_replace()
    {
        if (! is_singular(Post_Type::POST_TYPE)) {
            return false;
        }

        if (! has_entry_hash()) {
            return false;
        }

        return true;
    }

    private function get_current_entry_id()
    {
        $entry_id = get_transient('ar_current_entry_id');
        if ($entry_id) {
            return absint($entry_id);
        }

        return ar_get_entry_id_from_hash();
    }

    private function get_ai_content_for_entry($entry_id)
    {
        $content = get_transient('ar_current_ai_content_' . $entry_id);
        if ($content && is_array($content)) {
            return $content;
        }

        return ar_get_ai_generated_content($entry_id);
    }

    private function get_loading_markup($entry_id, $post_id, $for_meta = false)
    {
        $entry_id = absint($entry_id);
        $post_id = absint($post_id);
        if (! $entry_id || ! $post_id) {
            return null;
        }

        $report_id = $this->get_report_id_for_post($post_id);
        if (! $report_id) {
            return null;
        }

        $entry_report_id = ar_get_report_id_by_entry_id($entry_id);
        if ($entry_report_id && $entry_report_id !== $report_id) {
            return null;
        }

        $ai_blocks = get_post_meta($report_id, '_ai_content_blocks', true);
        if (is_string($ai_blocks)) {
            $decoded = json_decode($ai_blocks, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $ai_blocks = $decoded;
            }
        }

        $requires_ai = is_array($ai_blocks) && ! empty($ai_blocks);
        if (! $requires_ai) {
            return null;
        }

        if (! $this->is_ai_ready()) {
            return $for_meta ? null : $this->render_message(__('AI personalization is not available yet. Please try again later.', 'assessment-reports'));
        }

        $ai_content = ar_get_ai_generated_content($entry_id);
        $status = \FluentForm\App\Helpers\Helper::getSubmissionMeta($entry_id, 'ai_generation_status');

        if (! empty($ai_content) && ! in_array($status, ['pending', 'running', 'failed'], true)) {
            return null;
        }

        if ($for_meta) {
            return null;
        }

        $this->show_loader = true;
        $this->loader_entry_hash = get_current_entry_hash() ?: '';
        $this->enqueue_ai_loader_assets($this->loader_entry_hash);

        return '';
    }

    private function get_report_id_for_post($post_id)
    {
        $post = get_post($post_id);
        if (! $post || $post->post_type !== Post_Type::POST_TYPE) {
            return 0;
        }

        if ($post->post_parent) {
            return absint($post->post_parent);
        }

        return absint($post->ID);
    }

    private function enqueue_ai_loader_assets($entry_hash)
    {
        $js_path = ASSESSMENT_REPORTS_PLUGIN_DIR . 'assets/report-ai.js';
        $css_path = ASSESSMENT_REPORTS_PLUGIN_DIR . 'assets/report-ai.css';
        $asset_version = defined('ASSESSMENT_REPORTS_VERSION') ? ASSESSMENT_REPORTS_VERSION : null;

        if (file_exists($css_path)) {
            wp_enqueue_style(
                'assessment-reports-report-ai',
                ASSESSMENT_REPORTS_PLUGIN_URL . 'assets/report-ai.css',
                [],
                $asset_version
            );
        }

        if (file_exists($js_path)) {
            wp_enqueue_script(
                'assessment-reports-report-ai',
                ASSESSMENT_REPORTS_PLUGIN_URL . 'assets/report-ai.js',
                [],
                $asset_version,
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

    public function render_loader_modal()
    {
        if (! $this->show_loader) {
            return;
        }

        $entry_hash = $this->loader_entry_hash;
        echo '<div class="assessment-report-overlay" data-entry-hash="' . esc_attr($entry_hash) . '" role="status" aria-live="polite">' .
            '<div class="assessment-report-overlay__inner">' .
            '<div class="ar-spinner" aria-hidden="true"></div>' .
            '<p>' . esc_html__('Generating your personalized report…', 'assessment-reports') . '</p>' .
            '</div>' .
            '</div>';
    }
}
