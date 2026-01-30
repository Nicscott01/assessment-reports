<?php
/**
 * Plugin Name: Assessment Reports
 * Description: Maps Fluent Forms quiz responses to dynamic report sections and surfaces personalized report content.
 * Version: 1.0.0
 * Author: Nic Scott
 * Text Domain: assessment-reports
 * License: GPL-2.0+
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('ASSESSMENT_REPORTS_PLUGIN_DIR')) {
    define('ASSESSMENT_REPORTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (! defined('ASSESSMENT_REPORTS_PLUGIN_URL')) {
    define('ASSESSMENT_REPORTS_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (file_exists(ASSESSMENT_REPORTS_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-post-type.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-meta-box.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-submission-handler.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-report-display.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-cli.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-settings.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-ai-generator.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-content-filters.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/helper-functions.php';

add_action('init', function () {
    // Initialize the WordPress AI Client package
    \WordPress\AI_Client\AI_Client::init();
});

add_action('plugins_loaded', function () {
    new \AssessmentReports\Post_Type();
    new \AssessmentReports\Meta_Box();
    new \AssessmentReports\Submission_Handler();
    new \AssessmentReports\Report_Display();
    new \AssessmentReports\CLI_Command();
    new \AssessmentReports\Settings_Page();
    new \AssessmentReports\AI_Generator();
    new \AssessmentReports\Content_Filters();
});
