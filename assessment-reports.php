<?php
/**
 * Plugin Name: Assessment Reports
 * Description: Maps Fluent Forms quiz responses to dynamic report sections and surfaces personalized report content.
 * Version: 1.0.1
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

if (! defined('ASSESSMENT_REPORTS_VERSION')) {
    define('ASSESSMENT_REPORTS_VERSION', '1.0.1');
}

if (file_exists(ASSESSMENT_REPORTS_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'vendor/autoload.php';
}

if (! function_exists('as_enqueue_async_action')) {
    $as_bootstrap = ASSESSMENT_REPORTS_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
    if (file_exists($as_bootstrap)) {
        require_once $as_bootstrap;
    }
}

require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-post-type.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-meta-box.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-submission-handler.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-report-display.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-cli.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-settings.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-ai-generator.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-content-filters.php';
require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-fluentform-smartcodes.php';
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
    new \AssessmentReports\FluentForm_Smartcodes();
});

add_action('fluentform_loaded', function ($app = null) {
    if (
        class_exists('\FluentForm\App\Services\Integrations\IntegrationManager') &&
        class_exists('\FluentForm\Framework\Foundation\Application')
    ) {
        assessment_reports_bootstrap_fcrm_integration($app);
    }
});

add_action('plugins_loaded', function () {
    if (
        class_exists('\FluentForm\App\Services\Integrations\IntegrationManager') &&
        class_exists('\FluentForm\Framework\Foundation\Application')
    ) {
        assessment_reports_bootstrap_fcrm_integration();
    }
}, 20);

function assessment_reports_bootstrap_fcrm_integration($app = null)
{
    static $booted = false;
    if ($booted) {
        return;
    }

    $application = $app;
    if (! $application instanceof \FluentForm\Framework\Foundation\Application) {
        $application = \FluentForm\Framework\Foundation\Application::getInstance();
    }

    if ($application instanceof \FluentForm\Framework\Foundation\Application) {
        require_once ASSESSMENT_REPORTS_PLUGIN_DIR . 'includes/class-fluentcrm-integration.php';
        new \AssessmentReports\FluentCRM_Integration($application);
        $booted = true;
    }
}
