<?php
/**
 * Comprehensive Automated Test Suite for Excel Batch Import System (§7.1 – §7.8)
 *
 * Tests all business rules on BOTH SQLite (:memory:) and MySQL (temporary database aoi_test_import_*, dropped on exit):
 *   1. Template generation (-025..-044 after -024), partial fill (-025..-035), skip blank trailing rows,
 *      subsequent web save (-036), and next template download (-037).
 *   2. Expired template detection when another board is saved before import (0 rows saved, no silent renumbering).
 *   3. Contiguity (blank row gap rejected), non-consecutive code rejected, duplicate code in file rejected,
 *      and monthly 999 cap (template capped at 999 with notice; blocked at 999 with CODE_MAX_RUN_ERROR).
 *   4. Shelf 4-step validation (wrong prefix, > rack capacity without auto-adding Magazine, occupied in DB,
 *      duplicate in same file with all-or-nothing rollback, and disabled sheet INACTIVE_SHEET_INSERT_ERROR).
 *   5. Magazine / Slot server-side calculation (ignores client column I, computes magazineSlotText()).
 *   6. Shared validateBoardInput() rules (D without Defects, invalid Defect code, G+D -> GD + aoi_programs sync,
 *      Bare + Status rejected, Bare + empty Status/Defects accepted).
 *   7. Security guards (_meta HMAC tamper rejected, non-xlsx rejected, >2MB rejected, XXE <!DOCTYPE/<!ENTITY rejected).
 *   8. MySQL advisory lock contention (GET_LOCK held by another connection -> 503 + CODE_LOCK_BUSY_ERROR, 0 rows saved).
 *
 * Run via:
 *   docker exec php_app_web php /var/www/html/aoi-web-php/tests/import-test.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/xlsx-writer.php';
require_once __DIR__ . '/../includes/xlsx-reader.php';
require_once __DIR__ . '/../includes/import-template.php';
require_once __DIR__ . '/../includes/import-validate.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

$passed = 0;
$failed = 0;
$mysqlTestDbName = '';
$mysqlAdminPdo   = null;

register_shutdown_function(static function () use (&$mysqlAdminPdo, &$mysqlTestDbName): void {
    if ($mysqlAdminPdo instanceof PDO && $mysqlTestDbName !== '') {
        try {
            $mysqlAdminPdo->exec("DROP DATABASE IF EXISTS `{$mysqlTestDbName}`");
        } catch (Throwable $_) {
        }
    }
});

function assertTrue(bool $cond, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/**
 * Helper: take a generated template result from buildImportTemplateWorkbook() and populate specific data rows,
 * returning new .xlsx binary bytes (simulating a user editing the template in Excel and saving).
 *
 * @param array<string,mixed>      $tplResult        Output of buildImportTemplateWorkbook()
 * @param array<int,array<string,string>> $rowEdits  Map of 0-based data row offset (0 => Excel row 5) => field overrides
 * @param array<string,string>|null $tamperMeta      Optional overrides to tamper with _meta without updating checksum
 */
function buildEditedTemplateXlsxBytes(array $tplResult, array $rowEdits, ?array $tamperMeta = null): string
{
    $parsed    = readXlsxBytes($tplResult['bytes']);
    $dataSheet = $parsed['sheets'][IMPORT_DATA_SHEET_TITLE];
    $meta      = $tplResult['meta'];
    if ($tamperMeta !== null) {
        $meta = array_merge($meta, $tamperMeta);
    }

    // Reconstruct rows for AOI_Import_Template
    $colWidths = [22, 14, 28, 14, 16, 14, 18, 26, 26];
    $outRows   = [];

    foreach ($dataSheet['rows'] as $r) {
        $excelRow = (int)$r['excel_row'];
        $cellsMap = $r['cells'];

        if ($excelRow >= 5) {
            $offset = $excelRow - 5;
            if (isset($rowEdits[$offset])) {
                $edit = $rowEdits[$offset];
                if (array_key_exists('code', $edit))       $cellsMap[1] = (string)$edit['code'];
                if (array_key_exists('shelf', $edit))      $cellsMap[2] = (string)$edit['shelf'];
                if (array_key_exists('product', $edit))    $cellsMap[3] = (string)$edit['product'];
                if (array_key_exists('side', $edit))       $cellsMap[4] = (string)$edit['side'];
                if (array_key_exists('board_type', $edit)) $cellsMap[5] = (string)$edit['board_type'];
                if (array_key_exists('status', $edit))     $cellsMap[6] = (string)$edit['status'];
                if (array_key_exists('pcs_status', $edit)) $cellsMap[7] = (string)$edit['pcs_status'];
                if (array_key_exists('defects', $edit))    $cellsMap[8] = (string)$edit['defects'];
                if (array_key_exists('mag_slot', $edit))   $cellsMap[9] = (string)$edit['mag_slot'];
            }
        }

        $rowCells = [];
        for ($c = 1; $c <= 9; $c++) {
            $rowCells[] = ['v' => (string)($cellsMap[$c] ?? ''), 's' => AoiXlsxStyle::DEFAULT_CELL];
        }
        $outRows[] = ['height' => 21, 'cells' => $rowCells];
    }

    $sheets = [
        [
            'title' => IMPORT_DATA_SHEET_TITLE,
            'xml'   => buildWorksheetXml($outRows, $colWidths, 4, 0),
        ],
        buildImportGuideSheet($tplResult['context'], (string)$meta['generated_at']),
        buildImportMetaSheet($meta),
    ];

    return buildXlsxWorkbookBytes($sheets);
}

/**
 * Run the full import test suite against any PDO instance (SQLite or MySQL).
 */
function runImportTestSuiteOnPdo(PDO $pdo, string $driverLabel): void
{
    echo "\n============================================================\n";
    echo "Running Import Test Suite on {$driverLabel}\n";
    echo "============================================================\n";

    runMigrations($pdo);
    topology($pdo, true);

    $now       = new DateTimeImmutable('now', new DateTimeZone(CODE_TZ));
    $ymPrefix  = sprintf('%s-%s-%s', CODE_PREFIX, $now->format('y'), $now->format('m'));

    // Clean boards table
    $pdo->exec('DELETE FROM boards');

    // ------------------------------------------------------------------
    // 1. Scenario 1: Template after -024 -> 20 rows (-025..-044) -> fill 11 rows (-025..-035)
    //    -> web save gets -036 -> new template starts at -037
    // ------------------------------------------------------------------
    echo "\n--- [1] Sequential Number Code & Partial Template Fill ({$driverLabel}) ---\n";

    // Seed board -024 on B200_Gen Shelf B1
    $seedCode = "{$ymPrefix}-024";
    $ins = $pdo->prepare(
        'INSERT INTO boards (zone, sheet, code, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, defects, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        'General', 'B200_Gen', $seedCode, 'B1', 1, 'Magazine 1st · Slot 1',
        'SEED-MODEL-24', 'F-Side', 'ES3261A', 'G', '', '', $now->format('Y-m-d H:i:s'),
    ]);

    $tpl20 = buildImportTemplateWorkbook($pdo, 'General', 'B200_Gen', 20);
    assertTrue($tpl20['ok'] === true, "[{$driverLabel}] 1.1 Generate 20-row template after -024 succeeds");
    assertTrue(
        $tpl20['context']['start_code'] === "{$ymPrefix}-025" && $tpl20['context']['end_code'] === "{$ymPrefix}-044",
        "[{$driverLabel}] 1.2 Template pre-fills codes {$ymPrefix}-025 to {$ymPrefix}-044",
        "got {$tpl20['context']['start_code']} .. {$tpl20['context']['end_code']}"
    );

    // Fill only rows 0..10 (11 rows: -025 to -035) on Shelves B2..B12; leave rows 11..19 (-036..-044) blank
    $edits11 = [];
    for ($i = 0; $i < 11; $i++) {
        $shelfNum = 2 + $i; // B2..B12
        $edits11[$i] = [
            'shelf'      => "b{$shelfNum} ", // lowercase + trailing space to test case-insensitivity & trim
            'product'    => "model-batch-{$i}",
            'side'       => ($i % 2 === 0) ? 'F-Side' : 'B-Side',
            'board_type' => 'ES3261A',
            'status'     => 'G',
            'pcs_status' => '',
            'defects'    => '',
        ];
    }

    $filledBytes11 = buildEditedTemplateXlsxBytes($tpl20, $edits11);
    $parsed11      = readXlsxBytes($filledBytes11);
    $preview11     = validateImportWorkbook($pdo, $parsed11, 'General', 'B200_Gen');

    assertTrue($preview11['success'] === true, "[{$driverLabel}] 1.3 Preview of 11 filled + 9 blank rows succeeds");
    assertTrue(
        $preview11['summary']['filled_rows'] === 11
        && $preview11['summary']['valid_rows'] === 11
        && $preview11['summary']['skipped_blank_rows'] === 9,
        "[{$driverLabel}] 1.4 Preview counts 11 filled, 11 valid, 9 skipped blank rows"
    );

    $commit11 = commitImportWorkbook($pdo, $parsed11, 'General', 'B200_Gen');
    assertTrue($commit11['success'] === true && $commit11['imported_count'] === 11, "[{$driverLabel}] 1.5 Commit saves 11 records (-025 to -035)");
    assertTrue(
        $commit11['first_code'] === "{$ymPrefix}-025" && $commit11['last_code'] === "{$ymPrefix}-035",
        "[{$driverLabel}] 1.6 Committed range is {$ymPrefix}-025 .. {$ymPrefix}-035"
    );

    // Verify next web save gets -036
    $webSave = saveBoardRecord($pdo, [
        'zone'         => 'General',
        'sheet'        => 'B200_Gen',
        'shelf'        => 'B13',
        'product'      => 'WEB-AFTER-IMPORT',
        'side'         => 'F-Side',
        'board_type'   => 'Aluminum',
        'board_status' => 'G',
        'pcs'          => '',
        'defects'      => '',
    ]);
    assertTrue(
        $webSave['success'] === true && $webSave['code'] === "{$ymPrefix}-036",
        "[{$driverLabel}] 1.7 Subsequent web save receives {$ymPrefix}-036",
        "got " . ($webSave['code'] ?? 'none')
    );

    // Verify next template download starts at -037
    $tplNext = buildImportTemplateWorkbook($pdo, 'General', 'B200_Gen', 10);
    assertTrue(
        $tplNext['ok'] === true && $tplNext['context']['start_code'] === "{$ymPrefix}-037",
        "[{$driverLabel}] 1.8 Next template download starts at {$ymPrefix}-037",
        "got " . ($tplNext['context']['start_code'] ?? 'none')
    );

    // ------------------------------------------------------------------
    // 2. Scenario 2: Expired Template (someone saves on web before template is imported)
    // ------------------------------------------------------------------
    echo "\n--- [2] Expired Template Detection ({$driverLabel}) ---\n";

    // $tplNext was generated starting at -037. Now another user saves via web, consuming -037:
    $webSave37 = saveBoardRecord($pdo, [
        'zone'         => 'General',
        'sheet'        => 'B200_Gen',
        'shelf'        => 'B14',
        'product'      => 'RACE-WEB-37',
        'side'         => 'B-Side',
        'board_type'   => 'L67068',
        'board_status' => 'G',
        'pcs'          => '',
        'defects'      => '',
    ]);
    assertTrue($webSave37['code'] === "{$ymPrefix}-037", "[{$driverLabel}] 2.1 Concurrent web save consumes -037");

    // User tries to import $tplNext (which still starts at -037)
    $expiredBytes = buildEditedTemplateXlsxBytes($tplNext, [
        0 => [
            'shelf'      => 'B15',
            'product'    => 'STALE-TPL-ROW',
            'side'       => 'F-Side',
            'board_type' => 'ES3261A',
            'status'     => 'G',
        ],
    ]);
    $expiredCommit = commitImportWorkbook($pdo, readXlsxBytes($expiredBytes), 'General', 'B200_Gen');
    assertTrue(
        $expiredCommit['success'] === false && $expiredCommit['template_expired'] === true,
        "[{$driverLabel}] 2.2 Importing expired template (-037 when system is at -038) is rejected with template_expired=true"
    );

    $chkStale = $pdo->prepare('SELECT COUNT(*) FROM boards WHERE product = ?');
    $chkStale->execute(['STALE-TPL-ROW']);
    assertTrue((int)$chkStale->fetchColumn() === 0, "[{$driverLabel}] 2.3 Expired template saves 0 rows (no silent renumbering)");

    // ------------------------------------------------------------------
    // 3. Scenario 3: Contiguity, Non-Consecutive Code, Duplicate Code, & 999 Monthly Cap
    // ------------------------------------------------------------------
    echo "\n--- [3] Contiguity, Code Integrity & 999 Monthly Cap ({$driverLabel}) ---\n";

    $tpl38 = buildImportTemplateWorkbook($pdo, 'General', 'B200_Gen', 10);
    assertTrue($tpl38['context']['start_code'] === "{$ymPrefix}-038", "[{$driverLabel}] 3.1 Fresh template starts at -038");

    // 3a. Blank row gap (Row 0 filled, Row 1 left blank, Row 2 filled)
    $gapBytes = buildEditedTemplateXlsxBytes($tpl38, [
        0 => ['shelf' => 'B15', 'product' => 'GAP-ROW-1', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
        // 1 is left blank!
        2 => ['shelf' => 'B16', 'product' => 'GAP-ROW-3', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
    ]);
    $gapCommit = commitImportWorkbook($pdo, readXlsxBytes($gapBytes), 'General', 'B200_Gen');
    assertTrue($gapCommit['success'] === false, "[{$driverLabel}] 3.2 Blank row gap between filled rows is rejected");
    assertTrue(!empty($gapCommit['rows'][1]['is_blank_gap']), "[{$driverLabel}] 3.3 Blank gap row (#6) is pinpointed in validation rows");

    // 3b. Non-consecutive Number Code (Row 1 code modified to -045 instead of -039)
    $nonConsecBytes = buildEditedTemplateXlsxBytes($tpl38, [
        0 => ['shelf' => 'B15', 'product' => 'NC-1', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
        1 => ['code' => "{$ymPrefix}-045", 'shelf' => 'B16', 'product' => 'NC-2', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
    ]);
    $nonConsecRes = commitImportWorkbook($pdo, readXlsxBytes($nonConsecBytes), 'General', 'B200_Gen');
    assertTrue($nonConsecRes['success'] === false && isset($nonConsecRes['rows'][1]['field_errors']['code']), "[{$driverLabel}] 3.4 Non-consecutive Number Code is rejected");

    // 3c. Duplicate Number Code in same file
    $dupCodeBytes = buildEditedTemplateXlsxBytes($tpl38, [
        0 => ['shelf' => 'B15', 'product' => 'DC-1', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
        1 => ['code' => "{$ymPrefix}-038", 'shelf' => 'B16', 'product' => 'DC-2', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
    ]);
    $dupCodeRes = commitImportWorkbook($pdo, readXlsxBytes($dupCodeBytes), 'General', 'B200_Gen');
    assertTrue($dupCodeRes['success'] === false && isset($dupCodeRes['rows'][0]['field_errors']['code']) && isset($dupCodeRes['rows'][1]['field_errors']['code']), "[{$driverLabel}] 3.5 Duplicate Number Code in file is rejected on both rows");

    // 3d. Monthly 999 cap check
    $ins->execute([
        'General', 'B200_Gen', "{$ymPrefix}-996", 'B40', 40, 'Magazine 1st · Slot 40',
        'CAP-996', 'F-Side', 'ES3261A', 'G', '', '', $now->format('Y-m-d H:i:s'),
    ]);
    $tplCapped = buildImportTemplateWorkbook($pdo, 'General', 'B200_Gen', 25);
    assertTrue(
        $tplCapped['ok'] === true
        && $tplCapped['context']['actual_rows'] === 3
        && $tplCapped['context']['capped_by_month_limit'] === true
        && $tplCapped['context']['start_code'] === "{$ymPrefix}-997"
        && $tplCapped['context']['end_code'] === "{$ymPrefix}-999",
        "[{$driverLabel}] 3.6 Template near month limit caps at 999 (3 rows: -997..-999) with cap_notice"
    );

    $ins->execute([
        'General', 'B200_Gen', "{$ymPrefix}-999", 'B41', 41, 'Magazine 1st · Slot 41',
        'CAP-999', 'F-Side', 'ES3261A', 'G', '', '', $now->format('Y-m-d H:i:s'),
    ]);
    $tplFull = buildImportTemplateWorkbook($pdo, 'General', 'B200_Gen', 10);
    assertTrue(
        $tplFull['ok'] === false && $tplFull['error'] === CODE_MAX_RUN_ERROR,
        "[{$driverLabel}] 3.7 Template generation at 999 is blocked with CODE_MAX_RUN_ERROR"
    );

    // Remove the 996 and 999 test rows so we are back at -037
    $pdo->prepare('DELETE FROM boards WHERE code IN (?, ?)')->execute(["{$ymPrefix}-996", "{$ymPrefix}-999"]);

    // ------------------------------------------------------------------
    // 4. Scenario 4: Shelf 4-Step Validation & All-or-Nothing Rollback
    // ------------------------------------------------------------------
    echo "\n--- [4] Shelf 4-Step Validation & All-or-Nothing Rollback ({$driverLabel}) ---\n";

    $tplShelf = buildImportTemplateWorkbook($pdo, 'General', 'B200_Gen', 10);

    // 4a. Wrong prefix (A15 on B200_Gen), > Rack capacity (B55 when 1 Mag = 50), Occupied in DB (B1), Duplicate in file (B20 twice), plus 1 valid row (B19)
    $shelfTestBytes = buildEditedTemplateXlsxBytes($tplShelf, [
        0 => ['shelf' => 'A15', 'product' => 'BAD-PREFIX',  'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
        1 => ['shelf' => 'B55', 'product' => 'OVER-RACK',   'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
        2 => ['shelf' => 'B1',  'product' => 'OCCUPIED-DB', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
        3 => ['shelf' => 'B19', 'product' => 'VALID-ROW-4', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
        4 => ['shelf' => 'B20', 'product' => 'DUP-SHELF-1', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
        5 => ['shelf' => 'B20', 'product' => 'DUP-SHELF-2', 'side' => 'B-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
    ]);
    $shelfCommit = commitImportWorkbook($pdo, readXlsxBytes($shelfTestBytes), 'General', 'B200_Gen');

    assertTrue($shelfCommit['success'] === false, "[{$driverLabel}] 4.1 Batch with Shelf errors is rejected");
    assertTrue(
        !$shelfCommit['rows'][0]['valid']
        && !$shelfCommit['rows'][1]['valid']
        && !$shelfCommit['rows'][2]['valid']
        && $shelfCommit['rows'][3]['valid'] === true
        && !$shelfCommit['rows'][4]['valid']
        && !$shelfCommit['rows'][5]['valid'],
        "[{$driverLabel}] 4.2 Every invalid Shelf row (wrong prefix, >rack, occupied DB, dup in file) is flagged while valid row is marked valid in preview"
    );
    assertTrue(
        magazineCount($pdo, 'B200_Gen') === 1,
        "[{$driverLabel}] 4.3 Out-of-range Shelf B55 does NOT auto-create a Magazine"
    );

    $chkValidRow4 = $pdo->prepare('SELECT COUNT(*) FROM boards WHERE product = ?');
    $chkValidRow4->execute(['VALID-ROW-4']);
    assertTrue(
        (int)$chkValidRow4->fetchColumn() === 0,
        "[{$driverLabel}] 4.4 All-or-Nothing: valid row VALID-ROW-4 (B19) was NOT saved because other rows failed"
    );

    // 4b. Disabled Sheet check (INACTIVE_SHEET_INSERT_ERROR)
    $pdo->exec("UPDATE sheets SET is_active = 0 WHERE sheet_key = 'C250_Gen'");
    topology($pdo, true);
    $tplDisabled = buildImportTemplateWorkbook($pdo, 'General', 'C250_Gen', 10);
    assertTrue(
        $tplDisabled['ok'] === false && $tplDisabled['error'] === INACTIVE_SHEET_INSERT_ERROR,
        "[{$driverLabel}] 4.5 Disabled sheet rejects template download with INACTIVE_SHEET_INSERT_ERROR"
    );
    $pdo->exec("UPDATE sheets SET is_active = 1 WHERE sheet_key = 'C250_Gen'");
    topology($pdo, true);

    // ------------------------------------------------------------------
    // 5 & 6. Scenarios 5 & 6: Server Magazine/Slot calculation & Shared Field Validation
    // ------------------------------------------------------------------
    echo "\n--- [5 & 6] Magazine/Slot Auto-Calculation & Shared Board Field Validation ({$driverLabel}) ---\n";

    // 6a. Status=D without Defects, invalid Defect code 999, and Bare + Status=G must all fail
    $badFieldsBytes = buildEditedTemplateXlsxBytes($tplShelf, [
        0 => ['shelf' => 'B21', 'product' => 'D-NO-DEF',   'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'D',   'defects' => ''],
        1 => ['shelf' => 'B22', 'product' => 'BAD-DEF-99', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G+D', 'defects' => '1, 999'],
        2 => ['shelf' => 'B23', 'product' => 'BARE-WITH-G','side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G',   'pcs_status' => 'Bare'],
    ]);
    $badFieldsRes = commitImportWorkbook($pdo, readXlsxBytes($badFieldsBytes), 'General', 'B200_Gen');
    assertTrue(
        $badFieldsRes['success'] === false
        && isset($badFieldsRes['rows'][0]['field_errors']['defects'])
        && isset($badFieldsRes['rows'][1]['field_errors']['defects'])
        && isset($badFieldsRes['rows'][2]['field_errors']['status']),
        "[{$driverLabel}] 6.1 Shared validator rejects D without Defects, invalid Defect 999, and Bare with Status=G"
    );

    // 5 & 6b. Valid import with client tampering Magazine/Slot column I, G+D -> GD + OTHER:<detail>, and Bare with blank Status/Defects
    $goodFieldsBytes = buildEditedTemplateXlsxBytes($tplShelf, [
        0 => [
            'shelf'      => 'B49',
            'product'    => 'GD-SYNC-MODEL',
            'side'       => 'F-Side',
            'board_type' => 'ES3261A',
            'status'     => 'G+D',
            'pcs_status' => '',
            'defects'    => '1, 5, OTHER:Solder bridge pin 8',
            'mag_slot'   => 'FAKE CLIENT MAG 999', // must be ignored by server!
        ],
        1 => [
            'shelf'      => 'B50',
            'product'    => 'BARE-CLEAN-MODEL',
            'side'       => 'B-Side',
            'board_type' => 'Aluminum',
            'status'     => '',
            'pcs_status' => 'Bare',
            'defects'    => '',
            'mag_slot'   => 'WRONG SLOT TEXT',
        ],
    ]);
    $goodCommit = commitImportWorkbook($pdo, readXlsxBytes($goodFieldsBytes), 'General', 'B200_Gen');
    assertTrue($goodCommit['success'] === true && $goodCommit['imported_count'] === 2, "[{$driverLabel}] 6.2 Valid G+D and Bare rows commit cleanly");

    $savedB49 = $pdo->query("SELECT status, defects, mag_slot FROM boards WHERE shelf = 'B49' AND sheet = 'B200_Gen'")->fetch();
    assertTrue(
        $savedB49['status'] === 'GD'
        && $savedB49['defects'] === '1,5,OTHER:Solder bridge pin 8'
        && $savedB49['mag_slot'] === 'Magazine 1st · Slot 49',
        "[{$driverLabel}] 5.1 & 6.3 Server ignores client mag_slot, saves 'Magazine 1st · Slot 49', normalizes G+D->GD and Defects"
    );

    $savedProg = $pdo->query("SELECT COUNT(*) FROM aoi_programs WHERE product_model = 'GD-SYNC-MODEL' AND side = 'F-Side'")->fetchColumn();
    assertTrue((int)$savedProg === 1, "[{$driverLabel}] 6.4 Imported GD board automatically syncs to aoi_programs");

    // ------------------------------------------------------------------
    // 7. Scenario 7: Security & File Tamper Guards
    // ------------------------------------------------------------------
    echo "\n--- [7] Security & Tamper Guards ({$driverLabel}) ---\n";

    // 7a. Tampered _meta sheet (changed sheet to CD300 without valid HMAC signature)
    $tamperedBytes = buildEditedTemplateXlsxBytes(
        $tplShelf,
        [0 => ['shelf' => 'CD1', 'product' => 'TAMPER', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G']],
        ['sheet' => 'CD300', 'shelf_prefix' => 'CD']
    );
    $tamperRes = validateImportWorkbook($pdo, readXlsxBytes($tamperedBytes));
    assertTrue(
        $tamperRes['success'] === false && in_array(IMPORT_META_TAMPERED_ERROR, $tamperRes['errors'], true),
        "[{$driverLabel}] 7.1 Tampered _meta sheet is rejected by HMAC-SHA256 verification"
    );

    // 7b. Non-xlsx / plain text bytes rejected
    $nonXlsxRejected = false;
    try {
        readXlsxBytes("not-a-zip-file,just,csv,data\n1,2,3");
    } catch (RuntimeException $e) {
        $nonXlsxRejected = true;
    }
    assertTrue($nonXlsxRejected, "[{$driverLabel}] 7.2 Non-.xlsx file (missing PK\\x03\\x04 signature) is rejected");

    // 7c. Oversized file (> 2 MB) rejected
    $oversizedRejected = false;
    try {
        readXlsxBytes("PK\x03\x04" . str_repeat('A', IMPORT_MAX_FILE_BYTES + 100));
    } catch (RuntimeException $e) {
        $oversizedRejected = true;
    }
    assertTrue($oversizedRejected, "[{$driverLabel}] 7.3 Oversized file (> 2 MB) is rejected");

    // 7d. XXE <!DOCTYPE / <!ENTITY injection rejected
    $xxeRejected = false;
    try {
        xlsxSafeParseXml('<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo>&xxe;</foo>');
    } catch (RuntimeException $e) {
        $xxeRejected = true;
    }
    assertTrue($xxeRejected, "[{$driverLabel}] 7.4 XML with <!DOCTYPE / <!ENTITY (XXE attempt) is rejected");
}

// ============================================================================
// Execute Suite on 1) SQLite (:memory:)
// ============================================================================
$sqlitePdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
runImportTestSuiteOnPdo($sqlitePdo, 'SQLite (:memory:)');

// ============================================================================
// Execute Suite on 2) MySQL (throwaway database aoi_test_import_<random>)
// ============================================================================
$dbHost = getenv('DB_HOST') ?: 'db';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') ?: '';

if ($dbUser !== '') {
    $mysqlTestDbName = 'aoi_test_import_' . date('His') . '_' . bin2hex(random_bytes(2));
    $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,
    ];

    $mysqlAdminPdo = new PDO($dsn, $dbUser, $dbPass, $opts);
    $mysqlAdminPdo->exec("CREATE DATABASE `{$mysqlTestDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mysqlAdminPdo->exec("USE `{$mysqlTestDbName}`");

    runImportTestSuiteOnPdo($mysqlAdminPdo, "MySQL ({$mysqlTestDbName})");

    // ------------------------------------------------------------------
    // 8. Scenario 8: MySQL Advisory Lock Contention (GET_LOCK held by 2nd connection)
    // ------------------------------------------------------------------
    echo "\n--- [8] MySQL Advisory Lock Contention (GET_LOCK) ---\n";
    $holderPdo = new PDO($dsn, $dbUser, $dbPass, $opts);
    $holderPdo->exec("USE `{$mysqlTestDbName}`");

    $tplLock = buildImportTemplateWorkbook($mysqlAdminPdo, 'General', 'B200_Gen', 5);
    $lockBytes = buildEditedTemplateXlsxBytes($tplLock, [
        0 => ['shelf' => 'B30', 'product' => 'LOCK-TEST-ROW', 'side' => 'F-Side', 'board_type' => 'ES3261A', 'status' => 'G'],
    ]);

    $gotHolderLock = (int)$holderPdo->query("SELECT GET_LOCK('aoi_board_number_code', 5)")->fetchColumn() === 1;
    assertTrue($gotHolderLock, '[MySQL] 8.1 Second connection acquires GET_LOCK(aoi_board_number_code)');

    // Test acquireNumberCodeLock timeout (1s) and commitImportWorkbook while lock is held
    $lockBusyRes = commitImportWorkbook($mysqlAdminPdo, readXlsxBytes($lockBytes), 'General', 'B200_Gen');
    $holderPdo->query("DO RELEASE_LOCK('aoi_board_number_code')");

    assertTrue(
        $lockBusyRes['success'] === false
        && ($lockBusyRes['http_status'] ?? 0) === 503
        && ($lockBusyRes['lock_busy'] ?? false) === true
        && in_array(CODE_LOCK_BUSY_ERROR, $lockBusyRes['errors'], true),
        '[MySQL] 8.2 commitImportWorkbook returns 503 + CODE_LOCK_BUSY_ERROR when advisory lock is held'
    );

    $chkLocked = $mysqlAdminPdo->query("SELECT COUNT(*) FROM boards WHERE product = 'LOCK-TEST-ROW'")->fetchColumn();
    assertTrue((int)$chkLocked === 0, '[MySQL] 8.3 Zero rows inserted when lock is busy');

    // ------------------------------------------------------------------
    // 9. HTTP API Endpoints & CSRF Protection (Built-in Server against Throwaway DB)
    // ------------------------------------------------------------------
    echo "\n--- [9] HTTP Endpoints & CSRF Protection (api/import_*.php) ---\n";
    $port    = random_int(18100, 18900);
    $docRoot = dirname(__DIR__);
    $env     = array_merge($_ENV, [
        'DB_HOST'     => $dbHost,
        'DB_PORT'     => $dbPort,
        'DB_USER'     => $dbUser,
        'DB_PASS'     => $dbPass,
        'AOI_DB_NAME' => $mysqlTestDbName,
        'PATH'        => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
    ]);
    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docRoot],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        $docRoot,
        $env
    );

    if (is_resource($proc)) {
        // Wait for built-in server to listen
        $up = false;
        for ($w = 0; $w < 25; $w++) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($fp)) {
                fclose($fp);
                $up = true;
                break;
            }
            usleep(80000);
        }

        if ($up) {
            // 9.1 POST without CSRF token -> must return HTTP 403
            $ctx403 = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content'       => 'csrf=invalid_token',
                    'ignore_errors' => true,
                ],
            ]);
            $resPreview403 = (string)@file_get_contents("http://127.0.0.1:{$port}/api/import_preview.php", false, $ctx403);
            $statusLine1   = $http_response_header[0] ?? '';
            assertTrue(
                str_contains($statusLine1, '403') && str_contains($resPreview403, 'CSRF'),
                '[HTTP] 9.1 POST /api/import_preview.php without valid CSRF returns HTTP 403'
            );

            $resCommit403 = (string)@file_get_contents("http://127.0.0.1:{$port}/api/import_commit.php", false, $ctx403);
            $statusLine2  = $http_response_header[0] ?? '';
            assertTrue(
                str_contains($statusLine2, '403') && str_contains($resCommit403, 'CSRF'),
                '[HTTP] 9.2 POST /api/import_commit.php without valid CSRF returns HTTP 403'
            );

            // 9.2 GET /api/import_template.php?mode=info
            $infoJson = (string)@file_get_contents("http://127.0.0.1:{$port}/api/import_template.php?mode=info&zone=General&sheet=B200_Gen&rows=25");
            $infoData = json_decode($infoJson, true);
            assertTrue(
                is_array($infoData) && ($infoData['success'] ?? false) === true && ($infoData['context']['actual_rows'] ?? 0) === 25,
                '[HTTP] 9.3 GET /api/import_template.php?mode=info returns valid telemetry JSON'
            );
        }

        proc_terminate($proc);
        proc_close($proc);
    }

    // Drop temporary MySQL test database
    $mysqlAdminPdo->exec("DROP DATABASE IF EXISTS `{$mysqlTestDbName}`");
    $mysqlTestDbName = '';
}

echo "\n============================================================\n";
echo "Import Test Summary: {$passed} passed, {$failed} failed\n";
echo "============================================================\n";

exit($failed > 0 ? 1 : 0);
