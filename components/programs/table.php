<?php
/**
 * Data Table Component for AOI Programs
 */

declare(strict_types=1);
?>
<!-- 4. MAIN DATA TABLE -->
<div class="prog-table-card">
  <!-- Table Scroll Wrapper -->
  <div class="prog-table-scroll" id="prog-table-scroll">
    <table class="prog-table" id="prog-table" aria-label="ตารางข้อมูลโปรแกรม AOI">
      <thead>
        <tr>
          <!-- Checkbox Column (Sticky) -->
          <th scope="col" class="prog-th prog-col-check prog-th-sticky-check">
            <input type="checkbox" id="prog-check-all" class="rounded accent-emerald-600 w-3.5 h-3.5 cursor-pointer align-middle" aria-label="เลือกทั้งหมดในหน้านี้">
          </th>

          <!-- Product Model Column (Sticky) -->
          <th scope="col" class="prog-th prog-col-product prog-th-sticky-product is-sortable" data-sort="product_model" aria-sort="none">
            <div class="flex items-center justify-between">
              <span>Product / รุ่นชิ้นงาน</span>
              <i class="fa-solid fa-sort prog-th-sort-icon" aria-hidden="true"></i>
            </div>
          </th>

          <!-- Machine Type Column -->
          <th scope="col" class="prog-th prog-col-machine is-sortable" data-sort="machine_type" aria-sort="none">
            <div class="flex items-center justify-between">
              <span>เครื่อง / Mach</span>
              <i class="fa-solid fa-sort prog-th-sort-icon" aria-hidden="true"></i>
            </div>
          </th>

          <!-- 4-Stage Progress Stepper Column -->
          <th scope="col" class="prog-th prog-col-stepper">
            <div class="flex items-center justify-between">
              <span>ความคืบหน้า 4 ขั้น / 4-Stage Stepper</span>
            </div>
          </th>

          <!-- Master Board Status Column -->
          <th scope="col" class="prog-th prog-col-master">
            <span>Master บอร์ด</span>
          </th>

          <!-- Shelf Location Column -->
          <th scope="col" class="prog-th prog-col-shelf">
            <span>Shelf ตำแหน่ง</span>
          </th>

          <!-- Owner Column -->
          <th scope="col" class="prog-th prog-col-owner is-sortable" data-sort="owner" aria-sort="none">
            <div class="flex items-center justify-between">
              <span>ผู้ทำ / Owner</span>
              <i class="fa-solid fa-sort prog-th-sort-icon" aria-hidden="true"></i>
            </div>
          </th>

          <!-- Updated At Column -->
          <th scope="col" class="prog-th prog-col-updated is-sortable" data-sort="updated_at" aria-sort="descending">
            <div class="flex items-center justify-between">
              <span>อัปเดตล่าสุด</span>
              <i class="fa-solid fa-sort-down prog-th-sort-icon text-emerald-600 opacity-100" aria-hidden="true"></i>
            </div>
          </th>

          <!-- Note Column -->
          <th scope="col" class="prog-th prog-col-note">
            <span>หมายเหตุ / Note</span>
          </th>

          <!-- Action Column -->
          <th scope="col" class="prog-th prog-col-action text-center">
            <span>จัดการ</span>
          </th>
        </tr>
      </thead>
      <tbody id="prog-table-body">
        <!-- Rendered dynamically by assets/js/components/programs/table.js -->
        <!-- Initial skeleton rows for smooth loading -->
        <?php for ($i = 0; $i < 10; $i++): ?>
          <tr class="prog-row animate-pulse">
            <td class="prog-td text-center"><div class="w-3.5 h-3.5 bg-slate-200 rounded mx-auto"></div></td>
            <td class="prog-td"><div class="h-4 w-32 bg-slate-200 rounded"></div></td>
            <td class="prog-td"><div class="h-4 w-16 bg-slate-200 rounded"></div></td>
            <td class="prog-td"><div class="h-6 w-44 bg-slate-200 rounded"></div></td>
            <td class="prog-td"><div class="h-4 w-20 bg-slate-200 rounded"></div></td>
            <td class="prog-td"><div class="h-4 w-14 bg-slate-200 rounded"></div></td>
            <td class="prog-td"><div class="h-4 w-20 bg-slate-200 rounded"></div></td>
            <td class="prog-td"><div class="h-4 w-16 bg-slate-200 rounded"></div></td>
            <td class="prog-td"><div class="h-4 w-28 bg-slate-200 rounded"></div></td>
            <td class="prog-td text-center"><div class="h-4 w-8 bg-slate-200 rounded mx-auto"></div></td>
          </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination & Total Summary Bar -->
  <div class="prog-pagination-bar" id="prog-pagination-bar">
    <div class="flex items-center gap-2">
      <span id="prog-paging-summary">กำลังโหลดข้อมูล…</span>
      <span class="text-slate-300">·</span>
      <span class="font-mono text-slate-400 text-xs" id="prog-paging-sub">คำนวณจากฐานข้อมูลจริง</span>
    </div>
    <div class="flex items-center gap-1" id="prog-paging-controls">
      <!-- Paging buttons injected by JS -->
    </div>
  </div>
</div>
