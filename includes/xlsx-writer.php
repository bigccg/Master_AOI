<?php
/**
 * Pure-PHP Native Excel (.xlsx) OpenXML Workbook Generator & Report Builder
 * Requires only standard PHP + zlib (gzdeflate, crc32) — zero external dependencies.
 * Follows strict ECMA-376 SpreadsheetML tag ordering so Excel, LibreOffice, and Google Sheets open cleanly.
 */

declare(strict_types=1);

/**
 * Minimal, standards-compliant ZIP archive builder in pure PHP.
 */
final class AoiZipArchiveBuilder
{
    /** @var array<int, array{name: string, data: string, crc: int, zdata: string, time: int, date: int}> */
    private array $entries = [];

    public function addFromString(string $name, string $data): void
    {
        $name  = ltrim(str_replace('\\', '/', $name), '/');
        $crc   = crc32($data);
        $zdata = gzdeflate($data, 6);
        $now   = getdate();
        $time  = (($now['hours'] & 0x1F) << 11) | (($now['minutes'] & 0x3F) << 5) | (($now['seconds'] >> 1) & 0x1F);
        $date  = ((max(1980, (int)$now['year']) - 1980) << 9) | (($now['mon'] & 0x0F) << 5) | ($now['mday'] & 0x1F);

        $this->entries[] = [
            'name'  => $name,
            'data'  => $data,
            'crc'   => $crc,
            'zdata' => $zdata,
            'time'  => $time,
            'date'  => $date,
        ];
    }

    public function toBytes(): string
    {
        $localParts = [];
        $centralDir = [];
        $offset     = 0;

        foreach ($this->entries as $e) {
            $nameLen = strlen($e['name']);
            $uLen    = strlen($e['data']);
            $cLen    = strlen($e['zdata']);

            // Local file header (PK\x03\x04)
            $localHeader = "\x50\x4b\x03\x04"
                . pack('v', 20)              // version needed to extract (2.0)
                . pack('v', 0)               // general purpose bit flag
                . pack('v', 8)               // compression method (8 = deflate)
                . pack('v', $e['time'])      // last mod file time
                . pack('v', $e['date'])      // last mod file date
                . pack('V', $e['crc'])       // crc-32
                . pack('V', $cLen)           // compressed size
                . pack('V', $uLen)           // uncompressed size
                . pack('v', $nameLen)        // file name length
                . pack('v', 0)               // extra field length
                . $e['name'];

            $localChunk   = $localHeader . $e['zdata'];
            $localParts[] = $localChunk;

            // Central directory file header (PK\x01\x02)
            $centralDir[] = "\x50\x4b\x01\x02"
                . pack('v', 20)              // version made by
                . pack('v', 20)              // version needed to extract
                . pack('v', 0)               // general purpose bit flag
                . pack('v', 8)               // compression method
                . pack('v', $e['time'])      // last mod file time
                . pack('v', $e['date'])      // last mod file date
                . pack('V', $e['crc'])       // crc-32
                . pack('V', $cLen)           // compressed size
                . pack('V', $uLen)           // uncompressed size
                . pack('v', $nameLen)        // file name length
                . pack('v', 0)               // extra field length
                . pack('v', 0)               // file comment length
                . pack('v', 0)               // disk number start
                . pack('v', 0)               // internal file attributes
                . pack('V', 32)              // external file attributes (archive)
                . pack('V', $offset)         // relative offset of local header
                . $e['name'];

            $offset += strlen($localChunk);
        }

        $cdBytes = implode('', $centralDir);
        $count   = count($this->entries);

        // End of central directory record (PK\x05\x06)
        $eocd = "\x50\x4b\x05\x06"
            . pack('v', 0)                   // number of this disk
            . pack('v', 0)                   // disk where central directory starts
            . pack('v', $count)              // number of central directory records on this disk
            . pack('v', $count)              // total number of central directory records
            . pack('V', strlen($cdBytes))    // size of central directory
            . pack('V', $offset)             // offset of start of central directory
            . pack('v', 0);                  // comment length

        return implode('', $localParts) . $cdBytes . $eocd;
    }
}

/**
 * Style indices used in xl/styles.xml
 */
final class AoiXlsxStyle
{
    public const DEFAULT_CELL     = 0;
    public const TITLE_BANNER     = 1;  // Dark Navy #0F172A, white 13pt bold, left/vcenter
    public const SUBTITLE_META    = 2;  // Slate #F1F5F9, #334155 9.5pt, left/vcenter
    public const KPI_SUMMARY      = 3;  // Emerald tint #ECFDF5, #065F46 10pt bold
    public const TH_SLATE         = 4;  // Header #1E293B, white 10pt bold, center
    public const TH_BLUE          = 5;  // Header #1D4ED8 (Number Code), white 10pt bold
    public const TH_CYAN          = 6;  // Header #0E7490 (Shelf), white 10pt bold
    public const TH_EMERALD       = 7;  // Header #047857 (Zone), white 10pt bold
    public const TH_INDIGO        = 8;  // Header #4338CA (Product Model), white 10pt bold
    public const TH_AMBER         = 9;  // Header #D97706 (Status G+D), white 10pt bold
    public const TH_VIOLET        = 10; // Header #6D28D9 (Other), white 10pt bold
    public const TH_ROSE          = 11; // Header #BE123C (Defect Other), white 10pt bold
    public const TD_TEXT_EVEN     = 12; // Data cell white, left
    public const TD_TEXT_ODD      = 13; // Data cell #F8FAFC, left
    public const TD_CENTER_EVEN   = 14; // Data cell white, center
    public const TD_CENTER_ODD    = 15; // Data cell #F8FAFC, center
    public const TD_MONO_EVEN     = 16; // Mono cell white, center (Code)
    public const TD_MONO_ODD      = 17; // Mono cell #F8FAFC, center (Code)
    public const TD_SHELF         = 18; // Shelf pill #EFF6FF, bold #1D4ED8, center
    public const TD_ZONE          = 19; // Zone badge #ECFDF5, bold #047857, center
    public const TD_STATUS_G      = 20; // Good [G] #DCFCE7, bold #15803D, center
    public const TD_STATUS_D      = 21; // Defect [D] #FEE2E2, bold #B91C1C, center
    public const TD_STATUS_GD     = 22; // Mixed [G+D] #FEF3C7, bold #B45309, center
    public const TD_OTHER_BARE    = 23; // Bare #E0F2FE, bold #0369A1, center
    public const TD_OTHER_BAD     = 24; // Bad Mark #EDE9FE, bold #6D28D9, center
    public const TD_DEFECT_HIT    = 25; // Defect Hit #FFE4E6, bold #DC2626, center
    public const TD_DEFECT_MISS   = 26; // Defect Miss white, muted #94A3B8, center
    public const TFOOT_LABEL      = 27; // Footer label #E2E8F0, bold #0F172A, center
    public const TFOOT_TOTAL      = 28; // Footer total #DBEAFE, bold #1D4ED8, center
    public const TFOOT_DEFECT_HIT = 29; // Footer nonzero defect #FEE2E2, bold #B91C1C, center
    public const TFOOT_DEFECT_ZERO= 30; // Footer zero defect #F1F5F9, muted #64748B, center
    public const LEGEND_HEAD      = 31; // Legend section header #1E293B, white bold
    public const LEGEND_ITEM      = 32; // Legend item #F8FAFC, #334155 9.5pt
}

/**
 * Convert 1-based column index to Excel column letters (1 -> A, 26 -> Z, 27 -> AA).
 */
function xlsxColLetter(int $colIndex): string
{
    $letter = '';
    while ($colIndex > 0) {
        $mod       = ($colIndex - 1) % 26;
        $letter    = chr(65 + $mod) . $letter;
        $colIndex  = intdiv($colIndex - $mod, 26);
    }
    return $letter;
}

/**
 * Safe XML text escaping, stripping invalid XML 1.0 control characters.
 */
function xlsxXmlEscape(string $val): string
{
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $val) ?? '';
    return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Build xl/styles.xml with all 33 styles defined in AoiXlsxStyle.
 */
function buildXlsxStylesXml(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        // Fonts
        . '<fonts count="16">'
        . '<font><sz val="10"/><color rgb="FF1E293B"/><name val="Calibri"/></font>'                                   // 0: default
        . '<font><b/><sz val="13"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'                               // 1: banner white bold 13pt
        . '<font><sz val="9.5"/><color rgb="FF334155"/><name val="Calibri"/></font>'                                  // 2: subtitle slate 9.5pt
        . '<font><b/><sz val="10"/><color rgb="FF065F46"/><name val="Calibri"/></font>'                               // 3: KPI emerald bold
        . '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'                               // 4: TH white bold 10pt
        . '<font><sz val="10"/><color rgb="FF0F172A"/><name val="Consolas"/></font>'                                  // 5: mono dark
        . '<font><b/><sz val="10"/><color rgb="FF1D4ED8"/><name val="Consolas"/></font>'                              // 6: shelf / total blue bold
        . '<font><b/><sz val="9.5"/><color rgb="FF047857"/><name val="Calibri"/></font>'                              // 7: zone emerald bold
        . '<font><b/><sz val="10"/><color rgb="FF15803D"/><name val="Calibri"/></font>'                               // 8: status G green bold
        . '<font><b/><sz val="10"/><color rgb="FFB91C1C"/><name val="Calibri"/></font>'                               // 9: status D red bold
        . '<font><b/><sz val="10"/><color rgb="FFB45309"/><name val="Calibri"/></font>'                               // 10: status GD amber bold
        . '<font><b/><sz val="9.5"/><color rgb="FF0369A1"/><name val="Calibri"/></font>'                              // 11: Bare sky bold
        . '<font><b/><sz val="9.5"/><color rgb="FF6D28D9"/><name val="Calibri"/></font>'                              // 12: Bad Mark violet bold
        . '<font><b/><sz val="11"/><color rgb="FFDC2626"/><name val="Calibri"/></font>'                               // 13: defect hit red bold
        . '<font><sz val="10"/><color rgb="FF94A3B8"/><name val="Calibri"/></font>'                                   // 14: muted dash / zero
        . '<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Calibri"/></font>'                               // 15: footer dark bold
        . '</fonts>'
        // Fills (0 = none and 1 = gray125 are required by Excel)
        . '<fills count="22">'
        . '<fill><patternFill patternType="none"/></fill>'                                                            // 0: none
        . '<fill><patternFill patternType="gray125"/></fill>'                                                         // 1: gray125
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/></patternFill></fill>'                     // 2: Banner dark navy
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>'                     // 3: Subtitle / zero soft slate
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFECFDF5"/></patternFill></fill>'                     // 4: Emerald tint (KPI / Zone)
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/></patternFill></fill>'                     // 5: TH Slate
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/></patternFill></fill>'                     // 6: TH Blue
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0E7490"/></patternFill></fill>'                     // 7: TH Cyan
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF047857"/></patternFill></fill>'                     // 8: TH Emerald
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF4338CA"/></patternFill></fill>'                     // 9: TH Indigo
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD97706"/></patternFill></fill>'                     // 10: TH Amber
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF6D28D9"/></patternFill></fill>'                     // 11: TH Violet
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFBE123C"/></patternFill></fill>'                     // 12: TH Rose
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/></patternFill></fill>'                     // 13: White
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>'                     // 14: Zebra odd row #F8FAFC
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/></patternFill></fill>'                     // 15: Shelf soft blue #EFF6FF
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/></patternFill></fill>'                     // 16: Status G soft green
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/></patternFill></fill>'                     // 17: Status D soft red
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/></patternFill></fill>'                     // 18: Status GD soft amber
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0F2FE"/></patternFill></fill>'                     // 19: Bare soft sky
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEDE9FE"/></patternFill></fill>'                     // 20: Bad Mark soft violet
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFE4E6"/></patternFill></fill>'                     // 21: Defect hit soft rose
        . '</fills>'
        // Borders
        . '<borders count="4">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'                                                // 0: none
        . '<border>'                                                                                                  // 1: thin slate cell border
        . '<left style="thin"><color rgb="FFCBD5E1"/></left>'
        . '<right style="thin"><color rgb="FFCBD5E1"/></right>'
        . '<top style="thin"><color rgb="FFCBD5E1"/></top>'
        . '<bottom style="thin"><color rgb="FFCBD5E1"/></bottom>'
        . '<diagonal/>'
        . '</border>'
        . '<border>'                                                                                                  // 2: header border
        . '<left style="thin"><color rgb="FF475569"/></left>'
        . '<right style="thin"><color rgb="FF475569"/></right>'
        . '<top style="medium"><color rgb="FF0F172A"/></top>'
        . '<bottom style="medium"><color rgb="FF0F172A"/></bottom>'
        . '<diagonal/>'
        . '</border>'
        . '<border>'                                                                                                  // 3: footer double/medium border
        . '<left style="thin"><color rgb="FF94A3B8"/></left>'
        . '<right style="thin"><color rgb="FF94A3B8"/></right>'
        . '<top style="medium"><color rgb="FF475569"/></top>'
        . '<bottom style="double"><color rgb="FF0F172A"/></bottom>'
        . '<diagonal/>'
        . '</border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        // CellXfs (0..32 matching AoiXlsxStyle constants)
        . '<cellXfs count="33">'
        // 0: DEFAULT_CELL
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        // 1: TITLE_BANNER
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'
        // 2: SUBTITLE_META
        . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'
        // 3: KPI_SUMMARY
        . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'
        // 4: TH_SLATE
        . '<xf numFmtId="0" fontId="4" fillId="5" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        // 5: TH_BLUE
        . '<xf numFmtId="0" fontId="4" fillId="6" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        // 6: TH_CYAN
        . '<xf numFmtId="0" fontId="4" fillId="7" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        // 7: TH_EMERALD
        . '<xf numFmtId="0" fontId="4" fillId="8" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        // 8: TH_INDIGO
        . '<xf numFmtId="0" fontId="4" fillId="9" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        // 9: TH_AMBER
        . '<xf numFmtId="0" fontId="4" fillId="10" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        // 10: TH_VIOLET
        . '<xf numFmtId="0" fontId="4" fillId="11" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        // 11: TH_ROSE
        . '<xf numFmtId="0" fontId="4" fillId="12" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        // 12: TD_TEXT_EVEN
        . '<xf numFmtId="0" fontId="0" fillId="13" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        // 13: TD_TEXT_ODD
        . '<xf numFmtId="0" fontId="0" fillId="14" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
        // 14: TD_CENTER_EVEN
        . '<xf numFmtId="0" fontId="0" fillId="13" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 15: TD_CENTER_ODD
        . '<xf numFmtId="0" fontId="0" fillId="14" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 16: TD_MONO_EVEN
        . '<xf numFmtId="0" fontId="5" fillId="13" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 17: TD_MONO_ODD
        . '<xf numFmtId="0" fontId="5" fillId="14" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 18: TD_SHELF
        . '<xf numFmtId="0" fontId="6" fillId="15" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 19: TD_ZONE
        . '<xf numFmtId="0" fontId="7" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 20: TD_STATUS_G
        . '<xf numFmtId="0" fontId="8" fillId="16" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 21: TD_STATUS_D
        . '<xf numFmtId="0" fontId="9" fillId="17" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 22: TD_STATUS_GD
        . '<xf numFmtId="0" fontId="10" fillId="18" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 23: TD_OTHER_BARE
        . '<xf numFmtId="0" fontId="11" fillId="19" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 24: TD_OTHER_BAD
        . '<xf numFmtId="0" fontId="12" fillId="20" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 25: TD_DEFECT_HIT
        . '<xf numFmtId="0" fontId="13" fillId="21" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 26: TD_DEFECT_MISS
        . '<xf numFmtId="0" fontId="14" fillId="13" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 27: TFOOT_LABEL
        . '<xf numFmtId="0" fontId="15" fillId="3" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 28: TFOOT_TOTAL
        . '<xf numFmtId="0" fontId="6" fillId="15" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 29: TFOOT_DEFECT_HIT
        . '<xf numFmtId="0" fontId="9" fillId="17" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 30: TFOOT_DEFECT_ZERO
        . '<xf numFmtId="0" fontId="14" fillId="3" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        // 31: LEGEND_HEAD
        . '<xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'
        // 32: LEGEND_ITEM
        . '<xf numFmtId="0" fontId="2" fillId="14" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" indent="1"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/**
 * Render a single Worksheet XML from structured rows, column widths, freeze panes, merges, and autoFilter.
 *
 * Each row in $rows is:
 *   ['height' => float|null, 'cells' => [ ['v' => string|int|float, 's' => int, 't' => 'str'|'n'] ]]
 */
function buildWorksheetXml(
    array $rows,
    array $colWidths,
    int $freezeRow = 4,
    int $freezeCol = 0,
    ?string $autoFilterRef = null,
    array $merges = []
): string {
    $maxCol = max(1, count($colWidths));
    $maxRow = max(1, count($rows));
    $dimRef = 'A1:' . xlsxColLetter($maxCol) . $maxRow;

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

    // 1. dimension
    $xml .= '<dimension ref="' . $dimRef . '"/>';

    // 2. sheetViews (with frozen header / columns)
    $xml .= '<sheetViews><sheetView showGridLines="1" workbookViewId="0">';
    if ($freezeRow > 0 || $freezeCol > 0) {
        $topLeft = xlsxColLetter($freezeCol + 1) . ($freezeRow + 1);
        $activePane = ($freezeRow > 0 && $freezeCol > 0) ? 'bottomRight' : ($freezeRow > 0 ? 'bottomLeft' : 'topRight');
        $paneAttrs = '';
        if ($freezeCol > 0) $paneAttrs .= ' xSplit="' . $freezeCol . '"';
        if ($freezeRow > 0) $paneAttrs .= ' ySplit="' . $freezeRow . '"';
        $paneAttrs .= ' topLeftCell="' . $topLeft . '" activePane="' . $activePane . '" state="frozen"';
        $xml .= '<pane' . $paneAttrs . '/>';
    }
    $xml .= '</sheetView></sheetViews>';

    // 3. sheetFormatPr
    $xml .= '<sheetFormatPr defaultRowHeight="21" customHeight="1"/>';

    // 4. cols
    $xml .= '<cols>';
    foreach (array_values($colWidths) as $idx => $w) {
        $c = $idx + 1;
        $xml .= '<col min="' . $c . '" max="' . $c . '" width="' . number_format((float)$w, 2, '.', '') . '" customWidth="1"/>';
    }
    $xml .= '</cols>';

    // 5. sheetData
    $xml .= '<sheetData>';
    foreach ($rows as $rIdx => $rowSpec) {
        $rNum   = $rIdx + 1;
        $height = isset($rowSpec['height']) ? ' ht="' . number_format((float)$rowSpec['height'], 1, '.', '') . '" customHeight="1"' : '';
        $xml   .= '<row r="' . $rNum . '"' . $height . '>';
        foreach ($rowSpec['cells'] as $cIdx => $cell) {
            if ($cell === null) continue;
            $ref   = xlsxColLetter($cIdx + 1) . $rNum;
            $style = isset($cell['s']) ? ' s="' . (int)$cell['s'] . '"' : '';
            $type  = $cell['t'] ?? 'str';
            $val   = $cell['v'] ?? '';

            if ($type === 'n' && is_numeric($val)) {
                $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $val . '</v></c>';
            } else {
                $escaped = xlsxXmlEscape((string)$val);
                $xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';

    // 6. autoFilter (must appear BEFORE mergeCells in ECMA-376)
    if ($autoFilterRef !== null && $autoFilterRef !== '') {
        $xml .= '<autoFilter ref="' . $autoFilterRef . '"/>';
    }

    // 7. mergeCells
    if (!empty($merges)) {
        $xml .= '<mergeCells count="' . count($merges) . '">';
        foreach ($merges as $mRef) {
            $xml .= '<mergeCell ref="' . $mRef . '"/>';
        }
        $xml .= '</mergeCells>';
    }

    // 8. pageMargins
    $xml .= '<pageMargins left="0.5" right="0.5" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';
    $xml .= '</worksheet>';

    return $xml;
}

/**
 * Build Worksheet 1: Master Boards Table (รายการบอร์ด AOI)
 */
function buildMasterBoardsSheet(array $boardRows, string $filterSummaryText): array
{
    $colWidths = [
        7,   // A: No.
        15,  // B: Zone
        16,  // C: Sheet / Size
        21,  // D: Number Code
        11,  // E: Shelf
        26,  // F: Product Model
        12,  // G: Side
        16,  // H: Board Type
        13,  // I: Status (G+D)
        16,  // J: Other (Bare / Bad Mark)
        23,  // K: Magazine / Slot
        21,  // L: Created At
    ];
    $colCount = count($colWidths);
    $lastCol  = xlsxColLetter($colCount);

    // Count KPIs
    $total = count($boardRows);
    $cntG  = 0;
    $cntD  = 0;
    $cntGD = 0;
    $cntBare = 0;
    $cntBad  = 0;
    foreach ($boardRows as $r) {
        $st = (string)($r['status'] ?? '');
        if ($st === 'G') $cntG++;
        elseif ($st === 'D') $cntD++;
        elseif ($st === 'GD') $cntGD++;
        $pcs = (string)($r['pcs_status'] ?? '');
        if ($pcs === 'Bare') $cntBare++;
        elseif ($pcs === 'Bad Mark') $cntBad++;
    }

    $kpiLine = sprintf(
        'สรุปจำนวนทั้งหมด: %d บอร์ด   |   Good [G]: %d   |   Defect [D]: %d   |   Mixed [G+D]: %d   |   Bare: %d   |   Bad Mark: %d',
        $total, $cntG, $cntD, $cntGD, $cntBare, $cntBad
    );

    $fillBanner = static function (string $text, int $style) use ($colCount): array {
        $cells = [['v' => $text, 's' => $style]];
        for ($i = 1; $i < $colCount; $i++) {
            $cells[] = ['v' => '', 's' => $style];
        }
        return $cells;
    };

    $rows = [];
    // Row 1: Title Banner
    $rows[] = [
        'height' => 30,
        'cells'  => $fillBanner('AOI Inspection & Shelf Hub — รายงานข้อมูลบอร์ด (Master Boards Report)', AoiXlsxStyle::TITLE_BANNER),
    ];
    // Row 2: Filter Metadata
    $rows[] = [
        'height' => 22,
        'cells'  => $fillBanner($filterSummaryText, AoiXlsxStyle::SUBTITLE_META),
    ];
    // Row 3: KPI Summary
    $rows[] = [
        'height' => 23,
        'cells'  => $fillBanner($kpiLine, AoiXlsxStyle::KPI_SUMMARY),
    ];
    // Row 4: Table Column Headers
    $rows[] = [
        'height' => 26,
        'cells'  => [
            ['v' => 'ลำดับ (No.)',           's' => AoiXlsxStyle::TH_SLATE],
            ['v' => 'Zone (โซน)',            's' => AoiXlsxStyle::TH_EMERALD],
            ['v' => 'Size / Sheet (ชั้นวาง)', 's' => AoiXlsxStyle::TH_CYAN],
            ['v' => 'Number Code',           's' => AoiXlsxStyle::TH_BLUE],
            ['v' => 'Shelf',                 's' => AoiXlsxStyle::TH_CYAN],
            ['v' => 'Product Model',         's' => AoiXlsxStyle::TH_INDIGO],
            ['v' => 'Side',                  's' => AoiXlsxStyle::TH_SLATE],
            ['v' => 'Board Type',            's' => AoiXlsxStyle::TH_SLATE],
            ['v' => 'Status (G+D)',          's' => AoiXlsxStyle::TH_AMBER],
            ['v' => 'Other (Bare/Bad Mark)', 's' => AoiXlsxStyle::TH_VIOLET],
            ['v' => 'Magazine / Slot',       's' => AoiXlsxStyle::TH_SLATE],
            ['v' => 'Created At (เวลาบันทึก)','s' => AoiXlsxStyle::TH_SLATE],
        ],
    ];

    $topoSheets = topology()['sheets'] ?? [];
    foreach ($boardRows as $idx => $r) {
        $odd       = ($idx % 2) === 1;
        $sText     = $odd ? AoiXlsxStyle::TD_TEXT_ODD   : AoiXlsxStyle::TD_TEXT_EVEN;
        $sCenter   = $odd ? AoiXlsxStyle::TD_CENTER_ODD : AoiXlsxStyle::TD_CENTER_EVEN;
        $sMono     = $odd ? AoiXlsxStyle::TD_MONO_ODD   : AoiXlsxStyle::TD_MONO_EVEN;

        $statusRaw = (string)($r['status'] ?? '');
        $statusTxt = $statusRaw === 'GD' ? 'G+D' : ($statusRaw !== '' ? $statusRaw : '-');
        $sStatus   = match ($statusRaw) {
            'G'  => AoiXlsxStyle::TD_STATUS_G,
            'D'  => AoiXlsxStyle::TD_STATUS_D,
            'GD' => AoiXlsxStyle::TD_STATUS_GD,
            default => $sCenter,
        };

        $pcsRaw = (string)($r['pcs_status'] ?? '');
        $pcsTxt = $pcsRaw !== '' ? $pcsRaw : '-';
        $sPcs   = match ($pcsRaw) {
            'Bare'     => AoiXlsxStyle::TD_OTHER_BARE,
            'Bad Mark' => AoiXlsxStyle::TD_OTHER_BAD,
            default    => $sCenter,
        };

        $shKey   = (string)($r['sheet'] ?? '');
        $shLabel = isset($topoSheets[$shKey]) ? ($topoSheets[$shKey]['prefix'] . ' (' . $shKey . ')') : $shKey;
        $magSlot = function_exists('magazineSlotDisplay') ? magazineSlotDisplay($r) : (string)($r['mag_slot'] ?? '');

        $rows[] = [
            'height' => 20,
            'cells'  => [
                ['v' => $idx + 1,                        's' => $sCenter, 't' => 'n'],
                ['v' => (string)($r['zone'] ?? ''),      's' => AoiXlsxStyle::TD_ZONE],
                ['v' => $shLabel,                        's' => $sCenter],
                ['v' => (string)($r['code'] ?? ''),      's' => $sMono],
                ['v' => (string)($r['shelf'] ?? ''),     's' => AoiXlsxStyle::TD_SHELF],
                ['v' => (string)($r['product'] ?? ''),   's' => $sText],
                ['v' => (string)($r['side'] ?? '-'),     's' => $sCenter],
                ['v' => (string)($r['board_type'] ?? '-'),'s' => $sCenter],
                ['v' => $statusTxt,                      's' => $sStatus],
                ['v' => $pcsTxt,                         's' => $sPcs],
                ['v' => $magSlot,                        's' => $sCenter],
                ['v' => (string)($r['created_at'] ?? ''),'s' => $sMono],
            ],
        ];
    }

    // Footer Total Row
    $footerCells = [
        ['v' => 'Total', 's' => AoiXlsxStyle::TFOOT_LABEL],
        ['v' => $total . ' บอร์ด', 's' => AoiXlsxStyle::TFOOT_TOTAL],
    ];
    for ($i = 2; $i < $colCount; $i++) {
        $footerCells[] = ['v' => '', 's' => AoiXlsxStyle::TFOOT_LABEL];
    }
    $rows[] = [
        'height' => 24,
        'cells'  => $footerCells,
    ];

    $merges = [
        "A1:{$lastCol}1",
        "A2:{$lastCol}2",
        "A3:{$lastCol}3",
    ];
    $lastDataRow = 4 + max(1, $total);
    $autoFilter  = "A4:{$lastCol}{$lastDataRow}";

    return [
        'title' => 'Master Boards',
        'xml'   => buildWorksheetXml($rows, $colWidths, 4, 0, $autoFilter, $merges),
    ];
}

/**
 * Build Worksheet 2: Defect Matrix: All Size Magazines — รวมทุกโซน (ตารางข้อบกพร่องทุก Shelf ทุกโซน)
 */
function buildDefectMatrixSheet(array $matrixRows, string $filterSummaryText, string $zoneTitleLabel): array
{
    $defectCodes = array_keys(DEFECT_LABELS);
    sort($defectCodes, SORT_NUMERIC);

    // Fixed columns: Number Code, Shelf, Zone, Size/Sheet, Product Model, G+D + N defect codes + Other
    $colWidths = [
        21, // A: Number Code
        11, // B: Shelf
        14, // C: Zone
        15, // D: Size / Sheet
        25, // E: Product Model
        11, // F: G+D
    ];
    foreach ($defectCodes as $code) {
        $colWidths[] = 9; // Defect code columns
    }
    $colWidths[] = 26; // Other (รายละเอียด)

    $colCount = count($colWidths);
    $lastCol  = xlsxColLetter($colCount);

    $fillBanner = static function (string $text, int $style) use ($colCount): array {
        $cells = [['v' => $text, 's' => $style]];
        for ($i = 1; $i < $colCount; $i++) {
            $cells[] = ['v' => '', 's' => $style];
        }
        return $cells;
    };

    $rows   = [];
    $merges = [];

    // Row 1: Title Banner
    $bannerTitle = "Defect Matrix: All Size Magazines — {$zoneTitleLabel} (ตารางข้อบกพร่องทุก Shelf ทุกโซน)";
    $rows[] = [
        'height' => 30,
        'cells'  => $fillBanner($bannerTitle, AoiXlsxStyle::TITLE_BANNER),
    ];
    $merges[] = "A1:{$lastCol}1";

    // Row 2: Filter Metadata
    $rows[] = [
        'height' => 22,
        'cells'  => $fillBanner($filterSummaryText . '   |   จำนวนบอร์ดที่มีข้อบกพร่อง (D / G+D): ' . count($matrixRows) . ' rows', AoiXlsxStyle::SUBTITLE_META),
    ];
    $merges[] = "A2:{$lastCol}2";

    // Row 3: Legend Preview Bar
    $legendShort = [];
    foreach (DEFECT_LABELS as $c => $lbl) {
        $legendShort[] = "{$c}={$lbl}";
    }
    $legendShort[] = 'Other=ข้อบกพร่องอื่นๆ';
    $rows[] = [
        'height' => 22,
        'cells'  => $fillBanner('รหัส Defect: ' . implode(' · ', $legendShort), AoiXlsxStyle::KPI_SUMMARY),
    ];
    $merges[] = "A3:{$lastCol}3";

    // Row 4: Table Header Row
    $headCells = [
        ['v' => 'Number Code',   's' => AoiXlsxStyle::TH_BLUE],
        ['v' => 'Shelf',         's' => AoiXlsxStyle::TH_CYAN],
        ['v' => 'Zone',          's' => AoiXlsxStyle::TH_EMERALD],
        ['v' => 'Size / Sheet',  's' => AoiXlsxStyle::TH_CYAN],
        ['v' => 'Product Model', 's' => AoiXlsxStyle::TH_INDIGO],
        ['v' => 'G+D',           's' => AoiXlsxStyle::TH_AMBER],
    ];
    foreach ($defectCodes as $code) {
        $label = DEFECT_LABELS[$code] ?? '';
        $headCells[] = ['v' => "{$code}\n({$label})", 's' => AoiXlsxStyle::TH_SLATE];
    }
    $headCells[] = ['v' => "Other\n(ข้อบกพร่องอื่นๆ)", 's' => AoiXlsxStyle::TH_ROSE];

    $rows[] = [
        'height' => 34,
        'cells'  => $headCells,
    ];

    // Defect counters for footer
    $counts = [];
    foreach ($defectCodes as $code) {
        $counts[$code] = 0;
    }
    $otherCount = 0;

    $topoSheets = topology()['sheets'] ?? [];

    foreach ($matrixRows as $idx => $r) {
        $odd     = ($idx % 2) === 1;
        $sText   = $odd ? AoiXlsxStyle::TD_TEXT_ODD   : AoiXlsxStyle::TD_TEXT_EVEN;
        $sCenter = $odd ? AoiXlsxStyle::TD_CENTER_ODD : AoiXlsxStyle::TD_CENTER_EVEN;
        $sMono   = $odd ? AoiXlsxStyle::TD_MONO_ODD   : AoiXlsxStyle::TD_MONO_EVEN;

        $parsed    = parseDefects((string)($r['defects'] ?? ''));
        $codeSet   = array_flip($parsed['codes']);
        $otherText = trim((string)$parsed['other_text']);
        $hasOther  = (bool)($parsed['other'] || $otherText !== '');

        $statusRaw = (string)($r['status'] ?? '');
        $statusTxt = $statusRaw === 'GD' ? 'G+D' : ($statusRaw !== '' ? $statusRaw : '-');
        $sStatus   = $statusRaw === 'D' ? AoiXlsxStyle::TD_STATUS_D : AoiXlsxStyle::TD_STATUS_GD;

        $shKey   = (string)($r['sheet'] ?? '');
        $shLabel = isset($topoSheets[$shKey]) ? ($topoSheets[$shKey]['prefix'] . ' (' . $shKey . ')') : $shKey;

        $rowCells = [
            ['v' => (string)($r['code'] ?? ''),    's' => $sMono],
            ['v' => (string)($r['shelf'] ?? ''),   's' => AoiXlsxStyle::TD_SHELF],
            ['v' => (string)($r['zone'] ?? ''),    's' => AoiXlsxStyle::TD_ZONE],
            ['v' => $shLabel,                      's' => $sCenter],
            ['v' => (string)($r['product'] ?? ''), 's' => $sText],
            ['v' => $statusTxt,                    's' => $sStatus],
        ];

        foreach ($defectCodes as $code) {
            if (isset($codeSet[$code])) {
                $counts[$code]++;
                $rowCells[] = ['v' => '✓', 's' => AoiXlsxStyle::TD_DEFECT_HIT];
            } else {
                $rowCells[] = ['v' => '-', 's' => AoiXlsxStyle::TD_DEFECT_MISS];
            }
        }

        if ($hasOther) {
            $otherCount++;
            $rowCells[] = [
                'v' => $otherText !== '' ? "✓ {$otherText}" : '✓ OTHER',
                's' => AoiXlsxStyle::TD_DEFECT_HIT,
            ];
        } else {
            $rowCells[] = ['v' => '-', 's' => AoiXlsxStyle::TD_DEFECT_MISS];
        }

        $rows[] = [
            'height' => 20,
            'cells'  => $rowCells,
        ];
    }

    // Footer Row (Total & Defect Counts per Code)
    $footerCells = [
        ['v' => 'Total',                  's' => AoiXlsxStyle::TFOOT_LABEL],
        ['v' => count($matrixRows),       's' => AoiXlsxStyle::TFOOT_TOTAL, 't' => 'n'],
        ['v' => '-',                      's' => AoiXlsxStyle::TFOOT_LABEL],
        ['v' => '-',                      's' => AoiXlsxStyle::TFOOT_LABEL],
        ['v' => 'รวม Defect แต่ละรหัส:',   's' => AoiXlsxStyle::TFOOT_LABEL],
        ['v' => '-',                      's' => AoiXlsxStyle::TFOOT_LABEL],
    ];
    foreach ($defectCodes as $code) {
        $c = $counts[$code] ?? 0;
        $footerCells[] = [
            'v' => $c,
            's' => $c > 0 ? AoiXlsxStyle::TFOOT_DEFECT_HIT : AoiXlsxStyle::TFOOT_DEFECT_ZERO,
            't' => 'n',
        ];
    }
    $footerCells[] = [
        'v' => $otherCount,
        's' => $otherCount > 0 ? AoiXlsxStyle::TFOOT_DEFECT_HIT : AoiXlsxStyle::TFOOT_DEFECT_ZERO,
        't' => 'n',
    ];
    $rows[] = [
        'height' => 24,
        'cells'  => $footerCells,
    ];

    // Blank spacer row + Legend Reference Block at the bottom
    $rows[] = ['height' => 14, 'cells' => []];

    $legendHeaderRowIdx = count($rows) + 1;
    $rows[] = [
        'height' => 22,
        'cells'  => $fillBanner('คำอธิบายรหัสข้อบกพร่อง (Defect Code Legend Reference)', AoiXlsxStyle::LEGEND_HEAD),
    ];
    $merges[] = "A{$legendHeaderRowIdx}:F{$legendHeaderRowIdx}";

    foreach (DEFECT_LABELS as $code => $label) {
        $rIdx = count($rows) + 1;
        $cVal = $counts[$code] ?? 0;
        $rows[] = [
            'height' => 19,
            'cells'  => [
                ['v' => "รหัส {$code}", 's' => AoiXlsxStyle::TD_MONO_ODD],
                ['v' => $label,         's' => AoiXlsxStyle::LEGEND_ITEM],
                ['v' => '',             's' => AoiXlsxStyle::LEGEND_ITEM],
                ['v' => '',             's' => AoiXlsxStyle::LEGEND_ITEM],
                ['v' => "พบ {$cVal} บอร์ด", 's' => $cVal > 0 ? AoiXlsxStyle::TD_STATUS_D : AoiXlsxStyle::TD_CENTER_ODD],
            ],
        ];
        $merges[] = "B{$rIdx}:D{$rIdx}";
    }

    $lastDataRow = 4 + max(1, count($matrixRows));
    $autoFilter  = "A4:{$lastCol}{$lastDataRow}";

    // Freeze first 2 columns (Number Code, Shelf) and top 4 header rows
    return [
        'title' => 'Defect Matrix (All Zones)',
        'xml'   => buildWorksheetXml($rows, $colWidths, 4, 2, $autoFilter, $merges),
    ];
}

/**
 * Assemble complete .xlsx binary bytes from an array of sheets [['title' => string, 'xml' => string], ...].
 */
function buildXlsxWorkbookBytes(array $sheets): string
{
    $zip = new AoiZipArchiveBuilder();

    // 1. [Content_Types].xml
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    foreach ($sheets as $i => $_) {
        $n = $i + 1;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $ct .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $ct);

    // 2. _rels/.rels
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);

    // 3. xl/workbook.xml
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>';
    foreach ($sheets as $i => $sh) {
        $n     = $i + 1;
        $title = mb_substr(preg_replace('/[\\\\\\/?*\\[\\]:]+/', ' ', (string)$sh['title']) ?? 'Sheet' . $n, 0, 31);
        $wb   .= '<sheet name="' . xlsxXmlEscape($title) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
    }
    $wb .= '</sheets></workbook>';
    $zip->addFromString('xl/workbook.xml', $wb);

    // 4. xl/_rels/workbook.xml.rels
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheets as $i => $_) {
        $n = $i + 1;
        $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $styleRelId = count($sheets) + 1;
    $wbRels .= '<Relationship Id="rId' . $styleRelId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $wbRels .= '</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

    // 5. xl/styles.xml
    $zip->addFromString('xl/styles.xml', buildXlsxStylesXml());

    // 6. xl/worksheets/sheetN.xml
    foreach ($sheets as $i => $sh) {
        $n = $i + 1;
        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $sh['xml']);
    }

    return $zip->toBytes();
}
