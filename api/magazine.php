<?php
/**
 * API Endpoint: Add Magazine to sheet (AJAX)
 * POST /api/magazine.php
 * Body: { csrf: string, zone?: string, sheet: string, magazines: int }
 * Returns JSON: { success: bool, message?: string, magazines?: int }
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = $_POST;
$rawBody = file_get_contents('php://input');
if (!empty($rawBody) && ($json = json_decode($rawBody, true)) && is_array($json)) {
    $input = array_merge($input, $json);
}

$csrfToken = (string)($input['csrf'] ?? '');
if (!hash_equals(csrf(), $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$zone  = normalizeZone((string)($input['zone'] ?? DEFAULT_ZONE));
$sheet = trim((string)($input['sheet'] ?? ''));
if (!isset(zoneSheets($zone)[$sheet])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => !zoneHasSheets($zone)
            ? EMPTY_ZONE_INSERT_ERROR
            : 'ไม่สามารถเพิ่ม Magazine ได้ เนื่องจากชีตที่ระบุไม่อยู่ในโซนที่เลือก กรุณาเลือกชีตใหม่ / Selected sheet does not belong to this zone.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$cnt = magazineCount($pdo, $sheet);
$cap = $cnt * MAGAZINE_SIZE;

$usedStmt = $pdo->prepare('SELECT COUNT(DISTINCT shelf_no) FROM boards WHERE zone = ? AND sheet = ? AND shelf_no BETWEEN 1 AND ?');
$usedStmt->execute([$zone, $sheet, $cap]);
$used = (int)$usedStmt->fetchColumn();

$postedMagazines = (int)($input['magazines'] ?? 0);
if ($postedMagazines !== $cnt) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'เพิ่ม Magazine ไม่ได้ เนื่องจากข้อมูลจำนวน Magazine เปลี่ยนไปแล้ว กรุณาลองใหม่อีกครั้ง / Magazine count changed, please retry.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($used < $cap) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'เพิ่ม Magazine ไม่ได้ เนื่องจากยังมีชั้นวาง (Shelf) ว่างอยู่ในชีตนี้ กรุณาใช้ชั้นวางที่ว่างก่อน / Free shelves remain in this sheet.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($cnt >= MAGAZINE_LIMIT) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'เพิ่ม Magazine ไม่ได้ เนื่องจากครบจำนวน Magazine สูงสุดแล้ว (' . MAGAZINE_LIMIT . ') / Magazine limit reached.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$new = $cnt + 1;
$up  = $pdo->prepare('UPDATE sheet_magazines SET magazines = ? WHERE sheet = ?');
$up->execute([$new, $sheet]);
if ($up->rowCount() === 0) {
    try {
        $pdo->prepare('INSERT INTO sheet_magazines (sheet, magazines) VALUES (?, ?)')->execute([$sheet, $new]);
    } catch (PDOException $e) {
        $up->execute([$new, $sheet]);
    }
}

[$from, $to] = magazineRange($new);
$msg = 'เพิ่ม Magazine ' . ordinal($new) . " (Shelf $from-$to) ให้ชีต $sheet (โซน $zone) เรียบร้อยแล้ว / Magazine added to $sheet.";

echo json_encode([
    'success'   => true,
    'message'   => $msg,
    'magazines' => $new,
    'sheet'     => $sheet,
    'zone'      => $zone,
], JSON_UNESCAPED_UNICODE);
