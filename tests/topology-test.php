<?php
/**
 * Tests for the Zone & Sheet tables, the manager (api/topology.php) and the administrator gate on MySQL:
 *   (ก) runMigrations() twice: no error, seeded once, administrator edits never overwritten (not even "all sheets deleted")
 *   (ข) after the seed everything matches the old constants: data intact, Number Code continues
 *   (ค) new zone + new sheet: a saved board continues the system-wide Number Code (no restart)
 *   (ง) duplicate / case-only duplicate / malformed keys and prefixes are refused, and refused writes change nothing
 *   (จ) a sheet with records cannot be deleted or moved; disabled: records stay, no tab, no new record / Magazine,
 *       direct link + search + export still work; deleted keys fall back like stale links
 *   (ฉ) the default zone (General) can neither be disabled nor deleted
 *   (ช) not signed in -> 401 and nothing changes; wrong CSRF -> 403; missing / invalid hash -> fail closed (503);
 *       sign-in regenerates the session id, wrong passwords lock, sessions expire
 *   (ซ) zone / sheet names such as <script> are escaped wherever the server outputs them
 *   (ญ) scripts/check-duplicate-codes.php and the CSV export work with the database topology
 *   (ฎ) web exposure: .git / config / includes / tests / scripts ... refused (PHP built-in server, and Apache when reachable)
 *
 * Throwaway databases aoi_test_<run>_* only (dropped at the end); Master_AoI is never touched: AOI_DB_NAME points to a
 * guard database name for the whole run and the last check proves that nothing connected through db() implicitly.
 * Needs DB_HOST / DB_PORT / DB_USER / DB_PASS with CREATE / DROP DATABASE (root in docker-compose). Takes 1-2 minutes.
 * Browser-side rendering (clicks, focus, the manager's JS) is NOT covered here.
 *
 *   docker exec php_app_web php /var/www/html/aoi-web-php/tests/topology-test.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root      = dirname(__DIR__);
$runId     = date('His') . bin2hex(random_bytes(2));
$guardDb   = "aoi_test_{$runId}_guard";
$sessDir   = sys_get_temp_dir() . "/aoi-test-sess-$runId";
mkdir($sessDir);
putenv("AOI_DB_NAME=$guardDb");   // an accidental db() call lands here (and is reported), never in Master_AoI
ini_set('session.save_path', $sessDir);

require_once $root . '/config/database.php';
require_once $root . '/includes/admin-auth.php';
require_once $root . '/includes/topology-manager.php';

error_reporting(E_ALL);
$phpWarnings = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$phpWarnings): bool {
    if (error_reporting() & $no) {
        $phpWarnings[] = "$msg ($file:$line)";
    }
    return true;
});

$databases    = [$guardDb];
$cleanup      = [];
$servers      = [];
$passed       = 0;
$failed       = 0;
$bodyWarnings = [];
$now          = new DateTimeImmutable('now', new DateTimeZone(CODE_TZ));
$prefix       = sprintf('%s-%s-%s-', CODE_PREFIX, $now->format('y'), $now->format('m'));
$password     = 'Test-' . bin2hex(random_bytes(6));
$hash         = password_hash($password, PASSWORD_DEFAULT);

register_shutdown_function(static function () use (&$databases, &$cleanup, &$servers, $sessDir): void {
    foreach ($servers as $s) {
        if (is_resource($s['proc'])) {
            proc_terminate($s['proc']);
            proc_close($s['proc']);
        }
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

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
    if (!$ok && $detail !== '') {
        echo '        -> ' . mb_substr($detail, 0, 600) . PHP_EOL;
    }
}

function section(string $title): void
{
    echo PHP_EOL . $title . PHP_EOL;
}

function connect(?string $dbName = null): PDO
{
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    if (!is_string($user) || $user === '' || !is_string($pass)) {
        throw new RuntimeException('DB_USER / DB_PASS are not set (no fallback credentials in code)');
    }
    $pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]);
    if ($dbName !== null) {
        $pdo->exec("USE `$dbName`");
    }
    return $pdo;
}

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

/** boards as before zones + topology (commit 8e35372 shape, incl. its later columns) */
function createLegacyBoards(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE boards (
        id INT AUTO_INCREMENT PRIMARY KEY, sheet VARCHAR(30) NOT NULL, code VARCHAR(40) NOT NULL, shelf VARCHAR(10) NOT NULL,
        shelf_no INT NOT NULL, product VARCHAR(40) NOT NULL, board_type VARCHAR(30) NOT NULL DEFAULT "Standard PCB",
        status VARCHAR(2) NOT NULL, pcs_status VARCHAR(100) NOT NULL DEFAULT "", created_at DATETIME NOT NULL,
        side VARCHAR(10) NOT NULL DEFAULT "Top", defects TEXT NULL, mag_slot VARCHAR(60) NULL,
        UNIQUE KEY uq_sheet_code (sheet, code), UNIQUE KEY uq_sheet_shelf (sheet, shelf_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE sheet_magazines (sheet VARCHAR(30) NOT NULL PRIMARY KEY, magazines INT NOT NULL DEFAULT 1) ENGINE=InnoDB');
}

/** Raw row (shelf letters given explicitly: legacy databases have no topology to ask) */
function addRow(PDO $pdo, string $sheet, string $shelf, int $shelfNo, string $code, string $zone = ''): void
{
    $cols = $zone !== '' ? 'zone, ' : '';
    $pdo->prepare("INSERT INTO boards ({$cols}sheet, code, shelf, shelf_no, product, board_type, status, side, created_at) VALUES (" . ($zone !== '' ? '?,' : '') . '?,?,?,?,?,?,?,?,?)')
        ->execute(array_merge($zone !== '' ? [$zone] : [], [$sheet, $code, $shelf, $shelfNo, 'TEST-MODEL', 'ES3261A', 'G', 'F-Side', '2026-10-01 08:00:00']));
}

function boardInput(string $zone, string $sheet, string $shelf): array
{
    return ['id' => 0, 'zone' => $zone, 'sheet' => $sheet, 'shelf' => $shelf, 'product' => 'TEST-MODEL', 'side' => 'F-Side',
            'board_type' => 'ES3261A', 'board_status' => 'G', 'pcs' => '', 'defects' => ''];
}

function rows(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll();
}

/** zones + sheets + audit count + boards + magazines: everything a refused write must leave alone */
function topoSnapshot(PDO $pdo): string
{
    return json_encode([
        rows($pdo, 'SELECT * FROM zones ORDER BY zone_key'),
        rows($pdo, 'SELECT * FROM sheets ORDER BY sheet_key'),
        rows($pdo, 'SELECT COUNT(*) c FROM topology_audit'),
        rows($pdo, 'SELECT id, zone, sheet, code, shelf, shelf_no FROM boards ORDER BY id'),
        rows($pdo, 'SELECT * FROM sheet_magazines ORDER BY sheet'),
    ], JSON_UNESCAPED_UNICODE);
}

function apply(PDO $pdo, string $action, array $in): array
{
    return topologyApply($pdo, $action, $in, '127.0.0.1');
}

function rejected(array $r, int $status, string $field = ''): bool
{
    return $r['success'] === false && $r['status_code'] === $status && ($field === '' || isset($r['field_errors'][$field]));
}

function runScript(array $env): array
{
    global $root, $sessDir;
    $proc = proc_open([PHP_BINARY, '-d', "session.save_path=$sessDir", "$root/scripts/check-duplicate-codes.php"],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root, array_merge(getenv(), $env));
    $out = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($proc), $out];
}

/** PHP built-in server on index.php with $env; returns its base URL ('' when it did not start) */
function startServer(string $name, array $env, array $unset = []): string
{
    global $root, $sessDir, $cleanup, $servers;
    $port = 18000 + random_int(0, 1999);
    $log  = (string)tempnam(sys_get_temp_dir(), "aoi-test-$name-");
    $cleanup[] = $log;
    $fullEnv = array_merge(getenv(), $env);
    foreach ($unset as $k) {
        unset($fullEnv[$k]);
    }
    $proc = proc_open([PHP_BINARY, '-d', "session.save_path=$sessDir", '-S', "127.0.0.1:$port", '-t', $root, "$root/index.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $fullEnv);
    $servers[$name] = ['proc' => $proc, 'log' => $log];
    for ($i = 0; $i < 50; $i++) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($sock) {
            fclose($sock);
            return "http://127.0.0.1:$port";
        }
        usleep(100000);
    }
    return '';
}

/** One browser: keeps its PHPSESSID cookie */
final class WebClient
{
    public string $cookie = '';

    public function __construct(public string $base) {}

    public function request(string $method, string $path, ?array $form = null, ?array $json = null): array
    {
        global $bodyWarnings;
        $headers = $this->cookie !== '' ? ["Cookie: {$this->cookie}"] : [];
        $content = '';
        if ($json !== null) {
            $content   = (string)json_encode($json);
            $headers[] = 'Content-Type: application/json';
        } elseif ($form !== null) {
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
        $body = (string)@file_get_contents($this->base . $path, false, $ctx);
        $head = $http_response_header ?? [];
        $location = '';
        foreach ($head as $line) {
            if (preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $line, $m)) {
                $this->cookie = $m[1];
            }
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
        }
        if (preg_match('/(<b>)?(Warning|Notice|Deprecated|Fatal error)(<\/b>)?:\s/', $body)) {
            $bodyWarnings[] = "$method $path";
        }
        $status = preg_match('#^HTTP/\S+ (\d{3})#', $head[0] ?? '', $m) ? (int)$m[1] : 0;
        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true), 'location' => $location];
    }

    public function get(string $path): array { return $this->request('GET', $path); }
    public function post(string $path, array $form): array { return $this->request('POST', $path, $form); }
    public function postJson(string $path, array $data): array { return $this->request('POST', $path, null, $data); }

    public function csrf(string $path = '/'): string
    {
        $html = $this->get($path)['body'];
        return preg_match('/window\.csrfToken = "([0-9a-f]+)"/', $html, $m) ? $m[1] : '';
    }

    public function signIn(string $password): array
    {
        $html  = $this->get('/admin.php')['body'];
        $token = preg_match('/name="csrf" value="([0-9a-f]+)"/', $html, $m) ? $m[1] : '';
        return $this->post('/admin.php', ['csrf' => $token, 'action' => 'login', 'password' => $password]);
    }
}

/** HTTP status of a plain GET (no cookies) */
function statusOf(string $url): int
{
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
    @file_get_contents($url, false, $ctx);
    $head = $http_response_header ?? [];
    return preg_match('#^HTTP/\S+ (\d{3})#', $head[0] ?? '', $m) ? (int)$m[1] : 0;
}

// ──────────────────────────────────────────────
// Tests
// ──────────────────────────────────────────────

echo 'Topology tests · MySQL ' . (getenv('DB_HOST') ?: 'db') . " · run $runId · prefix $prefix" . PHP_EOL;

try {
    // ── (ก) ────────────────────────────────────
    section('(ก) migration รัน 2 รอบ: ไม่ error, seed ครั้งเดียว, ไม่เขียนทับสิ่งที่ผู้ดูแลแก้ / migrate twice, seed once, keep edits');

    $pdo = connect(newDb('fresh'));
    $err = '';
    try {
        runMigrations($pdo);
        runMigrations($pdo);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    check('ฐานข้อมูลใหม่: 2 รอบไม่ error / new DB: two runs, no error', $err === '', $err);
    $zones  = rows($pdo, 'SELECT zone_key, label, short_label, sort_order, is_active FROM zones ORDER BY sort_order');
    $sheets = rows($pdo, 'SELECT sheet_key, zone_key, label, shelf_prefix, dot_color, sort_order, is_active FROM sheets ORDER BY sort_order');
    $wantZones = [];
    $wantSheets = [];
    $o = 0;
    foreach (ZONES as $z => $keys) {
        $o += 10;
        $wantZones[] = ['zone_key' => $z, 'label' => ZONE_META[$z]['label'], 'short_label' => ZONE_META[$z]['short'], 'sort_order' => (string)$o, 'is_active' => '1'];
        $so = 0;
        foreach ($keys as $k) {
            $so += 10;
            $wantSheets[] = ['sheet_key' => $k, 'zone_key' => $z, 'label' => SHEETS[$k][0], 'shelf_prefix' => SHELF_PREFIX[$k], 'dot_color' => SHEETS[$k][1], 'sort_order' => (string)$so, 'is_active' => '1'];
        }
    }
    check('seed 3 โซน ตรงกับค่าเดิม (ชื่อ ลำดับ) / 3 zones match the old constants', $zones == $wantZones, json_encode($zones, JSON_UNESCAPED_UNICODE));
    check('seed 4 ชีต ตรงกับค่าเดิม (prefix สี ลำดับ) / 4 sheets match the old constants', $sheets == $wantSheets, json_encode($sheets, JSON_UNESCAPED_UNICODE));
    check('ไม่มี audit จากการ seed / seeding writes no audit rows', (int)$pdo->query('SELECT COUNT(*) FROM topology_audit')->fetchColumn() === 0);

    apply($pdo, 'zone_update', ['zone_key' => 'General', 'label' => 'แก้แล้ว / Edited']);
    apply($pdo, 'zone_update', ['zone_key' => 'AIXZ', 'is_active' => '0']);
    apply($pdo, 'zone_delete', ['zone_key' => 'Automotive']);
    apply($pdo, 'sheet_update', ['sheet_key' => 'C250_Gen', 'is_active' => '0', 'dot_color' => 'bg-violet-600']);
    $before = topoSnapshot($pdo);
    runMigrations($pdo);
    runMigrations($pdo);
    check('แก้ชื่อ / ปิดโซน / ลบโซน / ปิดชีตแล้วรัน migration 2 รอบ: ไม่ถูกเขียนทับ / edits survive two more runs', topoSnapshot($pdo) === $before);
    foreach (array_keys(zoneSheets('General', true)) as $k) {
        apply($pdo, 'sheet_delete', ['sheet_key' => (string)$k]);
    }
    runMigrations($pdo);
    check('ลบชีตจนหมดแล้วรัน migration: ไม่ seed ชีตกลับมา / all sheets deleted: not re-seeded',
        (int)$pdo->query('SELECT COUNT(*) FROM sheets')->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM zones')->fetchColumn() === 2);

    $legacyDb = newDb('legacy');
    $pdo      = connect($legacyDb);
    createLegacyBoards($pdo);
    addRow($pdo, 'CD300', 'CD1', 1, $prefix . '001');
    addRow($pdo, 'B200_Gen', 'B60', 60, $prefix . '002');
    addRow($pdo, 'A150_Gen', 'A3', 3, $prefix . '003');
    $pdo->exec("INSERT INTO sheet_magazines (sheet, magazines) VALUES ('B200_Gen', 2)");
    $legacyRows = rows($pdo, 'SELECT id, sheet, code, shelf, shelf_no, product, status FROM boards ORDER BY id');
    runMigrations($pdo);
    runMigrations($pdo);
    check('ฐานข้อมูลเก่า (มีข้อมูล): seed 3 โซน 4 ชีต ครั้งเดียว / legacy DB seeded once',
        (int)$pdo->query('SELECT COUNT(*) FROM zones')->fetchColumn() === 3 && (int)$pdo->query('SELECT COUNT(*) FROM sheets')->fetchColumn() === 4);

    $sq = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $err = '';
    try {
        runMigrations($sq);
        runMigrations($sq);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    check('SQLite (ทดสอบเท่านั้น): 2 รอบไม่ error, seed ครั้งเดียว / SQLite branch seeds once',
        $err === '' && (int)$sq->query('SELECT COUNT(*) FROM zones')->fetchColumn() === 3 && (int)$sq->query('SELECT COUNT(*) FROM sheets')->fetchColumn() === 4, $err);
    $r = saveBoardRecord($sq, boardInput('General', 'B200_Gen', 'B1'));
    check('SQLite: saveBoardRecord() อ่านชีตจากตาราง / reads sheets from its tables', $r['success'] && $r['code'] === $prefix . '001', json_encode($r['errors'], JSON_UNESCAPED_UNICODE));

    // ── (ข) ────────────────────────────────────
    section('(ข) หลัง seed ระบบทำงานเหมือนเดิม: ข้อมูลครบ Number Code ต่อจากเดิม / behaves as before');

    $pdo = connect($legacyDb);
    topology($pdo);
    check('ข้อมูลบอร์ดเดิมครบและไม่ถูกแก้ / existing rows unchanged',
        rows($pdo, 'SELECT id, sheet, code, shelf, shelf_no, product, status FROM boards ORDER BY id') === $legacyRows);
    check('zoneSheets(General) === SHEETS เดิม (ลำดับ ชื่อ สี) / same as the old SHEETS', zoneSheets('General') === SHEETS, json_encode(zoneSheets('General')));
    $prefixOk = true;
    foreach (SHELF_PREFIX as $sh => $sp) {
        $prefixOk = $prefixOk && shelfPrefix($sh) === $sp && sheetZone($sh) === 'General' && parseShelf($sh, $sp . '7') === 7;
    }
    check('shelfPrefix / sheetZone / parseShelf ตรงกับค่าเดิมทุกชีต / prefixes, zones, shelf parsing as before', $prefixOk);
    check('normalizeZone: โซนเดิมผ่าน ค่าแปลก fallback General / normalizeZone as before',
        normalizeZone('AIXZ') === 'AIXZ' && normalizeZone('Automotive') === 'Automotive' && normalizeZone('bogus') === 'General' && normalizeZone('') === 'General' && normalizeZone('aixz') === 'General');
    check('resolveSheetInZone เหมือนเดิม / resolveSheetInZone as before',
        resolveSheetInZone('General', 'B200_Gen') === 'B200_Gen' && resolveSheetInZone('AIXZ', 'B200_Gen') === 'ALL'
        && resolveSheetInZone('General', 'nope') === 'ALL' && resolveSheetInZone('General', 'ALL') === 'ALL');
    check('allSheetsRangeLabel(General) = "All Sheets (CD, A, B, C)"', allSheetsRangeLabel('General') === 'All Sheets (CD, A, B, C)', allSheetsRangeLabel('General'));
    check('ข้อความกันเพิ่มข้อมูลเหมือนเดิม (ALL / โซนว่าง / ชีตปกติ) / insert rules as before',
        insertBlockedReason('General', 'ALL') === ALL_SIZE_INSERT_ERROR && insertBlockedReason('AIXZ', '') === EMPTY_ZONE_INSERT_ERROR
        && insertBlockedReason('AIXZ', 'B200_Gen') === EMPTY_ZONE_INSERT_ERROR && insertBlockedReason('General', 'B200_Gen') === '');
    check('magazineCount: แถว sheet_magazines เดิม + ชั้นวางที่มีอยู่ / Magazine counts as before',
        magazineCount($pdo, 'B200_Gen') === 2 && magazineCount($pdo, 'CD300') === 1 && magazineCount($pdo, 'X') === 0);
    $r = saveBoardRecord($pdo, boardInput('General', 'C250_Gen', 'C1'));
    check("Number Code ต่อจากเดิม: {$prefix}004 / continues after the existing codes", $r['success'] && $r['code'] === $prefix . '004',
        json_encode([$r['code'], $r['errors']], JSON_UNESCAPED_UNICODE));
    check('แถวใหม่เก็บ zone = General และ mag_slot ตามรูปแบบเดิม / zone + mag_slot as before',
        rows($pdo, "SELECT zone, mag_slot FROM boards WHERE code = '{$prefix}004'") === [['zone' => 'General', 'mag_slot' => 'C250_Gen Magazine 1st · Slot 1']]);

    // ── (ง) (ฉ) rules, in-process ─────────────
    section('(ง) (ฉ) กฎการแก้ไข (เรียกฟังก์ชันตรง) / manager rules');

    $rulesDb = newDb('rules');
    $pdo     = connect($rulesDb);
    runMigrations($pdo);
    $snap = topoSnapshot($pdo);
    $cases = [
        'zone key ซ้ำ (General)'                 => [apply($pdo, 'zone_create', ['zone_key' => 'General', 'label' => 'x']), 'zone_key'],
        'zone key ซ้ำต่างแค่ตัวพิมพ์ (gEnErAl)'    => [apply($pdo, 'zone_create', ['zone_key' => 'gEnErAl', 'label' => 'x']), 'zone_key'],
        'zone key ว่าง'                          => [apply($pdo, 'zone_create', ['zone_key' => '', 'label' => 'x']), 'zone_key'],
        'zone key มีขีด (bad-key)'               => [apply($pdo, 'zone_create', ['zone_key' => 'bad-key', 'label' => 'x']), 'zone_key'],
        'zone key มีช่องว่าง'                     => [apply($pdo, 'zone_create', ['zone_key' => 'a b', 'label' => 'x']), 'zone_key'],
        'zone key ภาษาไทย'                       => [apply($pdo, 'zone_create', ['zone_key' => 'โซน', 'label' => 'x']), 'zone_key'],
        'zone key ตัวเลขล้วน (123)'               => [apply($pdo, 'zone_create', ['zone_key' => '123', 'label' => 'x']), 'zone_key'],
        'zone key 31 ตัว'                        => [apply($pdo, 'zone_create', ['zone_key' => str_repeat('a', 31), 'label' => 'x']), 'zone_key'],
        'zone label ว่าง'                        => [apply($pdo, 'zone_create', ['zone_key' => 'NewZ', 'label' => '   ']), 'label'],
        'zone label 61 ตัว'                      => [apply($pdo, 'zone_create', ['zone_key' => 'NewZ', 'label' => str_repeat('ก', 61)]), 'label'],
        'zone sort_order -1'                     => [apply($pdo, 'zone_create', ['zone_key' => 'NewZ', 'label' => 'x', 'sort_order' => '-1']), 'sort_order'],
        'sheet key ซ้ำ (CD300)'                  => [apply($pdo, 'sheet_create', ['zone_key' => 'AIXZ', 'sheet_key' => 'CD300', 'label' => 'x', 'shelf_prefix' => 'QA', 'dot_color' => 'bg-sky-500']), 'sheet_key'],
        'sheet key ซ้ำต่างแค่ตัวพิมพ์ (cd300)'     => [apply($pdo, 'sheet_create', ['zone_key' => 'AIXZ', 'sheet_key' => 'cd300', 'label' => 'x', 'shelf_prefix' => 'QA', 'dot_color' => 'bg-sky-500']), 'sheet_key'],
        'sheet key สงวน (ALL / all)'             => [apply($pdo, 'sheet_create', ['zone_key' => 'AIXZ', 'sheet_key' => 'all', 'label' => 'x', 'shelf_prefix' => 'QA', 'dot_color' => 'bg-sky-500']), 'sheet_key'],
        'sheet key รูปแบบผิด (A.1)'              => [apply($pdo, 'sheet_create', ['zone_key' => 'AIXZ', 'sheet_key' => 'A.1', 'label' => 'x', 'shelf_prefix' => 'QA', 'dot_color' => 'bg-sky-500']), 'sheet_key'],
        'prefix ซ้ำ (CD)'                        => [apply($pdo, 'sheet_create', ['zone_key' => 'AIXZ', 'sheet_key' => 'Q1', 'label' => 'x', 'shelf_prefix' => 'CD', 'dot_color' => 'bg-sky-500']), 'shelf_prefix'],
        'prefix ซ้ำต่างแค่ตัวพิมพ์ (cd)'           => [apply($pdo, 'sheet_create', ['zone_key' => 'AIXZ', 'sheet_key' => 'Q1', 'label' => 'x', 'shelf_prefix' => 'cd', 'dot_color' => 'bg-sky-500']), 'shelf_prefix'],
        'prefix มีตัวเลข (A1)'                   => [apply($pdo, 'sheet_create', ['zone_key' => 'AIXZ', 'sheet_key' => 'Q1', 'label' => 'x', 'shelf_prefix' => 'A1', 'dot_color' => 'bg-sky-500']), 'shelf_prefix'],
        'prefix 4 ตัว (ABCD)'                    => [apply($pdo, 'sheet_create', ['zone_key' => 'AIXZ', 'sheet_key' => 'Q1', 'label' => 'x', 'shelf_prefix' => 'ABCD', 'dot_color' => 'bg-sky-500']), 'shelf_prefix'],
        'สีนอก palette (class อิสระ)'             => [apply($pdo, 'sheet_create', ['zone_key' => 'AIXZ', 'sheet_key' => 'Q1', 'label' => 'x', 'shelf_prefix' => 'QA', 'dot_color' => 'bg-red-500" onclick="x']), 'dot_color'],
        'โซนปลายทางไม่มีอยู่'                     => [apply($pdo, 'sheet_create', ['zone_key' => 'Nope', 'sheet_key' => 'Q1', 'label' => 'x', 'shelf_prefix' => 'QA', 'dot_color' => 'bg-sky-500']), 'zone_key'],
        'แก้ prefix ภายหลัง (CD300 → ZZ)'          => [apply($pdo, 'sheet_update', ['sheet_key' => 'CD300', 'shelf_prefix' => 'ZZ']), 'shelf_prefix'],
    ];
    foreach ($cases as $label => [$r, $field]) {
        check("(ง) ปฏิเสธ: $label → 422 + field $field", rejected($r, 422, $field), json_encode($r, JSON_UNESCAPED_UNICODE));
    }
    check('(ง) action ไม่รู้จัก → 400 / unknown action', rejected(apply($pdo, 'zone_rename', ['zone_key' => 'AIXZ']), 400));
    check('(ง) ไม่พบโซน / ชีต → 404 / missing zone or sheet',
        rejected(apply($pdo, 'zone_update', ['zone_key' => 'Nope', 'label' => 'x']), 404) && rejected(apply($pdo, 'sheet_delete', ['sheet_key' => 'Nope']), 404));
    check('(ฉ) ปิดใช้งาน General ไม่ได้ (รวมพิมพ์ general) → 409',
        rejected(apply($pdo, 'zone_update', ['zone_key' => 'General', 'is_active' => '0']), 409)
        && rejected(apply($pdo, 'zone_update', ['zone_key' => 'general', 'is_active' => '0']), 409));
    check('(ฉ) ลบ General ไม่ได้ (รวมพิมพ์ GENERAL) → 409',
        rejected(apply($pdo, 'zone_delete', ['zone_key' => 'General']), 409) && rejected(apply($pdo, 'zone_delete', ['zone_key' => 'GENERAL']), 409));
    check('(ง) (ฉ) ทุกคำขอที่ถูกปฏิเสธ ไม่เปลี่ยนข้อมูลและไม่เขียน audit / refused writes change nothing', topoSnapshot($pdo) === $snap);

    $r = apply($pdo, 'zone_update', ['zone_key' => 'AIXZ', 'label' => 'AIXZ ใหม่', 'zone_key_new' => 'XX', 'new_key' => 'XX']);
    check('(ง) แก้โซนได้เฉพาะช่องที่อนุญาต: key คงเดิม / only editable fields change, the key stays',
        $r['success'] && rows($pdo, "SELECT zone_key, label FROM zones WHERE label = 'AIXZ ใหม่'") === [['zone_key' => 'AIXZ', 'label' => 'AIXZ ใหม่']]);
    $r = apply($pdo, 'sheet_create', ['zone_key' => 'AIXZ', 'sheet_key' => 'Ax_1', 'label' => '  A   x  ', 'shelf_prefix' => 'ax', 'dot_color' => 'bg-sky-500']);
    check('(ง) prefix ตัวเล็กถูกแปลงเป็นพิมพ์ใหญ่ ชื่อถูกตัดช่องว่าง / lower-case prefix stored upper-case, label trimmed',
        $r['success'] && rows($pdo, "SELECT shelf_prefix, label, sort_order FROM sheets WHERE sheet_key = 'Ax_1'") == [['shelf_prefix' => 'AX', 'label' => 'A x', 'sort_order' => 10]],
        json_encode(rows($pdo, "SELECT shelf_prefix, label, sort_order FROM sheets WHERE sheet_key = 'Ax_1'"), JSON_UNESCAPED_UNICODE));
    $audit = rows($pdo, "SELECT action, entity_type, entity_key, before_json IS NULL nb, after_json IS NULL na, ip FROM topology_audit ORDER BY id");
    check('audit บันทึกทุกการแก้ไข (action / entity / before / after / ip) / every write audited',
        $audit == [
            ['action' => 'update', 'entity_type' => 'zone', 'entity_key' => 'AIXZ', 'nb' => 0, 'na' => 0, 'ip' => '127.0.0.1'],
            ['action' => 'create', 'entity_type' => 'sheet', 'entity_key' => 'Ax_1', 'nb' => 1, 'na' => 0, 'ip' => '127.0.0.1'],
        ], json_encode($audit));

    // ── HTTP ───────────────────────────────────
    section('(ข) (ค) (จ) (ช) (ซ) (ญ) ผ่านเว็บ: php -S + ฐานข้อมูลทดสอบ / over HTTP against a test database');

    $webDb   = newDb('web');
    $base    = startServer('admin', ['AOI_DB_NAME' => $webDb, 'AOI_ADMIN_PASSWORD_HASH' => $hash]);
    check('เปิด PHP built-in server (มีรหัสผู้ดูแล) / test server started', $base !== '');
    if ($base === '') {
        throw new RuntimeException('test server did not start');
    }
    $cleanup[] = sys_get_temp_dir() . "/aoi-$webDb-admin-" . sha1('127.0.0.1') . '.json';
    $web       = connect($webDb);

    $user = new WebClient($base);
    $page = $user->get('/?zone=General&sheet=B200_Gen');
    $csrf = preg_match('/window\.csrfToken = "([0-9a-f]+)"/', $page['body'], $m) ? $m[1] : '';
    check('GET index.php: 200 + CSRF token', $page['status'] === 200 && $csrf !== '', "status={$page['status']}");
    preg_match_all('/id="tab-([A-Za-z0-9_]+)"/', $page['body'], $tabs);
    check('(ข) แท็บชีตเดิมครบ เรียงเหมือนเดิม / tabs as before', $tabs[1] === ['ALL', 'CD300', 'A150_Gen', 'B200_Gen', 'C250_Gen'], implode(',', $tabs[1]));
    check('(ข) จุดสีแท็บเดิม / tab colours as before',
        str_contains($page['body'], 'inline-block bg-lime-500') && str_contains($page['body'], 'inline-block bg-emerald-500') && str_contains($page['body'], 'inline-block bg-amber-400') && str_contains($page['body'], 'inline-block bg-yellow-400'));
    check('(ข) ตัวเลือกโซนเดิม / zone selector as before',
        str_contains($page['body'], '>ทั่วไป / General (4 ชีต)</option>') && str_contains($page['body'], '>AIXZ / โซน AIXZ (ยังไม่มีชีต / No sheets)</option>') && str_contains($page['body'], '>Automotive (ยังไม่มีชีต / No sheets)</option>'));
    check('JS ได้ topology จากฐานข้อมูล (window.TOPOLOGY) และไม่มี window.ZONES / SHEETS เดิม',
        str_contains($page['body'], 'window.TOPOLOGY = {"defaultZone":"General"') && !str_contains($page['body'], 'window.ZONES') && !str_contains($page['body'], 'window.SHEETS'));
    check('(ช) ผู้ใช้ทั่วไป: ไม่มีปุ่ม/หน้าจัดการ แต่มีลิงก์เข้าสู่ระบบผู้ดูแล / regular user: no manager, only the sign-in link',
        !str_contains($page['body'], 'id="tm-open-btn"') && !str_contains($page['body'], 'id="topology-manager"') && !str_contains($page['body'], 'topology-manager.js')
        && str_contains($page['body'], 'href="admin.php?zone=General&amp;sheet=B200_Gen"'));

    $form = static fn(array $over = []): array => array_merge([
        'csrf' => $csrf, 'action' => 'save', 'id' => '0', 'code' => '', 'zone' => 'General', 'sheet' => 'B200_Gen', 'shelf' => 'B1',
        'product' => 'TEST-HTTP', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'board_status' => 'G', 'pcs' => '', 'defects' => '',
    ], $over);
    $r1 = $user->post('/api/save.php', $form());
    $r2 = $user->post('/api/save.php', $form(['sheet' => 'CD300', 'shelf' => 'CD1']));
    check('(ข) ผู้ใช้ทั่วไปบันทึกบอร์ดได้โดยไม่ต้องล็อกอิน: 001, 002 / regular users still save without signing in',
        ($r1['json']['code'] ?? '') === $prefix . '001' && ($r2['json']['code'] ?? '') === $prefix . '002', $r1['body'] . ' ' . $r2['body']);

    // (ช) anonymous requests to the manager
    $snap = topoSnapshot($web);
    $anonGet  = $user->get('/api/topology.php');
    $anonPost = $user->postJson('/api/topology.php', ['csrf' => $csrf, 'action' => 'zone_create', 'zone_key' => 'Hack', 'label' => 'x']);
    $anonForm = $user->post('/api/topology.php', ['csrf' => $csrf, 'action' => 'zone_delete', 'zone_key' => 'AIXZ']);
    check('(ช) ไม่ล็อกอิน GET api/topology.php → 401', $anonGet['status'] === 401 && ($anonGet['json']['success'] ?? null) === false, $anonGet['body']);
    check('(ช) ไม่ล็อกอิน POST (JSON และ form, CSRF ถูก) → 401', $anonPost['status'] === 401 && $anonForm['status'] === 401
        && ($anonPost['json']['errors'] ?? []) === [ADMIN_SIGN_IN_REQUIRED_ERROR], $anonPost['body']);
    check('(ช) ไม่ล็อกอิน: ไม่มีการเปลี่ยนแปลง / nothing changed', topoSnapshot($web) === $snap);
    check('(ช) เมธอดอื่น (PUT) → 405', $user->request('PUT', '/api/topology.php', null, [])['status'] === 405);

    // (ช) sign-in
    $admin  = new WebClient($base);
    $admin->get('/admin.php');
    $cookie0 = $admin->cookie;
    $wrong   = $admin->signIn('wrong-password');
    check('(ช) รหัสผ่านผิด → 401 + ข้อความบอกจำนวนครั้งที่เหลือ / wrong password', $wrong['status'] === 401 && str_contains($wrong['body'], 'ลองได้อีก 4 ครั้ง'), "status={$wrong['status']}");
    $ok = $admin->signIn($password);
    check('(ช) รหัสผ่านถูก → 303 ไปหน้าหลักพร้อม manage=1', $ok['status'] === 303 && $ok['location'] === 'index.php?manage=1', "status={$ok['status']} location={$ok['location']}");
    check('(ช) session_regenerate_id หลังล็อกอิน (cookie เปลี่ยน) / session id regenerated', $cookie0 !== '' && $admin->cookie !== '' && $admin->cookie !== $cookie0);
    $landing = $admin->get('/' . $ok['location']);   // php -S router used to answer /index.php with an empty page
    check('(ช) หน้าที่ redirect ไป (/index.php?manage=1) แสดงหน้าหลักพร้อมหน้าจัดการ', $landing['status'] === 200 && str_contains($landing['body'], 'id="topology-manager"'),
        "status={$landing['status']} bytes=" . strlen($landing['body']));
    $adminPage = $admin->get('/?zone=General&sheet=ALL');
    $adminCsrf = preg_match('/window\.csrfToken = "([0-9a-f]+)"/', $adminPage['body'], $m) ? $m[1] : '';
    check('(ช) ผู้ดูแลเห็นปุ่มจัดการ หน้าต่างจัดการ และสคริปต์ / administrator gets the manager',
        str_contains($adminPage['body'], 'id="tm-open-btn"') && str_contains($adminPage['body'], 'id="topology-manager"') && str_contains($adminPage['body'], 'components/topology-manager.js'));
    $badCsrf = $admin->postJson('/api/topology.php', ['csrf' => str_repeat('0', 32), 'action' => 'zone_create', 'zone_key' => 'Hack', 'label' => 'x']);
    $noCsrf  = $admin->postJson('/api/topology.php', ['action' => 'zone_create', 'zone_key' => 'Hack', 'label' => 'x']);
    check('(ช) ล็อกอินแล้วแต่ CSRF ผิด / ไม่มี → 403 และไม่มีการเปลี่ยนแปลง', $badCsrf['status'] === 403 && $noCsrf['status'] === 403 && topoSnapshot($web) === $snap, $badCsrf['body']);
    $list = $admin->get('/api/topology.php');
    check('(ช) ผู้ดูแล GET api/topology.php → 200 + โซน กฎ ข้อความ palette',
        $list['status'] === 200 && count($list['json']['data']['zones'] ?? []) === 3 && ($list['json']['data']['rules']['key_pattern'] ?? '') === TOPOLOGY_KEY_PATTERN
        && count($list['json']['data']['palette'] ?? []) === count(SHEET_DOT_PALETTE) && isset($list['json']['topology']['zones']['General']), substr($list['body'], 0, 300));
    $b200 = null;
    foreach ($list['json']['data']['zones'][0]['sheets'] ?? [] as $s) {
        if ($s['key'] === 'B200_Gen') $b200 = $s;
    }
    check('(จ) เหตุผลบนปุ่มที่ทำไม่ได้: "ลบไม่ได้ เพราะมี 1 รายการ ใช้ปิดใช้งานแทน" / disabled-button reason',
        is_array($b200) && str_starts_with((string)$b200['guards']['delete'], 'ลบไม่ได้ เพราะมี 1 รายการ ใช้ปิดใช้งานแทน') && $b200['guards']['deactivate'] === null, json_encode($b200, JSON_UNESCAPED_UNICODE));

    // (ค) new zone + sheet, then a board in it
    $api = static fn(array $data): array => $admin->postJson('/api/topology.php', ['csrf' => $adminCsrf] + $data);
    $z = $api(['action' => 'zone_create', 'zone_key' => 'Plant2', 'label' => 'โรงงาน 2 / Plant 2', 'short_label' => 'Plant 2']);
    check('(ค) เพิ่มโซน Plant2 → 201 + topology ใหม่ในคำตอบ', $z['status'] === 201 && isset($z['json']['topology']['zones']['Plant2']), $z['body']);
    $p2 = $user->get('/?zone=Plant2');
    check('(ค) โซนใหม่ยังไม่มีชีต: แสดง empty state เดิม / a new zone shows the existing empty state',
        str_contains($p2['body'], 'โซน Plant2 ยังไม่มีชีตในระบบ / No sheets configured in Plant2 zone') && str_contains($p2['body'], '>โรงงาน 2 / Plant 2 (ยังไม่มีชีต / No sheets)</option>'));
    $s = $api(['action' => 'sheet_create', 'zone_key' => 'Plant2', 'sheet_key' => 'P2_A', 'label' => 'P2 A', 'shelf_prefix' => 'PA', 'dot_color' => 'bg-sky-500']);
    check('(ค) เพิ่มชีต P2_A (prefix PA) → 201', $s['status'] === 201 && ($s['json']['topology']['sheets']['P2_A']['prefix'] ?? '') === 'PA', $s['body']);
    $r3 = $user->post('/api/save.php', $form(['zone' => 'Plant2', 'sheet' => 'P2_A', 'shelf' => 'PA1']));
    check("(ค) บันทึกบอร์ดในชีตใหม่ได้ Number Code ต่อจากเลขรวมทั้งระบบ: {$prefix}003 (ไม่เริ่ม 001)",
        $r3['status'] === 200 && ($r3['json']['code'] ?? '') === $prefix . '003', $r3['body']);
    check('(ค) แถวใหม่เก็บ zone = Plant2, shelf PA1, mag_slot ของชีตใหม่',
        rows($web, "SELECT zone, sheet, shelf, mag_slot FROM boards WHERE code = '{$prefix}003'") === [['zone' => 'Plant2', 'sheet' => 'P2_A', 'shelf' => 'PA1', 'mag_slot' => 'P2_A Magazine 1st · Slot 1']]);
    $cap = $user->post('/api/save.php', $form(['zone' => 'Plant2', 'sheet' => 'P2_A', 'shelf' => 'PA51']));
    check('(ค) ชีตใหม่เริ่มต้นมี 1 Magazine: PA51 เกินความจุ → 422', $cap['status'] === 422 && str_contains($cap['body'], 'PA50'), $cap['body']);
    $p2 = $user->get('/?zone=Plant2&sheet=P2_A');
    check('(ค) หน้าโซนใหม่: มีแท็บ P2_A, ตัวเลือกโซน (1 ชีต), ปุ่มเพิ่มบอร์ดใช้ได้',
        str_contains($p2['body'], 'id="tab-P2_A"') && str_contains($p2['body'], '>โรงงาน 2 / Plant 2 (1 ชีต)</option>') && str_contains($p2['body'], 'aria-disabled="false" title="เพิ่มข้อมูลบอร์ดลงในชีต P2_A'));

    // (จ) records protect a sheet; disabling keeps them reachable
    $snap = topoSnapshot($web);
    $del  = $api(['action' => 'sheet_delete', 'sheet_key' => 'B200_Gen']);
    $mv   = $api(['action' => 'sheet_update', 'sheet_key' => 'P2_A', 'zone_key' => 'General']);
    check('(จ) ลบชีตที่มีเรคอร์ด → 409 + เสนอปิดใช้งานแทน', $del['status'] === 409 && str_contains($del['body'], 'ใช้ปิดใช้งานแทน'), $del['body']);
    check('(จ) ย้ายชีตที่มีเรคอร์ดข้ามโซน → 409 + อธิบายเหตุผล', $mv['status'] === 409 && str_contains($mv['body'], 'บันทึกโซนไว้ด้วย'), $mv['body']);
    check('(จ) ถูกปฏิเสธแล้วไม่มีการเปลี่ยนแปลง', topoSnapshot($web) === $snap);

    $off = $api(['action' => 'sheet_update', 'sheet_key' => 'B200_Gen', 'is_active' => 0]);
    check('(จ) ปิดใช้งาน B200_Gen → 200', $off['status'] === 200 && ($off['json']['topology']['sheets']['B200_Gen']['active'] ?? null) === false, $off['body']);
    check('(จ) เรคอร์ดยังอยู่ครบ / records intact', (int)$web->query("SELECT COUNT(*) FROM boards WHERE sheet = 'B200_Gen'")->fetchColumn() === 1);
    $all = $user->get('/?zone=General&sheet=ALL');
    preg_match_all('/id="tab-([A-Za-z0-9_]+)"/', $all['body'], $tabs);
    check('(จ) ไม่โผล่ในแท็บ และไม่นับเป็นตัวเลือก (3 ชีต) / no tab, not counted',
        $tabs[1] === ['ALL', 'CD300', 'A150_Gen', 'C250_Gen'] && str_contains($all['body'], '>ทั่วไป / General (3 ชีต)</option>'), implode(',', $tabs[1]));
    check('(จ) แต่ยังนับในทุกขนาด (ALL = 2 รายการ) / still counted in All Size', (bool)preg_match('/id="tab-ALL".*?tab-count[^>]*>2</s', $all['body']));
    $add = $user->post('/api/save.php', $form(['shelf' => 'B2']));
    check('(จ) เพิ่มเรคอร์ดใหม่ในชีตที่ปิด → 422 + INACTIVE_SHEET_INSERT_ERROR', $add['status'] === 422 && ($add['json']['errors'] ?? []) === [INACTIVE_SHEET_INSERT_ERROR], $add['body']);
    $mag = $user->post('/api/magazine.php', ['csrf' => $csrf, 'zone' => 'General', 'sheet' => 'B200_Gen', 'magazines' => '1']);
    check('(จ) เพิ่ม Magazine ในชีตที่ปิด → 400 + INACTIVE_SHEET_MAGAZINE_ERROR', $mag['status'] === 400 && ($mag['json']['message'] ?? '') === INACTIVE_SHEET_MAGAZINE_ERROR, $mag['body']);
    $direct = $user->get('/?zone=General&sheet=B200_Gen');
    check('(จ) ลิงก์ตรงยังเปิดได้: ดูได้อย่างเดียว + ปุ่มเพิ่มบอร์ดปิดพร้อมเหตุผล',
        $direct['status'] === 200 && str_contains($direct['body'], 'window.initialSheet = "B200_Gen"') && str_contains($direct['body'], 'ชีต B200_Gen ถูกปิดใช้งาน · ดูได้อย่างเดียว')
        && str_contains($direct['body'], 'disabled aria-disabled="true" title="' . h(INACTIVE_SHEET_INSERT_ERROR) . '"'), "status={$direct['status']}");
    $search = $user->get('/api/boards.php?zone=General&sheet=B200_Gen&search=TEST-HTTP');
    check('(จ) ค้นหาตาม URL ตรงยังได้เรคอร์ด / search by URL still returns it', ($search['json']['total'] ?? 0) === 1 && ($search['json']['sheet'] ?? '') === 'B200_Gen', $search['body']);
    $csv = $user->get('/?zone=General&sheet=B200_Gen&export=1');
    check('(จ) (ญ) export CSV ของชีตที่ปิดยังได้เรคอร์ด', str_contains($csv['body'], $prefix . '001') && str_contains($csv['body'], 'Number Code'), substr($csv['body'], 0, 200));
    $on = $api(['action' => 'sheet_update', 'sheet_key' => 'B200_Gen', 'is_active' => 1]);
    check('(จ) เปิดใช้งานกลับ → กลับมาในแท็บ', $on['status'] === 200 && str_contains($user->get('/?zone=General')['body'], 'id="tab-B200_Gen"'));

    $e1 = $api(['action' => 'sheet_create', 'zone_key' => 'Plant2', 'sheet_key' => 'P2_Empty', 'label' => 'Empty', 'shelf_prefix' => 'PE', 'dot_color' => 'bg-lime-500']);
    $web->exec("INSERT INTO sheet_magazines (sheet, magazines) VALUES ('P2_Empty', 3)");
    $mvOk = $api(['action' => 'sheet_update', 'sheet_key' => 'P2_Empty', 'zone_key' => 'AIXZ']);
    check('(จ) ย้ายชีตที่ไม่มีเรคอร์ดได้ / an empty sheet moves', $e1['status'] === 201 && $mvOk['status'] === 200
        && rows($web, "SELECT zone_key FROM sheets WHERE sheet_key = 'P2_Empty'") === [['zone_key' => 'AIXZ']], $mvOk['body']);
    $delOk = $api(['action' => 'sheet_delete', 'sheet_key' => 'P2_Empty']);
    check('(จ) ลบชีตที่ไม่มีเรคอร์ดได้ และล้างแถว sheet_magazines', $delOk['status'] === 200
        && (int)$web->query("SELECT COUNT(*) FROM sheets WHERE sheet_key = 'P2_Empty'")->fetchColumn() === 0
        && (int)$web->query("SELECT COUNT(*) FROM sheet_magazines WHERE sheet = 'P2_Empty'")->fetchColumn() === 0, $delOk['body']);
    $gone = $user->get('/?zone=AIXZ&sheet=P2_Empty');
    $goneApi = $user->get('/api/sheet_data.php?zone=AIXZ&sheet=P2_Empty');
    check('(จ) URL ไปชีตที่ถูกลบ fallback เป็น ALL ไม่พัง / link to a deleted sheet falls back',
        $gone['status'] === 200 && str_contains($gone['body'], 'window.initialSheet = "ALL"') && ($goneApi['json']['data']['sheet'] ?? '') === 'ALL', "status={$gone['status']}");

    $zOff = $api(['action' => 'zone_update', 'zone_key' => 'Plant2', 'is_active' => 0]);
    check('(จ) ปิดโซนที่ยังมีชีต active → 409', $zOff['status'] === 409 && str_contains($zOff['body'], 'P2_A'), $zOff['body']);
    $api(['action' => 'sheet_update', 'sheet_key' => 'P2_A', 'is_active' => 0]);
    $zOff = $api(['action' => 'zone_update', 'zone_key' => 'Plant2', 'is_active' => 0]);
    $inOff = $api(['action' => 'sheet_create', 'zone_key' => 'Plant2', 'sheet_key' => 'P2_B', 'label' => 'B', 'shelf_prefix' => 'PB', 'dot_color' => 'bg-lime-500']);
    $enOff = $api(['action' => 'sheet_update', 'sheet_key' => 'P2_A', 'is_active' => 1]);
    check('(จ) ปิดชีตหมดแล้วปิดโซนได้; เพิ่มชีตในโซนที่ปิด → 422; เปิดชีตในโซนที่ปิด → 409',
        $zOff['status'] === 200 && $inOff['status'] === 422 && isset($inOff['json']['field_errors']['zone_key']) && $enOff['status'] === 409, $zOff['body'] . $inOff['body'] . $enOff['body']);
    $zLink = $user->get('/?zone=Plant2&sheet=P2_A');
    check('(จ) โซน + ชีตที่ปิด: ลิงก์ตรงยังเปิดดูได้ แต่ไม่อยู่ในตัวเลือกโซนของผู้อื่น',
        $zLink['status'] === 200 && str_contains($zLink['body'], 'window.initialZone = "Plant2"') && str_contains($zLink['body'], 'โซนนี้ถูกปิดใช้งาน · ดูได้อย่างเดียว')
        && !str_contains($user->get('/?zone=General')['body'], 'value="Plant2"'));
    $csv = $user->get('/?zone=Plant2&sheet=P2_A&export=1');
    check('(ญ) export CSV ของโซน/ชีตที่ปิดยังได้เรคอร์ด', str_contains($csv['body'], $prefix . '003'));

    $zDel = $api(['action' => 'zone_delete', 'zone_key' => 'Plant2']);
    check('(จ) ลบโซนที่ยังมีชีต → 409', $zDel['status'] === 409, $zDel['body']);
    $api(['action' => 'zone_create', 'zone_key' => 'Ghost', 'label' => 'Ghost']);
    addRow($web, 'CD300', 'CD9', 9, $prefix . '900', 'Ghost');   // a record stored with zone Ghost (e.g. edited by SQL)
    $zDel = $api(['action' => 'zone_delete', 'zone_key' => 'Ghost']);
    check('(จ) ลบโซนที่ไม่มีชีตแต่มีเรคอร์ดที่บันทึกด้วยโซนนี้ → 409', $zDel['status'] === 409 && str_contains($zDel['body'], 'ใช้ปิดใช้งานแทน'), $zDel['body']);
    $web->exec("DELETE FROM boards WHERE code = '{$prefix}900'");
    $zDel = $api(['action' => 'zone_delete', 'zone_key' => 'Ghost']);
    $ghost = $user->get('/?zone=Ghost');
    check('(จ) ลบโซนว่างได้ และ URL ไปโซนที่ถูกลบ fallback เป็น General', $zDel['status'] === 200 && $ghost['status'] === 200 && str_contains($ghost['body'], 'window.initialZone = "General"'), $zDel['body']);
    $gen = $api(['action' => 'zone_update', 'zone_key' => 'General', 'is_active' => 0]);
    $genDel = $api(['action' => 'zone_delete', 'zone_key' => 'General']);
    check('(ฉ) ผ่านเว็บ: ปิด / ลบ General → 409', $gen['status'] === 409 && $genDel['status'] === 409);
    $audit = (int)$web->query("SELECT COUNT(*) FROM topology_audit WHERE ip = '127.0.0.1'")->fetchColumn();
    // Plant2, P2_A, B200 off + on, P2_Empty create + move + delete, P2_A off, Plant2 off, Ghost create + delete
    check('audit มีครบทุกการเขียนที่สำเร็จ (IP ของผู้ดูแล) / audit rows for every successful write', $audit === 11, "rows=$audit");

    // (ซ) escaping
    $xz = $api(['action' => 'zone_create', 'zone_key' => 'XssZone', 'label' => '<script>alert("z")</script>&\'', 'short_label' => '<b>s</b>']);
    $xs = $api(['action' => 'sheet_create', 'zone_key' => 'XssZone', 'sheet_key' => 'XssSheet', 'label' => '<img src=x onerror=alert(1)>', 'shelf_prefix' => 'XS', 'dot_color' => 'bg-sky-500']);
    $xPage = $user->get('/?zone=XssZone&sheet=XssSheet');
    // the string as it must appear inside window.TOPOLOGY: JSON with JSON_HEX_TAG / JSON_HEX_AMP, never a raw tag
    $tagSafe = static fn(string $s): string => substr((string)json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP), 1, -1);
    check('(ซ) สร้างโซน/ชีตชื่อมี <script> ได้ (เก็บตามที่พิมพ์)', $xz['status'] === 201 && $xs['status'] === 201, $xz['body'] . $xs['body']);
    check('(ซ) HTML ไม่มี payload ดิบ / no raw payload in the page',
        !str_contains($xPage['body'], '<script>alert("z")') && !str_contains($xPage['body'], '<img src=x onerror') && !str_contains($xPage['body'], '<b>s</b>'));
    check('(ซ) ชื่อโซนถูก escape ในตัวเลือกโซนและป้ายหัวหน้า / escaped in the selector and header badge',
        str_contains($xPage['body'], '&lt;script&gt;alert(&quot;z&quot;)&lt;/script&gt;&amp;&#039; (1 ชีต)</option>')
        && str_contains($xPage['body'], 'โซน: &lt;script&gt;alert(&quot;z&quot;)&lt;/script&gt;&amp;&#039;</span>'));
    check('(ซ) ชื่อชีตถูก escape ในแท็บ / escaped in the sheet tab', str_contains($xPage['body'], '<span class="tab-label">&lt;img src=x onerror=alert(1)&gt;</span>'));
    check('(ซ) window.TOPOLOGY ใช้ \\u003C แทน < (ปิด <script> ไม่ได้) / JSON in <script> is tag-safe',
        str_contains($xPage['body'], $tagSafe('<script>alert("z")</script>&')) && str_contains($xPage['body'], $tagSafe('<img src=x onerror=alert(1)>')),
        preg_match('/(.{0,120}XssZone.{0,200})/', explode('window.TOPOLOGY = ', $xPage['body'])[1] ?? '', $m) ? $m[1] : 'no TOPOLOGY line');
    $xData = $user->get('/api/sheet_data.php?zone=XssZone&sheet=XssSheet');
    check('(ซ) API ส่งชื่อเป็น JSON string (หน้าเว็บแสดงผ่าน h() / textContent)', ($xData['json']['data']['zone_label'] ?? '') === '<script>alert("z")</script>&\'');

    // (ช) sign-out, lockout, fail closed
    $out = $admin->post('/admin.php', ['csrf' => $adminCsrf, 'action' => 'logout', 'zone' => 'General', 'sheet' => 'ALL']);
    check('(ช) ออกจากระบบ → 303 กลับหน้าหลัก', $out['status'] === 303 && $out['location'] === 'index.php?zone=General&sheet=ALL', "status={$out['status']} {$out['location']}");
    $after = $admin->postJson('/api/topology.php', ['csrf' => $adminCsrf, 'action' => 'zone_create', 'zone_key' => 'After', 'label' => 'x']);
    check('(ช) หลังออกจากระบบ POST → 401', $after['status'] === 401, $after['body']);

    $attacker = new WebClient($base);
    $codes = [];
    for ($i = 0; $i < 5; $i++) {
        $codes[] = $attacker->signIn('guess-' . $i)['status'];
    }
    check('(ช) ผิดครบ 5 ครั้ง: ครั้งที่ 5 ถูกล็อก (401 ×4 แล้ว 429)', $codes === [401, 401, 401, 401, 429], implode(',', $codes));
    $lockedRight = $attacker->signIn($password);
    $fresh = new WebClient($base);
    $freshTry = $fresh->signIn($password);
    check('(ช) ระหว่างล็อก: รหัสถูกก็เข้าไม่ได้ ทั้ง session เดิมและ session ใหม่จาก IP เดียวกัน', $lockedRight['status'] === 429 && $freshTry['status'] === 429 && !str_contains($freshTry['location'], 'manage'),
        "{$lockedRight['status']} {$freshTry['status']}");
    $throttle = sys_get_temp_dir() . "/aoi-$webDb-admin-" . sha1('127.0.0.1') . '.json';
    file_put_contents($throttle, json_encode(['fails' => [], 'until' => time() - 1]));   // as if the 5 minutes passed
    $later = $fresh->signIn($password);
    check('(ช) พ้นเวลาล็อก: เข้าสู่ระบบได้ / after the lock expires sign-in works', $later['status'] === 303, "status={$later['status']}");

    $noHash = startServer('nohash', ['AOI_DB_NAME' => $webDb], ['AOI_ADMIN_PASSWORD_HASH']);
    $badHash = startServer('badhash', ['AOI_DB_NAME' => $webDb, 'AOI_ADMIN_PASSWORD_HASH' => 'not-a-password-hash']);
    $snap = topoSnapshot($web);
    foreach (['ไม่ได้ตั้ง AOI_ADMIN_PASSWORD_HASH' => $noHash, 'ค่าไม่ใช่ password_hash()' => $badHash] as $label => $b) {
        $c = new WebClient($b);
        $tok   = $c->csrf('/');
        $login = $c->signIn($password);
        $get   = $c->get('/api/topology.php');
        $post  = $c->postJson('/api/topology.php', ['csrf' => $tok, 'action' => 'zone_create', 'zone_key' => 'Hack', 'label' => 'x']);
        $setup = $c->get('/admin.php');
        $home  = $c->get('/');
        check("(ช) fail closed ($label): หน้า admin 503 + วิธีตั้งค่า, login ไม่ได้, API 503, ไม่มีลิงก์ผู้ดูแล",
            $b !== '' && $setup['status'] === 503 && str_contains($setup['body'], 'admin-password-hash.php') && !str_contains($setup['body'], 'name="password"')
            && $login['status'] === 503 && $get['status'] === 503 && $post['status'] === 503 && ($post['json']['errors'] ?? []) === [ADMIN_NOT_CONFIGURED_ERROR]
            && !str_contains($home['body'], 'admin.php?') && !str_contains($home['body'], 'tm-open-btn'), "login={$login['status']} get={$get['status']} post={$post['status']} setup={$setup['status']}");
    }
    check('(ช) fail closed: ไม่มีการเปลี่ยนแปลง', topoSnapshot($web) === $snap);

    // (ช) session expiry rules, in-process
    putenv("AOI_ADMIN_PASSWORD_HASH=$hash");
    $t   = time();
    $fp  = adminHashFingerprint();
    $set = static function (array $s): string { $_SESSION['aoi_admin'] = $s; return adminStatus(); };
    check('(ช) session หมดอายุ: idle > 30 นาที / 8 ชั่วโมงหลังล็อกอิน / แฮชเปลี่ยน → expired; ปกติ → ok',
        $set(['since' => $t - 60, 'seen' => $t - 60, 'fp' => $fp]) === 'ok'
        && $set(['since' => $t - 60, 'seen' => $t - ADMIN_IDLE_SECONDS - 1, 'fp' => $fp]) === 'expired'
        && $set(['since' => $t - ADMIN_MAX_SECONDS - 1, 'seen' => $t, 'fp' => $fp]) === 'expired'
        && $set(['since' => $t, 'seen' => $t, 'fp' => 'old-hash']) === 'expired'
        && !isset($_SESSION['aoi_admin']));
    putenv('AOI_ADMIN_PASSWORD_HASH');
    check('(ช) ไม่มีแฮชใน env → unconfigured แม้ session เคยเป็นผู้ดูแล', $set(['since' => $t, 'seen' => $t, 'fp' => $fp]) === 'unconfigured');
    unset($_SESSION['aoi_admin']);

    // (ญ) duplicate-code script with the database topology
    section('(ญ) scripts/check-duplicate-codes.php ใช้ topology จากฐานข้อมูล / duplicate-code script');

    $scriptDb = newDb('script');
    $pdo      = connect($scriptDb);
    runMigrations($pdo);
    apply($pdo, 'zone_create', ['zone_key' => 'Plant2', 'label' => 'Plant 2']);
    apply($pdo, 'sheet_create', ['zone_key' => 'Plant2', 'sheet_key' => 'P2_A', 'label' => 'P2 A', 'shelf_prefix' => 'PA', 'dot_color' => 'bg-sky-500']);
    addRow($pdo, 'P2_A', 'PA1', 1, $prefix . '001', 'Plant2');
    addRow($pdo, 'P2_A', 'PA2', 2, $prefix . '002', 'General');   // zone mismatch for a sheet that only exists in the table
    addRow($pdo, 'X9', 'X1', 1, $prefix . '003', 'Plant2');       // sheet not in the table at all
    $before = topoSnapshot($pdo);
    [$exit, $out] = runScript(['AOI_DB_NAME' => $scriptDb]);
    check('(ญ) พบแถวที่ zone ไม่ตรงกับชีตจากตาราง sheets: exit 2 + SQL แก้', $exit === 2
        && preg_match('/P2_A\s+General\s+Plant2\s+1\s/', $out) === 1 && str_contains($out, "UPDATE boards SET zone = 'Plant2' WHERE sheet = 'P2_A';"), $out);
    check('(ญ) ชีตที่ไม่มีในตาราง: "(not in sheets)" + วิธีแก้', preg_match('/X9\s+Plant2\s+\(not in sheets\)/', $out) === 1 && str_contains($out, 'Sheet X9 is not in the sheets table'), $out);
    check('(ญ) สคริปต์อ่านอย่างเดียว / read-only', topoSnapshot($pdo) === $before);
    $pdo->exec("UPDATE boards SET zone = 'Plant2' WHERE sheet = 'P2_A'");
    $pdo->exec("DELETE FROM boards WHERE sheet = 'X9'");
    [$exit, $out] = runScript(['AOI_DB_NAME' => $scriptDb]);
    check('(ญ) แก้แล้ว: exit 0', $exit === 0 && str_contains($out, 'ไม่พบ / None found.'), $out);

    $oldDb = newDb('script_old');
    $pdo   = connect($oldDb);
    createLegacyBoards($pdo);
    $pdo->exec("ALTER TABLE boards ADD COLUMN zone VARCHAR(30) NOT NULL DEFAULT 'General'");
    addRow($pdo, 'CD300', 'CD1', 1, $prefix . '001');
    [$exit, $out] = runScript(['AOI_DB_NAME' => $oldDb]);
    $tables = rows($pdo, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME");
    check('(ญ) ฐานข้อมูลที่ยังไม่ migrate: ข้ามหัวข้อ [2] พร้อมคำอธิบาย ไม่ error ไม่สร้างตาราง',
        $exit === 0 && str_contains($out, 'there are no zones / sheets tables yet') && array_column($tables, 'TABLE_NAME') === ['boards', 'sheet_magazines'], $out);

    // (ฎ) web exposure
    section('(ฎ) ไฟล์ที่ห้ามเข้าถึงผ่านเว็บ / web exposure');

    $blocked = ['/.git/config', '/.git/HEAD', '/%2egit/config', '/.htaccess', '/config/database.php', '/includes/admin-auth.php',
                '/components/header.php', '/README.md', '/docs/zone-sheet-manager.md', '/tests/.htaccess'];
    $res = [];
    foreach ($blocked as $p) {
        $res[$p] = statusOf($base . $p);
    }
    check('(ฎ) PHP built-in server: .git / config / includes / components / docs / .md → 404',
        !array_diff($res, [404]), json_encode($res));
    check('(ฎ) PHP built-in server: tests/ และ scripts/ (CLI only) → 403',
        statusOf("$base/tests/topology-test.php") === 403 && statusOf("$base/scripts/admin-password-hash.php") === 403 && statusOf("$base/scripts/check-duplicate-codes.php") === 403);
    check('(ฎ) PHP built-in server: ไฟล์ปกติยังเปิดได้ (assets, admin.php)', statusOf("$base/assets/css/base/base.css") === 200 && statusOf("$base/admin.php") === 200);

    $apache = '';
    if ($sock = @fsockopen('127.0.0.1', 80, $errno, $errstr, 0.5)) {
        fclose($sock);
        $apache = 'http://127.0.0.1/' . basename($root);
    }
    if ($apache === '') {
        echo '  SKIP  (ฎ) Apache ไม่ได้ฟังพอร์ต 80 ในเครื่องนี้ จึงไม่ได้ทดสอบ .htaccess / Apache not reachable, .htaccess not tested' . PHP_EOL;
    } else {
        $res = [];
        foreach (['/.git/config', '/.git/HEAD', '/%2egit/config', '/.htaccess', '/tests/topology-test.php', '/tests/number-code-test.php',
                  '/scripts/check-duplicate-codes.php', '/scripts/admin-password-hash.php', '/config/database.php', '/includes/admin-auth.php',
                  '/components/header.php', '/docs/zone-sheet-manager.md', '/README.md', '/OFFLINE.md', '/start-offline.sh'] as $p) {
            $res[$p] = statusOf($apache . $p);
        }
        check("(ฎ) Apache ($apache): ทุก path ที่ห้ามได้ 403/404", !array_diff($res, [403, 404]), json_encode($res));
        check('(ฎ) Apache: ไฟล์ปกติยังเปิดได้ (assets 200, api/get.php?id=0 → 400 ก่อนแตะฐานข้อมูล)',
            statusOf("$apache/assets/css/base/base.css") === 200 && statusOf("$apache/api/get.php?id=0") === 400);
        $zip = statusOf('http://127.0.0.1/' . basename($root) . '.zip');
        echo ($zip === 200 ? '  WARN  ' : '  INFO  ') . '(ฎ) นอกโปรเจกต์: /' . basename($root) . ".zip ใน document root ตอบ HTTP $zip"
            . ($zip === 200 ? ' (ดาวน์โหลดได้ ควรย้ายออกจาก ./src)' : '') . PHP_EOL;
    }
    $junk = [];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn(SplFileInfo $f): bool => $f->getFilename() !== '.git'
    ));
    foreach ($it as $f) {
        if (preg_match('/\.(zip|tar|t?gz|7z|rar|bak|old|orig|swp|tmp|log|sql|sqlite)$|~$/i', $f->getFilename())) {
            $junk[] = substr($f->getPathname(), strlen($root));
        }
    }
    check('(ฎ) ไม่มีไฟล์ zip / ไฟล์ชั่วคราวในโฟลเดอร์โปรเจกต์ / no archives or temp files in the project', !$junk, implode(', ', $junk));

    foreach ($servers as $name => $s) {
        proc_terminate($s['proc']);
        proc_close($s['proc']);
        $log = (string)file_get_contents($s['log']);
        check("ไม่มี PHP warning/error ใน log ของ server $name", !preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)/', $log), substr($log, 0, 500));
    }
    $servers = [];
    check('ไม่มี PHP warning ในหน้าเว็บที่ทดสอบ / no warnings in responses', !$bodyWarnings, implode(' | ', $bodyWarnings));
} catch (Throwable $e) {
    check('ไม่มี exception ระหว่างทดสอบ / no unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

check('ไม่มี PHP warning ในโปรเซสทดสอบ / no PHP warnings in the test process', !$phpWarnings, implode(' | ', $phpWarnings));
check('ไม่มีการเชื่อม db() โดยไม่ระบุฐานข้อมูล (guard DB ไม่ถูกสร้าง, Master_AoI ไม่ถูกแตะ) / no implicit db() call', !dbExists($guardDb));

echo PHP_EOL . "ผล / Result: $passed passed, $failed failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
