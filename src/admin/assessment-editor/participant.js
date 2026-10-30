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
        if (e.properties.helper || e.properties.helper_html)
          block.append(A.richText.render(e.properties, "helper"));
        block.append(A.widget(e, session));
        if (e.properties.footer_html)
          block.append(A.richText.render(e.properties, "footer"));
      }
      parent.append(block);
    });
  };
  /** No access lookup, network, partial or final persistence. An authenticated host owns these. */
  A.mountParticipant = function (
    host,
    definition,
    { onChange = () => {}, answers = {}, touched = {} } = {},
  ) {
    const session = new A.PreviewSession(definition);
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
