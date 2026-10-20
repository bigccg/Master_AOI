<?php
/**
 * Duplicate Number Code check (read-only, CLI only)
 *
 * Reports what keeps runMigrations() from adding the system-wide unique index `uq_code`:
 *   [1] Number Codes used by more than one row (left over from the old per-sheet running number)
 *   [2] Rows whose `zone` column no longer matches sheetZone(sheet) in ZONES
 *   [3] Whether the uq_code / uq_sheet_code indexes exist
 * Only SELECTs: never changes data, never renumbers, never runs migrations (connects with db(false)).
 *
 *   php scripts/check-duplicate-codes.php
 *   docker exec -it php_app_web php /var/www/html/aoi-web-php/scripts/check-duplicate-codes.php
 *
 * Exit code: 0 = nothing to fix, 2 = duplicate codes or zone mismatches found, 1 = could not check.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("สคริปต์นี้รันได้จาก Command Line เท่านั้น / This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';
session_destroy(); // config/database.php starts a session on include; this report needs none (no sess_* file left behind)

// ──────────────────────────────────────────────
// Output helpers
// ──────────────────────────────────────────────

/** Terminal width of a table cell (cells are kept ASCII; Thai stays in headings and notes) */
function cellWidth(string $s): int
{
    return function_exists('mb_strwidth') ? mb_strwidth($s, 'UTF-8') : strlen($s);
}

/** Print rows as a plain-text table; column indexes in $right are right-aligned */
function printTable(array $headers, array $rows, array $right = []): void
{
    $widths = array_map('cellWidth', $headers);
    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $cell) {
            $widths[$i] = max($widths[$i], cellWidth((string)$cell));
        }
    }
    $line = static function (array $cells) use ($widths, $right): string {
        $out = [];
        foreach (array_values($cells) as $i => $cell) {
            $cell  = (string)$cell;
            $pad   = str_repeat(' ', $widths[$i] - cellWidth($cell));
            $out[] = in_array($i, $right, true) ? $pad . $cell : $cell . $pad;
        }
        return '    ' . rtrim(implode('  ', $out)) . PHP_EOL;
    };

    echo $line($headers);
    echo $line(array_map(static fn(int $w): string => str_repeat('-', $w), $widths));
    foreach ($rows as $row) {
        echo $line($row);
    }
}

/** What happened + Why + How to fix, each as a Thai line followed by its English line */
function explain(array $what, array $why, array $fix): void
{
    foreach (['เกิดอะไร' => $what, 'ทำไม' => $why, 'วิธีแก้' => $fix] as $label => [$th, $en]) {
        echo PHP_EOL . "    $label: $th" . PHP_EOL . "      $en" . PHP_EOL;
    }
}

function heading(string $text): void
{
    echo PHP_EOL . $text . PHP_EOL;
}

// ──────────────────────────────────────────────
// Connect (read-only: db(false) skips CREATE DATABASE and runMigrations())
// ──────────────────────────────────────────────

// Same defaults as db(); only used to explain a failed connection
$dbHost = getenv('DB_HOST') ?: 'db';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('AOI_DB_NAME') ?: 'Master_AoI';

echo "กำลังเชื่อมต่อ MySQL $dbHost:$dbPort ฐานข้อมูล $dbName (ถ้า MySQL ยังไม่พร้อม จะลองซ้ำได้นานสุดราว 30 วินาที)" . PHP_EOL;
echo "Connecting to MySQL $dbHost:$dbPort, database $dbName ..." . PHP_EOL;

// db() prints a short message and calls exit() when it cannot connect: capture that message and explain it instead
$connected = false;
register_shutdown_function(static function () use (&$connected, $dbHost, $dbPort, $dbName): void {
    if ($connected) {
        return;
    }
    $detail = trim(html_entity_decode((string)ob_get_clean(), ENT_QUOTES, 'UTF-8'));
    fwrite(STDERR, PHP_EOL
        . 'ตรวจไม่ได้: เชื่อมต่อฐานข้อมูลไม่สำเร็จ ยังไม่มีการตรวจหรือแก้ไขข้อมูลใด ๆ' . PHP_EOL
        . 'Could not check: the database connection failed. Nothing was checked or changed.' . PHP_EOL
        . PHP_EOL
        . '  รายละเอียด / Detail: ' . ($detail !== '' ? $detail : '(ไม่มีข้อความ / no message)') . PHP_EOL
        . PHP_EOL
        . '  วิธีแก้ / How to fix:' . PHP_EOL
        . '  - MySQL ยังไม่ทำงาน: สั่ง docker compose up -d db แล้วรันใหม่ / MySQL is not running: start it, then retry.' . PHP_EOL
        . "  - รันจากเครื่อง host: DB_HOST=$dbHost อาจใช้ได้เฉพาะใน Docker ให้ระบุ DB_HOST=127.0.0.1 DB_PORT=$dbPort" . PHP_EOL
        . '    Running on the host: "db" only resolves inside Docker, set DB_HOST=127.0.0.1.' . PHP_EOL
        . "  - ไม่พบฐานข้อมูล $dbName: ตรวจค่า AOI_DB_NAME หรือเปิดหน้าเว็บ 1 ครั้ง (ระบบสร้างฐานข้อมูลให้เมื่อเปิดครั้งแรก)" . PHP_EOL
        . '    Unknown database: check AOI_DB_NAME, or open the web page once so the system creates it.' . PHP_EOL
        . '  - วิธีที่ง่ายที่สุด / Easiest: docker exec -it php_app_web php /var/www/html/aoi-web-php/scripts/check-duplicate-codes.php' . PHP_EOL);
    exit(1);
});

ob_start();
$pdo = db(false);
ob_end_flush();
$connected = true;

// ──────────────────────────────────────────────
// Read everything first (SELECT only)
// ──────────────────────────────────────────────

try {
    $hasBoards = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards'"
    )->fetchColumn() > 0;

    if ($hasBoards) {
        $hasZone = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' AND COLUMN_NAME = 'zone'"
        )->fetchColumn() > 0;

        $dups = $pdo->query(
            "SELECT code, COUNT(*) AS cnt,
                    GROUP_CONCAT(DISTINCT sheet ORDER BY sheet SEPARATOR ', ') AS sheets,
                    MIN(created_at) AS first_at, MAX(created_at) AS last_at
             FROM boards GROUP BY code HAVING COUNT(*) > 1 ORDER BY code"
        )->fetchAll();

        $zoneGroups = $hasZone ? $pdo->query(
            "SELECT sheet, zone, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id SEPARATOR ',') AS ids
             FROM boards GROUP BY sheet, zone ORDER BY sheet, zone"
        )->fetchAll() : [];

        $indexes = $pdo->query(
            "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ', ')
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' AND INDEX_NAME IN ('uq_code', 'uq_sheet_code')
             GROUP BY INDEX_NAME"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $dbLabel = (string)$pdo->query('SELECT DATABASE()')->fetchColumn() . ' (' . (string)$pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS) . ')';
} catch (PDOException $e) {
    fwrite(STDERR, PHP_EOL
        . 'ตรวจไม่สำเร็จ: ฐานข้อมูลตอบกลับด้วยข้อผิดพลาด ยังไม่มีการแก้ไขข้อมูลใด ๆ' . PHP_EOL
        . 'Check failed: the database returned an error. Nothing was changed.' . PHP_EOL
        . '  รายละเอียด / Detail: ' . $e->getMessage() . PHP_EOL
        . '  วิธีแก้ / How to fix: ตรวจว่าผู้ใช้ฐานข้อมูลมีสิทธิ์ SELECT แล้วรันใหม่ / Make sure the database user can SELECT, then retry.' . PHP_EOL);
    exit(1);
}

$checkedAt = (new DateTimeImmutable('now', new DateTimeZone(CODE_TZ)))->format('Y-m-d H:i:s');

echo PHP_EOL . str_repeat('=', 78) . PHP_EOL;
echo ' AOI · ตรวจ Number Code ซ้ำ / Duplicate Number Code check' . PHP_EOL;
echo str_repeat('=', 78) . PHP_EOL;
echo " ฐานข้อมูล / Database : $dbLabel" . PHP_EOL;
echo " เวลา / Checked at    : $checkedAt (" . CODE_TZ . ')' . PHP_EOL;
echo ' โหมด / Mode          : อ่านอย่างเดียว ไม่แก้ไขข้อมูล ไม่ renumber / Read-only, nothing is changed' . PHP_EOL;

if (!$hasBoards) {
    heading('ยังไม่มีตาราง boards ในฐานข้อมูลนี้ จึงไม่มีรหัสให้ตรวจ ระบบจะสร้างตารางพร้อม uq_code เมื่อเปิดหน้าเว็บครั้งแรก');
    echo 'No boards table yet, so there is nothing to check. Opening the web page once creates it (with uq_code).' . PHP_EOL;
    exit(0);
}

// ──────────────────────────────────────────────
// [1] Duplicate Number Codes
// ──────────────────────────────────────────────

heading('[1] Number Code ที่ซ้ำข้ามชีต / Duplicate Number Codes across sheets');

$dupRows = array_sum(array_map(static fn(array $d): int => (int)$d['cnt'], $dups));
if (!$dups) {
    echo '    ไม่พบรหัสซ้ำ / No duplicates found.' . PHP_EOL;
} else {
    echo '    พบ ' . count($dups) . " รหัส รวม $dupRows แถว / " . count($dups) . " code(s), $dupRows rows" . PHP_EOL . PHP_EOL;
    printTable(
        ['Number Code', 'Rows', 'Sheets', 'First created_at', 'Last created_at'],
        array_map(static fn(array $d): array => [$d['code'], $d['cnt'], $d['sheets'], $d['first_at'], $d['last_at']], $dups),
        [1]
    );
    explain(
        ['Number Code เดียวกันถูกใช้มากกว่า 1 แถว', 'The same Number Code is used by more than one row.'],
        ['แถวเหล่านี้บันทึกในช่วงที่เลขรันแยกตามชีต ระบบจึงยังไม่เพิ่ม uq_code เพื่อไม่ renumber รหัสเดิมให้อัตโนมัติ การบันทึกใหม่ยังปลอดภัย เพราะใช้เลข MAX+1 ของทั้งระบบ',
         'They were saved while the running number was per sheet. uq_code is held back so existing codes are never renumbered automatically; new saves stay safe because they use the system-wide MAX+1.'],
        ['ถ้าต้องการเปิดใช้ uq_code ให้เทียบกับป้ายบอร์ดจริงว่าแถวไหนถือรหัสนั้น แล้วแก้ Number Code ของแถวที่เหลือด้วยปุ่มแก้ไขบนหน้าเว็บ (หรือลบแถวที่บันทึกซ้ำ) จนแต่ละรหัสเหลือ 1 แถว แล้วรันสคริปต์นี้อีกครั้ง',
         'To enable uq_code, compare with the physical board labels, edit the Number Code of the other rows in the web UI (or delete accidental duplicates) until each code has one row, then run this script again.']
    );
    echo PHP_EOL . "    ดูแถวของรหัส / List the rows of a code: SELECT id, zone, sheet, shelf, created_at FROM boards WHERE code = '<Number Code>';" . PHP_EOL;
}

// ──────────────────────────────────────────────
// [2] zone column vs ZONES config
// ──────────────────────────────────────────────

heading('[2] แถวที่ zone ไม่ตรงกับ ZONES ใน config / Rows whose zone does not match ZONES');

$configuredSheets = array_merge(...array_values(ZONES));
$mismatch = array_values(array_filter(
    $zoneGroups,
    static fn(array $g): bool => (string)$g['zone'] !== sheetZone((string)$g['sheet'])
));
$mismatchRows = array_sum(array_map(static fn(array $g): int => (int)$g['cnt'], $mismatch));

if (!$hasZone) {
    echo '    ข้ามการตรวจ: ยังไม่มีคอลัมน์ zone (ฐานข้อมูลยังไม่ผ่าน migration ล่าสุด) เปิดหน้าเว็บ 1 ครั้งแล้วรันสคริปต์นี้ใหม่' . PHP_EOL;
    echo '    Skipped: there is no zone column yet. Open the web page once, then run this script again.' . PHP_EOL;
} elseif (!$mismatch) {
    echo '    ไม่พบ / None found.' . PHP_EOL;
} else {
    echo "    พบ $mismatchRows แถว / $mismatchRows row(s)" . PHP_EOL . PHP_EOL;
    printTable(
        ['Sheet', 'Stored zone', 'sheetZone()', 'Rows', 'IDs'],
        array_map(static function (array $g) use ($configuredSheets): array {
            $ids  = explode(',', (string)$g['ids']);
            $more = count($ids) > 10 ? ' ... (+' . (count($ids) - 10) . ')' : '';
            $want = in_array($g['sheet'], $configuredSheets, true) ? sheetZone((string)$g['sheet']) : '(not in ZONES)';
            return [$g['sheet'], $g['zone'], $want, $g['cnt'], implode(',', array_slice($ids, 0, 10)) . $more];
        }, $mismatch),
        [3]
    );
    explain(
        ['แถวเหล่านี้เก็บ zone ไม่ตรงกับโซนของชีตใน ZONES (config/database.php)', 'These rows store a zone that differs from the zone of their sheet in ZONES.'],
        ['ชีตถูกย้ายโซนใน config หลังบันทึกข้อมูล หน้าจอกรองด้วย zone + sheet จึงไม่แสดงแถวเหล่านี้',
         'The sheet was moved to another zone in config after these rows were saved; screens filter by zone + sheet, so the rows are hidden.'],
        ['สำรองข้อมูลก่อน แล้วรัน SQL ด้านล่าง (เช่นใน phpMyAdmin) ตามขั้นตอนที่ comment ของ ZONES',
         'Back up first, then run the SQL below (e.g. in phpMyAdmin), as described in the ZONES comment.']
    );
    echo PHP_EOL;
    foreach (array_unique(array_column($mismatch, 'sheet')) as $sh) {
        if (in_array($sh, $configuredSheets, true)) {
            echo "    UPDATE boards SET zone = '" . sheetZone((string)$sh) . "' WHERE sheet = '$sh';" . PHP_EOL;
        } else {
            echo "    -- ชีต $sh ไม่อยู่ใน ZONES: เพิ่มชีตกลับใน config/database.php ก่อน (SQL อย่างเดียวไม่ทำให้แถวกลับมาแสดง)" . PHP_EOL;
            echo "    -- Sheet $sh is not in ZONES: add it back to config first (SQL alone will not bring the rows back)." . PHP_EOL;
        }
    }
}

// ──────────────────────────────────────────────
// [3] Index status
// ──────────────────────────────────────────────

heading('[3] สถานะ index / Index status');

$hasCodeIdx  = isset($indexes['uq_code']);
$hasSheetIdx = isset($indexes['uq_sheet_code']);
printTable(
    ['Index', 'Columns', 'Status'],
    [
        ['uq_code', $indexes['uq_code'] ?? '(code)',
         $hasCodeIdx ? 'มี / present: ฐานข้อมูลกันรหัสซ้ำทั้งระบบ' : 'ไม่มี / missing'],
        ['uq_sheet_code', $indexes['uq_sheet_code'] ?? '(sheet, code)',
         $hasSheetIdx ? 'มี / present: index เดิม กันรหัสซ้ำเฉพาะในชีตเดียวกัน' : 'ไม่มี / missing'],
    ]
);
if ($hasCodeIdx && $hasSheetIdx) {
    echo PHP_EOL . '    มีทั้งสอง index: migration ไม่ลบ uq_sheet_code ให้เมื่อมี uq_code อยู่แล้ว ลบเองได้ด้วย ALTER TABLE boards DROP INDEX uq_sheet_code; (ไม่กระทบข้อมูล)' . PHP_EOL;
    echo '    Both exist: migrations leave uq_sheet_code alone once uq_code exists; drop it with the statement above (data is not affected).' . PHP_EOL;
}

// ──────────────────────────────────────────────
// Summary
// ──────────────────────────────────────────────

heading('สรุป / Summary');

// The web re-checks a missing uq_code at most once per MIGRATION_RECHECK_SECONDS (migrationCheckDue()).
// The flag is only visible here when this script shares the web server's temp dir (e.g. docker exec).
$recheckMin = intdiv(MIGRATION_RECHECK_SECONDS, 60);
$codeFlag   = migrationFlagFile($pdo, 'uq_code');
clearstatcache(true, $codeFlag);
$flagTime   = @filemtime($codeFlag);
$flagFresh  = $flagTime !== false && time() - $flagTime >= 0 && time() - $flagTime < MIGRATION_RECHECK_SECONDS;

if (!$dups && $hasCodeIdx) {
    echo '  OK: Number Code ไม่ซ้ำ และมี uq_code แล้ว ไม่ต้องทำอะไรเพิ่ม' . PHP_EOL;
    echo '      No duplicate Number Codes and uq_code is in place. Nothing to do.' . PHP_EOL;
} elseif (!$dups && $flagFresh) {
    $next = (new DateTimeImmutable('@' . ($flagTime + MIGRATION_RECHECK_SECONDS)))->setTimezone(new DateTimeZone(CODE_TZ))->format('H:i:s');
    echo "  OK: ไม่พบ Number Code ซ้ำ ระบบจะเพิ่ม uq_code (และลบ uq_sheet_code เดิม) ให้อัตโนมัติเมื่อโหลดหน้าเว็บครั้งแรกหลังเวลา $next" . PHP_EOL;
    echo "      เนื่องจากเว็บเพิ่งตรวจพบรหัสซ้ำ จึงพักการตรวจไว้ $recheckMin นาที ถ้าไม่ต้องการรอ ให้ลบไฟล์ด้านล่าง (ในเครื่องเดียวกับเว็บ) แล้วโหลดหน้าเว็บ 1 ครั้ง" . PHP_EOL;
    echo "      No duplicate Number Codes: uq_code is added automatically on the first web page load after $next" . PHP_EOL;
    echo "      (the web found duplicates recently and pauses its check for $recheckMin min). To skip the wait, delete this file, then load the page once:" . PHP_EOL;
    echo "      rm $codeFlag" . PHP_EOL;
} elseif (!$dups) {
    echo '  OK: ไม่พบ Number Code ซ้ำ ระบบจะเพิ่ม uq_code (และลบ uq_sheet_code เดิม) ให้อัตโนมัติเมื่อโหลดหน้าเว็บครั้งถัดไป ไม่ต้องรันคำสั่งเพิ่ม' . PHP_EOL;
    echo "      (เว็บตรวจไม่เกินทุก $recheckMin นาที ถ้าเว็บเพิ่งพบรหัสซ้ำในช่วง $recheckMin นาทีที่ผ่านมา จะเพิ่มให้เมื่อครบช่วงนั้น)" . PHP_EOL;
    echo '      No duplicate Number Codes: uq_code is added automatically (and uq_sheet_code dropped) on the next web page load' . PHP_EOL;
    echo "      (the web checks at most every $recheckMin min; if it found duplicates within that time, it waits for the window to end)." . PHP_EOL;
} else {
    echo '  ต้องแก้: ยังเพิ่ม uq_code ไม่ได้ เนื่องจากมี Number Code ซ้ำ ' . count($dups) . ' รหัส ระหว่างนี้ระบบยังบันทึกงานใหม่ได้ตามปกติ และรหัสใหม่ไม่ชนรหัสเดิม' . PHP_EOL;
    echo '  Action needed: uq_code cannot be added while ' . count($dups) . ' Number Code(s) are duplicated. New saves keep working and never reuse an existing code.' . PHP_EOL;
    echo "  เมื่อแก้ครบและรันสคริปต์นี้แล้วไม่พบรหัสซ้ำ ระบบจะเพิ่ม uq_code ให้อัตโนมัติเมื่อโหลดหน้าเว็บครั้งถัดไป (เว็บตรวจไม่เกินทุก $recheckMin นาที)" . PHP_EOL;
    echo "  Once this script finds no duplicates, uq_code is added automatically on the next web page load (the web rechecks at most every $recheckMin min)." . PHP_EOL;
}
if ($mismatch) {
    echo "  ต้องแก้: มี $mismatchRows แถวที่ zone ไม่ตรงกับ ZONES (หัวข้อ [2]) แถวเหล่านี้จะไม่แสดงบนหน้าจอจนกว่าจะรัน SQL ที่แนะนำ" . PHP_EOL;
    echo "  Action needed: $mismatchRows row(s) have a zone that does not match ZONES (section [2]); they stay hidden until the suggested SQL is run." . PHP_EOL;
}

exit(($dups || $mismatch) ? 2 : 0);
