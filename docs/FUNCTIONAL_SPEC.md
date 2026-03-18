Admin page: Tools → MW Audit (slug: mw-site-index-audit).

Panels (top → bottom)
•	Health (with status “pills” per rule set in HEALTH_CHECK.md).
•	DB Structure Check (read only, no schema migration).
•	Sitemaps detected (list of URLs discovered).
•	Debug block (prefix, table names, last DB error, queue name, uninstall toggle state).
•	Actions (each in its own card with progress bar + Start/Stop/Resume):
o	Rebuild Inventory
o	Prepare/Cache Sitemaps
o	Refresh On Site Signals — AJAX (adaptive)
o	Collect HTTP only Signals (HEAD/GET)
o	Build Post→Primary Category Map (fast)
o	Check presence of URL in posts/pages (inbound links)
•	Footer actions:
o	Export CSV
o	Delete All Data (confirm dialog)
o	“Drop tables on uninstall: YES/NO” toggle (stores an option, uninstall respects it)

Main table
•	White background; sticky header; sortable by clicking on column labels.
•	Columns: norm_url, http_status, in_sitemap, noindex, inbound_links, canonical, robots_meta, schema_type, pc_name, pc_path, updated_at.
•	Default page shows first 100 rows; order and dir via $_GET (sanitize!).

Queues & state
•	Adaptive batches per ADAPTIVE_ENGINE.md.
•	Step statuses persisted in options (e.g., { sm: running|done|idle, os: … }).
•	Health pills consume both raw counts and step statuses.
