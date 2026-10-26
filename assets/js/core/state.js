/**
 * Shared page state (zone, sheet, filters, paging) + DOM roots set on init.
 */

// Zones + sheets from the database (components/layout/scripts.php -> topologyForJs()); replaced by applyTopology()
const EMPTY_TOPOLOGY = { defaultZone: '', zoneOrder: [], zones: {}, sheets: {} };
const topo = () => window.TOPOLOGY || EMPTY_TOPOLOGY;
const hasOwn = (obj, key) => Object.prototype.hasOwnProperty.call(obj, key);

// "ทุกโซน / All Zones": a view-only pseudo zone (?zone=ALL), same value as ALL_ZONES in config/database.php
const ALL_ZONES_KEY = 'ALL';
const isAllZonesJs = (z) => z === ALL_ZONES_KEY;

const state = {
    zone: topo().defaultZone,
    sheet: 'ALL',
    page: 1,
    pageSize: 10,
    search: '',
    shelf: 'ALL',
    period: 'ALL',
    board_type: 'ALL',
    status: 'ALL',
    other: 'ALL',
    sort: 'shelf_no',
    dir: 'asc'
};

const view = { status: 'loading', rows: [], total: 0 };
const matrixView = { status: 'loading', rows: [], total: 0 };
// All Zones view: one matrix per zone ([{ zone, label, total, data: rows }], zone order)
const matrixZonesView = { status: 'loading', groups: [] };

let tableWrapper  = null;
let pagerFooter   = null;
let matrixWrapper = null;

let currentSheetData = null;

// Mirrors the PHP helpers in config/database.php: direct links accept disabled zones / sheets, tabs show enabled ones only
function defaultZoneJs() {
    return topo().defaultZone;
}

function normalizeZoneJs(z) {
    const key = String(z || '').trim();
    if (isAllZonesJs(key)) return key;
    return hasOwn(topo().zones, key) ? key : defaultZoneJs();
}

// Zones listed by the All Zones view, in zone order: enabled zones that have at least one sheet (same as allZonesWithSheets() in PHP)
function allZonesWithSheetsJs() {
    const t = topo();
    return t.zoneOrder.filter(k => t.zones[k] && t.zones[k].active && t.zones[k].sheets.length > 0);
}

function zoneMetaJs(z) {
    const key = normalizeZoneJs(z);
    if (isAllZonesJs(key)) {
        const t = topo();
        const zones = allZonesWithSheetsJs();
        return {
            label: 'ทุกโซน / All Zones', short: 'All Zones', active: true,
            sheets: zones.flatMap(k => t.zones[k].sheets),
        };
    }
    return topo().zones[key] || { label: key, short: key, active: false, sheets: [] };
}

function sheetMetaJs(sh) {
    return hasOwn(topo().sheets, sh) ? topo().sheets[sh] : null;
}

// Sheet keys of a zone in tab order: enabled only (tabs), or incl. disabled ones
function getZoneSheetKeysJs(z = state.zone, includeDisabled = false) {
    return zoneMetaJs(z).sheets.filter(k => includeDisabled || sheetMetaJs(k)?.active);
}

// Does the zone have sheets whose records can be shown (enabled or disabled)? Adding: insertBlockedReasonJs()
function zoneHasSheetsJs(z = state.zone) {
    return getZoneSheetKeysJs(z, true).length > 0;
}

function defaultSheetForZoneJs(z = state.zone) {
    return getZoneSheetKeysJs(z)[0] || '';
}

function resolveSheetInZoneJs(z, sh) {
    if (!sh || sh === 'ALL' || isAllZonesJs(z)) return 'ALL';
    return getZoneSheetKeysJs(z, true).includes(sh) ? sh : 'ALL';
}

// Same order and messages as insertBlockedReason() in PHP; '' = a new record may be added
function insertBlockedReasonJs(z = state.zone, sh = state.sheet) {
    if (isAllZonesJs(z)) return ALL_ZONES_INSERT_MSG;
    const zone = zoneMetaJs(z);
    if (getZoneSheetKeysJs(z, true).length === 0) return EMPTY_ZONE_INSERT_MSG;
    if (!zone.active) return INACTIVE_ZONE_INSERT_MSG;
    if (getZoneSheetKeysJs(z).length === 0) return NO_ACTIVE_SHEET_INSERT_MSG;
    if (getZoneSheetKeysJs(z, true).includes(sh) && !sheetMetaJs(sh)?.active) return INACTIVE_SHEET_INSERT_MSG;
    if (!sh || sh === 'ALL' || !getZoneSheetKeysJs(z).includes(sh)) return ALL_SIZE_INSERT_MSG;
    return '';
}

// Zone selector text, same as zoneOptionLabel() in PHP
function zoneOptionLabelJs(z) {
    if (isAllZonesJs(z)) return `ทุกโซน / All Zones (${allZonesWithSheetsJs().length} โซน)`;
    const zone = topo().zones[z];
    if (!zone) return String(z);
    const enabled = getZoneSheetKeysJs(z).length;
    let suffix;
    if (!zone.active) suffix = ' (ปิดใช้งาน / Disabled)';
    else if (enabled > 0) suffix = ` (${enabled} Shelf)`;
    else suffix = getZoneSheetKeysJs(z, true).length > 0 ? ' (ไม่มี Shelf ที่เปิดใช้งาน / No active shelves)' : ' (ยังไม่มี Shelf / No shelves)';
    return zone.label + suffix;
}
