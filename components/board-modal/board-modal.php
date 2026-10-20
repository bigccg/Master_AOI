<?php
/**
 * Add / Edit board modal (glass window) + defect panel beside it.
 * JS: assets/js/components/board-modal/ · CSS: components/glass-popup/, components/board-modal/
 */
?>
<!-- ===== Modal: Add / Edit + Side-by-Side Droplet Defect Panel ===== -->
<div id="add-entry-modal" class="gm-overlay hidden" role="presentation">
  <div class="gm-stage" id="gm-stage">
  <div class="gm-window" id="gm-window" role="dialog" aria-modal="true" aria-labelledby="modal-title-text">
    <span class="gm-glow"></span><span class="gm-ring"></span>
    <div class="gm-surface">
      <i class="gm-orb o1"></i><i class="gm-orb o2"></i><i class="gm-orb o3"></i>

      <header class="gm-head">
        <span class="gm-badge"><i id="modal-title-icon" class="fa-solid fa-plus"></i></span>
        <div class="gm-headtext">
          <h3 id="modal-title-text" class="gm-title">Add New AOI Board Record / บันทึกข้อมูลบอร์ดใหม่</h3>
        </div>
        <button type="button" class="gm-close" onclick="closeModal()" aria-label="ปิดหน้าต่าง / Close"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <form id="board-form" onsubmit="handleBoardFormSubmit(event)" class="gm-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"/>
        <input type="hidden" name="action" value="save"/>
        <input type="hidden" name="zone" id="modal-zone" value="<?= h($zone) ?>"/>
        <input type="hidden" name="id" id="modal-id" value="0"/>
        <input type="hidden" name="code" id="modal-code" value="<?= h($nextCode) ?>"/>
        <input type="hidden" name="defects" id="defects-input" value=""/>

        <div class="gm-body">
          <div id="modal-error-box" class="gm-error hidden" role="alert"></div>

          <div class="gm-row">
            <div class="gm-field">
              <label class="gm-label" for="modal-shelf"><span class="k">01</span><span>Shelf Slot <span class="th">(ตำแหน่งชั้น)</span><i class="gm-req">*</i></span></label>
              <select name="shelf" id="modal-shelf" required <?= $freeShelves ? '' : 'disabled' ?>
                      class="gm-input mono <?= $freeShelves ? '' : 'is-max' ?>">
                <?php if (!$freeShelves): ?>
                  <option value="">Magazine is Max</option>
                <?php else: ?>
                  <option value="" disabled selected>เลือก Shelf...</option>
                  <?php foreach ($freeByMag as $mg => $list): ?>
                    <optgroup label="<?= h(magazineTitle($mg)) ?>">
                      <?php foreach ($list as $n): ?>
                        <option value="<?= h($sp . $n) ?>"><?= h($sp . $n) ?></option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
              <p id="modal-shelf-info" class="gm-hint">
                <?= $freeShelves ? 'แสดงเฉพาะ Shelf ว่าง (' . count($freeShelves) . '/' . $capacity . ')' : '<span class="font-semibold text-red-600">Magazine is Max — เพิ่ม Magazine ได้ที่ Rack Map (กด Confirm)</span>' ?>
              </p>
            </div>
            <div class="gm-field">
              <label class="gm-label" for="modal-product"><span class="k">02</span><span>Product Model<i class="gm-req">*</i></span></label>
              <div class="gm-input-wrap">
                <input type="text" name="product" id="modal-product" required maxlength="40" placeholder="NKGZ-248MS-0A"
                       class="gm-input mono has-ico" spellcheck="false"/>
                <i class="fa-solid fa-microchip gm-ico"></i>
                <span class="gm-tick"><i class="fa-solid fa-check"></i></span>
              </div>
            </div>
          </div>

          <div class="gm-row">
            <div class="gm-field">
              <label class="gm-label" for="modal-side"><span class="k">03</span><span>Side<i class="gm-req">*</i></span></label>
              <select name="side" id="modal-side" required class="gm-input">
                <option value="">เลือก Side...</option>
                <?php foreach (BOARD_SIDES as $s): ?>
                  <option value="<?= h($s) ?>"><?= h($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="gm-field">
              <label class="gm-label" for="modal-board-type"><span class="k">04</span><span>Board Type<i class="gm-req">*</i></span></label>
              <select name="board_type" id="modal-board-type" required class="gm-input">
                <option value="">เลือก Board Type...</option>
                <?php foreach (BOARD_TYPES as $bt): ?>
                  <option value="<?= h($bt) ?>"><?= h($bt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="gm-field">
            <label class="gm-label"><span class="k">05</span><span>Board Classification <span class="th">(ประเภทของงาน)</span><i class="gm-req">*</i></span></label>
            <div class="gm-seg" role="radiogroup">
              <span class="gm-thumb"></span>
              <label class="gm-seg-item g"><input type="radio" name="board_status" value="G" required/><i class="fa-solid fa-circle-check"></i>Master [G]</label>
              <label class="gm-seg-item d"><input type="radio" name="board_status" value="D" required/><i class="fa-solid fa-triangle-exclamation"></i>Master [D]</label>
              <label class="gm-seg-item gd"><input type="radio" name="board_status" value="GD" required/><i class="fa-solid fa-layer-group"></i>[G+D] Mixed</label>
            </div>
            <p class="gm-hint gm-seg-lock">ล็อกไว้ — ไม่ต้องเลือกเมื่อ Other เป็น <?= h(implode(' / ', OTHER_NO_CLASS)) ?></p>
          </div>

          <div class="gm-field">
            <label class="gm-label" for="modal-pcs"><span class="k">06</span><span>Other</span></label>
            <select name="pcs" id="modal-pcs" class="gm-input">
              <option value="">เลือก Other...</option>
              <?php foreach (OTHER_OPTIONS as $opt): ?>
                <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="gm-actions">
          <button type="button" id="modal-delete-btn" class="gm-btn danger hidden" hidden style="margin-right:auto">
            <i class="fa-solid fa-trash-can" aria-hidden="true"></i><span>ลบรายการ / Delete</span>
          </button>
          <button type="button" onclick="closeModal()" class="gm-btn ghost">ยกเลิก / Cancel</button>
          <button type="submit" id="modal-submit-btn" class="gm-btn primary">
            <i class="fa-solid fa-floppy-disk"></i><span id="modal-submit-label">บันทึกลง <?= h($sheet) ?> / Save to <?= h($sheet) ?></span><kbd>Enter ↵</kbd>
          </button>
        </div>
      </form>

      <footer class="gm-status">
        <span class="gm-mode" id="gm-mode">
          <svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" aria-hidden="true"><path d="M12 1.5c-1.1 2.7-1.8 4.5-3 6.9.7.8 1.7 1.700 3.200 2.700-1.600-.7-2.700-1.300-3.500-1.900C6.400 12.600 4.400 16.200 0 22.500c3.400-2 6-3.100 8.300-3.600-.1-.5-.2-1-.2-1.500v-.1c0-2.300 1.100-4 2.700-4s2.700 1.700 2.700 4c0 .5-.1 1-.2 1.500 2.300.5 4.900 1.600 8.300 3.600-.7-1.200-1.300-2.300-1.800-3.400-.9-.7-1.800-1.500-3.700-2.500 1.200.3 2.200.7 2.900 1.100C14.600 9.800 13.200 7.200 12 1.500z"/></svg>
          <span id="gm-mode-text">INSERT</span>
        </span>
        <span class="gm-seg-info"><i class="fa-solid fa-table-list" style="font-size:9px"></i><b id="gm-st-sheet"><?= h($zone . ' · ' . $sheet) ?></b></span>
        <span class="gm-seg-info hide-sm"><span class="gm-prog"><i id="gm-prog-bar"></i></span><b id="gm-prog-text">0/5</b></span>
        <span class="gm-keys"><span><kbd>Esc</kbd> close</span><span><kbd>Enter</kbd> save</span></span>
      </footer>
    </div>
  </div>

<?php require __DIR__ . '/defect-panel.php'; ?>
  </div>
</div>
