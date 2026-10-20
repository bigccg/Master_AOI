<?php
/**
 * KPI cards (scoped to selected Zone & Sheet). JS: assets/js/components/kpi-cards.js
 */
$utilTitlePrefix = !$hasZoneSheets
    ? 'No Sheets'
    : ($isAllSheets ? 'All Sheets' : 'Rack ' . h(shelfPrefix($sheet)));
?>
  <!-- KPI -->
  <section class="grid grid-cols-2 md:grid-cols-5 gap-3.5">
    <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between">
      <div>
        <p class="text-xs font-medium text-slate-500">Total Boards (ทั้งหมด)</p>
        <p class="text-2xl font-bold text-slate-900 mt-1 font-mono" id="kpi-total"><?= $total ?></p>
        <p class="text-[11px] text-slate-400 mt-0.5" id="kpi-shelf-range"><?= h($shelfRangeText) ?></p>
      </div>
      <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-lg"><i class="fa-solid fa-layer-group"></i></div>
    </div>

    <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between">
      <div>
        <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span><p class="text-xs font-medium text-emerald-800">Master GOOD [G]</p></div>
        <p class="text-2xl font-bold text-emerald-600 mt-1 font-mono" id="kpi-good"><?= $cnt['G'] ?></p>
        <p class="text-[11px] text-slate-400 mt-0.5" id="kpi-pct-good"><?= $pctG ?>% of section</p>
      </div>
      <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg"><i class="fa-solid fa-circle-check"></i></div>
    </div>

    <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between">
      <div>
        <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-red-500"></span><p class="text-xs font-medium text-red-800">Master Defect [D]</p></div>
        <p class="text-2xl font-bold text-red-600 mt-1 font-mono" id="kpi-defect"><?= $cnt['D'] ?></p>
        <p class="text-[11px] text-slate-400 mt-0.5">Defect Golden Sample</p>
      </div>
      <div class="w-10 h-10 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-lg"><i class="fa-solid fa-triangle-exclamation"></i></div>
    </div>

    <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between">
      <div>
        <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span><p class="text-xs font-medium text-amber-800">Mixed [G+D]</p></div>
        <p class="text-2xl font-bold text-amber-600 mt-1 font-mono" id="kpi-mixed"><?= $cnt['GD'] ?></p>
        <p class="text-[11px] text-slate-400 mt-0.5">Combination Sheets</p>
      </div>
      <div class="w-10 h-10 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-lg"><i class="fa-solid fa-cubes"></i></div>
    </div>

    <div class="bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between col-span-2 md:col-span-1">
      <div>
        <p class="text-xs font-medium text-slate-500" id="kpi-util-title"><?= $utilTitlePrefix ?> Utilization <span class="text-slate-400">(<?= $usedShelves ?>/<?= $capacity ?>)</span></p>
        <p class="text-2xl font-bold text-slate-800 mt-1 font-mono" id="kpi-util-text"><?= $util ?>%</p>
        <div class="w-28 bg-slate-200 rounded-full h-1.5 mt-1.5 overflow-hidden">
          <div id="kpi-util-bar" class="bg-blue-600 h-1.5 rounded-full transition-all duration-300" style="width: <?= $util ?>%"></div>
        </div>
      </div>
      <div class="w-10 h-10 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center text-lg"><i class="fa-solid fa-warehouse"></i></div>
    </div>
  </section>
