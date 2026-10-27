<?php
/**
 * API Endpoint: Import Template Info & .xlsx Download
 *
 * GET /api/import_template.php?zone=Zone+A&sheet=38x46:B&rows=50
 *   - mode=info     -> Returns JSON telemetry (next code range, remaining in month, rack range, cap notice)
 *   - mode=download -> Streams the signed .xlsx template file (default)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/import-template.php';

$zone  = trim((string)($_GET['zone'] ?? $_POST['zone'] ?? ''));
$sheet = trim((string)($_GET['sheet'] ?? $_POST['sheet'] ?? ''));
$rows  = isset($_GET['rows']) ? (int)$_GET['rows'] : (isset($_POST['rows']) ? (int)$_POST['rows'] : IMPORT_DEFAULT_ROWS);
$mode  = strtolower(trim((string)($_GET['mode'] ?? $_POST['mode'] ?? 'download')));

try {
    $pdo = db();

    if ($mode === 'info') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        $ctx = getImportTemplateContext($pdo, $zone, $sheet, $rows);
        if (!$ctx['ok']) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'errors'  => [$ctx['error'] ?? IMPORT_SELECT_ZONE_SHEET_ERROR],
                'context' => $ctx,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'success' => true,
            'errors'  => [],
            'context' => $ctx,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = buildImportTemplateWorkbook($pdo, $zone, $sheet, $rows);
    if (!$result['ok']) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode([
            'success' => false,
            'errors'  => [$result['error'] ?? IMPORT_SELECT_ZONE_SHEET_ERROR],
            'context' => $result['context'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $bytes    = $result['bytes'];
    $filename = $result['filename'];
    $ctx      = $result['context'];

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Import-Zone: ' . rawurlencode((string)$ctx['zone']));
    header('X-Import-Sheet: ' . rawurlencode((string)$ctx['sheet']));
    header('X-Import-Start-Code: ' . (string)$ctx['start_code']);
    header('X-Import-End-Code: ' . (string)$ctx['end_code']);
    header('X-Import-Actual-Rows: ' . (int)$ctx['actual_rows']);
    header('X-Import-Capped: ' . ($ctx['capped_by_month_limit'] ? '1' : '0'));

    echo $bytes;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'errors'  => ['ไม่สามารถสร้างไฟล์แม่แบบได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง / Unable to generate template right now.'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
