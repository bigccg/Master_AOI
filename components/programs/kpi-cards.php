<?php
/**
 * 5 KPI Summary Cards (display only — no click-to-filter) for AOI Programs
 * Styled to match index.php KPI cards aesthetics and visual order.
 */

declare(strict_types=1);

$kpiData = isset($pdo) ? fetchProgramKpis($pdo) : [
    'total' => 0, 'complete' => 0, 'pct_complete' => 0, 'wait_master' => 0, 'queued' => 0, 'overdue' => 0, 'omron' => 0, 'yamaha' => 0, 'tri' => 0
];
?>
<!-- 1. KPI SUMMARY CARDS — same markup/classes as components/kpi-cards.php (index.php) -->
<section class="grid grid-cols-2 md:grid-cols-5 gap-3.5" aria-label="สรุปภาพรวมสถานะโปรแกรม">
  <!-- Card 1: Total Programs -->
  <div class="prog-kpi-card bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between">
    <div>
      <p class="text-xs font-medium text-slate-500">Total Programs (ทั้งหมด)</p>
      <p class="text-2xl font-bold text-slate-900 mt-1 font-mono" id="kpi-num-total"><?= $kpiData['total'] ?></p>
      <p class="text-[11px] text-slate-400 mt-0.5 font-mono truncate max-w-[170px]" id="kpi-breakdown-mach" title="OMRON <?= $kpiData['omron'] ?> · YAMAHA <?= $kpiData['yamaha'] ?> · TRI <?= $kpiData['tri'] ?>">
        OMRON <?= $kpiData['omron'] ?> · YAMAHA <?= $kpiData['yamaha'] ?> · TRI <?= $kpiData['tri'] ?>
      </p>
    </div>
    <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-lg shrink-0">
      <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
    </div>
  </div>

  <!-- Card 2: Complete -->
  <div class="prog-kpi-card bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between">
    <div>
      <div class="flex items-center gap-1.5">
        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
        <p class="text-xs font-medium text-emerald-800">Complete (เสร็จสมบูรณ์)</p>
      </div>
      <p class="text-2xl font-bold text-emerald-600 mt-1 font-mono" id="kpi-num-complete"><?= $kpiData['complete'] ?></p>
      <p class="text-[11px] text-slate-400 mt-0.5"><span id="kpi-pct-complete"><?= $kpiData['pct_complete'] ?>%</span> of total</p>
    </div>
    <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center text-lg shrink-0">
      <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
    </div>
  </div>

  <!-- Card 3: Wait Master -->
  <div class="prog-kpi-card bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between">
    <div>
      <div class="flex items-center gap-1.5">
        <span class="w-2.5 h-2.5 rounded-full bg-amber-500 shrink-0"></span>
        <p class="text-xs font-medium text-amber-800">Wait Master (รอแผ่น)</p>
      </div>
      <p class="text-2xl font-bold text-amber-600 mt-1 font-mono" id="kpi-num-wait-master"><?= $kpiData['wait_master'] ?></p>
      <p class="text-[11px] text-slate-400 mt-0.5">รอแผ่น Master G/D</p>
    </div>
    <div class="w-10 h-10 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center text-lg shrink-0">
      <i class="fa-solid fa-cubes" aria-hidden="true"></i>
    </div>
  </div>

  <!-- Card 4: Queued -->
  <div class="prog-kpi-card bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between">
    <div>
      <div class="flex items-center gap-1.5">
        <span class="w-2.5 h-2.5 rounded-full bg-slate-400 shrink-0"></span>
        <p class="text-xs font-medium text-slate-700">Queued (รอทำโปรแกรม)</p>
      </div>
      <p class="text-2xl font-bold text-slate-800 mt-1 font-mono" id="kpi-num-queued"><?= $kpiData['queued'] ?></p>
      <p class="text-[11px] text-slate-400 mt-0.5">รอดำเนินการสร้างโปรแกรม</p>
    </div>
    <div class="w-10 h-10 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center text-lg shrink-0">
      <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
    </div>
  </div>

  <!-- Card 5: Overdue -->
  <div class="prog-kpi-card bg-white p-3.5 rounded-lg border border-slate-200 shadow-sm flex items-center justify-between col-span-2 md:col-span-1">
    <div>
      <div class="flex items-center gap-1.5">
        <span class="w-2.5 h-2.5 rounded-full bg-red-500 shrink-0"></span>
        <p class="text-xs font-medium text-red-800">Overdue (ค้าง &gt; 14 วัน)</p>
      </div>
      <p class="text-2xl font-bold text-red-600 mt-1 font-mono" id="kpi-num-overdue"><?= $kpiData['overdue'] ?></p>
      <p class="text-[11px] text-red-500 mt-0.5 font-medium">ต้องติดตามเร่งด่วน</p>
    </div>
    <div class="w-10 h-10 rounded-lg bg-red-50 text-red-600 flex items-center justify-center text-lg shrink-0">
      <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
    </div>
  </div>
</section>
