# Assessment Reports

Assessment Reports maps Fluent Forms quiz submissions to dynamic report sections and optionally generates AI-personalized content blocks that can be merged into report content.

## Features
- Custom post type for report sections and parent reports
- Report Group taxonomy for tagging related report sections
- Fluent Forms submission mapping to section scores
- Shortcode rendering for report output
- AI personalization blocks with token replacement (e.g. `{ai.opening}`)
- Caches AI content per submission

## Requirements
- WordPress
- Fluent Forms
- WP AI Client (for AI generation)

## Core Flow
1. A Fluent Forms submission is saved.
2. Report sections are selected/scored and stored as submission meta.
3. The report URL includes an entry hash.
4. The report shortcode renders the parent report plus matched sections.
5. If AI blocks exist, AI content is generated on first load and cached to submission meta.

## Report Links & Hashes
- The report URL uses an entry hash.
- The code supports both:
  - `?entry_hash=` (Fluent Forms `_entry_uid_hash`)
  - `?entry=` (Assessment Reports encoded hash)

## Shortcode
Use `[assessment_report]` on a page to render a report based on the URL entry hash.

## AI Personalization
- AI blocks are configured on parent report posts (Report Configuration > AI Personalization).
- Each block has a token name (e.g. `opening`).
- Use `{ai.opening}` in report content or closing content.
- Generated output is stored in submission meta under `ai_generated_content`.

## Helper Functions
Common helpers live in `includes/helper-functions.php`.

Notable helpers:
- `get_entry_field($field_name, $hash = null, $default = '')`
  - Supports dot notation for nested values (e.g. `name.first`).
- `get_ai_generated_content($key = null)`
  - Fetches AI content for the current request via `$_GET['entry_hash']`.
- `ar_get_ai_generated_content($entry_id)` / `ar_set_ai_generated_content($entry_id, $content)`
  - Low-level AI content access by entry ID.
- `ar_get_score_payload_by_hash($hash = null)`
  - Returns the stored score-driven payload for the current entry/report.
- `ar_get_score_value($path, $hash = null, $default = null)`
  - Returns a nested payload value such as `summary.percent` or `wellness.values.spiritual`.
- `ar_get_chart_data($chart_key, $hash = null)`
  - Returns chart-ready data for `maslow`, `wellness`, `sdoh`, or `readiness`.
- `ar_get_group_score_data($term, $hash = null)`
  - Returns aggregate data for a tagged report group, including `score`, `max_score`, `percent`, and matched section IDs.
- `ar_get_group_score($term, $hash = null, $default = null)` / `get_group_score($term, $hash = null, $default = null)`
  - Returns the combined score for all child sections tagged with the given Report Group term.
- `ar_get_group_percent($term, $hash = null, $default = null)` / `get_group_percent($term, $hash = null, $default = null)`
  - Returns the combined percent for all child sections tagged with the given Report Group term when max scores are available.
- `ar_get_section_score_by_graph_key($graph_key, $hash = null, $default = null)`
  - Returns a stored child section score by its graph key.
- `ar_get_child_section_score_records($parent_id, $entry_hash = null)` / `get_child_section_score_records($parent_id, $entry_hash = null)`
  - Returns an array keyed by child section post ID with `score` and `graph_key`.
- `ar_get_child_section_scores($parent_id, $entry_hash = null)` / `get_child_section_scores($parent_id, $entry_hash = null)`
  - Returns an array keyed by child section post ID with each section's score for the current entry. Unmatched children return `0`.
- `ar_get_overall_score($entry_hash = null, $default = null)` / `get_overall_score($entry_hash = null, $default = null)`
  - Returns the overall score for the provided hash, or falls back to `$_GET['entry_hash']`.
- `ar_get_overall_percent($entry_hash = null, $default = null)` / `get_overall_percent($entry_hash = null, $default = null)`
  - Returns the overall percentage as `sum(section.score) / sum(section.max_score) * 100` for the provided hash, or falls back to `$_GET['entry_hash']`.
- `ar_get_section_scores_by_hash($hash = null)`
  - Returns the full stored legacy child-score dataset for the current entry.
- `ar_get_display_section_ids_by_hash($hash = null)`
  - Returns ordered child IDs for Breakdance `post__in` queries using the parent report display rules.
- `ar_get_section_score_by_graph_key($graph_key, $hash = null, $default = null)`
  - Returns a stored child section score by its graph key.
- `ar_get_section_percent_by_graph_key($graph_key, $hash = null, $default = null)`
  - Returns a stored child section percent by its graph key.

## Legacy Child Scoring
The plugin keeps the original response-mapped report model and now also supports:

- per-choice `points` and `multiplier`
- full child-score storage on submission
- parent-level display limit/order rules
- child-level graph keys, max scores, and zero-score inclusion

## Report Groups
Use the `Report Groups` taxonomy on child report sections to tag related sections together. This lets templates and content filters ask for a combined score without hard-coding section IDs, for example:

```php
\AssessmentReports\get_group_score('financial-wellbeing');
```

The helper accepts a term ID, slug, name, or `WP_Term`. Group aggregation is scoped to the current report entry and sums the matching child section scores for that report.

## Development Notes
- AI generation is triggered on `template_redirect` when viewing a singular report and an entry hash is present.
- If AI content already exists for the entry, generation is skipped.
- The WP AI Client must be initialized on `init`.

## Troubleshooting
- If AI meta boxes do not render, confirm callbacks are public.
- If entry hashes fail to resolve, verify the URL parameter and submission hash storage.
- Check PHP error logs for `AR AI:` debug lines during AI generation.
