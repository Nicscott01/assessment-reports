<?php

namespace AssessmentReports;

use Exception;
use FluentForm\App\Helpers\Helper;

if (! defined('ABSPATH')) {
    exit;
}

class AI_Generator
{
    public function __construct()
    {
        add_action('template_redirect', [$this, 'maybe_generate_ai_content']);
    }

    public function maybe_generate_ai_content()
    {
        error_log('AR AI: template_redirect fired.');

        if (! is_singular(Post_Type::POST_TYPE)) {
            error_log('AR AI: not singular assessment_report.');
            return;
        }

        if (! has_entry_hash()) {
            error_log('AR AI: missing entry hash param.');
            return;
        }

        $entry_hash = get_current_entry_hash();
        $entry_id = ar_get_entry_id_from_hash($entry_hash);
        if (! $entry_id) {
            error_log('AR AI: invalid entry hash. hash=' . $entry_hash);
            return;
        }

        $ai_content = ar_get_ai_generated_content($entry_id);
        if ($ai_content) {
            error_log('AR AI: existing AI content found for entry_id=' . $entry_id);
        }

        if (! $ai_content && ! $this->is_ai_ready()) {
            error_log('AR AI: AI client not ready.');
        }

        if (! $ai_content && $this->is_ai_ready()) {
            $report_id = get_the_ID();
            error_log('AR AI: generating content. report_id=' . $report_id . ' entry_id=' . $entry_id);
            $ai_content = $this->generate_ai_content($report_id, $entry_id);
            if ($ai_content) {
                error_log('AR AI: generated content, saving.');
                ar_set_ai_generated_content($entry_id, $ai_content);
                Helper::setSubmissionMeta($entry_id, 'ai_generation_timestamp', time());
            } else {
                error_log('AR AI: generation returned empty content.');
            }
        }

        set_transient('ar_current_ai_content_' . $entry_id, $ai_content, HOUR_IN_SECONDS);
        set_transient('ar_current_entry_id', $entry_id, HOUR_IN_SECONDS);
    }

    private function generate_ai_content($report_id, $entry_id)
    {
        $ai_blocks = get_post_meta($report_id, '_ai_content_blocks', true);
        if (! is_array($ai_blocks) || empty($ai_blocks)) {
            return [];
        }

        try {
            $entry = fluentFormApi('submissions')->find($entry_id);
        } catch (Exception $exception) {
            return [];
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

        $temperature = get_option('ar_ai_temperature', 0.7);
        $tone = get_option('ar_ai_tone', 'Professional');
        $voice = get_option('ar_ai_voice', 'Second Person');
        $max_tokens = get_option('ar_ai_max_tokens', 500);
        $additional_instructions = get_option('ar_ai_additional_instructions', '');

        $settings = [
            'tone' => $tone,
            'voice' => $voice,
            'additional_instructions' => $additional_instructions,
        ];

        $generated = [];

        foreach ($ai_blocks as $block) {
            $token = $block['token'] ?? '';
            if (! $token) {
                continue;
            }

            $prompt = $this->build_prompt($block, $response_data, $entry, $settings, $entry_id);
            try {
                $text = $this->call_ai($prompt, $temperature, $max_tokens);
                if ($text) {
                    $generated[$token] = trim($text);
                    continue;
                }
            } catch (Exception $e) {
                error_log('Assessment Reports AI error: ' . $e->getMessage());
            }

            $generated[$token] = $block['example'] ?? '';
        }

        return $generated;
    }

    private function build_prompt($block, array $response_data, $entry, array $settings, $entry_id)
    {
        $prompt = "You are writing personalized content for an assessment report. Adapt the example content based on the user's specific responses while maintaining tone and structure.\n\n";
        $prompt .= "EXAMPLE CONTENT:\n" . ($block['example'] ?? '') . "\n\n";
        $prompt .= "PERSONALIZATION INSTRUCTIONS:\n" . ($block['instructions'] ?? '') . "\n\n";
        $prompt .= "USER'S RESPONSES:\n";

        if (! empty($block['context_fields']) && is_array($block['context_fields'])) {
            foreach ($block['context_fields'] as $field_name) {
            $value = $response_data[$field_name] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $label = ar_get_field_label($entry->form_id, $field_name);
            $prompt .= '- ' . $label . ': ' . $value . "\n";
        }
        }

        if (! empty($block['include_score'])) {
            $score = ar_get_quiz_score($entry_id);
            if ($score !== null) {
                $prompt .= '- Overall Assessment Score: ' . $score . "\n";
            }
        }

        if (! empty($block['additional_context'])) {
            $prompt .= "\nADDITIONAL CONTEXT:\n" . $block['additional_context'] . "\n\n";
        }

        $prompt .= "\nTONE: " . $settings['tone'] . "\n";
        $prompt .= "VOICE: " . $settings['voice'] . "\n\n";

        if (! empty($settings['additional_instructions'])) {
            $prompt .= $settings['additional_instructions'] . "\n\n";
        }

        $prompt .= "Generate the personalized content now. Output only the final content, no preamble or explanation.";

        return $prompt;
    }

    private function call_ai($prompt, $temperature, $max_tokens)
    {
        if (class_exists('\WordPress\\AI_Client\\AI_Client')) {
            $builder = \WordPress\AI_Client\AI_Client::prompt($prompt)
                ->using_temperature((float) $temperature)
                ->using_max_tokens((int) $max_tokens);

            return $builder->generate_text();
        }

        if (class_exists('\WP_AI_Client')) {
            $client = new \WP_AI_Client();
            if (! method_exists($client, 'generate')) {
                throw new Exception('WP AI Client missing generate method.');
            }

            $response = $client->generate([
                'prompt' => $prompt,
                'temperature' => (float) $temperature,
                'max_tokens' => (int) $max_tokens,
            ]);

            if (is_string($response)) {
                return $response;
            }

            if (is_object($response)) {
                if (method_exists($response, 'get_text')) {
                    return $response->get_text();
                }
                if (isset($response->text)) {
                    return $response->text;
                }
            }

            throw new Exception('Unrecognized AI response format.');
        }

        throw new Exception('WP AI Client is not available.');
    }

    private function is_ai_ready()
    {
        return class_exists('\WordPress\\AI_Client\\AI_Client') || class_exists('\WP_AI_Client');
    }
}
