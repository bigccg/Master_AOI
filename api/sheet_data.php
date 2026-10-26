<?php
/**
 * API Endpoint: Get complete zone + sheet metadata, KPIs, Rack Map, and Free Shelves (AJAX)
 * GET /api/sheet_data.php?zone=...&sheet=...
 * Returns JSON: { success: bool, data: { ... } }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$zone              = normalizeZoneView(qs('zone', DEFAULT_ZONE));   // ALL = the "All Zones" view
$isAllZones        = isAllZones($zone);
$pdo               = db();
$allZonesKpi       = $isAllZones ? allZonesKpi($pdo) : null;
if ($isAllZones) {
    $currentZoneSheets = [];
    $zoneSheetKeys     = $allZonesKpi['sheetKeys'];
} else {
    $currentZoneSheets = zoneSheets($zone);              // enabled sheets = the sheet tabs
    $zoneSheetKeys     = array_keys(zoneSheets($zone, true));   // + disabled ones: their records still count and open
}
$hasZoneSheets     = !empty($zoneSheetKeys);

// Validate sheet within the selected zone; if not in zone (or deleted), fallback to 'ALL' of that zone
$sheet       = $isAllZones ? 'ALL' : resolveSheetInZone($zone, qs('sheet', 'ALL'), 'ALL');
$isAllSheets = ($sheet === 'ALL');
$modalSheet  = $isAllZones ? '' : ($isAllSheets ? defaultSheetForZone($zone) : $sheet);   // All Zones: no rack, nothing to add to

$sp  = $isAllSheets ? 'ALL' : ($sheet !== '' ? shelfPrefix($sheet) : '');

// ──────────────────────────────────────────────
// Tab counts (scoped strictly to the selected Zone): every sheet incl. disabled ones, + ALL
// ──────────────────────────────────────────────
$tabCounts = array_fill_keys($zoneSheetKeys, 0);
if ($hasZoneSheets && !$isAllZones) {
    $tcSt = $pdo->prepare('SELECT sheet, COUNT(*) c FROM boards WHERE zone = ? GROUP BY sheet');
    $tcSt->execute([$zone]);
    foreach ($tcSt as $r) {
        if (isset($tabCounts[$r['sheet']])) {
            $tabCounts[$r['sheet']] = (int)$r['c'];
        }
    }
}
$tabCounts['ALL'] = $isAllZones ? $allZonesKpi['total'] : array_sum($tabCounts);

// Build zone_sheets array for dynamic tab rendering on the frontend
$zoneSheetsList = [];
foreach ($currentZoneSheets as $k => [$label, $dot]) {
    $zoneSheetsList[] = [
        'key'   => $k,
        'label' => $label,
        'dot'   => $dot,
        'count' => $tabCounts[$k] ?? 0,
    ];
}

// ──────────────────────────────────────────────
// KPI (scoped to the selected Zone)
// ──────────────────────────────────────────────
$cnt = ['G' => 0, 'D' => 0, 'GD' => 0];
if (!$hasZoneSheets) {
    $total          = 0;
    $pctG           = 0;
    $magCount       = 0;
    $capacity       = 0;
    $usedShelves    = 0;
    $util           = 0;
    $maxShelf       = 0;
    $shelfRangeText = 'ยังไม่มีชีตในโซนนี้ / No sheets in this zone';
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
    $total = $tabCounts['ALL'];   // every board in this zone, incl. Other = Bare / Bad Mark
    $pctG  = $total ? (int)round($cnt['G'] / $total * 100) : 0;

    $maxMagCount      = 1;
    $totalCapacity    = 0;
    $totalUsedShelves = 0;
    foreach ($zoneSheetKeys as $shKey) {
        $shMag = magazineCount($pdo, $shKey);
        if ($shMag > $maxMagCount) $maxMagCount = $shMag;
        $shCap = $shMag * MAGAZINE_SIZE;
        $totalCapacity += $shCap;
        $uSt = $pdo->prepare('SELECT COUNT(DISTINCT shelf_no) FROM boards WHERE zone = ? AND sheet = ? AND shelf_no BETWEEN 1 AND ?');
        $uSt->execute([$zone, $shKey, $shCap]);
        $totalUsedShelves += (int)$uSt->fetchColumn();
    }
    $magCount       = $maxMagCount;
    $capacity       = max(1, $totalCapacity);
    $usedShelves    = $totalUsedShelves;
    $util           = min(100, (int)round($usedShelves / $capacity * 100));
    $maxShelfStmt   = $pdo->prepare('SELECT COALESCE(MAX(shelf_no), 0) FROM boards WHERE zone = ?');
    $maxShelfStmt->execute([$zone]);
    $maxShelf       = (int)$maxShelfStmt->fetchColumn();
    $shelfRangeText = $total ? allSheetsRangeLabel($zone) : 'No boards yet';
} else {
    $st = $pdo->prepare('SELECT status, COUNT(*) c FROM boards WHERE zone = ? AND sheet = ? GROUP BY status');
    $st->execute([$zone, $sheet]);
    foreach ($st as $r) {
        if (isset($cnt[$r['status']])) {
            $cnt[$r['status']] = (int)$r['c'];
        }
    }
    $total       = $tabCounts[$sheet] ?? array_sum($cnt);
    $pctG        = $total ? (int)round($cnt['G'] / $total * 100) : 0;
    $magCount    = magazineCount($pdo, $sheet);
    $capacity    = max(1, $magCount * MAGAZINE_SIZE);
    $util        = min(100, (int)round($total / $capacity * 100));

    $maxShelfStmt = $pdo->prepare('SELECT COALESCE(MAX(shelf_no), 0) FROM boards WHERE zone = ? AND sheet = ?');
    $maxShelfStmt->execute([$zone, $sheet]);
    $maxShelf       = (int)$maxShelfStmt->fetchColumn();
    $shelfRangeText = $total ? "Shelf {$sp}1 - {$sp}{$maxShelf}" : 'No boards yet';
}

// ──────────────────────────────────────────────
// Rack Map & Modal Free Shelves (for $modalSheet when zone has sheets)
// ──────────────────────────────────────────────
$rack        = [];
$rackInfo    = [];
$takenBy     = [];
$magazines   = [];
$freeShelves = [];
$freeByMag   = [];

if ($hasZoneSheets && $modalSheet !== '') {
    $modalSp       = shelfPrefix($modalSheet);
    $modalMagCount = magazineCount($pdo, $modalSheet);
    $modalCapacity = $modalMagCount * MAGAZINE_SIZE;

    $st = $pdo->prepare('SELECT id, shelf, shelf_no, status, pcs_status, code, product FROM boards WHERE zone = ? AND sheet = ?');
    $st->execute([$zone, $modalSheet]);
    foreach ($st as $r) {
        $no = (int)$r['shelf_no'];
        $rack[$no]     = $r['status'];
        $rackInfo[$no] = [
            'id'         => (int)$r['id'],
            'shelf'      => $r['shelf'],
            'code'       => $r['code'],
            'product'    => $r['product'],
            'status'     => $r['status'],
            'pcs_status' => $r['pcs_status'],
        ];
        $takenBy[$no][] = (int)$r['id'];
    }

    $modalUsedShelves = count(array_filter(array_keys($takenBy), fn($k) => $k >= 1 && $k <= $modalCapacity));
    $isMax            = ($modalUsedShelves >= $modalCapacity);
    if (!$isAllSheets) {
        $usedShelves = $modalUsedShelves;
        $util        = min(100, (int)round($usedShelves / max(1, $capacity) * 100));
    }

    for ($mg = 1; $mg <= $modalMagCount; $mg++) {
        [$from, $to] = magazineRange($mg);
        $mgUsed      = 0;
        $shelvesList = [];
        for ($n = $from; $n <= $to; $n++) {
            $occupied = isset($rack[$n]);
            if ($occupied) $mgUsed++;
            $shelvesList[] = [
                'no'       => $n,
                'shelf'    => $modalSp . $n,
                'occupied' => $occupied,
                'status'   => $rack[$n] ?? null,
                'data'     => $rackInfo[$n] ?? null,
            ];
        }
        $magazines[] = [
            'mag'     => $mg,
            'title'   => magazineTitle($mg),
            'from'    => $from,
            'to'      => $to,
            'used'    => $mgUsed,
            'full'    => ($mgUsed >= MAGAZINE_SIZE),
            'shelves' => $shelvesList,
        ];
    }

    for ($n = 1; $n <= $modalCapacity; $n++) {
        if (empty($takenBy[$n])) {
            $freeShelves[] = $n;
            $mIdx = intdiv($n - 1, MAGAZINE_SIZE) + 1;
            $freeByMag[$mIdx][] = [
                'no'    => $n,
                'shelf' => $modalSp . $n,
            ];
        }
    }
} else {
    $modalSp          = '';
    $modalMagCount    = 0;
    $modalCapacity    = 0;
    $modalUsedShelves = 0;
    $isMax            = false;
}

// Next system-wide Number Code
$nextCode = '';
try {
    $nextCode = nextNumberCode($pdo);
} catch (RuntimeException $e) {
    $nextCode = '';
}

// Magazine options for Shelf filter
$magOptions = [];
for ($mg = 1; $mg <= $magCount; $mg++) {
    $magOptions[] = [
        'value' => "M$mg",
        'label' => magazineTitle($mg),
    ];
}

[$nextFrom, $nextTo] = magazineRange($modalMagCount + 1);

echo json_encode([
    'success' => true,
    'data' => [
        'zone'         => $zone,
        'zone_label'   => zoneLabel($zone),
        'all_zones'    => $isAllZones,
        'has_sheets'   => $hasZoneSheets,
        'zone_sheets'  => $zoneSheetsList,
        'sheet'        => $sheet,
        'shelf_prefix' => $sp,
        'kpi' => [
            'total'       => $total,
            'g'           => $cnt['G'],
            'd'           => $cnt['D'],
            'gd'          => $cnt['GD'],
            'pct_g'       => $pctG,
            'capacity'    => $capacity,
            'used'        => $usedShelves,
            'util'        => $util,
            'max_shelf'   => $maxShelf,
            'shelf_range' => $shelfRangeText,
        ],
        'tab_counts' => $tabCounts,
        'zone_counts' => $isAllZones ? $allZonesKpi['zoneCounts'] : (object)[],
        'rack' => [
            'mag_count'    => $modalMagCount,
            'is_max'       => $isMax,
            'capacity'     => $modalCapacity,
            'used_shelves' => $modalUsedShelves,
            'magazines'    => $magazines,
            'next_range'   => [$nextFrom, $nextTo],
            'can_add_mag'  => ($hasZoneSheets && $modalMagCount < MAGAZINE_LIMIT && sheetIsActive($modalSheet)),
            'add_mag_note' => ($modalSheet !== '' && !sheetIsActive($modalSheet)) ? INACTIVE_SHEET_MAGAZINE_ERROR : '',
        ],
        'free_shelves'  => $freeShelves,
        'free_by_mag'   => $freeByMag,
        'next_code'     => $nextCode,
        'mag_options'   => $magOptions,
        'defect_labels' => DEFECT_LABELS,
    ],
], JSON_UNESCAPED_UNICODE);
