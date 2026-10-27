<?php
declare(strict_types=1);

/**
 * AOI Data Backup & Restore Manager ("Data Time-Machine")
 *
 * Provides Git-like snapshot creation (commit), comparison (diff), and atomic rollback (restore)
 * for application data tables (`zones`, `sheets`, `sheet_magazines`, `boards`, `aoi_programs`, `aoi_operators`),
 * plus configurable monthly auto-backup retention (default 12 months) and signed JSON export/import.
 *
 * All snapshots are stored in database tables (`aoi_data_snapshots`, `aoi_backup_settings`)
 * so no backup files ever pollute the project directory.
 */

require_once __DIR__ . '/../config/database.php';

defined('BACKUP_SCHEMA_VERSION')           || define('BACKUP_SCHEMA_VERSION', '1');
defined('BACKUP_DEFAULT_RETENTION_MONTHS') || define('BACKUP_DEFAULT_RETENTION_MONTHS', 12);
defined('BACKUP_MIN_RETENTION_MONTHS')     || define('BACKUP_MIN_RETENTION_MONTHS', 1);
defined('BACKUP_MAX_RETENTION_MONTHS')     || define('BACKUP_MAX_RETENTION_MONTHS', 60);
defined('BACKUP_RETENTION_OPTIONS')        || define('BACKUP_RETENTION_OPTIONS', [1, 3, 6, 12, 18, 24, 36, 60]);
defined('BACKUP_MAX_PRE_RESTORE_KEEP')     || define('BACKUP_MAX_PRE_RESTORE_KEEP', 10);
defined('BACKUP_MAX_UPLOAD_BYTES')         || define('BACKUP_MAX_UPLOAD_BYTES', 10485760);

defined('BACKUP_CSRF_ERROR') || define('BACKUP_CSRF_ERROR', 'ดำเนินการไม่สำเร็จ เนื่องจากเซสชันหมดอายุหรือโทเค็นความปลอดภัย (CSRF) ไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บแล้วลองใหม่อีกครั้ง / Action refused because the security token (CSRF) is missing or invalid. Please refresh the page and try again.');
defined('BACKUP_METHOD_ERROR') || define('BACKUP_METHOD_ERROR', 'ไม่อนุญาตให้เรียกด้วยเมธอดนี้ เนื่องจากคำสั่งแก้ไขต้องส่งแบบ POST เท่านั้น กรุณาดำเนินการผ่านหน้าต่างระบบสำรองข้อมูล / Method not allowed (POST required). Please submit from the Data Backup Manager.');
defined('BACKUP_NOT_FOUND_ERROR') || define('BACKUP_NOT_FOUND_ERROR', 'ไม่พบจุดสำรองข้อมูล (Snapshot) ที่ระบุ อาจถูกลบไปแล้วหรือรหัสไม่ถูกต้อง กรุณารีเฟรชรายการแล้วเลือกใหม่ / Snapshot not found. It may have been deleted; please refresh the list and try again.');
defined('BACKUP_INVALID_RETENTION_ERROR') || define('BACKUP_INVALID_RETENTION_ERROR', 'ตั้งค่าจำนวนเดือนที่เก็บ Auto Backup ไม่สำเร็จ เนื่องจากค่าต้องอยู่ระหว่าง 1 ถึง 60 เดือน กรุณาเลือกจำนวนเดือนที่ถูกต้อง / Invalid retention period. Please choose between 1 and 60 months.');
defined('BACKUP_UPLOAD_EMPTY_ERROR') || define('BACKUP_UPLOAD_EMPTY_ERROR', 'ไม่สามารถนำเข้าไฟล์สำรองข้อมูลได้ เนื่องจากยังไม่ได้เลือกไฟล์ (.json) หรือไฟล์ว่างเปล่า กรุณาเลือกไฟล์สำรองข้อมูลที่ดาวน์โหลดจากระบบ / No backup file uploaded or the file is empty. Please select a valid AOI backup (.json) file.');
defined('BACKUP_UPLOAD_TOO_LARGE_ERROR') || define('BACKUP_UPLOAD_TOO_LARGE_ERROR', 'ไม่สามารถนำเข้าไฟล์สำรองข้อมูลได้ เนื่องจากไฟล์มีขนาดใหญ่เกินกำหนด (สูงสุดไม่เกิน 10 MB) กรุณาใช้ไฟล์สำรองข้อมูลมาตรฐานจากระบบ / Backup file exceeds the 10 MB size limit.');
defined('BACKUP_UPLOAD_INVALID_JSON_ERROR') || define('BACKUP_UPLOAD_INVALID_JSON_ERROR', 'ไม่สามารถนำเข้าไฟล์สำรองข้อมูลได้ เนื่องจากโครงสร้างไฟล์ JSON ไม่ถูกต้องหรือไม่ใช่ไฟล์สำรองข้อมูลของระบบ AOI กรุณาใช้ไฟล์ .json ที่ดาวน์โหลดจากระบบนี้เท่านั้น / Invalid backup file format. Only genuine AOI Data Snapshot (.json) files exported from this system are accepted.');
defined('BACKUP_CHECKSUM_MISMATCH_ERROR') || define('BACKUP_CHECKSUM_MISMATCH_ERROR', 'ไม่สามารถนำเข้าหรือกู้คืนข้อมูลได้ เนื่องจากลายเซ็นดิจิทัล (Checksum / HMAC) ของไฟล์ไม่ตรงกับข้อมูลภายใน ไฟล์อาจถูกแก้ไขหรือเสียหาย กรุณาใช้ไฟล์สำรองข้อมูลต้นฉบับที่ไม่ถูกดัดแปลง / Snapshot integrity check failed (checksum/signature mismatch). The backup data may have been modified or corrupted.');
defined('BACKUP_LOCK_BUSY_ERROR') || define('BACKUP_LOCK_BUSY_ERROR', 'ไม่สามารถกู้คืนข้อมูลได้ในขณะนี้ เนื่องจากระบบกำลังบันทึกหรือนำเข้าข้อมูลจากผู้ใช้อื่นอยู่ กรุณารอสักครู่แล้วกดกู้คืนใหม่อีกครั้ง / System is currently busy with another write or import operation. Please wait a moment and try restoring again.');

/**
 * Ensure `aoi_backup_settings` and `aoi_data_snapshots` tables exist on the given PDO connection.
 * Cached per PDO instance so repeated calls within a request incur zero extra queries.
 */
function ensureBackupTables(PDO $pdo, bool $force = false): void
{
    static $initialized = [];
    $oid = spl_object_id($pdo);
    if (!$force && isset($initialized[$oid])) {
        return;
    }

    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $now    = (new DateTimeImmutable('now', new DateTimeZone(CODE_TZ)))->format('Y-m-d H:i:s');

    if ($driver === 'sqlite') {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS aoi_backup_settings (
                id                   INTEGER PRIMARY KEY,
                retention_months     INTEGER NOT NULL DEFAULT 12,
                auto_monthly_enabled INTEGER NOT NULL DEFAULT 1,
                last_auto_check_ym    VARCHAR(7) NOT NULL DEFAULT "",
                updated_at           DATETIME NOT NULL
            )
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS aoi_data_snapshots (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                snapshot_uid     VARCHAR(64)  NOT NULL UNIQUE,
                snapshot_type    VARCHAR(20)  NOT NULL,
                period_ym        VARCHAR(7)   NOT NULL,
                title            VARCHAR(120) NOT NULL,
                board_count      INTEGER      NOT NULL DEFAULT 0,
                program_count    INTEGER      NOT NULL DEFAULT 0,
                zone_count       INTEGER      NOT NULL DEFAULT 0,
                sheet_count      INTEGER      NOT NULL DEFAULT 0,
                last_number_code VARCHAR(30)  NOT NULL DEFAULT "",
                checksum         VARCHAR(64)  NOT NULL,
                payload_json     TEXT         NOT NULL,
                created_by       VARCHAR(60)  NOT NULL DEFAULT "system",
                created_at       DATETIME     NOT NULL
            )
        ');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_snap_type_ym ON aoi_data_snapshots (snapshot_type, period_ym)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_snap_created ON aoi_data_snapshots (created_at DESC, id DESC)');

        $st = $pdo->prepare('
            INSERT OR IGNORE INTO aoi_backup_settings (id, retention_months, auto_monthly_enabled, last_auto_check_ym, updated_at)
            VALUES (1, :ret, 1, "", :now)
        ');
        $st->execute([':ret' => BACKUP_DEFAULT_RETENTION_MONTHS, ':now' => $now]);
    } else {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS aoi_backup_settings (
                id                   INT NOT NULL PRIMARY KEY,
                retention_months     INT NOT NULL DEFAULT 12,
                auto_monthly_enabled TINYINT(1) NOT NULL DEFAULT 1,
                last_auto_check_ym   VARCHAR(7) NOT NULL DEFAULT "",
                updated_at           DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS aoi_data_snapshots (
                id               INT AUTO_INCREMENT PRIMARY KEY,
                snapshot_uid     VARCHAR(64)  NOT NULL,
                snapshot_type    VARCHAR(20)  NOT NULL,
                period_ym        VARCHAR(7)   NOT NULL,
                title            VARCHAR(120) NOT NULL,
                board_count      INT          NOT NULL DEFAULT 0,
                program_count    INT          NOT NULL DEFAULT 0,
                zone_count       INT          NOT NULL DEFAULT 0,
                sheet_count      INT          NOT NULL DEFAULT 0,
                last_number_code VARCHAR(30)  NOT NULL DEFAULT "",
                checksum         VARCHAR(64)  NOT NULL,
                payload_json     LONGTEXT     NOT NULL,
                created_by       VARCHAR(60)  NOT NULL DEFAULT "system",
                created_at       DATETIME     NOT NULL,
                UNIQUE KEY uq_snapshot_uid (snapshot_uid),
                KEY idx_snap_type_ym (snapshot_type, period_ym),
                KEY idx_snap_created (created_at, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $st = $pdo->prepare('
            INSERT IGNORE INTO aoi_backup_settings (id, retention_months, auto_monthly_enabled, last_auto_check_ym, updated_at)
            VALUES (1, :ret, 1, "", :now)
        ');
        $st->execute([':ret' => BACKUP_DEFAULT_RETENTION_MONTHS, ':now' => $now]);
    }

    $initialized[$oid] = true;
}

/**
 * Secret key for signing snapshot payloads (HMAC-SHA256).
 */
function snapshotSigningSecret(): string
{
    $envSecret = getenv('AOI_BACKUP_SECRET');
    if (is_string($envSecret) && $envSecret !== '') {
        return $envSecret;
    }
    return 'aoi-data-time-machine-v1-' . CODE_PREFIX;
}

/**
 * Compute deterministic HMAC-SHA256 checksum for a snapshot tables array.
 */
function computeSnapshotChecksum(array $tables): string
{
    $normalized = normalizeSnapshotTablesForHash($tables);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return hash_hmac('sha256', (string)$json, snapshotSigningSecret());
}

/**
 * Verify that a snapshot tables payload matches its stored HMAC-SHA256 checksum.
 */
function verifySnapshotChecksum(array $tables, string $expectedChecksum): bool
{
    if ($expectedChecksum === '' || strlen($expectedChecksum) !== 64) {
        return false;
    }
    $actual = computeSnapshotChecksum($tables);
    return hash_equals($actual, strtolower(trim($expectedChecksum)));
}

/**
 * Normalize table rows into a deterministic structure before hashing or encoding.
 */
function normalizeSnapshotTablesForHash(array $tables): array
{
    $zones = [];
    foreach ($tables['zones'] ?? [] as $r) {
        $zones[] = [
            'zone_key'    => (string)($r['zone_key'] ?? ''),
            'label'       => (string)($r['label'] ?? ''),
            'short_label' => (string)($r['short_label'] ?? ''),
            'sort_order'  => (int)($r['sort_order'] ?? 0),
            'is_active'   => (int)($r['is_active'] ?? 1),
            'created_at'  => (string)($r['created_at'] ?? ''),
        ];
    }

    $sheets = [];
    foreach ($tables['sheets'] ?? [] as $r) {
        $sheets[] = [
            'sheet_key'    => (string)($r['sheet_key'] ?? ''),
            'zone_key'     => (string)($r['zone_key'] ?? ''),
            'label'        => (string)($r['label'] ?? ''),
            'shelf_prefix' => (string)($r['shelf_prefix'] ?? ''),
            'dot_color'    => (string)($r['dot_color'] ?? ''),
            'sort_order'   => (int)($r['sort_order'] ?? 0),
            'is_active'    => (int)($r['is_active'] ?? 1),
            'created_at'   => (string)($r['created_at'] ?? ''),
        ];
    }

    $magazines = [];
    foreach ($tables['sheet_magazines'] ?? [] as $r) {
        $magazines[] = [
            'sheet'     => (string)($r['sheet'] ?? ''),
            'magazines' => max(1, (int)($r['magazines'] ?? 1)),
        ];
    }

    $boards = [];
    foreach ($tables['boards'] ?? [] as $r) {
        $boards[] = [
            'id'         => (int)($r['id'] ?? 0),
            'zone'       => (string)($r['zone'] ?? ''),
            'sheet'      => (string)($r['sheet'] ?? ''),
            'code'       => (string)($r['code'] ?? ''),
            'shelf'      => (string)($r['shelf'] ?? ''),
            'shelf_no'   => (int)($r['shelf_no'] ?? 0),
            'product'    => (string)($r['product'] ?? ''),
            'board_type' => (string)($r['board_type'] ?? ''),
            'status'     => (string)($r['status'] ?? ''),
            'pcs_status' => (string)($r['pcs_status'] ?? ''),
            'side'       => (string)($r['side'] ?? 'F-Side'),
            'defects'    => $r['defects'] !== null ? (string)$r['defects'] : null,
            'mag_slot'   => $r['mag_slot'] !== null ? (string)$r['mag_slot'] : null,
            'created_at' => (string)($r['created_at'] ?? ''),
        ];
    }

    $programs = [];
    foreach ($tables['aoi_programs'] ?? [] as $r) {
        $programs[] = [
            'id'            => (int)($r['id'] ?? 0),
            'product_model' => (string)($r['product_model'] ?? ''),
            'side'          => (string)($r['side'] ?? 'F-Side'),
            'family_key'    => (string)($r['family_key'] ?? ''),
            'machine_type'  => (string)($r['machine_type'] ?? 'OMRON (2D)'),
            'stage_1'       => (int)($r['stage_1'] ?? 0),
            'stage_1_by'    => isset($r['stage_1_by']) && $r['stage_1_by'] !== null ? (string)$r['stage_1_by'] : null,
            'stage_1_at'    => isset($r['stage_1_at']) && $r['stage_1_at'] !== null ? (string)$r['stage_1_at'] : null,
            'stage_2'       => (int)($r['stage_2'] ?? 0),
            'stage_2_by'    => isset($r['stage_2_by']) && $r['stage_2_by'] !== null ? (string)$r['stage_2_by'] : null,
            'stage_2_at'    => isset($r['stage_2_at']) && $r['stage_2_at'] !== null ? (string)$r['stage_2_at'] : null,
            'stage_3'       => (int)($r['stage_3'] ?? 0),
            'stage_3_by'    => isset($r['stage_3_by']) && $r['stage_3_by'] !== null ? (string)$r['stage_3_by'] : null,
            'stage_3_at'    => isset($r['stage_3_at']) && $r['stage_3_at'] !== null ? (string)$r['stage_3_at'] : null,
            'stage_4'       => (int)($r['stage_4'] ?? 0),
            'stage_4_by'    => isset($r['stage_4_by']) && $r['stage_4_by'] !== null ? (string)$r['stage_4_by'] : null,
            'stage_4_at'    => isset($r['stage_4_at']) && $r['stage_4_at'] !== null ? (string)$r['stage_4_at'] : null,
            'owner'         => isset($r['owner']) && $r['owner'] !== null ? (string)$r['owner'] : null,
            'shelf'         => isset($r['shelf']) && $r['shelf'] !== null ? (string)$r['shelf'] : null,
            'program_ok'    => isset($r['program_ok']) && $r['program_ok'] !== null ? (string)$r['program_ok'] : null,
            'wait_reason'   => isset($r['wait_reason']) && $r['wait_reason'] !== null ? (string)$r['wait_reason'] : null,
            'note'          => isset($r['note']) && $r['note'] !== null ? (string)$r['note'] : null,
            'version'       => (int)($r['version'] ?? 1),
            'created_at'    => (string)($r['created_at'] ?? ''),
            'updated_at'    => (string)($r['updated_at'] ?? ''),
        ];
    }

    $operators = [];
    foreach ($tables['aoi_operators'] ?? [] as $r) {
        $operators[] = [
            'name'       => (string)($r['name'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
        ];
    }

    return [
        'zones'           => $zones,
        'sheets'          => $sheets,
        'sheet_magazines' => $magazines,
        'boards'          => $boards,
        'aoi_programs'    => $programs,
        'aoi_operators'   => $operators,
    ];
}

/**
 * Determine the latest Number Code from a list of board rows (sorted by YY-MM then NNN).
 */
function findLatestNumberCodeFromBoards(array $boards): string
{
    $bestCode = '';
    $bestKey  = '';
    foreach ($boards as $b) {
        $code = trim((string)($b['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        if (preg_match('/-(\d{2})-(\d{2})-(\d{3})$/', $code, $m)) {
            $sortKey = '1-' . $m[1] . '-' . $m[2] . '-' . $m[3];
        } else {
            $sortKey = '0-' . $code;
        }
        if ($bestKey === '' || strcmp($sortKey, $bestKey) > 0) {
            $bestKey  = $sortKey;
            $bestCode = $code;
        }
    }
    return $bestCode;
}

/**
 * Format a YYYY-MM period into a readable Thai + Year label (e.g. "ต.ค. 2026").
 */
function formatBackupPeriodThai(string $periodYm): string
{
    static $thaiMonths = [
        1  => 'ม.ค.', 2  => 'ก.พ.', 3  => 'มี.ค.', 4  => 'เม.ย.',
        5  => 'พ.ค.', 6  => 'มิ.ย.', 7  => 'ก.ค.',  8  => 'ส.ค.',
        9  => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.',  12 => 'ธ.ค.',
    ];
    if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $periodYm, $m)) {
        $mo = (int)$m[2];
        return ($thaiMonths[$mo] ?? $m[2]) . ' ' . $m[1];
    }
    return $periodYm;
}

/**
 * Read current Backup & Retention settings.
 */
function getBackupSettings(PDO $pdo): array
{
    ensureBackupTables($pdo);
    $row = $pdo->query('SELECT retention_months, auto_monthly_enabled, last_auto_check_ym, updated_at FROM aoi_backup_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    $ret = $row ? (int)$row['retention_months'] : BACKUP_DEFAULT_RETENTION_MONTHS;
    $ret = max(BACKUP_MIN_RETENTION_MONTHS, min(BACKUP_MAX_RETENTION_MONTHS, $ret));

    return [
        'retention_months'     => $ret,
        'auto_monthly_enabled' => $row ? ((int)$row['auto_monthly_enabled'] === 1) : true,
        'last_auto_check_ym'   => $row ? (string)$row['last_auto_check_ym'] : '',
        'updated_at'           => $row ? (string)$row['updated_at'] : '',
        'options'              => BACKUP_RETENTION_OPTIONS,
        'min_months'           => BACKUP_MIN_RETENTION_MONTHS,
        'max_months'           => BACKUP_MAX_RETENTION_MONTHS,
    ];
}

/**
 * Update Auto-Backup retention settings (1..60 months) and immediately prune expired `monthly_auto` snapshots.
 */
function updateBackupSettings(PDO $pdo, int $retentionMonths, bool $autoMonthlyEnabled = true, ?DateTimeInterface $now = null): array
{
    ensureBackupTables($pdo);
    if ($retentionMonths < BACKUP_MIN_RETENTION_MONTHS || $retentionMonths > BACKUP_MAX_RETENTION_MONTHS) {
        throw new InvalidArgumentException(BACKUP_INVALID_RETENTION_ERROR);
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone(CODE_TZ));
    $nowStr = $now->format('Y-m-d H:i:s');

    $st = $pdo->prepare('
        UPDATE aoi_backup_settings
        SET retention_months = :ret,
            auto_monthly_enabled = :en,
            updated_at = :now
        WHERE id = 1
    ');
    $st->execute([
        ':ret' => $retentionMonths,
        ':en'  => $autoMonthlyEnabled ? 1 : 0,
        ':now' => $nowStr,
    ]);

    $pruned = pruneExpiredAutoSnapshots($pdo, $retentionMonths, $now);

    return [
        'settings'     => getBackupSettings($pdo),
        'pruned_count' => $pruned,
    ];
}

/**
 * Capture the entire current state of application data tables.
 */
function captureCurrentDatabasePayload(PDO $pdo): array
{
    $zones = $pdo->query('SELECT zone_key, label, short_label, sort_order, is_active, created_at FROM zones ORDER BY sort_order, zone_key')->fetchAll(PDO::FETCH_ASSOC);
    $sheets = $pdo->query('SELECT sheet_key, zone_key, label, shelf_prefix, dot_color, sort_order, is_active, created_at FROM sheets ORDER BY sort_order, sheet_key')->fetchAll(PDO::FETCH_ASSOC);
    $magazines = $pdo->query('SELECT sheet, magazines FROM sheet_magazines ORDER BY sheet')->fetchAll(PDO::FETCH_ASSOC);
    $boards = $pdo->query('SELECT id, zone, sheet, code, shelf, shelf_no, product, board_type, status, pcs_status, side, defects, mag_slot, created_at FROM boards ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $programs = $pdo->query('
        SELECT id, product_model, side, family_key, machine_type,
               stage_1, stage_1_by, stage_1_at,
               stage_2, stage_2_by, stage_2_at,
               stage_3, stage_3_by, stage_3_at,
               stage_4, stage_4_by, stage_4_at,
               owner, shelf, program_ok, wait_reason, note,
               version, created_at, updated_at
        FROM aoi_programs
        ORDER BY id ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
    $operators = $pdo->query('SELECT name, created_at FROM aoi_operators ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

    $tables = normalizeSnapshotTablesForHash([
        'zones'           => $zones,
        'sheets'          => $sheets,
        'sheet_magazines' => $magazines,
        'boards'          => $boards,
        'aoi_programs'    => $programs,
        'aoi_operators'   => $operators,
    ]);

    $statusCounts = ['G' => 0, 'D' => 0, 'GD' => 0];
    foreach ($tables['boards'] as $b) {
        $st = (string)$b['status'];
        if (isset($statusCounts[$st])) {
            $statusCounts[$st]++;
        }
    }

    $magTotal = 0;
    foreach ($tables['sheet_magazines'] as $m) {
        $magTotal += (int)$m['magazines'];
    }

    return [
        'tables'  => $tables,
        'summary' => [
            'board_count'      => count($tables['boards']),
            'program_count'    => count($tables['aoi_programs']),
            'operator_count'   => count($tables['aoi_operators']),
            'zone_count'       => count($tables['zones']),
            'sheet_count'      => count($tables['sheets']),
            'magazine_total'   => $magTotal,
            'last_number_code' => findLatestNumberCodeFromBoards($tables['boards']),
            'status_counts'    => $statusCounts,
        ],
    ];
}

/**
 * Format a database snapshot metadata row for API/UI consumption.
 */
function formatSnapshotRow(array $row): array
{
    $type = (string)($row['snapshot_type'] ?? 'manual');
    $typeLabels = [
        'monthly_auto' => 'Auto รายเดือน (Monthly)',
        'manual'       => 'สร้างเอง (Manual Checkpoint)',
        'pre_restore'  => 'สำรองก่อนกู้คืน (Pre-Restore Safety)',
        'uploaded'     => 'นำเข้าจากไฟล์ (Uploaded Backup)',
    ];
    $periodYm = (string)($row['period_ym'] ?? '');

    return [
        'id'               => (int)($row['id'] ?? 0),
        'snapshot_uid'     => (string)($row['snapshot_uid'] ?? ''),
        'snapshot_type'    => $type,
        'type_label'       => $typeLabels[$type] ?? $type,
        'period_ym'        => $periodYm,
        'period_label'     => formatBackupPeriodThai($periodYm),
        'title'            => (string)($row['title'] ?? ''),
        'board_count'      => (int)($row['board_count'] ?? 0),
        'program_count'    => (int)($row['program_count'] ?? 0),
        'zone_count'       => (int)($row['zone_count'] ?? 0),
        'sheet_count'      => (int)($row['sheet_count'] ?? 0),
        'last_number_code' => (string)($row['last_number_code'] ?? ''),
        'checksum'         => (string)($row['checksum'] ?? ''),
        'short_hash'       => substr((string)($row['checksum'] ?? ''), 0, 8),
        'created_by'       => (string)($row['created_by'] ?? 'system'),
        'created_at'       => (string)($row['created_at'] ?? ''),
    ];
}

/**
 * Create a new database snapshot of the current system state.
 */
function createDataSnapshot(
    PDO $pdo,
    string $type = 'manual',
    string $title = '',
    string $createdBy = 'admin',
    ?string $periodYm = null,
    ?DateTimeInterface $now = null
): array {
    ensureBackupTables($pdo);

    $allowedTypes = ['monthly_auto', 'manual', 'pre_restore', 'uploaded'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'manual';
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone(CODE_TZ));
    $nowStr   = $now->format('Y-m-d H:i:s');
    $periodYm = ($periodYm !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodYm))
        ? $periodYm
        : $now->format('Y-m');

    $captured = captureCurrentDatabasePayload($pdo);
    $tables   = $captured['tables'];
    $summary  = $captured['summary'];
    $checksum = computeSnapshotChecksum($tables);

    $title = trim($title);
    if ($title === '') {
        $periodLabel = formatBackupPeriodThai($periodYm);
        if ($type === 'monthly_auto') {
            $title = "สำรองข้อมูลอัตโนมัติประจำเดือน {$periodLabel} ({$periodYm})";
        } elseif ($type === 'pre_restore') {
            $title = "สำรองอัตโนมัติก่อนย้อนข้อมูล ({$now->format('d/m/Y H:i')})";
        } else {
            $title = "จุดสำรองข้อมูล (Manual Checkpoint {$now->format('d/m/Y H:i')})";
        }
    }
    if (function_exists('mb_substr')) {
        $title = mb_substr($title, 0, 120, 'UTF-8');
    } else {
        $title = substr($title, 0, 120);
    }

    $uid = sprintf(
        'snap_%s_%s_%s',
        str_replace('-', '', $periodYm),
        $now->format('YmdHis'),
        substr(bin2hex(random_bytes(4)), 0, 8)
    );

    $payloadDoc = [
        'schema_version' => BACKUP_SCHEMA_VERSION,
        'snapshot_uid'   => $uid,
        'snapshot_type'  => $type,
        'period_ym'      => $periodYm,
        'title'          => $title,
        'created_by'     => $createdBy,
        'created_at'     => $nowStr,
        'summary'        => $summary,
        'checksum'       => $checksum,
        'tables'         => $tables,
    ];
    $payloadJson = (string)json_encode($payloadDoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $st = $pdo->prepare('
        INSERT INTO aoi_data_snapshots (
            snapshot_uid, snapshot_type, period_ym, title,
            board_count, program_count, zone_count, sheet_count,
            last_number_code, checksum, payload_json, created_by, created_at
        ) VALUES (
            :uid, :type, :ym, :title,
            :bc, :pc, :zc, :sc,
            :lnc, :chk, :json, :by, :at
        )
    ');
    $st->execute([
        ':uid'   => $uid,
        ':type'  => $type,
        ':ym'    => $periodYm,
        ':title' => $title,
        ':bc'    => $summary['board_count'],
        ':pc'    => $summary['program_count'],
        ':zc'    => $summary['zone_count'],
        ':sc'    => $summary['sheet_count'],
        ':lnc'   => $summary['last_number_code'],
        ':chk'   => $checksum,
        ':json'  => $payloadJson,
        ':by'    => substr(trim($createdBy) ?: 'system', 0, 60),
        ':at'    => $nowStr,
    ]);

    $newId = (int)$pdo->lastInsertId();

    if ($type === 'monthly_auto') {
        pruneExpiredAutoSnapshots($pdo, null, $now);
    } elseif ($type === 'pre_restore') {
        pruneExcessPreRestoreSnapshots($pdo);
    }

    return formatSnapshotRow([
        'id'               => $newId,
        'snapshot_uid'     => $uid,
        'snapshot_type'    => $type,
        'period_ym'        => $periodYm,
        'title'            => $title,
        'board_count'      => $summary['board_count'],
        'program_count'    => $summary['program_count'],
        'zone_count'       => $summary['zone_count'],
        'sheet_count'      => $summary['sheet_count'],
        'last_number_code' => $summary['last_number_code'],
        'checksum'         => $checksum,
        'created_by'       => $createdBy,
        'created_at'       => $nowStr,
    ]);
}

/**
 * Prune `monthly_auto` snapshots according to the configured retention months (e.g. keep at most N months).
 */
function pruneExpiredAutoSnapshots(PDO $pdo, ?int $retentionMonths = null, ?DateTimeInterface $now = null): int
{
    ensureBackupTables($pdo);

    if ($retentionMonths === null) {
        $settings = getBackupSettings($pdo);
        $retentionMonths = $settings['retention_months'];
    }
    $retentionMonths = max(BACKUP_MIN_RETENTION_MONTHS, min(BACKUP_MAX_RETENTION_MONTHS, $retentionMonths));

    $now ??= new DateTimeImmutable('now', new DateTimeZone(CODE_TZ));
    $firstOfCurrentMonth = new DateTimeImmutable($now->format('Y-m-01 00:00:00'), new DateTimeZone(CODE_TZ));
    $monthsBack          = max(0, $retentionMonths - 1);
    $cutoffDate          = $firstOfCurrentMonth->modify("-{$monthsBack} months");
    $cutoffYm            = $cutoffDate->format('Y-m');

    $st = $pdo->query("
        SELECT id, period_ym
        FROM aoi_data_snapshots
        WHERE snapshot_type = 'monthly_auto'
        ORDER BY period_ym DESC, id DESC
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $deleteIds   = [];
    $keptPeriods = [];

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $ym = (string)$row['period_ym'];

        if (strcmp($ym, $cutoffYm) < 0 || isset($keptPeriods[$ym]) || count($keptPeriods) >= $retentionMonths) {
            $deleteIds[] = $id;
        } else {
            $keptPeriods[$ym] = true;
        }
    }

    if (!$deleteIds) {
        return 0;
    }

    $placeholders = implode(', ', array_fill(0, count($deleteIds), '?'));
    $delSt = $pdo->prepare("DELETE FROM aoi_data_snapshots WHERE id IN ({$placeholders})");
    $delSt->execute($deleteIds);

    return $delSt->rowCount();
}

/**
 * Keep at most BACKUP_MAX_PRE_RESTORE_KEEP `pre_restore` safety snapshots so the table stays bounded.
 */
function pruneExcessPreRestoreSnapshots(PDO $pdo): int
{
    ensureBackupTables($pdo);
    $st = $pdo->query("
        SELECT id
        FROM aoi_data_snapshots
        WHERE snapshot_type = 'pre_restore'
        ORDER BY created_at DESC, id DESC
    ");
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) <= BACKUP_MAX_PRE_RESTORE_KEEP) {
        return 0;
    }
    $toDelete = array_slice($ids, BACKUP_MAX_PRE_RESTORE_KEEP);
    $placeholders = implode(', ', array_fill(0, count($toDelete), '?'));
    $delSt = $pdo->prepare("DELETE FROM aoi_data_snapshots WHERE id IN ({$placeholders})");
    $delSt->execute($toDelete);
    return $delSt->rowCount();
}

/**
 * Automatically ensure a `monthly_auto` snapshot exists for the current month (Asia/Bangkok).
 */
function ensureMonthlyAutoSnapshot(PDO $pdo, ?DateTimeInterface $now = null): ?array
{
    ensureBackupTables($pdo);
    $settings = getBackupSettings($pdo);
    if (!$settings['auto_monthly_enabled']) {
        return null;
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone(CODE_TZ));
    $currentYm = $now->format('Y-m');

    $chk = $pdo->prepare("
        SELECT id
        FROM aoi_data_snapshots
        WHERE snapshot_type = 'monthly_auto' AND period_ym = :ym
        LIMIT 1
    ");
    $chk->execute([':ym' => $currentYm]);
    $existingId = $chk->fetchColumn();

    if ($existingId !== false) {
        if ($settings['last_auto_check_ym'] !== $currentYm) {
            $upd = $pdo->prepare('UPDATE aoi_backup_settings SET last_auto_check_ym = :ym, updated_at = :now WHERE id = 1');
            $upd->execute([':ym' => $currentYm, ':now' => $now->format('Y-m-d H:i:s')]);
            pruneExpiredAutoSnapshots($pdo, $settings['retention_months'], $now);
        }
        return null;
    }

    $created = createDataSnapshot($pdo, 'monthly_auto', '', 'system-auto', $currentYm, $now);

    $upd = $pdo->prepare('UPDATE aoi_backup_settings SET last_auto_check_ym = :ym, updated_at = :now WHERE id = 1');
    $upd->execute([':ym' => $currentYm, ':now' => $now->format('Y-m-d H:i:s')]);

    return $created;
}

/**
 * List all stored snapshots (without heavy `payload_json`), plus current database summary and retention settings.
 */
function listDataSnapshots(PDO $pdo, ?DateTimeInterface $now = null): array
{
    ensureBackupTables($pdo);
    ensureMonthlyAutoSnapshot($pdo, $now);

    $st = $pdo->query('
        SELECT id, snapshot_uid, snapshot_type, period_ym, title,
               board_count, program_count, zone_count, sheet_count,
               last_number_code, checksum, created_by, created_at
        FROM aoi_data_snapshots
        ORDER BY created_at DESC, id DESC
    ');
    $snapshots = [];
    $autoCount = 0;
    $manualCount = 0;
    $preRestoreCount = 0;

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $formatted = formatSnapshotRow($r);
        $snapshots[] = $formatted;
        if ($formatted['snapshot_type'] === 'monthly_auto') {
            $autoCount++;
        } elseif ($formatted['snapshot_type'] === 'pre_restore') {
            $preRestoreCount++;
        } else {
            $manualCount++;
        }
    }

    $current = captureCurrentDatabasePayload($pdo)['summary'];
    $settings = getBackupSettings($pdo);

    return [
        'snapshots' => $snapshots,
        'counts'    => [
            'total'        => count($snapshots),
            'monthly_auto' => $autoCount,
            'manual'       => $manualCount,
            'pre_restore'  => $preRestoreCount,
        ],
        'current'   => $current,
        'settings'  => $settings,
    ];
}

/**
 * Fetch a single snapshot record including decoded `payload_json` and verify its integrity.
 */
function getSnapshotWithPayload(PDO $pdo, int $snapshotId): array
{
    ensureBackupTables($pdo);
    $st = $pdo->prepare('
        SELECT id, snapshot_uid, snapshot_type, period_ym, title,
               board_count, program_count, zone_count, sheet_count,
               last_number_code, checksum, payload_json, created_by, created_at
        FROM aoi_data_snapshots
        WHERE id = :id
        LIMIT 1
    ');
    $st->execute([':id' => $snapshotId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException(BACKUP_NOT_FOUND_ERROR);
    }

    $decoded = json_decode((string)$row['payload_json'], true);
    if (!is_array($decoded) || !isset($decoded['tables']) || !is_array($decoded['tables'])) {
        throw new RuntimeException(BACKUP_UPLOAD_INVALID_JSON_ERROR);
    }

    $tables = normalizeSnapshotTablesForHash($decoded['tables']);
    if (!verifySnapshotChecksum($tables, (string)$row['checksum'])) {
        throw new RuntimeException(BACKUP_CHECKSUM_MISMATCH_ERROR);
    }

    return [
        'meta'    => formatSnapshotRow($row),
        'payload' => $decoded,
        'tables'  => $tables,
    ];
}

/**
 * Delete a snapshot by ID.
 */
function deleteDataSnapshot(PDO $pdo, int $snapshotId): array
{
    ensureBackupTables($pdo);
    $st = $pdo->prepare('
        SELECT id, snapshot_uid, snapshot_type, period_ym, title,
               board_count, program_count, zone_count, sheet_count,
               last_number_code, checksum, created_by, created_at
        FROM aoi_data_snapshots
        WHERE id = :id
        LIMIT 1
    ');
    $st->execute([':id' => $snapshotId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException(BACKUP_NOT_FOUND_ERROR);
    }

    $del = $pdo->prepare('DELETE FROM aoi_data_snapshots WHERE id = :id');
    $del->execute([':id' => $snapshotId]);

    return formatSnapshotRow($row);
}

/**
 * Git-like `diff` between a stored snapshot and the live database.
 */
function compareSnapshotWithCurrent(PDO $pdo, int $snapshotId): array
{
    $snapData = getSnapshotWithPayload($pdo, $snapshotId);
    $snapMeta = $snapData['meta'];
    $snapTables = $snapData['tables'];

    $currentCaptured = captureCurrentDatabasePayload($pdo);
    $curTables  = $currentCaptured['tables'];
    $curSummary = $currentCaptured['summary'];

    $snapBoardsByCode = [];
    foreach ($snapTables['boards'] as $b) {
        $snapBoardsByCode[(string)$b['code']] = $b;
    }
    $curBoardsByCode = [];
    foreach ($curTables['boards'] as $b) {
        $curBoardsByCode[(string)$b['code']] = $b;
    }

    $addedSinceSnapshot    = [];
    $deletedSinceSnapshot  = [];
    $modifiedSinceSnapshot = [];

    $compareFields = ['zone', 'sheet', 'shelf', 'shelf_no', 'product', 'board_type', 'status', 'pcs_status', 'side', 'defects'];

    foreach ($curBoardsByCode as $code => $curB) {
        if (!isset($snapBoardsByCode[$code])) {
            $addedSinceSnapshot[] = [
                'code'       => $code,
                'zone'       => $curB['zone'],
                'sheet'      => $curB['sheet'],
                'shelf'      => $curB['shelf'],
                'product'    => $curB['product'],
                'board_type' => $curB['board_type'],
                'status'     => $curB['status'],
                'side'       => $curB['side'],
            ];
            continue;
        }
        $snapB = $snapBoardsByCode[$code];
        $changes = [];
        foreach ($compareFields as $f) {
            $vSnap = (string)($snapB[$f] ?? '');
            $vCur  = (string)($curB[$f] ?? '');
            if ($vSnap !== $vCur) {
                $changes[$f] = [
                    'snapshot' => $vSnap,
                    'current'  => $vCur,
                ];
            }
        }
        if ($changes) {
            $modifiedSinceSnapshot[] = [
                'code'    => $code,
                'zone'    => $snapB['zone'],
                'sheet'   => $snapB['sheet'],
                'shelf'   => $snapB['shelf'],
                'product' => $snapB['product'],
                'changes' => $changes,
            ];
        }
    }

    foreach ($snapBoardsByCode as $code => $snapB) {
        if (!isset($curBoardsByCode[$code])) {
            $deletedSinceSnapshot[] = [
                'code'       => $code,
                'zone'       => $snapB['zone'],
                'sheet'      => $snapB['sheet'],
                'shelf'      => $snapB['shelf'],
                'product'    => $snapB['product'],
                'board_type' => $snapB['board_type'],
                'status'     => $snapB['status'],
                'side'       => $snapB['side'],
            ];
        }
    }

    $snapMags = [];
    foreach ($snapTables['sheet_magazines'] as $m) {
        $snapMags[(string)$m['sheet']] = (int)$m['magazines'];
    }
    $curMags = [];
    foreach ($curTables['sheet_magazines'] as $m) {
        $curMags[(string)$m['sheet']] = (int)$m['magazines'];
    }
    $allMagSheets = array_unique(array_merge(array_keys($snapMags), array_keys($curMags)));
    sort($allMagSheets);
    $magazinesDiff = [];
    foreach ($allMagSheets as $sh) {
        $sVal = $snapMags[$sh] ?? 1;
        $cVal = $curMags[$sh] ?? 1;
        if ($sVal !== $cVal) {
            $magazinesDiff[] = [
                'sheet'    => $sh,
                'snapshot' => $sVal,
                'current'  => $cVal,
            ];
        }
    }

    $snapProgs = [];
    foreach ($snapTables['aoi_programs'] as $p) {
        $snapProgs[$p['product_model'] . '|' . $p['side']] = $p;
    }
    $curProgs = [];
    foreach ($curTables['aoi_programs'] as $p) {
        $curProgs[$p['product_model'] . '|' . $p['side']] = $p;
    }
    $progAdded    = 0;
    $progDeleted  = 0;
    $progModified = 0;
    $progFields   = ['machine_type', 'stage_1', 'stage_2', 'stage_3', 'stage_4', 'owner', 'shelf', 'program_ok', 'wait_reason', 'note'];

    foreach ($curProgs as $k => $cp) {
        if (!isset($snapProgs[$k])) {
            $progAdded++;
            continue;
        }
        $sp = $snapProgs[$k];
        foreach ($progFields as $pf) {
            if ((string)($sp[$pf] ?? '') !== (string)($cp[$pf] ?? '')) {
                $progModified++;
                break;
            }
        }
    }
    foreach ($snapProgs as $k => $sp) {
        if (!isset($curProgs[$k])) {
            $progDeleted++;
        }
    }

    $snapSheets = [];
    foreach ($snapTables['sheets'] as $s) {
        $snapSheets[(string)$s['sheet_key']] = $s;
    }
    $curSheets = [];
    foreach ($curTables['sheets'] as $s) {
        $curSheets[(string)$s['sheet_key']] = $s;
    }
    $topologyDiffCount = 0;
    foreach (array_unique(array_merge(array_keys($snapSheets), array_keys($curSheets))) as $sk) {
        if (!isset($snapSheets[$sk]) || !isset($curSheets[$sk])) {
            $topologyDiffCount++;
        } else {
            $ss = $snapSheets[$sk];
            $cs = $curSheets[$sk];
            if ($ss['zone_key'] !== $cs['zone_key'] || $ss['label'] !== $cs['label'] || $ss['shelf_prefix'] !== $cs['shelf_prefix'] || $ss['is_active'] !== $cs['is_active']) {
                $topologyDiffCount++;
            }
        }
    }

    $isIdentical = empty($addedSinceSnapshot)
        && empty($deletedSinceSnapshot)
        && empty($modifiedSinceSnapshot)
        && empty($magazinesDiff)
        && $progAdded === 0
        && $progDeleted === 0
        && $progModified === 0
        && $topologyDiffCount === 0;

    return [
        'snapshot'     => $snapMeta,
        'current'      => $curSummary,
        'is_identical' => $isIdentical,
        'boards_diff'  => [
            'added_since_snapshot_count'    => count($addedSinceSnapshot),
            'deleted_since_snapshot_count'  => count($deletedSinceSnapshot),
            'modified_since_snapshot_count' => count($modifiedSinceSnapshot),
            'added_since_snapshot'          => array_slice($addedSinceSnapshot, 0, 100),
            'deleted_since_snapshot'        => array_slice($deletedSinceSnapshot, 0, 100),
            'modified_since_snapshot'       => array_slice($modifiedSinceSnapshot, 0, 100),
        ],
        'magazines_diff' => $magazinesDiff,
        'programs_diff'  => [
            'added_since_snapshot_count'    => $progAdded,
            'deleted_since_snapshot_count'  => $progDeleted,
            'modified_since_snapshot_count' => $progModified,
        ],
        'topology_diff_count' => $topologyDiffCount,
    ];
}

/**
 * Restore all application tables to the exact state stored in `$snapshotId`.
 */
function restoreDataSnapshot(
    PDO $pdo,
    int $snapshotId,
    string $actorIp = '',
    bool $createPreRestoreBackup = true,
    ?DateTimeInterface $now = null
): array {
    $snapData   = getSnapshotWithPayload($pdo, $snapshotId);
    $snapMeta   = $snapData['meta'];
    $snapTables = $snapData['tables'];

    if (!acquireNumberCodeLock($pdo, 5)) {
        throw new RuntimeException(BACKUP_LOCK_BUSY_ERROR, 503);
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone(CODE_TZ));
    $nowStr = $now->format('Y-m-d H:i:s');

    try {
        $beforeSummary = captureCurrentDatabasePayload($pdo)['summary'];

        $pdo->beginTransaction();

        $preRestoreSnap = null;
        if ($createPreRestoreBackup) {
            $preTitle = sprintf(
                'สำรองอัตโนมัติก่อนย้อนไป #%d (%s · %s)',
                $snapMeta['id'],
                $snapMeta['period_ym'],
                $now->format('d/m/Y H:i')
            );
            $preRestoreSnap = createDataSnapshot($pdo, 'pre_restore', $preTitle, 'auto-safety', $now->format('Y-m'), $now);
        }

        // Delete in child-first order to respect fk_sheets_zone
        $pdo->exec('DELETE FROM boards');
        $pdo->exec('DELETE FROM sheet_magazines');
        $pdo->exec('DELETE FROM sheets');
        $pdo->exec('DELETE FROM zones');
        $pdo->exec('DELETE FROM aoi_programs');
        $pdo->exec('DELETE FROM aoi_operators');

        // 1. Insert `zones`
        if (!empty($snapTables['zones'])) {
            $insZone = $pdo->prepare('
                INSERT INTO zones (zone_key, label, short_label, sort_order, is_active, created_at)
                VALUES (:zk, :lbl, :slbl, :so, :ia, :ca)
            ');
            foreach ($snapTables['zones'] as $z) {
                $insZone->execute([
                    ':zk'   => $z['zone_key'],
                    ':lbl'  => $z['label'],
                    ':slbl' => $z['short_label'],
                    ':so'   => $z['sort_order'],
                    ':ia'   => $z['is_active'],
                    ':ca'   => $z['created_at'] ?: $nowStr,
                ]);
            }
        }

        // 2. Insert `sheets`
        if (!empty($snapTables['sheets'])) {
            $insSheet = $pdo->prepare('
                INSERT INTO sheets (sheet_key, zone_key, label, shelf_prefix, dot_color, sort_order, is_active, created_at)
                VALUES (:sk, :zk, :lbl, :sp, :dc, :so, :ia, :ca)
            ');
            foreach ($snapTables['sheets'] as $s) {
                $insSheet->execute([
                    ':sk'  => $s['sheet_key'],
                    ':zk'  => $s['zone_key'],
                    ':lbl' => $s['label'],
                    ':sp'  => $s['shelf_prefix'],
                    ':dc'  => $s['dot_color'],
                    ':so'  => $s['sort_order'],
                    ':ia'  => $s['is_active'],
                    ':ca'  => $s['created_at'] ?: $nowStr,
                ]);
            }
        }

        // 3. Insert `sheet_magazines`
        if (!empty($snapTables['sheet_magazines'])) {
            $insMag = $pdo->prepare('
                INSERT INTO sheet_magazines (sheet, magazines)
                VALUES (:sh, :mags)
            ');
            foreach ($snapTables['sheet_magazines'] as $m) {
                $insMag->execute([
                    ':sh'   => $m['sheet'],
                    ':mags' => max(1, (int)$m['magazines']),
                ]);
            }
        }

        // 4. Insert `boards`
        if (!empty($snapTables['boards'])) {
            $insBoard = $pdo->prepare('
                INSERT INTO boards (
                    id, zone, sheet, code, shelf, shelf_no,
                    product, board_type, status, pcs_status,
                    side, defects, mag_slot, created_at
                ) VALUES (
                    :id, :zone, :sheet, :code, :shelf, :shelf_no,
                    :product, :board_type, :status, :pcs_status,
                    :side, :defects, :mag_slot, :created_at
                )
            ');
            foreach ($snapTables['boards'] as $idx => $b) {
                $rowId = (int)$b['id'] > 0 ? (int)$b['id'] : ($idx + 1);
                $insBoard->execute([
                    ':id'         => $rowId,
                    ':zone'       => $b['zone'],
                    ':sheet'      => $b['sheet'],
                    ':code'       => $b['code'],
                    ':shelf'      => $b['shelf'],
                    ':shelf_no'   => (int)$b['shelf_no'],
                    ':product'    => $b['product'],
                    ':board_type' => $b['board_type'],
                    ':status'     => $b['status'],
                    ':pcs_status' => $b['pcs_status'],
                    ':side'       => $b['side'],
                    ':defects'    => $b['defects'],
                    ':mag_slot'   => $b['mag_slot'],
                    ':created_at' => $b['created_at'] ?: $nowStr,
                ]);
            }
        }

        // 5. Insert `aoi_programs`
        if (!empty($snapTables['aoi_programs'])) {
            $insProg = $pdo->prepare('
                INSERT INTO aoi_programs (
                    id, product_model, side, family_key, machine_type,
                    stage_1, stage_1_by, stage_1_at,
                    stage_2, stage_2_by, stage_2_at,
                    stage_3, stage_3_by, stage_3_at,
                    stage_4, stage_4_by, stage_4_at,
                    owner, shelf, program_ok, wait_reason, note,
                    version, created_at, updated_at
                ) VALUES (
                    :id, :pm, :side, :fk, :mt,
                    :s1, :s1b, :s1a,
                    :s2, :s2b, :s2a,
                    :s3, :s3b, :s3a,
                    :s4, :s4b, :s4a,
                    :owner, :shelf, :pok, :wr, :note,
                    :ver, :ca, :ua
                )
            ');
            foreach ($snapTables['aoi_programs'] as $idx => $p) {
                $progId = (int)$p['id'] > 0 ? (int)$p['id'] : ($idx + 1);
                $insProg->execute([
                    ':id'    => $progId,
                    ':pm'    => $p['product_model'],
                    ':side'  => $p['side'],
                    ':fk'    => $p['family_key'],
                    ':mt'    => $p['machine_type'],
                    ':s1'    => (int)$p['stage_1'],
                    ':s1b'   => $p['stage_1_by'],
                    ':s1a'   => $p['stage_1_at'],
                    ':s2'    => (int)$p['stage_2'],
                    ':s2b'   => $p['stage_2_by'],
                    ':s2a'   => $p['stage_2_at'],
                    ':s3'    => (int)$p['stage_3'],
                    ':s3b'   => $p['stage_3_by'],
                    ':s3a'   => $p['stage_3_at'],
                    ':s4'    => (int)$p['stage_4'],
                    ':s4b'   => $p['stage_4_by'],
                    ':s4a'   => $p['stage_4_at'],
                    ':owner' => $p['owner'],
                    ':shelf' => $p['shelf'],
                    ':pok'   => $p['program_ok'],
                    ':wr'    => $p['wait_reason'],
                    ':note'  => $p['note'],
                    ':ver'   => max(1, (int)$p['version']),
                    ':ca'    => $p['created_at'] ?: $nowStr,
                    ':ua'    => $p['updated_at'] ?: $nowStr,
                ]);
            }
        }

        // 6. Insert `aoi_operators`
        if (!empty($snapTables['aoi_operators'])) {
            $insOp = $pdo->prepare('
                INSERT INTO aoi_operators (name, created_at)
                VALUES (:name, :ca)
            ');
            foreach ($snapTables['aoi_operators'] as $op) {
                if (trim((string)$op['name']) === '') {
                    continue;
                }
                $insOp->execute([
                    ':name' => $op['name'],
                    ':ca'   => $op['created_at'] ?: $nowStr,
                ]);
            }
        }

        // 7. Record audit trail in `topology_audit`
        $auditSt = $pdo->prepare('
            INSERT INTO topology_audit (action, entity_type, entity_key, before_json, after_json, ip, created_at)
            VALUES (:act, :etype, :ekey, :before, :after, :ip, :ca)
        ');
        $auditSt->execute([
            ':act'    => 'snapshot_restore',
            ':etype'  => 'snapshot',
            ':ekey'   => substr((string)$snapMeta['snapshot_uid'], 0, 30),
            ':before' => json_encode($beforeSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':after'  => json_encode([
                'restored_id'      => $snapMeta['id'],
                'snapshot_uid'     => $snapMeta['snapshot_uid'],
                'period_ym'        => $snapMeta['period_ym'],
                'board_count'      => $snapMeta['board_count'],
                'last_number_code' => $snapMeta['last_number_code'],
                'pre_restore_id'   => $preRestoreSnap['id'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip'     => substr($actorIp, 0, 45),
            ':ca'     => $nowStr,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        releaseNumberCodeLock($pdo);
    }

    topology($pdo, true);

    return [
        'restored_snapshot'    => $snapMeta,
        'pre_restore_snapshot' => $preRestoreSnap,
        'current'              => captureCurrentDatabasePayload($pdo)['summary'],
    ];
}

/**
 * Build a downloadable signed `.json` file payload for `$snapshotId`.
 */
function exportSnapshotFilePayload(PDO $pdo, int $snapshotId): array
{
    $snapData = getSnapshotWithPayload($pdo, $snapshotId);
    $meta     = $snapData['meta'];
    $tables   = $snapData['tables'];

    $exportDoc = [
        'format'         => 'aoi_data_snapshot',
        'schema_version' => BACKUP_SCHEMA_VERSION,
        'exported_at'    => (new DateTimeImmutable('now', new DateTimeZone(CODE_TZ)))->format('Y-m-d H:i:s'),
        'snapshot_uid'   => $meta['snapshot_uid'],
        'snapshot_type'  => $meta['snapshot_type'],
        'period_ym'      => $meta['period_ym'],
        'title'          => $meta['title'],
        'created_by'     => $meta['created_by'],
        'created_at'     => $meta['created_at'],
        'summary'        => [
            'board_count'      => $meta['board_count'],
            'program_count'    => $meta['program_count'],
            'zone_count'       => $meta['zone_count'],
            'sheet_count'      => $meta['sheet_count'],
            'last_number_code' => $meta['last_number_code'],
        ],
        'checksum'       => $meta['checksum'],
        'tables'         => $tables,
    ];

    $safePeriod = preg_replace('/[^0-9A-Za-z_-]/', '', $meta['period_ym']) ?: 'backup';
    $shortHash  = substr($meta['checksum'], 0, 8);
    $filename   = sprintf('AOI_Backup_%s_id%d_%s.json', $safePeriod, $meta['id'], $shortHash);
    $json       = (string)json_encode($exportDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return [
        'filename' => $filename,
        'json'     => $json,
        'meta'     => $meta,
    ];
}

/**
 * Validate and import an uploaded `.json` backup file into `aoi_data_snapshots` as `'uploaded'`.
 */
function importSnapshotFromUploadedJson(PDO $pdo, string $rawJson, string $createdBy = 'admin-upload', ?DateTimeInterface $now = null): array
{
    ensureBackupTables($pdo);

    $len = strlen($rawJson);
    if ($len === 0) {
        throw new InvalidArgumentException(BACKUP_UPLOAD_EMPTY_ERROR);
    }
    if ($len > BACKUP_MAX_UPLOAD_BYTES) {
        throw new InvalidArgumentException(BACKUP_UPLOAD_TOO_LARGE_ERROR);
    }

    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded) || !isset($decoded['tables']) || !is_array($decoded['tables'])) {
        throw new InvalidArgumentException(BACKUP_UPLOAD_INVALID_JSON_ERROR);
    }

    $requiredTables = ['zones', 'sheets', 'sheet_magazines', 'boards'];
    foreach ($requiredTables as $tbl) {
        if (!isset($decoded['tables'][$tbl]) || !is_array($decoded['tables'][$tbl])) {
            throw new InvalidArgumentException(BACKUP_UPLOAD_INVALID_JSON_ERROR);
        }
    }

    $tables   = normalizeSnapshotTablesForHash($decoded['tables']);
    $checksum = isset($decoded['checksum']) ? (string)$decoded['checksum'] : '';
    if (!verifySnapshotChecksum($tables, $checksum)) {
        throw new InvalidArgumentException(BACKUP_CHECKSUM_MISMATCH_ERROR);
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone(CODE_TZ));
    $nowStr = $now->format('Y-m-d H:i:s');

    $periodYm = isset($decoded['period_ym']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$decoded['period_ym'])
        ? (string)$decoded['period_ym']
        : $now->format('Y-m');

    $origTitle = trim((string)($decoded['title'] ?? ''));
    $title     = $origTitle !== '' ? "[นำเข้า] {$origTitle}" : "ไฟล์สำรองนำเข้า ({$periodYm})";
    if (function_exists('mb_substr')) {
        $title = mb_substr($title, 0, 120, 'UTF-8');
    } else {
        $title = substr($title, 0, 120);
    }

    $uid = sprintf(
        'snap_up_%s_%s_%s',
        str_replace('-', '', $periodYm),
        $now->format('YmdHis'),
        substr(bin2hex(random_bytes(4)), 0, 8)
    );

    $boardCount     = count($tables['boards']);
    $programCount   = count($tables['aoi_programs']);
    $zoneCount      = count($tables['zones']);
    $sheetCount     = count($tables['sheets']);
    $lastNumberCode = findLatestNumberCodeFromBoards($tables['boards']);

    $payloadDoc = [
        'schema_version' => BACKUP_SCHEMA_VERSION,
        'snapshot_uid'   => $uid,
        'snapshot_type'  => 'uploaded',
        'period_ym'      => $periodYm,
        'title'          => $title,
        'created_by'     => $createdBy,
        'created_at'     => $nowStr,
        'summary'        => [
            'board_count'      => $boardCount,
            'program_count'    => $programCount,
            'zone_count'       => $zoneCount,
            'sheet_count'      => $sheetCount,
            'last_number_code' => $lastNumberCode,
        ],
        'checksum'       => $checksum,
        'tables'         => $tables,
    ];
    $payloadJson = (string)json_encode($payloadDoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $st = $pdo->prepare('
        INSERT INTO aoi_data_snapshots (
            snapshot_uid, snapshot_type, period_ym, title,
            board_count, program_count, zone_count, sheet_count,
            last_number_code, checksum, payload_json, created_by, created_at
        ) VALUES (
            :uid, :type, :ym, :title,
            :bc, :pc, :zc, :sc,
            :lnc, :chk, :json, :by, :at
        )
    ');
    $st->execute([
        ':uid'   => $uid,
        ':type'  => 'uploaded',
        ':ym'    => $periodYm,
        ':title' => $title,
        ':bc'    => $boardCount,
        ':pc'    => $programCount,
        ':zc'    => $zoneCount,
        ':sc'    => $sheetCount,
        ':lnc'   => $lastNumberCode,
        ':chk'   => $checksum,
        ':json'  => $payloadJson,
        ':by'    => substr($createdBy, 0, 60),
        ':at'    => $nowStr,
    ]);

    $newId = (int)$pdo->lastInsertId();

    return formatSnapshotRow([
        'id'               => $newId,
        'snapshot_uid'     => $uid,
        'snapshot_type'    => 'uploaded',
        'period_ym'        => $periodYm,
        'title'            => $title,
        'board_count'      => $boardCount,
        'program_count'    => $programCount,
        'zone_count'       => $zoneCount,
        'sheet_count'      => $sheetCount,
        'last_number_code' => $lastNumberCode,
        'checksum'         => $checksum,
        'created_by'       => $createdBy,
        'created_at'       => $nowStr,
    ]);
}
