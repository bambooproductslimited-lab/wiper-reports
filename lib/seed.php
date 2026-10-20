<?php
// Opening stock as of 2026-09-28, from the Google Sheet "20260928 Wiper_blades_Inventory", tab "For OS".
// Only loaded when the admin presses "Load opening stock" after reviewing the preview.
// Cost per piece is that tab's "Cost" column; retail is its "Retail Price" column, per pack.

return [
    'date' => '2026-09-28',
    'source' => 'Google Sheet "20260928 Wiper_blades_Inventory", tab "For OS"',
    'variations' => [
        // code, sku, packaging, pieces per pack, cost per piece (pesewas), retail per pack (pesewas), opening pieces
        ['FG1', 'U01', 'Blue box (5 mini boxes × 2 blades)', 10, 800, 15000, 2530],
        ['FG2', 'U01', 'Brown box (5 mini boxes × 1 blade)', 5, 800, 7500, 1970],
        ['FG3', 'U01', 'White box (2 blades)', 2, 800, 3000, 350],
        ['FG4', 'U01', 'White plastic (1 blade)', 1, 800, 1500, 225],
        ['FG5', 'U01', 'Bundle (50 blades)', 50, 800, 70000, 1850],
        ['FG6', 'U01', 'Loose blade', 1, 800, 1400, 1490],
        ['FG7', 'U02', 'Box of 100 spark plugs', 100, 200, 47500, 19000],
    ],
    'location' => 'A2',
    'discrepancies' => [
        'Wiper blades: "For OS" tab totals 8,415 blades; the supplier count is 8,275; the "Wiper_blades" and "Inventory summary" tabs say 9,159; "B stack" (8,275) + "A stack" (884) = 9,159.',
        'Blue boxes (FG1): 253 boxes × 5 × 2 = 2,530 on "For OS", but 3,274 on the "Wiper_blades" / "B stack" tabs.',
        'Bundles and loose: "For OS" has 37 bundles and 1,490 loose; "B stack" has 30 bundles and 956 loose (+ "A stack" 7 bundles and 534 loose).',
        'Spark plugs: 190 boxes × 100 = 19,000 counted vs 18,100 supplier count (sheet notes 900 extra "to store room").',
        'Wipers: sheet notes 14 blue boxes (140 blades) extra "to store room".',
        'GHS 8 / GHS 2 is labelled "Selling Price Per Piece" on some tabs, but it is the cost: the item list shows 46% (U01) and 58% (U02) margins against retail of GHS 14–15 per blade and GHS 4.75 per plug.',
    ],
];
