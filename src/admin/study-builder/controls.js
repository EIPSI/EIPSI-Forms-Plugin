(function (B) {
    'use strict';
    B.guard = fn => e => { try { Promise.resolve(fn(e)).catch(error => B.active?.fail(error)); } catch (error) { B.active?.fail(error); } };
    B.el = (tag, props = {}, ...children) => { const e = document.createElement(tag); Object.entries(props).forEach(([k, v]) => { if (v === undefined) return; if (k.startsWith('on')) e.addEventListener(k.slice(2).toLowerCase(), B.guard(v)); else if (k === 'text') e.textContent = v; else if (k === 'checked' || k === 'disabled' || k === 'value') e[k] = v; else e.setAttribute(k, v); }); children.flat().forEach(c => { if (c != null) e.append(typeof c === 'string' ? document.createTextNode(c) : c); }); return e; };
    B.button = (label, action, disabled = false) => B.el('button', { type: 'button', class: 'button', text: label, onclick: action, disabled });
    B.field = (label, value, change, type = 'text', extra = {}) => { const id = B.id('control'), control = B.el(type === 'textarea' ? 'textarea' : 'input', { id, type: type === 'textarea' ? undefined : type, value: value ?? '', ...extra, onchange: e => change(type === 'number' ? Number(e.target.value) : e.target.value) }); return B.el('label', { class: 'sb-field', for: id }, B.el('span', { text: label }), control); };
    B.select = (label, value, options, change) => { const id = B.id('control'), s = B.el('select', { id, onchange: e => change(e.target.value) }, options.map(x => { const [v, text] = Array.isArray(x) ? x : [x, x]; return B.el('option', { value: v, text }); })); s.value = value ?? ''; return B.el('label', { class: 'sb-field', for: id }, B.el('span', { text: label }), s); };
    B.propertyGroup=(title,...children)=>B.el('details',{class:'sb-property-section',open:''},B.el('summary',{text:title}),...children);
    B.organizeProperties=box=>{
        const children=[...box.children];box.replaceChildren();let group=null,groupKind=null;
        const add=(title,kind=null)=>{groupKind=kind;group=B.propertyGroup(title);box.append(group);return group;};
        for(const child of children){
            if(child.tagName==='H3'){box.append(child);continue;}
            if(child.tagName==='H4'){add(child.textContent);continue;}
            if(child.tagName==='DETAILS'){child.classList.add('sb-property-section');box.append(child);group=null;continue;}
            if(child.tagName==='SECTION'&&child.firstElementChild?.tagName==='H3'){const title=child.firstElementChild.textContent;child.firstElementChild.remove();add(title).append(...child.childNodes);group=null;continue;}
            if(child.tagName==='BUTTON'){if(!group||groupKind!=='actions')add(window.EIPSIAdminI18n.t("Actions"),'actions');group.append(child);continue;}
            if(!group)add('General');group.append(child);
        }return box;
    };
    B.check = (label, checked, change) => B.el('label', { class: 'sb-field sb-checkbox' }, B.el('input', { type: 'checkbox', checked, onchange: e => change(e.target.checked) }), label);
    B.note = text => B.el('p', { class: 'description', text });
    B.nodeOptions = (d, predicate) => d.nodes.filter(predicate).map(n => [n.id, B.path(d, n.id) + ' (' + n.id + ')']);
    B.selector = (label, value, change, d) => {
        const box = B.el('fieldset', {}, B.el('legend', { text: label })); const current = value || { kind: 'SINGLE' };
        box.append(B.select(window.EIPSIAdminI18n.t("Occurrence"), current.kind, [['SINGLE', window.EIPSIAdminI18n.t("Single, without repetition")], ['SAME_PARENT_OCCURRENCE', window.EIPSIAdminI18n.t("Same parent occurrence")], ['FIRST_COMPLETED', window.EIPSIAdminI18n.t("First completed")], ['EXACT_OCCURRENCE', window.EIPSIAdminI18n.t("Specific occurrence")], ['LAST_COMPLETED_BEFORE', window.EIPSIAdminI18n.t("Last completed before an event")]], kind => change(kind === 'EXACT_OCCURRENCE' ? { kind, occurrence_key: [{ block_id: d.root_block_ids[0], slot: 'single' }] } : kind === 'LAST_COMPLETED_BEFORE' ? { kind, source_event_id: 'EVENT_REQUIRED' } : { kind })));
        if (current.kind === 'LAST_COMPLETED_BEFORE') box.append(B.field('ID del evento anterior (avanzado; no “latest” implícito)', current.source_event_id, v => change({ ...current, source_event_id: v })));
        if (current.kind === 'EXACT_OCCURRENCE') {
            (current.occurrence_key || []).forEach((p, i) => { const update = patch => { const key = B.copy(current.occurrence_key); key[i] = { ...p, ...patch }; change({ ...current, occurrence_key: key }); }; const row = B.el('div', { class: 'sb-row' }, B.select(window.EIPSIAdminI18n.t("Path block"), p.block_id, B.nodeOptions(d, n => n.kind === 'BLOCK'), v => update({ block_id: v })), B.select(window.EIPSIAdminI18n.t("Slot"), typeof p.slot === 'string' ? 'single' : p.slot.ordinal ? 'ordinal' : 'daily', [['single', 'Único'], ['daily', window.EIPSIAdminI18n.t("Day and time")], ['ordinal', window.EIPSIAdminI18n.t("Repetition number")]], v => update({ slot: v === 'single' ? 'single' : v === 'daily' ? { day: 1, slot: 1 } : { ordinal: 1 } })));
                if (typeof p.slot === 'object') Object.keys(p.slot).forEach(k => row.append(B.field(k, p.slot[k], v => update({ slot: { ...p.slot, [k]: v } }), 'number', { min: 1 })));
                row.append(B.button(window.EIPSIAdminI18n.t("Remove segment"), () => change({ ...current, occurrence_key: current.occurrence_key.filter((_, j) => j !== i) }))); box.append(row);
            }); box.append(B.button(window.EIPSIAdminI18n.t("Add segment"), () => change({ ...current, occurrence_key: [...current.occurrence_key, { block_id: d.root_block_ids[0], slot: 'single' }] })));
        }return box;
    };
    B.duration = (label, value, change, allowZero = false) => { const elapsed = value.kind === 'ELAPSED', box = B.el('fieldset', {}, B.el('legend', { text: label }));box.append(B.select(window.EIPSIAdminI18n.t("Time calculation"), value.kind, [['ELAPSED', window.EIPSIAdminI18n.t("Exact elapsed time")], ['CALENDAR', window.EIPSIAdminI18n.t("Local calendar")]], kind => change(kind === 'ELAPSED' ? { kind, seconds: 86400 } : { kind, amount: 1, unit: 'DAY' })));
        if (elapsed) { let factor = 60; const amount = B.field(window.EIPSIAdminI18n.t("Exact amount"), value.seconds / factor, v => change({ kind: 'ELAPSED', seconds: Math.round(v * factor) }), 'number', { min: allowZero ? 0 : 1 }); box.append(amount, B.select(window.EIPSIAdminI18n.t("Exact time unit"), '60', [['60', window.EIPSIAdminI18n.t("Minutes")], ['3600', window.EIPSIAdminI18n.t("Hours")], ['86400', window.EIPSIAdminI18n.t("24-hour days")], ['604800', window.EIPSIAdminI18n.t("168-hour weeks")]], unit => { factor = Number(unit); amount.querySelector('input').value = value.seconds / factor; }), B.note(window.EIPSIAdminI18n.t("Exact elapsed time: +24 hours may differ from the next day at the same local time."))); }
        else box.append(B.field(window.EIPSIAdminI18n.t("Amount"), value.amount, v => change({ ...value, amount: v }), 'number', { min: allowZero ? 0 : 1 }), B.select(window.EIPSIAdminI18n.t("Calendar unit"), value.unit, [['DAY', window.EIPSIAdminI18n.t("Days")], ['WEEK', window.EIPSIAdminI18n.t("Weeks")], ['MONTH', 'Meses']], unit => change({ ...value, unit })), B.note(window.EIPSIAdminI18n.t("Preserves local time; elapsed duration may vary with daylight saving time.")));
        return box;
    };
})(window.EIPSIBuilder);
