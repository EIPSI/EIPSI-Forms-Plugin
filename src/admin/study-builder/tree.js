(function (B) {
    'use strict';
    B.tree = (app, d, selected) => {
        const root = B.el('nav', { 'aria-label': 'Estructura del protocolo' }, B.el('h2', { text: 'Structure' }), B.note('Macro: protocolo. Micro evaluación: assessment. Micro grupo: contenido interno.'));
        root.append(B.button('Añadir Macro raíz', () => app.mutate(x => B.add(x, x.id, 'MACRO')), app.readOnly));
        const branch = parent => B.el('ul', {}, B.children(d, parent).map(n => {
            const separator = n.kind === 'DIRECTIVE' && n.directive_kind === 'PAGE_BREAK', li = B.el('li', { class: separator ? 'sb-pagebreak' : '', 'data-node': n.id, draggable: !app.readOnly, ondragstart: e => { e.stopPropagation(); e.dataTransfer.setData('text/plain', n.id); }, ondragover: e => { if (!app.readOnly) e.preventDefault(); }, ondrop: e => { e.preventDefault(); e.stopPropagation(); const id = e.dataTransfer.getData('text/plain'); app.mutate(x => B.move(x, id, n.id, B.children(x, n.id).length)); } });
            const text = separator ? '──────── Page break ────────' : B.label(n) + ' · ' + (n.kind === 'BLOCK' ? n.scope + ' / ' + n.completion_boundary : n.kind) + (n.schedule?.recurrence ? ' ↻' : '');
            li.append(B.el('button', { type: 'button', text, class: selected === n.id ? 'sb-selected' : '', 'aria-current': selected === n.id ? 'true' : 'false', onclick: () => app.select(n.id) }));
            if (n.kind === 'BLOCK' && B.children(d, n.id).length) li.append(B.el('details', { open: '' }, B.el('summary', { text: 'Hijos de ' + B.label(n) }), branch(n.id)));return li;
        }));root.append(d.nodes.length ? branch(d.id) : B.note('Sin bloques. Añadí un Macro para comenzar.'));return root;
    };
    B.inspector = (app, d, id) => {
        const n = B.node(d, id), box = B.el('fieldset', { class: 'sb-inspector' });box.disabled = app.readOnly;
        if (!n) { box.append(B.note('Seleccioná un elemento del árbol.'));return box; }
        const patch = p => app.mutate(x => { Object.assign(B.node(x, id), p); return x; });
        box.append(B.el('legend', { text: B.path(d, id) }), B.el('h2', { text: 'Inspector' }));
        if (n.kind === 'BLOCK') { const advanced = B.el('details', n.schedule?.recurrence ? { open: '' } : {}, B.el('summary', { text: 'Calendario y orden — opciones de protocolo' }), B.scheduleEditor(d, n, schedule => patch({ schedule })), B.randomEditor(d, n, patch)); box.append(B.field('Nombre administrativo', B.label(n), label => patch({ metadata: { ...n.metadata, label } })), advanced); }
        if (n.kind === 'QUESTION') box.append(B.note('Contenido snapshot: ' + B.label(n) + '. Editá preguntas en la fuente Gutenberg y aplicá su snapshot al draft.'), B.select('Rol semántico', n.semantic_role || '', [['', 'Sin rol'], ['INFORMED_CONSENT', 'Consentimiento informado']], v => patch({ semantic_role: v || null })), B.note('El rol informa seguimiento/export. Agregá una condición para definir qué sucede si se rechaza consentimiento.'));
        if (n.kind === 'DIRECTIVE') box.append(B.note('Separador visual. No crea Screen ni ejecución propia.'));
        const peers = B.children(d, n.parent_id), index = peers.findIndex(x => x.id === id);
        box.append(B.button('Subir', () => app.mutate(x => B.move(x, id, n.parent_id, index - 1)), index === 0), B.button('Bajar', () => app.mutate(x => B.move(x, id, n.parent_id, index + 1)), index === peers.length - 1), B.select('Cambiar padre (teclado)', n.parent_id, [[d.id, 'Raíz de versión'], ...B.nodeOptions(d, x => x.kind === 'BLOCK' && x.id !== id && !B.descendants(d, id).includes(x.id) && B.allowed(d, x.id, n))], parent => app.mutate(x => B.move(x, id, parent, B.children(x, parent).length))), B.button('Duplicar elemento', () => app.mutate(x => B.duplicate(x, id))), B.button('Eliminar elemento', () => { if (confirm('Eliminar este elemento y sus hijos del draft? Se comprobarán referencias.')) app.mutate(x => B.remove(x, id)); }));
        if (n.kind === 'BLOCK') {
            if (n.scope === 'MACRO') box.append(B.button('Añadir Macro hijo', () => app.mutate(x => B.add(x, id, 'MACRO'))));box.append(B.button(n.scope === 'MACRO' ? 'Añadir evaluación Micro' : 'Añadir grupo Micro', () => app.mutate(x => B.add(x, id, 'MICRO'))));
            if (n.scope === 'MICRO') box.append(B.button('Insertar Page break', () => app.mutate(x => B.add(x, id, 'PAGE_BREAK'))));
            if (n.completion_boundary === 'ASSESSMENT') box.append(B.assessmentEditor(app, n));
        }
        box.append(B.conditionEditor(d, n, condition_rules => app.mutate(x => ({ ...x, condition_rules }))));
        const advanced = B.el('details', {}, B.el('summary', { text: 'Avanzado: identidad y contrato' }), B.note('ID estable: ' + n.id + (n.response_key ? ' · response_key: ' + n.response_key : '')), B.note('Las IDs no cambian al renombrar o mover. DST conserva las políticas del engine.'));box.append(advanced);return box;
    };
})(window.EIPSIBuilder);
