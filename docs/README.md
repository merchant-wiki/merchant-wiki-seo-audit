Merchant.WiKi SEO Audit (WordPress plugin)

Audit how well your WordPress site is represented for crawling and indexing.
This plugin builds an inventory of URLs, hydrates on-site signals (sitemaps, robots/noindex, HTTP status, canonical, JSON-LD schema type, internal links), creates a Post → Primary Category map, and presents everything in a sortable admin table with health pills and progress bars.

Highlights
•	Zero schema changes to your existing DB (respects custom table prefixes).
•	Rebuild Inventory of URLs (posts, pages, terms, home).
•	Prepare / Cache Sitemaps (index + children) for fast lookups.
•	Refresh On-Site Signals via AJAX with adaptive batching (EWMA).
•	HTTP-only pass (HEAD/GET) when you want the fastest lightweight update.
•	Post → Primary Category (fast) map (Astra/RankMath compatible).
•	Internal link scanner (counts inbound links from your own content).
•	Outbound link scanner (counts external/internal exits per URL for pruning).
•	Health dashboard with colored pills + DB structure checks.
•	Case automations (“Next steps”) that export blocker tickets, manual indexing queues, pruning sheets, and launch snapshots directly from Reports or WP-CLI.
•	CSV export, resume/stop, and “drop tables on uninstall” toggle.

⚠️ The plugin does not phone home. All data stays on your server.

Requirements
•	WordPress 6.7+
•	PHP 8.0+ (tested on 8.2)
•	MySQL/MariaDB with privileges to SELECT/INSERT/UPDATE/DELETE (and read sitemaps over HTTP/S)

Disclaimer
•	The plugin is currently in open beta. Install it on staging or a full backup copy first.
•	Make sure you have recent file + database backups before running the audit queues.
•	Merchant.WiKi is not liable for any data loss; use at your own risk.

Author
•	Built by [Merchant.WiKi](https://merchant.wiki/) — your merchant wiki & knowledge hub.

Installation
1.	Copy the plugin folder to wp-content/plugins/merchant-wiki-audit/.
2.	Activate in WP Admin → Plugins.
3.	Open Tools → MW Audit.

Uses $wpdb->prefix everywhere. Custom prefixes (e.g. wm_) are supported.

How it works (admin blocks)

Each block has Start / Stop / Resume and a progress bar.
Completed blocks are highlighted in light-green; running blocks in light-yellow.
1.	Rebuild Inventory

Collects all site URLs (posts, pages, terms, home) into the inventory table.
2.	Prepare / Cache Sitemaps

Downloads sitemap index and child sitemaps and caches them inside the plugin to speed up “in sitemap” checks.
3.	Refresh On-Site Signals — AJAX (adaptive batch)

Full pass (HTTP + parsing) with adaptive batching driven by EWMA of per-URL time. Updates:
o	http_status, redirects
o	in_sitemap, robots_disallow, noindex
o	canonical, robots_meta, schema_type
o	(optional) inbound_links if internal scan is enabled
4.	Collect HTTP-only signals (HEAD/GET)

Lightweight pass for status/redirects and minimal meta (canonical/robots/JSON-LD) without internal link analysis.
5.	Build Post → Primary Category Map (fast)

Creates/refreshes a fast map of each post’s primary category/name/path (no network calls).
6.	Check presence of URL in posts/pages (internal links)

Scans site content and updates inbound_links per URL. Also uses adaptive batching.
7.	Outbound Link Scan

Counts outbound internal/external links and unique external domains per URL (feeds content pruning case).

Footer & reports actions
•	Export CSV — Streams the full table to CSV (chunked).
•	Case automations — Buttons for “Export blocker CSV”, “Export queue CSV”, “Export pruning CSV”, “Save snapshot”, “Download diff CSV”.
•	Delete All Data — Clears plugin data (not tables).
•	Drop tables on uninstall: YES/NO — Toggle whether the plugin’s tables are dropped during uninstall.

Stale content refresh (Reports)
•	What: surfaces the stalest published posts/pages with three presets — ≥365 days since last update, “last calendar year”, and “oldest 5 articles” — plus their title, publication date, last modified date, inbound link count, and detected meta description.
•	Why: copy teams can queue quick wins before evergreen URLs slide; the rows are resolved directly from WordPress so no crawls/APIs are required.
•	Bonus: every row ships an “Open Gemini” button that copies an English-language brief (“update the page…”) and opens https://gemini.google.com/app so you can paste the existing content, request an updated draft, and even ask for two supporting external links.
•	Filters/hooks: `mw_audit_refresh_ui_args` (limit, min_days_since_update/publish, post_types, `only_zero_inbound`, calendar windows), `mw_audit_meta_description_keys` (meta value priority), and `mw_audit_stale_content_candidates` (final array) let you align the backlog with your editorial process. The inline dropdown selects the preset, and the checkbox (“Only show URLs with zero inbound links”) narrows the table to posts that lack internal links.

Find similar URL (Reports)
•	What: loads a reference URL’s onsite + GSC signals (HTTP status, sitemap/noindex flags, inbound link count, days since update, primary category path, inspection/page-indexing verdicts) and instantly finds other URLs with the same profile.
•	Data sources: inventory/status tables, Post → Primary Category map, and cached Inspection/Page Indexing verdicts (no live crawls required).
•	Workflow: click “Find similar URL” (or the contextual link next to any row in the Preview table), load the reference signals, enable the filters you care about, preview the first matches, then export the full cohort to CSV for outreach/backlog handoff.
•	Why: perfect for cloning a win (e.g., articles updated last year with ≤2 internal links), diagnosing URLs that share the same exclusion reason, or feeding an editorial sprint with lookalike opportunities.

Evergreen refresh case (Reports → Stale content refresh)
1.	Optional: Run Rebuild Inventory + Refresh On-Site Signals so canonical URLs and publish timestamps are fresh (the refresh table itself reads straight from WordPress).
2.	Open Reports → “Stale content refresh”, pick the preset (≥365 days, “Last calendar year”, or “Oldest 5 articles”), and review the title/meta/inbound columns (toggle “Only show URLs with zero inbound links” if you want pure orphans).
3.	Click the URL to review it, then hit “Open Gemini” — the plugin copies the recommended English brief (“update the page… propose two external links…”) and opens Gemini in a new tab so you can paste the existing article and request an updated draft focusing on changes from the current year.
4.	Publish the refreshed page; because `post_modified` changes, the URL naturally leaves this backlog on the next page load. Adjust the backlog window via `mw_audit_refresh_ui_args` if you want other sweeps.

Health & DB structure

The Health panel shows pills bound to block outcomes:
•	Inventory rows → Rebuild Inventory
o	Green: inventory_rows > 0
o	Yellow: running / freshly updated but zero rows yet
o	Red: finished but zero rows or DB read/write error
•	Status rows → Sitemaps + Refresh Signals / HTTP-only
o	Green: status_rows ≥ inventory_rows * 0.9 (or equal)
o	Yellow: queues active and rows growing
o	Red: finished with zero/very low rows without network errors
•	Post → Primary Category map → PC Map
o	Green: rows ≈ public posts (or step marked done)
o	Yellow: queue running
o	Red: finished with zero rows while posts exist
•	Inventory detected last run → Rebuild Inventory
o	Green: equals current inventory_rows (fresh)
o	Yellow: >0 but differs >10%
o	Red: zero after rebuild or not updating

DB Structure Check validates (read-only):
•	Table presence (with custom prefix)
•	Column set & index presence (no migrations)
•	Basic SELECT/WRITE (Self-Test button provided)
•	Last WPDB error (if any)

Adaptive batching (performance)

Three profiles:
•	Fast — start batch ≈ 80, per-URL budget ≈ 1200 ms, timeout ≈ 8 s
•	Standard (default) — batch ≈ 40, budget ≈ 800 ms, timeout ≈ 10 s
•	Safe — batch ≈ 20, budget ≈ 600 ms, timeout ≈ 12 s

EWMA control (α=0.3):
•	If EWMA > budget → decrease batch ~20%, bump timeout +2 s (cap ~15 s)
•	If EWMA < 0.6× budget → increase batch ~20% (cap ~120), lower timeout −1 s (floor ~6 s)
•	Backoff on repeated 5xx/timeouts

Data model (read / write only)

The plugin does not alter schema. It reads/writes rows in the following existing tables (prefix varies):

•	${prefix}mw_url_inventory — URL inventory
(e.g. id, norm_url, obj_type, obj_id, updated_at, …)
•	${prefix}mw_url_status — on-site signals per URL
(norm_url, http_status, in_sitemap, robots_disallow, noindex, canonical, robots_meta, schema_type, inbound_links, updated_at)
•	${prefix}mw_post_primary_category — post → primary category map
(post_id, category_id/term_id, pc_name, pc_path, updated_at)

If plugin-specific tables are entirely missing on a fresh install, the plugin can create empty tables (no migrations), but never modifies existing schemas.

Privacy & network
•	Uses WordPress HTTP API (wp_remote_head, wp_remote_get) against your own site.
•	Honors your server/Cloudflare caching; offers an HTTP-only pass for minimal load.
•	No data leaves your environment.

Troubleshooting
•	“Serialization of ‘Closure’ is not allowed”
Ensure the uninstall hook is a static callable (no closures). This plugin registers
register_uninstall_hook( __FILE__, ['MW_Audit_Install','uninstall'] );
•	Sitemap shows blank lines / invalid XML
Ensure no BOM/whitespace before <?php in any plugin file. If you unpack sources from a single-file bundle, trim leading blank lines.
•	Counts = 0
Check DB prefix in “Debug”, run Self-Test, verify user has SELECT/INSERT/UPDATE/DELETE, and that sitemaps are reachable.
•	Export “link expired”
Make sure you’re logged in and the nonce hasn’t expired; reload the admin page and retry.

Internationalization (i18n)
•	Text domain: merchant-wiki-audit
•	Translations in languages/ (.pot, .po, .mo)
•	Example (with WP-CLI):
wp i18n make-pot wp-content/plugins/merchant-wiki-audit languages/merchant-wiki-audit.pot


Development
•	Code style: WordPress PHPCS rules recommended.
•	Security: nonces for POST, sanitize all $_GET (order, dir) and escape outputs.
•	Performance: avoid huge memory buffers; stream CSV; batch DB updates.

Key endpoints
•	Admin-Post: mw_audit_rebuild, mw_audit_export_csv, mw_audit_delete_all, mw_audit_toggle_dropdb, mw_audit_selftest
•	AJAX: mw_audit_sm_prepare, mw_audit_status_tick, mw_audit_http_only_tick, mw_audit_pc_tick, mw_audit_links_tick

Roadmap
•	Per-URL details drawer (raw headers, parsed meta)
•	More granular queue metrics (avg latency, error classes)
•	Optional cron scheduling windows

Contributing

Issues and PRs welcome. Please avoid schema changes; discuss first if a new column/index seems necessary. Include screenshots for UI changes and describe perf impact. Workflow for community fixes:
1.	Fork the repository and open a pull request (PR) against `main`.
2.	Automated checks (lint/tests) run via GitHub Actions; only passing PRs need human review.
3.	Maintainers skim the diff for architectural consistency. If everything looks good, the PR is merged; otherwise feedback is left for the contributor.
You keep control over the codebase—nothing lands without merge approval—but automation + contributor screenshots/tests minimize manual time.

License

GNU General Public License v3.0 or later.
See LICENSE for full text. Dual-licensing or commercial add-ons can layer on top, but core distribution must remain GPL-compatible.

Changelog
•	Unreleased — Initial public README for GitHub; adaptive batches, health pills, DB structure check, installers, CSV export, uninstall toggle, plus the “Stale content refresh” backlog (English Gemini brief, “zero inbound links” preset, `mw_audit_refresh_ui_args`, `mw_audit_meta_description_keys`, `mw_audit_stale_content_candidates`).
