/**
 * Defect panel: Dynamic Island open / close morph (WAAPI state machine).
 */

// ── Dynamic Island Morph Controller (WAAPI State Machine: closed | opening | open | closing) ──
const gmWindow   = document.getElementById('gm-window');

const defectSlot = document.getElementById('defect-slot');
const islandSkin = document.getElementById('gm-island-skin');
const gooLayer   = document.getElementById('gm-goo-layer');
const gooBud     = document.getElementById('gm-goo-bud');
const edgeRipple = document.getElementById('gm-edge-ripple');

const islandCtrl = {
    state: 'closed',      // 'closed' | 'opening' | 'open' | 'closing'
    direction: null,      // 'open' | 'close' | null (active keyframe timeline orientation)
    status: null,         // 'D' | 'GD' | null
    anims: [],            // active synchronized WAAPI Animation instances
    pulseAnim: null,      // short WAAPI Animation for D <-> G+D pulse or settle bounce
    rippleAnim: null,     // standalone edge absorb ripple when reversing an open morph
    seq: 0                // monotonic token to ignore stale finish callbacks
};

const isReducedMotion = () =>
    Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

const isStackedStage = () =>
    Boolean(window.matchMedia && window.matchMedia('(max-width: 980px)').matches);

// Lock entrance gm-popin once completed so #gm-window / form fields never replay it
gmStage?.addEventListener('animationend', (e) => {
    if (e.target === gmStage && e.animationName === 'gm-popin') {
        gmStage.classList.add('is-entered');
    }
});

function applyDefectTint(st) {
    if (!defectPanel) return;
    const isMixed = st === 'GD';
    defectPanel.classList.toggle('is-mixed', isMixed);
    defectPanel.classList.toggle('is-danger', !isMixed);
    defectSlot?.classList.toggle('is-mixed', isMixed);
    islandCtrl.status = st;
}

function cancelIslandAnims() {
    while (islandCtrl.anims.length) {
        const a = islandCtrl.anims.pop();
        try {
            a.onfinish = null;
            a.oncancel = null;
            a.cancel();
        } catch (_) {}
    }
}

function playIslandPulse(isSettle = false) {
    if (!defectPanel || isReducedMotion()) return;
    islandCtrl.pulseAnim?.cancel();
    defectPanel.style.willChange = 'transform';
    const keyframes = isSettle
        ? [
            { transform: 'translate3d(0, 0, 0) scale3d(1, 1, 1)' },
            { transform: 'translate3d(0, 0, 0) scale3d(1.032, 1.015, 1)', offset: 0.42 },
            { transform: 'translate3d(0, 0, 0) scale3d(0.996, 0.998, 1)', offset: 0.78 },
            { transform: 'translate3d(0, 0, 0) scale3d(1, 1, 1)' }
        ]
        : [
            { transform: 'translate3d(0, 0, 0) scale3d(1, 1, 1)' },
            { transform: 'translate3d(0, 0, 0) scale3d(1.024, 0.98, 1)', offset: 0.36 },
            { transform: 'translate3d(0, 0, 0) scale3d(0.994, 1.007, 1)', offset: 0.72 },
            { transform: 'translate3d(0, 0, 0) scale3d(1, 1, 1)' }
        ];
    const anim = defectPanel.animate(keyframes, {
        duration: isSettle ? 180 : 210,
        easing: 'cubic-bezier(0.22, 1, 0.36, 1)'
    });
    islandCtrl.pulseAnim = anim;
    anim.onfinish = anim.oncancel = () => {
        if (islandCtrl.pulseAnim === anim) {
            islandCtrl.pulseAnim = null;
            if (islandCtrl.state === 'open') defectPanel.style.willChange = '';
        }
    };
}

function playEdgeAbsorbRipple(edgeX) {
    if (!edgeRipple || isReducedMotion() || isStackedStage()) return;
    islandCtrl.rippleAnim?.cancel();
    const rx = Math.round(edgeX - 6);
    const anim = edgeRipple.animate([
        { opacity: 0, transform: `translate3d(${rx}px, 0px, 0px) scale3d(0.35, 0.35, 1)` },
        { opacity: 0.95, transform: `translate3d(${rx}px, 0px, 0px) scale3d(1.15, 1.08, 1)`, offset: 0.42 },
        { opacity: 0, transform: `translate3d(${rx}px, 0px, 0px) scale3d(1.5, 1.55, 1)` }
    ], {
        duration: 190,
        easing: 'cubic-bezier(0.16, 1, 0.3, 1)'
    });
    islandCtrl.rippleAnim = anim;
    anim.onfinish = anim.oncancel = () => {
        if (islandCtrl.rippleAnim === anim) islandCtrl.rippleAnim = null;
    };
}

function measureStageMetrics() {
    const stacked = isStackedStage();
    const panelW  = (!stacked && defectPanel) ? (defectPanel.offsetWidth || 460) : 460;
    const gap     = 20;
    const shiftX  = stacked ? 0 : (panelW + gap) * 0.5;
    return { stacked, panelW, gap, shiftX };
}

function openDefectPanel(statusOverride) {
    if (!defectPanel || !gmStage) return;
    const st = statusOverride || document.querySelector('#board-form input[name="board_status"]:checked')?.value || 'D';
    applyDefectTint(st);
    gmSyncDefectCount();

    // Fast, lightweight open for low-spec PCs (instant without heavy WAAPI SVG filters)
    islandCtrl.seq++;
    islandCtrl.pulseAnim?.cancel();
    islandCtrl.rippleAnim?.cancel();
    cancelIslandAnims();
    gmStage.classList.add('is-entered', 'has-defects');
    gmStage.classList.remove('is-morphing');
    defectPanel.classList.remove('hidden', 'is-morphing');
    islandCtrl.state = 'open';
    islandCtrl.direction = null;
}

function closeDefectPanel(immediate = false) {
    if (!defectPanel || !gmStage) return;

    // Reset modal entrance lock when modal is being prepared from hidden state
    if (immediate && modal?.classList.contains('hidden')) {
        gmStage.classList.remove('is-entered');
    }

    islandCtrl.seq++;
    islandCtrl.pulseAnim?.cancel();
    islandCtrl.rippleAnim?.cancel();
    cancelIslandAnims();
    defectPanel.classList.add('hidden');
    defectPanel.classList.remove('is-morphing', 'gm-closing');
    gmStage.classList.remove('has-defects', 'is-morphing');
    if (modal && !modal.classList.contains('hidden')) {
        gmStage.classList.add('is-entered');
    }
    islandCtrl.state = 'closed';
    islandCtrl.direction = null;
}

window.openDefectPanel = openDefectPanel;
window.closeDefectPanel = closeDefectPanel;
