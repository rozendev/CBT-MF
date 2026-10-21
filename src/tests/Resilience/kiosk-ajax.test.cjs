const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');
const test = require('node:test');
const assert = require('node:assert/strict');
const source = readFileSync(resolve(__dirname, '../../app/Views/bundle/_jquery_shim.php'), 'utf8');

test('kiosk AJAX preserves 429 and Retry-After even when the server returns HTML', async () => {
    let options, completed;
    const context = { window: {}, AbortSignal, fetch: async (url, opts) => {
        options = opts;
        return { ok: false, status: 429, headers: new Headers({ 'Retry-After': '10' }), json: async () => { throw new SyntaxError('HTML'); } };
    } };
    vm.runInNewContext(source, context);
    const error = await new Promise(resolve => context.window.$.ajax({ url: '/save', timeout: 15000, error: resolve }).always(value => { completed = value; }));
    assert.equal(error.status, 429);
    assert.equal(error.getResponseHeader('Retry-After'), '10');
    assert.ok(options.signal instanceof AbortSignal, 'A hung request would prevent retries forever');
    assert.equal(completed?.status, 429, 'Auto-sync needs the same cooldown information as autosave');
});
