<?php
/**
 * Configuration & Database Connection
 * AOI Inspection & Shelf Management System
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // HttpOnly (no script access) + SameSite=Lax (not sent on cross-site POSTs); strict mode refuses session ids
    // the server never issued. The administrator sign-in (includes/admin-auth.php) relies on this cookie.
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
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

// Default + protected zone: links without a valid ?zone= open it, and it can never be disabled or deleted.
const DEFAULT_ZONE   = 'General';

// ──────────────────────────────────────────────
// DEPRECATED: first-run seed of the Zone & Sheet tables
// ──────────────────────────────────────────────
// Zones and sheets live in the `zones` / `sheets` tables, managed by administrators in
// "จัดการโซนและชีต / Manage Zones & Sheets" (api/topology.php). seedTopology() copies the constants below into
// those tables ONCE, while `zones` is still empty, and nothing else may read them: use topology(), zoneSheets(),
// sheetZone(), shelfPrefix() ... instead. Editing them changes nothing in a database that is already seeded.
const DEFAULT_SHEET  = 'B200_Gen';   // deprecated, unused: a zone's default sheet is now its first enabled sheet

const ZONES = [
    'General'    => ['CD300', 'A150_Gen', 'B200_Gen', 'C250_Gen'],
    'AIXZ'       => [],
    'Automotive' => [],
];

// Zone display metadata (Thai / English)
const ZONE_META = [
    'General'    => ['label' => 'ทั่วไป / General',    'short' => 'General'],
    'AIXZ'       => ['label' => 'AIXZ / โซน AIXZ',     'short' => 'AIXZ'],
    'Automotive' => ['label' => 'Automotive', 'short' => 'Automotive'],
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
// ── end of deprecated seed ──

// Sheet tab dot colours an administrator can pick: Tailwind classes the page already uses (the four seeded
// sheets' colours + the rack map's Bare / Bad Mark / fallback colours). sheets.dot_color only accepts these;
// any other value read from the table is rendered as SHEET_DOT_FALLBACK.
const SHEET_DOT_PALETTE = [
    'bg-lime-500'    => 'เขียวมะนาว / Lime',
    'bg-yellow-400'  => 'เหลือง / Yellow',
    'bg-emerald-500' => 'เขียวมรกต / Emerald',
    'bg-amber-400'   => 'อำพัน / Amber',
    'bg-sky-500'     => 'ฟ้า / Sky',
    'bg-blue-500'    => 'น้ำเงิน / Blue',
    'bg-violet-600'  => 'ม่วง / Violet',
    'bg-slate-500'   => 'เทา / Slate',
];
const SHEET_DOT_FALLBACK = 'bg-slate-500';

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
const ALL_SIZE_INSERT_ERROR = 'ไม่สามารถเพิ่มข้อมูลในแท็บรวมทุกขนาดได้ เนื่องจากต้องระบุ Shelf และชั้นวางปลายทาง กรุณาเลือกแท็บ Shelf ด้านบนก่อนกดเพิ่มข้อมูล / Cannot add a record from All Size Magazine because a target shelf is required. Please select a shelf tab first.';
const EMPTY_ZONE_INSERT_ERROR = 'ไม่สามารถเพิ่มข้อมูลได้ เนื่องจากโซนนี้ยังไม่มี Shelf ในระบบ กรุณาสลับไปโซนที่มี Shelf (เช่น General) และเลือก Shelf ก่อน / Cannot add a record because this zone has no shelves configured yet. Please switch to an active zone first.';
const INACTIVE_ZONE_INSERT_ERROR = 'ไม่สามารถเพิ่มข้อมูลได้ เนื่องจากโซนนี้ถูกปิดใช้งานโดยผู้ดูแลระบบ ข้อมูลเดิมยังดูและส่งออกได้ตามปกติ กรุณาสลับไปโซนอื่นที่เปิดใช้งาน / Cannot add a record because an administrator disabled this zone. Existing records can still be viewed and exported. Please switch to an active zone.';
const NO_ACTIVE_SHEET_INSERT_ERROR = 'ไม่สามารถเพิ่มข้อมูลได้ เนื่องจาก Shelf ทั้งหมดในโซนนี้ถูกปิดใช้งาน กรุณาสลับไปโซนที่มี Shelf เปิดใช้งาน หรือติดต่อผู้ดูแลระบบให้เปิดใช้งาน Shelf / Cannot add a record because every shelf in this zone is disabled. Switch to a zone with an active shelf, or ask an administrator to enable one.';
const INACTIVE_SHEET_INSERT_ERROR = 'ไม่สามารถเพิ่มข้อมูลใน Shelf นี้ได้ เนื่องจาก Shelf ถูกปิดใช้งานโดยผู้ดูแลระบบ ข้อมูลเดิมยังดู แก้ไข และส่งออกได้ตามปกติ กรุณาเลือก Shelf อื่นที่เปิดใช้งานจากแท็บด้านบน / Cannot add a record to this shelf because an administrator disabled it. Existing records can still be viewed, edited and exported. Please pick an active shelf tab above.';
const INACTIVE_SHEET_MAGAZINE_ERROR = 'เพิ่ม Magazine ไม่ได้ เนื่องจาก Shelf นี้ถูกปิดใช้งานโดยผู้ดูแลระบบ กรุณาเลือก Shelf ที่เปิดใช้งาน หรือติดต่อผู้ดูแลระบบให้เปิดใช้งาน Shelf ก่อน / Cannot add a Magazine because an administrator disabled this shelf. Pick an active shelf, or ask an administrator to enable it first.';
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

// AOI Program Tracking Constants
const PROGRAM_STAGES = [
    1 => ['key' => 'stage_1', 'short' => 'Capture', 'label' => 'Capture P/D', 'th' => 'ถ่ายภาพชิ้นงาน P/D'],
    2 => ['key' => 'stage_2', 'short' => 'Good',    'label' => 'Make Good / Sheet', 'th' => 'สร้างแผ่น Good / Sheet'],
    3 => ['key' => 'stage_3', 'short' => 'G+D',     'label' => 'Master Good+Defect', 'th' => 'รวม Master G+D'],
    4 => ['key' => 'stage_4', 'short' => 'Test',    'label' => 'Run Test Program', 'th' => 'ทดสอบโปรแกรมเสร็จ'],
];

const PROGRAM_STAGE_STATUS = [
    0 => ['code' => 'not_started', 'label' => 'ยังไม่เริ่ม / Not Started', 'icon' => 'fa-regular fa-circle', 'color' => 'slate'],
    1 => ['code' => 'in_progress', 'label' => 'กำลังดำเนินการ / In Progress', 'icon' => 'fa-solid fa-circle-half-stroke', 'color' => 'amber'],
    2 => ['code' => 'complete',    'label' => 'เสร็จสมบูรณ์ / Complete',    'icon' => 'fa-solid fa-check', 'color' => 'emerald'],
    9 => ['code' => 'not_needed',  'label' => 'ไม่ต้องทำ / N/A',            'icon' => 'fa-solid fa-slash', 'color' => 'slate-strike'],
];

const PROGRAM_MACHINES = ['OMRON (2D)', 'YAMAHA (3D)', 'TRI INNOVATION'];
const PROGRAM_SIDES    = ['F-Side', 'B-Side'];

// ──────────────────────────────────────────────
// Zone & Sheet Helpers (zones / sheets tables, see topology())
// ──────────────────────────────────────────────
//
// Enabled vs disabled (is_active = 0): a disabled zone or sheet keeps its records and still opens from a direct
// link or export (normalizeZone / resolveSheetInZone accept it), but it is left out of the zone selector and the
// sheet tabs and takes no new records or Magazines (zoneSheets() without $includeDisabled, insertBlockedReason()).
// A deleted key no longer exists, so links to it fall back as before: zone -> DEFAULT_ZONE, sheet -> 'ALL'.

/**
 * Zone & Sheet topology from the `zones` / `sheets` tables, the source of truth (seeded once by seedTopology()).
 * Read once per request and cached:
 *   'zones'  => [zoneKey  => ['key', 'label', 'short_label', 'sort_order', 'is_active', 'created_at', 'sheets' => [sheetKey, ...]]]
 *   'sheets' => [sheetKey => ['key', 'zone', 'label', 'prefix', 'dot', 'sort_order', 'is_active', 'created_at']]
 * Both maps follow sort_order and include disabled rows; the helpers below decide what a caller sees.
 *
 * $pdo picks the database. Helpers that take no handle read the database of the last call that passed one
 * (runMigrations() and saveBoardRecord() pass theirs, so code that works on a given handle - tests, CLI tools -
 * never falls through to db()); only with no handle at all is db() used. $refresh = true re-reads the tables,
 * e.g. after an administrator changed them.
 */
function topology(?PDO $pdo = null, bool $refresh = false): array
{
    static $source = null;
    static $cache  = null;

    if ($pdo !== null && $pdo !== $source) {
        $source = $pdo;
        $cache  = null;
    }
    if ($source === null) {
        $source = db();   // the first db() call may already fill $cache through runMigrations()
    }
    if ($cache === null || $refresh) {
        $cache = loadTopology($source);
    }
    return $cache;
}

/** Read `zones` + `sheets` into the shape described at topology() */
function loadTopology(PDO $pdo): array
{
    $topo = ['zones' => [], 'sheets' => []];
    foreach ($pdo->query('SELECT zone_key, label, short_label, sort_order, is_active, created_at FROM zones ORDER BY sort_order, zone_key') as $r) {
        $key = (string)$r['zone_key'];
        $topo['zones'][$key] = [
            'key'         => $key,
            'label'       => (string)$r['label'],
            'short_label' => (string)$r['short_label'],
            'sort_order'  => (int)$r['sort_order'],
            'is_active'   => (int)$r['is_active'] === 1,
            'created_at'  => (string)$r['created_at'],
            'sheets'      => [],
        ];
    }
    foreach ($pdo->query('SELECT sheet_key, zone_key, label, shelf_prefix, dot_color, sort_order, is_active, created_at FROM sheets ORDER BY sort_order, sheet_key') as $r) {
        $key  = (string)$r['sheet_key'];
        $zone = (string)$r['zone_key'];
        $dot  = (string)$r['dot_color'];
        $topo['sheets'][$key] = [
            'key'        => $key,
            'zone'       => $zone,
            'label'      => (string)$r['label'],
            'prefix'     => (string)$r['shelf_prefix'],
            'dot'        => isset(SHEET_DOT_PALETTE[$dot]) ? $dot : SHEET_DOT_FALLBACK,
            'sort_order' => (int)$r['sort_order'],
            'is_active'  => (int)$r['is_active'] === 1,
            'created_at' => (string)$r['created_at'],
        ];
        if (isset($topo['zones'][$zone])) {
            $topo['zones'][$zone]['sheets'][] = $key;
        }
    }
    return $topo;
}

/** Validate zone against the zones table (enabled or disabled, so direct links keep working); fallback to DEFAULT_ZONE */
function normalizeZone(?string $zone): string
{
    $zone = trim((string)$zone);
    return isset(topology()['zones'][$zone]) ? $zone : DEFAULT_ZONE;
}

/**
 * Sheets of $zone in tab order: [sheetKey => [label, dotClass], ...].
 * Enabled sheets only (tabs, Add Board, Magazine); $includeDisabled adds the disabled ones (direct links, All Size totals).
 */
function zoneSheets(string $zone, bool $includeDisabled = false): array
{
    $topo = topology();
    $out  = [];
    foreach ($topo['zones'][normalizeZone($zone)]['sheets'] ?? [] as $shKey) {
        $sh = $topo['sheets'][$shKey];
        if ($includeDisabled || $sh['is_active']) {
            $out[$shKey] = [$sh['label'], $sh['dot']];
        }
    }
    return $out;
}

/** Does $zone have at least one enabled sheet (or any sheet with $includeDisabled)? */
function zoneHasSheets(string $zone, bool $includeDisabled = false): bool
{
    return !empty(zoneSheets($zone, $includeDisabled));
}

/** Default concrete sheet for a zone (e.g. for $modalSheet): its first enabled sheet, or '' when it has none */
function defaultSheetForZone(string $zone): string
{
    return (string)array_key_first(zoneSheets($zone));
}

/**
 * Validate $sheet within $zone: 'ALL', or any sheet of that zone incl. disabled ones (their records stay reachable
 * by link / export). Anything else (deleted, unknown, another zone's sheet) falls back to $fallback.
 */
function resolveSheetInZone(string $zone, ?string $sheet, string $fallback = 'ALL'): string
{
    $sheet = trim((string)$sheet);
    if ($sheet === 'ALL') {
        return 'ALL';
    }
    return isset(zoneSheets($zone, true)[$sheet]) ? $sheet : $fallback;
}

/** Find which zone owns $sheet (defaults to DEFAULT_ZONE if unknown) */
function sheetZone(string $sheet): string
{
    return topology()['sheets'][$sheet]['zone'] ?? DEFAULT_ZONE;
}

/** Is $sheet a sheet in the sheets table (enabled or disabled)? */
function sheetExists(string $sheet): bool
{
    return $sheet !== '' && isset(topology()['sheets'][$sheet]);
}

function sheetIsActive(string $sheet): bool
{
    return (topology()['sheets'][$sheet]['is_active'] ?? false) === true;
}

function zoneIsActive(string $zone): bool
{
    return (topology()['zones'][$zone]['is_active'] ?? false) === true;
}

/** Zone display label, e.g. "ทั่วไป / General" */
function zoneLabel(string $zone): string
{
    return topology()['zones'][$zone]['label'] ?? $zone;
}

/** Zones offered in the zone selector: every enabled zone, plus $current while a direct link shows a disabled one */
function selectableZones(string $current): array
{
    $keys = [];
    foreach (topology()['zones'] as $key => $z) {
        if ($z['is_active'] || $key === $current) {
            $keys[] = (string)$key;
        }
    }
    return $keys;
}

/** Zone selector text: label + number of enabled shelves, e.g. "ทั่วไป / General (4 Shelf)" (JS: zoneOptionLabelJs) */
function zoneOptionLabel(string $zone): string
{
    $z = topology()['zones'][$zone] ?? null;
    if ($z === null) {
        return $zone;
    }
    $enabled = count(zoneSheets($zone));
    if (!$z['is_active']) {
        $suffix = ' (ปิดใช้งาน / Disabled)';
    } elseif ($enabled > 0) {
        $suffix = " ({$enabled} Shelf)";
    } else {
        $suffix = zoneHasSheets($zone, true) ? ' (ไม่มี Shelf ที่เปิดใช้งาน / No active shelves)' : ' (ยังไม่มี Shelf / No shelves)';
    }
    return $z['label'] . $suffix;
}

/**
 * Why a new record cannot be saved to $sheet in $zone, or '' when it can (JS: insertBlockedReasonJs).
 * Checked in the order a user can fix it: empty zone, disabled zone, no enabled sheet, disabled sheet, no sheet picked.
 */
function insertBlockedReason(string $zone, string $sheet): string
{
    $zone = normalizeZone($zone);
    if (!zoneHasSheets($zone, true)) {
        return EMPTY_ZONE_INSERT_ERROR;
    }
    if (!zoneIsActive($zone)) {
        return INACTIVE_ZONE_INSERT_ERROR;
    }
    if (!zoneHasSheets($zone)) {
        return NO_ACTIVE_SHEET_INSERT_ERROR;
    }
    if (isset(zoneSheets($zone, true)[$sheet]) && !sheetIsActive($sheet)) {
        return INACTIVE_SHEET_INSERT_ERROR;
    }
    if ($sheet === '' || $sheet === 'ALL' || !isset(zoneSheets($zone)[$sheet])) {
        return ALL_SIZE_INSERT_ERROR;
    }
    return '';
}

/** Label for KPI shelf range when viewing All Sheets in $zone, e.g. "All Sheets (CD, A, B, C)" (disabled sheets included) */
function allSheetsRangeLabel(string $zone): string
{
    $sheets = array_keys(zoneSheets($zone, true));
    if (empty($sheets)) {
        return 'All Sheets';
    }
    $prefixes = array_values(array_unique(array_map(static fn($sh): string => shelfPrefix((string)$sh), $sheets)));
    return 'All Sheets (' . implode(', ', $prefixes) . ')';
}

/**
 * Topology for the browser (window.TOPOLOGY in components/layout/scripts.php, api/topology.php responses).
 * Order travels in arrays (zoneOrder, zones[].sheets): JS objects would reorder integer-like keys.
 */
function topologyForJs(): array
{
    $topo  = topology();
    $zones = [];
    foreach ($topo['zones'] as $key => $z) {
        $zones[$key] = ['label' => $z['label'], 'short' => $z['short_label'], 'active' => $z['is_active'], 'sheets' => $z['sheets']];
    }
    $sheets = [];
    foreach ($topo['sheets'] as $key => $sh) {
        $sheets[$key] = ['label' => $sh['label'], 'dot' => $sh['dot'], 'prefix' => $sh['prefix'], 'zone' => $sh['zone'], 'active' => $sh['is_active']];
    }
    return [
        'defaultZone' => DEFAULT_ZONE,
        'zoneOrder'   => array_map('strval', array_keys($zones)),
        'zones'       => (object)$zones,
        'sheets'      => (object)$sheets,
    ];
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
 * Parse a period string 'YYYY-MM' into a [from, to) date range for created_at filtering.
 * Returns null for 'ALL' or invalid values. Works with both MySQL and SQLite.
 * @return ?array{string, string}  ['YYYY-MM-DD 00:00:00', 'YYYY-MM-DD 00:00:00']
 */
function periodRange(string $p): ?array
{
    if ($p === 'THIS') {
        $p = date('Y-m');
    }
    if ($p === 'ALL' || !preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $p, $m)) {
        return null;
    }
    $y  = (int)$m[1];
    $mo = (int)$m[2];
    $from = sprintf('%04d-%02d-01 00:00:00', $y, $mo);
    if ($mo === 12) {
        $to = sprintf('%04d-01-01 00:00:00', $y + 1);
    } else {
        $to = sprintf('%04d-%02d-01 00:00:00', $y, $mo + 1);
    }
    return [$from, $to];
}

/**
 * Available period options for the filter dropdown: months that actually have data
 * within the given zone (and optional sheet), ordered newest → oldest.
 * Each element: ['value' => 'YYYY-MM', 'label' => 'ต.ค. 2026', 'count' => 4]
 * Works with both MySQL and SQLite (uses string substr on created_at).
 * Supports both signatures: periodOptions(PDO $pdo, string $zone, string $sheet = 'ALL')
 * and periodOptions(string $zone, string $sheet = 'ALL', ?PDO $pdo = null).
 */
function periodOptions($a, $b = 'ALL', $c = null): array
{
    if ($a instanceof PDO) {
        $pdo   = $a;
        $zone  = (string)$b;
        $sheet = is_string($c) ? $c : 'ALL';
    } else {
        $pdo   = ($c instanceof PDO) ? $c : db();
        $zone  = (string)$a;
        $sheet = (string)$b;
    }
    $where  = ['zone = :zone'];
    $params = [':zone' => $zone];
    if ($sheet !== 'ALL') {
        $where[]          = 'sheet = :sheet';
        $params[':sheet'] = $sheet;
    }
    $whereSql = implode(' AND ', $where);

    // SUBSTR works on both MySQL and SQLite for 'YYYY-MM-DD HH:MM:SS' strings
    // created_at > '1970-01-01' avoids SQLSTATE[HY000]: 1525 Incorrect DATETIME value: '' on MySQL strict mode
    $sql = "SELECT SUBSTR(created_at, 1, 7) AS ym, COUNT(*) AS cnt
            FROM boards
            WHERE $whereSql AND created_at IS NOT NULL AND created_at > '1970-01-01'
            GROUP BY ym
            ORDER BY ym DESC";

    $st = $pdo->prepare($sql);
    $st->execute($params);

    // Thai abbreviated month names (1-indexed)
    $thaiMonths = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
    ];

    $options = [];
    while ($r = $st->fetch()) {
        $ym = (string)$r['ym'];
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym, $m)) continue;
        $y   = (int)$m[1];
        $mo  = (int)$m[2];
        $options[] = [
            'value' => $ym,
            'label' => ($thaiMonths[$mo] ?? $m[2]) . " $y",
            'count' => (int)$r['cnt'],
        ];
    }
    return $options;
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
    if ($sheet === '' || !isset(topology($pdo)['sheets'][$sheet])) {
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

/** Magazine / Slot text stored with a record, e.g. "Magazine 5th · Slot 10" */
function magazineSlotText(string $sheet, int $shelfNo): string
{
    $magazine = intdiv($shelfNo - 1, MAGAZINE_SIZE) + 1;
    $slot = (($shelfNo - 1) % MAGAZINE_SIZE) + 1;
    return 'Magazine ' . ordinal($magazine) . ' · Slot ' . $slot;
}

/** Magazine / Slot text of an existing row: clean UI display without technical sheet prefix */
function magazineSlotDisplay(array $row): string
{
    $val = !empty($row['mag_slot']) ? (string)$row['mag_slot'] : '';
    if ($val !== '') {
        // Strip sheet prefix if present, e.g. "B200_Gen Magazine 1st · Slot 9" -> "Magazine 1st · Slot 9"
        if (preg_match('/(Magazine\s+\w+\s*·\s*Slot\s+\d+)/u', $val, $m)) {
            return $m[1];
        }
        return $val;
    }
    $no = (int)($row['shelf_no'] ?? 0);
    return 'Magazine ' . ordinal(intdiv($no - 1, MAGAZINE_SIZE) + 1) . ' · Slot ' . ((($no - 1) % MAGAZINE_SIZE) + 1);
}

/** Shelf letter prefix of a sheet (sheets.shelf_prefix, e.g. AX for shelves AX1, AX2 ...), '' for an unknown sheet */
function shelfPrefix(string $sheet): string
{
    return topology()['sheets'][$sheet]['prefix'] ?? '';
}

/** Shelf number from a shelf slot of this sheet (AX12 => 12), or null if the slot is not valid for the sheet */
function parseShelf(string $sheet, string $shelf): ?int
{
    if (!sheetExists($sheet)) return null;
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
 *    line of defense, plus an explicit app-level duplicate check on edit (when code changes) in case `uq_code` migration was deferred.
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
    topology($pdo);   // the zone / sheet checks below read the zones + sheets tables of this database

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
        // New record: the zone needs an enabled sheet and that sheet must be selected (disabled ones take no new records)
        $blocked = insertBlockedReason($zone, $sheet);
        if ($blocked !== '') {
            $formErrors[] = $blocked;
        } else {
            $zone = sheetZone($sheet);
        }
    }

    // Validate Shelf when a valid sheet is known
    $shelfNo = null;
    if (sheetExists($sheet)) {
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

    // System-wide duplicate check on edit (only when code was actually changed, allowing edits to legacy duplicates)
    if (!$formErrors && $id > 0 && $code !== (string)$old['code']) {
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

        if (in_array($status, ['D', 'GD'], true) && in_array($side, PROGRAM_SIDES, true) && $product !== '') {
            try {
                syncProgramFromBoard($pdo, $product, $side, $shelf);
            } catch (Throwable $_) {
                // Non-fatal if aoi_programs table is absent in isolated unit tests
            }
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

    // Migration 7: Zone & Sheet tables (the source of truth, managed in api/topology.php) + their audit log.
    // Keys are case-insensitive (MySQL utf8mb4_unicode_ci / SQLite NOCASE) and never change after creation.
    if ($driver === 'sqlite') {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS zones (
                zone_key    VARCHAR(30) NOT NULL PRIMARY KEY COLLATE NOCASE,
                label       VARCHAR(60) NOT NULL,
                short_label VARCHAR(30) NOT NULL DEFAULT "",
                sort_order  INTEGER     NOT NULL DEFAULT 0,
                is_active   INTEGER     NOT NULL DEFAULT 1,
                created_at  DATETIME    NOT NULL
            )
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS sheets (
                sheet_key    VARCHAR(30) NOT NULL PRIMARY KEY COLLATE NOCASE,
                zone_key     VARCHAR(30) NOT NULL COLLATE NOCASE REFERENCES zones (zone_key),
                label        VARCHAR(60) NOT NULL,
                shelf_prefix VARCHAR(3)  NOT NULL COLLATE NOCASE,
                dot_color    VARCHAR(30) NOT NULL,
                sort_order   INTEGER     NOT NULL DEFAULT 0,
                is_active    INTEGER     NOT NULL DEFAULT 1,
                created_at   DATETIME    NOT NULL
            )
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS topology_audit (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                action      VARCHAR(20) NOT NULL,
                entity_type VARCHAR(10) NOT NULL,
                entity_key  VARCHAR(30) NOT NULL,
                before_json TEXT        NULL,
                after_json  TEXT        NULL,
                ip          VARCHAR(45) NOT NULL DEFAULT "",
                created_at  DATETIME    NOT NULL
            )
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS aoi_programs (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                product_model VARCHAR(50)  NOT NULL,
                side          VARCHAR(10)  NOT NULL DEFAULT "F-Side",
                family_key    VARCHAR(20)  NOT NULL DEFAULT "",
                machine_type  VARCHAR(30)  NOT NULL DEFAULT "OMRON (2D)",
                stage_1       TINYINT      NOT NULL DEFAULT 0,
                stage_1_by    VARCHAR(50)  NULL,
                stage_1_at    DATETIME     NULL,
                stage_2       TINYINT      NOT NULL DEFAULT 0,
                stage_2_by    VARCHAR(50)  NULL,
                stage_2_at    DATETIME     NULL,
                stage_3       TINYINT      NOT NULL DEFAULT 0,
                stage_3_by    VARCHAR(50)  NULL,
                stage_3_at    DATETIME     NULL,
                stage_4       TINYINT      NOT NULL DEFAULT 0,
                stage_4_by    VARCHAR(50)  NULL,
                stage_4_at    DATETIME     NULL,
                owner         VARCHAR(50)  NULL,
                shelf         VARCHAR(50)  NULL,
                program_ok    VARCHAR(50)  NULL,
                wait_reason   VARCHAR(100) NULL,
                note          TEXT         NULL,
                version       INTEGER      NOT NULL DEFAULT 1,
                created_at    DATETIME     NOT NULL,
                updated_at    DATETIME     NOT NULL
            )
        ');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_prog_prod_side ON aoi_programs (product_model, side)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prog_owner ON aoi_programs (owner)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prog_machine ON aoi_programs (machine_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prog_updated ON aoi_programs (updated_at)');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS aoi_program_audit (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                program_id  INTEGER      NOT NULL,
                action      VARCHAR(30)  NOT NULL,
                field_name  VARCHAR(50)  NULL,
                old_value   TEXT         NULL,
                new_value   TEXT         NULL,
                operator    VARCHAR(50)  NOT NULL,
                ip_address  VARCHAR(45)  NOT NULL DEFAULT "",
                created_at  DATETIME     NOT NULL
            )
        ');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prog_audit_created ON aoi_program_audit (program_id, created_at)');
        // Operator dropdown options. Seeded from existing owners ONLY when the table is first created,
        // otherwise a deleted operator would come back on the next request.
        $opsExisted = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'aoi_operators'")->fetchColumn();
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS aoi_operators (
                name       VARCHAR(50) NOT NULL PRIMARY KEY,
                created_at DATETIME    NOT NULL
            )
        ');
        if (!$opsExisted) {
            $pdo->exec('
                INSERT OR IGNORE INTO aoi_operators (name, created_at)
                SELECT DISTINCT owner, datetime("now") FROM aoi_programs WHERE owner IS NOT NULL AND owner != ""
            ');
        }
    } else {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS zones (
                zone_key    VARCHAR(30) NOT NULL PRIMARY KEY,
                label       VARCHAR(60) NOT NULL,
                short_label VARCHAR(30) NOT NULL DEFAULT "",
                sort_order  INT         NOT NULL DEFAULT 0,
                is_active   TINYINT(1)  NOT NULL DEFAULT 1,
                created_at  DATETIME    NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS sheets (
                sheet_key    VARCHAR(30) NOT NULL PRIMARY KEY,
                zone_key     VARCHAR(30) NOT NULL,
                label        VARCHAR(60) NOT NULL,
                shelf_prefix VARCHAR(3)  NOT NULL,
                dot_color    VARCHAR(30) NOT NULL,
                sort_order   INT         NOT NULL DEFAULT 0,
                is_active    TINYINT(1)  NOT NULL DEFAULT 1,
                created_at   DATETIME    NOT NULL,
                KEY idx_shelf_prefix (shelf_prefix),
                KEY idx_zone_order (zone_key, sort_order),
                CONSTRAINT fk_sheets_zone FOREIGN KEY (zone_key) REFERENCES zones (zone_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Drop uq_shelf_prefix if it exists so multiple sheets (e.g. CD200_Gen, CD250_Gen, CD300) can share the same shelf prefix
        $hasUqPrefix = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sheets' AND INDEX_NAME = 'uq_shelf_prefix'"
        )->fetchColumn() > 0;
        if ($hasUqPrefix) {
            $pdo->exec('ALTER TABLE sheets DROP INDEX uq_shelf_prefix');
        }
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS topology_audit (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                action      VARCHAR(20) NOT NULL,
                entity_type VARCHAR(10) NOT NULL,
                entity_key  VARCHAR(30) NOT NULL,
                before_json TEXT        NULL,
                after_json  TEXT        NULL,
                ip          VARCHAR(45) NOT NULL DEFAULT "",
                created_at  DATETIME    NOT NULL,
                KEY idx_entity (entity_type, entity_key, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS aoi_programs (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                product_model VARCHAR(50)  NOT NULL,
                side          VARCHAR(10)  NOT NULL DEFAULT "F-Side",
                family_key    VARCHAR(20)  NOT NULL DEFAULT "",
                machine_type  VARCHAR(30)  NOT NULL DEFAULT "OMRON (2D)",
                stage_1       TINYINT      NOT NULL DEFAULT 0,
                stage_1_by    VARCHAR(50)  NULL,
                stage_1_at    DATETIME     NULL,
                stage_2       TINYINT      NOT NULL DEFAULT 0,
                stage_2_by    VARCHAR(50)  NULL,
                stage_2_at    DATETIME     NULL,
                stage_3       TINYINT      NOT NULL DEFAULT 0,
                stage_3_by    VARCHAR(50)  NULL,
                stage_3_at    DATETIME     NULL,
                stage_4       TINYINT      NOT NULL DEFAULT 0,
                stage_4_by    VARCHAR(50)  NULL,
                stage_4_at    DATETIME     NULL,
                owner         VARCHAR(50)  NULL,
                shelf         VARCHAR(50)  NULL,
                program_ok    VARCHAR(50)  NULL,
                wait_reason   VARCHAR(100) NULL,
                note          TEXT         NULL,
                version       INT          NOT NULL DEFAULT 1,
                created_at    DATETIME     NOT NULL,
                updated_at    DATETIME     NOT NULL,
                UNIQUE KEY uq_prog_prod_side (product_model, side),
                KEY idx_prog_owner (owner),
                KEY idx_prog_machine (machine_type),
                KEY idx_prog_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS aoi_program_audit (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                program_id  INT          NOT NULL,
                action      VARCHAR(30)  NOT NULL,
                field_name  VARCHAR(50)  NULL,
                old_value   TEXT         NULL,
                new_value   TEXT         NULL,
                operator    VARCHAR(50)  NOT NULL,
                ip_address  VARCHAR(45)  NOT NULL DEFAULT "",
                created_at  DATETIME     NOT NULL,
                KEY idx_prog_audit_created (program_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        // Operator dropdown options. Seeded from existing owners ONLY when the table is first created,
        // otherwise a deleted operator would come back on the next request.
        $opsExisted = (bool)$pdo->query("SHOW TABLES LIKE 'aoi_operators'")->fetchColumn();
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS aoi_operators (
                name       VARCHAR(50) NOT NULL PRIMARY KEY,
                created_at DATETIME    NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        if (!$opsExisted) {
            $pdo->exec('
                INSERT IGNORE INTO aoi_operators (name, created_at)
                SELECT DISTINCT owner, NOW() FROM aoi_programs WHERE owner IS NOT NULL AND owner != ""
            ');
        }
    }
    seedTopology($pdo);
    topology($pdo, true);   // (re)load; also makes $pdo the database of helpers that take no handle
    syncProgramsFromMasterBoards($pdo);
}

/**
 * Ensure a program tracking row exists in `aoi_programs` when a Defect (D / GD) board is saved on Master Board.
 * Strictly accepts F-Side or B-Side only; never guesses or converts other side values, and never overwrites
 * existing stage progress, machine_type, owner, or notes.
 */
function syncProgramFromBoard(PDO $pdo, string $product, string $side, string $shelf = ''): bool
{
    $product = strtoupper(trim($product));
    $side    = trim($side);
    $shelf   = strtoupper(trim($shelf));
    if ($product === '' || !in_array($side, PROGRAM_SIDES, true)) {
        return false;
    }

    $parts  = explode('-', $product);
    $family = count($parts) >= 2 ? ($parts[0] . '-' . $parts[1]) : substr($product, 0, 20);
    $family = substr($family, 0, 20);
    $now    = (new DateTimeImmutable('now', new DateTimeZone(CODE_TZ)))->format('Y-m-d H:i:s');

    $chk = $pdo->prepare('SELECT id, shelf FROM aoi_programs WHERE product_model = :model AND side = :side LIMIT 1');
    $chk->execute([':model' => $product, ':side' => $side]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($shelf !== '' && trim((string)($existing['shelf'] ?? '')) === '') {
            $upd = $pdo->prepare('UPDATE aoi_programs SET shelf = :shelf WHERE id = :id');
            $upd->execute([':shelf' => $shelf, ':id' => (int)$existing['id']]);
        }
        return false;
    }

    $driver   = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $insertKw = ($driver === 'sqlite') ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

    $ins = $pdo->prepare("
        $insertKw INTO aoi_programs (
            product_model, side, family_key, machine_type,
            stage_1, stage_1_by, stage_1_at,
            stage_2, stage_2_by, stage_2_at,
            stage_3, stage_3_by, stage_3_at,
            stage_4, stage_4_by, stage_4_at,
            owner, shelf, program_ok, wait_reason, note,
            version, created_at, updated_at
        ) VALUES (
            :model, :side, :family, 'OMRON (2D)',
            0, NULL, NULL,
            0, NULL, NULL,
            0, NULL, NULL,
            0, NULL, NULL,
            NULL, :shelf, 'Queued', NULL, NULL,
            1, :now, :now
        )
    ");
    $ins->execute([
        ':model'  => $product,
        ':side'   => $side,
        ':family' => $family,
        ':shelf'  => $shelf !== '' ? $shelf : null,
        ':now'    => $now,
    ]);

    if ($ins->rowCount() > 0) {
        $newId = (int)$pdo->lastInsertId();
        if ($newId > 0) {
            $log = $pdo->prepare('
                INSERT INTO aoi_program_audit (program_id, action, field_name, old_value, new_value, operator, ip_address, created_at)
                VALUES (:pid, :act, NULL, NULL, :val, :op, :ip, :now)
            ');
            $log->execute([
                ':pid' => $newId,
                ':act' => 'sync_from_board',
                ':val' => "{$product} ({$side})",
                ':op'  => 'Master Board',
                ':ip'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ':now' => $now,
            ]);
        }
        return true;
    }
    return false;
}

/**
 * Non-destructive migration: ensures every (product, side) in `boards` with status IN ('D', 'GD')
 * and valid side IN ('F-Side', 'B-Side') has a corresponding row in `aoi_programs`.
 * Never deletes or modifies existing `aoi_programs` rows, and never guesses invalid `boards.side` values.
 */
function syncProgramsFromMasterBoards(PDO $pdo): int
{
    // Anti-join: only (product, side) pairs that have no aoi_programs row yet come back, so the common case
    // (everything already synced) is ONE query instead of one SELECT per board group. This runs on every
    // request (runMigrations), so it must stay cheap.
    $st = $pdo->query("
        SELECT b.product AS product, b.side AS side, MIN(b.shelf) AS first_shelf
        FROM boards b
        LEFT JOIN aoi_programs p ON p.product_model = b.product AND p.side = b.side
        WHERE b.status IN ('D', 'GD')
          AND b.product != ''
          AND b.side IN ('F-Side', 'B-Side')
          AND p.id IS NULL
        GROUP BY b.product, b.side
    ");
    $added = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
        if (syncProgramFromBoard($pdo, (string)$b['product'], (string)$b['side'], (string)($b['first_shelf'] ?? ''))) {
            $added++;
        }
    }
    return $added;
}

/**
 * First-run seed of `zones` / `sheets` from the deprecated ZONES / ZONE_META / SHEETS / SHELF_PREFIX constants.
 * Runs only while `zones` is empty. DEFAULT_ZONE can never be deleted, so `zones` stays non-empty afterwards and
 * later runs never re-seed or overwrite what administrators changed (not even once every sheet was deleted).
 * One transaction: a failed first request leaves nothing behind; a parallel one finds the rows already there.
 */
function seedTopology(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM zones')->fetchColumn() > 0) {
        return;
    }
    $insert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    $now    = date('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $zoneSt  = $pdo->prepare("$insert INTO zones (zone_key, label, short_label, sort_order, is_active, created_at) VALUES (?, ?, ?, ?, 1, ?)");
        $sheetSt = $pdo->prepare("$insert INTO sheets (sheet_key, zone_key, label, shelf_prefix, dot_color, sort_order, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
        $zoneOrder = 0;
        foreach (ZONES as $zoneKey => $sheetKeys) {
            $zoneOrder += 10;
            $zoneSt->execute([$zoneKey, ZONE_META[$zoneKey]['label'] ?? $zoneKey, ZONE_META[$zoneKey]['short'] ?? $zoneKey, $zoneOrder, $now]);
            $sheetOrder = 0;
            foreach ($sheetKeys as $sheetKey) {
                $sheetOrder += 10;
                [$label, $dot] = SHEETS[$sheetKey];
                $sheetSt->execute([$sheetKey, $zoneKey, $label, SHELF_PREFIX[$sheetKey], $dot, $sheetOrder, $now]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ((int)$pdo->query('SELECT COUNT(*) FROM zones')->fetchColumn() === 0) {
            throw $e;
        }
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
    // Credentials only from the environment (docker-compose.yml sets them for the web service); no fallback in code
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    if (!is_string($user) || $user === '' || !is_string($pass)) {
        http_response_code(500);
        exit('ยังเชื่อมต่อฐานข้อมูลไม่ได้ เนื่องจากยังไม่ได้ตั้งค่าตัวแปร DB_USER / DB_PASS (โค้ดไม่มีรหัสผ่านสำรองแล้ว) กรุณาตั้งค่าตาม OFFLINE.md แล้วลองใหม่ / Database is not configured: set the DB_USER and DB_PASS environment variables (see OFFLINE.md).');
    }
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
