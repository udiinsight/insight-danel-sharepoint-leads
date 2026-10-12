=== Insight - Danel - SharePoint Leads ===
Contributors: insightmarketing
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 1.0.1

Sends leads from four WS Form forms to the Datafromsite Excel file on Danel's SharePoint, verifies each write, then deletes the lead from the site.

== Description ==

Forms: 9 (טופס כללי), 14 (טופס לידים – מאמר), 13 (מחשבון זכאות), 10 (טופס גיוס מטפלת).
Excel tables: tblLeads (all forms), tblEligibility (form 13 answers), matched by column header name.

How a lead travels:

1. On submit, WS Form saves the submission as usual. The plugin adds a reference row (lead ID only, no personal data) to its queue and wakes the background worker. Microsoft is never called during the visitor's request.
2. The worker (one at a time) checks the lead ID is not already in Excel, writes the row, then reads Excel back until the ID is found exactly once. Duplicates are removed automatically.
3. Only after the read-back succeeds is the WS Form submission permanently deleted.
4. Failures retry with backoff. A locked workbook (open in Excel desktop) waits and retries. Leads not delivered within 48 hours are deleted, with an email alert (agreed with Danel).

Required constants (wp-config.php): DANEL_GRAPH_TENANT_ID, DANEL_GRAPH_CLIENT_ID, DANEL_GRAPH_CLIENT_SECRET, DANEL_GRAPH_DRIVE_ID, DANEL_GRAPH_ITEM_ID.
Optional: DANEL_LEADS_ALERT_EMAIL (defaults to the site admin email), DANEL_GRAPH_SECRET_EXPIRES (defaults to 2028-09-27).

The client secret must never be committed to this repository.

Status page: Tools → Danel Leads → Excel.

Field IDs are mapped in includes/class-mapper.php. Rebuilding or duplicating a form changes its field IDs, so the map must be updated.

For reliable timing, run WP-Cron from a real system cron (every 5 minutes).

== Changelog ==

= 1.0.1 =
* chore: no code change — verifies WP Pusher push-to-deploy on the dev site

= 1.0.0 =
* feat: queue, Graph delivery with read-back verification, verified delete, 48h expiry, alerts, status page
* fix: unambiguous ISO date (Excel read d/m/Y as US m/d), page URL and marketing consent from each form's own fields
