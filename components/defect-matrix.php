<?php
/**
 * Defect Matrix, shown on All Size Magazine within the selected zone (rows rendered by assets/js/components/defect-matrix.js).
 * CSS: components/defect-matrix.css
 */
?>
  <!-- Defect Matrix (shown when zone has sheets and Sheets = ALL / All Size Magazine) -->
  <section id="defect-matrix" class="mt-3 bg-slate-50 p-4 rounded-lg border border-slate-300 border-t-4 border-t-blue-600 shadow-md<?= ($hasZoneSheets && $isAllSheets) ? '' : ' hidden' ?>">
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 mb-3 border-b-2 pb-2 border-slate-200">
      <div class="flex items-center gap-2">
        <i class="fa-solid fa-table-cells text-blue-600 text-sm"></i>
        <h2 id="defect-matrix-title" class="text-xs font-bold uppercase tracking-wider text-slate-700">Defect Matrix: All Size Magazines — โซน <?= h($zone) ?> (ตารางข้อบกพร่องทุกชีตในโซน)</h2>
        <span id="defect-matrix-counter" class="text-[11px] font-mono text-slate-500 bg-white border border-slate-200 rounded px-1.5 py-0.5"><?= $cnt['D'] + $cnt['GD'] ?> rows</span>
      </div>
      <div class="flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-red-600"></span> Defect (D)</span>
        <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-amber-400"></span> Mixed (G+D)</span>
        <span class="flex items-center gap-1"><i class="fa-solid fa-circle-check text-red-600"></i> มี Defect</span>
        <span class="flex items-center gap-1"><i class="fa-solid fa-comment-dots text-rose-600"></i> Other (ชี้ดูรายละเอียด)</span>
      </div>
    </div>

    <div id="defect-matrix-wrapper" class="bg-white rounded-md border border-slate-300 shadow-sm overflow-hidden" aria-busy="true"></div>

    <div id="defect-matrix-legend" class="mt-2.5 px-3 py-2 rounded-md bg-white border border-slate-200 text-[11px] text-slate-600 flex flex-wrap items-center gap-x-1.5 gap-y-1 leading-relaxed">
      <span class="font-bold text-slate-700 uppercase tracking-wider mr-1"><i class="fa-solid fa-circle-info text-blue-600 mr-1"></i>Legend:</span>
      <?php
        $legendItems = [];
        foreach (DEFECT_LABELS as $code => $label) {
            $legendItems[] = '<span class="dm-legend-item inline-flex items-center gap-1 px-1 py-0.5 rounded transition" data-legend-code="' . (int)$code . '"><b class="font-mono font-bold text-slate-800">' . (int)$code . '</b><span>= ' . h($label) . '</span></span>';
        }
        $legendItems[] = '<span class="dm-legend-item inline-flex items-center gap-1 px-1 py-0.5 rounded transition" data-legend-code="OTHER"><b class="font-mono font-bold text-slate-800">Other</b><span>= ข้อบกพร่องอื่นๆ</span></span>';
        echo implode('<span class="text-slate-300 select-none">·</span>', $legendItems);
      ?>
    </div>
  </section>
