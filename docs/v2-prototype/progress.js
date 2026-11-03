(function () {
    'use strict';

    const types = ['Character', 'Integer', 'Float', 'Datetime'];
    const numeric = type => ['Integer', 'Float'].includes(type);
    const clone = value => JSON.parse(JSON.stringify(value));

    // Parse once without sample values: valid formulas such as End / Start
    // must not be rejected because an arbitrary test denominator happens to be zero.
    function parseFormula(expression, fields) {
        if (expression.length > 1000) throw Error('Formula is too long (maximum 1000 characters).');
        const tokens = expression.match(/[A-Za-z_][A-Za-z_0-9]*|(?:\d+(?:\.\d+)?|\.\d+)|[()+*\/-]|\S/g) || [];
        if (!tokens.length || tokens.length > 200) throw Error('Enter a formula with at most 200 tokens.');
        let position = 0;
        const fieldMap = new Map(fields.map(field => [field.key, field]));
        function primary() {
            const token = tokens[position++];
            if (token === '+' || token === '-') return { op: token === '+' ? 'positive' : 'negative', right: primary() };
            if (token === '(') {
                const node = expressionNode();
                if (tokens[position++] !== ')') throw Error('Missing closing parenthesis.');
                return node;
            }
            if (/^(?:\d+(?:\.\d+)?|\.\d+)$/.test(token || '')) {
                const value = Number(token);
                if (!Number.isFinite(value)) throw Error('Number is too large.');
                return { value };
            }
            if (/^[A-Za-z_][A-Za-z_0-9]*$/.test(token || '')) {
                const field = fieldMap.get(token);
                if (!field) throw Error('Unknown input key: ' + token);
                if (!numeric(field.type)) throw Error('Formula input must be numeric: ' + field.name);
                return { key: token };
            }
            throw Error('Unexpected or missing formula token: ' + (token || 'end'));
        }
        function term() {
            let node = primary();
            while (['*', '/'].includes(tokens[position])) node = { op: tokens[position++], left: node, right: primary() };
            return node;
        }
        function expressionNode() {
            let node = term();
            while (['+', '-'].includes(tokens[position])) node = { op: tokens[position++], left: node, right: term() };
            return node;
        }
        const root = expressionNode();
        if (position !== tokens.length) throw Error('Unexpected formula token: ' + tokens[position]);
        return root;
    }

    function evaluate(node, values) {
        if (Object.hasOwn(node, 'value')) return node.value;
        if (node.key) {
            const value = values[node.key];
            if (typeof value !== 'number' || !Number.isFinite(value)) throw Error('Missing numeric value for ' + node.key);
            return value;
        }
        const right = evaluate(node.right, values);
        if (node.op === 'positive') return right;
        if (node.op === 'negative') return -right;
        const left = evaluate(node.left, values);
        if (node.op === '/' && right === 0) throw Error('Division by zero.');
        const result = node.op === '+' ? left + right : node.op === '-' ? left - right : node.op === '*' ? left * right : left / right;
        if (!Number.isFinite(result)) throw Error('Formula result is outside the supported range.');
        return result;
    }

    function outputType(config) {
        return config.formula ? 'Float' : config.fields.find(field => field.key === config.outputField)?.type;
    }

    function validateConfig(config) {
        if (!config.name.trim()) throw Error('Enter an activity name.');
        if (!config.fields.length || config.fields.length > 30) throw Error('Use between 1 and 30 input rows.');
        const keys = new Set();
        const names = new Set();
        for (const field of config.fields) {
            if (!field.name.trim()) throw Error('Every input row needs a title.');
            if (!/^[A-Za-z_][A-Za-z_0-9]*$/.test(field.key) || keys.has(field.key)) throw Error('Input keys must be unique identifiers.');
            const title = field.name.trim().toLowerCase();
            if (names.has(title)) throw Error('Input titles must be unique.');
            if (!types.includes(field.type)) throw Error('Select a valid datatype.');
            for (const bound of ['min', 'max']) {
                if (field[bound] !== null && field[bound] !== undefined && (!numeric(field.type) || !Number.isFinite(field[bound]))) throw Error('Limits must be finite numbers on numeric fields.');
            }
            if (field.min != null && field.max != null && field.min > field.max) throw Error('Minimum cannot exceed maximum.');
            keys.add(field.key);
            names.add(title);
        }
        if (config.formula) parseFormula(config.formula, config.fields);
        else if (!keys.has(config.outputField)) throw Error('Choose the input to display in the tracker.');
        return config;
    }

    function typedValues(config, raw) {
        const values = Object.create(null);
        for (const field of config.fields) {
            const value = raw[field.key];
            if (value === undefined || value === null || String(value).trim() === '') {
                if (field.required) throw Error(field.name + ' is required.');
                continue;
            }
            if (numeric(field.type)) {
                if (!/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/.test(String(value).trim())) throw Error(field.name + ' must be a number.');
                values[field.key] = Number(value);
                if (!Number.isFinite(values[field.key])) throw Error(field.name + ' must be finite.');
                if (field.type === 'Integer' && !Number.isSafeInteger(values[field.key])) throw Error(field.name + ' must be a safe whole number.');
                if (field.min != null && values[field.key] < field.min) throw Error(field.name + ' must be at least ' + field.min);
                if (field.max != null && values[field.key] > field.max) throw Error(field.name + ' must be at most ' + field.max);
            } else if (field.type === 'Datetime') {
                const date = String(value);
                const parts = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(date);
                const parsed = new Date(date + ':00Z');
                if (!parts || !Number.isFinite(parsed.getTime()) || parsed.toISOString().slice(0, 16) !== date) throw Error(field.name + ' must be a valid date and time.');
                values[field.key] = date;
            } else values[field.key] = String(value).trim();
        }
        return values;
    }

    function calculate(config, raw) {
        validateConfig(config);
        const values = typedValues(config, raw);
        const result = config.formula ? evaluate(parseFormula(config.formula, config.fields), values) : values[config.outputField];
        if (result === undefined) throw Error('Enter a value for the tracker output.');
        if (typeof result === 'number' && (!Number.isFinite(result) || result <= 0)) throw Error('Recorded quantity must be finite and greater than zero.');
        return { values, result, resultType: outputType(config) };
    }

    function saveDraft(activity, draft) {
        return { ...activity, draft: clone(draft) };
    }

    function publish(activity, draft) {
        validateConfig(draft);
        const previous = activity.versions.at(-1);
        if (previous && (numeric(outputType(previous)) !== numeric(outputType(draft)) || previous.unit !== draft.unit)) {
            throw Error('Keep the published unit and numeric/text output category. Create a separate activity to change them without mixing historical totals.');
        }
        const version = clone({ ...draft, version: activity.versions.length + 1 });
        return { ...activity, draft: null, versions: [...activity.versions, version] };
    }

    function record(activity, raw, metadata) {
        const config = activity.versions.at(-1);
        if (!config) throw Error('Publish this activity before recording progress.');
        return {
            ...metadata, activity: activity.id, configuration: clone(config),
            ...calculate(config, raw), status: 'Pending',
        };
    }

    function groupEntries(entries, project) {
        const grouped = new Map();
        entries.filter(entry => entry.project === project).forEach(entry => {
            const key = JSON.stringify([entry.route, entry.date]);
            if (!grouped.has(key)) grouped.set(key, { route: entry.route, date: entry.date, entries: [] });
            grouped.get(key).entries.push(entry);
        });
        return [...grouped.values()].sort((a, b) => b.date.localeCompare(a.date) || a.route.localeCompare(b.route));
    }

    function aggregate(group, activity, status = null) {
        const config = activity.versions.at(-1);
        const matching = group.entries.filter(entry => entry.activity === activity.id && (!status || entry.status === status));
        if (numeric(outputType(config))) {
            const result = matching.reduce((sum, entry) => sum + entry.result, 0);
            if (!Number.isFinite(result)) throw Error('Daily total is outside the supported range.');
            return result;
        }
        return [...new Set(matching.map(entry => entry.result))].join('; ');
    }

    globalThis.NorthwestProgress = { types, numeric, clone, parseFormula, evaluate, outputType, validateConfig, typedValues, calculate, saveDraft, publish, record, groupEntries, aggregate };
})();
