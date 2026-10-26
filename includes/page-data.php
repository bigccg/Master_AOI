<?php
/**
 * Page data for rendering: edit row, zone-filtered KPI, rack map, modal state.
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
// KPI & Tab counts (scoped to the selected Zone, independent of search/dropdown filters)
// ──────────────────────────────────────────────

// Every sheet of the zone, disabled ones included: they have no tab but still belong to All Size Magazine
$sheetCounts = array_fill_keys($zoneSheetKeys, 0);
$allZonesKpi = $isAllZones ? allZonesKpi($pdo) : null;
if ($hasZoneSheets && !$isAllZones) {
    $tcSt = $pdo->prepare('SELECT sheet, COUNT(*) c FROM boards WHERE zone = ? GROUP BY sheet');
    $tcSt->execute([$zone]);
    foreach ($tcSt as $r) {
        if (isset($sheetCounts[$r['sheet']])) {
            $sheetCounts[$r['sheet']] = (int)$r['c'];
        }
    }
}
$tabCounts      = array_intersect_key($sheetCounts, $currentZoneSheets);
$allSheetsCount = $isAllZones ? $allZonesKpi['total'] : array_sum($sheetCounts);

$cnt = ['G' => 0, 'D' => 0, 'GD' => 0];
if (!$hasZoneSheets) {
    $total          = 0;
    $pctG           = 0;
    $magCount       = 0;
    $capacity       = 0;
    $usedShelves    = 0;
    $util           = 0;
    $maxShelf       = 0;
    $shelfRangeText = 'ยังไม่มี Shelf ในโซนนี้ / No shelves in this zone';
} elseif ($isAllZones) {
    $cnt            = $allZonesKpi['cnt'];
    $total          = $allZonesKpi['total'];
    $pctG           = $allZonesKpi['pctG'];
    $magCount       = $allZonesKpi['magCount'];
    $capacity       = $allZonesKpi['capacity'];
    $usedShelves    = $allZonesKpi['usedShelves'];
    $util           = $allZonesKpi['util'];
    $maxShelf       = $allZonesKpi['maxShelf'];
    $shelfRangeText = $allZonesKpi['shelfRangeText'];
} elseif ($isAllSheets) {
    $st = $pdo->prepare('SELECT status, COUNT(*) c FROM boards WHERE zone = ? GROUP BY status');
    $st->execute([$zone]);
    foreach ($st as $r) {
        if (isset($cnt[$r['status']])) {
            $cnt[$r['status']] = (int)$r['c'];
        }
    }
    $total       = $allSheetsCount;   // every board in this zone, incl. Other = Bare / Bad Mark
    $pctG        = $total ? (int)round($cnt['G'] / $total * 100) : 0;
    $magCount    = 1;
    $capacity    = 0;
    $usedShelves = 0;
    foreach ($zoneSheetKeys as $shKey) {
        $shMag = magazineCount($pdo, $shKey);
        if ($shMag > $magCount) $magCount = $shMag;
        $shCap = $shMag * MAGAZINE_SIZE;
        $capacity += $shCap;
        $uSt = $pdo->prepare('SELECT COUNT(DISTINCT shelf_no) FROM boards WHERE zone = ? AND sheet = ? AND shelf_no BETWEEN 1 AND ?');
        $uSt->execute([$zone, $shKey, $shCap]);
        $usedShelves += (int)$uSt->fetchColumn();
    }
    $capacity       = max(1, $capacity);
    $util           = min(100, (int)round($usedShelves / $capacity * 100));
    $maxShelfSt     = $pdo->prepare('SELECT COALESCE(MAX(shelf_no), 0) FROM boards WHERE zone = ?');
    $maxShelfSt->execute([$zone]);
    $maxShelf       = (int)$maxShelfSt->fetchColumn();
    $shelfRangeText = $total ? allSheetsRangeLabel($zone) : 'No boards yet';
} else {
    $st = $pdo->prepare('SELECT status, COUNT(*) c FROM boards WHERE zone = ? AND sheet = ? GROUP BY status');
    $st->execute([$zone, $sheet]);
    foreach ($st as $r) {
        if (isset($cnt[$r['status']])) {
            $cnt[$r['status']] = (int)$r['c'];
        }
    }
    $total       = $sheetCounts[$sheet] ?? array_sum($cnt);
    $pctG        = $total ? (int)round($cnt['G'] / $total * 100) : 0;
    $magCount    = magazineCount($pdo, $sheet);
    $capacity    = max(1, $magCount * MAGAZINE_SIZE);
    $util        = min(100, (int)round($total / $capacity * 100));
    $maxShelfSt  = $pdo->prepare('SELECT COALESCE(MAX(shelf_no), 0) FROM boards WHERE zone = ? AND sheet = ?');
    $maxShelfSt->execute([$zone, $sheet]);
    $maxShelf       = (int)$maxShelfSt->fetchColumn();
    $shelfRangeText = $total ? 'Shelf ' . shelfPrefix($sheet) . '1 - ' . shelfPrefix($sheet) . $maxShelf : 'No boards yet';
}

// ──────────────────────────────────────────────
// Rack map (for $modalSheet when zone has sheets)
// ──────────────────────────────────────────────

$rack       = [];   // shelf_no => status
$rackInfo   = [];   // shelf_no => [shelf, code, product, pcs_status]
$takenBy    = [];   // shelf_no => [record ids]
$freeShelves = [];
$freeByMag   = [];

if ($hasZoneSheets && $modalSheet !== '') {
    $modalMagCount = magazineCount($pdo, $modalSheet);
    $modalCapacity = $modalMagCount * MAGAZINE_SIZE;
    $st = $pdo->prepare('SELECT id, shelf, shelf_no, status, pcs_status, code, product FROM boards WHERE zone = ? AND sheet = ?');
    $st->execute([$zone, $modalSheet]);
    foreach ($st as $r) {
        $no = (int)$r['shelf_no'];
        $rack[$no]      = $r['status'];
        $rackInfo[$no]  = $r;
        $takenBy[$no][] = (int)$r['id'];
    }

    $st = $pdo->prepare('SELECT code, created_at FROM boards WHERE zone = ? AND sheet = ? ORDER BY created_at DESC, id DESC LIMIT 1');
    $st->execute([$zone, $modalSheet]);
    $last = $st->fetch();

    $modalUsedShelves = count(array_filter(array_keys($takenBy), fn($k) => $k >= 1 && $k <= $modalCapacity));
    if (!$isAllSheets) {
        $usedShelves = $modalUsedShelves;
        $util        = min(100, (int)round($usedShelves / max(1, $capacity) * 100));
    }
    $isMax = ($modalUsedShelves >= $modalCapacity);
    $canAddMagazine = $magCount < MAGAZINE_LIMIT && sheetIsActive($modalSheet);

    for ($n = 1; $n <= $modalCapacity; $n++) {
        if (empty($takenBy[$n])) {
            $freeShelves[] = $n;
            $freeByMag[intdiv($n - 1, MAGAZINE_SIZE) + 1][] = $n;
        }
    }
} else {
    $modalMagCount    = 0;
    $modalCapacity    = 0;
    $modalUsedShelves = 0;
    $isMax            = false;
    $canAddMagazine   = false;
    $last             = false;
}

// ──────────────────────────────────────────────
// Modal state (System-wide Number Code)
// ──────────────────────────────────────────────

$cellRow   = $dense ? 'py-2' : 'py-3.5';
$modalOpen = (bool)$editRow || (!empty($formErrors) && !$editRow);
$nextCode  = '';
try {
    $nextCode = nextNumberCode($pdo);
} catch (RuntimeException $e) {
    $nextCode = '';
}
$m = $editRow ?? [
    'id' => 0, 'code' => $nextCode, 'shelf' => '', 'product' => '',
    'side' => '', 'board_type' => '', 'status' => '', 'pcs_status' => '',
];

// ──────────────────────────────────────────────
// View helpers for the Defect Matrix / Rack Map / board modal
// ──────────────────────────────────────────────

$sp = $modalSheet !== '' ? shelfPrefix($modalSheet) : '';
// Other = Bare / Bad Mark has no G / D / G+D, so it gets its own color (keyed by pcs_status)
$cellCls = [
    'G' => 'bg-emerald-600 text-white',
    'D' => 'bg-red-600 text-white',
    'GD' => 'bg-amber-400 text-amber-950',
    'Bare' => 'bg-sky-500 text-white',
    'Bad Mark' => 'bg-violet-600 text-white',
];
$nextRange = magazineRange($modalMagCount + 1);
