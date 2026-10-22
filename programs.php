<?php
/**
 * AOI Inspection & Shelf Management System
 * AOI Program Tracker Main Entry Point
 */

declare(strict_types=1);

// When running under PHP built-in CLI server (e.g. start-offline.sh), pass static files directly
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (is_string($path) && preg_match('#/\.|^/(config|includes|components|docs|tests|scripts)(/|$)|\.(zip|tar|t?gz|7z|rar|bak|old|orig|swp|tmp|log|sql|sqlite|md|sh|bat|dist)$#i', rawurldecode($path))) {
        http_response_code(404);
        exit('Not Found');
    }
    if ($path !== '/' && $path !== '' && $path !== '/' . basename(__FILE__) && file_exists(__DIR__ . $path)) {
        return false;
    }
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/admin-auth.php';

require __DIR__ . '/includes/assets.php';
require __DIR__ . '/includes/programs-request.php';
require __DIR__ . '/includes/programs-data.php';

// GET ?export=1 -> CSV download of programs (including Side and Master Samples D / G+D)
if (isset($_GET['export'])) {
    $exportFilters = [
        'search'  => $search,
        'machine' => $machineF,
        'owner'   => $ownerF,
        'status'  => $statusF,
        'stage'   => $stageF,
    ];
    $exportRes = fetchProgramList($pdo, $exportFilters, $sortCol, $dir, MAX_ALL_ROWS, 0);

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="aoi_programs_' . date('Ymd_His') . '.csv"');
    }
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Thai Excel compatibility
    fputcsv($out, [
        'Product Model',
        'Side',
        'Machine',
        'Stage 1 (Capture P/D)',
        'Stage 2 (Make Good/Sheet)',
        'Stage 3 (Master G+D)',
        'Stage 4 (Run Test)',
        'Master D',
        'Master G+D',
        'Shelf',
        'Operator',
        'Status',
        'Updated At',
        'Note / Reason',
    ]);

    $stageLabelMap = [
        0 => 'ยังไม่เริ่ม',
        1 => 'กำลังทำ',
        2 => 'เสร็จสมบูรณ์',
        9 => 'N/A',
    ];

    foreach ($exportRes['rows'] as $rawRow) {
        $r = formatProgramRow($rawRow);
        $noteText = $r['wait_reason'] !== ''
            ? ($r['note'] !== '' ? ($r['wait_reason'] . ': ' . $r['note']) : $r['wait_reason'])
            : ($r['note'] !== '' ? $r['note'] : (!$r['has_master_defect'] ? 'ไม่มีแผ่น Defect ใน Master Board' : ''));
        fputcsv($out, [
            $r['product_model'],
            $r['side'],
            $r['machine_type'],
            $stageLabelMap[$r['stage_1']] ?? (string)$r['stage_1'],
            $stageLabelMap[$r['stage_2']] ?? (string)$r['stage_2'],
            $stageLabelMap[$r['stage_3']] ?? (string)$r['stage_3'],
            $stageLabelMap[$r['stage_4']] ?? (string)$r['stage_4'],
            $r['master_d'],
            $r['master_gd'],
            $r['shelf'],
            $r['owner'],
            $r['program_ok'],
            $r['updated_at'],
            $noteText,
        ]);
    }
    fclose($out);
    exit;
}

$currentPage = 'programs';
$pageTitle = 'โปรแกรม AOI | AOI Program Tracker - Precision SMT Hub';
?>
<!DOCTYPE html>
<html class="h-full bg-slate-50" lang="th">
<?php require __DIR__ . '/components/programs/head.php'; ?>
<body class="h-full flex flex-col font-sans text-slate-800 antialiased selection:bg-emerald-500 selection:text-white">

<?php require __DIR__ . '/components/layout/app-header.php'; ?>

<!-- ===== Main Workspace ===== -->
<main class="flex-1 overflow-y-auto px-4 lg:px-8 py-5 flex flex-col gap-5">

  <!-- 1. KPI Cards -->
  <?php require __DIR__ . '/components/programs/kpi-cards.php'; ?>

  <!-- 2. Command Bar & Filters -->
  <?php require __DIR__ . '/components/programs/command-bar.php'; ?>

  <!-- 3. Data Table Workspace -->
  <div id="prog-view-table-wrapper">
    <?php require __DIR__ . '/components/programs/table.php'; ?>
  </div>

</main>

<!-- Floating Toast Container -->
<div id="prog-toast-container" class="prog-toast-container" aria-live="polite"></div>

<?php require __DIR__ . '/components/programs/scripts.php'; ?>
</body>
</html>
