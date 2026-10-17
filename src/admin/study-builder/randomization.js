(function (B) {
    'use strict';
    B.randomConfig = (d, n, strategy) => {
        if (!strategy.startsWith('RANDOM') && strategy !== 'WEIGHTED_RANDOM') return null;
        const children = B.children(d, n.id), candidates = children.filter(x => x.kind !== 'DIRECTIVE').map(x => x.id), config = { strategy, scope: n.scope === 'MACRO' ? 'EXECUTION' : 'BLOCK_INSTANCE', candidates, select_n: strategy === 'RANDOM_SELECT_N' ? 1 : null, draw_count: strategy === 'WEIGHTED_RANDOM' ? 1 : null, weights: strategy === 'WEIGHTED_RANDOM' ? Object.fromEntries(candidates.map(x => [x, 1])) : [], order: 'DRAW', algorithm_version: 'EIPSI_RANDOM_V1' };
        if (children.some(x => x.kind === 'DIRECTIVE')) { const groups = []; let part = []; children.forEach(x => { if (x.kind === 'DIRECTIVE') { if (part.length) groups.push(part); part = []; } else part.push(x.id); }); if (part.length) groups.push(part); config.segments = groups.map((ids, i) => ({ id: 'segment_' + i, candidates: ids })); }return config;
    };
    B.randomEditor = (d, n, change) => {
        const box = B.el('section', {}, B.el('h3', { text: 'Orden y selección de hijos' }), B.select('Estrategia', n.children_strategy, [['SEQUENTIAL', 'Secuencial'], ['PARALLEL_AVAILABLE', 'Disponibles en paralelo'], ['RANDOM_ORDER', 'Orden aleatorio'], ['RANDOM_SELECT_ONE', 'Elegir uno (por ejemplo, brazo RCT)'], ['RANDOM_SELECT_N', 'Elegir N'], ['WEIGHTED_RANDOM', 'Selección ponderada']], children_strategy => change({ children_strategy, randomization: B.randomConfig(d, n, children_strategy) })));
        if (!n.randomization) return box; const r = n.randomization, patch = p => change({ randomization: { ...r, ...p } });
        box.append(B.select('Cuándo sortear', r.scope, [['EXECUTION', 'Una vez por participante/ejecución'], ['BLOCK_INSTANCE', 'En cada ocurrencia del bloque']], scope => patch({ scope })), B.select('Orden de seleccionados', r.order, [['DRAW', 'Orden del sorteo'], ['DEFINITION', 'Orden estructural']], order => patch({ order })), B.note(r.candidates.length + ' hijos elegibles. PageBreak queda fuera de cada pool y separa segmentos.'));
        if (r.strategy === 'RANDOM_SELECT_N') box.append(B.field('Elegir N', r.select_n, select_n => patch({ select_n }), 'number', { min: 1, max: r.candidates.length }));
        if (r.strategy === 'WEIGHTED_RANDOM') { box.append(B.field('Cantidad de selecciones', r.draw_count, draw_count => patch({ draw_count }), 'number', { min: 1 }));r.candidates.forEach(id => box.append(B.field('Peso: ' + B.path(d, id), r.weights[id], v => patch({ weights: { ...r.weights, [id]: v } }), 'number', { min: 0 })));box.append(B.note('Los pesos expresan proporciones relativas; no garantizan tamaños finales exactos.')); }
        if (r.segments) box.append(B.note('Segmentos: ' + r.segments.map(x => x.candidates.map(id => B.label(B.node(d, id))).join(', ')).join(' | ')));return box;
    };
})(window.EIPSIBuilder);
