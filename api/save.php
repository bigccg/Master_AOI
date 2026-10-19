<?php
/**
 * API Endpoint: Save board record (Add / Edit) via AJAX
 * POST /api/save.php
 * Accepts Form POST or JSON
 * Returns JSON: { success: bool, message?: string, errors?: array, id?: int, code?: string }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'errors' => ['Method not allowed']]);
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
    echo json_encode(['success' => false, 'errors' => ['Invalid CSRF token']]);
    exit;
}

$sheet = trim((string)($input['sheet'] ?? DEFAULT_SHEET));
if (!isset(SHEETS[$sheet])) {
    $sheet = DEFAULT_SHEET;
}

$id      = (int)($input['id'] ?? 0);
$code    = trim((string)($input['code'] ?? ''));
$shelf   = strtoupper(trim((string)($input['shelf'] ?? '')));
$product = strtoupper(trim((string)($input['product'] ?? '')));
$side    = trim((string)($input['side'] ?? ''));
$type    = trim((string)($input['board_type'] ?? ''));
$status  = (string)($input['board_status'] ?? '');
$pcs     = trim((string)($input['pcs'] ?? ''));
$defects = trim((string)($input['defects'] ?? ''));

$formErrors = [];
$pdo = db();

// Validate / Generate Number Code
$old = ['code' => '', 'shelf' => '', 'shelf_no' => 0];
if ($id > 0) {
    $cur = $pdo->prepare('SELECT code, shelf, shelf_no FROM boards WHERE id = ? AND sheet = ?');
    $cur->execute([$id, $sheet]);
    $old = $cur->fetch() ?: $old;
    $oldCode = (string)$old['code'];
    if ($code === '') {
        $code = $oldCode;
    }
    if ($code !== $oldCode && !isValidNumberCode($code)) {
        $formErrors[] = 'Number Code ต้องอยู่ในรูปแบบ ' . CODE_PREFIX . '-YY-MM-000 เช่น ' . CODE_PREFIX . '-25-12-023 (เลข running 001-999)';
    }
} else {
    try {
        $code = nextNumberCode($pdo, $sheet);
    } catch (RuntimeException $e) {
        $formErrors[] = $e->getMessage();
    }
}

// Validate Shelf
$sp      = shelfPrefix($sheet);
$shelfNo = parseShelf($sheet, $shelf);
if ($shelfNo === null) {
    $formErrors[] = "กรุณาเลือก Shelf ของชีต $sheet ({$sp}1, {$sp}2, ...)";
} else {
    $shelf = $sp . $shelfNo;   // normalize
    $limit = magazineCount($pdo, $sheet) * MAGAZINE_SIZE;
    if ($shelfNo > $limit) {
        $formErrors[] = "Shelf $shelf เกินจำนวนชั้นของชีต $sheet (ตอนนี้มีถึง {$sp}{$limit}) ต้องเพิ่ม Magazine ก่อน";
    } else {
        $taken = $pdo->prepare('SELECT id FROM boards WHERE sheet = ? AND shelf_no = ? AND id <> ?');
        $taken->execute([$sheet, $shelfNo, $id]);
        if ($taken->fetch()) {
            $formErrors[] = "Shelf $shelf มีงานอยู่แล้ว กรุณาเลือก Shelf อื่น";
        }
    }
}

// Validate fields
if ($product === '' || strlen($product) > 40) {
    $formErrors[] = 'กรุณากรอก Product Model';
}
if ($side === '' || !in_array($side, BOARD_SIDES, true)) {
    $formErrors[] = 'กรุณาเลือก Side';
}
if ($type === '' || !in_array($type, BOARD_TYPES, true)) {
    $formErrors[] = 'กรุณาเลือก Board Type';
}
if ($status === '' || !in_array($status, ['G', 'D', 'GD'], true)) {
    $formErrors[] = 'กรุณาเลือก Board Classification';
}
if ($pcs !== '' && !in_array($pcs, OTHER_OPTIONS, true)) {
    $formErrors[] = 'Other ไม่ถูกต้อง';
}

// Validate defect selection if D or GD
if (in_array($status, ['D', 'GD'], true) && $defects === '') {
    $formErrors[] = 'กรุณาเลือกข้อบกพร่องอย่างน้อย 1 รายการสำหรับสถานะ Defect';
}

if (!$formErrors && $id > 0) {
    $dup = $pdo->prepare('SELECT id FROM boards WHERE sheet = ? AND code = ? AND id <> ?');
    $dup->execute([$sheet, $code, $id]);
    if ($dup->fetch()) {
        $formErrors[] = "Number Code \"$code\" มีอยู่แล้วในชีต $sheet";
    }
}

if ($formErrors) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'errors'  => $formErrors
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($id > 0) {
        $st = $pdo->prepare('UPDATE boards SET code=?, shelf=?, shelf_no=?, mag_slot=?, product=?, side=?, board_type=?, status=?, pcs_status=?, defects=? WHERE id=? AND sheet=?');
        $st->execute([$code, $shelf, $shelfNo, magazineSlotText($sheet, $shelfNo), $product, $side, $type, $status, $pcs, $defects, $id, $sheet]);
        $message = 'แก้ไขข้อมูลเรียบร้อยแล้ว / Record updated.';
        $savedId = $id;
    } else {
        $ins = $pdo->prepare('INSERT INTO boards (sheet, code, shelf, shelf_no, mag_slot, product, side, board_type, status, pcs_status, defects, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        for ($try = 1; ; $try++) {
            try {
                $ins->execute([$sheet, $code, $shelf, $shelfNo, magazineSlotText($sheet, $shelfNo), $product, $side, $type, $status, $pcs, $defects, date('Y-m-d H:i:s')]);
                $savedId = (int)$pdo->lastInsertId();
                break;
            } catch (PDOException $e) {
                $shelfClash = str_contains($e->getMessage(), 'uq_sheet_shelf');
                if (($e->errorInfo[1] ?? 0) !== 1062 || $shelfClash || $try >= 5) {
                    throw $e;
                }
                $code = nextNumberCode($pdo, $sheet);
            }
        }
        $message = "บันทึกลงชีต $sheet เรียบร้อยแล้ว (Number Code: $code, Shelf: $shelf) / Record added.";
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'id'      => $savedId,
        'code'    => $code,
        'shelf'   => $shelf,
        'sheet'   => $sheet
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_sheet_shelf')) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'errors'  => ["Shelf $shelf มีงานอยู่แล้ว กรุณาเลือก Shelf อื่น"]
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'errors'  => ['Database error: ' . $e->getMessage()]
        ], JSON_UNESCAPED_UNICODE);
    }
}

