<?php
// Shared store for wiper.chou.net: SQLite at data/wiper.sqlite, migrations, admin PIN, sessions.
// Money is integer pesewas everywhere; quantities are pieces (blades / plugs).

declare(strict_types=1);
date_default_timezone_set('Africa/Accra');

const DATA_DIR = __DIR__ . '/../data';
// sha256 of the one-time setup code needed to set the first admin PIN (useless once a PIN exists).
const SETUP_CODE_SHA256 = 'bc749bd315495cd3682ff1cd94e0e47b5ee4912c5a2b4ef8d503623d49baa47b';
const SESSION_HOURS = 12;
const MAX_TRIES = 5;
const LOCK_SECONDS = 60;
const MOVEMENT_TYPES = ['opening', 'sale', 'receipt', 'adjustment', 'transfer'];

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $deny = DATA_DIR . '/.htaccess';
    if (!file_exists($deny)) file_put_contents($deny, "Require all denied\n");
    $pdo = new PDO('sqlite:' . DATA_DIR . '/wiper.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v < 1) {
        $pdo->exec("
            CREATE TABLE products (sku TEXT PRIMARY KEY, name TEXT NOT NULL, unit TEXT NOT NULL);
            CREATE TABLE variations (
                id INTEGER PRIMARY KEY,
                sku TEXT NOT NULL REFERENCES products(sku),
                code TEXT NOT NULL UNIQUE,
                packaging TEXT NOT NULL,
                pieces_per_pack INTEGER NOT NULL DEFAULT 1,
                cost_pesewas INTEGER NOT NULL DEFAULT 0,      -- cost per piece
                retail_pesewas INTEGER NOT NULL DEFAULT 0,    -- retail per pack
                reorder_level INTEGER NOT NULL DEFAULT 0,     -- pieces
                location TEXT NOT NULL DEFAULT '',
                active INTEGER NOT NULL DEFAULT 1
            );
            CREATE TABLE batches (id INTEGER PRIMARY KEY, kind TEXT NOT NULL, label TEXT, rows INTEGER NOT NULL, created_at TEXT NOT NULL, undone INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE movements (
                id INTEGER PRIMARY KEY,
                date TEXT NOT NULL,                           -- YYYY-MM-DD
                variation_id INTEGER NOT NULL REFERENCES variations(id),
                type TEXT NOT NULL,
                qty INTEGER NOT NULL,                         -- pieces; signed (+ in, - out)
                unit_price_pesewas INTEGER NOT NULL DEFAULT 0,-- per piece, sales only
                amount_pesewas INTEGER NOT NULL DEFAULT 0,    -- sale revenue (positive)
                cost_pesewas INTEGER NOT NULL DEFAULT 0,      -- cost per piece at the time
                customer TEXT NOT NULL DEFAULT '',
                order_ref TEXT NOT NULL DEFAULT '',
                note TEXT NOT NULL DEFAULT '',
                source TEXT NOT NULL DEFAULT 'manual',
                batch_id INTEGER REFERENCES batches(id),
                voided INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            );
            CREATE INDEX movements_date ON movements(date);
            CREATE TABLE discount_tiers (threshold_pesewas INTEGER PRIMARY KEY, percent INTEGER NOT NULL);
            CREATE TABLE sessions (token TEXT PRIMARY KEY, expires_at INTEGER NOT NULL);
            CREATE TABLE attempts (ip TEXT PRIMARY KEY, fails INTEGER NOT NULL, locked_until INTEGER NOT NULL DEFAULT 0);
            CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT NOT NULL);
            INSERT INTO products VALUES ('U01', 'Wiper blade', 'blade'), ('U02', 'Spark plugs', 'plug');
            INSERT INTO discount_tiers VALUES (200000, 5), (500000, 10);
            PRAGMA user_version = 1;
        ");
    }
}

function now(): string { return date('c'); }
function meta_get(string $k): ?string {
    $s = db()->prepare('SELECT v FROM meta WHERE k = ?'); $s->execute([$k]);
    $v = $s->fetchColumn(); return $v === false ? null : $v;
}
function meta_set(string $k, string $v): void {
    db()->prepare('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}
function touch_data(): void { meta_set('data_updated_at', now()); }

// ---- admin PIN (data/admin_pin.json; delete that file to reset, then the setup code is needed again) ----
function pin_file(): string { return DATA_DIR . '/admin_pin.json'; }
function pin_is_set(): bool { db(); return is_file(pin_file()); }
function secret(): string {
    $f = DATA_DIR . '/secret.key';
    if (!is_file($f)) file_put_contents($f, bin2hex(random_bytes(32)));
    return trim((string)file_get_contents($f));
}
function pin_set(string $pin): void {
    file_put_contents(pin_file(), json_encode(['hash' => password_hash($pin . secret(), PASSWORD_DEFAULT), 'set_at' => now()]));
}
function pin_check(string $pin): bool {
    $j = json_decode((string)@file_get_contents(pin_file()), true);
    return is_array($j) && isset($j['hash']) && password_verify($pin . secret(), $j['hash']);
}

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'unknown'; }
function lock_remaining(): int {
    $s = db()->prepare('SELECT locked_until FROM attempts WHERE ip = ?'); $s->execute([client_ip()]);
    return max(0, (int)$s->fetchColumn() - time());
}
function record_fail(): void {
    $pdo = db(); $ip = client_ip();
    $pdo->prepare('INSERT INTO attempts (ip, fails) VALUES (?, 0) ON CONFLICT(ip) DO NOTHING')->execute([$ip]);
    $pdo->prepare('UPDATE attempts SET fails = fails + 1 WHERE ip = ?')->execute([$ip]);
    $pdo->prepare('UPDATE attempts SET fails = 0, locked_until = ? WHERE ip = ? AND fails >= ?')->execute([time() + LOCK_SECONDS, $ip, MAX_TRIES]);
}
function clear_fails(): void { db()->prepare('DELETE FROM attempts WHERE ip = ?')->execute([client_ip()]); }

function session_create(): string {
    $t = bin2hex(random_bytes(24));
    db()->prepare('DELETE FROM sessions WHERE expires_at < ?')->execute([time()]);
    db()->prepare('INSERT INTO sessions VALUES (?, ?)')->execute([hash('sha256', $t), time() + SESSION_HOURS * 3600]);
    return $t;
}
function session_valid(string $t): bool {
    if ($t === '') return false;
    $s = db()->prepare('SELECT 1 FROM sessions WHERE token = ? AND expires_at > ?');
    $s->execute([hash('sha256', $t), time()]);
    return (bool)$s->fetchColumn();
}
function session_end(string $t): void { db()->prepare('DELETE FROM sessions WHERE token = ?')->execute([hash('sha256', $t)]); }

// ---- parsing helpers ----
// "12", "12.5", "1,234.50", "GHS 8" -> pesewas; null when not a number.
function to_pesewas($v): ?int {
    $s = str_replace([',', ' ', 'GHS', 'GH₵', '₵'], '', trim((string)$v));
    if ($s === '') return 0;
    if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) return null;
    $neg = $s[0] === '-'; $s = ltrim($s, '-');
    [$i, $f] = array_pad(explode('.', $s), 2, '');
    $p = (int)$i * 100 + (int)str_pad($f, 2, '0');
    return $neg ? -$p : $p;
}
// YYYY-MM-DD or DD/MM/YYYY (day first, as used in Ghana) -> YYYY-MM-DD; null when invalid.
function to_date($v): ?string {
    $s = trim((string)$v);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    else return null;
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}
