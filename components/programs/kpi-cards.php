<?php
/**
 * 5 Clickable KPI Summary Cards for AOI Programs
 */

declare(strict_types=1);

$kpiData = isset($pdo) ? fetchProgramKpis($pdo) : [
    'total' => 0, 'complete' => 0, 'pct_complete' => 0, 'wait_master' => 0, 'queued' => 0, 'overdue' => 0, 'omron' => 0, 'yamaha' => 0, 'tri' => 0
];
?>
<!-- 1. KPI SUMMARY CARDS -->
<section class="prog-kpi-grid" aria-label="สรุปภาพรวมสถานะโปรแกรม">
  <!-- Card 1: Total Programs -->
  <button type="button" class="prog-kpi-card <?= ($statusF === 'ALL') ? 'is-active' : '' ?>" data-kpi-filter="ALL" aria-pressed="<?= ($statusF === 'ALL') ? 'true' : 'false' ?>">
    <div class="prog-kpi-header">
      <span class="prog-kpi-title">
        โปรแกรมทั้งหมด <span class="sub-en">/ Total</span>
      </span>
      <div class="prog-kpi-icon bg-blue-50 text-blue-600">
        <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
      </div>
    </div>
    <div class="prog-kpi-value-row">
      <span class="prog-kpi-num" id="kpi-num-total"><?= $kpiData['total'] ?></span>
    </div>
    <div class="prog-kpi-footer font-mono" id="kpi-breakdown-mach" title="OMRON <?= $kpiData['omron'] ?> · YAMAHA <?= $kpiData['yamaha'] ?> · TRI <?= $kpiData['tri'] ?>">
      <span>OMRON <?= $kpiData['omron'] ?></span>
      <span class="text-slate-300">·</span>
      <span>YAMAHA <?= $kpiData['yamaha'] ?></span>
      <span class="text-slate-300">·</span>
      <span>TRI <?= $kpiData['tri'] ?></span>
    </div>
  </button>

  <!-- Card 2: Complete -->
  <button type="button" class="prog-kpi-card <?= ($statusF === 'complete') ? 'is-active' : '' ?>" data-kpi-filter="complete" aria-pressed="<?= ($statusF === 'complete') ? 'true' : 'false' ?>">
    <div class="prog-kpi-header">
      <span class="prog-kpi-title text-emerald-800">
        เสร็จสมบูรณ์ <span class="sub-en">/ Complete</span>
      </span>
      <div class="prog-kpi-icon bg-emerald-50 text-emerald-600">
        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
      </div>
    </div>
    <div class="prog-kpi-value-row">
      <span class="prog-kpi-num text-emerald-600" id="kpi-num-complete"><?= $kpiData['complete'] ?></span>
      <span class="prog-kpi-badge" id="kpi-pct-complete"><?= $kpiData['pct_complete'] ?>%</span>
    </div>
    <div class="prog-kpi-progress">
      <div class="prog-kpi-progress-bar" id="kpi-bar-complete" style="width: <?= $kpiData['pct_complete'] ?>%"></div>
    </div>
  </button>

  <!-- Card 3: Wait Master -->
  <button type="button" class="prog-kpi-card <?= ($statusF === 'wait_master') ? 'is-active' : '' ?>" data-kpi-filter="wait_master" aria-pressed="<?= ($statusF === 'wait_master') ? 'true' : 'false' ?>">
    <div class="prog-kpi-header">
      <span class="prog-kpi-title text-amber-800">
        รอ Master <span class="sub-en">/ Wait Master</span>
      </span>
      <div class="prog-kpi-icon bg-amber-50 text-amber-600">
        <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
      </div>
    </div>
    <div class="prog-kpi-value-row">
      <span class="prog-kpi-num text-amber-600" id="kpi-num-wait-master"><?= $kpiData['wait_master'] ?></span>
    </div>
    <div class="prog-kpi-footer text-amber-700">
      <span>รอจัดเตรียมแผ่น Master G/D</span>
    </div>
  </button>

  <!-- Card 4: Queued / Wait Program -->
  <button type="button" class="prog-kpi-card <?= ($statusF === 'queued') ? 'is-active' : '' ?>" data-kpi-filter="queued" aria-pressed="<?= ($statusF === 'queued') ? 'true' : 'false' ?>">
    <div class="prog-kpi-header">
      <span class="prog-kpi-title text-slate-700">
        รอทำโปรแกรม <span class="sub-en">/ Queued</span>
      </span>
      <div class="prog-kpi-icon bg-slate-100 text-slate-600">
        <i class="fa-solid fa-list-check" aria-hidden="true"></i>
      </div>
    </div>
    <div class="prog-kpi-value-row">
      <span class="prog-kpi-num" id="kpi-num-queued"><?= $kpiData['queued'] ?></span>
    </div>
    <div class="prog-kpi-footer text-slate-500">
      <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-600"></span>
      <span>รอดำเนินการสร้างโปรแกรม</span>
    </div>
  </button>

  <!-- Card 5: Overdue -->
  <button type="button" class="prog-kpi-card is-overdue <?= ($statusF === 'overdue') ? 'is-active' : '' ?>" data-kpi-filter="overdue" aria-pressed="<?= ($statusF === 'overdue') ? 'true' : 'false' ?>">
    <div class="prog-kpi-header">
      <span class="prog-kpi-title text-red-700 font-semibold">
        ค้างนาน &gt; 14 วัน <span class="sub-en text-red-400">/ Overdue</span>
      </span>
      <div class="prog-kpi-icon bg-red-50 text-red-600">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
      </div>
    </div>
    <div class="prog-kpi-value-row">
      <span class="prog-kpi-num" id="kpi-num-overdue"><?= $kpiData['overdue'] ?></span>
    </div>
    <div class="prog-kpi-footer text-red-600 font-medium justify-between">
      <span>ต้องเร่งดำเนินการ</span>
      <span class="text-[10px]">ดูรายการ →</span>
    </div>
  </button>
</section>
