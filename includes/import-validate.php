<?php
/**
 * Excel Import Validator & All-or-Nothing Transactional Commit Engine
 *
 * Enforces all server-side business rules (§2.1 – §2.4):
 *   - Verifies hidden _meta sheet HMAC-SHA256 signature and active Zone/Sheet topology
 *   - Enforces top-to-bottom contiguous row filling (no blank row gaps)
 *   - Verifies Number Code format, starting at nextNumberCode($pdo), strictly consecutive (+1),
 *     within current Asia/Bangkok month (<= 999), no duplicates in file or DB
 *   - Validates Shelf per row in strict 4-step order (prefix format -> Rack capacity -> DB vacancy -> file uniqueness)
 *   - Ignores client Magazine / Slot column and computes magazineSlotText() on server with final uniqueness guard
 *   - Calls shared validateBoardInput() for Product Model, Side, Board Type, Status, Other, and Defects
 *   - Commits all rows atomically inside GET_LOCK('aoi_board_number_code', 5) + single DB transaction
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/xlsx-reader.php';
require_once __DIR__ . '/import-template.php';

/**
 * Extract key-value metadata from the hidden "_meta" worksheet.
 *
 * @param array<string,mixed> $parsedWorkbook Output of readXlsxBytes() / readXlsxFile()
 * @return array<string,string>|null
 */
function extractImportMetaFromWorkbook(array $parsedWorkbook): ?array
{
    $sheets = $parsedWorkbook['sheets'] ?? [];
    if (!isset($sheets[IMPORT_META_SHEET_TITLE])) {
        return null;
    }

    $metaRows = $sheets[IMPORT_META_SHEET_TITLE]['rows'] ?? [];
    $meta     = [];

    foreach ($metaRows as $r) {
        $cells = $r['cells'] ?? [];
        $key   = trim((string)($cells[1] ?? ''));
        $val   = trim((string)($cells[2] ?? ''));
        if ($key === '' || strtolower($key) === 'key') {
            continue;
        }
        $meta[$key] = $val;
    }

    return $meta;
}

/**
 * Validate a parsed .xlsx workbook against all AOI Import business rules (Dry-Run / Preview or Pre-Commit).
 *
 * @param PDO                 $pdo
 * @param array<string,mixed> $parsedWorkbook Output of readXlsxFile() or readXlsxBytes()
 * @param string              $uiZone         Currently selected Zone in UI (for mismatch warning)
 * @param string              $uiSheet        Currently selected Sheet in UI (for mismatch warning)
 * @return array{
 *   success:bool,
 *   template_expired:bool,
 *   errors:string[],
 *   warnings:string[],
 *   meta:array<string,mixed>,
 *   summary:array{
 *     total_template_rows:int,
 *     filled_rows:int,
 *     valid_rows:int,
 *     error_rows:int,
 *     skipped_blank_rows:int,
 *     first_code:string,
 *     last_code:string,
 *     expected_next_code:string,
 *     zone:string,
 *     zone_label:string,
 *     sheet:string,
 *     shelf_prefix:string,
 *     max_shelf_no:int
 *   },
 *   rows:array<int,array<string,mixed>>
 * }
 */
function validateImportWorkbook(
    PDO $pdo,
    array $parsedWorkbook,
    string $uiZone = '',
    string $uiSheet = ''
): array {
    $errors          = [];
    $warnings        = [];
    $templateExpired = false;

    $defaultSummary = [
        'total_template_rows' => 0,
        'filled_rows'         => 0,
        'valid_rows'          => 0,
        'error_rows'          => 0,
        'skipped_blank_rows'  => 0,
        'first_code'          => '',
        'last_code'           => '',
        'expected_next_code'  => '',
        'zone'                => '',
        'zone_label'          => '',
        'sheet'               => '',
        'shelf_prefix'        => '',
        'max_shelf_no'        => 0,
    ];

    // 1. Verify _meta sheet & HMAC-SHA256 checksum
    $meta = extractImportMetaFromWorkbook($parsedWorkbook);
    if ($meta === null || !verifyImportMetaChecksum($meta)) {
        return [
            'success'          => false,
            'template_expired' => false,
            'errors'           => [IMPORT_TAMPERED_META_ERROR],
            'warnings'         => [],
            'meta'             => $meta ?? [],
            'summary'          => $defaultSummary,
            'rows'             => [],
        ];
    }

    $zone        = trim((string)($meta['zone'] ?? ''));
    $sheet       = trim((string)($meta['sheet'] ?? ''));
    $metaStart   = trim((string)($meta['start_code'] ?? ''));
    $metaYM      = trim((string)($meta['year_month'] ?? ''));
    $metaRowsCap = (int)($meta['row_count'] ?? 0);

    // 2. Verify Zone & Sheet are concrete and active in topology (§2.2 step 5)
    if ($zone === '' || strtoupper($zone) === 'ALL' || $sheet === '' || strtoupper($sheet) === 'ALL') {
        return [
            'success'          => false,
            'template_expired' => false,
            'errors'           => [IMPORT_SELECT_ZONE_SHEET_ERROR],
            'warnings'         => [],
            'meta'             => $meta,
            'summary'          => $defaultSummary,
            'rows'             => [],
        ];
    }

    $topo          = topology($pdo);
    $blockedReason = insertBlockedReason($zone, $sheet);
    if ($blockedReason !== '') {
        return [
            'success'          => false,
            'template_expired' => false,
            'errors'           => [$blockedReason],
            'warnings'         => [],
            'meta'             => $meta,
            'summary'          => array_merge($defaultSummary, ['zone' => $zone, 'sheet' => $sheet]),
            'rows'             => [],
        ];
    }

    $zone       = sheetZone($sheet);
    $zoneLabel  = (string)($topo['zones'][$zone]['label'] ?? $zone);
    $prefix     = shelfPrefix($sheet);
    $magCount   = magazineCount($pdo, $sheet);
    $maxShelfNo = $magCount * MAGAZINE_SIZE;

    $defaultSummary['zone']         = $zone;
    $defaultSummary['zone_label']   = $zoneLabel;
    $defaultSummary['sheet']        = $sheet;
    $defaultSummary['shelf_prefix'] = $prefix;
    $defaultSummary['max_shelf_no'] = $maxShelfNo;

    // 3. Warn if UI is currently viewing a different Zone or Sheet (§3.3)
    $uiZoneTrim  = trim($uiZone);
    $uiSheetTrim = trim($uiSheet);
    if (
        ($uiZoneTrim !== '' && strtoupper($uiZoneTrim) !== 'ALL' && $uiZoneTrim !== $zone)
        || ($uiSheetTrim !== '' && strtoupper($uiSheetTrim) !== 'ALL' && $uiSheetTrim !== $sheet)
    ) {
        $warnings[] = sprintf(
            'แจ้งเตือน: ไฟล์แม่แบบนี้ผูกกับโซน "%s (%s)" · ชีต "%s" (ขณะนี้หน้าจอกำลังเปิดโซน "%s" · ชีต "%s") หากยืนยันนำเข้า ข้อมูลจะถูกบันทึกลงชีต "%s" ตามที่ระบุในไฟล์แม่แบบ / '
            . 'Notice: This template is bound to Zone "%s" · Sheet "%s" (current view is "%s" · "%s"). Imported boards will be saved to "%s".',
            $zone,
            $zoneLabel,
            $sheet,
            $uiZoneTrim !== '' ? $uiZoneTrim : '-',
            $uiSheetTrim !== '' ? $uiSheetTrim : '-',
            $sheet,
            $zone,
            $sheet,
            $uiZoneTrim !== '' ? $uiZoneTrim : '-',
            $uiSheetTrim !== '' ? $uiSheetTrim : '-',
            $sheet
        );
    }

    // 4. Locate Data Worksheet (AOI_Import_Template)
    $sheets = $parsedWorkbook['sheets'] ?? [];
    $dataSheet = $sheets[IMPORT_DATA_SHEET_TITLE] ?? null;
    if ($dataSheet === null) {
        return [
            'success'          => false,
            'template_expired' => false,
            'errors'           => [
                sprintf(
                    'ไม่พบชีตข้อมูล "%s" ในไฟล์ — กรุณาใช้ไฟล์แม่แบบที่ดาวน์โหลดจากระบบโดยไม่เปลี่ยนชื่อชีต / '
                    . 'Worksheet "%s" not found in file. Please use an unmodified system template.',
                    IMPORT_DATA_SHEET_TITLE,
                    IMPORT_DATA_SHEET_TITLE
                ),
            ],
            'warnings'         => $warnings,
            'meta'             => $meta,
            'summary'          => $defaultSummary,
            'rows'             => [],
        ];
    }

    $sheetRows = $dataSheet['rows'] ?? [];

    // 5. Verify Header Row (Excel row 4)
    $headerCells = [];
    foreach ($sheetRows as $sr) {
        if ((int)($sr['excel_row'] ?? 0) === 4) {
            $headerCells = $sr['cells'] ?? [];
            break;
        }
    }
    $expectedHeaders = [
        1 => 'Number Code',
        2 => 'Shelf',
        3 => 'Product Model',
        4 => 'Side',
        5 => 'Board Type',
        6 => 'Status',
        7 => 'PCS P/D (Other)',
        8 => 'Defects',
        9 => 'Magazine / Slot',
    ];
    foreach ($expectedHeaders as $colIdx => $expectedTitle) {
        $actualTitle = trim((string)($headerCells[$colIdx] ?? ''));
        if (strcasecmp($actualTitle, $expectedTitle) !== 0) {
            return [
                'success'          => false,
                'template_expired' => false,
                'errors'           => [
                    sprintf(
                        'โครงสร้างหัวตารางในแถวที่ 4 ไม่ตรงกับแม่แบบ (คอลัมน์ %s คาดหวัง "%s" แต่พบ "%s") — กรุณาดาวน์โหลดแม่แบบใหม่และห้ามแทรกหรือลบคอลัมน์ / '
                        . 'Header mismatch in row 4 column %s (expected "%s", found "%s"). Do not insert or delete columns.',
                        xlsxColLetter($colIdx),
                        $expectedTitle,
                        $actualTitle !== '' ? $actualTitle : '(ว่าง)',
                        xlsxColLetter($colIdx),
                        $expectedTitle,
                        $actualTitle !== '' ? $actualTitle : '(empty)'
                    ),
                ],
                'warnings'         => $warnings,
                'meta'             => $meta,
                'summary'          => $defaultSummary,
                'rows'             => [],
            ];
        }
    }

    // 6. Inspect current Number Code sequence at server time
    $codeInfo         = nextNumberCodeInfo($pdo);
    $expectedNextCode = (string)($codeInfo['next_code'] ?? '');
    $defaultSummary['expected_next_code'] = $expectedNextCode;

    if ($codeInfo['remaining_in_month'] <= 0 || $expectedNextCode === '') {
        return [
            'success'          => false,
            'template_expired' => false,
            'errors'           => [CODE_MAX_RUN_ERROR],
            'warnings'         => $warnings,
            'meta'             => $meta,
            'summary'          => $defaultSummary,
            'rows'             => [],
        ];
    }

    // Check if template metadata start_code matches current nextNumberCode($pdo)
    if ($metaStart !== $expectedNextCode || $metaYM !== $codeInfo['year_month']) {
        $templateExpired = true;
        $errors[] = sprintf(
            '%s (รหัสเริ่มของไฟล์คือ %s แต่รหัสถัดไปของระบบปัจจุบันคือ %s)',
            IMPORT_TEMPLATE_EXPIRED_ERROR,
            $metaStart !== '' ? $metaStart : '-',
            $expectedNextCode
        );
    }

    // 7. Extract Data Rows (Excel row >= 5) and classify Filled vs Blank rows
    $dataRowsByExcelNum = [];
    $maxSeenExcelRow    = 4;
    foreach ($sheetRows as $sr) {
        $rNum = (int)($sr['excel_row'] ?? 0);
        if ($rNum < 5) {
            continue;
        }
        $dataRowsByExcelNum[$rNum] = $sr['cells'] ?? [];
        if ($rNum > $maxSeenExcelRow) {
            $maxSeenExcelRow = $rNum;
        }
    }

    $totalTemplateRows = max($metaRowsCap, $maxSeenExcelRow - 4);
    if ($totalTemplateRows > IMPORT_MAX_ROWS) {
        return [
            'success'          => false,
            'template_expired' => $templateExpired,
            'errors'           => [
                sprintf(
                    'จำนวนแถวในแม่แบบ (%d แถว) เกินขีดจำกัดสูงสุด %d แถวต่อไฟล์ / Template row count (%d) exceeds maximum limit (%d).',
                    $totalTemplateRows,
                    IMPORT_MAX_ROWS,
                    $totalTemplateRows,
                    IMPORT_MAX_ROWS
                ),
            ],
            'warnings'         => $warnings,
            'meta'             => $meta,
            'summary'          => $defaultSummary,
            'rows'             => [],
        ];
    }

    $lastExcelRowToScan = 4 + $totalTemplateRows;
    $rawSequence        = [];
    $lastFilledExcelRow = 0;

    for ($rNum = 5; $rNum <= $lastExcelRowToScan; $rNum++) {
        $cells = $dataRowsByExcelNum[$rNum] ?? [];
        $code      = trim((string)($cells[1] ?? ''));
        $shelf     = trim((string)($cells[2] ?? ''));
        $product   = trim((string)($cells[3] ?? ''));
        $side      = trim((string)($cells[4] ?? ''));
        $boardType = trim((string)($cells[5] ?? ''));
        $statusRaw = trim((string)($cells[6] ?? ''));
        $otherRaw  = trim((string)($cells[7] ?? ''));
        $defects   = trim((string)($cells[8] ?? ''));

        // User-editable columns are B..H (cols 2..8).
        $isFilled = (
            $shelf !== ''
            || $product !== ''
            || $side !== ''
            || $boardType !== ''
            || $statusRaw !== ''
            || $otherRaw !== ''
            || $defects !== ''
        );

        if ($isFilled) {
            $lastFilledExcelRow = $rNum;
        }

        $rawSequence[$rNum] = [
            'excel_row'   => $rNum,
            'is_filled'   => $isFilled,
            'code'        => $code,
            'shelf'       => $shelf,
            'product'     => $product,
            'side'        => $side,
            'board_type'  => $boardType,
            'status_raw'  => $statusRaw,
            'pcs_status'  => $otherRaw,
            'defects_raw' => $defects,
        ];
    }

    // If no rows were filled at all -> reject with IMPORT_EMPTY_ROWS_ERROR
    if ($lastFilledExcelRow === 0) {
        $errors[] = IMPORT_EMPTY_ROWS_ERROR;
        $defaultSummary['total_template_rows'] = $totalTemplateRows;
        $defaultSummary['skipped_blank_rows']  = $totalTemplateRows;
        return [
            'success'          => false,
            'template_expired' => $templateExpired,
            'errors'           => $errors,
            'warnings'         => $warnings,
            'meta'             => $meta,
            'summary'          => $defaultSummary,
            'rows'             => [],
        ];
    }

    // 8. Check Contiguity from row 5 to $lastFilledExcelRow & Pre-scan duplicates in file
    $codeOccurrencesInFile  = []; // code => int[] excel_rows
    $shelfOccurrencesInFile = []; // shelf_no => int[] excel_rows
    $skippedBlankRows       = max(0, $lastExcelRowToScan - $lastFilledExcelRow);
    $filledRowCount         = 0;

    for ($rNum = 5; $rNum <= $lastFilledExcelRow; $rNum++) {
        $item = $rawSequence[$rNum];
        if ($item['is_filled']) {
            $filledRowCount++;
            $cUpper = strtoupper($item['code']);
            if ($cUpper !== '') {
                $codeOccurrencesInFile[$cUpper][] = $rNum;
            }
            $parsedShelfNo = parseShelf($sheet, $item['shelf']);
            if ($parsedShelfNo !== null) {
                $shelfOccurrencesInFile[$parsedShelfNo][] = $rNum;
            }
        }
    }

    // Also pre-fetch any existing Number Codes in DB among the codes present in the file
    $existingDbCodes = [];
    if (!empty($codeOccurrencesInFile)) {
        $allCodes     = array_keys($codeOccurrencesInFile);
        $placeholders = implode(',', array_fill(0, count($allCodes), '?'));
        $cStmt        = $pdo->prepare("SELECT `code`, `sheet`, `shelf` FROM `boards` WHERE `code` IN ({$placeholders})");
        $cStmt->execute($allCodes);
        foreach ($cStmt->fetchAll() as $dbRow) {
            $existingDbCodes[strtoupper((string)$dbRow['code'])] = $dbRow;
        }
    }

    // 9. Validate every row from row 5 up to $lastFilledExcelRow
    $validatedRows      = [];
    $validRowsCount     = 0;
    $errorRowsCount     = 0;
    $magSlotOccurrences = []; // mag_slot => int[] excel_rows
    $firstFilledCode    = '';
    $lastFilledCode     = '';
    $hasBlankGapError   = false;

    for ($rNum = 5; $rNum <= $lastFilledExcelRow; $rNum++) {
        $item      = $rawSequence[$rNum];
        $rowIdx0   = $rNum - 5; // 0-based index from row 5
        $rowErrors = [];
        $fieldErrs = [];

        $expectedRunForRow  = $codeInfo['next_run'] + $rowIdx0;
        $expectedCodeForRow = $expectedRunForRow <= CODE_MAX_RUN
            ? sprintf('%s-%03d', $codeInfo['month_prefix'], $expectedRunForRow)
            : '';

        // 9a. Contiguity check: Blank row sitting above $lastFilledExcelRow
        if (!$item['is_filled']) {
            $hasBlankGapError = true;
            $gapMsg = sprintf(
                'แถวที่ %d: พบแถวว่างคั่นก่อนแถวที่ %d — ต้องกรอกข้อมูลต่อเนื่องจากบนลงล่างโดยไม่เว้นบรรทัด เพื่อรักษาลำดับ Number Code (%s) ให้ต่อเนื่อง กรุณาลบแถวว่างหรือย้ายข้อมูลขึ้นมาติดกัน / '
                . 'Row %d: Blank row gap before filled row %d. Rows must be filled contiguously from top to bottom without gaps.',
                $rNum,
                $lastFilledExcelRow,
                $item['code'] !== '' ? $item['code'] : $expectedCodeForRow,
                $rNum,
                $lastFilledExcelRow
            );
            $rowErrors[]        = $gapMsg;
            $fieldErrs['shelf'] = $gapMsg;

            $validatedRows[] = [
                'excel_row'    => $rNum,
                'is_blank_gap' => true,
                'valid'        => false,
                'code'         => $item['code'] !== '' ? $item['code'] : $expectedCodeForRow,
                'shelf'        => '',
                'shelf_no'     => null,
                'product'      => '',
                'side'         => '',
                'board_type'   => '',
                'status_raw'   => '',
                'status'       => '',
                'pcs_status'   => '',
                'defects_raw'  => '',
                'defects'      => '',
                'mag_slot'     => '-',
                'errors'       => $rowErrors,
                'field_errors' => $fieldErrs,
            ];
            $errorRowsCount++;
            continue;
        }

        // 9b. Number Code validation (§2.1)
        $codeRaw   = $item['code'];
        $codeUpper = strtoupper($codeRaw);

        if ($firstFilledCode === '') {
            $firstFilledCode = $codeUpper;
        }
        $lastFilledCode = $codeUpper;

        if ($codeUpper === '' || !isValidNumberCode($codeUpper)) {
            $msg = sprintf(
                'แถวที่ %d: รูปแบบ Number Code "%s" ไม่ถูกต้อง (ต้องอยู่ในรูปแบบ AOI-P1-YY-MM-NNN เช่น %s) กรุณาใช้ไฟล์แม่แบบโดยไม่แก้ไขคอลัมน์ Number Code / '
                . 'Row %d: Invalid Number Code format "%s" (expected %s). Do not modify the Number Code column.',
                $rNum,
                $codeRaw !== '' ? $codeRaw : '(ว่าง)',
                $expectedCodeForRow !== '' ? $expectedCodeForRow : 'AOI-P1-26-10-001',
                $rNum,
                $codeRaw !== '' ? $codeRaw : '(empty)',
                $expectedCodeForRow !== '' ? $expectedCodeForRow : 'AOI-P1-26-10-001'
            );
            $rowErrors[]       = $msg;
            $fieldErrs['code'] = $msg;
        } elseif ($expectedRunForRow > CODE_MAX_RUN) {
            $msg = sprintf(
                'แถวที่ %d: เลขรัน Number Code เกิน 999 ของเดือนปัจจุบัน (%s) — %s',
                $rNum,
                $codeInfo['year_month'],
                CODE_MAX_RUN_ERROR
            );
            $rowErrors[]       = $msg;
            $fieldErrs['code'] = $msg;
        } else {
            // Check duplicate Number Code in same file
            $dupCodeRows = array_values(array_filter(
                $codeOccurrencesInFile[$codeUpper] ?? [],
                static fn(int $otherRow): bool => $otherRow !== $rNum
            ));
            if (!empty($dupCodeRows)) {
                $msg = sprintf(
                    'แถวที่ %d: Number Code "%s" ซ้ำกับแถวที่ %s ในไฟล์เดียวกัน — ห้ามคัดลอกหรือแก้ไขรหัสให้ซ้ำกัน / '
                    . 'Row %d: Number Code "%s" duplicates row(s) %s in the same file.',
                    $rNum,
                    $codeUpper,
                    implode(', ', $dupCodeRows),
                    $rNum,
                    $codeUpper,
                    implode(', ', $dupCodeRows)
                );
                $rowErrors[]       = $msg;
                $fieldErrs['code'] = $msg;
            } elseif (isset($existingDbCodes[$codeUpper])) {
                $dbHit = $existingDbCodes[$codeUpper];
                $msg = sprintf(
                    'แถวที่ %d: Number Code "%s" มีอยู่ในระบบแล้ว (ชีต %s · Shelf %s) — กรุณาดาวน์โหลดแม่แบบใหม่เพื่อรับรหัสล่าสุด / '
                    . 'Row %d: Number Code "%s" already exists in database (Sheet %s · Shelf %s). Please download a new template.',
                    $rNum,
                    $codeUpper,
                    (string)$dbHit['sheet'],
                    (string)$dbHit['shelf'],
                    $rNum,
                    $codeUpper,
                    (string)$dbHit['sheet'],
                    (string)$dbHit['shelf']
                );
                $rowErrors[]       = $msg;
                $fieldErrs['code'] = $msg;
                $templateExpired   = true;
            } elseif ($codeUpper !== $expectedCodeForRow) {
                if ($rNum === 5 && $templateExpired) {
                    $msg = sprintf(
                        'แถวที่ %d: รหัสเริ่มต้นในไฟล์ (%s) ไม่ตรงกับรหัสถัดไปของระบบ (%s) เนื่องจากมีการเพิ่มข้อมูลเข้าระบบไปก่อนหน้านี้ กรุณากด "ดาวน์โหลดแม่แบบใหม่" / '
                        . 'Row %d: Starting code (%s) does not match current next system code (%s). Please download a fresh template.',
                        $rNum,
                        $codeUpper,
                        $expectedCodeForRow,
                        $rNum,
                        $codeUpper,
                        $expectedCodeForRow
                    );
                } else {
                    $msg = sprintf(
                        'แถวที่ %d: ลำดับ Number Code ไม่ต่อเนื่อง (พบ "%s" แต่ตามลำดับต้องเป็น "%s") กรุณาอย่าแก้ไขหรือสลับแถวคอลัมน์ Number Code / '
                        . 'Row %d: Non-consecutive Number Code (found "%s", expected "%s"). Do not modify or reorder Number Codes.',
                        $rNum,
                        $codeUpper,
                        $expectedCodeForRow,
                        $rNum,
                        $codeUpper,
                        $expectedCodeForRow
                    );
                }
                $rowErrors[]       = $msg;
                $fieldErrs['code'] = $msg;
            }
        }

        // 9c. Shelf validation in strict order (§2.2: stop at first Shelf error for this row)
        // Step 1 (format) -> Step 2 (exists in Rack) -> Step 3 (empty in DB)
        $shelfCheck = validateBoardShelf($pdo, $sheet, $item['shelf'], null);
        $shelfNo    = $shelfCheck['shelf_no'];
        $shelfNorm  = $shelfCheck['shelf'];
        $magSlot    = $shelfCheck['mag_slot'] !== '' ? $shelfCheck['mag_slot'] : '-';

        if (!$shelfCheck['valid']) {
            $msg = sprintf('แถวที่ %d: %s', $rNum, (string)$shelfCheck['error']);
            $rowErrors[]        = $msg;
            $fieldErrs['shelf'] = (string)$shelfCheck['error'];
        } else {
            // Step 4: Check duplicate Shelf within the same file (only if Steps 1-3 passed for this row)
            $dupShelfRows = array_values(array_filter(
                $shelfOccurrencesInFile[(int)$shelfNo] ?? [],
                static fn(int $otherRow): bool => $otherRow !== $rNum
            ));
            if (!empty($dupShelfRows)) {
                $shelfErr = sprintf(
                    'Shelf "%s" ซ้ำกับแถวที่ %s ในไฟล์เดียวกัน — 1 ช่องวางบอร์ดได้เพียง 1 แผ่น กรุณาเปลี่ยนเป็นช่องว่างอื่นในช่วง %s1–%s%d / '
                    . 'Shelf "%s" is duplicated in row(s) %s of the same file. Each shelf slot can hold only one board.',
                    $shelfNorm,
                    implode(', ', $dupShelfRows),
                    $prefix,
                    $prefix,
                    $maxShelfNo,
                    $shelfNorm,
                    implode(', ', $dupShelfRows)
                );
                $msg = sprintf('แถวที่ %d: %s', $rNum, $shelfErr);
                $rowErrors[]        = $msg;
                $fieldErrs['shelf'] = $shelfErr;
            }
        }

        // 9d. Server-calculated Magazine / Slot & final uniqueness safeguard (§2.3)
        if ($shelfNo !== null && $shelfNo >= 1 && $shelfNo <= $maxShelfNo) {
            $magSlot = magazineSlotText($sheet, $shelfNo);
            $magSlotOccurrences[$magSlot][] = $rNum;
        }

        // 9e. Validate Other Fields using the shared validator validateBoardInput() (§2.4)
        $inputValidation = validateBoardInput([
            'product'    => $item['product'],
            'side'       => $item['side'],
            'board_type' => $item['board_type'],
            'status'     => $item['status_raw'],
            'pcs_status' => $item['pcs_status'],
            'defects'    => $item['defects_raw'],
        ], true);

        foreach ($inputValidation['field_errors'] as $fKey => $fErr) {
            $fieldErrs[$fKey] = $fErr;
            $rowErrors[]      = sprintf('แถวที่ %d: %s', $rNum, $fErr);
        }

        $norm = $inputValidation['normalized'];

        $validatedRows[] = [
            'excel_row'    => $rNum,
            'is_blank_gap' => false,
            'valid'        => empty($rowErrors),
            'code'         => $codeUpper !== '' ? $codeUpper : $codeRaw,
            'shelf'        => $shelfNorm,
            'shelf_no'     => $shelfNo,
            'product'      => $norm['product'],
            'side'         => $norm['side'],
            'board_type'   => $norm['board_type'],
            'status_raw'   => $item['status_raw'],
            'status'       => $norm['status'],
            'pcs_status'   => $norm['pcs_status'],
            'defects_raw'  => $item['defects_raw'],
            'defects'      => $norm['defects'],
            'mag_slot'     => $magSlot,
            'errors'       => $rowErrors,
            'field_errors' => $fieldErrs,
        ];
    }

    // 9f. Final safeguard on Magazine / Slot uniqueness (§2.3)
    foreach ($validatedRows as &$vRow) {
        $ms = (string)$vRow['mag_slot'];
        if ($ms === '' || $ms === '-') {
            continue;
        }
        $rNum = (int)$vRow['excel_row'];
        $dupMsRows = array_values(array_filter(
            $magSlotOccurrences[$ms] ?? [],
            static fn(int $otherRow): bool => $otherRow !== $rNum
        ));
        if (!empty($dupMsRows) && !isset($vRow['field_errors']['shelf'])) {
            $msErr = sprintf(
                'ตำแหน่ง "%s" ซ้ำกับแถวที่ %s ในไฟล์เดียวกัน / Magazine/Slot "%s" conflicts with row(s) %s.',
                $ms,
                implode(', ', $dupMsRows),
                $ms,
                implode(', ', $dupMsRows)
            );
            $vRow['field_errors']['mag_slot'] = $msErr;
            $vRow['errors'][]                 = sprintf('แถวที่ %d: %s', $rNum, $msErr);
            $vRow['valid']                    = false;
        }
    }
    unset($vRow);

    // Recount valid vs error rows and collect summary errors
    $validRowsCount = 0;
    $errorRowsCount = 0;
    foreach ($validatedRows as $vRow) {
        if ($vRow['valid']) {
            $validRowsCount++;
        } else {
            $errorRowsCount++;
        }
    }

    if ($hasBlankGapError) {
        array_unshift(
            $errors,
            'พบแถวว่างคั่นกลางระหว่างแถวที่มีข้อมูล — ระบบกำหนดให้ต้องกรอกข้อมูลต่อเนื่องจากบนลงล่างโดยไม่เว้นบรรทัด กรุณาลบแถวว่างคั่นออกแล้วอัปโหลดใหม่ / '
            . 'Blank row gap(s) detected between filled rows. Please fill rows contiguously from top to bottom.'
        );
    }

    $overallSuccess = empty($errors) && $errorRowsCount === 0 && $validRowsCount > 0;

    if (!$overallSuccess && empty($errors) && $errorRowsCount > 0) {
        $errors[] = sprintf(
            'พบข้อมูลไม่ถูกต้องจำนวน %d แถว จากทั้งหมด %d แถวที่ตรวจสอบ — ระบบยังไม่ได้บันทึกข้อมูลใดๆ (All-or-Nothing) กรุณาแก้ไขแถวที่ขึ้นสีแดงแล้วอัปโหลดใหม่อีกครั้ง / '
            . 'Found %d invalid row(s) out of %d checked. No records were saved (All-or-Nothing). Please fix the highlighted rows and re-upload.',
            $errorRowsCount,
            count($validatedRows),
            $errorRowsCount,
            count($validatedRows)
        );
    }

    $summary = [
        'total_template_rows' => $totalTemplateRows,
        'filled_rows'         => $filledRowCount,
        'valid_rows'          => $validRowsCount,
        'error_rows'          => $errorRowsCount,
        'skipped_blank_rows'  => $skippedBlankRows,
        'first_code'          => $firstFilledCode,
        'last_code'           => $lastFilledCode,
        'expected_next_code'  => $expectedNextCode,
        'zone'                => $zone,
        'zone_label'          => $zoneLabel,
        'sheet'               => $sheet,
        'shelf_prefix'        => $prefix,
        'max_shelf_no'        => $maxShelfNo,
    ];

    return [
        'success'          => $overallSuccess,
        'template_expired' => $templateExpired,
        'errors'           => $errors,
        'warnings'         => $warnings,
        'meta'             => $meta,
        'summary'          => $summary,
        'rows'             => $validatedRows,
    ];
}

/**
 * Execute an all-or-nothing transactional import commit with advisory locking.
 *
 * Re-validates the entire workbook inside GET_LOCK('aoi_board_number_code', 5) + DB transaction.
 * If even 1 row fails validation or hits a unique constraint, rolls back everything so 0 rows are saved.
 *
 * @param PDO                 $pdo
 * @param array<string,mixed> $parsedWorkbook Output of readXlsxFile() or readXlsxBytes()
 * @param string              $uiZone
 * @param string              $uiSheet
 * @return array<string,mixed>
 */
function commitImportWorkbook(
    PDO $pdo,
    array $parsedWorkbook,
    string $uiZone = '',
    string $uiSheet = ''
): array {
    // 1. Acquire system-wide advisory lock for Number Code generation/import (5s timeout)
    if (!acquireNumberCodeLock($pdo, 5)) {
        return [
            'success'          => false,
            'http_status'      => 503,
            'lock_busy'        => true,
            'template_expired' => false,
            'errors'           => [CODE_LOCK_BUSY_ERROR],
            'warnings'         => [],
            'summary'          => [],
            'rows'             => [],
        ];
    }

    $startedTx = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        // 2. Re-validate everything inside the lock & transaction
        $validation = validateImportWorkbook($pdo, $parsedWorkbook, $uiZone, $uiSheet);
        if (!$validation['success']) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $validation['http_status'] = 422;
            $validation['lock_busy']   = false;
            return $validation;
        }

        $zone  = (string)$validation['summary']['zone'];
        $sheet = (string)$validation['summary']['sheet'];
        $rows  = $validation['rows'];

        $nowBkk = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');

        $insertSql = 'INSERT INTO `boards`
            (`zone`, `sheet`, `code`, `shelf`, `shelf_no`, `product`, `board_type`, `status`, `pcs_status`, `side`, `defects`, `mag_slot`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $insertStmt = $pdo->prepare($insertSql);

        $syncedPrograms = [];
        $insertedCount  = 0;

        foreach ($rows as $r) {
            if (!empty($r['is_blank_gap'])) {
                continue;
            }

            $shelfNo  = (int)$r['shelf_no'];
            $shelfStr = (string)$r['shelf'];
            $magSlot  = magazineSlotText($sheet, $shelfNo);
            $product  = (string)$r['product'];
            $bType    = (string)$r['board_type'];
            $status   = (string)$r['status'];
            $side     = (string)$r['side'];

            $insertStmt->execute([
                $zone,
                $sheet,
                (string)$r['code'],
                $shelfStr,
                $shelfNo,
                $product,
                $bType,
                $status,
                (string)$r['pcs_status'],
                $side,
                (string)$r['defects'],
                $magSlot,
                $nowBkk,
            ]);

            if (in_array($status, ['D', 'GD'], true) && in_array($side, PROGRAM_SIDES, true) && $product !== '') {
                $progKey = $product . '|' . $side;
                if (!isset($syncedPrograms[$progKey])) {
                    try {
                        syncProgramFromBoard($pdo, $product, $side, $shelfStr);
                    } catch (Throwable $_) {
                        // Non-fatal in isolated test environments without aoi_programs
                    }
                    $syncedPrograms[$progKey] = true;
                }
            }

            $insertedCount++;
        }

        if ($startedTx && $pdo->inTransaction()) {
            $pdo->commit();
        }

        $nextAfterCommit = nextNumberCode($pdo);
        $firstCode       = (string)$validation['summary']['first_code'];
        $lastCode        = (string)$validation['summary']['last_code'];

        $successMessage = sprintf(
            'นำเข้าสำเร็จ %d รายการ (รหัส %s – %s) เข้าสู่โซน %s · ชีต %s เรียบร้อยแล้ว / '
            . 'Successfully imported %d board(s) (%s – %s) into %s · %s.',
            $insertedCount,
            $firstCode,
            $lastCode,
            $zone,
            $sheet,
            $insertedCount,
            $firstCode,
            $lastCode,
            $zone,
            $sheet
        );

        return [
            'success'          => true,
            'http_status'      => 200,
            'lock_busy'        => false,
            'template_expired' => false,
            'message'          => $successMessage,
            'imported_count'   => $insertedCount,
            'first_code'       => $firstCode,
            'last_code'        => $lastCode,
            'next_code'        => $nextAfterCommit,
            'zone'             => $zone,
            'sheet'            => $sheet,
            'errors'           => [],
            'warnings'         => $validation['warnings'],
            'meta'             => $validation['meta'],
            'summary'          => $validation['summary'],
            'rows'             => $validation['rows'],
        ];
    } catch (Throwable $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $errMsg = $e->getMessage();
        $friendlyError = 'ไม่สามารถบันทึกข้อมูลได้เนื่องจากพบข้อมูลซ้ำหรือข้อผิดพลาดของฐานข้อมูล ระบบได้ยกเลิกการบันทึกทั้งหมดแล้ว (All-or-Nothing) กรุณาตรวจสอบข้อมูลหรือดาวน์โหลดแม่แบบใหม่ / '
            . 'Database constraint conflict occurred. Transaction rolled back (0 rows saved). Please re-verify or download a fresh template.';

        if (stripos($errMsg, 'uq_code') !== false || stripos($errMsg, 'boards.code') !== false) {
            $friendlyError = IMPORT_TEMPLATE_EXPIRED_ERROR;
        } elseif (stripos($errMsg, 'uq_sheet_shelf') !== false || stripos($errMsg, 'boards.sheet, boards.shelf_no') !== false) {
            $friendlyError = 'ไม่สามารถบันทึกได้: มีช่อง Shelf บางรายการถูกใช้งานไปพร้อมกันระหว่างการนำเข้า ระบบได้ยกเลิกการบันทึกทั้งไฟล์แล้ว กรุณาตรวจสอบอีกครั้ง / '
                . 'A Shelf slot was occupied concurrently. All changes were rolled back.';
        }

        return [
            'success'          => false,
            'http_status'      => 422,
            'lock_busy'        => false,
            'template_expired' => stripos($errMsg, 'uq_code') !== false || stripos($errMsg, 'boards.code') !== false,
            'errors'           => [$friendlyError],
            'warnings'         => [],
            'summary'          => [],
            'rows'             => [],
        ];
    } finally {
        releaseNumberCodeLock($pdo);
    }
}
