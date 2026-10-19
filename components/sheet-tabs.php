<?php
/**
 * Sheet tabs (All Size Magazine + one per sheet). JS: assets/js/components/sheet-tabs.js
 */
?>
  <!-- Sheet tabs (AJAX controlled) -->
  <nav id="sheet-tabs" class="bg-slate-100/90 border-t border-slate-200 px-4 lg:px-8 flex items-center overflow-x-auto py-1 gap-1">
    <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mr-2 shrink-0 flex items-center gap-1">
      <i class="fa-solid fa-table-cells text-slate-400"></i> Sheets:
    </span>
    <?php
      $allActive = ($sheet === 'ALL');
      $allHref   = '?' . http_build_query(['sheet' => 'ALL']);
    ?>
    <a href="<?= h($allHref) ?>" data-sheet="ALL" id="tab-ALL" class="<?= $allActive ? 'px-4 py-1 rounded text-xs font-bold bg-white text-emerald-800 shadow-sm border-t-2 border-t-emerald-600 border-x border-b border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer' : 'px-3.5 py-1 rounded text-xs font-medium bg-transparent text-slate-600 hover:bg-white border border-transparent hover:border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer' ?>">
      <span class="tab-dot w-2.5 h-2.5 rounded-sm inline-block bg-blue-600"></span>
      <span class="tab-label">ทุกขนาด (All Size Magazine)</span>
      <span class="tab-count bg-emerald-100 text-emerald-800 text-[10px] px-1.5 rounded font-mono <?= $allZonesCount === 0 ? 'opacity-50' : '' ?>"><?= $allZonesCount ?></span>
    </a>
    <?php foreach (SHEETS as $key => [$label, $dot]):
        $active = ($key === $sheet);
        $href   = '?' . http_build_query(['sheet' => $key]);
        $n      = $tabCounts[$key] ?? 0; ?>
      <a href="<?= h($href) ?>" data-sheet="<?= h($key) ?>" id="tab-<?= h($key) ?>" class="<?= $active ? 'px-4 py-1 rounded text-xs font-bold bg-white text-emerald-800 shadow-sm border-t-2 border-t-emerald-600 border-x border-b border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer' : 'px-3.5 py-1 rounded text-xs font-medium bg-transparent text-slate-600 hover:bg-white border border-transparent hover:border-slate-300 transition shrink-0 flex items-center gap-1.5 cursor-pointer' ?>">
        <span class="tab-dot w-2.5 h-2.5 rounded-sm inline-block <?= $dot ?>"></span>
        <span class="tab-label"><?= h($label) ?></span>
        <span class="tab-count bg-emerald-100 text-emerald-800 text-[10px] px-1.5 rounded font-mono <?= $n === 0 ? 'opacity-50' : '' ?>"><?= $n ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
