<?php
/**
 * Request state: current sheet, filters, sort / paging + DB handle.
 */

declare(strict_types=1);

// ──────────────────────────────────────────────
// Request State
// ──────────────────────────────────────────────

// Default view = All Size Magazine (read-only for inserts; pick a sheet to add records)
$sheet = qs('sheet', 'ALL');
if ($sheet !== 'ALL' && !isset(SHEETS[$sheet])) $sheet = 'ALL';
$isAllZones = ($sheet === 'ALL');
$modalSheet = $isAllZones ? DEFAULT_SHEET : $sheet;

$q          = trim(qs('q'));
$shelfF     = qs('shelf', 'ALL');
$boardTypeF = qs('board_type', 'ALL');
$statusF    = qs('status', 'ALL');
$otherF     = qs('other', 'ALL');
$sort    = qs('sort', 'shelf');
$dir     = (qs('dir', 'asc') === 'desc') ? 'DESC' : 'ASC';
$dense   = (qs('density', 'dense') !== 'standard');
$page    = max(1, (int)qs('page', '1'));

$pdo = db();
