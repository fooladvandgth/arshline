/*!
 * ARSH Tools Registry
 * Provides register/get/getDefaults/renderEditor/renderPreview
 * Extracted from dashboard-template.php
 */
(function (global) {
    'use strict';
    var ARSH = global.ARSH = global.ARSH || {};
    var _defs = Object.create(null);

    function register(def) {
        if (!def || !def.type) return;
        _defs[def.type] = def;
    }

    function get(type) {
        return _defs[type] || null;
    }

    function clone(obj) {
        try { return JSON.parse(JSON.stringify(obj)); } catch (_) { return obj; }
    }

    function getDefaults(type) {
        var d = get(type);
        return d && d.defaults ? clone(d.defaults) : null;
    }

    function renderEditor(type, field, ctx) {
        try {
            var d = get(type);
            if (!d || typeof d.renderEditor !== 'function') return false;
            ctx = ctx || {}; ctx.field = ctx.field || field;
            // Support both signatures: (field, ctx) and (ctx)
            if (d.renderEditor.length >= 2) { return !!d.renderEditor(field, ctx); }
            else { return !!d.renderEditor(ctx); }
        } catch (_) { return false; }
    }

    function renderPreview(type, field, ctx) {
        try {
            var d = get(type);
            if (!d || typeof d.renderPreview !== 'function') return false;
            ctx = ctx || {}; ctx.field = ctx.field || field;
            if (d.renderPreview.length >= 2) { return !!d.renderPreview(field, ctx); }
            else { return !!d.renderPreview(ctx); }
        } catch (_) { return false; }
    }

    ARSH.Tools = {
        register: register,
        get: get,
        getDefaults: getDefaults,
        renderEditor: renderEditor,
        renderPreview: renderPreview
    };
})(window);
