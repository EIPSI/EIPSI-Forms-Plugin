(function (root) {
  "use strict";
  const A = root.EIPSIAssessment,
    el = A.el;
  A.openPreview = function (definition, options = {}) {
    const message=key=>definition.flow.messages?.[key] ?? root.EIPSIContent.get('flow_messages',root.EIPSIContent.language(definition))[key];
    let elapsed = 0;
    const session = new A.PreviewSession(definition),
      recovery = new A.RecoverySimulation(() => Date.now() + elapsed),
      previousFocus = document.activeElement,
      dialog = el("dialog", {
        class: "ae-preview",
        "aria-label": root.EIPSIAdminI18n.t("Assessment preview"),
      }),
      toolbar = el("div", { class: "ae-preview-toolbar" }),
      frame = el("div", { class: "ae-preview-frame", lang: root.EIPSIContent.language(definition), "data-width": "desktop" }),
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
    toolbar.append(el("strong", {}, root.EIPSIAdminI18n.t("Preview · temporary responses")));
    function group(label) {
      const box = el("fieldset", { class: "ae-preview-control-group" });
      box.append(el("legend", {}, label));
      toolbar.append(box);
      return box;
    }
    const displayGroup = group(root.EIPSIAdminI18n.t("Screen size")),
      sessionGroup = group(root.EIPSIAdminI18n.t("Test session"));
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
    const exit = el("button", { type: "button" }, root.EIPSIAdminI18n.t("Close preview"));
    exit.onclick = close;
    sessionGroup.append(exit);
    if (root.EIPSIAssessmentRandomization.hasState(session.randomization)) {
      const reroll = el("button", {type:"button"}, root.EIPSIAdminI18n.t("Simulate randomization again"));
      reroll.onclick = () => reset(root.EIPSIAdminI18n.t("New simulated selection. Test responses were reset."), true);
      group(root.EIPSIAdminI18n.t("Test randomization")).append(reroll);
    }
    if (options.exporter) {
      const exportButton = el(
        "button",
        { type: "button" },
        root.EIPSIAdminI18n.t("Export simulated responses"),
      );
      exportButton.onclick = async () => {
        try {
          if (session.errors().length)
            throw Error(
              root.EIPSIAdminI18n.t("Complete required responses before exporting."),
            );
          const result = await options.exporter(session);
          debug.replaceChildren(el("p",{},root.EIPSIAdminI18n.t("Simulated export prepared.")));
          diagnostics.open = true;
          const blob = new Blob([result.csv], {
              type: "text/csv;charset=utf-8",
            }),
            url = root.URL.createObjectURL(blob),
            link = el(
              "a",
              { href: url, download: "native-preview.csv" },
              root.EIPSIAdminI18n.t("Download simulated CSV"),
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
      group(root.EIPSIAdminI18n.t("Test export")).append(exportButton);
    }

    const recoveryGroup = group(root.EIPSIAdminI18n.t("Leave and return to the questionnaire")),
      timeInput = el("input", {type: "number", min: "0", step: "any", value: "0", "aria-label": root.EIPSIAdminI18n.t("Time spent away")}),
      timeUnit = el("select", {"aria-label": root.EIPSIAdminI18n.t("Elapsed time unit")}),
      leave = el("button", {type: "button", class: "ae-preview-save"}, root.EIPSIAdminI18n.t("Leave questionnaire")),
      resume = el("button", {type: "button", class: "ae-preview-resume"}, root.EIPSIAdminI18n.t("Return to questionnaire"));
    for (const [value, label] of [["minutes",root.EIPSIAdminI18n.t("Minutes")],["hours",root.EIPSIAdminI18n.t("Hours")],["days",root.EIPSIAdminI18n.t("Days")],["weeks",root.EIPSIAdminI18n.t("Weeks")],["months",root.EIPSIAdminI18n.t("Months (30 days)")]]) timeUnit.append(el("option", {value}, label));
    const timeLabel = el("label", {}, root.EIPSIAdminI18n.t("Time spent away "));
    timeLabel.append(timeInput, timeUnit);
    recoveryGroup.append(timeLabel, leave, resume,
      el("p", {class: "ae-preview-group-help"}, root.EIPSIAdminI18n.t("A valid session retains progress. On expiration, you start again; partial saving retains a previous record without resuming it.")));
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
        notice = root.EIPSIAdminI18n.t("Enter a valid elapsed time greater than or equal to zero.");
        render(); return;
      }
      elapsed += value * root.EIPSIAssessmentFlow.units[timeUnit.value] * 1000;
      if (session.finished) { suspended = false; notice = ""; render(); return; }
      if (recovery.resume(session)) { suspended = false; notice = root.EIPSIAdminI18n.t("The session is still valid. You can continue where you left off."); render(); }
      else reset(recovery.previousPartialSaved
        ? message("expired_saved")
        : message("expired"));
    };
    const diagnostics = el("details", { class: "ae-metadata-debug" }),
      summary = el("summary", {}, root.EIPSIAdminI18n.t("Metadata diagnostics (authoring only)")),
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
      audit.replaceChildren(el("summary", {}, root.EIPSIAdminI18n.t("Randomization resolution · preview only")));
      const r = session.randomization, names = new Map();
      A.walk(session.definition.elements, e => {names.set(e.id, e.properties.label || e.type);e.options.forEach(o => names.set(o.id,o.label));});
      const name = id => (names.get(id) || id) + " [" + id + "]";
      audit.append(el("p", {}, root.EIPSIAdminI18n.f("Reference / seed: %1$s \u00b7 %2$s",r.seed,r.resolved_at)));
      for (const block of Object.values(r.blocks || {})) {
        const section = el("section"), table = el("table", {class:"ae-audit-table"});
        section.append(el("h4", {}, root.EIPSIAdminI18n.f("Randomization: %s",name(block.block_id))));
        const head = el("tr");[root.EIPSIAdminI18n.t("Candidate"), root.EIPSIAdminI18n.t("Weight"), root.EIPSIAdminI18n.t("Fixed"), root.EIPSIAdminI18n.t("Always show"), root.EIPSIAdminI18n.t("Selected"), root.EIPSIAdminI18n.t("Resulting order")].forEach(t => head.append(el("th", {}, t)));table.append(head);
        for (const id of block.candidates) {
          const row = el("tr"), fixed = (block.fixed || []).includes(id), always = (block.always_show || (block.algorithm ? [] : block.fixed) || []).includes(id), index = block.order.indexOf(id);
          [name(id), String(block.weights?.[id] ?? 1), fixed ? root.EIPSIAdminI18n.t("Yes") : root.EIPSIAdminI18n.t("No"), always ? root.EIPSIAdminI18n.t("Yes") : root.EIPSIAdminI18n.t("No"), always ? root.EIPSIAdminI18n.t("Always show") : block.selected.includes(id) ? root.EIPSIAdminI18n.t("Yes") : root.EIPSIAdminI18n.t("No"), index < 0 ? "—" : String(index + 1)].forEach(t => row.append(el("td", {}, t)));table.append(row);
        }
        section.append(table);audit.append(section);
      }
      for (const o of Object.values(r.option_orders || {})) {
        const section = el("section");section.append(el("h4", {}, root.EIPSIAdminI18n.f("Option order: %s",name(o.question_id))));
        section.append(el("p", {}, root.EIPSIAdminI18n.f("Candidates: %s",o.candidates.map(name).join(' · '))));
        section.append(el("p", {}, root.EIPSIAdminI18n.f("Fixed position: %s",(o.fixed.map(name).join(' · ')||root.EIPSIAdminI18n.t('None')))));
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
            root.EIPSIAdminI18n.t("You left the questionnaire. Enter the elapsed time and press Return to questionnaire."),
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
            (flow.messages?.progress ?? root.EIPSIContent.get('flow_messages',root.EIPSIContent.language(session.definition)).progress).replaceAll("[ACTUAL]",String(session.page+1)).replaceAll("[TOTAL]",String(session.pages.length)),
          ),
        );
      if (["bar", "both"].includes(flow.progress))
        body.append(
          el("progress", {
            max: session.pages.length,
            value: session.page + 1,
            "aria-label": flow.messages?.progress_accessible ?? root.EIPSIContent.get('flow_messages',root.EIPSIContent.language(session.definition)).progress_accessible,
          }),
        );
      renderElements(session.pages[session.page], body);
      const back = el(
        "button",
        { type: "button", class: "ae-nav-back", disabled: session.page === 0 },
        session.definition.flow.navigation_labels.back || root.EIPSIContent.get('flow',root.EIPSIContent.language(session.definition)).navigation_labels.back,
      );
      back.onclick = () => {
        session.previous();
        render();
      };
      const next = el(
        "button",
        { type: "button", class: "ae-primary ae-nav-forward" },
        session.page === session.pages.length - 1 ? (session.definition.flow.navigation_labels.finish || root.EIPSIContent.get('flow',root.EIPSIContent.language(session.definition)).navigation_labels.finish) : (session.definition.flow.navigation_labels.next || root.EIPSIContent.get('flow',root.EIPSIContent.language(session.definition)).navigation_labels.next),
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
