# Assessment Reports / FluentCRM Integration – State & Next Steps

## Current State (as of 2026-02-03)
- AI generation:
  - Submission_Handler queues `assessment_reports_generate_ai` when `ai_generation_status` is not `ready` or `running`.
  - AI_Generator marks status `running`, writes AI content, sets `ready`, and fires `assessment_reports_ai_generation_completed`.
  - Duplicate enqueue protection via `ai_generation_enqueued` meta and a per-request action guard.

- FluentCRM integration:
  - Feed settings per form are stored in `fluentform_form_meta` under `assessment_reports_fcrm_feeds`.
  - Feed supports `map_fields` plus top-level `email`, `first_name`, `last_name`, `report_id`, `tag_slug`, and `pending_tag_slug`.
  - On submission (when AI status set to `pending`), fires `assessment_reports_submission_pending`:
    - Creates/updates the subscriber immediately and applies the Pending Tag (if configured).
    - Stores feed payload once in submission meta `ar_fcrm_feed` for later completion use.
  - On AI completion:
    - Builds report URL using `entry_hash`.
    - Stores subscriber meta first, then attaches the final tag and removes the pending tag.
  - Completion handler stores:
    - `ar_ai_reports` array (last 20)
    - `ar_ai_report_latest_hash`, `ar_ai_report_latest_url`
    - Tag-suffixed: `ar_ai_report_latest_hash_<tag>`, `ar_ai_report_latest_url_<tag>`
  - Report URL built from selected Report permalink, fallback to derived report by entry, then helper/site.
  - Smartcodes added:
    - `{{assessment_reports.latest_url}}`
    - `{{assessment_reports.latest_hash}}`
    - Tag-specific via subscriber meta: `{{subscriber.meta.ar_ai_report_latest_url_<tag>}}`, `..._hash_<tag>`
  - Debug logging (when WP_DEBUG) prefix `AR FCRM` logs contact resolution, tag attachment, and meta store.

- Recent fixes:
  - Feed settings now read from form meta (not missing options).
  - Field mapping resolves from both `field_map` and top-level keys.
  - Pending tag support added with immediate contact creation.
  - Meta stored before final tag application so automations can read URLs.
  - Report URL query param uses `entry_hash`.

## What’s Needed to Finish
Run these from WP root (where `wp` works) and share outputs:
1) Feed config on the form (replace `<FORM_ID>`):
   `wp db query "select value from $(wp db prefix)fluentform_form_meta where form_id=<FORM_ID> and meta_key='assessment_reports_fcrm_feeds';"`
2) Entry meta on the problematic entry (e.g., 38):
   `wp eval "var_dump(\FluentForm\App\Helpers\Helper::getSubmissionMeta(38, 'ar_fcrm_feed'));"`
3) Contact + meta for the email used in that entry:
   ```
   wp eval "
   $sub = (new \FluentCrm\App\Models\Subscriber)->where('email','<EMAIL>')->first();
   print_r($sub);
   print_r(\FluentCrm\App\Models\SubscriberMeta::where('subscriber_id', $sub->id)->get()->toArray());
   "
   ```

## Next Steps (once data is available)
- Re-test: submit form → pending tag applied immediately → AI completes → final tag applied & pending removed.
- Verify meta keys written before automation email fires.
- Confirm smartcodes resolve from subscriber meta for tag-specific URLs.
