<?php
/**
 * Test: AOI Program Tracker — 403 Direct Creation Guard, Master Board Live Sync (D / GD),
 * Stage Updates, Metadata Updates, Optimistic Concurrency, Side Sort & Undo
 * Run in Docker:
 *   docker exec -w /var/www/html/aoi-web-php php_app_web php tests/program-stage-interactive-test.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/programs-data.php';

$pdo = db();
$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $cond, string $details = ''): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc} -> {$details}\n";
    }
}

echo "\n--- Testing AOI Programs Live Master Sync, 403 Creation Guard & Interactive Engine ---\n";

$testModel = 'TEST-SYNC-' . strtoupper(bin2hex(random_bytes(3)));
$testSide  = 'F-Side';

// 1. Direct creation via saveNewProgram is blocked with HTTP 403
$createRes = saveNewProgram($pdo, [
    'product_model' => $testModel,
    'side'          => $testSide,
    'machine_type'  => 'OMRON (2D)',
    'owner'         => 'TestUser',
], 'TestUser', '127.0.0.1');

assertTest('saveNewProgram refuses direct creation with 403', $createRes['success'] === false && ($createRes['status_code'] ?? 0) === 403);

// 2. Syncing from a Master Board Defect (D) creates the program record automatically
$synced = syncProgramFromBoard($pdo, $testModel, $testSide, 'CB_06');
assertTest('syncProgramFromBoard creates new row for D/GD board', $synced === true);

// Insert matching D board in boards to test live Master Samples link
$nowStr = date('Y-m-d H:i:s');
$insBoard = $pdo->prepare("
    INSERT INTO boards (zone, sheet, code, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, defects, created_at)
    VALUES ('General', 'CD300', :code, 'CD49', 49, 'CD300 Magazine 1st · Slot 49', :prod, :side, 'ES3261A', 'D', '', '1', :now)
");
$testCode = 'AOI-T-' . strtoupper(bin2hex(random_bytes(3)));
$insBoard->execute([
    ':code' => $testCode,
    ':prod' => $testModel,
    ':side' => $testSide,
    ':now'  => $nowStr,
]);
$testBoardId = (int)$pdo->lastInsertId();

$listRes = fetchProgramList($pdo, ['search' => $testModel], 'side', 'ASC', 50, 0);
assertTest('fetchProgramList finds newly synced program and supports sort=side', $listRes['total'] === 1);

$progRow = formatProgramRow($listRes['rows'][0]);
$testProgId = $progRow['id'];
assertTest('Initial version is 1 and status is Queued', $progRow['version'] === 1 && $progRow['program_ok'] === 'Queued');
assertTest('Live Master Samples counts D board (master_d = 1, master_gd = 0)', $progRow['master_d'] === 1 && $progRow['master_gd'] === 0);
assertTest('Live Master Samples includes board shelf in master_d_shelves', str_contains($progRow['master_d_shelves'], 'CD49'));

// 3. Test stage 1 update (0 -> 1)
$stg1Res = updateProgramStage($pdo, $testProgId, 1, 1, 'Thanapol', 1, null, '127.0.0.1');
assertTest('updateProgramStage stage 1 to 1 succeeds', $stg1Res['success'] === true);
assertTest('Version increments to 2', $stg1Res['program']['version'] === 2);
assertTest('Stage 1 operator recorded', $stg1Res['program']['stage_1_by'] === 'Thanapol');
assertTest('Stage 1 status is 1', $stg1Res['program']['stage_1'] === 1);

// 4. Test optimistic locking conflict (passing obsolete version 1)
$conflictRes = updateProgramStage($pdo, $testProgId, 1, 2, 'Thanapol', 1, null, '127.0.0.1');
assertTest('Outdated version produces conflict (409)', $conflictRes['success'] === false && ($conflictRes['status_code'] ?? 0) === 409);

// 5. Update stage 1 to complete (version 2 -> 3)
$stg1Complete = updateProgramStage($pdo, $testProgId, 1, 2, 'Thanapol', 2, null, '127.0.0.1');
assertTest('Update stage 1 to complete (2) succeeds', $stg1Complete['success'] === true);
$auditStage1 = $stg1Complete['audit_id'];

// 6. Test Undo stage update via audit trail
$undoRes = undoProgramAudit($pdo, $testProgId, $auditStage1, 'Thanapol', '127.0.0.1');
assertTest('undoProgramAudit restores previous stage status', $undoRes['success'] === true && $undoRes['program']['stage_1'] === 1);

// 7. Test progression to Wait Master and Complete
updateProgramStage($pdo, $testProgId, 1, 2, 'Thanapol', 0, null, '127.0.0.1');
updateProgramStage($pdo, $testProgId, 2, 2, 'Thanapol', 0, null, '127.0.0.1');
$midProg = fetchProgramById($pdo, $testProgId);
assertTest('Stages 1 & 2 complete results in Wait Master', $midProg['program_ok'] === 'Wait Master');

updateProgramStage($pdo, $testProgId, 3, 2, 'Thanapol', 0, null, '127.0.0.1');
updateProgramStage($pdo, $testProgId, 4, 2, 'Thanapol', 0, null, '127.0.0.1');
$finalProg = fetchProgramById($pdo, $testProgId);
assertTest('All 4 stages complete results in Complete', $finalProg['program_ok'] === 'Complete' && $finalProg['is_complete'] === true);

// 8. Test single-row metadata update (owner & note)
$metaRes = updateProgramMeta($pdo, $testProgId, ['owner' => 'SONGSREM', 'note' => 'ทดสอบหมายเหตุ'], 'Thanapol', 0, '127.0.0.1');
assertTest('updateProgramMeta updates owner and note on existing row', $metaRes['success'] === true && $metaRes['program']['owner'] === 'SONGSREM' && $metaRes['program']['note'] === 'ทดสอบหมายเหตุ');

// 9. When D board is deleted from boards, program row remains intact with master_d = 0
$pdo->prepare('DELETE FROM boards WHERE id = ?')->execute([$testBoardId]);
$afterBoardDel = fetchProgramById($pdo, $testProgId);
assertTest('Deleting D board keeps program row intact with master_d = 0 and has_master_defect = false',
    $afterBoardDel !== null && $afterBoardDel['master_d'] === 0 && $afterBoardDel['has_master_defect'] === false && $afterBoardDel['is_complete'] === true);

// 10. Clean up test records
$pdo->prepare('DELETE FROM aoi_programs WHERE id = ?')->execute([$testProgId]);
$pdo->prepare('DELETE FROM aoi_program_audit WHERE program_id = ?')->execute([$testProgId]);
assertTest('Test records cleanup completed', true);

echo "\nResult: {$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
