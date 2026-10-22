<?php
/**
 * Test: AOI Program Stage Updates, Optimistic Concurrency, Undo & Bulk Actions
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

echo "\n--- Testing AOI Programs Interactive Engine & API Endpoints ---\n";

// 1. Create a test program record
$testModel = 'TEST-PHASE-B-' . bin2hex(random_bytes(3));
$testSide  = 'F-Side';

$createRes = saveNewProgram($pdo, [
    'product_model' => $testModel,
    'side'          => $testSide,
    'machine_type'  => 'OMRON (2D)',
    'owner'         => 'TestUser',
], 'TestUser', '127.0.0.1');

assertTest('saveNewProgram creates new record', $createRes['success'] === true && !empty($createRes['program']['id']));
$testProgId = $createRes['program']['id'];
$initVersion = $createRes['program']['version'];
$createAuditId = $createRes['audit_id'];

assertTest('Initial version is 1', $initVersion === 1);
assertTest('Initial status is Queued', $createRes['program']['program_ok'] === 'Queued');

// 2. Test duplicate rejection
$dupRes = saveNewProgram($pdo, [
    'product_model' => $testModel,
    'side'          => $testSide,
    'machine_type'  => 'OMRON (2D)',
], 'TestUser', '127.0.0.1');

assertTest('Duplicate (model, side) is rejected with 422', $dupRes['success'] === false && $dupRes['status_code'] === 422);

// 3. Test stage 1 update (0 -> 1)
$stg1Res = updateProgramStage($pdo, $testProgId, 1, 1, 'Thanapol', 1, null, '127.0.0.1');
assertTest('updateProgramStage stage 1 to 1 succeeds', $stg1Res['success'] === true);
assertTest('Version increments to 2', $stg1Res['program']['version'] === 2);
assertTest('Stage 1 operator recorded', $stg1Res['program']['stage_1_by'] === 'Thanapol');
assertTest('Stage 1 status is 1', $stg1Res['program']['stage_1'] === 1);

// 4. Test optimistic locking conflict (passing obsolete version 1)
$conflictRes = updateProgramStage($pdo, $testProgId, 1, 2, 'Thanapol', 1, null, '127.0.0.1');
assertTest('Outdated version produces conflict', $conflictRes['success'] === false && ($conflictRes['conflict'] ?? false) === true);
assertTest('Conflict status code is 409', $conflictRes['status_code'] === 409);
assertTest('Conflict returns latest record', $conflictRes['current']['version'] === 2);

// 5. Update stage 1 to complete (version 2 -> 3)
$stg1Complete = updateProgramStage($pdo, $testProgId, 1, 2, 'Thanapol', 2, null, '127.0.0.1');
assertTest('Update stage 1 to complete (2) succeeds', $stg1Complete['success'] === true);
assertTest('Version is now 3', $stg1Complete['program']['version'] === 3);
$auditStage1 = $stg1Complete['audit_id'];

// 6. Test Undo stage update via audit trail
$undoRes = undoProgramAudit($pdo, $testProgId, $auditStage1, 'Thanapol', '127.0.0.1');
assertTest('undoProgramAudit restores previous stage status', $undoRes['success'] === true);
assertTest('Restored stage 1 is 1 (In Progress)', $undoRes['program']['stage_1'] === 1);

// 7. Test progression to complete and program_ok calculation
updateProgramStage($pdo, $testProgId, 1, 2, 'Thanapol', 0, null, '127.0.0.1');
updateProgramStage($pdo, $testProgId, 2, 2, 'Thanapol', 0, null, '127.0.0.1');
$midProg = fetchProgramById($pdo, $testProgId);
assertTest('Stages 1 & 2 complete results in Wait Master', $midProg['program_ok'] === 'Wait Master');

updateProgramStage($pdo, $testProgId, 3, 2, 'Thanapol', 0, null, '127.0.0.1');
updateProgramStage($pdo, $testProgId, 4, 2, 'Thanapol', 0, null, '127.0.0.1');
$finalProg = fetchProgramById($pdo, $testProgId);
assertTest('All 4 stages complete results in Complete', $finalProg['program_ok'] === 'Complete');
assertTest('is_complete helper is true', $finalProg['is_complete'] === true);

// 8. Test Bulk Operations
$bulkModel1 = 'TEST-BULK-A-' . bin2hex(random_bytes(3));
$bulkModel2 = 'TEST-BULK-B-' . bin2hex(random_bytes(3));

$b1 = saveNewProgram($pdo, ['product_model' => $bulkModel1, 'side' => 'F-Side'], 'TestUser', '127.0.0.1');
$b2 = saveNewProgram($pdo, ['product_model' => $bulkModel2, 'side' => 'F-Side'], 'TestUser', '127.0.0.1');

$bulkIds = [$b1['program']['id'], $b2['program']['id']];

// Bulk Advance
$bulkAdvRes = bulkUpdatePrograms($pdo, 'advance_stage', $bulkIds, [], 'OperatorX', '127.0.0.1');
assertTest('bulkUpdatePrograms advance_stage succeeds', $bulkAdvRes['success'] === true && $bulkAdvRes['updated_count'] === 2);

$b1Updated = fetchProgramById($pdo, $bulkIds[0]);
$b2Updated = fetchProgramById($pdo, $bulkIds[1]);
assertTest('Bulk item 1 advanced to stage 1 = 1', $b1Updated['stage_1'] === 1);
assertTest('Bulk item 2 advanced to stage 1 = 1', $b2Updated['stage_1'] === 1);

// Bulk Assign Owner
$bulkOwnerRes = bulkUpdatePrograms($pdo, 'assign_owner', $bulkIds, ['owner' => 'SupervisorY'], 'OperatorX', '127.0.0.1');
assertTest('bulkUpdatePrograms assign_owner succeeds', $bulkOwnerRes['success'] === true);
$b1Assigned = fetchProgramById($pdo, $bulkIds[0]);
assertTest('Bulk item 1 owner updated to SupervisorY', $b1Assigned['owner'] === 'SupervisorY');

// Bulk Set Stage
$bulkSetRes = bulkUpdatePrograms($pdo, 'set_stage', $bulkIds, ['stage' => 2, 'status' => 9], 'OperatorX', '127.0.0.1');
assertTest('bulkUpdatePrograms set_stage (N/A 9) succeeds', $bulkSetRes['success'] === true);
$b1Set = fetchProgramById($pdo, $bulkIds[0]);
assertTest('Bulk item 1 stage 2 is 9 (N/A)', $b1Set['stage_2'] === 9);

// 9. Clean up test records
$pdo->prepare('DELETE FROM aoi_programs WHERE id IN (?, ?, ?)')->execute([$testProgId, $bulkIds[0], $bulkIds[1]]);
$pdo->prepare('DELETE FROM aoi_program_audit WHERE program_id IN (?, ?, ?)')->execute([$testProgId, $bulkIds[0], $bulkIds[1]]);
assertTest('Test records cleanup completed', true);

echo "\nResult: {$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
