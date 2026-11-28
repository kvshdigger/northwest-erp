(function (root) {
    'use strict';
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
    class NativeFinance {
        constructor(api) { this.api = api; this.modals = new NorthwestHrWorkflows(api); }
        async render(host, kind, page = 1, search = '') {
            if (kind === 'configuration' || kind === 'company-profile') return this.configuration(host, null, kind === 'company-profile');
            host.innerHTML = '<p role="status">Loading finance records…</p>';
            try {
                const [schema, data] = await Promise.all([this.api.request('finance/' + kind + '/form'), this.api.request('modules/finance/' + kind + '?page=' + page + '&search=' + encodeURIComponent(search))]);
                if (!host.isConnected) return;
                const reload = () => this.render(host, kind, page, search);
                host.innerHTML = '<div class="heading"><div><h2>' + esc(schema.title) + 's</h2><p class="muted">Create and manage records inside Northwest ERP V2. Posted documents remain read-only.</p></div>' + (schema.can_create ? '<button class="btn primary" data-create>Create ' + esc(schema.title.toLowerCase()) + '</button>' : '') + '</div><form class="module-search"><label class="field">Search references or items<input class="input" name="search" maxlength="120" value="' + esc(search) + '"></label><button class="btn">Search</button><button class="btn" type="button" data-refresh>Refresh</button></form><div class="tablewrap"><table><thead><tr>' + data.columns.map(c => '<th>' + esc(c) + '</th>').join('') + '<th>Record</th></tr></thead><tbody>' + data.rows.map(row => '<tr>' + row.cells.map(c => '<td>' + esc(c) + '</td>').join('') + '<td><button class="link" data-view="' + row.id + '">View</button></td></tr>').join('') + '</tbody></table></div>' + (!data.rows.length ? '<p class="muted">No matching records.</p>' : '') + '<div class="module-pagination"><span>Page ' + data.page + '</span><button class="btn" data-prev ' + (page <= 1 ? 'disabled' : '') + '>Previous</button><button class="btn" data-next ' + (!data.has_next ? 'disabled' : '') + '>Next</button></div>';
                host.querySelector('[data-create]')?.addEventListener('click', () => this.open(kind, null, reload));
                host.querySelectorAll('[data-view]').forEach(b => b.onclick = () => this.open(kind, Number(b.dataset.view), reload));
                host.querySelector('form').onsubmit = e => { e.preventDefault(); this.render(host, kind, 1, host.querySelector('[name=search]').value.trim()); };
                host.querySelector('[data-refresh]').onclick = reload;
                host.querySelector('[data-prev]').onclick = () => this.render(host, kind, page - 1, search);
                host.querySelector('[data-next]').onclick = () => this.render(host, kind, page + 1, search);
            } catch (error) { host.innerHTML = '<p class="notice" role="alert">' + esc(error.message) + '</p>'; }
        }
        select(key, label, options, selected, required = true) {
            return '<label class="field">' + esc(label) + (required ? ' *' : '') + '<select class="input" name="' + key + '" ' + (required ? 'required' : '') + '><option value="">Choose…</option>' + options.map(o => '<option value="' + esc(o.id) + '" ' + (String(o.id) === String(selected) ? 'selected' : '') + '>' + esc(o.name) + '</option>').join('') + '</select></label>';
        }
        input(key, label, value, type = 'text', required = false) {
            return '<label class="field">' + esc(label) + (required ? ' *' : '') + '<input class="input" name="' + key + '" type="' + type + '" value="' + esc(value) + '" ' + (required ? 'required' : '') + (type === 'number' ? ' min="0" step="0.01"' : '') + '></label>';
        }
        page() {
            const main = document.querySelector('main');
            const previous = [...main.children].map(node => [node, node.hidden]);
            previous.forEach(([node]) => { node.hidden = true; });
            const host = document.createElement('section'); host.className = 'po-editor-page';
            host.innerHTML = '<div class="heading"><h2>New Purchase order</h2><button class="btn" data-close>Back to purchase orders</button></div><div class="hr-content"></div>';
            main.appendChild(host);
            const state = {host, content:host.querySelector('.hr-content'), busy:false, close:() => { if (state.busy) return; host.remove(); previous.forEach(([node, hidden]) => { node.hidden = hidden; }); }};
            host.querySelector('[data-close]').onclick = state.close;
            host.scrollIntoView(); return state;
        }
        async open(kind, id, reload, editing = !id, companyId = null) {
            const modal = kind === 'purchase-orders' && editing ? this.page() : this.modals.modal('Loading finance document…');
            try {
                const row = id ? await this.api.request('finance/' + kind + '/' + id) : null;
                const schema = await this.api.request('finance/' + kind + '/form' + ((companyId || row?.values.company_id) ? '?company_id=' + (companyId || row.values.company_id) : '') + (kind === 'purchase-orders' && id ? '&order_id=' + id : ''));
                const title = schema.title, values = row ? row.values : schema.defaults;
                modal.host.querySelector('h2').textContent = (row ? row.name : 'New ' + title);
                if (!editing) {
                    modal.content.innerHTML = (row.work_order ? '<p class="notice">Source Work Order: ' + esc(row.work_order.reference) + ' · version ' + esc(row.work_order.versions.join(', ')) + '</p>' : '') + '<p class="scope-badge">' + esc(row.state) + '</p><dl class="hr-detail"><dt>Company</dt><dd>' + esc(schema.lookups.companies.find(c => c.id == values.company_id)?.name) + '</dd><dt>Date</dt><dd>' + esc(values.date) + '</dd><dt>Reference</dt><dd>' + esc(values.reference || values.reference === 0 ? values.reference : '—') + '</dd>' + (kind === 'items' ? '<dt>Item</dt><dd>' + esc(values.name) + '</dd>' : '<dt>' + (kind === 'purchase-orders' || kind === 'bills' ? 'Vendor' : 'Customer') + '</dt><dd>' + esc(schema.lookups.partners.find(p => p.id == values.partner_id)?.name) + '</dd><dt>Total</dt><dd>' + esc(row.total + ' ' + row.currency) + '</dd>') + '</dl>' + (values.lines ? '<div class="tablewrap"><table><thead><tr><th>Item / description</th><th>Quantity</th><th>Unit price</th></tr></thead><tbody>' + values.lines.map(l => '<tr><td>' + esc(l.description) + '</td><td>' + esc(l.quantity) + '</td><td>' + esc(l.unit_price) + '</td></tr>').join('') + '</tbody></table></div>' : '') + '<p>' + esc(values.terms) + '</p><div class="actions">' + (row.can_edit ? '<button class="btn" data-edit>Edit ' + esc(title.toLowerCase()) + '</button>' : '') + (row.can_confirm ? '<button class="btn primary" data-confirm>' + (kind === 'purchase-orders' ? 'Review & confirm PO' : kind === 'payments' ? 'Review & record payment' : 'Review & post') + '</button>' : '') + (row.can_cancel ? '<button class="btn" data-cancel-po>Cancel unconfirmed PO</button>' : '') + (row.can_print ? '<a class="btn" target="_blank" rel="noopener" href="' + esc(this.api.base + '/finance/' + kind + '/' + id + '/print') + '">Open / print document</a>' : '') + '</div><div data-decision></div>';
                    modal.content.querySelector('[data-edit]')?.addEventListener('click', () => { modal.close(); this.open(kind, id, reload, true); });
                    modal.content.querySelector('[data-cancel-po]')?.addEventListener('click', () => {
                        const decision = modal.content.querySelector('[data-decision]');
                        decision.innerHTML = '<p class="notice">Cancel this unconfirmed PO and release its Work Order quantity reservation.</p><button class="btn" data-final-cancel>Confirm cancellation</button><p data-error role="alert"></p>';
                        decision.querySelector('button').onclick = async () => { if (modal.busy) return; modal.busy = true; decision.querySelector('button').disabled = true; try { await this.api.request('finance/'+kind+'/'+id+'/cancel','POST',{revision:row.revision}); modal.busy=false; modal.close(); await reload(); this.open(kind,id,reload); } catch(e) { decision.querySelector('[data-error]').textContent=e.message; } finally { modal.busy=false; decision.querySelector('button').disabled=false; } };
                    });
                    modal.content.querySelector('[data-confirm]')?.addEventListener('click', () => {
                        const decision = modal.content.querySelector('[data-decision]');
                        decision.innerHTML = '<p class="notice">' + (kind === 'purchase-orders' ? 'Confirm this order using the existing purchasing approval and stock workflow.' : 'Post this record using the existing accounting engine. This records accounting entries; it does not transfer money or send email.') + '</p><button class="btn primary" data-final>Confirm ' + esc(title.toLowerCase()) + '</button><p data-error role="alert"></p>';
                        decision.querySelector('[data-final]').onclick = async () => { if (modal.busy) return; modal.busy = true; decision.querySelector('button').disabled = true; try { await this.api.request('finance/' + kind + '/' + id + '/confirm', 'POST', { revision: row.revision }); modal.busy = false; modal.close(); await reload(); this.open(kind, id, reload); } catch (e) { decision.querySelector('[data-error]').textContent = e.message; } finally { modal.busy = false; decision.querySelector('button').disabled = false; } };
                    });
                    return;
                }
                if (row && !row.can_edit || !row && !schema.can_create) throw Error('You do not have permission to edit this record.');
                const look = schema.lookups;
                let fields = this.select('company_id', 'Company', look.companies, values.company_id);
                if (kind === 'purchase-orders') fields += this.select('work_order_id', 'Source Work Order (leave blank for general purchasing)', look.work_orders || [], values.work_order_id, false);
                if (kind === 'items') {
                    fields += this.input('name', 'Item name', values.name, 'text', true) + this.input('reference', 'Item reference', values.reference) + this.select('type', 'Item type', [{id:'goods',name:'Goods'},{id:'service',name:'Service'}], values.type) + this.input('price', 'Sale price', values.price ?? 0, 'number', true) + this.input('cost', 'Purchase cost', values.cost ?? 0, 'number', true) + this.select('category_id', 'Category', look.categories, values.category_id) + this.select('uom_id', 'Unit of measure', look.uoms, values.uom_id) + this.select('uom_po_id', 'Purchase unit of measure', look.uoms, values.uom_po_id) + this.input('description', 'Description', values.description) + ['enable_sales','enable_purchase'].map(k => '<label><input type="checkbox" name="' + k + '" ' + (values[k] ? 'checked' : '') + '> ' + (k === 'enable_sales' ? 'Available for sales' : 'Available for purchasing') + '</label>').join('');
                } else {
                    fields += this.select('partner_id', kind === 'purchase-orders' ? 'Subcontractor / Company' : kind === 'bills' ? 'Vendor' : 'Customer', look.partners, values.partner_id) + this.select('currency_id', 'Currency', look.currencies, values.currency_id) + this.input('date', kind === 'purchase-orders' ? 'Order date' : 'Document date', values.date, 'date', true) + this.input('reference', 'External reference', values.reference);
                    if (kind !== 'purchase-orders') fields += this.select('journal_id', 'Journal', look.journals, values.journal_id || (look.journals.length === 1 ? look.journals[0].id : ''));
                    if (kind === 'payments') fields += this.input('amount', 'Amount received', values.amount, 'number', true) + this.select('payment_method_line_id', 'Payment method', look.methods, values.payment_method_line_id);
                    else fields += this.input('due_date', kind === 'purchase-orders' ? 'Expected arrival' : 'Due date', values.due_date, 'date') + '<label class="field">Terms and conditions<textarea class="input" name="terms" rows="3">' + esc(values.terms) + '</textarea></label>';
                }
                modal.content.innerHTML = '<form><div class="formgrid">' + fields + '</div>' + (kind !== 'items' && kind !== 'payments' ? '<h3>Items</h3>' + (!look.items.length ? '<p class="notice">Create an item in the Items section before adding it to this document.</p>' : '') + '<div data-lines></div><button class="btn" type="button" data-add>Add item</button><p class="muted">Totals and configured taxes are calculated by the ERP when the draft is saved.</p>' : '') + '<p class="notice hidden" data-error role="alert"></p><div class="modal-actions"><button class="btn" type="button" data-cancel>Cancel</button><button class="btn primary" type="submit">' + (kind === 'items' ? 'Save item' : kind === 'purchase-orders' && row?.state === 'purchase' ? 'Save confirmed PO' : 'Save draft') + '</button></div></form>';
                const form = modal.content.querySelector('form');
                form.elements.company_id.disabled = !!row;
                form.elements.company_id.onchange = () => { const company = Number(form.elements.company_id.value); if (!company) return; modal.close(); this.open(kind, null, reload, true, company); };
                modal.content.querySelector('[data-cancel]').onclick = modal.close;
                let lineHost = modal.content.querySelector('[data-lines]');
                const poColumns = [{key:'work_order_line_key',label:'Work Order item'},{key:'item_id',label:'Item',required:true},{key:'unit',label:'Unit'},{key:'description',label:'Description'},{key:'expected_arrival',label:'Expected Arrival'},{key:'quantity',label:'Quantity',required:true},{key:'unit_price',label:'Unit Price',required:true},{key:'tax_ids',label:'Taxes'},{key:'discount',label:'Discount (%)'},{key:'amount',label:'Amount',required:true}];
                let visibleColumns = new Set(poColumns.filter(c=>!['work_order_line_key','description','expected_arrival','discount'].includes(c.key)).map(c=>c.key));
                const applyColumns = () => { if(kind!=='purchase-orders')return; form.querySelectorAll('[data-po-column]').forEach(cell=>{cell.hidden=!visibleColumns.has(cell.dataset.poColumn);}); };
                if (kind === 'purchase-orders') {
                    lineHost.outerHTML = '<div class="po-table-tools"><button class="btn" type="button" data-columns>Columns</button></div><div class="tablewrap po-table"><table><thead><tr>'+poColumns.map(c=>'<th data-po-column="'+c.key+'">'+esc(c.label)+'</th>').join('')+'<th>Actions</th></tr></thead><tbody data-lines></tbody></table></div><p class="po-subtotal">Untaxed amount: <strong data-po-subtotal>0.00</strong></p>';
                    lineHost=form.querySelector('[data-lines]');
                    form.querySelector('[data-columns]').onclick=()=>{const picker=this.modals.modal('Columns');picker.content.innerHTML=poColumns.map(c=>'<label class="po-column-choice"><input type="checkbox" value="'+c.key+'" '+(visibleColumns.has(c.key)?'checked ':'')+(c.required?'disabled':'')+'> '+esc(c.label)+'</label>').join('')+'<div class="actions"><button class="btn" data-reset>Reset</button><button class="btn primary" data-apply>Apply columns</button></div>';picker.content.querySelector('[data-reset]').onclick=()=>picker.content.querySelectorAll('input').forEach(n=>{n.checked=!['work_order_line_key','description','expected_arrival','discount'].includes(n.value);});picker.content.querySelector('[data-apply]').onclick=()=>{visibleColumns=new Set([...picker.content.querySelectorAll('input:checked')].map(n=>n.value));applyColumns();picker.close();};};
                }
                const updateAmounts=()=>{if(kind!=='purchase-orders')return;let total=0;lineHost.querySelectorAll('.finance-line').forEach(row=>{const get=k=>Number(row.querySelector('[name='+k+']')?.value||0);const amount=get('quantity')*get('unit_price')*(1-get('discount')/100);row.querySelector('[data-amount]').textContent=amount.toFixed(2);total+=amount;});const currency=look.currencies.find(c=>c.id==form.elements.currency_id.value)?.name||'';form.querySelector('[data-po-subtotal]').textContent=currency+' '+total.toFixed(2);const totalOutput=form.querySelector('[data-po-total]');if(totalOutput)totalOutput.textContent=lineHost.querySelectorAll('[name=tax_ids] option:checked').length?'Calculated on save':currency+' '+total.toFixed(2);};
                const selectedWorkOrder = () => look.work_orders?.find(w => String(w.id) === form.elements.work_order_id?.value);
                const addLine = (line = {}) => {
                    const award = selectedWorkOrder();
                    if(kind==='purchase-orders')poColumns.find(c=>c.key==='work_order_line_key').required=!!award;
                    const node = document.createElement(kind === 'purchase-orders' ? 'tr' : 'fieldset'); node.className = 'finance-line';
                    node.innerHTML = '<legend>Item</legend><div class="formgrid">' + (award ? this.select('work_order_line_key', 'Work Order item / available quantity', award.lines.map(l => ({id:l.key,name:l.description+' · '+l.route_name+' · available '+l.available+' '+l.unit})), line.work_order_line_key) : '') + this.select('item_id', 'Item', look.items, line.item_id) + this.input('description', 'Description', line.description, 'text', true) + this.input('quantity', 'Quantity (item base unit)', line.quantity ?? 1, 'number', true) + this.input('unit_price', 'Unit price', line.unit_price ?? 0, 'number', true) + this.input('discount', 'Discount (%)', line.discount ?? 0, 'number') + (kind !== 'purchase-orders' ? this.select('account_id', 'Account', look.accounts, line.account_id) : '') + '<label class="field">Taxes<select class="input" name="tax_ids" multiple>' + look.taxes.map(t => '<option value="' + t.id + '" ' + ((line.tax_ids || []).includes(t.id) ? 'selected' : '') + '>' + esc(t.name) + '</option>').join('') + '</select></label></div><button class="btn" type="button" data-remove>Remove item</button>';
                    if (kind === 'purchase-orders') {
                        const controls = [...node.querySelectorAll('.field')];
                        const cells = poColumns.map(column => {
                            const cell=document.createElement('td'); cell.dataset.poColumn=column.key;
                            if(column.key==='amount')cell.innerHTML='<output data-amount>0.00</output>';
                            else if(column.key==='unit')cell.innerHTML='<output data-unit></output>';
                            else if(column.key==='expected_arrival')cell.innerHTML=this.input('expected_arrival','Expected Arrival',line.expected_arrival||values.due_date,'date');
                            else { const label=controls.find(label=>label.querySelector('[name="'+column.key+'"]')); if(label)cell.appendChild(label); }
                            return cell;
                        });
                        const action=document.createElement('td'); action.appendChild(node.querySelector('[data-remove]')); action.querySelector('button').textContent='Remove'; action.querySelector('button').setAttribute('aria-label','Remove Item row');
                        node.replaceChildren(...cells,action);
                        node.querySelector('[name=tax_ids]').size=1;
                    }
                    lineHost.appendChild(node); if(kind==='purchase-orders'){const item=look.items.find(i=>i.id==line.item_id);node.querySelector('[data-unit]').textContent=look.uoms.find(u=>u.id==item?.uom_id)?.name||'—';}
                    node.querySelector('[name=item_id]').onchange = e => { const item = look.items.find(i => i.id == e.target.value); if (!item) return; node.querySelector('[name=description]').value = item.name; if(kind==='purchase-orders')node.querySelector('[data-unit]').textContent=look.uoms.find(u=>u.id==item.uom_id)?.name||'—'; node.querySelector('[name=unit_price]').value = kind === 'purchase-orders' || kind === 'bills' ? (item.cost ?? 0) : (item.price ?? 0); };
                    if (award) {
                        const source = node.querySelector('[name=work_order_line_key]');
                        node.querySelector('[name=item_id]').disabled = true;
                        const choose = (fill) => { const l = award.lines.find(l => l.key === source.value); if (!l) return; node.querySelector('[name=item_id]').value = l.item_id; node.querySelector('[data-unit]').textContent=l.unit||'—'; node.querySelector('[name=quantity]').max = l.available; if (fill) { node.querySelector('[name=description]').value = l.description; node.querySelector('[name=unit_price]').value = Number(l.planned_subcon_rate).toFixed(2); } };
                        source.onchange = () => choose(true); choose(false); visibleColumns.add('work_order_line_key');
                    }
                    node.querySelector('[data-remove]').onclick = () => { node.remove(); updateAmounts(); };
                    node.addEventListener('input',updateAmounts); node.addEventListener('change',()=>queueMicrotask(updateAmounts)); applyColumns(); updateAmounts();
                };
                if (lineHost) { (values.lines?.length ? values.lines : [{}]).forEach(addLine); modal.content.querySelector('[data-add]').onclick = () => addLine(); }
                if (kind === 'purchase-orders') {
                    form.elements.work_order_id.disabled = !!values.work_order_id;
                    form.elements.work_order_id.onchange = () => { lineHost.replaceChildren(); const award = selectedWorkOrder(); if (award) form.elements.currency_id.value = award.currency_id; form.elements.currency_id.disabled = !!award; addLine(); };
                    if (selectedWorkOrder()) { form.elements.currency_id.value = selectedWorkOrder().currency_id; form.elements.currency_id.disabled = true; }
                    form.insertAdjacentHTML('afterbegin','<p class="notice">Subcon POs: select a Work Order to restrict items and quantities to its approved award. Drafts reserve available quantities. General purchasing is for costs outside a Work Order.</p>');
                }
                if (kind === 'purchase-orders') {
                    modal.host.classList.add('po-reference-page');
                    const getField = name => form.elements[name]?.closest('.field');
                    const general = document.createElement('section'); general.className='po-card';
                    general.innerHTML='<h3 class="po-card-title">General</h3><div class="po-general-grid"><div data-left></div><div data-right></div></div>';
                    for(const name of ['partner_id','reference','currency_id'])general.querySelector('[data-left]').appendChild(getField(name));
                    for(const name of ['date','due_date'])general.querySelector('[data-right]').appendChild(getField(name));
                    const details=document.createElement('section');details.className='po-card po-details';
                    details.innerHTML='<nav class="po-detail-tabs" aria-label="Purchase order details"><button class="btn" type="button" data-po-tab="items" aria-selected="true">Items</button><button class="btn" type="button" data-po-tab="additional" aria-selected="false">Additional Information</button><button class="btn" type="button" data-po-tab="terms" aria-selected="false">Terms and Conditions</button></nav><div data-po-pane="items"></div><div data-po-pane="additional" hidden><div class="formgrid"></div></div><div data-po-pane="terms" hidden></div>';
                    const itemsPane=details.querySelector('[data-po-pane=items]');
                    for(const selector of ['.po-table-tools','.po-table','[data-add]','.po-subtotal']){const node=form.querySelector(selector);if(node)itemsPane.appendChild(node);}
                    itemsPane.querySelector('[data-add]').classList.add('po-add-item');
                    const totals=document.createElement('div');totals.className='po-totals';totals.appendChild(itemsPane.querySelector('.po-subtotal'));totals.insertAdjacentHTML('beforeend','<p>Total amount <strong data-po-total></strong></p>');itemsPane.appendChild(totals);
                    const additional=details.querySelector('[data-po-pane=additional] .formgrid');
                    for(const name of ['company_id','work_order_id'])additional.appendChild(getField(name));
                    details.querySelector('[data-po-pane=terms]').appendChild(getField('terms'));
                    form.querySelector('.formgrid').remove();form.querySelector('h3')?.remove();
                    const status=document.createElement('div');status.className='po-status-bar';status.innerHTML='<span class="scope-badge">'+esc(row?.state==='purchase'?'Confirmed':row?.state==='cancel'?'Cancelled':'Draft')+'</span>';
                    form.prepend(status,general,details);
                    details.querySelectorAll('[data-po-tab]').forEach(button=>button.onclick=()=>{details.querySelectorAll('[data-po-tab]').forEach(b=>b.setAttribute('aria-selected',String(b===button)));details.querySelectorAll('[data-po-pane]').forEach(p=>p.hidden=p.dataset.poPane!==button.dataset.poTab);});
                    const submit=form.querySelector('[type=submit]');submit.textContent=row?.state==='purchase'?'Save confirmed PO':'Save draft';
                    if(!row)submit.insertAdjacentHTML('afterend','<button class="btn" type="submit" data-create-another>Save draft &amp; create another</button>');
                    const actions=form.querySelector('.modal-actions');actions.appendChild(actions.querySelector('[data-cancel]'));
                    form.elements.currency_id.addEventListener('change',updateAmounts);
                    updateAmounts();
                    form.addEventListener('invalid',event=>{const pane=event.target.closest('[data-po-pane]');if(pane?.hidden)details.querySelector('[data-po-tab="'+pane.dataset.poPane+'"]').click();},true);
                }
                let attempt = null;
                form.onsubmit = async e => {
                    e.preventDefault(); if (modal.busy || !form.reportValidity()) return;
                    const data = Object.fromEntries([...form.elements].filter(n => n.name && !n.closest('.finance-line')).map(n => [n.name, n.type === 'checkbox' ? n.checked : n.value || null]));
                    if (lineHost) data.lines = [...lineHost.children].map(node => Object.fromEntries([...node.querySelectorAll('[name]')].map(n => [n.name, n.multiple ? [...n.selectedOptions].map(o => Number(o.value)) : n.value || null])));
                    if (kind === 'purchase-orders' && selectedWorkOrder()) data.work_order_version_id = selectedWorkOrder().version_id;
                    const signature = JSON.stringify(data); if (!attempt || attempt.signature !== signature) attempt = {signature, key: crypto.randomUUID()};
                    modal.busy = true; form.inert = true;
                    try { const result = await this.api.request('finance/' + kind + (id ? '/' + id : ''), id ? 'PUT' : 'POST', { values: data, ...(id ? {revision:row.revision} : {request_id:attempt.key}) }); modal.busy = false; modal.close(); await reload(); this.open(kind, e.submitter?.hasAttribute('data-create-another') ? null : result.id, reload); }
                    catch (error) { const notice = form.querySelector('[data-error]'); notice.textContent = error.message; notice.classList.remove('hidden'); }
                    finally { modal.busy = false; form.inert = false; }
                };
            } catch (error) { modal.content.innerHTML = '<p class="notice" role="alert">' + esc(error.message) + '</p>'; }
        }
        async vendors(host) {
            try {
                const data = await this.api.request('vendors');
                host.innerHTML = '<div class="heading"><h2>Vendors &amp; subcontractors</h2>'+(data.can_create ? '<button class="btn primary" data-new>Register on their behalf</button>' : '')+'</div><div class="tablewrap"><table><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Profile</th></tr></thead><tbody>'+data.rows.map(v=>'<tr><td>'+esc(v.name)+'</td><td>'+esc(v.email)+'</td><td>'+esc(v.mobile||v.phone)+'</td><td><button class="btn" data-vendor="'+v.id+'">Open profile</button></td></tr>').join('')+'</tbody></table></div>';
                host.insertAdjacentHTML('beforeend','<section class="panel"><h3>Self-registration</h3><p>Share a private registration link. It expires after 24 hours and accepts one submission.</p>'+this.select('invite_company','Your company',data.companies,data.companies[0]?.id)+'<button class="btn" data-invite>Share Link</button><div data-link></div><h3>Registration reviews</h3><div data-registrations></div></section>');
                host.querySelector('[data-invite]').onclick=async()=>{const area=host.querySelector('[data-link]');try{const result=await this.api.request('vendor-registrations/invite','POST',{company_id:host.querySelector('[name=invite_company]').value});area.innerHTML='<label class="field">Copy and share this private link<input class="input" readonly value="'+esc(result.url)+'"></label><p>Expires: '+esc(result.expires_at)+'</p>';}catch(error){area.textContent=error.message;}};
                const registrations=await this.api.request('vendor-registrations');
                host.querySelector('[data-registrations]').innerHTML=registrations.rows.map(r=>'<div class="finance-line"><strong>'+esc(r.data?.name||'Registration invitation')+'</strong> · '+esc(r.state)+(r.state==='link_requested'||(r.state==='invited'&&new Date(r.expires_at)<new Date())?'<button class="btn" data-resend="'+r.id+'">Share Link</button>':'')+(r.state==='pending'&&registrations.can_review?'<button class="btn" data-review="'+r.id+'">Review submission</button>':'')+'</div>').join('')||'<p>No submissions yet.</p>';
                host.querySelectorAll('[data-resend]').forEach(button=>button.onclick=async()=>{try{const result=await this.api.request('vendor-registrations/'+button.dataset.resend+'/resend','POST',{});host.querySelector('[data-link]').innerHTML='<label class="field">New link · valid for 24 hours<input class="input" readonly value="'+esc(result.url)+'"></label>';button.disabled=true;}catch(error){host.querySelector('[data-link]').textContent=error.message;}});
                host.querySelectorAll('[data-review]').forEach(button=>button.onclick=()=>{const r=registrations.rows.find(r=>r.id==button.dataset.review),m=this.modals.modal('Review subcontractor registration');m.content.innerHTML='<dl class="hr-detail">'+Object.entries(r.data).map(([key,value])=>'<dt>'+esc(key.replaceAll('_',' '))+'</dt><dd>'+esc(value)+'</dd>').join('')+'</dl><p>Approval creates a vendor record available for purchase orders.</p><div class="actions"><button class="btn primary" data-decision="approve">Approve registration</button><button class="btn" data-decision="reject">Reject registration</button></div><p role="alert" data-error></p>';m.content.querySelectorAll('[data-decision]').forEach(b=>b.onclick=async()=>{if(m.busy)return;m.busy=true;try{await this.api.request('vendor-registrations/'+r.id+'/'+b.dataset.decision,'POST',{});m.busy=false;m.close();await this.vendors(host);}catch(error){m.content.querySelector('[data-error]').textContent=error.message;}finally{m.busy=false;}});});
                const linkArea=host.querySelector('[data-link]');
                const observer=new MutationObserver(()=>{const input=linkArea.querySelector('input');if(!input||linkArea.querySelector('[data-copy]'))return;linkArea.insertAdjacentHTML('beforeend','<div class="actions"><button class="btn" data-copy>Copy link</button><button class="btn primary" data-share>Share Link</button></div><p data-share-status role="status"></p>');linkArea.querySelector('[data-copy]').onclick=async()=>{try{await navigator.clipboard.writeText(input.value);linkArea.querySelector('[data-share-status]').textContent='Link copied.';}catch(error){input.select();linkArea.querySelector('[data-share-status]').textContent='Select and copy the link above.';}};linkArea.querySelector('[data-share]').onclick=async()=>{if(navigator.share){try{await navigator.share({title:'Subcontractor registration',text:'Please register within 24 hours.',url:input.value});}catch(error){}}else linkArea.querySelector('[data-copy]').click();};});observer.observe(linkArea,{childList:true});
                const edit = (v={}) => {
                    const page=this.page(); page.host.querySelector('h2').textContent=v.id ? v.name : 'Register vendor / subcontractor';
                    const fields=(keys)=>keys.map(k=>this.input(k,k.replaceAll('_',' '),v[k],k==='email'?'email':'text',k==='name')).join('');
                    page.content.innerHTML='<form><section class="panel"><h3>General</h3><div class="formgrid">'+this.select('company_id','Company',data.companies,v.company_id||data.companies[0]?.id)+this.select('account_type','Account type',[{id:'company',name:'Company'},{id:'individual',name:'Individual'}],v.account_type||'company')+fields(['name','tax_id','company_registry','phone','mobile','email','website'])+'</div><h3>Address</h3><div class="formgrid">'+fields(['street1','street2','city','zip'])+'</div></section><section class="panel"><h3>Sales &amp; Purchases</h3><div class="formgrid">'+fields(['reference'])+'</div><h3>Internal Notes</h3><textarea class="input" name="comment" rows="4">'+esc(v.comment)+'</textarea></section><p role="alert" data-error></p><div class="actions"><button class="btn" type="button" data-cancel>Back to vendors</button><button class="btn primary">Save vendor</button></div></form>';
                    page.content.querySelector('[data-cancel]').onclick=page.close;
                    const form=page.content.querySelector('form'); form.onsubmit=async e=>{e.preventDefault();if(page.busy||!form.reportValidity())return;page.busy=true;try{await this.api.request('vendors'+(v.id?'/'+v.id:''),v.id?'PUT':'POST',Object.fromEntries(new FormData(form)));page.busy=false;page.close();await this.vendors(host);}catch(error){form.querySelector('[data-error]').textContent=error.message;}finally{page.busy=false;}};
                };
                host.querySelector('[data-new]')?.addEventListener('click',()=>edit());
                host.querySelectorAll('[data-vendor]').forEach(b=>b.onclick=()=>edit(data.rows.find(v=>v.id==b.dataset.vendor)));
            } catch(error) { host.innerHTML='<p class="notice" role="alert">'+esc(error.message)+'</p>'; }
        }
        async configuration(host, companyId = null, profile = false) {
            host.innerHTML = '<p role="status">Loading document configuration…</p>';
            try {
                const data = await this.api.request('finance/configuration' + (companyId ? '?company_id=' + companyId : ''));
                const settings = data.settings;
                host.innerHTML = '<h2>PO / Invoice configuration</h2><p class="muted">Company-specific branding, numbering and document defaults. Saved documents retain their configuration snapshot. Numbering never moves backwards.</p><form enctype="multipart/form-data"><div class="formgrid">' + this.select('company_id','Company',data.companies,data.company_id) + ['display_name','registration_number','tax_number','email','phone'].map(k => this.input(k,k.replaceAll('_',' '),settings[k])).join('') + '</div><h3>Company logo</h3>' + (settings.logo_data ? '<img class="finance-logo" alt="Current company logo" src="' + esc(settings.logo_data) + '">' : '<p class="muted">No logo uploaded.</p>') + '<label class="field">Upload logo (PNG, JPEG or WebP, up to 512 KB)<input class="input" name="logo" type="file" accept="image/png,image/jpeg,image/webp"></label><label><input type="checkbox" name="remove_logo"> Remove current logo</label><div class="formgrid">' + ['address','bank_details','po_terms','invoice_terms','footer'].map(k => '<label class="field">' + esc(k.replaceAll('_',' ')) + '<textarea class="input" name="' + k + '" rows="3">' + esc(settings[k]) + '</textarea></label>').join('') + '</div>' + ['po','invoice'].map(k => '<fieldset class="finance-line"><legend>' + (k === 'po' ? 'Purchase order' : 'Invoice') + ' numbering</legend><div class="formgrid">' + this.input(k+'_prefix','Prefix',settings[k+'_prefix']) + this.input(k+'_suffix','Suffix',settings[k+'_suffix']) + this.input(k+'_padding','Number digits',settings[k+'_padding'],'number',true) + this.input(k+'_next','Next number',settings[k+'_next'],'number',true) + '</div><p>Next reference preview: <strong data-preview="' + k + '">' + esc(settings[k+'_preview']) + '</strong></p></fieldset>').join('') + '<p class="muted">Use %(year), %(y), %(month) or %(day) in prefixes and suffixes. Preview does not consume a number. Counters continue across years.</p><p class="notice hidden" data-error role="alert"></p><div class="modal-actions"><span data-saved role="status"></span><button class="btn primary">Save configuration</button></div></form>';
                host.querySelector('h2').textContent = profile ? 'Company Profile' : 'Document settings';
                host.querySelector('h2 + p').textContent = profile ? 'Set up your company once. New POs and invoices automatically use this company branding, contact information and bank details. Existing documents keep their saved snapshot.' : 'Set company branding once, then manage PO and invoice numbering, terms and footer defaults here.';
                const form = host.querySelector('form');
                const companyFields = ['display_name','registration_number','tax_number','email','phone','address','bank_details','logo','remove_logo'];
                for (const name of companyFields) { const node = form.elements[name]; const wrapper = node.closest('label'); if (wrapper) wrapper.hidden = false; }
                form.querySelectorAll('h3,.finance-logo').forEach(node => { node.hidden = false; });
                form.querySelectorAll('fieldset').forEach(node => { node.hidden = profile; });
                for (const name of ['po_terms','invoice_terms','footer']) form.elements[name].closest('label').hidden = profile;
                form.querySelector('button[type=submit],button.primary').textContent = profile ? 'Save company profile' : 'Save configuration';
                form.elements.company_id.onchange = () => this.configuration(host, Number(form.elements.company_id.value), profile);
                const preview = () => { const now = new Date(), map = {'%(year)':String(now.getFullYear()),'%(y)':String(now.getFullYear()).slice(-2),'%(month)':String(now.getMonth()+1).padStart(2,'0'),'%(day)':String(now.getDate()).padStart(2,'0')}; const replace = s => Object.entries(map).reduce((v,[a,b])=>v.split(a).join(b),s); for (const k of ['po','invoice']) form.querySelector('[data-preview="'+k+'"]').textContent = replace(form.elements[k+'_prefix'].value) + form.elements[k+'_next'].value.padStart(Math.min(10, Math.max(1, Number(form.elements[k+'_padding'].value))), '0') + replace(form.elements[k+'_suffix'].value); };
                form.oninput = preview;
                for (const k of ['po','invoice']) { form.elements[k+'_padding'].step = '1'; form.elements[k+'_padding'].min = '1'; form.elements[k+'_padding'].max = '10'; form.elements[k+'_next'].step = '1'; form.elements[k+'_next'].min = '1'; }
                let busy = false;
                form.onsubmit = async e => { e.preventDefault(); if (busy || !form.reportValidity()) return; busy = true; form.inert = true;
                    const body = new FormData(); body.append('company_id',data.company_id); body.append('revision',data.revision); body.append('remove_logo',form.elements.remove_logo.checked ? '1' : '0');
                    for (const node of form.querySelectorAll('input[name],textarea[name]')) if (!['company_id','logo','remove_logo'].includes(node.name)) body.append('settings['+node.name+']',node.value);
                    if (form.elements.logo.files[0]) body.append('logo',form.elements.logo.files[0]);
                    try { const response = await fetch(this.api.base+'/finance/configuration',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':this.api.csrf},body}); const result = await response.json(); if (!response.ok) throw Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Unable to save configuration.'); await this.configuration(host,data.company_id,profile); host.querySelector('[data-saved]').textContent = 'Configuration saved · version '+result.revision; }
                    catch(error) { const notice = form.querySelector('[data-error]'); notice.textContent = error.message; notice.classList.remove('hidden'); }
                    finally { busy = false; form.inert = false; }
                };
            } catch(error) { host.innerHTML = '<p class="notice" role="alert">'+esc(error.message)+'</p>'; }
        }
    }
    root.NorthwestNativeFinance = NativeFinance;
})(globalThis);
