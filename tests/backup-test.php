<?php
/**
 * Automated Test Suite for Monthly Data Backup & Restore ("Data Time-Machine")
 *
 * Run via CLI:
 *   docker exec -w /var/www/html/aoi-web-php php_app_web php tests/backup-test.php
 *
 * Tests both SQLite (:memory:) and MySQL (disposable test database) for:
 *   1. Backup settings & configurable retention months (default 12 months, validation 1..60)
 *   2. Automatic monthly snapshot creation (`ensureMonthlyAutoSnapshot`) & retention pruning (`pruneExpiredAutoSnapshots`)
 *   3. Git-like diff inspection (`compareSnapshotWithCurrent`: added, deleted, modified boards, magazines, programs)
 *   4. Atomic full-system restore (`restoreDataSnapshot`), auto `pre_restore` safety snapshot, Undo restore, and audit log
 *   5. Signed `.json` export/import & tamper rejection (`HMAC-SHA256` checksum verification)
 *   6. MySQL `GET_LOCK('aoi_board_number_code')` contention protection (`503`)
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/backup-manager.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function runSuiteOnDriver(PDO $pdo, string $driverLabel): void
{
    echo "\n━━━ {$driverLabel} ━━━\n";
    runMigrations($pdo);
    ensureBackupTables($pdo, true);

    // 1. Default settings & Retention validation
    $settings = getBackupSettings($pdo);
    ok("[{$driverLabel}] Default retention_months is 12", $settings['retention_months'] === 12 && $settings['auto_monthly_enabled'] === true);

    $caughtLow = false;
    try {
        updateBackupSettings($pdo, 0, true);
    } catch (InvalidArgumentException $e) {
        $caughtLow = ($e->getMessage() === BACKUP_INVALID_RETENTION_ERROR);
    }
    ok("[{$driverLabel}] Rejects retention_months < 1", $caughtLow);

    $caughtHigh = false;
    try {
        updateBackupSettings($pdo, 61, true);
    } catch (InvalidArgumentException $e) {
        $caughtHigh = ($e->getMessage() === BACKUP_INVALID_RETENTION_ERROR);
    }
    ok("[{$driverLabel}] Rejects retention_months > 60", $caughtHigh);

    // 2. Seed initial baseline boards and test 15 months of Auto Monthly Backups with 12-month retention
    $r1 = saveBoardRecord($pdo, [
        'zone'       => 'General',
        'sheet'      => 'B200_Gen',
        'shelf'      => 'B1',
        'product'    => 'PROD-ALPHA-01',
        'board_type' => 'ES3261A',
        'status'     => 'G',
        'pcs_status' => '',
        'side'       => 'F-Side',
        'defects'    => '',
    ]);
    $r2 = saveBoardRecord($pdo, [
        'zone'       => 'General',
        'sheet'      => 'B200_Gen',
        'shelf'      => 'B2',
        'product'    => 'PROD-BETA-02',
        'board_type' => 'Aluminum',
        'status'     => 'G',
        'pcs_status' => '',
        'side'       => 'B-Side',
        'defects'    => '',
    ]);
    $r3 = saveBoardRecord($pdo, [
        'zone'       => 'General',
        'sheet'      => 'B200_Gen',
        'shelf'      => 'B3',
        'product'    => 'PROD-GAMMA-03',
        'board_type' => 'L67068',
        'status'     => 'D',
        'pcs_status' => '',
        'side'       => 'F-Side',
        'defects'    => '1,4',
    ]);
    ok("[{$driverLabel}] Seeded 3 baseline boards in B200_Gen", $r1['success'] && $r2['success'] && $r3['success']);

    $code1 = (string)$r1['code'];
    $code2 = (string)$r2['code'];
    $code3 = (string)$r3['code'];

    // Simulate 15 monthly auto backups from 2025-08 to 2026-10
    $startMonth = new DateTimeImmutable('2025-08-15 09:00:00', new DateTimeZone(CODE_TZ));
    for ($i = 0; $i < 15; $i++) {
        $mDate = $startMonth->modify("+{$i} months");
        $created = ensureMonthlyAutoSnapshot($pdo, $mDate);
        if ($i === 0) {
            ok("[{$driverLabel}] First call in month creates monthly_auto snapshot", $created !== null && $created['period_ym'] === '2025-08');
            $dup = ensureMonthlyAutoSnapshot($pdo, $mDate);
            ok("[{$driverLabel}] Second call in same month is idempotent (no duplicate)", $dup === null);
        }
    }

    // Create 1 manual checkpoint so we can verify manual checkpoints are never pruned
    $octDate = new DateTimeImmutable('2026-10-20 14:00:00', new DateTimeZone(CODE_TZ));
    $manualSnap = createDataSnapshot($pdo, 'manual', 'Baseline Month 1 Checkpoint', 'admin', '2026-09', $octDate);
    ok("[{$driverLabel}] Manual checkpoint created with 3 boards", $manualSnap['board_count'] === 3 && $manualSnap['last_number_code'] === $code3);

    $listAfter15 = listDataSnapshots($pdo, $octDate);
    $autoPeriods = [];
    foreach ($listAfter15['snapshots'] as $s) {
        if ($s['snapshot_type'] === 'monthly_auto') {
            $autoPeriods[] = $s['period_ym'];
        }
    }
    ok(
        "[{$driverLabel}] 12-month retention keeps exactly 12 monthly_auto snapshots (2025-11 .. 2026-10)",
        count($autoPeriods) === 12 && min($autoPeriods) === '2025-11' && max($autoPeriods) === '2026-10',
        'got: ' . implode(', ', $autoPeriods)
    );

    // Update retention_months to 6 months -> should immediately prune 6 older auto snapshots (leaving 2026-05 .. 2026-10)
    $upd6 = updateBackupSettings($pdo, 6, true, $octDate);
    $listAfter6 = listDataSnapshots($pdo, $octDate);
    $autoPeriods6 = [];
    foreach ($listAfter6['snapshots'] as $s) {
        if ($s['snapshot_type'] === 'monthly_auto') {
            $autoPeriods6[] = $s['period_ym'];
        }
    }
    ok(
        "[{$driverLabel}] Updating retention_months to 6 prunes 6 older auto backups and keeps 2026-05 .. 2026-10",
        $upd6['pruned_count'] === 6 && count($autoPeriods6) === 6 && min($autoPeriods6) === '2026-05',
        'pruned=' . $upd6['pruned_count'] . ', kept=' . implode(',', $autoPeriods6)
    );
    ok("[{$driverLabel}] Manual checkpoint was preserved during auto-backup pruning", $listAfter6['counts']['manual'] === 1);

    // Restore retention back to 12 months
    updateBackupSettings($pdo, 12, true, $octDate);

    // 3. Simulate accidental data modifications & test Git-like Diff (`compareSnapshotWithCurrent`)
    // - Delete board 1 ($code1)
    $delSt = $pdo->prepare('DELETE FROM boards WHERE code = ?');
    $delSt->execute([$code1]);
    // - Modify board 2 ($code2): change status to D and shelf to B10
    $updSt = $pdo->prepare("UPDATE boards SET shelf = 'B10', shelf_no = 10, status = 'D', defects = '5' WHERE code = ?");
    $updSt->execute([$code2]);
    // - Add 2 new erroneous boards ($code4, $code5)
    $r4 = saveBoardRecord($pdo, [
        'zone'       => 'General',
        'sheet'      => 'B200_Gen',
        'shelf'      => 'B20',
        'product'    => 'WRONG-BATCH-01',
        'board_type' => 'ES3261A',
        'status'     => 'G',
        'pcs_status' => '',
        'side'       => 'F-Side',
        'defects'    => '',
    ]);
    $r5 = saveBoardRecord($pdo, [
        'zone'       => 'General',
        'sheet'      => 'B200_Gen',
        'shelf'      => 'B21',
        'product'    => 'WRONG-BATCH-02',
        'board_type' => 'ES3261A',
        'status'     => 'G',
        'pcs_status' => '',
        'side'       => 'F-Side',
        'defects'    => '',
    ]);
    $code5 = (string)$r5['code'];

    // - Change sheet_magazines for B200_Gen to 4
    $pdo->exec("DELETE FROM sheet_magazines WHERE sheet = 'B200_Gen'");
    $pdo->exec("INSERT INTO sheet_magazines (sheet, magazines) VALUES ('B200_Gen', 4)");

    $diff = compareSnapshotWithCurrent($pdo, $manualSnap['id']);
    ok("[{$driverLabel}] Diff detects database is not identical to snapshot", $diff['is_identical'] === false);
    ok("[{$driverLabel}] Diff detects 1 deleted board ({$code1})", $diff['boards_diff']['deleted_since_snapshot_count'] === 1 && $diff['boards_diff']['deleted_since_snapshot'][0]['code'] === $code1);
    ok("[{$driverLabel}] Diff detects 1 modified board ({$code2})", $diff['boards_diff']['modified_since_snapshot_count'] === 1 && $diff['boards_diff']['modified_since_snapshot'][0]['code'] === $code2);
    ok("[{$driverLabel}] Diff detects 2 newly added boards", $diff['boards_diff']['added_since_snapshot_count'] === 2);
    ok("[{$driverLabel}] Diff detects magazine change on B200_Gen", count($diff['magazines_diff']) === 1 && $diff['magazines_diff'][0]['current'] === 4);

    // 4. Atomic Full-System Restore & Pre-Restore Safety Snapshot
    $restoreRes = restoreDataSnapshot($pdo, $manualSnap['id'], '127.0.0.1', true, $octDate);
    ok("[{$driverLabel}] Restore created a pre_restore safety snapshot automatically", $restoreRes['pre_restore_snapshot'] !== null && $restoreRes['pre_restore_snapshot']['snapshot_type'] === 'pre_restore');
    ok("[{$driverLabel}] Restore reverted board count to 3 and last_number_code to {$code3}", $restoreRes['current']['board_count'] === 3 && $restoreRes['current']['last_number_code'] === $code3);

    $diffAfterRestore = compareSnapshotWithCurrent($pdo, $manualSnap['id']);
    ok("[{$driverLabel}] Live DB is 100% identical to snapshot after restore", $diffAfterRestore['is_identical'] === true);

    // Verify nextNumberCode continues consecutively from -004 after restoring to the 3-board snapshot
    $nextCodeAfterRestore = nextNumberCode($pdo);
    $expectedCode4 = substr($code3, 0, -3) . '004';
    ok("[{$driverLabel}] nextNumberCode() is {$expectedCode4} after restore", $nextCodeAfterRestore === $expectedCode4, "got {$nextCodeAfterRestore}");

    // Verify audit log entry in topology_audit
    $auditCnt = (int)$pdo->query("SELECT COUNT(*) FROM topology_audit WHERE action = 'snapshot_restore'")->fetchColumn();
    ok("[{$driverLabel}] Restore recorded snapshot_restore entry in topology_audit", $auditCnt >= 1);

    // Test Undo Restore using the pre_restore snapshot!
    $preId = (int)$restoreRes['pre_restore_snapshot']['id'];
    $undoRes = restoreDataSnapshot($pdo, $preId, '127.0.0.1', false, $octDate);
    ok("[{$driverLabel}] Undo Restore via pre_restore snapshot brings back the 4 boards and {$code5}", $undoRes['current']['board_count'] === 4 && $undoRes['current']['last_number_code'] === $code5);

    // Restore back to clean $manualSnap
    restoreDataSnapshot($pdo, $manualSnap['id'], '127.0.0.1', false, $octDate);

    // 5. Signed .json Export, Tamper Detection & Import
    $exported = exportSnapshotFilePayload($pdo, $manualSnap['id']);
    ok("[{$driverLabel}] Exported signed .json file has valid filename and JSON", str_ends_with($exported['filename'], '.json') && strlen($exported['json']) > 200);

    // Tamper with the exported JSON
    $tamperedJson = str_replace('PROD-ALPHA-01', 'HACKED-PROD-99', $exported['json']);
    $caughtTamper = false;
    try {
        importSnapshotFromUploadedJson($pdo, $tamperedJson, 'test');
    } catch (InvalidArgumentException $e) {
        $caughtTamper = ($e->getMessage() === BACKUP_CHECKSUM_MISMATCH_ERROR);
    }
    ok("[{$driverLabel}] Tampered .json backup file is rejected by HMAC-SHA256 checksum check", $caughtTamper);

    // Import genuine exported JSON
    $importedSnap = importSnapshotFromUploadedJson($pdo, $exported['json'], 'test', $octDate);
    ok("[{$driverLabel}] Genuine .json backup file imports cleanly as 'uploaded' snapshot", $importedSnap['snapshot_type'] === 'uploaded' && $importedSnap['board_count'] === 3);
}

// ── 1. Run on SQLite (:memory:) ──────────────────────────────────────
$sqlitePdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
runSuiteOnDriver($sqlitePdo, 'SQLite (:memory:)');

// ── 2. Run on MySQL (if reachable) ───────────────────────────────────
$dbHost = getenv('DB_HOST') ?: 'db';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') ?: '';

if ($dbUser !== '') {
    $testDb = 'aoi_test_backup_' . getmypid();
    $rootDsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ];
    $adminPdo = new PDO($rootDsn, $dbUser, $dbPass, $opts);
    $adminPdo->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    try {
        $mysqlDsn = "mysql:host={$dbHost};port={$dbPort};dbname={$testDb};charset=utf8mb4";
        $mysqlPdo = new PDO($mysqlDsn, $dbUser, $dbPass, $opts);

        runSuiteOnDriver($mysqlPdo, "MySQL ({$testDb})");

        // Test MySQL GET_LOCK contention
        $mysqlPdo2 = new PDO($mysqlDsn, $dbUser, $dbPass, $opts);
        $mysqlPdo2->query("SELECT GET_LOCK('aoi_board_number_code', 1)");
        $lockAcquired = acquireNumberCodeLock($mysqlPdo, 0);
        ok("[MySQL ({$testDb})] Advisory lock contention detected when another connection holds GET_LOCK", $lockAcquired === false);
        $mysqlPdo2->query("DO RELEASE_LOCK('aoi_board_number_code')");
    } finally {
        $adminPdo->exec("DROP DATABASE IF EXISTS `{$testDb}`");
    }
} else {
    echo "\n  SKIP  MySQL suite skipped (DB_USER not set)\n";
}

echo "\n==================================================\n";
echo "Backup & Restore Test Summary: {$passed} passed, {$failed} failed\n";
echo "==================================================\n";
exit($failed > 0 ? 1 : 0);
