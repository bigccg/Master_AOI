<?php
/**
 * API Endpoint: Server-side data for Grid.js
 * GET /api/boards.php?sheet=...&limit=...&offset=...&search=...&sort=...&dir=...&board_type=...&status=...&other=...&shelf=...
 * Returns JSON: { data: [...], total: int, page: int, pages: int }
 * Works with both MySQL and SQLite
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// ──────────────────────────────────────────────
// Request parameters
// ──────────────────────────────────────────────

$sheet   = qs('sheet', DEFAULT_SHEET);
if ($sheet !== 'ALL' && !isset(SHEETS[$sheet])) $sheet = DEFAULT_SHEET;

$rawLimit = qs('limit', (string)PER_PAGE);
$isAll = strtolower($rawLimit) === 'all';
if ($isAll) {
    $limit = MAX_ALL_ROWS;
    $offset = 0;
} else {
    $limit = max(1, min(500, (int)$rawLimit));
    $offset = max(0, (int)qs('offset', '0'));
}
$page   = (int)floor($offset / $limit) + 1;

$search     = trim(qs('search', qs('q')));
$sort       = qs('sort', 'shelf_no');
$dir        = (qs('dir', 'asc') === 'desc') ? 'DESC' : 'ASC';
$boardTypeF = qs('board_type', 'ALL');
$statusF    = qs('status', 'ALL');
$otherF     = qs('other', 'ALL');
$shelfF     = qs('shelf', 'ALL');
$isMatrix   = (qs('matrix') === '1' || qs('mode') === 'matrix');

// ──────────────────────────────────────────────
// Validate sort column (security)
// ──────────────────────────────────────────────

$allowedSort = [
    'code'        => 'code',
    'shelf'       => 'shelf_no',
    'product'     => 'product',
    'side'        => 'side',
    'board_type'  => 'board_type',
    'status'      => 'status',
    'pcs_status'  => 'pcs_status',
    'created_at'  => 'created_at',
    'id'          => 'id',
];
// When viewing All Zones (sheet = ALL), order chronologically from oldest (top) to newest (bottom),
// independent of zone (sheet), Number Code, or shelf_no.
if ($sheet === 'ALL') {
    $orderBy = 'created_at ASC, id ASC';
} else {
    $orderBy = ($allowedSort[$sort] ?? 'shelf_no') . " $dir, id ASC";
}

// ──────────────────────────────────────────────
// Build WHERE clause
// ──────────────────────────────────────────────

$where  = [];
$params = [];

if ($sheet !== 'ALL') {
    $where[] = 'sheet = :sheet';
    $params[':sheet'] = $sheet;
}

// Defect Matrix mode: only boards with Defect (D or GD)
if ($isMatrix) {
    $where[] = "status IN ('D', 'GD')";
}

// Keyword search (Product Model only)
if ($search !== '') {
    $where[] = 'product LIKE :q';
    $params[':q'] = "%$search%";
}

// Board Type filter
if (in_array($boardTypeF, BOARD_TYPES, true)) {
    $where[] = 'board_type = :board_type';
    $params[':board_type'] = $boardTypeF;
}

// G+D (Status) filter
if (in_array($statusF, ['G', 'D', 'GD'], true)) {
    $where[] = 'status = :status';
    $params[':status'] = $statusF;
}

// OTHER (pcs_status) filter
if (in_array($otherF, OTHER_OPTIONS, true)) {
    $where[] = 'pcs_status = :other';
    $params[':other'] = $otherF;
} elseif ($otherF === 'NONE') {
    $where[] = "(pcs_status = '' OR pcs_status IS NULL)";
}

// Shelf range filter (M1 = Magazine 1st 1-50, M2 = 51-100, ...; legacy B1-B10 style keys still accepted)
if ($range = shelfFilterRange($shelfF)) {
    $where[] = 'shelf_no BETWEEN :s1 AND :s2';
    [$params[':s1'], $params[':s2']] = $range;
}

$whereSql = $where ? implode(' AND ', $where) : '1=1';

$pdo = db();
$isSQLite = str_starts_with($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'sqlite');

// ──────────────────────────────────────────────
// Defect Matrix mode response
// ──────────────────────────────────────────────

if ($isMatrix) {
    $matrixOrderBy = ($sheet === 'ALL') ? 'created_at ASC, id ASC' : 'shelf_no ASC, id ASC';
    $matrixSql = "
        SELECT id, sheet, code, shelf, shelf_no, product, status, defects, created_at
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
    foreach ($rows as $r) {
        $parsed = parseDefects($r['defects'] ?? '');
        $matrixData[] = [
            'id'         => (int)$r['id'],
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
    }

    echo json_encode([
        'success'       => true,
        'data'          => $matrixData,
        'total'         => count($matrixData),
        'defect_labels' => DEFECT_LABELS,
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

// SQLite doesn't support named params for LIMIT/OFFSET in some versions
// Use positional params for LIMIT/OFFSET, named for the rest
if ($isSQLite) {
    $sql = "
        SELECT id, code, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, created_at
        FROM boards
        WHERE $whereSql
        ORDER BY $orderBy
        LIMIT ? OFFSET ?
    ";
    $st = $pdo->prepare($sql);
    // Bind WHERE params first
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    // Then LIMIT/OFFSET as positional (1-indexed after named params)
    $paramCount = count($params);
    $st->bindValue($paramCount + 1, $limit, PDO::PARAM_INT);
    $st->bindValue($paramCount + 2, $offset, PDO::PARAM_INT);
} else {
    $sql = "
        SELECT id, code, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, created_at
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
// Transform to Grid.js server-side format
// data = array of arrays (column order matches frontend)
// ──────────────────────────────────────────────

$data = [];
foreach ($rows as $r) {
    $magSlotText = magazineSlotDisplay($r);

    $data[] = [
        $r['code'],                           // Number Code
        $r['shelf'],                          // Shelf
        $r['product'],                        // Product Model
        $r['side'],                           // Side
        $r['board_type'],                     // Board Type
        $r['status'],                         // Status (G / D / GD) -> single "G+D" column
        $r['pcs_status'],                     // OTHER
        $magSlotText,                         // Magazine / Slot
        (string)$r['id'],                     // Actions cell: carries the row id used by fmtActions
    ];
}

// ──────────────────────────────────────────────
// Response
// ──────────────────────────────────────────────

echo json_encode([
    'data'  => $data,
    'total' => $total,
    'truncated' => $isAll && $total > MAX_ALL_ROWS,
    'page'  => $page,
    'pages' => $pages,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);