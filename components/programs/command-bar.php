<?php
/**
 * Single Glass Command Bar: Search (reusing index.php search.css & search-fx.js),
 * Filters, Controls (36px uniform height), Saved Views & Active Filter Chips
 */

declare(strict_types=1);

$owners = isset($pdo) ? fetchProgramOwners($pdo) : [];
?>
<!-- 2. SINGLE GLASS COMMAND BAR & FILTERS -->
<section id="filters" class="prog-command-card" aria-label="แถบควบคุมและตัวกรอง">
  <!-- Row 1: Search -> Machine / Operator / Status / Stage -> (Right) Density, Page Size, Clear Filters -->
  <div class="prog-command-row">
    <!-- Left: Search & Filter Dropdowns -->
    <div class="prog-controls-left">
      <div class="aoi-search-wrap prog-search-wrap relative">
        <!-- Glass search bar (reused from index.php: components/search.css + assets/js/components/search-fx.js) -->
        <div class="aoi-search-glass<?= $search !== '' ? ' has-value' : '' ?>">
          <span class="aoi-search-aurora" aria-hidden="true"><b class="aoi-search-sheen"></b></span>
          <span class="aoi-search-icon" aria-hidden="true">
            <i class="fa-solid fa-magnifying-glass"></i>
            <svg class="aoi-search-ring" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10"/></svg>
          </span>
          <input type="text"
                 name="q"
                 id="barcode-search-input"
                 value="<?= h($search) ?>"
                 autocomplete="off"
                 spellcheck="false"
                 enterkeyhint="search"
                 aria-label="ค้นหา Product, Shelf หรือผู้ทำ"
                 placeholder="ค้นหา Product / Shelf / ผู้ทำ…"/>
          <span class="aoi-search-count" aria-live="polite" hidden><b>0</b><em>พบ</em></span>
          <button type="button" class="aoi-search-clear" aria-label="ล้างคำค้นหา" title="ล้างคำค้นหา (Esc)" tabindex="-1">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
          </button>
          <kbd class="aoi-search-kbd" aria-hidden="true" title="กด / เพื่อค้นหา">/</kbd>
          <span class="aoi-search-bar" aria-hidden="true"></span>
        </div>
      </div>

      <!-- Machine Select -->
      <select id="prog-filter-machine" class="prog-select" aria-label="กรองตามเครื่อง">
        <option value="ALL">เครื่อง: ทั้งหมด</option>
        <?php foreach (PROGRAM_MACHINES as $m): ?>
          <option value="<?= h($m) ?>" <?= $machineF === $m ? 'selected' : '' ?>><?= h($m) ?></option>
        <?php endforeach; ?>
      </select>

      <!-- Operator Select -->
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
      <select id="prog-filter-stage" class="prog-select" aria-label="กรองตามขั้นที่ค้าง">
        <option value="ALL">ขั้นที่ค้าง: ทั้งหมด</option>
        <option value="1" <?= $stageF === '1' ? 'selected' : '' ?>>ค้างขั้น 1: Capture P/D</option>
        <option value="2" <?= $stageF === '2' ? 'selected' : '' ?>>ค้างขั้น 2: Make Good/Sheet</option>
        <option value="3" <?= $stageF === '3' ? 'selected' : '' ?>>ค้างขั้น 3: Master G+D</option>
        <option value="4" <?= $stageF === '4' ? 'selected' : '' ?>>ค้างขั้น 4: Run Test</option>
      </select>
    </div>

    <!-- Right: Row Height (Density), Rows per Page, Clear Filters -->
    <div class="prog-controls-right">
      <!-- Density Switcher -->
      <button type="button" id="prog-density-btn" class="prog-ctl-btn" title="สลับความสูงแถว (กะทัดรัด / มาตรฐาน / สบายตา)">
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

      <!-- Clear All Filters Button -->
      <button type="button" id="prog-clear-filters-btn" class="prog-ctl-btn is-danger" title="ล้างตัวกรองทั้งหมด">
        <i class="fa-solid fa-filter-circle-xmark" aria-hidden="true"></i>
        <span class="hidden md:inline">ล้างตัวกรอง</span>
      </button>
    </div>
  </div>

  <!-- Row 2: Saved Views (Left-aligned) -->
  <div class="prog-saved-views-row">
    <span class="prog-saved-views-label"><i class="fa-regular fa-bookmark mr-1" aria-hidden="true"></i>มุมมองด่วน:</span>
    <button type="button" class="prog-view-pill is-active" data-saved-view="all">
      <span>ทั้งหมด</span>
    </button>
    <button type="button" class="prog-view-pill" data-saved-view="overdue">
      <i class="fa-solid fa-clock-rotate-left text-[10px] text-red-500" aria-hidden="true"></i>
      <span>ค้างนาน &gt; 14 วัน</span>
    </button>
    <button type="button" class="prog-view-pill" data-saved-view="unassigned">
      <i class="fa-solid fa-user-plus text-[10px]" aria-hidden="true"></i>
      <span>ยังไม่มอบหมาย</span>
    </button>
    <button type="button" class="prog-view-pill" data-saved-view="complete">
      <i class="fa-solid fa-check text-[10px] text-emerald-600" aria-hidden="true"></i>
      <span>เสร็จสมบูรณ์</span>
    </button>
  </div>

  <!-- Active Filter Chips Row (Shown only when filters are active) -->
  <div id="prog-active-chips-container" class="prog-active-chips-row" style="display: none;">
    <span class="prog-active-chips-label">ตัวกรองที่เลือก:</span>
    <div id="prog-chips-list" class="prog-chips-list">
      <!-- Injected by JS -->
    </div>
    <button type="button" id="prog-chips-clear-all" class="prog-chip-clear-all">ล้างตัวกรองทั้งหมด</button>
  </div>
</section>
