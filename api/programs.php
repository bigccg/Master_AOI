<?php
/**
 * API Endpoint: AOI Programs Data & KPI Provider
 * GET /api/programs.php?q=...&machine=...&owner=...&status=...&stage=...&page=...&limit=...&sort=...&dir=...
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/programs-data.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    echo json_encode([
        'success'     => false,
        'status_code' => 403,
        'error'       => PROGRAM_CREATE_FORBIDDEN_ERROR,
        'errors'      => [PROGRAM_CREATE_FORBIDDEN_ERROR],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'ไม่อนุญาตให้เรียกด้วยเมธอดนี้ (รองรับเฉพาะ GET) / Method not allowed (GET required).'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();

$search   = trim(qs('q', qs('search')));
$machineF = qs('machine', 'ALL');
$ownerF   = qs('owner', 'ALL');
$statusF  = qs('status', 'ALL');
$stageF   = qs('stage', 'ALL');

$sort = qs('sort', 'updated_at');
$dir  = (strtolower(qs('dir', 'desc')) === 'asc') ? 'ASC' : 'DESC';

$rawLimit = qs('limit', '50');
$isAll = strtolower($rawLimit) === 'all';
$limit = $isAll ? 5000 : max(1, min(500, (int)$rawLimit));

$page = max(1, (int)qs('page', '1'));
$offset = max(0, (int)qs('offset', (string)(($page - 1) * $limit)));
$page = (int)floor($offset / $limit) + 1;

$filters = [
    'search'  => $search,
    'machine' => $machineF,
    'owner'   => $ownerF,
    'status'  => $statusF,
    'stage'   => $stageF,
];

$kpi    = fetchProgramKpis($pdo);
$owners = fetchProgramOwners($pdo);
$res    = fetchProgramList($pdo, $filters, $sort, $dir, $limit, $offset);

$formattedRows = array_map('formatProgramRow', $res['rows']);

$total = $res['total'];
$pages = (int)ceil($total / max(1, $limit));

echo json_encode([
    'success'  => true,
    'data'     => $formattedRows,
    'kpi'      => $kpi,
    'owners'   => $owners,
    'machines' => PROGRAM_MACHINES,
    'total'    => $total,
    'page'     => $page,
    'pages'    => $pages,
    'limit'    => $limit,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
