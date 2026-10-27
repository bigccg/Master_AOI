<?php
/**
 * Pure-PHP OpenXML (.xlsx) Reader — Zero external dependencies
 *
 * Parses .xlsx archives using a built-in ZIP Central Directory + gzinflate() reader
 * (does not require ext-zip / ZipArchive) with strict security guards:
 *   - Magic bytes verification (PK\x03\x04) & file size cap (<= 2 MB)
 *   - Zip-bomb protection (entry count cap <= 64, total uncompressed cap <= 16 MB)
 *   - XXE / Entity expansion prevention (rejects <!DOCTYPE and <!ENTITY, uses LIBXML_NONET)
 *   - Reads sharedStrings.xml, inlineStr, str/n/b values, ignores <f> formulas
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Convert Excel column letters (e.g. "A", "I", "AA") to 1-based column index.
 */
function xlsxColIndexFromLetters(string $letters): int
{
    $letters = strtoupper(trim($letters));
    $len     = strlen($letters);
    $col     = 0;
    for ($i = 0; $i < $len; $i++) {
        $ch = ord($letters[$i]);
        if ($ch < 65 || $ch > 90) {
            return 0;
        }
        $col = ($col * 26) + ($ch - 64);
    }
    return $col;
}

/**
 * Split cell reference like "B14" or "$B$14" into [colIndex (1-based), rowNumber (1-based)].
 *
 * @return array{0:int,1:int}
 */
function xlsxSplitCellRef(string $ref, int $fallbackCol, int $fallbackRow): array
{
    $clean = str_replace('$', '', strtoupper(trim($ref)));
    if (preg_match('/^([A-Z]{1,3})(\d{1,7})$/', $clean, $m) === 1) {
        return [xlsxColIndexFromLetters($m[1]), (int)$m[2]];
    }
    return [$fallbackCol, $fallbackRow];
}

/**
 * Safely parse an XML string into a SimpleXMLElement with XXE & DOCTYPE blocks disabled.
 *
 * @throws RuntimeException if XML is malformed or contains forbidden DOCTYPE/ENTITY declarations.
 */
function xlsxSafeParseXml(string $xmlContent, string $entryName = 'xml'): SimpleXMLElement
{
    if ($xmlContent === '') {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR . " (Empty XML: {$entryName})");
    }

    // Strip UTF-8 BOM if present
    if (strncmp($xmlContent, "\xEF\xBB\xBF", 3) === 0) {
        $xmlContent = substr($xmlContent, 3);
    }

    // Security guard: strictly forbid DOCTYPE or ENTITY declarations to prevent XXE / Billion Laughs
    if (preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $xmlContent) === 1) {
        throw new RuntimeException(
            'ไฟล์ไม่ปลอดภัย: ตรวจพบคำสั่ง XML DOCTYPE/ENTITY ที่ไม่อนุญาต กรุณาใช้ไฟล์แม่แบบจากระบบเท่านั้น / '
            . 'Unsafe XML detected: DOCTYPE/ENTITY declarations are forbidden.'
        );
    }

    $prevInternal = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $flags = LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT;
    $sx    = simplexml_load_string($xmlContent, SimpleXMLElement::class, $flags);

    libxml_clear_errors();
    libxml_use_internal_errors($prevInternal);

    if ($sx === false) {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }

    return $sx;
}

/**
 * Extract ZIP entries from raw .xlsx bytes using Central Directory parsing + gzinflate().
 *
 * @return array<string,string> Map of normalized internal path => uncompressed content
 * @throws RuntimeException on invalid ZIP structure, unsupported compression, or zip-bomb limits
 */
function xlsxExtractZipEntriesFromBytes(string $bytes): array
{
    $len = strlen($bytes);
    if ($len < 22 || $len > IMPORT_MAX_FILE_BYTES) {
        if ($len > IMPORT_MAX_FILE_BYTES) {
            throw new RuntimeException(
                'ไฟล์มีขนาดใหญ่เกินกำหนด (สูงสุด 2 MB) กรุณาลดขนาดไฟล์หรือแบ่งการนำเข้า / '
                . 'File size exceeds the 2 MB maximum limit.'
            );
        }
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }

    // Verify PK\x03\x04 magic signature at offset 0
    if ( strncmp($bytes, "PK\x03\x04", 4) !== 0 ) {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }

    // Locate End of Central Directory (EOCD) signature PK\x05\x06 near the end of the archive
    $maxComment = min($len, 65557);
    $eocdPos    = strrpos(substr($bytes, $len - $maxComment), "PK\x05\x06");
    if ($eocdPos === false) {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }
    $eocdOffset = ($len - $maxComment) + $eocdPos;
    if ($eocdOffset + 22 > $len) {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }

    $eocd = unpack('vdisk/vcdDisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLen', substr($bytes, $eocdOffset + 4, 18));
    if (!is_array($eocd)) {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }

    $totalEntries = (int)$eocd['totalEntries'];
    $cdOffset     = (int)$eocd['cdOffset'];
    $cdSize       = (int)$eocd['cdSize'];

    if ($totalEntries <= 0 || $totalEntries > IMPORT_MAX_ZIP_ENTRIES) {
        if ($totalEntries > IMPORT_MAX_ZIP_ENTRIES) {
            throw new RuntimeException(
                'โครงสร้างไฟล์ซับซ้อนเกินกำหนด (จำนวนไฟล์ย่อยใน .xlsx เกินขีดจำกัดความปลอดภัย) / '
                . 'Archive contains too many internal entries.'
            );
        }
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }

    if ($cdOffset < 0 || $cdSize < 0 || ($cdOffset + $cdSize) > $eocdOffset) {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }

    $entries          = [];
    $totalUncompressed = 0;
    $pos              = $cdOffset;

    for ($i = 0; $i < $totalEntries; $i++) {
        if ($pos + 46 > $len || substr($bytes, $pos, 4) !== "PK\x01\x02") {
            throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
        }

        $cd = unpack(
            'vverMade/vverNeed/vflags/vmethod/vmtime/vmdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen/vcommentLen/vdiskStart/vintAttr/VextAttr/VlocalOffset',
            substr($bytes, $pos + 4, 42)
        );
        if (!is_array($cd)) {
            throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
        }

        $nameLen    = (int)$cd['nameLen'];
        $extraLen   = (int)$cd['extraLen'];
        $commentLen = (int)$cd['commentLen'];
        $entryName  = substr($bytes, $pos + 46, $nameLen);
        $pos       += 46 + $nameLen + $extraLen + $commentLen;

        // Normalize path
        $normName = ltrim(str_replace('\\', '/', $entryName), '/');
        if ($normName === '' || str_ends_with($normName, '/')) {
            continue;
        }
        if (str_contains($normName, '../')) {
            throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
        }

        $compSize   = (int)$cd['compSize'];
        $uncompSize = (int)$cd['uncompSize'];
        $method     = (int)$cd['method'];
        $localOff   = (int)$cd['localOffset'];

        if ($uncompSize < 0 || $compSize < 0 || ($totalUncompressed + $uncompSize) > IMPORT_MAX_UNCOMPRESSED_BYTES) {
            throw new RuntimeException(
                'ข้อมูลภายในไฟล์ขยายตัวเกินขีดจำกัดความปลอดภัย กรุณาใช้ไฟล์แม่แบบมาตรฐาน / '
                . 'Uncompressed archive data exceeds safety limits.'
            );
        }

        if ($localOff < 0 || $localOff + 30 > $len || substr($bytes, $localOff, 4) !== "PK\x03\x04") {
            throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
        }

        $lh = unpack('vver/vflags/vmethod/vmtime/vmdate/Vcrc/VcompSize/VuncompSize/vnameLen/vextraLen', substr($bytes, $localOff + 4, 26));
        if (!is_array($lh)) {
            throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
        }

        $dataOffset = $localOff + 30 + (int)$lh['nameLen'] + (int)$lh['extraLen'];
        if ($dataOffset < 0 || ($dataOffset + $compSize) > $len) {
            throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
        }

        $rawData = $compSize > 0 ? substr($bytes, $dataOffset, $compSize) : '';

        if ($method === 0) {
            // Stored (uncompressed)
            $content = $rawData;
        } elseif ($method === 8) {
            // Deflated
            $inflated = @gzinflate($rawData, IMPORT_MAX_UNCOMPRESSED_BYTES);
            if ($inflated === false) {
                throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
            }
            $content = $inflated;
        } else {
            throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
        }

        $actualLen         = strlen($content);
        $totalUncompressed += $actualLen;
        if ($totalUncompressed > IMPORT_MAX_UNCOMPRESSED_BYTES) {
            throw new RuntimeException(
                'ข้อมูลภายในไฟล์ขยายตัวเกินขีดจำกัดความปลอดภัย กรุณาใช้ไฟล์แม่แบบมาตรฐาน / '
                . 'Uncompressed archive data exceeds safety limits.'
            );
        }

        $entries[$normName] = $content;
    }

    return $entries;
}

/**
 * Extract plain text from a SimpleXMLElement representing a string item (<si> or <is>).
 */
function xlsxExtractTextNode(SimpleXMLElement $node): string
{
    // Direct <t> child
    if (isset($node->t)) {
        return (string)$node->t;
    }
    // Rich text runs <r><t>...</t></r>
    if (isset($node->r)) {
        $buf = '';
        foreach ($node->r as $run) {
            if (isset($run->t)) {
                $buf .= (string)$run->t;
            }
        }
        return $buf;
    }
    return '';
}

/**
 * Parse sharedStrings.xml if present in the archive.
 *
 * @param array<string,string> $entries
 * @return array<int,string>
 */
function xlsxParseSharedStrings(array $entries): array
{
    if (!isset($entries['xl/sharedStrings.xml'])) {
        return [];
    }

    $sx = xlsxSafeParseXml($entries['xl/sharedStrings.xml'], 'xl/sharedStrings.xml');
    $sx->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $strings = [];
    foreach ($sx->si as $si) {
        $strings[] = xlsxExtractTextNode($si);
    }

    return $strings;
}

/**
 * Parse a single worksheet XML into a list of row records.
 *
 * Each row record:
 *   [
 *     'excel_row' => int (1-based Excel row number),
 *     'cells'     => array<int,string> (1-based col index => trimmed string value)
 *   ]
 *
 * @param array<int,string> $sharedStrings
 * @return array<int,array{excel_row:int,cells:array<int,string>}>
 */
function xlsxParseWorksheetXml(string $xmlContent, array $sharedStrings, string $entryName = 'worksheet'): array
{
    $sx = xlsxSafeParseXml($xmlContent, $entryName);
    if (!isset($sx->sheetData)) {
        return [];
    }

    $rows       = [];
    $nextRowNum = 1;

    foreach ($sx->sheetData->row as $rowNode) {
        $rAttr  = isset($rowNode['r']) ? (int)$rowNode['r'] : $nextRowNum;
        $rowNum = $rAttr > 0 ? $rAttr : $nextRowNum;
        $nextRowNum = $rowNum + 1;

        if (count($rows) >= 2000) {
            throw new RuntimeException(
                'จำนวนแถวในชีตเกินขีดจำกัด (สูงสุด 999 แถวข้อมูลต่อครั้ง) / '
                . 'Worksheet exceeds maximum allowed row count (999 rows).'
            );
        }

        $cells      = [];
        $nextColIdx = 1;

        foreach ($rowNode->c as $cellNode) {
            $refAttr = isset($cellNode['r']) ? (string)$cellNode['r'] : '';
            [$colIdx, $_] = $refAttr !== ''
                ? xlsxSplitCellRef($refAttr, $nextColIdx, $rowNum)
                : [$nextColIdx, $rowNum];
            if ($colIdx <= 0) {
                $colIdx = $nextColIdx;
            }
            $nextColIdx = $colIdx + 1;

            // Ignore overly wide columns beyond Z (col 26)
            if ($colIdx > 26) {
                continue;
            }

            $type = isset($cellNode['t']) ? (string)$cellNode['t'] : '';
            $val  = '';

            // Note: <f> formula nodes are intentionally ignored; we only extract literal/cached values
            if ($type === 'inlineStr') {
                if (isset($cellNode->is)) {
                    $val = xlsxExtractTextNode($cellNode->is);
                } elseif (isset($cellNode->v)) {
                    $val = (string)$cellNode->v;
                }
            } elseif ($type === 's') {
                if (isset($cellNode->v)) {
                    $ssIdx = (int)$cellNode->v;
                    $val   = $sharedStrings[$ssIdx] ?? '';
                }
            } elseif ($type === 'b') {
                if (isset($cellNode->v)) {
                    $raw = trim((string)$cellNode->v);
                    $val = ($raw === '1' || strcasecmp($raw, 'true') === 0) ? 'TRUE' : 'FALSE';
                }
            } elseif ($type === 'e') {
                // Formula error (#VALUE!, #REF!, etc.) -> treat as empty string if it was a formula cell
                $val = isset($cellNode->f) ? '' : (isset($cellNode->v) ? (string)$cellNode->v : '');
            } else {
                // Default numeric or 'str' cached string
                if (isset($cellNode->v)) {
                    $val = (string)$cellNode->v;
                } elseif (isset($cellNode->is)) {
                    $val = xlsxExtractTextNode($cellNode->is);
                }
            }

            $cells[$colIdx] = trim($val);
        }

        $rows[] = [
            'excel_row' => $rowNum,
            'cells'     => $cells,
        ];
    }

    return $rows;
}

/**
 * Parse raw .xlsx bytes into structured sheets map.
 *
 * Returns:
 *   [
 *     'sheets' => [
 *       SheetName => [
 *         'name'  => string,
 *         'state' => string ('visible'|'hidden'|'veryHidden'),
 *         'path'  => string,
 *         'rows'  => [ ['excel_row' => int, 'cells' => [1 => string, ...]], ... ]
 *       ]
 *     ],
 *     'sheet_order' => string[]
 *   ]
 *
 * @throws RuntimeException with bilingual user-facing message on invalid/corrupted/unsafe file
 */
function readXlsxBytes(string $bytes): array
{
    $entries = xlsxExtractZipEntriesFromBytes($bytes);

    if (!isset($entries['xl/workbook.xml'])) {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }

    $sharedStrings = xlsxParseSharedStrings($entries);

    // Map relationship IDs (rId -> target path inside archive)
    $relMap = [];
    if (isset($entries['xl/_rels/workbook.xml.rels'])) {
        $relsXml = xlsxSafeParseXml($entries['xl/_rels/workbook.xml.rels'], 'xl/_rels/workbook.xml.rels');
        foreach ($relsXml->Relationship as $rel) {
            $id     = isset($rel['Id']) ? (string)$rel['Id'] : '';
            $target = isset($rel['Target']) ? (string)$rel['Target'] : '';
            if ($id === '' || $target === '') {
                continue;
            }
            $normTarget = ltrim(str_replace('\\', '/', $target), '/');
            if (!str_starts_with($normTarget, 'xl/')) {
                $normTarget = 'xl/' . ltrim($normTarget, '/');
            }
            $relMap[$id] = $normTarget;
        }
    }

    $wbXml = xlsxSafeParseXml($entries['xl/workbook.xml'], 'xl/workbook.xml');
    if (!isset($wbXml->sheets)) {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }

    $sheets     = [];
    $sheetOrder = [];
    $idx        = 0;

    foreach ($wbXml->sheets->sheet as $sheetNode) {
        $idx++;
        $name  = isset($sheetNode['name']) ? (string)$sheetNode['name'] : ('Sheet' . $idx);
        $state = isset($sheetNode['state']) ? (string)$sheetNode['state'] : 'visible';

        // Extract r:id attribute across namespaces
        $rId = '';
        $rAttrs = $sheetNode->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        if ($rAttrs !== null && isset($rAttrs['id'])) {
            $rId = (string)$rAttrs['id'];
        } elseif (isset($sheetNode['id'])) {
            $rId = (string)$sheetNode['id'];
        }

        $targetPath = ($rId !== '' && isset($relMap[$rId]))
            ? $relMap[$rId]
            : ('xl/worksheets/sheet' . $idx . '.xml');

        if (!isset($entries[$targetPath])) {
            $fallbackPath = 'xl/worksheets/sheet' . $idx . '.xml';
            if (isset($entries[$fallbackPath])) {
                $targetPath = $fallbackPath;
            } else {
                continue;
            }
        }

        $rows = xlsxParseWorksheetXml($entries[$targetPath], $sharedStrings, $targetPath);
        $sheets[$name] = [
            'name'  => $name,
            'state' => $state,
            'path'  => $targetPath,
            'rows'  => $rows,
        ];
        $sheetOrder[] = $name;
    }

    if (empty($sheets)) {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }

    return [
        'sheets'      => $sheets,
        'sheet_order' => $sheetOrder,
    ];
}

/**
 * Validate and read an uploaded or local .xlsx file path.
 *
 * @param string      $filePath         Path to the .xlsx file on disk
 * @param string|null $originalFilename Optional original client filename to check .xlsx extension
 * @return array{sheets:array<string,array>,sheet_order:string[]}
 * @throws RuntimeException with bilingual message if file is invalid, oversized, or unreadable
 */
function readXlsxFile(string $filePath, ?string $originalFilename = null): array
{
    if ($originalFilename !== null && $originalFilename !== '') {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
        }
    }

    if (!is_file($filePath) || !is_readable($filePath)) {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }

    $size = @filesize($filePath);
    if ($size === false || $size <= 0) {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }
    if ($size > IMPORT_MAX_FILE_BYTES) {
        throw new RuntimeException(
            'ไฟล์มีขนาดใหญ่เกินกำหนด (สูงสุด 2 MB) กรุณาลดขนาดไฟล์หรือแบ่งการนำเข้า / '
            . 'File size exceeds the 2 MB maximum limit.'
        );
    }

    $bytes = @file_get_contents($filePath, false, null, 0, IMPORT_MAX_FILE_BYTES + 1);
    if (!is_string($bytes) || $bytes === '') {
        throw new RuntimeException(IMPORT_INVALID_FILE_ERROR);
    }

    return readXlsxBytes($bytes);
}
