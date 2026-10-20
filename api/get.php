<?php
/**
 * API Endpoint: Get single board record by ID (for Edit modal)
 * GET /api/get.php?id=...&zone=...&sheet=...
 * Returns JSON: { success: bool, data?: array, message?: string }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$id = (int)qs('id', '0');

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'รหัสรายการไม่ถูกต้อง / Invalid ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$st  = $pdo->prepare('SELECT id, zone, sheet, code, shelf, shelf_no, product, side, board_type, status, pcs_status, defects, created_at FROM boards WHERE id = ?');
$st->execute([$id]);
$row = $st->fetch();

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'ไม่พบรายการบอร์ดที่ต้องการแก้ไข อาจถูกลบไปแล้ว / Record not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'data'    => $row,
], JSON_UNESCAPED_UNICODE);
