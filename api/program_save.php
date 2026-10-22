<?php
/**
 * API Endpoint: Create or Update Program Record (Quick Add or Full Form)
 * POST /api/program_save.php
 * Accepts Form POST or JSON
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

// CSRF validation
$csrfToken = (string)($input['csrf'] ?? '');
if (!hash_equals(csrf(), $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => ['เซสชันหมดอายุหรือโทเค็นความปลอดภัยไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วลองใหม่ / Invalid CSRF token. Please refresh the page and retry.']], JSON_UNESCAPED_UNICODE);
    exit;
}

$operator = trim((string)($input['operator'] ?? ''));
$ip = clientIp();

$pdo = db();
$result = saveNewProgram($pdo, $input, $operator, $ip);

if (!$result['success']) {
    http_response_code($result['status_code'] ?? 422);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Attach fresh KPIs so client can update dashboard numbers in 1 roundtrip
$result['kpi'] = fetchProgramKpis($pdo);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
