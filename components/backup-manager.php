<?php
/**
 * Data Backup & Restore Manager ("Data Time-Machine") Modal — Lite-Epic Glass · HyperOS Edition
 * Rendered for signed-in administrators (`$isAdmin` in `index.php`).
 *
 * Features:
 *   - Live Database HEAD Telemetry & Auto Monthly Backup Retention Engine (1..60 months, default 12m)
 *   - Instant Manual Checkpoint ("Git Commit" for DB data) & Signed .json Backup Import/Export
 *   - Interactive Git-Style Diff Inspector (+ Restored, ~ Reverted, - Removed boards/magazines/programs)
 *   - Atomic Full-System Rollback with Automatic Pre-Restore Safety Snapshot (1-Click Undo)
 */
?>
<!-- ===== Modal: Monthly Data Backup & Time-Machine (administrators) ===== -->
<div id="backup-manager" class="gm-overlay hidden no-print" role="presentation">
  <div class="gm-window" id="bm-window" role="dialog" aria-modal="true" aria-labelledby="bm-title" aria-describedby="bm-subtitle">
    <span class="gm-glow" aria-hidden="true"></span><span class="gm-ring" aria-hidden="true"></span>
    <div class="gm-surface">
      <i class="gm-orb o1"></i><i class="gm-orb o2"></i><i class="gm-orb o3"></i>

      <header class="gm-head bm-head">
        <div class="bm-brand">
          <span class="gm-badge bm-head-badge" aria-hidden="true">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span class="bm-badge-pulse"></span>
          </span>
          <div class="gm-headtext bm-headtext">
            <div class="bm-kicker-row">
              <span class="bm-kicker-pill"><i class="fa-solid fa-code-branch" aria-hidden="true"></i> GIT-STYLE DATA TIME-MACHINE</span>
              <span class="bm-live-pill" id="bm-live-pill">
                <span class="bm-pulse" aria-hidden="true"></span>
                <span id="bm-live-pill-text">Auto Monthly Active</span>
              </span>
            </div>
            <h3 id="bm-title" class="gm-title">สำรองและย้อนคืนข้อมูลรายเดือน / Data Backup &amp; Time-Machine</h3>
            <p id="bm-subtitle" class="tm-subtitle">เปรียบเทียบความต่าง (Diff) และย้อนข้อมูลกลับ (Restore) เหมือน Git สำหรับฐานข้อมูล · ไม่กระทบโค้ดหรือระบบเว็บ</p>
          </div>
        </div>

        <div class="bm-head-right">
          <div class="bm-view-pills" id="bm-view-stepper" aria-label="มุมมองการทำงาน">
            <button type="button" class="bm-step-pill is-active" id="bm-step-list" data-bm-action="back">
              <span class="bm-step-dot">1</span>
              <span>ไทม์ไลน์จุดสำรอง</span>
            </button>
            <i class="fa-solid fa-chevron-right bm-step-arrow" aria-hidden="true"></i>
            <button type="button" class="bm-step-pill" id="bm-step-diff" disabled>
              <span class="bm-step-dot">2</span>
              <span>เปรียบเทียบ &amp; กู้คืน</span>
            </button>
          </div>
          <button type="button" class="gm-close bm-close-btn" data-bm-action="close" aria-label="ปิดหน้าต่าง / Close">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
          </button>
        </div>
      </header>

      <div class="gm-form bm-form-wrap">
        <!-- Floating result notice: overlays the body so showing it never shifts the layout (screen readers get #bm-live) -->
        <div id="bm-notice" class="bm-island" aria-hidden="true">
          <i class="fa-solid fa-circle-check" aria-hidden="true"></i><span></span>
        </div>
        <div class="gm-body" id="bm-body" aria-busy="true"></div>
        <div class="gm-actions bm-footer-actions" id="bm-actions"></div>
      </div>

      <footer class="gm-status">
        <span class="gm-mode"><i class="fa-solid fa-shield-halved" style="font-size:9px" aria-hidden="true"></i><span id="bm-mode-text">DATA TIME-MACHINE</span></span>
        <span class="gm-seg-info hide-sm"><b id="bm-st-info">auto monthly · retention 12m</b></span>
        <span class="gm-keys"><span><kbd>/</kbd> commit</span><span><kbd>Esc</kbd> close / back</span></span>
      </footer>
      <input type="file" id="bm-upload-input" class="hidden" accept=".json,application/json" aria-hidden="true">
      <p id="bm-live" class="sr-only" role="status" aria-live="polite"></p>
    </div>
  </div>
</div>
