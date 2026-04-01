<?php

namespace AssessmentReports;

if (! defined('ABSPATH')) {
    exit;
}

class Score_Engine
{
    private $profile;

    public function __construct(array $profile)
    {
        $this->profile = $profile;
    }

    public function compute_payload(array $responses)
    {
        $profile_definition = $this->profile['definition'] ?? [];
        if (! is_array($profile_definition) || empty($profile_definition['charts']) || empty($profile_definition['summary'])) {
            return [];
        }

        $chart_buckets = [];
        foreach ($profile_definition['charts'] as $chart_key => $chart) {
            foreach (($chart['dimensions'] ?? []) as $dimension) {
                if (empty($dimension['key'])) {
                    continue;
                }

                $chart_buckets[ $chart_key . '.' . $dimension['key'] ] = [];
            }
        }

        foreach (($profile_definition['field_rules'] ?? []) as $rule) {
            $field = $rule['field'] ?? '';
            $path = $rule['path'] ?? '';
            if ($field === '' || $path === '') {
                continue;
            }

            $raw_value = $responses[ $field ] ?? null;
            if ($raw_value === null || $raw_value === '') {
                continue;
            }

            $scores = $this->map_rule_values($raw_value, $rule['value_map'] ?? []);
            if (empty($scores)) {
                continue;
            }

            $rule_score = $this->aggregate_values($scores, $rule['aggregation'] ?? 'average');
            $rule_score = round((float) $rule_score * (float) ($rule['multiplier'] ?? 1), 4);

            if (! isset($chart_buckets[ $path ])) {
                $chart_buckets[ $path ] = [];
            }

            $chart_buckets[ $path ][] = $rule_score;
        }

        $payload = [
            'profile_id' => $this->profile['id'] ?? '',
            'profile_name' => $this->profile['name'] ?? '',
            'focus_area' => '',
        ];

        $focus_field = $profile_definition['focus_field'] ?? '';
        if ($focus_field !== '' && isset($responses[ $focus_field ])) {
            $payload['focus_area'] = $this->stringify_value($responses[ $focus_field ]);
        }

        foreach ($profile_definition['charts'] as $chart_key => $chart) {
            $payload[ $chart_key ] = $this->build_chart_payload($chart_key, $chart, $chart_buckets);
        }

        $payload['summary'] = $this->build_summary_payload($payload, $profile_definition['summary']);

        if (isset($payload['readiness']['score'])) {
            $payload['readiness']['category'] = $this->match_band($payload['readiness']['score'], $profile_definition['charts']['readiness']['bands'] ?? []);
            $payload['readiness']['percent'] = $this->calculate_percent(
                $payload['readiness']['score'],
                $profile_definition['charts']['readiness']['max'] ?? 100
            );
            $payload['readiness']['label'] = $payload['readiness']['category']['label'] ?? '';
            $payload['readiness']['category_key'] = $payload['readiness']['category']['key'] ?? '';
        }

        return $payload;
    }

    public function select_sections($report_id, array $payload)
    {
        $sections = get_posts([
            'post_type' => Post_Type::POST_TYPE,
            'post_parent' => absint($report_id),
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => [
                'menu_order' => 'ASC',
                'date' => 'ASC',
            ],
        ]);

        if (! $sections) {
            return [];
        }

        $selected = [];

        foreach ($sections as $section) {
            $rules = get_post_meta($section->ID, '_score_section_rules', true);
            if (! is_array($rules)) {
                continue;
            }

            $always_include = ! empty($rules['always_include']);
            $priority = isset($rules['priority']) ? (int) $rules['priority'] : 0;
            $match_type = isset($rules['match_type']) && $rules['match_type'] === 'any' ? 'any' : 'all';
            $conditions = isset($rules['conditions']) && is_array($rules['conditions']) ? $rules['conditions'] : [];

            if (! $always_include && empty($conditions)) {
                continue;
            }

            if (! $always_include && ! $this->matches_conditions($payload, $conditions, $match_type)) {
                continue;
            }

            $selected[] = [
                'section_id' => $section->ID,
                'score' => $priority,
                'parent_id' => absint($report_id),
                'priority' => $priority,
                'menu_order' => (int) $section->menu_order,
            ];
        }

        usort($selected, static function ($left, $right) {
            if (($left['priority'] ?? 0) !== ($right['priority'] ?? 0)) {
                return ($right['priority'] ?? 0) <=> ($left['priority'] ?? 0);
            }

            if (($left['menu_order'] ?? 0) !== ($right['menu_order'] ?? 0)) {
                return ($left['menu_order'] ?? 0) <=> ($right['menu_order'] ?? 0);
            }

            return ($left['section_id'] ?? 0) <=> ($right['section_id'] ?? 0);
        });

        return $selected;
    }

    private function build_chart_payload($chart_key, array $chart, array $chart_buckets)
    {
        $values = [];
        $items = [];
        $total = 0.0;
        $total_max = 0.0;

        foreach (($chart['dimensions'] ?? []) as $dimension) {
            $dimension_key = $dimension['key'] ?? '';
            if ($dimension_key === '') {
                continue;
            }

            $bucket_key = $chart_key . '.' . $dimension_key;
            $raw_scores = $chart_buckets[ $bucket_key ] ?? [];
            $value = $this->aggregate_values($raw_scores, $chart['aggregation'] ?? 'average');
            $value = round((float) $value, 2);
            $dimension_max = isset($dimension['max']) && $dimension['max'] !== null ? (float) $dimension['max'] : (float) ($chart['max'] ?? 0);

            $values[ $dimension_key ] = $value;
            $items[] = [
                'key' => $dimension_key,
                'label' => $dimension['label'] ?? $dimension_key,
                'value' => $value,
                'max' => $dimension_max,
                'percent' => $this->calculate_percent($value, $dimension_max),
            ];

            $total += $value;
            $total_max += $dimension_max;
        }

        return [
            'label' => $chart['label'] ?? ucfirst($chart_key),
            'max' => (float) ($chart['max'] ?? 0),
            'values' => $values,
            'items' => $items,
            'total' => round($total, 2),
            'max_total' => round($total_max, 2),
            'percent' => $this->calculate_percent($total, $total_max),
            'bands' => $chart['bands'] ?? [],
        ];
    }

    private function build_summary_payload(array $payload, array $summary)
    {
        $score = 0.0;
        foreach (($summary['sources'] ?? []) as $source) {
            $path_value = ar_get_nested_value($payload, explode('.', (string) ($source['path'] ?? '')));
            if ($path_value === null || $path_value === '') {
                continue;
            }

            $score += (float) $path_value * (float) ($source['weight'] ?? 1);
        }

        $score = round($score, 2);
        $max_score = round((float) ($summary['max_score'] ?? 0), 2);
        $band = $this->match_band($score, $summary['bands'] ?? []);

        return [
            'label' => $summary['label'] ?? 'Summary',
            'score' => $score,
            'max_score' => $max_score,
            'percent' => $this->calculate_percent($score, $max_score),
            'category_key' => $band['key'] ?? '',
            'category_label' => $band['label'] ?? '',
            'range_label' => isset($band['min'], $band['max']) ? $band['min'] . ' - ' . $band['max'] : '',
            'band' => $band,
        ];
    }

    private function matches_conditions(array $payload, array $conditions, $match_type)
    {
        $results = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $path = isset($condition['path']) ? (string) $condition['path'] : '';
            $operator = isset($condition['operator']) ? sanitize_key((string) $condition['operator']) : 'equals';
            $value = $condition['value'] ?? null;

            if ($path === '') {
                continue;
            }

            $actual = ar_get_nested_value($payload, explode('.', $path));
            $results[] = $this->compare($actual, $operator, $value);
        }

        if (empty($results)) {
            return false;
        }

        return $match_type === 'any'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    private function compare($actual, $operator, $expected)
    {
        switch ($operator) {
            case 'not_equals':
                return (string) $actual !== (string) $expected;
            case 'gt':
                return (float) $actual > (float) $expected;
            case 'gte':
                return (float) $actual >= (float) $expected;
            case 'lt':
                return (float) $actual < (float) $expected;
            case 'lte':
                return (float) $actual <= (float) $expected;
            case 'between':
                if (! is_array($expected)) {
                    return false;
                }

                $min = isset($expected['min']) ? (float) $expected['min'] : 0.0;
                $max = isset($expected['max']) ? (float) $expected['max'] : 0.0;

                return (float) $actual >= $min && (float) $actual <= $max;
            case 'in':
                return is_array($expected) ? in_array((string) $actual, array_map('strval', $expected), true) : false;
            case 'contains':
                return is_string($actual) && is_string($expected) ? strpos($actual, $expected) !== false : false;
            case 'equals':
            default:
                return (string) $actual === (string) $expected;
        }
    }

    private function map_rule_values($raw_value, array $value_map)
    {
        $values = is_array($raw_value) ? $raw_value : [ $raw_value ];
        $scores = [];

        foreach ($values as $value) {
            $value_string = $this->stringify_value($value);
            if ($value_string === '') {
                continue;
            }

            $mapped = $this->lookup_mapped_value($value_string, $value_map);
            if ($mapped === null) {
                continue;
            }

            $scores[] = (float) $mapped;
        }

        return $scores;
    }

    private function lookup_mapped_value($value, array $value_map)
    {
        if (array_key_exists($value, $value_map)) {
            return $value_map[ $value ];
        }

        $normalized = strtolower(trim((string) $value));
        foreach ($value_map as $label => $mapped_value) {
            if ($normalized === strtolower(trim((string) $label))) {
                return $mapped_value;
            }
        }

        return null;
    }

    private function aggregate_values(array $values, $aggregation)
    {
        if (empty($values)) {
            return 0.0;
        }

        switch ($aggregation) {
            case 'sum':
                return array_sum($values);
            case 'max':
                return max($values);
            case 'min':
                return min($values);
            case 'last':
                return end($values);
            case 'average':
            default:
                return array_sum($values) / count($values);
        }
    }

    private function match_band($score, array $bands)
    {
        foreach ($bands as $band) {
            $min = isset($band['min']) ? (float) $band['min'] : 0.0;
            $max = isset($band['max']) ? (float) $band['max'] : 0.0;
            if ((float) $score >= $min && (float) $score <= $max) {
                return $band;
            }
        }

        return [];
    }

    private function calculate_percent($score, $max)
    {
        $max = (float) $max;
        if ($max <= 0) {
            return 0.0;
        }

        return round(((float) $score / $max) * 100, 2);
    }

    private function stringify_value($value)
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }
}
