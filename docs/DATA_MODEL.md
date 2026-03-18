The plugin must not change schema. This is a reference for reading/writing only.

Tables (prefix varies)
•	${prefix}mw_url_inventory — inventory of URLs.
o	Key fields used: id, norm_url, obj_type, obj_id, updated_at, …
•	${prefix}mw_url_status — per URL signals.
o	Key fields used: norm_url (unique/index), http_status, in_sitemap, robots_disallow, noindex, canonical, robots_meta, schema_type, inbound_links, updated_at.
•	${prefix}mw_post_primary_category — map post → primary category.
o	Key fields used: post_id, category_id (or term_id depending on existing schema), pc_name, pc_path, updated_at.
•	${prefix}mw_gsc_cache — cached Inspection API + Page Indexing results.
o	Key fields used: norm_url, source (inspection/page_indexing), coverage_state, reason_label, pi_reason_raw, inspected_at, ttl_until, last_error.
•	${prefix}mw_outbound_links — outbound internal/external link counts per URL.
o	Key fields used: norm_url (UNIQUE), outbound_internal, outbound_external, outbound_external_domains, last_scanned.

Indexes known from customer DB
•	Respect existing definitions. Do not add/alter in code.

Uploads directory
•	wp-content/uploads/mw-audit/*.json.gz — launch snapshots (max 8 retained); managed by MW_Audit_Next_Steps.
