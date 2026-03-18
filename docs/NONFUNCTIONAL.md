•	Performance: batched processing; EWMA control; chunked CSV export; Next Steps exports stream row-by-row; snapshots gzip JSON.
•	Reliability: resume from last queue cursor; guard timeouts; log last error in option; snapshots stored redundantly until purged (max 8).
•	Security: nonces on all POST actions (including Next Steps); sanitize $_GET['order']/$_GET['dir']; uploads restricted to gz/JSON created by plugin.
•	Compatibility: PHP 8.x; WP 6.7+; custom table prefixes; WP-CLI parity for automation scripts.
