<?php
/**
 * Zone selector + Shelf selector (dropdowns).
 * JS: assets/js/components/sheet-tabs.js · CSS: components/glass-select.css, components/filters.css, theme/hyprland.css
 */
?>
  <!-- Zone selector & Shelf selector (AJAX controlled) -->
  <nav id="sheet-tabs" aria-label="การนำทางโซนและ Shelf / Zone and Shelf Navigation" class="bg-slate-100/90 border-t border-slate-200 px-4 lg:px-8 flex flex-wrap sm:flex-nowrap items-center overflow-x-auto py-1.5 gap-2">
    <!-- Zone Selector -->
    <div class="aoi-zone-group flex items-center gap-1.5 pr-2.5 mr-1 border-r border-slate-300 shrink-0">
      <label for="zone-select" id="zone-select-label" class="text-[11px] font-semibold text-slate-600 uppercase tracking-wider flex items-center gap-1 cursor-pointer whitespace-nowrap" title="สลับโซนเพื่อกรอง Shelf ตาราง และสรุปผลเฉพาะโซนที่เลือก / Switch zone to filter shelves, records, and metrics">
        <i class="fa-solid fa-layer-group text-emerald-600" aria-hidden="true"></i>
        <span>โซน / Zone:</span>
      </label>
      <select id="zone-select" name="zone"
              aria-labelledby="zone-select-label"
              aria-label="เลือกโซนการผลิต / Select Production Zone"
              aria-controls="table-wrapper defect-matrix rack-map"
              title="สลับโซนเพื่อกรอง Shelf ตาราง และสรุปผลเฉพาะโซนที่เลือก / Switch zone to filter shelves, records, and metrics"
              class="bg-white border border-slate-300 text-slate-900 text-xs rounded-md py-1 px-2.5 pr-7 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 cursor-pointer font-semibold">
        <?php foreach (selectableZones($zone) as $zKey): ?>
          <option value="<?= h($zKey) ?>" <?= $zone === $zKey ? 'selected' : '' ?>><?= h(zoneOptionLabel($zKey)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Shelf Selector (Dropdown like Zone) -->
    <div class="aoi-sheet-group flex items-center gap-1.5 pr-2.5 mr-1 border-r border-slate-300 shrink-0">
      <label for="sheet-select" id="sheet-select-label" class="text-[11px] font-semibold text-slate-600 uppercase tracking-wider flex items-center gap-1 cursor-pointer whitespace-nowrap" title="สลับ Shelf เพื่อกรองตาราง และสรุปผลเฉพาะ Shelf ที่เลือก / Switch shelf to filter records and metrics">
        <i class="fa-solid fa-table-cells text-emerald-600" aria-hidden="true"></i>
        <span>Shelf / ชั้นวาง:</span>
      </label>
      <select id="sheet-select" name="sheet"
              aria-labelledby="sheet-select-label"
              aria-label="เลือก Shelf / Select Shelf"
              aria-controls="table-wrapper defect-matrix rack-map"
              title="สลับ Shelf เพื่อกรองตาราง และสรุปผลเฉพาะ Shelf ที่เลือก / Switch shelf to filter records and metrics"
              class="bg-white border border-slate-300 text-slate-900 text-xs rounded-md py-1 px-2.5 pr-7 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500 cursor-pointer font-semibold"
              <?= !$hasZoneSheets ? 'disabled' : '' ?>>
        <?php if (!$hasZoneSheets): ?>
          <option value="ALL" id="tab-ALL">ไม่มี Shelf ในโซนนี้ / No shelves</option>
        <?php else: ?>
          <option value="ALL" id="tab-ALL" <?= $sheet === 'ALL' ? 'selected' : '' ?>>ทุกขนาด (All Size Magazine) (<?= $allSheetsCount ?>)</option>
          <?php foreach ($currentZoneSheets as $key => [$label, $dot]):
              $n = $tabCounts[$key] ?? 0; ?>
            <option value="<?= h($key) ?>" id="tab-<?= h($key) ?>" <?= $sheet === $key ? 'selected' : '' ?>><?= h($label) ?> (<?= $n ?>)</option>
          <?php endforeach; ?>
          <?php if (!$isSheetActive && $sheet !== 'ALL'): ?>
            <option value="<?= h($sheet) ?>" id="tab-<?= h($sheet) ?>" selected><?= h($sheet) ?> (ปิดใช้งาน / Disabled) (<?= (int)($sheetCounts[$sheet] ?? 0) ?>)</option>
          <?php endif; ?>
        <?php endif; ?>
      </select>
    </div>

    <!-- Disabled zone / sheet notes: rendered again by renderSheetTabNotes() in sheet-tabs.js -->
    <div id="sheet-tabs-notes" class="flex items-center gap-1 shrink-0">
      <?php if ($hasZoneSheets && !zoneIsActive($zone)): ?>
        <span role="status" title="ผู้ดูแลระบบปิดใช้งานโซนนี้ จึงไม่แสดงในตัวเลือกโซนและเพิ่มข้อมูลใหม่ไม่ได้ ข้อมูลเดิมยังดูและส่งออกได้ / An administrator disabled this zone: it is hidden from the zone selector and takes no new records. Existing records can still be viewed and exported." class="px-3 py-1 rounded text-xs font-medium bg-amber-50 text-amber-800 border border-amber-300 flex items-center gap-1.5 whitespace-nowrap">
          <i class="fa-solid fa-eye" aria-hidden="true"></i><span>โซนนี้ถูกปิดใช้งาน · ดูได้อย่างเดียว / Zone disabled · view only</span>
        </span>
      <?php elseif ($hasZoneSheets && !$currentZoneSheets): ?>
        <span role="status" title="<?= h(NO_ACTIVE_SHEET_INSERT_ERROR) ?>" class="px-3 py-1 rounded text-xs font-medium bg-amber-50 text-amber-800 border border-amber-300 flex items-center gap-1.5 whitespace-nowrap">
          <i class="fa-solid fa-circle-info text-amber-600" aria-hidden="true"></i><span>ยังไม่มี Shelf ที่เปิดใช้งาน / No active shelves</span>
        </span>
      <?php endif; ?>
      <?php if (!$isSheetActive): ?>
        <span id="sheet-disabled-pill" role="status" title="Shelf นี้ไม่แสดงในตัวเลือกและเพิ่มข้อมูลใหม่ไม่ได้ ข้อมูลเดิมยังดู แก้ไข และส่งออกได้ / This shelf takes no new records; its existing records can still be viewed, edited and exported." class="px-3 py-1 rounded text-xs font-medium bg-amber-50 text-amber-800 border border-amber-300 flex items-center gap-1.5 whitespace-nowrap">
          <i class="fa-solid fa-eye" aria-hidden="true"></i>
          <span>Shelf <?= h($sheet) ?> ถูกปิดใช้งาน · ดูได้อย่างเดียว / Shelf <?= h($sheet) ?> disabled · view only</span>
          <span class="tab-count bg-amber-100 text-amber-800 text-[10px] px-1.5 rounded font-mono"><?= (int)($sheetCounts[$sheet] ?? 0) ?></span>
        </span>
      <?php endif; ?>
    </div>
  </nav>
