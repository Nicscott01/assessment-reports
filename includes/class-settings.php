<?php

namespace AssessmentReports;

if (! defined('ABSPATH')) {
    exit;
}

class Settings_Page
{
    private const PAGE_SLUG = 'assessment-reports-ai';
    private const OPTION_GROUP = 'assessment_reports_ai_options';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_menu()
    {
        $parent = 'edit.php?post_type=' . Post_Type::POST_TYPE;
        add_submenu_page(
            $parent,
            esc_html__('Assessment Reports AI', 'assessment-reports'),
            esc_html__('AI Settings', 'assessment-reports'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function register_settings()
    {
        register_setting(self::OPTION_GROUP, 'ar_ai_temperature', [
            'type' => 'float',
            'sanitize_callback' => function ($value) {
                return $this->sanitize_float($value, 0.7, 0.0, 1.0);
            },
            'default' => 0.7,
        ]);

        register_setting(self::OPTION_GROUP, 'ar_ai_tone', [
            'sanitize_callback' => function ($value) {
                $allowed = array_keys($this->get_tone_options());
                return in_array($value, $allowed, true) ? $value : 'Professional';
            },
            'default' => 'Professional',
        ]);

        register_setting(self::OPTION_GROUP, 'ar_ai_voice', [
            'sanitize_callback' => function ($value) {
                $allowed = ['Second Person', 'Third Person'];
                return in_array($value, $allowed, true) ? $value : 'Second Person';
            },
            'default' => 'Second Person',
        ]);

        register_setting(self::OPTION_GROUP, 'ar_ai_max_tokens', [
            'type' => 'integer',
            'sanitize_callback' => function ($value) {
                return $this->sanitize_int($value, 500, 100, 2000);
            },
            'default' => 500,
        ]);

        register_setting(self::OPTION_GROUP, 'ar_ai_additional_instructions', [
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => '',
        ]);

        add_settings_section(
            'ar_ai_settings_section',
            esc_html__('Global AI Settings', 'assessment-reports'),
            fn () => esc_html_e('Fine-tune the tone and temperature for all AI content generations.', 'assessment-reports'),
            self::PAGE_SLUG
        );

        add_settings_field(
            'ar_ai_temperature',
            esc_html__('Temperature', 'assessment-reports'),
            [$this, 'render_temperature_field'],
            self::PAGE_SLUG,
            'ar_ai_settings_section'
        );

        add_settings_field(
            'ar_ai_tone',
            esc_html__('Tone', 'assessment-reports'),
            [$this, 'render_tone_field'],
            self::PAGE_SLUG,
            'ar_ai_settings_section'
        );

        add_settings_field(
            'ar_ai_voice',
            esc_html__('Voice', 'assessment-reports'),
            [$this, 'render_voice_field'],
            self::PAGE_SLUG,
            'ar_ai_settings_section'
        );

        add_settings_field(
            'ar_ai_max_tokens',
            esc_html__('Max Tokens', 'assessment-reports'),
            [$this, 'render_max_tokens_field'],
            self::PAGE_SLUG,
            'ar_ai_settings_section'
        );

        add_settings_field(
            'ar_ai_additional_instructions',
            esc_html__('Additional Instructions', 'assessment-reports'),
            [$this, 'render_additional_instructions'],
            self::PAGE_SLUG,
            'ar_ai_settings_section'
        );
    }

    public function render_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Assessment Reports AI', 'assessment-reports'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_temperature_field()
    {
        $value = get_option('ar_ai_temperature', 0.7);
        ?>
        <input
            name="ar_ai_temperature"
            type="range"
            min="0"
            max="1"
            step="0.05"
            value="<?php echo esc_attr($value); ?>"
            oninput="this.nextElementSibling.textContent = this.value"
        >
        <span class="ar-ai-range-value"><?php echo esc_html($value); ?></span>
        <p class="description"><?php esc_html_e('0.0 = deterministic, 1.0 = creative.', 'assessment-reports'); ?></p>
        <?php
    }

    public function render_tone_field()
    {
        $value = get_option('ar_ai_tone', 'Professional');
        ?>
        <select name="ar_ai_tone">
            <?php foreach ($this->get_tone_options() as $tone => $label) : ?>
                <option value="<?php echo esc_attr($tone); ?>" <?php selected($value, $tone); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function render_voice_field()
    {
        $value = get_option('ar_ai_voice', 'Second Person');
        $options = ['Second Person' => esc_html__('Second Person (you/your)', 'assessment-reports'), 'Third Person' => esc_html__('Third Person (they/their)', 'assessment-reports')];
        foreach ($options as $key => $label) {
            ?>
            <label style="display:inline-flex;align-items:center;margin-right:16px;">
                <input type="radio" name="ar_ai_voice" value="<?php echo esc_attr($key); ?>" <?php checked($value, $key); ?>>
                <span style="margin-left:6px;"><?php echo esc_html($label); ?></span>
            </label>
            <?php
        }
    }

    public function render_max_tokens_field()
    {
        $value = get_option('ar_ai_max_tokens', 500);
        ?>
        <input
            name="ar_ai_max_tokens"
            type="number"
            min="100"
            max="2000"
            step="50"
            value="<?php echo esc_attr($value); ?>"
        >
        <p class="description"><?php esc_html_e('Limit how much text the AI is allowed to generate per block.', 'assessment-reports'); ?></p>
        <?php
    }

    public function render_additional_instructions()
    {
        $value = get_option('ar_ai_additional_instructions', '');
        ?>
        <textarea name="ar_ai_additional_instructions" rows="5" class="widefat"><?php echo esc_textarea($value); ?></textarea>
        <p class="description"><?php esc_html_e('Global instructions appended to every prompt.', 'assessment-reports'); ?></p>
        <?php
    }

    private function sanitize_float($value, $default, $min, $max)
    {
        $value = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($value === false) {
            return $default;
        }

        return max($min, min($max, $value));
    }

    private function sanitize_int($value, $default, $min, $max)
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if ($value === false) {
            return $default;
        }

        return max($min, min($max, $value));
    }

    private function get_tone_options()
    {
        return [
            'Professional' => esc_html__('Professional', 'assessment-reports'),
            'Conversational' => esc_html__('Conversational', 'assessment-reports'),
            'Technical' => esc_html__('Technical', 'assessment-reports'),
            'Friendly' => esc_html__('Friendly', 'assessment-reports'),
        ];
    }
}
