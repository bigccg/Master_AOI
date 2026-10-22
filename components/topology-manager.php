<?php
/**
 * Zone & Sheet manager window (administrators only: index.php renders it when isAdmin()).
 * Body and buttons are rendered by assets/js/components/topology-manager.js from api/topology.php.
 * CSS: components/topology-manager.css + the glass popup (components/glass-popup/, board-modal/form.css)
 */
?>
<!-- ===== Modal: Manage Zones & Sheets (administrators) ===== -->
<div id="topology-manager" class="gm-overlay hidden no-print" role="presentation">
  <div class="gm-window" id="tm-window" role="dialog" aria-modal="true" aria-labelledby="tm-title" aria-describedby="tm-subtitle">
    <span class="gm-glow" aria-hidden="true"></span><span class="gm-ring" aria-hidden="true"></span>
    <div class="gm-surface">
      <i class="gm-orb o1"></i><i class="gm-orb o2"></i><i class="gm-orb o3"></i>

      <header class="gm-head">
        <span class="gm-badge"><i class="fa-solid fa-sitemap" aria-hidden="true"></i></span>
        <div class="gm-headtext">
          <h3 id="tm-title" class="gm-title">จัดการโซนและ Shelf / Manage Zones &amp; Shelves</h3>
          <p id="tm-subtitle" class="tm-subtitle">เพิ่ม แก้ไข หรือปิดใช้งานโซนและ Shelf · ข้อมูลบอร์ดเดิมไม่ถูกแก้ไข / Add, edit or disable zones and shelves · board records are never changed</p>
        </div>
        <button type="button" class="gm-close" data-tm-action="close" aria-label="ปิดหน้าต่าง / Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </header>

      <div class="gm-form">
        <div class="gm-body" id="tm-body" aria-busy="true"></div>
        <div class="gm-actions" id="tm-actions"></div>
      </div>

      <footer class="gm-status">
        <span class="gm-mode"><i class="fa-solid fa-user-shield" style="font-size:9px" aria-hidden="true"></i><span id="tm-mode-text">ADMIN</span></span>
        <span class="gm-seg-info hide-sm"><b id="tm-st-info">zones · sheets</b></span>
        <span class="gm-keys"><span><kbd>↑</kbd><kbd>↓</kbd><kbd>→</kbd><kbd>←</kbd> move</span><span><kbd>Enter</kbd> edit</span><span><kbd>Esc</kbd> back</span></span>
      </footer>
      <p id="tm-live" class="sr-only" role="status" aria-live="polite"></p>
    </div>
  </div>
</div>
