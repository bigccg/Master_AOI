<?php
/**
 * API Endpoint: Server-side data for Data Table & Defect Matrix
 * GET /api/boards.php?zone=...&sheet=...&limit=...&offset=...&search=...&sort=...&dir=...&board_type=...&status=...&other=...&shelf=...
 * Returns JSON: { data: [...], total: int, page: int, pages: int }
 * Works with both MySQL and SQLite
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// ──────────────────────────────────────────────
// Request parameters
// ──────────────────────────────────────────────

$zone          = normalizeZoneView(qs('zone', DEFAULT_ZONE));   // ALL = every zone that has shelves
$isAllZones    = isAllZones($zone);
$viewZones     = viewZones($zone);
$hasZoneSheets = $isAllZones ? !empty($viewZones) : zoneHasSheets($zone, true);   // disabled sheets still show their records
$sheet         = $isAllZones ? 'ALL' : resolveSheetInZone($zone, qs('sheet', 'ALL'), 'ALL');

$rawLimit = qs('limit', (string)PER_PAGE);
$isAll    = strtolower($rawLimit) === 'all';
if ($isAll) {
    $limit  = MAX_ALL_ROWS;
    $offset = 0;
} else {
    $limit  = max(1, min(500, (int)$rawLimit));
    $offset = max(0, (int)qs('offset', '0'));
}
$page = (int)floor($offset / $limit) + 1;

$search     = trim(qs('search', qs('q')));
$sort       = qs('sort', 'shelf_no');
$dir        = (qs('dir', 'asc') === 'desc') ? 'DESC' : 'ASC';
$boardTypeF = qs('board_type', 'ALL');
$statusF    = qs('status', 'ALL');
$otherF     = qs('other', 'ALL');
$shelfF     = qs('shelf', 'ALL');
$periodF    = qs('period', 'ALL');
if ($periodF !== 'ALL' && $periodF !== 'THIS' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodF)) {
    $periodF = 'ALL';
}
$isMatrix   = (qs('matrix') === '1' || qs('mode') === 'matrix');

// ──────────────────────────────────────────────
// Validate sort column (security)
// ──────────────────────────────────────────────

$allowedSort = [
    'code'       => 'code',
    'shelf'      => 'shelf_no',
    'product'    => 'product',
    'side'       => 'side',
    'board_type' => 'board_type',
    'status'     => 'status',
    'pcs_status' => 'pcs_status',
    'created_at' => 'created_at',
    'id'         => 'id',
];
// When viewing All Sheets (sheet = ALL) within the selected zone or across All Zones,
// order chronologically from oldest (top) to newest (bottom), independent of zone, sheet, or shelf_no.
if ($sheet === 'ALL') {
    $orderBy = 'created_at ASC, id ASC';
} else {
    $orderBy = ($allowedSort[$sort] ?? 'shelf_no') . " $dir, id ASC";
}

// ──────────────────────────────────────────────
// Build WHERE clause (always scoped to $zone)
// ──────────────────────────────────────────────

if ($isAllZones) {
    [$zoneSql, $params] = zoneInClause($viewZones);
    $where = [$zoneSql];
} else {
    $where  = ['zone = :zone'];
    $params = [':zone' => $zone];
}

if (!$hasZoneSheets) {
    $where[] = '1 = 0';
} elseif ($sheet !== 'ALL') {
    $where[]          = 'sheet = :sheet';
    $params[':sheet'] = $sheet;
}

// Defect Matrix mode: only boards with Defect (D or GD)
if ($isMatrix) {
    $where[] = "status IN ('D', 'GD')";
}

// Keyword search (Product Model only)
if ($search !== '') {
    $where[]      = 'product LIKE :q';
    $params[':q'] = "%$search%";
}

// Board Type filter
if (in_array($boardTypeF, BOARD_TYPES, true)) {
    $where[]               = 'board_type = :board_type';
    $params[':board_type'] = $boardTypeF;
}

// G+D (Status) filter
if (in_array($statusF, ['G', 'D', 'GD'], true)) {
    $where[]           = 'status = :status';
    $params[':status'] = $statusF;
}

// OTHER (pcs_status) filter
if (in_array($otherF, OTHER_OPTIONS, true)) {
    $where[]          = 'pcs_status = :other';
    $params[':other'] = $otherF;
} elseif ($otherF === 'NONE') {
    $where[] = "(pcs_status = '' OR pcs_status IS NULL)";
}

// Shelf range filter (M1 = Magazine 1st 1-50, M2 = 51-100, ...; legacy B1-B10 style keys still accepted)
if ($range = shelfFilterRange($shelfF)) {
    $where[] = 'shelf_no BETWEEN :s1 AND :s2';
    [$params[':s1'], $params[':s2']] = $range;
}

// Period filter (created_at range)
if ($pRange = periodRange($periodF)) {
    $where[] = 'created_at >= :p_from AND created_at < :p_to';
    $params[':p_from'] = $pRange[0];
    $params[':p_to']   = $pRange[1];
}

$whereSql = implode(' AND ', $where);

$pdo      = db();
$isSQLite = str_starts_with($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'sqlite');

// ──────────────────────────────────────────────
// Defect Matrix mode response
// ──────────────────────────────────────────────

if ($isMatrix) {
    $matrixOrderBy = ($sheet === 'ALL') ? 'created_at ASC, id ASC' : 'shelf_no ASC, id ASC';
    $matrixSql = "
        SELECT id, zone, sheet, code, shelf, shelf_no, product, status, defects, created_at
        FROM boards
        WHERE $whereSql
        ORDER BY $matrixOrderBy
        LIMIT " . (int)MAX_ALL_ROWS . "
    ";
    $st = $pdo->prepare($matrixSql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->execute();
    $rows = $st->fetchAll();

    $matrixData = [];
    $byZone     = array_fill_keys($viewZones, []);   // All Zones: one group per zone, in zone order
    foreach ($rows as $r) {
        $parsed = parseDefects($r['defects'] ?? '');
        $matrixRow = [
            'id'         => (int)$r['id'],
            'zone'       => (string)($r['zone'] ?? $zone),
            'sheet'      => (string)$r['sheet'],
            'code'       => (string)$r['code'],
            'shelf'      => (string)$r['shelf'],
            'shelf_no'   => (int)$r['shelf_no'],
            'product'    => (string)$r['product'],
            'status'     => (string)$r['status'],
            'defects'    => $parsed['codes'],
            'other'      => $parsed['other'],
            'other_text' => $parsed['other_text'],
        ];
        $matrixData[] = $matrixRow;
        if (isset($byZone[$matrixRow['zone']])) {
            $byZone[$matrixRow['zone']][] = $matrixRow;
        }
    }

    $groups = [];
    foreach ($byZone as $zKey => $zRows) {
        $groups[] = [
            'zone'  => (string)$zKey,
            'label' => zoneLabel((string)$zKey),
            'total' => count($zRows),
            'data'  => $zRows,
        ];
    }

    echo json_encode([
        'success'        => true,
        'zone'           => $zone,
        'sheet'          => $sheet,
        'has_sheets'     => $hasZoneSheets,
        'groups'         => $groups,
        'data'           => $matrixData,
        'total'          => count($matrixData),
        'defect_labels'  => DEFECT_LABELS,
        'period_options' => periodOptions($pdo, $zone, $sheet),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ──────────────────────────────────────────────
// Total count (for pagination)
// ──────────────────────────────────────────────

$st = $pdo->prepare("SELECT COUNT(*) FROM boards WHERE $whereSql");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $limit));

// ──────────────────────────────────────────────
// Fetch page data
// ──────────────────────────────────────────────

if ($isSQLite) {
    $sql = "
        SELECT id, zone, sheet, code, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, created_at
        FROM boards
        WHERE $whereSql
        ORDER BY $orderBy
        LIMIT ? OFFSET ?
    ";
    $st = $pdo->prepare($sql);
    $idx = 1;
    // When using named + positional in SQLite, replace named params or bind cleanly via array
    $sqlPos = $sql;
    $posParams = [];
    // Simpler: use named :lim and :off with PDO::PARAM_INT, which PHP PDO SQLite supports
    $sqlNamed = "
        SELECT id, zone, sheet, code, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, created_at
        FROM boards
        WHERE $whereSql
        ORDER BY $orderBy
        LIMIT :lim OFFSET :off
    ";
    $st = $pdo->prepare($sqlNamed);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
} else {
    $sql = "
        SELECT id, zone, sheet, code, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, created_at
        FROM boards
        WHERE $whereSql
        ORDER BY $orderBy
        LIMIT :lim OFFSET :off
    ";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':lim', $limit,  PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
}
$st->execute();
$rows = $st->fetchAll();

// ──────────────────────────────────────────────
// Transform to table row format
// ──────────────────────────────────────────────

$data = [];
foreach ($rows as $r) {
    $magSlotText = magazineSlotDisplay($r);

    $data[] = [
        $r['code'],                           // Number Code (globally unique across sheets/zones for new records)
        $r['shelf'],                          // Shelf
        $r['product'],                        // Product Model
        $r['side'],                           // Side
        $r['board_type'],                     // Board Type
        $r['status'],                         // Status (G / D / GD) -> single "G+D" column
        $r['pcs_status'],                     // OTHER
        $magSlotText,                         // Magazine / Slot
        (string)$r['id'],                     // Actions cell: carries the row id
        (string)$r['zone'],                   // Zone (shown as a column in the All Zones view)
    ];
}

// ──────────────────────────────────────────────
// Response
// ──────────────────────────────────────────────

echo json_encode([
    'zone'           => $zone,
    'sheet'          => $sheet,
    'has_sheets'     => $hasZoneSheets,
    'data'           => $data,
    'total'          => $total,
    'truncated'      => $isAll && $total > MAX_ALL_ROWS,
    'page'           => $page,
    'pages'          => $pages,
    'period_options' => periodOptions($pdo, $zone, $sheet),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);