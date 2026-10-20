<?php
/**
 * Regression tests for the system-wide Number Code work on MySQL (review checklist ก-ฉ):
 *   (ก) runMigrations() twice: no error; the duplicate check and its error_log run at most once per hour
 *   (ข) old DB with cross-sheet duplicate codes: uq_code not added, new saves get MAX+1 without collisions
 *   (ค) old DB without duplicates: uq_code added, uq_sheet_code dropped (also once duplicates are fixed)
 *   (ง) GET_LOCK not granted: no INSERT, 503 + CODE_LOCK_BUSY_ERROR (saveBoardRecord, api/save.php, index.php)
 *   (จ) scripts/check-duplicate-codes.php runs from the CLI, changes nothing, refuses web requests
 *   (ฉ) web: saving in General works; empty zones (AIXZ / Automotive) are rejected with EMPTY_ZONE_INSERT_ERROR
 *
 * Connects with DB_HOST / DB_PORT / DB_USER / DB_PASS (same defaults as db()), creates throwaway databases
 * aoi_test_<run>_* and drops them at the end; Master_AoI is never touched. Needs CREATE / DROP DATABASE
 * (root in docker-compose). (ง) holds GET_LOCK('aoi_board_number_code') for about 15 s in total.
 *
 *   docker exec php_app_web php /var/www/html/aoi-web-php/tests/number-code-test.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';
session_destroy(); // config/database.php starts a session; tests do not need it

error_reporting(E_ALL);
$phpWarnings = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$phpWarnings): bool {
    if (error_reporting() & $no) {
        $phpWarnings[] = "$msg ($file:$line)";
    }
    return true;
});

$root         = dirname(__DIR__);
$runId        = date('His') . bin2hex(random_bytes(2));
$databases    = [];
$cleanup      = [];
$passed       = 0;
$failed       = 0;
$server       = null;
$base         = '';
$cookieJar    = '';
$bodyWarnings = [];
$now          = new DateTimeImmutable('now', new DateTimeZone(CODE_TZ));
$prefix       = sprintf('%s-%s-%s-', CODE_PREFIX, $now->format('y'), $now->format('m'));

$logFile   = (string)tempnam(sys_get_temp_dir(), 'aoi-test-log-');
$sessDir   = sys_get_temp_dir() . "/aoi-test-sess-$runId";
$cleanup[] = $logFile;
mkdir($sessDir);
ini_set('error_log', $logFile);

register_shutdown_function(static function () use (&$databases, &$cleanup, &$server, $sessDir): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    try {
        $pdo = connect();
        foreach ($databases as $db) {
            $pdo->exec("DROP DATABASE IF EXISTS `$db`");
        }
    } catch (Throwable $e) {
        echo 'cleanup: ' . $e->getMessage() . PHP_EOL;
    }
    foreach ($cleanup as $file) {
        @unlink($file);
    }
    array_map('unlink', glob("$sessDir/*") ?: []);
    @rmdir($sessDir);
});

// ──────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────

/** PDO that records every statement; can swap the GET_LOCK() query to simulate a lock error (NULL) */
final class TestPdo extends PDO
{
    public array $queries = [];
    public ?string $fakeLockSql = null;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queries[] = $query;
        if ($this->fakeLockSql !== null && str_contains($query, 'GET_LOCK(')) {
            $query = $this->fakeLockSql;
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->queries[] = $statement;
        return parent::exec($statement);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return parent::prepare($query, $options);
    }

    public function count(string $needle): int
    {
        return count(array_filter($this->queries, static fn(string $q): bool => str_contains($q, $needle)));
    }
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    if (!$ok && $detail !== '') {
        echo '        -> ' . $detail . PHP_EOL;
    }
}

function section(string $title): void
{
    echo PHP_EOL . $title . PHP_EOL;
}

/** Same connection options as db() */
function connect(?string $dbName = null): TestPdo
{
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '3306';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'rootpassword';
    $pdo  = new TestPdo("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]);
    if ($dbName !== null) {
        $pdo->exec("USE `$dbName`");
        $pdo->queries = [];
    }
    return $pdo;
}

/** Create an empty throwaway database (dropped at shutdown together with its flag files) */
function newDb(string $suffix): string
{
    global $runId, $databases, $cleanup;
    $name        = "aoi_test_{$runId}_$suffix";
    $databases[] = $name;
    $pdo         = connect();
    $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$name`");
    $cleanup[] = migrationFlagFile($pdo, 'uq_code');
    $cleanup[] = migrationFlagFile($pdo, 'uq_sheet_shelf');
    return $name;
}

function dbExists(string $name): bool
{
    $st = connect()->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $st->execute([$name]);
    return (int)$st->fetchColumn() > 0;
}

/** boards as created before zones + the system-wide Number Code (commit 8e35372), incl. its later columns */
function createLegacyBoards(PDO $pdo, bool $withSheetShelfKey = true): void
{
    $pdo->exec('CREATE TABLE boards (
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
        side       VARCHAR(10)  NOT NULL DEFAULT "Top",
        defects    TEXT         NULL,
        mag_slot   VARCHAR(60)  NULL,
        UNIQUE KEY uq_sheet_code (sheet, code)' . ($withSheetShelfKey ? ',
        UNIQUE KEY uq_sheet_shelf (sheet, shelf_no)' : '') . '
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function addRow(PDO $pdo, string $sheet, string $code, int $shelfNo, string $createdAt = '2026-10-01 08:00:00'): void
{
    $pdo->prepare('INSERT INTO boards (sheet, code, shelf, shelf_no, product, board_type, status, side, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$sheet, $code, shelfPrefix($sheet) . $shelfNo, $shelfNo, 'TEST-MODEL', 'ES3261A', 'G', 'F-Side', $createdAt]);
}

function indexNames(PDO $pdo): array
{
    return $pdo->query(
        "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards' ORDER BY INDEX_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
}

function rowCount(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM boards')->fetchColumn();
}

function codes(PDO $pdo): array
{
    return $pdo->query('SELECT id, code FROM boards ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function zoneOf(PDO $pdo, int $id): string
{
    $st = $pdo->prepare('SELECT zone FROM boards WHERE id = ?');
    $st->execute([$id]);
    return (string)$st->fetchColumn();
}

/** Lines of the captured error_log that contain $needle */
function logLines(string $needle): array
{
    global $logFile;
    return array_values(array_filter(file($logFile) ?: [], static fn(string $l): bool => str_contains($l, $needle)));
}

/** Data checksum, indexes, columns and flag mtime: anything a read-only tool must leave alone */
function snapshot(PDO $pdo): array
{
    clearstatcache();
    return [
        $pdo->query('CHECKSUM TABLE boards')->fetch()['Checksum'],
        indexNames($pdo),
        $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'boards'")->fetchColumn(),
        @filemtime(migrationFlagFile($pdo, 'uq_code')),
    ];
}

/** New-record input as the board modal sends it */
function boardInput(string $sheet, string $shelf): array
{
    return [
        'id' => 0, 'zone' => sheetZone($sheet), 'sheet' => $sheet, 'shelf' => $shelf, 'product' => 'TEST-MODEL',
        'side' => 'F-Side', 'board_type' => 'ES3261A', 'board_status' => 'G', 'pcs' => '', 'defects' => '',
    ];
}

/** Run scripts/check-duplicate-codes.php as a CLI process: [exit code, stdout + stderr] */
function runScript(array $env): array
{
    global $root, $sessDir;
    $proc = proc_open(
        [PHP_BINARY, '-d', "session.save_path=$sessDir", "$root/scripts/check-duplicate-codes.php"],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $root,
        array_merge(getenv(), $env)
    );
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($proc), $out];
}

/** HTTP request to the test server, keeping the session cookie; POST bodies are multipart like FormData */
function http(string $method, string $path, array $form = []): array
{
    global $base, $cookieJar, $bodyWarnings;
    $headers = $cookieJar !== '' ? ["Cookie: $cookieJar"] : [];
    $content = '';
    if ($method === 'POST') {
        $boundary = 'aoi' . bin2hex(random_bytes(8));
        foreach ($form as $k => $v) {
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        }
        $content  .= "--$boundary--\r\n";
        $headers[] = "Content-Type: multipart/form-data; boundary=$boundary";
    }
    $ctx  = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30,
    ]]);
    $body = (string)@file_get_contents($base . $path, false, $ctx);
    $head = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    foreach ($head as $line) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $line, $m)) {
            $cookieJar = $m[1];
        }
    }
    if (preg_match('/(<b>)?(Warning|Notice|Deprecated|Fatal error)(<\/b>)?:\s/', $body)) {
        $bodyWarnings[] = "$method $path";
    }
    $status = preg_match('#^HTTP/\S+ (\d{3})#', $head[0] ?? '', $m) ? (int)$m[1] : 0;
    return [$status, $body, $head];
}

// ──────────────────────────────────────────────
// Tests
// ──────────────────────────────────────────────

echo "Number Code tests · MySQL " . (getenv('DB_HOST') ?: 'db') . ' · run ' . $runId . ' · prefix ' . $prefix . PHP_EOL;

try {
    // ── (ก) ────────────────────────────────────
    section('(ก) runMigrations() รัน 2 รอบ: ไม่ error และไม่เขียน log ซ้ำในชั่วโมงเดียวกัน / two runs, log at most once per hour');

    $pdo = connect(newDb('fresh'));
    $err = '';
    try {
        runMigrations($pdo);
        runMigrations($pdo);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    check('ฐานข้อมูลใหม่: 2 รอบไม่ error และมี uq_code / new DB: no error, uq_code created',
        $err === '' && in_array('uq_code', indexNames($pdo), true), $err);

    $pdo = connect(newDb('dups'));
    createLegacyBoards($pdo, false);                // this DB never got uq_sheet_shelf
    addRow($pdo, 'CD300',    $prefix . '001', 1);
    addRow($pdo, 'B200_Gen', $prefix . '001', 1);   // same code in another sheet
    addRow($pdo, 'B200_Gen', $prefix . '002', 1);   // same sheet + shelf as the row above
    $codeMsg  = 'Number Code(s) are duplicated';
    $shelfMsg = 'shelf(s) hold more than one record';
    $runs     = [];
    $err      = '';
    try {
        for ($i = 0; $i < 2; $i++) {
            $pdo->queries = [];
            runMigrations($pdo);
            $runs[] = [$pdo->count('GROUP BY code HAVING'), $pdo->count('GROUP BY sheet, shelf_no HAVING')];
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    check('มีรหัสซ้ำ + shelf ซ้ำ: 2 รอบไม่ error / duplicates present: no error', $err === '', $err);
    check('log รหัสซ้ำ 1 ครั้งจาก 2 รอบ / duplicate-code log written once', count(logLines($codeMsg)) === 1, (string)count(logLines($codeMsg)));
    check('log shelf ซ้ำ 1 ครั้งจาก 2 รอบ / duplicate-shelf log written once', count(logLines($shelfMsg)) === 1, (string)count(logLines($shelfMsg)));
    check('รอบ 2 ข้าม query ตรวจซ้ำทั้งสองแบบ / 2nd run skips both GROUP BY queries', $runs === [[1, 1], [0, 0]], json_encode($runs));
    $lines = logLines($codeMsg);
    $line  = (string)end($lines);
    check('log บอกจำนวนรหัสซ้ำ และชี้ไปที่สคริปต์ / log has the count and the script',
        str_contains($line, 'AOI: 1 Number Code(s)') && str_contains($line, 'php scripts/check-duplicate-codes.php'), trim($line));

    $codeFlag = migrationFlagFile($pdo, 'uq_code');
    touch($codeFlag, time() - MIGRATION_RECHECK_SECONDS - 1);
    runMigrations($pdo);
    check('flag เกิน 1 ชั่วโมง: ตรวจใหม่และ log อีก 1 ครั้ง / expired flag: checks and logs again', count(logLines($codeMsg)) === 2);
    touch($codeFlag, time() + 7200);
    runMigrations($pdo);
    check('flag เวลาอนาคต (นาฬิกาถูกปรับ) ไม่บล็อกการตรวจ / future-dated flag does not block', count(logLines($codeMsg)) === 3);
    runMigrations($pdo);
    check('ตรวจแล้ว รอบถัดไปในชั่วโมงเดียวกันไม่ log / next run within the hour stays quiet', count(logLines($codeMsg)) === 3);

    // ── (ข) ────────────────────────────────────
    section('(ข) ฐานข้อมูลเก่ามีรหัสซ้ำข้ามชีต: ไม่เพิ่ม uq_code, บันทึกใหม่ได้ MAX+1 / legacy DB with cross-sheet duplicates');

    $legacyDb = newDb('legacy_dups');
    $pdo      = connect($legacyDb);
    createLegacyBoards($pdo);
    addRow($pdo, 'CD300',    $prefix . '001', 1);
    addRow($pdo, 'B200_Gen', $prefix . '001', 1);
    addRow($pdo, 'B200_Gen', $prefix . '002', 2);
    addRow($pdo, 'A150_Gen', $prefix . '005', 1);
    addRow($pdo, 'C250_Gen', $prefix . '005', 1);
    $codesBefore = codes($pdo);
    runMigrations($pdo);
    $idx = indexNames($pdo);
    check('ไม่เพิ่ม uq_code / uq_code not added', !in_array('uq_code', $idx, true), implode(',', $idx));
    check('uq_sheet_code เดิมยังอยู่ / legacy uq_sheet_code kept', in_array('uq_sheet_code', $idx, true));
    check('รหัสเดิมไม่ถูก renumber / existing codes unchanged', codes($pdo) === $codesBefore);
    $r1 = saveBoardRecord($pdo, boardInput('B200_Gen', 'B3'));
    check("บันทึกใหม่ได้ MAX+1 = {$prefix}006 / new save gets MAX+1",
        $r1['success'] && $r1['code'] === $prefix . '006', json_encode([$r1['code'], $r1['errors']], JSON_UNESCAPED_UNICODE));
    $r2 = saveBoardRecord($pdo, boardInput('CD300', 'CD2'));
    check("บันทึกถัดไปต่างชีตได้ {$prefix}007 / next save (other sheet) gets 007",
        $r2['success'] && $r2['code'] === $prefix . '007', json_encode([$r2['code'], $r2['errors']], JSON_UNESCAPED_UNICODE));
    $st = $pdo->prepare('SELECT COUNT(*) FROM boards WHERE code IN (?, ?)');
    $st->execute([$prefix . '006', $prefix . '007']);
    check('รหัสใหม่ไม่ชนของเดิม (รหัสละ 1 แถว) / new codes do not collide', (int)$st->fetchColumn() === 2);
    check('แถวใหม่เก็บ zone = sheetZone(sheet) / new rows store sheetZone()',
        zoneOf($pdo, $r1['id']) === 'General' && zoneOf($pdo, $r2['id']) === 'General');

    // ── (ค) ────────────────────────────────────
    section('(ค) ฐานข้อมูลเก่าไม่มีรหัสซ้ำ: เพิ่ม uq_code และลบ uq_sheet_code / legacy DB without duplicates');

    $pdo = connect(newDb('legacy_clean'));
    createLegacyBoards($pdo);
    addRow($pdo, 'CD300',    $prefix . '001', 1);
    addRow($pdo, 'B200_Gen', $prefix . '002', 1);
    addRow($pdo, 'A150_Gen', $prefix . '003', 1);
    $leftover = migrationFlagFile($pdo, 'uq_code');
    touch($leftover, time() - 2 * MIGRATION_RECHECK_SECONDS);   // flag left over from an earlier hour
    runMigrations($pdo);
    $idx = indexNames($pdo);
    check('เพิ่ม uq_code / uq_code added', in_array('uq_code', $idx, true), implode(',', $idx));
    check('ลบ uq_sheet_code / uq_sheet_code dropped', !in_array('uq_sheet_code', $idx, true), implode(',', $idx));
    clearstatcache();
    check('flag ที่ค้างไม่บล็อก และถูกลบหลังเพิ่ม index / leftover flag did not block, removed afterwards', !file_exists($leftover));
    $err = '';
    try {
        runMigrations($pdo);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    check('รันซ้ำหลังมี index แล้วไม่ error / rerun with the index in place', $err === '', $err);

    // The (ข) database after its duplicates are fixed, as an admin would do with the edit form
    $pdo = connect($legacyDb);
    $pdo->exec("UPDATE boards SET code = '{$prefix}101' WHERE sheet = 'B200_Gen' AND code = '{$prefix}001'");
    $pdo->exec("UPDATE boards SET code = '{$prefix}102' WHERE sheet = 'C250_Gen' AND code = '{$prefix}005'");
    runMigrations($pdo);
    check('แก้รหัสซ้ำแล้ว แต่เว็บเพิ่งตรวจภายในชั่วโมงนี้: รอรอบตรวจถัดไป / fixed within the hour: waits for the next check',
        !in_array('uq_code', indexNames($pdo), true));
    [$exit, $out] = runScript(['AOI_DB_NAME' => $legacyDb]);
    check('(จ) สคริปต์บอกเวลาที่เว็บจะตรวจใหม่ และไฟล์ flag ที่ลบได้ / script names the recheck time and the flag',
        $exit === 0 && str_contains($out, 'rm ' . migrationFlagFile($pdo, 'uq_code')), "exit=$exit");
    touch(migrationFlagFile($pdo, 'uq_code'), time() - MIGRATION_RECHECK_SECONDS - 1);
    runMigrations($pdo);
    $idx = indexNames($pdo);
    check('พ้นช่วงพัก: เพิ่ม uq_code และลบ uq_sheet_code / after the window: uq_code added, uq_sheet_code dropped',
        in_array('uq_code', $idx, true) && !in_array('uq_sheet_code', $idx, true), implode(',', $idx));

    // ── (ง) ────────────────────────────────────
    section('(ง) GET_LOCK ไม่สำเร็จ: ต้องไม่ INSERT และได้ข้อความตามข้อ 3 / lock not granted: no INSERT');

    $pdo = connect(newDb('lock'));
    runMigrations($pdo);
    $holder   = connect();
    $holderId = (int)$holder->query('SELECT CONNECTION_ID()')->fetchColumn();
    check('connection ทดสอบถือ lock ไว้ก่อน / a test connection holds the lock',
        (int)$holder->query("SELECT GET_LOCK('aoi_board_number_code', 10)")->fetchColumn() === 1);

    $pdo->queries = [];
    $t0     = microtime(true);
    $r      = saveBoardRecord($pdo, boardInput('B200_Gen', 'B1'));
    $waited = microtime(true) - $t0;
    check('lock timeout: success=false, status_code=503', $r['success'] === false && $r['status_code'] === 503,
        json_encode([$r['status_code'], $r['errors']], JSON_UNESCAPED_UNICODE));
    check('ได้ข้อความ CODE_LOCK_BUSY_ERROR / message is CODE_LOCK_BUSY_ERROR', $r['errors'] === [CODE_LOCK_BUSY_ERROR]);
    check('ไม่มีแถวใหม่ และไม่มีคำสั่ง INSERT / no row and no INSERT statement',
        rowCount($pdo) === 0 && $pdo->count('INSERT INTO boards') === 0);
    check('รอครบ timeout 5 วินาทีก่อนยอมแพ้ / waited for the 5 s timeout', $waited >= 4.5, sprintf('%.1f s', $waited));
    check('ไม่เรียก RELEASE_LOCK เมื่อไม่ได้ lock / no RELEASE_LOCK without the lock', $pdo->count('RELEASE_LOCK') === 0);
    check('lock ยังเป็นของ connection เดิม / lock still owned by the holder',
        (int)$pdo->query("SELECT IS_USED_LOCK('aoi_board_number_code')")->fetchColumn() === $holderId);

    $pdo->fakeLockSql = 'SELECT NULL';   // what GET_LOCK() answers on an error
    $r = saveBoardRecord($pdo, boardInput('B200_Gen', 'B1'));
    $pdo->fakeLockSql = null;
    check('GET_LOCK ตอบ NULL (error): 503 + ข้อความเดียวกัน และไม่มีแถวใหม่ / NULL answer handled the same',
        $r['status_code'] === 503 && $r['errors'] === [CODE_LOCK_BUSY_ERROR] && rowCount($pdo) === 0);

    $holder->query("SELECT RELEASE_LOCK('aoi_board_number_code')");
    $pdo->queries = [];
    $r = saveBoardRecord($pdo, boardInput('B200_Gen', 'B1'));
    check('lock ว่างแล้ว บันทึกได้ตามปกติ / saves once the lock is free', $r['success'] && rowCount($pdo) === 1,
        json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
    check('ได้ lock จริง: RELEASE_LOCK ใน finally 1 ครั้ง และ lock ว่าง / released exactly once',
        $pdo->count('RELEASE_LOCK') === 1 && (int)$pdo->query("SELECT IS_FREE_LOCK('aoi_board_number_code')")->fetchColumn() === 1);

    // ── SQLite branch ──────────────────────────
    section('SQLite branch ใน runMigrations() (สำหรับทดสอบเท่านั้น / tests only)');

    $sq  = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $err = '';
    try {
        runMigrations($sq);
        runMigrations($sq);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    check('runMigrations() 2 รอบไม่ error / two runs, no error', $err === '', $err);
    $r = saveBoardRecord($sq, boardInput('B200_Gen', 'B1'));
    check('saveBoardRecord() ข้าม GET_LOCK และบันทึกได้ / skips GET_LOCK and saves',
        $r['success'] && $r['code'] === $prefix . '001', json_encode([$r['code'], $r['errors']], JSON_UNESCAPED_UNICODE));

    // ── (จ) ────────────────────────────────────
    section('(จ) scripts/check-duplicate-codes.php รันจาก CLI และอ่านอย่างเดียว / CLI, read-only');

    $scriptDb = newDb('script');
    $pdo      = connect($scriptDb);
    createLegacyBoards($pdo);
    addRow($pdo, 'CD300',    $prefix . '001', 1, '2026-10-01 08:00:00');
    addRow($pdo, 'B200_Gen', $prefix . '001', 1, '2026-10-02 09:30:00');
    addRow($pdo, 'A150_Gen', $prefix . '002', 1, '2026-10-03 10:00:00');
    runMigrations($pdo);
    $pdo->exec("UPDATE boards SET zone = 'AIXZ' WHERE sheet = 'A150_Gen'");   // as if A150_Gen moved in ZONES without the UPDATE
    $before = snapshot($pdo);
    [$exit, $out] = runScript(['AOI_DB_NAME' => $scriptDb]);
    check('พบรหัสซ้ำ / zone ไม่ตรง: exit code 2', $exit === 2, "exit=$exit");
    check('[1] แสดงรหัสซ้ำ จำนวนแถว ชีต created_at แรก/ล่าสุด / lists duplicates',
        preg_match('/' . preg_quote($prefix . '001', '/') . '\s+2\s+B200_Gen, CD300\s+2026-10-01 08:00:00\s+2026-10-02 09:30:00/', $out) === 1, $out);
    check('[2] แสดงแถวที่ zone ไม่ตรง พร้อม SQL แก้ / lists zone mismatches with the fix',
        preg_match('/A150_Gen\s+AIXZ\s+General\s+1\s/', $out) === 1 && str_contains($out, "UPDATE boards SET zone = 'General' WHERE sheet = 'A150_Gen';"));
    check('[3] แสดงสถานะ uq_code / uq_sheet_code / shows index status',
        preg_match('/uq_code\s+\(code\)\s+ไม่มี/u', $out) === 1 && preg_match('/uq_sheet_code\s+sheet, code\s+มี/u', $out) === 1);
    check('สรุปบอกว่า uq_code จะเพิ่มอัตโนมัติเมื่อไม่มีรหัสซ้ำ / summary explains the automatic uq_code',
        str_contains($out, 'uq_code is added automatically on the next web page load'));
    check('ไม่แก้ข้อมูล index คอลัมน์ หรือ flag / changes no data, index, column or flag', snapshot($pdo) === $before);

    $pdo->exec("UPDATE boards SET code = '{$prefix}003' WHERE sheet = 'B200_Gen'");
    $pdo->exec("UPDATE boards SET zone = 'General' WHERE sheet = 'A150_Gen'");
    touch(migrationFlagFile($pdo, 'uq_code'), time() - MIGRATION_RECHECK_SECONDS - 1);
    runMigrations($pdo);   // first page load after the window
    [$exit, $out] = runScript(['AOI_DB_NAME' => $scriptDb]);
    check('ฐานข้อมูลสะอาด: exit 0 และ "Nothing to do" / clean DB', $exit === 0 && str_contains($out, 'Nothing to do'), "exit=$exit");

    $missing     = "aoi_test_{$runId}_missing";
    $databases[] = $missing;
    [$exit, $out] = runScript(['AOI_DB_NAME' => $missing]);
    check('ไม่มีฐานข้อมูล: exit 1 + ข้อความอธิบาย และไม่สร้างฐานข้อมูล / unknown DB: explained, not created',
        $exit === 1 && str_contains($out, 'Could not check') && str_contains($out, 'Unknown database') && !dbExists($missing), "exit=$exit");

    // ── HTTP: (ฉ), (ง), (จ) ────────────────────
    section('(ฉ) (ง) (จ) ผ่านเว็บ: php -S + ฐานข้อมูลทดสอบ / over HTTP against a test database');

    $webDb     = newDb('web');
    $port      = 18000 + random_int(0, 999);
    $serverLog = (string)tempnam(sys_get_temp_dir(), 'aoi-test-server-');
    $cleanup[] = $serverLog;
    $server    = proc_open(
        [PHP_BINARY, '-d', "session.save_path=$sessDir", '-S', "127.0.0.1:$port", '-t', $root, "$root/index.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
        $pipes,
        $root,
        array_merge(getenv(), ['AOI_DB_NAME' => $webDb])
    );
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($sock) {
            fclose($sock);
            $up = true;
        } else {
            usleep(100000);
        }
    }
    check("เปิด PHP built-in server 127.0.0.1:$port ได้ / test server started", $up);
    $base = "http://127.0.0.1:$port";

    if ($up) {
        [$status, $html] = http('GET', '/?zone=General&sheet=B200_Gen');
        $csrf = preg_match('/window\.csrfToken = "([0-9a-f]+)"/', $html, $m) ? $m[1] : '';
        check('GET index.php (General / B200_Gen): 200 + CSRF token', $status === 200 && $csrf !== '', "status=$status");

        $form = static fn(array $over = []): array => array_merge([
            'csrf' => $csrf, 'action' => 'save', 'id' => '0', 'code' => '', 'zone' => 'General', 'sheet' => 'B200_Gen',
            'shelf' => 'B1', 'product' => 'TEST-HTTP', 'side' => 'F-Side', 'board_type' => 'ES3261A',
            'board_status' => 'G', 'pcs' => '', 'defects' => '',
        ], $over);
        $web = connect($webDb);

        [$status, $body] = http('POST', '/api/save.php', $form());
        $json = json_decode($body, true) ?? [];
        check('(ฉ) api/save.php บันทึกโซน General ได้: 200 + success + Number Code',
            $status === 200 && ($json['success'] ?? null) === true && isValidNumberCode((string)($json['code'] ?? '')), "status=$status $body");
        check('(ฉ) แถวใหม่เก็บ zone = General / row stored with zone General',
            rowCount($web) === 1 && zoneOf($web, (int)($json['id'] ?? 0)) === 'General');

        foreach ([['AIXZ', ''], ['Automotive', ''], ['AIXZ', 'B200_Gen']] as [$z, $s]) {
            [$status, $body] = http('POST', '/api/save.php', $form(['zone' => $z, 'sheet' => $s, 'shelf' => 'B9']));
            $json = json_decode($body, true) ?? [];
            check("(ฉ) โซนว่าง $z (sheet='$s') ถูกปฏิเสธ: 422 + EMPTY_ZONE_INSERT_ERROR",
                $status === 422 && ($json['success'] ?? null) === false && ($json['errors'] ?? []) === [EMPTY_ZONE_INSERT_ERROR], "status=$status $body");
        }
        check('(ฉ) ไม่มีแถวใหม่จากโซนว่าง / nothing saved for empty zones', rowCount($web) === 1);

        preg_match("/const EMPTY_ZONE_INSERT_MSG = '([^']+)'/", (string)file_get_contents("$root/assets/js/core/messages.js"), $m);
        check('(ฉ) ข้อความฝั่ง JS (กดเพิ่มในโซนว่าง) ตรงกับ PHP / JS copy matches PHP', ($m[1] ?? '') === EMPTY_ZONE_INSERT_ERROR);

        [$status, $html] = http('GET', '/?zone=AIXZ');
        check('(ฉ) หน้าโซน AIXZ (ยังไม่มีชีต) เปิดได้ / empty-zone page renders',
            $status === 200 && str_contains($html, 'window.initialZone = "AIXZ"'), "status=$status");

        [$status] = http('POST', '/?zone=General&sheet=B200_Gen', $form(['shelf' => 'B2']));
        check('(ฉ) index.php (ไม่ใช้ AJAX) บันทึก General ได้: 302 / non-AJAX save redirects', $status === 302 && rowCount($web) === 2, "status=$status");
        [, $html] = http('GET', '/?zone=General&sheet=B200_Gen');
        check('(ฉ) หลัง redirect แสดงข้อความบันทึกสำเร็จ / flash shown after redirect',
            str_contains($html, 'บันทึกข้อมูลลงโซน General · ชีต B200_Gen เรียบร้อยแล้ว'));
        [$status, $html] = http('POST', '/?zone=AIXZ', $form(['zone' => 'AIXZ', 'sheet' => '']));
        check('(ฉ) index.php (ไม่ใช้ AJAX) โซนว่าง: แสดง EMPTY_ZONE_INSERT_ERROR / non-AJAX empty zone shows the error',
            $status === 200 && str_contains($html, h(EMPTY_ZONE_INSERT_ERROR)) && rowCount($web) === 2, "status=$status");

        $holder = connect();
        $locked = (int)$holder->query("SELECT GET_LOCK('aoi_board_number_code', 10)")->fetchColumn() === 1;
        [$status, $body] = http('POST', '/api/save.php', $form(['shelf' => 'B3']));
        $json = json_decode($body, true) ?? [];
        check('(ง) api/save.php ขณะ lock ไม่ว่าง: 503 + success=false + CODE_LOCK_BUSY_ERROR',
            $locked && $status === 503 && ($json['success'] ?? null) === false && ($json['errors'] ?? []) === [CODE_LOCK_BUSY_ERROR], "status=$status $body");
        [$status, $html] = http('POST', '/?zone=General&sheet=B200_Gen', $form(['shelf' => 'B3']));
        check('(ง) index.php (ไม่ใช้ AJAX) ขณะ lock ไม่ว่าง: แสดง CODE_LOCK_BUSY_ERROR',
            $status === 200 && str_contains($html, h(CODE_LOCK_BUSY_ERROR)), "status=$status");
        check('(ง) ไม่มีแถวใหม่ระหว่าง lock ไม่ว่าง / nothing saved while the lock was busy', rowCount($web) === 2);
        $holder->query("SELECT RELEASE_LOCK('aoi_board_number_code')");
        [$status, $body] = http('POST', '/api/save.php', $form(['shelf' => 'B3']));
        check('(ง) กดบันทึกอีกครั้งหลัง lock ว่าง สำเร็จ / retry succeeds', $status === 200 && (json_decode($body, true)['success'] ?? null) === true, "status=$status $body");

        [$status, $body] = http('GET', '/scripts/check-duplicate-codes.php');
        check('(จ) เรียกสคริปต์ผ่านเว็บ: 403 และไม่ทำงาน / web request refused',
            $status === 403 && str_contains($body, 'command line') && !str_contains($body, 'Duplicate Number Code check'), "status=$status");
        [$status] = http('GET', '/tests/number-code-test.php');
        check('tests/ เรียกผ่านเว็บไม่ได้ / tests refuse web requests', $status === 403, "status=$status");

        proc_terminate($server);
        proc_close($server);
        $server = null;
        $serverOut = (string)file_get_contents($serverLog);
        check('ไม่มี PHP warning/error ใน server log และหน้าเว็บ / no PHP warnings on the server',
            !preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)/', $serverOut) && !$bodyWarnings,
            implode(' | ', $bodyWarnings) . ' ' . substr($serverOut, 0, 500));
    }
} catch (Throwable $e) {
    check('ไม่มี exception ระหว่างทดสอบ / no unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

check('ไม่มี PHP warning ในโปรเซสทดสอบ / no PHP warnings in the test process', !$phpWarnings, implode(' | ', $phpWarnings));

echo PHP_EOL . "ผล / Result: $passed passed, $failed failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
