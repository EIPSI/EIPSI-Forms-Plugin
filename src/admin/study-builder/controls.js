(function (B) {
    'use strict';
    B.guard = fn => e => { try { Promise.resolve(fn(e)).catch(error => B.active?.fail(error)); } catch (error) { B.active?.fail(error); } };
    B.el = (tag, props = {}, ...children) => { const e = document.createElement(tag); Object.entries(props).forEach(([k, v]) => { if (v === undefined) return; if (k.startsWith('on')) e.addEventListener(k.slice(2).toLowerCase(), B.guard(v)); else if (k === 'text') e.textContent = v; else if (k === 'checked' || k === 'disabled' || k === 'value') e[k] = v; else e.setAttribute(k, v); }); children.flat().forEach(c => { if (c != null) e.append(typeof c === 'string' ? document.createTextNode(c) : c); }); return e; };
    B.button = (label, action, disabled = false) => B.el('button', { type: 'button', class: 'button', text: label, onclick: action, disabled });
    B.field = (label, value, change, type = 'text', extra = {}) => { const id = B.id('control'), control = B.el(type === 'textarea' ? 'textarea' : 'input', { id, type: type === 'textarea' ? undefined : type, value: value ?? '', ...extra, onchange: e => change(type === 'number' ? Number(e.target.value) : e.target.value) }); return B.el('label', { class: 'sb-field', for: id }, B.el('span', { text: label }), control); };
    B.select = (label, value, options, change) => { const id = B.id('control'), s = B.el('select', { id, onchange: e => change(e.target.value) }, options.map(x => { const [v, text] = Array.isArray(x) ? x : [x, x]; return B.el('option', { value: v, text }); })); s.value = value ?? ''; return B.el('label', { class: 'sb-field', for: id }, B.el('span', { text: label }), s); };
    B.propertyGroup=(title,...children)=>B.el('details',{class:'sb-property-section',open:''},B.el('summary',{text:title}),...children);
    B.organizeProperties=box=>{
        const children=[...box.children];box.replaceChildren();let group=null;
        const add=(title)=>{group=B.propertyGroup(title);box.append(group);return group;};
        for(const child of children){
            if(child.tagName==='H3'){box.append(child);continue;}
            if(child.tagName==='H4'){add(child.textContent);continue;}
            if(child.tagName==='DETAILS'){child.classList.add('sb-property-section');box.append(child);group=null;continue;}
            if(child.tagName==='SECTION'&&child.firstElementChild?.tagName==='H3'){const title=child.firstElementChild.textContent;child.firstElementChild.remove();add(title).append(...child.childNodes);group=null;continue;}
            if(child.tagName==='BUTTON'){if(group?.querySelector('summary')?.textContent!=='Acciones')add('Acciones');group.append(child);continue;}
            if(!group)add('General');group.append(child);
        }return box;
    };
    B.check = (label, checked, change) => B.el('label', { class: 'sb-field sb-checkbox' }, B.el('input', { type: 'checkbox', checked, onchange: e => change(e.target.checked) }), label);
    B.note = text => B.el('p', { class: 'description', text });
    B.nodeOptions = (d, predicate) => d.nodes.filter(predicate).map(n => [n.id, B.path(d, n.id) + ' (' + n.id + ')']);
    B.selector = (label, value, change, d) => {
        const box = B.el('fieldset', {}, B.el('legend', { text: label })); const current = value || { kind: 'SINGLE' };
        box.append(B.select('Ocurrencia', current.kind, [['SINGLE', 'Única, sin repetición'], ['SAME_PARENT_OCCURRENCE', 'Misma ocurrencia del padre'], ['FIRST_COMPLETED', 'Primera completada'], ['EXACT_OCCURRENCE', 'Ocurrencia específica'], ['LAST_COMPLETED_BEFORE', 'Última completada antes de un evento']], kind => change(kind === 'EXACT_OCCURRENCE' ? { kind, occurrence_key: [{ block_id: d.root_block_ids[0], slot: 'single' }] } : kind === 'LAST_COMPLETED_BEFORE' ? { kind, source_event_id: 'EVENT_REQUIRED' } : { kind })));
        if (current.kind === 'LAST_COMPLETED_BEFORE') box.append(B.field('ID del evento anterior (avanzado; no “latest” implícito)', current.source_event_id, v => change({ ...current, source_event_id: v })));
        if (current.kind === 'EXACT_OCCURRENCE') {
            (current.occurrence_key || []).forEach((p, i) => { const update = patch => { const key = B.copy(current.occurrence_key); key[i] = { ...p, ...patch }; change({ ...current, occurrence_key: key }); }; const row = B.el('div', { class: 'sb-row' }, B.select('Bloque del camino', p.block_id, B.nodeOptions(d, n => n.kind === 'BLOCK'), v => update({ block_id: v })), B.select('Slot', typeof p.slot === 'string' ? 'single' : p.slot.ordinal ? 'ordinal' : 'daily', [['single', 'Único'], ['daily', 'Día y horario'], ['ordinal', 'Número de repetición']], v => update({ slot: v === 'single' ? 'single' : v === 'daily' ? { day: 1, slot: 1 } : { ordinal: 1 } })));
                if (typeof p.slot === 'object') Object.keys(p.slot).forEach(k => row.append(B.field(k, p.slot[k], v => update({ slot: { ...p.slot, [k]: v } }), 'number', { min: 1 })));
                row.append(B.button('Quitar tramo', () => change({ ...current, occurrence_key: current.occurrence_key.filter((_, j) => j !== i) }))); box.append(row);
            }); box.append(B.button('Añadir tramo', () => change({ ...current, occurrence_key: [...current.occurrence_key, { block_id: d.root_block_ids[0], slot: 'single' }] })));
        }return box;
    };
    B.duration = (label, value, change, allowZero = false) => { const elapsed = value.kind === 'ELAPSED', box = B.el('fieldset', {}, B.el('legend', { text: label }));box.append(B.select('Cómputo del tiempo', value.kind, [['ELAPSED', 'Tiempo transcurrido exacto'], ['CALENDAR', 'Calendario local']], kind => change(kind === 'ELAPSED' ? { kind, seconds: 86400 } : { kind, amount: 1, unit: 'DAY' })));
        if (elapsed) { let factor = 60; const amount = B.field('Cantidad exacta', value.seconds / factor, v => change({ kind: 'ELAPSED', seconds: Math.round(v * factor) }), 'number', { min: allowZero ? 0 : 1 }); box.append(amount, B.select('Unidad de tiempo exacto', '60', [['60', 'Minutos'], ['3600', 'Horas'], ['86400', 'Días de 24 horas'], ['604800', 'Semanas de 168 horas']], unit => { factor = Number(unit); amount.querySelector('input').value = value.seconds / factor; }), B.note('Tiempo exacto: +24 horas puede diferir del día siguiente a la misma hora local.')); }
        else box.append(B.field('Cantidad', value.amount, v => change({ ...value, amount: v }), 'number', { min: allowZero ? 0 : 1 }), B.select('Unidad calendario', value.unit, [['DAY', 'Días'], ['WEEK', 'Semanas'], ['MONTH', 'Meses']], unit => change({ ...value, unit })), B.note('Conserva la hora local; puede variar la duración por horario de verano.'));
        return box;
    };
})(window.EIPSIBuilder);
