<?php
/**
 * Top bar: logo, live clock, Export, New Board Entry + Zone selector & sheet tabs.
 * JS: assets/js/components/clock.js, sheet-tabs.js · CSS: components/clock.css, theme/hyprland.css
 */
$addBlocked     = insertBlockedReason($zone, $sheet);   // same rules saveBoardRecord() applies
$addBtnDisabled = $addBlocked !== '';
$addBtnTitle    = $addBtnDisabled ? $addBlocked : 'เพิ่มข้อมูลบอร์ดลงใน Shelf ' . $sheet . ' / Add board record to ' . $sheet;
?>
<!-- ===== Header ===== -->
<header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm">
  <div class="px-4 lg:px-f6 py-2.5">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
      <!-- Left: Logo & System Info -->
      <div class="flex items-center space-x-3 min-w-0">
        <img src="assets/icons/logo.png" alt="AOI System Logo" class="w-9 h-9 object-contain drop-shadow-sm select-none shrink-0">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h1 class="text-base sm:text-lg font-bold text-slate-900 tracking-tight leading-tight truncate">AOI Inspection &amp; Shelf Management System</h1>
          </div>
          <p class="text-xs text-slate-500 flex items-center gap-2">
            <span class="truncate">ระบบบันทึกและจัดการสต็อกบอร์ด AOI</span>
            <span class="inline-block w-1 h-1 rounded-full bg-slate-300 shrink-0"></span>
            <span id="header-zone-badge" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 font-medium text-[11px] shrink-0 whitespace-nowrap">
              <i class="fa-solid fa-layer-group text-[10px]" aria-hidden="true"></i>
              <span id="header-zone-text">โซน: <?= h(zoneLabel($zone)) ?></span>
            </span>
          </p>
        </div>
      </div>s

      <!-- Right: Clock & Actions Toolbar -->
      <div class="flex items-center justify-between lg:justify-end gap-2 sm:gap-3 text-xs text-slate-600 shrink-0 flex-nowrap overflow-x-auto sm:overflow-visible">
        <!-- Glass clock -->
        <div class="aoi-clock shrink-0" id="live-clock" role="timer" aria-label="เวลาปัจจุบัน">
          <span class="aoi-clock-ico" aria-hidden="true"><i class="fa-regular fa-clock"></i><span class="aoi-clock-dot"></span></span>
          <div class="aoi-clock-body">
            <span class="aoi-clock-shift"><?= h(SHIFT_LABEL) ?></span>
            <span class="aoi-clock-row">
              <span class="aoi-clock-time"><span id="clock-hm"><?= date('H:i') ?></span><span class="aoi-clock-sec" id="clock-s">:<?= date('s') ?></span></span>
              <span class="aoi-clock-date" id="clock-date"><?= date('Y-m-d') ?></span>
            </span>
          </div>
        </div>

        <!-- Buttons Toolbar (Export, Add Board, Manage, Logout) -->
        <div class="flex items-center gap-1.5 sm:gap-2 pl-2 sm:pl-3 border-l border-slate-200 shrink-0 flex-nowrap">
          <a href="<?= h(url(['zone' => $zone, 'sheet' => $sheet, 'export' => 1])) ?>" id="export-btn" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm transition whitespace-nowrap shrink-0" title="ส่งออกไฟล์ CSV ของโซนและ Shelf ที่เลือก / Export CSV for current zone & shelf">
            <i class="fa-solid fa-file-excel text-emerald-600" aria-hidden="true"></i><span>ส่งออก CSV / Export</span>
          </a>
          <button type="button" id="new-entry-btn" onclick="openAddModal()" <?= $addBtnDisabled ? 'disabled aria-disabled="true"' : 'aria-disabled="false"' ?> title="<?= h($addBtnTitle) ?>" class="inline-flex items-center gap-1.5 px-3 sm:px-3.5 py-1.5 text-xs font-semibold rounded-md bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-emerald-600 whitespace-nowrap shrink-0">
            <i class="fa-solid fa-plus" aria-hidden="true"></i><span>เพิ่มบอร์ด / Add Board</span>
          </button>

          <?php $adminBack = http_build_query(['zone' => $zone, 'sheet' => $sheet]); ?>
          <?php if ($isAdmin): ?>
          <!-- Administrator only -->
          <button type="button" id="tm-open-btn" onclick="openTopologyManager()" aria-haspopup="dialog" aria-controls="topology-manager" title="เพิ่ม แก้ไข หรือปิดใช้งานโซนและ Shelf / Add, edit or disable zones and shelves" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm transition cursor-pointer whitespace-nowrap shrink-0">
            <i class="fa-solid fa-sitemap text-emerald-600" aria-hidden="true"></i><span>จัดการโซนและ Shelf / Manage</span>
          </button>
          <?php if (!isClientIpAllowed()): ?>
          <form method="post" action="admin.php" class="inline-flex shrink-0">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"/>
            <input type="hidden" name="action" value="logout"/>
            <input type="hidden" name="zone" value="<?= h($zone) ?>"/>
            <input type="hidden" name="sheet" value="<?= h($sheet) ?>"/>
            <button type="submit" title="ออกจากระบบผู้ดูแล / Sign out of the administrator session" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm transition cursor-pointer">
              <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span class="sr-only">ออกจากระบบผู้ดูแล / Sign out</span>
            </button>
          </form>
          <?php endif; ?>
          <?php elseif ($adminConfigured): ?>
          <a href="admin.php?<?= h($adminBack) ?>" title="เข้าสู่ระบบผู้ดูแล (สำหรับจัดการโซนและ Shelf) / Administrator sign-in (zone &amp; shelf management)" class="inline-flex items-center px-2 py-1.5 text-xs rounded-md text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition shrink-0">
            <i class="fa-solid fa-user-shield" aria-hidden="true"></i><span class="sr-only">เข้าสู่ระบบผู้ดูแล / Administrator sign-in</span>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

<?php require __DIR__ . '/sheet-tabs.php'; ?>
</header>
