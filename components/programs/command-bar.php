<?php
/**
 * Command Bar: Search, Filters, Saved Views, Active Chips & View Controls
 */

declare(strict_types=1);

$owners = isset($pdo) ? fetchProgramOwners($pdo) : [];
?>
<!-- 2. COMMAND BAR & FILTERS -->
<section class="prog-command-card" aria-label="แถบควบคุมและตัวกรอง">
  <!-- Top Control Row -->
  <div class="prog-command-row">
    <!-- Left: Search & Filter Dropdowns -->
    <div class="prog-controls-left">
      <!-- Glass Search Bar -->
      <div class="prog-search-box">
        <i class="fa-solid fa-magnifying-glass prog-search-icon" aria-hidden="true"></i>
        <input type="text"
               id="prog-search-input"
               class="prog-search-input"
               placeholder="ค้นหา Product / Family / Shelf / ผู้ทำ…"
               value="<?= h($search) ?>"
               autocomplete="off"
               spellcheck="false"
               aria-label="ค้นหาโปรแกรม AOI">
        <div class="prog-search-trailing">
          <span class="prog-search-badge" id="prog-search-count" style="display: <?= $search !== '' ? 'inline-block' : 'none' ?>;">0</span>
          <button type="button" id="prog-search-clear" class="prog-search-clear <?= $search !== '' ? 'is-active' : '' ?>" title="ล้างการค้นหา (Esc)">
            <i class="fa-solid fa-xmark"></i>
          </button>
          <kbd class="prog-search-kbd" title="กดปุ่ม / เพื่อโฟกัสช่องค้นหา">/</kbd>
        </div>
      </div>

      <!-- Machine Select -->
      <select id="prog-filter-machine" class="prog-select" aria-label="กรองตามเครื่อง">
        <option value="ALL">เครื่อง: ทั้งหมด</option>
        <?php foreach (PROGRAM_MACHINES as $m): ?>
          <option value="<?= h($m) ?>" <?= $machineF === $m ? 'selected' : '' ?>><?= h($m) ?></option>
        <?php endforeach; ?>
      </select>

      <!-- Owner Select -->
      <select id="prog-filter-owner" class="prog-select" aria-label="กรองตามผู้ทำ">
        <option value="ALL">ผู้ทำ: ทั้งหมด</option>
        <option value="unassigned" <?= $ownerF === 'unassigned' ? 'selected' : '' ?>>-- ยังไม่มอบหมาย --</option>
        <?php foreach ($owners as $o): ?>
          <option value="<?= h($o) ?>" <?= $ownerF === $o ? 'selected' : '' ?>><?= h($o) ?></option>
        <?php endforeach; ?>
      </select>

      <!-- Status Select -->
      <select id="prog-filter-status" class="prog-select" aria-label="กรองตามสถานะ">
        <option value="ALL">สถานะ: ทั้งหมด</option>
        <option value="complete" <?= $statusF === 'complete' ? 'selected' : '' ?>>เสร็จสมบูรณ์ / Complete</option>
        <option value="wait_master" <?= $statusF === 'wait_master' ? 'selected' : '' ?>>รอ Master / Wait Master</option>
        <option value="queued" <?= $statusF === 'queued' ? 'selected' : '' ?>>รอทำโปรแกรม / Queued</option>
        <option value="overdue" <?= $statusF === 'overdue' ? 'selected' : '' ?>>ค้างนาน &gt; 14 วัน / Overdue</option>
      </select>

      <!-- Stage Stuck Select -->
      <select id="prog-filter-stage" class="prog-select" aria-label="กรองตามขั้นตอนที่ค้าง">
        <option value="ALL">ขั้นที่ค้าง: ทั้งหมด</option>
        <option value="1" <?= $stageF === '1' ? 'selected' : '' ?>>ค้างขั้น 1: Capture P/D</option>
        <option value="2" <?= $stageF === '2' ? 'selected' : '' ?>>ค้างขั้น 2: Make Good/Sheet</option>
        <option value="3" <?= $stageF === '3' ? 'selected' : '' ?>>ค้างขั้น 3: Master G+D</option>
        <option value="4" <?= $stageF === '4' ? 'selected' : '' ?>>ค้างขั้น 4: Run Test</option>
      </select>
    </div>

    <!-- Right: View Switcher, Density, Page Size, Refresh -->
    <div class="prog-controls-right">
      <!-- View Switcher -->
      <div class="prog-view-switcher" role="tablist" aria-label="เลือกมุมมอง">
        <button type="button" class="prog-view-btn <?= $viewMode === 'table' ? 'is-active' : '' ?>" data-view="table" role="tab" aria-selected="<?= $viewMode === 'table' ? 'true' : 'false' ?>" title="มุมมองตาราง">
          <i class="fa-solid fa-table-list" aria-hidden="true"></i>
          <span>ตาราง</span>
        </button>
        <button type="button" class="prog-view-btn <?= $viewMode === 'pipeline' ? 'is-active' : '' ?>" data-view="pipeline" role="tab" aria-selected="<?= $viewMode === 'pipeline' ? 'true' : 'false' ?>" title="มุมมอง Pipeline (Kanban)">
          <i class="fa-solid fa-table-columns" aria-hidden="true"></i>
          <span class="hidden sm:inline">Pipeline</span>
        </button>
        <button type="button" class="prog-view-btn <?= $viewMode === 'workload' ? 'is-active' : '' ?>" data-view="workload" role="tab" aria-selected="<?= $viewMode === 'workload' ? 'true' : 'false' ?>" title="มุมมองภาระงาน">
          <i class="fa-solid fa-chart-simple" aria-hidden="true"></i>
          <span class="hidden sm:inline">ภาระงาน</span>
        </button>
      </div>

      <!-- Density Switcher -->
      <button type="button" id="prog-density-btn" class="prog-select flex items-center gap-1.5" title="สลับความแน่นของแถว (Compact / Standard / Comfortable)">
        <i class="fa-solid fa-bars" aria-hidden="true"></i>
        <span id="prog-density-label" class="hidden sm:inline">มาตรฐาน</span>
      </button>

      <!-- Page Size -->
      <select id="prog-page-size" class="prog-select font-mono" aria-label="จำนวนแถวต่อหน้า">
        <option value="10" <?= $limit === 10 ? 'selected' : '' ?>>10 แถว</option>
        <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50 แถว</option>
        <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100 แถว</option>
        <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500 แถว</option>
        <option value="ALL" <?= $isAll ? 'selected' : '' ?>>ทั้งหมด</option>
      </select>

      <!-- Refresh Button -->
      <button type="button" id="prog-refresh-btn" class="prog-select flex items-center justify-center w-8 px-0" title="รีเฟรชข้อมูล (R)">
        <i class="fa-solid fa-arrow-rotate-right" aria-hidden="true"></i>
      </button>

      <!-- Clear All Filters Button -->
      <button type="button" id="prog-clear-filters-btn" class="h-8 px-2.5 rounded-lg text-red-600 hover:bg-red-50 text-xs font-medium flex items-center gap-1 transition-colors" title="ล้างตัวกรองทั้งหมด">
        <i class="fa-solid fa-filter-circle-xmark" aria-hidden="true"></i>
        <span class="hidden md:inline">ล้างตัวกรอง</span>
      </button>
    </div>
  </div>

  <!-- Saved Views Row (Quick Filter Pills) -->
  <div class="prog-saved-views-row">
    <span class="prog-saved-views-label"><i class="fa-regular fa-bookmark mr-1"></i>Saved Views:</span>
    <button type="button" class="prog-view-pill is-active" data-saved-view="all">
      <span>ทั้งหมด / All</span>
    </button>
    <button type="button" class="prog-view-pill" data-saved-view="my_tasks">
      <i class="fa-solid fa-user-check text-[10px]"></i>
      <span>งานของฉัน</span>
    </button>
    <button type="button" class="prog-view-pill" data-saved-view="overdue">
      <i class="fa-solid fa-clock-rotate-left text-[10px] text-red-500"></i>
      <span>ค้างนาน &gt; 14 วัน</span>
    </button>
    <button type="button" class="prog-view-pill" data-saved-view="unassigned">
      <i class="fa-solid fa-user-plus text-[10px]"></i>
      <span>ยังไม่มอบหมาย</span>
    </button>
    <button type="button" class="prog-view-pill" data-saved-view="complete">
      <i class="fa-solid fa-check text-[10px] text-emerald-600"></i>
      <span>เสร็จสมบูรณ์</span>
    </button>
  </div>

  <!-- Active Filter Chips Row (Hidden when no filter active) -->
  <div id="prog-active-chips-container" class="prog-active-chips-row" style="display: none;">
    <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Active:</span>
    <div id="prog-chips-list" class="flex flex-wrap items-center gap-1.5">
      <!-- Injected by JS -->
    </div>
    <button type="button" id="prog-chips-clear-all" class="prog-chip-clear-all">ล้างทั้งหมด</button>
  </div>

  <!-- Sticky Bulk Action Bar (Visible when selectedIds.size > 0) -->
  <div id="prog-bulk-bar" class="prog-bulk-bar" style="display: none;" role="region" aria-label="แถบจัดการข้อมูลแบบกลุ่ม">
    <div class="prog-bulk-left">
      <span class="prog-bulk-badge">
        <i class="fa-solid fa-circle-check text-emerald-600"></i>
        <span>เลือกอยู่ <strong id="prog-bulk-count" class="font-mono font-bold text-slate-900 mx-1">0</strong> รายการ</span>
      </span>
      <button type="button" id="prog-bulk-deselect-btn" class="prog-bulk-deselect" title="ยกเลิกการเลือกทั้งหมด (Esc)">
        <i class="fa-solid fa-xmark mr-1"></i>ยกเลิก
      </button>
    </div>
    <div class="prog-bulk-actions">
      <!-- Advance Next Stage -->
      <button type="button" class="prog-bulk-btn is-primary" id="prog-bulk-advance-btn" title="เลื่อนขั้นถัดไปสำหรับทุกรายการที่เลือก">
        <i class="fa-solid fa-forward-step"></i>
        <span>เลื่อนขั้นถัดไป</span>
      </button>

      <!-- Set Stage Menu / Dropdown -->
      <div class="prog-dropdown-wrap" id="prog-bulk-stage-dropdown-wrap">
        <button type="button" class="prog-bulk-btn" id="prog-bulk-stage-menu-btn" aria-haspopup="true" aria-expanded="false">
          <i class="fa-solid fa-list-check"></i>
          <span>กำหนดขั้น…</span>
          <i class="fa-solid fa-chevron-down text-[10px] ml-1"></i>
        </button>
        <div class="prog-dropdown-menu" id="prog-bulk-stage-menu">
          <div class="prog-dropdown-header">กำหนดสถานะขั้นที่เลือก</div>
          <button type="button" class="prog-dropdown-item" data-bulk-stage="1" data-bulk-status="2">✓ ขั้น 1 เสร็จสิ้น (Capture P/D)</button>
          <button type="button" class="prog-dropdown-item" data-bulk-stage="2" data-bulk-status="2">✓ ขั้น 2 เสร็จสิ้น (Make Good)</button>
          <button type="button" class="prog-dropdown-item" data-bulk-stage="3" data-bulk-status="2">✓ ขั้น 3 เสร็จสิ้น (Master G+D)</button>
          <button type="button" class="prog-dropdown-item" data-bulk-stage="4" data-bulk-status="2">✓ ขั้น 4 เสร็จสิ้น (Run Test)</button>
          <div class="prog-dropdown-divider"></div>
          <button type="button" class="prog-dropdown-item text-amber-700" data-bulk-stage="2" data-bulk-status="9">➖ ขั้น 2 เป็น N/A (ข้าม)</button>
          <button type="button" class="prog-dropdown-item text-slate-500" data-bulk-stage="1" data-bulk-status="0">↺ รีเซ็ตขั้น 1 เป็นยังไม่เริ่ม</button>
        </div>
      </div>

      <!-- Assign Owner Menu -->
      <div class="prog-dropdown-wrap" id="prog-bulk-owner-dropdown-wrap">
        <button type="button" class="prog-bulk-btn" id="prog-bulk-owner-menu-btn" aria-haspopup="true" aria-expanded="false">
          <i class="fa-solid fa-user-plus"></i>
          <span>มอบหมาย…</span>
          <i class="fa-solid fa-chevron-down text-[10px] ml-1"></i>
        </button>
        <div class="prog-dropdown-menu" id="prog-bulk-owner-menu">
          <div class="prog-dropdown-header">เลือกผู้รับผิดชอบ</div>
          <button type="button" class="prog-dropdown-item font-semibold text-emerald-700" data-bulk-owner="__ME__">
            <i class="fa-solid fa-user-check mr-1.5"></i>มอบหมายให้ฉัน (<span id="prog-bulk-me-name">Thanapol</span>)
          </button>
          <div class="prog-dropdown-divider"></div>
          <div id="prog-bulk-owner-list">
            <!-- Dynamically populated from owners -->
          </div>
          <div class="prog-dropdown-divider"></div>
          <button type="button" class="prog-dropdown-item text-red-600" data-bulk-owner="">
            <i class="fa-solid fa-user-xmark mr-1.5"></i>ยกเลิกการมอบหมาย
          </button>
        </div>
      </div>

      <!-- Export Selected CSV -->
      <button type="button" class="prog-bulk-btn" id="prog-bulk-export-btn" title="ส่งออกเฉพาะรายการที่เลือกเป็น CSV">
        <i class="fa-solid fa-file-csv"></i>
        <span>ส่งออก CSV</span>
      </button>
    </div>
  </div>
</section>
