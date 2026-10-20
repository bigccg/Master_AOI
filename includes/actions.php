<?php
/**
 * POST actions (non-AJAX fallback): Add Magazine + Save board. Sets $flash / $formErrors / $editRow.
 * Uses shared saveBoardRecord() in config/database.php to avoid duplicate validation/retry logic.
 */

declare(strict_types=1);

// ──────────────────────────────────────────────
// POST: Save (Add/Edit) & Add Magazine (delete is handled by api/delete.php)
// ──────────────────────────────────────────────

$flash = null;
$formErrors = [];
$editRow = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }

    $action    = (string)($_POST['action'] ?? '');
    $postZone  = normalizeZone((string)($_POST['zone'] ?? $zone));
    $zoneShts  = zoneSheets($postZone);
    $rawSheet  = trim((string)($_POST['sheet'] ?? $sheet));
    $postSheet = isset($zoneShts[$rawSheet]) ? $rawSheet : defaultSheetForZone($postZone);

    if ($action === 'add_magazine') {
        if ($postSheet === '' || !isset(SHEETS[$postSheet])) {
            $_SESSION['flash'] = ['err', EMPTY_ZONE_INSERT_ERROR];
            header('Location: ' . url(['edit' => null]));
            exit;
        }
        // Only allowed when every shelf of the sheet is taken ("Magazine is Max") and after the Confirm click.
        $cnt      = magazineCount($pdo, $postSheet);
        $cap      = $cnt * MAGAZINE_SIZE;
        $usedStmt = $pdo->prepare('SELECT COUNT(DISTINCT shelf_no) FROM boards WHERE zone = ? AND sheet = ? AND shelf_no BETWEEN 1 AND ?');
        $usedStmt->execute([$postZone, $postSheet, $cap]);
        $used = (int)$usedStmt->fetchColumn();

        if ((int)($_POST['magazines'] ?? 0) !== $cnt) {
            $_SESSION['flash'] = ['err', 'เพิ่ม Magazine ไม่ได้ เนื่องจากจำนวน Magazine ในระบบเปลี่ยนไปแล้ว กรุณารีเฟรชหน้าจอแล้วลองใหม่ / Magazine count changed, please refresh and retry.'];
        } elseif ($used < $cap) {
            $_SESSION['flash'] = ['err', 'เพิ่ม Magazine ไม่ได้ เนื่องจากยังมีชั้นวาง (Shelf) ว่างอยู่ในชีตนี้ กรุณาใช้ชั้นวางที่ว่างก่อน / Free shelves remain in this sheet.'];
        } elseif ($cnt >= MAGAZINE_LIMIT) {
            $_SESSION['flash'] = ['err', 'เพิ่ม Magazine ไม่ได้ เนื่องจากครบจำนวน Magazine สูงสุด (' . MAGAZINE_LIMIT . ') แล้ว / Maximum Magazine limit (' . MAGAZINE_LIMIT . ') reached.'];
        } else {
            $new = $cnt + 1;
            $up  = $pdo->prepare('UPDATE sheet_magazines SET magazines = ? WHERE sheet = ?');
            $up->execute([$new, $postSheet]);
            if ($up->rowCount() === 0) {
                try {
                    $pdo->prepare('INSERT INTO sheet_magazines (sheet, magazines) VALUES (?, ?)')->execute([$postSheet, $new]);
                } catch (PDOException $e) {
                    $up->execute([$new, $postSheet]);
                }
            }
            [$from, $to] = magazineRange($new);
            $_SESSION['flash'] = ['ok', 'เพิ่ม Magazine ' . ordinal($new) . " (Shelf $from-$to) ให้ชีต $postSheet (โซน $postZone) เรียบร้อยแล้ว / Magazine added to $postSheet."];
        }
        header('Location: ' . url(['edit' => null]));
        exit;
    }

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        // When on All Size Magazine ("ALL"), new records cannot be inserted; edits resolve their own sheet inside saveBoardRecord()
        $targetSheet = ($id === 0 && $isAllSheets && $rawSheet === 'ALL') ? 'ALL' : ($rawSheet !== '' ? $rawSheet : $sheet);

        $result = saveBoardRecord($pdo, array_merge($_POST, [
            'zone'  => $postZone,
            'sheet' => $targetSheet,
        ]));

        if ($result['success']) {
            $_SESSION['flash'] = ['ok', $result['message']];
            header('Location: ' . url(['edit' => null]));
            exit;
        }

        $formErrors = $result['errors'];
        $editRow    = $result['edit_row'];
    }
}

if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
