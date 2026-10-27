<?php
/**
 * Import Station Modal — Glass HyperOS + Hypermodern Edition
 * Central control center for downloading pre-numbered Excel (.xlsx) templates
 * and importing validated board records into the AOI inspection system.
 */

declare(strict_types=1);

$isTopoZones  = topology()['zones'] ?? [];
$isTopoSheets = topology()['sheets'] ?? [];
$isCurrentZone = $zone ?? DEFAULT_ZONE;
$isCurrentSheet = $sheet ?? '';
?>
<!-- ===== Modal: Import Station (Glass HyperOS + Hypermodern) ===== -->
<div id="import-station" class="is-overlay hidden no-print" role="presentation" aria-hidden="true">
  <div class="is-window" id="is-window" role="dialog" aria-modal="true" aria-labelledby="is-title" aria-describedby="is-subtitle">
    <!-- Ambient HyperOS Aurora Orbs -->
    <div class="is-aurora" aria-hidden="true">
      <span class="is-orb is-orb-1"></span>
      <span class="is-orb is-orb-2"></span>
      <span class="is-orb is-orb-3"></span>
    </div>

    <div class="is-surface">
      <!-- Header -->
      <header class="is-head">
        <div class="is-brand">
          <div class="is-icon-badge" aria-hidden="true">
            <i class="fa-solid fa-file-import"></i>
            <span class="is-icon-pulse"></span>
          </div>
          <div class="is-head-text">
            <div class="is-kicker">
              <span class="is-kicker-pill"><i class="fa-solid fa-file-excel"></i> EXCEL BATCH IMPORT</span>
              <span class="is-live-indicator" id="is-live-status">
                <span class="is-live-dot"></span>
                <span id="is-live-status-text">พร้อมใช้งาน</span>
              </span>
            </div>
            <h2 id="is-title" class="is-title">Import Station — ระบบนำเข้าข้อมูลบอร์ดผ่าน Excel</h2>
            <p id="is-subtitle" class="is-subtitle">ดาวน์โหลดแม่แบบพร้อม Number Code ต่อเนื่อง · ตรวจสอบความถูกต้องและบันทึกข้อมูลปลอดภัย</p>
          </div>
        </div>

        <!-- Header Actions: Format Switcher Tabs + Close Button -->
        <div class="is-head-actions">
          <div class="is-tab-group" role="tablist" aria-label="ขั้นตอนการนำเข้า">
            <button type="button" class="is-tab-btn is-active" id="is-tab-template-btn" role="tab" aria-selected="true" onclick="isSwitchTab(1)">
              <i class="fa-solid fa-file-arrow-down text-emerald-600"></i>
              <span>1. แม่แบบ Import</span>
            </button>
            <button type="button" class="is-tab-btn" id="is-tab-upload-btn" role="tab" aria-selected="false" onclick="isSwitchTab(2)">
              <i class="fa-solid fa-cloud-arrow-up text-cyan-600"></i>
              <span>2. อัปโหลด &amp; ตรวจสอบ</span>
            </button>
          </div>

          <button type="button" class="is-close-btn" onclick="closeImportStation()" aria-label="ปิดหน้าต่าง / Close">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </header>

      <!-- Main Body Panels -->
      <div class="is-body">

        <!-- ===== TAB 1: Download Template ===== -->
        <div id="is-panel-template" class="is-panel" role="tabpanel" aria-labelledby="is-tab-template-btn">
          <div class="is-guide-card">
            <div class="is-guide-icon"><i class="fa-solid fa-circle-info text-blue-500"></i></div>
            <div class="is-guide-text">
              <strong>กติกา Number Code ต่อเนื่อง:</strong>
              แม่แบบจะเติมรหัสเริ่มต้นอัตโนมัติตามลำดับถัดไปของระบบในเดือนปัจจุบัน กรอกข้อมูลต่อเนื่องจากแถวแรก (ห้ามเว้นบรรทัดว่าง)
            </div>
          </div>

          <div class="is-form-grid">
            <!-- Zone Selection -->
            <div class="is-form-group">
              <label class="is-label" for="is-select-zone">
                <i class="fa-solid fa-layer-group text-slate-500"></i>
                <span>โซนปลายทาง (Target Zone)</span>
              </label>
              <select id="is-select-zone" class="is-select" onchange="isHandleZoneChange()">
                <?php foreach ($isTopoZones as $zKey => $zMeta): ?>
                  <?php if (!empty($zMeta['active']) && $zKey !== 'ALL'): ?>
                    <option value="<?= h($zKey) ?>" <?= $zKey === $isCurrentZone ? 'selected' : '' ?>>
                      <?= h($zMeta['title'] ?? $zKey) ?> (<?= h($zKey) ?>)
                    </option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Sheet Selection -->
            <div class="is-form-group">
              <label class="is-label" for="is-select-sheet">
                <i class="fa-solid fa-table-cells text-slate-500"></i>
                <span>ชีต / ขนาดบอร์ด (Target Sheet)</span>
              </label>
              <select id="is-select-sheet" class="is-select">
                <?php foreach ($isTopoSheets as $sKey => $sMeta): ?>
                  <?php if (!empty($sMeta['active']) && !empty($sMeta['sheet_prefix'])): ?>
                    <option value="<?= h($sKey) ?>" data-zone="<?= h($sMeta['zone'] ?? '') ?>" <?= $sKey === $isCurrentSheet ? 'selected' : '' ?>>
                      <?= h($sKey) ?> (Prefix: <?= h($sMeta['sheet_prefix']) ?>)
                    </option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Row Count Option -->
            <div class="is-form-group">
              <label class="is-label" for="is-select-rows">
                <i class="fa-solid fa-list-ol text-slate-500"></i>
                <span>จำนวนแถวในแม่แบบ (Template Rows)</span>
              </label>
              <select id="is-select-rows" class="is-select">
                <option value="25">25 แถว (บอร์ดขนาดเล็ก / รอบย่อย)</option>
                <option value="50" selected>50 แถว (ขนาดมาตรฐาน 1 Magazine)</option>
                <option value="100">100 แถว (ขนาดใหญ่ 2 Magazines)</option>
                <option value="200">200 แถว (รอบผลิตขนาดใหญ่)</option>
              </select>
            </div>
          </div>

          <div class="is-actions-row">
            <button type="button" class="is-btn-primary" id="is-download-template-btn" onclick="isDownloadTemplate()">
              <i class="fa-solid fa-file-arrow-down"></i>
              <span>ดาวน์โหลดแม่แบบ Excel (.xlsx)</span>
            </button>
            <button type="button" class="is-btn-secondary" onclick="isSwitchTab(2)">
              <span>มีไฟล์แล้ว ไปที่ขั้นตอนอัปโหลด</span>
              <i class="fa-solid fa-arrow-right"></i>
            </button>
          </div>
        </div>

        <!-- ===== TAB 2: Upload & Preview ===== -->
        <div id="is-panel-upload" class="is-panel hidden" role="tabpanel" aria-labelledby="is-tab-upload-btn">
          <!-- Drop Zone -->
          <div class="is-dropzone" id="is-dropzone" onclick="document.getElementById('is-file-input').click()">
            <input type="file" id="is-file-input" accept=".xlsx" class="hidden" onchange="isHandleFileSelect(event)"/>
            <div class="is-dropzone-icon">
              <i class="fa-solid fa-cloud-arrow-up"></i>
            </div>
            <div class="is-dropzone-text">
              <strong id="is-filename-label">ลากไฟล์ Excel (.xlsx) มาวางที่นี่ หรือคลิกเพื่อเลือกไฟล์</strong>
              <span>รองรับเฉพาะไฟล์แม่แบบ .xlsx ขนาดไม่เกิน 2 MB</span>
            </div>
          </div>

          <!-- Preview & Status Box -->
          <div id="is-preview-box" class="is-preview-box hidden">
            <div class="is-preview-head">
              <div class="is-preview-title">
                <i class="fa-solid fa-clipboard-check text-emerald-600"></i>
                <span id="is-preview-status-text">ผลการตรวจสอบไฟล์</span>
              </div>
              <span id="is-preview-badge" class="is-badge">0 รายการ</span>
            </div>
            <div id="is-preview-content" class="is-preview-content">
              <!-- Dynamically populated rows or messages -->
            </div>
          </div>

          <div class="is-actions-row">
            <button type="button" class="is-btn-secondary" onclick="isSwitchTab(1)">
              <i class="fa-solid fa-arrow-left"></i>
              <span>ย้อนกลับไปดาวน์โหลดแม่แบบ</span>
            </button>
            <button type="button" class="is-btn-primary" id="is-commit-btn" disabled onclick="isCommitImport()">
              <i class="fa-solid fa-check-double"></i>
              <span>ยืนยันบันทึกข้อมูล (Commit Import)</span>
            </button>
          </div>
        </div>

      </div>

      <!-- Footer Bar -->
      <footer class="is-foot">
        <div class="is-foot-left">
          <i class="fa-solid fa-shield-halved text-emerald-600"></i>
          <span class="is-foot-note">ระบบตรวจสอบ Shelf ว่าง, Prefix, และสถานะ Defect อย่างเข้มงวดก่อนบันทึก</span>
        </div>
        <div class="is-foot-right">
          <button type="button" class="is-btn-secondary is-btn-sm" onclick="closeImportStation()">
            <span>ปิดหน้าต่าง (Esc)</span>
          </button>
        </div>
      </footer>
    </div>
  </div>
</div>

