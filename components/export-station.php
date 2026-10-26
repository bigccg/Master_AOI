<?php
/**
 * Export Station Modal — Glass HyperOS + Hypermodern Edition
 * Interactive multi-filter control center for exporting organized Excel (.xlsx) & CSV reports,
 * including Master Boards and Defect Matrix: All Size Magazines (All Zones / Selected Zones).
 */

declare(strict_types=1);

$esTopoZones  = topology()['zones'] ?? [];
$esTopoSheets = topology()['sheets'] ?? [];
$esThaiMonths = [
    '01' => 'ม.ค.', '02' => 'ก.พ.', '03' => 'มี.ค.', '04' => 'เม.ย.',
    '05' => 'พ.ค.', '06' => 'มิ.ย.', '07' => 'ก.ค.', '08' => 'ส.ค.',
    '09' => 'ก.ย.', '10' => 'ต.ค.', '11' => 'พ.ย.', '12' => 'ธ.ค.',
];
?>
<!-- ===== Modal: Export Station (Glass HyperOS + Hypermodern) ===== -->
<div id="export-station" class="es-overlay hidden no-print" role="presentation" aria-hidden="true">
  <div class="es-window" id="es-window" role="dialog" aria-modal="true" aria-labelledby="es-title" aria-describedby="es-subtitle">
    <!-- Ambient HyperOS Aurora Orbs -->
    <div class="es-aurora" aria-hidden="true">
      <span class="es-orb es-orb-1"></span>
      <span class="es-orb es-orb-2"></span>
      <span class="es-orb es-orb-3"></span>
    </div>

    <div class="es-surface">
      <!-- Header -->
      <header class="es-head">
        <div class="es-brand">
          <div class="es-icon-badge" aria-hidden="true">
            <i class="fa-solid fa-file-excel"></i>
            <span class="es-icon-pulse"></span>
          </div>
          <div class="es-head-text">
            <div class="es-kicker">
              <span class="es-kicker-pill"><i class="fa-solid fa-wand-magic-sparkles"></i> HYPEROS EXPORT STATION</span>
              <span class="es-live-indicator" id="es-live-status">
                <span class="es-live-dot"></span>
                <span id="es-live-status-text">พร้อมส่งออกข้อมูล</span>
              </span>
            </div>
            <h2 id="es-title" class="es-title">Export Station — ศูนย์ควบคุมการส่งออกรายงาน Excel</h2>
            <p id="es-subtitle" class="es-subtitle">จัดระเบียบตารางอัตโนมัติ · รวมตารางรายการบอร์ดและ Defect Matrix ทุก Shelf ทุกโซน</p>
          </div>
        </div>

        <!-- Format Switcher (XLSX vs CSV) + Close -->
        <div class="es-head-actions">
          <div class="es-format-switch" role="radiogroup" aria-label="รูปแบบไฟล์ส่งออก">
            <span class="es-format-glider" id="es-format-glider" aria-hidden="true"></span>
            <button type="button" class="es-format-btn is-active" data-es-format="xlsx" role="radio" aria-checked="true">
              <i class="fa-solid fa-table"></i>
              <span>Excel (.xlsx)</span>
              <small>จัดตารางสวยงาม</small>
            </button>
            <button type="button" class="es-format-btn" data-es-format="csv" role="radio" aria-checked="false">
              <i class="fa-solid fa-file-csv"></i>
              <span>CSV (.csv)</span>
              <small>ข้อมูลดิบ</small>
            </button>
          </div>

          <button type="button" class="es-close-btn" id="es-close-btn" onclick="closeExportStation()" aria-label="ปิดหน้าต่าง Export Station">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
          </button>
        </div>
      </header>

      <!-- Live Telemetry Metrics Bar -->
      <div class="es-telemetry" style="--es-step: 1">
        <div class="es-metric-card is-primary">
          <div class="es-metric-icon"><i class="fa-solid fa-layer-group"></i></div>
          <div class="es-metric-body">
            <span class="es-metric-label">บอร์ดที่ตรงตามเงื่อนไข (Master Boards)</span>
            <div class="es-metric-row">
              <strong class="es-metric-val" id="es-count-boards">0</strong>
              <span class="es-metric-unit">รายการ</span>
            </div>
            <div class="es-metric-subpills">
              <span class="es-subpill is-g" title="Good [G]">G: <b id="es-count-g">0</b></span>
              <span class="es-subpill is-d" title="Defect [D]">D: <b id="es-count-d">0</b></span>
              <span class="es-subpill is-gd" title="Mixed [G+D]">G+D: <b id="es-count-gd">0</b></span>
            </div>
          </div>
        </div>

        <div class="es-metric-card is-matrix">
          <div class="es-metric-icon"><i class="fa-solid fa-table-cells"></i></div>
          <div class="es-metric-body">
            <span class="es-metric-label">ตาราง Defect Matrix (ทุก Shelf / โซนที่เลือก)</span>
            <div class="es-metric-row">
              <strong class="es-metric-val" id="es-count-matrix">0</strong>
              <span class="es-metric-unit">rows</span>
            </div>
            <div class="es-metric-subpills">
              <span class="es-subpill is-matrix-tag"><i class="fa-solid fa-bug"></i> เฉพาะบอร์ด D และ G+D พร้อมรหัส Defect ครบทุกช่อง</span>
            </div>
          </div>
        </div>

        <div class="es-metric-card is-special">
          <div class="es-metric-icon"><i class="fa-solid fa-microchip"></i></div>
          <div class="es-metric-body">
            <span class="es-metric-label">แผ่นพิเศษ Bare &amp; Bad Mark</span>
            <div class="es-metric-row">
              <strong class="es-metric-val" id="es-count-special">0</strong>
              <span class="es-metric-unit">แผ่น</span>
            </div>
            <div class="es-metric-subpills">
              <span class="es-subpill is-bare">Bare: <b id="es-count-bare">0</b></span>
              <span class="es-subpill is-bad">Bad Mark: <b id="es-count-bad">0</b></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Scrollable Filter Body -->
      <div class="es-body" id="es-body">

        <!-- 1. เลือกตารางที่จะรวมในไฟล์ Excel (Sheets / Tables Selection) -->
        <section class="es-section" style="--es-step: 2">
          <div class="es-sec-head">
            <div class="es-sec-title">
              <span class="es-sec-num">01</span>
              <i class="fa-solid fa-table-columns text-emerald-600"></i>
              <h3>ตารางที่ต้องการส่งออกในไฟล์ Excel (Included Worksheets)</h3>
            </div>
            <span class="es-sec-hint">จัดรูปแบบหัวตาราง สีสถานะ และสรุปยอดรวมอัตโนมัติ</span>
          </div>

          <div class="es-table-cards" role="radiogroup" aria-label="เลือกตารางที่ต้องการส่งออก">
            <button type="button" class="es-table-card is-selected" data-es-tables="both">
              <div class="es-tc-check"><i class="fa-solid fa-check"></i></div>
              <div class="es-tc-icon is-both"><i class="fa-solid fa-clone"></i></div>
              <div class="es-tc-content">
                <div class="es-tc-title">ครบทั้ง 2 ตารางในไฟล์เดียว <span class="es-rec-badge">แนะนำ</span></div>
                <p class="es-tc-desc">Sheet 1: ตารางรายการบอร์ด (Master Boards) + Sheet 2: ตาราง Defect Matrix รวมทุกโซนทุกขนาด</p>
              </div>
            </button>

            <button type="button" class="es-table-card" data-es-tables="matrix">
              <div class="es-tc-check"><i class="fa-solid fa-check"></i></div>
              <div class="es-tc-icon is-matrix"><i class="fa-solid fa-table-cells"></i></div>
              <div class="es-tc-content">
                <div class="es-tc-title">เฉพาะตาราง Defect Matrix</div>
                <p class="es-tc-desc">Defect Matrix: All Size Magazines — รวมทุกโซน (ตารางข้อบกพร่องทุก Shelf ทุกโซน) พร้อมตารางสรุปรหัส</p>
              </div>
            </button>

            <button type="button" class="es-table-card" data-es-tables="boards">
              <div class="es-tc-check"><i class="fa-solid fa-check"></i></div>
              <div class="es-tc-icon is-boards"><i class="fa-solid fa-table-list"></i></div>
              <div class="es-tc-content">
                <div class="es-tc-title">เฉพาะตาราง Master Boards</div>
                <p class="es-tc-desc">ตารางรายการบอร์ดทั้งหมด (Number Code, Shelf, Zone, Product, Side, Type, G+D, Bare/Bad Mark)</p>
              </div>
            </button>
          </div>
        </section>

        <!-- 2. เลือกโซน (Zone Filter) & ขนาด Magazine / ชั้นวาง (Size / Sheet Filter) -->
        <div class="es-grid-2" style="--es-step: 3">
          <!-- 2A: Zones -->
          <section class="es-section">
            <div class="es-sec-head">
              <div class="es-sec-title">
                <span class="es-sec-num">02</span>
                <i class="fa-solid fa-sitemap text-emerald-600"></i>
                <h3>เลือกโซน (Zone Filter)</h3>
              </div>
              <span class="es-sec-hint">เลือกได้หลายโซน</span>
            </div>

            <div class="es-chip-group" id="es-zone-chips">
              <button type="button" class="es-chip is-all is-active" data-es-zone="ALL">
                <span class="es-chip-dot is-emerald"></span>
                <span class="es-chip-label">ทุกโซน (All Zones)</span>
                <span class="es-chip-count" id="es-zcount-ALL">ทั้งหมด</span>
              </button>
              <?php foreach ($esTopoZones as $zKey => $zMeta): ?>
                <?php if (empty($zMeta['sheets'])) continue; ?>
                <button type="button" class="es-chip" data-es-zone="<?= h((string)$zKey) ?>">
                  <i class="fa-solid fa-layer-group es-chip-ico"></i>
                  <span class="es-chip-label"><?= h((string)($zMeta['short_label'] ?: $zKey)) ?></span>
                  <span class="es-chip-count" data-es-zcount="<?= h((string)$zKey) ?>">0</span>
                </button>
              <?php endforeach; ?>
            </div>
          </section>

          <!-- 2B: Sizes / Magazine Sheets -->
          <section class="es-section">
            <div class="es-sec-head">
              <div class="es-sec-title">
                <span class="es-sec-num">03</span>
                <i class="fa-solid fa-boxes-stacked text-cyan-600"></i>
                <h3>เลือกขนาด / ชั้นวาง (Size &amp; Shelf)</h3>
              </div>
              <span class="es-sec-hint">กรองตามขนาดหรือชั้นวาง</span>
            </div>

            <!-- Size Prefix Quick Filters -->
            <div class="es-chip-group" id="es-sheet-chips">
              <button type="button" class="es-chip is-all is-active" data-es-sheet="ALL">
                <span class="es-chip-dot is-cyan"></span>
                <span class="es-chip-label">ทุกขนาด (All Size Magazines)</span>
              </button>
              <?php foreach ($esTopoSheets as $shKey => $shMeta): ?>
                <button type="button" class="es-chip" data-es-sheet="<?= h((string)$shKey) ?>" data-es-sheet-zone="<?= h((string)$shMeta['zone']) ?>" title="<?= h($shMeta['label'] . ' · โซน ' . $shMeta['zone']) ?>">
                  <span class="es-chip-prefix">Size <?= h((string)$shMeta['prefix']) ?></span>
                  <span class="es-chip-label"><?= h((string)$shKey) ?></span>
                  <span class="es-chip-count" data-es-shcount="<?= h((string)$shKey) ?>">0</span>
                </button>
              <?php endforeach; ?>
            </div>
          </section>
        </div>

        <!-- 3. เลือกปี และ เดือน (Year & Month Filter) -->
        <section class="es-section" style="--es-step: 4">
          <div class="es-sec-head">
            <div class="es-sec-title">
              <span class="es-sec-num">04</span>
              <i class="fa-regular fa-calendar-check text-indigo-600"></i>
              <h3>เลือกช่วงเวลา ปี และ เดือน (Year &amp; Month Filter)</h3>
            </div>
            <span class="es-sec-hint">เลือกเฉพาะปีและเดือนที่ต้องการ (เลือกหลายเดือนได้)</span>
          </div>

          <div class="es-period-layout">
            <!-- Year Selector -->
            <div class="es-year-box">
              <span class="es-sub-label"><i class="fa-solid fa-calendar-day"></i> ปี (Year)</span>
              <div class="es-chip-group" id="es-year-chips">
                <button type="button" class="es-chip is-all is-active" data-es-year="ALL">
                  <span class="es-chip-label">ทุกปี (All Years)</span>
                </button>
                <button type="button" class="es-chip" data-es-year="<?= date('Y') ?>">
                  <span class="es-chip-label">ปี <?= date('Y') ?></span>
                  <span class="es-chip-count" data-es-ycount="<?= date('Y') ?>">0</span>
                </button>
              </div>
            </div>

            <!-- Month Selector (Multi-select) -->
            <div class="es-month-box">
              <span class="es-sub-label"><i class="fa-regular fa-calendar"></i> เดือน (Month — กดเลือกได้หลายเดือน)</span>
              <div class="es-month-grid" id="es-month-chips">
                <button type="button" class="es-chip is-all is-active" data-es-month="ALL">
                  <span class="es-chip-label">ทุกเดือน</span>
                </button>
                <?php foreach ($esThaiMonths as $mNum => $mName): ?>
                  <button type="button" class="es-chip es-month-chip" data-es-month="<?= $mNum ?>">
                    <span class="es-month-num"><?= $mNum ?></span>
                    <span class="es-chip-label"><?= $mName ?></span>
                    <span class="es-chip-count" data-es-mcount="<?= $mNum ?>">0</span>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </section>

        <!-- 4. ตัวกรอง Bare / Bad Mark และ สถานะบอร์ด (Special & Status Filters) -->
        <div class="es-grid-2" style="--es-step: 5">
          <!-- 4A: Bare / Bad Mark Filter -->
          <section class="es-section">
            <div class="es-sec-head">
              <div class="es-sec-title">
                <span class="es-sec-num">05</span>
                <i class="fa-solid fa-filter text-violet-600"></i>
                <h3>ตัวกรอง Bare / Bad Mark (Other Filter)</h3>
              </div>
            </div>

            <div class="es-chip-group" id="es-other-chips">
              <button type="button" class="es-chip is-all is-active" data-es-other="ALL">
                <span class="es-chip-label">รวมทั้งหมด (All Boards)</span>
              </button>
              <button type="button" class="es-chip is-bare-chip" data-es-other="Bare">
                <span class="es-chip-dot is-sky"></span>
                <span class="es-chip-label">เฉพาะ Bare เท่านั้น</span>
              </button>
              <button type="button" class="es-chip is-bad-chip" data-es-other="Bad Mark">
                <span class="es-chip-dot is-violet"></span>
                <span class="es-chip-label">เฉพาะ Bad Mark เท่านั้น</span>
              </button>
              <button type="button" class="es-chip is-both-special-chip" data-es-other="BARE_OR_BAD">
                <i class="fa-solid fa-layer-group es-chip-ico"></i>
                <span class="es-chip-label">เฉพาะ Bare + Bad Mark</span>
              </button>
              <button type="button" class="es-chip" data-es-other="NONE">
                <i class="fa-solid fa-ban es-chip-ico text-slate-400"></i>
                <span class="es-chip-label">ไม่รวม Bare / Bad Mark</span>
              </button>
            </div>
          </section>

          <!-- 4B: Status G / D / G+D Filter -->
          <section class="es-section">
            <div class="es-sec-head">
              <div class="es-sec-title">
                <span class="es-sec-num">06</span>
                <i class="fa-solid fa-shield-halved text-amber-600"></i>
                <h3>สถานะบอร์ด (G / D / G+D Status)</h3>
              </div>
            </div>

            <div class="es-chip-group" id="es-status-chips">
              <button type="button" class="es-chip is-all is-active" data-es-status="ALL">
                <span class="es-chip-label">ทุกสถานะ (All)</span>
              </button>
              <button type="button" class="es-chip" data-es-status="DEFECT_ALL">
                <span class="es-chip-dot is-red"></span>
                <span class="es-chip-label">เฉพาะที่มี Defect (D + G+D)</span>
              </button>
              <button type="button" class="es-chip" data-es-status="G">
                <span class="es-chip-dot is-emerald"></span>
                <span class="es-chip-label">เฉพาะ Good [G]</span>
              </button>
              <button type="button" class="es-chip" data-es-status="D">
                <span class="es-chip-dot is-red"></span>
                <span class="es-chip-label">เฉพาะ Defect [D]</span>
              </button>
              <button type="button" class="es-chip" data-es-status="GD">
                <span class="es-chip-dot is-amber"></span>
                <span class="es-chip-label">เฉพาะ Mixed [G+D]</span>
              </button>
            </div>
          </section>
        </div>

      </div>

      <!-- Footer Action Bar -->
      <footer class="es-foot" style="--es-step: 6">
        <div class="es-foot-left">
          <button type="button" class="es-reset-btn" id="es-reset-btn" onclick="resetExportStationFilters()">
            <i class="fa-solid fa-rotate-left"></i>
            <span>รีเซ็ตตัวกรองทั้งหมด</span>
          </button>
          <span class="es-summary-text" id="es-summary-text">พร้อมส่งออกทุกโซน · ทุกขนาด · ทุกช่วงเวลา</span>
        </div>

        <div class="es-foot-right">
          <button type="button" class="es-cancel-btn" onclick="closeExportStation()">
            <span>ยกเลิก</span>
          </button>
          <button type="button" class="es-submit-btn" id="es-submit-btn" onclick="triggerExportStationDownload()">
            <span class="es-submit-shimmer" aria-hidden="true"></span>
            <i class="fa-solid fa-file-arrow-down es-submit-ico" id="es-submit-ico"></i>
            <span id="es-submit-label">ส่งออก Excel (.xlsx) จัดตาราง</span>
            <span class="es-submit-badge" id="es-submit-badge">324 รายการ</span>
          </button>
        </div>
      </footer>
    </div>
  </div>
</div>
