<?php
/**
 * Shelf Rack Physical Map for one sheet ($modalSheet) + "Magazine is Max" banner.
 * JS: assets/js/components/rack-map.js (re-renders after AJAX)
 */
?>
  <!-- Rack map (shown when zone has sheets and a specific sheet tab is active) -->
  <section id="rack-map" class="mt-3 bg-slate-50 p-4 rounded-lg border border-slate-300 border-t-4 border-t-blue-600 shadow-md<?= (!$hasZoneSheets || $isAllSheets) ? ' hidden' : '' ?>">
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 mb-3 border-b-2 pb-2 border-slate-200">
      <div class="flex items-center gap-2">
        <i class="fa-solid fa-cubes-stacked text-blue-600 text-sm"></i>
        <h2 id="rack-header-title" class="text-xs font-bold uppercase tracking-wider text-slate-700">Shelf Rack Physical Map: Rack <?= h($sp) ?> (ตารางชั้นวางจริงของ Magazine)</h2>
        <span id="rack-header-counter" class="text-[11px] font-mono text-slate-500 bg-white border border-slate-200 rounded px-1.5 py-0.5"><?= $modalUsedShelves ?>/<?= $modalCapacity ?></span>
      </div>
      <div class="flex items-center gap-3 text-[11px] text-slate-500">
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-emerald-600"></span> Good (G)</span>
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-red-600"></span> Defect (D)</span>
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-amber-400"></span> G+D</span>
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-sky-500"></span> Bare</span>
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-violet-600"></span> Bad Mark</span>
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-slate-100 border border-slate-300"></span> Empty</span>
      </div>
    </div>

    <?php if ($isMax): ?>
      <div id="rack-max-banner" class="no-print mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 flex flex-wrap items-center justify-between gap-2 text-xs">
        <span class="font-bold text-red-700 flex items-center gap-1.5">
          <i class="fa-solid fa-triangle-exclamation"></i> Magazine is Max
          <span class="font-normal text-red-600">— ชั้นวาง Shelf <?= h($sp) ?>1-<?= h($sp . $capacity) ?> เต็มทั้งหมดแล้ว กรุณาเพิ่ม Magazine เพื่อเปิดชั้นวางใหม่</span>
        </span>
        <?php if ($canAddMagazine): ?>
          <div class="flex items-center gap-2">
            <button type="button" id="add-mag-btn" onclick="toggleAddMag(true)"
                    class="px-3 py-1 rounded-md bg-white border border-red-300 text-red-700 font-semibold hover:bg-red-100 cursor-pointer">
              <i class="fa-solid fa-plus mr-1"></i>เพิ่ม Magazine / Add Magazine
            </button>
            <form id="add-mag-confirm" onsubmit="handleAddMagazine(event)" class="hidden items-center gap-2">
              <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"/>
              <input type="hidden" name="action" value="add_magazine"/>
              <input type="hidden" name="zone" value="<?= h($zone) ?>"/>
              <input type="hidden" name="magazines" value="<?= $magCount ?>"/>
              <span class="text-slate-700">เพิ่ม <b>Magazine <?= ordinal($magCount + 1) ?></b> (Shelf <?= $nextRange[0] ?>-<?= $nextRange[1] ?>) อีก <?= MAGAZINE_SIZE ?> Shelf ?</span>
              <button type="submit" class="px-3 py-1 rounded-md bg-red-600 text-white font-semibold hover:bg-red-700 cursor-pointer">ยืนยัน / Confirm</button>
              <button type="button" onclick="toggleAddMag(false)" class="px-3 py-1 rounded-md bg-white border border-slate-300 text-slate-600 hover:bg-slate-100 cursor-pointer">ยกเลิก / Cancel</button>
            </form>
          </div>
        <?php elseif (!sheetIsActive($modalSheet)): ?>
          <span class="text-slate-600"><?= h(INACTIVE_SHEET_MAGAZINE_ERROR) ?></span>
        <?php else: ?>
          <span class="text-slate-600">ครบ <?= MAGAZINE_LIMIT ?> Magazine (สูงสุด) แล้ว</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div id="rack-cards-container" class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(300px, 400px));">
      <?php for ($mg = 1; $mg <= $magCount; $mg++):
          [$from, $to] = magazineRange($mg);
          $mgUsed = 0;
          for ($n = $from; $n <= $to; $n++) if (isset($rack[$n])) $mgUsed++;
          $mgFull = ($mgUsed >= MAGAZINE_SIZE); ?>
        <div data-mag="<?= $mg ?>" class="rack-card bg-white rounded-md border <?= $mgFull ? 'border-red-200' : 'border-slate-200' ?> p-2.5 shadow-sm">
          <div class="flex items-center justify-between mb-2">
            <h3 class="text-[11px] font-bold text-slate-700">Magazine <?= ordinal($mg) ?> = <?= h($sp . $from) ?>-<?= h($sp . $to) ?></h3>
            <span class="text-[10px] font-mono <?= $mgFull ? 'text-red-600 font-bold' : 'text-slate-500' ?>"><?= $mgUsed ?>/<?= MAGAZINE_SIZE ?><?= $mgFull ? ' · Full' : '' ?></span>
          </div>
          <div class="grid grid-cols-10 gap-1 font-mono text-center">
            <?php for ($n = $from; $n <= $to; $n++):
                if (isset($rack[$n])): $i = $rackInfo[$n];
                    $cellKey = in_array($i['pcs_status'], OTHER_NO_CLASS, true) ? $i['pcs_status'] : $i['status'];
                    $tip = $sp . $n . ' · ' . $i['code'] . ' · ' . $i['product'] . ' · ' . (STATUS_STYLE[$cellKey][2] ?? $cellKey); ?>
              <button type="button" data-action="edit" data-id="<?= (int)$i['id'] ?>" title="<?= h($tip) ?>"
                 class="h-7 flex items-center justify-center rounded text-[11px] font-semibold <?= $cellCls[$cellKey] ?? 'bg-slate-500 text-white' ?> hover:ring-2 hover:ring-blue-400 hover:ring-offset-1 cursor-pointer transition"><?= h($sp . $n) ?></button>
            <?php else: ?>
              <button type="button" data-shelf="<?= h($sp . $n) ?>" title="<?= h($sp . $n) ?> · Empty"
                      class="h-7 flex items-center justify-center rounded text-[11px] bg-slate-100 text-slate-400 border border-slate-200 hover:border-blue-300 hover:text-blue-500 cursor-pointer transition"><?= h($sp . $n) ?></button>
            <?php endif; endfor; ?>
          </div>
        </div>
      <?php endfor; ?>
    </div>
    <div id="rack-pager" class="no-print mt-3 hidden" data-per-page="<?= RACK_PER_PAGE ?>" data-total="<?= $magCount ?>"></div>
  </section>
