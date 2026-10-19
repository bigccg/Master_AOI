<?php
/**
 * Page data for rendering: edit row, KPI, rack map, modal state.
 */

declare(strict_types=1);

// ──────────────────────────────────────────────
// Edit mode (GET ?edit=ID)
// ──────────────────────────────────────────────

if (!$editRow && isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM boards WHERE id = ?');
    $st->execute([(int)qs('edit')]);
    $editRow = $st->fetch() ?: null;
}

// ──────────────────────────────────────────────
// KPI (entire sheet or All Zones, independent of filters)
// ──────────────────────────────────────────────

$tabCounts = [];
foreach ($pdo->query('SELECT sheet, COUNT(*) c FROM boards GROUP BY sheet') as $r) $tabCounts[$r['sheet']] = (int)$r['c'];
$allZonesCount = array_sum($tabCounts);

$cnt = ['G' => 0, 'D' => 0, 'GD' => 0];
if ($isAllZones) {
    $st = $pdo->query('SELECT status, COUNT(*) c FROM boards GROUP BY status');
    foreach ($st as $r) {
        if (isset($cnt[$r['status']])) $cnt[$r['status']] = (int)$r['c'];
    }
    $total = $allZonesCount;   // every board, incl. Other = Bare / Bad Mark (no G / D / G+D)
    $pctG  = $total ? round($cnt['G'] / $total * 100) : 0;
    $magCount = 1;
    $capacity = 0;
    $usedShelves = 0;
    foreach (array_keys(SHEETS) as $shKey) {
        $shMag = magazineCount($pdo, $shKey);
        if ($shMag > $magCount) $magCount = $shMag;
        $shCap = $shMag * MAGAZINE_SIZE;
        $capacity += $shCap;
        $uSt = $pdo->prepare('SELECT COUNT(DISTINCT shelf_no) FROM boards WHERE sheet = ? AND shelf_no BETWEEN 1 AND ?');
        $uSt->execute([$shKey, $shCap]);
        $usedShelves += (int)$uSt->fetchColumn();
    }
    $capacity = max(1, $capacity);
    $util     = min(100, (int)round($usedShelves / $capacity * 100));
    $maxShelf = (int)$pdo->query('SELECT COALESCE(MAX(shelf_no),0) FROM boards')->fetchColumn();
} else {
    $st = $pdo->prepare('SELECT status, COUNT(*) c FROM boards WHERE sheet = ? GROUP BY status');
    $st->execute([$sheet]);
    foreach ($st as $r) $cnt[$r['status']] = (int)$r['c'];
    $total = array_sum($cnt);
    $pctG  = $total ? round($cnt['G'] / $total * 100) : 0;
    $magCount = magazineCount($pdo, $sheet);
    $capacity = $magCount * MAGAZINE_SIZE;
    $util     = min(100, (int)round($total / $capacity * 100));
    $maxShelf = (int)$pdo->query('SELECT COALESCE(MAX(shelf_no),0) FROM boards WHERE sheet = ' . $pdo->quote($sheet))->fetchColumn();
}

// ──────────────────────────────────────────────
// Rack map (for $modalSheet)
// ──────────────────────────────────────────────

$modalMagCount = magazineCount($pdo, $modalSheet);
$modalCapacity = $modalMagCount * MAGAZINE_SIZE;
$st = $pdo->prepare('SELECT id, shelf, shelf_no, status, pcs_status, code, product FROM boards WHERE sheet = ?');
$st->execute([$modalSheet]);
$rack     = [];   // shelf_no => status
$rackInfo = [];   // shelf_no => [shelf, code, product, pcs_status]  (tooltip + color + link in the rack map)
$takenBy  = [];   // shelf_no => [record ids]            (hides occupied shelves in the dropdown)
foreach ($st as $r) {
    $no = (int)$r['shelf_no'];
    $rack[$no]       = $r['status'];
    $rackInfo[$no]   = $r;
    $takenBy[$no][]  = (int)$r['id'];
}

$st = $pdo->prepare('SELECT code, created_at FROM boards WHERE sheet = ? ORDER BY created_at DESC, id DESC LIMIT 1');
$st->execute([$modalSheet]);
$last = $st->fetch();

$modalUsedShelves = count(array_filter(array_keys($takenBy), fn($k) => $k >= 1 && $k <= $modalCapacity));
if (!$isAllZones) {
    $usedShelves = $modalUsedShelves;
}
$isMax = ($modalUsedShelves >= $modalCapacity);   // every shelf of every Magazine is taken -> "Magazine is Max"

// Free shelves for the modal's Shelf dropdown (magazine => [shelf_no, ...]); same rule as api/sheet_data.php
$freeShelves = [];
$freeByMag   = [];
for ($n = 1; $n <= $modalCapacity; $n++) {
    if (empty($takenBy[$n])) {
        $freeShelves[] = $n;
        $freeByMag[intdiv($n - 1, MAGAZINE_SIZE) + 1][] = $n;
    }
}

// ──────────────────────────────────────────────
// Modal state
// ──────────────────────────────────────────────

$cellRow   = $dense ? 'py-2' : 'py-3.5';
$modalOpen = (bool)$editRow || (!empty($formErrors) && !$editRow);
$nextCode = '';
try { $nextCode = nextNumberCode($pdo, $modalSheet); } catch (RuntimeException $e) { $nextCode = ''; }
$m = $editRow ?? ['id' => 0, 'code' => $nextCode, 'shelf' => '', 'product' => '', 'side' => '', 'board_type' => '', 'status' => '', 'pcs_status' => ''];

// ──────────────────────────────────────────────
// View helpers for the Defect Matrix / Rack Map / board modal
// ──────────────────────────────────────────────

$sp = shelfPrefix($modalSheet);
// Other = Bare / Bad Mark has no G / D / G+D, so it gets its own color (keyed by pcs_status)
$cellCls = ['G' => 'bg-emerald-600 text-white', 'D' => 'bg-red-600 text-white', 'GD' => 'bg-amber-400 text-amber-950',
            'Bare' => 'bg-sky-500 text-white', 'Bad Mark' => 'bg-violet-600 text-white'];
$nextRange = magazineRange($modalMagCount + 1);
