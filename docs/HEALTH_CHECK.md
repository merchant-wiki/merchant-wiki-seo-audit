Pill colors (CSS classes)
•	pill neutral — gray/white (no result yet)
•	pill ok — green (success)
•	pill warn — yellow (in progress)
•	pill fail — red (error/failed)

Bindings
•	Inventory rows → Rebuild Inventory
o	ok: inventory_rows > 0
o	warn: step flag running OR last_inv_detected updated but rows=0
o	fail: after finish rows=0 or DB read/write error
•	Status rows → Sitemaps + Refresh Signals / HTTP only
o	ok: status_rows > 0 and preferably status_rows ≥ inventory_rows*0.9
o	warn: active queue for status/http; rows growing
o	fail: finished, but rows=0 OR far below inventory without network errors
•	Post→Primary Category map → PC tick
o	ok: pc_rows ≈ posts_count (within tolerance) or step done
o	warn: queue active
o	fail: finished, but pc_rows=0 while posts exist
•	Inventory detected last run → Rebuild Inventory
o	ok: last_inv_detected == inventory_rows (fresh date)
o	warn: last_inv_detected > 0 but differs >10%
o	fail: last_inv_detected == 0 after rebuild OR not updating
