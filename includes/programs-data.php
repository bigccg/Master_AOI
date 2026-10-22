<?php
/**
 * Data Retrieval & KPI Calculation for AOI Program Tracker
 */

declare(strict_types=1);

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
 * Standardize and enrich program record for API/UI output
 */
function formatProgramRow(array $r): array
{
    $complete = isProgramComplete($r);
    $overdue = overdueDays($r['updated_at'] ?? null, $complete);

    return [
        'id'            => (int)($r['id'] ?? 0),
        'product_model' => (string)($r['product_model'] ?? ''),
        'side'          => (string)($r['side'] ?? 'F-Side'),
        'family_key'    => (string)($r['family_key'] ?? ''),
        'machine_type'  => (string)($r['machine_type'] ?? 'OMRON (2D)'),
        'stage_1'       => (int)($r['stage_1'] ?? 0),
        'stage_1_by'    => (string)($r['stage_1_by'] ?? ''),
        'stage_1_at'    => (string)($r['stage_1_at'] ?? ''),
        'stage_2'       => (int)($r['stage_2'] ?? 0),
        'stage_2_by'    => (string)($r['stage_2_by'] ?? ''),
        'stage_2_at'    => (string)($r['stage_2_at'] ?? ''),
        'stage_3'       => (int)($r['stage_3'] ?? 0),
        'stage_3_by'    => (string)($r['stage_3_by'] ?? ''),
        'stage_3_at'    => (string)($r['stage_3_at'] ?? ''),
        'stage_4'       => (int)($r['stage_4'] ?? 0),
        'stage_4_by'    => (string)($r['stage_4_by'] ?? ''),
        'stage_4_at'    => (string)($r['stage_4_at'] ?? ''),
        'owner'         => (string)($r['owner'] ?? ''),
        'shelf'         => (string)($r['shelf'] ?? ''),
        'program_ok'    => (string)($r['program_ok'] ?? ''),
        'wait_reason'   => (string)($r['wait_reason'] ?? ''),
        'note'          => (string)($r['note'] ?? ''),
        'version'       => (int)($r['version'] ?? 1),
        'created_at'    => (string)($r['created_at'] ?? ''),
        'updated_at'    => (string)($r['updated_at'] ?? ''),
        'updated_rel'   => formatProgramDate($r['updated_at'] ?? null),
        'is_complete'   => $complete,
        'overdue_days'  => $overdue,
    ];
}

/**
 * Fetch single program by ID
 */
function fetchProgramById(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM aoi_programs WHERE id = :id LIMIT 1');
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
        return ['success' => false, 'errors' => ['หมายเลขขั้นไม่ถูกต้อง (ต้องเป็น 1, 2, 3 หรือ 4) / Invalid stage number.']];
    }
    if (!in_array($status, [0, 1, 2, 9], true)) {
        return ['success' => false, 'errors' => ['สถานะขั้นไม่ถูกต้อง / Invalid stage status.']];
    }

    $st = $pdo->prepare('SELECT * FROM aoi_programs WHERE id = :id');
    $st->execute([':id' => $id]);
    $current = $st->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        return ['success' => false, 'status_code' => 404, 'errors' => ['ไม่พบข้อมูลโปรแกรมที่ระบุ / Program record not found.']];
    }

    $currentVer = (int)$current['version'];
    if ($expectedVersion > 0 && $currentVer !== $expectedVersion) {
        return [
            'success'     => false,
            'conflict'    => true,
            'status_code' => 409,
            'current'     => formatProgramRow($current),
            'errors'      => ['ข้อมูลถูกแก้ไขโดยผู้อื่นแล้ว กรุณารีเฟรชข้อมูล / Record was modified by someone else. Please reload.'],
        ];
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');
    $stageCol = "stage_{$stage}";
    $byCol    = "stage_{$stage}_by";
    $atCol    = "stage_{$stage}_at";

    $oldStatus = (int)$current[$stageCol];
    $newBy = ($status === 0) ? null : ($operator !== '' ? $operator : 'System');
    $newAt = ($status === 0) ? null : $now;

    // Simulate new record to compute new program_ok
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
        // Race condition: another query won the lock
        $latest = fetchProgramById($pdo, $id);
        return [
            'success'     => false,
            'conflict'    => true,
            'status_code' => 409,
            'current'     => $latest,
            'errors'      => ['การบันทึกล้มเหลวเนื่องจากมีการแก้ไขชนกัน กรุณาลองใหม่ / Conflict detected. Please retry.'],
        ];
    }

    // Insert audit record
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
 * Revert previous action via audit log
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

    if ($action === 'create') {
        // Undo create = delete the program record
        $del = $pdo->prepare('DELETE FROM aoi_programs WHERE id = :id');
        $del->execute([':id' => $programId]);

        $log = $pdo->prepare('
            INSERT INTO aoi_program_audit (program_id, action, field_name, old_value, new_value, operator, ip_address, created_at)
            VALUES (:pid, :act, NULL, NULL, NULL, :op, :ip, :now)
        ');
        $log->execute([
            ':pid' => $programId,
            ':act' => 'undo_create',
            ':op'  => $operator ?: 'System',
            ':ip'  => $ip,
            ':now' => $now,
        ]);

        return [
            'success'    => true,
            'message'    => 'ยกเลิกการสร้างโปรแกรมเรียบร้อยแล้ว / Program creation undone.',
            'deleted_id' => $programId,
        ];
    }

    if ($action === 'stage_update') {
        $field = (string)$audit['field_name'];
        if (!str_starts_with($field, 'stage_')) {
            return ['success' => false, 'errors' => ['ไม่สามารถเลิกทำฟิลด์นี้ได้ / Cannot undo this field.']];
        }

        $stage = (int)str_replace('stage_', '', $field);
        $oldVal = (int)$audit['old_value'];

        $progSt = $pdo->prepare('SELECT * FROM aoi_programs WHERE id = :id');
        $progSt->execute([':id' => $programId]);
        $current = $progSt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            return ['success' => false, 'status_code' => 404, 'errors' => ['ไม่พบโปรแกรม / Program not found.']];
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

    return ['success' => false, 'errors' => ['ประเภทรายการไม่รองรับการเลิกทำ / Unsupported undo action.']];
}

/**
 * Save brand new program record (e.g. from Quick Add or Full Modal)
 */
function saveNewProgram(PDO $pdo, array $input, string $operator, string $ip): array
{
    $model = trim((string)($input['product_model'] ?? ''));
    if ($model === '') {
        return ['success' => false, 'status_code' => 422, 'errors' => ['กรุณาระบุรหัสโมเดล / Product model is required.']];
    }
    if (mb_strlen($model) > 50) {
        return ['success' => false, 'status_code' => 422, 'errors' => ['รหัสโมเดลยาวเกิน 50 ตัวอักษร / Product model exceeds 50 chars.']];
    }

    $side = trim((string)($input['side'] ?? 'F-Side'));
    if (!in_array($side, ['F-Side', 'B-Side'], true)) {
        $side = 'F-Side';
    }

    $machine = trim((string)($input['machine_type'] ?? 'OMRON (2D)'));
    if (!in_array($machine, PROGRAM_MACHINES, true)) {
        $machine = 'OMRON (2D)';
    }

    // Check duplicate model + side
    $chk = $pdo->prepare('SELECT id FROM aoi_programs WHERE product_model = :model AND side = :side LIMIT 1');
    $chk->execute([':model' => $model, ':side' => $side]);
    if ($chk->fetch()) {
        return [
            'success'     => false,
            'status_code' => 422,
            'errors'      => ["โมเดล '{$model}' ฝั่ง '{$side}' มีอยู่ในระบบแล้ว / Model and side already exist."],
        ];
    }

    $family = trim((string)($input['family_key'] ?? ''));
    if ($family === '') {
        // Auto extract family prefix (e.g. NKSZ from NKSZ-268MW-0C-B)
        if (preg_match('/^([A-Za-z0-9]+)/', $model, $m)) {
            $family = $m[1];
        }
    }

    $owner = trim((string)($input['owner'] ?? ''));
    $shelf = trim((string)($input['shelf'] ?? ''));
    $note  = trim((string)($input['note'] ?? ''));

    $s1 = isset($input['stage_1']) ? (int)$input['stage_1'] : 0;
    $s2 = isset($input['stage_2']) ? (int)$input['stage_2'] : 0;
    $s3 = isset($input['stage_3']) ? (int)$input['stage_3'] : 0;
    $s4 = isset($input['stage_4']) ? (int)$input['stage_4'] : 0;

    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');

    $s1By = ($s1 > 0) ? ($operator ?: 'System') : null;
    $s1At = ($s1 > 0) ? $now : null;
    $s2By = ($s2 > 0) ? ($operator ?: 'System') : null;
    $s2At = ($s2 > 0) ? $now : null;
    $s3By = ($s3 > 0) ? ($operator ?: 'System') : null;
    $s3At = ($s3 > 0) ? $now : null;
    $s4By = ($s4 > 0) ? ($operator ?: 'System') : null;
    $s4At = ($s4 > 0) ? $now : null;

    $progOk = computeProgramStatus([
        'stage_1' => $s1,
        'stage_2' => $s2,
        'stage_3' => $s3,
        'stage_4' => $s4,
    ]);

    $stmt = $pdo->prepare('
        INSERT INTO aoi_programs (
            product_model, side, family_key, machine_type,
            stage_1, stage_1_by, stage_1_at,
            stage_2, stage_2_by, stage_2_at,
            stage_3, stage_3_by, stage_3_at,
            stage_4, stage_4_by, stage_4_at,
            owner, shelf, program_ok, wait_reason, note,
            version, created_at, updated_at
        ) VALUES (
            :model, :side, :family, :machine,
            :s1, :s1_by, :s1_at,
            :s2, :s2_by, :s2_at,
            :s3, :s3_by, :s3_at,
            :s4, :s4_by, :s4_at,
            :owner, :shelf, :prog_ok, NULL, :note,
            1, :now, :now
        )
    ');

    $stmt->execute([
        ':model'   => $model,
        ':side'    => $side,
        ':family'  => $family,
        ':machine' => $machine,
        ':s1'      => $s1,
        ':s1_by'   => $s1By,
        ':s1_at'   => $s1At,
        ':s2'      => $s2,
        ':s2_by'   => $s2By,
        ':s2_at'   => $s2At,
        ':s3'      => $s3,
        ':s3_by'   => $s3By,
        ':s3_at'   => $s3At,
        ':s4'      => $s4,
        ':s4_by'   => $s4By,
        ':s4_at'   => $s4At,
        ':owner'   => $owner ?: null,
        ':shelf'   => $shelf ?: null,
        ':prog_ok' => $progOk,
        ':note'    => $note ?: null,
        ':now'     => $now,
    ]);

    $newId = (int)$pdo->lastInsertId();

    $log = $pdo->prepare('
        INSERT INTO aoi_program_audit (program_id, action, field_name, old_value, new_value, operator, ip_address, created_at)
        VALUES (:pid, :act, NULL, NULL, :model, :op, :ip, :now)
    ');
    $log->execute([
        ':pid'   => $newId,
        ':act'   => 'create',
        ':model' => "{$model} ({$side})",
        ':op'    => $operator ?: 'System',
        ':ip'    => $ip,
        ':now'   => $now,
    ]);
    $auditId = (int)$pdo->lastInsertId();

    $created = fetchProgramById($pdo, $newId);
    return [
        'success'  => true,
        'message'  => "เพิ่มโปรแกรม {$model} ({$side}) สำเร็จ / Program added successfully.",
        'program'  => $created,
        'audit_id' => $auditId,
    ];
}

/**
 * Execute batch operations on multiple program IDs
 */
function bulkUpdatePrograms(PDO $pdo, string $action, array $ids, array $payload, string $operator, string $ip): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));
    if (empty($ids)) {
        return ['success' => false, 'status_code' => 422, 'errors' => ['ไม่ได้เลือกรายการใดๆ / No items selected.']];
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');
    $pdo->beginTransaction();

    try {
        $count = 0;

        if ($action === 'advance_stage') {
            // Advance the earliest incomplete stage
            foreach ($ids as $id) {
                $st = $pdo->prepare('SELECT * FROM aoi_programs WHERE id = :id FOR UPDATE');
                $st->execute([':id' => $id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if (!$r) continue;

                $targetStage = 0;
                for ($s = 1; $s <= 4; $s++) {
                    $stVal = (int)$r["stage_{$s}"];
                    if ($stVal < 2) {
                        $targetStage = $s;
                        break;
                    }
                }

                if ($targetStage > 0) {
                    $currVal = (int)$r["stage_{$targetStage}"];
                    $nextVal = ($currVal === 0) ? 1 : 2;

                    $r["stage_{$targetStage}"] = $nextVal;
                    $newOk = computeProgramStatus($r);

                    $upd = $pdo->prepare("
                        UPDATE aoi_programs
                        SET stage_{$targetStage} = :val,
                            stage_{$targetStage}_by = :by,
                            stage_{$targetStage}_at = :at,
                            program_ok = :prog_ok,
                            version = version + 1,
                            updated_at = :now
                        WHERE id = :id
                    ");
                    $upd->execute([
                        ':val'     => $nextVal,
                        ':by'      => $operator ?: 'System',
                        ':at'      => $now,
                        ':prog_ok' => $newOk,
                        ':now'     => $now,
                        ':id'      => $id,
                    ]);

                    $audit = $pdo->prepare('
                        INSERT INTO aoi_program_audit (program_id, action, field_name, old_value, new_value, operator, ip_address, created_at)
                        VALUES (:pid, :act, :field, :old, :new, :op, :ip, :now)
                    ');
                    $audit->execute([
                        ':pid'   => $id,
                        ':act'   => 'bulk_advance',
                        ':field' => "stage_{$targetStage}",
                        ':old'   => (string)$currVal,
                        ':new'   => (string)$nextVal,
                        ':op'    => $operator ?: 'System',
                        ':ip'    => $ip,
                        ':now'   => $now,
                    ]);
                    $count++;
                }
            }
        } elseif ($action === 'set_stage') {
            $stage = max(1, min(4, (int)($payload['stage'] ?? 1)));
            $status = in_array((int)($payload['status'] ?? 2), [0, 1, 2, 9], true) ? (int)$payload['status'] : 2;
            $by = ($status === 0) ? null : ($operator ?: 'System');
            $at = ($status === 0) ? null : $now;

            foreach ($ids as $id) {
                $st = $pdo->prepare('SELECT * FROM aoi_programs WHERE id = :id FOR UPDATE');
                $st->execute([':id' => $id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if (!$r) continue;

                $r["stage_{$stage}"] = $status;
                $newOk = computeProgramStatus($r);

                $upd = $pdo->prepare("
                    UPDATE aoi_programs
                    SET stage_{$stage} = :val,
                        stage_{$stage}_by = :by,
                        stage_{$stage}_at = :at,
                        program_ok = :prog_ok,
                        version = version + 1,
                        updated_at = :now
                    WHERE id = :id
                ");
                $upd->execute([
                    ':val'     => $status,
                    ':by'      => $by,
                    ':at'      => $at,
                    ':prog_ok' => $newOk,
                    ':now'     => $now,
                    ':id'      => $id,
                ]);

                $audit = $pdo->prepare('
                    INSERT INTO aoi_program_audit (program_id, action, field_name, old_value, new_value, operator, ip_address, created_at)
                    VALUES (:pid, :act, :field, :old, :new, :op, :ip, :now)
                ');
                $audit->execute([
                    ':pid'   => $id,
                    ':act'   => 'bulk_set_stage',
                    ':field' => "stage_{$stage}",
                    ':old'   => (string)$r["stage_{$stage}"],
                    ':new'   => (string)$status,
                    ':op'    => $operator ?: 'System',
                    ':ip'    => $ip,
                    ':now'   => $now,
                ]);
                $count++;
            }
        } elseif ($action === 'assign_owner') {
            $owner = trim((string)($payload['owner'] ?? ''));
            foreach ($ids as $id) {
                $upd = $pdo->prepare('
                    UPDATE aoi_programs
                    SET owner = :owner, version = version + 1, updated_at = :now
                    WHERE id = :id
                ');
                $upd->execute([
                    ':owner' => $owner !== '' ? $owner : null,
                    ':now'   => $now,
                    ':id'    => $id,
                ]);

                $audit = $pdo->prepare('
                    INSERT INTO aoi_program_audit (program_id, action, field_name, old_value, new_value, operator, ip_address, created_at)
                    VALUES (:pid, :act, :field, NULL, :new, :op, :ip, :now)
                ');
                $audit->execute([
                    ':pid'   => $id,
                    ':act'   => 'bulk_assign_owner',
                    ':field' => 'owner',
                    ':new'   => $owner,
                    ':op'    => $operator ?: 'System',
                    ':ip'    => $ip,
                    ':now'   => $now,
                ]);
                $count++;
            }
        } elseif ($action === 'delete') {
            foreach ($ids as $id) {
                $del = $pdo->prepare('DELETE FROM aoi_programs WHERE id = :id');
                $del->execute([':id' => $id]);

                $audit = $pdo->prepare('
                    INSERT INTO aoi_program_audit (program_id, action, field_name, old_value, new_value, operator, ip_address, created_at)
                    VALUES (:pid, :act, NULL, NULL, NULL, :op, :ip, :now)
                ');
                $audit->execute([
                    ':pid' => $id,
                    ':act' => 'bulk_delete',
                    ':op'  => $operator ?: 'System',
                    ':ip'  => $ip,
                    ':now' => $now,
                ]);
                $count++;
            }
        } else {
            $pdo->rollBack();
            return ['success' => false, 'status_code' => 400, 'errors' => ['การดำเนินการไม่ถูกต้อง / Invalid action.']];
        }

        $pdo->commit();
        return [
            'success'       => true,
            'message'       => "อัปเดตข้อมูล {$count} รายการเรียบร้อยแล้ว / Updated {$count} records successfully.",
            'updated_count' => $count,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [
            'success'     => false,
            'status_code' => 500,
            'errors'      => ['เกิดข้อผิดพลาดในการบันทึกข้อมูลแบบกลุ่ม: ' . $e->getMessage()],
        ];
    }
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
    ];
}

/**
 * Fetch distinct owners for dropdown filters
 */
function fetchProgramOwners(PDO $pdo): array
{
    $st = $pdo->query("SELECT DISTINCT owner FROM aoi_programs WHERE owner IS NOT NULL AND owner != '' ORDER BY owner ASC");
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Build filtered list query
 */
function fetchProgramList(PDO $pdo, array $filters, string $sortCol, string $dir, int $limit, int $offset): array
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $overdueSql = ($driver === 'sqlite')
        ? "updated_at < datetime('now', '-14 days')"
        : "updated_at < (NOW() - INTERVAL 14 DAY)";

    $where = ['1=1'];
    $params = [];

    // Search query
    $q = trim((string)($filters['search'] ?? ''));
    if ($q !== '') {
        $where[] = '(product_model LIKE :q OR family_key LIKE :q OR shelf LIKE :q OR owner LIKE :q OR note LIKE :q OR program_ok LIKE :q)';
        $params[':q'] = "%$q%";
    }

    // Machine filter
    $mach = (string)($filters['machine'] ?? 'ALL');
    if ($mach !== 'ALL') {
        $where[] = 'machine_type = :mach';
        $params[':mach'] = $mach;
    }

    // Owner filter
    $owner = (string)($filters['owner'] ?? 'ALL');
    if ($owner === 'unassigned') {
        $where[] = "(owner IS NULL OR owner = '')";
    } elseif ($owner !== 'ALL') {
        $where[] = 'owner = :owner';
        $params[':owner'] = $owner;
    }

    // Status filter
    $status = (string)($filters['status'] ?? 'ALL');
    if ($status === 'complete') {
        $where[] = 'stage_1 = 2 AND (stage_2 = 2 OR stage_2 = 9) AND stage_3 = 2 AND stage_4 = 2';
    } elseif ($status === 'wait_master') {
        $where[] = 'stage_1 = 2 AND (stage_2 = 2 OR stage_2 = 9) AND stage_3 < 2';
    } elseif ($status === 'queued') {
        $where[] = 'stage_1 < 2';
    } elseif ($status === 'overdue') {
        $where[] = "NOT (stage_1 = 2 AND (stage_2 = 2 OR stage_2 = 9) AND stage_3 = 2 AND stage_4 = 2) AND $overdueSql";
    }

    // Stage filter (stuck at stage X)
    $stage = (string)($filters['stage'] ?? 'ALL');
    if (in_array($stage, ['1', '2', '3', '4'], true)) {
        $stgCol = "stage_{$stage}";
        $where[] = "$stgCol IN (0, 1)";
    }

    $whereSql = implode(' AND ', $where);

    // Count total filtered
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM aoi_programs WHERE $whereSql");
    $countStmt->execute($params);
    $totalFiltered = (int)$countStmt->fetchColumn();

    // Fetch page rows
    $sortColSafe = in_array($sortCol, ['product_model', 'machine_type', 'owner', 'updated_at', 'created_at', 'id'], true) ? $sortCol : 'updated_at';
    $dirSafe = ($dir === 'ASC') ? 'ASC' : 'DESC';

    $sql = "
        SELECT * FROM aoi_programs
        WHERE $whereSql
        ORDER BY $sortColSafe $dirSafe, id DESC
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
