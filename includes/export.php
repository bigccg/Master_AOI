<?php
/**
 * Export Station & Download Engine
 * - GET ?export_preview=1 -> JSON live preview counts & available period/zone/sheet metadata for the Export Station UI.
 * - GET ?export=1&format=xlsx -> Organized Multi-Sheet Excel (.xlsx) Workbook (Master Boards + Defect Matrix).
 * - GET ?export=1 (or format=csv) -> CSV download (100% backward compatible with CLI & automated tests).
 */

declare(strict_types=1);

require_once __DIR__ . '/xlsx-writer.php';

if ((isset($_GET['action']) && $_GET['action'] === 'import_template') || isset($_GET['import_template'])) {
    require __DIR__ . '/../api/import_template.php';
    exit;
}

/**
 * Build SQL WHERE clause, bind parameters, and human-readable summary from Export Station parameters.
 */
function buildExportFilterQuery(
    PDO $pdo,
    string $fallbackZone,
    string $fallbackSheet,
    string $fallbackQ,
    string $fallbackBoardType,
    string $fallbackStatus,
    string $fallbackOther,
    string $fallbackShelf,
    string $fallbackPeriod
): array {
    $topo       = topology($pdo);
    $allZones   = array_keys($topo['zones']);
    $activeZones= allZonesWithSheets();

    // 1. Zones filter (supports multi-select `zones=General,AIXZ` or `ALL`, or legacy `zone`)
    $rawZones = trim((string)($_GET['zones'] ?? ''));
    $selectedZones = [];
    $isAllZonesSelected = false;

    if ($rawZones !== '') {
        if (strtoupper($rawZones) === 'ALL') {
            $isAllZonesSelected = true;
            $selectedZones = $activeZones ?: $allZones;
        } else {
            foreach (explode(',', $rawZones) as $zk) {
                $zk = trim($zk);
                if ($zk !== '' && isset($topo['zones'][$zk]) && !in_array($zk, $selectedZones, true)) {
                    $selectedZones[] = $zk;
                }
            }
            if (empty($selectedZones)) {
                $isAllZonesSelected = true;
                $selectedZones = $activeZones ?: $allZones;
            }
        }
    } else {
        if (isAllZones($fallbackZone)) {
            $isAllZonesSelected = true;
            $selectedZones = $activeZones;
        } else {
            $selectedZones = [$fallbackZone];
        }
    }

    [$zoneSql, $params] = zoneInClause($selectedZones, 'zone', ':ez');
    $where = [$zoneSql];

    // 2. Sheets / Sizes filter (supports multi-select `sheets=B200_Gen,A150_Gen`, `sizes=B,A,CD`, or `ALL`)
    $rawSheets = trim((string)($_GET['sheets'] ?? ''));
    $rawSizes  = trim((string)($_GET['sizes'] ?? ''));
    $selectedSheets = [];
    $selectedSizes  = [];
    $isAllSheetsSelected = true;

    if ($rawSheets !== '' && strtoupper($rawSheets) !== 'ALL') {
        foreach (explode(',', $rawSheets) as $shk) {
            $shk = trim($shk);
            if ($shk !== '' && isset($topo['sheets'][$shk]) && !in_array($shk, $selectedSheets, true)) {
                $selectedSheets[] = $shk;
            }
        }
        if (!empty($selectedSheets)) {
            $isAllSheetsSelected = false;
            [$shSql, $shParams] = zoneInClause($selectedSheets, 'sheet', ':esh');
            $where[] = $shSql;
            $params  = array_merge($params, $shParams);
        }
    } elseif ($rawSizes !== '' && strtoupper($rawSizes) !== 'ALL') {
        foreach (explode(',', $rawSizes) as $sz) {
            $sz = strtoupper(trim($sz));
            if ($sz !== '' && !in_array($sz, $selectedSizes, true)) {
                $selectedSizes[] = $sz;
            }
        }
        if (!empty($selectedSizes)) {
            $matchingSheetKeys = [];
            foreach ($topo['sheets'] as $shKey => $shMeta) {
                if (in_array(strtoupper((string)$shMeta['prefix']), $selectedSizes, true)) {
                    $matchingSheetKeys[] = (string)$shKey;
                }
            }
            if (!empty($matchingSheetKeys)) {
                $isAllSheetsSelected = false;
                [$shSql, $shParams] = zoneInClause($matchingSheetKeys, 'sheet', ':esz');
                $where[] = $shSql;
                $params  = array_merge($params, $shParams);
            } else {
                $where[] = '1 = 0';
            }
        }
    } elseif ($rawSheets === '' && $rawSizes === '' && $fallbackSheet !== 'ALL' && !$isAllZonesSelected) {
        $isAllSheetsSelected = false;
        $where[]          = 'sheet = :sheet';
        $params[':sheet'] = $fallbackSheet;
        $selectedSheets   = [$fallbackSheet];
    }

    // 3. Year & Month filter (supports `year=2026`, `months=10,11` or `month=10`, or legacy `period=2026-10`)
    $rawYear   = trim((string)($_GET['year'] ?? ''));
    $rawMonths = trim((string)($_GET['months'] ?? ($_GET['month'] ?? '')));
    $periodSummary = 'ทุกช่วงเวลา (All Time)';

    $thaiMonths = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
    ];

    if ($rawYear !== '' && strtoupper($rawYear) !== 'ALL' && preg_match('/^\d{4}$/', $rawYear)) {
        $where[] = "SUBSTR(created_at, 1, 4) = :eyear";
        $params[':eyear'] = $rawYear;

        $validMonths = [];
        if ($rawMonths !== '' && strtoupper($rawMonths) !== 'ALL') {
            foreach (explode(',', $rawMonths) as $mStr) {
                $mInt = (int)trim($mStr);
                if ($mInt >= 1 && $mInt <= 12) {
                    $mPad = str_pad((string)$mInt, 2, '0', STR_PAD_LEFT);
                    if (!in_array($mPad, $validMonths, true)) {
                        $validMonths[] = $mPad;
                    }
                }
            }
        }
        if (!empty($validMonths)) {
            [$mSql, $mParams] = zoneInClause($validMonths, "SUBSTR(created_at, 6, 2)", ':emo');
            $where[] = $mSql;
            $params  = array_merge($params, $mParams);
            $mLabels = array_map(static fn(string $mp): string => $thaiMonths[(int)$mp] ?? $mp, $validMonths);
            $periodSummary = implode(', ', $mLabels) . ' ' . $rawYear;
        } else {
            $periodSummary = 'ปี ' . $rawYear . ' (ทุกเดือน)';
        }
    } elseif ($rawMonths !== '' && strtoupper($rawMonths) !== 'ALL') {
        $validMonths = [];
        foreach (explode(',', $rawMonths) as $mStr) {
            $mInt = (int)trim($mStr);
            if ($mInt >= 1 && $mInt <= 12) {
                $mPad = str_pad((string)$mInt, 2, '0', STR_PAD_LEFT);
                if (!in_array($mPad, $validMonths, true)) {
                    $validMonths[] = $mPad;
                }
            }
        }
        if (!empty($validMonths)) {
            [$mSql, $mParams] = zoneInClause($validMonths, "SUBSTR(created_at, 6, 2)", ':emo');
            $where[] = $mSql;
            $params  = array_merge($params, $mParams);
            $mLabels = array_map(static fn(string $mp): string => $thaiMonths[(int)$mp] ?? $mp, $validMonths);
            $periodSummary = 'เดือน ' . implode(', ', $mLabels) . ' (ทุกปี)';
        }
    } elseif ($pRange = periodRange($fallbackPeriod)) {
        $where[] = 'created_at >= :p_from AND created_at < :p_to';
        $params[':p_from'] = $pRange[0];
        $params[':p_to']   = $pRange[1];
        $periodSummary = $fallbackPeriod;
    }

    // 4. Keyword search
    if ($fallbackQ !== '') {
        $where[]      = 'product LIKE :q';
        $params[':q'] = "%$fallbackQ%";
    }

    // 5. Board Type filter
    if (in_array($fallbackBoardType, BOARD_TYPES, true)) {
        $where[]               = 'board_type = :board_type';
        $params[':board_type'] = $fallbackBoardType;
    }

    // 6. Status (G / D / G+D / DEFECT_ALL) filter
    $statusMode = strtoupper(trim((string)($_GET['status_mode'] ?? $fallbackStatus)));
    $statusSummary = 'ทุกสถานะ';
    if (in_array($statusMode, ['G', 'D', 'GD'], true)) {
        $where[]           = 'status = :status';
        $params[':status'] = $statusMode;
        $statusSummary     = $statusMode === 'GD' ? 'Mixed (G+D)' : $statusMode;
    } elseif ($statusMode === 'DEFECT_ALL') {
        $where[]       = "status IN ('D', 'GD')";
        $statusSummary = 'เฉพาะที่มี Defect (D + G+D)';
    }

    // 7. Other / Bare / Bad Mark filter (`other_mode` or legacy `other`)
    $otherMode = trim((string)($_GET['other_mode'] ?? $fallbackOther));
    $otherSummary = 'รวมทุกประเภท';
    if ($otherMode === 'Bare') {
        $where[]          = "pcs_status = :other";
        $params[':other'] = 'Bare';
        $otherSummary     = 'เฉพาะ Bare';
    } elseif ($otherMode === 'Bad Mark') {
        $where[]          = "pcs_status = :other";
        $params[':other'] = 'Bad Mark';
        $otherSummary     = 'เฉพาะ Bad Mark';
    } elseif (strtoupper($otherMode) === 'BARE_OR_BAD') {
        $where[]      = "pcs_status IN ('Bare', 'Bad Mark')";
        $otherSummary = 'เฉพาะ Bare และ Bad Mark';
    } elseif (strtoupper($otherMode) === 'NONE') {
        $where[]      = "(pcs_status = '' OR pcs_status IS NULL)";
        $otherSummary = 'ไม่รวม Bare / Bad Mark';
    }

    // 8. Shelf range filter
    if ($range = shelfFilterRange($fallbackShelf)) {
        $where[] = 'shelf_no BETWEEN :s1 AND :s2';
        [$params[':s1'], $params[':s2']] = $range;
    }

    $whereSql = implode(' AND ', $where);

    $zoneTitleLabel = ($isAllZonesSelected || count($selectedZones) > 1)
        ? (count($selectedZones) === count($activeZones) ? 'รวมทุกโซน' : 'โซน ' . implode(', ', $selectedZones))
        : ('โซน ' . ($selectedZones[0] ?? DEFAULT_ZONE));

    $sheetSummary = $isAllSheetsSelected
        ? 'ทุกขนาด (All Size Magazines)'
        : (!empty($selectedSizes)
            ? ('ขนาด ' . implode(', ', $selectedSizes))
            : ('ชั้นวาง ' . implode(', ', $selectedSheets)));

    $summaryText = sprintf(
        'โซน: %s   |   ขนาด/ชั้นวาง: %s   |   ช่วงเวลา: %s   |   สถานะ: %s   |   Other: %s   |   ส่งออกเมื่อ: %s',
        $zoneTitleLabel,
        $sheetSummary,
        $periodSummary,
        $statusSummary,
        $otherSummary,
        date('Y-m-d H:i:s')
    );

    return [
        'whereSql'            => $whereSql,
        'params'              => $params,
        'selectedZones'       => $selectedZones,
        'isAllZonesSelected'  => $isAllZonesSelected,
        'isAllSheetsSelected' => $isAllSheetsSelected,
        'zoneTitleLabel'      => $zoneTitleLabel,
        'summaryText'         => $summaryText,
    ];
}

// ──────────────────────────────────────────────
// Live Export Station Preview API (?export_preview=1)
// ──────────────────────────────────────────────

if (isset($_GET['export_preview'])) {
    $flt = buildExportFilterQuery(
        $pdo, $zone, $sheet, $q, $boardTypeF, $statusF, $otherF, $shelfF, $periodF
    );
    $wSql   = $flt['whereSql'];
    $params = $flt['params'];

    // Aggregate counts for matching selection
    $st = $pdo->prepare("
        SELECT
            COUNT(*) AS total_boards,
            SUM(CASE WHEN status = 'G' THEN 1 ELSE 0 END) AS cnt_g,
            SUM(CASE WHEN status = 'D' THEN 1 ELSE 0 END) AS cnt_d,
            SUM(CASE WHEN status = 'GD' THEN 1 ELSE 0 END) AS cnt_gd,
            SUM(CASE WHEN status IN ('D', 'GD') THEN 1 ELSE 0 END) AS matrix_rows,
            SUM(CASE WHEN pcs_status = 'Bare' THEN 1 ELSE 0 END) AS cnt_bare,
            SUM(CASE WHEN pcs_status = 'Bad Mark' THEN 1 ELSE 0 END) AS cnt_bad_mark
        FROM boards
        WHERE $wSql
    ");
    $st->execute($params);
    $agg = $st->fetch() ?: [];

    // Available Years & Months across the database (for period selector chips)
    $ymSt = $pdo->query("
        SELECT SUBSTR(created_at, 1, 4) AS yr, SUBSTR(created_at, 6, 2) AS mo, COUNT(*) AS cnt
        FROM boards
        WHERE created_at IS NOT NULL AND created_at > '1970-01-01'
        GROUP BY yr, mo
        ORDER BY yr DESC, mo DESC
    ");
    $yearsMap  = [];
    $monthsMap = [];
    foreach ($ymSt as $r) {
        $yr = (string)$r['yr'];
        $mo = (string)$r['mo'];
        if (!preg_match('/^\d{4}$/', $yr) || !preg_match('/^(0[1-9]|1[0-2])$/', $mo)) continue;
        $c = (int)$r['cnt'];
        $yearsMap[$yr]  = ($yearsMap[$yr] ?? 0) + $c;
        $monthsMap[$mo] = ($monthsMap[$mo] ?? 0) + $c;
    }

    // Per-zone & per-sheet board counts
    $zCounts  = [];
    $shCounts = [];
    foreach ($pdo->query("SELECT zone, sheet, COUNT(*) AS cnt FROM boards GROUP BY zone, sheet") as $r) {
        $zk  = (string)$r['zone'];
        $shk = (string)$r['sheet'];
        $c   = (int)$r['cnt'];
        $zCounts[$zk]   = ($zCounts[$zk] ?? 0) + $c;
        $shCounts[$shk] = ($shCounts[$shk] ?? 0) + $c;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'        => true,
        'total_boards'   => (int)($agg['total_boards'] ?? 0),
        'matrix_rows'    => (int)($agg['matrix_rows'] ?? 0),
        'cnt_g'          => (int)($agg['cnt_g'] ?? 0),
        'cnt_d'          => (int)($agg['cnt_d'] ?? 0),
        'cnt_gd'         => (int)($agg['cnt_gd'] ?? 0),
        'cnt_bare'       => (int)($agg['cnt_bare'] ?? 0),
        'cnt_bad_mark'   => (int)($agg['cnt_bad_mark'] ?? 0),
        'years'          => $yearsMap,
        'months'         => $monthsMap,
        'zone_counts'    => $zCounts,
        'sheet_counts'   => $shCounts,
        'summary_text'   => $flt['summaryText'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ──────────────────────────────────────────────
// Export Download (?export=1) — XLSX & CSV
// ──────────────────────────────────────────────

if (isset($_GET['export'])) {
    $flt = buildExportFilterQuery(
        $pdo, $zone, $sheet, $q, $boardTypeF, $statusF, $otherF, $shelfF, $periodF
    );
    $whereSql = $flt['whereSql'];
    $params   = $flt['params'];

    if (!$hasZoneSheets && empty($_GET['zones'])) {
        $whereSql = '1 = 0';
        $params   = [];
    }

    $sortCols = ['shelf' => 'shelf_no', 'code' => 'code', 'product' => 'product'];
    $orderBy  = ($flt['isAllSheetsSelected'] || $flt['isAllZonesSelected'] || count($flt['selectedZones']) > 1)
        ? 'created_at ASC, id ASC'
        : (($sortCols[$sort] ?? 'shelf_no') . " $dir, id ASC");

    $format        = strtolower(trim((string)($_GET['format'] ?? 'csv')));
    $includeTables = strtolower(trim((string)($_GET['include_tables'] ?? 'both')));
    if (!in_array($includeTables, ['both', 'boards', 'matrix'], true)) {
        $includeTables = 'both';
    }

    // ── Excel (.xlsx) Multi-Sheet Organized Workbook ──
    if ($format === 'xlsx') {
        $sheetsToBuild = [];

        // Sheet 1: Master Boards
        if ($includeTables === 'both' || $includeTables === 'boards') {
            $st = $pdo->prepare("
                SELECT id, code, zone, sheet, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, defects, created_at
                FROM boards
                WHERE $whereSql
                ORDER BY $orderBy
            ");
            $st->execute($params);
            $boardRows = $st->fetchAll();
            $sheetsToBuild[] = buildMasterBoardsSheet($boardRows, $flt['summaryText']);
        }

        // Sheet 2: Defect Matrix: All Size Magazines — รวมทุกโซน (ตารางข้อบกพร่องทุก Shelf ทุกโซน)
        if ($includeTables === 'both' || $includeTables === 'matrix') {
            $matrixWhereSql = $whereSql . " AND status IN ('D', 'GD')";
            $matrixOrderBy  = ($flt['isAllSheetsSelected'] || $flt['isAllZonesSelected'] || count($flt['selectedZones']) > 1)
                ? 'created_at ASC, id ASC'
                : 'shelf_no ASC, id ASC';
            $mSt = $pdo->prepare("
                SELECT id, code, zone, sheet, shelf, shelf_no, product, status, defects, created_at
                FROM boards
                WHERE $matrixWhereSql
                ORDER BY $matrixOrderBy
            ");
            $mSt->execute($params);
            $matrixRows = $mSt->fetchAll();
            $sheetsToBuild[] = buildDefectMatrixSheet($matrixRows, $flt['summaryText'], $flt['zoneTitleLabel']);
        }

        $xlsxBytes = buildXlsxWorkbookBytes($sheetsToBuild);
        $zoneSlug  = ($flt['isAllZonesSelected'] || count($flt['selectedZones']) > 1)
            ? 'ALL_ZONES'
            : preg_replace('/[^A-Za-z0-9_-]/', '_', $flt['selectedZones'][0] ?? 'Zone');
        $fileName  = 'AOI_Export_' . $zoneSlug . '_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($xlsxBytes));
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        echo $xlsxBytes;
        exit;
    }

    // ── Standard CSV Download (backward-compatible) ──
    $st = $pdo->prepare("SELECT code, zone, sheet, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, created_at FROM boards WHERE $whereSql ORDER BY $orderBy");
    $st->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aoi_' . $zone . '_' . $sheet . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM for Thai Excel
    $csvHead = ['Number Code', 'Shelf', 'Product Model', 'Side', 'Board Type', 'Status', 'PCS P/D', 'Magazine / Slot', 'Created At'];
    if ($isAllZones || $flt['isAllZonesSelected'] || count($flt['selectedZones']) > 1) {
        array_unshift($csvHead, 'Zone');   // rows of several zones: say which one each belongs to
    }
    fputcsv($out, $csvHead);
    while ($r = $st->fetch()) {
        $magSlotText = magazineSlotDisplay($r);
        $csvRow = [
            $r['code'],
            $r['shelf'],
            $r['product'],
            $r['side'],
            $r['board_type'],
            $r['status'],
            $r['pcs_status'],
            $magSlotText,
            exportDateOnly($r['created_at']),
        ];
        if ($isAllZones || $flt['isAllZonesSelected'] || count($flt['selectedZones']) > 1) {
            array_unshift($csvRow, $r['zone']);
        }
        fputcsv($out, $csvRow);
    }
    fclose($out);
    exit;
}
