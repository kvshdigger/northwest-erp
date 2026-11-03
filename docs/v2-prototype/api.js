(function (root) {
    'use strict';
    class ProgressApi {
        constructor(base, csrf, transport = (...args) => root.fetch(...args)) {
            this.base = base.replace(/\/$/, '');
            this.csrf = csrf;
            this.transport = transport;
        }
        async request(path, method = 'GET', body) {
            let response;
            try { response = await this.transport(this.base + '/' + path, {
                method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            });
            } catch (_) { throw Error('Connection interrupted. Your form is still open. Retry unchanged values to avoid duplicate progress.'); }
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = response.status === 401 || response.status === 419 ? 'Your session expired. Reload this page and sign in again.'
                    : response.status === 409 ? 'This record changed or this submission key was already used. Refresh data before trying again; your form is still open.'
                    : Object.values(data.errors || {}).flat().join(' ') || data.message || 'Unable to complete the request. Please try again.';
                const error = Error(message);
                error.status = response.status;
                throw error;
            }
            return data;
        }
        async projects() {
            const rows = [];
            let page = 1, last = 1;
            do {
                const result = await this.request('projects?page=' + page++);
                rows.push(...result.data);
                last = result.last_page;
            } while (page <= last);
            return rows;
        }
        project(id, path, method = 'GET', body) { return this.request('projects/' + encodeURIComponent(id) + '/' + path, method, body); }
        column(row) {
            return { id: String(row.id), revision: row.revision, visible: row.is_active, draft: row.draft || null,
                versions: row.published ? [{ ...row.published.configuration, version: row.published.version }] : [] };
        }
        async save(id, column, configuration, publish, onSaved) {
            const saved = await this.project(id, column.revision ? 'activities/' + column.id + '/draft' : 'activities', column.revision ? 'PUT' : 'POST', { revision: column.revision || 0, configuration });
            let result = this.column(saved.data);
            onSaved(result);
            if (publish) {
                const published = await this.project(id, 'activities/' + result.id + '/publish', 'POST', { revision: result.revision });
                result = this.column(published.data);
                onSaved(result);
            }
            return result;
        }
    }
    root.NorthwestProgressApi = ProgressApi;
    if (typeof module !== 'undefined') module.exports = ProgressApi;
})(typeof window !== 'undefined' ? window : globalThis);
