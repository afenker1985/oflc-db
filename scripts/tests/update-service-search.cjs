// Run with: node scripts/tests/update-service-search.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../update-service.php'), 'utf8');
function extract(name, next) {
    return source.slice(source.indexOf('    function ' + name + '('), source.indexOf('    function ' + next + '('));
}
function check(initial, typed, expectedVisible) {
    let options;
    const listeners = {};
    const makeInput = value => ({ value, getAttribute() {}, setAttribute() {},
        addEventListener(event, handler) { listeners[event] = handler; }, focus() {}, setSelectionRange() {} });
    const rows = ['Christmas Eve', 'Christmas Midnight', 'Christmas Day', 'Trinity 1'].map(text => ({
        hidden: false, getAttribute() { return text; }
    }));
    const context = {
        searchInput: makeInput(initial), searchRows: [], suppressInitialSearchSync: false,
        DOMParser: class { parseFromString() { return { getElementById() { return {}; } }; } },
        getContentRoot() { return { replaceWith() {} }; },
        refreshUpdateServiceDomReferences() { context.searchInput = makeInput(''); context.searchRows = rows; },
        bindUpdateServiceRows() {}, bindUpdateServiceForms() {}, bindUpdateServiceFilters() {},
        syncSearchSelection() {}, hasActiveRangeFilter() { return true; },
        buildSearchResultsUrl() { return 'update-service.php?start_date=&end_date='; },
        requestUpdateService(url, value) { options = value; },
        getCleanUpdateServiceUrl() { return 'update-service.php'; },
        window: { history: { replaceState() {} } }
    };
    vm.createContext(context);
    vm.runInContext(extract('applySearchFilter', 'closeOtherUpdateServiceRows') +
        extract('syncUpdateServiceRoot', 'syncUpdatedServiceRowFromHtml') +
        source.slice(source.indexOf('    function bindUpdateServiceSearch()'), source.lastIndexOf('    bindUpdateServiceRows();')), context);
    context.bindUpdateServiceSearch();
    listeners.focus(); // Starts loading all services, before the first keystroke.
    context.searchInput.value = typed;
    listeners.input();
    context.syncUpdateServiceRoot('', 'update-service.php', options); // Delayed response arrives.
    assert.equal(context.searchInput.value, typed);
    assert.deepEqual(rows.map(row => !row.hidden), expectedVisible);
}
check('', 'Christmas', [true, true, true, false]);
check('Christmas', 'Midnight', [false, true, false, false]);
check('Christmas', '', [true, true, true, true]);
console.log('PASS: first search, edits during loading, and clearing during loading retain the latest query and filter results.');
