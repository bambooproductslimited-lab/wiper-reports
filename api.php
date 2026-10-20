<?php
// wiper.chou.net API.
// Public (no login, read-only): health, report.
// Admin (X-Session token from login): status, setup, login, logout, catalog, seed_preview, seed_load,
//   import (CSV, all-or-nothing, dry_run supported), add_movement, void_movement, batches, undo_batch,
//   save_variation, save_tiers.
// Quantities are pieces. Sales are stored as negative qty with a positive amount_pesewas.

declare(strict_types=1);
require __DIR__ . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400, array $extra = []): never { out(['ok' => false, 'error' => $msg] + $extra, $code); }

$action = $_GET['action'] ?? '';
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) fail('Request body must be JSON.');
}

try {
    $public = ['health', 'report', 'status', 'setup', 'login'];
    if (!in_array($action, $public, true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required.', 405);
        if (!session_valid($_SERVER['HTTP_X_SESSION'] ?? '')) fail('Admin sign-in required.', 401, ['code' => 'auth']);
    }
    switch ($action) {
        case 'health': out(['ok' => true, 'time' => now()]);
        case 'report': out(['ok' => true] + report($_GET));
        case 'status': out(['ok' => true, 'pin_set' => pin_is_set(), 'signed_in' => session_valid($_SERVER['HTTP_X_SESSION'] ?? '')]);
        case 'setup': setup($in);
        case 'login': login($in);
        case 'logout': session_end($_SERVER['HTTP_X_SESSION'] ?? ''); out(['ok' => true]);
        case 'catalog': out(['ok' => true] + catalog());
        case 'seed_preview': out(['ok' => true] + seed_preview());
        case 'seed_load': seed_load();
        case 'import': import_csv($in);
        case 'add_movement': add_movement($in);
        case 'void_movement': void_movement($in);
        case 'batches': out(['ok' => true, 'batches' => db()->query('SELECT * FROM batches ORDER BY id DESC LIMIT 50')->fetchAll()]);
        case 'undo_batch': undo_batch($in);
        case 'save_variation': save_variation($in);
        case 'save_tiers': save_tiers($in);
        default: fail('Unknown action.', 404);
    }
} catch (Throwable $e) {
    error_log('wiper api: ' . $e->getMessage());
    fail('Server error.', 500);
}

// ---------------- auth ----------------
function setup(array $in): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required.', 405);
    if (pin_is_set()) fail('An admin PIN already exists.', 409);
    if ($w = lock_remaining()) fail("Too many tries. Wait {$w} s.", 429);
    if (!hash_equals(SETUP_CODE_SHA256, hash('sha256', strtoupper(trim((string)($in['code'] ?? '')))))) { record_fail(); fail('Wrong setup code.', 403); }
    $pin = (string)($in['pin'] ?? '');
    if (!preg_match('/^\d{4,8}$/', $pin)) fail('PIN must be 4–8 digits.');
    pin_set($pin); clear_fails();
    out(['ok' => true, 'token' => session_create()]);
}
function login(array $in): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required.', 405);
    if (!pin_is_set()) fail('No admin PIN yet. Use the setup code first.', 409, ['code' => 'setup']);
    if ($w = lock_remaining()) fail("Too many wrong PINs. Wait {$w} s.", 429);
    if (!pin_check((string)($in['pin'] ?? ''))) { record_fail(); fail('Wrong PIN.', 403); }
    clear_fails();
    out(['ok' => true, 'token' => session_create()]);
}

// ---------------- catalog ----------------
function variations(bool $activeOnly = false): array {
    return db()->query('SELECT v.*, p.name AS product FROM variations v JOIN products p ON p.sku = v.sku'
        . ($activeOnly ? ' WHERE v.active = 1' : '') . ' ORDER BY v.sku, v.code')->fetchAll();
}
function catalog(): array {
    return [
        'products' => db()->query('SELECT * FROM products ORDER BY sku')->fetchAll(),
        'variations' => variations(),
        'tiers' => db()->query('SELECT * FROM discount_tiers ORDER BY threshold_pesewas')->fetchAll(),
    ];
}
function save_variation(array $in): never {
    $pdo = db();
    $code = strtoupper(trim((string)($in['code'] ?? '')));
    $sku = (string)($in['sku'] ?? '');
    if (!preg_match('/^[A-Z0-9-]{1,20}$/', $code)) fail('Code must be 1–20 letters/digits, e.g. FG8.');
    if (!in_array($sku, ['U01', 'U02'], true)) fail('Product must be U01 or U02.');
    $packaging = trim((string)($in['packaging'] ?? ''));
    if ($packaging === '') fail('Packaging is required.');
    $ppp = (int)($in['pieces_per_pack'] ?? 0);
    if ($ppp < 1) fail('Pieces per pack must be at least 1.');
    $cost = to_pesewas($in['cost'] ?? ''); $retail = to_pesewas($in['retail'] ?? '');
    if ($cost === null || $cost < 0 || $retail === null || $retail < 0) fail('Cost and retail must be amounts in GHS.');
    $reorder = (int)($in['reorder_level'] ?? 0);
    if ($reorder < 0) fail('Reorder level cannot be negative.');
    $vals = [$sku, $packaging, $ppp, $cost, $retail, $reorder, trim((string)($in['location'] ?? '')), empty($in['active']) ? 0 : 1];
    $id = (int)($in['id'] ?? 0);
    $dup = $pdo->prepare('SELECT 1 FROM variations WHERE code = ? AND id != ?'); $dup->execute([$code, $id]);
    if ($dup->fetchColumn()) fail("Code {$code} already exists.");
    if ($id) {
        $s = $pdo->prepare('UPDATE variations SET sku=?, packaging=?, pieces_per_pack=?, cost_pesewas=?, retail_pesewas=?, reorder_level=?, location=?, active=?, code=? WHERE id=?');
        $s->execute([...$vals, $code, $id]);
        if (!$s->rowCount()) fail('Variation not found.', 404);
    } else {
        $pdo->prepare('INSERT INTO variations (sku, packaging, pieces_per_pack, cost_pesewas, retail_pesewas, reorder_level, location, active, code) VALUES (?,?,?,?,?,?,?,?,?)')->execute([...$vals, $code]);
    }
    touch_data();
    out(['ok' => true] + catalog());
}
function save_tiers(array $in): never {
    $rows = $in['tiers'] ?? null;
    if (!is_array($rows) || count($rows) > 10) fail('Send up to 10 tiers.');
    $clean = [];
    foreach ($rows as $r) {
        $t = to_pesewas($r['threshold'] ?? ''); $p = (int)($r['percent'] ?? -1);
        if ($t === null || $t <= 0 || $p < 0 || $p > 100) fail('Each tier needs a threshold above 0 and a percent 0–100.');
        $clean[$t] = $p;
    }
    $pdo = db(); $pdo->beginTransaction();
    $pdo->exec('DELETE FROM discount_tiers');
    $s = $pdo->prepare('INSERT INTO discount_tiers VALUES (?, ?)');
    foreach ($clean as $t => $p) $s->execute([$t, $p]);
    $pdo->commit(); touch_data();
    out(['ok' => true] + catalog());
}

// ---------------- seed ----------------
function seed_preview(): array {
    $seed = require __DIR__ . '/lib/seed.php';
    $loaded = meta_get('seed_loaded_at');
    $rows = array_map(fn($v) => ['code' => $v[0], 'sku' => $v[1], 'packaging' => $v[2], 'pieces_per_pack' => $v[3],
        'cost_pesewas' => $v[4], 'retail_pesewas' => $v[5], 'opening' => $v[6], 'value_cost_pesewas' => $v[4] * $v[6]], $seed['variations']);
    return ['date' => $seed['date'], 'source' => $seed['source'], 'location' => $seed['location'], 'rows' => $rows,
        'discrepancies' => $seed['discrepancies'], 'loaded_at' => $loaded];
}
function seed_load(): never {
    if (meta_get('seed_loaded_at')) fail('Opening stock was already loaded. Undo that batch first to reload.', 409);
    $seed = require __DIR__ . '/lib/seed.php';
    $pdo = db(); $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO batches (kind, label, rows, created_at) VALUES (?,?,?,?)')->execute(['seed', 'Opening stock ' . $seed['date'], count($seed['variations']), now()]);
    $batch = (int)$pdo->lastInsertId();
    $find = $pdo->prepare('SELECT id FROM variations WHERE code = ?');
    $ins = $pdo->prepare('INSERT INTO variations (code, sku, packaging, pieces_per_pack, cost_pesewas, retail_pesewas, location) VALUES (?,?,?,?,?,?,?)');
    $mv = $pdo->prepare("INSERT INTO movements (date, variation_id, type, qty, cost_pesewas, source, batch_id, note, created_at) VALUES (?,?,'opening',?,?,'seed',?,?,?)");
    foreach ($seed['variations'] as [$code, $sku, $pack, $ppp, $cost, $retail, $qty]) {
        $find->execute([$code]); $id = $find->fetchColumn();
        if (!$id) { $ins->execute([$code, $sku, $pack, $ppp, $cost, $retail, $seed['location']]); $id = $pdo->lastInsertId(); }
        $mv->execute([$seed['date'], $id, $qty, $cost, $batch, $seed['source'], now()]);
    }
    $pdo->commit();
    meta_set('seed_loaded_at', now()); touch_data();
    out(['ok' => true, 'batch_id' => $batch]);
}

// ---------------- movements ----------------
// Validates one movement; returns [row, error]. $r keys: date, sku, variation, type, qty, unit_price, customer, note, order_ref.
function build_movement(array $r, array $byCode, array $bySku): array {
    $date = to_date($r['date'] ?? '');
    if (!$date) return [null, 'date must be YYYY-MM-DD or DD/MM/YYYY'];
    if ($date > date('Y-m-d')) return [null, 'date is in the future'];
    $sku = strtoupper(trim((string)($r['sku'] ?? '')));
    if (!isset($bySku[$sku])) return [null, "sku must be U01 or U02 with at least one variation (got \"{$sku}\")"];
    $code = strtoupper(trim((string)($r['variation'] ?? '')));
    if ($code === '') {
        if (count($bySku[$sku]) !== 1) return [null, "variation is required for {$sku} (one of " . implode(', ', array_keys($bySku[$sku])) . ')'];
        $code = array_key_first($bySku[$sku]);
    }
    $v = $byCode[$code] ?? null;
    if (!$v || $v['sku'] !== $sku) return [null, "variation {$code} is not a {$sku} variation"];
    $type = strtolower(trim((string)($r['type'] ?? '')));
    if (!in_array($type, ['sale', 'receipt', 'adjustment', 'transfer'], true)) return [null, 'type must be sale, receipt, adjustment or transfer'];
    $q = trim(str_replace(',', '', (string)($r['qty'] ?? '')));
    if (!preg_match('/^-?\d+$/', $q) || (int)$q === 0) return [null, 'qty must be a whole number of pieces, not 0'];
    $qty = (int)$q;
    if (in_array($type, ['sale', 'receipt'], true) && $qty < 0) return [null, "{$type} qty must be positive"];
    $unit = 0; $amount = 0;
    if ($type === 'sale') {
        $p = trim((string)($r['unit_price'] ?? ''));
        $unit = $p === '' ? (int)round($v['retail_pesewas'] / max(1, $v['pieces_per_pack'])) : to_pesewas($p);
        if ($unit === null || $unit < 0) return [null, 'unit_price must be an amount in GHS per piece'];
        $amount = $unit * $qty;
        $qty = -$qty;
    }
    return [[
        'date' => $date, 'variation_id' => (int)$v['id'], 'type' => $type, 'qty' => $qty,
        'unit_price_pesewas' => $unit, 'amount_pesewas' => $amount, 'cost_pesewas' => (int)$v['cost_pesewas'],
        'customer' => mb_substr(trim((string)($r['customer'] ?? '')), 0, 120),
        'order_ref' => mb_substr(trim((string)($r['order_ref'] ?? '')), 0, 60),
        'note' => mb_substr(trim((string)($r['note'] ?? '')), 0, 300),
    ], null];
}
function lookup(): array {
    $byCode = []; $bySku = [];
    foreach (variations(true) as $v) { $byCode[$v['code']] = $v; $bySku[$v['sku']][$v['code']] = $v; }
    return [$byCode, $bySku];
}
function insert_movements(array $rows, string $source, ?int $batch): void {
    $s = db()->prepare('INSERT INTO movements (date, variation_id, type, qty, unit_price_pesewas, amount_pesewas, cost_pesewas, customer, order_ref, note, source, batch_id, created_at)
        VALUES (:date, :variation_id, :type, :qty, :unit_price_pesewas, :amount_pesewas, :cost_pesewas, :customer, :order_ref, :note, :source, :batch_id, :created_at)');
    foreach ($rows as $r) $s->execute($r + ['source' => $source, 'batch_id' => $batch, 'created_at' => now()]);
}
function add_movement(array $in): never {
    [$byCode, $bySku] = lookup();
    [$row, $err] = build_movement($in, $byCode, $bySku);
    if ($err) fail(ucfirst($err) . '.');
    insert_movements([$row], 'manual', null); touch_data();
    out(['ok' => true]);
}
function void_movement(array $in): never {
    $s = db()->prepare('UPDATE movements SET voided = 1 WHERE id = ? AND voided = 0');
    $s->execute([(int)($in['id'] ?? 0)]);
    if (!$s->rowCount()) fail('Movement not found or already voided.', 404);
    touch_data(); out(['ok' => true]);
}
function undo_batch(array $in): never {
    $pdo = db(); $id = (int)($in['id'] ?? 0);
    $b = $pdo->prepare('SELECT * FROM batches WHERE id = ? AND undone = 0'); $b->execute([$id]); $batch = $b->fetch();
    if (!$batch) fail('Batch not found or already undone.', 404);
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE movements SET voided = 1 WHERE batch_id = ?')->execute([$id]);
    $pdo->prepare('UPDATE batches SET undone = 1 WHERE id = ?')->execute([$id]);
    $pdo->commit();
    if ($batch['kind'] === 'seed') $pdo->prepare("DELETE FROM meta WHERE k = 'seed_loaded_at'")->execute();
    touch_data(); out(['ok' => true]);
}

// CSV columns: date, sku, variation, type, qty, unit_price, customer, note, order_ref (header row required, any order).
function import_csv(array $in): never {
    $csv = (string)($in['csv'] ?? '');
    if (trim($csv) === '') fail('The file is empty.');
    if (strlen($csv) > 5_000_000) fail('File too large (max 5 MB).');
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $csv); rewind($fh);
    $header = fgetcsv($fh, null, ',', '"', '');
    if (!$header) fail('Missing header row.');
    $cols = array_map(fn($h) => strtolower(trim(str_replace(' ', '_', (string)$h))), $header);
    foreach (['date', 'sku', 'type', 'qty'] as $req) if (!in_array($req, $cols, true)) fail("Missing column \"{$req}\". Expected: date, sku, variation, type, qty, unit_price, customer, note, order_ref.");
    [$byCode, $bySku] = lookup();
    $rows = []; $errors = []; $line = 1;
    while (($rec = fgetcsv($fh, null, ',', '"', '')) !== false) {
        $line++;
        if (count(array_filter($rec, fn($c) => trim((string)$c) !== '')) === 0) continue;
        $r = [];
        foreach ($cols as $i => $c) $r[$c] = $rec[$i] ?? '';
        [$row, $err] = build_movement($r, $byCode, $bySku);
        if ($err) { $errors[] = ['line' => $line, 'error' => $err]; if (count($errors) >= 200) break; }
        else $rows[] = $row;
    }
    fclose($fh);
    if ($errors) fail('Nothing was imported. Fix these rows and try again.', 422, ['errors' => $errors]);
    if (!$rows) fail('No data rows found.');
    $summary = ['rows' => count($rows),
        'sales_pieces' => -array_sum(array_map(fn($r) => $r['type'] === 'sale' ? $r['qty'] : 0, $rows)),
        'revenue_pesewas' => array_sum(array_column($rows, 'amount_pesewas')),
        'from' => min(array_column($rows, 'date')), 'to' => max(array_column($rows, 'date'))];
    if (!empty($in['dry_run'])) out(['ok' => true, 'dry_run' => true, 'summary' => $summary]);
    $pdo = db(); $pdo->beginTransaction();
    $label = mb_substr(trim((string)($in['label'] ?? 'CSV import')), 0, 120);
    $pdo->prepare('INSERT INTO batches (kind, label, rows, created_at) VALUES (?,?,?,?)')->execute(['import', $label, count($rows), now()]);
    $batch = (int)$pdo->lastInsertId();
    insert_movements($rows, 'import', $batch);
    $pdo->commit(); touch_data();
    out(['ok' => true, 'batch_id' => $batch, 'summary' => $summary]);
}

// ---------------- report ----------------
function report(array $q): array {
    $today = date('Y-m-d');
    $to = to_date($q['to'] ?? '') ?? $today;
    $from = to_date($q['from'] ?? '') ?? date('Y-m-d', strtotime($to . ' -29 days'));
    if ($from > $to) [$from, $to] = [$to, $from];
    $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));
    $sku = in_array($q['sku'] ?? '', ['U01', 'U02'], true) ? $q['sku'] : '';
    $code = strtoupper(trim((string)($q['var'] ?? '')));
    $bucket = in_array($q['bucket'] ?? '', ['day', 'week', 'month'], true) ? $q['bucket'] : ($days <= 45 ? 'day' : ($days <= 200 ? 'week' : 'month'));

    $allVars = variations();
    $vars = array_values(array_filter($allVars, fn($v) => (!$sku || $v['sku'] === $sku) && (!$code || $v['code'] === $code)));
    $vIds = array_flip(array_column($vars, 'id'));
    $vById = array_column($allVars, null, 'id');
    $mov = array_values(array_filter(
        db()->query('SELECT * FROM movements WHERE voided = 0 ORDER BY date, id')->fetchAll(),
        fn($m) => isset($vIds[$m['variation_id']])));

    $tiers = db()->query('SELECT * FROM discount_tiers ORDER BY threshold_pesewas DESC')->fetchAll();
    $kpi = fn() => ['revenue' => 0, 'units' => 0, 'cost' => 0, 'margin' => 0, 'orders' => 0];
    $cur = $kpi(); $prev = $kpi();
    $keys = bucket_keys($from, $to, $bucket);
    $series = ['U01' => ['revenue' => array_fill(0, count($keys), 0), 'units' => array_fill(0, count($keys), 0)],
               'U02' => ['revenue' => array_fill(0, count($keys), 0), 'units' => array_fill(0, count($keys), 0)]];
    $keyIdx = array_flip(array_column($keys, 'key'));
    $byVar = []; $cust = []; $orders = []; $prevOrders = [];
    $onHand = []; $sold7 = []; $sold28 = []; $ledger = []; $running = [];
    $d7 = date('Y-m-d', strtotime($today . ' -6 days')); $d28 = date('Y-m-d', strtotime($today . ' -27 days'));

    foreach ($mov as $m) {
        $vid = (int)$m['variation_id']; $v = $vById[$vid];
        $qty = (int)$m['qty'];
        $running[$vid] = ($running[$vid] ?? 0) + $qty;
        $onHand[$vid] = $running[$vid];
        $isSale = $m['type'] === 'sale'; $pcs = -$qty;
        if ($isSale && $m['date'] >= $d7) $sold7[$vid] = ($sold7[$vid] ?? 0) + $pcs;
        if ($isSale && $m['date'] >= $d28) $sold28[$vid] = ($sold28[$vid] ?? 0) + $pcs;
        $orderKey = $m['order_ref'] !== '' ? 'r:' . $m['order_ref'] : ($m['customer'] !== '' ? 'c:' . $m['date'] . '|' . $m['customer'] : 'm:' . $m['id']);
        if ($m['date'] >= $from && $m['date'] <= $to) {
            $ledger[] = ['id' => (int)$m['id'], 'date' => $m['date'], 'code' => $v['code'], 'sku' => $v['sku'], 'type' => $m['type'],
                'qty' => $qty, 'unit_price' => (int)$m['unit_price_pesewas'], 'amount' => (int)$m['amount_pesewas'],
                'customer' => $m['customer'], 'balance' => $running[$vid]];
            if ($isSale) {
                $cur['revenue'] += $m['amount_pesewas']; $cur['units'] += $pcs; $cur['cost'] += $pcs * $m['cost_pesewas'];
                $i = $keyIdx[bucket_key($m['date'], $bucket)];
                $series[$v['sku']]['revenue'][$i] += $m['amount_pesewas']; $series[$v['sku']]['units'][$i] += $pcs;
                $b = &$byVar[$v['code']]; $b ??= ['code' => $v['code'], 'sku' => $v['sku'], 'packaging' => $v['packaging'], 'revenue' => 0, 'units' => 0];
                $b['revenue'] += $m['amount_pesewas']; $b['units'] += $pcs; unset($b);
                $name = $m['customer'] !== '' ? $m['customer'] : '(walk-in / not recorded)';
                $c = &$cust[$name]; $c ??= ['customer' => $name, 'revenue' => 0, 'units' => 0, 'orders' => []];
                $c['revenue'] += $m['amount_pesewas']; $c['units'] += $pcs; $c['orders'][$orderKey] = 1; unset($c);
                $orders[$orderKey] = ($orders[$orderKey] ?? 0) + $m['amount_pesewas'];
            }
        } elseif ($isSale && $m['date'] >= $prevFrom && $m['date'] <= $prevTo) {
            $prev['revenue'] += $m['amount_pesewas']; $prev['units'] += $pcs; $prev['cost'] += $pcs * $m['cost_pesewas'];
            $prevOrders[$orderKey] = 1;
        }
    }
    $cur['margin'] = $cur['revenue'] - $cur['cost']; $cur['orders'] = count($orders);
    $prev['margin'] = $prev['revenue'] - $prev['cost']; $prev['orders'] = count($prevOrders);

    // Discount tiers by order size ("over GHS 2,000" = strictly more than the threshold).
    $tierRows = [];
    foreach (array_reverse($tiers) as $t) $tierRows[$t['threshold_pesewas']] = ['label' => 'Over GHS ' . number_format($t['threshold_pesewas'] / 100) . " ({$t['percent']}%)", 'revenue' => 0, 'orders' => 0];
    $tierRows = [0 => ['label' => 'No discount tier', 'revenue' => 0, 'orders' => 0]] + $tierRows;
    foreach ($orders as $amt) {
        $th = 0;
        foreach ($tiers as $t) if ($amt > $t['threshold_pesewas']) { $th = (int)$t['threshold_pesewas']; break; }
        $tierRows[$th]['revenue'] += $amt; $tierRows[$th]['orders']++;
    }

    // Stock & reorder (as of today, not the date filter).
    $stock = []; $tot = ['pieces' => 0, 'value_cost' => 0, 'value_retail' => 0];
    foreach ($vars as $v) {
        if (!$v['active'] && empty($onHand[$v['id']])) continue;
        $id = (int)$v['id']; $oh = $onHand[$id] ?? 0; $ppp = max(1, (int)$v['pieces_per_pack']);
        $a7 = ($sold7[$id] ?? 0) / 7; $a28 = ($sold28[$id] ?? 0) / 28; $rate = max($a7, $a28);
        $cover = $rate > 0 ? $oh / $rate : null;
        $need = $rate > 0 ? max(0, (int)ceil($rate * 60 - $oh)) : 0;
        if ($oh <= (int)$v['reorder_level'] && $need === 0 && (int)$v['reorder_level'] > 0) $need = (int)$v['reorder_level'] * 2 - $oh;
        $packs = (int)ceil($need / $ppp);
        $retailPc = $v['retail_pesewas'] / $ppp;
        $stock[] = ['code' => $v['code'], 'sku' => $v['sku'], 'packaging' => $v['packaging'], 'location' => $v['location'],
            'pieces_per_pack' => $ppp, 'on_hand' => $oh, 'packs' => intdiv(max(0, $oh), $ppp), 'avg7' => round($a7, 1), 'avg28' => round($a28, 1),
            'days_cover' => $cover === null ? null : round($cover, 1),
            'stockout' => $cover === null ? null : date('Y-m-d', strtotime($today . ' +' . (int)floor($cover) . ' days')),
            'reorder_level' => (int)$v['reorder_level'], 'low' => $oh <= (int)$v['reorder_level'] || ($cover !== null && $cover < 14),
            'suggest_packs' => $packs, 'suggest_pieces' => $packs * $ppp,
            'value_cost' => $oh * (int)$v['cost_pesewas'], 'value_retail' => (int)round($oh * $retailPc)];
        $tot['pieces'] += $oh; $tot['value_cost'] += $oh * (int)$v['cost_pesewas']; $tot['value_retail'] += (int)round($oh * $retailPc);
    }

    usort($cust, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
    $cust = array_map(fn($c) => ['customer' => $c['customer'], 'revenue' => $c['revenue'], 'units' => $c['units'], 'orders' => count($c['orders'])], array_slice($cust, 0, 10));
    usort($byVar, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
    $ledgerTotal = count($ledger);
    $ledger = array_slice(array_reverse($ledger), 0, 1000);

    return [
        'as_of' => now(), 'data_updated_at' => meta_get('data_updated_at'),
        'range' => ['from' => $from, 'to' => $to, 'days' => $days, 'prev_from' => $prevFrom, 'prev_to' => $prevTo, 'bucket' => $bucket],
        'filter' => ['sku' => $sku, 'var' => $code],
        'kpis' => ['current' => $cur, 'previous' => $prev, 'stock' => $tot],
        'series' => ['buckets' => $keys, 'products' => $series],
        'by_variation' => array_values($byVar), 'customers' => $cust, 'tiers' => array_values($tierRows),
        'stock' => $stock, 'ledger' => $ledger, 'ledger_total' => $ledgerTotal,
        'variations' => array_map(fn($v) => ['code' => $v['code'], 'sku' => $v['sku'], 'packaging' => $v['packaging']], $allVars),
    ];
}
function bucket_key(string $d, string $bucket): string {
    $t = strtotime($d);
    return match ($bucket) { 'day' => $d, 'week' => date('Y-m-d', strtotime('monday this week', $t)), 'month' => date('Y-m', $t) };
}
function bucket_keys(string $from, string $to, string $bucket): array {
    $keys = []; $t = strtotime($from); $end = strtotime($to);
    while ($t <= $end) {
        $k = bucket_key(date('Y-m-d', $t), $bucket);
        if (!$keys || end($keys)['key'] !== $k) {
            $label = match ($bucket) { 'day' => date('j M', $t), 'week' => 'Wk ' . date('j M', strtotime($k)), 'month' => date('M Y', $t) };
            $keys[] = ['key' => $k, 'label' => $label];
        }
        $t = strtotime('+1 day', $t);
    }
    return $keys;
}
