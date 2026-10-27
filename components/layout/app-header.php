<?php
/**
 * Shared App Header: Logo, 2 Navigation Tabs (Master Board & AOI Program), Live Clock, Actions, User Selector
 * Replaces and extends components/header.php while keeping 100% backward compatibility.
 */

declare(strict_types=1);

$currentPage = $currentPage ?? 'master'; // 'master' or 'programs'
$currentZone = $zone ?? DEFAULT_ZONE;
$currentSheet = $sheet ?? 'ALL';

// Master board specific buttons
$addBlocked = function_exists('insertBlockedReason') ? insertBlockedReason($currentZone, $currentSheet) : '';
$addBtnDisabled = $addBlocked !== '';
$addBtnTitle = $addBtnDisabled ? $addBlocked : 'เพิ่มข้อมูลบอร์ดลงใน Shelf ' . $currentSheet . ' / Add board record to ' . $currentSheet;
?>
<!-- ===== App Header ===== -->
<header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-sm">
  <div class="px-4 lg:px-6 py-2.5">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
      <!-- Left: Logo & Navigation Tabs -->
      <div class="flex flex-wrap items-center gap-4 sm:gap-6 min-w-0">
        <div class="flex items-center space-x-3 shrink-0">
          <img src="assets/icons/logo.png" alt="AOI System Logo" class="w-9 h-9 object-contain drop-shadow-sm select-none shrink-0">
          <div class="min-w-0">
            <h1 class="text-base sm:text-lg font-bold text-slate-900 tracking-tight leading-tight truncate">AOI Inspection &amp; Shelf Hub</h1>
            <p class="text-xs text-slate-500 flex items-center gap-2">
              <span class="truncate">ระบบจัดการบอร์ดและโปรแกรม AOI</span>
              <?php if ($currentPage === 'master' && function_exists('zoneLabel')): ?>
                <span class="inline-block w-1 h-1 rounded-full bg-slate-300 shrink-0"></span>
                <span id="header-zone-badge" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 font-medium text-[11px] shrink-0 whitespace-nowrap">
                  <i class="fa-solid fa-layer-group text-[10px]" aria-hidden="true"></i>
                  <span id="header-zone-text">โซน: <?= h(zoneLabel($currentZone)) ?></span>
                </span>
              <?php endif; ?>
            </p>
          </div>
        </div>

        <!-- 2 Main Page Navigation Tabs -->
        <nav class="flex items-center gap-1 p-1 bg-slate-100 rounded-lg text-xs font-medium" aria-label="เมนูหลัก">
          <a href="<?= $currentPage === 'master' ? '#' : 'index.php?zone=' . urlencode($currentZone) . '&sheet=' . urlencode($currentSheet) ?>"
             class="px-3 py-1.5 rounded-md transition-all flex items-center gap-1.5 <?= $currentPage === 'master' ? 'bg-white text-emerald-700 shadow-sm font-semibold' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/60' ?>">
            <i class="fa-solid fa-table-list <?= $currentPage === 'master' ? 'text-emerald-600' : 'text-slate-400' ?>"></i>
            <span>บอร์ด Master</span>
            <span class="hidden xl:inline text-[10px] text-slate-400 font-normal">/ Master Board</span>
          </a>
          <a href="<?= $currentPage === 'programs' ? '#' : 'programs.php' ?>"
             class="px-3 py-1.5 rounded-md transition-all flex items-center gap-1.5 <?= $currentPage === 'programs' ? 'bg-white text-emerald-700 shadow-sm font-semibold' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/60' ?>">
            <i class="fa-solid fa-list-check <?= $currentPage === 'programs' ? 'text-emerald-600' : 'text-slate-400' ?>"></i>
            <span>โปรแกรม AOI</span>
            <span class="hidden xl:inline text-[10px] text-slate-400 font-normal">/ Programs</span>
          </a>
        </nav>
      </div>

      <!-- Right: Clock, User selector, & Actions Toolbar -->
      <div class="flex items-center justify-between lg:justify-end gap-2 sm:gap-3 text-xs text-slate-600 shrink-0 flex-nowrap overflow-x-auto sm:overflow-visible">
        <!-- Live Clock -->
        <div class="aoi-clock shrink-0 hidden md:flex" id="live-clock" role="timer" aria-label="เวลาปัจจุบัน">
          <span class="aoi-clock-ico" aria-hidden="true"><i class="fa-regular fa-clock"></i><span class="aoi-clock-dot"></span></span>
          <div class="aoi-clock-body">
            <span class="aoi-clock-shift"><?= h(SHIFT_LABEL) ?></span>
            <span class="aoi-clock-row">
              <span class="aoi-clock-time"><span id="clock-hm"><?= date('H:i') ?></span><span class="aoi-clock-sec" id="clock-s">:<?= date('s') ?></span></span>
              <span class="aoi-clock-date" id="clock-date"><?= date('Y-m-d') ?></span>
            </span>
          </div>
        </div>

        <!-- Buttons Toolbar (Export, Add, Manage, Logout) -->
        <div class="flex items-center gap-1.5 sm:gap-2 pl-2 sm:pl-3 border-l border-slate-200 shrink-0 flex-nowrap">
          <?php if ($currentPage === 'master'): ?>
            <!-- Master Board Page Actions -->
            <a href="<?= h(url(['zone' => $currentZone, 'sheet' => $currentSheet, 'export' => 1])) ?>" id="export-btn" aria-haspopup="dialog" aria-controls="export-station" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm transition whitespace-nowrap shrink-0" title="เปิด Export Station เพื่อกรองและส่งออกไฟล์ Excel (.xlsx) จัดระเบียบตาราง / Open Export Station">
              <i class="fa-solid fa-file-excel text-emerald-600" aria-hidden="true"></i><span>Export Station</span>
            </a>
            <button type="button" id="import-station-btn" onclick="openImportStation(1, this)" aria-haspopup="dialog" aria-controls="import-station" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm transition cursor-pointer whitespace-nowrap shrink-0" title="ดาวน์โหลดแม่แบบ Excel พร้อม Number Code ต่อเนื่อง และนำเข้าข้อมูลบอร์ด (Import Excel) / Open Excel Import Station">
              <i class="fa-solid fa-file-import text-cyan-600" aria-hidden="true"></i><span>Import Excel</span>
            </button>
            <button type="button" id="new-entry-btn" onclick="openAddModal()" <?= $addBtnDisabled ? 'disabled aria-disabled="true"' : 'aria-disabled="false"' ?> title="<?= h($addBtnTitle) ?>" class="inline-flex items-center gap-1.5 px-3 sm:px-3.5 py-1.5 text-xs font-semibold rounded-md bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-emerald-600 whitespace-nowrap shrink-0">
              <i class="fa-solid fa-plus" aria-hidden="true"></i><span>เพิ่มบอร์ด / Add Board</span>
            </button>
          <?php else: ?>
            <!-- Programs Page Actions (Export only; program creation is disabled on this page) -->
            <a href="<?= h(url(['export' => 1])) ?>" id="prog-export-btn" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm transition whitespace-nowrap shrink-0" title="ส่งออกรายการโปรแกรมเป็นไฟล์ CSV">
              <i class="fa-solid fa-file-excel text-emerald-600" aria-hidden="true"></i><span>ส่งออก / Export</span>
            </a>
          <?php endif; ?>

          <!-- Admin Zone & Shelf Manager button -->
          <?php
            $adminBack = http_build_query(['zone' => $currentZone, 'sheet' => $currentSheet]);
            $isAdm = isset($isAdmin) ? $isAdmin : (function_exists('isAdmin') ? isAdmin() : false);
            $admCfg = isset($adminConfigured) ? $adminConfigured : (function_exists('adminConfigured') ? adminConfigured() : false);
          ?>
          <?php if ($isAdm): ?>
            <button type="button" id="tm-open-btn" onclick="typeof openTopologyManager === 'function' ? openTopologyManager() : (window.location='index.php?manage=1')" aria-haspopup="dialog" aria-controls="topology-manager" title="เพิ่ม แก้ไข หรือปิดใช้งานโซนและ Shelf / Add, edit or disable zones and shelves" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm transition cursor-pointer whitespace-nowrap shrink-0">
              <i class="fa-solid fa-sitemap text-emerald-600" aria-hidden="true"></i><span>จัดการโซนและ Shelf</span>
            </button>
            <?php if (function_exists('isClientIpAllowed') && !isClientIpAllowed()): ?>
              <form method="post" action="admin.php" class="inline-flex shrink-0">
                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"/>
                <input type="hidden" name="action" value="logout"/>
                <input type="hidden" name="zone" value="<?= h($currentZone) ?>"/>
                <input type="hidden" name="sheet" value="<?= h($currentSheet) ?>"/>
                <button type="submit" title="ออกจากระบบผู้ดูแล / Sign out of the administrator session" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm transition cursor-pointer">
                  <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span class="sr-only">ออกจากระบบผู้ดูแล / Sign out</span>
                </button>
              </form>
            <?php endif; ?>
          <?php elseif ($admCfg): ?>
            <a href="admin.php?<?= h($adminBack) ?>" title="เข้าสู่ระบบผู้ดูแล (สำหรับจัดการโซนและ Shelf) / Administrator sign-in" class="inline-flex items-center px-2 py-1.5 text-xs rounded-md text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition shrink-0">
              <i class="fa-solid fa-user-shield" aria-hidden="true"></i><span class="sr-only">เข้าสู่ระบบผู้ดูแล</span>
            </a>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>

  <?php if ($currentPage === 'master'): ?>
    <?php require __DIR__ . '/../sheet-tabs.php'; ?>
  <?php endif; ?>
</header>
