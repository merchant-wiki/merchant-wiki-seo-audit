Admin Post endpoints (nonce protected)
•	mw_audit_rebuild — rebuild inventory; redirect back with ?rebuilt=1.
•	mw_audit_export_csv — stream CSV (no memory blowups; chunked output).
•	mw_audit_delete_all — truncate plugin data (not tables).
•	mw_audit_toggle_dropdb — flip option to drop/keep tables on uninstall.
•	mw_audit_selftest — small INSERT/SELECT to verify WRITE/READ.

AJAX endpoints (JSON { ok, done, total, errors, percent, eta, batch })
•	mw_audit_sm_prepare — download sitemap index and children into cache dir.
•	mw_audit_status_tick — refresh on site signals (adaptive batch).
•	mw_audit_http_only_tick — HEAD/GET only signals.
•	mw_audit_pc_tick — build Post→Primary Category map fast.
•	mw_audit_links_tick — scan posts/pages for inbound links.

Options used
•	mw_audit_steps — { sm:{status}, os:{status}, http:{status}, pc:{status}, link:{status} }.
•	mw_audit_uninstall_drop — yes|no.
•	mw_audit_last_inv_count — last inventory count after rebuild.
•	mw_audit_sitemap_cache_meta — { count, age, sources[] }.
 