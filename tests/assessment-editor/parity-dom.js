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
  C = A.controls,
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
  assert(b, text);
  b.click();
};
const change = (n, value, event = "change") => {
  assert(n);
  if (n.type === "checkbox") n.checked = value;
  else if (n.getAttribute("role") === "textbox") n.textContent = value;
  else n.value = value;
  n.dispatchEvent(new w.Event(event, { bubbles: true }));
};
const tick = () => new Promise((r) => setImmediate(r));
const fixture = () =>
  C.normalize(
    JSON.parse(
      fs.readFileSync(
        path.join(root, "tests/assessment-editor/parity-fixture.json"),
      ),
    ),
  );
(async () => {
  for (const type of C.questions.filter((t) => t !== "matrix"))
    await test(type + " native creation and shared participant control", () => {
      const e = A.element(type),
        widget = A.widget(
          e,
          new A.PreviewSession({ ...A.assessment(), elements: [e] }),
        );
      assert(e.response_key);
      const control = widget.querySelector(
        type === "textarea"
          ? "textarea"
          : type === "select"
          ? "select"
          : "input",
      );
      assert(control);
      if (type !== "textarea" && type !== "select")
        assert.equal(
          control.type,
          {
            number: "text",
            date: "text",
            single_choice: "radio",
            multiple_choice: "checkbox",
            likert: "radio",
            vas: "range",
          }[type] || type,
        );
    });
  await test("v0 normalization preserves identities labels and source without mutation", () => {
    const old = JSON.parse(
        fs.readFileSync(
          path.join(root, "tests/assessment-editor/fixture.json"),
        ),
      ),
      before = JSON.stringify(old),
      d = A.normalizeDefinition(old);
    assert.equal(JSON.stringify(old), before);
    for (let i = 0; i < old.elements.length; i++) {
      assert.equal(d.elements[i].id, old.elements[i].id);
      assert.equal(d.elements[i].response_key, old.elements[i].response_key);
    }
    const vas = d.elements.find((e) => e.type === "vas");
    assert.deepEqual(plain(vas.properties.labels), [
      vas.properties.left_label,
      vas.properties.right_label,
    ]);
    assert.equal(vas.properties.gradient, "none");
  });
  await test("all six original presets match labels minimum and counts", () => {
    let source = fs
      .readFileSync(path.join(root, "src/components/LikertPresets.js"), "utf8")
      .replace(/export /g, "");
    source += "\nwindow.legacyPresets=LIKERT_PRESETS;";
    w.eval(source);
    assert.equal(Object.keys(C.presets).length, 6);
    for (const [key, p] of Object.entries(C.presets)) {
      assert.deepEqual(
        plain(p.labels),
        w.legacyPresets[key].labels.split("; "),
      );
      assert.equal(p.min, w.legacyPresets[key].minValue);
    }
  });
  await test("preview validation covers each required type including untouched VAS/select placeholder", () => {
    const d = fixture(),
      s = new A.PreviewSession(d);
    assert.equal(s.errors().length, 12);
    assert.equal(s.values().R_vas, undefined);
  });
  await test("text email URL date number and textarea reject invalid answers", () => {
    const d = fixture(),
      by = Object.fromEntries(d.elements.map((e) => [e.type, e]));
    for (const [type, bad] of [
      ["text", "x"],
      ["textarea", "x".repeat(101)],
      ["email", "not@email"],
      ["url", "relative/path"],
      ["date", "2026-02-30"],
      ["number", 7.75],
    ])
      assert(C.answerError(by[type], bad, true), type);
    for (const [type, good] of [
      ["text", "ok"],
      ["textarea", "Texto\nlargo"],
      ["email", "a@example.test"],
      ["url", "https://example.test/path"],
      ["date", "2024-02-29"],
      ["number", -2.25],
      ["number", 0.1],
      ["tel", "+54 11"],
    ])
      assert.equal(C.answerError(by[type], good, true), "", type);
  });
  await test("multiple cardinality membership and duplicate IDs enforced", () => {
    const e = fixture().elements.find((e) => e.type === "multiple_choice"),
      ids = e.options.map((o) => o.id);
    assert(C.answerError(e, [ids[0]], true));
    assert.equal(C.answerError(e, ids.slice(0, 2), true), "");
    assert(C.answerError(e, ids.slice(0, 4), true));
    assert(C.answerError(e, [ids[0], ids[0]], true));
    assert(C.answerError(e, [ids[0], "unknown"], true));
  });
  await test("Likert reverse does not change consecutive numeric projections or option identities", () => {
    const e = fixture().elements.find((e) => e.type === "likert"),
      d = { ...A.assessment(), elements: [e] },
      s = new A.PreviewSession(d);
    s.set(e, e.options[0].id);
    assert.equal(s.values()[e.response_key], -3);
    const widget = A.widget(e, s);
    assert(widget.classList.contains("ae-reversed"));
    assert.deepEqual(
      Array.from(widget.querySelectorAll("input"), (n) => n.value),
      Array.from(e.options, (o) => o.id).reverse(),
    );
    assert.equal(widget.querySelector(".ae-likert-value"),null);
    assert.equal(widget.querySelector("input:checked").value, e.options[0].id);
    e.properties.reversed = false;
    assert.equal(C.answerValue(e, e.options[0].id), -3);
  });
  await test("VAS full settings render equally in canvas/preview and preserve multiline labels", () => {
    const e = fixture().elements.find((e) => e.type === "vas"),
      a = A.widget(e),
      b = A.widget(
        e,
        new A.PreviewSession({ ...A.assessment(), elements: [e] }),
      );
    for (const widget of [a, b]) {
      assert.equal(widget.querySelectorAll(".ae-semantic-label").length, 5);
      assert(widget.textContent.includes("Un\npoco"));
      assert.equal(
        widget.querySelector(".ae-vas-value").style.fontSize,
        "72px",
      );
      assert.equal(widget.firstElementChild.tagName, "OUTPUT");
      assert(
        widget
          .querySelector(".ae-vas-labels")
          .classList.contains("ae-label-boxes"),
      );
      assert(
        widget
          .querySelector("input")
          .style.backgroundImage.includes("linear-gradient"),
      );
      assert.equal(widget.querySelector("input").step, "0.25");
    }
  });
  await test("VAS same initial keyboard Home End pointer and input mark touched; hidden display retains answer", () => {
    for (const [event, key] of [
      ["keydown", "Home"],
      ["keydown", "End"],
      ["keydown", "ArrowLeft"],
      ["pointerdown"],
      ["pointerup"],
      ["touchend"],
      ["input"],
    ]) {
      const e = A.element("vas");
      e.properties.required = true;
      e.properties.show_value = false;
      const s = new A.PreviewSession({ ...A.assessment(), elements: [e] }),
        widget = A.widget(e, s),
        slider = widget.querySelector("input");
      assert.equal(s.errors().length, 1);
      assert.equal(widget.querySelector("output"), null);
      slider.dispatchEvent(
        key ? new w.KeyboardEvent(event, { key }) : new w.Event(event),
      );
      assert.equal(s.errors().length, 0);
      assert.equal(s.answers[e.response_key], 50);
    }
  });
  await test("gradients include all original directions custom and none", () => {
    for (const gradient of [
      "improvement-right",
      "improvement-left",
      "progress-neutral",
      "custom",
      "none",
    ]) {
      const e = A.element("vas");
      e.properties.gradient = gradient;
      const widget = A.widget(e);
      assert.equal(
        Boolean(widget.querySelector("input").style.backgroundImage),
        gradient !== "none",
      );
    }
    assert(
      C.gradient({ gradient: "improvement-right" }).includes(
        "#f44336, #ff9800, #ffc107, #8bc34a, #4caf50",
      ),
    );
    assert(
      C.gradient({ gradient: "improvement-left" }).includes(
        "#4caf50, #8bc34a, #ffc107, #ff9800, #f44336",
      ),
    );
  });
  const rows = new Map(),
    calls = [];
  const api = async (op, data) => {
    calls.push(op);
    if (op === "list")
      return [...rows.values()].map((r) => ({
        id: r.definition.id,
        title: r.definition.title,
        revision: r.revision,
      }));
    if (op === "load") return plain(rows.get(data.id));
    if (op === "save") {
      const r = {
        definition: JSON.parse(data.definition),
        revision: Number(data.revision) + 1,
      };
      rows.set(r.definition.id, r);
      return plain(r);
    }
  };
  const host = doc.getElementById("root"),
    app = A.mount(host, {}, api);
  await tick();
  app.newAssessment();
  await test("palette offers unified Text Date selection and scale categories", () => {
    for (const type of [
      "text",
      "date",
      "single_choice",
      "multiple_choice",
      "select",
      "likert",
      "vas",
      "matrix",
    ])
      assert(host.querySelector('[data-type="' + type + '"]'), type);
    assert(host.querySelector(".ae-palette").textContent.includes("Escalas"));
  });
  await test("all maximally configured types save reopen without loss", async () => {
    const d = fixture();
    rows.set(d.id, { definition: d, revision: 1 });
    await app.showLibrary();
    assert(host.querySelector(".ae-library-item").textContent.includes(d.title));
    click(host.querySelector(".ae-library-item"), "Editar");
    await tick();
    assert.equal(A.serialize(app.definition), A.serialize(d));
    await app.save();
    assert.equal(A.serialize(rows.get(d.id).definition), A.serialize(d));
  });
  await test("canvas shows real dropdown checkboxes Likert and VAS rather than technical cards", () => {
    assert(host.querySelector('[data-widget="select"] select'));
    assert(
      host.querySelector(
        '[data-widget="multiple_choice"] input[type=checkbox]',
      ),
    );
    assert(host.querySelector('[data-widget="likert"] input[type="radio"]'));
    assert(host.querySelector('[data-widget="vas"] input[type=range]'));
  });
  await test("inspector is contextual, textarea rows and multiple cardinality have real controls", () => {
    host.querySelector('[data-id="EL_textarea"]').click();
    assert(host.querySelector('[data-property="rows"]'));
    assert.equal(host.querySelector('[data-property="gradient"]'), null);
    change(host.querySelector('[data-property="rows"]'), 12);
    assert.equal(
      host.querySelector('[data-widget="textarea"] textarea').rows,
      12,
    );
    host.querySelector('[data-id="EL_multiple_choice"]').click();
    change(host.querySelector('[data-property="min_selected"]'), 3);
    assert.equal(
      app.definition.elements.find((e) => e.type === "multiple_choice")
        .properties.min_selected,
      3,
    );
  });
  await test("Likert removes preset/reverse UI while retaining editable scale identity and gradient", () => {
    host.querySelector('[data-id="EL_likert"]').click();
    const e = A.find(app.definition, "EL_likert").node;
    assert(!host.querySelector('[data-property="preset"]'));
    assert(!host.querySelector('[data-property="reversed"]'));
    const id = e.options[0].id;
    change(host.querySelector('.ae-inspector [aria-label="Valor analítico de opción 1"]'), "7.5");
    assert.equal(e.options[0].value, 7.5);
    assert.equal(e.options[0].id, id);
    assert.equal(e.id, "EL_likert");
    change(host.querySelector('[data-property="gradient"]'), "custom");
    change(
      host.querySelector('[data-property="gradient_start"]'),
      "#abcdef",
      "input",
    );
    assert(
      host
        .querySelector('[data-widget="likert"]')
        .style.backgroundImage.includes("rgb(171, 205, 239)"),
    );
  });
  await test("VAS semantic labels inline and inspector add remove reorder plus live presentation", () => {
    host.querySelector('[data-id="EL_vas"]').click();
    let e = A.find(app.definition, "EL_vas").node;
    const field = host.querySelector(
      '[data-widget="vas"] [data-label-index="1"]',
    );
    change(field, "Nuevo\ntexto", "input");
    assert.equal(e.properties.labels[1], "Nuevo\ntexto");
    assert.equal(
      host.querySelector('[data-semantic-index="1"]').value,
      "Nuevo\ntexto",
    );
    click(host.querySelector(".ae-inspector"), "+ Agregar etiqueta");
    assert.equal(e.properties.labels.length, 6);
    click(host.querySelector(".ae-semantic-editor .ae-option-row"), "↓");
    assert.equal(e.properties.labels[0], "Nuevo\ntexto");
    click(
      host.querySelector(".ae-semantic-editor .ae-option-row"),
      "Quitar etiqueta",
    );
    assert.equal(e.properties.labels.length, 5);
    change(host.querySelector('[data-property="value_position"]'), "below");
    assert.equal(
      host.querySelector('[data-widget="vas"]').lastElementChild.tagName,
      "OUTPUT",
    );
    change(
      host.querySelector('[data-property="gradient"]'),
      "improvement-left",
    );
    assert(
      host.querySelector('[data-widget="vas"] input').style.backgroundImage,
    );
  });
  await test("preview semantic labels respond to container width and no transport/storage occurs", () => {
    const css = fs.readFileSync(
      path.join(root, "assets/css/assessment-editor.css"),
      "utf8",
    );
    assert(css.includes("@container"));
    assert(css.includes("container-type:") || css.includes("container-type:"));
    const before = calls.length,
      p = A.openPreview(fixture());
    for (const name of ["Desktop", "Tablet", "Mobile"]) click(p.dialog, name);
    assert.equal(
      p.dialog.querySelector(".ae-preview-frame").dataset.width,
      "mobile",
    );
    assert.equal(
      p.dialog.querySelectorAll('[data-widget="vas"] .ae-semantic-label')
        .length,
      5,
    );
    p.close();
    assert.equal(calls.length, before);
    assert.equal(w.localStorage.length, 0);
    assert.equal(w.sessionStorage.length, 0);
  });
  console.log(tests + " tests, " + failures + " failures");
  w.close();
  process.exitCode = failures ? 1 : 0;
})();
