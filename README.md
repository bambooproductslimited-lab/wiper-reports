# wiper.chou.net — wiper blade & spark plug reports

Public, read-only sales and inventory reports for **U01 Wiper blade** and **U02 Spark plugs**, plus a PIN-protected admin page for data entry. Currency GHS; money is integer pesewas everywhere; quantities are pieces (blades / plugs).

| Path | What |
| --- | --- |
| `index.html` | Report page (public): period/product/variation filters, KPI tiles vs previous period, sales trend (stacked SVG bars, revenue/units, hover tooltips, table view), sales by variation, top customers, sales by discount tier, stock on hand & reorder, stock movement ledger. Every table sorts and exports CSV. Light/dark, phone width. |
| `admin.html` | Admin (PIN): load opening stock, CSV / Excel upload (check first, all-or-nothing, template download), record a movement, edit variations, discount tiers, undo imports, void movements. |
| `api.php` | JSON API. Public: `health`, `report`. Admin (`X-Session` token): see header comment. |
| `lib/db.php` | SQLite store at `data/wiper.sqlite` (gitignored, created at runtime with a deny `.htaccess`), migrations, PIN, sessions, rate limit. |
| `lib/xlsx.php` | Minimal .xlsx reader (all sheets; needs PHP `zip`, which Hostinger has as far as I know — if not, uploads fall back to CSV with a clear message). |
| `lib/seed.php` | Opening stock as of 2026-09-28 from the Google Sheet "20260928 Wiper_blades_Inventory" (tab "For OS"). Loaded only when the admin presses the button. |

No build step, no framework, no external scripts. PHP 8.3 with `pdo_sqlite`.

## Data model
- `variations`: FG1–FG6 are packagings of U01 (blue box, brown box, white box, white plastic, bundle, loose); FG7 is U02 (box of 100). Cost per piece, retail per pack, reorder level in pieces.
- `movements`: one row per stock change. Stock on hand is always the sum of movements (never stored). Sales are stored with negative qty and a positive `amount_pesewas`; cost per piece is snapshotted for margin.
- Discount tiers: an order is "over GHS 2,000" (5%) or "over GHS 5,000" (10%) by its total. Lines group into an order by `order_ref`, else by date + customer.
- Reorder: rate = max(7-day, 28-day average daily sales); days of cover = on hand ÷ rate; suggestion = enough for 60 days, rounded up to whole packs. Items at or below their reorder level, or under 14 days of cover, are flagged Low.

## Access
- Viewing is public (decided), but pages send `noindex` and `robots.txt` disallows all, so search engines should not list it. Anyone with the link can still see the figures, including customer names in the ledger.
- All writes need the admin PIN. 5 wrong PINs lock that IP for 60 s; sessions last 12 h.
- **First PIN:** open `/admin.html`, enter the one-time setup code (its sha256 is `SETUP_CODE_SHA256` in `lib/db.php`; the code itself is not in the repo) and choose a 4–8 digit PIN.
- **Reset a lost PIN:** delete `data/admin_pin.json` in Hostinger File Manager, then set a new PIN with the setup code. If the setup code is lost too, generate a new one and replace the hash in `lib/db.php`.

## Uploading data (CSV or Excel)
Data comes in only by upload or manual entry on the admin page; there is no link to another system. Excel workbooks (.xlsx; old .xls must be re-saved) or CSV. Each sheet is read and recognised by its header row; sheets in neither layout (e.g. a pivot "Summary") are skipped. Any bad row rejects the whole upload with errors that give the sheet and row number. Each upload can be undone from Admin → Uploads.

### 1. The shop's sales report (e.g. `20261019_WB_SP_Sales_Report.xlsx`)
One sheet per product — the name says which ("Wiper Blade" → U01, "Spark Plug" → U02) — with columns
`Date, Product, Quantity, Unit Price (GHS), Sales Amount (GHS), Discount (GHS)` (optional `Customer`, `Note`).
- **Product** is the packaging, matched to a variation by the start of its name: Blue box, Brown box, White box, White plastic, Bundle(s), Loose; "Box" for spark plugs. "White" alone is ambiguous and rejected.
- **Quantity** is in packs and is converted to pieces (2 brown boxes = 10 blades); **Unit Price** is per pack.
- Revenue = **Sales Amount − Discount** (the discount is stored separately). Blank Sales Amount = Quantity × Unit Price.
- The report is a running list, so an upload **replaces** earlier report rows for that product within the dates it covers (first to last date in the sheet). Re-uploading the latest report each week keeps totals right and picks up corrections; it never double-counts. Template rows and manual entries are never replaced.
- No customer or order number in the report means every line counts as its own order (affects the Orders tile and discount tiers).

### 2. Template (deliveries, adjustments, transfers, sales with customers) — rows are added
Header row required, any column order, case and spaces ignored:
```
date,sku,variation,type,qty,unit_price,customer,note,order_ref
2026-10-01,U01,FG5,sale,100,14,Kofi Auto Parts,,INV-101
2026-10-02,U01,FG6,adjustment,-3,,,damaged,
```
`date` a real Excel date cell, YYYY-MM-DD or DD/MM/YYYY · `type` sale / receipt / adjustment / transfer · `qty` whole pieces (sale/receipt positive; adjustment/transfer signed) · `unit_price` GHS per piece (blank = retail ÷ pieces per pack). "Download template" on the admin page gives this file.

## Hosting / deploy
- Hostinger account `u344960516`; `chou.net` DNS is in Hostinger. Subdomain `wiper.chou.net` → the folder this repo is deployed into; SSL on.
- Deploy with Hostinger "Deploy website Git repository": this repo, branch `main`. If the repo is private, add Hostinger's SSH key as a read-only deploy key first.

## Open questions
1. Are prices tax-inclusive? Any VAT/NHIL to show?
2. One stock location (A2) or several?
3. Should per-pack retail prices or per-piece prices drive revenue? (Imports take a per-piece price.)
4. The source sheets disagree on opening counts (8,415 vs 8,275 vs 9,159 blades; 19,000 vs 18,100 plugs) See Admin → Opening stock. (GHS 8 / GHS 2 is cost: the item list's 46% / 58% margins confirm it.)
