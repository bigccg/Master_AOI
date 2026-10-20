<?php
/**
 * Request state: current zone, sheet, filters, sort / paging + DB handle.
 */

declare(strict_types=1);

// ──────────────────────────────────────────────
// Request State
// ──────────────────────────────────────────────

// Zone parameter (whitelist validated; invalid values fall back to DEFAULT_ZONE = 'General')
$zone              = normalizeZone(qs('zone', DEFAULT_ZONE));
$currentZoneSheets = zoneSheets($zone);
$hasZoneSheets     = !empty($currentZoneSheets);

// Default view = All Size Magazine within the selected zone.
// If the requested sheet does not belong to the selected zone, fall back to 'ALL' of that zone.
$sheet       = resolveSheetInZone($zone, qs('sheet', 'ALL'), 'ALL');
$isAllSheets = ($sheet === 'ALL');
$modalSheet  = $isAllSheets ? defaultSheetForZone($zone) : $sheet;

$q          = trim(qs('q'));
$shelfF     = qs('shelf', 'ALL');
$boardTypeF = qs('board_type', 'ALL');
$statusF    = qs('status', 'ALL');
$otherF     = qs('other', 'ALL');
$sort       = qs('sort', 'shelf');
$dir        = (qs('dir', 'asc') === 'desc') ? 'DESC' : 'ASC';
$dense      = (qs('density', 'dense') !== 'standard');
$page       = max(1, (int)qs('page', '1'));

$pdo = db();
