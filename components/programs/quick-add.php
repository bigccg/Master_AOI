<?php
/**
 * Inline Industrial Quick Add Dock for AOI Programs
 */

declare(strict_types=1);

$owners = isset($pdo) ? fetchProgramOwners($pdo) : [];
?>
<!-- 3. QUICK-ADD DOCK -->
<section id="prog-quick-add" class="prog-quick-add-dock" aria-label="เพิ่มโปรแกรมด่วน">
  <!-- Controls Group -->
  <div class="prog-quick-add-controls">
    <div class="prog-quick-add-badge">
      <i class="fa-solid fa-bolt" aria-hidden="true"></i>
      <span>Quick Add ↵</span>
    </div>

    <!-- Product Model Input -->
    <input type="text"
           id="quick-add-model"
           class="prog-quick-add-input"
           placeholder="รหัส เช่น NKSZ-380MW-0C"
           autocomplete="off"
           spellcheck="false"
           aria-label="รหัส Product Model">

    <!-- Side Select -->
    <select id="quick-add-side" class="prog-quick-add-select font-mono font-bold text-emerald-700" aria-label="ด้านชิ้นงาน (Side)">
      <option value="F-Side">F-SIDE</option>
      <option value="B-Side">B-SIDE</option>
    </select>

    <!-- Machine Select -->
    <select id="quick-add-machine" class="prog-quick-add-select" aria-label="เลือกเครื่องจักร">
      <?php foreach (PROGRAM_MACHINES as $m): ?>
        <option value="<?= h($m) ?>"><?= h($m) ?></option>
      <?php endforeach; ?>
    </select>

    <!-- Owner Select -->
    <select id="quick-add-owner" class="prog-quick-add-select" aria-label="เลือกผู้รับผิดชอบ">
      <option value="">-- ผู้ทำ (ยังไม่ระบุ) --</option>
      <?php foreach ($owners as $o): ?>
        <option value="<?= h($o) ?>"><?= h($o) ?></option>
      <?php endforeach; ?>
    </select>

    <!-- Add Button -->
    <button type="button" id="quick-add-submit-btn" class="prog-quick-add-btn" title="บันทึกและเพิ่มรายการใหม่ (Enter)">
      <i class="fa-solid fa-plus" aria-hidden="true"></i>
      <span>เพิ่ม ↵</span>
    </button>
  </div>

  <!-- Real-time Feedback & Similarity Alert Badges -->
  <div class="prog-quick-add-feedback">
    <span class="prog-quick-add-hint hidden xl:inline">
      กด Enter เพื่อสร้างแถวใหม่ · ฟิลด์จำค่าเดิมอัตโนมัติ
    </span>

    <!-- Duplicate Warning Badge (Amber) -->
    <div id="quick-add-duplicate-alert" class="prog-alert-badge is-duplicate" style="display: none;">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <span>มีรายการนี้อยู่แล้ว: <a href="#" id="quick-add-goto-dup" class="underline font-bold">ไปยังแถวนั้น</a></span>
    </div>

    <!-- Similar Model Info Badge (Sky) -->
    <div id="quick-add-similar-alert" class="prog-alert-badge is-similar" style="display: none;">
      <i class="fa-solid fa-circle-info"></i>
      <span id="quick-add-similar-text">ตรวจพบโมเดลคล้ายคลึง: NKSZ-375MW</span>
    </div>
  </div>
</section>
