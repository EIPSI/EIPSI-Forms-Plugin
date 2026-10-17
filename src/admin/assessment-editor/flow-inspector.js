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
    host.append(el("h3", {}, "Página"));
    field(host, page, "title", "Título de página", onchange);
    rich(host, page, "description_html", "Descripción de página", onchange);
  };
  A.completionInspector = function (c, host, onchange) {
    host.append(el("h3", {}, "Pantalla final"));
  };
  A.flowInspector = function (d, host, onchange) {
    root.EIPSIAssessmentFlow.normalize(d);
    const f = d.flow;
    host.append(el("h3", {}, "Configuración"));
    const texts={...root.EIPSIAssessmentFlow.messages,...f.messages};
    const updateMessages=()=>{f.messages={...texts};onchange();};
    const messages=el("details",{class:"ae-property-section"});messages.append(el("summary",{},"Mensajes de la evaluación"));
    field(messages,texts,"completed","Respuestas recibidas",updateMessages);
    field(messages,texts,"invalid","Error de respuestas",updateMessages);
    field(messages,texts,"delivery_error","Error de envío",updateMessages);

    const titlePolicy = {show_title: f.show_title !== false};
    field(host, titlePolicy, "show_title", "Mostrar título de la evaluación", () => {
      f.show_title = titlePolicy.show_title;
      onchange();
    }, "checkbox");
    const discardPolicy = {allow_discard: f.allow_discard === true};
    const discardFields=el("div",{class:"ae-dependent-fields"});
    discardFields.hidden=!discardPolicy.allow_discard;
    field(host, discardPolicy, "allow_discard", "Permitir descartar respuesta", () => {
      f.allow_discard = discardPolicy.allow_discard;
      discardFields.hidden=!f.allow_discard;
      onchange();
    }, "checkbox");
    host.append(discardFields);
    field(discardFields,texts,"discard_label","Botón descartar",updateMessages);
    field(discardFields,texts,"discard_confirm","Confirmación de descarte",updateMessages);
    field(discardFields,texts,"discard_accept","Botón confirmar descarte",updateMessages);
    field(discardFields,texts,"discard_cancel","Botón cancelar descarte",updateMessages);
    field(discardFields,texts,"discarded","Respuesta descartada",updateMessages);
    const backFields=el("div",{class:"ae-dependent-fields"});
    backFields.hidden=!f.allow_back;
    field(host, f, "allow_back", "Permitir volver atrás", ()=>{backFields.hidden=!f.allow_back;onchange();}, "checkbox");
    host.append(backFields);
    for (const [key, label] of [
      ["back", "Texto del botón Atrás"],
      ["next", "Texto del botón Siguiente"],
      ["finish", "Texto del botón Terminar"],
    ]) {
      const input = field(
        key === "back" ? backFields : host,
        f.navigation_labels,
        key,
        label,
        onchange,
      );
      input.placeholder = {back: "Anterior", next: "Siguiente", finish: "Terminar"}[key];
    }

    const progressFields=el("div",{class:"ae-dependent-fields"});
    progressFields.hidden=f.progress==="none";
    const progressText=el("div");progressFields.append(progressText);
    field(host, f, "progress", "Mostrar progreso", ()=>{progressFields.hidden=f.progress==="none";progressText.hidden=!["pages","both"].includes(f.progress);onchange();}, "text", [
      ["none", "No"],
      ["bar", "Barra"],
      ["pages", "Página X de Y"],
      ["both", "Barra + páginas"],
    ]);
    host.append(progressFields);
    field(progressText,texts,"progress","Texto de progreso — [ACTUAL] y [TOTAL]",updateMessages);
    progressText.hidden=!["pages","both"].includes(f.progress);
    field(progressFields,texts,"progress_accessible","Etiqueta accesible del progreso",updateMessages);
    A.designInspector(d, host, onchange, field);
    host.append(el("h3", {}, "Sesión del cuestionario y guardado parcial"));
    const enabled = field(
        host,
        f.save_continue,
        "enabled",
        "Guardar respuestas parciales cuando venza la sesión",
        onchange,
        "checkbox",
      ),
      window = el("div", {class: "ae-session-window-row"});
    host.append(el("p", {}, "Mientras la sesión siga vigente, se puede volver con las respuestas en curso aunque el guardado parcial esté desactivado. Al vencer, se empieza desde cero."), window);
    window.append(el("p", {class: "ae-session-window-title"}, "Conservar la sesión al salir durante"));
    const number = field(
      window,
      f.save_continue.window,
      "value",
      "Duración",
      onchange,
      "number",
    );
    number.parentElement.querySelector("span").remove();
    number.setAttribute("aria-label", "Conservar la sesión al salir durante");
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
      "Unidad",
      onchange,
      "text",
      [
        ["minutes", "Minutos"],
        ["hours", "Horas"],
        ["days", "Días"],
        ["weeks", "Semanas"],
        ["months", "Meses (30 días)"],
      ],
    );
    unit.parentElement.querySelector("span").remove();
    unit.setAttribute("aria-label", "Unidad de duración de la sesión");
    window.hidden = false;
    const change = enabled.onchange;
    enabled.onchange = () => {
      change();
      window.hidden = false;
    };
    field(host,texts,"expired","Sesión vencida",updateMessages);
    field(host,texts,"expired_saved","Sesión vencida con parcial guardado",updateMessages);
    field(host,texts,"processing","Procesando",updateMessages);
    field(host,texts,"updated","Sesión actualizada",updateMessages);
    host.append(el("h3", {}, "Metadata del proceso"));
    for (const [value, title, description] of [
      [
        "basic",
        "Basic",
        "Registra los tiempos esenciales de la evaluación, las páginas y las respuestas, incluyendo cuándo se presentan y cuándo se responden.",
      ],
      [
        "standard",
        "Standard",
        "Agrega interacciones, cambios de respuesta, foco, revisitas, navegación, dispositivo, navegador, viewport y método de entrada cuando puede detectarse.",
      ],
      [
        "detailed",
        "Detailed",
        "Además registra la secuencia de cambios e interacciones compatibles para estudiar el proceso de respuesta. En campos de texto no almacena cada tecla ni el contenido intermedio.",
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
