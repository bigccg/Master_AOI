<?php
/**
 * API Endpoint: Delete board record (AJAX)
 * POST /api/delete.php
 * Body: { id: int, csrf: string }
 * Returns: { success: bool, message: string }
 * Works with both MySQL and SQLite
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ──────────────────────────────────────────────
// Read JSON input
// ──────────────────────────────────────────────

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$id   = isset($input['id'])   ? (int)$input['id']   : 0;
$csrf = isset($input['csrf']) ? (string)$input['csrf'] : '';

// ──────────────────────────────────────────────
// Validate CSRF
// ──────────────────────────────────────────────

if (!hash_equals(csrf(), $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

// ──────────────────────────────────────────────
// Delete record
// ──────────────────────────────────────────────

$pdo = db();

try {
    $st = $pdo->prepare('DELETE FROM boards WHERE id = ?');
    $st->execute([$id]);
    
    if ($st->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'ลบรายการเรียบร้อยแล้ว / Record deleted.'
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Record not found']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}