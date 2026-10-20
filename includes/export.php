<?php
/**
 * GET ?export=1 -> CSV download of the current zone + sheet + filters (exits).
 */

declare(strict_types=1);

// ──────────────────────────────────────────────
// Export CSV (filtered by current Zone and Sheet)
// ──────────────────────────────────────────────

if (isset($_GET['export'])) {
    $where  = ['zone = :zone'];
    $params = [':zone' => $zone];

    if (!$hasZoneSheets) {
        $where[] = '1 = 0';
    } elseif (!$isAllSheets) {
        $where[]          = 'sheet = :sheet';
        $params[':sheet'] = $sheet;
    }

    if ($q !== '') {
        $where[]      = 'product LIKE :q';
        $params[':q'] = "%$q%";
    }
    if (in_array($boardTypeF, BOARD_TYPES, true)) {
        $where[]               = 'board_type = :board_type';
        $params[':board_type'] = $boardTypeF;
    }
    if (in_array($statusF, ['G', 'D', 'GD'], true)) {
        $where[]           = 'status = :status';
        $params[':status'] = $statusF;
    }
    if (in_array($otherF, OTHER_OPTIONS, true)) {
        $where[]          = 'pcs_status = :other';
        $params[':other'] = $otherF;
    }
    if ($range = shelfFilterRange($shelfF)) {
        $where[] = 'shelf_no BETWEEN :s1 AND :s2';
        [$params[':s1'], $params[':s2']] = $range;
    }
    $whereSql = implode(' AND ', $where);

    $sortCols = ['shelf' => 'shelf_no', 'code' => 'code', 'product' => 'product'];
    $orderBy  = $isAllSheets
        ? 'created_at ASC, id ASC'
        : (($sortCols[$sort] ?? 'shelf_no') . " $dir, id ASC");

    $st = $pdo->prepare("SELECT code, zone, sheet, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, created_at FROM boards WHERE $whereSql ORDER BY $orderBy");
    $st->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aoi_' . $zone . '_' . $sheet . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM for Thai Excel
    fputcsv($out, ['Number Code', 'Shelf', 'Product Model', 'Side', 'Board Type', 'Status', 'PCS P/D', 'Magazine / Slot', 'Created At']);
    while ($r = $st->fetch()) {
        $magSlotText = magazineSlotDisplay($r);
        fputcsv($out, [
            $r['code'],
            $r['shelf'],
            $r['product'],
            $r['side'],
            $r['board_type'],
            $r['status'],
            $r['pcs_status'],
            $magSlotText,
            $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}
