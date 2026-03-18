Product: WordPress plugin “Merchant.WiKi — Site Index Audit” (merchant-wiki-audit).

Goal: Crawl/collect site URLs into an inventory, hydrate per URL signals (sitemap, robots/noindex, HTTP status, canonical, JSON LD schema type, inbound links), build Post→Primary Category (PC) map, and present results in a sortable admin table with health indicators.

Hard constraints
•	Do not change DB schema (tables already exist in customer DB). Use $wpdb->prefix.
•	Support custom table prefixes (not only wp_).
•	No output before headers; no accidental whitespace/BOM; avoid breaking XML sitemaps.
•	i18n: text domain merchant-wiki-audit.
•	Uninstall hook: static callable only (no closures).

High level steps (UI blocks)
1.	Rebuild Inventory
2.	Prepare/Cache Sitemaps
3.	Refresh On Site Signals — AJAX (adaptive batch)
4.	Collect HTTP only Signals (HEAD/GET)
5.	Build Post→Primary Category Map (fast)
6.	Check presence of URL in posts/pages (inbound links)
