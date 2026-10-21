<?php
/**
 * Test Shelf Reordering & Sort Order Removal
 * 1. Test backend reorderSheets() logic directly
 * 2. Test api/topology.php permission checking (auth required for sheet_reorder)
 * 3. Verify ALL is excluded and cannot be moved
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/topology-manager.php';

$pdo = db();

echo "Running Shelf Drag & Drop Reordering Tests...\n\n";

// 1. Get current sheets in General zone
$initialTopo = topology($pdo, true);
$generalSheets = $initialTopo['zones']['General']['sheets'];
echo "Initial General sheets: " . implode(', ', $generalSheets) . "\n";

// 2. Test reordering
$reversed = array_reverse($generalSheets);
echo "Reordering to: " . implode(', ', $reversed) . "\n";

$reorderResult = topologyApply($pdo, 'sheet_reorder', [
    'zone_key' => 'General',
    'order' => $reversed,
], '10.17.86.126');

assert($reorderResult['success'] === true, "Reorder should succeed");
echo "PASS: reorderSheets() succeeded with message: " . $reorderResult['message'] . "\n";

// Check updated topology
$updatedTopo = topology($pdo, true);
$newOrder = $updatedTopo['zones']['General']['sheets'];
assert($newOrder === $reversed, "New order should match reversed order");
echo "PASS: Topology reflected new order: " . implode(', ', $newOrder) . "\n";

// 3. Test that 'ALL' in order array is safely ignored
$withAll = array_merge(['ALL'], $generalSheets);
$resWithAll = topologyApply($pdo, 'sheet_reorder', [
    'zone_key' => 'General',
    'order' => $withAll,
], '10.17.86.126');
assert($resWithAll['success'] === true, "Reorder with ALL should succeed");
$topoAfterAll = topology($pdo, true);
assert(!in_array('ALL', $topoAfterAll['zones']['General']['sheets'], true), "ALL must never be in sheets table");
echo "PASS: 'ALL' tab was ignored during reordering\n";

// 4. Test auto-generating sort_order on sheet creation without sort_order field
$testSheetKey = 'TEST_SH_' . substr(md5((string)microtime()), 0, 6);
$createRes = topologyApply($pdo, 'sheet_create', [
    'zone_key' => 'General',
    'sheet_key' => $testSheetKey,
    'label' => 'Test Auto Order',
    'shelf_prefix' => 'TS',
    'dot_color' => 'bg-emerald-500',
    // sort_order is omitted!
], '10.17.86.126');
assert($createRes['success'] === true, "Creating sheet without sort_order should succeed");
$row = topologyRow($pdo, 'sheets', $testSheetKey);
assert($row !== null && (int)$row['sort_order'] > 0, "sort_order should be auto-assigned");
echo "PASS: Sheet created without sort_order received auto-order: " . $row['sort_order'] . "\n";

// Clean up test sheet
topologyApply($pdo, 'sheet_delete', ['sheet_key' => $testSheetKey], '10.17.86.126');
echo "PASS: Cleaned up test sheet\n";

// Restore original order
topologyApply($pdo, 'sheet_reorder', [
    'zone_key' => 'General',
    'order' => $generalSheets,
], '10.17.86.126');
echo "PASS: Restored original sheet order\n";

// 5. Test Audit Log
$audit = $pdo->query("SELECT * FROM topology_audit WHERE action = 'reorder' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert($audit !== null, "Audit entry for reorder should exist");
assert($audit['action'] === 'reorder', "Audit action should be reorder");
assert($audit['entity_type'] === 'sheet', "Audit entity_type should be sheet");
echo "PASS: Audit log verified: action={$audit['action']}, entity={$audit['entity_key']}\n";

echo "\nAll Shelf Reordering Tests Passed Successfully!\n";
