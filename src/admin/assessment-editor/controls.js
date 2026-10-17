(function (root) {
  "use strict";
  const presets = {
    "likert5-agreement": {
      label: "Escala de Acuerdo (5 puntos)",
      min: 1,
      labels: [
        "Totalmente en desacuerdo",
        "En desacuerdo",
        "Neutral",
        "De acuerdo",
        "Totalmente de acuerdo",
      ],
    },
    "likert5-satisfaction": {
      label: "Escala de Satisfacción (5 puntos)",
      min: 1,
      labels: [
        "Muy insatisfecho",
        "Insatisfecho",
        "Neutral",
        "Satisfecho",
        "Muy satisfecho",
      ],
    },
    "likert5-frequency": {
      label: "Escala de Frecuencia (5 puntos)",
      min: 1,
      labels: ["Nunca", "Raramente", "A veces", "Frecuentemente", "Siempre"],
    },
    "likert7-agreement": {
      label: "Escala de Acuerdo (7 puntos)",
      min: 1,
      labels: [
        "Totalmente en desacuerdo",
        "Muy en desacuerdo",
        "En desacuerdo",
        "Neutral",
        "De acuerdo",
        "Muy de acuerdo",
        "Totalmente de acuerdo",
      ],
    },
    "likert7-satisfaction": {
      label: "Escala de Satisfacción (7 puntos)",
      min: 1,
      labels: [
        "Muy insatisfecho",
        "Bastante insatisfecho",
        "Algo insatisfecho",
        "Neutral",
        "Algo satisfecho",
        "Bastante satisfecho",
        "Muy satisfecho",
      ],
    },
    "likert4-agreement": {
      label: "Escala de Acuerdo (4 puntos)",
      min: 1,
      labels: [
        "Muy en desacuerdo",
        "En desacuerdo",
        "De acuerdo",
        "Muy de acuerdo",
      ],
    },
  };
  const textTypes = [
    "text",
    "textarea",
    "email",
    "number",
    "tel",
    "date",
    "url",
  ];
  const choiceTypes = ["single_choice", "multiple_choice", "select", "likert"];
  const questions = [...textTypes, ...choiceTypes, "vas", "matrix"];
  const labels = {
    text: "Texto",
    textarea: "Texto largo",
    email: "Email",
    number: "Número",
    tel: "Teléfono",
    date: "Fecha",
    url: "URL",
    single_choice: "Opción única",
    multiple_choice: "Opción múltiple",
    select: "Lista desplegable",
    likert: "Likert",
    vas: "VAS",
    matrix: "Matriz Likert",
    instruction: "Texto / instrucciones",
    image: "Imagen",
    group: "Grupo / sección",
    randomization: "Randomización",
    page_break: "Salto de página",
  };
  function defaults(type, p = {}) {
    const out = {};
    if (questions.includes(type))
      Object.assign(out, {
        label_html: null,
        helper_html: null,
        footer_html: "",
      });
    if (["text", "textarea", "email", "tel", "url"].includes(type))
      Object.assign(out, {
        content_type: type === "text" || type === "textarea" ? "free" : type,
        display_mode: type === "textarea" ? "multiline" : "single",
      });
    if (type === "number") out.integer_only = false;
    if (["single_choice", "multiple_choice"].includes(type)) out.columns = 1;
    if (type === "matrix")
      Object.assign(out, {
        matrix_kind: "likert",
        matrix_rows: [],
        presentation: "horizontal",
      });
    if (textTypes.includes(type) && !["date", "number"].includes(type))
      Object.assign(out, { placeholder: "", max_length: 2000, min_length: 0 });
    if (type === "number") out.placeholder = "";
    if (type === "textarea") out.rows = 4;
    if (type === "number")
      Object.assign(out, { min: null, max: null, step: 1 });
    if (type === "select") out.placeholder = "Seleccioná una opción";
    if (type === "multiple_choice")
      Object.assign(out, { min_selected: 0, max_selected: null });
    if (["likert", "matrix"].includes(type))
      Object.assign(out, { min: 1, preset: "custom", reversed: false });
    if (["likert", "matrix", "vas"].includes(type))
      Object.assign(out, {
        gradient: "none",
        gradient_start: "#f44336",
        gradient_end: "#4caf50",
      });
    if (type === "vas")
      Object.assign(out, {
        labels: [p.left_label ?? "Nada", p.right_label ?? "Mucho"],
        label_font_size: 16,
        label_scale:
          (p.label_font_size ?? 16) <= 14
            ? "small"
            : (p.label_font_size ?? 16) <= 24
            ? "normal"
            : "large",
        value_font_size: 36,
        bold_labels: true,
        label_boxes: false,
        label_alignment: 100,
        value_position: "below",
      });
    if (type === "image") out.src = "";
    if (type === "instruction") out.label_html = null;
    return out;
  }
  function normalize(d) {
    const out = JSON.parse(JSON.stringify(d));
    function visit(items) {
      items.forEach((e) => {
        e.properties = { ...defaults(e.type, e.properties), ...e.properties };

        if (e.type === "vas")
          e.properties.label_settings ??= e.properties.labels.map((_, i) => ({
            id: "L_" + e.id.slice(0, 70) + "_" + i,
            position: null,
          }));
        if (e.type === "matrix")
          e.properties.matrix_rows.forEach((r) => {
            r.label_html ??= null;
            r.helper_html ??= null;
            r.footer_html ??= "";
            r.required ??= false;
          });
        if (["likert", "matrix"].includes(e.type))
          e.options.forEach((o, i) => {
            if (!Object.prototype.hasOwnProperty.call(o, "value"))
              o.value = e.properties.min + i;
          });
        visit(e.children);
      });
    }
    visit(out.elements);
    return root.EIPSIAssessmentFlow.normalize(
      root.EIPSIAssessmentVariables.normalize(out),
    );
  }
  function numeric(value, min, max, step, base = 0) {
    return (
      typeof value === "number" &&
      Number.isFinite(value) &&
      (min === null || value >= min) &&
      (max === null || value <= max) &&
      Math.abs((value - base) / step - Math.round((value - base) / step)) <=
        1e-7
    );
  }
  function answerError(e,value,touched) {
    const message=defaultAnswerError(e,value,touched);if(!message)return '';
    const p={...defaults(e.type,e.properties),...e.properties};
    if(e.type==='multiple_choice'&&value==null)value=[];
    if(e.type==='multiple_choice'&&Array.isArray(value)&&new Set(value).size===value.length&&value.every(v=>e.options.some(o=>o.id===v))){
      const minimum=value.length<p.min_selected,maximum=p.max_selected!==null&&value.length>p.max_selected;
      if(minimum||maximum){const key=minimum?'selection_min_message':'selection_max_message',fallback=minimum?'Elegí al menos [MINIMO] opciones.':'Elegí como máximo [MAXIMO] opciones.';return (p[key]?.trim()?p[key]:fallback).replaceAll('[MINIMO]',String(p.min_selected)).replaceAll('[MAXIMO]',String(p.max_selected??''));}
    }
    if(p.min_length!==undefined&&typeof value==='string'&&value.trim()){
      const length=Array.from(value).length;
      if((p.min_length!==null&&length<p.min_length)||(p.max_length!==null&&length>p.max_length))return (p.length_message?.trim()?p.length_message:'La respuesta debe tener entre [MINIMO] - [MAXIMO] caracteres.').replaceAll('[MINIMO]',String(p.min_length??0)).replaceAll('[MAXIMO]',String(p.max_length??'∞'));
    }
    if(e.type==='number'&&typeof value==='number'&&Number.isFinite(value)&&((p.min!==null&&value<p.min)||(p.max!==null&&value>p.max)))return (p.number_range_message?.trim()?p.number_range_message:'La respuesta debe estar entre [MINIMO] - [MAXIMO].').replaceAll('[MINIMO]',String(p.min??'−∞')).replaceAll('[MAXIMO]',String(p.max??'∞'));
    const empty=value==null||value===''||(typeof value==='string'&&!value.trim())||(Array.isArray(value)&&!value.length)||(e.type==='vas'&&!touched);
    const text=['text','textarea','email','tel','url','number'].includes(e.type);
    const custom=empty?p.required_message:text?p.validation_message:p.invalid_value_message;return custom?.trim()?custom:(empty?'Respondé esta pregunta para continuar.':message);
  }
  function defaultAnswerError(e, value, touched) {
    const p = { ...defaults(e.type, e.properties), ...e.properties };
    const empty =
      value === undefined ||
      value === null ||
      value === "" ||
      (Array.isArray(value) && !value.length) ||
      (typeof value === "string" && !value.trim());
    if (e.type === "vas" && !touched)
      return p.required ? "Tocá la escala para responder." : "";
    if (empty) {
      if (p.required || (e.type === "multiple_choice" && p.min_selected > 0))
        return "Respondé esta pregunta para continuar.";
      return "";
    }
    if (textTypes.includes(e.type) && !["number", "date"].includes(e.type)) {
      if (typeof value !== "string") return "Ingresá texto válido.";
      const length = Array.from(value).length;
      if (p.min_length !== null && length < p.min_length)
        return "La respuesta es demasiado corta.";
      if (p.max_length !== null && length > p.max_length)
        return "La respuesta supera la longitud máxima.";
    }
    if (
      (p.content_type || e.type) === "email" &&
      !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
    )
      return "Ingresá un email válido.";
    if ((p.content_type || e.type) === "url") {
      if (/\s/.test(value)) return "Ingresá una URL completa válida.";
      try {
        const u = new URL(value);
        if (!u.hostname || !["http:", "https:", "ftp:"].includes(u.protocol))
          return "Ingresá una URL completa válida.";
      } catch (_) {
        return "Ingresá una URL completa válida.";
      }
    }
    if (p.content_type === "letters" && !/^[\p{L}\p{M}\s]+$/u.test(value))
      return "Usá sólo letras y espacios.";
    if (e.type === "number" && p.integer_only && !Number.isInteger(value))
      return "Ingresá un número entero.";
    if (e.type === "date") {
      if (
        typeof value !== "string" ||
        !/^\d{4}-\d{2}-\d{2}$/.test(value) ||
        value.slice(0, 4) === "0000" ||
        !Number.isFinite(Date.parse(value + "T00:00:00Z")) ||
        new Date(value + "T00:00:00Z").toISOString().slice(0, 10) !== value
      )
        return "Ingresá una fecha válida.";
    }
    if (
      e.type === "number" &&
      !(typeof value === "number" && Number.isFinite(value) && (p.min===null||value>=p.min) && (p.max===null||value<=p.max))
    )
      return "Ingresá un número dentro del rango configurado.";
    if (e.type === "vas" && !numeric(value, p.min, p.max, p.step, p.min))
      return "Elegí un valor válido en la escala.";
    if (
      ["single_choice", "select", "likert"].includes(e.type) &&
      !e.options.some((o) => o.id === value)
    )
      return "Elegí una opción disponible.";
    if (e.type === "multiple_choice") {
      if (
        !Array.isArray(value) ||
        new Set(value).size !== value.length ||
        value.some((v) => !e.options.some((o) => o.id === v))
      )
        return "Elegí opciones disponibles sin repetir.";
      if (value.length < p.min_selected)
        return "Elegí al menos " + p.min_selected + " opciones.";
      if (p.max_selected !== null && value.length > p.max_selected)
        return "Elegí como máximo " + p.max_selected + " opciones.";
    }
    return "";
  }
  function answerValue(e, value) {
    if (["likert", "matrix"].includes(e.type)) {
      const i = e.options.findIndex((o) => o.id === value);
      return i < 0
        ? null
        : Object.prototype.hasOwnProperty.call(e.options[i], "value")
        ? e.options[i].value ?? e.options[i].label
        : (e.properties.min ?? 1) + i;
    }
    return value;
  }
  function gradient(p) {
    const colors = {
      "improvement-right": [
        "#f44336",
        "#ff9800",
        "#ffc107",
        "#8bc34a",
        "#4caf50",
      ],
      "improvement-left": [
        "#4caf50",
        "#8bc34a",
        "#ffc107",
        "#ff9800",
        "#f44336",
      ],
      "progress-neutral": ["#e0e0e0", "#9e9e9e", "#424242"],
      custom: [p.gradient_start, p.gradient_end],
    };
    return p.gradient === "none"
      ? ""
      : `linear-gradient(to right, ${(colors[p.gradient] || []).join(", ")})`;
  }
  // Presentation only: equidistant columns; alignment controls inset, never response values.
  function labelInset(alignment) {
    return (100 - alignment) * 0.2;
  }
  root.EIPSIAssessmentControls = {
    presets,
    textTypes,
    choiceTypes,
    questions,
    labels,
    defaults,
    normalize,
    answerError,
    answerValue,
    gradient,
    labelInset,
  };
})(typeof window !== "undefined" ? window : globalThis);
