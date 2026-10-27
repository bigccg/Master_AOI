<?php
/**
 * Import Station Modal — Glass HyperOS + Hypermodern Edition
 * 3-Step Workflow:
 *   1. ดาวน์โหลดแม่แบบ (Download Pre-Numbered Excel Template)
 *   2. อัปโหลดไฟล์ (Upload Filled .xlsx File)
 *   3. ตรวจสอบและยืนยัน (Dry-Run Preview, Row-Level Error Inspection & All-or-Nothing Commit)
 */

declare(strict_types=1);

$isTopo       = topology();
$isTopoZones  = $isTopo['zones'] ?? [];
$isTopoSheets = $isTopo['sheets'] ?? [];
$isCurZone    = isset($zone) && !isAllZones((string)$zone) ? (string)$zone : DEFAULT_ZONE;
$isCurSheet   = isset($sheet) && $sheet !== 'ALL' && $sheet !== '' ? (string)$sheet : defaultSheetForZone($isCurZone);
?>
<!-- ===== Modal: Import Station (3-Step Excel Batch Import) ===== -->
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
                <span class="is-live-dot" id="is-live-dot"></span>
                <span id="is-live-status-text">พร้อมใช้งาน / Ready</span>
              </span>
            </div>
            <h2 id="is-title" class="is-title">Import Station — ระบบนำเข้าข้อมูลบอร์ดผ่าน Excel</h2>
            <p id="is-subtitle" class="is-subtitle">สร้างแม่แบบพร้อม Number Code รันต่อเนื่อง · ตรวจสอบความถูกต้องรายแถว · บันทึกแบบ All-or-Nothing</p>
          </div>
        </div>

        <!-- 3-Step Stepper Navigation + Close Button -->
        <div class="is-head-actions">
          <nav class="is-stepper" role="tablist" aria-label="ขั้นตอนการนำเข้าข้อมูล Excel">
            <button type="button" class="is-step-btn is-active" id="is-step-btn-1" role="tab" aria-selected="true" aria-controls="is-panel-step-1" onclick="isGoToStep(1)">
              <span class="is-step-num">1</span>
              <span class="is-step-label">ดาวน์โหลดแม่แบบ</span>
            </button>
            <span class="is-step-sep" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
            <button type="button" class="is-step-btn" id="is-step-btn-2" role="tab" aria-selected="false" aria-controls="is-panel-step-2" onclick="isGoToStep(2)">
              <span class="is-step-num">2</span>
              <span class="is-step-label">อัปโหลดไฟล์</span>
            </button>
            <span class="is-step-sep" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
            <button type="button" class="is-step-btn" id="is-step-btn-3" role="tab" aria-selected="false" aria-controls="is-panel-step-3" onclick="isGoToStep(3)" disabled>
              <span class="is-step-num">3</span>
              <span class="is-step-label">ตรวจสอบและยืนยัน</span>
            </button>
          </nav>

          <button type="button" class="is-close-btn" id="is-close-btn" onclick="closeImportStation()" aria-label="ปิดหน้าต่าง Import Station / Close Import Station">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
          </button>
        </div>
      </header>

      <!-- Main Body Panels -->
      <div class="is-body" id="is-body">

        <!-- =========================================================
             STEP 1: ดาวน์โหลดแม่แบบ Excel (Download Template)
             ========================================================= -->
        <section id="is-panel-step-1" class="is-panel" role="tabpanel" aria-labelledby="is-step-btn-1">
          <!-- Context Alert when opened from ALL Zones or ALL Sheets -->
          <div id="is-all-mode-notice" class="is-banner is-banner-info hidden" role="status" aria-live="polite">
            <i class="fa-solid fa-circle-info is-banner-ico" aria-hidden="true"></i>
            <div class="is-banner-body">
              <strong>กรุณาเลือกโซนและชีตปลายทางก่อนดาวน์โหลดแม่แบบ / Please select a target Zone and Sheet:</strong>
              <span>หน้าจอหลักกำลังเปิดมุมมองรวม (All Zones / All Size Magazine) แม่แบบแต่ละไฟล์ต้องผูกกับโซนและชีตที่เปิดใช้งานจริง</span>
            </div>
          </div>

          <div class="is-guide-card">
            <div class="is-guide-icon" aria-hidden="true"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
            <div class="is-guide-text">
              <strong>กติกา Number Code ต่อเนื่องอัตโนมัติ (System-Wide Sequential Number Code):</strong>
              <span>ระบบจะเติม <code>Number Code</code> ล่วงหน้าต่อจากรหัสล่าสุดที่บันทึกจริงในฐานข้อมูล กรุณากรอกข้อมูลใน Excel ต่อเนื่องจากแถวแรกโดยไม่เว้นบรรทัดว่าง แถวท้ายที่ไม่ได้กรอกระบบจะข้ามให้อัตโนมัติและไม่เสียรหัส</span>
            </div>
          </div>

          <!-- Form Controls: Zone, Sheet, Row Count -->
          <div class="is-form-grid">
            <div class="is-form-group">
              <label class="is-label" for="is-select-zone">
                <i class="fa-solid fa-layer-group text-emerald-600" aria-hidden="true"></i>
                <span>1. โซนปลายทาง (Target Zone)</span>
              </label>
              <select id="is-select-zone" class="is-select" onchange="isHandleZoneChange()">
                <?php foreach ($isTopoZones as $zKey => $zMeta): ?>
                  <?php if (!empty($zMeta['is_active']) && $zKey !== 'ALL'): ?>
                    <option value="<?= h((string)$zKey) ?>" <?= (string)$zKey === $isCurZone ? 'selected' : '' ?>>
                      <?= h((string)($zMeta['label'] ?? $zKey)) ?> (<?= h((string)$zKey) ?>)
                    </option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="is-form-group">
              <label class="is-label" for="is-select-sheet">
                <i class="fa-solid fa-table-cells text-cyan-600" aria-hidden="true"></i>
                <span>2. ชีต / ขนาดบอร์ด (Target Sheet)</span>
              </label>
              <select id="is-select-sheet" class="is-select" onchange="isRefreshTemplateTelemetry()">
                <?php foreach ($isTopoSheets as $sKey => $sMeta): ?>
                  <?php if (!empty($sMeta['is_active'])): ?>
                    <option value="<?= h((string)$sKey) ?>"
                            data-zone="<?= h((string)($sMeta['zone'] ?? '')) ?>"
                            data-prefix="<?= h((string)($sMeta['prefix'] ?? '')) ?>"
                            <?= (string)$sKey === $isCurSheet ? 'selected' : '' ?>>
                      <?= h((string)($sMeta['label'] ?? $sKey)) ?> (Shelf: <?= h((string)($sMeta['prefix'] ?? '')) ?>1–<?= h((string)($sMeta['prefix'] ?? '')) ?>N)
                    </option>
                  <?php endif; ?>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="is-form-group">
              <label class="is-label" for="is-select-rows">
                <i class="fa-solid fa-list-ol text-indigo-600" aria-hidden="true"></i>
                <span>3. จำนวนแถวในแม่แบบ (Template Rows)</span>
              </label>
              <select id="is-select-rows" class="is-select" onchange="isRefreshTemplateTelemetry()">
                <?php foreach (IMPORT_ROW_OPTIONS as $rOpt): ?>
                  <option value="<?= (int)$rOpt ?>" <?= (int)$rOpt === IMPORT_DEFAULT_ROWS ? 'selected' : '' ?>>
                    <?= (int)$rOpt ?> แถว<?= (int)$rOpt === 50 ? ' (ค่าเริ่มต้น / 1 Magazine)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Live Template Telemetry Preview Card -->
          <div class="is-telemetry-grid" id="is-template-telemetry" aria-live="polite">
            <div class="is-telemetry-card">
              <span class="is-telemetry-label"><i class="fa-solid fa-hashtag text-emerald-600"></i> ช่วงรหัส Number Code ที่จะสร้างในไฟล์</span>
              <strong class="is-telemetry-val is-mono" id="is-tpl-code-range">กำลังคำนวณ...</strong>
              <small class="is-telemetry-sub" id="is-tpl-remaining">ตรวจสอบโควตาเดือนปัจจุบัน (สูงสุด 999)</small>
            </div>
            <div class="is-telemetry-card">
              <span class="is-telemetry-label"><i class="fa-solid fa-boxes-stacked text-cyan-600"></i> ขอบเขตชั้นวาง (Rack Shelf Range)</span>
              <strong class="is-telemetry-val" id="is-tpl-shelf-range">-</strong>
              <small class="is-telemetry-sub" id="is-tpl-free-shelves">กำลังตรวจสอบจำนวนช่องว่าง...</small>
            </div>
          </div>

          <!-- Monthly Limit Cap Warning / Error Banner -->
          <div id="is-tpl-cap-banner" class="is-banner is-banner-warn hidden" role="alert" aria-live="polite">
            <i class="fa-solid fa-triangle-exclamation is-banner-ico" aria-hidden="true"></i>
            <div class="is-banner-body" id="is-tpl-cap-text"></div>
          </div>

          <div class="is-actions-row">
            <button type="button" class="is-btn-primary" id="is-download-template-btn" onclick="isDownloadTemplate()">
              <i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i>
              <span>ดาวน์โหลดแม่แบบ Excel (.xlsx) / Download Template</span>
            </button>
            <button type="button" class="is-btn-secondary" onclick="isGoToStep(2)">
              <span>กรอกไฟล์เสร็จแล้ว ไปอัปโหลดไฟล์ / Proceed to Upload</span>
              <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </button>
          </div>
        </section>

        <!-- =========================================================
             STEP 2: อัปโหลดไฟล์ Excel (Upload Filled .xlsx)
             ========================================================= -->
        <section id="is-panel-step-2" class="is-panel hidden" role="tabpanel" aria-labelledby="is-step-btn-2">
          <div class="is-guide-card">
            <div class="is-guide-icon" aria-hidden="true"><i class="fa-solid fa-shield-check"></i></div>
            <div class="is-guide-text">
              <strong>การตรวจสอบก่อนบันทึกจริง (Dry-Run Validation):</strong>
              <span>เมื่อเลือกไฟล์แล้ว ระบบจะตรวจสอบ <code>Number Code</code>, <code>Shelf</code>, <code>Status</code> และ <code>Defects</code> ทุกแถวโดยยังไม่บันทึกข้อมูลลงฐานข้อมูล หากพบข้อผิดพลาดแม้แต่แถวเดียว ระบบจะแจ้งรายละเอียดทุกแถวให้แก้ไขก่อน</span>
            </div>
          </div>

          <!-- Drag-and-Drop Zone (4 States: idle / dragover / loading / error) -->
          <div class="is-dropzone" id="is-dropzone" role="button" tabindex="0"
               aria-label="ลากไฟล์ Excel .xlsx มาวางที่นี่ หรือกด Enter เพื่อเลือกไฟล์"
               onclick="isTriggerFilePicker()"
               onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();isTriggerFilePicker();}">
            <input type="file" id="is-file-input" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="hidden" onchange="isHandleFileSelect(event)"/>
            <div class="is-dropzone-icon" id="is-dropzone-icon" aria-hidden="true">
              <i class="fa-solid fa-cloud-arrow-up"></i>
            </div>
            <div class="is-dropzone-text">
              <strong id="is-filename-label">ลากไฟล์แม่แบบ Excel (.xlsx) มาวางที่นี่ หรือคลิกเพื่อเลือกไฟล์</strong>
              <span id="is-dropzone-sub">รองรับเฉพาะไฟล์แม่แบบ .xlsx จากระบบ · ขนาดไม่เกิน 2 MB · สูงสุด 999 แถว</span>
            </div>
            <span class="is-dropzone-cta" id="is-dropzone-cta">
              <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
              <span>เลือกไฟล์ .xlsx จากเครื่อง / Browse File</span>
            </span>
          </div>

          <div id="is-upload-error-banner" class="is-banner is-banner-error hidden" role="alert" aria-live="assertive">
            <i class="fa-solid fa-circle-xmark is-banner-ico" aria-hidden="true"></i>
            <div class="is-banner-body" id="is-upload-error-text"></div>
          </div>

          <div class="is-actions-row">
            <button type="button" class="is-btn-secondary" onclick="isGoToStep(1)">
              <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
              <span>ย้อนกลับไปดาวน์โหลดแม่แบบ / Back to Template</span>
            </button>
            <button type="button" class="is-btn-primary" id="is-recheck-btn" onclick="isRunPreviewValidation()" disabled>
              <i class="fa-solid fa-magnifying-glass-chart" aria-hidden="true"></i>
              <span>ตรวจสอบข้อมูลในไฟล์ / Validate File</span>
            </button>
          </div>
        </section>

        <!-- =========================================================
             STEP 3: ตรวจสอบผลรายแถวและยืนยันนำเข้า (Preview & Commit)
             ========================================================= -->
        <section id="is-panel-step-3" class="is-panel hidden" role="tabpanel" aria-labelledby="is-step-btn-3">
          <!-- Bound Metadata Pill Bar -->
          <div class="is-meta-bar" id="is-preview-meta-bar">
            <div class="is-meta-chips">
              <span class="is-meta-chip">
                <i class="fa-solid fa-layer-group text-emerald-600"></i>
                <span>โซนของไฟล์: <b id="is-meta-zone">-</b></span>
              </span>
              <span class="is-meta-chip">
                <i class="fa-solid fa-table-cells text-cyan-600"></i>
                <span>ชีตปลายทาง: <b id="is-meta-sheet">-</b></span>
              </span>
              <span class="is-meta-chip">
                <i class="fa-solid fa-barcode text-indigo-600"></i>
                <span>ช่วงรหัสที่นำเข้า: <b id="is-meta-codes" class="is-mono">-</b></span>
              </span>
            </div>
            <div class="is-meta-actions">
              <button type="button" class="is-btn-ghost is-btn-xs" onclick="isGoToStep(2)">
                <i class="fa-solid fa-file-arrow-up"></i>
                <span>เลือกไฟล์อื่น / Choose Another File</span>
              </button>
            </div>
          </div>

          <!-- 4 Summary KPI Cards -->
          <div class="is-kpi-grid" role="region" aria-label="สรุปผลการตรวจสอบไฟล์นำเข้า">
            <div class="is-kpi-card is-kpi-blue">
              <span class="is-kpi-label">แถวที่กรอกข้อมูล (Filled)</span>
              <strong class="is-kpi-val" id="is-kpi-filled">0</strong>
              <small class="is-kpi-unit">รายการที่ตรวจพบ</small>
            </div>
            <div class="is-kpi-card is-kpi-emerald">
              <span class="is-kpi-label">ผ่านการตรวจสอบ (Valid)</span>
              <strong class="is-kpi-val" id="is-kpi-valid">0</strong>
              <small class="is-kpi-unit">พร้อมนำเข้า</small>
            </div>
            <div class="is-kpi-card is-kpi-rose">
              <span class="is-kpi-label">พบปัญหา (Errors)</span>
              <strong class="is-kpi-val" id="is-kpi-errors">0</strong>
              <small class="is-kpi-unit">ต้องแก้ไขก่อนบันทึก</small>
            </div>
            <div class="is-kpi-card is-kpi-slate">
              <span class="is-kpi-label">แถวว่างที่ข้าม (Skipped)</span>
              <strong class="is-kpi-val" id="is-kpi-skipped">0</strong>
              <small class="is-kpi-unit">ไม่ถูกบันทึก/ไม่เสียรหัส</small>
            </div>
          </div>

          <!-- Warnings Banner (e.g. UI Sheet Mismatch) -->
          <div id="is-preview-warn-banner" class="is-banner is-banner-warn hidden" role="status" aria-live="polite">
            <i class="fa-solid fa-triangle-exclamation is-banner-ico" aria-hidden="true"></i>
            <div class="is-banner-body" id="is-preview-warn-text"></div>
          </div>

          <!-- Expired Template Banner + Fresh Template CTA -->
          <div id="is-expired-banner" class="is-banner is-banner-error hidden" role="alert" aria-live="assertive">
            <i class="fa-solid fa-clock-rotate-left is-banner-ico" aria-hidden="true"></i>
            <div class="is-banner-body">
              <strong>แม่แบบนี้หมดอายุแล้ว (Template Expired):</strong>
              <span id="is-expired-text"><?= h(IMPORT_TEMPLATE_EXPIRED_ERROR) ?></span>
              <div class="is-banner-cta-row">
                <button type="button" class="is-btn-primary is-btn-sm" onclick="isDownloadFreshTemplateFromExpired()">
                  <i class="fa-solid fa-file-arrow-down" aria-hidden="true"></i>
                  <span>ดาวน์โหลดแม่แบบใหม่ทันที / Download Fresh Template</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Global Validation Errors Banner -->
          <div id="is-preview-error-banner" class="is-banner is-banner-error hidden" role="alert" aria-live="assertive">
            <i class="fa-solid fa-circle-exclamation is-banner-ico" aria-hidden="true"></i>
            <div class="is-banner-body" id="is-preview-error-text"></div>
          </div>

          <!-- All Valid Ready Banner -->
          <div id="is-preview-ok-banner" class="is-banner is-banner-ok hidden" role="status" aria-live="polite">
            <i class="fa-solid fa-circle-check is-banner-ico" aria-hidden="true"></i>
            <div class="is-banner-body" id="is-preview-ok-text">
              ข้อมูลทุกแถวผ่านการตรวจสอบครบถ้วน พร้อมนำเข้าสู่ฐานข้อมูลทันที
            </div>
          </div>

          <!-- Table Toolbar: Filter All vs Errors Only + Download Error Report (.xlsx) -->
          <div class="is-table-toolbar">
            <div class="is-filter-pills" role="group" aria-label="กรองแถวในตารางตรวจสอบ">
              <button type="button" class="is-filter-pill is-active" id="is-filter-all-btn" aria-pressed="true" onclick="isSetRowFilter('all')">
                <span>แสดงทั้งหมด / All (<b id="is-filter-all-count">0</b>)</span>
              </button>
              <button type="button" class="is-filter-pill" id="is-filter-err-btn" aria-pressed="false" onclick="isSetRowFilter('errors')">
                <i class="fa-solid fa-triangle-exclamation text-rose-600" aria-hidden="true"></i>
                <span>เฉพาะที่ผิด / Errors Only (<b id="is-filter-err-count">0</b>)</span>
              </button>
            </div>

            <button type="button" class="is-btn-report hidden" id="is-download-error-report-btn" onclick="isDownloadErrorReportXlsx()">
              <i class="fa-solid fa-file-circle-exclamation" aria-hidden="true"></i>
              <span>ดาวน์โหลดรายการข้อผิดพลาด (.xlsx) / Download Error Report</span>
            </button>
          </div>

          <!-- Row-by-Row Validation Table -->
          <div class="is-table-wrap">
            <table class="is-preview-table" aria-label="ตารางตรวจสอบข้อมูลนำเข้ารายแถว">
              <thead>
                <tr>
                  <th scope="col" class="is-col-row">แถว Excel</th>
                  <th scope="col">Number Code</th>
                  <th scope="col">Shelf</th>
                  <th scope="col">Product Model</th>
                  <th scope="col">Side</th>
                  <th scope="col">Board Type</th>
                  <th scope="col">Status</th>
                  <th scope="col">Other</th>
                  <th scope="col">Defects</th>
                  <th scope="col">Magazine / Slot</th>
                  <th scope="col" class="is-col-result">ผลการตรวจสอบ / รายละเอียด</th>
                </tr>
              </thead>
              <tbody id="is-preview-tbody">
                <tr>
                  <td colspan="11" class="is-table-empty">ยังไม่มีข้อมูลสำหรับแสดงผล กรุณาอัปโหลดไฟล์ในขั้นตอนที่ 2</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Step 3 Actions -->
          <div class="is-actions-row">
            <button type="button" class="is-btn-secondary" onclick="isGoToStep(2)">
              <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
              <span>กลับไปเลือกไฟล์ใหม่ / Upload Another File</span>
            </button>
            <button type="button" class="is-btn-primary" id="is-commit-btn" disabled onclick="isCommitImport()">
              <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
              <span id="is-commit-btn-label">นำเข้าข้อมูลบอร์ด / Import Boards</span>
            </button>
          </div>
        </section>

      </div>

      <!-- Footer Bar -->
      <footer class="is-foot">
        <div class="is-foot-left">
          <i class="fa-solid fa-lock text-emerald-600" aria-hidden="true"></i>
          <span class="is-foot-note">All-or-Nothing Transaction: หากพบข้อมูลซ้ำหรือผิดกติกาแม้แต่ 1 แถว ระบบจะไม่บันทึกรายการใดๆ</span>
        </div>
        <div class="is-foot-right">
          <button type="button" class="is-btn-secondary is-btn-sm" onclick="closeImportStation()">
            <span>ปิดหน้าต่าง / Close Window (Esc)</span>
          </button>
        </div>
      </footer>
    </div>
  </div>
</div>
