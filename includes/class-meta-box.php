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
    }

    public function render_report_config_meta_box($post)
    {
        if ($post->post_parent) {
            echo '<p>' . esc_html__('Report configuration only applies to root report posts.', 'assessment-reports') . '</p>';
            return;
        }

        wp_nonce_field('ar_report_meta_box', 'ar_report_meta_nonce');

        $selected_form = get_post_meta($post->ID, '_report_form_id', true);
        $closing_content = get_post_meta($post->ID, '_report_closing_content', true);
        $forms = $this->get_available_forms();
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
        if (! $form_id) {
            echo '<p>' . esc_html__('Please select a Fluent Form on the parent Report first.', 'assessment-reports') . '</p>';
            return;
        }

        $fields = $this->get_form_fields($form_id);
        if (! $fields) {
            echo '<p>' . esc_html__('No radio or checkbox fields were found on the selected Fluent Form.', 'assessment-reports') . '</p>';
            return;
        }

        $saved_mappings = get_post_meta($post->ID, '_field_mappings', true);
        if (! is_array($saved_mappings)) {
            $saved_mappings = [];
        }

        echo '<div class="ar-field-mappings">';
        foreach ($fields as $field) {
            $field_type = $field['element'] ?? $field['type'] ?? '';
            if (! in_array($field_type, ['input_checkbox', 'input_radio'], true)) {
                continue;
            }

            $field_name = $field['attributes']['name'] ?? '';
            $choices = $field['settings']['advanced_options'] ?? [];
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

                $is_checked = isset($saved_mappings[$field_name][$choice_value]);
                $weight_value = $is_checked ? intval($saved_mappings[$field_name][$choice_value]) : 1;
                ?>
                <label class="ar-choice-row">
                    <input type="checkbox" class="ar-mapping-checkbox" name="mappings[<?php echo esc_attr($field_name); ?>][<?php echo esc_attr($choice_value); ?>]" value="1" <?php checked($is_checked); ?>>
                    <span class="ar-choice-label"><?php echo esc_html($choice_label); ?></span>
                    <span class="ar-weight-wrapper">
                        <?php esc_html_e('Weight:', 'assessment-reports'); ?>
                        <input
                            type="number"
                            class="ar-weight-input"
                            min="1"
                            max="10"
                            value="<?php echo esc_attr($weight_value); ?>"
                            name="weights[<?php echo esc_attr($field_name); ?>][<?php echo esc_attr($choice_value); ?>]"
                        >
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

                    $weight = isset($_POST['weights'][$field_name][$choice_value]) ? intval($_POST['weights'][$field_name][$choice_value]) : 1;
                    $weight = max(1, min(10, $weight));
                    $mappings[$field_name][$choice_value] = $weight;
                }
            }
        }

        if ($mappings) {
            update_post_meta($post_id, '_field_mappings', $mappings);
        } else {
            delete_post_meta($post_id, '_field_mappings');
        }
    }

    public function enqueue_assets($hook)
    {
        $screen = get_current_screen();
        if (! $screen || $screen->post_type !== Post_Type::POST_TYPE) {
            return;
        }

        $js_path = ASSESSMENT_REPORTS_PLUGIN_DIR . 'assets/admin.js';
        $css_path = ASSESSMENT_REPORTS_PLUGIN_DIR . 'assets/admin.css';

        if (file_exists($js_path)) {
            wp_enqueue_script(
                'assessment-reports-admin',
                ASSESSMENT_REPORTS_PLUGIN_URL . 'assets/admin.js',
                [],
                filemtime($js_path),
                true
            );
        }

        if (file_exists($css_path)) {
            wp_enqueue_style(
                'assessment-reports-admin',
                ASSESSMENT_REPORTS_PLUGIN_URL . 'assets/admin.css',
                [],
                filemtime($css_path)
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

        return $fields['fields'] ?? [];
    }
}
