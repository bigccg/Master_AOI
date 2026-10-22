<?php
/**
 * Confirm popup for disabling / deleting a zone or sheet (same glass pattern as delete-confirm.php).
 * Text is filled with textContent by assets/js/components/popups/topology-confirm.js.
 */
?>
<!-- ===== Popup: Confirm Disable / Delete Zone or Sheet (glass, same style as Confirm Delete) ===== -->
<div id="topology-confirm" class="gm-overlay hidden no-print" role="presentation">
  <div class="gm-window sm is-danger" role="alertdialog" aria-modal="true" aria-labelledby="tm-confirm-title" aria-describedby="tm-confirm-text">
    <span class="gm-glow" aria-hidden="true"></span><span class="gm-ring" aria-hidden="true"></span>
    <div class="gm-surface">
      <i class="gm-orb o1"></i><i class="gm-orb o2"></i><i class="gm-orb o3"></i>
      <div class="gm-confirm-body">
        <div class="gm-pulse"><i id="tm-confirm-icon" class="fa-solid fa-power-off" aria-hidden="true"></i></div>
        <h3 id="tm-confirm-title" class="gm-confirm-title"></h3>
        <p id="tm-confirm-text" class="gm-confirm-text"></p>
        <div class="gm-confirm-actions">
          <button type="button" id="tm-confirm-cancel" class="gm-btn ghost">ยกเลิก / Cancel</button>
          <button type="button" id="tm-confirm-ok" class="gm-btn danger"><i class="fa-solid fa-check" aria-hidden="true"></i><span id="tm-confirm-ok-label">ยืนยัน / Confirm</span></button>
        </div>
      </div>
      <div class="gm-status" style="margin-top:0">
        <span class="gm-mode"><i class="fa-solid fa-user-shield" style="font-size:9px" aria-hidden="true"></i><span id="tm-confirm-mode">CONFIRM</span></span>
        <span class="gm-seg-info" id="tm-confirm-seg"></span>
        <span class="gm-keys"><span><kbd>Esc</kbd> cancel</span></span>
      </div>
    </div>
  </div>
</div>
