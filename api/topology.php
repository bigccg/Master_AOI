<?php
/**
 * API Endpoint: Zone & Sheet manager (administrators only, includes/topology-manager.php)
 *   GET  /api/topology.php                            -> { success, data: manager data, topology }
 *   POST /api/topology.php  { csrf, action, ...fields } -> { success, message, data, topology }
 *                                                       | { success: false, errors: [...], field_errors: {field: message} }
 *   action: zone_create | zone_update | zone_delete | sheet_create | sheet_update | sheet_delete | sheet_reorder
 * Order of checks: method (405) -> administrator session (503 not configured / 401 not signed in or expired)
 * -> CSRF for writes (403) -> every rule again on the server (400 / 404 / 409 / 422). Accepts Form POST or JSON.
 * `topology` is the same shape as window.TOPOLOGY, so the page can update without a reload (applyTopology()).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/topology-manager.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['success' => false, 'errors' => ['ไม่อนุญาตให้เรียกด้วยเมธอดนี้ (รองรับ GET สำหรับอ่าน และ POST สำหรับบันทึก) / Method not allowed (GET to read, POST to save).']], JSON_UNESCAPED_UNICODE);
    exit;
}

requireAdminJson();

$pdo = db();

if ($method === 'GET') {
    echo json_encode(['success' => true, 'data' => topologyManagerData($pdo), 'topology' => topologyForJs()], JSON_UNESCAPED_UNICODE);
    exit;
}

// Support JSON input or Form POST
$input   = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody) && ($json = json_decode($rawBody, true)) && is_array($json)) {
    $input = array_merge($input, $json);
}

$csrfToken = $input['csrf'] ?? '';
if (!is_string($csrfToken) || !hash_equals(csrf(), $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => ['เซสชันหมดอายุหรือโทเค็นความปลอดภัยไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วลองใหม่ / Invalid CSRF token. Please refresh the page and retry.']], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = is_string($input['action'] ?? null) ? $input['action'] : '';
$result = topologyApply($pdo, $action, $input, clientIp());

http_response_code($result['status_code']);
if (!$result['success']) {
    echo json_encode([
        'success'      => false,
        'errors'       => $result['errors'],
        'field_errors' => (object)$result['field_errors'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success'  => true,
    'message'  => $result['message'],
    'data'     => topologyManagerData($pdo),
    'topology' => topologyForJs(),
], JSON_UNESCAPED_UNICODE);
