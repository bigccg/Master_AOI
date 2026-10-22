<?php
/**
 * Filter bar: search, shelf / board type / status / other, rows per page.
 * JS: assets/js/components/filters.js, search-fx.js · CSS: components/filters.css, search.css, glass-select.css, page-size.css
 */
?>
  <!-- Filters -->
  <section id="filters" class="bg-white p-2.5 rounded-lg border border-slate-200 shadow-sm">
    <form method="get" id="filter-form" onsubmit="event.preventDefault();" class="aoi-filter-form">
      <input type="hidden" name="zone" id="filter-zone" value="<?= h($zone) ?>"/>
      <input type="hidden" name="sheet" id="filter-sheet" value="<?= h($sheet) ?>"/>
      <div class="aoi-filter-controls">
        <div class="aoi-search-wrap relative">
          <!-- Glass search bar (styles: components/search.css, effects: assets/js/components/search-fx.js) -->
          <div class="aoi-search-glass<?= $q !== '' ? ' has-value' : '' ?>">
            <span class="aoi-search-aurora" aria-hidden="true"><b class="aoi-search-sheen"></b></span>
            <span class="aoi-search-icon" aria-hidden="true">
              <i class="fa-solid fa-magnifying-glass"></i>
              <svg class="aoi-search-ring" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10"/></svg>
            </span>
            <input type="text" name="q" id="barcode-search-input" value="<?= h($q) ?>" autofocus
                   autocomplete="off" spellcheck="false" enterkeyhint="search"
                   aria-label="ค้นหา Product Model"
                   placeholder="ค้นหา Product Model…"/>
            <span class="aoi-search-count" aria-live="polite" hidden><b>0</b><em>พบ</em></span>
            <button type="button" class="aoi-search-clear" aria-label="ล้างคำค้นหา" title="ล้างคำค้นหา (Esc) / Clear search (Esc)" tabindex="-1">
              <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
            <kbd class="aoi-search-kbd" aria-hidden="true" title="กด / เพื่อค้นหา">/</kbd>
            <span class="aoi-search-bar" aria-hidden="true"></span>
          </div>
        </div>
        <select name="shelf" id="filter-shelf" aria-label="กรองตามชั้นวาง / Filter by Shelf" class="bg-slate-50 border border-slate-300 text-slate-900 text-xs rounded-md py-2 px-3 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 cursor-pointer">
          <option value="ALL">ทุกชั้นวาง</option>
          <?php for ($mg = 1; $mg <= $magCount; $mg++): ?>
            <option value="M<?= $mg ?>" <?= $shelfF === "M$mg" ? 'selected' : '' ?>><?= h(magazineTitle($mg)) ?></option>
          <?php endfor; ?>
        </select>
        <select name="period" id="filter-period" aria-label="กรองตามช่วงเวลา / Filter by Period" class="bg-slate-50 border border-slate-300 text-slate-900 text-xs rounded-md py-2 px-3 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 cursor-pointer">
          <option value="ALL">ทุกช่วงเวลา</option>
          <?php
            $nowYM = date('Y-m');
            $periodOpts = periodOptions($pdo, $zone, $isAllSheets ? 'ALL' : $sheet);
          ?>
          <option value="THIS" <?= $periodF === 'THIS' ? 'selected' : '' ?>>เดือนนี้ / This month</option>
          <?php foreach ($periodOpts as $po): ?>
            <option value="<?= h($po['value']) ?>" <?= $periodF === $po['value'] ? 'selected' : '' ?>><?= h($po['label']) ?> (<?= $po['count'] ?>)</option>
          <?php endforeach; ?>
        </select>
        <select name="board_type" id="filter-board-type" aria-label="กรองตามประเภทบอร์ด / Filter by Board Type" class="bg-slate-50 border border-slate-300 text-slate-900 text-xs rounded-md py-2 px-3 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 cursor-pointer">
          <option value="ALL">ทุกประเภทบอร์ด</option>
          <?php foreach (BOARD_TYPES as $bt): ?>
            <option value="<?= h($bt) ?>" <?= $boardTypeF === $bt ? 'selected' : '' ?>><?= h($bt) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="status" id="filter-status" aria-label="กรองตามสถานะบอร์ด / Filter by Board Status" class="bg-slate-50 border border-slate-300 text-slate-900 text-xs rounded-md py-2 px-3 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 cursor-pointer">
          <option value="ALL">ทุกสถานะ</option>
          <option value="G"  <?= $statusF === 'G'  ? 'selected' : '' ?>>Master GOOD (G)</option>
          <option value="D"  <?= $statusF === 'D'  ? 'selected' : '' ?>>Master Defect (D)</option>
          <option value="GD" <?= $statusF === 'GD' ? 'selected' : '' ?>>GOOD + DEFECT (G+D)</option>
        </select>
        <select name="other" id="filter-other" aria-label="ตัวกรองอื่นๆ / Filter by Other" class="bg-slate-50 border border-slate-300 text-slate-900 text-xs rounded-md py-2 px-3 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 cursor-pointer">
          <option value="ALL">Other: ทั้งหมด</option>
          <?php foreach (OTHER_OPTIONS as $opt): ?>
            <option value="<?= h($opt) ?>" <?= $otherF === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="aoi-filter-actions">
        <div class="page-size-group">
          <label for="page-size-select" class="font-medium text-slate-700 whitespace-nowrap">Rows per page</label>
          <select id="page-size-select" class="rounded-md border border-slate-300 text-slate-900 text-xs py-2 px-3 pr-8 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 bg-white cursor-pointer">
            <option value="10">10</option>
            <option value="50">50</option>
            <option value="100">100</option>
            <option value="500">500</option>
            <option value="all">ALL</option>
          </select>
        </div>
        <button type="button" id="refresh-table-btn" class="aoi-icon-btn border border-slate-300 rounded-md text-slate-700 hover:bg-slate-50 cursor-pointer" title="รีเฟรชข้อมูลตาราง / Refresh Table">
          <i class="fa-solid fa-arrow-rotate-right"></i>
        </button>
        <button type="button" id="clear-filters-btn" class="aoi-icon-btn border border-red-200 rounded-md text-red-600 hover:bg-red-50 cursor-pointer" title="ล้างตัวกรองทั้งหมด / Clear Filters">
          <i class="fa-solid fa-filter-circle-xmark"></i>
        </button>
      </div>
    </form>
  </section>
