<?php
/**
 * API Endpoint: Data Backup & Restore Manager ("Data Time-Machine", administrators only)
 *
 *   GET  /api/backup.php?mode=list         -> { success: true, data: { snapshots, counts, current, settings } }
 *   GET  /api/backup.php?mode=diff&id=N    -> { success: true, data: { snapshot, current, is_identical, boards_diff, ... } }
 *   GET  /api/backup.php?mode=download&id=N -> Downloads signed AOI_Backup_<period>_id<N>_<hash>.json
 *
 *   POST /api/backup.php  { csrf, action, ... }
 *     action = create          : Create a manual checkpoint (`title`)
 *     action = update_settings : Update `retention_months` (1..60) and `auto_monthly_enabled`
 *     action = restore         : Atomic full-system restore to snapshot `id` (with automatic `pre_restore` safety backup)
 *     action = delete          : Delete snapshot `id`
 *     action = upload          : Import a signed `.json` backup file (`$_FILES['backup_file']` or `json_content`)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/backup-manager.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'errors' => [BACKUP_METHOD_ERROR]], JSON_UNESCAPED_UNICODE);
    exit;
}

requireAdminJson();

$pdo = db();

if ($method === 'GET') {
    $mode = trim((string)($_GET['mode'] ?? 'list'));

    if ($mode === 'download') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'errors' => [BACKUP_NOT_FOUND_ERROR]], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $file = exportSnapshotFilePayload($pdo, $id);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
            header('Content-Length: ' . strlen($file['json']));
            echo $file['json'];
            exit;
        } catch (Throwable $e) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'errors' => [$e->getMessage()]], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    header('Content-Type: application/json; charset=utf-8');

    if ($mode === 'diff') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => [BACKUP_NOT_FOUND_ERROR]], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $diff = compareSnapshotWithCurrent($pdo, $id);
            echo json_encode(['success' => true, 'data' => $diff], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            $code = ($e->getMessage() === BACKUP_NOT_FOUND_ERROR) ? 404 : 422;
            http_response_code($code);
            echo json_encode(['success' => false, 'errors' => [$e->getMessage()]], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    try {
        $list = listDataSnapshots($pdo);
        echo json_encode(['success' => true, 'data' => $list], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'errors' => [$e->getMessage()]], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// POST actions
header('Content-Type: application/json; charset=utf-8');

$input   = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody) && ($json = json_decode($rawBody, true)) && is_array($json)) {
    $input = array_merge($input, $json);
}

$csrfToken = $input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($csrfToken) || !hash_equals(csrf(), $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'errors' => [BACKUP_CSRF_ERROR]], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string)($input['action'] ?? ''));

try {
    if ($action === 'create') {
        $title = trim((string)($input['title'] ?? ''));
        $snap  = createDataSnapshot($pdo, 'manual', $title, 'admin');
        echo json_encode([
            'success'  => true,
            'message'  => "สร้างจุดสำรองข้อมูล \"{$snap['title']}\" เรียบร้อยแล้ว ({$snap['board_count']} บอร์ด)",
            'snapshot' => $snap,
            'data'     => listDataSnapshots($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update_settings') {
        $retMonths   = isset($input['retention_months']) ? (int)$input['retention_months'] : 0;
        $autoEnabled = !isset($input['auto_monthly_enabled'])
            || filter_var($input['auto_monthly_enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

        $res    = updateBackupSettings($pdo, $retMonths, $autoEnabled);
        $pruned = (int)$res['pruned_count'];
        $msg    = "บันทึกการตั้งค่าเก็บ Auto Backup ย้อนหลังสูงสุด {$retMonths} เดือนเรียบร้อยแล้ว";
        if ($pruned > 0) {
            $msg .= " (ลบ Auto Backup เก่าที่เกินกำหนดออก {$pruned} รายการ)";
        }
        echo json_encode([
            'success'      => true,
            'message'      => $msg,
            'settings'     => $res['settings'],
            'pruned_count' => $pruned,
            'data'         => listDataSnapshots($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'restore') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => [BACKUP_NOT_FOUND_ERROR]], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $res  = restoreDataSnapshot($pdo, $id, clientIp(), true);
        $snap = $res['restored_snapshot'];
        echo json_encode([
            'success' => true,
            'message' => "ย้อนข้อมูลกลับไปยังจุดสำรอง \"{$snap['title']}\" ({$snap['board_count']} บอร์ด) สำเร็จแล้ว และระบบได้สำรองสถานะก่อนย้อนไว้ให้เรียบร้อย",
            'result'  => $res,
            'data'    => listDataSnapshots($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => [BACKUP_NOT_FOUND_ERROR]], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $deleted = deleteDataSnapshot($pdo, $id);
        echo json_encode([
            'success' => true,
            'message' => "ลบจุดสำรองข้อมูล \"{$deleted['title']}\" เรียบร้อยแล้ว",
            'deleted' => $deleted,
            'data'    => listDataSnapshots($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'upload') {
        $rawJson = '';
        if (isset($_FILES['backup_file']) && is_array($_FILES['backup_file'])) {
            $err = (int)($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                throw new InvalidArgumentException(BACKUP_UPLOAD_TOO_LARGE_ERROR);
            }
            if ($err !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException(BACKUP_UPLOAD_EMPTY_ERROR);
            }
            $size = (int)($_FILES['backup_file']['size'] ?? 0);
            if ($size > BACKUP_MAX_UPLOAD_BYTES) {
                throw new InvalidArgumentException(BACKUP_UPLOAD_TOO_LARGE_ERROR);
            }
            $tmpPath = (string)($_FILES['backup_file']['tmp_name'] ?? '');
            if ($tmpPath === '' || !is_readable($tmpPath)) {
                throw new InvalidArgumentException(BACKUP_UPLOAD_EMPTY_ERROR);
            }
            $rawJson = (string)file_get_contents($tmpPath);
        } elseif (isset($input['json_content']) && is_string($input['json_content'])) {
            $rawJson = $input['json_content'];
        } else {
            throw new InvalidArgumentException(BACKUP_UPLOAD_EMPTY_ERROR);
        }

        $imported = importSnapshotFromUploadedJson($pdo, $rawJson, 'admin-upload');
        echo json_encode([
            'success'  => true,
            'message'  => "นำเข้าไฟล์สำรองข้อมูล \"{$imported['title']}\" ({$imported['board_count']} บอร์ด) สำเร็จแล้ว สามารถกดดูเปรียบเทียบ (Diff) หรือกดย้อนข้อมูลได้ทันที",
            'snapshot' => $imported,
            'data'     => listDataSnapshots($pdo),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'errors'  => ['ไม่รู้จักคำสั่ง (action) ที่ส่งมา กรุณารีเฟรชหน้าเว็บแล้วลองใหม่ / Unknown backup action.'],
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => [$e->getMessage()]], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    $code = (int)$e->getCode();
    if ($code === 503) {
        http_response_code(503);
    } elseif ($e->getMessage() === BACKUP_NOT_FOUND_ERROR) {
        http_response_code(404);
    } else {
        http_response_code(422);
    }
    echo json_encode(['success' => false, 'errors' => [$e->getMessage()]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'errors' => ['เกิดข้อผิดพลาดในการจัดการข้อมูลสำรอง: ' . $e->getMessage()]], JSON_UNESCAPED_UNICODE);
}
