(function (B) {
    'use strict';
    B.tree = (app, d, selected) => {
        const root = B.el('nav', { 'aria-label': window.EIPSIAdminI18n.t("Protocol structure") }, B.el('h2', { text: window.EIPSIAdminI18n.t("Structure") }), B.note(window.EIPSIAdminI18n.t("Macro: protocol. Micro assessment: assessment. Micro group: internal content.")));
        root.append(B.button(window.EIPSIAdminI18n.t("Add root Macro"), () => app.mutate(x => B.add(x, x.id, 'MACRO')), app.readOnly));
        const branch = parent => B.el('ul', {}, B.children(d, parent).map(n => {
            const separator = n.kind === 'DIRECTIVE' && n.directive_kind === 'PAGE_BREAK', li = B.el('li', { class: separator ? 'sb-pagebreak' : '', 'data-node': n.id, draggable: !app.readOnly, ondragstart: e => { e.stopPropagation(); e.dataTransfer.setData('text/plain', n.id); }, ondragover: e => { if (!app.readOnly) e.preventDefault(); }, ondrop: e => { e.preventDefault(); e.stopPropagation(); const id = e.dataTransfer.getData('text/plain'); app.mutate(x => B.move(x, id, n.id, B.children(x, n.id).length)); } });
            const text = separator ? window.EIPSIAdminI18n.t("\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500 Page break \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500") : B.label(n) + ' · ' + (n.kind === 'BLOCK' ? n.scope + ' / ' + n.completion_boundary : n.kind) + (n.schedule?.recurrence ? ' ↻' : '');
            li.append(B.el('button', { type: 'button', text, class: selected === n.id ? 'sb-selected' : '', 'aria-current': selected === n.id ? 'true' : 'false', onclick: () => app.select(n.id) }));
            if (n.kind === 'BLOCK' && B.children(d, n.id).length) li.append(B.el('details', { open: '' }, B.el('summary', { text: window.EIPSIAdminI18n.f("Children of %s",B.label(n)) }), branch(n.id)));return li;
        }));root.append(d.nodes.length ? branch(d.id) : B.note(window.EIPSIAdminI18n.t("No blocks. Add a Macro to begin.")));return root;
    };
    B.inspector = (app, d, id) => {
        const n = B.node(d, id), box = B.el('fieldset', { class: 'sb-inspector' });box.disabled = app.readOnly;
        if (!n) { box.append(B.note(window.EIPSIAdminI18n.t("Select a tree element.")));return box; }
        const patch = p => app.mutate(x => { Object.assign(B.node(x, id), p); return x; });
        box.append(B.el('legend', { text: B.path(d, id) }), B.el('h2', { text: window.EIPSIAdminI18n.t("Inspector") }));
        if (n.kind === 'BLOCK') { const advanced = B.el('details', n.schedule?.recurrence ? { open: '' } : {}, B.el('summary', { text: window.EIPSIAdminI18n.t("Calendar and order — protocol options") }), B.scheduleEditor(d, n, schedule => patch({ schedule })), B.randomEditor(d, n, patch)); box.append(B.field(window.EIPSIAdminI18n.t("Administrative name"), B.label(n), label => patch({ metadata: { ...n.metadata, label } })), advanced); }
        if (n.kind === 'QUESTION') box.append(B.note(window.EIPSIAdminI18n.t("Snapshot content: ") + B.label(n) + window.EIPSIAdminI18n.t(". Edit questions in the Gutenberg source and apply its snapshot to the draft.")), B.select(window.EIPSIAdminI18n.t("Semantic role"), n.semantic_role || '', [['', window.EIPSIAdminI18n.t("No role")], ['INFORMED_CONSENT', window.EIPSIAdminI18n.t("Informed consent")]], v => patch({ semantic_role: v || null })), B.note(window.EIPSIAdminI18n.t("The role informs tracking/export. Add a condition to define what happens when consent is declined.")));
        if (n.kind === 'DIRECTIVE') box.append(B.note(window.EIPSIAdminI18n.t("Visual separator. Does not create a Screen or separate execution.")));
        const peers = B.children(d, n.parent_id), index = peers.findIndex(x => x.id === id);
        box.append(B.button(window.EIPSIAdminI18n.t("Move up"), () => app.mutate(x => B.move(x, id, n.parent_id, index - 1)), index === 0), B.button(window.EIPSIAdminI18n.t("Move down"), () => app.mutate(x => B.move(x, id, n.parent_id, index + 1)), index === peers.length - 1), B.select(window.EIPSIAdminI18n.t("Change parent (keyboard)"), n.parent_id, [[d.id, window.EIPSIAdminI18n.t("Version root")], ...B.nodeOptions(d, x => x.kind === 'BLOCK' && x.id !== id && !B.descendants(d, id).includes(x.id) && B.allowed(d, x.id, n))], parent => app.mutate(x => B.move(x, id, parent, B.children(x, parent).length))), B.button(window.EIPSIAdminI18n.t("Duplicate element"), () => app.mutate(x => B.duplicate(x, id))), B.button(window.EIPSIAdminI18n.t("Delete element"), () => { if (confirm(window.EIPSIAdminI18n.t("Delete this element and its children from the draft? References will be checked."))) app.mutate(x => B.remove(x, id)); }));
        if (n.kind === 'BLOCK') {
            if (n.scope === 'MACRO') box.append(B.button(window.EIPSIAdminI18n.t("Add child Macro"), () => app.mutate(x => B.add(x, id, 'MACRO'))));box.append(B.button(n.scope === 'MACRO' ? window.EIPSIAdminI18n.t("Add Micro assessment") : window.EIPSIAdminI18n.t("Add Micro group"), () => app.mutate(x => B.add(x, id, 'MICRO'))));
            if (n.scope === 'MICRO') box.append(B.button(window.EIPSIAdminI18n.t("Insert page break"), () => app.mutate(x => B.add(x, id, 'PAGE_BREAK'))));
            if (n.completion_boundary === 'ASSESSMENT') box.append(B.assessmentEditor(app, n));
        }
        box.append(B.conditionEditor(d, n, condition_rules => app.mutate(x => ({ ...x, condition_rules }))));
        const advanced = B.el('details', {}, B.el('summary', { text: window.EIPSIAdminI18n.t("Advanced: identity and contract") }), B.note(window.EIPSIAdminI18n.t("Stable ID: ") + n.id + (n.response_key ? ' · response_key: ' + n.response_key : '')), B.note(window.EIPSIAdminI18n.t("IDs stay unchanged when renaming or moving. DST retains engine policies.")));box.append(advanced);return box;
    };
})(window.EIPSIBuilder);
