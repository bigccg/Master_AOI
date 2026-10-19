<?php
/**
 * AOI Inspection & Shelf Management System
 * Main Entry Point
 *
 *   includes/    request state, POST actions, page data, CSV export, asset load order
 *   components/  page sections (one PHP template each)
 *   assets/css/  base/, theme/, components/  — one stylesheet per component, loaded in includes/assets.php order
 *   assets/js/   core/, components/, main.js — one script per component, loaded in includes/assets.php order
 */

declare(strict_types=1);

// When running under PHP built-in CLI server (e.g. start-offline.sh), pass static files directly
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($path !== '/' && $path !== '' && file_exists(__DIR__ . $path)) {
        return false;
    }
}

require_once __DIR__ . '/config/database.php';

require __DIR__ . '/includes/assets.php';
require __DIR__ . '/includes/request.php';
require __DIR__ . '/includes/actions.php';
require __DIR__ . '/includes/page-data.php';
require __DIR__ . '/includes/export.php';
?>
<!DOCTYPE html>
<html class="h-full bg-slate-50" lang="th">
<?php require __DIR__ . '/components/layout/head.php'; ?>
<body class="h-full flex flex-col font-sans text-slate-800 antialiased selection:bg-blue-500 selection:text-white">

<?php require __DIR__ . '/components/header.php'; ?>

<!-- ===== Main ===== -->
<main class="flex-1 overflow-y-auto px-4 lg:px-8 py-5 flex flex-col gap-5">

  <?php require __DIR__ . '/components/flash.php'; ?>

  <?php require __DIR__ . '/components/kpi-cards.php'; ?>

  <?php require __DIR__ . '/components/filters.php'; ?>

  <?php require __DIR__ . '/components/data-table.php'; ?>

  <?php require __DIR__ . '/components/defect-matrix.php'; ?>

  <?php require __DIR__ . '/components/rack-map.php'; ?>

</main>

<?php require __DIR__ . '/components/popups/rack-insert-confirm.php'; ?>

<?php require __DIR__ . '/components/popups/delete-confirm.php'; ?>

<?php require __DIR__ . '/components/board-modal/board-modal.php'; ?>

<?php require __DIR__ . '/components/layout/scripts.php'; ?>
</body>
</html>
