<?php

namespace AssessmentReports;

use FluentForm\App\Helpers\Helper;

class Entry_Report_Metabox
{
    public function __construct()
    {
        add_filter('fluentform/submissions_widgets', [$this, 'add_widget'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_widget($widgets, $entry_data, $submission)
    {
        $entry_id = absint($submission->id ?? 0);
        if (! $entry_id) {
            return $widgets;
        }

        $groups = $this->build_report_groups($entry_id);
        if (! $groups) {
            return $widgets;
        }

        $widgets['assessment_reports'] = [
            'title' => __('Assessment Report', 'assessment-reports'),
            'content' => $this->render_widget($entry_id, $groups),
        ];

        return $widgets;
    }

    public function enqueue_assets()
    {
        if (! is_admin()) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $route = isset($_GET['route']) ? sanitize_key(wp_unslash($_GET['route'])) : '';

        if ($page !== 'fluent_forms' || $route !== 'entries') {
            return;
        }

        $css_path = ASSESSMENT_REPORTS_PLUGIN_DIR . 'assets/admin.css';
        $asset_version = defined('ASSESSMENT_REPORTS_VERSION') ? ASSESSMENT_REPORTS_VERSION : null;

        if (file_exists($css_path)) {
            wp_enqueue_style(
                'assessment-reports-admin',
                ASSESSMENT_REPORTS_PLUGIN_URL . 'assets/admin.css',
                [],
                $asset_version
            );
        }
    }

    private function render_widget($entry_id, array $groups)
    {
        $fluent_hash = (string) Helper::getSubmissionMeta($entry_id, '_entry_uid_hash');
        $encoded_hash = ar_encode_entry_hash($entry_id);

        ob_start();
        ?>
        <div class="ar-entry-report-widget">
            <p class="ar-entry-report-widget__intro">
                <?php esc_html_e('Stored report data for this Fluent Forms entry. Use this to compare the submission against the report content and section selection.', 'assessment-reports'); ?>
            </p>

            <div class="ar-entry-report-widget__meta">
                <span><strong><?php esc_html_e('Entry ID:', 'assessment-reports'); ?></strong> <?php echo esc_html((string) $entry_id); ?></span>
                <?php if ($fluent_hash !== '') : ?>
                    <span><strong><?php esc_html_e('Entry Hash:', 'assessment-reports'); ?></strong> <code><?php echo esc_html($fluent_hash); ?></code></span>
                <?php endif; ?>
                <?php if ($encoded_hash !== '') : ?>
                    <span><strong><?php esc_html_e('Encoded Entry:', 'assessment-reports'); ?></strong> <code><?php echo esc_html($encoded_hash); ?></code></span>
                <?php endif; ?>
            </div>

            <?php foreach ($groups as $group) : ?>
                <section class="ar-entry-report-group">
                    <div class="ar-entry-report-group__header">
                        <div>
                            <h4><?php echo esc_html($group['title']); ?></h4>
                            <p class="ar-entry-report-group__meta">
                                <span><?php echo esc_html(sprintf(__('Report ID %d', 'assessment-reports'), $group['report_id'])); ?></span>
                                <?php if (! empty($group['report_url'])) : ?>
                                    <a href="<?php echo esc_url($group['report_url']); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php esc_html_e('Open report', 'assessment-reports'); ?>
                                    </a>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <?php if (! empty($group['section_rows'])) : ?>
                        <div class="ar-entry-report-block">
                            <h5><?php esc_html_e('Report Sections', 'assessment-reports'); ?></h5>
                            <table class="ar-entry-report-table widefat striped">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Section', 'assessment-reports'); ?></th>
                                        <th><?php esc_html_e('Stored Score', 'assessment-reports'); ?></th>
                                        <th><?php esc_html_e('Max Points', 'assessment-reports'); ?></th>
                                        <th><?php esc_html_e('Percent', 'assessment-reports'); ?></th>
                                        <th><?php esc_html_e('Graph Key', 'assessment-reports'); ?></th>
                                        <th><?php esc_html_e('Shown', 'assessment-reports'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['section_rows'] as $row) : ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc_html($row['title']); ?></strong>
                                                <div class="ar-entry-report-table__subtle">#<?php echo esc_html((string) $row['section_id']); ?></div>
                                            </td>
                                            <td><?php echo esc_html($this->format_number($row['score'])); ?></td>
                                            <td><?php echo esc_html($this->format_number($row['max_score'])); ?></td>
                                            <td><?php echo esc_html($this->format_percent($row['percent'])); ?></td>
                                            <td><code><?php echo esc_html($row['graph_key']); ?></code></td>
                                            <td><?php echo $row['is_displayed'] ? esc_html__('Yes', 'assessment-reports') : esc_html__('No', 'assessment-reports'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if (! empty($group['section_rows'])) : ?>
                        <details class="ar-entry-report-details">
                            <summary><?php esc_html_e('Raw section data', 'assessment-reports'); ?></summary>
                            <pre><?php echo esc_html($this->encode_pretty_json($group['raw_section_data'])); ?></pre>
                        </details>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function build_report_groups($entry_id)
    {
        $entry_id = absint($entry_id);
        if (! $entry_id) {
            return [];
        }

        $report_ids = [];
        $canonical_report_id = ar_get_report_id_by_entry_id($entry_id);
        $section_scores = ar_get_section_scores_by_entry_id($entry_id);
        $display_sections = get_top_sections_by_entry_id($entry_id);

        foreach ($section_scores as $record) {
            $parent_id = absint($record['parent_id'] ?? 0);
            if ($parent_id) {
                $report_ids[ $parent_id ] = $parent_id;
            }
        }

        foreach ((array) $display_sections as $record) {
            $parent_id = absint($record['parent_id'] ?? 0);
            if ($parent_id) {
                $report_ids[ $parent_id ] = $parent_id;
            }
        }

        if ($canonical_report_id) {
            $report_ids[ $canonical_report_id ] = $canonical_report_id;
        }

        if (! $report_ids) {
            return [];
        }

        $groups = [];

        foreach (array_values($report_ids) as $report_id) {
            $group = $this->build_single_group(
                $entry_id,
                $report_id,
                $section_scores,
                is_array($display_sections) ? $display_sections : [],
                $canonical_report_id
            );

            if (! $group) {
                continue;
            }

            $groups[] = $group;
        }

        return $groups;
    }

    private function build_single_group($entry_id, $report_id, array $section_scores, array $display_sections, $canonical_report_id)
    {
        $report_id = absint($report_id);
        if (! $report_id) {
            return [];
        }

        $report_post = get_post($report_id);
        if (! $report_post) {
            return [];
        }

        $display_lookup = [];
        foreach ($display_sections as $record) {
            $section_id = absint($record['section_id'] ?? 0);
            $parent_id = absint($record['parent_id'] ?? 0);
            if ($section_id && $parent_id === $report_id) {
                $display_lookup[ $section_id ] = $record;
            }
        }

        $section_rows = [];
        $raw_section_data = [];

        $report_records = [];

        foreach ($section_scores as $record) {
            if (absint($record['parent_id'] ?? 0) !== $report_id) {
                continue;
            }

            $section_id = absint($record['section_id'] ?? 0);
            if (! $section_id) {
                continue;
            }

            $report_records[ $section_id ] = $record;
        }

        if (! $report_records) {
            foreach ($display_lookup as $section_id => $record) {
                $report_records[ $section_id ] = $record;
            }
        }

        foreach ($report_records as $section_id => $record) {
            $section_post = get_post($section_id);
            if (! $section_post) {
                continue;
            }

            $max_score = array_key_exists('max_score', $record) && $record['max_score'] !== null
                ? (float) $record['max_score']
                : null;

            if ($max_score === null) {
                $configured_max = get_post_meta($section_id, '_section_max_score', true);
                if ($configured_max !== '') {
                    $max_score = (float) $configured_max;
                }
            }

            $percent = array_key_exists('percent', $record) && $record['percent'] !== null
                ? (float) $record['percent']
                : null;

            if ($percent === null && $max_score) {
                $percent = round((((float) ($record['score'] ?? 0)) / $max_score) * 100, 2);
            }

            $row = [
                'section_id' => $section_id,
                'title' => $section_post->post_title ?: sprintf(__('Section %d', 'assessment-reports'), $section_id),
                'score' => (float) ($record['score'] ?? 0),
                'max_score' => $max_score,
                'percent' => $percent,
                'graph_key' => (string) ($record['graph_key'] ?? ''),
                'is_displayed' => isset($display_lookup[ $section_id ]),
                'question_points' => $record['question_points'] ?? [],
            ];

            $section_rows[] = $row;
            $raw_section_data[] = $record;
        }

        usort($section_rows, static function ($left, $right) {
            $left_displayed = ! empty($left['is_displayed']) ? 1 : 0;
            $right_displayed = ! empty($right['is_displayed']) ? 1 : 0;

            if ($left_displayed !== $right_displayed) {
                return $right_displayed <=> $left_displayed;
            }

            return strcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        });

        return [
            'report_id' => $report_id,
            'title' => $report_post->post_title ?: sprintf(__('Report %d', 'assessment-reports'), $report_id),
            'report_url' => $this->get_report_url($report_id, $entry_id),
            'section_rows' => $section_rows,
            'raw_section_data' => $raw_section_data,
        ];
    }

    private function get_report_url($report_id, $entry_id)
    {
        $report_id = absint($report_id);
        $entry_id = absint($entry_id);

        if (! $report_id || ! $entry_id) {
            return '';
        }

        $report_permalink = get_permalink($report_id);
        if (! $report_permalink) {
            return '';
        }

        $fluent_hash = (string) Helper::getSubmissionMeta($entry_id, '_entry_uid_hash');
        if ($fluent_hash !== '') {
            return add_query_arg('entry_hash', $fluent_hash, $report_permalink);
        }

        $encoded_hash = ar_encode_entry_hash($entry_id);

        return $encoded_hash !== ''
            ? add_query_arg('entry', $encoded_hash, $report_permalink)
            : '';
    }

    private function format_number($value)
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $value = (float) $value;

        if ((float) (int) $value === $value) {
            return number_format_i18n($value, 0);
        }

        return number_format_i18n($value, 2);
    }

    private function format_percent($value)
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format_i18n((float) $value, 2) . '%';
    }

    private function encode_pretty_json($value)
    {
        $encoded = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }
}
