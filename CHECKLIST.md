# Assessment Reports – AI Email Flow Checklist

## Core Flow
- Form submission maps to a Report (via `_report_form_id`) and stores `top_report_sections`.
- AI generation must run asynchronously for each entry (`assessment_reports_generate_ai` via ActionScheduler/wp_cron).
- On AI completion, fire `assessment_reports_ai_generation_completed($report_id, $entry_id, $entry_hash)` only after status is `ready`.
- Do **not** tag or email before AI completion; tagging happens only on the completion hook.

## FluentCRM Integration
- Custom integration key: `assessment_reports_fcrm`.
- Feed config per form:
  - Map Email (required), First Name (optional), Last Name (optional) using `map_fields` picker.
  - Select Report (permalink derives report URL; no manual URL field).
- When completion fires:
  - Ensure tag `ar-ai-report-ready` exists (create if missing) and attach idempotently.
  - Store contact meta:
    - `ar_ai_reports`: append `{ entry_id, entry_hash, report_id, report_url, generated_at }` (trim to last 20).
    - `ar_ai_report_latest_hash`
    - `ar_ai_report_latest_url`
- Requires FluentCRM active (free is sufficient); guard exits otherwise.

## AI Generation Trigger
- Submission_Handler enqueues AI if `ai_generation_status` is not `running/ready` and sets status `pending`.
- Generation action sets status `running` → writes AI content → status `ready` and fires completion hook.
- Failure path sets status `failed` (no tagging).

## URLs / Hashes
- Entry hash resolution: use `_entry_uid_hash` if present; fallback to `ar_encode_entry_hash($entry_id)`.
- Report URL built from selected Report permalink with `?entry={hash}` (fallback to helper/site URL).

## CLI Utilities
- `wp assessment-reports rerun-actions --entry=<id>`: re-fire submission inserted actions.
- `wp assessment-reports trigger-complete --entry=<id>`: manually fire completion hook (tags/meta).
- `wp assessment-reports test-submit` exists for manual insertion but primary path is real submissions.

## Logging / Observability (planned)
- Required: Add FluentForm submission log and update status when our action is complete.
- Optional: add logging/admin notice when FluentCRM missing to surface silent no-op.
- Optional: log AI completion and tag/meta write for easier debugging.

## Open Items / Verification
- Confirm ActionScheduler job appears for new submissions and runs to `ready`.
- Verify tag and contact meta set only after AI completion (not on submission).
- Verify automation email template can read `ar_ai_report_latest_hash` or iterate `ar_ai_reports`.
- Ensure feed is configured on each form and integration is enabled.***
