"use strict";
const fs = require("fs"),
  path = require("path"),
  assert = require("assert/strict"),
  { JSDOM } = require("jsdom"),
  { webcrypto } = require("crypto");
const base = path.resolve(__dirname, "../.."),
  dom = new JSDOM('<div id="root"></div>', {
    url: "http://localhost/wp-admin/",
    runScripts: "outside-only",
  }),
  w = dom.window,
  doc = w.document;
Object.defineProperty(w, "crypto", { value: webcrypto });
w.confirm = () => true;
w.prompt = () => null;
for (const f of JSON.parse(
  fs.readFileSync(path.join(base, "src/admin/assessment-editor/manifest.json")),
).entry_order)
  w.eval(
    fs.readFileSync(path.join(base, "src/admin/assessment-editor", f), "utf8"),
  );
const A = w.EIPSIAssessment,
  C = A.controls,
  H = A.richText,
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
const fixture = () =>
    JSON.parse(fs.readFileSync(path.join(__dirname, "authoring-fixture.json"))),
  one = (t) => fixture().elements.find((e) => e.type === t),
  tick = () => new Promise((r) => setImmediate(r));
const click = (root, text) => {
  const b = [...root.querySelectorAll("button")].find(
    (n) => n.textContent === text,
  );
  assert(b, text);
  b.click();
};
function change(n, v, event = "change") {
  assert(n);
  if (n.type === "checkbox") n.checked = v;
  else if (n.getAttribute("role") === "textbox") n.innerHTML = v;
  else n.value = v;
  n.dispatchEvent(new w.Event(event, { bubbles: true }));
}
(async () => {
  await test("prior definitions preserve identities properties and idempotent normalization", () => {
    for (const f of ["fixture.json", "parity-fixture.json"]) {
      const raw = JSON.parse(fs.readFileSync(path.join(__dirname, f))),
        before = A.serialize(raw),
        n = C.normalize(raw);
      assert.equal(A.serialize(raw), before);
      assert.equal(A.serialize(C.normalize(n)), A.serialize(n));
      raw.elements.forEach((e, i) => {
        assert.equal(n.elements[i].id, e.id);
        assert.equal(n.elements[i].response_key, e.response_key);
      });
    }
  });
  await test("VAS all automatic uses the shared anchored distribution", () => {
    const e = one("vas");
    e.properties.label_settings.forEach((l) => (l.position = null));
    const box = A.widget(e);
    assert.equal(box.querySelectorAll(".ae-vas-label-anchor").length, 5);
    const labels = box.querySelectorAll(".ae-semantic-label");
    assert.equal(Number(labels[2].dataset.labelPosition), 0.5);
    assert(!box.querySelector(".ae-positioned-labels"));
  });
  await test("VAS mixed explicit endpoints decimal and untouched automatic ratios", () => {
    const nodes = A.widget(one("vas")).querySelectorAll(".ae-semantic-label");
    assert.deepEqual(
      [
        nodes[0].dataset.labelPosition,
        nodes[2].dataset.labelPosition,
        nodes[4].dataset.labelPosition,
      ],
      ["0", "0.43", "1"],
    );
    assert.equal(Number(nodes[1].dataset.labelPosition), 0.352);
    assert.equal(Number(nodes[3].dataset.labelPosition), 0.648);
  });
  await test("VAS clearing explicit restores automatic anchor in the same renderer", () => {
    const e = one("vas");
    e.properties.label_settings.forEach((l) => (l.position = null));
    const before = A.widget(e).querySelector('[data-label-index="1"]').dataset
      .labelPosition;
    e.properties.label_settings[1].position = 0.123;
    assert.notEqual(
      A.widget(e).querySelector('[data-label-index="1"]').dataset.labelPosition,
      before,
    );
    e.properties.label_settings[1].position = null;
    assert.equal(
      A.widget(e).querySelector('[data-label-index="1"]').dataset.labelPosition,
      before,
    );
  });
  await test("VAS exact coincident labels share an anchored group without arbitrary lanes", () => {
    const e = one("vas");
    e.properties.label_settings.forEach((l) => (l.position = 0));
    const n = A.widget(e);
    assert.equal(n.querySelectorAll(".ae-vas-label-anchor").length, 1);
    assert.equal(
      n.querySelectorAll(".ae-coincident-labels .ae-semantic-label").length,
      5,
    );
    assert(!n.innerHTML.includes("label-lane"));
  });
  await test("rich allowlist retains formatting lists headings images and safe links", () => {
    const safe = H.sanitize(
      '<h2>Title</h2><p><b>B</b><i>I</i><u>U</u><br><a href="https://example.test">Link</a></p><ol><li>One</li></ol><img src="/image.png" alt="Image">',
    );
    for (const v of [
      "<h2>",
      "<b>",
      "<i>",
      "<u>",
      "<ol>",
      "<img",
      "noopener noreferrer",
    ])
      assert(safe.includes(v), v);
    assert.equal(H.sanitize(safe), safe);
  });
  await test("rich frontend rejects executable markup event handlers and unsafe protocols", () => {
    const s = H.sanitize(
      '<script>alert(1)</script><svg onload="bad()"></svg><iframe></iframe><img src="javascript:alert(1)" onerror="bad()"><a href="jav&#x61;script:bad()" onclick="bad()">X</a><p style="color:red">Text</p>',
    );
    assert(
      !/<(script|svg|iframe)|onload=|onclick=|onerror=|javascript:|position:|url\(/i.test(
        s,
      ),
    );
    assert(s.includes("color: red"));
  });
  await test("legacy plain labels remain escaped including literal HTML", () => {
    const n = H.render(
      { label: "<img src=x onerror=bad()>\nNext", label_html: null },
      "label",
    );
    assert(!n.querySelector("img"));
    assert(n.querySelector("br"));
    assert(n.textContent.includes("<img"));
  });
  await test("rich modal edits a draft and saves shared content", () => {
    const p = one("text").properties;
    let calls = 0;
    const box = H.editor(p, "label", "Question", () => calls++);
    doc.body.append(box);
    box.querySelector(".ae-format-button").click();
    const modal = doc.querySelector(".ae-rich-modal"),
      input = modal.querySelector("[role=textbox]");
    input.focus();
    change(input, "<p><strong>Edited</strong><br>Next</p>", "input");
    assert.equal(calls, 0);
    click(modal, "Guardar formato");
    assert(p.label_html.includes("<strong>"));
    assert(p.label.includes("Edited"));
    assert.equal(calls, 1);
    box.remove();
  });
  await test("rich paste sanitizes HTML and preserves allowed content", () => {
    const p = one("text").properties,
      box = H.editor(p, "helper", "Help", () => {}),
      modal = H.openModal(p, "helper", "Help", () => {}),
      input = modal.dialog.querySelector("[role=textbox]");
    doc.body.append(box);
    const e = new w.Event("paste", { bubbles: true, cancelable: true });
    Object.defineProperty(e, "clipboardData", {
      value: {
        getData: (t) =>
          t === "text/html"
            ? '<b>Pasted</b><img src="javascript:bad()" onerror="bad()">'
            : "",
      },
    });
    input.dispatchEvent(e);
    click(modal.dialog, "Guardar formato");
    assert(p.helper_html.includes("<b>Pasted</b>"));
    assert(!/onerror|javascript:/.test(p.helper_html));
    box.remove();
  });
  await test("rich toolbar provides all required formatting and shared image insertion", () => {
    const modal = H.openModal(
        one("text").properties,
        "label",
        "Question",
        () => {},
      ),
      box = modal.dialog;
    for (const text of [
      "Negrita",
      "Cursiva",
      "Subrayado",
      "Título",
      "Párrafo",
      "Lista",
      "Lista numerada",
      "Insertar o editar enlace",
      "Imagen",
      "Editar HTML",
    ])
      assert(
        [...box.querySelectorAll("button")].some((n) => n.textContent === text),
      );
    modal.close();
  });
  await test("WP media image inserted safely with alt and no Gutenberg", () => {
    const p = one("text").properties;
    let selected;
    w.wp = {
      media: () => ({
        on: (event, fn) => {
          if (event === "select") selected = fn;
        },
        open: () => {},
        state: () => ({
          get: () => ({
            first: () => ({
              toJSON: () => ({
                url: "https://example.test/image.png",
                alt: "Descripción",
              }),
            }),
          }),
        }),
      }),
    };
    const box = H.editor(p, "footer", "Footer", () => {});
    doc.body.append(box);
    box.querySelector(".ae-format-button").click();
    const modal = doc.querySelector(".ae-rich-modal");
    click(modal, "Imagen");
    selected();
    click(modal, "Guardar formato");
    assert(p.footer_html.includes('alt="Descripción"'));
    assert(!p.footer_html.includes("<!-- wp:"));
    box.remove();
    delete w.wp;
  });
  for (const t of C.questions)
    await test(
      t +
        " shared footer rich content renders after response and variables hidden in Preview",
      () => {
        const e = t === "matrix" ? one("matrix") : one(t);
        e.properties.footer_html =
          '<p><b>Footer</b><img src="https://example.test/a.png" alt="A"></p>';
        const panel = A.openPreview({ ...A.assessment(), elements: [e] });
        const question = panel.dialog.querySelector("#preview-" + e.id);
        assert(question.querySelector(".ae-footer b"));
        assert(question.querySelector(".ae-footer img"));
        assert.equal(
          question.lastElementChild.className,
          "ae-rich-content ae-footer",
        );
        assert(!panel.dialog.querySelector(".ae-variable-name"));
        panel.close();
      },
    );
  await test("empty footer does not produce participant container", () => {
    const e = one("text");
    e.properties.footer_html = H.sanitize("<p><br></p>");
    assert.equal(e.properties.footer_html, "");
    const p = A.openPreview({ ...A.assessment(), elements: [e] });
    assert(!p.dialog.querySelector(".ae-footer"));
    p.close();
  });
  await test("variable defaults rename duplicate and format validation preserve technical keys", () => {
    const e = A.element("text"),
      id = e.id,
      key = e.response_key,
      d = { ...A.assessment(), elements: [e] };
    assert(e.properties.variable_name);
    e.properties.variable_name = "edad";
    e.properties.label = "Renamed";
    assert.equal(e.id, id);
    assert.equal(e.response_key, key);
    assert.equal(A.variableErrors(d).length, 0);
    const n = A.duplicate(d, e.id);
    assert.notEqual(n.properties.variable_name, e.properties.variable_name);
    n.properties.variable_name = "edad";
    assert.equal(A.variableErrors(d).length, 1);
    n.properties.variable_name = "123 bad";
    assert.equal(A.variableErrors(d).length, 1);
  });
  await test("Text family conversions keep IDs response keys rich header and edited variable", () => {
    const e = one("text"),
      before = plain(e);
    for (const [mode, format] of [
      ["single", "email"],
      ["single", "url"],
      ["single", "tel"],
      ["single", "number"],
      ["multiline", "free"],
      ["single", "letters"],
    ]) {
      A.textMode(e, mode, format);
      assert.equal(e.id, before.id);
      assert.equal(e.response_key, before.response_key);
      assert.equal(e.properties.variable_name, before.properties.variable_name);
      assert.equal(e.properties.footer_html, before.properties.footer_html);
      assert(A.widget(e).querySelector("input,textarea"));
    }
    assert.equal(e.type, "text");
    assert.equal(e.properties.content_type, "letters");
  });
  await test("Text formats and nullable lengths enforce letters Unicode email URL numeric", () => {
    const e = A.element("text");
    e.properties.content_type = "letters";
    e.properties.min_length = null;
    e.properties.max_length = null;
    assert.equal(C.answerError(e, "María José", true), "");
    assert(C.answerError(e, "abc123", true));
    A.textMode(e, "multiline", "email");
    assert.equal(C.answerError(e, "bad", true), "");
    assert.equal(C.answerError(e, "a@example.test", true), "");
    A.textMode(e, "single", "number");
    e.properties.integer_only = true;
    assert(C.answerError(e, 1.5, true));
    assert.equal(C.answerError(e, 1, true), "");
  });
  await test("choice columns preserve stored row-major order and select unaffected", () => {
    for (const t of ["single_choice", "multiple_choice"]) {
      const e = one(t),
        before = A.serialize(e.options),
        n = A.widget(e);
      assert(n.classList.contains("ae-columns"));
      assert.equal(
        n.style.getPropertyValue("--ae-columns"),
        String(e.properties.columns),
      );
      assert.deepEqual(
        [...n.querySelectorAll(".ae-choice input")].map((n) => n.value),
        plain(e.options.map((o) => o.id)),
      );
      assert.equal(A.serialize(e.options), before);
    }
    assert(!A.widget(one("select")).classList.contains("ae-columns"));
  });
  await test("Matrix native shared scale independent row keys numeric projection and required", () => {
    const e = one("matrix"),
      d = { ...A.assessment(), elements: [e] },
      s = new A.PreviewSession(d);
    assert.equal(s.errors().length, 5);
    e.properties.matrix_rows.forEach((r, i) =>
      s.set({ ...e, response_key: r.response_key }, e.options[i % 4].id),
    );
    assert.equal(s.errors().length, 0);
    e.properties.matrix_rows.forEach((r, i) =>
      assert.equal(s.values()[r.response_key], -2 + (i % 4)),
    );
    assert.equal(Object.keys(s.answers).length, 5);
  });
  await test("Matrix optional all rows and individual required", () => {
    const e = one("matrix");
    e.properties.required = false;
    const s = new A.PreviewSession({ ...A.assessment(), elements: [e] });
    assert.equal(s.errors().length, 1);
    s.definition.elements[0].properties.matrix_rows[0].required = false;
    assert.equal(s.errors().length, 0);
  });
  await test("Matrix reverse preserves canonical values and desktop/mobile labels", () => {
    const e = one("matrix"),
      n = A.widget(
        e,
        new A.PreviewSession({ ...A.assessment(), elements: [e] }),
      );
    assert.equal(n.querySelectorAll("tbody tr").length, 5);
    assert.equal(n.querySelector("tbody input").value, e.options[3].id);
    assert.equal(C.answerValue({ ...e, type: "likert" }, e.options[3].id), 1);
    assert(n.querySelector(".ae-matrix-mobile-label"));
  });
  await test("Matrix clone renews rows options keys and variable names retains rich content", () => {
    const e = one("matrix"),
      n = A.cloneIdentity(e);
    assert.notEqual(n.id, e.id);
    assert.notEqual(n.properties.variable_name, e.properties.variable_name);
    n.properties.matrix_rows.forEach((r, i) => {
      assert.notEqual(r.id, e.properties.matrix_rows[i].id);
      assert.notEqual(r.response_key, e.properties.matrix_rows[i].response_key);
      assert.notEqual(
        r.variable_name,
        e.properties.matrix_rows[i].variable_name,
      );
      assert.equal(r.label_html, e.properties.matrix_rows[i].label_html);
    });
  });
  const records = new Map(),
    host = doc.getElementById("root"),
    api = async (op, args = {}) => {
      if (op === "list")
        return [...records.values()].map((r) => ({
          id: r.definition.id,
          title: r.definition.title,
          revision: r.revision,
        }));
      if (op === "load") return plain(records.get(args.id));
      if (op === "save") {
        const d = JSON.parse(args.definition),
          r = { definition: d, revision: Number(args.revision) + 1 };
        records.set(d.id, r);
        return plain(r);
      }
      throw Error(op);
    },
    app = A.mount(host, {}, api);
  await tick();
  app.newAssessment();
  await test("palette unified Text Date and native Matrix with legacy types recoverable in inspector", () => {
    const types = [...host.querySelectorAll(".ae-palette [data-type]")].map(
      (n) => n.dataset.type,
    );
    assert(
      types.includes("text") &&
        types.includes("date") &&
        types.includes("matrix"),
    );
    for (const t of ["email", "number", "tel", "url", "textarea"])
      assert(!types.includes(t));
    click(host, "+ Texto");
    assert(host.querySelector('[data-property="content_type"]'));
    assert(host.querySelector('[data-property="display_mode"]'));
  });
  await test("Text inspector mode format controls really change widgets and validation settings", () => {
    change(host.querySelector('[data-property="content_type"]'), "email");
    assert(host.querySelector("[data-widget=email] input[type=email]"));
    change(host.querySelector('[data-property="display_mode"]'), "multiline");
    assert(host.querySelector("[data-widget=textarea] textarea"));
    assert(!host.querySelector('[data-property="content_type"]'));
    assert(!host.querySelector('[data-property="validation_message"]'));
    change(host.querySelector('[data-property="display_mode"]'), "single");
    change(host.querySelector('[data-property="content_type"]'), "number");
    assert(host.querySelector('[data-property="integer_only"]'));
    assert(!host.querySelector('[data-property="max_length"]'));
  });
  await test("variable inline conflict immediate and blocks invalid save", async () => {
    const e = app.definition.elements[0];
    change(host.querySelector(".ae-variable-name"), "bad space", "input");
    assert.equal(
      host.querySelector(".ae-variable-name").getAttribute("aria-invalid"),
      "true",
    );
    const before = records.size;
    await app.save();
    assert.equal(records.size, before);
    change(host.querySelector(".ae-variable-name"), "edad", "input");
    assert.equal(
      host.querySelector(".ae-variable-name").getAttribute("aria-invalid"),
      "false",
    );
    assert.equal(e.properties.variable_name, "edad");
  });
  await test("maximal authoring save load keeps all new settings and five Matrix rows", async () => {
    const d = fixture();
    records.set(d.id, { definition: d, revision: 1 });
    await app.showLibrary();
    assert(host.querySelector(".ae-library-item").textContent.includes(d.title));
    click(host.querySelector(".ae-library-item"), "Editar");
    await tick();
    assert.equal(A.serialize(app.definition), A.serialize(C.normalize(d)));
    await app.save();
    assert.equal(
      A.serialize(records.get(d.id).definition),
      A.serialize(C.normalize(d)),
    );
    assert(host.querySelector(".ae-canvas .ae-rich-content img"));
  });
  await test("VAS position inspector edits decimal clears and reorders stable label settings", () => {
    const e = app.definition.elements.find((e) => e.type === "vas");
    host.querySelector('[data-id="' + e.id + '"]').click();
    let input = host.querySelector('[aria-label="Posición de etiqueta 2"]');
    change(input, "1.13");
    assert.equal(e.properties.label_settings[1].position, 1.13);
    change(input, "");
    assert.equal(e.properties.label_settings[1].position, null);
    const id = e.properties.label_settings[0].id;
    const row = host.querySelector(".ae-semantic-editor .ae-option-row");
    click(row, "↓");
    assert.equal(e.properties.label_settings[1].id, id);
  });
  await test("Matrix inspector adds removes reorders items and edits shared scale once", () => {
    const e = app.definition.elements.find((e) => e.type === "matrix");
    host.querySelector('[data-id="' + e.id + '"]').click();
    const first = e.properties.matrix_rows[0].response_key;
    click(host.querySelector(".ae-inspector"), "+ Agregar item");
    assert.equal(e.properties.matrix_rows.length, 6);
    click(host.querySelector(".ae-matrix-edit-row"), "↓ Item");
    assert.equal(e.properties.matrix_rows[1].response_key, first);
    const count = e.options.length;
    change(host.querySelector('.ae-inspector [aria-label="Valor analítico de opción 1"]'), "7.5");
    assert.equal(e.options[0].value, 7.5);
    assert.equal(e.options.length, count);
    assert.equal(
      host.querySelectorAll(
        ".ae-canvas [data-widget=matrix] tbody input[type=radio]",
      ).length,
      6 * count,
    );
    click(host.querySelector(".ae-matrix-edit-row"), "Quitar item");
    assert.equal(e.properties.matrix_rows.length, 5);
  });
  await test("Matrix canvas row inline rich edit and variables survive save", async () => {
    const e = app.definition.elements.find((e) => e.type === "matrix"),
      r = e.properties.matrix_rows[0],
      key = r.response_key;
    click(
      host.querySelector('[data-row-id="' + r.id + '"]'),
      "✎",
    );
    const modal = doc.querySelector(".ae-rich-modal");
    change(
      modal.querySelector("[role=textbox]"),
      "<p><b>Nuevo item</b></p>",
      "input",
    );
    click(modal, "Guardar formato");
    assert(r.label_html.includes("<b>"));
    assert.equal(r.response_key, key);
    await app.save();
    assert.equal(
      records
        .get(app.definition.id)
        .definition.elements.find((e) => e.type === "matrix").properties
        .matrix_rows[0].label_html,
      r.label_html,
    );
  });
  await test("responsive contracts reduce choice columns stack Matrix and use one VAS rail", () => {
    const css = fs.readFileSync(
      path.join(base, "assets/css/assessment-editor.css"),
      "utf8",
    );
    for (const text of [
      "max-width: 700px",
      "max-width: 480px",
      "max-width: 300px",
      "ae-matrix-mobile-label",
      "ae-vas-track",
      "min(var(--ae-columns), 2)",
    ])
      assert(css.includes(text), text);
  });
  await test("new preview remains ephemeral has no fetch storage or variable exposure", () => {
    const saved = A.serialize(app.definition);
    w.fetch = () => {
      throw Error("Unexpected network");
    };
    const p = A.openPreview(app.definition);
    assert(!p.dialog.querySelector(".ae-variable-name"));
    p.dialog
      .querySelector("input")
      .dispatchEvent(new w.Event("input", { bubbles: true }));
    assert.equal(A.serialize(app.definition), saved);
    assert.equal(w.localStorage.length, 0);
    assert.equal(w.sessionStorage.length, 0);
    p.close();
  });
  await test("JSON import opens an independent saved draft through the authorized endpoint", async () => {
    const target = doc.createElement("div");doc.body.append(target);
    let submitted;
    const imported = fixture();imported.id = "AE_IMPORTED";
    const editor = A.mount(target, {}, async (operation, payload) => {
      if (operation === "list") return [];
      assert.equal(operation, "import_definition");submitted = JSON.parse(payload.document);
      return { definition: imported, revision: 1 };
    });
    await tick();
    const input = target.querySelector('input[type="file"]');
    const document = { format: "EIPSI_ASSESSMENT_JSON", version: 1, definition: fixture() };
    Object.defineProperty(input, "files", { configurable: true, value: [new w.File([JSON.stringify(document)], "assessment.json")] });
    await input.onchange();
    assert.equal(submitted.format, document.format);
    assert.equal(editor.definition.id, "AE_IMPORTED");
    assert.equal(target.querySelector(".ae-message").textContent, "Evaluación importada.");
    const exportButton = [...target.querySelectorAll("button")].find(n => n.textContent === "Exportar JSON");
    assert.equal(exportButton.disabled, false);
    target.remove();
  });
  await test("JSON import rejects malformed or oversized files and preserves current assessment", async () => {
    const target = doc.createElement("div");doc.body.append(target);
    const editor = A.mount(target, {}, async operation => { assert.equal(operation, "list");return []; });
    await tick();editor.newAssessment();const before = A.serialize(editor.definition);
    const input = target.querySelector('input[type="file"]');
    for (const body of ["{", " ".repeat(1048577)]) {
      Object.defineProperty(input, "files", { configurable: true, value: [new w.File([body], "bad.json")] });
      await input.onchange();assert.equal(A.serialize(editor.definition), before);
      assert(target.querySelector(".ae-message").classList.contains("ae-error"));
      assert.equal(target.querySelector(".ae-workspace").inert, false);
    }
    target.remove();
  });
  await test("question import preserves custom variables and resolves collisions including Matrix rows", () => {
    const d = A.assessment(), q = plain(one("matrix"));
    q.properties.variable_name = "custom_matrix";
    q.properties.matrix_rows.forEach((r, i) => r.variable_name = "custom_matrix_" + (i + 1));
    A.importQuestion(d, q);
    assert.equal(d.elements[0].properties.variable_name, "custom_matrix");
    const second = plain(q);second.id = "EL_SECOND";second.response_key = "R_SECOND";
    second.properties.matrix_rows.forEach((r,i) => {r.id = "ROW_SECOND_"+i;r.response_key="R_SECOND_"+i;});
    A.importQuestion(d, second);
    assert.notEqual(d.elements[1].properties.variable_name, "custom_matrix");
    assert.equal(A.variableErrors(d).length, 0);
    assert.equal(d.elements[0].properties.variable_name, "custom_matrix");
  });
  await test("question JSON upload inserts after selection without losing unsaved edits", async () => {
    const target = doc.createElement("div");doc.body.append(target);let calls=0;
    const question = plain(one("likert"));question.id="EL_IMPORTED_QUESTION";
    const editor = A.mount(target, {}, async (operation, payload) => {
      if(operation==="list")return [];
      assert.equal(operation,"import_question");assert.equal(JSON.parse(payload.document).format,"EIPSI_QUESTION_JSON");calls++;
      return question;
    });
    await tick();editor.newAssessment();editor.definition.title="Unsaved title";
    const input=target.querySelector('input[aria-label="Importar pregunta JSON"]');
    Object.defineProperty(input,"files",{value:[new w.File([JSON.stringify({format:"EIPSI_QUESTION_JSON",version:1,question})],"question.json")]});
    await input.onchange();
    assert.equal(calls,1);assert.equal(editor.definition.title,"Unsaved title");
    assert.equal(editor.definition.elements[0].id,question.id);
    assert.equal(editor.selected,question.id);
    assert(target.querySelector(".ae-save-status").textContent.includes("sin guardar"));
    assert(target.querySelector("[data-export-question]").disabled);
    target.remove();
  });
  console.log(`${tests} tests, ${failures} failures`);
  process.exitCode = failures ? 1 : 0;
})();
