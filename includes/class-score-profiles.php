<?php

namespace AssessmentReports;

if (! defined('ABSPATH')) {
    exit;
}

class Score_Profiles
{
    public const OPTION_NAME = 'ar_score_profiles';
    private const PAGE_SLUG = 'assessment-reports-score-profiles';
    private const NOTICE_TRANSIENT = 'ar_score_profiles_notice';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'handle_form_submission']);
    }

    public function register_menu()
    {
        $parent = 'edit.php?post_type=' . Post_Type::POST_TYPE;
        add_submenu_page(
            $parent,
            esc_html__('Assessment Report Score Profiles', 'assessment-reports'),
            esc_html__('Score Profiles', 'assessment-reports'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function handle_form_submission()
    {
        if (! is_admin()) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['ar_score_profiles_submit'])) {
            return;
        }

        if (! current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('ar_save_score_profiles', 'ar_score_profiles_nonce');

        $raw_profiles = isset($_POST[ self::OPTION_NAME ]) && is_array($_POST[ self::OPTION_NAME ])
            ? wp_unslash($_POST[ self::OPTION_NAME ])
            : [];

        $sanitized = $this->sanitize_profiles_option($raw_profiles);
        update_option(self::OPTION_NAME, $sanitized, false);

        if (! get_transient(self::NOTICE_TRANSIENT)) {
            set_transient(
                self::NOTICE_TRANSIENT,
                [
                    'type' => 'success',
                    'message' => esc_html__('Score profiles saved.', 'assessment-reports'),
                ],
                MINUTE_IN_SECONDS
            );
        }

        wp_safe_redirect($this->get_page_url());
        exit;
    }

    public function sanitize_profiles_option($profiles)
    {
        if (! is_array($profiles)) {
            return [];
        }

        $sanitized = [];

        foreach ($profiles as $index => $profile) {
            if (! is_array($profile)) {
                continue;
            }

            if (! empty($profile['delete'])) {
                continue;
            }

            $name = isset($profile['name']) ? sanitize_text_field(wp_unslash($profile['name'])) : '';
            $description = isset($profile['description']) ? sanitize_textarea_field(wp_unslash($profile['description'])) : '';
            $raw_definition = isset($profile['definition_json']) ? wp_unslash($profile['definition_json']) : '';
            $raw_id = isset($profile['id']) ? sanitize_key(wp_unslash($profile['id'])) : '';

            if ($name === '') {
                continue;
            }

            $profile_id = $raw_id !== '' ? $raw_id : sanitize_title($name);
            if ($profile_id === '') {
                $profile_id = 'score_profile_' . absint($index + 1);
            }

            $definition = json_decode((string) $raw_definition, true);
            if (! is_array($definition)) {
                set_transient(
                    self::NOTICE_TRANSIENT,
                    [
                        'type' => 'error',
                        'message' => sprintf(
                            /* translators: %s: profile name */
                            esc_html__('Score profile "%s" was skipped because its JSON definition is invalid.', 'assessment-reports'),
                            $name ?: $profile_id
                        ),
                    ],
                    MINUTE_IN_SECONDS
                );
                continue;
            }

            $sanitized[ $profile_id ] = [
                'id' => $profile_id,
                'name' => $name ?: $profile_id,
                'description' => $description,
                'definition' => self::sanitize_definition($definition),
            ];
        }

        return $sanitized;
    }

    public function render_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $notice = get_transient(self::NOTICE_TRANSIENT);
        if (is_array($notice) && ! empty($notice['message'])) {
            add_settings_error(
                self::OPTION_NAME,
                'ar_score_profiles_notice',
                $notice['message'],
                $notice['type'] ?? 'success'
            );
            delete_transient(self::NOTICE_TRANSIENT);
        }

        $profiles = self::get_profiles();
        $rows = array_values($profiles);
        $rows[] = [
            'id' => '',
            'name' => '',
            'description' => '',
            'definition' => self::get_profile_template(),
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Assessment Report Score Profiles', 'assessment-reports'); ?></h1>
            <p>
                <?php esc_html_e('Score-driven reports use a named score profile. Each profile defines the field mappings, chart configuration, summary bands, and section-selection inputs for one assessment model.', 'assessment-reports'); ?>
            </p>
            <p>
                <?php esc_html_e('Each profile definition is stored as JSON so complex scoring models can be edited in wp-admin without changing code.', 'assessment-reports'); ?>
            </p>
            <?php settings_errors(self::OPTION_NAME); ?>
            <form method="post" action="<?php echo esc_url($this->get_page_url()); ?>">
                <?php
                wp_nonce_field('ar_save_score_profiles', 'ar_score_profiles_nonce');
                ?>
                <div class="ar-score-profiles">
                    <?php foreach ($rows as $index => $profile) : ?>
                        <div class="ar-score-profile-card">
                            <h2>
                                <?php
                                echo esc_html(
                                    $profile['name']
                                        ? $profile['name']
                                        : sprintf(
                                            /* translators: %d: profile number */
                                            __('New Score Profile %d', 'assessment-reports'),
                                            $index + 1
                                        )
                                );
                                ?>
                            </h2>
                            <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($profile['id']); ?>">
                            <p>
                                <label class="ar-field-label" for="ar-score-profile-name-<?php echo esc_attr($index); ?>"><?php esc_html_e('Profile Name', 'assessment-reports'); ?></label>
                                <input
                                    id="ar-score-profile-name-<?php echo esc_attr($index); ?>"
                                    type="text"
                                    class="widefat"
                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[<?php echo esc_attr($index); ?>][name]"
                                    value="<?php echo esc_attr($profile['name']); ?>"
                                >
                            </p>
                            <p>
                                <label class="ar-field-label" for="ar-score-profile-description-<?php echo esc_attr($index); ?>"><?php esc_html_e('Description', 'assessment-reports'); ?></label>
                                <textarea
                                    id="ar-score-profile-description-<?php echo esc_attr($index); ?>"
                                    class="widefat"
                                    rows="3"
                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[<?php echo esc_attr($index); ?>][description]"
                                ><?php echo esc_textarea($profile['description']); ?></textarea>
                            </p>
                            <p>
                                <label class="ar-field-label" for="ar-score-profile-definition-<?php echo esc_attr($index); ?>"><?php esc_html_e('Profile Definition JSON', 'assessment-reports'); ?></label>
                                <textarea
                                    id="ar-score-profile-definition-<?php echo esc_attr($index); ?>"
                                    class="widefat code ar-json-editor"
                                    rows="28"
                                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[<?php echo esc_attr($index); ?>][definition_json]"
                                ><?php echo esc_textarea(wp_json_encode($profile['definition'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
                            </p>
                            <?php if (! empty($profile['id'])) : ?>
                                <label class="ar-inline-checkbox">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[<?php echo esc_attr($index); ?>][delete]" value="1">
                                    <?php esc_html_e('Delete this profile on save', 'assessment-reports'); ?>
                                </label>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="ar_score_profiles_submit" value="1">
                <?php submit_button(esc_html__('Save Score Profiles', 'assessment-reports')); ?>
            </form>
            <hr>
            <h2><?php esc_html_e('Definition Notes', 'assessment-reports'); ?></h2>
            <ul>
                <li><?php esc_html_e('`summary.sources` accepts nested value paths such as `maslow.total`, `readiness.score`, or `wellness.values.spiritual`.', 'assessment-reports'); ?></li>
                <li><?php esc_html_e('`field_rules` map a Fluent Forms field to a score path using `value_map` labels and a `path` such as `maslow.physiological`.', 'assessment-reports'); ?></li>
                <li><?php esc_html_e('`charts` define labels, dimensions, max values, and readiness bands for the chart helpers.', 'assessment-reports'); ?></li>
                <li><?php esc_html_e('Section rules reference the stored payload paths, for example `summary.category_key` or `readiness.percent`.', 'assessment-reports'); ?></li>
            </ul>
        </div>
        <?php
    }

    public static function get_profiles()
    {
        $profiles = get_option(self::OPTION_NAME, []);

        return is_array($profiles) ? $profiles : [];
    }

    public static function get_profile($profile_id)
    {
        $profile_id = sanitize_key((string) $profile_id);
        if ($profile_id === '') {
            return null;
        }

        $profiles = self::get_profiles();

        return isset($profiles[ $profile_id ]) && is_array($profiles[ $profile_id ]) ? $profiles[ $profile_id ] : null;
    }

    public static function get_profile_options()
    {
        $options = [];
        foreach (self::get_profiles() as $profile_id => $profile) {
            $options[ $profile_id ] = $profile['name'] ?? $profile_id;
        }

        return $options;
    }

    public static function sanitize_definition(array $definition)
    {
        $template = self::get_profile_template();

        $definition['focus_field'] = isset($definition['focus_field']) ? sanitize_key((string) $definition['focus_field']) : '';

        $summary = isset($definition['summary']) && is_array($definition['summary']) ? $definition['summary'] : [];
        $definition['summary'] = [
            'label' => isset($summary['label']) ? sanitize_text_field((string) $summary['label']) : $template['summary']['label'],
            'max_score' => isset($summary['max_score']) ? (float) $summary['max_score'] : $template['summary']['max_score'],
            'sources' => self::sanitize_sources($summary['sources'] ?? []),
            'bands' => self::sanitize_bands($summary['bands'] ?? []),
        ];

        $charts = isset($definition['charts']) && is_array($definition['charts']) ? $definition['charts'] : [];
        $definition['charts'] = [];
        foreach ($template['charts'] as $chart_key => $defaults) {
            $chart = isset($charts[ $chart_key ]) && is_array($charts[ $chart_key ]) ? $charts[ $chart_key ] : [];
            $definition['charts'][ $chart_key ] = [
                'label' => isset($chart['label']) ? sanitize_text_field((string) $chart['label']) : $defaults['label'],
                'max' => isset($chart['max']) ? (float) $chart['max'] : $defaults['max'],
                'aggregation' => self::sanitize_aggregation($chart['aggregation'] ?? $defaults['aggregation']),
                'dimensions' => self::sanitize_dimensions($chart['dimensions'] ?? $defaults['dimensions']),
                'bands' => self::sanitize_bands($chart['bands'] ?? $defaults['bands']),
            ];
        }

        $rules = isset($definition['field_rules']) && is_array($definition['field_rules']) ? $definition['field_rules'] : [];
        $definition['field_rules'] = self::sanitize_field_rules($rules);

        return $definition;
    }

    public static function get_profile_template()
    {
        return [
            'focus_field' => '',
            'summary' => [
                'label' => 'Summary',
                'max_score' => 100,
                'sources' => [
                    [
                        'path' => 'maslow.total',
                        'weight' => 0.25,
                    ],
                    [
                        'path' => 'wellness.total',
                        'weight' => 0.25,
                    ],
                    [
                        'path' => 'sdoh.total',
                        'weight' => 0.25,
                    ],
                    [
                        'path' => 'readiness.score',
                        'weight' => 0.25,
                    ],
                ],
                'bands' => [
                    [
                        'key' => 'low',
                        'label' => 'Low',
                        'min' => 0,
                        'max' => 39.99,
                    ],
                    [
                        'key' => 'medium',
                        'label' => 'Medium',
                        'min' => 40,
                        'max' => 69.99,
                    ],
                    [
                        'key' => 'high',
                        'label' => 'High',
                        'min' => 70,
                        'max' => 100,
                    ],
                ],
            ],
            'charts' => [
                'maslow' => [
                    'label' => 'Hierarchy of Need',
                    'max' => 100,
                    'aggregation' => 'average',
                    'dimensions' => [
                        [ 'key' => 'physiological', 'label' => 'Physiological' ],
                        [ 'key' => 'safety', 'label' => 'Safety' ],
                        [ 'key' => 'belonging', 'label' => 'Belonging' ],
                        [ 'key' => 'esteem', 'label' => 'Esteem' ],
                        [ 'key' => 'purpose', 'label' => 'Purpose' ],
                    ],
                    'bands' => [],
                ],
                'wellness' => [
                    'label' => 'Your Health',
                    'max' => 5,
                    'aggregation' => 'average',
                    'dimensions' => [
                        [ 'key' => 'social', 'label' => 'Social' ],
                        [ 'key' => 'emotional', 'label' => 'Emotional' ],
                        [ 'key' => 'occupational', 'label' => 'Occupational' ],
                        [ 'key' => 'spiritual', 'label' => 'Spiritual' ],
                        [ 'key' => 'physical', 'label' => 'Physical' ],
                        [ 'key' => 'environmental', 'label' => 'Environmental' ],
                        [ 'key' => 'intellectual', 'label' => 'Intellectual' ],
                        [ 'key' => 'financial', 'label' => 'Financial' ],
                    ],
                    'bands' => [],
                ],
                'sdoh' => [
                    'label' => 'Social Determinants of Health',
                    'max' => 5,
                    'aggregation' => 'average',
                    'dimensions' => [
                        [ 'key' => 'access_to_financial_resources', 'label' => 'Access to Financial Resources' ],
                        [ 'key' => 'supportive_community', 'label' => 'Supportive Community' ],
                        [ 'key' => 'overall_health', 'label' => 'Overall Health' ],
                        [ 'key' => 'access_to_community', 'label' => 'Access to Community' ],
                        [ 'key' => 'healthcare_experience', 'label' => 'Healthcare Experience' ],
                        [ 'key' => 'access_to_information', 'label' => 'Access to Information' ],
                    ],
                    'bands' => [],
                ],
                'readiness' => [
                    'label' => 'Readiness Score',
                    'max' => 100,
                    'aggregation' => 'average',
                    'dimensions' => [
                        [ 'key' => 'score', 'label' => 'Readiness Score' ],
                    ],
                    'bands' => [
                        [
                            'key' => 'not_ready',
                            'label' => 'Not Ready',
                            'min' => 0,
                            'max' => 39.99,
                        ],
                        [
                            'key' => 'needs_encouragement',
                            'label' => 'Needs Encouragement',
                            'min' => 40,
                            'max' => 69.99,
                        ],
                        [
                            'key' => 'ready',
                            'label' => "I'm ready.",
                            'min' => 70,
                            'max' => 100,
                        ],
                    ],
                ],
            ],
            'field_rules' => [
                [
                    'field' => 'field_name',
                    'path' => 'wellness.spiritual',
                    'value_map' => [
                        'Poor' => 1,
                        'Fair' => 3,
                        'Great' => 5,
                    ],
                    'aggregation' => 'average',
                    'multiplier' => 1,
                ],
            ],
        ];
    }

    private static function sanitize_sources($sources)
    {
        $sanitized = [];
        if (! is_array($sources)) {
            return $sanitized;
        }

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $path = isset($source['path']) ? sanitize_text_field((string) $source['path']) : '';
            if ($path === '') {
                continue;
            }

            $sanitized[] = [
                'path' => $path,
                'weight' => isset($source['weight']) ? (float) $source['weight'] : 1.0,
            ];
        }

        return $sanitized;
    }

    private static function sanitize_bands($bands)
    {
        $sanitized = [];
        if (! is_array($bands)) {
            return $sanitized;
        }

        foreach ($bands as $band) {
            if (! is_array($band)) {
                continue;
            }

            $key = isset($band['key']) ? sanitize_key((string) $band['key']) : '';
            $label = isset($band['label']) ? sanitize_text_field((string) $band['label']) : '';
            if ($key === '' && $label === '') {
                continue;
            }

            $sanitized[] = [
                'key' => $key ?: sanitize_title($label),
                'label' => $label ?: $key,
                'min' => isset($band['min']) ? (float) $band['min'] : 0.0,
                'max' => isset($band['max']) ? (float) $band['max'] : 0.0,
            ];
        }

        return $sanitized;
    }

    private static function sanitize_dimensions($dimensions)
    {
        $sanitized = [];
        if (! is_array($dimensions)) {
            return $sanitized;
        }

        foreach ($dimensions as $dimension) {
            if (! is_array($dimension)) {
                continue;
            }

            $key = isset($dimension['key']) ? sanitize_key((string) $dimension['key']) : '';
            $label = isset($dimension['label']) ? sanitize_text_field((string) $dimension['label']) : '';
            if ($key === '') {
                continue;
            }

            $sanitized[] = [
                'key' => $key,
                'label' => $label ?: $key,
                'max' => isset($dimension['max']) ? (float) $dimension['max'] : null,
            ];
        }

        return $sanitized;
    }

    private static function sanitize_field_rules($rules)
    {
        $sanitized = [];
        if (! is_array($rules)) {
            return $sanitized;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $field = isset($rule['field']) ? sanitize_key((string) $rule['field']) : '';
            $path = isset($rule['path']) ? sanitize_text_field((string) $rule['path']) : '';

            if ($field === '' || $path === '') {
                continue;
            }

            $value_map = [];
            if (! empty($rule['value_map']) && is_array($rule['value_map'])) {
                foreach ($rule['value_map'] as $label => $value) {
                    $label = sanitize_text_field((string) $label);
                    if ($label === '') {
                        continue;
                    }

                    $value_map[ $label ] = (float) $value;
                }
            }

            $sanitized[] = [
                'field' => $field,
                'path' => $path,
                'value_map' => $value_map,
                'aggregation' => self::sanitize_aggregation($rule['aggregation'] ?? 'average'),
                'multiplier' => isset($rule['multiplier']) ? (float) $rule['multiplier'] : 1.0,
            ];
        }

        return $sanitized;
    }

    private static function sanitize_aggregation($value)
    {
        $allowed = [ 'sum', 'average', 'max', 'min', 'last' ];
        $value = sanitize_key((string) $value);

        return in_array($value, $allowed, true) ? $value : 'average';
    }

    private function get_page_url()
    {
        return admin_url('edit.php?post_type=' . Post_Type::POST_TYPE . '&page=' . self::PAGE_SLUG);
    }
}
