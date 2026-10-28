<?php
/**
 * API Endpoint: Administrator Authentication via AJAX
 * Handles login, logout, and session status checks without page reloads.
 *
 * GET  /api/admin_auth.php                -> returns { success: true, is_admin: bool, configured: bool, csrf: string }
 * POST /api/admin_auth.php (action=login)  -> returns { success: bool, is_admin: bool, message?: string, error?: string, locked?: int, csrf: string }
 * POST /api/admin_auth.php (action=logout) -> returns { success: bool, is_admin: false, message: string, csrf: string }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    echo json_encode([
        'success'    => true,
        'is_admin'   => isAdmin(),
        'configured' => adminConfigured(),
        'csrf'       => csrf(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'ไม่อนุญาตให้เรียกด้วยเมธอดนี้ (รองรับเฉพาะ GET และ POST) / Method not allowed.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Support JSON input or Form POST
$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody) && ($json = json_decode($rawBody, true)) && is_array($json)) {
    $input = array_merge($input, $json);
}

$action = (string)($input['action'] ?? 'login');
$token  = (string)($input['csrf'] ?? '');

if (!hash_equals(csrf(), $token)) {
    http_response_code(403);
    echo json_encode([
        'success'  => false,
        'error'    => ADMIN_FORM_EXPIRED_ERROR,
        'csrf'     => csrf(),
        'is_admin' => isAdmin(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'logout') {
    adminSignOut();
    echo json_encode([
        'success'  => true,
        'is_admin' => false,
        'message'  => 'ออกจากระบบผู้ดูแลเรียบร้อยแล้ว / Signed out successfully.',
        'csrf'     => csrf(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'login') {
    $password = (string)($input['password'] ?? '');
    if (trim($password) === '') {
        http_response_code(422);
        echo json_encode([
            'success'  => false,
            'error'    => ADMIN_EMPTY_PASSWORD_ERROR,
            'is_admin' => false,
            'csrf'     => csrf(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $lockLeft = adminLockLeft();
    if ($lockLeft > 0) {
        http_response_code(429);
        echo json_encode([
            'success'  => false,
            'error'    => adminLockedMessage($lockLeft),
            'locked'   => $lockLeft,
            'is_admin' => false,
            'csrf'     => csrf(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $res = adminSignIn($password);
    if ($res['ok']) {
        echo json_encode([
            'success'  => true,
            'is_admin' => true,
            'message'  => 'เข้าสู่ระบบผู้ดูแลสำเร็จ / Signed in as administrator.',
            'csrf'     => csrf(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = !adminConfigured() ? 503 : (($res['locked'] ?? 0) > 0 ? 429 : 401);
    http_response_code($status);
    echo json_encode([
        'success'  => false,
        'error'    => $res['error'],
        'locked'   => $res['locked'] ?? 0,
        'is_admin' => false,
        'csrf'     => csrf(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode([
    'success'  => false,
    'error'    => 'คำสั่งไม่ถูกต้อง / Invalid action.',
    'is_admin' => isAdmin(),
    'csrf'     => csrf(),
], JSON_UNESCAPED_UNICODE);
