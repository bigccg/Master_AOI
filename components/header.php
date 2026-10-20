<?php
/**
 * Top bar: logo, live clock, Export, New Board Entry + Zone selector & sheet tabs.
 * JS: assets/js/components/clock.js, sheet-tabs.js · CSS: components/clock.css, theme/hyprland.css
 */
$addBtnDisabled = !$hasZoneSheets || $isAllSheets;
$addBtnTitle    = !$hasZoneSheets
    ? EMPTY_ZONE_INSERT_ERROR
    : ($isAllSheets ? ALL_SIZE_INSERT_ERROR : 'เพิ่มข้อมูลบอร์ดลงในชีต ' . $sheet . ' / Add board record to ' . $sheet);
?>
<!-- ===== Header ===== -->
<header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm">
  <div class="px-4 lg:px-8 py-3">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div class="flex items-center space-x-3.5">
        <img src="assets/icons/logo.png" alt="AOI System Logo" class="w-10 h-10 object-contain drop-shadow-sm select-none">
        <div>
          <div class="flex items-center gap-2">
            <h1 class="text-lg font-bold text-slate-900 tracking-tight leading-tight">AOI Inspection & Shelf Management System</h1>
          </div>
          <p class="text-xs text-slate-500 flex items-center gap-2">
            <span>ระบบบันทึกและจัดการสต็อกบอร์ด AOI</span>
            <span class="inline-block w-1 h-1 rounded-full bg-slate-300"></span>
            <span id="header-zone-badge" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 font-medium text-[11px]">
              <i class="fa-solid fa-layer-group text-[10px]" aria-hidden="true"></i>
              <span id="header-zone-text">โซน: <?= h(ZONE_META[$zone]['label'] ?? $zone) ?></span>
            </span>
          </p>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-3 md:gap-5 text-xs text-slate-600">
        <!-- Glass clock (styles: components/clock.css; ticked by updateClock() in assets/js/components/clock.js) -->
        <div class="aoi-clock" id="live-clock" role="timer" aria-label="เวลาปัจจุบัน">
          <span class="aoi-clock-ico" aria-hidden="true"><i class="fa-regular fa-clock"></i><span class="aoi-clock-dot"></span></span>
          <div class="aoi-clock-body">
            <span class="aoi-clock-shift"><?= h(SHIFT_LABEL) ?></span>
            <span class="aoi-clock-row">
              <span class="aoi-clock-time"><span id="clock-hm"><?= date('H:i') ?></span><span class="aoi-clock-sec" id="clock-s">:<?= date('s') ?></span></span>
              <span class="aoi-clock-date" id="clock-date"><?= date('Y-m-d') ?></span>
            </span>
          </div>
        </div>
        <div class="flex items-center gap-2 pl-2 border-l border-slate-200">
          <a href="<?= h(url(['zone' => $zone, 'sheet' => $sheet, 'export' => 1])) ?>" id="export-btn" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm transition" title="ส่งออกไฟล์ CSV ของโซนและชีตที่เลือก / Export CSV for current zone & sheet">
            <i class="fa-solid fa-file-excel text-emerald-600" aria-hidden="true"></i><span>ส่งออก CSV / Export</span>
          </a>
          <button type="button" id="new-entry-btn" onclick="openAddModal()" <?= $addBtnDisabled ? 'disabled aria-disabled="true"' : 'aria-disabled="false"' ?> title="<?= h($addBtnTitle) ?>" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-semibold rounded-md bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-emerald-600">
            <i class="fa-solid fa-plus" aria-hidden="true"></i><span>เพิ่มบอร์ด / Add Board</span>
          </button>
        </div>
      </div>
    </div>
  </div>

<?php require __DIR__ . '/sheet-tabs.php'; ?>
</header>
