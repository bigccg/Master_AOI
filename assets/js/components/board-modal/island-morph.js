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

    // 1. Already open: cross-fade tint (via CSS) + play tiny squash/stretch pulse (< 250ms)
    if (islandCtrl.state === 'open') {
        gmStage.classList.add('has-defects');
        defectPanel.classList.remove('hidden');
        playIslandPulse(false);
        return;
    }

    // 2. Reduced motion: instant cross-fade, no spring
    if (isReducedMotion()) {
        islandCtrl.seq++;
        islandCtrl.pulseAnim?.cancel();
        cancelIslandAnims();
        gmStage.classList.add('is-entered', 'has-defects');
        gmStage.classList.remove('is-morphing');
        defectPanel.classList.remove('hidden', 'is-morphing');
        islandCtrl.state = 'open';
        islandCtrl.direction = null;
        return;
    }

    // 3. Already opening: just let the running morph continue with the updated tint
    if (islandCtrl.state === 'opening' && islandCtrl.anims.length > 0) {
        return;
    }

    // 4. Interrupted while closing: reverse smoothly from exact current position/shape
    if (islandCtrl.state === 'closing' && islandCtrl.anims.length > 0) {
        islandCtrl.state = 'opening';
        gmStage.classList.add('has-defects', 'is-morphing');
        defectPanel.classList.remove('hidden');
        defectPanel.classList.add('is-morphing');
        const targetRate = islandCtrl.direction === 'open' ? 1 : -1.12;
        islandCtrl.anims.forEach(a => {
            a.updatePlaybackRate(targetRate);
            if (a.playState !== 'running') a.play();
        });
        return;
    }

    // 5. Fresh open from 'closed'
    const seq = ++islandCtrl.seq;
    islandCtrl.pulseAnim?.cancel();
    islandCtrl.rippleAnim?.cancel();
    cancelIslandAnims();

    islandCtrl.state = 'opening';
    islandCtrl.direction = 'open';

    gmStage.classList.add('has-defects', 'is-morphing');
    defectPanel.classList.remove('hidden');
    defectPanel.classList.add('is-morphing');

    // Measure once before starting animations (zero per-frame layout reads)
    const { stacked, gap, shiftX } = measureStageMetrics();
    const dur = 610; // < 650ms total budget including 4.8% spring overshoot settle
    const timing = { duration: dur, easing: 'linear', fill: 'both' };

    const surface = defectPanel.querySelector(':scope > .gm-surface');
    const head    = defectPanel.querySelector('.gm-head');
    const body    = defectPanel.querySelector('.gm-defect-body');
    const anims   = [];

    if (stacked) {
        // Vertical pill -> panel expand for narrow screens (<= 980px)
        const panelAnim = defectPanel.animate([
            { opacity: 0, transform: 'translate3d(0px, -16px, 0px) scale3d(0.9, 0.86, 1)', clipPath: 'inset(0% 34% 88% 34% round 26px)', offset: 0 },
            { opacity: 1, transform: 'translate3d(0px, -8px, 0px) scale3d(0.96, 0.94, 1)', clipPath: 'inset(0% 24% 76% 24% round 26px)', offset: 0.22 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(1.016, 1.038, 1)', clipPath: 'inset(0% 0% 0% 0% round 22px)', offset: 0.74 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(0.997, 0.995, 1)', clipPath: 'inset(0% 0% 0% 0% round 22px)', offset: 0.88 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)', clipPath: 'inset(0% 0% 0% 0% round 22px)', offset: 1 }
        ], timing);
        anims.push(panelAnim);
    } else {
        // Desktop horizontal Dynamic Island pill morph + pure translate3d window glide
        const edge0  = Math.round(shiftX - gap);              // #gm-window right border at t=0
        const edge18 = Math.round(shiftX * 0.78 - gap);       // #gm-window right border at t=0.18
        const edge36 = Math.round(shiftX * 0.44 - gap);       // #gm-window right border at t=0.36

        if (gmWindow) {
            anims.push(gmWindow.animate([
                { transform: 'translate3d(0px, 0px, 0px)', offset: 0 },
                { transform: `translate3d(${-Math.round(shiftX * 0.22)}px, 0px, 0px)`, offset: 0.18 },
                { transform: `translate3d(${-Math.round(shiftX * 0.56)}px, 0px, 0px)`, offset: 0.36 },
                { transform: `translate3d(${-Math.round(shiftX * 0.88)}px, 0px, 0px)`, offset: 0.56 },
                { transform: `translate3d(${-Math.round(shiftX * 1.032)}px, 0px, 0px)`, offset: 0.74 },
                { transform: `translate3d(${-Math.round(shiftX * 0.996)}px, 0px, 0px)`, offset: 0.88 },
                { transform: `translate3d(${-shiftX}px, 0px, 0px)`, offset: 1 }
            ], timing));
        }

        const panelAnim = defectPanel.animate([
            {
                opacity: 0,
                transform: `translate3d(${edge0 - 20}px, 0px, 0px) scale3d(0.9, 0.9, 1)`,
                clipPath: 'inset(51.5% 84% 40.5% 0% round 28px)',
                offset: 0
            },
            {
                opacity: 1,
                transform: `translate3d(${edge0 - 12}px, 0px, 0px) scale3d(0.96, 0.96, 1)`,
                clipPath: 'inset(51% 78% 40% 0% round 28px)',
                offset: 0.07
            },
            {
                opacity: 1,
                transform: `translate3d(${edge18 - 4}px, 0px, 0px) scale3d(1, 0.98, 1)`,
                clipPath: 'inset(49.5% 58% 39.5% 0% round 28px)',
                offset: 0.20
            },
            {
                opacity: 1,
                transform: `translate3d(${Math.round(edge36 * 0.45)}px, 0px, 0px) scale3d(1.01, 1, 1)`,
                clipPath: 'inset(32% 16% 24% 0% round 25px)',
                offset: 0.36
            },
            {
                opacity: 1,
                transform: 'translate3d(2px, 0px, 0px) scale3d(1.032, 1.014, 1)',
                clipPath: 'inset(2% 1% 2% 0% round 22px)',
                offset: 0.56
            },
            {
                opacity: 1,
                transform: 'translate3d(0px, 0px, 0px) scale3d(1.048, 1.024, 1)',
                clipPath: 'inset(0% 0% 0% 0% round 22px)',
                offset: 0.74
            },
            {
                opacity: 1,
                transform: 'translate3d(0px, 0px, 0px) scale3d(0.994, 0.997, 1)',
                clipPath: 'inset(0% 0% 0% 0% round 22px)',
                offset: 0.88
            },
            {
                opacity: 1,
                transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)',
                clipPath: 'inset(0% 0% 0% 0% round 22px)',
                offset: 1
            }
        ], timing);
        anims.push(panelAnim);

        if (gooLayer && gooBud) {
            anims.push(gooLayer.animate([
                { opacity: 0, transform: `translate3d(${edge0 - 22}px, 0px, 0px)`, offset: 0 },
                { opacity: 0.92, transform: `translate3d(${edge0 - 22}px, 0px, 0px)`, offset: 0.06 },
                { opacity: 0.88, transform: `translate3d(${edge18 - 22}px, 0px, 0px)`, offset: 0.19 },
                { opacity: 0, transform: `translate3d(${edge36 - 18}px, 0px, 0px)`, offset: 0.30 },
                { opacity: 0, transform: 'translate3d(0px, 0px, 0px)', offset: 1 }
            ], timing));
            anims.push(gooBud.animate([
                { transform: 'translate3d(-4px, 0px, 0px) scale3d(0.65, 0.85, 1)', offset: 0 },
                { transform: 'translate3d(20px, 0px, 0px) scale3d(1.16, 0.62, 1)', offset: 0.18 },
                { transform: 'translate3d(34px, 0px, 0px) scale3d(0.38, 0.3, 1)', offset: 0.30 },
                { transform: 'translate3d(0px, 0px, 0px) scale3d(0.2, 0.2, 1)', offset: 1 }
            ], timing));
        }
    }

    if (islandSkin) {
        anims.push(islandSkin.animate([
            { opacity: 1, offset: 0 },
            { opacity: 1, offset: 0.28 },
            { opacity: 0.18, offset: 0.54 },
            { opacity: 0, offset: 0.70 },
            { opacity: 0, offset: 1 }
        ], timing));
    }

    if (surface) {
        anims.push(surface.animate([
            { opacity: 0, transform: 'translate3d(-10px, 0px, 0px) scale3d(0.95, 0.95, 1)', offset: 0 },
            { opacity: 0, transform: 'translate3d(-8px, 0px, 0px) scale3d(0.96, 0.96, 1)', offset: 0.20 },
            { opacity: 0.94, transform: 'translate3d(0px, 0px, 0px) scale3d(1.004, 1.004, 1)', offset: 0.56 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)', offset: 0.76 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)', offset: 1 }
        ], timing));
    }

    if (head) {
        anims.push(head.animate([
            { opacity: 0, transform: 'translate3d(-6px, -4px, 0px)', filter: 'blur(4px)', offset: 0 },
            { opacity: 0, transform: 'translate3d(-5px, -3px, 0px)', filter: 'blur(4px)', offset: 0.24 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px)', filter: 'blur(0px)', offset: 0.58 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px)', filter: 'blur(0px)', offset: 1 }
        ], timing));
    }

    if (body) {
        anims.push(body.animate([
            { opacity: 0, transform: 'translate3d(0px, 10px, 0px)', offset: 0 },
            { opacity: 0, transform: 'translate3d(0px, 8px, 0px)', offset: 0.30 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px)', offset: 0.66 },
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px)', offset: 1 }
        ], timing));
    }

    islandCtrl.anims = anims;
    const master = anims[0];
    if (master) {
        master.onfinish = () => {
            if (seq !== islandCtrl.seq) return;
            if (islandCtrl.state === 'opening') {
                gmStage.classList.add('is-entered', 'has-defects');
                gmStage.classList.remove('is-morphing');
                defectPanel.classList.remove('hidden', 'is-morphing');
                cancelIslandAnims();
                islandCtrl.state = 'open';
                islandCtrl.direction = null;
            } else if (islandCtrl.state === 'closing') {
                const { gap: g, shiftX: sx } = measureStageMetrics();
                playEdgeAbsorbRipple(sx - g);
                defectPanel.classList.add('hidden');
                defectPanel.classList.remove('is-morphing');
                gmStage.classList.remove('has-defects', 'is-morphing');
                gmStage.classList.add('is-entered');
                cancelIslandAnims();
                islandCtrl.state = 'closed';
                islandCtrl.direction = null;
            }
        };
    }
}

function closeDefectPanel(immediate = false) {
    if (!defectPanel || !gmStage) return;

    // Reset modal entrance lock when modal is being prepared from hidden state
    if (immediate && modal?.classList.contains('hidden')) {
        gmStage.classList.remove('is-entered');
    }

    if (immediate || isReducedMotion() || (islandCtrl.state === 'closed' && defectPanel.classList.contains('hidden'))) {
        islandCtrl.seq++;
        islandCtrl.pulseAnim?.cancel();
        islandCtrl.rippleAnim?.cancel();
        cancelIslandAnims();
        defectPanel.classList.add('hidden');
        defectPanel.classList.remove('is-morphing', 'gm-closing');
        gmStage.classList.remove('has-defects', 'is-morphing');
        islandCtrl.state = 'closed';
        islandCtrl.direction = null;
        return;
    }

    // Already closing: let current closing morph complete
    if (islandCtrl.state === 'closing' && islandCtrl.anims.length > 0) {
        return;
    }

    // Interrupted while opening: reverse smoothly from exact current position/shape at faster close rate
    if (islandCtrl.state === 'opening' && islandCtrl.anims.length > 0) {
        islandCtrl.pulseAnim?.cancel();
        islandCtrl.state = 'closing';
        gmStage.classList.add('is-morphing');
        defectPanel.classList.add('is-morphing');
        const targetRate = islandCtrl.direction === 'open' ? -1.32 : 1;
        islandCtrl.anims.forEach(a => {
            a.updatePlaybackRate(targetRate);
            if (a.playState !== 'running') a.play();
        });
        return;
    }

    // Fresh close from 'open' (480ms budget: content blurs/fades first -> shrinks to pill -> edge absorb ripple)
    const seq = ++islandCtrl.seq;
    islandCtrl.pulseAnim?.cancel();
    islandCtrl.rippleAnim?.cancel();
    cancelIslandAnims();

    islandCtrl.state = 'closing';
    islandCtrl.direction = 'close';

    gmStage.classList.add('is-entered', 'is-morphing');
    defectPanel.classList.add('is-morphing');

    const { stacked, gap, shiftX } = measureStageMetrics();
    const dur = 480; // 450-520ms fast reverse budget
    const timing = { duration: dur, easing: 'linear', fill: 'both' };

    const surface = defectPanel.querySelector(':scope > .gm-surface');
    const head    = defectPanel.querySelector('.gm-head');
    const body    = defectPanel.querySelector('.gm-defect-body');
    const anims   = [];

    if (stacked) {
        const panelAnim = defectPanel.animate([
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)', clipPath: 'inset(0% 0% 0% 0% round 22px)', offset: 0 },
            { opacity: 1, transform: 'translate3d(0px, -6px, 0px) scale3d(0.96, 0.94, 1)', clipPath: 'inset(0% 26% 78% 26% round 26px)', offset: 0.65 },
            { opacity: 0, transform: 'translate3d(0px, -16px, 0px) scale3d(0.9, 0.85, 1)', clipPath: 'inset(0% 36% 90% 36% round 26px)', offset: 1 }
        ], timing);
        anims.push(panelAnim);
    } else {
        const cEdge48 = Math.round(shiftX * 0.52 - gap);
        const cEdge74 = Math.round(shiftX * 0.90 - gap);
        const cEdge88 = Math.round(shiftX - 3 - gap);
        const cEdge100 = Math.round(shiftX - gap);

        if (gmWindow) {
            anims.push(gmWindow.animate([
                { transform: `translate3d(${-shiftX}px, 0px, 0px)`, offset: 0 },
                { transform: `translate3d(${-Math.round(shiftX * 0.88)}px, 0px, 0px)`, offset: 0.16 },
                { transform: `translate3d(${-Math.round(shiftX * 0.48)}px, 0px, 0px)`, offset: 0.48 },
                { transform: `translate3d(${-Math.round(shiftX * 0.10)}px, 0px, 0px)`, offset: 0.74 },
                { transform: 'translate3d(-3px, 0px, 0px)', offset: 0.88 },
                { transform: 'translate3d(0px, 0px, 0px)', offset: 1 }
            ], timing));
        }

        const panelAnim = defectPanel.animate([
            {
                opacity: 1,
                transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)',
                clipPath: 'inset(0% 0% 0% 0% round 22px)',
                offset: 0
            },
            {
                opacity: 1,
                transform: 'translate3d(18px, 0px, 0px) scale3d(0.98, 0.98, 1)',
                clipPath: 'inset(26% 18% 20% 0% round 25px)',
                offset: 0.22
            },
            {
                opacity: 1,
                transform: `translate3d(${cEdge48}px, 0px, 0px) scale3d(0.98, 0.98, 1)`,
                clipPath: 'inset(49.5% 58% 39.5% 0% round 28px)',
                offset: 0.48
            },
            {
                opacity: 1,
                transform: `translate3d(${cEdge74 - 8}px, 0px, 0px) scale3d(0.94, 0.94, 1)`,
                clipPath: 'inset(51% 79% 40% 0% round 28px)',
                offset: 0.74
            },
            {
                opacity: 0.78,
                transform: `translate3d(${cEdge88 - 22}px, 0px, 0px) scale3d(0.85, 0.84, 1)`,
                clipPath: 'inset(52% 90% 41% 0% round 28px)',
                offset: 0.90
            },
            {
                opacity: 0,
                transform: `translate3d(${cEdge100 - 30}px, 0px, 0px) scale3d(0.76, 0.76, 1)`,
                clipPath: 'inset(52.5% 94% 41.5% 0% round 28px)',
                offset: 1
            }
        ], timing);
        anims.push(panelAnim);

        if (gooLayer && gooBud) {
            anims.push(gooLayer.animate([
                { opacity: 0, transform: `translate3d(${cEdge48 - 22}px, 0px, 0px)`, offset: 0 },
                { opacity: 0, transform: `translate3d(${cEdge74 - 22}px, 0px, 0px)`, offset: 0.64 },
                { opacity: 0.92, transform: `translate3d(${cEdge74 - 22}px, 0px, 0px)`, offset: 0.74 },
                { opacity: 0.86, transform: `translate3d(${cEdge88 - 22}px, 0px, 0px)`, offset: 0.90 },
                { opacity: 0, transform: `translate3d(${cEdge100 - 22}px, 0px, 0px)`, offset: 1 }
            ], timing));
            anims.push(gooBud.animate([
                { transform: 'translate3d(28px, 0px, 0px) scale3d(0.4, 0.4, 1)', offset: 0 },
                { transform: 'translate3d(24px, 0px, 0px) scale3d(1.12, 0.64, 1)', offset: 0.68 },
                { transform: 'translate3d(-4px, 0px, 0px) scale3d(0.75, 0.88, 1)', offset: 0.90 },
                { transform: 'translate3d(-12px, 0px, 0px) scale3d(0.3, 0.4, 1)', offset: 1 }
            ], timing));
        }

        if (edgeRipple) {
            anims.push(edgeRipple.animate([
                { opacity: 0, transform: `translate3d(${cEdge74 - 6}px, 0px, 0px) scale3d(0.3, 0.3, 1)`, offset: 0 },
                { opacity: 0, transform: `translate3d(${cEdge74 - 6}px, 0px, 0px) scale3d(0.4, 0.35, 1)`, offset: 0.72 },
                { opacity: 0.95, transform: `translate3d(${cEdge88 - 6}px, 0px, 0px) scale3d(1.15, 1.08, 1)`, offset: 0.86 },
                { opacity: 0, transform: `translate3d(${cEdge100 - 6}px, 0px, 0px) scale3d(1.5, 1.55, 1)`, offset: 1 }
            ], timing));
        }
    }

    if (islandSkin) {
        anims.push(islandSkin.animate([
            { opacity: 0, offset: 0 },
            { opacity: 0.94, offset: 0.24 },
            { opacity: 1, offset: 0.84 },
            { opacity: 0, offset: 1 }
        ], timing));
    }

    if (surface) {
        anims.push(surface.animate([
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px) scale3d(1, 1, 1)', offset: 0 },
            { opacity: 0, transform: 'translate3d(-8px, 0px, 0px) scale3d(0.95, 0.95, 1)', offset: 0.26 },
            { opacity: 0, transform: 'translate3d(-8px, 0px, 0px) scale3d(0.95, 0.95, 1)', offset: 1 }
        ], timing));
    }

    if (head) {
        anims.push(head.animate([
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px)', filter: 'blur(0px)', offset: 0 },
            { opacity: 0, transform: 'translate3d(-6px, -3px, 0px)', filter: 'blur(4px)', offset: 0.22 },
            { opacity: 0, transform: 'translate3d(-6px, -3px, 0px)', filter: 'blur(4px)', offset: 1 }
        ], timing));
    }

    if (body) {
        anims.push(body.animate([
            { opacity: 1, transform: 'translate3d(0px, 0px, 0px)', offset: 0 },
            { opacity: 0, transform: 'translate3d(0px, 8px, 0px)', offset: 0.20 },
            { opacity: 0, transform: 'translate3d(0px, 8px, 0px)', offset: 1 }
        ], timing));
    }

    islandCtrl.anims = anims;
    const master = anims[0];
    if (master) {
        master.onfinish = () => {
            if (seq !== islandCtrl.seq) return;
            if (islandCtrl.state === 'closing') {
                defectPanel.classList.add('hidden');
                defectPanel.classList.remove('is-morphing');
                gmStage.classList.remove('has-defects', 'is-morphing');
                gmStage.classList.add('is-entered');
                cancelIslandAnims();
                islandCtrl.state = 'closed';
                islandCtrl.direction = null;
            } else if (islandCtrl.state === 'opening') {
                gmStage.classList.add('is-entered', 'has-defects');
                gmStage.classList.remove('is-morphing');
                defectPanel.classList.remove('hidden', 'is-morphing');
                cancelIslandAnims();
                islandCtrl.state = 'open';
                islandCtrl.direction = null;
                playIslandPulse(true);
            }
        };
    }
}

window.openDefectPanel = openDefectPanel;
window.closeDefectPanel = closeDefectPanel;
