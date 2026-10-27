<?php
/**
 * Component stylesheets and scripts, in load order.
 * CSS order is the cascade order: later files win on equal specificity.
 * JS files are plain scripts sharing one global scope; main.js boots the page and must stay last.
 */

declare(strict_types=1);

const CSS_FILES = [
    'base/base.css',
    'components/data-table.css',
    'components/page-size.css',
    'theme/hyprland.css',
    'components/glass-select.css',
    'components/filters.css',
    'components/search.css',
    'components/clock.css',
    'components/defect-matrix.css',
    'components/glass-popup/base.css',
    'components/board-modal/stage.css',
    'components/glass-popup/window.css',
    'components/board-modal/form.css',
    'components/glass-popup/controls.css',
    'components/popups/confirm.css',
    'components/board-modal/defect-panel.css',
    'components/glass-popup/responsive.css',
    'components/topology-manager.css',      // after the glass popup: manager + admin.php build on it
    'components/backup-manager.css',        // monthly data backup & time-machine (administrators)
    'components/export-station.css',
    'components/import-station.css',
];

const JS_FILES = [
    'core/state.js',
    'core/messages.js',
    'core/utils.js',
    'core/glass-popup.js',
    'components/flash-toast.js',
    'components/clock.js',
    'components/data-table/cells.js',      // before table.js: COLUMNS references the cell renderers
    'components/data-table/table.js',
    'components/data-table/pagination.js',
    'components/data-table/loader.js',
    'components/defect-matrix.js',
    'components/sheet-data.js',
    'components/kpi-cards.js',
    'components/sheet-tabs.js',
    'components/filters.js',
    'components/rack-map.js',
    'components/board-modal/modal.js',
    'components/board-modal/form-submit.js',
    'components/board-modal/defect-panel.js',   // before island-morph.js: defines gmStage
    'components/board-modal/island-morph.js',
    'components/popups/delete-confirm.js',
    'components/popups/rack-insert-confirm.js',
    'components/export-station.js',
    'components/import-station.js',
    'core/events.js',
    'main.js',
];

// Zone & Sheet manager & Data Backup/Restore: only sent to a signed-in administrator, inserted before main.js (components/layout/scripts.php)
const ADMIN_JS_FILES = [
    'components/popups/topology-confirm.js',
    'components/topology-manager.js',
    'components/backup-manager.js',
];

// ──────────────────────────────────────────────
// AOI Programs Page Assets
// ──────────────────────────────────────────────
const PROGRAM_CSS_FILES = [
    'base/base.css',
    'theme/hyprland.css',
    'components/clock.css',
    'components/glass-select.css',
    'components/search.css',
    'components/programs/tokens.css',
    'components/programs/layout.css',
    'components/programs/kpi.css',
    'components/programs/command-bar.css',
    'components/programs/table.css',
    'components/programs/stepper.css',
    'components/programs/toast.css',
];

const PROGRAM_JS_FILES = [
    'core/messages.js',
    'core/utils.js',
    'components/clock.js',
    'components/search-fx.js',
    'components/programs/state.js',
    'components/programs/api.js',
    'components/programs/toast-undo.js',
    'components/programs/kpi.js',
    'components/programs/command-bar.js',
    'components/programs/stepper.js',
    'components/programs/table.js',
    'components/programs/main.js',
];
