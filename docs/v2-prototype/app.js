(function () {
    'use strict';

    const P = NorthwestProgress;
    const apiBase = document.querySelector('meta[name="progress-api"]')?.content;
    const api = apiBase ? new NorthwestProgressApi(apiBase, document.querySelector('meta[name="csrf-token"]').content) : null;
    let workspace = null, projectRows = [], serverGroups = [], trackerMeta = { page: 1, total: 0, per_page: 30 };
    let busy = false, loadSequence = 0, reportAttempt = null;
    const projectId = () => currentProject;
    const titleStatus = value => value ? value[0].toUpperCase() + value.slice(1) : 'Pending';
    const el = id => document.getElementById(id);
    const escapeHTML = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
    const icon = name => '<img class="icon" src="assets/' + name + '.svg" alt="" aria-hidden="true">';
    const routes = { 'YTL RAC Fiber Optic': ['PTP-Skudai', 'Gemas-Labis'], 'IBC Infrastructure': ['Site A', 'Site B'] };
    const field = (key, name, type = 'Float') => ({ key, name, type, required: true, min: null, max: null });
    function activity(id, name, unit, fields, formula = '') {
        return P.publish({ id, visible: true, versions: [], draft: null }, { name, unit, fields, formula, outputField: fields[0].key });
    }
    const columns = {
        'YTL RAC Fiber Optic': [
            activity('open12', 'Open Cut 1.2m', 'm', [field('start', 'Start'), field('end', 'End')], 'end - start'),
            activity('open06', 'Open Cut 0.6m', 'm', [field('quantity', 'Quantity')]),
            activity('manhole', 'Manhole Installed', 'pcs', [field('quantity', 'Quantity', 'Integer')]),
        ],
        'IBC Infrastructure': [activity('site', 'Sites Completed', 'sites', [field('quantity', 'Quantity', 'Integer')])],
    };
    let currentProject = Object.keys(columns)[0];
    try {
        const remembered = localStorage.getItem('northwest-demo-project');
        if (Object.hasOwn(columns, remembered)) currentProject = remembered;
    } catch (_) { /* The file preview can run with browser storage disabled. */ }
    if (!api) el('projectSelect').value = currentProject;

    // Seed values have explicit inputs and configuration snapshots, just like new demo entries.
    const entries = [
        ['PTP-Skudai', '2026-10-27', 'Team A', 'open12', 320, 'Approved'],
        ['PTP-Skudai', '2026-10-27', 'Team B', 'open06', 100, 'Pending'],
        ['PTP-Skudai', '2026-10-28', 'Team A', 'open12', 290, 'Pending'],
        ['PTP-Skudai', '2026-10-28', 'Team B', 'open06', 100, 'Pending'],
        ['Gemas-Labis', '2026-10-28', 'Team C', 'open12', 100, 'Approved'],
        ['Gemas-Labis', '2026-10-28', 'Team C', 'open06', 90, 'Approved'],
    ].map(([route, date, team, id, quantity, status]) => {
        const column = columns['YTL RAC Fiber Optic'].find(item => item.id === id);
        return { ...P.record(column, id === 'open12' ? { start: 0, end: quantity } : { quantity }, {
            project: 'YTL RAC Fiber Optic', route, date, team, location: 'Section A',
        }), status };
    });

    const configFor = column => column.versions.at(-1);
    const displayName = column => (column.draft || configFor(column)).name || 'Untitled activity';
    const publishedColumns = () => columns[currentProject].filter(column => configFor(column));
    const visibleColumns = () => publishedColumns().filter(column => column.visible);
    const formatNumber = value => value.toLocaleString(undefined, { maximumFractionDigits: 6 });
    const groups = () => api ? serverGroups : P.groupEntries(entries, currentProject);
    function status(group) {
        if (api) return titleStatus(group.status);
        const statuses = [...new Set(group.entries.map(entry => entry.status))];
        return statuses.length === 1 ? statuses[0] : 'Mixed';
    }
    const total = (group, column, state = null) => {
        if (!api) return P.aggregate(group, column, state);
        const item = group.activities.find(item => String(item.activity_id) === String(column.id));
        if (!item) return '—';
        const value = state ? item.approved : item.recorded;
        return Array.isArray(value) ? value.join('; ') : value;
    };
    const entryCount = group => api ? group.entry_count : group.entries.length;
    function filteredGroups() {
        const search = el('search').value.toLowerCase();
        return groups().filter(group => (!search || (group.route + ' ' + group.date).toLowerCase().includes(search))
            && (!el('statusFilter').value || status(group) === el('statusFilter').value));
    }
    function navigate(page) {
        document.querySelectorAll('.page').forEach(node => node.classList.toggle('hidden', node.id !== page));
        document.querySelectorAll('.nav, [data-tab-page]').forEach(node => {
            const selected = (node.dataset.page || node.dataset.tabPage) === page;
            node.classList.toggle('active', selected);
            if (selected) node.setAttribute('aria-current', 'page');
            else node.removeAttribute('aria-current');
        });
        el('projectContext').classList.toggle('hidden', !['dashboard', 'tracker', 'config'].includes(page));
        el('sidebar').classList.remove('open');
        el('menuBtn').setAttribute('aria-expanded', 'false');
        render();
    }
    function render() {
        const grouped = groups();
        const cols = visibleColumns();
        const visible = filteredGroups();
        el('breadcrumbProject').textContent = api ? workspace?.project.name || 'Select a project' : currentProject;
        el('recordCount').textContent = visible.length + ' daily reports' + (api ? ' on this page · ' + trackerMeta.total + ' total' : '');
        el('statReports').textContent = grouped.length;
        const pending = entries.filter(entry => entry.project === currentProject && entry.status === 'Pending');
        el('statOpen').textContent = api ? publishedColumns().length : pending.length;
        el('statPendingReports').textContent = api ? grouped.reduce((sum, group) => sum + group.entry_count, 0) : grouped.filter(group => group.entries.some(entry => entry.status === 'Pending')).length;
        el('trackerHead').innerHTML = '<tr><th>Route</th><th>Progress date</th>' + cols.map(column => '<th scope="col" title="' + escapeHTML(configFor(column).name) + '">' + escapeHTML(configFor(column).name) + '</th>').join('') + '<th>Status</th><th>Details</th></tr>';
        el('trackerBody').innerHTML = visible.map((group, index) => '<tr><td title="' + escapeHTML(group.route) + '"><b>' + escapeHTML(group.route) + '</b></td><td>' + group.date + '</td>' + cols.map(column => {
            const value = total(group, column);
            return '<td>' + escapeHTML(api ? value === '—' ? value : value + ' ' + configFor(column).unit : typeof value === 'number' ? formatNumber(value) + ' ' + configFor(column).unit : value || '—')
                + ((typeof value === 'number' || (api && typeof group.activities.find(item => String(item.activity_id) === column.id)?.recorded === 'string')) ? '<div class="foot">Approved: ' + escapeHTML((api ? total(group, column, 'Approved') : formatNumber(total(group, column, 'Approved'))) + ' ' + configFor(column).unit) + '</div>' : '') + '</td>';
        }).join('') + '<td><span class="pill ' + status(group).toLowerCase() + '">' + status(group) + '</span></td><td><button class="link" data-detail="' + index + '">View entries</button></td></tr>').join('') || '<tr><td colspan="' + (cols.length + 4) + '">No progress records found. Add progress or adjust the filters.</td></tr>';
        document.querySelectorAll('[data-detail]').forEach(button => { button.onclick = () => showDetails(visible[Number(button.dataset.detail)]); });
        el('recent').innerHTML = '<div class="tablewrap"><table><thead><tr><th>Route</th><th>Date</th><th>Entries</th><th>Status</th></tr></thead><tbody>' + grouped.slice(0, 5).map(group => '<tr><td>' + escapeHTML(group.route) + '</td><td>' + group.date + '</td><td>' + entryCount(group) + '</td><td>' + status(group) + '</td></tr>').join('') + '</tbody></table></div>';
        el('columnList').innerHTML = columns[currentProject].map((column, index) => {
            const published = configFor(column);
            return '<div class="config-row"><span>' + (index + 1) + '</span><div><b>' + escapeHTML(published?.name || displayName(column)) + '</b><div class="foot">' + escapeHTML(published?.unit || '') + '</div></div><div>' + (published ? 'Version ' + published.version : 'Unpublished') + '</div><label class="config-check"><input type="checkbox" data-toggle="' + index + '" ' + (column.visible ? 'checked' : '') + (published ? '' : ' disabled') + '> Show in tracker</label><div class="config-status">' + (column.draft ? 'DRAFT' + (published ? ' · published version retained' : '') : 'PUBLISHED') + '</div><button class="btn" data-settings="' + index + '">Settings</button></div>';
        }).join('');
        document.querySelectorAll('[data-toggle]').forEach(input => { input.onchange = () => { columns[currentProject][Number(input.dataset.toggle)].visible = input.checked;
            if (api) { try { localStorage.setItem('northwest-hidden-columns-' + projectId(), JSON.stringify(columns[currentProject].filter(column => !column.visible).map(column => column.id))); } catch (_) { /* Storage is optional. */ } }
            render(); }; });
        document.querySelectorAll('[data-settings]').forEach(button => { button.onclick = () => openSettings(Number(button.dataset.settings)); });
        if (api) applyCapabilities();
    }

    let activeModal = null;
    let returnFocus = null;
    let pendingNewColumn = null;
    function showModal(id) {
        returnFocus = document.activeElement;
        activeModal = id;
        el(id).classList.remove('hidden');
        document.body.classList.add('modal-open');
        document.querySelector('.app').inert = true;
        el(id).querySelector('input, button, select')?.focus();
    }
    function hideModal() {
        if (busy) return;
        el(activeModal).classList.add('hidden');
        activeModal = null;
        document.body.classList.remove('modal-open');
        document.querySelector('.app').inert = false;
        if (pendingNewColumn) {
            columns[currentProject] = columns[currentProject].filter(column => column !== pendingNewColumn);
            pendingNewColumn = null;
            render();
        }
        if (returnFocus?.isConnected) returnFocus.focus();
        else document.querySelector('[data-settings="' + editingColumn + '"]')?.focus();
    }
    document.addEventListener('keydown', event => {
        if (!activeModal) return;
        if (event.key === 'Escape') { event.preventDefault(); hideModal(); }
        if (event.key === 'Tab') {
            const focusable = [...el(activeModal).querySelectorAll('button, input, select, textarea, [tabindex="0"]')].filter(node => !node.disabled && node.getClientRects().length);
            const first = focusable[0], last = focusable.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });

    let editingColumn = -1;
    let nextField = 1;
    let sampleValues = {};
    function openSettings(index) {
        editingColumn = index;
        const column = columns[currentProject][index];
        const config = column.draft || configFor(column);
        el('settingsTitle').textContent = displayName(column) + ' settings';
        el('activityName').value = config.name;
        el('activityUnit').value = config.unit;
        el('settingsFormula').value = config.formula;
        el('settingsRows').replaceChildren();
        sampleValues = {};
        config.fields.forEach(addSettingsRow);
        refreshOutput(config.outputField);
        el('settingsError').classList.add('hidden');
        showModal('settingsBackdrop');
    }
    function addSettingsRow(input) {
        let key;
        do { key = 'input_' + nextField++; } while ([...el('settingsRows').children].some(row => row.dataset.key === key));
        const item = input || { ...field(key, ''), required: false };
        const row = document.createElement('div');
        row.className = 'settings-panel';
        row.dataset.key = item.key;
        row.innerHTML = '<div class="formgrid"><label class="field">Title<input class="input row-name" value="' + escapeHTML(item.name ?? '') + '"></label><div class="field">Formula key <code>' + escapeHTML(item.key) + '</code></div><label class="field">Datatype<select class="input row-type">' + P.types.map(type => '<option ' + (type === item.type ? 'selected' : '') + '>' + type + '</option>').join('') + '</select></label><label class="field">Minimum (optional)<input type="number" step="any" class="input row-min" value="' + (item.min ?? '') + '"></label><label class="field">Maximum (optional)<input type="number" step="any" class="input row-max" value="' + (item.max ?? '') + '"></label><label><input type="checkbox" class="row-required" ' + (item.required ? 'checked' : '') + '> Required</label></div><button class="btn remove-row" type="button">Remove input</button>';
        el('settingsRows').appendChild(row);
        function updateLimits() {
            const numeric = P.numeric(row.querySelector('.row-type').value);
            row.querySelectorAll('.row-min, .row-max').forEach(input => { input.disabled = !numeric; if (!numeric) input.value = ''; });
        }
        updateLimits();
        row.addEventListener('input', () => refreshOutput());
        row.querySelector('.row-type').onchange = () => { updateLimits(); refreshOutput(); };
        row.querySelector('.remove-row').onclick = () => { row.remove(); refreshOutput(); };
        refreshOutput();
    }
    function settingsData() {
        return {
            name: el('activityName').value.trim(), unit: el('activityUnit').value.trim(),
            formula: el('settingsFormula').value.trim(), outputField: el('outputField').value,
            fields: [...el('settingsRows').children].map(row => ({
                key: row.dataset.key, name: row.querySelector('.row-name').value.trim(), type: row.querySelector('.row-type').value,
                required: row.querySelector('.row-required').checked,
                min: row.querySelector('.row-min').value === '' ? null : Number(row.querySelector('.row-min').value),
                max: row.querySelector('.row-max').value === '' ? null : Number(row.querySelector('.row-max').value),
            })),
        };
    }
    function refreshOutput(selected) {
        const config = settingsData();
        const output = selected || el('outputField').value;
        el('outputField').innerHTML = config.fields.map(item => '<option value="' + escapeHTML(item.key) + '">' + escapeHTML((item.name || 'Untitled') + ' (' + item.key + ')') + '</option>').join('');
        if (config.fields.some(item => item.key === output)) el('outputField').value = output;
        el('outputField').disabled = !!el('settingsFormula').value.trim();
        el('sampleInputs').querySelectorAll('[data-sample]').forEach(input => { sampleValues[input.dataset.sample] = input.value; });
        el('sampleInputs').innerHTML = config.fields.map(item => '<label class="field">' + escapeHTML(item.name || item.key) + '<input class="input" data-sample="' + escapeHTML(item.key) + '" type="' + (P.numeric(item.type) ? 'number' : item.type === 'Datetime' ? 'datetime-local' : 'text') + '" step="' + (item.type === 'Integer' ? '1' : 'any') + '" value="' + escapeHTML(sampleValues[item.key] ?? '') + '"></label>').join('');
        el('formulaResult').textContent = 'Enter sample values and select Test calculation.';
    }
    function settingsError(error) {
        el('settingsError').textContent = error.message;
        el('settingsError').classList.remove('hidden');
    }
    async function saveSettings(publish) {
        if (busy) return;
        try {
            const config = settingsData();
            const column = columns[currentProject][editingColumn];
            if (api) {
                setBusy(true);
                await api.save(projectId(), column, config, publish, saved => {
                    columns[currentProject][editingColumn] = saved;
                    pendingNewColumn = null;
                });
                setBusy(false);
            } else columns[currentProject][editingColumn] = publish ? P.publish(column, config) : P.saveDraft(column, config);
            if (publish && column.versions.length === 0) columns[currentProject][editingColumn].visible = true;
            pendingNewColumn = null;
            render();
            hideModal();
        } catch (error) { settingsError(error); } finally { setBusy(false); }
    }
    el('testFormula').onclick = async () => {
        if (busy) return;
        el('settingsError').classList.add('hidden');
        try {
            const raw = Object.fromEntries([...el('sampleInputs').querySelectorAll('[data-sample]')].map(input => [input.dataset.sample, input.value]));
            if (api) setBusy(true);
            const { result } = api ? (await api.project(projectId(), 'activities/preview', 'POST', { configuration: settingsData(), inputs: raw })).data : P.calculate(settingsData(), raw);
            el('formulaResult').textContent = 'Result: ' + (typeof result === 'number' ? formatNumber(result) : result);
        } catch (error) { el('formulaResult').textContent = 'Unable to calculate: ' + error.message; } finally { setBusy(false); }
    };

    let entryNumber = 0;
    function openProgress() {
        if (api && (!workspace?.capabilities.can_submit || !workspace.routes.length)) return;
        reportAttempt = null;
        el('progressError').classList.add('hidden');
        el('activityInputs').replaceChildren();
        el('route').innerHTML = api ? workspace.routes.map(route => '<option value="' + route.id + '">' + escapeHTML(route.name) + '</option>').join('') : routes[currentProject].map(route => '<option>' + escapeHTML(route) + '</option>').join('');
        el('date').value = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kuala_Lumpur', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
        publishedColumns().forEach(column => {
            const section = document.createElement('div');
            section.className = 'section';
            section.innerHTML = '<div class="entry-head"><b>' + escapeHTML(configFor(column).name) + ' · v' + configFor(column).version + '</b><button class="btn" type="button">' + icon('plus') + 'Add entry</button></div><div class="entry-list"></div>';
            section.querySelector('button').onclick = () => addInput(column, section.querySelector('.entry-list'));
            el('activityInputs').appendChild(section);
            addInput(column, section.querySelector('.entry-list'));
        });
        if (!publishedColumns().length) el('activityInputs').textContent = 'Publish an activity in Column Settings before adding progress.';
        showModal('modalBackdrop');
    }
    function addInput(column, host) {
        const config = P.clone(configFor(column));
        const row = document.createElement('div');
        const prefix = 'entry-' + entryNumber++;
        row.className = 'entry activity-entry';
        row.dataset.activity = column.id;
        row.configuration = config;
        row.innerHTML = '<div class="formgrid">' + config.fields.map(item => '<label class="field" for="' + prefix + '-' + item.key + '">' + escapeHTML(item.name) + (item.required ? ' *' : '') + '<input class="input dynamic-input" id="' + prefix + '-' + item.key + '" data-field="' + escapeHTML(item.key) + '" type="' + (item.type === 'Datetime' ? 'datetime-local' : P.numeric(item.type) ? 'number' : 'text') + '" step="' + (item.type === 'Integer' ? '1' : 'any') + '" ' + (item.min != null ? 'min="' + item.min + '"' : '') + ' ' + (item.max != null ? 'max="' + item.max + '"' : '') + '></label>').join('') + '<label class="field">Team / subcontractor (override)<input class="input entry-team" placeholder="Use report team"></label><label class="field">Location (override)<input class="input entry-location" placeholder="Use report location"></label></div><button class="link remove-entry" type="button">Remove entry</button>';
        row.querySelector('.remove-entry').onclick = () => row.remove();
        host.appendChild(row);
    }
    async function saveProgress() {
        if (busy) return;
        try {
            const date = el('date').value;
            const team = el('team').value.trim();
            if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !team) throw Error('A valid date and report team are required.');
            if (api) {
                const rows = [...document.querySelectorAll('.activity-entry')].map(row => ({
                    activity_id: Number(row.dataset.activity), version: row.configuration.version,
                    inputs: Object.fromEntries([...row.querySelectorAll('.dynamic-input')].map(input => [input.dataset.field, input.value])),
                    team: row.querySelector('.entry-team').value.trim() || null,
                    location: row.querySelector('.entry-location').value.trim() || null,
                })).filter(row => Object.values(row.inputs).some(value => value.trim()) || row.team || row.location);
                if (!rows.length) throw Error('Enter at least one activity value.');
                const payload = { route_id: Number(el('route').value), report_date: date, team, location: el('chainage').value.trim() || null, entries: rows };
                const signature = JSON.stringify(payload);
                if (!reportAttempt || reportAttempt.signature !== signature) reportAttempt = { signature, key: crypto.randomUUID() };
                setBusy(true);
                await api.project(projectId(), 'reports', 'POST', { ...payload, submission_key: reportAttempt.key });
                setBusy(false);
                hideModal();
                navigate('tracker');
                await loadWorkspace();
                return;
            }
            const pending = [];
            document.querySelectorAll('.activity-entry').forEach(row => {
                const raw = Object.fromEntries([...row.querySelectorAll('.dynamic-input')].map(input => [input.dataset.field, input.value]));
                if (!Object.values(raw).some(value => value.trim() !== '') && !row.querySelector('.entry-team').value.trim() && !row.querySelector('.entry-location').value.trim()) return;
                try {
                    pending.push({
                        project: currentProject, route: el('route').value, date,
                        team: row.querySelector('.entry-team').value.trim() || team,
                        location: row.querySelector('.entry-location').value.trim() || el('chainage').value.trim(),
                        activity: row.dataset.activity, configuration: P.clone(row.configuration),
                        ...P.calculate(row.configuration, raw), status: 'Pending',
                    });
                } catch (error) { throw Error(row.configuration.name + ': ' + error.message); }
            });
            if (!pending.length) throw Error('Enter at least one activity value.');
            // Validate prospective aggregates before committing any part of the report.
            P.groupEntries([...entries, ...pending], currentProject).forEach(group => {
                publishedColumns().forEach(column => total(group, column));
            });
            entries.push(...pending);
            hideModal();
            navigate('tracker');
        } catch (error) {
            el('progressError').textContent = error.message;
            el('progressError').classList.remove('hidden');
        } finally { setBusy(false); }
    }
    async function showDetails(group) {
        if (api) {
            const selected = projectId();
            try {
                const reports = [];
                for (const id of group.report_ids) reports.push((await api.project(selected, 'reports/' + id)).data);
                if (selected !== projectId()) return;
                group = { ...group, entries: reports.flatMap(report => report.entries.map(entry => ({ ...entry,
                    configuration: { ...entry.configuration, version: entry.version }, values: entry.inputs,
                }))) };
            } catch (error) { connectionError(error); return; }
        }
        el('detailsTitle').textContent = group.route + ' · ' + group.date;
        el('detailsBody').innerHTML = group.entries.map(entry => '<section class="entry"><h3>' + escapeHTML(entry.configuration.name) + ' · v' + entry.configuration.version + '</h3><p>' + escapeHTML(entry.team + ' · ' + (entry.location || 'No location') + ' · ' + entry.status) + '</p><p><b>Recorded: ' + escapeHTML(entry.result + ' ' + entry.configuration.unit) + '</b></p><dl>' + entry.configuration.fields.map(item => '<dt>' + escapeHTML(item.name) + '</dt><dd>' + escapeHTML(entry.values[item.key] ?? '—') + '</dd>').join('') + '</dl><p class="foot">' + escapeHTML(entry.configuration.formula ? 'Formula: ' + entry.configuration.formula : 'Output input: ' + entry.configuration.outputField) + '</p></section>').join('');
        showModal('detailsBackdrop');
    }
    function exportCSV() {
        const cols = visibleColumns();
        const rows = [['Route', 'Progress date', ...cols.flatMap(column => [configFor(column).name + ' (recorded)', configFor(column).name + ' (approved)']), 'Status']];
        filteredGroups().forEach(group => rows.push([group.route, group.date, ...cols.flatMap(column => [total(group, column), total(group, column, 'Approved')]), status(group)]));
        const csv = rows.map(row => row.map(value => {
            const text = String(value);
            const safe = typeof value === 'string' && /^[\s]*[=+\-@]/.test(text) ? "'" + text : text;
            return '"' + safe.replace(/"/g, '""') + '"';
        }).join(',')).join('\r\n');
        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        link.download = api ? 'northwest-progress-page-' + trackerMeta.page + '.csv' : 'northwest-progress-demo.csv';
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    }

    document.querySelectorAll('.nav').forEach(button => { button.onclick = () => navigate(button.dataset.page); });
    document.querySelectorAll('[data-tab-page]').forEach(button => { button.onclick = () => navigate(button.dataset.tabPage); });
    el('projectSelect').onchange = event => {
        if (busy) return;
        currentProject = event.target.value;
        try { localStorage.setItem(api ? 'northwest-live-project' : 'northwest-demo-project', currentProject); } catch (_) { /* Storage is optional. */ }
        el('search').value = '';
        el('mobileSearch').value = '';
        el('statusFilter').value = '';
        if (api) { loadWorkspace(); return; }
        render();
    };
    el('search').oninput = () => { el('mobileSearch').value = el('search').value; navigate('tracker'); };
    el('mobileSearch').oninput = () => { el('search').value = el('mobileSearch').value; render(); };
    el('statusFilter').onchange = render;
    ['trackerNew', 'dashNew'].forEach(id => { el(id).onclick = openProgress; });
    el('trackerConfig').onclick = () => navigate('config');
    el('dashTracker').onclick = () => navigate('tracker');
    ['closeModal', 'cancelModal', 'closeSettings', 'cancelSettings', 'closeDetails', 'closeSetup'].forEach(id => { el(id).onclick = hideModal; });
    el('saveProgress').onclick = saveProgress;
    el('menuBtn').onclick = () => { el('menuBtn').setAttribute('aria-expanded', String(el('sidebar').classList.toggle('open'))); };
    el('addColumn').onclick = () => {
        columns[currentProject].push({ id: 'custom-' + Date.now(), visible: false, versions: [], draft: { name: 'New activity', unit: '', fields: [field('input_1', 'Quantity')], formula: '', outputField: 'input_1' } });
        pendingNewColumn = columns[currentProject].at(-1);
        render();
        openSettings(columns[currentProject].length - 1);
    };
    el('addInputRow').onclick = () => addSettingsRow();
    el('settingsFormula').oninput = () => refreshOutput();
    el('saveDraft').onclick = () => saveSettings(false);
    el('publishSettings').onclick = () => saveSettings(true);
    el('exportBtn').onclick = exportCSV;
    function connectionError(error) {
        el('connectionStatus').textContent = error.message;
        el('connectionStatus').classList.toggle('connection-error', !error.loading);
        el('connectionStatus').classList.remove('hidden');
    }
    let disabledModalControls = [];
    function setBusy(value) {
        if (value && !busy && activeModal) {
            disabledModalControls = [...el(activeModal).querySelectorAll('input, select, button, textarea')].map(node => [node, node.disabled]);
            disabledModalControls.forEach(([node]) => { node.disabled = true; });
        }
        if (!value) {
            disabledModalControls.forEach(([node, disabled]) => { node.disabled = disabled; });
            disabledModalControls = [];
        }
        busy = value;
        for (const id of ['saveProgress', 'saveDraft', 'publishSettings', 'saveSetup', 'projectSelect', 'refreshWorkspace', 'previousPage', 'nextPage']) el(id).disabled = value;
        if (api && !value) {
            el('previousPage').disabled = trackerMeta.page <= 1;
            el('nextPage').disabled = trackerMeta.page * trackerMeta.per_page >= trackerMeta.total;
        }
    }
    async function loadWorkspace(page = 1) {
        const sequence = ++loadSequence;
        const selected = projectId();
        serverGroups = [];
        columns[selected] = [];
        workspace = null;
        render();
        applyCapabilities();
        if (!selected) { connectionError(Error('No projects assigned. Ask your project manager to assign access.')); return; }
        connectionError(Object.assign(Error('Loading project data…'), { loading: true }));
        try {
            const [loaded, tracker] = await Promise.all([api.project(selected, 'workspace'), api.project(selected, 'tracker?page=' + page)]);
            if (sequence !== loadSequence) return;
            workspace = loaded;
            columns[selected] = loaded.activities.map(row => api.column(row));
            try {
                const hidden = JSON.parse(localStorage.getItem('northwest-hidden-columns-' + selected) || '[]');
                if (Array.isArray(hidden)) columns[selected].forEach(column => { if (hidden.includes(column.id)) column.visible = false; });
            } catch (_) { /* Storage is optional. */ }
            serverGroups = tracker.data.map(group => ({ ...group, route: group.route.name, date: group.report_date }));
            trackerMeta = tracker.meta;
            render();
            applyCapabilities();
            el('connectionStatus').textContent = 'Connected · Project data saved to ERP';
            el('connectionStatus').classList.remove('connection-error');
            el('pageLabel').textContent = 'Page ' + trackerMeta.page + ' of ' + Math.max(1, Math.ceil(trackerMeta.total / trackerMeta.per_page));
            el('previousPage').disabled = trackerMeta.page <= 1;
            el('nextPage').disabled = trackerMeta.page * trackerMeta.per_page >= trackerMeta.total;
        } catch (error) { if (sequence === loadSequence) connectionError(error); }
    }
    function applyCapabilities() {
        const configure = workspace?.capabilities.can_configure || false;
        for (const id of ['trackerNew', 'dashNew']) el(id).disabled = !workspace?.capabilities.can_submit || !workspace?.routes.length || !publishedColumns().length;
        for (const id of ['addColumn', 'routeSetup']) el(id).disabled = !configure;
        el('memberSetup').classList.toggle('hidden', !workspace?.capabilities.can_manage_members);
        document.querySelectorAll('[data-settings]').forEach(button => { button.disabled = !configure; });
    }
    let setupKind = '';
    function openSetup(kind) {
        setupKind = kind;
        el('projectTools').open = false;
        el('setupError').classList.add('hidden');
        el('setupTitle').textContent = kind === 'route' ? 'Add project route' : 'Project member access';
        el('setupBody').innerHTML = kind === 'route' ? '<label class="field">Route name<input class="input" id="setupRoute" maxlength="120"></label>'
            : '<label class="field">Team member<select class="input" id="setupUser">' + workspace.member_candidates.map(user => '<option value="' + user.id + '">' + escapeHTML(user.name) + '</option>').join('') + '</select></label><label><input type="checkbox" id="setupSubmit"> Can submit progress</label><br><label><input type="checkbox" id="setupConfigure"> Can configure activities and routes</label><p class="foot">Unchecked permissions retain read-only project membership.</p><div>' + workspace.members.map(member => '<p>' + escapeHTML(member.name || 'Unavailable user') + ' · ' + (member.can_submit ? 'Submit' : 'Read only') + (member.can_configure ? ' · Configure' : '') + '</p>').join('') + '</div>';
        if (kind === 'member') {
            const update = () => {
                const member = workspace.members.find(member => String(member.user_id) === el('setupUser').value);
                el('setupSubmit').checked = member?.can_submit || false;
                el('setupConfigure').checked = member?.can_configure || false;
            };
            el('setupUser').onchange = update;
            update();
        }
        showModal('setupBackdrop');
    }
    el('saveSetup').onclick = async () => {
        if (busy) return;
        try {
            setBusy(true);
            await api.project(projectId(), setupKind === 'route' ? 'routes' : 'members', setupKind === 'route' ? 'POST' : 'PUT', setupKind === 'route' ? { name: el('setupRoute').value.trim() }
                : { user_id: Number(el('setupUser').value), can_submit: el('setupSubmit').checked, can_configure: el('setupConfigure').checked });
            setBusy(false);
            hideModal();
            await loadWorkspace();
        } catch (error) { el('setupError').textContent = error.message; el('setupError').classList.remove('hidden'); } finally { setBusy(false); }
    };
    async function startConnected() {
        currentProject = '';
        columns[''] = [];
        entries.length = 0;
        el('projectSelect').replaceChildren();
        el('liveControls').classList.remove('hidden');
        el('trackerPagination').classList.remove('hidden');
        el('saveProgress').textContent = 'Save progress';
        el('exportBtn').title = 'Export filtered rows on the loaded page';
        document.querySelector('.sidebar-footer p').textContent = 'Connected ERP workspace';
        document.querySelector('#config .notice').textContent = 'Drafts are saved separately. Publishing affects new entries; historical inputs, formulas and results retain their original version. Historical recalculation and approval workflows remain pending.';
        document.querySelector('#modalBackdrop .foot').textContent = 'Entries are validated and saved by the server.';
        document.querySelector('#detailsBackdrop .notice').textContent = 'Original recorded inputs, configuration version and result. Claim eligibility is not yet implemented.';
        document.querySelector('#dashboard .stat .number').textContent = '—';
        document.querySelector('#dashboard .stat .label').textContent = 'Assigned projects';
        const cards = document.querySelectorAll('#dashboard .stat');
        cards[1].querySelector('.label').textContent = 'Published activities';
        cards[2].querySelector('.label').textContent = 'Daily reports';
        cards[2].querySelector('.muted').textContent = 'Loaded tracker page';
        cards[3].querySelector('.label').textContent = 'Activity entries';
        cards[3].querySelector('.muted').textContent = 'Loaded tracker page';
        el('search').placeholder = 'Search this tracker page';
        el('mobileSearch').placeholder = 'Search this tracker page';
        el('team').value = '';
        navigate('tracker');
        applyCapabilities();
        el('refreshWorkspace').onclick = () => loadWorkspace();
        el('previousPage').onclick = () => loadWorkspace(trackerMeta.page - 1);
        el('nextPage').onclick = () => loadWorkspace(trackerMeta.page + 1);
        el('routeSetup').onclick = () => openSetup('route');
        el('memberSetup').onclick = () => openSetup('member');
        try {
            projectRows = await api.projects();
            document.querySelector('#dashboard .stat .number').textContent = projectRows.length;
            el('projectSelect').innerHTML = projectRows.map(project => '<option value="' + project.id + '">' + escapeHTML(project.name) + '</option>').join('');
            document.querySelector('#projects tbody').innerHTML = projectRows.map(project => '<tr><td>' + escapeHTML(project.name) + '</td><td colspan="2">Assigned project</td></tr>').join('');
            let remembered;
            try { remembered = localStorage.getItem('northwest-live-project'); } catch (_) { /* Storage is optional. */ }
            currentProject = String(projectRows.find(project => String(project.id) === remembered)?.id || projectRows[0]?.id || '');
            el('projectSelect').value = currentProject;
            await loadWorkspace();
        } catch (error) { connectionError(error); }
    }
    if (api) startConnected();
    else navigate('tracker');
})();
