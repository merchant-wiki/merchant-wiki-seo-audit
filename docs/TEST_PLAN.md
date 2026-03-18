1.	Health loads without warnings; pills neutral.
2.	Rebuild Inventory → inventory_rows > 0; pill green.
3.	Prepare Sitemaps → cache files listed; step done.
4.	Refresh Signals (standard profile) to 100%; status_rows close to inventory_rows.
5.	HTTP only to 100%; verify http_status, canonical, robots_meta fields update.
6.	Build PC Map → pc_rows ≈ public posts.
7.	Links Scan → inbound_links non zero for linked URLs.
8.	Export CSV (first 10k rows, streamed). No memory error.
9.	Delete All Data → counts reset; pills back to neutral; Inventory detected last run = 0.
10.	Toggle uninstall; uninstall plugin; verify tables dropped/kept accordingly.
11.	Reports → Case automations:
	•	Export blocker CSV → contains at least one row when issues exist; empty when no blockers remain.
	•	Export queue CSV → filtered by threshold; includes GSC reason columns.
	•	Export pruning CSV → shows outbound/leak metrics.
12.	Snapshot workflow:
	•	Save snapshot before launch; confirm file saved in uploads/wp-content/uploads/mw-audit/.
	•	Save second snapshot after rerun; download diff CSV; verify HTTP/canonical deltas captured.
13.	WP-CLI `wp mw-audit next-steps ...` commands mirror UI exports (spot-check quick-audit and snapshot-diff).
