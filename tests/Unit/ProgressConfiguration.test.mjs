import { test } from 'node:test';
import assert from 'node:assert/strict';
import '../../docs/v2-prototype/progress.js';

const P = globalThis.NorthwestProgress;
const field = (key, name, type = 'Float', required = true) => ({ key, name, type, required, min: null, max: null });
const configuration = () => ({
    name: 'Excavation', unit: 'm³',
    fields: [field('start', 'Starting point'), field('end', 'Ending point'), field('width', 'Width'), field('notes', 'Remarks / notes', 'Character', false)],
    formula: '(end - start) * width', outputField: 'start',
});
const activity = () => P.publish({ id: 'excavation', versions: [], draft: null }, configuration());

test('uses precedence, parentheses, decimals and unary signs without executing code', () => {
    const config = configuration();
    config.formula = '-start + (end - .5) * +width / 2';
    assert.equal(P.calculate(config, { start: '2', end: '4.5', width: '3' }).result, 4);
    for (const formula of ['alert(1)', 'start.constructor', 'start ** end', '1; process.exit()', '1 2', '1e2', '(start + end', 'start +', 'start end', '1,2']) {
        assert.throws(() => P.parseFormula(formula, config.fields));
    }
});

test('formula validation does not divide by arbitrary dummy inputs', () => {
    const config = configuration();
    config.formula = 'end / (width - start)';
    assert.doesNotThrow(() => P.validateConfig(config));
    assert.equal(P.calculate(config, { start: '2', end: '12', width: '5' }).result, 4);
    assert.throws(() => P.calculate(config, { start: '2', end: '12', width: '2' }), /Division by zero/);
});

test('allows optional notes alongside numeric formulas and arbitrary display titles', () => {
    const config = configuration();
    config.fields[0].name = '起点 / Start (m)';
    assert.equal(P.calculate(config, { start: '2', end: '10', width: '3', notes: 'Ground wet' }).result, 24);
    assert.equal(P.calculate(config, { start: '2', end: '10', width: '3' }).result, 24);
    config.formula = 'notes + start';
    assert.throws(() => P.validateConfig(config), /must be numeric/);
});

test('rejects missing referenced optional inputs rather than treating blanks as zero', () => {
    const config = configuration();
    config.fields[0].required = false;
    assert.throws(() => P.calculate(config, { end: 10, width: 3 }), /Missing numeric value/);
});

test('validates integer limits, required fields and positive quantities', () => {
    const config = { name: 'Units', unit: 'pcs', fields: [{ ...field('units', 'Units', 'Integer'), min: 1, max: 10 }], formula: '', outputField: 'units' };
    for (const units of ['', '2.5', '11', '-1', 'Infinity', '0x10', '1e2', '9007199254740992']) {
        assert.throws(() => P.calculate(config, { units }));
    }
    assert.equal(P.calculate(config, { units: '10' }).result, 10);
    assert.throws(() => P.calculate({ ...config, fields: [field('units', 'Units')] }, { units: '0' }), /greater than zero/);
});

test('supports text and datetime outputs without coercing them to quantities', () => {
    const config = { name: 'Inspection note', unit: '', fields: [field('note', 'Observation', 'Character')], formula: '', outputField: 'note' };
    assert.equal(P.calculate(config, { note: 'Work completed' }).result, 'Work completed');
    config.fields = [field('time', 'Inspection time', 'Datetime')];
    config.outputField = 'time';
    assert.equal(P.calculate(config, { time: '2026-11-03T14:30' }).result, '2026-11-03T14:30');
    for (const time of ['invalid', '2026-02-30T10:00', '2026-11-03T25:00']) assert.throws(() => P.calculate(config, { time }));
});

test('rejects duplicate keys and titles, unsupported types, unknown outputs and invalid limits', () => {
    for (const mutate of [
        c => { c.fields[1].key = 'start'; },
        c => { c.fields[1].name = ' starting POINT '; },
        c => { c.fields[0].type = 'Unknown'; },
        c => { c.fields[0].min = 10; c.fields[0].max = 1; },
        c => { c.fields[3].min = 1; },
        c => { c.formula = ''; c.outputField = 'unknown'; },
        c => { c.fields = []; },
    ]) {
        const config = configuration();
        mutate(config);
        assert.throws(() => P.validateConfig(config));
    }
});

test('draft saves cannot mutate the published configuration', () => {
    const original = activity();
    const draft = configuration();
    draft.formula = 'end - start';
    const updated = P.saveDraft(original, draft);
    draft.fields[0].name = 'Changed outside store';
    assert.equal(updated.draft.fields[0].name, 'Starting point');
    assert.equal(updated.versions[0].formula, '(end - start) * width');
    assert.equal(original.draft, null);
    assert.equal(P.record(updated, { start: 2, end: 10, width: 3 }, {}).result, 24);
});

test('publishing preserves original entries, formulas, titles and units', () => {
    const original = activity();
    const entry = P.record(original, { start: 2, end: 10, width: 3 }, { project: 'A', route: 'R', date: '2026-11-03' });
    const draft = configuration();
    draft.fields[0].name = 'New display title';
    draft.formula = 'end - start';
    const updated = P.publish(original, draft);
    draft.fields[0].name = 'External mutation';
    assert.equal(updated.versions.length, 2);
    assert.equal(entry.result, 24);
    assert.equal(entry.configuration.version, 1);
    assert.equal(entry.configuration.fields[0].name, 'Starting point');
    assert.equal(P.record(updated, { start: 2, end: 10, width: 3 }, {}).result, 8);
    assert.equal(P.record(updated, { start: 2, end: 10, width: 3 }, {}).configuration.version, 2);
});

test('blocks unit/category changes that would corrupt aggregation', () => {
    const original = activity();
    assert.throws(() => P.publish(original, { ...configuration(), unit: 'pcs' }), /published unit/);
    assert.throws(() => P.publish(original, { ...configuration(), formula: '', outputField: 'notes' }), /output category/);
    assert.throws(() => P.record({ id: 'draft', versions: [] }, {}, {}), /Publish/);
});

test('bounds formula complexity and rejects nonfinite intermediate results', () => {
    assert.throws(() => P.parseFormula('('.repeat(1001), []), /too long/);
    assert.throws(() => P.parseFormula('1+'.repeat(110) + '1', []), /200 tokens/);
    const config = configuration();
    config.formula = 'end * width';
    assert.throws(() => P.calculate(config, { start: 1, end: '9'.repeat(200), width: '9'.repeat(200) }), /supported range/);
});

test('groups by project, route and date while preserving team entries and approval totals', () => {
    const column = activity();
    const entry = (project, route, date, end, status, team) => ({
        ...P.record(column, { start: 0, end, width: 1 }, { project, route, date, team }), status,
    });
    const entries = [
        entry('A', 'R', '2026-11-03', 10, 'Pending', 'Team A'),
        entry('A', 'R', '2026-11-03', 20, 'Approved', 'Team B'),
        entry('A', 'Other', '2026-11-03', 50, 'Pending', 'Team A'),
        entry('A', 'R', '2026-11-02', 60, 'Approved', 'Team A'),
        entry('B', 'R', '2026-11-03', 100, 'Approved', 'Team A'),
    ];
    const groups = P.groupEntries(entries, 'A');
    assert.equal(groups.length, 3);
    const group = groups.find(item => item.route === 'R' && item.date === '2026-11-03');
    assert.equal(group.entries.length, 2);
    assert.deepEqual(group.entries.map(item => item.team), ['Team A', 'Team B']);
    assert.equal(P.aggregate(group, column), 30);
    assert.equal(P.aggregate(group, column, 'Approved'), 20);
    assert.equal(P.aggregate(group, column, 'Pending'), 10);
    assert.equal(P.groupEntries(entries, 'B').length, 1);
});

test('retains text values instead of summing them and detects overflowing daily totals', () => {
    const column = P.publish({ id: 'notes', versions: [] }, { name: 'Notes', unit: '', fields: [field('note', 'Note', 'Character')], formula: '', outputField: 'note' });
    const group = { entries: ['First', 'Second', 'First'].map(note => P.record(column, { note }, {})) };
    assert.equal(P.aggregate(group, column), 'First; Second');
    const numericColumn = activity();
    const numericGroup = { entries: [1, 2].map(() => ({ activity: numericColumn.id, result: Number.MAX_VALUE, status: 'Pending' })) };
    assert.throws(() => P.aggregate(numericGroup, numericColumn), /Daily total/);
});
