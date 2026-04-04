<?php

namespace AssessmentReports;

class Meta_Box
{
    public function __construct()
    {
        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
        add_action('save_post', [$this, 'save_meta'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_meta_boxes()
    {
        add_meta_box(
            'ar_report_config',
            __('Report Configuration', 'assessment-reports'),
            [$this, 'render_report_config_meta_box'],
            Post_Type::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'ar_section_field_mapping',
            __('Section Field Mapping', 'assessment-reports'),
            [$this, 'render_section_mapping_meta_box'],
            Post_Type::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'ar_ai_personalization',
            __('AI Personalization', 'assessment-reports'),
            [$this, 'render_ai_personalization_meta_box'],
            Post_Type::POST_TYPE,
            'normal',
            'default'
        );
    }

    public function render_report_config_meta_box($post)
    {
        if ($post->post_parent) {
            echo '<p>' . esc_html__('Report configuration only applies to root report posts.', 'assessment-reports') . '</p>';
            return;
        }

        wp_nonce_field('ar_report_meta_box', 'ar_report_meta_nonce');

        $selected_form = get_post_meta($post->ID, '_report_form_id', true);
        $report_mode = $this->get_report_mode($post->ID);
        $selected_profile = get_post_meta($post->ID, '_score_profile_id', true);
        $children_display_limit = get_post_meta($post->ID, '_children_display_limit', true);
        $children_display_limit = $children_display_limit !== '' ? $children_display_limit : '3';
        $children_display_order = get_post_meta($post->ID, '_children_display_order', true);
        if (! in_array($children_display_order, ['menu_order', 'score_asc', 'score_desc'], true)) {
            $children_display_order = 'score_desc';
        }
        $closing_content = get_post_meta($post->ID, '_report_closing_content', true);
        $forms = $this->get_available_forms();
        $profiles = Score_Profiles::get_profile_options();
        ?>
        <p>
            <label for="assessment_report_form_id"><?php esc_html_e('Fluent Form', 'assessment-reports'); ?></label>
            <select name="assessment_report_form_id" id="assessment_report_form_id" class="widefat">
                <option value=""><?php esc_html_e('Select a Fluent Form', 'assessment-reports'); ?></option>
                <?php foreach ($forms as $form) : ?>
                    <option value="<?php echo esc_attr($form->id); ?>" <?php selected($selected_form, $form->id); ?>>
                        <?php echo esc_html($form->title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="assessment_report_mode"><?php esc_html_e('Report Mode', 'assessment-reports'); ?></label>
            <select name="assessment_report_mode" id="assessment_report_mode" class="widefat">
                <option value="legacy_response_mapped" <?php selected($report_mode, 'legacy_response_mapped'); ?>>
                    <?php esc_html_e('Legacy Response Mapped', 'assessment-reports'); ?>
                </option>
                <option value="score_driven" <?php selected($report_mode, 'score_driven'); ?>>
                    <?php esc_html_e('Score Driven', 'assessment-reports'); ?>
                </option>
            </select>
            <span class="description"><?php esc_html_e('Legacy reports use field mappings. Score-driven reports use a saved score profile and section rules.', 'assessment-reports'); ?></span>
        </p>
        <p>
            <label for="assessment_score_profile_id"><?php esc_html_e('Score Profile', 'assessment-reports'); ?></label>
            <select name="assessment_score_profile_id" id="assessment_score_profile_id" class="widefat">
                <option value=""><?php esc_html_e('Select a Score Profile', 'assessment-reports'); ?></option>
                <?php foreach ($profiles as $profile_id => $label) : ?>
                    <option value="<?php echo esc_attr($profile_id); ?>" <?php selected($selected_profile, $profile_id); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="description">
                <?php
                echo $profiles
                    ? esc_html__('Only used when the report mode is score-driven.', 'assessment-reports')
                    : esc_html__('No score profiles found yet. Create one under Reports > Score Profiles before using score-driven mode.', 'assessment-reports');
                ?>
            </span>
        </p>
        <p>
            <label for="assessment_report_closing_content"><?php esc_html_e('Closing content', 'assessment-reports'); ?></label>
            <?php
            if (function_exists('wp_enqueue_editor')) {
                wp_enqueue_editor();
            }
            wp_editor(
                $closing_content,
                'assessment_report_closing_content',
                [
                    'textarea_name' => 'assessment_report_closing_content',
                    'textarea_rows' => 4,
                ]
            );
            ?>
        </p>
        <p>
            <label for="assessment_children_display_limit"><?php esc_html_e('Children Display Limit', 'assessment-reports'); ?></label>
            <input
                type="text"
                name="assessment_children_display_limit"
                id="assessment_children_display_limit"
                class="widefat"
                value="<?php echo esc_attr((string) $children_display_limit); ?>"
                placeholder="3"
            >
            <span class="description"><?php esc_html_e('Used by legacy child scoring and Breakdance helpers. Enter a whole number or `all`.', 'assessment-reports'); ?></span>
        </p>
        <p>
            <label for="assessment_children_display_order"><?php esc_html_e('Children Display Order', 'assessment-reports'); ?></label>
            <select name="assessment_children_display_order" id="assessment_children_display_order" class="widefat">
                <option value="score_desc" <?php selected($children_display_order, 'score_desc'); ?>>
                    <?php esc_html_e('Score Descending', 'assessment-reports'); ?>
                </option>
                <option value="score_asc" <?php selected($children_display_order, 'score_asc'); ?>>
                    <?php esc_html_e('Score Ascending', 'assessment-reports'); ?>
                </option>
                <option value="menu_order" <?php selected($children_display_order, 'menu_order'); ?>>
                    <?php esc_html_e('Menu Order', 'assessment-reports'); ?>
                </option>
            </select>
            <span class="description"><?php esc_html_e('Determines how child sections are ordered before the display limit is applied.', 'assessment-reports'); ?></span>
        </p>
        <?php
    }

    public function render_section_mapping_meta_box($post)
    {
        wp_nonce_field('ar_section_meta_box', 'ar_section_meta_nonce');

        $parent_id = wp_get_post_parent_id($post->ID);
        if (! $parent_id) {
            echo '<p>' . esc_html__('Assign this report to a parent report in order to configure mappings.', 'assessment-reports') . '</p>';
            return;
        }

        $form_id = get_post_meta($parent_id, '_report_form_id', true);
        $report_mode = $this->get_report_mode($parent_id);

        if ($report_mode === 'score_driven') {
            $this->render_score_rules_meta_box($post, $parent_id);
            return;
        }

        if (! $form_id) {
            echo '<p>' . esc_html__('Please select a Fluent Form on the parent Report first.', 'assessment-reports') . '</p>';
            return;
        }

        $fields = $this->get_form_fields($form_id);
        if (! $fields) {
            echo '<p>' . esc_html__('No scoreable choice fields were found on the selected Fluent Form.', 'assessment-reports') . '</p>';
            return;
        }

        $saved_mappings = get_post_meta($post->ID, '_field_mappings', true);
        if (! is_array($saved_mappings)) {
            $saved_mappings = [];
        }
        $show_with_zero_score = ! empty(get_post_meta($post->ID, '_show_with_zero_score', true));
        $graph_key = get_post_meta($post->ID, '_graph_key', true);
        $graph_key = is_string($graph_key) ? $graph_key : '';
        $section_max_score = get_post_meta($post->ID, '_section_max_score', true);

        ?>
        <div class="ar-section-scoring-settings">
            <p>
                <label for="assessment_report_graph_key" class="ar-field-label"><?php esc_html_e('Graph Key', 'assessment-reports'); ?></label>
                <input
                    type="text"
                    class="widefat"
                    name="assessment_report_graph_key"
                    id="assessment_report_graph_key"
                    value="<?php echo esc_attr($graph_key); ?>"
                    placeholder="<?php echo esc_attr(sanitize_title($post->post_name ?: $post->post_title)); ?>"
                >
                <span class="description"><?php esc_html_e('Optional stable identifier for fetching this section score in Breakdance or helper functions.', 'assessment-reports'); ?></span>
            </p>
            <p>
                <label for="assessment_report_section_max_score" class="ar-field-label"><?php esc_html_e('Section Max Score', 'assessment-reports'); ?></label>
                <input
                    type="number"
                    class="widefat"
                    name="assessment_report_section_max_score"
                    id="assessment_report_section_max_score"
                    step="0.01"
                    value="<?php echo esc_attr($section_max_score !== '' ? (string) $section_max_score : ''); ?>"
                    placeholder="100"
                >
                <span class="description"><?php esc_html_e('Optional max score used to calculate percentages for graphs and display helpers.', 'assessment-reports'); ?></span>
            </p>
            <p>
                <label class="ar-inline-checkbox">
                    <input type="checkbox" name="assessment_report_show_with_zero_score" value="1" <?php checked($show_with_zero_score); ?>>
                    <span><?php esc_html_e('Show this section even with zero score', 'assessment-reports'); ?></span>
                </label>
            </p>
        </div>
        <?php
        echo '<div class="ar-field-mappings">';
        foreach ($fields as $field) {
            $field_type = $field['element'] ?? $field['type'] ?? '';
            if (! in_array($field_type, ['input_checkbox', 'input_radio', 'select', 'input_select', 'ratings'], true)) {
                continue;
            }

            $field_name = $field['attributes']['name'] ?? '';
            $choices = $this->get_scoreable_field_choices($field);
            if (empty($field_name) || empty($choices) || ! is_array($choices)) {
                continue;
            }

            $label = $field['settings']['label'] ?? $field['attributes']['label'] ?? 'Field';
            $admin_label = $field['settings']['admin_field_label'] ?? '';

            echo '<div class="ar-field">';
            echo '<h4>' . esc_html($label) . ' <small>' . esc_html($field_name) . '</small></h4>';
            if ($admin_label) {
                echo '<p class="ar-admin-label">' . esc_html__('Admin label:', 'assessment-reports') . ' ' . esc_html($admin_label) . '</p>';
            }
            foreach ($choices as $choice) {
                $choice_value = isset($choice['value']) ? (string) $choice['value'] : '';
                $choice_label = isset($choice['label']) ? $choice['label'] : $choice_value;
                if ($choice_value === '') {
                    continue;
                }

                $normalized_mapping = ar_normalize_choice_mapping($saved_mappings[$field_name][$choice_value] ?? null);
                $is_checked = $normalized_mapping['enabled'];
                $points_value = $is_checked ? (string) $normalized_mapping['points'] : '1';
                $multiplier_value = $is_checked ? (string) $normalized_mapping['multiplier'] : '1';
                ?>
                <label class="ar-choice-row">
                    <input type="checkbox" class="ar-mapping-checkbox" name="mappings[<?php echo esc_attr($field_name); ?>][<?php echo esc_attr($choice_value); ?>]" value="1" <?php checked($is_checked); ?>>
                    <span class="ar-choice-label"><?php echo esc_html($choice_label); ?></span>
                    <span class="ar-choice-scoring">
                        <span class="ar-weight-wrapper">
                            <?php esc_html_e('Points:', 'assessment-reports'); ?>
                            <input
                                type="number"
                                class="ar-weight-input"
                                step="0.01"
                                value="<?php echo esc_attr($points_value); ?>"
                                name="points[<?php echo esc_attr($field_name); ?>][<?php echo esc_attr($choice_value); ?>]"
                            >
                        </span>
                        <span class="ar-weight-wrapper">
                            <?php esc_html_e('Multiplier:', 'assessment-reports'); ?>
                            <input
                                type="number"
                                class="ar-weight-input"
                                step="0.01"
                                value="<?php echo esc_attr($multiplier_value); ?>"
                                name="multipliers[<?php echo esc_attr($field_name); ?>][<?php echo esc_attr($choice_value); ?>]"
                            >
                        </span>
                    </span>
                </label>
                <?php
            }
            echo '</div>';
        }
        echo '</div>';
    }

    public function save_meta($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if ($post->post_type !== Post_Type::POST_TYPE) {
            return;
        }

        if ($post->post_parent) {
            $this->save_section_meta($post_id);
        } else {
            $this->save_report_meta($post_id);
            $this->save_ai_meta($post_id);
        }
    }

    private function save_report_meta($post_id)
    {
        if (! isset($_POST['ar_report_meta_nonce']) || ! wp_verify_nonce($_POST['ar_report_meta_nonce'], 'ar_report_meta_box')) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $form_id = isset($_POST['assessment_report_form_id']) ? absint($_POST['assessment_report_form_id']) : 0;
        if ($form_id) {
            update_post_meta($post_id, '_report_form_id', $form_id);
        } else {
            delete_post_meta($post_id, '_report_form_id');
        }

        $report_mode = isset($_POST['assessment_report_mode']) ? sanitize_key(wp_unslash($_POST['assessment_report_mode'])) : 'legacy_response_mapped';
        if (! in_array($report_mode, [ 'legacy_response_mapped', 'score_driven' ], true)) {
            $report_mode = 'legacy_response_mapped';
        }
        update_post_meta($post_id, '_report_mode', $report_mode);

        $score_profile_id = isset($_POST['assessment_score_profile_id']) ? sanitize_key(wp_unslash($_POST['assessment_score_profile_id'])) : '';
        if ($score_profile_id !== '') {
            update_post_meta($post_id, '_score_profile_id', $score_profile_id);
        } else {
            delete_post_meta($post_id, '_score_profile_id');
        }

        $children_display_limit = isset($_POST['assessment_children_display_limit']) ? trim((string) wp_unslash($_POST['assessment_children_display_limit'])) : '';
        if ($children_display_limit === '') {
            delete_post_meta($post_id, '_children_display_limit');
        } elseif (strtolower($children_display_limit) === 'all') {
            update_post_meta($post_id, '_children_display_limit', 'all');
        } else {
            $limit = absint($children_display_limit);
            update_post_meta($post_id, '_children_display_limit', $limit > 0 ? (string) $limit : '3');
        }

        $children_display_order = isset($_POST['assessment_children_display_order']) ? sanitize_key(wp_unslash($_POST['assessment_children_display_order'])) : 'score_desc';
        if (! in_array($children_display_order, ['menu_order', 'score_asc', 'score_desc'], true)) {
            $children_display_order = 'score_desc';
        }
        update_post_meta($post_id, '_children_display_order', $children_display_order);

        $closing_content = isset($_POST['assessment_report_closing_content']) ? wp_kses_post(wp_unslash($_POST['assessment_report_closing_content'])) : '';
        if ($closing_content !== '') {
            update_post_meta($post_id, '_report_closing_content', $closing_content);
        } else {
            delete_post_meta($post_id, '_report_closing_content');
        }
    }

    private function save_section_meta($post_id)
    {
        if (! isset($_POST['ar_section_meta_nonce']) || ! wp_verify_nonce($_POST['ar_section_meta_nonce'], 'ar_section_meta_box')) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $graph_key = isset($_POST['assessment_report_graph_key']) ? sanitize_key(wp_unslash($_POST['assessment_report_graph_key'])) : '';
        if ($graph_key !== '') {
            update_post_meta($post_id, '_graph_key', $graph_key);
        } else {
            delete_post_meta($post_id, '_graph_key');
        }

        $section_max_score = isset($_POST['assessment_report_section_max_score']) ? trim((string) wp_unslash($_POST['assessment_report_section_max_score'])) : '';
        if ($section_max_score !== '') {
            update_post_meta($post_id, '_section_max_score', (float) $section_max_score);
        } else {
            delete_post_meta($post_id, '_section_max_score');
        }

        if (! empty($_POST['assessment_report_show_with_zero_score'])) {
            update_post_meta($post_id, '_show_with_zero_score', 1);
        } else {
            delete_post_meta($post_id, '_show_with_zero_score');
        }

        $parent_id = wp_get_post_parent_id($post_id);
        if ($parent_id && $this->get_report_mode($parent_id) === 'score_driven') {
            $this->save_score_rules_meta($post_id);
            return;
        }

        $mappings = [];
        if (! empty($_POST['mappings']) && is_array($_POST['mappings'])) {
            foreach ($_POST['mappings'] as $field_name => $choices) {
                $field_name = sanitize_text_field($field_name);
                if (! $field_name || ! is_array($choices)) {
                    continue;
                }

                foreach ($choices as $choice_value => $value) {
                    $choice_value = sanitize_text_field($choice_value);
                    if ($choice_value === '') {
                        continue;
                    }

                    $points = isset($_POST['points'][$field_name][$choice_value]) ? trim((string) wp_unslash($_POST['points'][$field_name][$choice_value])) : '';
                    $multiplier = isset($_POST['multipliers'][$field_name][$choice_value]) ? trim((string) wp_unslash($_POST['multipliers'][$field_name][$choice_value])) : '';

                    $mappings[$field_name][$choice_value] = [
                        'enabled' => 1,
                        'points' => $points === '' ? 1.0 : (float) $points,
                        'multiplier' => $multiplier === '' ? 1.0 : (float) $multiplier,
                    ];
                }
            }
        }

        if ($mappings) {
            update_post_meta($post_id, '_field_mappings', $mappings);
        } else {
            delete_post_meta($post_id, '_field_mappings');
        }
    }

    private function render_score_rules_meta_box($post, $parent_id)
    {
        $profile_id = get_post_meta($parent_id, '_score_profile_id', true);
        $profile = $profile_id ? Score_Profiles::get_profile($profile_id) : null;
        if (! $profile) {
            echo '<p>' . esc_html__('Select a score profile on the parent report before configuring score-driven section rules.', 'assessment-reports') . '</p>';
            return;
        }

        $saved = get_post_meta($post->ID, '_score_section_rules', true);
        if (! is_array($saved)) {
            $saved = [];
        }

        $always_include = ! empty($saved['always_include']);
        $priority = isset($saved['priority']) ? (int) $saved['priority'] : 0;
        $match_type = isset($saved['match_type']) && $saved['match_type'] === 'any' ? 'any' : 'all';
        $conditions = isset($saved['conditions']) && is_array($saved['conditions']) ? $saved['conditions'] : [];
        if (empty($conditions)) {
            $conditions = [
                [
                    'path' => 'summary.category_key',
                    'operator' => 'equals',
                    'value' => '',
                ],
            ];
        }

        ?>
        <p class="description">
            <?php esc_html_e('This section is selected from the computed score payload instead of raw form answers.', 'assessment-reports'); ?>
        </p>
        <p>
            <label>
                <input type="checkbox" name="score_rules[always_include]" value="1" <?php checked($always_include); ?>>
                <?php esc_html_e('Always include this section', 'assessment-reports'); ?>
            </label>
        </p>
        <p>
            <label for="ar-score-priority-<?php echo esc_attr($post->ID); ?>" class="ar-field-label"><?php esc_html_e('Priority', 'assessment-reports'); ?></label>
            <input
                id="ar-score-priority-<?php echo esc_attr($post->ID); ?>"
                type="number"
                name="score_rules[priority]"
                min="-100"
                max="100"
                step="1"
                value="<?php echo esc_attr($priority); ?>"
            >
            <span class="description"><?php esc_html_e('Higher priority sections are returned earlier in the selected-section helper.', 'assessment-reports'); ?></span>
        </p>
        <p>
            <label for="ar-score-match-type-<?php echo esc_attr($post->ID); ?>" class="ar-field-label"><?php esc_html_e('Condition Matching', 'assessment-reports'); ?></label>
            <select id="ar-score-match-type-<?php echo esc_attr($post->ID); ?>" name="score_rules[match_type]">
                <option value="all" <?php selected($match_type, 'all'); ?>><?php esc_html_e('All conditions must match', 'assessment-reports'); ?></option>
                <option value="any" <?php selected($match_type, 'any'); ?>><?php esc_html_e('Any condition may match', 'assessment-reports'); ?></option>
            </select>
        </p>
        <table class="widefat striped ar-score-rules-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Payload Path', 'assessment-reports'); ?></th>
                    <th><?php esc_html_e('Operator', 'assessment-reports'); ?></th>
                    <th><?php esc_html_e('Value', 'assessment-reports'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($conditions as $index => $condition) : ?>
                    <tr>
                        <td>
                            <input
                                type="text"
                                class="widefat"
                                name="score_rules[conditions][<?php echo esc_attr($index); ?>][path]"
                                value="<?php echo esc_attr($condition['path'] ?? ''); ?>"
                                placeholder="summary.category_key"
                            >
                        </td>
                        <td>
                            <select name="score_rules[conditions][<?php echo esc_attr($index); ?>][operator]">
                                <?php foreach ($this->get_score_rule_operators() as $operator => $label) : ?>
                                    <option value="<?php echo esc_attr($operator); ?>" <?php selected($condition['operator'] ?? 'equals', $operator); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input
                                type="text"
                                class="widefat"
                                name="score_rules[conditions][<?php echo esc_attr($index); ?>][value]"
                                value="<?php echo esc_attr($this->stringify_rule_value($condition['value'] ?? '')); ?>"
                                placeholder="specific_need"
                            >
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php for ($blank = 0; $blank < 2; $blank++) : ?>
                    <?php $row_index = count($conditions) + $blank; ?>
                    <tr>
                        <td><input type="text" class="widefat" name="score_rules[conditions][<?php echo esc_attr($row_index); ?>][path]" value="" placeholder="readiness.percent"></td>
                        <td>
                            <select name="score_rules[conditions][<?php echo esc_attr($row_index); ?>][operator]">
                                <?php foreach ($this->get_score_rule_operators() as $operator => $label) : ?>
                                    <option value="<?php echo esc_attr($operator); ?>" <?php selected('equals', $operator); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" class="widefat" name="score_rules[conditions][<?php echo esc_attr($row_index); ?>][value]" value="" placeholder="70"></td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        <p class="description">
            <?php esc_html_e('Use payload paths such as `summary.category_key`, `summary.percent`, `maslow.values.safety`, `wellness.values.spiritual`, `sdoh.values.access_to_community`, or `readiness.percent`.', 'assessment-reports'); ?>
        </p>
        <p class="description">
            <?php
            printf(
                /* translators: %s: score profile name */
                esc_html__('This section uses score profile "%s".', 'assessment-reports'),
                esc_html($profile['name'] ?? $profile_id)
            );
            ?>
        </p>
        <?php
    }

    private function save_score_rules_meta($post_id)
    {
        $rules = isset($_POST['score_rules']) && is_array($_POST['score_rules']) ? wp_unslash($_POST['score_rules']) : [];
        $conditions = [];

        if (! empty($rules['conditions']) && is_array($rules['conditions'])) {
            foreach ($rules['conditions'] as $condition) {
                if (! is_array($condition)) {
                    continue;
                }

                $path = isset($condition['path']) ? sanitize_text_field($condition['path']) : '';
                $operator = isset($condition['operator']) ? sanitize_key($condition['operator']) : 'equals';
                $raw_value = isset($condition['value']) ? trim((string) $condition['value']) : '';

                if ($path === '' || $raw_value === '') {
                    continue;
                }

                $value = $this->parse_score_rule_value($operator, $raw_value);
                $conditions[] = [
                    'path' => $path,
                    'operator' => $operator,
                    'value' => $value,
                ];
            }
        }

        $payload = [
            'always_include' => ! empty($rules['always_include']) ? 1 : 0,
            'priority' => isset($rules['priority']) ? (int) $rules['priority'] : 0,
            'match_type' => isset($rules['match_type']) && $rules['match_type'] === 'any' ? 'any' : 'all',
            'conditions' => $conditions,
        ];

        if ($payload['always_include'] || ! empty($payload['conditions'])) {
            update_post_meta($post_id, '_score_section_rules', $payload);
        } else {
            delete_post_meta($post_id, '_score_section_rules');
        }
    }

    public function render_ai_personalization_meta_box($post)
    {
        if ($post->post_parent) {
            echo '<p>' . esc_html__('AI personalization only applies to parent report posts.', 'assessment-reports') . '</p>';
            return;
        }

        wp_nonce_field('ar_ai_meta_box', 'ar_ai_meta_nonce');

        $form_id = get_post_meta($post->ID, '_report_form_id', true);
        if (! $form_id) {
            echo '<p>' . esc_html__('Select a Fluent Form on this report before creating AI content blocks.', 'assessment-reports') . '</p>';
            return;
        }

        if (! $this->is_ai_client_ready()) {
            echo '<div class="notice notice-warning inline"><p>' .
                esc_html__('The WP AI Client is not configured or active, so AI personalization cannot be generated yet.', 'assessment-reports') .
                '</p></div>';
            return;
        }

        $ai_blocks = get_post_meta($post->ID, '_ai_content_blocks', true);
        if (! is_array($ai_blocks)) {
            $ai_blocks = [];
        }

        $context_fields = $this->get_context_field_options($form_id);
        $next_index = count($ai_blocks);

        echo '<div class="ar-ai-blocks" data-next-index="' . esc_attr($next_index) . '">';
        foreach ($ai_blocks as $index => $block) {
            echo $this->render_ai_block_row($index, $block, $context_fields);
        }
        echo '</div>';

        echo '<p><button type="button" class="button" id="ar-add-ai-block">' . esc_html__('Add AI Block', 'assessment-reports') . '</button></p>';

        echo '<script type="text/html" id="ar-ai-block-template">';
        echo $this->render_ai_block_row('__INDEX__', [], $context_fields, true);
        echo '</script>';

        if (! $context_fields) {
            echo '<p class="description">' . esc_html__('No checkbox, radio, or select inputs exist on the selected form, so no context fields can be attached.', 'assessment-reports') . '</p>';
        }
    }

    private function render_ai_block_row($index, $block, array $context_fields, $is_template = false)
    {
        $token = $block['token'] ?? '';
        $example = $block['example'] ?? '';
        $instructions = $block['instructions'] ?? '';
        $context_selected = is_array($block['context_fields']) ? $block['context_fields'] : [];
        $include_score = ! empty($block['include_score']);
        $additional_context = $block['additional_context'] ?? '';
        $name_index = $is_template ? '__INDEX__' : $index;

        ob_start();
        ?>
        <div class="ar-ai-block" data-index="<?php echo esc_attr($name_index); ?>">
            <div class="ar-ai-block-header">
                <strong><?php echo esc_html(sprintf(__('AI Block %s', 'assessment-reports'), $is_template ? '%s' : '#' . ($index + 1))); ?></strong>
                <button type="button" class="button-link ar-ai-remove-row"><?php esc_html_e('Remove', 'assessment-reports'); ?></button>
            </div>
            <p>
                <label>
                    <span class="ar-field-label"><?php esc_html_e('Token Name', 'assessment-reports'); ?></span>
                    <input
                        type="text"
                        name="ai_blocks[<?php echo esc_attr($name_index); ?>][token]"
                        value="<?php echo esc_attr($token); ?>"
                        placeholder="<?php esc_attr_e('opening', 'assessment-reports'); ?>"
                        class="widefat"
                    >
                    <span class="description"><?php esc_html_e('Use this token as {ai.TOKEN_NAME} in your content.', 'assessment-reports'); ?></span>
                </label>
            </p>
            <p>
                <label>
                    <span class="ar-field-label"><?php esc_html_e('Example Content', 'assessment-reports'); ?></span>
                    <textarea
                        name="ai_blocks[<?php echo esc_attr($name_index); ?>][example]"
                        rows="8"
                        class="widefat"
                    ><?php echo esc_textarea($example); ?></textarea>
                    <span class="description"><?php esc_html_e('The example paragraph the AI should model.', 'assessment-reports'); ?></span>
                </label>
            </p>
            <p>
                <label>
                    <span class="ar-field-label"><?php esc_html_e('Personalization Instructions', 'assessment-reports'); ?></span>
                    <textarea
                        name="ai_blocks[<?php echo esc_attr($name_index); ?>][instructions]"
                        rows="4"
                        class="widefat"
                    ><?php echo esc_textarea($instructions); ?></textarea>
                    <span class="description"><?php esc_html_e('Tell the AI what to adjust for this user.', 'assessment-reports'); ?></span>
                </label>
            </p>
            <div class="ar-ai-context-fields">
                <p class="ar-field-label"><?php esc_html_e('Context Fields', 'assessment-reports'); ?></p>
                <?php echo $this->render_context_checkboxes($name_index, $context_fields, $context_selected, $is_template); ?>
            </div>
            <p class="ar-ai-checkbox">
                <label>
                    <input
                        type="checkbox"
                        name="ai_blocks[<?php echo esc_attr($name_index); ?>][include_score]"
                        value="1"
                        <?php checked($include_score); ?>
                    >
                    <?php esc_html_e('Include quiz score in prompt', 'assessment-reports'); ?>
                </label>
            </p>
            <p>
                <label>
                    <span class="ar-field-label"><?php esc_html_e('Additional Context', 'assessment-reports'); ?></span>
                    <textarea
                        name="ai_blocks[<?php echo esc_attr($name_index); ?>][additional_context]"
                        rows="3"
                        class="widefat"
                    ><?php echo esc_textarea($additional_context); ?></textarea>
                    <span class="description"><?php esc_html_e('Extra context not included in the form data.', 'assessment-reports'); ?></span>
                </label>
            </p>
        </div>
        <?php
        return $is_template ? trim(preg_replace('/\s+/', ' ', ob_get_clean())) : ob_get_clean();
    }

    private function render_context_checkboxes($index, array $context_fields, array $selected, $is_template = false)
    {
        if (empty($context_fields)) {
            return '<p class="description">' . esc_html__('No context fields available for this form.', 'assessment-reports') . '</p>';
        }

        $html = '<div class="ar-ai-context-list">';
        foreach ($context_fields as $field_name => $label) {
            $is_checked = in_array($field_name, $selected, true);
            $field_name_html = 'ai_blocks[' . esc_attr($index) . '][context_fields][]';
            $html .= '<label class="ar-ai-context-option">';
            $html .= '<input type="checkbox" name="' . $field_name_html . '" value="' . esc_attr($field_name) . '" ' . checked($is_checked, true, false) . '>';
            $html .= esc_html($label);
            $html .= '</label>';
        }
        $html .= '</div>';

        return $html;
    }

    private function save_ai_meta($post_id)
    {
        if (! isset($_POST['ar_ai_meta_nonce']) || ! wp_verify_nonce($_POST['ar_ai_meta_nonce'], 'ar_ai_meta_box')) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $blocks = [];
        if (! empty($_POST['ai_blocks']) && is_array($_POST['ai_blocks'])) {
            foreach ($_POST['ai_blocks'] as $block) {
                $token = isset($block['token']) ? sanitize_text_field($block['token']) : '';
                $token = trim($token);
                $token = trim($token, '{}');
                $token = preg_replace('/[^A-Za-z0-9_]+/', '_', $token);
                $token = trim($token, '_');
                if (! $token) {
                    continue;
                }

                $example = isset($block['example']) ? wp_kses_post(wp_unslash($block['example'])) : '';
                $instructions = isset($block['instructions']) ? sanitize_textarea_field($block['instructions']) : '';
                $context = [];
                if (! empty($block['context_fields']) && is_array($block['context_fields'])) {
                    foreach ($block['context_fields'] as $context_field) {
                        $context_field = sanitize_text_field($context_field);
                        if ($context_field) {
                            $context[] = $context_field;
                        }
                    }
                }

                $blocks[] = [
                    'token' => $token,
                    'example' => $example,
                    'instructions' => $instructions,
                    'context_fields' => $context,
                    'include_score' => ! empty($block['include_score']) ? 1 : 0,
                    'additional_context' => isset($block['additional_context']) ? sanitize_textarea_field($block['additional_context']) : '',
                ];
            }
        }

        if ($blocks) {
            update_post_meta($post_id, '_ai_content_blocks', $blocks);
        } else {
            delete_post_meta($post_id, '_ai_content_blocks');
        }
    }

    private function get_context_field_options($form_id)
    {
        $fields = $this->get_form_fields($form_id);
        if (! $fields) {
            return [];
        }

        $options = [];
        foreach ($fields as $field) {
            $type = $field['element'] ?? $field['type'] ?? '';
            if (! in_array($type, ['input_checkbox', 'input_radio', 'select', 'input_select', 'ratings'], true)) {
                continue;
            }

            $name = $field['attributes']['name'] ?? '';
            if (! $name) {
                continue;
            }

            $label = $field['settings']['label'] ?? $field['attributes']['label'] ?? $name;
            $options[$name] = $label;
        }

        return $options;
    }

    private function is_ai_client_ready()
    {
        return class_exists('\WordPress\\AI_Client\\AI_Client') || class_exists('\WP_AI_Client');
    }

    public function enqueue_assets($hook)
    {
        $screen = get_current_screen();
        if (! $screen || $screen->post_type !== Post_Type::POST_TYPE) {
            return;
        }

        $js_path = ASSESSMENT_REPORTS_PLUGIN_DIR . 'assets/admin.js';
        $css_path = ASSESSMENT_REPORTS_PLUGIN_DIR . 'assets/admin.css';
        $ai_js_path = ASSESSMENT_REPORTS_PLUGIN_DIR . 'assets/admin-ai.js';
        $asset_version = defined('ASSESSMENT_REPORTS_VERSION') ? ASSESSMENT_REPORTS_VERSION : null;

        if (file_exists($js_path)) {
            wp_enqueue_script(
                'assessment-reports-admin',
                ASSESSMENT_REPORTS_PLUGIN_URL . 'assets/admin.js',
                [],
                $asset_version,
                true
            );
        }

        if (file_exists($ai_js_path)) {
            wp_enqueue_script(
                'assessment-reports-admin-ai',
                ASSESSMENT_REPORTS_PLUGIN_URL . 'assets/admin-ai.js',
                [],
                $asset_version,
                true
            );
        }

        if (file_exists($css_path)) {
            wp_enqueue_style(
                'assessment-reports-admin',
                ASSESSMENT_REPORTS_PLUGIN_URL . 'assets/admin.css',
                [],
                $asset_version
            );
        }
    }

    private function get_available_forms()
    {
        $forms = fluentFormApi('forms')->forms([
            'per_page' => 999,
            'sort_by' => 'ASC',
        ]);

        if (! is_array($forms) || empty($forms['data'])) {
            return [];
        }

        return $forms['data'];
    }

    private function get_form_fields($form_id)
    {
        if (! $form_id) {
            return [];
        }

        $form = fluentFormApi('forms')->find($form_id);
        if (! $form || empty($form->form_fields)) {
            return [];
        }

        $fields = json_decode($form->form_fields, true);
        if (! is_array($fields)) {
            return [];
        }

        $flat_fields = [];
        $this->collect_form_fields($fields['fields'] ?? [], $flat_fields);

        return $flat_fields;
    }

    private function collect_form_fields(array $fields, array &$flat_fields)
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            if (isset($field['columns']) && is_array($field['columns'])) {
                foreach ($field['columns'] as $column) {
                    if (is_array($column)) {
                        $this->collect_form_fields($column['fields'] ?? [], $flat_fields);
                    }
                }
            }

            if (isset($field['fields']) && is_array($field['fields'])) {
                $this->collect_form_fields($field['fields'], $flat_fields);
            }

            $name = $field['attributes']['name'] ?? '';
            if ($name !== '') {
                $flat_fields[] = $field;
            }
        }
    }

    private function get_scoreable_field_choices(array $field)
    {
        $choices = [];

        foreach (($field['settings']['advanced_options'] ?? []) as $choice) {
            if (! is_array($choice)) {
                continue;
            }

            $value = isset($choice['value']) ? (string) $choice['value'] : '';
            $label = isset($choice['label']) ? (string) $choice['label'] : $value;
            if ($value === '') {
                continue;
            }

            $choices[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        foreach (($field['settings']['options'] ?? []) as $choice) {
            if (! is_array($choice)) {
                continue;
            }

            $value = isset($choice['value']) ? (string) $choice['value'] : '';
            $label = isset($choice['label']) ? (string) $choice['label'] : $value;
            if ($value === '') {
                continue;
            }

            $choices[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        if (! empty($field['options']) && is_array($field['options'])) {
            foreach ($field['options'] as $value => $label) {
                $value = (string) $value;
                $label = is_scalar($label) ? (string) $label : $value;
                if ($value === '') {
                    continue;
                }

                $choices[] = [
                    'value' => $value,
                    'label' => $label,
                ];
            }
        }

        return $choices;
    }

    private function get_report_mode($post_id)
    {
        $mode = get_post_meta($post_id, '_report_mode', true);

        return $mode === 'score_driven' ? 'score_driven' : 'legacy_response_mapped';
    }

    private function get_score_rule_operators()
    {
        return [
            'equals' => __('Equals', 'assessment-reports'),
            'not_equals' => __('Does Not Equal', 'assessment-reports'),
            'gt' => __('Greater Than', 'assessment-reports'),
            'gte' => __('Greater Than or Equal To', 'assessment-reports'),
            'lt' => __('Less Than', 'assessment-reports'),
            'lte' => __('Less Than or Equal To', 'assessment-reports'),
            'contains' => __('Contains', 'assessment-reports'),
        ];
    }

    private function parse_score_rule_value($operator, $raw_value)
    {
        if (in_array($operator, [ 'gt', 'gte', 'lt', 'lte' ], true)) {
            return (float) $raw_value;
        }

        return sanitize_text_field($raw_value);
    }

    private function stringify_rule_value($value)
    {
        if (is_array($value)) {
            return wp_json_encode($value);
        }

        return (string) $value;
    }
}
