/**
 * Admin Authentication Controller (Full AJAX - Zero Page Refreshes)
 * Handles in-place sign-in, sign-out, session status, and header UI morphing.
 */

window.isAdmin = window.isAdmin || false;

function openAdminLoginModal() {
    const modal = document.getElementById('admin-login-modal');
    if (!modal) return;

    const errorBox = document.getElementById('admin-login-error');
    if (errorBox) errorBox.classList.add('hidden');

    const pwdInput = document.getElementById('admin-login-password');
    if (pwdInput) {
        pwdInput.value = '';
        pwdInput.type = 'password';
    }

    const icon = document.getElementById('admin-password-toggle-icon');
    if (icon) {
        icon.className = 'fa-solid fa-eye text-xs';
    }

    const submitBtn = document.getElementById('admin-login-submit-btn');
    const spinner = document.getElementById('admin-login-spinner');
    if (submitBtn) submitBtn.disabled = false;
    if (spinner) spinner.classList.add('hidden');

    if (typeof gmOpen === 'function') {
        gmOpen(modal);
    } else {
        modal.classList.remove('hidden');
    }

    setTimeout(() => {
        pwdInput?.focus();
    }, 150);
}
window.openAdminLoginModal = openAdminLoginModal;

function closeAdminLoginModal() {
    const modal = document.getElementById('admin-login-modal');
    if (!modal) return;
    if (typeof gmClose === 'function') {
        gmClose(modal);
    } else {
        modal.classList.add('hidden');
    }
}
window.closeAdminLoginModal = closeAdminLoginModal;

function toggleAdminPasswordVisibility() {
    const pwdInput = document.getElementById('admin-login-password');
    const icon = document.getElementById('admin-password-toggle-icon');
    if (!pwdInput) return;

    if (pwdInput.type === 'password') {
        pwdInput.type = 'text';
        if (icon) icon.className = 'fa-solid fa-eye-slash text-xs';
    } else {
        pwdInput.type = 'password';
        if (icon) icon.className = 'fa-solid fa-eye text-xs';
    }
}
window.toggleAdminPasswordVisibility = toggleAdminPasswordVisibility;

async function handleAdminLoginSubmit(e) {
    if (e) e.preventDefault();

    const pwdInput = document.getElementById('admin-login-password');
    const csrfInput = document.getElementById('admin-login-csrf');
    const errorBox = document.getElementById('admin-login-error');
    const errorText = document.getElementById('admin-login-error-text');
    const submitBtn = document.getElementById('admin-login-submit-btn');
    const spinner = document.getElementById('admin-login-spinner');
    const btnText = document.getElementById('admin-login-btn-text');

    const password = pwdInput ? pwdInput.value : '';
    const csrf = csrfInput ? csrfInput.value : (window.CSRF_TOKEN || '');

    if (!password.trim()) {
        if (errorBox && errorText) {
            errorText.textContent = 'กรุณากรอกรหัสผ่านผู้ดูแล / Please enter administrator password.';
            errorBox.classList.remove('hidden');
        }
        return;
    }

    if (submitBtn) submitBtn.disabled = true;
    if (spinner) spinner.classList.remove('hidden');
    if (btnText) btnText.textContent = 'กำลังตรวจสอบ...';
    if (errorBox) errorBox.classList.add('hidden');

    try {
        const res = await fetch('api/admin_auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'login',
                password: password,
                csrf: csrf
            })
        });

        const data = await res.json();

        if (data.csrf) {
            updatePageCsrfTokens(data.csrf);
        }

        if (res.ok && data.success) {
            window.isAdmin = true;
            closeAdminLoginModal();
            updateHeaderAdminState(true);

            if (typeof showFlashToast === 'function') {
                showFlashToast(data.message || 'เข้าสู่ระบบผู้ดูแลสำเร็จ', 'success');
            } else if (typeof showFlash === 'function') {
                showFlash('ok', data.message || 'เข้าสู่ระบบผู้ดูแลสำเร็จ');
            }

            // If admin modals are not yet in the DOM, seamlessly fetch authenticated view via SPA Router
            if (!document.getElementById('topology-manager') && typeof spaNavigate === 'function') {
                await spaNavigate(window.location.href, false);
            }

            // If user originally clicked Manage or Backup, open that dialog
            const params = new URLSearchParams(window.location.search);
            if ((params.get('manage') === '1' || window.__pendingManageOpen) && typeof openTopologyManager === 'function') {
                window.__pendingManageOpen = false;
                openTopologyManager();
            } else if ((params.get('backup') === '1' || window.__pendingBackupOpen) && typeof openBackupManager === 'function') {
                window.__pendingBackupOpen = false;
                openBackupManager();
            }
        } else {
            const err = data.error || 'รหัสผ่านไม่ถูกต้อง หรือเซสชันหมดอายุ กรุณาลองใหม่';
            if (errorBox && errorText) {
                errorText.textContent = err;
                errorBox.classList.remove('hidden');
            }
            if (pwdInput) {
                pwdInput.focus();
                pwdInput.select();
            }
        }
    } catch (err) {
        console.error('Admin login error:', err);
        if (errorBox && errorText) {
            errorText.textContent = 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบเครือข่าย / Network error.';
            errorBox.classList.remove('hidden');
        }
    } finally {
        if (submitBtn) submitBtn.disabled = false;
        if (spinner) spinner.classList.add('hidden');
        if (btnText) btnText.textContent = 'เข้าสู่ระบบ / Sign in';
    }
}
window.handleAdminLoginSubmit = handleAdminLoginSubmit;

async function handleAdminLogout(e) {
    if (e) e.preventDefault();

    const csrfInput = document.getElementById('admin-login-csrf');
    const csrf = csrfInput ? csrfInput.value : (window.CSRF_TOKEN || '');

    try {
        const res = await fetch('api/admin_auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'logout',
                csrf: csrf
            })
        });

        const data = await res.json();

        if (data.csrf) {
            updatePageCsrfTokens(data.csrf);
        }

        window.isAdmin = false;
        updateHeaderAdminState(false);

        // Close any admin-only modals if open
        if (typeof closeTopologyManager === 'function') closeTopologyManager();
        if (typeof closeBackupManager === 'function') closeBackupManager();

        if (typeof showFlashToast === 'function') {
            showFlashToast(data.message || 'ออกจากระบบผู้ดูแลเรียบร้อยแล้ว', 'info');
        } else if (typeof showFlash === 'function') {
            showFlash('ok', data.message || 'ออกจากระบบผู้ดูแลเรียบร้อยแล้ว');
        }
    } catch (err) {
        console.error('Admin logout error:', err);
    }
}
window.handleAdminLogout = handleAdminLogout;

function updatePageCsrfTokens(newCsrf) {
    if (!newCsrf) return;
    window.CSRF_TOKEN = newCsrf;
    document.querySelectorAll('input[name="csrf"], input[id$="-csrf"]').forEach(el => {
        el.value = newCsrf;
    });
}

/**
 * Dynamically show/hide Admin controls in the header without page refresh
 */
function updateHeaderAdminState(isAdmin) {
    const adminContainer = document.getElementById('header-admin-controls');
    if (!adminContainer) return;

    if (isAdmin) {
        adminContainer.innerHTML = `
            <button type="button" id="bm-open-btn" onclick="typeof openBackupManager === 'function' ? openBackupManager() : null" aria-haspopup="dialog" aria-controls="backup-manager" title="สำรองข้อมูลรายเดือน เปรียบเทียบความต่าง (Diff) และย้อนข้อมูลกลับ (Restore)" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm transition cursor-pointer whitespace-nowrap shrink-0">
              <i class="fa-solid fa-clock-rotate-left text-indigo-600" aria-hidden="true"></i><span>สำรอง/ย้อนข้อมูล</span>
            </button>
            <button type="button" id="tm-open-btn" onclick="typeof openTopologyManager === 'function' ? openTopologyManager() : null" aria-haspopup="dialog" aria-controls="topology-manager" title="เพิ่ม แก้ไข หรือปิดใช้งานโซนและ Shelf" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm transition cursor-pointer whitespace-nowrap shrink-0">
              <i class="fa-solid fa-sitemap text-emerald-600" aria-hidden="true"></i><span>จัดการโซนและ Shelf</span>
            </button>
            <button type="button" id="admin-logout-btn" onclick="handleAdminLogout(event)" title="ออกจากระบบผู้ดูแล / Sign out" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-md bg-white border border-slate-300 hover:bg-rose-50 hover:text-rose-600 text-slate-700 shadow-sm transition cursor-pointer">
              <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span class="sr-only sm:not-sr-only text-[11px]">ออก</span>
            </button>
        `;
    } else {
        adminContainer.innerHTML = `
            <button type="button" id="admin-signin-btn" onclick="openAdminLoginModal()" title="เข้าสู่ระบบผู้ดูแล (สำหรับจัดการโซนและ Shelf) / Administrator sign-in" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-md text-slate-500 hover:text-emerald-700 hover:bg-emerald-50/60 border border-transparent hover:border-emerald-200 transition shrink-0 cursor-pointer">
              <i class="fa-solid fa-shield-halved text-emerald-600" aria-hidden="true"></i>
              <span class="hidden sm:inline">ผู้ดูแล</span>
            </button>
        `;
    }
}
window.updateHeaderAdminState = updateHeaderAdminState;

document.addEventListener('DOMContentLoaded', () => {
    // Intercept clicks on any admin signin links
    document.querySelectorAll('a[href*="admin.php"]').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            openAdminLoginModal();
        });
    });

    // Intercept any admin logout form submissions
    document.querySelectorAll('form[action*="admin.php"]').forEach(form => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            handleAdminLogout(e);
        });
    });
});
