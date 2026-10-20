<?php
/**
 * Zone selector + Sheet tabs (All Size Magazine within zone + one tab per sheet in zone).
 * JS: assets/js/components/sheet-tabs.js · CSS: components/glass-select.css, components/filters.css, theme/hyprland.css
 */
?>
  <!-- Zone selector & Sheet tabs (AJAX controlled) -->
  <nav id="sheet-tabs" aria-label="การนำทางโซนและชีต / Zone and Sheet Navigation" class="bg-slate-100/90 border-t border-slate-200 px-4 lg:px-8 flex flex-wrap sm:flex-nowrap items-center overflow-x-auto py-1.5 gap-2">
    <div class="aoi-zone-group flex items-center gap-1.5 pr-2.5 mr-1 border-r border-slate-300 shrink-0">
      <label for="zone-select" id="zone-select-label" class="text-[11px] font-semibold text-slate-600 uppercase tracking-wider flex items-center gap-1 cursor-pointer whitespace-nowrap" title="สลับโซนเพื่อกรองชีต ตาราง และสรุปผลเฉพาะโซนที่เลือก / Switch zone to filter sheets, records, and metrics">
        <i class="fa-solid fa-layer-group text-emerald-600" aria-hidden="true"></i>
        <span>โซน / Zone:</span>
      </label>
      <select id="zone-select" name="zone"
              aria-labelledby="zone-select-label"
              aria-label="เลือกโซนการผลิต / Select Production Zone"
              aria-controls="sheet-tabs-list table-wrapper defect-matrix rack-map"
              title="สลับโซนเพื่อกรองชีต ตาราง และสรุปผลเฉพาะโซนที่เลือก / Switch zone to filter sheets, records, and metrics"
              class="bg-white border border-slate-300 text-slate-900 text-xs rounded-md py-1 px-2.5 pr-7 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 cursor-pointer font-semibold">
        <?php foreach (selectableZones($zone) as $zKey): ?>
          <option value="<?= h($zKey) ?>" <?= $zone === $zKey ? 'selected' : '' ?>><?= h(zoneOptionLabel($zKey)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mr-1 shrink-0 flex items-center gap-1">
      <i class="fa-solid fa-table-cells text-slate-400" aria-hidden="true"></i>
      <span>ชีต / Sheets:</span>
    </span>

    <div id="sheet-tabs-list" role="tablist" aria-label="รายการชีตในโซน / Sheets in selected zone" class="flex items-center gap-1 shrink-0">
      <?php if (!$hasZoneSheets): ?>
        <span id="zone-no-sheets-pill" role="status" class="px-3 py-1 rounded text-xs font-medium bg-amber-50 text-amber-800 border border-amber-300 flex items-center gap-1.5">
          <i class="fa-solid fa-circle-info text-amber-600" aria-hidden="true"></i>
          <span>โซน <?= h($zone) ?> ยังไม่มีชีตในระบบ / No sheets configured in <?= h($zone) ?> zone</span>
        </span>
      <?php else: ?>
        <?php
          $allActive = ($sheet === 'ALL');
          $allHref   = '?' . http_build_query(['zone' => $zone, 'sheet' => 'ALL']);
        ?>
        <a href="<?= h($allHref) ?>" data-sheet="ALL" id="tab-ALL" role="tab" aria-selected="<?= $allActive ? 'true' : 'false' ?>" tabindex="<?= ($allActive || !$isSheetActive) ? '0' : '-1' ?>" title="ดูข้อมูลรวมทุกชีตภายในโซน <?= h($zone) ?> / View all sheets within <?= h($zone) ?> zone" class="<?= $allActive ? 'px-4 py-1 rounded text-xs font-bold bg-white text-emerald-800 shadow-sm border-t-2 border-t-emerald-600 border-x border-b border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer' : 'px-3.5 py-1 rounded text-xs font-medium bg-transparent text-slate-600 hover:bg-white border border-transparent hover:border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer' ?>">
          <span class="tab-dot w-2.5 h-2.5 rounded-sm inline-block bg-blue-600" aria-hidden="true"></span>
          <span class="tab-label">ทุกขนาด (All Size Magazine)</span>
          <span class="tab-count bg-emerald-100 text-emerald-800 text-[10px] px-1.5 rounded font-mono <?= $allSheetsCount === 0 ? 'opacity-50' : '' ?>"><?= $allSheetsCount ?></span>
        </a>
        <?php foreach ($currentZoneSheets as $key => [$label, $dot]):
            $active = ($key === $sheet);
            $href   = '?' . http_build_query(['zone' => $zone, 'sheet' => $key]);
            $n      = $tabCounts[$key] ?? 0; ?>
          <a href="<?= h($href) ?>" data-sheet="<?= h($key) ?>" id="tab-<?= h($key) ?>" role="tab" aria-selected="<?= $active ? 'true' : 'false' ?>" tabindex="<?= $active ? '0' : '-1' ?>" title="เลือกชีต <?= h($label) ?> (โซน <?= h($zone) ?>) / Select sheet <?= h($label) ?>" class="<?= $active ? 'px-4 py-1 rounded text-xs font-bold bg-white text-emerald-800 shadow-sm border-t-2 border-t-emerald-600 border-x border-b border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer' : 'px-3.5 py-1 rounded text-xs font-medium bg-transparent text-slate-600 hover:bg-white border border-transparent hover:border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer' ?>">
            <span class="tab-dot w-2.5 h-2.5 rounded-sm inline-block <?= h($dot) ?>" aria-hidden="true"></span>
            <span class="tab-label"><?= h($label) ?></span>
            <span class="tab-count bg-emerald-100 text-emerald-800 text-[10px] px-1.5 rounded font-mono <?= $n === 0 ? 'opacity-50' : '' ?>"><?= $n ?></span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Disabled zone / sheet notes (not tabs): rendered again by renderSheetTabNotes() in sheet-tabs.js -->
    <div id="sheet-tabs-notes" class="flex items-center gap-1 shrink-0">
      <?php if ($hasZoneSheets && !zoneIsActive($zone)): ?>
        <span role="status" title="ผู้ดูแลระบบปิดใช้งานโซนนี้ จึงไม่แสดงในตัวเลือกโซนและเพิ่มข้อมูลใหม่ไม่ได้ ข้อมูลเดิมยังดูและส่งออกได้ / An administrator disabled this zone: it is hidden from the zone selector and takes no new records. Existing records can still be viewed and exported." class="px-3 py-1 rounded text-xs font-medium bg-amber-50 text-amber-800 border border-amber-300 flex items-center gap-1.5">
          <i class="fa-solid fa-eye" aria-hidden="true"></i><span>โซนนี้ถูกปิดใช้งาน · ดูได้อย่างเดียว / Zone disabled · view only</span>
        </span>
      <?php elseif ($hasZoneSheets && !$currentZoneSheets): ?>
        <span role="status" title="<?= h(NO_ACTIVE_SHEET_INSERT_ERROR) ?>" class="px-3 py-1 rounded text-xs font-medium bg-amber-50 text-amber-800 border border-amber-300 flex items-center gap-1.5">
          <i class="fa-solid fa-circle-info text-amber-600" aria-hidden="true"></i><span>ยังไม่มีชีตที่เปิดใช้งาน / No active sheets</span>
        </span>
      <?php endif; ?>
      <?php if (!$isSheetActive): ?>
        <span id="sheet-disabled-pill" role="status" title="ชีตนี้ไม่แสดงในแท็บและเพิ่มข้อมูลใหม่ไม่ได้ ข้อมูลเดิมยังดู แก้ไข และส่งออกได้ / This sheet is hidden from the tabs and takes no new records; its existing records can still be viewed, edited and exported." class="px-3 py-1 rounded text-xs font-medium bg-amber-50 text-amber-800 border border-amber-300 flex items-center gap-1.5">
          <i class="fa-solid fa-eye" aria-hidden="true"></i>
          <span>ชีต <?= h($sheet) ?> ถูกปิดใช้งาน · ดูได้อย่างเดียว / Sheet <?= h($sheet) ?> disabled · view only</span>
          <span class="tab-count bg-amber-100 text-amber-800 text-[10px] px-1.5 rounded font-mono"><?= (int)($sheetCounts[$sheet] ?? 0) ?></span>
        </span>
      <?php endif; ?>
    </div>
  </nav>
