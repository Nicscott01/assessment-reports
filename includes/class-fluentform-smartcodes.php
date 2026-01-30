<?php

namespace AssessmentReports;

if (! defined('ABSPATH')) {
    exit;
}

class FluentForm_Smartcodes
{
    private const ENTRY_HASH_CODE = '{submission.entry_uid_hash}';
    private const ENTRY_HASH_META_CODE = '{submission.meta._entry_uid_hash}';

    public function __construct()
    {
        add_filter('fluentform/all_editor_shortcodes', [$this, 'register_editor_shortcodes'], 10, 2);
        add_filter('fluentform/smartcode_group_submission', [$this, 'resolve_submission_smartcode'], 10, 2);
    }

    public function register_editor_shortcodes($shortcodes, $form = null)
    {
        if (! is_array($shortcodes)) {
            return $shortcodes;
        }

        $label = esc_html__('Entry UID Hash', 'assessment-reports');
        $added = false;

        foreach ($shortcodes as $index => $group) {
            if (! is_array($group) || ! isset($group['shortcodes']) || ! is_array($group['shortcodes'])) {
                continue;
            }

            if (array_key_exists('{submission.id}', $group['shortcodes'])) {
                $shortcodes[$index]['shortcodes'][self::ENTRY_HASH_CODE] = $label;
                $shortcodes[$index]['shortcodes'][self::ENTRY_HASH_META_CODE] = $label;
                $added = true;
                break;
            }
        }

        if (! $added) {
            $shortcodes[] = [
                'title' => esc_html__('Entry Attributes', 'assessment-reports'),
                'shortcodes' => [
                    self::ENTRY_HASH_CODE => $label,
                    self::ENTRY_HASH_META_CODE => $label,
                ],
            ];
        }

        return $shortcodes;
    }

    public function resolve_submission_smartcode($property, $instance)
    {
        if ($property !== 'entry_uid_hash') {
            return $property;
        }

        $entry = is_object($instance) && method_exists($instance, 'getEntry') ? $instance::getEntry() : null;
        if (! $entry || empty($entry->id)) {
            return '';
        }

        $hash = \FluentForm\App\Helpers\Helper::getSubmissionMeta($entry->id, '_entry_uid_hash');
        return $hash ? $hash : '';
    }
}
