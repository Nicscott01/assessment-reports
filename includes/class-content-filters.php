<?php

namespace AssessmentReports;

if (! defined('ABSPATH')) {
    exit;
}

class Content_Filters
{
    public function __construct()
    {
        add_filter('the_content', [$this, 'replace_content_tokens'], 999);
        add_filter('get_post_metadata', [$this, 'replace_meta_tokens'], 10, 4);
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
}
