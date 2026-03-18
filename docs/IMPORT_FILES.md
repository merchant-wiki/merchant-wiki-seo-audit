# Importing Page Indexing CSV Files

The plugin accepts multiple CSV uploads, but the fastest and safest process is to assemble **one normalized CSV** per import. A single file minimizes manual clicks, prevents duplicated rows, and keeps coverage states consistent.

---

## Recommended CSV format (single file)

- **Encoding:** UTF-8  
- **Delimiter:** comma  
- **Header (required columns):**

```
url,verdict,coverage_state,last_crawled,exported_at,source
```

### Column rules

- `url` — copy URLs exactly as they appear in the GSC export (the plugin normalizes them).
- `verdict` — `Indexed` or `Not indexed`.
- `coverage_state` — coverage reason exactly as in GSC (or very close). Examples:
  - Discovered – currently not indexed
  - Crawled – currently not indexed
  - Duplicate, Google chose different canonical than user
  - Alternate page with proper canonical tag
  - Soft 404
  - Excluded by ‘noindex’
  - Blocked by robots.txt
  - Page with redirect
  - Not found (404)
  - Server error (5xx)
- `last_crawled` — ISO timestamp from the export (e.g., `2025-10-10` or `2025-10-10T00:00:00Z`). Leave blank if the column is missing.
- `exported_at` — the report date you stamped manually (one date for every row in the file is fine).
- `source` — tag to identify the batch later. Suggested values:
  - `gsc_export_valid`
  - `gsc_export_discovered`
  - `gsc_export_crawled_not_indexed`
  - `gsc_export_duplicate`
  - `gsc_export_soft404`
  - `gsc_export_noindex`
  - `gsc_export_robots`
  - `gsc_export_redirect`
  - `gsc_export_404`
  - `gsc_export_5xx`

### Sample rows

```
url,verdict,coverage_state,last_crawled,exported_at,source
https://merchant.wiki/foo,Indexed,,2025-10-09,2025-10-11,gsc_export_valid
https://merchant.wiki/bar,Not indexed,Discovered – currently not indexed,,2025-10-11,gsc_export_discovered
https://merchant.wiki/baz,Not indexed,Crawled – currently not indexed,2025-10-08,2025-10-11,gsc_export_crawled_not_indexed
https://merchant.wiki/alt,Not indexed,Alternate page with proper canonical tag,2025-10-07,2025-10-11,gsc_export_duplicate
```

Already have separate CSV files per reason? Concatenate them into one file and set the `verdict`, `coverage_state`, and `source` columns before uploading.

---

## Uploading several files sequentially

Yes, you can upload multiple CSVs one after another (Valid, then Discovered, etc.). The plugin will:

1. Normalize URLs.  
2. Update the cache using the freshest `exported_at` for each URL.  
3. Favor `Indexed` over `Not indexed` when timestamps are identical (so fresh success is not overwritten by older problems).

The downside is additional manual work and higher chances to miss a column/date in one of the files.

---

## TTL and API interaction

- Imported rows receive a long TTL (e.g., 14 days — configurable). While the TTL is valid, the Inspection API will **not** re-check those URLs.
- If you need fresh data for a subset, run the Inspection queue; only URLs without valid TTL or cache will be enqueued.

---

## Quick ways to merge exports

1. **Google Sheets:** combine sheets with an array formula such as  
   ```
   ={FILTER(Valid!A:B, Valid!A:A<>"");
     FILTER(Discovered!A:B, Discovered!A:A<>"");
     ...}
   ```
   Then append `verdict`, `coverage_state`, `source` columns (per sheet) and download as CSV.
2. **Locally:** open each CSV, add missing columns/values, then concatenate (cat/Excel/Numbers).

---

## Pre-upload checklist

- Header exactly `url,verdict,coverage_state,last_crawled,exported_at,source`.
- Every row includes `url`, `verdict`, `exported_at`, and `source`.
- `verdict` is strictly `Indexed` or `Not indexed`.
- `coverage_state` filled for every `Not indexed` row.
- Dates use ISO format; encoding UTF-8; delimiter comma.
- Prefer one combined CSV (but multiple sequential uploads work too).

---

## Quick start bundle

### CSV template (first line)

```
url,verdict,coverage_state,last_crawled,exported_at,source
```

- `verdict`: `Indexed` or `Not indexed`.
- `coverage_state`: only for `Not indexed` rows (use the GSC wording).
- `last_crawled`: ISO date from export (optional).
- `exported_at`: one report date for the entire file.
- `source`: e.g., `gsc_export_valid`, `gsc_export_discovered`, `gsc_export_crawled_not_indexed`, etc.

### Example rows

```
https://merchant.wiki/foo,Indexed,,2025-10-09,2025-10-11,gsc_export_valid
https://merchant.wiki/bar,Not indexed,Discovered – currently not indexed,,2025-10-11,gsc_export_discovered
https://merchant.wiki/baz,Not indexed,Crawled – currently not indexed,2025-10-08,2025-10-11,gsc_export_crawled_not_indexed
https://merchant.wiki/alt,Not indexed,Alternate page with proper canonical tag,2025-10-07,2025-10-11,gsc_export_duplicate
```

---

## How to merge several exports into ONE CSV

### Option A — Google Sheets

On each sheet, paste the exported data. On the `Combined` sheet use:

```gs
={
  { FILTER(Valid!A:B, Valid!A:A<>""),       ARRAYFORMULA(IF(ROW(Valid!A:A)=1,"verdict","Indexed")), ARRAYFORMULA(IF(ROW(Valid!A:A)=1,"coverage_state","")), Valid!C:C, ARRAYFORMULA(IF(ROW(Valid!A:A)=1,"exported_at","2025-10-11")), ARRAYFORMULA(IF(ROW(Valid!A:A)=1,"source","gsc_export_valid")) };
  { FILTER(Discovered!A:B, Discovered!A:A<>""), ARRAYFORMULA(IF(ROW(Discovered!A:A)=1,"verdict","Not indexed")), ARRAYFORMULA(IF(ROW(Discovered!A:A)=1,"coverage_state","Discovered – currently not indexed")), Discovered!C:C, ARRAYFORMULA(IF(ROW(Discovered!A:A)=1,"exported_at","2025-10-11")), ARRAYFORMULA(IF(ROW(Discovered!A:A)=1,"source","gsc_export_discovered")) };
  { FILTER(CrawledNotIndexed!A:B, CrawledNotIndexed!A:A<>""), ARRAYFORMULA(IF(ROW(CrawledNotIndexed!A:A)=1,"verdict","Not indexed")), ARRAYFORMULA(IF(ROW(CrawledNotIndexed!A:A)=1,"coverage_state","Crawled – currently not indexed")), CrawledNotIndexed!C:C, ARRAYFORMULA(IF(ROW(CrawledNotIndexed!A:A)=1,"exported_at","2025-10-11")), ARRAYFORMULA(IF(ROW(CrawledNotIndexed!A:A)=1,"source","gsc_export_crawled_not_indexed")) }
}
```

Then **File → Download → CSV**.

### Option B — Excel / Numbers

Combine all rows on one sheet, add the extra columns (`verdict`, `coverage_state`, `exported_at`, `source`), fill them in bulk, and export as CSV (UTF-8).

### Option C — CLI

Ensure every CSV has the same header, then run:

```bash
(echo "url,verdict,coverage_state,last_crawled,exported_at,source" \
 && tail -n +2 valid.csv \
 && tail -n +2 discovered.csv \
 && tail -n +2 crawled_not_indexed.csv) > combined.csv
```

---

## What the plugin does with the CSV

1. Normalizes URLs.  
2. Inserts/updates cache rows with a “long” TTL.  
3. If the same URL appears multiple times, the most recent `exported_at` wins (ties prefer `Indexed`).

### Pre-upload checklist (quick)

- Prefer one CSV (UTF-8, comma).  
- Header exactly `url,verdict,coverage_state,last_crawled,exported_at,source`.  
- Verdict strictly `Indexed`/`Not indexed`.  
- Fill `coverage_state` for every `Not indexed`.  
- Always set `exported_at` (one date per file is fine).

---

## Google Drive workflows

### Option 1 — “GSC Master” Google Sheet (no code)

1. Create an empty sheet named “GSC Master”.  
2. On individual tabs, pull source sheets via `IMPORTRANGE`:
   ```gs
   =IMPORTRANGE("https://docs.google.com/spreadsheets/d/FILE_ID_VALID","Table!A:C")
   =IMPORTRANGE("https://docs.google.com/spreadsheets/d/FILE_ID_DISC","Table!A:C")
   ```
3. On the `Combined` tab merge everything with the template:
   ```gs
   ={
     {"url","verdict","coverage_state","last_crawled","exported_at","source"};
     { FILTER(Valid!A:C, Valid!A:A<>""),     ARRAYFORMULA(IF(ROW(Valid!A:A)=1,,"Indexed")),  ARRAYFORMULA(IF(ROW(Valid!A:A)=1,,"")),  INDEX(Valid!C:C,0),  "2025-10-11", "gsc_export_valid" };
     { FILTER(Discovered!A:C, Discovered!A:A<>""), ARRAYFORMULA(IF(ROW(Discovered!A:A)=1,,"Not indexed")), ARRAYFORMULA(IF(ROW(Discovered!A:A)=1,,"Discovered – currently not indexed")), INDEX(Discovered!C:C,0), "2025-10-11", "gsc_export_discovered" };
     /* add blocks for remaining reasons */
   }
   ```
4. Download as CSV and upload to the plugin.

### Option 2 — Apps Script auto-build (recommended for many sources)

```js
const SOURCES = [
  { id: 'FILE_ID_VALID', sheet: 'Table', verdict: 'Indexed',     coverage: '',                                    exported_at: '2025-10-11', source: 'gsc_export_valid' },
  { id: 'FILE_ID_DISC',  sheet: 'Table', verdict: 'Not indexed', coverage: 'Discovered – currently not indexed', exported_at: '2025-10-11', source: 'gsc_export_discovered' },
  // Add more exports here
];

function buildCombinedSheet() {
  const ss = SpreadsheetApp.getActive();
  const sh = ss.getSheetByName('Combined') || ss.insertSheet('Combined');
  sh.clearContents();
  sh.appendRow(['url','verdict','coverage_state','last_crawled','exported_at','source']);

  SOURCES.forEach(src => {
    const s = SpreadsheetApp.openById(src.id).getSheetByName(src.sheet);
    const rows = s.getDataRange().getValues(); // expect header: URL | Last crawled
    const head = rows[0].map(v => String(v).toLowerCase().trim());
    const iUrl = head.indexOf('url');
    const iCrawl = head.indexOf('last crawled');

    for (let r = 1; r < rows.length; r++) {
      const url = rows[r][iUrl];
      if (!url) continue;
      const last = iCrawl >= 0 ? rows[r][iCrawl] : '';
      sh.appendRow([url, src.verdict, src.coverage, last, src.exported_at, src.source]);
    }
  });
}

function exportCombinedCSVtoDrive() {
  const sh = SpreadsheetApp.getActive().getSheetByName('Combined');
  const data = sh.getDataRange().getValues();
  const csv = data.map(row => row.map(v => {
    const s = String(v ?? '');
    return (s.includes(',') || s.includes('"') || s.includes('\n')) ? `"${s.replace(/"/g,'""')}"` : s;
  }).join(',')).join('\n');
  DriveApp.createFile('gsc_combined.csv', csv, MimeType.CSV);
}
```

Run ▶ `buildCombinedSheet` (and optionally ▶ `exportCombinedCSVtoDrive`) to obtain `gsc_combined.csv`.

### Option 3 — Local merge (Excel/CLI/Python)

If you download CSVs locally, normalize columns, add the extra fields, and stitch them together via Excel, Numbers, or a small Python script.

---

## FAQ: Can the plugin accept multiple files at once?

- Currently uploads are one file per form submission, but you may upload several sequentially — the cache deduplicates URLs using `exported_at` (newer wins; ties prefer `Indexed`).  
- A future Drive/Folder mode may appear, but it would require extra OAuth scopes and would complicate the onboarding UX. Given quota limits, a single combined CSV remains the most practical approach.

### What should I do right now?

- Stick to one combined CSV via Sheets (Option 1) or Apps Script (Option 2).  
- This keeps the workflow fast, transparent, and fully compatible with the current plugin version.

Need help? Share your source file IDs and desired reason mapping — we can pre-fill the `SOURCES` array for you and produce a working `gsc_combined.csv` immediately.
