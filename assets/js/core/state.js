/**
 * Shared page state (filters, paging, current sheet) + DOM roots set on init.
 */

const state = {
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
