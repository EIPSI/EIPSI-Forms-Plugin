(function (root) {
  "use strict";
  const A = root.EIPSIAssessment,
    el = A.el;
  A.openPreview = function (definition, options = {}) {
    const message=key=>definition.flow.messages?.[key] ?? root.EIPSIAssessmentFlow.messages[key];
    let elapsed = 0;
    const session = new A.PreviewSession(definition),
      recovery = new A.RecoverySimulation(() => Date.now() + elapsed),
      previousFocus = document.activeElement,
      dialog = el("dialog", {
        class: "ae-preview",
        "aria-label": "Preview de la evaluación",
      }),
      toolbar = el("div", { class: "ae-preview-toolbar" }),
      frame = el("div", { class: "ae-preview-frame", "data-width": "desktop" }),
      body = el("div"),
      foot = el("div", { class: "ae-preview-actions" }),
      audit = el("details", {class:"ae-randomization-audit"});
    let closed = false,
      suspended = false,
      notice = "";
    function close() {
      if (closed) return;
      closed = true;
      session.answers = {};
      session.touched = {};
      recovery.clear();
      recovery.savedPartials = [];
      dialog.remove();
      if (previousFocus) previousFocus.focus();
    }
    toolbar.append(el("strong", {}, "Preview · respuestas temporales"));
    function group(label) {
      const box = el("fieldset", { class: "ae-preview-control-group" });
      box.append(el("legend", {}, label));
      toolbar.append(box);
      return box;
    }
    const displayGroup = group("Tamaño de pantalla"),
      sessionGroup = group("Sesión de prueba");
    function reset(message = "", rerandomize = false) {
      recovery.clear();
      suspended = false;
      notice = message;
      const fresh = new A.PreviewSession(session.definition, rerandomize ? null : session.randomization);
      fresh.metadata = new A.MetadataRecorder(fresh.definition, recovery.clock);
      if (root.EIPSIAssessmentRandomization.hasState(fresh.randomization)) fresh.metadata.data.randomization = A.copy(fresh.randomization);
      fresh.metadata.present(fresh.definition.flow.pages[0].id, fresh.pages[0]);
      Object.assign(session, fresh);
      render();
    }
    ["Desktop", "Tablet", "Mobile"].forEach((name) => {
      const b = el(
        "button",
        { type: "button", "aria-pressed": String(name === "Desktop") },
        name,
      );
      b.onclick = () => {
        frame.dataset.width = name.toLowerCase();
        toolbar
          .querySelectorAll("[aria-pressed]")
          .forEach((n) => n.setAttribute("aria-pressed", String(n === b)));
      };
      displayGroup.append(b);
    });
    const exit = el("button", { type: "button" }, "Cerrar preview");
    exit.onclick = close;
    sessionGroup.append(exit);
    if (root.EIPSIAssessmentRandomization.hasState(session.randomization)) {
      const reroll = el("button", {type:"button"}, "Volver a simular randomización");
      reroll.onclick = () => reset("Nueva selección simulada. Las respuestas de prueba se reiniciaron.", true);
      group("Randomización de prueba").append(reroll);
    }
    if (options.exporter) {
      const exportButton = el(
        "button",
        { type: "button" },
        "Exportar respuestas simuladas",
      );
      exportButton.onclick = async () => {
        try {
          if (session.errors().length)
            throw Error(
              "Completá las respuestas requeridas antes de exportar.",
            );
          const result = await options.exporter(session);
          debug.replaceChildren(el("p",{},"Exportación simulada preparada."));
          diagnostics.open = true;
          const blob = new Blob([result.csv], {
              type: "text/csv;charset=utf-8",
            }),
            url = root.URL.createObjectURL(blob),
            link = el(
              "a",
              { href: url, download: "native-preview.csv" },
              "Descargar CSV simulado",
            );
          body.append(link);
          link.click();
          root.setTimeout(() => {
            root.URL.revokeObjectURL(url);
            link.remove();
          }, 1000);
        } catch (error) {
          body.append(el("p", { role: "alert" }, error.message));
        }
      };
      group("Exportación de prueba").append(exportButton);
    }

    const recoveryGroup = group("Salir y volver al cuestionario"),
      timeInput = el("input", {type: "number", min: "0", step: "any", value: "0", "aria-label": "Tiempo transcurrido fuera"}),
      timeUnit = el("select", {"aria-label": "Unidad del tiempo transcurrido"}),
      leave = el("button", {type: "button", class: "ae-preview-save"}, "Salir del cuestionario"),
      resume = el("button", {type: "button", class: "ae-preview-resume"}, "Volver al cuestionario");
    for (const [value, label] of [["minutes","Minutos"],["hours","Horas"],["days","Días"],["weeks","Semanas"],["months","Meses (30 días)"]]) timeUnit.append(el("option", {value}, label));
    const timeLabel = el("label", {}, "Tiempo transcurrido fuera ");
    timeLabel.append(timeInput, timeUnit);
    recoveryGroup.append(timeLabel, leave, resume,
      el("p", {class: "ae-preview-group-help"}, "La sesión vigente conserva el progreso. Si vence, empezás desde cero; el guardado parcial conserva un registro anterior, sin reanudarlo."));
    leave.onclick = () => {
      if (suspended) return;
      if (!session.finished) {
        session.metadata.leave("suspend");
        recovery.save(session);
        session.answers = {};
        session.touched = {};
      }
      suspended = true;
      notice = "";
      render();
    };
    resume.onclick = () => {
      if (!suspended) return;
      const value = Number(timeInput.value);
      if (timeInput.value.trim() === "" || !Number.isFinite(value) || value < 0 || value > 1e9) {
        notice = "Ingresá un tiempo transcurrido válido, mayor o igual a cero.";
        render(); return;
      }
      elapsed += value * root.EIPSIAssessmentFlow.units[timeUnit.value] * 1000;
      if (session.finished) { suspended = false; notice = ""; render(); return; }
      if (recovery.resume(session)) { suspended = false; notice = "La sesión sigue vigente. Podés continuar donde estabas."; render(); }
      else reset(recovery.previousPartialSaved
        ? message("expired_saved")
        : message("expired"));
    };
    const diagnostics = el("details", { class: "ae-metadata-debug" }),
      summary = el("summary", {}, "Diagnóstico de metadata (sólo autoría)"),
      debug = el("div");
    diagnostics.append(summary, debug);
    dialog.append(diagnostics);
    const debugUpdate = () => {
      if (diagnostics.open)
        A.renderDiagnostics(debug,session);
    };
    diagnostics.ontoggle = debugUpdate;
    body.addEventListener("input", () => {
      root.setTimeout(debugUpdate, 0);
    });
    body.addEventListener("change", () => {
      root.setTimeout(debugUpdate, 0);
    });
    let method = "unknown";
    body.addEventListener("pointerdown", () => {
      method = "pointer";
      session.inputMethod = method;
    });
    body.addEventListener("keydown", () => {
      method = "keyboard";
      session.inputMethod = method;
    });
    function focus(event, active) {
      const node = event.target.closest('[id^="preview-"]');
      if (!node) return;
      const id = node.id.slice(8),
        item = [...session.metadata.items.values()].find((e) => e.id === id);
      if (item) session.metadata.focus(item.response_key, active, method);
    }
    body.addEventListener("focusin", (e) => focus(e, true));
    body.addEventListener("focusout", (e) => focus(e, false));
    function renderElements(items, container) {
      A.renderParticipantElements(items, container, session);
    }
    function showErrors() {
      body.querySelectorAll(".ae-validation").forEach((n) => n.remove());
      const errors = session.errors();
      errors.forEach((error) => {
        const n = body.querySelector("#preview-" + error.id);
        n.append(
          el("p", { class: "ae-validation", role: "alert" }, error.message),
        );
      });
      if (errors.length) {
        const first = body.querySelector("#preview-" + errors[0].id);
        first.tabIndex = -1;
        first.focus();
      }
      return errors;
    }
    function renderAudit() {
      audit.hidden = !root.EIPSIAssessmentRandomization.hasState(session.randomization);
      audit.replaceChildren(el("summary", {}, "Resolución de randomizaciones · sólo Preview"));
      const r = session.randomization, names = new Map();
      A.walk(session.definition.elements, e => {names.set(e.id, e.properties.label || e.type);e.options.forEach(o => names.set(o.id,o.label));});
      const name = id => (names.get(id) || id) + " [" + id + "]";
      audit.append(el("p", {}, "Referencia / seed: " + r.seed + " · " + r.resolved_at));
      for (const block of Object.values(r.blocks || {})) {
        const section = el("section"), table = el("table", {class:"ae-audit-table"});
        section.append(el("h4", {}, "Randomización: " + name(block.block_id)));
        const head = el("tr");["Candidato", "Peso", "Fijo", "Mostrar siempre", "Seleccionado", "Orden resultante"].forEach(t => head.append(el("th", {}, t)));table.append(head);
        for (const id of block.candidates) {
          const row = el("tr"), fixed = (block.fixed || []).includes(id), always = (block.always_show || (block.algorithm ? [] : block.fixed) || []).includes(id), index = block.order.indexOf(id);
          [name(id), String(block.weights?.[id] ?? 1), fixed ? "Sí" : "No", always ? "Sí" : "No", always ? "Mostrar siempre" : block.selected.includes(id) ? "Sí" : "No", index < 0 ? "—" : String(index + 1)].forEach(t => row.append(el("td", {}, t)));table.append(row);
        }
        section.append(table);audit.append(section);
      }
      for (const o of Object.values(r.option_orders || {})) {
        const section = el("section");section.append(el("h4", {}, "Orden de opciones: " + name(o.question_id)));
        section.append(el("p", {}, "Candidatas: " + o.candidates.map(name).join(" · ")));
        section.append(el("p", {}, "Posición fija: " + (o.fixed.map(name).join(" · ") || "Ninguna")));
        const list = el("ol");o.order.forEach(id => list.append(el("li", {}, name(id))));section.append(list);audit.append(section);
      }
    }
    function render() {
      renderAudit();
      body.replaceChildren();
      foot.replaceChildren();
      if (session.definition.flow.show_title !== false) body.append(el("h2", {}, session.definition.title));
      const flow = session.definition.flow;
      A.applyDesign(frame, flow.design);
      foot.dataset.alignment = flow.design?.navigation_alignment || "edges";
      const saveControl = toolbar.querySelector(".ae-preview-save"),
        resumeControl = toolbar.querySelector(".ae-preview-resume");
      if (saveControl) saveControl.disabled = suspended;
      if (resumeControl) resumeControl.disabled = !suspended;
      if (notice) body.append(el("p", { role: "status" }, notice));
      if (suspended) {
        body.append(
          el(
            "p",
            { role: "status" },
            "Saliste del cuestionario. Indicá cuánto tiempo pasó y pulsá Volver al cuestionario.",
          ),
        );
        debugUpdate();
        return;
      }
      if (session.finished) {
        recovery.clear();
        const c = flow.completion;
        body.append(el("h2", {}, c.title));
        const message = { label: "", label_html: c.message_html };
        body.append(A.richText.render(message, "label"));
        if (c.image_url)
          body.append(
            el("img", {
              src: c.image_url,
              alt: "",
              class: "ae-completion-image",
            }),
          );
        debugUpdate();
        return;
      }
      const page = flow.pages[session.page];
      if (page.title)
        body.append(el("h3", { class: "ae-page-title" }, page.title));
      if (page.description_html)
        body.append(
          A.richText.render({ label_html: page.description_html }, "label"),
        );
      if (["pages", "both"].includes(flow.progress))
        body.append(
          el(
            "p",
            { class: "ae-page-count" },
            (flow.messages?.progress ?? root.EIPSIAssessmentFlow.messages.progress).replaceAll("[ACTUAL]",String(session.page+1)).replaceAll("[TOTAL]",String(session.pages.length)),
          ),
        );
      if (["bar", "both"].includes(flow.progress))
        body.append(
          el("progress", {
            max: session.pages.length,
            value: session.page + 1,
            "aria-label": flow.messages?.progress_accessible ?? root.EIPSIAssessmentFlow.messages.progress_accessible,
          }),
        );
      renderElements(session.pages[session.page], body);
      const back = el(
        "button",
        { type: "button", class: "ae-nav-back", disabled: session.page === 0 },
        session.definition.flow.navigation_labels.back || "Anterior",
      );
      back.onclick = () => {
        session.previous();
        render();
      };
      const next = el(
        "button",
        { type: "button", class: "ae-primary ae-nav-forward" },
        session.page === session.pages.length - 1 ? (session.definition.flow.navigation_labels.finish || "Terminar") : (session.definition.flow.navigation_labels.next || "Siguiente"),
      );
      next.onclick = () => {
        if (showErrors().length) return;
        if (session.next()) render();
        else {
          render();
          showErrors();
        }
      };
      if (flow.allow_back) foot.append(back);
      foot.append(next);
      debugUpdate();
    }
    const discardFooter = el("footer", {class: "ae-discard-footer"});
    if (definition.flow.allow_discard === true) {
      const discard = el("button", {type: "button", class: "ae-discard-response"}, message("discard_label"));
      discard.onclick = async () => {
        if (!await A.confirmDiscard(dialog,message)) return;
        recovery.savedPartials = [];
        reset(message("discarded"));
      };
      discardFooter.append(discard);
    }
    frame.append(body, foot, discardFooter);
    dialog.append(toolbar, audit, frame);
    document.body.append(dialog);
    dialog.addEventListener("cancel", (event) => {
      event.preventDefault();
      close();
    });
    dialog.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        event.preventDefault();
        close();
      }
      if (event.key === "Tab") {
        const nodes = Array.from(
          dialog.querySelectorAll(
            'button:not(:disabled),input,textarea,select,[tabindex="0"]',
          ),
        );
        const first = nodes[0],
          last = nodes[nodes.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });
    render();
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "");
    exit.focus();
    return { session, dialog, close, recovery };
  };
})(window);
