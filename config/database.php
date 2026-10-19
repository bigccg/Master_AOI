<?php
/**
 * Configuration & Database Connection
 * AOI Inspection & Shelf Management System
 */

declare(strict_types=1);

session_start();

// ──────────────────────────────────────────────
// Constants
// ──────────────────────────────────────────────
const LINE_NAME     = 'Line AOI-01';
const SHIFT_LABEL   = 'Shift Day (08:00 - 20:00)';

// Magazine = physical rack unit of 50 shelves. Every sheet starts with 1 Magazine (shelf 1-50);
// when all shelves are taken ("Magazine is Max") another Magazine (+50 shelves) can be added after a Confirm.
const MAGAZINE_SIZE  = 50;
const MAGAZINE_LIMIT = 99;    // safety cap only (Shelf 1-4950); the Rack Map pages through them, 6 Magazines per page
const RACK_PER_PAGE  = 6;     // Magazines shown per Rack Map page
const PER_PAGE      = 10;           // Default page size (also used as fallback)
const MAX_ALL_ROWS  = 5000;         // Hard safety cap when limit=all
const DEFAULT_SHEET = 'B200_Gen';

// Number Code format: AOI-P1-(YY)-(MM)-(000)
//   AOI-P1 = fixed prefix (Factory P1), YY/MM = year/month of entry,
//   000    = running number, counted per sheet, restarts at 001 every month.
const CODE_PREFIX   = 'AOI-P1';
const CODE_TZ       = 'Asia/Bangkok';   // month boundary follows factory local time
const CODE_MAX_RUN  = 999;

// Shelf slot format: <sheet prefix><number>, e.g. CD1, A12, B26, C25
const SHELF_PREFIX = [
    'CD300'    => 'CD',
    'A150_Gen' => 'A',
    'B200_Gen' => 'B',
    'C250_Gen' => 'C',
];

// Sheet tabs: key => [label, dot color class]
const SHEETS = [
    'CD300'       => ['CD300',       'bg-lime-500'],
    'A150_Gen'    => ['A150_Gen',    'bg-yellow-400'],
    'B200_Gen'    => ['B200_Gen',    'bg-emerald-500'],
    'C250_Gen'    => ['C250_Gen',    'bg-amber-400'],
];

// Status styling map: status => [container classes, text class, label text]
const STATUS_STYLE = [
    'G'  => ['border-emerald-300 bg-emerald-50', 'text-emerald-700', 'G'],
    'D'  => ['border-red-300 bg-red-50',         'text-red-700',     'D'],
    'GD' => ['border-amber-300 bg-amber-50',     'text-amber-700',   'G+D'],
];

const BOARD_TYPES = ['ES3261A', 'Aluminum', 'L67068'];

const BOARD_SIDES = ['F-Side', 'B-Side'];

const OTHER_OPTIONS = ['Bare', 'Bad Mark'];

// Single source of truth for AOI defect codes (code => label)
const DEFECT_LABELS = [
    1  => 'WRONG COMP.',
    2  => 'COMP. MISDIRECTION',
    3  => 'NON COMP.',
    4  => 'COMP. DAMAGE',
    5  => 'TOMBSTONE',
    6  => 'COMP. MISPOSITION',
    7  => 'COMP. LIFT',
    11 => 'INSUFFICIENT SOLDER',
    13 => 'SOLDER NOT MELT',
    14 => 'SOLDER ON COMP.',
    17 => 'SOLDER BALL',
    18 => 'SOLDER BRIDGE',
    20 => 'SOLDER OPEN',
];

// ──────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────

/**
 * Parse boards.defects column (e.g. "1,5,20,OTHER:รายละเอียด") into structured parts:
 * - codes: int[] of valid DEFECT_LABELS keys
 * - other: bool (true when OTHER or OTHER:<text> is present)
 * - other_text: string (detail text after OTHER:, or empty string)
 */
function parseDefects(?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return ['codes' => [], 'other' => false, 'other_text' => ''];
    }

    $codes     = [];
    $hasOther  = false;
    $otherText = '';

    // updateDefectsInput() appends OTHER or OTHER:<text> at the end; extract it first in case <text> contains commas
    if (preg_match('/(?:^|,)OTHER:(.*)$/s', $raw, $m, PREG_OFFSET_CAPTURE)) {
        $hasOther  = true;
        $otherText = trim($m[1][0]);
        $raw       = substr($raw, 0, (int)$m[0][1]);
    }

    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if ($part === 'OTHER') {
            $hasOther = true;
        } elseif ( str_starts_with($part, 'OTHER:')) {
            $hasOther  = true;
            $otherText = trim(substr($part, 6));
        } elseif (ctype_digit($part)) {
            $code = (int)$part;
            if (isset(DEFECT_LABELS[$code]) && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
    }

    return [
        'codes'      => $codes,
        'other'      => $hasOther,
        'other_text' => $otherText,
    ];
}

/** HTML escape */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Read a GET param as a plain string (ignores array-style params like ?x[]=1) */
function qs(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_scalar($v) ? (string)$v : $default;
}

/** Build URL preserving current query + overrides */
function url(array $over = []): string
{
    $q = array_merge($_GET, $over);
    foreach ($q as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]);
    }
    return '?' . http_build_query($q);
}

/** CSRF token (stored in session) */
function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

/** Sort link generator */
function sortLink(string $col, string $cur, string $curDir): string
{
    $nextDir = ($cur === $col && $curDir === 'ASC') ? 'desc' : 'asc';
    return h(url(['sort' => $col, 'dir' => $nextDir, 'page' => null]));
}

/** 1 => 1st, 2 => 2nd, 3 => 3rd, 4 => 4th, 11 => 11th, 21 => 21st ... */
function ordinal(int $n): string
{
    $s = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}

/** Shelf range of Magazine $n (1-based): 1 => [1, 50], 2 => [51, 100], ... */
function magazineRange(int $n): array
{
    return [($n - 1) * MAGAZINE_SIZE + 1, $n * MAGAZINE_SIZE];
}

/** Heading text, e.g. "Magazine 2nd = 51-100" */
function magazineTitle(int $n): string
{
    [$a, $b] = magazineRange($n);
    return 'Magazine ' . ordinal($n) . " = $a-$b";
}

/**
 * Shelf filter value -> [from, to] shelf_no range, or null.
 * "M2" = Magazine 2nd (51-100); the old B1-B10 / B11-B20 / B21-B25 keys still work for existing links.
 */
function shelfFilterRange(string $f): ?array
{
    if (preg_match('/^M(\d{1,2})$/', $f, $m) && (int)$m[1] >= 1) return magazineRange((int)$m[1]);
    return ['B1-B10' => [1, 10], 'B11-B20' => [11, 20], 'B21-B25' => [21, 25]][$f] ?? null;
}

/**
 * Number of Magazines a sheet has (>= 1). Stored in sheet_magazines, but never less than what
 * the existing shelf numbers need (e.g. a record on shelf 60 implies 2 Magazines).
 */
function magazineCount(PDO $pdo, string $sheet): int
{
    $st = $pdo->prepare('SELECT magazines FROM sheet_magazines WHERE sheet = ?');
    $st->execute([$sheet]);
    $stored = (int)$st->fetchColumn();

    $st = $pdo->prepare('SELECT COALESCE(MAX(shelf_no), 0) FROM boards WHERE sheet = ?');
    $st->execute([$sheet]);
    $needed = (int)ceil((int)$st->fetchColumn() / MAGAZINE_SIZE);

    return max(1, $stored, $needed);
}

/** Magazine / Slot text stored with a record, e.g. "B200_Gen Magazine 5th · Slot 10" */
function magazineSlotText(string $sheet, int $shelfNo): string
{
    $magazine = intdiv($shelfNo - 1, MAGAZINE_SIZE) + 1;
    $slot = (($shelfNo - 1) % MAGAZINE_SIZE) + 1;
    return $sheet . ' Magazine ' . ordinal($magazine) . ' · Slot ' . $slot;
}

/** Magazine / Slot text of an existing row: the stored value, or the legacy format (no sheet) for rows saved before the column existed */
function magazineSlotDisplay(array $row): string
{
    if (!empty($row['mag_slot'])) return (string)$row['mag_slot'];
    $no = (int)$row['shelf_no'];
    return 'Magazine ' . ordinal(intdiv($no - 1, MAGAZINE_SIZE) + 1) . ' · Slot ' . ((($no - 1) % MAGAZINE_SIZE) + 1);
}

/** Shelf letter prefix of a sheet (CD300 => CD, A150_Gen => A, ...) */
function shelfPrefix(string $sheet): string
{
    return SHELF_PREFIX[$sheet] ?? 'B';
}

/** Shelf number from a shelf slot of this sheet (CD12 => 12), or null if the slot is not valid for the sheet */
function parseShelf(string $sheet, string $shelf): ?int
{
    if (!preg_match('/^' . preg_quote(shelfPrefix($sheet), '/') . '(\d{1,3})$/', $shelf, $m)) return null;
    return (int)$m[1] >= 1 ? (int)$m[1] : null;
}

/** Is $code a valid Number Code, e.g. AOI-P1-25-12-023 (running 001-999, month 01-12)? */
function isValidNumberCode(string $code): bool
{
    return (bool)preg_match('/^' . preg_quote(CODE_PREFIX, '/') . '-\d{2}-(0[1-9]|1[0-2])-(?!000)\d{3}$/', $code);
}

/**
 * Next Number Code for a sheet in the current month.
 * Running number is per sheet (CD300, A150_Gen, ...) and restarts at 001 each month.
 * Uses MAX()+1, so gaps left by deleting a middle record are not refilled.
 *
 * @throws RuntimeException when the sheet already used 999 codes this month
 */
function nextNumberCode(PDO $pdo, string $sheet, ?DateTimeInterface $at = null): string
{
    $at ??= new DateTimeImmutable('now', new DateTimeZone(CODE_TZ));
    $prefix = sprintf('%s-%s-%s-', CODE_PREFIX, $at->format('y'), $at->format('m'));

    $st = $pdo->prepare('SELECT MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)) FROM boards WHERE sheet = ? AND code LIKE ?');
    $st->execute([strlen($prefix) + 1, $sheet, $prefix . '___']);
    $next = (int)$st->fetchColumn() + 1;

    if ($next > CODE_MAX_RUN) {
        throw new RuntimeException("Number Code ของชีต $sheet เต็มแล้วสำหรับเดือนนี้ (สูงสุด " . CODE_MAX_RUN . ')');
    }
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

// ──────────────────────────────────────────────
// Database Connection (PDO)
// ──────────────────────────────────────────────

/**
 * Get PDO instance (singleton with retry logic)
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // Host/user/pass/port come from docker-compose.yml (shared MySQL container).
    // The database NAME is deliberately NOT read from DB_NAME: compose sets that to
    // another project's database (smt_machine_request) for every site in ./src.
    // This project has its own database; override with AOI_DB_NAME if needed.
    $host = getenv('DB_HOST') ?: 'db';
    $name = getenv('AOI_DB_NAME') ?: 'Master_AoI';
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        http_response_code(500);
        exit('Invalid database name');
    }
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'rootpassword';
    $port = getenv('DB_PORT') ?: '3306';

    $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";   // no dbname: created/selected below
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true, // allow named param reuse (:q)
    ];

    // MySQL container may not be ready immediately -> retry only on
    // "server unreachable" errors (~30s). Bad credentials / unknown DB fail fast.
    $retryable = [2002, 2003, 2006, 2013];
    $lastErr = null;
    for ($i = 0; $i < 15; $i++) {
        try {
            $pdo = new PDO($dsn, $user, $pass, $opts);
            break;
        } catch (PDOException $e) {
            $lastErr = $e;
            if (!in_array((int)$e->getCode(), $retryable, true)) break;
            sleep(2);
        }
    }

    if ($pdo === null) {
        http_response_code(500);
        exit('Database connection failed: ' . h($lastErr?->getMessage()));
    }

    // Own database for this project (needs CREATE privilege, e.g. root in docker-compose)
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        // no privilege: fall through, USE below fails with a clear message if it doesn't exist
    }
    try {
        $pdo->exec("USE `$name`");
    } catch (PDOException $e) {
        http_response_code(500);
        exit('Database "' . h($name) . '" is not available: ' . h($e->getMessage()));
    }

    // Ensure table exists
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS boards (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            sheet      VARCHAR(30)  NOT NULL,
            code       VARCHAR(40)  NOT NULL,
            shelf      VARCHAR(10)  NOT NULL,
            shelf_no   INT          NOT NULL,
            product    VARCHAR(40)  NOT NULL,
            board_type VARCHAR(30)  NOT NULL DEFAULT "Standard PCB",
            status     VARCHAR(2)   NOT NULL,
            pcs_status VARCHAR(100) NOT NULL DEFAULT "",
            created_at DATETIME     NOT NULL,
            UNIQUE KEY uq_sheet_code (sheet, code),
            UNIQUE KEY uq_sheet_shelf (sheet, shelf_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    // Magazines per sheet (absent row = 1 Magazine)
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS sheet_magazines (
            sheet     VARCHAR(30) NOT NULL PRIMARY KEY,
            magazines INT         NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    // Migration: code was UNIQUE on its own; running numbers are now per sheet,
    // so the same code may exist in different sheets -> unique on (sheet, code).
    $oldIdx = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' AND INDEX_NAME = 'uq_code'"
    )->fetchColumn();
    if ((int)$oldIdx > 0) {
        $pdo->exec('ALTER TABLE boards DROP INDEX uq_code, ADD UNIQUE KEY uq_sheet_code (sheet, code)');
    }

    // Migration: one shelf holds one record per sheet -> unique on (sheet, shelf_no).
    // Only added when existing data has no duplicates, otherwise the app-level check still applies.
    $shelfIdx = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' AND INDEX_NAME = 'uq_sheet_shelf'"
    )->fetchColumn();
    if ((int)$shelfIdx === 0) {
        $dupShelves = (int)$pdo->query(
            'SELECT COUNT(*) FROM (SELECT 1 FROM boards GROUP BY sheet, shelf_no HAVING COUNT(*) > 1) d'
        )->fetchColumn();
        if ($dupShelves === 0) {
            $pdo->exec('ALTER TABLE boards ADD UNIQUE KEY uq_sheet_shelf (sheet, shelf_no)');
        } else {
            error_log("AOI: $dupShelves shelf(s) hold more than one record; uq_sheet_shelf not added until duplicates are fixed.");
        }
    }

    // Migration: add 'side' column (MySQL/SQLite compatible check)
    $hasSide = false;
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $cols = $pdo->query("PRAGMA table_info(boards)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $hasSide = in_array('side', $cols, true);
    } else {
        $res = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' AND COLUMN_NAME = 'side'");
        $hasSide = (int)$res->fetchColumn() > 0;
    }
    if (!$hasSide) {
        $pdo->exec("ALTER TABLE boards ADD COLUMN side VARCHAR(10) NOT NULL DEFAULT 'Top'");
    }

    // Migration: add 'defects' column (MySQL/SQLite compatible check)
    $hasDefects = false;
    if ($driver === 'sqlite') {
        $cols = $pdo->query("PRAGMA table_info(boards)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $hasDefects = in_array('defects', $cols, true);
    } else {
        $res = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' AND COLUMN_NAME = 'defects'");
        $hasDefects = (int)$res->fetchColumn() > 0;
    }
    if (!$hasDefects) {
        $pdo->exec("ALTER TABLE boards ADD COLUMN defects TEXT NULL");
    }

    // Migration: add 'mag_slot' column. Old rows stay NULL (shown in the legacy format); new/edited rows store e.g. "B200_Gen Magazine 5th · Slot 10".
    if ($driver === 'sqlite') {
        $cols = $pdo->query("PRAGMA table_info(boards)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $hasMagSlot = in_array('mag_slot', $cols, true);
    } else {
        $res = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' AND COLUMN_NAME = 'mag_slot'");
        $hasMagSlot = (int)$res->fetchColumn() > 0;
    }
    if (!$hasMagSlot) {
        $pdo->exec("ALTER TABLE boards ADD COLUMN mag_slot VARCHAR(60) NULL");
    }

    return $pdo;
}
