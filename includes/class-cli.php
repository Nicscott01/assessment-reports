<?php

namespace AssessmentReports;

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
    }

    public function reprocess_entry($args)
    {
        $entry_id = isset($args[0]) ? absint($args[0]) : 0;
        if (! $entry_id) {
            \WP_CLI::error('Please provide a Fluent Forms submission ID.');
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

        do_action('fluentform_submission_inserted', $entry_id, $form_data, $form);
        do_action('fluentform/submission_inserted', $entry_id, $form_data, $form);

        \WP_CLI::success('Reprocessed submission #' . $entry_id . ' through the Assessment Reports handlers.');
    }
}
