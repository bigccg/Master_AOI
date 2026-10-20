<?php
/**
 * Defect selection panel (Dynamic Island morph to the right of the modal when D or G+D is selected).
 */
?>
  <!-- Defect Selection Panel (Dynamic Island morph to the right of #gm-window when D or GD is selected) -->
  <div class="gm-defect-slot" id="defect-slot">
    <svg class="gm-goo-svg" width="0" height="0" aria-hidden="true" focusable="false">
      <defs>
        <filter id="gm-island-goo" x="-35%" y="-35%" width="170%" height="170%" color-interpolation-filters="sRGB">
          <feGaussianBlur in="SourceGraphic" stdDeviation="5" result="blur"/>
          <feColorMatrix in="blur" mode="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 19 -8" result="goo"/>
          <feComposite in="SourceGraphic" in2="goo" operator="atop"/>
        </filter>
      </defs>
    </svg>
    <div class="gm-goo-layer" id="gm-goo-layer" aria-hidden="true">
      <span class="gm-goo-anchor" id="gm-goo-anchor"></span>
      <span class="gm-goo-bud" id="gm-goo-bud"></span>
    </div>
    <span class="gm-edge-ripple" id="gm-edge-ripple" aria-hidden="true"></span>
    <div id="defect-panel" class="gm-window md is-danger gm-defect-dock hidden" role="region" aria-labelledby="defect-panel-title">
      <span class="gm-glow" aria-hidden="true"></span><span class="gm-ring" aria-hidden="true"></span>
      <div class="gm-island-skin" id="gm-island-skin" aria-hidden="true">
        <span class="gm-island-rim"></span>
      </div>
      <div class="gm-surface">
        <i class="gm-orb o1"></i><i class="gm-orb o2"></i><i class="gm-orb o3"></i>
        <header class="gm-head">
          <span class="gm-badge"><i class="fa-solid fa-triangle-exclamation"></i></span>
          <div class="gm-headtext">
            <h3 id="defect-panel-title" class="gm-title">Select Defects / เลือกข้อบกพร่อง</h3>
          </div>
        </header>
      <div class="gm-form">
        <div class="gm-defect-body">
          <p class="gm-defect-lead"><i class="fa-solid fa-circle-info"></i>กรุณาเลือกข้อบกพร่องอย่างน้อย 1 รายการ</p>
          <div class="gm-defect-grid">
<?php foreach (DEFECT_LABELS as $code => $label): ?>
            <label class="gm-defect">
              <input type="checkbox" name="defect[]" value="<?= $code ?>" onchange="updateDefectsInput()"/>
              <span class="gm-box"><i class="fa-solid fa-check"></i></span>
              <span><span class="num"><?= $code ?>.</span> <?= h($label) ?></span>
            </label>
<?php endforeach; ?>
            <label class="gm-defect wide">
              <input type="checkbox" name="defect_other" value="1" id="defect-other-checkbox" onchange="toggleOtherDefectInput()"/>
              <span class="gm-box"><i class="fa-solid fa-check"></i></span>
              <span>OTHER</span>
            </label>
            <div class="gm-other-note hidden" id="defect-other-note">
              <textarea name="defect_other_text" id="defect-other-text" class="gm-input hidden" rows="3" placeholder="ระบุรายละเอียด Other..." disabled oninput="updateDefectsInput()"></textarea>
            </div>
          </div>
        </div>
        <div class="gm-actions" style="justify-content:space-between">
          <span class="gm-count" style="font-size:12px;color:#64748b"><i class="fa-solid fa-bug" style="color:#f43f5e"></i>เลือกแล้ว <b id="gm-defect-count">0</b> รายการ</span>
          <span style="display:flex;gap:10px">
            <button type="button" onclick="clearDefects()" class="gm-btn ghost"><i class="fa-solid fa-rotate-left"></i>ล้างที่เลือก</button>
          </span>
        </div>
      </div>
      <footer class="gm-status">
        <span class="gm-mode"><i class="fa-solid fa-bug" style="font-size:9px"></i>DEFECT</span>
        <span class="gm-seg-info"><?= count(DEFECT_LABELS) ?> codes</span>
        <span class="gm-keys"><span>เลือก <b>Master [G]</b> เพื่อพับเก็บ</span></span>
      </footer>
    </div>
  </div>
  </div>
