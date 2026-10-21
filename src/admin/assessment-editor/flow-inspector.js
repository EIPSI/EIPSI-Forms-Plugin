(function (root) {
  "use strict";
  const A = root.EIPSIAssessment,
    el = A.el;
  function field(
    host,
    object,
    key,
    label,
    onchange,
    type = "text",
    choices = null,
  ) {
    const row = el("label", { class: "ae-property" }),
      input = el(choices ? "select" : "input", choices ? {} : { type });
    input.setAttribute("aria-label", label);
    input.dataset.flowProperty = key;
    if (choices)
      choices.forEach(([value, text]) =>
        input.append(el("option", { value }, text)),
      );
    if (type === "checkbox") input.checked = object[key];
    else input.value = object[key] ?? "";
    input.onchange = () => {
      object[key] =
        type === "checkbox"
          ? input.checked
          : type === "number"
          ? Number(input.value)
          : input.value;
      onchange();
    };
    row.append(el("span", {}, label), input);
    host.append(row);
    return input;
  }
  function rich(host, object, key, label, onchange) {
    const draft = { label: "", label_html: object[key] };
    host.append(
      A.richText.editor(draft, "label", label, () => {
        object[key] = draft.label_html ?? A.richText.escaped(draft.label);
        onchange();
      }),
    );
  }
  A.pageInspector = function (page, host, onchange) {
    host.append(el("h3", {}, root.EIPSIAdminI18n.t("Page")));
    field(host, page, "title", root.EIPSIAdminI18n.t("Page title"), onchange);
    rich(host, page, "description_html", root.EIPSIAdminI18n.t("Page description"), onchange);
  };
  A.completionInspector = function (c, host, onchange) {
    host.append(el("h3", {}, root.EIPSIAdminI18n.t("Completion screen")));
  };
  A.flowInspector = function (d, host, onchange) {
    root.EIPSIAssessmentFlow.normalize(d);
    const f = d.flow;
    host.append(el("h3", {}, root.EIPSIAdminI18n.t("Configuration")));
    const texts={...root.EIPSIContent.get('flow_messages',root.EIPSIContent.language(d)),...f.messages};
    const updateMessages=()=>{f.messages={...texts};onchange();};
    const messages=el("details",{class:"ae-property-section"});messages.append(el("summary",{},root.EIPSIAdminI18n.t("Assessment messages")));
    field(messages,texts,"completed",root.EIPSIAdminI18n.t("Responses received"),updateMessages);
    field(messages,texts,"invalid",root.EIPSIAdminI18n.t("Response error"),updateMessages);
    field(messages,texts,"delivery_error",root.EIPSIAdminI18n.t("Delivery error"),updateMessages);

    const titlePolicy = {show_title: f.show_title !== false};
    field(host, titlePolicy, "show_title", root.EIPSIAdminI18n.t("Show assessment title"), () => {
      f.show_title = titlePolicy.show_title;
      onchange();
    }, "checkbox");
    const discardPolicy = {allow_discard: f.allow_discard === true};
    const discardFields=el("div",{class:"ae-dependent-fields"});
    discardFields.hidden=!discardPolicy.allow_discard;
    field(host, discardPolicy, "allow_discard", root.EIPSIAdminI18n.t("Allow discarding responses"), () => {
      f.allow_discard = discardPolicy.allow_discard;
      discardFields.hidden=!f.allow_discard;
      onchange();
    }, "checkbox");
    host.append(discardFields);
    field(discardFields,texts,"discard_label",root.EIPSIAdminI18n.t("Discard button"),updateMessages);
    field(discardFields,texts,"discard_confirm",root.EIPSIAdminI18n.t("Discard confirmation"),updateMessages);
    field(discardFields,texts,"discard_accept",root.EIPSIAdminI18n.t("Confirm discard button"),updateMessages);
    field(discardFields,texts,"discard_cancel",root.EIPSIAdminI18n.t("Cancel discard button"),updateMessages);
    field(discardFields,texts,"discarded",root.EIPSIAdminI18n.t("Response discarded"),updateMessages);
    const backFields=el("div",{class:"ae-dependent-fields"});
    backFields.hidden=!f.allow_back;
    field(host, f, "allow_back", root.EIPSIAdminI18n.t("Allow going back"), ()=>{backFields.hidden=!f.allow_back;onchange();}, "checkbox");
    host.append(backFields);
    for (const [key, label] of [
      ["back", root.EIPSIAdminI18n.t("Back button text")],
      ["next", root.EIPSIAdminI18n.t("Next button text")],
      ["finish", root.EIPSIAdminI18n.t("Finish button text")],
    ]) {
      const input = field(
        key === "back" ? backFields : host,
        f.navigation_labels,
        key,
        label,
        onchange,
      );
      input.placeholder = root.EIPSIContent.get('flow',root.EIPSIContent.language(d)).navigation_labels[key];
    }

    const progressFields=el("div",{class:"ae-dependent-fields"});
    progressFields.hidden=f.progress==="none";
    const progressText=el("div");progressFields.append(progressText);
    field(host, f, "progress", root.EIPSIAdminI18n.t("Show progress"), ()=>{progressFields.hidden=f.progress==="none";progressText.hidden=!["pages","both"].includes(f.progress);onchange();}, "text", [
      ["none", root.EIPSIAdminI18n.t("No")],
      ["bar", root.EIPSIAdminI18n.t("Bar")],
      ["pages", root.EIPSIAdminI18n.t("Page X of Y")],
      ["both", root.EIPSIAdminI18n.t("Bar and pages")],
    ]);
    host.append(progressFields);
    field(progressText,texts,"progress",root.EIPSIAdminI18n.t("Progress text — [ACTUAL] and [TOTAL]"),updateMessages);
    progressText.hidden=!["pages","both"].includes(f.progress);
    field(progressFields,texts,"progress_accessible",root.EIPSIAdminI18n.t("Accessible progress label"),updateMessages);
    A.designInspector(d, host, onchange, field);
    host.append(el("h3", {}, root.EIPSIAdminI18n.t("Questionnaire session and partial saving")));
    const enabled = field(
        host,
        f.save_continue,
        "enabled",
        root.EIPSIAdminI18n.t("Save partial responses when the session expires"),
        onchange,
        "checkbox",
      ),
      window = el("div", {class: "ae-session-window-row"});
    host.append(el("p", {}, root.EIPSIAdminI18n.t("While the session is valid, participants can return to their current responses even when partial saving is disabled. Once it expires, they start again.")), window);
    window.append(el("p", {class: "ae-session-window-title"}, root.EIPSIAdminI18n.t("Keep the session after leaving for")));
    const number = field(
      window,
      f.save_continue.window,
      "value",
      root.EIPSIAdminI18n.t("Duration"),
      onchange,
      "number",
    );
    number.parentElement.querySelector("span").remove();
    number.setAttribute("aria-label", root.EIPSIAdminI18n.t("Keep the session after leaving for"));
    number.min = 1;
    number.step = 1;
    number.max = 9999;
    number.inputMode = "numeric";
    number.addEventListener("input", () => {
      if (/^\d{5,}$/.test(number.value)) number.value = number.value.slice(0, 4);
    });
    const numberChange = number.onchange;
    number.onchange = () => {if (number.checkValidity()) numberChange(); else number.reportValidity();};
    const unit = field(
      window,
      f.save_continue.window,
      "unit",
      root.EIPSIAdminI18n.t("Unit"),
      onchange,
      "text",
      [
        ["minutes", root.EIPSIAdminI18n.t("Minutes")],
        ["hours", root.EIPSIAdminI18n.t("Hours")],
        ["days", root.EIPSIAdminI18n.t("Days")],
        ["weeks", root.EIPSIAdminI18n.t("Weeks")],
        ["months", root.EIPSIAdminI18n.t("Months (30 days)")],
      ],
    );
    unit.parentElement.querySelector("span").remove();
    unit.setAttribute("aria-label", root.EIPSIAdminI18n.t("Session duration unit"));
    window.hidden = false;
    const change = enabled.onchange;
    enabled.onchange = () => {
      change();
      window.hidden = false;
    };
    field(host,texts,"expired",root.EIPSIAdminI18n.t("Session expired"),updateMessages);
    field(host,texts,"expired_saved",root.EIPSIAdminI18n.t("Session expired with saved partial responses"),updateMessages);
    field(host,texts,"processing",root.EIPSIAdminI18n.t("Processing"),updateMessages);
    field(host,texts,"updated",root.EIPSIAdminI18n.t("Session updated"),updateMessages);
    host.append(el("h3", {}, root.EIPSIAdminI18n.t("Process metadata")));
    for (const [value, title, description] of [
      [
        "basic",
        "Basic",
        root.EIPSIAdminI18n.t("Records essential assessment, page and response timing, including presentation and response times."),
      ],
      [
        "standard",
        "Standard",
        root.EIPSIAdminI18n.t("Adds interactions, response changes, focus, revisits, navigation, device, browser, viewport and input method when detectable."),
      ],
      [
        "detailed",
        "Detailed",
        root.EIPSIAdminI18n.t("Also records supported change and interaction sequences to study the response process. Text fields do not store individual keystrokes or intermediate content."),
      ],
    ]) {
      const card = el("label", { class: "ae-metadata-profile" }),
        radio = el("input", {
          type: "radio",
          name: "ae-metadata-profile",
          value,
          checked: f.metadata_profile === value,
          "aria-label": title,
        });
      radio.onchange = () => {
        if (radio.checked) {
          f.metadata_profile = value;
          onchange();
        }
      };
      card.append(radio, el("strong", {}, title), el("p", {}, description));
      host.append(card);
    }
    host.append(messages);
  };
})(window);
