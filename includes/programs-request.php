<?php
/**
 * Request State for AOI Program Tracker
 */

declare(strict_types=1);

$search   = trim(qs('q', qs('search')));
$machineF = qs('machine', 'ALL');
$ownerF   = qs('owner', 'ALL');
$statusF  = qs('status', 'ALL');
$stageF   = qs('stage', 'ALL');
$viewMode = qs('view', 'table'); // table, pipeline, workload

$sort = qs('sort', 'updated_at');
$dir  = (strtolower(qs('dir', 'desc')) === 'asc') ? 'ASC' : 'DESC';

$allowedSortCols = [
    'product_model' => 'product_model',
    'machine_type'  => 'machine_type',
    'owner'         => 'owner',
    'updated_at'    => 'updated_at',
    'created_at'    => 'created_at',
    'id'            => 'id',
];
$sortCol = $allowedSortCols[$sort] ?? 'updated_at';

$density = qs('density', 'standard'); // standard, compact, comfortable
if (!in_array($density, ['standard', 'compact', 'comfortable'], true)) {
    $density = 'standard';
}

$rawLimit = qs('limit', '50');
$isAll = strtolower($rawLimit) === 'all';
if ($isAll) {
    $limit = 5000;
    $offset = 0;
} else {
    $limit = max(1, min(500, (int)$rawLimit));
    $offset = max(0, (int)qs('offset', '0'));
}

$page = max(1, (int)qs('page', '1'));
if (!isset($_GET['offset'])) {
    $offset = ($page - 1) * $limit;
} else {
    $page = (int)floor($offset / $limit) + 1;
}

$pdo = db();
$isAdmin = isAdmin();
$adminConfigured = adminConfigured();
