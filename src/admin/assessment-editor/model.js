(function (root) {
  "use strict";
  const C = root.EIPSIAssessmentControls,
    V = root.EIPSIAssessmentVariables;
  const pending = new WeakSet();
  const questions = C.questions;
  const types = [...questions, "instruction", "image", "group", "randomization", "page_break"];
  const labels = C.labels;
  const copy = (v) => JSON.parse(JSON.stringify(v));
  function id(prefix) {
    const crypto = root.crypto;
    if (!crypto || !crypto.getRandomValues)
      throw Error(
        "Se requiere un navegador con generación segura de identidad.",
      );
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    return (
      prefix +
      "_" +
      Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("")
    );
  }
  function option(label) {
    return { id: id("O"), label };
  }
  function element(type, d = null) {
    if (!d || typeof d !== "object" || !Array.isArray(d.elements)) d = null;
    if (!types.includes(type)) throw Error("Tipo desconocido");
    const lang=root.EIPSIContent.language(d),content=root.EIPSIContent.get('authoring',lang),endpoints=root.EIPSIContent.get('vas_endpoints',lang);
    const p = { label: content.labels[type] };
    if (questions.includes(type))
      Object.assign(p, {
        label: content.question,
        helper: "",
        required: false,
      });
    if (["text", "textarea"].includes(type))
      Object.assign(p, { placeholder: "", max_length: 2000 });
    if (["single_choice", "multiple_choice", "likert"].includes(type))
      p.presentation = type === "likert" ? "horizontal" : "vertical";
    if (type === "vas")
      Object.assign(p, {
        min: 0,
        max: 100,
        step: 1,
        initial: 50,
        left_label: endpoints[0],
        right_label: endpoints[1],
        show_value: true,
      });
    if (type === "randomization") Object.assign(p, {mode: "all", count: 1, order: "random", algorithm:"selection_uniform_order"});
    if (type === "instruction")
      p.label = content.instruction;
    if (type === "image") Object.assign(p, { alt: "", caption: "" });
    const node = {
      id: id("EL"),
      type,
      response_key: questions.includes(type) ? id("R") : null,
      properties: { ...C.defaults(type, p), ...(d?.content_language?root.EIPSIContent.question(type,lang):{}), ...p },
      options: [
        "single_choice",
        "multiple_choice",
        "select",
        "likert",
        "matrix",
      ].includes(type)
        ? (["likert", "matrix"].includes(type)
            ? content.likert
            : content.option
          ).map(option)
        : [],
      children: [],
    };
    if (["likert", "matrix"].includes(type))
      node.options.forEach((o, i) => (o.value = node.properties.min + i));
    if (node.response_key)
      node.properties.variable_name = V.prefix(type) + "_1";
    if (type === "vas")
      node.properties.label_settings = node.properties.labels.map(() => ({
        id: id("L"),
        position: null,
      }));
    if (type === "matrix")
      node.properties.matrix_rows = content.rows.map(label=>row(label));
    if (node.response_key) {
      if (d) V.allocate(d, node);
      else pending.add(node);
    }
    if (type === "matrix")
      node.properties.matrix_rows.forEach(
        (r, i) =>
          (r.variable_name = node.properties.variable_name + "_" + (i + 1)),
      );
    return node;
  }
  function row(label = "Nuevo item") {
    const key = id("R");
    return {
      id: id("ROW"),
      response_key: key,
      variable_name: "",
      label,
      label_html: null,
      helper_html: null,
      footer_html: "",
      required: false,
    };
  }
  function assessment(language = root.EIPSIAdminI18n?.language??'en') {
    root.EIPSIContent.valid(language);
    return root.EIPSIAssessmentFlow.normalize({
      contract: "EIPSI_ASSESSMENT_V1",
      id: id("AE"),
      title: root.EIPSIContent.get("authoring",language).title,
      content_language: language,
      elements: [],
      authoring: V.metadata(),
      flow: root.EIPSIAssessmentFlow.defaults(language),
    });
  }
  function find(d, target, parent = null) {
    const items = parent ? parent.children : d.elements;
    for (let index = 0; index < items.length; index++) {
      const node = items[index];
      if (node.id === target) return { node, items, index, parent };
      const child = find({ elements: node.children }, target, node);
      if (child) return child;
    }
    return null;
  }
  function walk(items, fn) {
    items.forEach((e) => {
      fn(e);
      walk(e.children, fn);
    });
  }
  function destination(d, parent) {
    if (!parent) return d.elements;
    const f = find(d, parent);
    if (!f || !["group", "randomization"].includes(f.node.type)) throw Error("Grupo no disponible");
    return f.node.children;
  }
  function insert(d, e, parent = null, index = null) {
    const items = destination(d, parent);
    if (e.type === "page_break" && parent)
      throw Error("Los saltos de página se insertan fuera de los grupos.");
    const extra = new Set();
    walk([e], (node) => {
      if (pending.has(node)) {
        if (node.properties.variable_name === V.prefix(node.type) + "_1")
          V.allocate(d, node, extra);
        pending.delete(node);
      }
    });
    items.splice(index === null ? items.length : index, 0, e);
    return e;
  }
  function importQuestion(d, source, parent = null, index = null) {
    if (!questions.includes(source.type) || source.children.length) throw Error("El archivo no contiene una pregunta.");
    let count = 0;const names = new Set();
    walk(d.elements, e => {
      count++;
      if (e.response_key) names.add(e.properties.variable_name);
      if (e.type === "matrix") e.properties.matrix_rows.forEach(r => names.add(r.variable_name));
    });
    if (count >= 300) throw Error("La evaluación admite hasta 300 elementos.");
    const question = copy(source), name = question.properties.variable_name;
    if (names.has(name) || (question.type === "matrix" && question.properties.matrix_rows.some(r => names.has(r.variable_name)))) V.allocate(d, question);
    insert(d, question, parent, index);
    return question;
  }
  function move(d, target, parent, index) {
    const f = find(d, target);
    if (!f) throw Error("Elemento no disponible");
    let cycle = target === parent;
    walk(f.node.children, (e) => {
      if (e.id === parent) cycle = true;
    });
    if (cycle) throw Error("Un grupo no puede contenerse a sí mismo.");
    const dest = destination(d, parent);
    if (f.node.type === "page_break" && parent)
      throw Error("Los saltos de página se insertan fuera de los grupos.");
    if (dest === f.items && index > f.index) index--;
    f.items.splice(f.index, 1);
    dest.splice(Math.max(0, Math.min(index, dest.length)), 0, f.node);
  }
  function cloneIdentity(e, d = null) {
    d ??= { ...assessment(), elements: [e] };
    const extra = new Set();
    const n = copy(e);
    walk([n], (v) => {
      v.id = id("EL");
      if (v.response_key) {
        v.response_key = id("R");
      }
      if (v.type === "vas")
        v.properties.label_settings = v.properties.labels.map((_, i) => ({
          id: id("L"),
          position: v.properties.label_settings?.[i]?.position ?? null,
        }));
      if (v.type === "matrix")
        v.properties.matrix_rows.forEach((r) => {
          r.id = id("ROW");
          r.response_key = id("R");
        });
      v.options.forEach((o) => (o.id = id("O")));
      if (v.response_key) V.allocate(d, v, extra);
    });
    return n;
  }
  function duplicate(d, target) {
    const f = find(d, target);
    const e = cloneIdentity(f.node, d);
    f.items.splice(f.index + 1, 0, e);
    return e;
  }
  function remove(d, target) {
    const f = find(d, target);
    if (f) f.items.splice(f.index, 1);
  }
  function pages(d) {
    const result = [[]];
    d.elements.forEach((e) => {
      if (e.type === "page_break") result.push([]);
      else result[result.length - 1].push(e);
    });
    return result;
  }
  function normalize(v) {
    if (Array.isArray(v)) return v.map(normalize);
    if (v && typeof v === "object")
      return Object.fromEntries(
        Object.keys(v)
          .sort()
          .map((k) => [k, normalize(v[k])]),
      );
    return v;
  }
  const serialize = (d) => JSON.stringify(normalize(d));
  class PreviewSession {
    constructor(d, randomization = null) {
      this.definition = C.normalize(d);
      this.randomization = randomization || root.EIPSIAssessmentRandomization.resolve(this.definition, id("SEED"));
      this.resolvedDefinition = root.EIPSIAssessmentRandomization.apply(this.definition, this.randomization);
      this.pages = pages(this.resolvedDefinition);
      this.page = 0;
      this.answers = {};
      this.touched = {};
      this.metadata = new root.EIPSIAssessment.MetadataRecorder(
        this.definition,
      );
      if (root.EIPSIAssessmentRandomization.hasState(this.randomization)) this.metadata.data.randomization = copy(this.randomization);
      this.metadata.present(this.definition.flow.pages[0].id, this.pages[0]);
      this.finished = false;
    }
    set(e, value, touched = true) {
      if (e.type === "vas" && !touched) return;
      const previous = this.answers[e.response_key];
      this.answers[e.response_key] = value;
      this.touched[e.response_key] = touched;
      this.metadata.interact(e, value, this.inputMethod || "unknown", previous);
    }
    errors(page = this.page) {
      const errors = [];
      walk(this.pages[page], (e) => {
        if (e.type === "matrix") {
          e.properties.matrix_rows.forEach((r) => {
            const row = {
              ...e,
              id: r.id,
              type: "likert",
              response_key: r.response_key,
              properties: {
                ...e.properties,
                required: e.properties.required || r.required,
              },
            };
            const message = C.answerError(
              row,
              this.answers[r.response_key],
              true,
              root.EIPSIContent.language(this.definition),
            );
            if (message) errors.push({ id: r.id, message });
          });
          return;
        }
        if (!e.response_key) return;
        const value = this.answers[e.response_key];
        const message = C.answerError(e, value, this.touched[e.response_key],root.EIPSIContent.language(this.definition));
        if (message) errors.push({ id: e.id, message });
      });
      return errors;
    }
    values() {
      const values = {};
      walk(this.definition.elements, (e) => {
        if (e.type === "matrix")
          e.properties.matrix_rows.forEach((r) => {
            if (
              Object.prototype.hasOwnProperty.call(this.answers, r.response_key)
            )
              values[r.response_key] = C.answerValue(
                { ...e, type: "likert" },
                this.answers[r.response_key],
              );
          });
        if (Object.prototype.hasOwnProperty.call(this.answers, e.response_key))
          values[e.response_key] = C.answerValue(
            e,
            this.answers[e.response_key],
          );
      });
      return values;
    }
    next() {
      if (this.errors().length) return false;
      if (this.page < this.pages.length - 1) {
        this.metadata.leave("next");
        this.page++;
        this.metadata.present(
          this.definition.flow.pages[this.page].id,
          this.pages[this.page],
        );
      } else {
        for (let i = 0; i < this.pages.length; i++)
          if (this.errors(i).length) {
            this.page = i;
            return false;
          }
        this.finished = true;
        this.metadata.complete();
      }
      return true;
    }
    previous() {
      if (!this.definition.flow.allow_back) return false;
      this.metadata.leave("previous");
      this.page = Math.max(0, this.page - 1);
      this.metadata.present(
        this.definition.flow.pages[this.page].id,
        this.pages[this.page],
      );
      this.finished = false;
    }
  }
  root.EIPSIAssessment = {
    controls: C,
    variables: V,
    refreshDerived: V.derive,
    importQuestion,
    normalizeDefinition: C.normalize,
    types,
    questions,
    labels,
    copy,
    id,
    option,
    row,
    element,
    assessment,
    find,
    walk,
    insert,
    move,
    duplicate,
    cloneIdentity,
    remove,
    pages,
    serialize,
    PreviewSession,
  };
  if (typeof module !== "undefined") module.exports = root.EIPSIAssessment;
})(typeof window !== "undefined" ? window : globalThis);
