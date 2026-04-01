# Assessment Reports

Assessment Reports maps Fluent Forms quiz submissions to dynamic report sections and optionally generates AI-personalized content blocks that can be merged into report content.

## Features
- Custom post type for report sections and parent reports
- Fluent Forms submission mapping to section scores
- Backward-compatible `score_driven` mode with stored score payloads and chart helpers
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
- `ar_get_selected_section_ids_by_hash($hash = null)`
  - Returns the score-driven selected child section IDs.

## Score-Driven Reports
The plugin now supports two report modes:

- `legacy_response_mapped`
- `score_driven`

`legacy_response_mapped` behaves the same as the original plugin.

`score_driven` uses:

- a saved Score Profile
- stored score payload data on submission
- child-section rules that target payload paths instead of raw form answers

See [`SCORE_PROFILES.md`](./SCORE_PROFILES.md) for the full format and examples for the `Profile Definition JSON` field.

## Development Notes
- AI generation is triggered on `template_redirect` when viewing a singular report and an entry hash is present.
- If AI content already exists for the entry, generation is skipped.
- The WP AI Client must be initialized on `init`.

## Troubleshooting
- If AI meta boxes do not render, confirm callbacks are public.
- If entry hashes fail to resolve, verify the URL parameter and submission hash storage.
- Check PHP error logs for `AR AI:` debug lines during AI generation.
