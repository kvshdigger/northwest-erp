(function (root) {
    'use strict';
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
    const safeLink = value => {
        if (!value) return null;
        const url = new URL(value, location.href);
        return url.origin === location.origin && ['http:', 'https:'].includes(url.protocol) ? url.href : null;
    };
    class BusinessModules {
        constructor(api) { this.api = api; this.states = {}; }
        async open(module) {
            const host = document.getElementById(module + 'Workspace');
            if (this.states[module]) return;
            host.innerHTML = '<p class="module-message" role="status">Loading your workspace…</p>';
            try {
                const catalog = await this.api.request('modules/' + module);
                this.states[module] = { catalog, section: catalog.sections.find(item => item.state === 'available')?.key || catalog.sections[0].key, page: 1, search: '', sequence: 0 };
                this.render(module);
                await this.load(module);
            } catch (error) { host.innerHTML = '<p class="notice" role="alert">' + escape(error.message) + '</p><button class="btn" data-retry>Try again</button>'; host.querySelector('[data-retry]').onclick = () => this.open(module); }
        }
        render(module) {
            const state = this.states[module], host = document.getElementById(module + 'Workspace');
            const { catalog } = state;
            host.innerHTML = '<div class="module-intro"><span class="scope-badge">Company-wide</span><p>Records for your permitted company selection. Access follows your Finance or HR role.</p></div>'
                + '<nav class="module-tabs" aria-label="' + (module === 'finance' ? 'Finance' : 'HR') + ' sections">' + catalog.sections.map(item => '<button class="btn ' + (item.key === state.section ? 'primary' : '') + '" data-section="' + item.key + '" aria-pressed="' + (item.key === state.section) + '">' + escape(item.title) + '</button>').join('') + '</nav>'
                + '<section class="panel"><div class="module-records"></div></section>'
                + '<section class="module-roadmap"><h2>' + (module === 'finance' ? 'Finance' : 'HR') + ' workflow roadmap</h2><p class="muted">Planned capabilities. These workflows are not available yet.</p><div class="module-capabilities">' + catalog.planned.map(item => '<article><span class="scope-badge planned">Planned</span><h3>' + escape(item.title) + '</h3><p>' + escape(item.description) + '</p></article>').join('') + '</div></section>';
            host.querySelectorAll('[data-section]').forEach(button => { button.onclick = () => { state.section = button.dataset.section; state.page = 1; state.search = ''; this.render(module); this.load(module); }; });
        }
        async load(module) {
            const state = this.states[module], sequence = ++state.sequence;
            const item = state.catalog.sections.find(item => item.key === state.section);
            const host = document.querySelector('#' + module + 'Workspace .module-records');
            if (item.state !== 'available') {
                host.innerHTML = '<h2>' + escape(item.title) + '</h2><p class="muted">' + escape(item.description) + '</p><div class="module-empty"><h3>' + (item.state === 'restricted' ? 'Access required' : 'Module not installed') + '</h3><p>' + (item.state === 'restricted' ? 'Ask your ERP administrator for the appropriate role. Project membership does not grant access to finance or employee records.' : 'An administrator must install this ERP module in the configured environment before records can be shown.') + '</p></div>';
                return;
            }
            const indexUrl = safeLink(item.url), createUrl = safeLink(item.create_url);
            host.innerHTML = '<div class="heading"><div><h2>' + escape(item.title) + '</h2><p class="muted">' + escape(item.description) + '</p></div><div class="actions">' + (indexUrl ? '<a class="btn" href="' + escape(indexUrl) + '">Manage in ERP</a>' : '') + (createUrl ? '<a class="btn primary" href="' + escape(createUrl) + '">Create record</a>' : '') + '</div></div>'
                + '<form class="module-search"><label class="field">Search ' + escape(item.title.toLowerCase()) + '<input class="input" name="search" maxlength="120" value="' + escape(state.search) + '" placeholder="Search reference or name"></label><button class="btn" type="submit">Search</button><button class="btn" type="button" data-refresh>Refresh</button></form><div class="module-results" role="status">Loading records…</div>';
            const form = host.querySelector('form');
            form.onsubmit = event => { event.preventDefault(); state.search = form.elements.search.value.trim(); state.page = 1; this.load(module); };
            host.querySelector('[data-refresh]').onclick = () => this.load(module);
            const results = host.querySelector('.module-results');
            try {
                const data = await this.api.request('modules/' + module + '/' + state.section + '?page=' + state.page + '&search=' + encodeURIComponent(state.search));
                if (sequence !== state.sequence) return;
                results.removeAttribute('role');
                results.innerHTML = data.rows.length ? '<div class="tablewrap business-table" tabindex="0" role="region" aria-label="' + escape(item.title) + '"><table><thead><tr>' + data.columns.map(column => '<th scope="col">' + escape(column) + '</th>').join('') + '<th scope="col">Record</th></tr></thead><tbody>' + data.rows.map(row => '<tr>' + row.cells.map(cell => '<td>' + escape(cell) + '</td>').join('') + '<td>' + (safeLink(row.url) ? '<a class="link" href="' + escape(safeLink(row.url)) + '">Open record</a>' : '—') + '</td></tr>').join('') + '</tbody></table></div>' : '<div class="module-empty"><h3>No matching records</h3><p>There are no records visible to your account for this search. Use the ERP workflow to create or manage records when permitted.</p></div>';
                results.insertAdjacentHTML('beforeend', '<div class="module-pagination"><span>' + data.rows.length + ' records shown · Page ' + data.page + '</span><button class="btn" data-prev ' + (data.page <= 1 ? 'disabled' : '') + '>Previous</button><button class="btn" data-next ' + (!data.has_next ? 'disabled' : '') + '>Next</button></div><p class="foot">' + (module === 'finance' ? 'Amounts are shown in their original currencies. Open a record to review its accounting details and permitted actions.' : 'Work information only. Open a record for the details permitted by your HR role.') + '</p>');
                results.querySelector('[data-prev]').onclick = () => { state.page--; this.load(module); };
                results.querySelector('[data-next]').onclick = () => { state.page++; this.load(module); };
            } catch (error) { if (sequence === state.sequence) results.innerHTML = '<p class="notice" role="alert">' + escape(error.message) + '</p>'; }
        }
    }
    root.NorthwestBusinessModules = BusinessModules;
})(globalThis);
