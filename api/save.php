<?php
/**
 * API Endpoint: Save board record (Add / Edit) via AJAX
 * POST /api/save.php
 * Accepts Form POST or JSON
 * Uses shared saveBoardRecord() in config/database.php.
 * Returns JSON: { success: bool, message?: string, errors?: array, id?: int, code?: string, shelf?: string, sheet?: string, zone?: string }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'errors' => ['ไม่อนุญาตให้เรียกด้วยเมธอดนี้ (รองรับเฉพาะ POST) / Method not allowed (POST required).']], JSON_UNESCAPED_UNICODE);
    exit;
}

// Support JSON input or Form POST
$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody) && ($json = json_decode($rawBody, true)) && is_array($json)) {
    $input = array_merge($input, $json);
}

// CSRF validation
$csrfToken = (string)($input['csrf'] ?? '');
if (!hash_equals(csrf(), $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => ['เซสชันหมดอายุหรือโทเค็นความปลอดภัยไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วลองใหม่ / Invalid CSRF token. Please refresh the page and retry.']], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo    = db();
$result = saveBoardRecord($pdo, $input);

if (!$result['success']) {
    http_response_code($result['status_code'] ?? 422);
    echo json_encode([
        'success' => false,
        'errors'  => $result['errors'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => $result['message'],
    'id'      => $result['id'],
    'code'    => $result['code'],
    'shelf'   => $result['shelf'],
    'sheet'   => $result['sheet'],
    'zone'    => $result['zone'],
], JSON_UNESCAPED_UNICODE);
