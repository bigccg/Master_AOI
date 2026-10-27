<?php
/**
 * API Endpoint: Commit Excel Batch Import (All-or-Nothing Transactional Save)
 *
 * POST /api/import_commit.php (multipart/form-data with csrf_token + file)
 *   - Acquires advisory lock GET_LOCK('aoi_board_number_code', 5) (returns 503 if busy)
 *   - Re-validates the entire file inside a single database transaction
 *   - Inserts all rows atomically and syncs program records, or rolls back 100% on any error
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/xlsx-reader.php';
require_once __DIR__ . '/../includes/import-template.php';
require_once __DIR__ . '/../includes/import-validate.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'errors' => [IMPORT_METHOD_ERROR]], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrfToken = (string)($_POST['csrf'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals(csrf(), $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => [IMPORT_CSRF_ERROR]], JSON_UNESCAPED_UNICODE);
    exit;
}

$upload = $_FILES['file'] ?? $_FILES['import_file'] ?? null;
if (!is_array($upload) || !isset($upload['error']) || (int)$upload['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'errors'  => [IMPORT_NO_FILE_ERROR],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmpPath  = (string)($upload['tmp_name'] ?? '');
$origName = (string)($upload['name'] ?? 'template.xlsx');
$uiZone   = trim((string)($_POST['zone'] ?? ''));
$uiSheet  = trim((string)($_POST['sheet'] ?? ''));

try {
    $pdo      = db();
    $workbook = readXlsxFile($tmpPath, $origName);
    $result   = commitImportWorkbook($pdo, $workbook, $uiZone, $uiSheet);

    if (!$result['success'] && !empty($result['rows'])) {
        $result['error_report_xlsx_base64'] = base64_encode(buildImportErrorReportBytes($result));
    }

    $status = (int)($result['http_status'] ?? ($result['success'] ? 200 : 422));
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode([
        'success'          => false,
        'template_expired' => false,
        'errors'           => [$e->getMessage()],
        'warnings'         => [],
        'summary'          => [],
        'rows'             => [],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success'          => false,
        'template_expired' => false,
        'errors'           => ['เกิดข้อผิดพลาดขณะนำเข้าข้อมูล ระบบยังไม่ได้บันทึกรายการใดๆ กรุณาลองใหม่อีกครั้ง / An unexpected error occurred. No records were saved.'],
        'warnings'         => [],
        'summary'          => [],
        'rows'             => [],
    ], JSON_UNESCAPED_UNICODE);
}
