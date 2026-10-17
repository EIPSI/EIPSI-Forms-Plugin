(function (B) {
    'use strict';
    B.messages = { STUDY_STATE_CONFLICT:'El estado del estudio cambió en otra pestaña. Recargá para ver el estado actual.', STUDY_FINALIZED:'El estudio está finalizado. Usá Reapertura para iniciar una nueva etapa.', STUDY_DELIVERY_BUSY:'Hay un envío en curso. Volvé a intentar cuando termine.', PUBLISHED_VERSION_REQUIRED:'Publicá una versión antes de activar el estudio.', AUTHORING_OCCURRENCE_CAP: 'La cantidad estimada supera el límite de 10000 instancias del engine. Reducí la recurrencia.', TEMPORAL_CYCLE: 'Hay un ciclo de dependencias temporales. Revisá los bloques enlazados.', TARGET_NOT_FOUND: 'El destino de la regla ya no existe.', RANDOM_N_EXCEEDS_CHILDREN: 'N supera la cantidad de hijos elegibles.', UNBOUNDED_RECURRENCE: 'La repetición necesita cantidad o fecha de finalización.', MICRO_CONTAINS_MACRO: 'Un Micro no puede contener un Macro.', IMPOSSIBLE_WAIT_DEPENDENCY: 'El destino WAIT depende de un bloque omitido.', CONSENT_WITHOUT_DECLINE_RULE: 'El rol consentimiento no tiene una regla de rechazo.', COMPLETION_RULES_OVERLAP: 'Hay reglas de finalización que pueden coincidir.', REQUIRED_MAY_BE_SKIPPED: 'Una pregunta obligatoria puede omitirse por una regla.', IMPOSSIBLE_ASSESSMENT_CLOSURE: 'La evaluación necesita preguntas o contenido antes de publicar.', REMINDER_AFTER_DEADLINE: 'Un recordatorio coincide con el vencimiento o queda después.', DRAFT_REVISION_CONFLICT: 'Otra pestaña guardó cambios. Tu edición se conserva aquí; recargá explícitamente para reconciliar.', PINNED_MEDIA_UNAVAILABLE: 'Falta el archivo de media snapshot o su hash cambió.', AMBIGUOUS_RECURRENT_REFERENCE: 'Elegí explícitamente qué ocurrencia repetida se referencia.' };
    Object.assign(B.messages, {
        NO_TERMINAL_PATH: 'El estudio no tiene un camino que permita terminar. Agregá una evaluación con contenido o revisá los saltos y cierres.',
        NATIVE_SNAPSHOT_REQUIRED: 'Una evaluación publicada referenciada falta o su contenido no coincide con el snapshot guardado. Revisá esa evaluación en la biblioteca y volvé a asignar una versión válida.',
        INVALID_PREDICATE_REFERENCE: 'La condición usa una pregunta, elemento u opción que ya no existe. Abrí Condiciones lógicas y elegí nuevamente su fuente.',
        INVALID_LOGICAL_QUESTION_REFERENCE: 'La pregunta usada por una condición no pertenece a la evaluación referenciada. Revisá el origen de esa respuesta.',
        INVALID_SOURCE_QUESTION: 'La pregunta origen no corresponde al elemento donde se evalúa la condición.',
        INVALID_ACTION_TARGET: 'El destino de una acción no existe o no admite esa operación. Elegí un destino válido en Condiciones lógicas.',
        INVALID_ENTRY_QUESTION: 'La pregunta de entrada no pertenece a la evaluación destino. Revisá el salto.',
        INVALID_INTRA_ASSESSMENT_TARGET: 'El salto interno debe apuntar a una pregunta de la misma evaluación.',
        OUTSIDE_TRACK_FUTURE: 'La acción apunta a un elemento que no está en el futuro de esta pista. Revisá el recorrido y el destino.',
        INVALID_REFERENCE_SCOPE: 'La fuente no respeta el pasado de la pista. Elegí información disponible cuando se evalúa esta condición.',
        INCOMPATIBLE_LOGICAL_COMPARATOR: 'La operación no es compatible con el tipo de respuesta. Revisá el comparador y el valor.',
        LOGICAL_VALUE_TYPE_CONFLICT: 'Se comparan valores de tipos incompatibles. Usá números, fechas u opciones según la pregunta.',
        MULTIPLE_RESPONSE_REQUIRES_MEMBERSHIP: 'Una respuesta múltiple requiere una comparación de pertenencia al conjunto de opciones.',
        TRAJECTORY_CYCLE: 'Los saltos o esperas forman un ciclo. Revisá los destinos para que la trayectoria pueda avanzar.',
        WAIT_DEPENDS_ON_SOURCE_NATURAL_COMPLETION: 'La espera depende de completar el mismo elemento que quedaría esperando. Elegí un destino independiente.',
        RANDOMIZATION_ALREADY_ACTIVE_AT_SOURCE: 'La condición intenta modificar una randomización que ya entró en vigencia. Elegí una randomización futura.',
        INVALID_RANDOMIZATION_CANDIDATES: 'La randomización tiene candidatos inválidos o insuficientes. Revisá las evaluaciones y sus pesos.',
        INVALID_FORCED_OUTCOME_SIZE: 'El resultado forzado no coincide con la cantidad de elementos que debe seleccionar la randomización.',
        UNREACHABLE_ASSESSMENT_ZERO_WEIGHT: 'Una evaluación tiene peso cero y no puede ser seleccionada. Revisá si es intencional.',
        UNREACHABLE_QUESTION_ZERO_WEIGHT: 'Una pregunta tiene peso cero y no puede ser seleccionada. Revisá si es intencional.'
    });
    B.validationPanel = app => {
        const issues = app.validation?.issues || [], errors = issues.filter(i => i.severity !== 'WARNING'), warnings = issues.filter(i => i.severity === 'WARNING');
        const box = B.el('section', { 'aria-label': 'Validación' }, B.el('h2', { text: 'Validación y publicación' }), B.button('Validar con el compilador', () => app.validate()));
        if (!app.validation) { box.append(B.note('Guardá y validá antes de publicar. Se revisan la estructura, las evaluaciones publicadas, la programación, las randomizaciones y las condiciones lógicas.'));return box; }
        const status = errors.length ? 'error' : warnings.length ? 'warning' : 'success';
        box.append(B.el('div', { class: 'sb-validation-status', 'data-status': status, role: 'status' }, B.el('strong', { text: errors.length ? 'Hay problemas que impiden publicar' : warnings.length ? 'Sin errores; hay advertencias para revisar' : 'Validación correcta' }), B.note(errors.length + ' errores · ' + warnings.length + ' advertencias'), B.note(errors.length ? 'Corregí los errores y volvé a validar. Podés seguir guardando el draft.' : warnings.length ? 'Revisá cada advertencia y confirmá las decisiones intencionales antes de publicar.' : 'El compilador no detectó errores ni advertencias. Esto no reemplaza el recorrido de prueba del participante.')));
        const summary = app.validation.summary;
        if (summary) box.append(B.note('Versión ' + summary.number + ' · ' + summary.blocks + ' bloques · ' + summary.assessments + ' evaluaciones · ' + (summary.enrollment === 'EMAIL_LONGITUDINAL' ? 'Longitudinal' : 'Transversal') + ' · ' + summary.timezone.default));
        const diffLabels = { 'initial version': 'Primera versión del estudio', structure: 'Estructura de bloques', content: 'Contenido', questions: 'Preguntas', options: 'Opciones de respuesta', schedule: 'Programación temporal', randomization: 'Randomizaciones', conditions: 'Condiciones', timezone: 'Zona horaria', reminders: 'Recordatorios', enrollment: 'Ingreso al estudio', presentation: 'Presentación', logical_trajectories: 'Trayectorias y condiciones lógicas' };
        box.append(B.el('details', {}, B.el('summary', { text: 'Diff — cambios respecto a la versión anterior' }), B.note(app.validation.diff.length ? app.validation.diff.map(k => diffLabels[k] || k).join(', ') : 'Sin cambios científicos detectados.')));
        issues.forEach(i => {
            const where = i.node_id || i.path || '', key = i.code + '|' + where, warning = i.severity === 'WARNING', node = B.node(app.draft, where);
            const rule = app.draft.protocol_metadata?.trajectory_conditions?.find(r => r.id === where), origin = node ? B.path(app.draft, where) : rule ? 'Condiciones lógicas · ' + (rule.label || rule.id) : where === app.draft.id ? 'Configuración del estudio' : where || 'Protocolo';
            const row = B.el('div', { class: 'sb-issue', 'data-severity': warning ? 'WARNING' : 'ERROR' }, B.el('strong', { text: (warning ? 'Advertencia: ' : 'Error: ') + (B.messages[i.code] || i.message || 'El compilador detectó un problema en este elemento.') }), B.note('Dónde: ' + origin), B.el('details', {}, B.el('summary', { text: 'Detalle técnico' }), B.note(i.code + (where ? ' · ' + where : ''))));
            if (node) row.append(B.button('Ir al elemento', () => app.select(where)));
            if (warning) row.append(B.check('Revisé este warning', app.acknowledgements.has(key), yes => { if (yes) app.acknowledgements.add(key);else app.acknowledgements.delete(key);app.render(); }));box.append(row);
        });return box;
    };
})(window.EIPSIBuilder);
