<?php
/**
 * Confirm Delete popup. JS: assets/js/components/popups/delete-confirm.js
 */
?>
<!-- ===== Popup: Confirm Delete Board Record (glass, same style as Confirm Insert) ===== -->
<div id="delete-board-confirm" class="gm-overlay hidden no-print" role="presentation">
  <div class="gm-window sm is-danger" role="alertdialog" aria-modal="true" aria-labelledby="delete-confirm-title">
    <span class="gm-glow"></span><span class="gm-ring"></span>
    <div class="gm-surface">
      <i class="gm-orb o1"></i><i class="gm-orb o2"></i><i class="gm-orb o3"></i>
      <div class="gm-confirm-body">
        <div class="gm-pulse"><i class="fa-solid fa-trash-can"></i></div>
        <h3 id="delete-confirm-title" class="gm-confirm-title">ยืนยันการลบข้อมูล / Confirm Delete</h3>
        <p class="gm-confirm-text">
          ยืนยันที่จะลบรายการ <span class="gm-shelf-pill is-danger" id="delete-confirm-code"></span> หรือไม่?<br>
          <span class="en">Delete record <strong id="delete-confirm-code2" class="font-mono font-bold text-rose-700"></strong>? การลบนี้ไม่สามารถย้อนกลับได้</span>
        </p>
        <div class="gm-confirm-actions">
          <button type="button" id="delete-confirm-cancel-btn" onclick="closeDeleteConfirm()" class="gm-btn ghost">Cancel</button>
          <button type="button" id="delete-confirm-ok-btn" onclick="confirmDeleteBoard()" class="gm-btn danger"><i class="fa-solid fa-trash-can"></i>Confirm</button>
        </div>
      </div>
      <div class="gm-status" style="margin-top:0">
        <span class="gm-mode"><i class="fa-solid fa-trash-can" style="font-size:9px"></i>DELETE</span>
        <span class="gm-seg-info">board <b>→</b> delete</span>
        <span class="gm-keys"><span><kbd>Esc</kbd> cancel</span></span>
      </div>
    </div>
  </div>
</div>
