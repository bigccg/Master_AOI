/**
 * AOI Program Tracker - Real-time Toast & Undo (Stage & Metadata updates only)
 */

let toastTimer = null;
window.currentUndoAction = null;

function showProgToast(title, desc, undoAction = null, durationMs = 8000) {
  const container = document.getElementById('prog-toast-container');
  if (!container) return;

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
          <i class="fa-solid fa-check" aria-hidden="true"></i>
        </div>
        <div class="min-w-0">
          <div class="prog-toast-title">${h(title)}</div>
          <div class="prog-toast-desc">${h(desc)}</div>
        </div>
      </div>
      ${undoAction ? `<button type="button" class="prog-toast-undo-btn" id="toast-undo-btn">เลิกทำ <kbd class="ml-1 text-[10px] opacity-70">Ctrl+Z</kbd></button>` : ''}
    </div>
    <div class="prog-toast-progress-track">
      <div class="prog-toast-progress-bar" id="toast-progress-bar" style="transition-duration: ${durationMs}ms;"></div>
    </div>
  `;

  container.appendChild(toast);

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
      showProgErrorToast('เลิกทำไม่สำเร็จ', err.message);
    }
  };

  if (undoAction) {
    const undoBtn = toast.querySelector('#toast-undo-btn');
    undoBtn?.addEventListener('click', triggerUndo);
  }

  toastTimer = setTimeout(() => {
    window.currentUndoAction = null;
    toast.classList.add('is-leaving');
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
  toast.className = 'prog-toast is-error';
  toast.setAttribute('role', 'alert');

  toast.innerHTML = `
    <div class="prog-toast-body">
      <div class="prog-toast-content">
        <div class="prog-toast-icon is-error">
          <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        </div>
        <div class="min-w-0">
          <div class="prog-toast-title">${h(title)}</div>
          <div class="prog-toast-desc">${h(desc)}</div>
        </div>
      </div>
      ${reloadAction ? `<button type="button" class="prog-toast-undo-btn is-error" id="toast-reload-btn">โหลดข้อมูลใหม่</button>` : ''}
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
    toast.classList.add('is-leaving');
    setTimeout(() => {
      container.innerHTML = '';
    }, 260);
  }, 10000);
}

// Global Ctrl+Z handler
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !e.shiftKey) {
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
