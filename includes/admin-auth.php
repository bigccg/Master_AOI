<?php
/**
 * Administrator gate for the Zone & Sheet manager (admin.php, api/topology.php, header buttons).
 * Regular users never sign in: viewing and saving board records stay open exactly as before.
 *
 * - One shared administrator password, stored only as a password_hash() in the environment variable
 *   AOI_ADMIN_PASSWORD_HASH (create it with: php scripts/admin-password-hash.php). While it is unset or not a
 *   valid hash every admin page and endpoint stays closed (fail closed) and explains how to set it.
 * - Sign-in regenerates the session id. The admin session ends after ADMIN_IDLE_SECONDS without a request,
 *   ADMIN_MAX_SECONDS after sign-in, on sign-out, or as soon as the configured hash changes.
 * - Wrong passwords: each one waits ADMIN_FAIL_DELAY_US; ADMIN_MAX_FAILURES within ADMIN_FAILURE_WINDOW lock
 *   sign-in for ADMIN_LOCK_SECONDS, counted per session and per client IP (REMOTE_ADDR; X-Forwarded-For is not
 *   trusted). The IP counter is a small JSON file per IP in the PHP temp dir, see adminThrottleFile().
 */

declare(strict_types=1);

const ADMIN_IDLE_SECONDS   = 1800;    // 30 min without an admin request
const ADMIN_MAX_SECONDS    = 28800;   // 8 h after sign-in
const ADMIN_MAX_FAILURES   = 5;
const ADMIN_FAILURE_WINDOW = 900;     // failures older than 15 min no longer count
const ADMIN_LOCK_SECONDS   = 300;     // 5 min lock after ADMIN_MAX_FAILURES
const ADMIN_FAIL_DELAY_US  = 500000;  // 0.5 s pause after every wrong password
const ADMIN_PASSWORD_MAX   = 1024;    // longer input is refused before hashing

// UX Copy (What happened + Why + How to fix, Thai / English)
const ADMIN_NOT_CONFIGURED_ERROR = 'หน้าจัดการโซนและชีตยังปิดอยู่ เนื่องจากเซิร์ฟเวอร์ยังไม่ได้ตั้งค่ารหัสผ่านผู้ดูแล (ตัวแปร AOI_ADMIN_PASSWORD_HASH) กรุณาให้ผู้ดูแลเซิร์ฟเวอร์ตั้งค่าตามขั้นตอนใน README หัวข้อ "ผู้ดูแลระบบ" / Zone & sheet management is disabled because the administrator password (AOI_ADMIN_PASSWORD_HASH) is not configured on the server. Ask the server administrator to set it up as described in the README ("Administrator").';
const ADMIN_SIGN_IN_REQUIRED_ERROR = 'ทำรายการนี้ไม่ได้ เนื่องจากยังไม่ได้เข้าสู่ระบบผู้ดูแล กรุณาเข้าสู่ระบบผู้ดูแลแล้วลองใหม่ / This action needs an administrator. Please sign in as administrator and try again.';
const ADMIN_SESSION_EXPIRED_ERROR = 'ทำรายการนี้ไม่ได้ เนื่องจากเซสชันผู้ดูแลหมดอายุ (ไม่มีการใช้งานเกิน 30 นาที หรือเข้าสู่ระบบนานเกิน 8 ชั่วโมง) กรุณาเข้าสู่ระบบอีกครั้ง / Your administrator session expired (30 minutes without activity or 8 hours since sign-in). Please sign in again.';
const ADMIN_EMPTY_PASSWORD_ERROR = 'เข้าสู่ระบบไม่ได้ เนื่องจากยังไม่ได้กรอกรหัสผ่าน กรุณากรอกรหัสผ่านผู้ดูแลแล้วกดเข้าสู่ระบบ / Please enter the administrator password, then select Sign in.';
const ADMIN_FORM_EXPIRED_ERROR = 'เข้าสู่ระบบไม่สำเร็จ เนื่องจากหน้านี้เปิดค้างไว้จนโทเค็นความปลอดภัยหมดอายุ กรุณากรอกรหัสผ่านแล้วลองอีกครั้ง / Sign-in failed because this page was open too long and its security token expired. Please enter the password and try again.';

/** The configured hash ('' when unset) */
function adminPasswordHash(): string
{
    $hash = getenv('AOI_ADMIN_PASSWORD_HASH');
    return is_string($hash) ? trim($hash) : '';
}

/** Is a usable password hash configured? Anything else keeps the admin features closed. */
function adminConfigured(): bool
{
    $hash = adminPasswordHash();
    return $hash !== '' && password_get_info($hash)['algo'] !== null;
}

/** Ties a session to the hash it signed in with: changing AOI_ADMIN_PASSWORD_HASH signs everybody out */
function adminHashFingerprint(): string
{
    return hash('sha256', adminPasswordHash());
}

/**
 * State of the administrator session: 'ok', 'none' (not signed in), 'expired' (timed out, or the hash changed)
 * or 'unconfigured'. An 'ok' check counts as activity for ADMIN_IDLE_SECONDS.
 */
function adminStatus(): string
{
    if (!adminConfigured()) {
        return 'unconfigured';
    }
    $s = $_SESSION['aoi_admin'] ?? null;
    if (!is_array($s)) {
        return 'none';
    }
    $now = time();
    if (!hash_equals(adminHashFingerprint(), (string)($s['fp'] ?? ''))
        || $now - (int)($s['since'] ?? 0) > ADMIN_MAX_SECONDS
        || $now - (int)($s['seen'] ?? 0) > ADMIN_IDLE_SECONDS) {
        unset($_SESSION['aoi_admin']);
        return 'expired';
    }
    $_SESSION['aoi_admin']['seen'] = $now;
    return 'ok';
}

function isAdmin(): bool
{
    return adminStatus() === 'ok';
}

/** Client address used for the sign-in throttle */
function clientIp(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * For admin JSON endpoints, before anything else: exits with 503 (not configured) or 401 (not signed in / expired)
 * in the api/save.php error shape { success: false, errors: [...] } plus auth: <adminStatus()>.
 */
function requireAdminJson(): void
{
    $status = adminStatus();
    if ($status === 'ok') {
        return;
    }
    [$code, $message] = match ($status) {
        'unconfigured' => [503, ADMIN_NOT_CONFIGURED_ERROR],
        'expired'      => [401, ADMIN_SESSION_EXPIRED_ERROR],
        default        => [401, ADMIN_SIGN_IN_REQUIRED_ERROR],
    };
    http_response_code($code);
    echo json_encode(['success' => false, 'errors' => [$message], 'auth' => $status], JSON_UNESCAPED_UNICODE);
    exit;
}

// ──────────────────────────────────────────────
// Sign-in / sign-out
// ──────────────────────────────────────────────

/**
 * Check $password and sign in.
 * @return array{ok: bool, error: string, locked: int} locked = seconds until sign-in may be tried again (0 = not locked)
 */
function adminSignIn(string $password): array
{
    if (!adminConfigured()) {
        return ['ok' => false, 'error' => ADMIN_NOT_CONFIGURED_ERROR, 'locked' => 0];
    }
    $now      = time();
    $ip       = clientIp();
    $lockLeft = adminLockLeft();
    if ($lockLeft > 0) {
        return ['ok' => false, 'error' => adminLockedMessage($lockLeft), 'locked' => $lockLeft];
    }
    if ($password === '') {
        return ['ok' => false, 'error' => ADMIN_EMPTY_PASSWORD_ERROR, 'locked' => 0];
    }

    if (strlen($password) <= ADMIN_PASSWORD_MAX && password_verify($password, adminPasswordHash())) {
        session_regenerate_id(true);   // new id for the privileged session (no fixation)
        unset($_SESSION['aoi_admin_fails']);
        adminIpThrottle($ip, $now, null);
        $_SESSION['aoi_admin'] = ['since' => $now, 'seen' => $now, 'fp' => adminHashFingerprint()];
        return ['ok' => true, 'error' => '', 'locked' => 0];
    }

    usleep(ADMIN_FAIL_DELAY_US);
    $_SESSION['aoi_admin_fails'] = throttleRecordFailure($_SESSION['aoi_admin_fails'] ?? [], $now);
    $ipState  = adminIpThrottle($ip, $now, true);
    $lockLeft = max(throttleLockLeft($_SESSION['aoi_admin_fails'], $now), throttleLockLeft($ipState, $now));
    if ($lockLeft > 0) {
        return ['ok' => false, 'error' => adminLockedMessage($lockLeft), 'locked' => $lockLeft];
    }
    $left = ADMIN_MAX_FAILURES - max(count($_SESSION['aoi_admin_fails']['fails']), count($ipState['fails']));
    return [
        'ok'     => false,
        'error'  => "เข้าสู่ระบบไม่สำเร็จ เนื่องจากรหัสผ่านไม่ถูกต้อง กรุณาตรวจสอบแป้นภาษา/Caps Lock แล้วลองใหม่ (ลองได้อีก $left ครั้ง ก่อนถูกล็อกชั่วคราว " . intdiv(ADMIN_LOCK_SECONDS, 60) . " นาที) / Sign-in failed because the password is incorrect. Check the keyboard language and Caps Lock, then try again ($left attempt(s) left before a " . intdiv(ADMIN_LOCK_SECONDS, 60) . '-minute lock).',
        'locked' => 0,
    ];
}

/** Seconds until this session / client may try to sign in again (0 = not locked) */
function adminLockLeft(): int
{
    $now = time();
    return max(throttleLockLeft($_SESSION['aoi_admin_fails'] ?? [], $now), throttleLockLeft(adminIpThrottle(clientIp(), $now, false), $now));
}

function adminLockedMessage(int $seconds): string
{
    $min = max(1, (int)ceil($seconds / 60));
    return 'เข้าสู่ระบบไม่ได้ชั่วคราว เนื่องจากใส่รหัสผ่านผิดครบ ' . ADMIN_MAX_FAILURES . " ครั้ง กรุณารอประมาณ $min นาทีแล้วลองใหม่ หรือติดต่อผู้ดูแลเซิร์ฟเวอร์ / Sign-in is temporarily locked because the password was wrong " . ADMIN_MAX_FAILURES . " times. Please wait about $min minute(s) and try again, or contact the server administrator.";
}

function adminSignOut(): void
{
    unset($_SESSION['aoi_admin']);
    session_regenerate_id(true);
}

// ──────────────────────────────────────────────
// Sign-in throttle: ['fails' => [timestamps within ADMIN_FAILURE_WINDOW], 'until' => lock end timestamp]
// ──────────────────────────────────────────────

function throttleLockLeft(array $state, int $now): int
{
    return max(0, (int)($state['until'] ?? 0) - $now);
}

/** Add one failure; reaching ADMIN_MAX_FAILURES starts the lock (and resets the count for after it) */
function throttleRecordFailure(array $state, int $now): array
{
    $fails   = array_values(array_filter((array)($state['fails'] ?? []), static fn($t): bool => $now - (int)$t < ADMIN_FAILURE_WINDOW));
    $fails[] = $now;
    if (count($fails) >= ADMIN_MAX_FAILURES) {
        return ['fails' => [], 'until' => $now + ADMIN_LOCK_SECONDS];
    }
    return ['fails' => $fails, 'until' => (int)($state['until'] ?? 0)];
}

/** Per-IP throttle file, per database like the migration flags: e.g. /tmp/aoi-Master_AoI-admin-<sha1(ip)>.json */
function adminThrottleFile(string $ip): string
{
    $dbName = preg_replace('/[^A-Za-z0-9_]/', '_', getenv('AOI_DB_NAME') ?: 'Master_AoI');
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "aoi-$dbName-admin-" . sha1($ip) . '.json';
}

/**
 * Read ($fail = false), add a failure ($fail = true) or clear ($fail = null) the throttle of $ip, under flock.
 * An unwritable temp dir only loses the per-IP part; the per-session counter still applies.
 */
function adminIpThrottle(string $ip, int $now, ?bool $fail): array
{
    $file = adminThrottleFile($ip);
    if ($fail === false && !is_file($file)) {
        return [];
    }
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return [];
    }
    flock($fh, LOCK_EX);
    $state = json_decode((string)stream_get_contents($fh), true);
    $state = is_array($state) ? $state : [];
    if ($fail !== false) {
        $state = $fail ? throttleRecordFailure($state, $now) : [];
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state));
        fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($fail === null) {
        @unlink($file);
    }
    return $state;
}
