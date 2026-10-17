(function (root) {
  "use strict";
  const A = root.EIPSIAssessment,
    el = A.el;
  A.renderDiagnostics = function (host, session) {
    const m = session.metadata.snapshot(),
      d = session.definition;
    host.replaceChildren();
    const human = el("div", { class: "ae-diagnostics-human" });
    const time = (v) =>
      v == null ? "Sin registrar" : new Date(v).toLocaleTimeString();
    const duration = (v) =>
      v == null ? "Sin registrar" : (v / 1000).toFixed(1) + " s";
    function section(title, fields) {
      const s = el("section"),
        dl = el("dl");
      s.append(el("h3", {}, title));
      for (const [label, value] of fields)
        dl.append(
          el("dt", {}, label),
          el("dd", {}, String(value ?? "Sin registrar")),
        );
      s.append(dl);
      human.append(s);
      return s;
    }
    section("Evaluación", [
      ["Iniciada", time(m.started_at)],
      [
        "Duración",
        duration(
          m.completed_at ? m.duration : Math.max(0, Date.now() - m.started_at),
        ),
      ],
      [
        "Estado",
        {
          active: "En curso",
          suspended: "Guardada para continuar",
          completed: "Completada",
        }[m.status] || m.status,
      ],
      [
        "Recuperación",
        m.recovery_used ? "Sí · " + m.recovery_count + " reanudaciones" : "No",
      ],
      [
        "Dispositivo / navegador",
        m.context
          ? [m.context.device, m.context.browser, m.context.os].join(" · ")
          : "Perfil Basic: contexto no capturado",
      ],
    ]);
    for (const p of Object.values(m.pages)) {
      const page = d.flow.pages.find((x) => x.id === p.page_id);
      section("Página · " + (page?.title || "Sin título"), [
        ["Visitas", p.visit_count],
        ["Tiempo", duration(p.duration)],
        ["Entrada", time(p.presented_at)],
        ["Salida", time(p.leave_at)],
        [
          "Navegación",
          p.next_transitions +
            " avances · " +
            p.previous_transitions +
            " regresos",
        ],
      ]);
    }
    for (const item of session.metadata.items.values()) {
      const v = m.items[item.response_key];
      if (!v) continue;
      const final = session.answers[item.response_key],
        value =
          final == null
            ? "Sin responder"
            : root.EIPSIAssessmentControls.answerValue(item, final);
      const s = section(
        item.properties.variable_name +
          " — " +
          (item.properties.label || item.label || "Item"),
        [
          ["Primera interacción", time(v.first_interaction_at)],
          [
            "Respuesta final",
            Array.isArray(value)
              ? value.join(", ")
              : String(value ?? "Sin responder"),
          ],
          ["Cambios", v.change_count],
          ["Interacciones", v.interaction_count],
          ["Tiempo hasta responder", duration(v.time_to_final_response)],
          [
            "Método de entrada",
            {
              pointer: "Puntero",
              keyboard: "Teclado",
              touch: "Táctil",
              unknown: "No detectado",
            }[v.input_method] || "No capturado",
          ],
        ],
      );
      if (d.flow.metadata_profile === "detailed" && v.trajectory.length) {
        const timeline = el("ol", { class: "ae-response-timeline" });
        for (const e of v.trajectory) {
          let state = e.state,
            text;
          if (
            state &&
            typeof state === "object" &&
            !Array.isArray(state) &&
            "length" in state
          )
            text =
              "Texto modificado: " +
              state.length +
              " caracteres (contenido no registrado)";
          else if (Array.isArray(state))
            text =
              "Selecciones: " +
              state
                .map((id) => item.options.find((o) => o.id === id)?.label || id)
                .join(", ");
          else
            text =
              "Respuesta: " +
              (item.options?.find((o) => o.id === state)?.label ??
                state ??
                "Sin respuesta");
          timeline.append(el("li", {}, time(e.timestamp) + " — " + text));
        }
        s.append(timeline);
      }
    }
    host.append(human);
    const technical = el("details"),
      raw = el("pre");
    technical.append(el("summary", {}, "Ver datos técnicos"), raw);
    technical.ontoggle = () => {
      if (technical.open)
        raw.textContent = JSON.stringify(session.metadata.snapshot(), null, 2);
    };
    host.append(technical);
  };
})(window);
