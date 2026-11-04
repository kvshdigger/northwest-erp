(function (root) {
    'use strict';
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
    class NativeHrEditor {
        constructor(api, onSaved) { this.api = api; this.onSaved = onSaved; this.dialog = null; }
        async open(kind, id = null, edit = false) {
            if (this.dialog) return;
            const origin = document.activeElement;
            const host = document.createElement('div');
            host.className = 'modal-backdrop';
            host.innerHTML = '<div class="modal hr-modal" role="dialog" aria-modal="true" aria-labelledby="hrTitle"><div class="heading"><h2 id="hrTitle">Loading profile…</h2><button class="btn" data-close>Close</button></div><div class="hr-content" role="status">Loading…</div></div>';
            document.body.appendChild(host);
            this.dialog = host;
            const app = document.querySelector('.app');
            app.inert = true;
            document.body.classList.add('modal-open');
            let busy = false;
            const close = () => {
                if (busy) return;
                document.removeEventListener('keydown', keyboard);
                host.remove(); this.dialog = null;
                app.inert = false; document.body.classList.remove('modal-open');
                if (origin?.isConnected) origin.focus();
            };
            const keyboard = event => {
                if (event.key === 'Escape') { event.preventDefault(); close(); }
                if (event.key !== 'Tab') return;
                const controls = [...host.querySelectorAll('input, select, button, textarea, [tabindex="0"]')].filter(node => !node.disabled && node.getClientRects().length);
                const first = controls[0], last = controls.at(-1);
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
            };
            document.addEventListener('keydown', keyboard);
            host.querySelector('[data-close]').onclick = close;
            host.querySelector('[data-close]').focus();
            try {
                const [form, record] = await Promise.all([this.api.request('hr/' + kind + '/form'), id ? this.api.request('hr/' + kind + '/' + id) : Promise.resolve(null)]);
                if (this.dialog !== host) return;
                let editing = !id || edit;
                if (editing && (record ? !record.can_edit : !form.can_create)) throw Error('You do not have permission to edit this profile.');
                let revision = record?.revision;
                let values = record?.values || form.defaults;
                let attempt = null;
                const content = host.querySelector('.hr-content');
                content.removeAttribute('role');
                const render = () => {
                    host.querySelector('#hrTitle').textContent = (id ? editing ? 'Edit ' : 'View ' : 'New ') + (kind === 'employees' ? 'employee profile' : kind === 'leave' ? 'leave request' : 'department');
                    const groups = [...new Set(form.fields.map(field => field.group))];
                    content.innerHTML = (kind === 'leave' ? '<p class="notice">' + escape(record ? record.status + ' · ' + record.duration : 'Duration is calculated by the ERP on save. Weekends are excluded. Hourly leave, required documents and public-holiday duration options need the existing ERP workflow.') + '</p>' : '') + '<form class="hr-form"><div class="hr-summary muted">' + (kind === 'leave' ? 'Employee leave information. Decisions follow the configured approval stages.' : editing ? 'Changes are saved to the company employee records.' : 'Company employee information. Personal fields are available only through your existing HR permissions.') + '</div>'
                        + groups.map(group => '<fieldset class="hr-section"><legend>' + escape(group) + '</legend><div class="formgrid">' + form.fields.filter(field => field.group === group).map(field => {
                            const value = values[field.key] ?? '';
                            let control;
                            if (field.type === 'select') {
                                let options = form.lookups[field.options] || [];
                                if (field.key !== 'company_id') options = options.filter(option => (!option.company_id || String(option.company_id) === String(values.company_id)) && !(id && String(option.id) === String(id) && (field.options === 'employees' && kind === 'employees' || field.key === 'parent_id' && kind === 'departments')));
                                if (value !== '' && !options.some(option => String(option.id) === String(value))) options = [...options, { id: value, name: 'Unavailable selection (' + value + ')', disabled: true }];
                                control = '<select class="input" name="' + field.key + '" ' + (!editing || id && (field.key === 'company_id' || kind === 'leave' && field.key === 'employee_id') ? 'disabled' : '') + (field.required ? ' required' : '') + '>' + (!field.required ? '<option value="">None</option>' : '') + options.map(option => '<option value="' + escape(option.id) + '" ' + (String(option.id) === String(value) ? 'selected' : '') + (option.disabled ? ' disabled' : '') + '>' + escape(option.name) + '</option>').join('') + '</select>';
                            } else if (field.type === 'checkbox') control = '<input type="checkbox" name="' + field.key + '" ' + (value ? 'checked' : '') + (!editing ? ' disabled' : '') + '>';
                            else control = '<input class="input" name="' + field.key + '" type="' + field.type + '" maxlength="255" value="' + escape(value) + '" ' + (!editing ? 'readonly' : '') + (field.required ? ' required' : '') + '>';
                            return '<label class="field ' + (field.type === 'checkbox' ? 'hr-check' : '') + '">' + escape(field.label) + (field.required ? ' *' : '') + control + '</label>';
                        }).join('') + '</div></fieldset>').join('')
                        + (!editing && record?.actions?.length ? '<fieldset class="hr-section"><legend>Leave decision</legend><label class="field">Decision reason<textarea class="input" data-reason maxlength="1000"></textarea></label><div class="actions">' + record.actions.map(action => '<button type="button" class="btn" data-decision="' + action + '">' + (action === 'approve' ? 'Approve current stage' : 'Refuse request') + '</button>').join('') + '</div></fieldset>' : '')
                        + '<div class="notice hidden" data-error role="alert"></div><div class="modal-actions"><button class="btn" type="button" data-cancel>' + (editing ? 'Cancel' : 'Close') + '</button>' + (editing ? '<button class="btn primary" type="submit">Save ' + (kind === 'employees' ? 'employee' : kind === 'leave' ? 'leave request' : 'department') + '</button>' : record.can_edit ? '<button class="btn primary" type="button" data-edit>' + (kind === 'leave' ? 'Edit request' : 'Edit profile') + '</button>' : '') + '</div></form>';
                    const element = content.querySelector('form');
                    const collect = () => Object.fromEntries(form.fields.map(field => {
                        const input = element.elements[field.key];
                        return [field.key, field.type === 'checkbox' ? input.checked : field.type === 'select' && field.key.endsWith('_id') ? input.value ? Number(input.value) : null : input.value || null];
                    }));
                    content.querySelector('[data-cancel]').onclick = close;
                    content.querySelectorAll('[data-decision]').forEach(button => button.onclick = async () => {
                        if (busy) return;
                        const reason = content.querySelector('[data-reason]').value.trim();
                        const notice = content.querySelector('[data-error]');
                        if (!reason) { notice.textContent = 'Enter a reason before deciding.'; notice.classList.remove('hidden'); return; }
                        busy = true; element.inert = true;
                        try {
                            await this.api.request('hr/leave/' + id + '/decision', 'POST', { action: button.dataset.decision, revision, reason });
                            busy = false; close(); await this.onSaved(kind);
                        } catch (error) { notice.textContent = error.message; notice.classList.remove('hidden'); }
                        finally { busy = false; element.inert = false; }
                    });
                    content.querySelector('[data-edit]')?.addEventListener('click', () => { editing = true; render(); });
                    element.elements.company_id.onchange = () => {
                        values = collect();
                        for (const field of form.fields) if (field.options && !['companies', 'genders', 'marital'].includes(field.options)) values[field.key] = null;
                        render();
                    };
                    element.onsubmit = async event => {
                        event.preventDefault();
                        if (!editing || busy || !element.reportValidity()) return;
                        const updated = collect();
                        const signature = JSON.stringify(updated);
                        if (!attempt || attempt.signature !== signature) attempt = { signature, key: crypto.randomUUID() };
                        const buttons = [...host.querySelectorAll('button')];
                        const states = buttons.map(button => button.disabled);
                        busy = true; buttons.forEach(button => { button.disabled = true; });
                        element.inert = true;
                        try {
                            await this.api.request('hr/' + kind + (id ? '/' + id : ''), id ? 'PUT' : 'POST', { values: updated, ...(id ? { revision } : { request_id: attempt.key }) });
                            busy = false;
                            close();
                            await this.onSaved(kind);
                        } catch (error) {
                            const notice = content.querySelector('[data-error]');
                            notice.textContent = error.message;
                            notice.classList.remove('hidden');
                        } finally {
                            busy = false;
                            element.inert = false;
                            buttons.forEach((button, index) => { button.disabled = states[index]; });
                        }
                    };
                };
                render();
            } catch (error) {
                if (this.dialog === host) host.querySelector('.hr-content').innerHTML = '<p class="notice" role="alert">' + escape(error.message) + '</p>';
            }
        }
    }
    root.NorthwestNativeHr = NativeHrEditor;
})(globalThis);
