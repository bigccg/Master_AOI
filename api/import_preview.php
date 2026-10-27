<?php
/**
 * API Endpoint: Dry-Run Excel Import Preview & Row-by-Row Validation
 *
 * POST /api/import_preview.php (multipart/form-data with csrf_token + file)
 *   - Parses the uploaded .xlsx file without modifying the database
 *   - Validates _meta HMAC, Number Code sequence, Contiguity, Shelf rules, and Board fields
 *   - Returns HTTP 200 (all valid) or HTTP 422 (validation errors found) with row-level details
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
if (!is_array($upload) || !isset($upload['error'])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'errors'  => [IMPORT_NO_FILE_ERROR],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadError = (int)$upload['error'];
if ($uploadError !== UPLOAD_ERR_OK) {
    http_response_code(422);
    $errMsg = ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE)
        ? IMPORT_FILE_TOO_LARGE_ERROR
        : IMPORT_INVALID_FILE_ERROR;
    echo json_encode(['success' => false, 'errors' => [$errMsg]], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmpPath  = (string)($upload['tmp_name'] ?? '');
$origName = (string)($upload['name'] ?? 'template.xlsx');
$uiZone   = trim((string)($_POST['zone'] ?? ''));
$uiSheet  = trim((string)($_POST['sheet'] ?? ''));

try {
    $pdo      = db();
    $workbook = readXlsxFile($tmpPath, $origName);
    $result   = validateImportWorkbook($pdo, $workbook, $uiZone, $uiSheet);

    $errorReportB64 = null;
    if (!$result['success'] && !empty($result['rows'])) {
        $errorReportB64 = base64_encode(buildImportErrorReportBytes($result));
    }
    $result['error_report_xlsx_base64'] = $errorReportB64;

    http_response_code($result['success'] ? 200 : 422);
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
        'errors'           => ['เกิดข้อผิดพลาดขณะตรวจสอบไฟล์ กรุณาลองใหม่อีกครั้ง / An unexpected error occurred while validating the file.'],
        'warnings'         => [],
        'summary'          => [],
        'rows'             => [],
    ], JSON_UNESCAPED_UNICODE);
}
