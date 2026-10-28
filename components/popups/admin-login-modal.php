<?php
/**
 * Glass Admin Sign-in Modal (Full AJAX - No Page Reload)
 * JS: assets/js/components/admin-auth.js
 */
?>
<!-- ===== Popup: Administrator Sign-in (Glass AJAX Modal) ===== -->
<div id="admin-login-modal" class="gm-overlay hidden no-print" role="presentation" aria-hidden="true">
  <div class="gm-window sm" role="dialog" aria-modal="true" aria-labelledby="admin-login-title">
    <span class="gm-glow"></span><span class="gm-ring"></span>
    <div class="gm-surface">
      <i class="gm-orb o1"></i><i class="gm-orb o2"></i><i class="gm-orb o3"></i>
      <div class="gm-confirm-body text-center p-6">
        <div class="w-12 h-12 mx-auto rounded-full bg-emerald-100/80 text-emerald-600 flex items-center justify-center text-xl mb-3 shadow-inner">
          <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        </div>
        <h3 id="admin-login-title" class="text-base font-bold text-slate-800 tracking-tight">เข้าสู่ระบบผู้ดูแล</h3>
        <p class="text-xs text-slate-500 mb-4">สำหรับจัดการโซน, Shelf และระบบสำรองข้อมูล / Administrator Sign-in</p>

        <!-- Error banner -->
        <div id="admin-login-error" class="hidden mb-3 p-2.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs text-left flex items-start gap-2">
          <i class="fa-solid fa-circle-exclamation shrink-0 mt-0.5" aria-hidden="true"></i>
          <span id="admin-login-error-text" class="leading-relaxed"></span>
        </div>

        <form id="admin-login-form" onsubmit="handleAdminLoginSubmit(event)" autocomplete="off">
          <input type="hidden" id="admin-login-csrf" name="csrf" value="<?= h(csrf()) ?>">
          <div class="text-left mb-4">
            <label for="admin-login-password" class="block text-xs font-semibold text-slate-700 mb-1">
              รหัสผ่านผู้ดูแล <span class="text-rose-500">*</span>
            </label>
            <div class="relative">
              <input type="password" id="admin-login-password" name="password" required autofocus
                     placeholder="กรอกรหัสผ่านผู้ดูแล..."
                     class="w-full text-xs rounded-lg border border-slate-300 py-2 pl-3 pr-9 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 font-mono transition">
              <button type="button" id="admin-password-toggle" onclick="toggleAdminPasswordVisibility()"
                      title="แสดง/ซ่อนรหัสผ่าน" tabindex="-1"
                      class="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1">
                <i class="fa-solid fa-eye text-xs" id="admin-password-toggle-icon"></i>
              </button>
            </div>
          </div>

          <div class="gm-confirm-actions flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
            <button type="button" onclick="closeAdminLoginModal()" class="gm-btn ghost text-xs py-1.5 px-3 rounded-md">
              ยกเลิก / Cancel
            </button>
            <button type="submit" id="admin-login-submit-btn" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 text-xs font-semibold rounded-md bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition">
              <span id="admin-login-spinner" class="hidden"><i class="fa-solid fa-circle-notch fa-spin"></i></span>
              <span id="admin-login-btn-text">เข้าสู่ระบบ / Sign in</span>
            </button>
          </div>
        </form>
      </div>

      <div class="gm-status" style="margin-top:0">
        <span class="gm-mode"><i class="fa-solid fa-lock" style="font-size:9px"></i>ADMIN AUTH</span>
        <span class="gm-seg-info">AJAX <b>→</b> Zero Reload</span>
        <span class="gm-keys"><span><kbd>Esc</kbd> ปิด</span> <span><kbd>Enter</kbd> ยืนยัน</span></span>
      </div>
    </div>
  </div>
</div>
