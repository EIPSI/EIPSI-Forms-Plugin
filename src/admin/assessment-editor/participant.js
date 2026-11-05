(function (root) {
  "use strict";
  const A = root.EIPSIAssessment;
  /** One native content/widget renderer shared by Preview and a host-owned participant surface. */
  A.renderParticipantElements = function (
    items,
    parent,
    session,
    prefix = "preview",
  ) {
    items.forEach((e) => {
      if (e.type === "page_break") return;
      const block = A.el(e.type === "group" ? "fieldset" : "div", {
        class: "ae-preview-question",
        id: prefix + "-" + e.id,
        "data-ae-question-id": e.id,
      });
      if (e.type === "group") {
        block.append(A.el("legend", {}, e.properties.label));
        A.renderParticipantElements(e.children, block, session, prefix);
      } else {
        if (e.type !== "image")
          block.append(
            e.response_key || e.type === "instruction"
              ? A.richText.render(e.properties, "label")
              : A.el("p", {}, e.properties.label),
          );
        if (e.properties.required) {
          const title = block.lastElementChild;
          const marker = A.el("span", { class: "ae-required", "aria-hidden": "true" }, "* ");
          const end = title?.querySelector("p,h1,h2,h3,h4,h5,h6") || title;
          if (end) end.prepend(marker);
        }
        const title = block.lastElementChild;
        let helper;
        if (e.properties.helper || e.properties.helper_html) {
          helper = A.richText.render(e.properties, "helper");
          block.append(helper);
        }
        const widget = A.widget(e, session);
        if (["single_choice", "multiple_choice", "likert", "registration"].includes(e.type)) {
          title.id = "ae-label-" + block.id;
          widget.setAttribute("role", ["multiple_choice","registration"].includes(e.type) ? "group" : "radiogroup");
          widget.setAttribute("aria-labelledby", title.id);
          if (helper) {
            helper.id = "ae-helper-" + block.id;
            widget.setAttribute("aria-describedby", helper.id);
          }
        }
        block.append(widget);
        if (e.properties.footer_html)
          block.append(A.richText.render(e.properties, "footer"));
      }
      parent.append(block);
    });
  };
  /** DOM-only association of errors already returned by the current validator. */
  A.presentValidationErrors = function (container, errors) {
    const old = [...container.querySelectorAll("[data-ae-validation-error]")];
    const ids = new Set(old.map(node => node.id));
    container.querySelectorAll("[data-ae-validation-control]").forEach(control => {
      const references = (control.getAttribute("aria-describedby") || "").split(/\s+/).filter(id => id && !ids.has(id));
      if (references.length) control.setAttribute("aria-describedby", references.join(" "));
      else control.removeAttribute("aria-describedby");
      control.removeAttribute("aria-invalid");
      control.removeAttribute("data-ae-validation-control");
    });
    old.forEach(node => node.remove());
    container.querySelectorAll(".ae-question-invalid").forEach(node => node.classList.remove("ae-question-invalid"));
    const sources = [...container.querySelectorAll("[data-ae-question-id],[data-row-id]")];
    for (const error of errors) {
      const source = sources.find(node => (node.dataset.rowId || node.dataset.aeQuestionId) === error.id);
      if (!source) continue;
      const card = source.closest(".ae-preview-question");
      if (card) card.classList.add("ae-question-invalid");
      const id = "ae-error-" + source.id;
      let message = source.querySelector('[data-ae-validation-error]');
      if (!message) {
        message = A.el("p", {id, class: "ae-field-error", "data-ae-validation-error": "", "aria-live": "off"});
        (source.matches("tr") ? source.querySelector("th") : source).append(message);
      }
      message.textContent += (message.textContent ? " " : "") + error.message;
      source.querySelectorAll('input:not([data-ae-calendar-year]),textarea,select,[role="group"],[role="radiogroup"]').forEach(control => {
        control.setAttribute("aria-invalid", "true");
        const references = new Set((control.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean));
        references.add(id);
        control.setAttribute("aria-describedby", [...references].join(" "));
        control.setAttribute("data-ae-validation-control", "");
      });
    }
  };
  /** No access lookup, network, partial or final persistence. An authenticated host owns these. */
  A.mountParticipant = function (
    host,
    definition,
    { onChange = () => {}, answers = {}, touched = {}, registrationSource = "" } = {},
  ) {
    const session = new A.PreviewSession(definition);
    session.registrationSource=registrationSource;
    session.answers = A.copy(answers);
    session.touched = A.copy(touched);
    const render = () => {
      host.replaceChildren();
      A.renderParticipantElements(
        session.pages[session.page],
        host,
        session,
        "native",
      );
    };
    const set = session.set.bind(session);
    session.set = (e, v, t = true) => {
      set(e, v, t);
      onChange({
        item_id: session.metadata.items.get(e.response_key)?.id,
        response_key: e.response_key,
        value: v,
        touched: t,
      });
    };
    render();
    return {
      session,
      validate: () => session.errors(),
      values: () => session.values(),
      next: () => {
        const okay = session.next();
        if (okay && !session.finished) render();
        return okay;
      },
      previous: () => {
        session.previous();
        render();
      },
      destroy: () => {
        host.replaceChildren();
        session.answers = {};
        session.touched = {};
      },
    };
  };
})(typeof window !== "undefined" ? window : globalThis);
