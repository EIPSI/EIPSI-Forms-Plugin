# Guía breve para investigadores — Study Engine

El piloto aún requiere el checklist operacional y un recorrido humano: [readiness y runbook](pilot-readiness.md). No usar estudios/datos críticos antes de esa revisión.

1. Abrí **EIPSI Forms → Study Engine**, creá el estudio y completá nombre, contacto e identificador público. Forms/Study/Waves anteriores siguen en la administración legacy; no se convierten automáticamente.
2. Añadí un Macro para organizar etapas y Micro de evaluación. Los grupos internos reúnen preguntas. Para un transversal simple basta un Macro inmediato y una evaluación.
3. En la evaluación elegí editar en Gutenberg o importar una fuente Form Library. Guardá Gutenberg y después aplicá el snapshot al Draft. Un PageBreak separa páginas; no crea otra evaluación. Editar la fuente no modifica versiones publicadas.
4. Configurá cuándo abre: evento de referencia (anchor), regla temporal y ventana de respuesta son decisiones distintas. “24 horas transcurridas” y “un día calendario local” pueden diferir; revisá timezone y cambios de horario.
5. Para diario/EMA elegí recurrencia, horarios y límite de días/count/until. Verificá el número de oportunidades en Simulator. Cinco horarios por siete días deben producir35; no dupliques manualmente la definición para cada día.
6. Configurá condiciones sobre respuestas/finalización. JUMP omite un tramo; WAIT espera el destino y puede reanudar un grupo interno; TERMINATE finaliza por regla y no es retiro voluntario. Revisá AND/OR/NOT y orden de reglas.
7. Para RCT, configurá la selección aleatoria en el Macro de brazos. La selección interna de preguntas/grupos se configura en su propio bloque. Revisá scope, N/pesos y segmentos PageBreak. Una execution ya asignada no debe volver a sortearse al recargar.
8. Si el estudio usa email, elegí verificación/notificaciones/reminders y TTL. El catálogo de textos de email es fijo. Preview no envía correos. Conocer un email existente no abre respuestas; el participante necesita sesión válida o enlace y debe pulsar **Continuar** para canjearlo.
9. Guardá y validá. Corregí errores; leé los warnings antes de aceptarlos. Simulator permite respuestas, reloj/timezone y trazas sin participantes reales, Storage ni SMTP.
10. Revisá resumen/diff y publicá. Published es read-only. Para cambiar preguntas/timing, cloná una nueva versión Draft. Publicar no abre el estudio: activá enrolamiento OPEN explícitamente y compartí su URL estable.
11. Participantes existentes conservan su versión; nuevos ingresos usan la activa. PAUSED/CLOSED detienen nuevas admisiones, pero no congelan las ventanas de participantes existentes. Retire tampoco borra sus datos.
12. Pedí al administrador el export explícito Study Engine y su manifest. Incluye versión, IDs/opciones, response keys, occurrence, estado y provenance. No mezclar raw export legacy. B1 puede conservarse según política; B2 queda excluido inmediatamente aunque cleanup siga pendiente. El runbook explica el export autorizado; no existe una descarga Engine/XLSX específica que esta guía pueda prometer.

Si aparece conflicto de guardado, conservá tus cambios y recargá sólo tras revisarlos; otra pestaña puede haber avanzado. Si un enlace venció, solicitá uno nuevo: eso no extiende el plazo de respuesta. Ante error operacional, anotá momento y Study/Execution opacos para el administrador; no publiques tokens ni respuestas privadas.

Para registrar el smoke pendiente: entorno/navegador/viewport/theme, pasos, resultado e incidencias. Recorré creación, Gutenberg, movimiento por teclado, errores/warnings, simulación, publish/clone/activate y participación desktop/narrow con PageBreak, enlace, WAIT/final y retiro. Ese registro humano se mantiene separado de los tests automáticos.
