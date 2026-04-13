=== Merchant.WiKi SEO Audit ===
Contributors: merchantwiki
Plugin URI: https://merchant.wiki/merchant-wiki-site-index-audit-plugin-for-wordpress/
Author URI: https://merchant.wiki/
Tags: seo, indexing, sitemap, google search console, audit
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.8.2
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Build URL inventories, cache sitemaps, refresh SEO signals, and connect Google Search Console for exportable index audits.

== Description ==

Merchant.WiKi SEO Audit — Where It Helps
1. Launch inventory baselines after migrations or redesigns to catch missing URLs, sitemap gaps, and misconfigured canonicals.
2. Build prioritized “ready to submit” lists for manual indexing by combining HTTP 200 pages, sitemap coverage, and low inbound links.
3. Audit internal linking to surface orphan or weak pages before requesting more crawl budget.
4. Export a full, normalized dataset for BI dashboards or agency handoffs without granting direct DB access.
5. Validate Google Search Console signals (Page Indexing exports + Inspection API) against on-site data to see why URLs stay “not indexed”.
6. Keep evergreen posts from slipping by spotting the oldest pages/posts (≥1 year since last update) and opening Gemini with a pre-written English script (“update the page… propose two external links…”) for rapid refreshes.

Author & Links
• Built by Merchant.WiKi — your SEO knowledge hub: https://merchant.wiki/

Disclaimer
• This plugin is currently in beta. Install it on staging or a fresh backup copy first.
• Always take full file + database backups before running the audit queues or deleting data.
• Merchant.WiKi provides the software “as-is” and is not liable for any data loss or downtime.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/merchant-wiki-audit` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Use the MW Audit menu in the WordPress admin to access the plugin features.

== Screenshots ==

1. Dashboard overview with health cards and queue status.
2. Operations page (Rebuild Inventory / Refresh signals etc.).
3. Reports table and filter controls.
4. Settings / Google Search Console integration.

---------------------

Blocks At a Glance

Rebuild Inventory
• What: Collects every public post/page/term/home URL into `mw_url_inventory`.
• Data source: WordPress database via WP_Query.
• API needed: No.
• Limits/speed: Only constrained by site size; runs with adaptive batches in `MW_Audit_Inventory_Builder`.
• Batch tuning: Adjusted automatically via EWMA in the “Rebuild” card.
• Use when: you need a current list of crawlable URLs before diagnosing anything else or exporting CSVs.

Prepare / Cache Sitemaps
• What: Downloads sitemap index + children, caches them for later “in_sitemap” checks.
• Data source: HTTP GET requests to your own sitemap URLs.
• API needed: No.
• Limits/speed: Bound by sitemap size and server response time.
• Batch tuning: Single batch per run; progress determined by file count.
• Use when: you want to confirm whether URLs actually live in XML sitemaps without manually opening every file.

Refresh On-Site Signals
• What: Pulls canonical, robots meta, sitemap inclusion, schema type, redirects, HTTP status.
• Data source: HTTP HEAD/GET to your site pages.
• API needed: No.
• Limits/speed: Subject to curl/WP HTTP throughput; EWMA keeps batches responsive.
• Batch tuning: Automatically adjusts batch size and timeouts while the block is running.
• Use when: you need a ground-truth view of canonical/robots/sitemap flags before cross-referencing Search Console.

HTTP-only Signals
• What: Lightweight HEAD/GET pass for status and redirects. Skips parsing HTML so it’s ideal right before a launch or after bulk redirects.
• Data source: Same-site HTTP requests.
• API needed: No.
• Limits/speed: Faster than full refresh; obeys adaptive batching.
• Use when: you want to sanity-check HTTP status / redirect chains without waiting for the full on-site signals crawl.

Internal Link Scan
• What: Parses stored content and counts inbound internal links per URL.
• Data source: WordPress posts/pages/content.
• API needed: No.
• Limits/speed: Depends on content volume; batches adapt through the queue to avoid timeouts.
• Use when: you need to surface orphan/weak pages or build manual indexing batches based on link equity.

Outbound Link Scan
• What: Parses each page/post and counts outbound internal links, outbound external links, and unique external domains.
• Data source: WordPress content for each URL in the inventory.
• API needed: No.
• Limits/speed: Similar to Internal Link Scan; bounded by content size and adaptive batching.
• Use when: you want to spot “dead-end” pages (0 outbound links) or pages that leak too much link equity to external domains.

Post → Primary Category Map
• What: Resolves each post to a preferred taxonomy term for prioritization filters.
• Data source: WordPress taxonomy tables.
• API needed: No.
• Limits/speed: Pure DB operations; completes quickly even on large catalogs.
• Use when: you want to slice the final table by category (e.g., focus only on “Guides”).

Check Google Index Status
• What: Queues Inspection API calls for stale/new URLs, writes verdicts/reasons to `mw_gsc_cache`, updates `indexed_in_google`.
• Data source: Google Search Console Inspection API.
• API needed: Yes — requires OAuth + Inspection API scope.
• Limits/speed: Google allows up to 2,000 inspections per property per day (600/min) and 10M/day per API project; the plugin tracks a soft threshold via `mw_audit_gsc_daily_quota` (defaults to 2,000). See https://developers.google.com/webmaster-tools/limits for the official caps.
• Batch tuning: UI lets you choose batch size and “only stale” mode; adaptive loop keeps requests balanced.
• Use when: you need real Inspection API verdicts/TTL to justify manual submissions or to confirm GSC anomalies.
• Works with Page Indexing Import: the CSV seed gives you reason codes and a backlog, while Inspection API handles high-priority refreshes, fills TTLs, and validates fixes the same day without waiting for the next CSV export.

Page Indexing Import
• What: Ingests the Page Indexing CSV export to enrich reason codes without spending API quota.
• Data source: Manual CSV from Search Console.
• API needed: No (just the CSV).
• Limits/speed: Limited by CSV size; imports stream to avoid memory spikes.
• Use when: you want Page Indexing reasons but are out of Inspection API quota.
• Even with Inspection API enabled: continue importing CSV snapshots to capture batch reasons, crawl states, and historical context that the on-demand API responses do not surface.

Priority: Ready to Submit
• What: Surfaces indexable pages with HTTP 200, sitemap coverage, and ≤N inbound links for manual submission.
• Data source: Local inventory/status tables plus optional GSC cache fields.
• API needed: Optional (GSC data enriches reason labels, but the list works without it).
• Limits/speed: AJAX loads batches of 20–100 rows; no API quotas involved.
• Batch tuning: Threshold selector controls how aggressively you hunt for near-orphan pages.
• Use when: you’re assembling manual indexing batches or want a queue for social/internal promo.

Find similar URL
• What: Off-canvas panel on the Reports tab that loads a reference page’s signals (HTTP, index state, inbound links, age, category) and instantly finds other URLs with the same profile.
• Data source: Plugin tables (inventory, status, GSC cache, primary category map). No crawls or APIs required at runtime.
• Workflow: Click “Find similar URL” (or the “Find similar” link next to any row), load the reference URL, toggle which signals to match, preview the first 100 matches, and export the full cohort to CSV.
• Use when: you want to replicate a win (e.g., pages updated last year with ≤2 internal links), queue lookalike refreshes, or hunt for URLs that share the same indexation blockers.

Stale Content Refresh
• What: Surfaces evergreen articles that need a rewrite using three presets—≥365 days since last update (default), “Last calendar year”, and “Oldest 5 articles”—plus each row’s title, publish/updated dates, inbound-link count, and detected meta description.
• Data source: WordPress posts/pages + stored internal-link counts (no crawl required).
• API needed: No.
• Limits/speed: Instant (pure SQL + metadata lookups).
• Workflow: pick a preset from the inline dropdown, optionally toggle “Only show URLs with zero inbound links” to focus on orphans, then click “Open Gemini” per row to copy the pre-written English brief (includes “suggest two external links”) before pasting the current copy into Gemini.
• Hooks: `mw_audit_refresh_ui_args` controls presets (limits, date ranges, zero-inbound defaults), `mw_audit_meta_description_keys` selects the metadata sources, and `mw_audit_stale_content_candidates` lets you post-process the candidate array.

Connecting Google Search Console
1. In Google Cloud Console (https://console.cloud.google.com/apis/credentials) create or select a project, open **APIs & Services → Library**, search for **Search Console API**, click **Enable**, and do the same for **Google Sheets API** if you plan to sync sheets.
2. Open **OAuth consent screen**, set the user type to **External**, keep the publishing status on **Testing**, add your site’s domain to Authorized domains, add the `https://www.googleapis.com/auth/webmasters` scope (and Sheets scope if needed), and then add every WordPress admin who will authenticate as a **Test user** so the consent screen works without production verification.
3. Go to **Credentials → Create credentials → OAuth client ID**, pick **Web application**, set an identifiable name, and add authorized redirect URIs using your domain — e.g. `https://example.com/wp-admin/admin-post.php?action=mw_audit_gsc_callback` (replace `example.com` with your real site URL, or add multiple entries if you run staging/production).
4. Copy the generated Client ID and Client Secret.
5. In WordPress open **Merchant.WiKi Audit → Settings → Google Search Console**, paste the Client ID/Secret, click **Save credentials**, then press **Connect Google Account** to complete OAuth.
6. After the OAuth popup closes, use the **Select property** dropdown to choose the Search Console site you want to audit (or paste the exact URL manually), save, and optionally click **Connect Sheets** if you enabled the Sheets API.
7. Once a property is selected you can toggle `Enable GSC Inspection API spot checks`, run **Check Google Index Status**, and still import CSVs whenever you need bulk Page Indexing reasons.

---------------------

Cases
1. Quick technical audit without GSC
   • Run: Rebuild Inventory → Prepare / Cache Sitemaps → Refresh On-Site Signals → Internal Link Scan.
   • Filters: sort the table by `http_status`, `in_sitemap`, `noindex`, `inbound_links`. Export CSV and highlight rows where `http_status ≠ 200`, `in_sitemap = 0`, or `noindex = 1`.
   • Result: list of structural blockers you can fix before touching Search Console.
   • Next steps: ticket the URLs grouped by error type, fix templates/redirects, then rerun Refresh On-Site Signals to confirm the blockers are cleared before looping in GSC.
2. Manual indexing when Inspection API quota is exhausted
   • Run: Rebuild Inventory + Internal Link Scan + Page Indexing Import (CSV).
   • Filters: enable `Show likely not indexed` or set the Priority threshold to `0 links` to surface orphans; combine with CSV columns `gsc_pi_reason` and `indexed_in_google`.
   • Result: export a queue of “Crawled / Discovered but not indexed” URLs justified with sitemap + internal-link deficits.
   • Next steps: feed the CSV into a manual indexing rotation (Search Console, Request Indexing, or social/internal promos), then schedule Google Index Status with `Only queue stale/new URLs` to verify the fixes without wasting quota.
3. Monitoring after a launch or redirect batch
   • Run: HTTP-only Signals (to catch 4xx/5xx quickly) followed by Refresh On-Site Signals for detailed metadata.
   • Filters: in the table or CSV, filter `http_status >= 300` or `canonical` mismatches to confirm the rollout.
   • Works even without GSC access because it relies only on onsite fetches.
   • Next steps: ship fixes for any broken chains, re-run HTTP-only Signals to see the delta, and export the “before/after” CSVs for stakeholders.
4. Content pruning with confidence (beyond Yoast/Rank Math)
   • Run: Rebuild Inventory → Internal Link Scan → Outbound Link Scan → Google Index Status.
   • Filters: focus on URLs with `indexed_in_google = 0`, `inbound_links = 0`, `external_link_domains > 0`, and thin schema/robots flags.
   • Result: prioritized list of pages that consume crawl budget but add little internal value — something Yoast/Rank Math do not reveal because they lack crawl+GSC fusion.
   • Next steps: decide whether to improve, redirect, or 410 each URL, document the action column in the CSV, then rerun Inventory + HTTP-only Signals after deployment to ensure pruning behaved as expected.
5. Evergreen refresh sprint (Gemini-assisted)
   • Run: No queues required (the list reads straight from WordPress), but it helps to run Refresh On-Site Signals after big launches so publish timestamps stay accurate.
   • Filters: Reports → “Stale content refresh” (use the inline preset dropdown for ≥365 days since last update, the previous calendar year, or the five oldest articles; adjust defaults via `mw_audit_refresh_ui_args`). Toggle “Only show URLs with zero inbound links” to focus on true orphans.
   • Result: table with URL, publish/update dates, title, stored meta description, inbound-link count, and an “Open Gemini” button that copies the recommended English brief for that page (including the reminder to propose two supporting external links).
   • Next steps: open the URL, click “Open Gemini,” paste the existing content into the chat, ship a refreshed draft (with updated title/description if needed plus external references), and update the page so it drops out of the backlog automatically.
   • Quick how-to: stay inside Reports → Stale content refresh, click the URL to review context, then use the on-row Gemini button to copy the prompt, open Gemini in a new tab, paste the current copy, and ask for two fresh external links before publishing the updated page.
6. Clone what works (Find similar URL)
   • Run: Reports → click “Find similar URL” (or the per-row “Find similar” link in the Preview table) to load a reference page’s signals.
   • Filters: keep the default suggestions (HTTP status, sitemap flag, noindex, inbound link range, days since update) or pin additional constraints such as primary category or indexed/not indexed state.
   • Result: lookalike queue that surfaces other URLs with the same age/index/link profile plus one-click CSV export for outreach or refresh tasks.
   • Next steps: save the CSV, assign it to the same editorial playbook as the reference page, or merge it with Stale content refresh/GSC reasons to prioritize evergreen wins.

Report filter presets
- The Report screen supports query-string parameters (e.g., `?in_sitemap=0&http_status!=200`). Adding inline links or buttons for each case above shortens the “setup filters” phase and keeps less-technical users on rails.
- You can surface a preset picker near the table header that loads saved filter JSON for “Quick audit,” “Manual indexing queue,” “Post-launch QA,” and “Content pruning.”
- Tooltips next to each preset should repeat the “Next steps” summary so users immediately know what to do with the filtered rows.

Case automations
- Reports → Case automations (Next steps) ships buttons to export blocker tickets, manual indexing queues, content pruning sheets, and to save/diff launch snapshots directly from the UI.
- `wp mw-audit next-steps <action>` mirrors the same flows from WP-CLI. Available actions today: `quick-audit`, `manual-indexing`, `content-pruning`, `snapshot-save`, `snapshot-diff`, and `snapshot-list`.

Post-launch monitoring snapshots
- Purpose: capture HTTP status, redirect targets, canonical, sitemap, and noindex state immediately before/after risky changes (launches, redirect waves, CDN swaps) so you can prove impact.
- How to snapshot: run the Operations blocks for your recipe (typically Rebuild Inventory → Refresh On-Site Signals → HTTP-only Signals). Once the queues finish, open Reports → Case automations → “Post-launch monitoring snapshots,” enter a descriptive label (e.g., “Pre-launch 2026-03-01”), and click “Save snapshot.” Under the hood the plugin stores a gzipped JSON copy inside `wp-content/uploads/mw-audit/`.
- Diffing snapshots: after the follow-up crawl completes, save a second snapshot. Use the same panel or `wp mw-audit next-steps snapshot-diff --before=<id> --after=<id>` to export a CSV showing URLs with changed HTTP codes, canonical targets, or redirect destinations.
- When to take them: at minimum grab (a) baseline before go-live, (b) immediately after launch once refresh completes, and (c) after remediation so you can share “before/after” deltas. For phased rollouts, snapshot every significant batch.

---------------------

FAQ
Q: Why does “Show likely not indexed” sometimes return zero rows even though GSC shows issues?
A: The table only shows URLs whose `g_ins`/`g_page` coverage state is cached locally. If the Inspection queue hasn’t refreshed the URL, the CSV wasn’t imported, or the cache entry expired (TTL), the filter will be empty until fresh data arrives.

Q: How do I know whether the Inspection API ran today?
A: Check the “Quota today” pill and the `Google Index Status` step on the dashboard — nonzero quota usage plus a `done` status means the queue executed. You can also inspect the PHP error log for `[MW Audit DBG]` entries when `MW_AUDIT_DEBUG` is enabled.

Q: Why are both “GSC coverage” pills pinned at 0%?
A: Either the Page Indexing CSV hasn’t been imported (export coverage), or the Inspection API hasn’t produced fresh responses (API coverage). Once those data sources run, the pills show `% of inventory` backed by each dataset.

Q: Is the plugin useful if I can’t or won’t use the Inspection API?
A: Yes. Inventory building, on-site signal refreshes, internal link scan, priority lists, and CSV exports all work with local data only — you just lose the automated GSC verdicts.

Q: Do I still need to import Page Indexing CSVs if the Inspection API is enabled?
A: Yes. CSV exports deliver bulk “Page indexing” reasons, crawl sources, and last-seen timestamps for every URL in one shot, something the Inspection API does not expose per-request. Imports also refill the backlog when quota runs out, so the Inspection queue can focus on fresh, critical URLs while the CSV covers the long tail.

Q: I imported the Page Indexing CSV today — what’s the benefit of running the Inspection API right after?
A: The API fetches real-time verdicts (minutes old, not yesterday’s export), sets TTLs so the plugin knows when data goes stale, and double-checks that fixes actually reached Google without waiting for the next CSV. Use it to spot regressions quickly, validate high-priority refreshes, or touch new posts that never appeared in the CSV yet.

Q: What does `inbound_links` mean and when is it populated?
A: It’s the count of internal links pointing to the URL from your own content. The value updates when you run the “Internal Link Scan” block (or its CLI equivalent); before that, the column stays empty.

Q: How do I update statuses if the Inspection API quota is limited?
A: Import the Page Indexing CSV for bulk reasons, then run the “Check Google Index Status” block during off-peak hours with `Only queue stale/new URLs` enabled to stretch API calls. The queue deduplicates URLs and only rechecks entries whose TTL expired unless you uncheck the stale option.

Q: Why is the “Only queue stale/new URLs” checkbox recommended?
A: With the toggle on, the query only picks URLs whose cached Inspection/Page Indexing TTL expired or were published recently, so finished URLs aren’t re-submitted until they actually need a refresh. It’s the easiest way to conserve daily quota without manual bookkeeping.

Q: How does the Inspection API daily limit behave, and what happens when I hit it?
A: Google caps the URL Inspection API at 2,000 calls per Search Console property per day (600/min) and 10,000,000 per API project (15,000/min). Source: https://developers.google.com/webmaster-tools/limits. The plugin logs usage in `MW_Audit_GSC::log_quota_usage` and shows it in the “Quota today” pill; once Google starts returning `RESOURCE_EXHAUSTED/429`, the queue halts, but every completed verdict stays cached in `mw_gsc_cache` with its TTL. At the next day’s reset the quota counter returns to zero automatically, and you can click “Resume”/“Start” to continue — only URLs still marked stale/new will be re-queued, so you never “start from scratch.” There’s no secret switch to bypass the Google cap, but you can (a) keep `Only queue stale/new URLs` enabled, (b) lower the Inspection TTL so rows stay fresh longer, and (c) rely on Page Indexing CSV imports on heavy days. The `mw_audit_gsc_daily_quota` filter only adjusts the UI warning threshold; it cannot raise Google’s real quota.

Q: Can I estimate how many URLs still need Inspection API checks?
A: Yes. The Dashboard’s GSC card now shows “Stale URLs (Inspection)” (inventory minus fresh TTL), and the “Check Google Index Status” block lists “Queued this run / Stale overall / Likely remaining after this run.” If “Stale overall” is larger than today’s queue, expect to come back tomorrow (or expand the queue) to finish the backlog.

Q: What should I run after importing a Page Indexing CSV from GSC?
A: First import the CSV via the “Page Indexing Import” block, then launch “Refresh On-Site Signals” (or at least “HTTP-only Signals”) so the local inventory is fresh, and optionally run “Internal Link Scan” / “Outbound Link Scan” to enrich data. Once the cache is populated you can enable `Show likely not indexed`, export CSV, or start “Check Google Index Status” later without re-running the import.

---------------------

Each column in the example means:

in_sitemap = 1 → URL was found in one of the XML sitemaps (Rank Math).

robots_disallow = 0 → the path is not blocked by a Disallow rule in robots.txt.

noindex = 0 → there is no `noindex` directive in `<meta name="robots">` or the `X-Robots-Tag` HTTP header.

inbound_links = 0 → the plugin hasn’t found internal links pointing to this URL yet (or hasn’t finished counting).

canonical = …/equal-opportunity-policy/ → the canonical points to the page itself (expected).

redirect_to = — → no manual redirect is configured (Rank Math / Redirection).

robots_meta = follow, index … → the page is indexable and previews are allowed.

schema_type = — → no JSON-LD schema type (e.g., Article) was detected. That’s fine; nothing is broken.

http_status = 200 → response comes back without redirects or errors.

updated_at → timestamp when this record was last refreshed in the inventory.

== External services ==

=== Google OAuth / Google account authorization ===
- Use: Lets administrators connect their Google account so the plugin can reach Search Console data and, if requested, Google Sheets.
- Data sent: During the consent flow the site posts your Google Cloud client ID, client secret, redirect URI, and the one-time authorization code to https://oauth2.googleapis.com/token, then calls https://www.googleapis.com/oauth2/v2/userinfo to record which account is connected.
- When data moves: Only when an administrator clicks “Connect Google Account” or “Connect Sheets”; no background jobs refresh tokens unless you start the OAuth flow.
- Optional: Yes. Inventory building, sitemap caching, crawls, and reports continue to run without signing in to Google.
- Terms: Google Terms of Service (https://policies.google.com/terms)
- Privacy: Google Privacy Policy (https://policies.google.com/privacy)

=== Google Search Console API ===
- Use: Fetches your property list and runs the URL Inspection API so you can log index verdicts and TTLs in `mw_gsc_cache`.
- Data sent: Each inspection call posts the selected property (`siteUrl`) plus every queued WordPress URL as `inspectionUrl`, and uses your OAuth access token for authorization.
- When data moves: Only while you run “Check Google Index Status” or refresh the property selector—no calls fire automatically otherwise.
- Optional: Yes. The rest of the audit features work without Search Console; enabling it simply enriches the reports.
- Terms: Google API Terms of Service (https://developers.google.com/terms)
- Privacy: Google Privacy Policy (https://policies.google.com/privacy)

=== Google Sheets API ===
- Use: Imports Page Indexing exports stored in Google Sheets and can build a combined export sheet through the “Assemble” tool.
- Data sent: Requests include the spreadsheet ID/range you provide, and when you create the combined export the plugin writes rows containing the URL, verdict, coverage reason, last crawled time, exported timestamp, and source label to a new sheet in your Drive.
- When data moves: Only when you opt into the Sheets import/export mode; the plugin never touches Sheets unless you paste sheet URLs and start the task.
- Optional: Yes. CSV uploads cover the same workflow if you prefer not to grant Sheets access.
- Terms: Google API Terms of Service (https://developers.google.com/terms)
- Privacy: Google Privacy Policy (https://policies.google.com/privacy)

=== Gemini link ===
- Use: Adds an “Open Gemini” button in the Stale Content Refresh table so you can open https://gemini.google.com/app with a prefilled brief for that URL.
- Data sent: Clicking the button encodes the page URL, publish and update dates, title, and current meta description into the `prompt` query string; clipboard text with the page body stays on your device until you paste it.
- When data moves: Only when someone presses “Open Gemini”; nothing is transmitted automatically.
- Optional: Yes. You can ignore the Gemini link and still use every refresh workflow.
- Terms: Google Terms of Service (https://policies.google.com/terms)
- Privacy: Google Privacy Policy (https://policies.google.com/privacy)

---------------------

You must be a verified owner of the site in Google Search Console to use the plugin.

Index coverage data can lag behind reality by several days.

== Changelog ==

= 1.8.2 =
* Initial release for WordPress.org
