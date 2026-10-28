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

// CSV Export Mode (Zero Page Reload download)
if (qs('export') === '1' || qs('export') === 'csv') {
    $allRes = fetchProgramList($pdo, $filters, $sort, $dir, 50000, 0);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aoi_programs_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID', 'Program Name', 'PCB Type', 'Machine', 'Owner', 'Stage 1 (Create)', 'Stage 2 (Teach)', 'Stage 3 (Dry Run)', 'Stage 4 (Sign-off)', 'Overall Status', 'Updated At']);
        foreach ($allRes['rows'] as $r) {
            $f = formatProgramRow($r);
            fputcsv($out, [
                $f['id'] ?? '',
                $f['program_name'] ?? '',
                $f['pcb_type'] ?? '',
                $f['machine'] ?? '',
                $f['owner'] ?? '',
                $f['stage1_label'] ?? $f['stage1_status'] ?? '',
                $f['stage2_label'] ?? $f['stage2_status'] ?? '',
                $f['stage3_label'] ?? $f['stage3_status'] ?? '',
                $f['stage4_label'] ?? $f['stage4_status'] ?? '',
                $f['status_label'] ?? $f['status'] ?? '',
                $f['updated_at'] ?? '',
            ]);
        }
        fclose($out);
    }
    exit;
}

$kpi    = fetchProgramKpis($pdo);
$owners = fetchProgramOwners($pdo);
$operators = fetchProgramOperatorOptions($pdo);
$res    = fetchProgramList($pdo, $filters, $sort, $dir, $limit, $offset);

$formattedRows = array_map('formatProgramRow', $res['rows']);

$total = $res['total'];
$pages = (int)ceil($total / max(1, $limit));

echo json_encode([
    'success'  => true,
    'data'     => $formattedRows,
    'kpi'      => $kpi,
    'owners'   => $owners,
    'operators' => $operators,
    'machines' => PROGRAM_MACHINES,
    'total'    => $total,
    'page'     => $page,
    'pages'    => $pages,
    'limit'    => $limit,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
