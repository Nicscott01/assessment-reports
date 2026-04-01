# Score Profiles

Score Profiles power the `score_driven` mode in `assessment-reports-dev`.

They answer four questions:

1. Which Fluent Forms fields contribute to scores?
2. Which report dimension does each answer affect?
3. How is the top summary score calculated?
4. Which chart datasets and section-selection values should be stored on the submission?

Profiles are edited in WordPress at:

- `Reports > Score Profiles`

Each profile is saved as one JSON document in the `Profile Definition JSON` field.

## How It Works

When a parent report is set to `Score Driven` and assigned a score profile:

1. A Fluent Forms submission is received.
2. Each `field_rule` maps form answers into numeric values.
3. Those values are aggregated into chart groups like `maslow`, `wellness`, `sdoh`, and `readiness`.
4. A top-level `summary` score is calculated from configured source paths.
5. The full computed payload is stored in submission meta as `ar_score_payload`.
6. Child report sections are selected from `_score_section_rules` using values from that stored payload.

## Top-Level Structure

Each profile JSON should look like this:

```json
{
  "focus_field": "q47_focus_area",
  "summary": {
    "label": "SpecificNeed",
    "max_score": 28.9,
    "sources": [],
    "bands": []
  },
  "charts": {
    "maslow": {},
    "wellness": {},
    "sdoh": {},
    "readiness": {}
  },
  "field_rules": []
}
```

## `focus_field`

Optional.

This is the Fluent Forms field name whose submitted value should be stored as:

- `focus_area`

Example:

```json
{
  "focus_field": "q47_please_select_one_dimension_of_health"
}
```

## `summary`

Defines the top banner/summary score.

### Keys

- `label`: Human-readable label for the summary block.
- `max_score`: The maximum possible summary score.
- `sources`: Weighted inputs used to calculate the summary score.
- `bands`: Named ranges used to classify the result.

### `summary.sources`

Each source has:

- `path`: A stored payload path.
- `weight`: Multiplier applied to that value.

Example:

```json
{
  "summary": {
    "label": "SpecificNeed",
    "max_score": 28.9,
    "sources": [
      { "path": "maslow.total", "weight": 0.25 },
      { "path": "wellness.total", "weight": 0.25 },
      { "path": "sdoh.total", "weight": 0.25 },
      { "path": "readiness.score", "weight": 0.25 }
    ],
    "bands": [
      { "key": "urgent_need", "label": "UrgentNeed", "min": 0, "max": 16 },
      { "key": "strategic_need", "label": "StrategicNeed", "min": 16.1, "max": 22.7 },
      { "key": "specific_need", "label": "SpecificNeed", "min": 22.8, "max": 28.9 }
    ]
  }
}
```

### Common Summary Source Paths

These are useful paths after chart totals are computed:

- `maslow.total`
- `maslow.percent`
- `wellness.total`
- `wellness.percent`
- `sdoh.total`
- `sdoh.percent`
- `readiness.score`
- `readiness.percent`
- `wellness.values.spiritual`
- `maslow.values.safety`

## `charts`

Defines the stored dataset for each chart/helper group.

The engine currently expects these chart keys:

- `maslow`
- `wellness`
- `sdoh`
- `readiness`

Each chart supports:

- `label`: Human-readable label
- `max`: Default max value for dimensions in that chart
- `aggregation`: How multiple hits for one dimension are combined
- `dimensions`: The dimensions that belong to the chart
- `bands`: Optional score bands, mainly useful for `readiness`

### `aggregation`

Allowed values:

- `sum`
- `average`
- `max`
- `min`
- `last`

### `dimensions`

Each dimension entry supports:

- `key`: Stored key used in payload paths
- `label`: Human-readable label
- `max`: Optional dimension-level max override

Example:

```json
{
  "charts": {
    "wellness": {
      "label": "Your Health",
      "max": 5,
      "aggregation": "average",
      "dimensions": [
        { "key": "social", "label": "Social" },
        { "key": "emotional", "label": "Emotional" },
        { "key": "occupational", "label": "Occupational" },
        { "key": "spiritual", "label": "Spiritual" },
        { "key": "physical", "label": "Physical" },
        { "key": "environmental", "label": "Environmental" },
        { "key": "intellectual", "label": "Intellectual" },
        { "key": "financial", "label": "Financial" }
      ],
      "bands": []
    }
  }
}
```

### `readiness.bands`

These are used to set:

- `readiness.category_key`
- `readiness.label`

Example:

```json
{
  "charts": {
    "readiness": {
      "label": "Readiness Score",
      "max": 100,
      "aggregation": "average",
      "dimensions": [
        { "key": "score", "label": "Readiness Score" }
      ],
      "bands": [
        { "key": "not_ready", "label": "Not Ready", "min": 0, "max": 39.99 },
        { "key": "needs_encouragement", "label": "Needs Encouragement", "min": 40, "max": 69.99 },
        { "key": "ready", "label": "I'm ready.", "min": 70, "max": 100 }
      ]
    }
  }
}
```

## `field_rules`

This is the most important part of the profile.

Each rule maps one Fluent Forms field into one score path.

### Keys

- `field`: Fluent Forms field name
- `path`: Target score path inside a chart, like `wellness.spiritual`
- `value_map`: Mapping from submitted option labels to numeric values
- `aggregation`: How multiple selected answers from the same field are combined
- `multiplier`: Optional numeric multiplier applied after aggregation

### Important Notes

- `field` must match the actual Fluent Forms field `name`, not the label shown to users.
- `path` should normally point to `chart_key.dimension_key`.
- `value_map` keys should match the submitted value or label from Fluent Forms.
- If a value is not found in `value_map`, it contributes nothing.

### Example Rules

```json
{
  "field_rules": [
    {
      "field": "q33_emotional_health",
      "path": "wellness.emotional",
      "value_map": {
        "1/poor": 1,
        "2": 2,
        "3": 3,
        "4": 4,
        "5/great": 5
      },
      "aggregation": "average",
      "multiplier": 1
    },
    {
      "field": "q07_are_you_able_to_breathe_without_trouble",
      "path": "maslow.physiological",
      "value_map": {
        "No": 0,
        "Yes": 0.4
      },
      "aggregation": "last",
      "multiplier": 1
    },
    {
      "field": "q53_neighborhood",
      "path": "sdoh.access_to_community",
      "value_map": {
        "1/poor": 1,
        "2": 2,
        "3": 3,
        "4": 4,
        "5/great": 5
      },
      "aggregation": "average",
      "multiplier": 1
    }
  ]
}
```

## Complete Starter Example

This is a simplified but valid starter profile:

```json
{
  "focus_field": "q47_focus_dimension",
  "summary": {
    "label": "SpecificNeed",
    "max_score": 28.9,
    "sources": [
      { "path": "maslow.total", "weight": 1 },
      { "path": "wellness.total", "weight": 1 },
      { "path": "sdoh.total", "weight": 1 },
      { "path": "readiness.score", "weight": 0.289 }
    ],
    "bands": [
      { "key": "urgent_need", "label": "UrgentNeed", "min": 0, "max": 16 },
      { "key": "strategic_need", "label": "StrategicNeed", "min": 16.1, "max": 22.7 },
      { "key": "specific_need", "label": "SpecificNeed", "min": 22.8, "max": 28.9 }
    ]
  },
  "charts": {
    "maslow": {
      "label": "Hierarchy of Need",
      "max": 5.75,
      "aggregation": "average",
      "dimensions": [
        { "key": "physiological", "label": "Physiological" },
        { "key": "safety", "label": "Safety" },
        { "key": "belonging", "label": "Belonging" },
        { "key": "esteem", "label": "Esteem" },
        { "key": "purpose", "label": "Purpose" }
      ],
      "bands": []
    },
    "wellness": {
      "label": "Your Health",
      "max": 5,
      "aggregation": "average",
      "dimensions": [
        { "key": "social", "label": "Social" },
        { "key": "emotional", "label": "Emotional" },
        { "key": "occupational", "label": "Occupational" },
        { "key": "spiritual", "label": "Spiritual" },
        { "key": "physical", "label": "Physical" },
        { "key": "environmental", "label": "Environmental" },
        { "key": "intellectual", "label": "Intellectual" },
        { "key": "financial", "label": "Financial" }
      ],
      "bands": []
    },
    "sdoh": {
      "label": "Social Determinants of Health",
      "max": 5,
      "aggregation": "average",
      "dimensions": [
        { "key": "access_to_financial_resources", "label": "Access to Financial Resources" },
        { "key": "supportive_community", "label": "Supportive Community" },
        { "key": "overall_health", "label": "Overall Health" },
        { "key": "access_to_community", "label": "Access to Community" },
        { "key": "healthcare_experience", "label": "Healthcare Experience" },
        { "key": "access_to_information", "label": "Access to Information" }
      ],
      "bands": []
    },
    "readiness": {
      "label": "Readiness Score",
      "max": 100,
      "aggregation": "average",
      "dimensions": [
        { "key": "score", "label": "Readiness Score" }
      ],
      "bands": [
        { "key": "not_ready", "label": "Not Ready", "min": 0, "max": 39.99 },
        { "key": "needs_encouragement", "label": "Needs Encouragement", "min": 40, "max": 69.99 },
        { "key": "ready", "label": "I'm ready.", "min": 70, "max": 100 }
      ]
    }
  },
  "field_rules": [
    {
      "field": "q33_emotional_health",
      "path": "wellness.emotional",
      "value_map": {
        "1/poor": 1,
        "2": 2,
        "3": 3,
        "4": 4,
        "5/great": 5
      },
      "aggregation": "average",
      "multiplier": 1
    },
    {
      "field": "q48_readiness_satisfaction",
      "path": "readiness.score",
      "value_map": {
        "1/poor": 20,
        "2": 40,
        "3": 60,
        "4": 80,
        "5/great": 100
      },
      "aggregation": "average",
      "multiplier": 1
    }
  ]
}
```

## How To Build a Profile

Use this order:

1. Create the report and set it to `Score Driven`.
2. Identify the exact Fluent Forms field names you want to score.
3. Define the chart dimensions you need.
4. Add one `field_rule` per scored field.
5. Configure `summary.sources` after the chart paths are stable.
6. Add `summary.bands` and `readiness.bands`.
7. Save the profile.
8. Assign the profile to the parent report.
9. Add child section rules that target stored payload paths like `summary.category_key` or `readiness.percent`.

## Stored Payload Shape

After submission, helpers read from `ar_score_payload`.

Typical keys:

- `summary.score`
- `summary.max_score`
- `summary.percent`
- `summary.category_key`
- `summary.category_label`
- `summary.range_label`
- `focus_area`
- `maslow.values.physiological`
- `wellness.values.spiritual`
- `sdoh.values.access_to_community`
- `readiness.score`
- `readiness.percent`
- `readiness.category_key`
- `readiness.label`
- `selected_section_ids`

## Using It In Templates

Useful helpers:

- `ar_get_score_payload_by_hash($hash = null)`
- `ar_get_score_value($path, $hash = null, $default = null)`
- `ar_get_chart_data($chart_key, $hash = null)`
- `ar_get_selected_section_ids_by_hash($hash = null)`
- `ar_get_selected_sections_by_hash($hash = null)`

Examples:

```php
$summary_percent = \AssessmentReports\ar_get_score_value('summary.percent');
$summary_label = \AssessmentReports\ar_get_score_value('summary.category_label');
$readiness = \AssessmentReports\ar_get_chart_data('readiness');
$wellness = \AssessmentReports\ar_get_chart_data('wellness');
```

## Section Rule Paths

Child section rules should target values from the stored payload.

Good examples:

- `summary.category_key`
- `summary.percent`
- `focus_area`
- `readiness.category_key`
- `readiness.percent`
- `wellness.values.spiritual`
- `maslow.values.safety`
- `sdoh.values.access_to_community`

## Troubleshooting

If a profile saves but produces no useful report data:

- Confirm `field` names match the actual Fluent Forms field names.
- Confirm `value_map` keys match the actual submitted values.
- Confirm `path` points to a defined chart and dimension.
- Confirm the parent report is set to `Score Driven`.
- Confirm the parent report has a `Score Profile` selected.
- Reprocess a test submission and inspect the payload with:

```bash
wp assessment-reports dump-score-payload --entry=<ENTRY_ID>
```
