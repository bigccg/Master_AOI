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
    'core/events.js',
    'main.js',
];
