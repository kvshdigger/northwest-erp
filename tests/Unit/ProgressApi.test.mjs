import { test } from 'node:test';
import assert from 'node:assert/strict';
import '../../docs/v2-prototype/api.js';
const Api = globalThis.NorthwestProgressApi;
const response = (data, status = 200) => ({ ok: status < 400, status, json: async () => data });
test('sends session credentials and CSRF headers without storing credentials', async () => {
    let call;
    const api = new Api('/api/', 'csrf-value', async (...args) => { call = args; return response({ data: [] }); });
    await api.project(12, 'routes', 'POST', { name: 'North' });
    assert.equal(call[0], '/api/projects/12/routes');
    assert.equal(call[1].credentials, 'same-origin');
    assert.equal(call[1].headers['X-CSRF-TOKEN'], 'csrf-value');
    assert.deepEqual(JSON.parse(call[1].body), { name: 'North' });
});
test('loads every assigned-project page', async () => {
    let count = 0;
    const api = new Api('/api', '', async () => response({ data: [{ id: ++count }], last_page: 2 }));
    assert.deepEqual(await api.projects(), [{ id: 1 }, { id: 2 }]);
});
test('retains the saved draft revision when publication fails so retries do not duplicate activities', async () => {
    const calls = [];
    const api = new Api('/api', '', async (url, options) => {
        calls.push([url, JSON.parse(options.body)]);
        return calls.length === 1 ? response({ data: { id: 4, revision: 1, draft: { name: 'Test' } } }) : response({ errors: { configuration: ['Invalid formula'] } }, 422);
    });
    let saved;
    await assert.rejects(api.save(1, { id: 'temporary' }, { name: 'Test' }, true, row => { saved = row; }), /Invalid formula/);
    assert.equal(saved.id, '4');
    assert.equal(saved.revision, 1);
    assert.equal(calls[1][1].revision, 1);
});
for (const status of [401, 419, 409, 403, 500]) test('handles failed response ' + status, async () => {
    const api = new Api('/api', '', async () => response({ message: 'Denied' }, status));
    await assert.rejects(api.projects(), error => error.status === status && !!error.message);
});
test('maps historical configuration and revision without applying unpublished edits', () => {
    const row = new Api('/api', '').column({ id: 2, revision: 4, is_active: true, published: { version: 1, configuration: { name: 'Published' } }, draft: { name: 'Draft' } });
    assert.equal(row.versions[0].name, 'Published');
    assert.equal(row.draft.name, 'Draft');
});

test('calls native fetch with the global receiver', async () => {
    const original = globalThis.fetch;
    globalThis.fetch = function () { assert.equal(this, globalThis); return Promise.resolve(response({ data: [] })); };
    try { await new Api('/api', '').request('projects'); } finally { globalThis.fetch = original; }
});

test('describes interrupted requests without discarding the caller form', async () => {
    const api = new Api('/api', '', async () => { throw TypeError('Failed to fetch'); });
    await assert.rejects(api.projects(), /Connection interrupted/);
});
