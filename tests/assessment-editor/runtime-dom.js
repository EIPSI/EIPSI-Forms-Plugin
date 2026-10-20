"use strict";
const fs = require("fs"),
  path = require("path"),
  assert = require("assert/strict"),
  { JSDOM } = require("jsdom"),
  { webcrypto } = require("crypto");
const root = path.resolve(__dirname, "../.."),
  dom = new JSDOM('<div id="root"></div>', {
    url: "http://localhost/wp-admin/",
    runScripts: "outside-only",
  }),
  w = dom.window,
  doc = w.document;
require("../i18n1b/admin-language.js")(dom.window);
Object.defineProperty(w, "crypto", { value: webcrypto });
w.confirm = () => true;
JSON.parse(
  fs.readFileSync(path.join(root, "src/admin/assessment-editor/manifest.json")),
).entry_order.forEach((f) =>
  w.eval(
    fs.readFileSync(path.join(root, "src/admin/assessment-editor", f), "utf8"),
  ),
);
const A = w.EIPSIAssessment,
  plain = (v) => JSON.parse(JSON.stringify(v));
let tests = 0,
  failures = 0;
async function test(name, fn) {
  tests++;
  try {
    await fn();
    console.log("PASS " + name);
  } catch (e) {
    failures++;
    console.log("FAIL " + name + ": " + e.stack);
  }
}
const click = (within, text) => {
  const b = [...within.querySelectorAll("button")].find(
    (n) => n.textContent === text,
  );
  assert(b, "Button " + text);
  b.click();
};
const input = (n, v, event = "input") => {
  if (n.getAttribute("role") === "textbox") n.textContent = v;
  else n.value = v;
  n.dispatchEvent(new w.Event(event, { bubbles: true }));
};
const tick = () => new Promise((resolve) => setImmediate(resolve));
function drop(n, payload) {
  const event = new w.Event("drop", { bubbles: true, cancelable: true });
  Object.defineProperty(event, "dataTransfer", {
    value: { getData: () => JSON.stringify(payload) },
  });
  n.dispatchEvent(event);
}
(async () => {
  await test("all native types have independent stable identities and valid shape", () => {
    const d = A.assessment();
    A.types.forEach((t) => A.insert(d, A.element(t)));
    const ids = [];
    A.walk(d.elements, (e) => {
      ids.push(e.id, ...e.options.map((o) => o.id));
      assert.equal(Boolean(e.response_key), A.questions.includes(e.type));
    });
    assert.equal(new Set(ids).size, ids.length);
  });
  await test("edit/reorder preserves IDs keys and option identities", () => {
    const d = A.assessment(),
      a = A.element("single_choice"),
      b = A.element("text");
    d.elements = [a, b];
    const identity = [a.id, a.response_key, ...a.options.map((o) => o.id)];
    a.properties.label = "Editada";
    a.options[0].label = "Cambio";
    A.move(d, a.id, null, 2);
    assert.deepEqual(
      [a.id, a.response_key, ...a.options.map((o) => o.id)],
      identity,
    );
    assert.equal(d.elements[1], a);
  });
  await test("move into nested group and out preserves item and order", () => {
    const d = A.assessment(),
      a = A.element("text"),
      g = A.element("group"),
      nested = A.element("group");
    d.elements = [a, g];
    g.children.push(nested);
    A.move(d, a.id, nested.id, 0);
    assert.equal(A.find(d, a.id).parent, nested);
    A.move(d, a.id, null, 1);
    assert.equal(d.elements[1], a);
    assert.equal(nested.children.length, 0);
  });
  await test("cycle and nested PageBreak rejected without mutation", () => {
    const d = A.assessment(),
      g = A.element("group"),
      nested = A.element("group");
    g.children = [nested];
    d.elements = [g];
    const before = A.serialize(d);
    assert.throws(() => A.move(d, g.id, nested.id, 0));
    assert.throws(() => A.insert(d, A.element("page_break"), g.id, 0));
    assert.equal(A.serialize(d), before);
  });
  await test("duplicate recursively renews IDs keys and options while retaining content", () => {
    const d = A.assessment(),
      g = A.element("group"),
      q = A.element("likert");
    g.children = [q];
    d.elements = [g];
    const n = A.duplicate(d, g.id);
    assert.notEqual(n.id, g.id);
    assert.notEqual(n.children[0].response_key, q.response_key);
    assert.notEqual(n.children[0].options[0].id, q.options[0].id);
    assert.notEqual(
      n.children[0].properties.variable_name,
      q.properties.variable_name,
    );
    assert.deepEqual(
      {
        ...plain(n.children[0].properties),
        variable_name: q.properties.variable_name,
      },
      plain(q.properties),
    );
  });
  await test("delete removes subtree without modifying sibling identity", () => {
    const d = A.assessment(),
      g = A.element("group"),
      q = A.element("text");
    g.children = [A.element("vas")];
    d.elements = [g, q];
    A.remove(d, g.id);
    assert.deepEqual(d.elements, [q]);
  });
  await test("PageBreak projection respects consecutive empty pages and order", () => {
    const d = A.assessment(),
      q = A.element("text"),
      q2 = A.element("text");
    d.elements = [q, A.element("page_break"), A.element("page_break"), q2];
    assert.deepEqual(plain(A.pages(d).map((p) => p.length)), [1, 0, 1]);
    assert.equal(A.pages(d)[2][0], q2);
  });
  await test("canonical serialization independent of object key order and lossless", () => {
    const d = A.assessment();
    d.elements = [A.element("vas")];
    const inverted = Object.fromEntries(Object.entries(d).reverse());
    assert.equal(A.serialize(d), A.serialize(inverted));
    assert.equal(A.serialize(JSON.parse(A.serialize(d))), A.serialize(d));
  });
  await test("preview copies definition and discards answers between sessions", () => {
    const d = A.assessment(),
      q = A.element("text");
    d.elements = [q];
    const s = new A.PreviewSession(d);
    s.set(q, "Secret");
    q.properties.label = "Changed";
    assert.notEqual(
      s.definition.elements[0].properties.label,
      q.properties.label,
    );
    assert.equal(Object.keys(new A.PreviewSession(d).answers).length, 0);
  });
  await test("required VAS initial is not an answer; interaction at same value counts", () => {
    const d = A.assessment(),
      q = A.element("vas");
    q.properties.required = true;
    d.elements = [q];
    const s = new A.PreviewSession(d);
    assert.equal(s.errors().length, 1);
    s.set(q, 50, false);
    assert.equal(s.errors().length, 1);
    s.set(q, 50, true);
    assert.equal(s.errors().length, 0);
  });
  await test("preview rejects missing required and off-step/out-of-range answers", () => {
    const d = A.assessment(),
      q = A.element("vas");
    q.properties.step = 2;
    q.properties.required = true;
    d.elements = [q];
    const s = new A.PreviewSession(d);
    s.set(q, 51);
    assert.equal(s.errors().length, 1);
    s.set(q, 102);
    assert.equal(s.errors().length, 1);
    s.set(q, 50);
    assert.equal(s.errors().length, 0);
  });
  await test("choice validation and multiple answers retain option IDs", () => {
    const d = A.assessment(),
      q = A.element("multiple_choice");
    q.properties.required = true;
    d.elements = [q];
    const s = new A.PreviewSession(d);
    assert.equal(s.errors().length, 1);
    s.set(q, [q.options[0].id]);
    assert.equal(s.errors().length, 0);
    s.set(q, ["missing"]);
    assert.equal(s.errors().length, 1);
  });
  const calls = [],
    records = new Map();
  let failSave = false;
  const api = async (op, data = {}) => {
    calls.push(op);
    if (op === "list")
      return [...records.values()].map((r) => ({
        id: r.definition.id,
        title: r.definition.title,
        revision: r.revision,
      }));
    if (op === "load") return plain(records.get(data.id));
    if (op === "save") {
      if (failSave) throw Error("Offline");
      const r = {
        definition: JSON.parse(data.definition),
        revision: Number(data.revision) + 1,
      };
      records.set(r.definition.id, r);
      return plain(r);
    }
  };
  const host = doc.getElementById("root"),
    app = A.mount(host, {}, api);
  await tick();
  await test("native screen has own library and creates empty canvas", () => {
    assert(host.textContent.includes("Listado de evaluaciones"));assert(host.querySelector(".ae-workspace").classList.contains("ae-library-workspace"));assert(host.querySelector(".ae-palette").hidden);assert(host.querySelector(".ae-inspector").hidden);
    click(host, "Nueva evaluación");
    assert.equal(app.definition.elements.length, 0);assert(!host.querySelector(".ae-palette").hidden);assert(!host.querySelector(".ae-inspector").hidden);
    assert(host.querySelector(".ae-palette"));
    assert(host.querySelector(".ae-canvas"));
    assert(host.querySelector(".ae-inspector"));
  });
  await test("palette click inserts selected element and synchronizes inspector", () => {
    click(host, "+ Texto");
    assert.equal(app.definition.elements[0].type, "text");
    assert.equal(host.querySelectorAll(".is-selected").length, 1);
    assert(host.querySelector('[data-property="required"]'));
  });
  await test("inline label typing keeps focus and stable identity", () => {
    const q = app.definition.elements[0],
      before = q.id;
    const field = host.querySelector(
      '.ae-element [aria-label="Texto de pregunta"]',
    );
    field.focus();
    input(field, "¿Cómo estás?");
    assert.equal(q.properties.label, "¿Cómo estás?");
    assert.equal(q.id, before);
    assert.equal(doc.activeElement, field);
    const propertyControl = host.querySelector('[data-property="required"]');
    field.dispatchEvent(new w.Event("blur"));
    assert.equal(
      host.querySelector('[data-property="required"]'),
      propertyControl,
    );
  });
  await test("properties update required and helper and canvas appearance", () => {
    const required = host.querySelector('[data-property="required"]');
    required.checked = true;
    required.dispatchEvent(new w.Event("change"));
    input(
      host.querySelector('.ae-canvas [aria-label="Ayuda de la pregunta"]'),
      "Una frase",
      "input",
    );
    assert.equal(app.definition.elements[0].properties.required, true);
    assert.equal(
      host.querySelector('.ae-canvas [aria-label="Ayuda de la pregunta"]')
        .value,
      "Una frase",
    );
  });
  await test("palette drag inserts at exact drop boundary", () => {
    drop(host.querySelector(".ae-dropzone"), {
      kind: "palette",
      type: "single_choice",
    });
    assert.equal(app.definition.elements[0].type, "single_choice");
    assert.equal(app.definition.elements[1].type, "text");
  });
  await test("option labels add remove and reorder preserve option identities", () => {
    let q = app.definition.elements[0],
      card = host.querySelector(".ae-element");
    const id = q.options[0].id;
    input(card.querySelector('[aria-label="Texto de opción 1"]'), "Nunca");
    assert.equal(
      host.querySelector('.ae-inspector [aria-label="Texto de opción 1"]')
        .value,
      "Nunca",
    );
    click(card, "+ Agregar opción");
    card = host.querySelector(".ae-element");
    click(card.querySelector(".ae-option-row"), "↓");
    assert.equal(q.options[1].id, id);
    assert.equal(q.options[1].label, "Nunca");
    click(host.querySelector(".ae-element"), "Quitar");
    assert.equal(q.options.length, 2);
  });
  await test("drag reorder actually changes model and canvas order", () => {
    const first = app.definition.elements[0].id;
    const zones = host.querySelectorAll(".ae-assessment-container > .ae-dropzone");
    drop(zones[zones.length - 1], { kind: "element", id: first });
    assert.equal(app.definition.elements[1].id, first);
    assert.equal(host.querySelectorAll(".ae-element")[1].dataset.id, first);
  });
  await test("drag into group and fallback out/up/down work", () => {
    click(host, "+ Grupo / sección");
    const group = app.definition.elements.find((e) => e.type === "group"),
      q = app.definition.elements[0];
    drop(host.querySelector('.ae-dropzone[data-parent="' + group.id + '"]'), {
      kind: "element",
      id: q.id,
    });
    assert.equal(group.children[0].id, q.id);
    click(host.querySelector(".ae-inspector"), "Sacar del grupo");
    assert.equal(group.children.length, 0);
    const card = host.querySelector('[data-id="' + q.id + '"]'),
      before = app.definition.elements.indexOf(q);
    click(card, "↑");
    assert.equal(app.definition.elements.indexOf(q), before - 1);
    click(host.querySelector('[data-id="' + q.id + '"]'), "↓");
    assert.equal(app.definition.elements.indexOf(q), before);
  });
  await test("PageBreak visible and moves with derived page headings", () => {
    drop(host.querySelector(".ae-assessment-container > .ae-dropzone"), {
      kind: "palette",
      type: "page_break",
    });
    assert.equal(app.definition.elements[0].type, "page_break");
    assert.equal(host.querySelectorAll(".ae-page-heading").length, 2);
    const zones = host.querySelectorAll(".ae-assessment-container > .ae-dropzone");
    drop(zones[zones.length - 1], {
      kind: "element",
      id: app.definition.elements[0].id,
    });
    assert.equal(app.definition.elements.at(-1).type, "page_break");
  });
  await test("duplicate then delete updates canvas without ID collisions", () => {
    const card = host.querySelector(".ae-element:not(.ae-page-break)"),
      id = card.dataset.id;
    card.click();
    click(card, "Duplicar");
    assert.notEqual(app.selected, id);
    const duplicate = host.querySelector('[data-id="' + app.selected + '"]');
    click(duplicate, "Eliminar");
    const ids = [];
    A.walk(app.definition.elements, (e) => ids.push(e.id));
    assert.equal(new Set(ids).size, ids.length);
    assert(A.find(app.definition, id));
  });
  await test("save/load native JSON retains canonical identity and shows states", async () => {
    assert(host.textContent.includes("Cambios sin guardar"));
    await app.save();
    assert(host.textContent.includes("Guardado"));
    const saved = A.serialize(app.definition),
      title = app.definition.title;
    await app.showLibrary();
    assert(host.querySelector(".ae-library-item").textContent.includes(title));
    click(host.querySelector(".ae-library-item"), "Editar");
    await tick();
    assert.equal(A.serialize(app.definition), saved);
  });
  await test("save failure remains visible and preserves unsaved work", async () => {
    input(
      host.querySelector('[aria-label="Título de la evaluación"]'),
      "Sin conexión",
    );
    failSave = true;
    await app.save();
    assert(host.textContent.includes("No se pudo guardar: Offline"));
    assert(host.textContent.includes("Error al guardar"));
    assert.equal(app.definition.title, "Sin conexión");
    failSave = false;
    await app.save();
  });
  await test("preview navigation validates required, goes back, finishes and clears on reopen", () => {
    const d = A.assessment(),
      text = A.element("text"),
      vas = A.element("vas");
    text.properties.required = true;
    vas.properties.required = true;
    d.elements = [text, A.element("page_break"), vas];
    const before = calls.length,
      p = A.openPreview(d);
    click(p.dialog, "Siguiente");
    assert(p.dialog.querySelector(".ae-validation"));
    input(p.dialog.querySelector(".ae-preview-frame input:not([type=range])"), "Respuesta");
    click(p.dialog, "Siguiente");
    assert(p.dialog.textContent.includes("Página 2 de 2"));
    assert.equal(p.session.answers[vas.response_key], undefined);
    click(p.dialog, "Terminar");
    assert(p.dialog.querySelector(".ae-validation"));
    const slider = p.dialog.querySelector("input[type=range]");
    slider.dispatchEvent(new w.Event("input"));
    click(p.dialog, "Anterior");
    assert.equal(p.dialog.querySelector(".ae-preview-frame input").value, "Respuesta");
    click(p.dialog, "Siguiente");
    click(p.dialog, "Terminar");
    assert(p.dialog.textContent.includes("Gracias por completar la evaluación"));
    p.close();
    assert.equal(Object.keys(p.session.answers).length, 0);
    const second = A.openPreview(d);
    assert.equal(second.dialog.querySelector(".ae-preview-frame input").value, "");
    second.close();
    assert.equal(calls.length, before, "Preview made a transport call");
  });
  await test("participant widgets support checkbox Likert textarea and VAS keyboard", () => {
    const d = A.assessment();
    d.elements = ["multiple_choice", "likert", "textarea", "vas"].map(
      A.element,
    );
    const p = A.openPreview(d),
      [multi, likert, area, vas] = d.elements;
    p.dialog.querySelector("input[type=checkbox]").click();
    p.dialog.querySelector("input[type=radio]").click();
    input(p.dialog.querySelector("textarea"), "Texto largo");
    p.dialog
      .querySelector("input[type=range]")
      .dispatchEvent(new w.KeyboardEvent("keydown", { key: "ArrowRight" }));
    assert.equal(p.session.answers[multi.response_key].length, 1);
    assert.equal(p.session.answers[likert.response_key], likert.options[0].id);
    assert.equal(p.session.answers[area.response_key], "Texto largo");
    assert(p.session.touched[vas.response_key]);
    p.close();
  });
  await test("preview responsive controls and restart are isolated from saved DTO", () => {
    const d = A.assessment();
    d.elements = [A.element("text")];
    const before = A.serialize(d),
      p = A.openPreview(d);
    input(p.dialog.querySelector(".ae-preview-frame input"), "Temporal");
    click(p.dialog, "Mobile");
    assert.equal(
      p.dialog.querySelector(".ae-preview-frame").dataset.width,
      "mobile",
    );
    click(p.dialog, "Tablet");
    assert.equal(
      p.dialog.querySelector(".ae-preview-frame").dataset.width,
      "tablet",
    );
    assert(![...p.dialog.querySelectorAll("button")].some(b=>b.textContent==="Reiniciar"));
    assert.equal(p.dialog.querySelector(".ae-preview-frame input").value, "Temporal");
    assert.equal(A.serialize(d), before);
    p.close();
  });
  await test("untrusted labels are text and never executable markup", () => {
    const d = A.assessment(),
      e = A.element("instruction");
    e.properties.label = '<img src=x onerror="alert(1)">';
    d.elements = [e];
    const p = A.openPreview(d);
    assert.equal(p.dialog.querySelector("img"), null);
    assert(p.dialog.textContent.includes("<img"));
    p.close();
  });
  console.log(tests + " tests, " + failures + " failures");
  w.close();
  process.exitCode = failures ? 1 : 0;
})();
