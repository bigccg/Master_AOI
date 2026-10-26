<?php
/**
 * Data Retrieval, Live Master Samples Link & Stage Tracking for AOI Program Tracker
 */

declare(strict_types=1);

const PROGRAM_CREATE_FORBIDDEN_ERROR = 'ไม่สามารถสร้างโปรแกรมใหม่จากหน้านี้ได้ เนื่องจากรายการโปรแกรมต้องเชื่อมโยงจากบอร์ด Defect (D / G+D) ในหน้า Master Board เท่านั้น กรุณาเพิ่มข้อมูลบอร์ดที่หน้า Master Board / Creating programs directly from this page is disabled. Programs are synced automatically from Defect (D / G+D) boards on the Master Board.';

/**
 * Format relative date (Rule: <= 7 days = relative, > 7 days = YYYY-MM-DD)
 */
function formatProgramDate(?string $dtStr): string
{
    if (!$dtStr) return '-';
    try {
        $dt = new DateTime($dtStr, new DateTimeZone('Asia/Bangkok'));
        $now = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
        $diff = $now->diff($dt);
        $days = (int)$diff->days;

        if ($days === 0 && $dt->format('Y-m-d') === $now->format('Y-m-d')) {
            return 'วันนี้ ' . $dt->format('H:i');
        }
        if ($days === 1 || ($days === 0 && $dt->format('Y-m-d') !== $now->format('Y-m-d'))) {
            return 'เมื่อวานนี้';
        }
        if ($days <= 7) {
            return "{$days} วันที่แล้ว";
        }
        return $dt->format('Y-m-d');
    } catch (Throwable) {
        return (string)$dtStr;
    }
}

/**
 * Calculate overdue days if > 14 days and not complete
 */
function overdueDays(?string $dtStr, bool $isComplete): int
{
    if ($isComplete || !$dtStr) return 0;
    try {
        $dt = new DateTime($dtStr, new DateTimeZone('Asia/Bangkok'));
        $now = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
        $diff = $now->diff($dt);
        $days = (int)$diff->days;
        return $days > 14 ? $days : 0;
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Check if a program row is complete
 */
function isProgramComplete(array $r): bool
{
    $s1 = (int)($r['stage_1'] ?? 0);
    $s2 = (int)($r['stage_2'] ?? 0);
    $s3 = (int)($r['stage_3'] ?? 0);
    $s4 = (int)($r['stage_4'] ?? 0);
    return ($s1 === 2) && ($s2 === 2 || $s2 === 9) && ($s3 === 2) && ($s4 === 2);
}

/**
 * Compute high-level program status
 */
function computeProgramStatus(array $r): string
{
    $s1 = (int)($r['stage_1'] ?? 0);
    $s2 = (int)($r['stage_2'] ?? 0);
    $s3 = (int)($r['stage_3'] ?? 0);
    $s4 = (int)($r['stage_4'] ?? 0);
    if ($s1 === 2 && ($s2 === 2 || $s2 === 9) && $s3 === 2 && $s4 === 2) {
        return 'Complete';
    }
    if ($s1 === 2 && ($s2 === 2 || $s2 === 9) && $s3 < 2) {
        return 'Wait Master';
    }
    return 'Queued';
}

/**
 * Normalize comma-separated shelf list from GROUP_CONCAT
 */
function normalizeShelfList(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    $parts = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), static fn(string $s) => $s !== '')));
    return implode(', ', $parts);
}

/**
 * Single aggregation subquery on `boards` (GROUP BY product, side) for D and GD boards.
 * Compatible with both SQLite and MySQL; avoids N+1 queries.
 */
function boardsMasterSubquerySql(PDO $pdo): string
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $dConcat  = "GROUP_CONCAT(CASE WHEN status = 'D' THEN shelf END, ', ')";
        $gdConcat = "GROUP_CONCAT(CASE WHEN status = 'GD' THEN shelf END, ', ')";
        $shConcat = "GROUP_CONCAT(DISTINCT shelf)";
    } else {
        $dConcat  = "GROUP_CONCAT(CASE WHEN status = 'D' THEN shelf END ORDER BY shelf ASC SEPARATOR ', ')";
        $gdConcat = "GROUP_CONCAT(CASE WHEN status = 'GD' THEN shelf END ORDER BY shelf ASC SEPARATOR ', ')";
        $shConcat = "GROUP_CONCAT(DISTINCT shelf ORDER BY shelf ASC SEPARATOR ', ')";
    }
    return "
        SELECT
            product AS b_product,
            side AS b_side,
            SUM(CASE WHEN status = 'D' THEN 1 ELSE 0 END) AS master_d,
            SUM(CASE WHEN status = 'GD' THEN 1 ELSE 0 END) AS master_gd,
            $dConcat AS master_d_shelves,
            $gdConcat AS master_gd_shelves,
            $shConcat AS board_shelves
        FROM boards
        WHERE status IN ('D', 'GD')
          AND side IN ('F-Side', 'B-Side')
        GROUP BY product, side
    ";
}

/**
 * Standardize and enrich program record for API/UI output
 */
function formatProgramRow(array $r): array
{
    $complete  = isProgramComplete($r);
    $overdue   = overdueDays($r['updated_at'] ?? null, $complete);
    $masterD   = (int)($r['master_d'] ?? 0);
    $masterGd  = (int)($r['master_gd'] ?? 0);
    $dShelves  = normalizeShelfList($r['master_d_shelves'] ?? '');
    $gdShelves = normalizeShelfList($r['master_gd_shelves'] ?? '');
    $bShelves  = normalizeShelfList($r['board_shelves'] ?? '');
    $shelfVal  = trim((string)($r['shelf'] ?? ''));
    if ($shelfVal === '' && $bShelves !== '') {
        $shelfVal = $bShelves;
    }

    return [
        'id'                => (int)($r['id'] ?? 0),
        'product_model'     => (string)($r['product_model'] ?? ''),
        'side'              => (string)($r['side'] ?? 'F-Side'),
        'family_key'        => (string)($r['family_key'] ?? ''),
        'machine_type'      => (string)($r['machine_type'] ?? 'OMRON (2D)'),
        'stage_1'           => (int)($r['stage_1'] ?? 0),
        'stage_1_by'        => (string)($r['stage_1_by'] ?? ''),
        'stage_1_at'        => (string)($r['stage_1_at'] ?? ''),
        'stage_2'           => (int)($r['stage_2'] ?? 0),
        'stage_2_by'        => (string)($r['stage_2_by'] ?? ''),
        'stage_2_at'        => (string)($r['stage_2_at'] ?? ''),
        'stage_3'           => (int)($r['stage_3'] ?? 0),
        'stage_3_by'        => (string)($r['stage_3_by'] ?? ''),
        'stage_3_at'        => (string)($r['stage_3_at'] ?? ''),
        'stage_4'           => (int)($r['stage_4'] ?? 0),
        'stage_4_by'        => (string)($r['stage_4_by'] ?? ''),
        'stage_4_at'        => (string)($r['stage_4_at'] ?? ''),
        'master_d'          => $masterD,
        'master_gd'         => $masterGd,
        'master_d_shelves'  => $dShelves,
        'master_gd_shelves' => $gdShelves,
        'has_master_defect' => ($masterD + $masterGd) > 0,
        'owner'             => (string)($r['owner'] ?? ''),
        'shelf'             => $shelfVal,
        'program_ok'        => (string)($r['program_ok'] ?? ''),
        'wait_reason'       => (string)($r['wait_reason'] ?? ''),
        'note'              => (string)($r['note'] ?? ''),
        'version'           => (int)($r['version'] ?? 1),
        'created_at'        => (string)($r['created_at'] ?? ''),
        'updated_at'        => (string)($r['updated_at'] ?? ''),
        'updated_rel'       => formatProgramDate($r['updated_at'] ?? null),
        'is_complete'       => $complete,
        'overdue_days'      => $overdue,
    ];
}

/**
 * Fetch single program by ID (including live Master Samples counts from `boards`)
 */
function fetchProgramById(PDO $pdo, int $id): ?array
{
    $sub = boardsMasterSubquerySql($pdo);
    $sql = "
        SELECT
            p.*,
            COALESCE(b.master_d, 0) AS master_d,
            COALESCE(b.master_gd, 0) AS master_gd,
            b.master_d_shelves,
            b.master_gd_shelves,
            b.board_shelves
        FROM aoi_programs p
        LEFT JOIN ($sub) b
          ON b.b_product = p.product_model AND b.b_side = p.side
        WHERE p.id = :id
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? formatProgramRow($row) : null;
}

/**
 * Update single stage with optimistic locking & audit trail
 */
function updateProgramStage(
    PDO $pdo,
    int $id,
    int $stage,
    int $status,
    string $operator,
    int $expectedVersion,
    ?string $note = null,
    string $ip = ''
): array {
    if (!in_array($stage, [1, 2, 3, 4], true)) {
        return ['success' => false, 'status_code' => 422, 'errors' => ['อัปเดตขั้นไม่สำเร็จ เนื่องจากหมายเลขขั้นไม่ถูกต้อง (ต้องเป็น 1–4) กรุณาลองใหม่ / Invalid stage number.']];
    }
    if (!in_array($status, [0, 1, 2, 9], true)) {
        return ['success' => false, 'status_code' => 422, 'errors' => ['อัปเดตสถานะไม่สำเร็จ เนื่องจากค่าสถานะไม่ถูกต้อง กรุณาเลือกสถานะจากรายการ / Invalid stage status.']];
    }

    $st = $pdo->prepare('SELECT * FROM aoi_programs WHERE id = :id');
    $st->execute([':id' => $id]);
    $current = $st->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        return ['success' => false, 'status_code' => 404, 'errors' => ['ไม่พบรายการโปรแกรมที่ต้องการอัปเดต กรุณาโหลดข้อมูลใหม่ / Program record not found.']];
    }

    $currentVer = (int)$current['version'];
    if ($expectedVersion > 0 && $currentVer !== $expectedVersion) {
        return [
            'success'     => false,
            'conflict'    => true,
            'status_code' => 409,
            'current'     => fetchProgramById($pdo, $id),
            'errors'      => ['บันทึกไม่สำเร็จ เนื่องจากมีผู้ใช้อื่นแก้ไขรายการนี้ไปแล้ว กรุณากดโหลดข้อมูลใหม่ / Record was modified by someone else. Please reload.'],
        ];
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');
    $stageCol = "stage_{$stage}";
    $byCol    = "stage_{$stage}_by";
    $atCol    = "stage_{$stage}_at";

    $oldStatus = (int)$current[$stageCol];
    $newBy = ($status === 0) ? null : ($operator !== '' ? $operator : 'System');
    $newAt = ($status === 0) ? null : $now;

    $tempRow = $current;
    $tempRow[$stageCol] = $status;
    $newProgramOk = computeProgramStatus($tempRow);

    $sql = "
        UPDATE aoi_programs
        SET $stageCol = :stg,
            $byCol = :stg_by,
            $atCol = :stg_at,
            program_ok = :prog_ok,
            note = CASE WHEN :note IS NOT NULL THEN :note_val ELSE note END,
            version = version + 1,
            updated_at = :now
        WHERE id = :id AND version = :version
    ";

    $upd = $pdo->prepare($sql);
    $upd->execute([
        ':stg'      => $status,
        ':stg_by'   => $newBy,
        ':stg_at'   => $newAt,
        ':prog_ok'  => $newProgramOk,
        ':note'     => $note,
        ':note_val' => $note,
        ':now'      => $now,
        ':id'       => $id,
        ':version'  => $currentVer,
    ]);

    if ($upd->rowCount() === 0) {
        $latest = fetchProgramById($pdo, $id);
        return [
            'success'     => false,
            'conflict'    => true,
            'status_code' => 409,
            'current'     => $latest,
            'errors'      => ['การบันทึกล้มเหลวเนื่องจากมีการแก้ไขชนกัน กรุณาโหลดข้อมูลใหม่แล้วลองอีกครั้ง / Conflict detected. Please retry.'],
        ];
    }

    $auditStmt = $pdo->prepare('
        INSERT INTO aoi_program_audit (program_id, action, field_name, old_value, new_value, operator, ip_address, created_at)
        VALUES (:pid, :act, :field, :old, :new, :op, :ip, :now)
    ');
    $auditStmt->execute([
        ':pid'   => $id,
        ':act'   => 'stage_update',
        ':field' => $stageCol,
        ':old'   => (string)$oldStatus,
        ':new'   => (string)$status,
        ':op'    => $operator !== '' ? $operator : 'System',
        ':ip'    => $ip,
        ':now'   => $now,
    ]);
    $auditId = (int)$pdo->lastInsertId();

    $updated = fetchProgramById($pdo, $id);
    return [
        'success'  => true,
        'message'  => "อัปเดตขั้นที่ {$stage} เรียบร้อยแล้ว / Stage {$stage} updated successfully.",
        'program'  => $updated,
        'audit_id' => $auditId,
    ];
}

/**
 * Update existing program row metadata (Operator `owner` or `note`) with optimistic locking & audit trail.
 * Never creates a new row.
 */
function updateProgramMeta(
    PDO $pdo,
    int $id,
    array $fields,
    string $operator,
    int $expectedVersion = 0,
    string $ip = ''
): array {
    $st = $pdo->prepare('SELECT * FROM aoi_programs WHERE id = :id');
    $st->execute([':id' => $id]);
    $current = $st->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        return ['success' => false, 'status_code' => 404, 'errors' => ['ไม่พบรายการโปรแกรมที่ต้องการแก้ไข กรุณาโหลดข้อมูลใหม่ / Program record not found.']];
    }

    $currentVer = (int)$current['version'];
    if ($expectedVersion > 0 && $currentVer !== $expectedVersion) {
        return [
            'success'     => false,
            'conflict'    => true,
            'status_code' => 409,
            'current'     => fetchProgramById($pdo, $id),
            'errors'      => ['บันทึกไม่สำเร็จ เนื่องจากมีผู้ใช้อื่นแก้ไขรายการนี้ไปแล้ว กรุณากดโหลดข้อมูลใหม่ / Record was modified by someone else. Please reload.'],
        ];
    }

    $hasOwner = array_key_exists('owner', $fields);
    $hasNote  = array_key_exists('note', $fields);
    if (!$hasOwner && !$hasNote) {
        return ['success' => false, 'status_code' => 422, 'errors' => ['ไม่ได้ระบุฟิลด์ที่ต้องการแก้ไข (ผู้ทำ หรือ หมายเหตุ) / No editable fields provided.']];
    }

    $newOwner = $hasOwner ? trim((string)$fields['owner']) : (string)($current['owner'] ?? '');
    $newNote  = $hasNote  ? trim((string)$fields['note'])  : (string)($current['note'] ?? '');

    if (mb_strlen($newOwner) > 50) {
        return ['success' => false, 'status_code' => 422, 'errors' => ['บันทึกชื่อผู้ทำไม่ได้ เนื่องจากความยาวเกิน 50 ตัวอักษร กรุณาย่อชื่อให้สั้นลง / Operator name exceeds 50 characters.']];
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');

    $upd = $pdo->prepare('
        UPDATE aoi_programs
        SET owner = :owner,
            note = :note,
            version = version + 1,
            updated_at = :now
        WHERE id = :id AND version = :version
    ');
    $upd->execute([
        ':owner'   => $newOwner !== '' ? $newOwner : null,
        ':note'    => $newNote !== '' ? $newNote : null,
        ':now'     => $now,
        ':id'      => $id,
        ':version' => $currentVer,
    ]);

    if ($upd->rowCount() === 0) {
        return [
            'success'     => false,
            'conflict'    => true,
            'status_code' => 409,
            'current'     => fetchProgramById($pdo, $id),
            'errors'      => ['การบันทึกล้มเหลวเนื่องจากมีการแก้ไขชนกัน กรุณาโหลดข้อมูลใหม่แล้วลองอีกครั้ง / Conflict detected. Please retry.'],
        ];
    }

    if ($hasOwner && $newOwner !== '') {
        saveProgramOperator($pdo, $newOwner);
    }

    // One audit row per field that actually changed, so each can be undone on its own
    // (a combined "owner,note" row could not be undone and lost the old owner value).
    $changes = [];
    if ($hasOwner && $newOwner !== (string)($current['owner'] ?? '')) {
        $changes['owner'] = [(string)($current['owner'] ?? ''), $newOwner];
    }
    if ($hasNote && $newNote !== (string)($current['note'] ?? '')) {
        $changes['note'] = [(string)($current['note'] ?? ''), $newNote];
    }

    $auditStmt = $pdo->prepare('
        INSERT INTO aoi_program_audit (program_id, action, field_name, old_value, new_value, operator, ip_address, created_at)
        VALUES (:pid, :act, :field, :old, :new, :op, :ip, :now)
    ');
    $auditId = 0;
    foreach ($changes as $fieldName => [$oldVal, $newVal]) {
        $auditStmt->execute([
            ':pid'   => $id,
            ':act'   => 'meta_update',
            ':field' => $fieldName,
            ':old'   => $oldVal,
            ':new'   => $newVal,
            ':op'    => $operator !== '' ? $operator : 'System',
            ':ip'    => $ip,
            ':now'   => $now,
        ]);
        $auditId = (int)$pdo->lastInsertId();
    }

    return [
        'success'  => true,
        'message'  => $hasOwner ? 'อัปเดตผู้ทำเรียบร้อยแล้ว / Operator updated.' : 'อัปเดตหมายเหตุเรียบร้อยแล้ว / Note updated.',
        'program'  => fetchProgramById($pdo, $id),
        'audit_id' => $auditId,
    ];
}

/**
 * Revert previous stage or metadata update via audit log.
 * Never inserts or deletes rows in `aoi_programs`.
 */
function undoProgramAudit(PDO $pdo, int $programId, int $auditId, string $operator, string $ip): array
{
    $st = $pdo->prepare('SELECT * FROM aoi_program_audit WHERE id = :aid AND program_id = :pid');
    $st->execute([':aid' => $auditId, ':pid' => $programId]);
    $audit = $st->fetch(PDO::FETCH_ASSOC);

    if (!$audit) {
        return ['success' => false, 'status_code' => 404, 'errors' => ['ไม่พบรายการประวัติที่ต้องการเลิกทำ / Audit record not found.']];
    }

    $action = (string)$audit['action'];
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');

    if ($action === 'stage_update') {
        $field = (string)$audit['field_name'];
        if (!in_array($field, ['stage_1', 'stage_2', 'stage_3', 'stage_4'], true)) {
            return ['success' => false, 'status_code' => 422, 'errors' => ['ไม่สามารถเลิกทำฟิลด์นี้ได้ / Cannot undo this field.']];
        }

        $stage = (int)str_replace('stage_', '', $field);
        $oldVal = (int)$audit['old_value'];

        $progSt = $pdo->prepare('SELECT * FROM aoi_programs WHERE id = :id');
        $progSt->execute([':id' => $programId]);
        $current = $progSt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            return ['success' => false, 'status_code' => 404, 'errors' => ['ไม่พบโปรแกรมที่ต้องการเลิกทำ / Program not found.']];
        }

        $byCol = "stage_{$stage}_by";
        $atCol = "stage_{$stage}_at";

        $temp = $current;
        $temp[$field] = $oldVal;
        $newOk = computeProgramStatus($temp);

        $upd = $pdo->prepare("
            UPDATE aoi_programs
            SET $field = :val,
                $byCol = CASE WHEN :val = 0 THEN NULL ELSE $byCol END,
                $atCol = CASE WHEN :val = 0 THEN NULL ELSE $atCol END,
                program_ok = :prog_ok,
                version = version + 1,
                updated_at = :now
            WHERE id = :id
        ");
        $upd->execute([
            ':val'     => $oldVal,
            ':prog_ok' => $newOk,
            ':now'     => $now,
            ':id'      => $programId,
        ]);

        $log = $pdo->prepare('
            INSERT INTO aoi_program_audit (program_id, action, field_name, old_value, new_value, operator, ip_address, created_at)
            VALUES (:pid, :act, :field, :old, :new, :op, :ip, :now)
        ');
        $log->execute([
            ':pid'   => $programId,
            ':act'   => 'undo_stage',
            ':field' => $field,
            ':old'   => (string)$audit['new_value'],
            ':new'   => (string)$oldVal,
            ':op'    => $operator ?: 'System',
            ':ip'    => $ip,
            ':now'   => $now,
        ]);

        return [
            'success' => true,
            'message' => 'เลิกทำสำเร็จ คืนค่าสถานะเรียบร้อยแล้ว / Stage update reverted.',
            'program' => fetchProgramById($pdo, $programId),
        ];
    }

    if ($action === 'meta_update') {
        $field = (string)$audit['field_name'];
        if (!in_array($field, ['owner', 'note'], true)) {
            return ['success' => false, 'status_code' => 422, 'errors' => ['ไม่สามารถเลิกทำฟิลด์นี้ได้ / Cannot undo this field.']];
        }
        $oldVal = trim((string)($audit['old_value'] ?? ''));

        $upd = $pdo->prepare("
            UPDATE aoi_programs
            SET $field = :val,
                version = version + 1,
                updated_at = :now
            WHERE id = :id
        ");
        $upd->execute([
            ':val' => $oldVal !== '' ? $oldVal : null,
            ':now' => $now,
            ':id'  => $programId,
        ]);

        $log = $pdo->prepare('
            INSERT INTO aoi_program_audit (program_id, action, field_name, old_value, new_value, operator, ip_address, created_at)
            VALUES (:pid, :act, :field, :old, :new, :op, :ip, :now)
        ');
        $log->execute([
            ':pid'   => $programId,
            ':act'   => 'undo_meta',
            ':field' => $field,
            ':old'   => (string)($audit['new_value'] ?? ''),
            ':new'   => $oldVal,
            ':op'    => $operator ?: 'System',
            ':ip'    => $ip,
            ':now'   => $now,
        ]);

        return [
            'success' => true,
            'message' => 'เลิกทำสำเร็จ คืนค่าข้อมูลเดิมเรียบร้อยแล้ว / Change reverted.',
            'program' => fetchProgramById($pdo, $programId),
        ];
    }

    return [
        'success'     => false,
        'status_code' => 403,
        'errors'      => ['ไม่อนุญาตให้เลิกทำรายการประเภทนี้ เพื่อป้องกันการสร้างหรือลบแถวโปรแกรมย้อนกลับ / Undo is only supported for stage and metadata updates on existing programs.'],
    ];
}

/**
 * Direct program creation from programs.php is permanently disabled (HTTP 403).
 * Programs must originate from Defect (D / G+D) boards saved on Master Board (index.php).
 */
function saveNewProgram(PDO $pdo, array $input, string $operator, string $ip): array
{
    return [
        'success'     => false,
        'status_code' => 403,
        'error'       => PROGRAM_CREATE_FORBIDDEN_ERROR,
        'errors'      => [PROGRAM_CREATE_FORBIDDEN_ERROR],
    ];
}

/**
 * Inspect `boards` for any row whose `side` is not in ('F-Side', 'B-Side') so callers can report without guessing.
 */
function findInvalidBoardSides(PDO $pdo): array
{
    $st = $pdo->query("
        SELECT id, zone, sheet, code, shelf, product, side, status
        FROM boards
        WHERE side NOT IN ('F-Side', 'B-Side')
        ORDER BY id ASC
    ");
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Count how many rows in `aoi_programs` currently have no Defect (D / GD) board in `boards`.
 */
function countUnlinkedPrograms(PDO $pdo): int
{
    $sql = "
        SELECT COUNT(*)
        FROM aoi_programs p
        LEFT JOIN (
            SELECT DISTINCT product, side
            FROM boards
            WHERE status IN ('D', 'GD') AND side IN ('F-Side', 'B-Side')
        ) b ON b.product = p.product_model AND b.side = p.side
        WHERE b.product IS NULL
    ";
    return (int)$pdo->query($sql)->fetchColumn();
}

/**
 * Fetch KPI metrics directly from database
 */
function fetchProgramKpis(PDO $pdo): array
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $overdueSql = ($driver === 'sqlite')
        ? "updated_at < datetime('now', '-14 days')"
        : "updated_at < (NOW() - INTERVAL 14 DAY)";

    $sql = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN stage_1 = 2 AND (stage_2 = 2 OR stage_2 = 9) AND stage_3 = 2 AND stage_4 = 2 THEN 1 ELSE 0 END) AS complete,
            SUM(CASE WHEN stage_1 = 2 AND (stage_2 = 2 OR stage_2 = 9) AND stage_3 < 2 THEN 1 ELSE 0 END) AS wait_master,
            SUM(CASE WHEN stage_1 < 2 THEN 1 ELSE 0 END) AS queued,
            SUM(CASE WHEN NOT (stage_1 = 2 AND (stage_2 = 2 OR stage_2 = 9) AND stage_3 = 2 AND stage_4 = 2) AND $overdueSql THEN 1 ELSE 0 END) AS overdue,
            SUM(CASE WHEN machine_type LIKE '%OMRON%' THEN 1 ELSE 0 END) AS cnt_omron,
            SUM(CASE WHEN machine_type LIKE '%YAMAHA%' THEN 1 ELSE 0 END) AS cnt_yamaha,
            SUM(CASE WHEN machine_type LIKE '%TRI%' THEN 1 ELSE 0 END) AS cnt_tri
        FROM aoi_programs
    ";

    $st = $pdo->query($sql);
    $row = $st->fetch() ?: [];

    $total = (int)($row['total'] ?? 0);
    $complete = (int)($row['complete'] ?? 0);
    $pctComplete = $total > 0 ? round(($complete / $total) * 100, 1) : 0.0;

    return [
        'total'        => $total,
        'complete'     => $complete,
        'pct_complete' => $pctComplete,
        'wait_master'  => (int)($row['wait_master'] ?? 0),
        'queued'       => (int)($row['queued'] ?? 0),
        'overdue'      => (int)($row['overdue'] ?? 0),
        'omron'        => (int)($row['cnt_omron'] ?? 0),
        'yamaha'       => (int)($row['cnt_yamaha'] ?? 0),
        'tri'          => (int)($row['cnt_tri'] ?? 0),
        'unlinked'     => countUnlinkedPrograms($pdo),
    ];
}

/**
 * Fetch distinct owners for dropdown filters and assign dropdowns
 */
function fetchProgramOwners(PDO $pdo): array
{
    try {
        $st = $pdo->query("
            SELECT DISTINCT name FROM (
                SELECT name FROM aoi_operators WHERE name IS NOT NULL AND name != ''
                UNION
                SELECT owner AS name FROM aoi_programs WHERE owner IS NOT NULL AND owner != ''
            ) AS combined_owners ORDER BY name ASC
        ");
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Throwable $e) {
        $st = $pdo->query("SELECT DISTINCT owner FROM aoi_programs WHERE owner IS NOT NULL AND owner != '' ORDER BY owner ASC");
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
}

/**
 * Operator options for the assign dropdown: [{name, count}] where count = programs currently using that name.
 * Only `aoi_operators` is read, so deleting an option removes it from the dropdown while rows that already
 * carry the name keep it (the filter list above still shows it for as long as a row uses it).
 */
function fetchProgramOperatorOptions(PDO $pdo): array
{
    try {
        $st = $pdo->query("
            SELECT o.name AS name,
                   (SELECT COUNT(*) FROM aoi_programs p WHERE p.owner = o.name) AS cnt
            FROM aoi_operators o
            WHERE o.name IS NOT NULL AND o.name != ''
            ORDER BY o.name ASC
        ");
        return array_map(
            static fn(array $r): array => ['name' => (string)$r['name'], 'count' => (int)$r['cnt']],
            $st->fetchAll(PDO::FETCH_ASSOC)
        );
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Remove an operator from the dropdown options. Existing programs keep their owner text untouched.
 */
function deleteProgramOperator(PDO $pdo, string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    $st = $pdo->prepare('DELETE FROM aoi_operators WHERE name = :name');
    $st->execute([':name' => $name]);
    return $st->rowCount() > 0;
}

/**
 * Save an operator permanently to `aoi_operators` table
 */
function saveProgramOperator(PDO $pdo, string $name): bool
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 50) {
        return false;
    }
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = ($driver === 'sqlite')
            ? 'INSERT OR IGNORE INTO aoi_operators (name, created_at) VALUES (:name, datetime("now"))'
            : 'INSERT IGNORE INTO aoi_operators (name, created_at) VALUES (:name, NOW())';
        $st = $pdo->prepare($sql);
        return $st->execute([':name' => $name]);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Build filtered list query with single-subquery Master Samples join on `boards`
 */
function fetchProgramList(PDO $pdo, array $filters, string $sortCol, string $dir, int $limit, int $offset): array
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $overdueSql = ($driver === 'sqlite')
        ? "p.updated_at < datetime('now', '-14 days')"
        : "p.updated_at < (NOW() - INTERVAL 14 DAY)";

    $sub = boardsMasterSubquerySql($pdo);

    $where = ['1=1'];
    $params = [];

    // Search query (Product / Shelf / Operator / Note)
    $q = trim((string)($filters['search'] ?? ''));
    if ($q !== '') {
        $where[] = "(p.product_model LIKE :q OR p.shelf LIKE :q OR COALESCE(b.board_shelves, '') LIKE :q OR p.owner LIKE :q OR p.note LIKE :q OR p.program_ok LIKE :q)";
        $params[':q'] = "%$q%";
    }

    // Machine filter
    $mach = (string)($filters['machine'] ?? 'ALL');
    if ($mach !== 'ALL') {
        $where[] = 'p.machine_type = :mach';
        $params[':mach'] = $mach;
    }

    // Owner filter
    $owner = (string)($filters['owner'] ?? 'ALL');
    if ($owner === 'unassigned') {
        $where[] = "(p.owner IS NULL OR p.owner = '')";
    } elseif ($owner !== 'ALL') {
        $where[] = 'p.owner = :owner';
        $params[':owner'] = $owner;
    }

    // Status filter
    $status = (string)($filters['status'] ?? 'ALL');
    if ($status === 'complete') {
        $where[] = 'p.stage_1 = 2 AND (p.stage_2 = 2 OR p.stage_2 = 9) AND p.stage_3 = 2 AND p.stage_4 = 2';
    } elseif ($status === 'wait_master') {
        $where[] = 'p.stage_1 = 2 AND (p.stage_2 = 2 OR p.stage_2 = 9) AND p.stage_3 < 2';
    } elseif ($status === 'queued') {
        $where[] = 'p.stage_1 < 2';
    } elseif ($status === 'overdue') {
        $where[] = "NOT (p.stage_1 = 2 AND (p.stage_2 = 2 OR p.stage_2 = 9) AND p.stage_3 = 2 AND p.stage_4 = 2) AND $overdueSql";
    }

    // Stage filter (stuck at stage X)
    $stage = (string)($filters['stage'] ?? 'ALL');
    if (in_array($stage, ['1', '2', '3', '4'], true)) {
        $stgCol = "p.stage_{$stage}";
        $where[] = "$stgCol IN (0, 1)";
    }

    $whereSql = implode(' AND ', $where);

    // Count total filtered
    $countSql = "
        SELECT COUNT(*)
        FROM aoi_programs p
        LEFT JOIN ($sub) b
          ON b.b_product = p.product_model AND b.b_side = p.side
        WHERE $whereSql
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalFiltered = (int)$countStmt->fetchColumn();

    // Whitelisted sort columns (including 'side')
    $allowedSort = [
        'product_model' => 'p.product_model',
        'side'          => 'p.side',
        'machine_type'  => 'p.machine_type',
        'owner'         => 'p.owner',
        'updated_at'    => 'p.updated_at',
        'created_at'    => 'p.created_at',
        'id'            => 'p.id',
    ];
    $sortColSafe = $allowedSort[$sortCol] ?? 'p.updated_at';
    $dirSafe = ($dir === 'ASC') ? 'ASC' : 'DESC';

    $sql = "
        SELECT
            p.*,
            COALESCE(b.master_d, 0) AS master_d,
            COALESCE(b.master_gd, 0) AS master_gd,
            b.master_d_shelves,
            b.master_gd_shelves,
            b.board_shelves
        FROM aoi_programs p
        LEFT JOIN ($sub) b
          ON b.b_product = p.product_model AND b.b_side = p.side
        WHERE $whereSql
        ORDER BY $sortColSafe $dirSafe, p.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'rows'  => $rows,
        'total' => $totalFiltered,
    ];
}
