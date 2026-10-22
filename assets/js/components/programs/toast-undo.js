/**
 * AOI Program Tracker - Real-time Toast & Undo
 */

let toastTimer = null;
window.currentUndoAction = null;

function showProgToast(title, desc, undoAction = null, durationMs = 8000) {
  const container = document.getElementById('prog-toast-container');
  if (!container) return;

  // Clear previous toast
  container.innerHTML = '';
  if (toastTimer) {
    clearTimeout(toastTimer);
    toastTimer = null;
  }

  window.currentUndoAction = undoAction;

  const toast = document.createElement('div');
  toast.className = 'prog-toast';
  toast.setAttribute('role', 'alert');

  toast.innerHTML = `
    <div class="prog-toast-body">
      <div class="prog-toast-content">
        <div class="prog-toast-icon">
          <i class="fa-solid fa-check"></i>
        </div>
        <div class="min-w-0">
          <div class="prog-toast-title">${h(title)}</div>
          <div class="prog-toast-desc">${h(desc)}</div>
        </div>
      </div>
      ${undoAction ? `<button type="button" class="prog-toast-undo-btn" id="toast-undo-btn">เลิกทำ / Undo <kbd class="ml-1 text-[10px] opacity-70">Ctrl+Z</kbd></button>` : ''}
    </div>
    <div class="prog-toast-progress-track">
      <div class="prog-toast-progress-bar" id="toast-progress-bar" style="transition-duration: ${durationMs}ms;"></div>
    </div>
  `;

  container.appendChild(toast);

  // Trigger progress bar countdown
  const pBar = toast.querySelector('#toast-progress-bar');
  requestAnimationFrame(() => {
    if (pBar) pBar.style.width = '0%';
  });

  const triggerUndo = async () => {
    if (!window.currentUndoAction) return;
    const action = window.currentUndoAction;
    window.currentUndoAction = null;
    clearTimeout(toastTimer);
    container.innerHTML = '';
    try {
      await action();
    } catch (err) {
      showProgErrorToast('ไม่สามารถเลิกทำได้', err.message);
    }
  };

  if (undoAction) {
    const undoBtn = toast.querySelector('#toast-undo-btn');
    undoBtn?.addEventListener('click', triggerUndo);
  }

  toastTimer = setTimeout(() => {
    window.currentUndoAction = null;
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(10px)';
    toast.style.transition = 'all 0.25s ease';
    setTimeout(() => {
      container.innerHTML = '';
    }, 260);
  }, durationMs);
}

function showProgErrorToast(title, desc, reloadAction = null) {
  const container = document.getElementById('prog-toast-container');
  if (!container) return;

  container.innerHTML = '';
  if (toastTimer) {
    clearTimeout(toastTimer);
    toastTimer = null;
  }
  window.currentUndoAction = null;

  const toast = document.createElement('div');
  toast.className = 'prog-toast border-red-200 bg-red-50 text-red-900 shadow-md';
  toast.setAttribute('role', 'alert');

  toast.innerHTML = `
    <div class="prog-toast-body">
      <div class="prog-toast-content">
        <div class="prog-toast-icon bg-red-500 text-white">
          <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div class="min-w-0">
          <div class="prog-toast-title font-semibold text-red-950">${h(title)}</div>
          <div class="prog-toast-desc text-red-800">${h(desc)}</div>
        </div>
      </div>
      ${reloadAction ? `<button type="button" class="prog-toast-undo-btn bg-red-600 hover:bg-red-700 text-white border-none" id="toast-reload-btn">โหลดข้อมูลใหม่</button>` : ''}
    </div>
  `;

  container.appendChild(toast);

  if (reloadAction) {
    toast.querySelector('#toast-reload-btn')?.addEventListener('click', () => {
      container.innerHTML = '';
      reloadAction();
    });
  }

  toastTimer = setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(10px)';
    toast.style.transition = 'all 0.25s ease';
    setTimeout(() => {
      container.innerHTML = '';
    }, 260);
  }, 10000);
}

// Global Ctrl+Z handler
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !e.shiftKey) {
    // If inside an input or textarea, let default input undo work
    const active = document.activeElement;
    if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA')) {
      return;
    }
    if (window.currentUndoAction) {
      e.preventDefault();
      const action = window.currentUndoAction;
      window.currentUndoAction = null;
      action();
    }
  }
});
