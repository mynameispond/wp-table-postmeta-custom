'use strict';

const path = require('path');

const requests = [];
let formControlsDisabled = false;

class Wrapper {
    constructor(options = {}) {
        this.length = options.length === undefined ? 1 : options.length;
        this.dataValues = options.data || {};
        this.findValues = options.find || {};
        this.inputValue = options.value;
        this.closestValue = options.closest || null;
        this.serializeValues = options.serialize || [];
        this.controlsAll = options.controlsAll || false;
        this.handlers = {};
    }

    ready(callback) {
        callback(jquery);
        return this;
    }

    find(selector) {
        return this.findValues[selector] || emptyWrapper;
    }

    closest() {
        return this.closestValue || emptyWrapper;
    }

    on(eventName, selector, callback) {
        if (typeof selector === 'function') {
            callback = selector;
            selector = '';
        }
        this.handlers[eventName + ' ' + selector] = callback;
        return this;
    }

    triggerDelegated(eventName, selector, context) {
        const callback = this.handlers[eventName + ' ' + selector];
        if (callback) {
            callback.call(context, { preventDefault() {} });
        }
    }

    data(name) {
        return this.dataValues[name];
    }

    val(value) {
        if (arguments.length > 0) {
            this.inputValue = value;
            return this;
        }
        return this.inputValue;
    }

    serializeArray() {
        if (formControlsDisabled) {
            return [];
        }
        return this.serializeValues.map((item) => ({ ...item }));
    }

    addClass() {
        return this;
    }

    removeClass() {
        return this;
    }

    removeAttr() {
        return this;
    }

    html() {
        return this;
    }

    append() {
        return this;
    }

    prepend() {
        return this;
    }

    prop(name, value) {
        if (this.controlsAll && name === 'disabled' && arguments.length > 1) {
            formControlsDisabled = Boolean(value);
        }
        return this;
    }

    after() {
        return this;
    }

    show() {
        return this;
    }

    hide() {
        return this;
    }

    animate() {
        return this;
    }

    remove() {
        return this;
    }

    fadeOut(callback) {
        if (callback) {
            callback.call(this);
        }
        return this;
    }
}

const emptyWrapper = new Wrapper({ length: 0 });
const dataContainer = new Wrapper({
    data: {
        source: 'main',
        table: '',
        'post-id': '42',
        'meta-key': '',
        'meta-value': '',
        paged: 1,
    },
});
const form = new Wrapper({
    serialize: [
        { name: 'page', value: 'wppc-data-manager' },
        { name: 'wppc_action', value: 'save_record' },
        { name: 'source', value: 'main' },
        { name: 'table', value: '' },
        { name: 'meta_id', value: '0' },
        { name: 'post_id', value: '42' },
        { name: 'meta_key', value: 'price' },
        { name: 'meta_value', value: '250' },
    ],
});
const postIdInput = new Wrapper({ closest: form });
const adminControls = new Wrapper({ controlsAll: true });
const documentObject = {};

function jquery(selector) {
    if (selector instanceof Wrapper) {
        return selector;
    }
    if (selector === documentObject) {
        return new Wrapper();
    }
    if (selector === '#wppc-data-table-container') {
        return dataContainer;
    }
    if (selector === '#wppc_post_id') {
        return postIdInput;
    }
    if (selector === '.wppc-admin-wrap button, .wppc-admin-wrap input, .wppc-admin-wrap textarea, .wppc-admin-wrap select') {
        return adminControls;
    }
    return emptyWrapper;
}

jquery.get = function get(url, data) {
    requests.push({ url, data });
    return {
        done(callback) {
            callback({ success: true, html: '' });
            return this;
        },
        fail() {
            return this;
        },
        always(callback) {
            callback();
            return this;
        },
    };
};

jquery.post = jquery.get;

global.document = documentObject;
global.jQuery = jquery;
global.wppc_params = {
    ajax_url: 'https://example.test/wp-admin/admin-ajax.php',
    active_slug: 'product',
    active_source: 'main',
    nonces: {
        get_data_table: 'nonce-get-data',
    },
};

require(path.join(__dirname, '..', 'assets', 'wppc-admin.js'));

if (requests.length !== 1) {
    console.error('FAIL: Opening the main source should perform one initial AJAX table request.');
    process.exit(1);
}

if (requests[0].data.source !== 'main' || requests[0].data.table !== '') {
    console.error('FAIL: The initial AJAX request should retain source=main without inventing a custom slug.');
    process.exit(1);
}

const searchForm = new Wrapper({
    find: {
        'input[name="filter_post_id"]': new Wrapper({ value: '42' }),
        'input[name="filter_meta_key"]': new Wrapper({ value: 'price' }),
        'input[name="filter_meta_value"]': new Wrapper({ value: '' }),
        'input[name="source"]': new Wrapper({ value: 'main' }),
        'input[name="table"]': new Wrapper({ value: '' }),
    },
});
dataContainer.triggerDelegated('submit', 'form[method="get"]', searchForm);

if (requests.length !== 2 || requests[1].data.source !== 'main' || requests[1].data.table !== '') {
    console.error('FAIL: Main-source searches must not fall back to the active custom slug.');
    process.exit(1);
}

form.triggerDelegated('submit', '', form);
const saveRequest = requests.find((request) => (
    Array.isArray(request.data)
    && request.data.some((item) => item.name === 'action' && item.value === 'wppc_ajax_save_record')
));
const saveFields = Object.fromEntries((saveRequest && saveRequest.data || []).map((item) => [item.name, item.value]));

if (
    saveFields.source !== 'main'
    || saveFields.table !== ''
    || saveFields.post_id !== '42'
    || saveFields.meta_key !== 'price'
    || saveFields.meta_value !== '250'
) {
    console.error('FAIL: The AJAX save handler must serialize form fields before disabling them.');
    process.exit(1);
}

console.log('PASS: Main-source JavaScript preserves source isolation and serializes complete save requests.');
