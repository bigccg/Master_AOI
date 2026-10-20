<?php
/**
 * Administrator sign-in / sign-out for the Zone & Sheet manager (includes/admin-auth.php).
 * Regular users never need this page: viewing and saving board records stay open without signing in.
 *
 *   GET  admin.php?zone=&sheet=          sign-in form (the setup steps while AOI_ADMIN_PASSWORD_HASH is not set)
 *   POST action=login  csrf, password    -> index.php?zone=&sheet=&manage=1 (opens the manager)
 *   POST action=logout csrf              -> index.php?zone=&sheet=
 * zone / sheet only carry the user back to the view they came from: keys only, never a URL (no open redirect).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/admin-auth.php';
require __DIR__ . '/includes/assets.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");

/** Field as a plain string (arrays like x[]=1 are ignored); csrf / action / password are only read from the POST body */
function formField(string $key, bool $postOnly = true): string
{
    $v = $_POST[$key] ?? ($postOnly ? '' : ($_GET[$key] ?? ''));
    return is_scalar($v) ? (string)$v : '';
}

$back = [];
foreach (['zone', 'sheet'] as $k) {
    if (preg_match('/^[A-Za-z0-9_]{1,30}$/', formField($k, false))) {
        $back[$k] = formField($k, false);
    }
}
$backUrl   = 'index.php' . ($back ? '?' . http_build_query($back) : '');
$manageUrl = 'index.php?' . http_build_query($back + ['manage' => '1']);

$error  = '';
$status = 200;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = formField('action');
    $csrfOk = hash_equals(csrf(), formField('csrf'));
    if ($action === 'logout') {
        if ($csrfOk) {
            adminSignOut();
            $_SESSION['flash'] = ['ok', 'ออกจากระบบผู้ดูแลเรียบร้อยแล้ว การดูและบันทึกข้อมูลบอร์ดยังใช้งานได้ตามปกติ / Signed out of the administrator session. Viewing and recording boards works as usual.'];
            header('Location: ' . $backUrl, true, 303);
            exit;
        }
        $error  = 'ออกจากระบบไม่สำเร็จ เนื่องจากหน้าเว็บเปิดค้างไว้จนโทเค็นความปลอดภัยหมดอายุ กรุณากดออกจากระบบอีกครั้ง / Sign-out failed because the page was open too long and its security token expired. Please select Sign out again.';
        $status = 403;
    } elseif ($action === 'login') {
        if (!$csrfOk) {
            $error  = ADMIN_FORM_EXPIRED_ERROR;
            $status = 403;
        } else {
            $result = adminSignIn(formField('password'));
            if ($result['ok']) {
                header('Location: ' . $manageUrl, true, 303);
                exit;
            }
            $error  = $result['error'];
            $status = !adminConfigured() ? 503 : ($result['locked'] > 0 ? 429 : 401);
        }
    } else {
        $error  = 'ทำรายการไม่ได้ เนื่องจากคำสั่งไม่ถูกต้อง กรุณาเปิดหน้านี้ใหม่จากปุ่มในหน้าหลัก / Unknown action. Please open this page again from the main page.';
        $status = 400;
    }
}

$configured = adminConfigured();
$signedIn   = $configured && isAdmin();
$lockLeft   = ($configured && !$signedIn) ? adminLockLeft() : 0;
if (!$configured) {
    $status = 503;
}
if ($lockLeft > 0 && $error === '') {
    $error = adminLockedMessage($lockLeft);
}
http_response_code($status);

$pageTitle = 'เข้าสู่ระบบผู้ดูแล / Administrator Sign-in | AOI';
?>
<!DOCTYPE html>
<html class="h-full bg-slate-50" lang="th">
<?php require __DIR__ . '/components/layout/head.php'; ?>
<body class="h-full font-sans text-slate-800 antialiased">
<main class="gm-overlay tm-page" aria-labelledby="admin-title">
  <div class="gm-window sm">
    <span class="gm-glow" aria-hidden="true"></span><span class="gm-ring" aria-hidden="true"></span>
    <div class="gm-surface">
      <i class="gm-orb o1"></i><i class="gm-orb o2"></i><i class="gm-orb o3"></i>

      <header class="gm-head">
        <span class="gm-badge"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span>
        <div class="gm-headtext">
          <h1 id="admin-title" class="gm-title">เข้าสู่ระบบผู้ดูแล / Administrator Sign-in</h1>
          <p class="tm-subtitle">จัดการโซนและชีต / Manage Zones &amp; Sheets</p>
        </div>
        <a href="<?= h($backUrl) ?>" class="gm-close" aria-label="กลับหน้าหลัก / Back to the main page"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
      </header>

<?php if (!$configured): ?>
      <div class="gm-form">
        <div class="gm-body">
          <div class="gm-error" role="alert"><?= h(ADMIN_NOT_CONFIGURED_ERROR) ?></div>
          <p class="tm-lead">ขั้นตอนตั้งค่า (สำหรับผู้ดูแลเซิร์ฟเวอร์) / Setup for the server administrator:</p>
          <ol class="tm-steps">
            <li>สร้างแฮชของรหัสผ่าน ระบบจะถามรหัสผ่านโดยไม่แสดงบนจอและไม่ค้างใน history / Create the password hash (the password is asked for, never echoed):
              <code class="tm-code">docker exec -it php_app_web php /var/www/html/aoi-web-php/scripts/admin-password-hash.php</code></li>
            <li>ใส่บรรทัดที่สคริปต์พิมพ์ออกมาใน <code>docker-compose.yml</code> ใต้ <code>services → web → environment</code> (ใน compose ต้องเขียน <code>$</code> เป็น <code>$$</code>) / Add the printed line under <code>services → web → environment</code> (compose needs <code>$$</code> for every <code>$</code>):
              <code class="tm-code">AOI_ADMIN_PASSWORD_HASH: "$$2y$$10$$…"</code></li>
            <li>รีสตาร์ต web แล้วเปิดหน้านี้ใหม่ / Restart the web service, then reload this page:
              <code class="tm-code">docker compose up -d web</code></li>
          </ol>
        </div>
        <div class="gm-actions">
          <a href="<?= h($backUrl) ?>" class="gm-btn ghost"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>กลับหน้าหลัก / Back</a>
        </div>
      </div>
<?php elseif ($signedIn): ?>
      <div class="gm-form">
        <div class="gm-body">
          <?php if ($error !== ''): ?><div class="gm-error" role="alert"><?= h($error) ?></div><?php endif; ?>
          <p class="tm-lead">คุณเข้าสู่ระบบผู้ดูแลอยู่แล้ว เปิดหน้าจัดการโซนและชีตได้จากปุ่มด้านล่างหรือปุ่ม "จัดการโซนและชีต" ที่หัวหน้าหลัก / You are already signed in as administrator. Open the manager below or with "Manage Zones &amp; Sheets" in the page header.</p>
        </div>
        <div class="gm-actions">
          <form method="post" action="admin.php" style="margin-right:auto">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"/>
            <input type="hidden" name="action" value="logout"/>
            <?php foreach ($back as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"/><?php endforeach; ?>
            <button type="submit" class="gm-btn ghost"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>ออกจากระบบ / Sign out</button>
          </form>
          <a href="<?= h($manageUrl) ?>" class="gm-btn primary"><i class="fa-solid fa-sitemap" aria-hidden="true"></i>เปิดหน้าจัดการ / Open manager</a>
        </div>
      </div>
<?php else: ?>
      <form method="post" action="admin.php" class="gm-form" id="admin-login-form">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"/>
        <input type="hidden" name="action" value="login"/>
        <?php foreach ($back as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"/><?php endforeach; ?>
        <input type="text" name="username" value="admin" autocomplete="username" hidden aria-hidden="true" tabindex="-1"/>
        <div class="gm-body">
          <p class="tm-lead">ใช้เฉพาะการจัดการโซนและชีต ผู้ใช้ทั่วไปดูและบันทึกข้อมูลบอร์ดได้โดยไม่ต้องเข้าสู่ระบบ / Only needed to manage zones and sheets. Viewing and recording boards needs no sign-in.</p>
          <?php if ($error !== ''): ?><div class="gm-error" role="alert" id="admin-error"><?= h($error) ?></div><?php endif; ?>
          <div class="gm-field">
            <label class="gm-label" for="admin-password"><span class="k">01</span><span>รหัสผ่านผู้ดูแล <span class="th">(Administrator password)</span><i class="gm-req">*</i></span></label>
            <div class="gm-input-wrap">
              <input type="password" id="admin-password" name="password" required maxlength="<?= ADMIN_PASSWORD_MAX ?>"
                     autocomplete="current-password" class="gm-input has-ico" <?= $lockLeft > 0 ? 'disabled' : 'autofocus' ?>
                     aria-describedby="admin-password-hint<?= $error !== '' ? ' admin-error' : '' ?>"<?= ($status === 401 || $status === 429) ? ' aria-invalid="true"' : '' ?>/>
              <i class="fa-solid fa-key gm-ico" aria-hidden="true"></i>
            </div>
            <p class="gm-hint" id="admin-password-hint">ใส่ผิดครบ <?= ADMIN_MAX_FAILURES ?> ครั้ง จะล็อก <?= intdiv(ADMIN_LOCK_SECONDS, 60) ?> นาที · ไม่ใช้งาน <?= intdiv(ADMIN_IDLE_SECONDS, 60) ?> นาที ต้องเข้าสู่ระบบใหม่ / <?= ADMIN_MAX_FAILURES ?> wrong tries lock sign-in for <?= intdiv(ADMIN_LOCK_SECONDS, 60) ?> min · <?= intdiv(ADMIN_IDLE_SECONDS, 60) ?> idle min sign you out</p>
          </div>
        </div>
        <div class="gm-actions">
          <a href="<?= h($backUrl) ?>" class="gm-btn ghost">กลับหน้าหลัก / Back</a>
          <button type="submit" id="admin-submit" class="gm-btn primary" <?= $lockLeft > 0 ? 'disabled aria-disabled="true"' : '' ?>>
            <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i><span>เข้าสู่ระบบ / Sign in</span><kbd>Enter ↵</kbd>
          </button>
        </div>
      </form>
<?php endif; ?>

      <footer class="gm-status">
        <span class="gm-mode"><i class="fa-solid fa-user-shield" style="font-size:9px" aria-hidden="true"></i>ADMIN</span>
        <span class="gm-seg-info">zones <b>·</b> sheets</span>
        <span class="gm-keys"><?= (!$configured || $signedIn) ? '' : '<span><kbd>Enter</kbd> sign in</span>' ?><span><kbd>Esc</kbd> back</span></span>
      </footer>
    </div>
  </div>
</main>
<script>
  // Esc = back to the main page; submit shows the loading state once (the server answers with a redirect or this page)
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') window.location.href = <?= json_encode($backUrl, JSON_HEX_TAG | JSON_HEX_AMP) ?>; });
  document.getElementById('admin-login-form')?.addEventListener('submit', () => {
    const btn = document.getElementById('admin-submit');
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span>กำลังตรวจสอบ / Checking...</span>';
  });
</script>
</body>
</html>
