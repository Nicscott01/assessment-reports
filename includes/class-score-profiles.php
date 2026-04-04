<?php

namespace AssessmentReports;

if (! defined('ABSPATH')) {
    exit;
}

class Score_Profiles
{
    public const POST_TYPE = 'ar_score_profile';
    public const LEGACY_OPTION_NAME = 'ar_score_profiles';
    private const NOTICE_TRANSIENT = 'ar_score_profiles_notice';
    private const MIGRATION_FLAG_OPTION = 'ar_score_profiles_cpt_migrated';
    private const META_LEGACY_ID = '_legacy_option_profile_id';
    private const META_DESCRIPTION = '_ar_score_profile_description';
    private const META_DEFINITION = '_ar_score_profile_definition';

    public function __construct()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
        add_action('save_post', [$this, 'save_profile'], 10, 2);
        add_action('admin_init', [$this, 'maybe_migrate_legacy_option_profiles']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_post_type()
    {
        $labels = [
            'name' => __('Score Profiles', 'assessment-reports'),
            'singular_name' => __('Score Profile', 'assessment-reports'),
            'add_new' => __('Add New Score Profile', 'assessment-reports'),
            'add_new_item' => __('Add New Score Profile', 'assessment-reports'),
            'edit_item' => __('Edit Score Profile', 'assessment-reports'),
            'new_item' => __('New Score Profile', 'assessment-reports'),
            'view_item' => __('View Score Profile', 'assessment-reports'),
            'search_items' => __('Search Score Profiles', 'assessment-reports'),
            'not_found' => __('No score profiles found.', 'assessment-reports'),
            'not_found_in_trash' => __('No score profiles found in Trash.', 'assessment-reports'),
            'all_items' => __('Score Profiles', 'assessment-reports'),
            'menu_name' => __('Score Profiles', 'assessment-reports'),
        ];

        register_post_type(self::POST_TYPE, [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=' . Post_Type::POST_TYPE,
            'capability_type' => 'post',
            'hierarchical' => false,
            'supports' => ['title'],
            'menu_icon' => 'dashicons-filter',
        ]);
    }

    public function register_meta_boxes()
    {
        add_meta_box(
            'ar_score_profile_builder',
            __('Score Profile Builder', 'assessment-reports'),
            [$this, 'render_profile_builder_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function render_profile_builder_meta_box($post)
    {
        if (! $post || $post->post_type !== self::POST_TYPE) {
            return;
        }

        wp_nonce_field('ar_save_score_profile', 'ar_score_profile_nonce');

        $profile = self::get_profile($post->ID);
        $definition = is_array($profile['definition'] ?? null) ? $profile['definition'] : self::get_profile_template();
        $description = $profile['description'] ?? '';
        $forms = $this->get_available_forms();
        $form_catalog = $this->get_form_catalog($forms);
        $selected_form_id = isset($definition['form_id']) ? absint($definition['form_id']) : 0;
        $selected_form_fields = isset($form_catalog[ $selected_form_id ]['fields']) ? $form_catalog[ $selected_form_id ]['fields'] : [];
        $selected_focus_field = $definition['focus_field'] ?? '';
        $field_rules = isset($definition['field_rules']) && is_array($definition['field_rules']) ? $definition['field_rules'] : [];
        ?>
        <p>
            <?php esc_html_e('Score-driven reports use a named score profile. Each profile defines the field mappings, chart configuration, summary bands, and section-selection inputs for one assessment model.', 'assessment-reports'); ?>
        </p>

        <?php
        $notice = get_transient(self::NOTICE_TRANSIENT);
        if (is_array($notice) && ! empty($notice['message'])) {
            add_settings_error(
                'ar_score_profile_notice',
                'ar_score_profile_notice',
                $notice['message'],
                $notice['type'] ?? 'success'
            );
            delete_transient(self::NOTICE_TRANSIENT);
        }
        settings_errors('ar_score_profile_notice');
        ?>

        <p>
            <label class="ar-field-label" for="ar-score-profile-description"><?php esc_html_e('Description', 'assessment-reports'); ?></label>
            <textarea id="ar-score-profile-description" class="widefat" rows="3" name="ar_score_profile[description]"><?php echo esc_textarea($description); ?></textarea>
        </p>

        <div
            class="ar-score-profile-builder"
            data-field-rule-prefix="ar_score_profile[builder][field_rules]"
            data-form-catalog="<?php echo esc_attr(wp_json_encode($form_catalog)); ?>"
            data-chart-config="<?php echo esc_attr(wp_json_encode($this->get_chart_builder_config($definition))); ?>"
        >
            <div class="ar-score-profile-grid">
                <p>
                    <label class="ar-field-label" for="ar-score-profile-form"><?php esc_html_e('Fluent Form', 'assessment-reports'); ?></label>
                    <select
                        id="ar-score-profile-form"
                        class="widefat ar-score-profile-form-select"
                        name="ar_score_profile[builder][form_id]"
                    >
                        <option value=""><?php esc_html_e('Select a Fluent Form', 'assessment-reports'); ?></option>
                        <?php foreach ($forms as $form) : ?>
                            <option value="<?php echo esc_attr($form['id']); ?>" <?php selected($selected_form_id, $form['id']); ?>>
                                <?php echo esc_html($form['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label class="ar-field-label" for="ar-score-profile-focus"><?php esc_html_e('Focus Field', 'assessment-reports'); ?></label>
                    <select
                        id="ar-score-profile-focus"
                        class="widefat ar-score-profile-focus-select"
                        name="ar_score_profile[builder][focus_field]"
                    >
                        <option value=""><?php esc_html_e('No focus field', 'assessment-reports'); ?></option>
                        <?php foreach ($selected_form_fields as $field) : ?>
                            <option value="<?php echo esc_attr($field['name']); ?>" <?php selected($selected_focus_field, $field['name']); ?>>
                                <?php echo esc_html($field['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label class="ar-field-label" for="ar-score-profile-summary-label"><?php esc_html_e('Summary Label', 'assessment-reports'); ?></label>
                    <input
                        id="ar-score-profile-summary-label"
                        type="text"
                        class="widefat"
                        name="ar_score_profile[builder][summary_label]"
                        value="<?php echo esc_attr($definition['summary']['label'] ?? ''); ?>"
                    >
                </p>
                <p>
                    <label class="ar-field-label" for="ar-score-profile-summary-max"><?php esc_html_e('Summary Max Score', 'assessment-reports'); ?></label>
                    <input
                        id="ar-score-profile-summary-max"
                        type="number"
                        step="0.01"
                        class="widefat"
                        name="ar_score_profile[builder][summary_max_score]"
                        value="<?php echo esc_attr($definition['summary']['max_score'] ?? ''); ?>"
                    >
                </p>
            </div>

            <div class="ar-score-field-rules">
                <div class="ar-score-field-rules__header">
                    <div>
                        <h3><?php esc_html_e('Field Scoring Rules', 'assessment-reports'); ?></h3>
                        <p class="description"><?php esc_html_e('Pick actual Fluent Forms fields and score their real answer options. This builder writes the `field_rules` JSON for you.', 'assessment-reports'); ?></p>
                    </div>
                </div>
                <div class="ar-score-field-rules-body">
                    <?php
                    if (! $field_rules) {
                        echo $this->render_field_rule_row(0, [], $selected_form_fields, $definition);
                    } else {
                        foreach ($field_rules as $rule_index => $rule) {
                            echo $this->render_field_rule_row($rule_index, $rule, $selected_form_fields, $definition);
                        }
                    }
                    ?>
                </div>
                <div class="ar-score-field-rules__footer">
                    <button type="button" class="button ar-score-profile-add-rule"><?php esc_html_e('Add Field Rule', 'assessment-reports'); ?></button>
                </div>
            </div>

            <details class="ar-score-profile-advanced">
                <summary><?php esc_html_e('Advanced JSON', 'assessment-reports'); ?></summary>
                <p class="description"><?php esc_html_e('Use this only for chart dimensions, summary sources, and summary/readiness bands that are not editable in the guided builder yet.', 'assessment-reports'); ?></p>
                <p>
                    <label class="ar-field-label" for="ar-score-profile-definition"><?php esc_html_e('Profile Definition JSON', 'assessment-reports'); ?></label>
                    <textarea
                        id="ar-score-profile-definition"
                        class="widefat code ar-json-editor"
                        rows="24"
                        name="ar_score_profile[definition_json]"
                    ><?php echo esc_textarea(wp_json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
                </p>
            </details>
        </div>
        <?php
    }

    public function save_profile($post_id, $post)
    {
        if (! $post || $post->post_type !== self::POST_TYPE) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        if (! isset($_POST['ar_score_profile_nonce']) || ! wp_verify_nonce($_POST['ar_score_profile_nonce'], 'ar_save_score_profile')) {
            return;
        }

        $input = isset($_POST['ar_score_profile']) && is_array($_POST['ar_score_profile'])
            ? wp_unslash($_POST['ar_score_profile'])
            : [];

        $description = isset($input['description']) ? sanitize_textarea_field($input['description']) : '';
        update_post_meta($post_id, self::META_DESCRIPTION, $description);

        $definition = [];
        if (isset($input['definition_json'])) {
            $definition = json_decode((string) $input['definition_json'], true);
        }

        if (! is_array($definition)) {
            $definition = self::get_profile_template();
            set_transient(
                self::NOTICE_TRANSIENT,
                [
                    'type' => 'error',
                    'message' => esc_html__('The advanced JSON was invalid. The last saved definition was replaced with the profile template before builder changes were applied.', 'assessment-reports'),
                ],
                MINUTE_IN_SECONDS
            );
        }

        $definition = $this->merge_builder_into_definition(
            $definition,
            isset($input['builder']) && is_array($input['builder']) ? $input['builder'] : []
        );

        $sanitized_definition = self::sanitize_definition($definition);
        update_post_meta($post_id, self::META_DEFINITION, $sanitized_definition);

        if (! get_post_meta($post_id, self::META_LEGACY_ID, true)) {
            update_post_meta($post_id, self::META_LEGACY_ID, sanitize_title($post->post_name ?: $post->post_title ?: 'score-profile-' . $post_id));
        }
    }

    public function maybe_migrate_legacy_option_profiles()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (get_option(self::MIGRATION_FLAG_OPTION)) {
            return;
        }

        $profiles = get_option(self::LEGACY_OPTION_NAME, []);
        if (! is_array($profiles) || empty($profiles)) {
            update_option(self::MIGRATION_FLAG_OPTION, 1, false);
            return;
        }

        foreach ($profiles as $legacy_id => $profile) {
            if (! is_array($profile)) {
                continue;
            }

            if ($this->get_profile_post_by_legacy_id($legacy_id)) {
                continue;
            }

            $post_id = wp_insert_post([
                'post_type' => self::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => sanitize_text_field($profile['name'] ?? $legacy_id),
                'post_name' => sanitize_title($legacy_id),
            ]);

            if (is_wp_error($post_id) || ! $post_id) {
                continue;
            }

            update_post_meta($post_id, self::META_LEGACY_ID, sanitize_key((string) $legacy_id));
            update_post_meta($post_id, self::META_DESCRIPTION, sanitize_textarea_field($profile['description'] ?? ''));
            update_post_meta($post_id, self::META_DEFINITION, self::sanitize_definition($profile['definition'] ?? self::get_profile_template()));
        }

        update_option(self::MIGRATION_FLAG_OPTION, 1, false);
        set_transient(
            self::NOTICE_TRANSIENT,
            [
                'type' => 'success',
                'message' => esc_html__('Legacy score profiles were migrated into Score Profile posts.', 'assessment-reports'),
            ],
            MINUTE_IN_SECONDS
        );
    }

    public function enqueue_assets($hook)
    {
        $screen = get_current_screen();
        if (! $screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }

        $asset_version = defined('ASSESSMENT_REPORTS_VERSION') ? ASSESSMENT_REPORTS_VERSION : null;
        $css_path = ASSESSMENT_REPORTS_PLUGIN_DIR . 'assets/admin.css';
        $js_path = ASSESSMENT_REPORTS_PLUGIN_DIR . 'assets/score-profiles.js';

        if (file_exists($css_path)) {
            wp_enqueue_style(
                'assessment-reports-admin',
                ASSESSMENT_REPORTS_PLUGIN_URL . 'assets/admin.css',
                [],
                $asset_version
            );
        }

        if (file_exists($js_path)) {
            wp_enqueue_script(
                'assessment-reports-score-profiles',
                ASSESSMENT_REPORTS_PLUGIN_URL . 'assets/score-profiles.js',
                [],
                $asset_version,
                true
            );
        }
    }

    public static function get_profile($profile_id)
    {
        $post = self::resolve_profile_post($profile_id);
        if (! $post) {
            return null;
        }

        $definition = get_post_meta($post->ID, self::META_DEFINITION, true);
        $description = get_post_meta($post->ID, self::META_DESCRIPTION, true);
        $legacy_id = get_post_meta($post->ID, self::META_LEGACY_ID, true);

        return [
            'id' => $post->ID,
            'legacy_id' => $legacy_id ?: sanitize_title($post->post_name),
            'name' => $post->post_title,
            'description' => is_string($description) ? $description : '',
            'definition' => is_array($definition) ? $definition : self::get_profile_template(),
        ];
    }

    public static function get_profile_options()
    {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => ['publish', 'draft', 'private'],
            'numberposts' => -1,
            'orderby' => [
                'title' => 'ASC',
                'date' => 'ASC',
            ],
        ]);

        $options = [];
        foreach ($posts as $post) {
            $options[ $post->ID ] = $post->post_title ?: ('#' . $post->ID);
        }

        return $options;
    }

    public static function sanitize_definition(array $definition)
    {
        $template = self::get_profile_template();

        $definition['focus_field'] = isset($definition['focus_field']) ? sanitize_key((string) $definition['focus_field']) : '';
        $definition['form_id'] = isset($definition['form_id']) ? absint($definition['form_id']) : 0;

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
            'form_id' => 0,
            'focus_field' => '',
            'summary' => [
                'label' => 'Summary',
                'max_score' => 100,
                'sources' => [
                    [ 'path' => 'maslow.total', 'weight' => 0.25 ],
                    [ 'path' => 'wellness.total', 'weight' => 0.25 ],
                    [ 'path' => 'sdoh.total', 'weight' => 0.25 ],
                    [ 'path' => 'readiness.score', 'weight' => 0.25 ],
                ],
                'bands' => [
                    [ 'key' => 'low', 'label' => 'Low', 'min' => 0, 'max' => 39.99 ],
                    [ 'key' => 'medium', 'label' => 'Medium', 'min' => 40, 'max' => 69.99 ],
                    [ 'key' => 'high', 'label' => 'High', 'min' => 70, 'max' => 100 ],
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
                        [ 'key' => 'not_ready', 'label' => 'Not Ready', 'min' => 0, 'max' => 39.99 ],
                        [ 'key' => 'needs_encouragement', 'label' => 'Needs Encouragement', 'min' => 40, 'max' => 69.99 ],
                        [ 'key' => 'ready', 'label' => "I'm ready.", 'min' => 70, 'max' => 100 ],
                    ],
                ],
            ],
            'field_rules' => [],
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
        $allowed = ['sum', 'average', 'max', 'min', 'last'];
        $value = sanitize_key((string) $value);

        return in_array($value, $allowed, true) ? $value : 'average';
    }

    private function merge_builder_into_definition(array $definition, array $builder)
    {
        if (! empty($builder['form_id'])) {
            $definition['form_id'] = absint($builder['form_id']);
        }

        if (array_key_exists('focus_field', $builder)) {
            $definition['focus_field'] = sanitize_key((string) $builder['focus_field']);
        }

        if (isset($builder['summary_label'])) {
            $definition['summary']['label'] = sanitize_text_field((string) $builder['summary_label']);
        }

        if (isset($builder['summary_max_score']) && $builder['summary_max_score'] !== '') {
            $definition['summary']['max_score'] = (float) $builder['summary_max_score'];
        }

        if (! empty($builder['field_rules']) && is_array($builder['field_rules'])) {
            $definition['field_rules'] = $this->sanitize_builder_field_rules($builder['field_rules']);
        } else {
            $definition['field_rules'] = [];
        }

        return $definition;
    }

    private function sanitize_builder_field_rules(array $rules)
    {
        $sanitized = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $field = isset($rule['field']) ? sanitize_key((string) $rule['field']) : '';
            $chart = isset($rule['chart']) ? sanitize_key((string) $rule['chart']) : '';
            $dimension = isset($rule['dimension']) ? sanitize_key((string) $rule['dimension']) : '';
            $aggregation = self::sanitize_aggregation($rule['aggregation'] ?? 'average');
            $multiplier = isset($rule['multiplier']) && $rule['multiplier'] !== '' ? (float) $rule['multiplier'] : 1.0;

            if ($field === '' || $chart === '' || $dimension === '') {
                continue;
            }

            $value_map = [];
            if (! empty($rule['options']) && is_array($rule['options'])) {
                foreach ($rule['options'] as $option) {
                    if (! is_array($option)) {
                        continue;
                    }

                    $value = isset($option['value']) ? sanitize_text_field((string) $option['value']) : '';
                    $score = isset($option['score']) ? trim((string) $option['score']) : '';
                    if ($value === '' || $score === '') {
                        continue;
                    }

                    $value_map[ $value ] = (float) $score;
                }
            }

            $sanitized[] = [
                'field' => $field,
                'path' => $chart . '.' . $dimension,
                'value_map' => $value_map,
                'aggregation' => $aggregation,
                'multiplier' => $multiplier,
            ];
        }

        return $sanitized;
    }

    private function get_available_forms()
    {
        if (! function_exists('fluentFormApi')) {
            return [];
        }

        $forms = fluentFormApi('forms')->forms([
            'per_page' => 999,
            'sort_by' => 'ASC',
        ]);

        if (! is_array($forms) || empty($forms['data'])) {
            return [];
        }

        return array_map(
            static function ($form) {
                return [
                    'id' => absint($form->id ?? 0),
                    'title' => sanitize_text_field($form->title ?? ''),
                ];
            },
            $forms['data']
        );
    }

    private function get_form_catalog(array $forms)
    {
        $catalog = [];

        foreach ($forms as $form) {
            $form_id = absint($form['id'] ?? 0);
            if (! $form_id) {
                continue;
            }

            $catalog[ $form_id ] = [
                'id' => $form_id,
                'title' => $form['title'] ?? '',
                'fields' => $this->get_form_field_catalog($form_id),
            ];
        }

        return $catalog;
    }

    private function get_form_field_catalog($form_id)
    {
        if (! function_exists('fluentFormApi')) {
            return [];
        }

        $form = fluentFormApi('forms')->find(absint($form_id));
        if (! $form || empty($form->form_fields)) {
            return [];
        }

        $fields = json_decode($form->form_fields, true);
        if (! is_array($fields)) {
            return [];
        }

        $catalog = [];
        $this->collect_form_fields($fields['fields'] ?? [], $catalog);

        return $catalog;
    }

    private function collect_form_fields(array $fields, array &$catalog)
    {
        foreach ($fields as $field) {
            if (isset($field['columns']) && is_array($field['columns'])) {
                foreach ($field['columns'] as $column) {
                    $this->collect_form_fields($column['fields'] ?? [], $catalog);
                }
            }

            $name = isset($field['attributes']['name']) ? sanitize_key((string) $field['attributes']['name']) : '';
            if ($name !== '') {
                $catalog[] = [
                    'name' => $name,
                    'label' => isset($field['settings']['label']) ? wp_strip_all_tags($field['settings']['label']) : $name,
                    'type' => isset($field['element']) ? sanitize_key((string) $field['element']) : '',
                    'options' => $this->extract_field_options($field),
                ];
            }

            if (isset($field['fields']) && is_array($field['fields']) && $name === '') {
                $this->collect_form_fields($field['fields'], $catalog);
            }
        }
    }

    private function extract_field_options(array $field)
    {
        $options = [];

        foreach ($field['settings']['advanced_options'] ?? [] as $option) {
            $value = isset($option['value']) ? sanitize_text_field((string) $option['value']) : '';
            $label = isset($option['label']) ? wp_strip_all_tags($option['label']) : $value;
            if ($value === '') {
                continue;
            }

            $options[] = [
                'value' => $value,
                'label' => $label !== '' ? $label : $value,
            ];
        }

        foreach ($field['settings']['options'] ?? [] as $option) {
            $value = isset($option['value']) ? sanitize_text_field((string) $option['value']) : '';
            $label = isset($option['label']) ? wp_strip_all_tags($option['label']) : $value;
            if ($value === '') {
                continue;
            }

            $options[] = [
                'value' => $value,
                'label' => $label !== '' ? $label : $value,
            ];
        }

        return array_values($options);
    }

    private function get_chart_builder_config(array $definition)
    {
        $charts = $definition['charts'] ?? self::get_profile_template()['charts'];
        $config = [];

        foreach ($charts as $chart_key => $chart) {
            $config[ $chart_key ] = [
                'label' => $chart['label'] ?? ucfirst($chart_key),
                'dimensions' => array_values(
                    array_filter(
                        array_map(
                            static function ($dimension) {
                                if (! is_array($dimension) || empty($dimension['key'])) {
                                    return null;
                                }

                                return [
                                    'key' => sanitize_key((string) $dimension['key']),
                                    'label' => sanitize_text_field((string) ($dimension['label'] ?? $dimension['key'])),
                                ];
                            },
                            $chart['dimensions'] ?? []
                        )
                    )
                ),
            ];
        }

        return $config;
    }

    private function render_field_rule_row($rule_index, array $rule, array $selected_form_fields, array $definition)
    {
        $parts = explode('.', (string) ($rule['path'] ?? ''));
        $selected_chart = isset($parts[0]) ? sanitize_key($parts[0]) : '';
        $selected_dimension = isset($parts[1]) ? sanitize_key($parts[1]) : '';
        $selected_field = isset($rule['field']) ? sanitize_key((string) $rule['field']) : '';
        $aggregation = self::sanitize_aggregation($rule['aggregation'] ?? 'average');
        $multiplier = isset($rule['multiplier']) ? (float) $rule['multiplier'] : 1.0;
        $field_options = [];

        foreach ($selected_form_fields as $field) {
            if (($field['name'] ?? '') === $selected_field) {
                $field_options = $field['options'] ?? [];
                break;
            }
        }

        $chart_config = $this->get_chart_builder_config($definition);
        ob_start();
        ?>
        <div class="ar-field-rule-row" data-rule-index="<?php echo esc_attr($rule_index); ?>">
            <div class="ar-field-rule-row__top">
                <p>
                    <label class="ar-field-label"><?php esc_html_e('Field', 'assessment-reports'); ?></label>
                    <select class="widefat ar-field-rule-field" name="ar_score_profile[builder][field_rules][<?php echo esc_attr($rule_index); ?>][field]">
                        <option value=""><?php esc_html_e('Select a field', 'assessment-reports'); ?></option>
                        <?php foreach ($selected_form_fields as $field) : ?>
                            <?php if (empty($field['options'])) { continue; } ?>
                            <option value="<?php echo esc_attr($field['name']); ?>" <?php selected($selected_field, $field['name']); ?>>
                                <?php echo esc_html($field['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label class="ar-field-label"><?php esc_html_e('Chart', 'assessment-reports'); ?></label>
                    <select class="widefat ar-field-rule-chart" name="ar_score_profile[builder][field_rules][<?php echo esc_attr($rule_index); ?>][chart]">
                        <option value=""><?php esc_html_e('Select a chart', 'assessment-reports'); ?></option>
                        <?php foreach ($chart_config as $chart_key => $chart) : ?>
                            <option value="<?php echo esc_attr($chart_key); ?>" <?php selected($selected_chart, $chart_key); ?>>
                                <?php echo esc_html($chart['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label class="ar-field-label"><?php esc_html_e('Dimension', 'assessment-reports'); ?></label>
                    <select class="widefat ar-field-rule-dimension" name="ar_score_profile[builder][field_rules][<?php echo esc_attr($rule_index); ?>][dimension]">
                        <option value=""><?php esc_html_e('Select a dimension', 'assessment-reports'); ?></option>
                        <?php foreach (($chart_config[ $selected_chart ]['dimensions'] ?? []) as $dimension) : ?>
                            <option value="<?php echo esc_attr($dimension['key']); ?>" <?php selected($selected_dimension, $dimension['key']); ?>>
                                <?php echo esc_html($dimension['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label class="ar-field-label"><?php esc_html_e('Aggregation', 'assessment-reports'); ?></label>
                    <select class="widefat" name="ar_score_profile[builder][field_rules][<?php echo esc_attr($rule_index); ?>][aggregation]">
                        <?php foreach (['average', 'sum', 'max', 'min', 'last'] as $option) : ?>
                            <option value="<?php echo esc_attr($option); ?>" <?php selected($aggregation, $option); ?>>
                                <?php echo esc_html(ucfirst($option)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <label class="ar-field-label"><?php esc_html_e('Multiplier', 'assessment-reports'); ?></label>
                    <input type="number" class="widefat" step="0.01" name="ar_score_profile[builder][field_rules][<?php echo esc_attr($rule_index); ?>][multiplier]" value="<?php echo esc_attr($multiplier); ?>">
                </p>
                <p class="ar-field-rule-row__actions">
                    <button type="button" class="button-link-delete ar-score-profile-remove-rule"><?php esc_html_e('Remove', 'assessment-reports'); ?></button>
                </p>
            </div>
            <div class="ar-field-rule-options">
                <strong><?php esc_html_e('Answer Scoring', 'assessment-reports'); ?></strong>
                <div class="ar-field-rule-options-body">
                    <?php echo $this->render_field_rule_options($rule_index, $field_options, $rule['value_map'] ?? []); ?>
                </div>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    private function render_field_rule_options($rule_index, array $field_options, array $value_map)
    {
        if (empty($field_options)) {
            return '<p class="description">' . esc_html__('Select a form field with predefined options to configure answer scoring.', 'assessment-reports') . '</p>';
        }

        $html = '<div class="ar-field-rule-options-grid">';
        foreach ($field_options as $option_index => $option) {
            $value = $option['value'] ?? '';
            $label = $option['label'] ?? $value;
            $score = array_key_exists($value, $value_map) ? $value_map[ $value ] : '';

            $html .= '<div class="ar-field-rule-option">';
            $html .= '<div class="ar-field-rule-option__label">' . esc_html($label) . '</div>';
            if ($label !== $value) {
                $html .= '<div class="ar-field-rule-option__value">' . esc_html($value) . '</div>';
            }
            $html .= '<input type="hidden" name="ar_score_profile[builder][field_rules][' . esc_attr($rule_index) . '][options][' . esc_attr($option_index) . '][value]" value="' . esc_attr($value) . '">';
            $html .= '<input type="hidden" name="ar_score_profile[builder][field_rules][' . esc_attr($rule_index) . '][options][' . esc_attr($option_index) . '][label]" value="' . esc_attr($label) . '">';
            $html .= '<input type="number" step="0.01" class="small-text" name="ar_score_profile[builder][field_rules][' . esc_attr($rule_index) . '][options][' . esc_attr($option_index) . '][score]" value="' . esc_attr((string) $score) . '" placeholder="0">';
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    private static function resolve_profile_post($profile_id)
    {
        if (is_numeric($profile_id)) {
            $post = get_post(absint($profile_id));
            if ($post && $post->post_type === self::POST_TYPE) {
                return $post;
            }
        }

        $profile_id = sanitize_key((string) $profile_id);
        if ($profile_id === '') {
            return null;
        }

        return self::get_profile_post_by_legacy_id($profile_id);
    }

    private static function get_profile_post_by_legacy_id($legacy_id)
    {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => ['publish', 'draft', 'private'],
            'numberposts' => 1,
            'meta_key' => self::META_LEGACY_ID,
            'meta_value' => sanitize_key((string) $legacy_id),
        ]);

        return $posts ? $posts[0] : null;
    }
}
