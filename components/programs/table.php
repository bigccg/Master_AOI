<?php
/**
 * Data Table Component for AOI Programs
 * 9 columns: Product Model (sticky), Side (sortable), Machine, 4-Stage Pipeline,
 * Master Samples (D / G+D live from index.php), Shelf, Operator, Updated, Note / Reason
 */

declare(strict_types=1);
?>
<!-- 3. MAIN DATA TABLE -->
<div class="bg-white rounded-lg border border-slate-300 shadow-sm overflow-hidden flex flex-col">
  <!-- Table Scroll Wrapper -->
  <div class="aoi-table-scroll" id="prog-table-scroll">
    <table class="aoi-table density-<?= h($density) ?>" id="prog-table" aria-label="ตารางติดตามสถานะโปรแกรม AOI">
      <thead>
        <tr>
          <!-- 1. Product Model Column (Sticky) -->
          <th scope="col" class="aoi-th prog-th prog-th-sticky-product is-sortable" data-col="product" data-sort="product_model" aria-sort="none">
            <div class="inline-flex items-center justify-center gap-1.5 cursor-pointer">
              <span>Product Model</span>
              <i class="fa-solid fa-sort prog-th-sort-icon opacity-60 text-[10px]" aria-hidden="true"></i>
            </div>
          </th>

          <!-- 2. Side Column (Sortable, non-sticky) -->
          <th scope="col" class="aoi-th prog-th is-sortable" data-col="side" data-sort="side" aria-sort="none">
            <div class="inline-flex items-center justify-center gap-1.5 cursor-pointer">
              <span>Side</span>
              <i class="fa-solid fa-sort prog-th-sort-icon opacity-60 text-[10px]" aria-hidden="true"></i>
            </div>
          </th>

          <!-- 3. Machine Type Column -->
          <th scope="col" class="aoi-th prog-th is-sortable" data-col="machine" data-sort="machine_type" aria-sort="none">
            <div class="inline-flex items-center justify-center gap-1.5 cursor-pointer">
              <span>Machine</span>
              <i class="fa-solid fa-sort prog-th-sort-icon opacity-60 text-[10px]" aria-hidden="true"></i>
            </div>
          </th>

          <!-- 4. 4-Stage Progress Stepper Column -->
          <th scope="col" class="aoi-th prog-th" data-col="stepper">
            <span>4-Stage Pipeline</span>
          </th>

          <!-- 5. Master Board Status Column (Live D / G+D from index.php) -->
          <th scope="col" class="aoi-th prog-th" data-col="master">
            <span>Master Samples</span>
          </th>

          <!-- 6. Shelf Location Column -->
          <th scope="col" class="aoi-th prog-th" data-col="shelf">
            <span>Shelf</span>
          </th>

          <!-- 7. Operator Column -->
          <th scope="col" class="aoi-th prog-th is-sortable" data-col="owner" data-sort="owner" aria-sort="none">
            <div class="inline-flex items-center justify-center gap-1.5 cursor-pointer">
              <span>Operator</span>
              <i class="fa-solid fa-sort prog-th-sort-icon opacity-60 text-[10px]" aria-hidden="true"></i>
            </div>
          </th>

          <!-- 8. Updated At Column -->
          <th scope="col" class="aoi-th prog-th is-sortable" data-col="updated" data-sort="updated_at" aria-sort="descending">
            <div class="inline-flex items-center justify-center gap-1.5 cursor-pointer">
              <span>Updated</span>
              <i class="fa-solid fa-sort-down prog-th-sort-icon text-white text-[10px]" aria-hidden="true"></i>
            </div>
          </th>

          <!-- 9. Note Column -->
          <th scope="col" class="aoi-th prog-th" data-col="note">
            <span>Note / Reason</span>
          </th>
        </tr>
      </thead>
      <tbody id="prog-table-body" class="aoi-tbody">
        <!-- Rendered dynamically by assets/js/components/programs/table.js -->
        <?php for ($i = 0; $i < 10; $i++): ?>
          <tr class="aoi-row animate-pulse">
            <td class="aoi-td prog-td-sticky-product"><div class="h-4 w-32 bg-slate-200 rounded mx-auto"></div></td>
            <td class="aoi-td"><div class="h-4 w-8 bg-slate-200 rounded mx-auto"></div></td>
            <td class="aoi-td"><div class="h-4 w-12 bg-slate-200 rounded mx-auto"></div></td>
            <td class="aoi-td"><div class="h-6 w-44 bg-slate-200 rounded mx-auto"></div></td>
            <td class="aoi-td"><div class="h-4 w-16 bg-slate-200 rounded mx-auto"></div></td>
            <td class="aoi-td"><div class="h-4 w-14 bg-slate-200 rounded mx-auto"></div></td>
            <td class="aoi-td"><div class="h-4 w-20 bg-slate-200 rounded mx-auto"></div></td>
            <td class="aoi-td"><div class="h-4 w-16 bg-slate-200 rounded mx-auto"></div></td>
            <td class="aoi-td"><div class="h-4 w-28 bg-slate-200 rounded mx-auto"></div></td>
          </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination & Total Summary Bar -->
  <div id="prog-pagination-bar" class="border-t border-slate-300 bg-white px-4 py-2.5 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-700">
    <div class="flex items-center gap-2">
      <span id="prog-paging-summary" class="font-medium text-slate-600">กำลังโหลดข้อมูล…</span>
      <span class="text-slate-300 hidden sm:inline">·</span>
      <span class="font-mono text-slate-400 text-[11px] hidden sm:inline" id="prog-paging-sub">เชื่อมโยงข้อมูลสดจาก Master Board</span>
    </div>
    <div class="flex items-center gap-1" id="prog-paging-controls">
      <!-- Paging buttons injected by JS -->
    </div>
  </div>
</div>
