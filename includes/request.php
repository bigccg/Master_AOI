<?php
/**
 * Request state: current zone, sheet, filters, sort / paging + DB handle.
 */

declare(strict_types=1);

// ──────────────────────────────────────────────
// Request State
// ──────────────────────────────────────────────

// Zone parameter (validated against the zones table; unknown / deleted zones fall back to DEFAULT_ZONE)
$zone              = normalizeZone(qs('zone', DEFAULT_ZONE));
$currentZoneSheets = zoneSheets($zone);              // enabled sheets = the sheet tabs
$zoneSheetKeys     = array_keys(zoneSheets($zone, true));   // + disabled ones: their records still count and open
$hasZoneSheets     = !empty($zoneSheetKeys);

// Default view = All Size Magazine within the selected zone.
// If the requested sheet does not belong to the selected zone (or was deleted), fall back to 'ALL' of that zone.
$sheet         = resolveSheetInZone($zone, qs('sheet', 'ALL'), 'ALL');
$isAllSheets   = ($sheet === 'ALL');
$isSheetActive = $isAllSheets || sheetIsActive($sheet);   // false: a disabled sheet opened from a direct link
$modalSheet    = $isAllSheets ? defaultSheetForZone($zone) : $sheet;

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

// Administrator (Zone & Sheet manager only; everything else stays open to every user)
$isAdmin         = isAdmin();
$adminConfigured = adminConfigured();
