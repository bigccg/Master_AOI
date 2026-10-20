<?php
/**
 * Configuration & Database Connection
 * AOI Inspection & Shelf Management System
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
const PER_PAGE       = 10;    // Default page size (also used as fallback)
const MAX_ALL_ROWS   = 5000;  // Hard safety cap when limit=all

const DEFAULT_ZONE   = 'General';
const DEFAULT_SHEET  = 'B200_Gen';

// Single source of truth for Zone -> Sheet keys mapping.
// Edit here and the entire system (URL validation, tabs, KPI, Rack Map, Defect Matrix, table, export) follows.
//
// !! Do NOT move an existing sheet to another zone here. Every record stores its zone in boards.zone and the
//    screens filter on it (`WHERE zone = ? AND sheet = ?`), so the sheet's existing records would disappear.
//    Adding a new sheet, or giving an empty zone (AIXZ / Automotive) its sheets, needs no data change.
//    A sheet key belongs to exactly one zone (sheetZone() returns the first match).
//    If a sheet really must move, do it while nobody is saving to that sheet:
//      1. Back up the database.
//      2. UPDATE boards SET zone = '<new zone>' WHERE sheet = '<sheet>';   e.g. zone = 'AIXZ' WHERE sheet = 'CD300'
//      3. Move the sheet key to the new zone below and deploy.
//      4. Run the UPDATE from step 2 once more (catches rows saved in between), then check with
//         php scripts/check-duplicate-codes.php: section [2] (zone mismatch) must say "None found".
const ZONES = [
    'General'    => ['CD300', 'A150_Gen', 'B200_Gen', 'C250_Gen'],
    'AIXZ'       => [],
    'Automotive' => [],
];

// Zone display metadata (Thai / English)
const ZONE_META = [
    'General'    => ['label' => 'ทั่วไป / General',    'short' => 'General'],
    'AIXZ'       => ['label' => 'AIXZ / โซน AIXZ',     'short' => 'AIXZ'],
    'Automotive' => ['label' => 'ยานยนต์ / Automotive', 'short' => 'Automotive'],
];

// Sheet tabs: key => [label, dot color class]
const SHEETS = [
    'CD300'    => ['CD300',    'bg-lime-500'],
    'A150_Gen' => ['A150_Gen', 'bg-yellow-400'],
    'B200_Gen' => ['B200_Gen', 'bg-emerald-500'],
    'C250_Gen' => ['C250_Gen', 'bg-amber-400'],
];

// Shelf slot format: <sheet prefix><number>, e.g. CD1, A12, B26, C25
const SHELF_PREFIX = [
    'CD300'    => 'CD',
    'A150_Gen' => 'A',
    'B200_Gen' => 'B',
    'C250_Gen' => 'C',
];

// Number Code format: AOI-P1-(YY)-(MM)-(000)
//   AOI-P1 = fixed prefix (Factory P1), YY/MM = year/month of entry,
//   000    = system-wide running number across all zones and sheets, restarts at 001 every month (Asia/Bangkok).
const CODE_PREFIX   = 'AOI-P1';
const CODE_TZ       = 'Asia/Bangkok';   // month boundary follows factory local time
const CODE_MAX_RUN  = 999;

// Deferred unique indexes (runMigrations): while existing duplicates block uq_code / uq_sheet_shelf, their
// duplicate check and error_log run at most once per this many seconds (flag file in sys_get_temp_dir()).
const MIGRATION_RECHECK_SECONDS = 3600;

// UX Copy (What happened + Why + How to fix, Thai / English)
const ALL_SIZE_INSERT_ERROR = 'ไม่สามารถเพิ่มข้อมูลในแท็บรวมทุกขนาดได้ เนื่องจากต้องระบุชีตและชั้นวางปลายทาง กรุณาเลือกแท็บชีตด้านบนก่อนกดเพิ่มข้อมูล / Cannot add a record from All Size Magazine because a target sheet is required. Please select a sheet tab first.';
const EMPTY_ZONE_INSERT_ERROR = 'ไม่สามารถเพิ่มข้อมูลได้ เนื่องจากโซนนี้ยังไม่มีชีตในระบบ กรุณาสลับไปโซนที่มีชีต (เช่น General) และเลือกชีตก่อน / Cannot add a record because this zone has no sheets configured yet. Please switch to an active zone first.';
const CODE_MAX_RUN_ERROR = 'ออกรหัส Number Code ใหม่ไม่ได้ เนื่องจากรหัสรันรวมของระบบในเดือนนี้ครบ ' . CODE_MAX_RUN . ' รายการแล้ว กรุณารอขึ้นเดือนใหม่ (รีเซ็ตเป็น 001 อัตโนมัติ) หรือติดต่อผู้ดูแลระบบ / Cannot generate a new Number Code because the system-wide monthly limit (' . CODE_MAX_RUN . ') has been reached. Please wait for the next month or contact an administrator.';
const CODE_LOCK_BUSY_ERROR = 'ยังไม่ได้บันทึกรายการนี้ เนื่องจากระบบกำลังออก Number Code ให้ผู้ใช้งานอื่นอยู่ กรุณากดบันทึกอีกครั้ง / This record was not saved because the system is issuing a Number Code to another user. Please click Save again.';

// Status styling map: status => [container classes, text class, label text]
const STATUS_STYLE = [
    'G'  => ['border-emerald-300 bg-emerald-50', 'text-emerald-700', 'G'],
    'D'  => ['border-red-300 bg-red-50',         'text-red-700',     'D'],
    'GD' => ['border-amber-300 bg-amber-50',     'text-amber-700',   'G+D'],
];

const BOARD_TYPES = ['ES3261A', 'Aluminum', 'L67068'];

const BOARD_SIDES = ['F-Side', 'B-Side'];

const OTHER_OPTIONS = ['Bare', 'Bad Mark'];

// Other values that have no Board Classification: G / D / G+D is saved empty and locked in the form
const OTHER_NO_CLASS = ['Bare', 'Bad Mark'];

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
// Zone & Sheet Helpers
// ──────────────────────────────────────────────

/** Validate zone against ZONES whitelist; fallback to DEFAULT_ZONE ('General') */
function normalizeZone(?string $zone): string
{
    $zone = trim((string)$zone);
    return array_key_exists($zone, ZONES) ? $zone : DEFAULT_ZONE;
}

/** Return SHEETS subset belonging to $zone: [sheetKey => [label, dotClass], ...] */
function zoneSheets(string $zone): array
{
    $zone = normalizeZone($zone);
    $out  = [];
    foreach (ZONES[$zone] ?? [] as $shKey) {
        if (isset(SHEETS[$shKey])) {
            $out[$shKey] = SHEETS[$shKey];
        }
    }
    return $out;
}

/** Does $zone have at least one configured sheet? */
function zoneHasSheets(string $zone): bool
{
    return !empty(zoneSheets($zone));
}

/** Default concrete sheet for a zone (e.g. for $modalSheet), or '' when the zone has no sheets */
function defaultSheetForZone(string $zone): string
{
    $sheets = zoneSheets($zone);
    if (empty($sheets)) {
        return '';
    }
    if (isset($sheets[DEFAULT_SHEET])) {
        return DEFAULT_SHEET;
    }
    return (string)array_key_first($sheets);
}

/**
 * Validate $sheet within $zone.
 * If $sheet is not 'ALL' and does not belong to $zone, fallback to 'ALL' (All Size Magazine of that zone).
 */
function resolveSheetInZone(string $zone, ?string $sheet, string $fallback = 'ALL'): string
{
    $zone   = normalizeZone($zone);
    $sheet  = trim((string)$sheet);
    $sheets = zoneSheets($zone);
    if ($sheet === 'ALL') {
        return 'ALL';
    }
    if (isset($sheets[$sheet])) {
        return $sheet;
    }
    return $fallback;
}

/** Find which zone owns $sheet (defaults to DEFAULT_ZONE if unknown) */
function sheetZone(string $sheet): string
{
    foreach (ZONES as $z => $sheetKeys) {
        if (in_array($sheet, $sheetKeys, true)) {
            return $z;
        }
    }
    return DEFAULT_ZONE;
}

/** Label for KPI shelf range when viewing All Sheets in $zone, e.g. "All Sheets (CD, A, B, C)" */
function allSheetsRangeLabel(string $zone): string
{
    $sheets = array_keys(zoneSheets($zone));
    if (empty($sheets)) {
        return 'All Sheets';
    }
    $prefixes = array_map('shelfPrefix', $sheets);
    return 'All Sheets (' . implode(', ', $prefixes) . ')';
}

// ──────────────────────────────────────────────
// General Helpers
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
        } elseif (str_starts_with($part, 'OTHER:')) {
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
    if ($sheet === '' || !isset(SHEETS[$sheet])) {
        return 0;
    }
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
    if ($sheet === '' || !isset(SHEETS[$sheet])) return null;
    if (!preg_match('/^' . preg_quote(shelfPrefix($sheet), '/') . '(\d{1,3})$/', $shelf, $m)) return null;
    return (int)$m[1] >= 1 ? (int)$m[1] : null;
}

/** Is $code a valid Number Code, e.g. AOI-P1-25-12-023 (running 001-999, month 01-12)? */
function isValidNumberCode(string $code): bool
{
    return (bool)preg_match('/^' . preg_quote(CODE_PREFIX, '/') . '-\d{2}-(0[1-9]|1[0-2])-(?!000)\d{3}$/', $code);
}

/**
 * Next system-wide Number Code in the current month (Asia/Bangkok).
 * Running number is shared across ALL zones and ALL sheets and restarts at 001 each month.
 * Uses MAX()+1 across all rows in `boards` for that YY-MM prefix, so gaps left by deleting
 * a middle record are not refilled.
 *
 * @throws RuntimeException when the system already used 999 codes this month
 */
function nextNumberCode(PDO $pdo, ?DateTimeInterface $at = null): string
{
    $at ??= new DateTimeImmutable('now', new DateTimeZone(CODE_TZ));
    $prefix = sprintf('%s-%s-%s-', CODE_PREFIX, $at->format('y'), $at->format('m'));

    $st = $pdo->prepare('SELECT MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)) FROM boards WHERE code LIKE ?');
    $st->execute([strlen($prefix) + 1, $prefix . '___']);
    $next = (int)$st->fetchColumn() + 1;

    if ($next > CODE_MAX_RUN) {
        throw new RuntimeException(CODE_MAX_RUN_ERROR);
    }
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/**
 * Shared validation, system-wide Number Code generation, and concurrency-safe INSERT/UPDATE for boards.
 * Used by both `api/save.php` (AJAX) and `includes/actions.php` (non-AJAX POST fallback).
 *
 * Concurrency strategy:
 * 1. Acquires MySQL advisory lock `GET_LOCK('aoi_board_number_code', 5)` so concurrent saves across any sheet/zone
 *    serialize their `nextNumberCode()` read + INSERT without colliding on the shared monthly sequence.
 *    If the lock is not granted (timeout = 0, error = NULL) nothing is inserted: returns status 503 + CODE_LOCK_BUSY_ERROR.
 * 2. Keeps a 5-attempt retry loop on MySQL error 1062 (duplicate key on `uq_code` / `uq_sheet_code`) as a second
 *    line of defense, plus an explicit app-level duplicate check in case `uq_code` migration was deferred.
 *
 * @return array{
 *   success: bool,
 *   status_code: int,
 *   errors: string[],
 *   message: string,
 *   id: int,
 *   code: string,
 *   shelf: string,
 *   sheet: string,
 *   zone: string,
 *   edit_row: array<string, mixed>
 * }
 */
function saveBoardRecord(PDO $pdo, array $input): array
{
    $id      = (int)($input['id'] ?? 0);
    $zone    = normalizeZone((string)($input['zone'] ?? DEFAULT_ZONE));
    $sheet   = trim((string)($input['sheet'] ?? ''));
    $code    = trim((string)($input['code'] ?? ''));
    $shelf   = strtoupper(trim((string)($input['shelf'] ?? '')));
    $product = strtoupper(trim((string)($input['product'] ?? '')));
    $side    = trim((string)($input['side'] ?? ''));
    $type    = trim((string)($input['board_type'] ?? ''));
    $status  = (string)($input['board_status'] ?? '');
    $pcs     = trim((string)($input['pcs'] ?? ''));
    $defects = trim((string)($input['defects'] ?? ''));

    // Other = Bare / Bad Mark -> no Board Classification (locked empty in the form), so no defects either
    $noClass = in_array($pcs, OTHER_NO_CLASS, true);
    if ($noClass) {
        $status  = '';
        $defects = '';
    }

    $formErrors = [];
    $old = ['id' => 0, 'zone' => $zone, 'sheet' => $sheet, 'code' => '', 'shelf' => '', 'shelf_no' => 0];

    if ($id > 0) {
        $cur = $pdo->prepare('SELECT id, zone, sheet, code, shelf, shelf_no FROM boards WHERE id = ?');
        $cur->execute([$id]);
        $fetched = $cur->fetch();
        if (!$fetched) {
            return [
                'success'     => false,
                'status_code' => 404,
                'errors'      => ['ไม่พบรายการบอร์ดที่ต้องการแก้ไข เนื่องจากรายการอาจถูกลบไปแล้ว กรุณารีเฟรชตารางแล้วลองใหม่ / Record not found. It may have been deleted; please refresh the table.'],
                'message'     => '',
                'id'          => $id,
                'code'        => $code,
                'shelf'       => $shelf,
                'sheet'       => $sheet,
                'zone'        => $zone,
                'edit_row'    => [
                    'id' => $id, 'code' => $code, 'shelf' => $shelf, 'product' => $product, 'side' => $side,
                    'board_type' => $type, 'status' => $status, 'pcs_status' => $pcs, 'defects' => $defects,
                ],
            ];
        }
        $old   = $fetched;
        $sheet = (string)$old['sheet'];
        $zone  = normalizeZone((string)($old['zone'] ?: sheetZone($sheet)));

        $oldCode = (string)$old['code'];
        if ($code === '') {
            $code = $oldCode;
        }
        if ($code !== $oldCode && !isValidNumberCode($code)) {
            $formErrors[] = 'รูปแบบ Number Code ไม่ถูกต้อง เนื่องจากระบบกำหนดรูปแบบเป็น ' . CODE_PREFIX . '-YY-MM-000 (เลขรัน 001-' . CODE_MAX_RUN . ') กรุณาแก้ไขให้ตรงรูปแบบ เช่น ' . CODE_PREFIX . '-25-12-023 / Invalid Number Code format. Expected ' . CODE_PREFIX . '-YY-MM-000 (001-' . CODE_MAX_RUN . ').';
        }
    } else {
        // New record: validate zone has sheets and a concrete sheet in that zone was selected
        if (!zoneHasSheets($zone)) {
            $formErrors[] = EMPTY_ZONE_INSERT_ERROR;
        } elseif ($sheet === '' || $sheet === 'ALL' || !isset(zoneSheets($zone)[$sheet])) {
            $formErrors[] = ALL_SIZE_INSERT_ERROR;
        } else {
            $zone = sheetZone($sheet);
        }
    }

    // Validate Shelf when a valid sheet is known
    $shelfNo = null;
    if (isset(SHEETS[$sheet])) {
        $sp      = shelfPrefix($sheet);
        $shelfNo = parseShelf($sheet, $shelf);
        if ($shelfNo === null) {
            $formErrors[] = "เลือกชั้นวาง (Shelf) ไม่ถูกต้อง เนื่องจากชีต $sheet ใช้รหัสชั้นวางขึ้นต้นด้วย {$sp} กรุณาเลือกชั้นวางจากรายการ ({$sp}1, {$sp}2, ...) / Invalid Shelf for sheet $sheet. Please select {$sp}1, {$sp}2, etc.";
        } else {
            $shelf = $sp . $shelfNo; // normalize e.g. CD05 -> CD5
            $limit = magazineCount($pdo, $sheet) * MAGAZINE_SIZE;
            if ($shelfNo > $limit) {
                $formErrors[] = "เลือกชั้นวาง $shelf ไม่ได้ เนื่องจากเกินความจุชั้นวางปัจจุบันของชีต $sheet (สูงสุด {$sp}{$limit}) กรุณากดเพิ่ม Magazine ที่ Rack Map ก่อน / Shelf $shelf exceeds current capacity ({$sp}{$limit}) for $sheet. Add a Magazine first.";
            } else {
                $taken = $pdo->prepare('SELECT id FROM boards WHERE sheet = ? AND shelf_no = ? AND id <> ?');
                $taken->execute([$sheet, $shelfNo, $id]);
                if ($taken->fetch()) {
                    $formErrors[] = "บันทึกข้อมูลลงชั้นวาง $shelf ไม่ได้ เนื่องจากมีบอร์ดวางอยู่ในชั้นนี้แล้วในชีต $sheet กรุณาเลือกชั้นวางอื่นที่ว่างอยู่ / Shelf $shelf is already occupied in $sheet. Please select an empty shelf.";
                }
            }
        }
    }

    // Validate fields
    if ($product === '' || strlen($product) > 40) {
        $formErrors[] = 'บันทึกไม่ได้ เนื่องจากยังไม่ได้กรอก Product Model (สูงสุดไม่เกิน 40 ตัวอักษร) กรุณาระบุรหัสรุ่นบอร์ด / Product Model is required (max 40 characters).';
    }
    if ($side === '' || !in_array($side, BOARD_SIDES, true)) {
        $formErrors[] = 'บันทึกไม่ได้ เนื่องจากยังไม่ได้เลือกด้านของบอร์ด (Side) กรุณาเลือก F-Side หรือ B-Side / Please select a Board Side (F-Side or B-Side).';
    }
    if ($type === '' || !in_array($type, BOARD_TYPES, true)) {
        $formErrors[] = 'บันทึกไม่ได้ เนื่องจากยังไม่ได้เลือกประเภทบอร์ด (Board Type) กรุณาเลือกประเภทบอร์ดจากรายการ / Please select a valid Board Type.';
    }
    if (!$noClass && !in_array($status, ['G', 'D', 'GD'], true)) {
        $formErrors[] = 'บันทึกไม่ได้ เนื่องจากยังไม่ได้เลือกสถานะบอร์ด (Board Classification) กรุณาเลือก Master [G], Master [D] หรือ [G+D] Mixed / Please select a Board Classification.';
    }
    if ($pcs !== '' && !in_array($pcs, OTHER_OPTIONS, true)) {
        $formErrors[] = 'ตัวเลือก Other ไม่ถูกต้อง กรุณาเลือก Bare, Bad Mark หรือเว้นว่างไว้ / Invalid Other value.';
    }
    if (in_array($status, ['D', 'GD'], true) && $defects === '') {
        $formErrors[] = 'บันทึกไม่ได้ เนื่องจากบอร์ดสถานะ Defect ต้องระบุอาการเสีย กรุณาเลือกข้อบกพร่องอย่างน้อย 1 รายการในแผง Select Defects / Please select at least 1 defect for Defect classification.';
    }

    // System-wide duplicate check on edit (no longer scoped to a single sheet)
    if (!$formErrors && $id > 0) {
        $dup = $pdo->prepare('SELECT id, zone, sheet FROM boards WHERE code = ? AND id <> ? LIMIT 1');
        $dup->execute([$code, $id]);
        if ($dupRow = $dup->fetch()) {
            $dupSheet = (string)$dupRow['sheet'];
            $dupZone  = (string)($dupRow['zone'] ?: sheetZone($dupSheet));
            $formErrors[] = "บันทึกการแก้ไขไม่ได้ เนื่องจาก Number Code \"$code\" ซ้ำกับรายการที่มีอยู่แล้วในระบบ (โซน $dupZone · ชีต $dupSheet) กรุณาระบุรหัสอื่นที่ไม่ซ้ำ / Number Code \"$code\" already exists in the system (Zone $dupZone · Sheet $dupSheet). Please enter a unique code.";
        }
    }

    // Pre-check monthly Number Code capacity for new records before attempting DB write
    if (!$formErrors && $id === 0) {
        try {
            $code = nextNumberCode($pdo);
        } catch (RuntimeException $e) {
            $formErrors[] = $e->getMessage();
        }
    }

    if ($formErrors) {
        return [
            'success'     => false,
            'status_code' => 422,
            'errors'      => $formErrors,
            'message'     => '',
            'id'          => $id,
            'code'        => $code,
            'shelf'       => $shelf,
            'sheet'       => $sheet,
            'zone'        => $zone,
            'edit_row'    => [
                'id' => $id, 'code' => $code, 'shelf' => $shelf, 'product' => $product, 'side' => $side,
                'board_type' => $type, 'status' => $status, 'pcs_status' => $pcs, 'defects' => $defects,
            ],
        ];
    }

    $driver  = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $hasLock = false;

    try {
        if ($id > 0) {
            $st = $pdo->prepare(
                'UPDATE boards
                 SET zone=?, code=?, shelf=?, shelf_no=?, mag_slot=?, product=?, side=?, board_type=?, status=?, pcs_status=?, defects=?
                 WHERE id=? AND sheet=?'
            );
            $st->execute([
                $zone,
                $code,
                $shelf,
                $shelfNo,
                magazineSlotText($sheet, (int)$shelfNo),
                $product,
                $side,
                $type,
                $status,
                $pcs,
                $defects,
                $id,
                $sheet,
            ]);
            $savedId = $id;
            $message = "อัปเดตข้อมูลบอร์ด $code (โซน $zone · ชีต $sheet, Shelf: $shelf) เรียบร้อยแล้ว / Record $code updated.";
        } else {
            // Serialize Number Code generation + INSERT across concurrent requests
            if ($driver === 'mysql') {
                $lockSt  = $pdo->query("SELECT GET_LOCK('aoi_board_number_code', 5)");
                $hasLock = ((int)$lockSt->fetchColumn() === 1);
                if (!$hasLock) {
                    // Another save holds the lock past the timeout (or GET_LOCK failed): never INSERT unlocked
                    throw new RuntimeException(CODE_LOCK_BUSY_ERROR, 503);
                }
            }

            $ins = $pdo->prepare(
                'INSERT INTO boards (zone, sheet, code, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, defects, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $chkCode = $pdo->prepare('SELECT id FROM boards WHERE code = ? LIMIT 1');

            for ($try = 1; ; $try++) {
                try {
                    $code = nextNumberCode($pdo);
                    $chkCode->execute([$code]);
                    if ($chkCode->fetch()) {
                        if ($try >= 5) {
                            throw new RuntimeException('ไม่สามารถออก Number Code ได้เนื่องจากมีการบันทึกพร้อมกันจำนวนมาก กรุณากดบันทึกอีกครั้ง / Could not allocate a unique Number Code due to concurrent saves. Please retry.');
                        }
                        continue;
                    }
                    $ins->execute([
                        $zone,
                        $sheet,
                        $code,
                        $shelf,
                        $shelfNo,
                        magazineSlotText($sheet, (int)$shelfNo),
                        $product,
                        $side,
                        $type,
                        $status,
                        $pcs,
                        $defects,
                        date('Y-m-d H:i:s'),
                    ]);
                    $savedId = (int)$pdo->lastInsertId();
                    break;
                } catch (PDOException $e) {
                    $msg = $e->getMessage();
                    $shelfClash = str_contains($msg, 'uq_sheet_shelf') || str_contains($msg, 'boards.sheet, boards.shelf_no');
                    $isDupKey   = (($e->errorInfo[1] ?? 0) === 1062) || str_contains($msg, 'UNIQUE constraint failed');
                    if (!$isDupKey || $shelfClash || $try >= 5) {
                        throw $e;
                    }
                }
            }
            $message = "บันทึกข้อมูลลงโซน $zone · ชีต $sheet เรียบร้อยแล้ว (Number Code: $code, Shelf: $shelf) / Record saved to Zone $zone · Sheet $sheet.";
        }

        return [
            'success'     => true,
            'status_code' => 200,
            'errors'      => [],
            'message'     => $message,
            'id'          => $savedId,
            'code'        => $code,
            'shelf'       => $shelf,
            'sheet'       => $sheet,
            'zone'        => $zone,
            'edit_row'    => [],
        ];
    } catch (RuntimeException $e) {
        return [
            'success'     => false,
            'status_code' => $e->getCode() === 503 ? 503 : 422, // 503 = Number Code lock busy, retry
            'errors'      => [$e->getMessage()],
            'message'     => '',
            'id'          => $id,
            'code'        => $code,
            'shelf'       => $shelf,
            'sheet'       => $sheet,
            'zone'        => $zone,
            'edit_row'    => [
                'id' => $id, 'code' => $code, 'shelf' => $shelf, 'product' => $product, 'side' => $side,
                'board_type' => $type, 'status' => $status, 'pcs_status' => $pcs, 'defects' => $defects,
            ],
        ];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        $shelfClash = str_contains($msg, 'uq_sheet_shelf') || str_contains($msg, 'boards.sheet, boards.shelf_no');
        $codeClash  = str_contains($msg, 'uq_code') || str_contains($msg, 'uq_sheet_code') || str_contains($msg, 'boards.code');
        $isDupKey   = (($e->errorInfo[1] ?? 0) === 1062) || str_contains($msg, 'UNIQUE constraint failed');

        if ($isDupKey && $shelfClash) {
            $err = "บันทึกข้อมูลลงชั้นวาง $shelf ไม่ได้ เนื่องจากมีผู้ใช้งานอื่นเพิ่งบันทึกชั้นวางนี้ไป กรุณาเลือกชั้นวางอื่นที่ว่างอยู่ / Shelf $shelf was just taken by another user. Please select another empty shelf.";
            $statusCode = 422;
        } elseif ($isDupKey && $codeClash) {
            $err = "บันทึกไม่ได้ เนื่องจาก Number Code \"$code\" ซ้ำกับรายการที่มีอยู่แล้วในระบบ กรุณาลองบันทึกใหม่อีกครั้ง / Number Code \"$code\" already exists in the system. Please retry.";
            $statusCode = 422;
        } else {
            $err = 'เกิดข้อผิดพลาดของฐานข้อมูล ไม่สามารถบันทึกได้ กรุณาลองใหม่อีกครั้ง (' . $e->getMessage() . ') / Database error occurred while saving.';
            $statusCode = 500;
        }

        return [
            'success'     => false,
            'status_code' => $statusCode,
            'errors'      => [$err],
            'message'     => '',
            'id'          => $id,
            'code'        => $code,
            'shelf'       => $shelf,
            'sheet'       => $sheet,
            'zone'        => $zone,
            'edit_row'    => [
                'id' => $id, 'code' => $code, 'shelf' => $shelf, 'product' => $product, 'side' => $side,
                'board_type' => $type, 'status' => $status, 'pcs_status' => $pcs, 'defects' => $defects,
            ],
        ];
    } finally {
        if ($hasLock) {
            try {
                $pdo->query("DO RELEASE_LOCK('aoi_board_number_code')");
            } catch (Throwable $_) {
                // ignore release errors on disconnect
            }
        }
    }
}

/**
 * Flag file that throttles the duplicate check of a unique index runMigrations() could not add yet:
 * one per database + index, e.g. /tmp/aoi-Master_AoI-uq_code.flag; its mtime is the time of the last check.
 */
function migrationFlagFile(PDO $pdo, string $index): string
{
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aoi-' . preg_replace('/[^A-Za-z0-9_]/', '_', $dbName) . "-$index.flag";
}

/**
 * runMigrations() runs on every db() call, so while duplicates keep a unique index out, its GROUP BY check
 * and error_log() would repeat on every request. Returns false while $flag is younger than
 * MIGRATION_RECHECK_SECONDS; otherwise refreshes $flag first (parallel requests then skip) and returns true.
 * A missing, expired or future-dated flag never blocks the check; an unwritable temp dir just means
 * checking on every call, as before.
 */
function migrationCheckDue(string $flag): bool
{
    clearstatcache(true, $flag);
    $mtime = @filemtime($flag);
    if ($mtime !== false) {
        $age = time() - $mtime;
        if ($age >= 0 && $age < MIGRATION_RECHECK_SECONDS) {
            return false;
        }
    }
    $isNew = !is_file($flag);
    if (@touch($flag) && $isNew) {
        @chmod($flag, 0666); // web server and CLI may run as different users: let either refresh it
    }
    return true;
}

/**
 * Run idempotent schema migrations on an active PDO handle (MySQL / SQLite).
 * Separated into a callable function so automated tests and CLI tools can invoke it directly.
 */
function runMigrations(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS boards (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                zone        VARCHAR(30)  NOT NULL DEFAULT "General",
                sheet      VARCHAR(30)  NOT NULL,
                code       VARCHAR(40)  NOT NULL,
                shelf      VARCHAR(10)  NOT NULL,
                shelf_no   INTEGER      NOT NULL,
                product    VARCHAR(40)  NOT NULL,
                board_type VARCHAR(30)  NOT NULL DEFAULT "Standard PCB",
                status     VARCHAR(2)   NOT NULL,
                pcs_status VARCHAR(100) NOT NULL DEFAULT "",
                side       VARCHAR(10)  NOT NULL DEFAULT "Top",
                defects    TEXT         NULL,
                mag_slot   VARCHAR(60)  NULL,
                created_at DATETIME     NOT NULL
            )
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS sheet_magazines (
                sheet     VARCHAR(30) NOT NULL PRIMARY KEY,
                magazines INTEGER     NOT NULL DEFAULT 1
            )
        ');
    } else {
        // Ensure tables exist (MySQL)
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS boards (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                zone       VARCHAR(30)  NOT NULL DEFAULT "General",
                sheet      VARCHAR(30)  NOT NULL,
                code       VARCHAR(40)  NOT NULL,
                shelf      VARCHAR(10)  NOT NULL,
                shelf_no   INT          NOT NULL,
                product    VARCHAR(40)  NOT NULL,
                board_type VARCHAR(30)  NOT NULL DEFAULT "Standard PCB",
                status     VARCHAR(2)   NOT NULL,
                pcs_status VARCHAR(100) NOT NULL DEFAULT "",
                created_at DATETIME     NOT NULL,
                UNIQUE KEY uq_code (code),
                UNIQUE KEY uq_sheet_shelf (sheet, shelf_no),
                KEY idx_zone_sheet (zone, sheet)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS sheet_magazines (
                sheet     VARCHAR(30) NOT NULL PRIMARY KEY,
                magazines INT         NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    // Migration 1: add 'zone' column (existing rows default to 'General', idempotent)
    if ($driver === 'sqlite') {
        $cols = $pdo->query("PRAGMA table_info(boards)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $hasZone = in_array('zone', $cols, true);
        if (!$hasZone) {
            $pdo->exec("ALTER TABLE boards ADD COLUMN zone VARCHAR(30) NOT NULL DEFAULT 'General'");
        }
        $pdo->exec("UPDATE boards SET zone = 'General' WHERE zone = '' OR zone IS NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_zone_sheet ON boards (zone, sheet)");
    } else {
        $res = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' AND COLUMN_NAME = 'zone'");
        $hasZone = (int)$res->fetchColumn() > 0;
        if (!$hasZone) {
            $pdo->exec("ALTER TABLE boards ADD COLUMN zone VARCHAR(30) NOT NULL DEFAULT 'General' AFTER id");
        }
        $pdo->exec("UPDATE boards SET zone = 'General' WHERE zone = '' OR zone IS NULL");

        $zoneIdx = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' AND INDEX_NAME = 'idx_zone_sheet'"
        )->fetchColumn();
        if ($zoneIdx === 0) {
            $pdo->exec('ALTER TABLE boards ADD INDEX idx_zone_sheet (zone, sheet)');
        }
    }

    // Migration 2: System-wide unique Number Code -> unique on `code` alone (`uq_code`).
    // Only added when existing data has no duplicate `code` across sheets; never renumbers existing records.
    if ($driver === 'sqlite') {
        $dupCodes = (int)$pdo->query(
            'SELECT COUNT(*) FROM (SELECT 1 FROM boards GROUP BY code HAVING COUNT(*) > 1) d'
        )->fetchColumn();
        if ($dupCodes === 0) {
            $pdo->exec('DROP INDEX IF EXISTS uq_sheet_code');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_code ON boards (code)');
        } else {
            error_log("AOI: $dupCodes Number Code(s) are duplicated across sheets; uq_code not added until duplicates are resolved.");
        }
        $dupShelves = (int)$pdo->query(
            'SELECT COUNT(*) FROM (SELECT 1 FROM boards GROUP BY sheet, shelf_no HAVING COUNT(*) > 1) d'
        )->fetchColumn();
        if ($dupShelves === 0) {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_sheet_shelf ON boards (sheet, shelf_no)');
        }
    } else {
        $codeIdx = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' AND INDEX_NAME = 'uq_code'"
        )->fetchColumn();
        if ($codeIdx === 0) {
            // Check (and log) at most once per MIGRATION_RECHECK_SECONDS while duplicates remain, see migrationCheckDue()
            $codeFlag = migrationFlagFile($pdo, 'uq_code');
            if (migrationCheckDue($codeFlag)) {
                $dupCodes = (int)$pdo->query(
                    'SELECT COUNT(*) FROM (SELECT 1 FROM boards GROUP BY code HAVING COUNT(*) > 1) d'
                )->fetchColumn();
                if ($dupCodes === 0) {
                    $oldSheetCodeIdx = (int)$pdo->query(
                        "SELECT COUNT(*) FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' AND INDEX_NAME = 'uq_sheet_code'"
                    )->fetchColumn();
                    if ($oldSheetCodeIdx > 0) {
                        $pdo->exec('ALTER TABLE boards DROP INDEX uq_sheet_code, ADD UNIQUE KEY uq_code (code)');
                    } else {
                        $pdo->exec('ALTER TABLE boards ADD UNIQUE KEY uq_code (code)');
                    }
                    @unlink($codeFlag); // the index exists now, so the flag is never read again
                } else {
                    error_log("AOI: $dupCodes Number Code(s) are duplicated across sheets, so uq_code was not added. Review them with: php scripts/check-duplicate-codes.php (rechecked at most every " . intdiv(MIGRATION_RECHECK_SECONDS, 60) . ' min).');
                }
            }
        }

        // Migration 3: one shelf holds one record per sheet -> unique on (sheet, shelf_no).
        $shelfIdx = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' AND INDEX_NAME = 'uq_sheet_shelf'"
        )->fetchColumn();
        if ($shelfIdx === 0) {
            // Same throttle as uq_code above
            $shelfFlag = migrationFlagFile($pdo, 'uq_sheet_shelf');
            if (migrationCheckDue($shelfFlag)) {
                $dupShelves = (int)$pdo->query(
                    'SELECT COUNT(*) FROM (SELECT 1 FROM boards GROUP BY sheet, shelf_no HAVING COUNT(*) > 1) d'
                )->fetchColumn();
                if ($dupShelves === 0) {
                    $pdo->exec('ALTER TABLE boards ADD UNIQUE KEY uq_sheet_shelf (sheet, shelf_no)');
                    @unlink($shelfFlag);
                } else {
                    error_log("AOI: $dupShelves shelf(s) hold more than one record, so uq_sheet_shelf was not added. Find them with: SELECT sheet, shelf_no, COUNT(*) FROM boards GROUP BY sheet, shelf_no HAVING COUNT(*) > 1; (rechecked at most every " . intdiv(MIGRATION_RECHECK_SECONDS, 60) . ' min).');
                }
            }
        }
    }

    // Migration 4: add 'side' column (MySQL/SQLite compatible check)
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

    // Migration 5: add 'defects' column (MySQL/SQLite compatible check)
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

    // Migration 6: add 'mag_slot' column
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
}

// ──────────────────────────────────────────────
// Database Connection (PDO)
// ──────────────────────────────────────────────

/**
 * Get PDO instance (singleton with retry logic)
 *
 * $migrate = false only connects and selects the database: no CREATE DATABASE and no runMigrations().
 * Used by read-only CLI tools (scripts/check-duplicate-codes.php); the first call in a process decides.
 */
function db(bool $migrate = true): PDO
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
    if ($migrate) {
        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            // no privilege: fall through, USE below fails with a clear message if it doesn't exist
        }
    }
    try {
        $pdo->exec("USE `$name`");
    } catch (PDOException $e) {
        http_response_code(500);
        exit('Database "' . h($name) . '" is not available: ' . h($e->getMessage()));
    }

    if ($migrate) {
        runMigrations($pdo);
    }

    return $pdo;
}
