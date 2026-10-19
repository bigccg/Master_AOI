<?php
/**
 * API Endpoint: Get complete sheet metadata, KPIs, Rack Map, and Free Shelves (AJAX)
 * GET /api/sheet_data.php?sheet=...
 * Returns JSON: { success: bool, data: { ... } }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$sheet = qs('sheet', DEFAULT_SHEET);
if ($sheet !== 'ALL' && !isset(SHEETS[$sheet])) {
    $sheet = DEFAULT_SHEET;
}
$isAllZones = ($sheet === 'ALL');
$modalSheet = $isAllZones ? DEFAULT_SHEET : $sheet;

$pdo = db();
$sp = $isAllZones ? 'ALL' : shelfPrefix($sheet);

// ──────────────────────────────────────────────
// Tab counts (always across all sheets)
// ──────────────────────────────────────────────
$tabCounts = [];
foreach ($pdo->query('SELECT sheet, COUNT(*) c FROM boards GROUP BY sheet') as $r) {
    $tabCounts[$r['sheet']] = (int)$r['c'];
}
foreach (SHEETS as $k => $_v) {
    if (!isset($tabCounts[$k])) {
        $tabCounts[$k] = 0;
    }
}
$tabCounts['ALL'] = array_sum($tabCounts);

// ──────────────────────────────────────────────
// KPI
// ──────────────────────────────────────────────
$cnt = ['G' => 0, 'D' => 0, 'GD' => 0];
if ($isAllZones) {
    $st = $pdo->query('SELECT status, COUNT(*) c FROM boards GROUP BY status');
    foreach ($st as $r) {
        if (isset($cnt[$r['status']])) {
            $cnt[$r['status']] = (int)$r['c'];
        }
    }
    $total = array_sum($cnt);
    $pctG  = $total ? (int)round($cnt['G'] / $total * 100) : 0;

    $maxMagCount = 1;
    $totalCapacity = 0;
    $totalUsedShelves = 0;
    foreach (array_keys(SHEETS) as $shKey) {
        $shMag = magazineCount($pdo, $shKey);
        if ($shMag > $maxMagCount) $maxMagCount = $shMag;
        $shCap = $shMag * MAGAZINE_SIZE;
        $totalCapacity += $shCap;
        $uSt = $pdo->prepare('SELECT COUNT(DISTINCT shelf_no) FROM boards WHERE sheet = ? AND shelf_no BETWEEN 1 AND ?');
        $uSt->execute([$shKey, $shCap]);
        $totalUsedShelves += (int)$uSt->fetchColumn();
    }
    $magCount    = $maxMagCount;
    $capacity    = max(1, $totalCapacity);
    $usedShelves = $totalUsedShelves;
    $util        = min(100, (int)round($usedShelves / $capacity * 100));
    $maxShelf    = (int)$pdo->query('SELECT COALESCE(MAX(shelf_no), 0) FROM boards')->fetchColumn();
    $shelfRangeText = $total ? 'All Zones (CD, A, B, C)' : 'No boards yet';
} else {
    $st = $pdo->prepare('SELECT status, COUNT(*) c FROM boards WHERE sheet = ? GROUP BY status');
    $st->execute([$sheet]);
    foreach ($st as $r) {
        $cnt[$r['status']] = (int)$r['c'];
    }
    $total = array_sum($cnt);
    $pctG  = $total ? (int)round($cnt['G'] / $total * 100) : 0;
    $magCount = magazineCount($pdo, $sheet);
    $capacity = $magCount * MAGAZINE_SIZE;
    $util     = min(100, (int)round($total / $capacity * 100));

    $maxShelfStmt = $pdo->prepare('SELECT COALESCE(MAX(shelf_no), 0) FROM boards WHERE sheet = ?');
    $maxShelfStmt->execute([$sheet]);
    $maxShelf = (int)$maxShelfStmt->fetchColumn();
    $shelfRangeText = $total ? "Shelf {$sp}1 - {$sp}{$maxShelf}" : 'No boards yet';
}

// ──────────────────────────────────────────────
// Rack Map & Modal Free Shelves (for $modalSheet when ALL is active)
// ──────────────────────────────────────────────
$modalSp       = shelfPrefix($modalSheet);
$modalMagCount = magazineCount($pdo, $modalSheet);
$modalCapacity = $modalMagCount * MAGAZINE_SIZE;

$st = $pdo->prepare('SELECT id, shelf, shelf_no, status, code, product FROM boards WHERE sheet = ?');
$st->execute([$modalSheet]);
$rack     = [];
$rackInfo = [];
$takenBy  = [];
foreach ($st as $r) {
    $no = (int)$r['shelf_no'];
    $rack[$no]      = $r['status'];
    $rackInfo[$no]  = [
        'id'      => (int)$r['id'],
        'shelf'   => $r['shelf'],
        'code'    => $r['code'],
        'product' => $r['product'],
        'status'  => $r['status']
    ];
    $takenBy[$no][] = (int)$r['id'];
}

$modalUsedShelves = count(array_filter(array_keys($takenBy), fn($k) => $k >= 1 && $k <= $modalCapacity));
$isMax            = ($modalUsedShelves >= $modalCapacity);
if (!$isAllZones) {
    $usedShelves = $modalUsedShelves;
}

// Magazines structure for easy rendering
$magazines = [];
for ($mg = 1; $mg <= $modalMagCount; $mg++) {
    [$from, $to] = magazineRange($mg);
    $mgUsed = 0;
    $shelvesList = [];
    for ($n = $from; $n <= $to; $n++) {
        $occupied = isset($rack[$n]);
        if ($occupied) $mgUsed++;
        $shelvesList[] = [
            'no'       => $n,
            'shelf'    => $modalSp . $n,
            'occupied' => $occupied,
            'status'   => $rack[$n] ?? null,
            'data'     => $rackInfo[$n] ?? null
        ];
    }
    $magazines[] = [
        'mag'     => $mg,
        'title'   => magazineTitle($mg),
        'from'    => $from,
        'to'      => $to,
        'used'    => $mgUsed,
        'full'    => ($mgUsed >= MAGAZINE_SIZE),
        'shelves' => $shelvesList
    ];
}

// ──────────────────────────────────────────────
// Free Shelves (for $modalSheet)
// ──────────────────────────────────────────────
$freeShelves = [];
$freeByMag   = [];
for ($n = 1; $n <= $modalCapacity; $n++) {
    if (empty($takenBy[$n])) {
        $freeShelves[] = $n;
        $mIdx = intdiv($n - 1, MAGAZINE_SIZE) + 1;
        $freeByMag[$mIdx][] = [
            'no'    => $n,
            'shelf' => $modalSp . $n
        ];
    }
}

// Next code
$nextCode = '';
try {
    $nextCode = nextNumberCode($pdo, $modalSheet);
} catch (RuntimeException $e) {
    $nextCode = '';
}

// Magazine options for Shelf filter
$magOptions = [];
for ($mg = 1; $mg <= $magCount; $mg++) {
    $magOptions[] = [
        'value' => "M$mg",
        'label' => magazineTitle($mg)
    ];
}

[$nextFrom, $nextTo] = magazineRange($modalMagCount + 1);

echo json_encode([
    'success' => true,
    'data' => [
        'sheet'        => $sheet,
        'shelf_prefix' => $sp,
        'kpi' => [
            'total'        => $total,
            'g'            => $cnt['G'],
            'd'            => $cnt['D'],
            'gd'           => $cnt['GD'],
            'pct_g'        => $pctG,
            'capacity'     => $capacity,
            'used'         => $usedShelves,
            'util'         => $util,
            'max_shelf'    => $maxShelf,
            'shelf_range'  => $shelfRangeText
        ],
        'tab_counts'   => $tabCounts,
        'rack' => [
            'mag_count'      => $modalMagCount,
            'is_max'         => $isMax,
            'capacity'       => $modalCapacity,
            'used_shelves'   => $modalUsedShelves,
            'magazines'      => $magazines,
            'next_range'     => [$nextFrom, $nextTo],
            'can_add_mag'    => ($modalMagCount < MAGAZINE_LIMIT)
        ],
        'free_shelves'  => $freeShelves,
        'free_by_mag'   => $freeByMag,
        'next_code'     => $nextCode,
        'mag_options'   => $magOptions,
        'defect_labels' => DEFECT_LABELS
    ]
], JSON_UNESCAPED_UNICODE);

