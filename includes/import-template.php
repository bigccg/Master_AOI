<?php
/**
 * Excel Import Template Generator & Metadata Signer
 *
 * Generates .xlsx templates pre-populated with consecutive Number Codes
 * starting from nextNumberCode($pdo), bound to an active Zone + Sheet,
 * with Data Validation dropdowns, locked/unlocked cell protection,
 * a bilingual "วิธีใช้ (Guide)" sheet, and an HMAC-signed hidden "_meta" sheet.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/xlsx-writer.php';

/**
 * Derive deterministic HMAC secret for signing template _meta sheets.
 */
function getImportMetaHmacSecret(): string
{
    $envSecret = getenv('AOI_IMPORT_SECRET');
    if (is_string($envSecret) && trim($envSecret) !== '') {
        return trim($envSecret);
    }
    $adminHash = getenv('AOI_ADMIN_PASS_HASH') ?: '';
    $dbName    = getenv('AOI_DB_NAME') ?: 'Master_AoI';
    $dbUser    = getenv('DB_USER') ?: 'aoi';
    return hash('sha256', 'aoi-web-php-import-hmac-v1|' . $dbName . '|' . $dbUser . '|' . $adminHash);
}

/**
 * Compute HMAC-SHA256 checksum over canonical template metadata fields.
 *
 * @param array<string,mixed> $meta
 */
function computeImportMetaChecksum(array $meta): string
{
    $canonical = implode('|', [
        (string)($meta['version'] ?? IMPORT_TEMPLATE_VERSION),
        (string)($meta['zone'] ?? ''),
        (string)($meta['sheet'] ?? ''),
        (string)($meta['shelf_prefix'] ?? ''),
        (string)($meta['year_month'] ?? ''),
        (string)($meta['start_code'] ?? ''),
        (string)($meta['end_code'] ?? ''),
        (string)((int)($meta['row_count'] ?? 0)),
        (string)($meta['generated_at'] ?? ''),
    ]);

    return hash_hmac('sha256', $canonical, getImportMetaHmacSecret());
}

/**
 * Verify that a parsed _meta associative array has a valid HMAC-SHA256 checksum.
 *
 * @param array<string,mixed> $meta
 */
function verifyImportMetaChecksum(array $meta): bool
{
    $provided = isset($meta['checksum']) ? trim((string)$meta['checksum']) : '';
    if ($provided === '' || strlen($provided) !== 64) {
        return false;
    }
    $expected = computeImportMetaChecksum($meta);
    return hash_equals($expected, $provided);
}

/**
 * Inspect and validate template generation context for a given Zone, Sheet, and requested row count.
 *
 * @return array{
 *   ok:bool,
 *   error:?string,
 *   zone:string,
 *   zone_label:string,
 *   sheet:string,
 *   shelf_prefix:string,
 *   mag_count:int,
 *   max_shelf_no:int,
 *   occupied_count:int,
 *   free_shelf_count:int,
 *   requested_rows:int,
 *   actual_rows:int,
 *   capped_by_month_limit:bool,
 *   cap_notice:?string,
 *   year_month:string,
 *   month_prefix:string,
 *   start_run:int,
 *   end_run:int,
 *   remaining_in_month:int,
 *   start_code:string,
 *   end_code:string
 * }
 */
function getImportTemplateContext(
    PDO $pdo,
    string $zone,
    string $sheet,
    int $requestedRows = IMPORT_DEFAULT_ROWS
): array {
    $zone  = trim($zone);
    $sheet = trim($sheet);

    $base = [
        'ok'                    => false,
        'error'                 => null,
        'zone'                  => $zone,
        'zone_label'            => $zone,
        'sheet'                 => $sheet,
        'shelf_prefix'          => '',
        'mag_count'             => 0,
        'max_shelf_no'          => 0,
        'occupied_count'        => 0,
        'free_shelf_count'      => 0,
        'requested_rows'        => $requestedRows,
        'actual_rows'           => 0,
        'capped_by_month_limit' => false,
        'cap_notice'            => null,
        'year_month'            => '',
        'month_prefix'          => '',
        'start_run'             => 0,
        'end_run'               => 0,
        'remaining_in_month'    => 0,
        'start_code'            => '',
        'end_code'              => '',
    ];

    // 1. Disallow ALL zone or ALL sheet
    if ($zone === '' || strtoupper($zone) === 'ALL' || $sheet === '' || strtoupper($sheet) === 'ALL') {
        $base['error'] = IMPORT_SELECT_ZONE_SHEET_ERROR;
        return $base;
    }

    // 2. Verify active topology & sheet status
    $topo          = topology($pdo);
    $blockedReason = insertBlockedReason($zone, $sheet);
    if ($blockedReason !== '') {
        $base['error'] = $blockedReason;
        return $base;
    }

    $zone      = sheetZone($sheet);
    $zoneLabel = (string)($topo['zones'][$zone]['label'] ?? $zone);
    $prefix    = shelfPrefix($sheet);
    $magCount  = magazineCount($pdo, $sheet);
    $maxShelf  = $magCount * MAGAZINE_SIZE;

    // Count occupied shelves in this sheet
    $occStmt = $pdo->prepare('SELECT COUNT(*) FROM `boards` WHERE `sheet` = ?');
    $occStmt->execute([$sheet]);
    $occupiedCount  = (int)$occStmt->fetchColumn();
    $freeShelfCount = max(0, $maxShelf - $occupiedCount);

    $base['zone']             = $zone;
    $base['zone_label']       = $zoneLabel;
    $base['shelf_prefix']     = $prefix;
    $base['mag_count']        = $magCount;
    $base['max_shelf_no']     = $maxShelf;
    $base['occupied_count']   = $occupiedCount;
    $base['free_shelf_count'] = $freeShelfCount;

    // 3. Sanitize requested row count (allow 1..IMPORT_MAX_ROWS, default 50)
    if ($requestedRows <= 0) {
        $requestedRows = IMPORT_DEFAULT_ROWS;
    }
    if ($requestedRows > IMPORT_MAX_ROWS) {
        $requestedRows = IMPORT_MAX_ROWS;
    }
    $base['requested_rows'] = $requestedRows;

    // 4. Inspect current Number Code sequence in Asia/Bangkok month
    $codeInfo = nextNumberCodeInfo($pdo);
    $base['year_month']         = $codeInfo['year_month'];
    $base['month_prefix']       = $codeInfo['month_prefix'];
    $base['remaining_in_month'] = $codeInfo['remaining_in_month'];

    if ($codeInfo['remaining_in_month'] <= 0 || $codeInfo['next_code'] === null) {
        $base['error'] = CODE_MAX_RUN_ERROR;
        return $base;
    }

    $startRun = $codeInfo['next_run'];
    $actual   = min($requestedRows, $codeInfo['remaining_in_month']);
    $endRun   = $startRun + $actual - 1;
    $capped   = $requestedRows > $codeInfo['remaining_in_month'];

    $startCode = sprintf('%s-%03d', $codeInfo['month_prefix'], $startRun);
    $endCode   = sprintf('%s-%03d', $codeInfo['month_prefix'], $endRun);

    $capNotice = null;
    if ($capped) {
        $capNotice = sprintf(
            'เดือนปัจจุบัน (%s) เหลือรหัสว่างอีก %d รหัส (สิ้นสุดที่ %s) ระบบจึงสร้างแม่แบบให้ %d แถวโดยไม่ข้ามเดือนอัตโนมัติ / '
            . 'Only %d code(s) remain in current month (%s), ending at %s. Template capped at %d row(s) without crossing into next month.',
            $codeInfo['year_month'],
            $actual,
            $endCode,
            $actual,
            $actual,
            $codeInfo['year_month'],
            $endCode,
            $actual
        );
    }

    $base['ok']                    = true;
    $base['actual_rows']           = $actual;
    $base['capped_by_month_limit'] = $capped;
    $base['cap_notice']            = $capNotice;
    $base['start_run']             = $startRun;
    $base['end_run']               = $endRun;
    $base['start_code']            = $startCode;
    $base['end_code']              = $endCode;

    return $base;
}

/**
 * Build the Excel formula for column I (Magazine / Slot preview) for a given Excel row number.
 */
function buildImportTemplateMagSlotFormula(string $prefix, int $excelRow, int $maxShelfNo): string
{
    $prefixEsc = str_replace('"', '""', $prefix);
    $prefixLen = strlen($prefix);
    $magSize   = MAGAZINE_SIZE;

    // Formula checks if B{row} starts with the sheet prefix and has a valid shelf number <= maxShelfNo,
    // then formats "Magazine N · Slot M" as a live preview in Excel. Server always recalculates on import.
    $numExpr = "VALUE(MID(UPPER(TRIM(B{$excelRow}))," . ($prefixLen + 1) . ",10))";
    $magExpr = "(INT((({$numExpr})-1)/{$magSize})+1)";
    $slotExpr = "(MOD(({$numExpr})-1,{$magSize})+1)";

    return "IF(TRIM(B{$excelRow})=\"\",\"\","
        . "IFERROR("
        . "IF(AND(LEFT(UPPER(TRIM(B{$excelRow})),{$prefixLen})=\"{$prefixEsc}\",{$numExpr}>=1,{$numExpr}<={$maxShelfNo},INT({$numExpr})={$numExpr}),"
        . "\"Magazine \"&{$magExpr}&\" · Slot \"&{$slotExpr},"
        . "\"⚠ ตรวจสอบ Shelf ({$prefixEsc}1-{$prefixEsc}{$maxShelfNo})\""
        . "),"
        . "\"⚠ ตรวจสอบ Shelf ({$prefixEsc}1-{$prefixEsc}{$maxShelfNo})\""
        . "))";
}

/**
 * Build Worksheet 1: AOI_Import_Template (Data entry table with pre-filled Number Codes).
 *
 * @param array<string,mixed> $ctx Output of getImportTemplateContext()
 * @param string              $generatedAt
 */
function buildImportDataSheet(array $ctx, string $generatedAt): array
{
    $colWidths = [
        22,  // A: Number Code (locked, pre-filled)
        14,  // B: Shelf (editable)
        28,  // C: Product Model (editable)
        14,  // D: Side (editable dropdown)
        16,  // E: Board Type (editable dropdown)
        14,  // F: Status (editable dropdown)
        18,  // G: PCS P/D (Other) (editable dropdown)
        26,  // H: Defects (editable)
        26,  // I: Magazine / Slot (locked auto preview)
    ];

    $colCount  = count($colWidths);
    $lastCol   = xlsxColLetter($colCount);
    $prefix    = (string)$ctx['shelf_prefix'];
    $maxShelf  = (int)$ctx['max_shelf_no'];
    $magCount  = (int)$ctx['mag_count'];
    $rowCount  = (int)$ctx['actual_rows'];
    $startRun  = (int)$ctx['start_run'];
    $mPrefix   = (string)$ctx['month_prefix'];

    $rows   = [];
    $merges = [];

    // Row 1: Title Banner
    $r1 = array_fill(0, $colCount, ['v' => '', 's' => AoiXlsxStyle::TITLE_BANNER]);
    $r1[0]['v'] = 'AOI Master Board — แม่แบบนำเข้าข้อมูลบอร์ด (Excel Batch Import Template)';
    $rows[]     = ['height' => 30, 'cells' => $r1];
    $merges[]   = "A1:{$lastCol}1";

    // Row 2: Bound Zone + Sheet + Range Metadata
    $r2 = array_fill(0, $colCount, ['v' => '', 's' => AoiXlsxStyle::SUBTITLE_META]);
    $r2[0]['v'] = sprintf(
        'Zone: %s (%s)   |   Sheet / Size: %s   |   Shelf Range: %s1–%s%d (%d Magazines)   |   Number Code: %s – %s (%d แถว)   |   Generated: %s',
        $ctx['zone'],
        $ctx['zone_label'],
        $ctx['sheet'],
        $prefix,
        $prefix,
        $maxShelf,
        $magCount,
        $ctx['start_code'],
        $ctx['end_code'],
        $rowCount,
        $generatedAt
    );
    $rows[]   = ['height' => 22, 'cells' => $r2];
    $merges[] = "A2:{$lastCol}2";

    // Row 3: Quick Instruction Banner
    $r3 = array_fill(0, $colCount, ['v' => '', 's' => AoiXlsxStyle::KPI_SUMMARY]);
    $r3[0]['v'] = sprintf(
        'คำแนะนำ: กรอกข้อมูลต่อเนื่องจากแถวที่ 5 ลงมา ห้ามเว้นแถวว่างคั่น · คอลัมน์สีเทา (Number Code, Magazine / Slot) ถูกล็อกอัตโนมัติ · แถวท้ายที่ไม่ใช้เว้นว่างได้ · ดูตัวอย่างและรหัส Defects ที่แท็บ "%s"',
        IMPORT_GUIDE_SHEET_TITLE
    );
    $rows[]   = ['height' => 22, 'cells' => $r3];
    $merges[] = "A3:{$lastCol}3";

    // Row 4: Table Headers (matching export headers in includes/xlsx-writer.php)
    $headers = [
        ['Number Code',     AoiXlsxStyle::TH_SLATE],
        ['Shelf',           AoiXlsxStyle::TH_CYAN],
        ['Product Model',   AoiXlsxStyle::TH_BLUE],
        ['Side',            AoiXlsxStyle::TH_INDIGO],
        ['Board Type',      AoiXlsxStyle::TH_INDIGO],
        ['Status',          AoiXlsxStyle::TH_EMERALD],
        ['PCS P/D (Other)', AoiXlsxStyle::TH_VIOLET],
        ['Defects',         AoiXlsxStyle::TH_AMBER],
        ['Magazine / Slot', AoiXlsxStyle::TH_SLATE],
    ];
    $r4 = [];
    foreach ($headers as [$hText, $hStyle]) {
        $r4[] = ['v' => $hText, 's' => $hStyle];
    }
    $rows[] = ['height' => 26, 'cells' => $r4];

    // Rows 5 .. (4 + $rowCount): Pre-filled Number Code + Editable B..H + Auto Formula Col I
    for ($i = 0; $i < $rowCount; $i++) {
        $excelRow = 5 + $i;
        $code     = sprintf('%s-%03d', $mPrefix, $startRun + $i);
        $formula  = buildImportTemplateMagSlotFormula($prefix, $excelRow, $maxShelf);

        $rows[] = [
            'height' => 21,
            'cells'  => [
                ['v' => $code, 's' => AoiXlsxStyle::TD_LOCKED_CODE],                 // A: Number Code (locked)
                ['v' => '',    's' => AoiXlsxStyle::TD_EDIT_SHELF],                  // B: Shelf (unlocked)
                ['v' => '',    's' => AoiXlsxStyle::TD_EDIT_TEXT],                   // C: Product Model (unlocked)
                ['v' => '',    's' => AoiXlsxStyle::TD_EDIT_CENTER],                 // D: Side (unlocked)
                ['v' => '',    's' => AoiXlsxStyle::TD_EDIT_CENTER],                 // E: Board Type (unlocked)
                ['v' => '',    's' => AoiXlsxStyle::TD_EDIT_CENTER],                 // F: Status (unlocked)
                ['v' => '',    's' => AoiXlsxStyle::TD_EDIT_CENTER],                 // G: PCS P/D (Other) (unlocked)
                ['v' => '',    's' => AoiXlsxStyle::TD_EDIT_TEXT],                   // H: Defects (unlocked)
                ['v' => '',    's' => AoiXlsxStyle::TD_LOCKED_AUTO, 'f' => $formula], // I: Magazine / Slot (locked formula)
            ],
        ];
    }

    $lastDataRow = 4 + $rowCount;

    $options = [
        'sheetProtection' => true,
        'dataValidations' => [
            [
                'sqref'      => "D5:D{$lastDataRow}",
                'list'       => BOARD_SIDES,
                'allowBlank' => true,
            ],
            [
                'sqref'      => "E5:E{$lastDataRow}",
                'list'       => BOARD_TYPES,
                'allowBlank' => true,
            ],
            [
                'sqref'      => "F5:F{$lastDataRow}",
                'list'       => ['G', 'D', 'G+D'],
                'allowBlank' => true,
            ],
            [
                'sqref'      => "G5:G{$lastDataRow}",
                'list'       => OTHER_OPTIONS,
                'allowBlank' => true,
            ],
        ],
    ];

    return [
        'title' => IMPORT_DATA_SHEET_TITLE,
        'xml'   => buildWorksheetXml($rows, $colWidths, 4, 0, null, $merges, $options),
    ];
}

/**
 * Build Worksheet 2: วิธีใช้ (Guide) — Bilingual rules, examples, and full Defect codes reference.
 *
 * @param array<string,mixed> $ctx
 * @param string              $generatedAt
 */
function buildImportGuideSheet(array $ctx, string $generatedAt): array
{
    $colWidths = [22, 28, 22, 22, 34];
    $colCount  = count($colWidths);
    $lastCol   = xlsxColLetter($colCount);
    $prefix    = (string)$ctx['shelf_prefix'];
    $maxShelf  = (int)$ctx['max_shelf_no'];
    $magCount  = (int)$ctx['mag_count'];

    $rows   = [];
    $merges = [];

    // Row 1: Title
    $r1 = array_fill(0, $colCount, ['v' => '', 's' => AoiXlsxStyle::TITLE_BANNER]);
    $r1[0]['v'] = 'คู่มือการกรอกแม่แบบนำเข้าข้อมูลบอร์ด AOI (Import Template Guide — TH / EN)';
    $rows[]     = ['height' => 30, 'cells' => $r1];
    $merges[]   = "A1:{$lastCol}1";

    // Row 2: Bound Context Summary
    $r2 = array_fill(0, $colCount, ['v' => '', 's' => AoiXlsxStyle::SUBTITLE_META]);
    $r2[0]['v'] = sprintf(
        'แม่แบบนี้ผูกกับ Zone: %s (%s)  |  Sheet: %s  |  Shelf ที่อนุญาต: %s1 ถึง %s%d (%d ตู้ Magazine)  |  Generated: %s',
        $ctx['zone'],
        $ctx['zone_label'],
        $ctx['sheet'],
        $prefix,
        $prefix,
        $maxShelf,
        $magCount,
        $generatedAt
    );
    $rows[]   = ['height' => 22, 'cells' => $r2];
    $merges[] = "A2:{$lastCol}2";

    // Row 3: Section 1 Header — Golden Rules
    $r3 = array_fill(0, $colCount, ['v' => '', 's' => AoiXlsxStyle::TH_BLUE]);
    $r3[0]['v'] = '1. กติกาสำคัญที่ระบบตรวจสอบอัตโนมัติ (Mandatory Validation Rules)';
    $rows[]     = ['height' => 24, 'cells' => $r3];
    $merges[]   = "A3:{$lastCol}3";

    $rules = [
        [
            'Number Code (ห้ามแก้)',
            'รหัสถูกสร้างล่วงหน้าต่อจากรหัสล่าสุดในระบบ ห้ามแก้ไข ลบ หรือสลับลำดับ แถวท้ายที่ไม่ได้ใช้ให้เว้นว่างทั้งแถว ระบบจะข้ามให้อัตโนมัติและไม่เสียรหัส',
        ],
        [
            'การกรอกต่อเนื่อง (Contiguous)',
            'ต้องกรอกข้อมูลไล่จากแถวแรก (แถว 5) ลงมาตามลำดับ ห้ามเว้นแถวว่างคั่นกลางระหว่างแถวที่มีข้อมูล',
        ],
        [
            'Shelf (ห้ามซ้ำ & ต้องมีอยู่จริง)',
            sprintf(
                'ระบุรหัสชั้นวางของชีต %s ในช่วง %s1 ถึง %s%d เท่านั้น (ไม่สนใจตัวพิมพ์เล็ก-ใหญ่) ต้องเป็นช่องที่ยังว่างในระบบ และห้ามซ้ำกันเองในไฟล์',
                $ctx['sheet'],
                $prefix,
                $prefix,
                $maxShelf
            ),
        ],
        [
            'Product Model / Side / Board Type',
            'Product Model ความยาวไม่เกิน 40 ตัวอักษร (ระบบแปลงเป็นตัวพิมพ์ใหญ่อัตโนมัติ) · Side เลือก F-Side หรือ B-Side · Board Type เลือก ES3261A, Aluminum หรือ L67068',
        ],
        [
            'Status & PCS P/D (Other)',
            'กรณีบอร์ดปกติให้เลือก Status เป็น G, D หรือ G+D · กรณีเลือก PCS P/D (Other) เป็น Bare หรือ Bad Mark ต้องเว้นว่างช่อง Status และ Defects เสมอ',
        ],
        [
            'Defects (เมื่อ Status = D หรือ G+D)',
            'เมื่อ Status เป็น D หรือ G+D ต้องระบุรหัส Defect อย่างน้อย 1 รายการ (ใช้เลขรหัส 1–19 คั่นด้วยจุลภาค เช่น "1, 5, 12" หรือระบุ "OTHER:รายละเอียด") · หาก Status เป็น G ต้องเว้นว่าง',
        ],
        [
            'Magazine / Slot (คำนวณอัตโนมัติ)',
            'คอลัมน์ Magazine / Slot ถูกล็อกไว้เพื่อแสดงตัวอย่างเท่านั้น เมื่อ Import ระบบจะคำนวณจาก Shelf ที่เซิร์ฟเวอร์โดยตรง',
        ],
    ];

    foreach ($rules as [$topic, $detail]) {
        $rIdx = count($rows) + 1;
        $rowCells = array_fill(0, $colCount, ['v' => '', 's' => AoiXlsxStyle::TD_TEXT_EVEN]);
        $rowCells[0] = ['v' => $topic, 's' => AoiXlsxStyle::LEGEND_HEAD];
        $rowCells[1] = ['v' => $detail, 's' => AoiXlsxStyle::TD_TEXT_EVEN];
        $rows[]      = ['height' => 22, 'cells' => $rowCells];
        $merges[]    = "B{$rIdx}:{$lastCol}{$rIdx}";
    }

    // Section 2: Example Rows
    $exHeadIdx = count($rows) + 1;
    $rExHead = array_fill(0, $colCount, ['v' => '', 's' => AoiXlsxStyle::TH_EMERALD]);
    $rExHead[0]['v'] = '2. ตัวอย่างการกรอกข้อมูลที่ถูกต้อง (Valid Input Examples)';
    $rows[]          = ['height' => 24, 'cells' => $rExHead];
    $merges[]        = "A{$exHeadIdx}:{$lastCol}{$exHeadIdx}";

    $rows[] = [
        'height' => 22,
        'cells'  => [
            ['v' => 'กรณี (Scenario)', 's' => AoiXlsxStyle::TH_SLATE],
            ['v' => 'Shelf / Product Model', 's' => AoiXlsxStyle::TH_SLATE],
            ['v' => 'Side / Board Type', 's' => AoiXlsxStyle::TH_SLATE],
            ['v' => 'Status / Other', 's' => AoiXlsxStyle::TH_SLATE],
            ['v' => 'Defects', 's' => AoiXlsxStyle::TH_SLATE],
        ],
    ];

    $rows[] = [
        'height' => 21,
        'cells'  => [
            ['v' => 'บอร์ด G (Good Board)', 's' => AoiXlsxStyle::TD_STATUS_G],
            ['v' => "{$prefix}1  /  PROD-ALPHA-01", 's' => AoiXlsxStyle::TD_TEXT_EVEN],
            ['v' => 'F-Side  /  ES3261A', 's' => AoiXlsxStyle::TD_CENTER_EVEN],
            ['v' => 'Status = G  (Other เว้นว่าง)', 's' => AoiXlsxStyle::TD_CENTER_EVEN],
            ['v' => '(เว้นว่าง / Leave blank)', 's' => AoiXlsxStyle::TD_TEXT_EVEN],
        ],
    ];

    $rows[] = [
        'height' => 21,
        'cells'  => [
            ['v' => 'บอร์ด D หรือ G+D (มี Defect)', 's' => AoiXlsxStyle::TD_STATUS_GD],
            ['v' => "{$prefix}2  /  PROD-BETA-02", 's' => AoiXlsxStyle::TD_TEXT_ODD],
            ['v' => 'B-Side  /  Aluminum', 's' => AoiXlsxStyle::TD_CENTER_ODD],
            ['v' => 'Status = G+D  (Other เว้นว่าง)', 's' => AoiXlsxStyle::TD_CENTER_ODD],
            ['v' => '1, 5, OTHER:Scratch near IC3', 's' => AoiXlsxStyle::TD_TEXT_ODD],
        ],
    ];

    $rows[] = [
        'height' => 21,
        'cells'  => [
            ['v' => 'บอร์ด Bare / Bad Mark', 's' => AoiXlsxStyle::TD_OTHER_BARE],
            ['v' => "{$prefix}3  /  PROD-GAMMA-03", 's' => AoiXlsxStyle::TD_TEXT_EVEN],
            ['v' => 'F-Side  /  L67068', 's' => AoiXlsxStyle::TD_CENTER_EVEN],
            ['v' => 'Status เว้นว่าง  (Other = Bare)', 's' => AoiXlsxStyle::TD_CENTER_EVEN],
            ['v' => '(เว้นว่าง / Leave blank)', 's' => AoiXlsxStyle::TD_TEXT_EVEN],
        ],
    ];

    // Section 3: Defect Codes Reference Table
    $defHeadIdx = count($rows) + 1;
    $rDefHead = array_fill(0, $colCount, ['v' => '', 's' => AoiXlsxStyle::TH_AMBER]);
    $rDefHead[0]['v'] = '3. ตารางรหัส Defects มาตรฐาน 19 รายการ (Standard Defect Codes Reference)';
    $rows[]           = ['height' => 24, 'cells' => $rDefHead];
    $merges[]         = "A{$defHeadIdx}:{$lastCol}{$defHeadIdx}";

    $rows[] = [
        'height' => 22,
        'cells'  => [
            ['v' => 'รหัสที่กรอก (Code)', 's' => AoiXlsxStyle::TH_SLATE],
            ['v' => 'ชื่อ Defect (Label)', 's' => AoiXlsxStyle::TH_SLATE],
            ['v' => 'ตัวอย่างการกรอก', 's' => AoiXlsxStyle::TH_SLATE],
            ['v' => 'หมายเหตุ (Notes)', 's' => AoiXlsxStyle::TH_SLATE],
            ['v' => '', 's' => AoiXlsxStyle::TH_SLATE],
        ],
    ];
    $hdrRowNum = count($rows);
    $merges[]  = "D{$hdrRowNum}:{$lastCol}{$hdrRowNum}";

    foreach (DEFECT_LABELS as $code => $label) {
        $rNum = count($rows) + 1;
        $isEven = ((int)$code % 2 === 0);
        $style  = $isEven ? AoiXlsxStyle::TD_TEXT_EVEN : AoiXlsxStyle::TD_TEXT_ODD;
        $cStyle = $isEven ? AoiXlsxStyle::TD_MONO_EVEN : AoiXlsxStyle::TD_MONO_ODD;

        $rows[] = [
            'height' => 20,
            'cells'  => [
                ['v' => (string)$code, 's' => $cStyle],
                ['v' => $label, 's' => $style],
                ['v' => (string)$code . ' หรือ ' . $label, 's' => $style],
                ['v' => 'คั่นหลายรหัสด้วยเครื่องหมายจุลภาค (,)', 's' => $style],
                ['v' => '', 's' => $style],
            ],
        ];
        $merges[] = "D{$rNum}:{$lastCol}{$rNum}";
    }

    // Add OTHER:<detail> row
    $rNum = count($rows) + 1;
    $rows[] = [
        'height' => 20,
        'cells'  => [
            ['v' => 'OTHER:<รายละเอียด>', 's' => AoiXlsxStyle::TD_MONO_ODD],
            ['v' => 'Other (ระบุรายละเอียดเพิ่มเติม)', 's' => AoiXlsxStyle::TD_TEXT_ODD],
            ['v' => 'OTHER:Solder ball on pin 4', 's' => AoiXlsxStyle::TD_TEXT_ODD],
            ['v' => 'ใช้เมื่อต้องการระบุ Defect อื่นนอกเหนือจากรหัส 1–19', 's' => AoiXlsxStyle::TD_TEXT_ODD],
            ['v' => '', 's' => AoiXlsxStyle::TD_TEXT_ODD],
        ],
    ];
    $merges[] = "D{$rNum}:{$lastCol}{$rNum}";

    return [
        'title' => IMPORT_GUIDE_SHEET_TITLE,
        'xml'   => buildWorksheetXml($rows, $colWidths, 2, 0, null, $merges, ['sheetProtection' => true]),
    ];
}

/**
 * Build Worksheet 3: Hidden _meta sheet with HMAC-SHA256 integrity signature.
 *
 * @param array<string,mixed> $meta
 */
function buildImportMetaSheet(array $meta): array
{
    $colWidths = [24, 68];
    $rows      = [
        [
            'height' => 20,
            'cells'  => [
                ['v' => 'key',   's' => AoiXlsxStyle::TH_SLATE],
                ['v' => 'value', 's' => AoiXlsxStyle::TH_SLATE],
            ],
        ],
    ];

    $orderedKeys = [
        'version',
        'zone',
        'sheet',
        'shelf_prefix',
        'year_month',
        'start_code',
        'end_code',
        'row_count',
        'generated_at',
        'checksum',
    ];

    foreach ($orderedKeys as $k) {
        $rows[] = [
            'height' => 20,
            'cells'  => [
                ['v' => $k,                    's' => AoiXlsxStyle::TD_MONO_EVEN],
                ['v' => (string)($meta[$k] ?? ''), 's' => AoiXlsxStyle::TD_TEXT_EVEN],
            ],
        ];
    }

    return [
        'title' => IMPORT_META_SHEET_TITLE,
        'state' => 'hidden',
        'xml'   => buildWorksheetXml($rows, $colWidths, 1, 0, null, [], ['sheetProtection' => true]),
    ];
}

/**
 * Generate a complete Import Template .xlsx workbook and return binary bytes + metadata.
 *
 * @return array{
 *   ok:bool,
 *   error:?string,
 *   bytes:string,
 *   filename:string,
 *   meta:array<string,mixed>,
 *   context:array<string,mixed>
 * }
 */
function buildImportTemplateWorkbook(
    PDO $pdo,
    string $zone,
    string $sheet,
    int $requestedRows = IMPORT_DEFAULT_ROWS
): array {
    $ctx = getImportTemplateContext($pdo, $zone, $sheet, $requestedRows);
    if (!$ctx['ok']) {
        return [
            'ok'       => false,
            'error'    => $ctx['error'],
            'bytes'    => '',
            'filename' => '',
            'meta'     => [],
            'context'  => $ctx,
        ];
    }

    $tz          = new DateTimeZone('Asia/Bangkok');
    $now         = new DateTimeImmutable('now', $tz);
    $generatedAt = $now->format('Y-m-d H:i:s');
    $fileStamp   = $now->format('Ymd_His');

    $meta = [
        'version'      => IMPORT_TEMPLATE_VERSION,
        'zone'         => $ctx['zone'],
        'sheet'        => $ctx['sheet'],
        'shelf_prefix' => $ctx['shelf_prefix'],
        'year_month'   => $ctx['year_month'],
        'start_code'   => $ctx['start_code'],
        'end_code'     => $ctx['end_code'],
        'row_count'    => (int)$ctx['actual_rows'],
        'generated_at' => $generatedAt,
    ];
    $meta['checksum'] = computeImportMetaChecksum($meta);

    $sheets = [
        buildImportDataSheet($ctx, $generatedAt),
        buildImportGuideSheet($ctx, $generatedAt),
        buildImportMetaSheet($meta),
    ];

    $bytes = buildXlsxWorkbookBytes($sheets);

    $safeZone  = preg_replace('/[^A-Za-z0-9_-]+/', '_', $ctx['zone']) ?? 'Zone';
    $safeSheet = preg_replace('/[^A-Za-z0-9_-]+/', '_', $ctx['sheet']) ?? 'Sheet';
    $filename  = sprintf(
        'AOI_Import_Template_%s_%s_%s-%03d_to_%03d_%s.xlsx',
        $safeZone,
        $safeSheet,
        $ctx['month_prefix'],
        $ctx['start_run'],
        $ctx['end_run'],
        $fileStamp
    );

    return [
        'ok'       => true,
        'error'    => null,
        'bytes'    => $bytes,
        'filename' => $filename,
        'meta'     => $meta,
        'context'  => $ctx,
    ];
}

/**
 * Build a downloadable .xlsx Error Report from a preview/validation result.
 *
 * @param array<string,mixed> $validationResult Output of validateImportWorkbook()
 */
function buildImportErrorReportBytes(array $validationResult): string
{
    $colWidths = [10, 20, 12, 24, 12, 15, 12, 16, 22, 24, 14, 54];
    $colCount  = count($colWidths);
    $lastCol   = xlsxColLetter($colCount);

    $meta    = $validationResult['meta'] ?? [];
    $summary = $validationResult['summary'] ?? [];
    $rowList = $validationResult['rows'] ?? [];
    $fileErr = $validationResult['errors'] ?? [];

    $rows   = [];
    $merges = [];

    // Row 1: Title
    $r1 = array_fill(0, $colCount, ['v' => '', 's' => AoiXlsxStyle::TITLE_BANNER]);
    $r1[0]['v'] = 'AOI Import Validation Report — รายงานผลการตรวจสอบไฟล์นำเข้าข้อมูลบอร์ด';
    $rows[]     = ['height' => 28, 'cells' => $r1];
    $merges[]   = "A1:{$lastCol}1";

    // Row 2: Summary
    $r2 = array_fill(0, $colCount, ['v' => '', 's' => AoiXlsxStyle::SUBTITLE_META]);
    $r2[0]['v'] = sprintf(
        'Zone: %s  |  Sheet: %s  |  แถวที่กรอก: %d  |  ผ่าน: %d  |  พบปัญหา: %d  |  แถวว่างที่ข้าม: %d',
        (string)($meta['zone'] ?? '-'),
        (string)($meta['sheet'] ?? '-'),
        (int)($summary['filled_rows'] ?? 0),
        (int)($summary['valid_rows'] ?? 0),
        (int)($summary['error_rows'] ?? 0),
        (int)($summary['skipped_blank_rows'] ?? 0)
    );
    $rows[]   = ['height' => 22, 'cells' => $r2];
    $merges[] = "A2:{$lastCol}2";

    // Row 3: Global Errors Summary (if any)
    $r3 = array_fill(0, $colCount, ['v' => '', 's' => AoiXlsxStyle::KPI_SUMMARY]);
    $r3[0]['v'] = !empty($fileErr)
        ? ('ข้อผิดพลาดระดับไฟล์: ' . implode('  •  ', array_slice($fileErr, 0, 3)))
        : 'ตรวจสอบรายแถวด้านล่าง — แก้ไขเฉพาะแถวที่ขึ้นสถานะ "ไม่ผ่าน (ERROR)" ในไฟล์แม่แบบแล้วอัปโหลดใหม่อีกครั้ง';
    $rows[]   = ['height' => 22, 'cells' => $r3];
    $merges[] = "A3:{$lastCol}3";

    // Row 4: Table Headers
    $headers = [
        ['แถว Excel',        AoiXlsxStyle::TH_SLATE],
        ['Number Code',     AoiXlsxStyle::TH_SLATE],
        ['Shelf',           AoiXlsxStyle::TH_CYAN],
        ['Product Model',   AoiXlsxStyle::TH_BLUE],
        ['Side',            AoiXlsxStyle::TH_INDIGO],
        ['Board Type',      AoiXlsxStyle::TH_INDIGO],
        ['Status',          AoiXlsxStyle::TH_EMERALD],
        ['PCS P/D (Other)', AoiXlsxStyle::TH_VIOLET],
        ['Defects',         AoiXlsxStyle::TH_AMBER],
        ['Magazine / Slot', AoiXlsxStyle::TH_SLATE],
        ['ผลการตรวจ',        AoiXlsxStyle::TH_ROSE],
        ['รายละเอียดข้อผิดพลาด (Error Details)', AoiXlsxStyle::TH_ROSE],
    ];
    $r4 = [];
    foreach ($headers as [$hText, $hStyle]) {
        $r4[] = ['v' => $hText, 's' => $hStyle];
    }
    $rows[] = ['height' => 26, 'cells' => $r4];

    foreach ($rowList as $r) {
        $hasErr   = !empty($r['errors']);
        $errText  = $hasErr ? implode(' | ', $r['errors']) : 'ผ่านการตรวจสอบ (Ready to Import)';
        $resBadge = $hasErr ? 'ไม่ผ่าน (ERROR)' : 'ผ่าน (OK)';
        $resStyle = $hasErr ? AoiXlsxStyle::TD_STATUS_D : AoiXlsxStyle::TD_STATUS_G;
        $txtStyle = $hasErr ? AoiXlsxStyle::TD_DEFECT_HIT : AoiXlsxStyle::TD_TEXT_EVEN;

        $rows[] = [
            'height' => 21,
            'cells'  => [
                ['v' => (int)($r['excel_row'] ?? 0),        's' => AoiXlsxStyle::TD_MONO_EVEN, 't' => 'n'],
                ['v' => (string)($r['code'] ?? ''),         's' => AoiXlsxStyle::TD_MONO_EVEN],
                ['v' => (string)($r['shelf'] ?? ''),        's' => AoiXlsxStyle::TD_SHELF],
                ['v' => (string)($r['product'] ?? ''),      's' => AoiXlsxStyle::TD_TEXT_EVEN],
                ['v' => (string)($r['side'] ?? ''),         's' => AoiXlsxStyle::TD_CENTER_EVEN],
                ['v' => (string)($r['board_type'] ?? ''),   's' => AoiXlsxStyle::TD_CENTER_EVEN],
                ['v' => (string)($r['status_raw'] ?? $r['status'] ?? ''), 's' => AoiXlsxStyle::TD_CENTER_EVEN],
                ['v' => (string)($r['pcs_status'] ?? ''),   's' => AoiXlsxStyle::TD_CENTER_EVEN],
                ['v' => (string)($r['defects_raw'] ?? $r['defects'] ?? ''), 's' => AoiXlsxStyle::TD_TEXT_EVEN],
                ['v' => (string)($r['mag_slot'] ?? '-'),    's' => AoiXlsxStyle::TD_TEXT_EVEN],
                ['v' => $resBadge,                          's' => $resStyle],
                ['v' => $errText,                           's' => $txtStyle],
            ],
        ];
    }

    $sheet = [
        'title' => 'Import_Validation_Report',
        'xml'   => buildWorksheetXml($rows, $colWidths, 4, 0, 'A4:L' . max(4, count($rows)), $merges),
    ];

    return buildXlsxWorkbookBytes([$sheet]);
}
