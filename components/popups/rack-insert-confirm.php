<?php
/**
 * Confirm Insert popup opened from an empty Rack Map shelf. JS: assets/js/components/popups/rack-insert-confirm.js
 */
?>
<!-- ===== Popup: Confirm Insert from Rack Map (glass) ===== -->
<div id="rack-insert-confirm" class="gm-overlay hidden no-print" role="presentation">
  <div class="gm-window sm" role="alertdialog" aria-modal="true" aria-labelledby="rack-confirm-title">
    <span class="gm-glow"></span><span class="gm-ring"></span>
    <div class="gm-surface">
      <i class="gm-orb o1"></i><i class="gm-orb o2"></i><i class="gm-orb o3"></i>
      <div class="gm-confirm-body">
        <div class="gm-pulse"><i class="fa-solid fa-layer-group"></i></div>
        <h3 id="rack-confirm-title" class="gm-confirm-title">ยืนยันเพิ่มข้อมูล / Confirm Insert</h3>
        <p class="gm-confirm-text">
          ยืนยันที่จะเพิ่มข้อมูลใน Shelf <span class="gm-shelf-pill" id="rack-confirm-shelf"></span> หรือไม่?<br>
          <span class="en">Insert new record into Shelf <strong id="rack-confirm-shelf2" class="font-mono font-bold text-emerald-700"></strong>?</span>
        </p>
        <div class="gm-confirm-actions">
          <button type="button" onclick="closeRackConfirm()" class="gm-btn ghost">Cancel</button>
          <button type="button" onclick="confirmRackInsert()" class="gm-btn primary"><i class="fa-solid fa-check"></i>Confirm</button>
        </div>
      </div>
      <div class="gm-status" style="margin-top:0">
        <span class="gm-mode"><i class="fa-solid fa-terminal" style="font-size:9px"></i>CONFIRM</span>
        <span class="gm-seg-info">rack <b>→</b> insert</span>
        <span class="gm-keys"><span><kbd>Esc</kbd> cancel</span></span>
      </div>
    </div>
  </div>
</div>
