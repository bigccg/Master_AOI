<?php
/**
 * API Endpoint: Update Existing Program Stage Status or Metadata (Operator / Note)
 * POST /api/program_stage.php
 * Accepts Form POST or JSON. Never creates new rows.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/programs-data.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'errors' => ['ไม่อนุญาตให้เรียกด้วยเมธอดนี้ (รองรับเฉพาะ POST) / Method not allowed (POST required).']], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody) && ($json = json_decode($rawBody, true)) && is_array($json)) {
    $input = array_merge($input, $json);
}

// Block any attempt to use this endpoint to create a program
$action = trim((string)($input['action'] ?? 'stage'));
if ($action === 'create' || $action === 'add' || (int)($input['id'] ?? 0) <= 0) {
    http_response_code(403);
    echo json_encode([
        'success'     => false,
        'status_code' => 403,
        'error'       => PROGRAM_CREATE_FORBIDDEN_ERROR,
        'errors'      => [PROGRAM_CREATE_FORBIDDEN_ERROR],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// CSRF validation
$csrfToken = (string)($input['csrf'] ?? '');
if (!hash_equals(csrf(), $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => ['เซสชันหมดอายุหรือโทเค็นความปลอดภัยไม่ถูกต้อง กรุณาโหลดหน้าเว็บใหม่แล้วลองอีกครั้ง / Invalid CSRF token. Please refresh the page and retry.']], JSON_UNESCAPED_UNICODE);
    exit;
}

$id       = (int)($input['id'] ?? 0);
$operator = trim((string)($input['operator'] ?? ''));
$version  = (int)($input['version'] ?? 0);
$ip       = clientIp();

$pdo = db();

if ($action === 'meta' || (!isset($input['stage']) && (array_key_exists('owner', $input) || array_key_exists('note', $input)))) {
    $fields = [];
    if (array_key_exists('owner', $input)) {
        $fields['owner'] = $input['owner'];
    }
    if (array_key_exists('note', $input)) {
        $fields['note'] = $input['note'];
    }
    $result = updateProgramMeta($pdo, $id, $fields, $operator, $version, $ip);
} else {
    $stage  = (int)($input['stage'] ?? 0);
    $status = (int)($input['status'] ?? 0);
    $note   = isset($input['note']) ? trim((string)$input['note']) : null;
    $result = updateProgramStage($pdo, $id, $stage, $status, $operator, $version, $note, $ip);
}

if (!$result['success']) {
    http_response_code($result['status_code'] ?? 422);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Attach fresh KPIs and owners so client can update dashboard in 1 roundtrip
$result['kpi']    = fetchProgramKpis($pdo);
$result['owners'] = fetchProgramOwners($pdo);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
