<?php

namespace AssessmentReports;

use FluentForm\App\Helpers\Helper;

class Submission_Handler
{
    public function __construct()
    {
        add_action('fluentform_submission_inserted', [$this, 'handle_submission'], 10, 3);
    }

    public function handle_submission($entry_id, $form_data, $form)
    {
        $entry_id = absint($entry_id);
        if (! $entry_id || empty($form)) {
            return;
        }

        $form_id = isset($form->id) ? absint($form->id) : 0;
        if (! $form_id) {
            return;
        }

        $report = $this->get_report_by_form($form_id);
        if (! $report) {
            return;
        }

        $sections = get_posts([
            'post_type'      => Post_Type::POST_TYPE,
            'post_parent'    => $report->ID,
            'post_status'    => 'publish',
            'numberposts'    => -1,
        ]);

        if (! $sections) {
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
}
