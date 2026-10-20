/**
 * Shared page state (zone, sheet, filters, paging) + DOM roots set on init.
 */

const state = {
    zone: 'General',
    sheet: 'ALL',
    page: 1,
    pageSize: 10,
    search: '',
    shelf: 'ALL',
    board_type: 'ALL',
    status: 'ALL',
    other: 'ALL',
    sort: 'shelf_no',
    dir: 'asc'
};

const view = { status: 'loading', rows: [], total: 0 };
const matrixView = { status: 'loading', rows: [], total: 0 };

let tableWrapper  = null;
let pagerFooter   = null;
let matrixWrapper = null;

let currentSheetData = null;

function normalizeZoneJs(z) {
    const zones = window.ZONES || { General: ['CD300', 'A150_Gen', 'B200_Gen', 'C250_Gen'], AIXZ: [], Automotive: [] };
    const key = String(z || '').trim();
    return Object.prototype.hasOwnProperty.call(zones, key) ? key : 'General';
}

function getZoneSheetKeysJs(z = state.zone) {
    const zones = window.ZONES || { General: ['CD300', 'A150_Gen', 'B200_Gen', 'C250_Gen'], AIXZ: [], Automotive: [] };
    const norm = normalizeZoneJs(z);
    return Array.isArray(zones[norm]) ? zones[norm] : [];
}

function zoneHasSheetsJs(z = state.zone) {
    return getZoneSheetKeysJs(z).length > 0;
}

function resolveSheetInZoneJs(z, sh) {
    const keys = getZoneSheetKeysJs(z);
    if (!sh || sh === 'ALL') return 'ALL';
    return keys.includes(sh) ? sh : 'ALL';
}
