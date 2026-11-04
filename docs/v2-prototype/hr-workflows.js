(function (root) {
    'use strict';
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const label = key => key.replace(/_/g, ' ').replace(/^./, c => c.toUpperCase());
    const titles = { attendance: 'Attendance & timesheets', payroll: 'Payroll & payslips', documents: 'Employment documents', 'payroll-rules': 'Payroll configuration', checklists: 'Onboarding & offboarding', expenses: 'Expenses' };
    class HrWorkflows {
        constructor(api) { this.api = api; }
        async render(host, kind, self = false, page = 1, expiry = 'all') {
            host.classList.add('hr-workflows');
            host.innerHTML = '<p role="status">Loading HR workspace…</p>';
            if (kind === 'self-service') return this.selfService(host);
            try {
                const suffix = '?self=' + (self ? '1' : '0');
                const [form, data] = await Promise.all([this.api.request('hr-workflows/' + kind + '/form' + suffix), this.api.request('hr-workflows/' + kind + suffix + '&page=' + page + '&expiry=' + expiry)]);
                const money = row => row.values.net_minor !== undefined ? (row.values.net_minor / 10 ** row.values.precision).toFixed(row.values.precision) + ' ' + row.values.currency : row.values.amount || '';
                host.innerHTML = '<div class="heading"><div><h2>' + esc(titles[kind]) + '</h2><p class="muted">' + (self ? 'Your private employee records.' : 'Company records. Submitted entries require an independent authorised reviewer.') + '</p></div>' + (form.can_create ? '<button class="btn primary" data-create>New ' + esc(kind === 'payroll-rules' ? 'configuration' : kind === 'payroll' ? 'payroll draft' : kind === 'checklists' ? 'checklist' : kind === 'attendance' ? 'timesheet' : kind === 'documents' ? 'document' : 'expense') + '</button>' : '') + '</div><div class="actions" data-related></div>'
                    + (kind === 'payroll' ? '<p class="notice">Malaysia is the default configurable profile. Enter and verify employee-specific statutory amounts using the current official schedules. Approved payslips do not initiate bank payments or statutory remittances.</p>' : '')
                    + (kind === 'documents' ? '<p class="notice">Private documents and expiry reminders. Upload a replacement as a new version to preserve history.</p>' : '')
                    + '<div class="tablewrap" tabindex="0"><table><thead><tr><th>Reference</th><th>Employee / configuration</th><th>Details</th><th>Status</th><th>Actions</th></tr></thead><tbody>' + data.rows.map(row => '<tr><td>#' + row.id + '</td><td>' + esc(row.employee || row.values.title) + '</td><td>' + esc(row.values.title || row.values.starts_at || row.values.period_from || '') + (row.values.worked_minutes ? ' · ' + esc(row.values.worked_minutes) + ' minutes worked' : '') + (money(row) ? ' · ' + esc(money(row)) : '') + (row.expiry_status ? ' · ' + esc(row.expiry_status) + ' (' + esc(row.values.expires_on) + ')' : '') + '</td><td>' + esc(label(row.state)) + '</td><td><button class="btn" data-view="' + row.id + '">View</button></td></tr>').join('') + '</tbody></table></div>' + (!data.rows.length ? '<p class="muted">No records on this page.</p>' : '')
                    + '<div class="actions"><button class="btn" data-prev ' + (page === 1 ? 'disabled' : '') + '>Previous</button><span>Page ' + page + '</span><button class="btn" data-next ' + (!data.has_next ? 'disabled' : '') + '>Next</button><button class="btn" data-refresh>Refresh</button></div>';
                const related = host.querySelector('[data-related]');
                const addRelated = (text, next, own = self) => { const button = document.createElement('button'); button.className = 'btn'; button.textContent = text; button.onclick = () => this.render(host, next, own); related.appendChild(button); };
                if (self) addRelated('Back to my workspace', 'self-service');
                if (kind === 'payroll' && form.manage) addRelated('Payroll configuration', 'payroll-rules', false);
                if (kind === 'payroll-rules') addRelated('Back to payroll', 'payroll', false);
                if (kind === 'documents' && form.manage) addRelated('Onboarding & offboarding', 'checklists', false);
                if (kind === 'checklists') addRelated('Back to documents', 'documents', false);
                if (!self && kind === 'attendance' && form.manage) addRelated('Expense review', 'expenses', false);
                if (kind === 'documents') { ['all', 'due', 'expired'].forEach(filter => { const button = document.createElement('button'); button.className = 'btn'; button.textContent = filter === 'all' ? 'All documents' : filter === 'due' ? 'Due within 30 days' : 'Expired'; button.onclick = () => this.render(host, kind, self, 1, filter); related.appendChild(button); }); }
                const reload = () => this.render(host, kind, self, page, expiry);
                host.querySelector('[data-create]')?.addEventListener('click', () => this.create(kind, form, reload));
                host.querySelectorAll('[data-view]').forEach(button => { button.onclick = () => this.view(data.rows.find(row => row.id === Number(button.dataset.view)), reload); });
                host.querySelector('[data-prev]').onclick = () => this.render(host, kind, self, page - 1, expiry);
                host.querySelector('[data-next]').onclick = () => this.render(host, kind, self, page + 1, expiry);
                host.querySelector('[data-refresh]').onclick = reload;
            } catch (error) { host.innerHTML = '<p class="notice" role="alert">' + esc(error.message) + '</p>'; }
        }
        modal(title) {
            const origin = document.activeElement, app = document.querySelector('.app');
            const host = document.createElement('div'); host.className = 'modal-backdrop';
            host.innerHTML = '<section class="modal hr-modal" role="dialog" aria-modal="true" aria-labelledby="workflowTitle"><div class="heading"><h2 id="workflowTitle">' + esc(title) + '</h2><button class="btn" data-close>Close</button></div><div class="hr-content"></div></section>';
            document.body.appendChild(host); app.inert = true; document.body.classList.add('modal-open');
            const state = { busy: false, host, content: host.querySelector('.hr-content'), close: () => { if (state.busy) return; host.remove(); app.inert = false; document.body.classList.remove('modal-open'); document.removeEventListener('keydown', keys); if (origin?.isConnected) origin.focus(); } };
            const keys = event => { if (event.key === 'Escape') { event.preventDefault(); state.close(); } if (event.key === 'Tab') { const nodes = [...host.querySelectorAll('button,input,select,textarea,a[href]')].filter(n => !n.disabled && n.getClientRects().length); if (!nodes.length) return; if (event.shiftKey && document.activeElement === nodes[0]) { event.preventDefault(); nodes.at(-1).focus(); } else if (!event.shiftKey && document.activeElement === nodes.at(-1)) { event.preventDefault(); nodes[0].focus(); } } };
            document.addEventListener('keydown', keys); host.querySelector('[data-close]').onclick = state.close; host.querySelector('[data-close]').focus(); return state;
        }
        componentRows(host, initial, manual = false) {
            const draw = rows => {
                host.innerHTML = '<div data-lines>' + rows.map((row, index) => '<fieldset class="hr-section" data-component><legend>Component ' + (index + 1) + '</legend><label class="field">Title<input class="input" data-label value="' + esc(row.label) + '" required maxlength="100" ' + (manual ? 'readonly' : '') + '></label>' + (manual ? '<input type="hidden" data-category value="' + esc(row.category) + '"><input type="hidden" data-method value="manual">' : '<div class="formgrid"><label class="field">Category<select class="input" data-category>' + ['earning', 'deduction', 'employer'].map(value => '<option ' + (value === row.category ? 'selected' : '') + '>' + value + '</option>').join('') + '</select></label><label class="field">Calculation<select class="input" data-method>' + ['manual', 'fixed', 'percent_base'].map(value => '<option ' + (value === row.method ? 'selected' : '') + '>' + value + '</option>').join('') + '</select></label></div>') + '<label class="field">' + (manual ? 'Verified amount' : 'Amount / percentage (manual starts at zero)') + '<input class="input" type="number" min="0" max="1000000" step="0.01" data-value required value="' + esc(row.value || '0.00') + '"></label>' + (!manual ? '<button type="button" class="btn" data-remove>Remove component</button>' : '') + '</fieldset>').join('') + '</div>' + (!manual ? '<button type="button" class="btn" data-add>Add component</button>' : '');
                host.querySelectorAll('[data-remove]').forEach((button, index) => { button.onclick = () => { const values = collect(); values.splice(index, 1); draw(values); }; });
                host.querySelector('[data-add]')?.addEventListener('click', () => { const values = collect(); if (values.length < 50) draw([...values, { label: '', category: 'earning', method: 'fixed', value: '0.00' }]); });
            };
            const collect = () => [...host.querySelectorAll('[data-component]')].map(row => ({ label: row.querySelector('[data-label]').value, category: row.querySelector('[data-category]').value, method: row.querySelector('[data-method]').value, value: row.querySelector('[data-value]').value }));
            draw(initial); return collect;
        }
        create(kind, schema, reload) {
            const modal = this.modal('New ' + (titles[kind] || kind));
            const defaults = { ...schema.defaults, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || schema.defaults.timezone };
            modal.content.innerHTML = '<form><div class="formgrid">' + schema.fields.map(field => (field.type === 'components' ? '<div class="field">' : '<label class="field">') + esc(field.label) + (field.required ? ' *' : '') + (field.type === 'components' ? '<div data-components></div>' : field.type === 'checklist' ? '<textarea class="input" name="' + field.key + '" rows="6" placeholder="One item per line" required></textarea>' : field.type === 'select' ? '<select class="input" name="' + field.key + '" ' + (field.required ? 'required' : '') + '></select>' : field.type === 'checkbox' ? '<input type="checkbox" name="' + field.key + '" required>' : '<input class="input" name="' + field.key + '" type="' + field.type + '" ' + (field.required ? 'required' : '') + (field.type === 'number' ? ' min="0" max="1000000" step="0.0001"' : ' maxlength="1000"') + ' value="' + esc(defaults[field.key] ?? '') + '">') + (field.type === 'components' ? '</div>' : '</label>')).join('') + '</div>'
                + (['documents', 'expenses'].includes(kind) ? '<label class="field">' + (kind === 'expenses' ? 'Receipt' : 'Document') + ' *<input class="input" name="attachment" type="file" accept=".pdf,.png,.jpg,.jpeg,.docx" required><span class="muted">Private attachment, up to 10 MB.</span></label>' : '')
                + (['payroll', 'payroll-rules'].includes(kind) ? '<p class="notice">Malaysia defaults include configurable EPF, SOCSO, EIS, PCB and employer contributions. Manual components require verified employee-specific amounts. Fixed and percent_base rules are your company calculations, not automatic statutory schedules.</p><p><a href="https://www.kwsp.gov.my/en/epf-act-1991-third-schedule" target="_blank" rel="noopener">EPF schedules</a> · <a href="https://www.perkeso.gov.my/en/our-services/employer-employee/contribution-calculator.html" target="_blank" rel="noopener">PERKESO calculator</a> · <a href="https://www.hasil.gov.my/majikan/jadual-pcb-dan-spesifikasi-data/" target="_blank" rel="noopener">HASiL PCB specification</a></p>' : '')
                + '<p class="notice hidden" data-error role="alert"></p><div class="modal-actions"><button class="btn" type="button" data-cancel>Cancel</button><button class="btn primary" type="submit">Save ' + (kind === 'payroll' ? 'draft' : 'record') + '</button></div></form>';
            const form = modal.content.querySelector('form');
            let components = () => [];
            if (modal.content.querySelector('[data-components]')) components = this.componentRows(modal.content.querySelector('[data-components]'), defaults.components || [], kind === 'payroll');
            const options = key => {
                const selected = form.elements[key].value || defaults[key];
                const rows = (schema.lookups[key] || []).filter(row => key === 'company_id' || !row.company_id || String(row.company_id) === form.elements.company_id.value);
                form.elements[key].innerHTML = '<option value="">Choose…</option>' + rows.map(row => '<option value="' + row.id + '" ' + (String(row.id) === String(selected) ? 'selected' : '') + '>' + esc(row.name) + '</option>').join('');
                if (rows.length === 1 && !form.elements[key].value) form.elements[key].value = rows[0].id;
            };
            options('company_id'); schema.fields.filter(field => field.type === 'select' && field.key !== 'company_id').forEach(field => options(field.key));
            const ruleChanged = () => { if (kind !== 'payroll') return; const rule = schema.lookups.rules_id.find(row => String(row.id) === form.elements.rules_id.value); components = this.componentRows(modal.content.querySelector('[data-components]'), (rule?.components || []).filter(row => row.method === 'manual'), true); };
            form.elements.company_id.onchange = () => { schema.fields.filter(field => field.type === 'select' && field.key !== 'company_id').forEach(field => { form.elements[field.key].value = ''; options(field.key); }); ruleChanged(); };
            if (form.elements.rules_id) { form.elements.rules_id.onchange = ruleChanged; ruleChanged(); }
            modal.content.querySelector('[data-cancel]').onclick = modal.close;
            let attempt = null;
            form.onsubmit = async event => {
                event.preventDefault(); if (modal.busy || !form.reportValidity()) return;
                const values = Object.fromEntries(schema.fields.map(field => [field.key, field.type === 'components' ? components() : field.type === 'checklist' ? form.elements[field.key].value.split('\n').map(v => v.trim()).filter(Boolean) : field.type === 'checkbox' ? form.elements[field.key].checked : field.type === 'select' ? Number(form.elements[field.key].value) : form.elements[field.key].value || null]));
                const file = form.elements.attachment?.files[0];
                const signature = JSON.stringify([values, file?.name, file?.size, file?.lastModified]);
                if (!attempt || attempt.signature !== signature) attempt = { signature, key: crypto.randomUUID() };
                modal.busy = true; form.inert = true;
                try {
                    if (file) {
                        const body = new FormData(); body.append('request_id', attempt.key);
                        for (const [key, value] of Object.entries(values)) if (value !== null) body.append('values[' + key + ']', String(value));
                        body.append('attachment', file);
                        const response = await this.api.transport(this.api.base + '/hr-workflows/' + kind, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.api.csrf }, body });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) throw Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Unable to upload. Retry unchanged values.');
                    } else await this.api.request('hr-workflows/' + kind, 'POST', { request_id: attempt.key, values });
                    modal.busy = false; modal.close(); await reload();
                } catch (error) { const notice = modal.content.querySelector('[data-error]'); notice.textContent = error.message; notice.classList.remove('hidden'); }
                finally { modal.busy = false; form.inert = false; }
            };
        }
        view(row, reload) {
            const modal = this.modal(titles[row.kind] + ' #' + row.id);
            const hidden = ['company_id', 'employee_id', 'project_id', 'rules_id', 'components', 'rules_snapshot', 'file_sha256', 'precision', 'completed', 'lines'];
            const format = (key, value) => key.endsWith('_minor') ? (value / 10 ** row.values.precision).toFixed(row.values.precision) + ' ' + row.values.currency : typeof value === 'boolean' ? (value ? 'Yes' : 'No') : Array.isArray(value) ? value.map(esc).join(', ') : esc(value);
            modal.content.innerHTML = '<p class="notice">' + esc(row.employee || row.values.title || '') + ' · ' + esc(label(row.state)) + (row.expiry_status ? ' · ' + esc(row.expiry_status) : '') + '</p><dl class="hr-detail">' + Object.entries(row.values).filter(([key, value]) => !hidden.includes(key) && key !== 'items' && value !== null).map(([key, value]) => '<dt>' + esc(label(key)) + '</dt><dd>' + format(key, value) + '</dd>').join('') + '</dl>'
                + (row.values.lines ? '<table><thead><tr><th>Component</th><th>Category</th><th>Amount</th></tr></thead><tbody>' + row.values.lines.map(line => '<tr><td>' + esc(line.label) + '</td><td>' + esc(line.category) + '</td><td>' + format('amount_minor', line.amount_minor) + '</td></tr>').join('') + '</tbody></table>' : '')
                + (row.kind === 'payroll-rules' ? '<ul>' + row.values.components.map(component => '<li>' + esc(component.label + ' · ' + component.category + ' · ' + component.method + ' · ' + component.value) + '</li>').join('') + '</ul>' : '')
                + (row.kind === 'checklists' ? '<ol>' + row.values.items.map((item, index) => '<li>' + (row.values.completed.includes(index) ? '✓ ' : '○ ') + esc(item) + '</li>').join('') + '</ol><label class="field">Checklist item<select class="input" data-item>' + row.values.items.map((item, index) => '<option value="' + index + '">' + esc(item) + '</option>').join('') + '</select></label>' : '')
                + (row.has_file ? '<p><a class="btn" href="' + esc(this.api.base + '/hr-workflows/' + row.kind + '/' + row.id + '/download') + '">Download private attachment</a></p>' : '')
                + (row.kind === 'payroll' && row.state === 'approved' ? '<p><a class="btn" target="_blank" rel="noopener" href="' + esc(this.api.base + '/hr-workflows/payslip/' + row.id) + '">Open / print payslip</a></p>' : '')
                + (row.actions.length ? '<label class="field">Reason for action<textarea class="input" maxlength="1000" data-reason></textarea></label>' : '')
                + '<p class="notice hidden" data-error role="alert"></p><div class="modal-actions"><button class="btn" data-done>Close</button>' + row.actions.map(action => '<button class="btn primary" data-action="' + action + '">' + esc(label(action)) + '</button>').join('') + '</div>';
            modal.content.querySelector('[data-done]').onclick = modal.close;
            modal.content.querySelectorAll('[data-action]').forEach(button => { button.onclick = async () => {
                if (modal.busy) return;
                const reason = modal.content.querySelector('[data-reason]').value.trim(), notice = modal.content.querySelector('[data-error]');
                if (!reason) { notice.textContent = 'Enter a reason for this action.'; notice.classList.remove('hidden'); return; }
                modal.busy = true; modal.content.inert = true;
                try { await this.api.request('hr-workflows/' + row.kind + '/' + row.id + '/action', 'POST', { action: button.dataset.action, reason, revision: row.revision, ...(row.kind === 'checklists' ? { item: Number(modal.content.querySelector('[data-item]').value) } : {}) }); modal.busy = false; modal.close(); await reload(); }
                catch (error) { notice.textContent = error.message; notice.classList.remove('hidden'); }
                finally { modal.busy = false; modal.content.inert = false; }
            }; });
        }
        async selfService(host) {
            try {
                const data = await this.api.request('hr-workflows/self-service');
                host.innerHTML = '<h2>My employee workspace</h2><p class="muted">Only your linked employee records are shown here.</p><dl class="hr-detail">' + Object.entries(data.profile).filter(([, value]) => value).map(([key, value]) => '<dt>' + esc(label(key)) + '</dt><dd>' + esc(value) + '</dd>').join('') + '</dl><button class="btn" data-profile>Edit my contact details</button><div class="actions">' + ['attendance', 'documents', 'expenses', 'payroll'].map(kind => '<button class="btn" data-own="' + kind + '">My ' + esc(titles[kind]) + '</button>').join('') + '</div><h3>My leave balances</h3><p class="muted">Approved allocations valid today, less reserved and approved requests within their coverage. Balances are conservative; accrual adjustments are maintained by HR.</p>' + (!data.leave_enabled ? '<p>Time-off module is not available.</p>' : '<div class="tablewrap"><table><thead><tr><th>Leave type</th><th>Approved allocation</th><th>Reserved / approved</th><th>Remaining</th></tr></thead><tbody>' + data.balances.map(row => '<tr><td>' + esc(row.name) + '</td><td>' + esc(row.requires_allocation ? row.approved_allocation : 'Allocation not required') + '</td><td>' + esc(row.reserved_including_approved) + '</td><td>' + esc(row.requires_allocation ? row.remaining : 'Not capped by allocation') + '</td></tr>').join('') + '</tbody></table></div><button class="btn primary" data-leave>Request leave</button><h3>My latest leave requests</h3><ul>' + data.requests.map(row => '<li>' + esc(row.type + ' · ' + row.from + ' – ' + row.to + ' · ' + row.days + ' days · ' + row.state) + '</li>').join('') + '</ul>');
                host.querySelector('[data-profile]').onclick = () => this.profile(data, () => this.selfService(host));
                host.querySelectorAll('[data-own]').forEach(button => { button.onclick = () => this.render(host, button.dataset.own, true); });
                host.querySelector('[data-leave]')?.addEventListener('click', () => this.selfLeave(data, () => this.selfService(host)));
            } catch (error) { host.innerHTML = '<p class="notice" role="alert">' + esc(error.message) + '</p>'; }
        }
        profile(data, reload) {
            const modal = this.modal('My contact details');
            const keys = ['private_email', 'private_phone', 'emergency_contact', 'emergency_phone'];
            modal.content.innerHTML = '<form>' + keys.map(key => '<label class="field">' + esc(label(key)) + '<input class="input" name="' + key + '" type="' + (key.includes('email') ? 'email' : 'text') + '" maxlength="255" value="' + esc(data.profile[key]) + '"></label>').join('') + '<p class="notice hidden" role="alert" data-error></p><div class="modal-actions"><button type="button" class="btn" data-cancel>Cancel</button><button type="submit" class="btn primary">Save contacts</button></div></form>';
            const form = modal.content.querySelector('form'); modal.content.querySelector('[data-cancel]').onclick = modal.close;
            form.onsubmit = async event => { event.preventDefault(); if (modal.busy || !form.reportValidity()) return; modal.busy = true; form.inert = true; try { await this.api.request('hr-workflows/self-service/profile', 'PUT', { revision: data.revision, values: Object.fromEntries(keys.map(key => [key, form.elements[key].value || null])) }); modal.busy = false; modal.close(); await reload(); } catch (error) { const notice = modal.content.querySelector('[data-error]'); notice.textContent = error.message; notice.classList.remove('hidden'); } finally { modal.busy = false; form.inert = false; } };
        }
        selfLeave(data, reload) {
            const modal = this.modal('My leave request');
            modal.content.innerHTML = '<form><label class="field">Leave type<select class="input" name="holiday_status_id" required>' + data.leave_types.map(type => '<option value="' + type.id + '">' + esc(type.name) + '</option>').join('') + '</select></label><div class="formgrid"><label class="field">From<input class="input" name="request_date_from" type="date" required></label><label class="field">To<input class="input" name="request_date_to" type="date" required></label></div><label class="field">Half day<input name="request_unit_half" type="checkbox"></label><label class="field">Period<select class="input" name="request_date_from_period"><option value="morning">Morning</option><option value="afternoon">Afternoon</option></select></label><label class="field">Description<input class="input" name="private_name" maxlength="255"></label><p class="notice hidden" data-error role="alert"></p><div class="modal-actions"><button class="btn" type="button" data-cancel>Cancel</button><button class="btn primary" type="submit">Submit leave request</button></div></form>';
            const form = modal.content.querySelector('form'); modal.content.querySelector('[data-cancel]').onclick = modal.close; let attempt;
            form.onsubmit = async event => { event.preventDefault(); if (modal.busy || !form.reportValidity()) return; const values = Object.fromEntries(new FormData(form)); values.holiday_status_id = Number(values.holiday_status_id); values.request_unit_half = form.elements.request_unit_half.checked; const signature = JSON.stringify(values); if (!attempt || attempt.signature !== signature) attempt = { signature, key: crypto.randomUUID() }; modal.busy = true; form.inert = true; try { await this.api.request('hr-workflows/self-service/leave', 'POST', { values, request_id: attempt.key }); modal.busy = false; modal.close(); await reload(); } catch (error) { const notice = modal.content.querySelector('[data-error]'); notice.textContent = error.message; notice.classList.remove('hidden'); } finally { modal.busy = false; form.inert = false; } };
        }
    }
    root.NorthwestHrWorkflows = HrWorkflows;
})(globalThis);
