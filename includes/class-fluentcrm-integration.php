<?php

namespace AssessmentReports;

use FluentForm\App\Helpers\Helper;
use FluentForm\App\Models\FormMeta;
use FluentForm\App\Services\Integrations\IntegrationManager;
use FluentForm\Framework\Foundation\Application;

if (! defined('ABSPATH')) {
    exit;
}

class FluentCRM_Integration extends IntegrationManager
{
    private const META_KEY_FEED = 'ar_fcrm_feed';
    private const META_KEY_PENDING_APPLIED = 'ar_fcrm_pending_applied';
    private const CONTACT_META_REPORTS = 'ar_ai_reports';
    private const CONTACT_META_LATEST_HASH = 'ar_ai_report_latest_hash';
    private const CONTACT_META_LATEST_URL = 'ar_ai_report_latest_url';
    private const DEFAULT_TAG_SLUG = 'ar-ai-report-ready';
    private const DEFAULT_PENDING_TAG_SLUG = '';
    private const MAX_REPORTS_STORED = 20;
    private const LOG_PREFIX = 'AR FCRM';

    public function __construct(?Application $application = null)
    {
        if (! class_exists(IntegrationManager::class)) {
            return;
        }

        if (! $application instanceof Application && class_exists(Application::class)) {
            $application = Application::getInstance();
        }

        if (! $application instanceof Application) {
            return;
        }

        parent::__construct(
            $application,
            __('Assessment Reports → FluentCRM', 'assessment-reports'),
            'assessment_reports_fcrm',
            'assessment_reports_fcrm_settings',
            'assessment_reports_fcrm_feeds',
            11
        );

        $this->description = __('Apply tag and store report link when AI generation finishes.', 'assessment-reports');
        $this->logo = '';

        $this->registerAdminHooks();

        add_action('assessment_reports_ai_generation_completed', [$this, 'handle_generation_completed'], 10, 3);
        add_action('assessment_reports_submission_pending', [$this, 'handle_submission_pending'], 10, 3);
        add_action('fluent_crm/after_init', [$this, 'register_smartcodes']);
    }

    public function isEnabled()
    {
        // Force enabled; no global toggle needed for this custom integration.
        return true;
    }

    public function isConfigured()
    {
        return true;
    }

    public function getGlobalFields($fields)
    {
        return [
            'logo'             => $this->logo,
            'menu_title'       => __('Assessment Reports → FluentCRM', 'assessment-reports'),
            'menu_description' => __('No global API configuration is required.', 'assessment-reports'),
            'valid_message'    => __('Ready to use.', 'assessment-reports'),
            'invalid_message'  => '',
            'save_button_text' => __('Save', 'assessment-reports'),
            'fields'           => [],
            'hide_on_valid'    => true,
            'discard_settings' => [
                'section_description' => '',
                'button_text'         => '',
                'data'                => [],
                'show_verify'         => false,
            ],
        ];
    }

    public function getGlobalSettings($settings)
    {
        $defaults = [
            'status' => true,
        ];

        return wp_parse_args((array) $settings, $defaults);
    }

    public function saveGlobalSettings($settings)
    {
        wp_send_json_success([
            'message' => __('Settings saved.', 'assessment-reports'),
            'status'  => true,
        ], 200);
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                 => $this->title,
            'logo'                  => $this->logo,
            'is_active'             => true,
            'configure_title'       => __('Ready to use', 'assessment-reports'),
            'global_configure_url'  => '',
            'configure_message'     => __('This integration has no global settings.', 'assessment-reports'),
            'configure_button_text' => __('Configure', 'assessment-reports'),
        ];

        return $integrations;
    }

    public function register_smartcodes()
    {
        if (! function_exists('FluentCrmApi')) {
            return;
        }

        $shortCodes = [
            'latest_url'  => __('Latest Assessment Report URL', 'assessment-reports'),
            'latest_hash' => __('Latest Assessment Report Hash', 'assessment-reports'),
        ];

        $callback = function ($code, $valueKey, $defaultValue, $subscriber) {
            if (! $subscriber) {
                return $defaultValue;
            }

            if ($valueKey === 'latest_url') {
                return fluentcrm_get_subscriber_meta($subscriber->id, self::CONTACT_META_LATEST_URL, $defaultValue);
            }

            if ($valueKey === 'latest_hash') {
                return fluentcrm_get_subscriber_meta($subscriber->id, self::CONTACT_META_LATEST_HASH, $defaultValue);
            }

            return $defaultValue;
        };

        FluentCrmApi('extender')->addSmartCode(
            'assessment_reports',
            __('Assessment Reports', 'assessment-reports'),
            $shortCodes,
            $callback
        );
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'             => '',
            'id'               => '',
            'field_map'        => [],
            'report_id'        => 0,
            'tag_slug'         => self::DEFAULT_TAG_SLUG,
            'pending_tag_slug' => self::DEFAULT_PENDING_TAG_SLUG,
            'enabled'          => true,
        ];
    }

    public function getMergeFields($list, $listId, $formId)
    {
        // No custom merge fields; pass through.
        return $list;
    }

    public function getSettingsFields($settings, $formId)
    {
        $report_options = $this->get_report_options();
        $tag_options = $this->get_tag_options();

        return [
            'fields' => [
                [
                    'key'         => 'name',
                    'label'       => __('Feed Name', 'assessment-reports'),
                    'placeholder' => __('Assessment Report → FluentCRM', 'assessment-reports'),
                    'component'   => 'text',
                    'required'    => true,
                ],
                [
                    'key'                => 'field_map',
                    'label'              => __('Map Fields', 'assessment-reports'),
                    'component'          => 'map_fields',
                    'field_label_remote' => __('FluentCRM Field', 'assessment-reports'),
                    'field_label_local'  => __('Form Field', 'assessment-reports'),
                    'primary_fileds'     => [
                        [
                            'key'           => 'email',
                            'label'         => __('Email', 'assessment-reports'),
                            'required'      => true,
                            'input_options' => 'emails',
                        ],
                        [
                            'key'           => 'first_name',
                            'label'         => __('First Name', 'assessment-reports'),
                            'required'      => false,
                            'input_options' => 'text',
                        ],
                        [
                            'key'           => 'last_name',
                            'label'         => __('Last Name', 'assessment-reports'),
                            'required'      => false,
                            'input_options' => 'text',
                        ],
                    ],
                ],
                [
                    'key'         => 'report_id',
                    'label'       => __('Report', 'assessment-reports'),
                    'component'   => 'select',
                    'options'     => $report_options,
                    'placeholder' => __('Select report', 'assessment-reports'),
                ],
                [
                    'key'         => 'tag_slug',
                    'label'       => __('Tag', 'assessment-reports'),
                    'component'   => 'select',
                    'options'     => $tag_options,
                    'placeholder' => __('Select tag to apply on completion', 'assessment-reports'),
                    'help_text'   => __('Used for tagging and tag-specific meta keys. Leave blank to use default.', 'assessment-reports'),
                ],
                [
                    'key'         => 'pending_tag_slug',
                    'label'       => __('Pending Tag', 'assessment-reports'),
                    'component'   => 'select',
                    'options'     => $tag_options,
                    'placeholder' => __('Select tag to apply while generating', 'assessment-reports'),
                    'help_text'   => __('Applied on submission and removed on completion. Leave blank to skip.', 'assessment-reports'),
                ],
                [
                    'key'            => 'enabled',
                    'label'          => __('Status', 'assessment-reports'),
                    'component'      => 'checkbox-single',
                    'checkbox_label' => __('Enable this feed', 'assessment-reports'),
                ],
            ],
            'integration_title' => $this->title,
        ];
    }

    public function notify($feed, $formData, $entry, $form)
    {
        $settings = $feed['settings'] ?? $feed;

        if (empty($settings['enabled'])) {
            return $entry;
        }

        $payload = $this->build_feed_payload($settings, $form);
        $existing = Helper::getSubmissionMeta($entry->id, self::META_KEY_FEED);
        if (! $existing && $payload) {
            Helper::setSubmissionMeta(
                $entry->id,
                self::META_KEY_FEED,
                wp_json_encode($payload)
            );
        }

        // Mark async feed as completed for FluentForms API Call logs.
        do_action('fluentform/integration_action_result', $feed, 'success', 'completed');

        return $entry;
    }

    public function handle_submission_pending($entry_id, $form_data, $form)
    {
        $entry_id = absint($entry_id);
        if (! $entry_id || empty($form) || empty($form->id)) {
            return;
        }

        $ai_status = Helper::getSubmissionMeta($entry_id, 'ai_generation_status');
        if ($ai_status === 'ready') {
            return;
        }

        if (Helper::getSubmissionMeta($entry_id, self::META_KEY_PENDING_APPLIED)) {
            return;
        }

        $form_id = absint($form->id);
        $feed_settings = $this->get_feed_settings_for_entry($entry_id, $form_id);
        if (! $feed_settings || empty($feed_settings['enabled'])) {
            return;
        }

        $pending_tag_slug = ! empty($feed_settings['pending_tag_slug'])
            ? sanitize_title($feed_settings['pending_tag_slug'])
            : '';
        if (! $pending_tag_slug) {
            return;
        }

        $entry = $this->get_entry($entry_id);
        if (! $entry) {
            return;
        }

        $payload = $this->build_feed_payload($feed_settings, $form);
        $existing_payload = Helper::getSubmissionMeta($entry_id, self::META_KEY_FEED);
        if (! $existing_payload && $payload) {
            Helper::setSubmissionMeta($entry_id, self::META_KEY_FEED, wp_json_encode($payload));
        }

        $email = $this->extract_from_map($entry, $feed_settings, 'email');
        if (! $email) {
            $email = $this->find_first_email($entry);
        }

        if (! $email) {
            $this->log('pending tag no email resolved', compact('entry_id', 'feed_settings'));
            return;
        }

        $first_name = $this->extract_from_map($entry, $feed_settings, 'first_name');
        $last_name  = $this->extract_from_map($entry, $feed_settings, 'last_name');

        $subscriber = $this->get_or_create_subscriber($email, $first_name, $last_name);
        if (! $subscriber) {
            return;
        }

        $pending_title = $feed_settings['pending_tag_title'] ?? $pending_tag_slug;
        $pending_tag_id = $this->ensure_tag($pending_tag_slug, $pending_title);
        if ($pending_tag_id) {
            $subscriber->attachTags([$pending_tag_id]);
            Helper::setSubmissionMeta($entry_id, self::META_KEY_PENDING_APPLIED, 'yes', $form_id);
            $this->log('pending tag attached', [
                'subscriber_id' => $subscriber->id,
                'tag_id'        => $pending_tag_id,
                'tag_slug'      => $pending_tag_slug,
            ]);
        }
    }

    public function handle_generation_completed($report_id, $entry_id, $entry_hash)
    {
        if (! class_exists('\\FluentCrm\\App\\Models\\Subscriber')) {
            return;
        }

        $entry = $this->get_entry($entry_id);
        if (! $entry || empty($entry->form_id)) {
            $this->log('no entry/form', compact('entry_id'));
            return;
        }

        $feed_settings = $this->get_feed_settings_for_entry($entry_id, $entry->form_id);
        if (! $feed_settings || empty($feed_settings['enabled'])) {
            $this->log('feed disabled or missing', compact('entry_id', 'feed_settings'));
            return;
        }

        $email = $this->extract_from_map($entry, $feed_settings, 'email');
        if (! $email) {
            $email = $this->find_first_email($entry);
        }

        if (! $email) {
            $this->log('no email resolved', compact('entry_id', 'feed_settings'));
            return;
        }

        $first_name = $this->extract_from_map($entry, $feed_settings, 'first_name');
        $last_name  = $this->extract_from_map($entry, $feed_settings, 'last_name');

        $this->log('resolved contact', [
            'email'      => $email,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'tag_slug'   => $feed_settings['tag_slug'] ?? '',
        ]);

        $report_url = $this->build_report_url($entry_id, $entry_hash, $feed_settings);

        $subscriber = $this->get_or_create_subscriber($email, $first_name, $last_name);
        if (! $subscriber) {
            $this->log('no subscriber', compact('email', 'entry_id'));
            return;
        }

        $tag_slug = ! empty($feed_settings['tag_slug']) ? sanitize_title($feed_settings['tag_slug']) : self::DEFAULT_TAG_SLUG;
        $tag_title = ! empty($feed_settings['tag_title']) ? $feed_settings['tag_title'] : $tag_slug;

        // Store meta before tag application so automations triggered by tags can read it.
        $this->store_report_meta($subscriber->id, $report_id, $entry_id, $entry_hash, $report_url, $tag_slug);

        $tag_id = $this->ensure_tag($tag_slug, $tag_title);
        if ($tag_id) {
            $subscriber->attachTags([$tag_id]);
            $this->log('tag attached', ['subscriber_id' => $subscriber->id, 'tag_id' => $tag_id, 'tag_slug' => $tag_slug]);
        } else {
            $this->log('tag not created', compact('tag_slug', 'tag_title'));
        }

        $pending_tag_slug = ! empty($feed_settings['pending_tag_slug']) ? sanitize_title($feed_settings['pending_tag_slug']) : '';
        if ($pending_tag_slug) {
            $pending_tag_id = $this->ensure_tag($pending_tag_slug, $feed_settings['pending_tag_title'] ?? $pending_tag_slug);
            if ($pending_tag_id) {
                $subscriber->detachTags([$pending_tag_id]);
                $this->log('pending tag removed', [
                    'subscriber_id' => $subscriber->id,
                    'tag_id'        => $pending_tag_id,
                    'tag_slug'      => $pending_tag_slug,
                ]);
            }
        }
        $this->log('stored meta', [
            'subscriber_id' => $subscriber->id,
            'tag_slug'      => $tag_slug,
            'report_url'    => $report_url,
            'entry_hash'    => $entry_hash,
        ]);
    }

    private function get_form_fields($form_id, $only_types = [])
    {
        $options = [];
        if (! function_exists('fluentFormApi')) {
            return $options;
        }

        try {
            $form = fluentFormApi('forms')->find($form_id);
        } catch (\Exception $e) {
            return $options;
        }

        $fields_json = $form->form_fields ?? '';
        $fields = json_decode($fields_json, true);
        if (! is_array($fields)) {
            return $options;
        }

        foreach ($fields['fields'] ?? [] as $field) {
            $name = $field['attributes']['name'] ?? '';
            $label = $field['settings']['label'] ?? ($field['attributes']['label'] ?? $name);
            $type = $field['attributes']['type'] ?? '';

            if (! $name) {
                continue;
            }

            if ($only_types && ! in_array($type, $only_types, true)) {
                continue;
            }

            $options[$name] = $label ? $label . ' (' . $name . ')' : $name;
        }

        return $options;
    }

    private function build_feed_payload(array $feed, $form)
    {
        $form_id = isset($form->id) ? absint($form->id) : 0;
        if (! $form_id) {
            return [];
        }

        return [
            'feed'    => [
                'name'              => $feed['name'] ?? '',
                'field_map'         => isset($feed['field_map']) ? (array) $feed['field_map'] : [],
                'report_id'         => absint($feed['report_id'] ?? 0),
                'tag_slug'          => ! empty($feed['tag_slug']) ? sanitize_title($feed['tag_slug']) : self::DEFAULT_TAG_SLUG,
                'tag_title'         => $feed['tag_title'] ?? '',
                'pending_tag_slug'  => ! empty($feed['pending_tag_slug']) ? sanitize_title($feed['pending_tag_slug']) : '',
                'pending_tag_title' => $feed['pending_tag_title'] ?? '',
                'email'             => $feed['email'] ?? '',
                'first_name'        => $feed['first_name'] ?? '',
                'last_name'         => $feed['last_name'] ?? '',
                'enabled'           => ! empty($feed['enabled']),
            ],
            'form_id' => $form_id,
        ];
    }

    private function get_report_options()
    {
        $reports = get_posts([
            'post_type'   => Post_Type::POST_TYPE,
            'post_parent' => 0,
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);

        $options = ['' => __('Auto-detect by form', 'assessment-reports')];
        foreach ($reports as $report) {
            $options[$report->ID] = $report->post_title ?: ('Report #' . $report->ID);
        }

        return $options;
    }

    private function get_tag_options()
    {
        $options = ['' => __('Use default tag', 'assessment-reports')];
        $tagClass = '\\FluentCrm\\App\\Models\\Tag';
        if (! class_exists($tagClass)) {
            return $options;
        }

        $tags = $tagClass::orderBy('title', 'asc')->get(['slug', 'title']);
        foreach ($tags as $tag) {
            $options[$tag->slug] = $tag->title ?: $tag->slug;
        }

        return $options;
    }

    private function get_entry($entry_id)
    {
        if (! function_exists('fluentFormApi')) {
            return null;
        }

        try {
            return fluentFormApi('submissions')->find($entry_id);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function get_feed_settings_for_entry($entry_id, $form_id)
    {
        $defaults = $this->getIntegrationDefaults([], $form_id);

        // Prefer feed stored on the entry (ensures we use the config active at submission time).
        $raw = Helper::getSubmissionMeta($entry_id, self::META_KEY_FEED);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $feed = $decoded['feed'] ?? $decoded;
                return wp_parse_args($feed, $defaults);
            }
        }

        // Fallback: current form settings.
        // Fallback: current form meta feeds for this integration.
        if (class_exists(FormMeta::class)) {
            $rows = FormMeta::where('form_id', $form_id)
                ->where('meta_key', $this->settingsKey)
                ->get();

            foreach ($rows as $row) {
                $value = $row->value ?? '';
                if (! $value) {
                    continue;
                }

                $feed = Helper::isJson($value) ? json_decode($value, true) : $value;
                if (! is_array($feed)) {
                    continue;
                }

                $enabled = $feed['enabled'] ?? true;
                if ($enabled === 'true') {
                    $enabled = true;
                } elseif ($enabled === 'false') {
                    $enabled = false;
                }

                if ($enabled) {
                    return wp_parse_args($feed, $defaults);
                }
            }

            if ($rows->count()) {
                $first = $rows->first();
                $value = $first->value ?? '';
                if ($value && Helper::isJson($value)) {
                    $feed = json_decode($value, true);
                    if (is_array($feed)) {
                        return wp_parse_args($feed, $defaults);
                    }
                }
            }
        }

        return $defaults;
    }

    private function extract_field_value($entry, $field_name)
    {
        if (! $field_name) {
            return '';
        }

        // Support FluentForms smartcode formats like {fields.email} or {inputs.name.first}
        if (is_string($field_name) && preg_match('/^{(.+)}$/', $field_name, $matches)) {
            $field_name = $matches[1];
        }

        $path = [];
        if (strpos($field_name, '.') !== false) {
            $parts = array_values(array_filter(explode('.', $field_name), 'strlen'));
            // If prefixed with "fields" or "inputs", drop the prefix for lookup.
            if (in_array($parts[0], ['fields', 'inputs'], true)) {
                array_shift($parts);
            }
            $field_name = $parts[0] ?? $field_name;
            $path = array_slice($parts, 1);
        }

        $response = $entry->response ?? [];
        if (is_string($response)) {
            $response = json_decode($response, true);
        }
        if (is_object($response)) {
            $response = (array) $response;
        }

        $value = $response[$field_name] ?? null;

        if ($path) {
            $value = $this->resolve_path($value, $path);
        }

        if ($value === null && isset($entry->user_inputs[$field_name])) {
            $value = $entry->user_inputs[$field_name];
        }

        if (is_array($value)) {
            return implode(', ', array_filter($value, static fn ($v) => $v !== null && $v !== ''));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function extract_from_map($entry, array $feed_settings, $key)
    {
        $map = isset($feed_settings['field_map']) ? (array) $feed_settings['field_map'] : [];
        $field = $map[$key] ?? ($feed_settings[$key] ?? '');

        // If map_fields returns nested structures, unwrap common shapes.
        if (is_array($field)) {
            if (isset($field['value']) && is_string($field['value'])) {
                $field = $field['value'];
            } elseif (isset($field['key']) && is_string($field['key'])) {
                $field = $field['key'];
            } elseif (isset($field['field']) && is_string($field['field'])) {
                $field = $field['field'];
            } elseif (isset($field['form_field']) && is_string($field['form_field'])) {
                $field = $field['form_field'];
            } elseif (isset($field['name']) && is_string($field['name'])) {
                $field = $field['name'];
            } else {
                foreach ($field as $v) {
                    if (is_string($v)) {
                        $field = $v;
                        break;
                    }
                }
            }
        }

        return $this->extract_field_value($entry, is_string($field) ? $field : '');
    }

    private function resolve_path($value, array $path)
    {
        foreach ($path as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }
            if (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
                continue;
            }
            return null;
        }
        return $value;
    }

    private function find_first_email($entry)
    {
        $candidate_sets = [];
        $response = $entry->response ?? [];
        if (is_string($response)) {
            $response = json_decode($response, true);
        }
        if (is_object($response)) {
            $response = (array) $response;
        }
        if (is_array($response)) {
            $candidate_sets[] = $response;
        }

        if (! empty($entry->user_inputs) && is_array($entry->user_inputs)) {
            $candidate_sets[] = $entry->user_inputs;
        }

        foreach ($candidate_sets as $set) {
            foreach ($set as $value) {
                $email = $this->maybe_email($value);
                if ($email) {
                    return $email;
                }
            }
        }

        return '';
    }

    private function maybe_email($value)
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $email = $this->maybe_email($item);
                if ($email) {
                    return $email;
                }
            }
            return '';
        }

        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);
        return is_email($value) ? $value : '';
    }

    private function build_report_url($entry_id, $entry_hash, array $feed_settings)
    {
        $hash = $entry_hash ?: ar_encode_entry_hash($entry_id);

        $base = '';
        if (! empty($feed_settings['report_id'])) {
            $base = get_permalink(absint($feed_settings['report_id']));
        }

        if (! $base) {
            $derived_report_id = ar_get_report_id_by_entry_id($entry_id);
            if ($derived_report_id) {
                $base = get_permalink($derived_report_id);
            }
        }

        if (! $base) {
            $base = get_report_link_by_entry_id($entry_id, '');
        }

        if (! $base) {
            $base = home_url('/');
        }

        return add_query_arg('entry_hash', $hash, $base);
    }

    private function get_or_create_subscriber($email, $first_name, $last_name)
    {
        $subscriberClass = '\\FluentCrm\\App\\Models\\Subscriber';
        if (! class_exists($subscriberClass)) {
            return null;
        }

        $subscriber = $subscriberClass::where('email', $email)->first();
        if ($subscriber) {
            $update = [];
            if ($first_name && empty($subscriber->first_name)) {
                $update['first_name'] = $first_name;
            }
            if ($last_name && empty($subscriber->last_name)) {
                $update['last_name'] = $last_name;
            }
            if ($update) {
                $subscriber->fill($update);
                $subscriber->save();
            }
            return $subscriber;
        }

        return $subscriberClass::create([
            'email'      => $email,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'status'     => 'subscribed',
        ]);
    }

    private function ensure_tag($slug, $title)
    {
        $tagClass = '\\FluentCrm\\App\\Models\\Tag';
        if (! class_exists($tagClass)) {
            return 0;
        }

        $tag = $tagClass::where('slug', $slug)->first();
        if ($tag) {
            return $tag->id;
        }

        $tag = $tagClass::create([
            'title' => $title ?: $slug,
            'slug'  => $slug,
        ]);

        return $tag ? $tag->id : 0;
    }

    private function store_report_meta($subscriber_id, $report_id, $entry_id, $entry_hash, $report_url, $tag_slug)
    {
        $metaClass = '\\FluentCrm\\App\\Models\\SubscriberMeta';
        if (! class_exists($metaClass)) {
            return;
        }

        $existing = $metaClass::where('subscriber_id', $subscriber_id)
            ->where('key', self::CONTACT_META_REPORTS)
            ->first();

        $reports = [];
        if ($existing && isset($existing->value)) {
            $decoded = maybe_unserialize($existing->value);
            if (is_array($decoded)) {
                $reports = $decoded;
            } else {
                $decoded = json_decode($existing->value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $reports = $decoded;
                }
            }
        }

        $reports = array_values(array_filter($reports, static function ($row) use ($entry_hash) {
            return isset($row['entry_hash']) && $row['entry_hash'] !== $entry_hash;
        }));

        $reports[] = [
            'entry_id'     => $entry_id,
            'entry_hash'   => $entry_hash,
            'report_id'    => $report_id,
            'report_url'   => $report_url,
            'generated_at' => time(),
        ];

        if (count($reports) > self::MAX_REPORTS_STORED) {
            $reports = array_slice($reports, -1 * self::MAX_REPORTS_STORED);
        }

        $metaClass::updateOrCreate(
            [
                'subscriber_id' => $subscriber_id,
                'key'           => self::CONTACT_META_REPORTS,
            ],
            [
                'value' => maybe_serialize($reports),
            ]
        );

        $metaClass::updateOrCreate(
            [
                'subscriber_id' => $subscriber_id,
                'key'           => self::CONTACT_META_LATEST_HASH,
            ],
            [
                'value' => $entry_hash,
            ]
        );

        $metaClass::updateOrCreate(
            [
                'subscriber_id' => $subscriber_id,
                'key'           => self::CONTACT_META_LATEST_URL,
            ],
            [
                'value' => $report_url,
            ]
        );

        if ($tag_slug) {
            $metaClass::updateOrCreate(
                [
                    'subscriber_id' => $subscriber_id,
                    'key'           => self::CONTACT_META_LATEST_HASH . '_' . $tag_slug,
                ],
                [
                    'value' => $entry_hash,
                ]
            );

            $metaClass::updateOrCreate(
                [
                    'subscriber_id' => $subscriber_id,
                    'key'           => self::CONTACT_META_LATEST_URL . '_' . $tag_slug,
                ],
                [
                    'value' => $report_url,
                ]
            );
        }
    }

    private function log($message, array $context = [])
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return;
        }

        $payload = $context ? ' | ' . wp_json_encode($context) : '';
        error_log(self::LOG_PREFIX . ': ' . $message . $payload);
    }
}
