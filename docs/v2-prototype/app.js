(function () {
    'use strict';

    const P = NorthwestProgress;
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
    el('projectSelect').value = currentProject;

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
    const groups = () => P.groupEntries(entries, currentProject);
    function status(group) {
        const statuses = [...new Set(group.entries.map(entry => entry.status))];
        return statuses.length === 1 ? statuses[0] : 'Mixed';
    }
    const total = (group, column, state = null) => P.aggregate(group, column, state);
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
        el('breadcrumbProject').textContent = currentProject;
        el('recordCount').textContent = visible.length + (visible.length === 1 ? ' daily report' : ' daily reports');
        el('statReports').textContent = grouped.length;
        const pending = entries.filter(entry => entry.project === currentProject && entry.status === 'Pending');
        el('statOpen').textContent = pending.length;
        el('statPendingReports').textContent = grouped.filter(group => group.entries.some(entry => entry.status === 'Pending')).length;
        el('trackerHead').innerHTML = '<tr><th>Route</th><th>Progress date</th>' + cols.map(column => '<th>' + escapeHTML(configFor(column).name) + '</th>').join('') + '<th>Status</th><th>Details</th></tr>';
        el('trackerBody').innerHTML = visible.map((group, index) => '<tr><td><b>' + escapeHTML(group.route) + '</b></td><td>' + group.date + '</td>' + cols.map(column => {
            const value = total(group, column);
            return '<td>' + escapeHTML(typeof value === 'number' ? formatNumber(value) + ' ' + configFor(column).unit : value || '—')
                + (typeof value === 'number' ? '<div class="foot">Approved: ' + escapeHTML(formatNumber(total(group, column, 'Approved')) + ' ' + configFor(column).unit) + '</div>' : '') + '</td>';
        }).join('') + '<td><span class="pill ' + status(group).toLowerCase() + '">' + status(group) + '</span></td><td><button class="link" data-detail="' + index + '">View entries</button></td></tr>').join('') || '<tr><td colspan="' + (cols.length + 4) + '">No progress records found. Add progress or adjust the filters.</td></tr>';
        document.querySelectorAll('[data-detail]').forEach(button => { button.onclick = () => showDetails(visible[Number(button.dataset.detail)]); });
        el('recent').innerHTML = '<div class="tablewrap"><table><thead><tr><th>Route</th><th>Date</th><th>Entries</th><th>Status</th></tr></thead><tbody>' + grouped.slice(0, 5).map(group => '<tr><td>' + escapeHTML(group.route) + '</td><td>' + group.date + '</td><td>' + group.entries.length + '</td><td>' + status(group) + '</td></tr>').join('') + '</tbody></table></div>';
        el('columnList').innerHTML = columns[currentProject].map((column, index) => {
            const published = configFor(column);
            return '<div class="config-row"><span>' + (index + 1) + '</span><div><b>' + escapeHTML(published?.name || displayName(column)) + '</b><div class="foot">' + escapeHTML(published?.unit || '') + '</div></div><div>' + (published ? 'Version ' + published.version : 'Unpublished') + '</div><label class="config-check"><input type="checkbox" data-toggle="' + index + '" ' + (column.visible ? 'checked' : '') + (published ? '' : ' disabled') + '> Show in tracker</label><div class="config-status">' + (column.draft ? 'DRAFT' + (published ? ' · published version retained' : '') : 'PUBLISHED') + '</div><button class="btn" data-settings="' + index + '">Settings</button></div>';
        }).join('');
        document.querySelectorAll('[data-toggle]').forEach(input => { input.onchange = () => { columns[currentProject][Number(input.dataset.toggle)].visible = input.checked; render(); }; });
        document.querySelectorAll('[data-settings]').forEach(button => { button.onclick = () => openSettings(Number(button.dataset.settings)); });
    }

    let activeModal = null;
    let returnFocus = null;
    let pendingNewColumn = null;
    function showModal(id) {
        returnFocus = document.activeElement;
        activeModal = id;
        el(id).classList.remove('hidden');
        document.querySelector('.app').inert = true;
        el(id).querySelector('input, button, select')?.focus();
    }
    function hideModal() {
        el(activeModal).classList.add('hidden');
        activeModal = null;
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
        row.innerHTML = '<div class="formgrid"><label class="field">Title<input class="input row-name" value="' + escapeHTML(item.name) + '"></label><div class="field">Formula key <code>' + escapeHTML(item.key) + '</code></div><label class="field">Datatype<select class="input row-type">' + P.types.map(type => '<option ' + (type === item.type ? 'selected' : '') + '>' + type + '</option>').join('') + '</select></label><label class="field">Minimum (optional)<input type="number" step="any" class="input row-min" value="' + (item.min ?? '') + '"></label><label class="field">Maximum (optional)<input type="number" step="any" class="input row-max" value="' + (item.max ?? '') + '"></label><label><input type="checkbox" class="row-required" ' + (item.required ? 'checked' : '') + '> Required</label></div><button class="btn remove-row" type="button">Remove input</button>';
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
    function saveSettings(publish) {
        try {
            const config = settingsData();
            const column = columns[currentProject][editingColumn];
            columns[currentProject][editingColumn] = publish ? P.publish(column, config) : P.saveDraft(column, config);
            if (publish && column.versions.length === 0) columns[currentProject][editingColumn].visible = true;
            pendingNewColumn = null;
            render();
            hideModal();
        } catch (error) { settingsError(error); }
    }
    el('testFormula').onclick = () => {
        el('settingsError').classList.add('hidden');
        try {
            const raw = Object.fromEntries([...el('sampleInputs').querySelectorAll('[data-sample]')].map(input => [input.dataset.sample, input.value]));
            const { result } = P.calculate(settingsData(), raw);
            el('formulaResult').textContent = 'Result: ' + (typeof result === 'number' ? formatNumber(result) : result);
        } catch (error) { el('formulaResult').textContent = 'Unable to calculate: ' + error.message; }
    };

    let entryNumber = 0;
    function openProgress() {
        el('progressError').classList.add('hidden');
        el('activityInputs').replaceChildren();
        el('route').innerHTML = routes[currentProject].map(route => '<option>' + escapeHTML(route) + '</option>').join('');
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
    function saveProgress() {
        try {
            const date = el('date').value;
            const team = el('team').value.trim();
            if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !team) throw Error('A valid date and report team are required.');
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
        }
    }
    function showDetails(group) {
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
        link.download = 'northwest-progress-demo.csv';
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    }

    document.querySelectorAll('.nav').forEach(button => { button.onclick = () => navigate(button.dataset.page); });
    document.querySelectorAll('[data-tab-page]').forEach(button => { button.onclick = () => navigate(button.dataset.tabPage); });
    el('projectSelect').onchange = event => {
        currentProject = event.target.value;
        try { localStorage.setItem('northwest-demo-project', currentProject); } catch (_) { /* Storage is optional. */ }
        el('search').value = '';
        el('mobileSearch').value = '';
        el('statusFilter').value = '';
        render();
    };
    el('search').oninput = () => { el('mobileSearch').value = el('search').value; navigate('tracker'); };
    el('mobileSearch').oninput = () => { el('search').value = el('mobileSearch').value; render(); };
    el('statusFilter').onchange = render;
    ['trackerNew', 'dashNew'].forEach(id => { el(id).onclick = openProgress; });
    el('trackerConfig').onclick = () => navigate('config');
    el('dashTracker').onclick = () => navigate('tracker');
    ['closeModal', 'cancelModal', 'closeSettings', 'cancelSettings', 'closeDetails'].forEach(id => { el(id).onclick = hideModal; });
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
    navigate('tracker');
})();
