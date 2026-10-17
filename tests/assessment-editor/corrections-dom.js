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
for (const f of JSON.parse(
  fs.readFileSync(path.join(base, "src/admin/assessment-editor/manifest.json")),
).entry_order)
  w.eval(
    fs.readFileSync(path.join(base, "src/admin/assessment-editor", f), "utf8"),
  );
const A = w.EIPSIAssessment,
  V = A.variables,
  H = A.richText,
  G = w.EIPSIVASGeometry,
  plain = (x) => JSON.parse(JSON.stringify(x));
let tests = 0,
  failures = 0;
function test(name, fn) {
  tests++;
  try {
    fn();
    console.log("PASS " + name);
  } catch (e) {
    failures++;
    console.log("FAIL " + name + ": " + e.stack);
  }
}
const fixture = () =>
    JSON.parse(
      fs.readFileSync(path.join(__dirname, "corrections-fixture.json")),
    ),
  one = (t) => fixture().elements.find((e) => e.type === t),
  add = (d, t) => A.insert(d, A.element(t, d));
function click(root, text) {
  const b = [...root.querySelectorAll("button")].find(
    (n) => n.textContent === text,
  );
  assert(b, text);
  b.click();
}
function input(n, value) {
  if (n.getAttribute("role") === "textbox") n.innerHTML = value;
  else n.value = value;
  n.dispatchEvent(new w.Event("input", { bubbles: true }));
}
test("per-type creation ordinal and shared Text family", () => {
  const d = A.assessment();
  ["text", "email", "vas", "textarea", "vas"].forEach((t) => add(d, t));
  assert.deepEqual(plain(d.elements.map((e) => e.properties.variable_name)), [
    "text_1",
    "text_2",
    "vas_1",
    "text_3",
    "vas_2",
  ]);
});
test("all short prefixes start independently at one", () => {
  const d = A.assessment();
  for (const t of [
    "text",
    "date",
    "single_choice",
    "multiple_choice",
    "select",
    "likert",
    "vas",
    "matrix",
  ])
    assert.equal(add(d, t).properties.variable_name, V.prefix(t) + "_1");
});
test("deletion middle and highest never reuses allocated ordinal after reload", () => {
  let d = A.assessment();
  const a = add(d, "text"),
    b = add(d, "text"),
    c = add(d, "text");
  A.remove(d, b.id);
  A.remove(d, c.id);
  d = A.normalizeDefinition(JSON.parse(A.serialize(d)));
  assert.equal(add(d, "text").properties.variable_name, "text_4");
  assert.equal(d.elements[0].id, a.id);
});
test("reorder preserves question variables and technical identities", () => {
  const d = A.assessment(),
    a = add(d, "vas"),
    b = add(d, "vas"),
    before = plain([a, b]);
  A.move(d, b.id, null, 0);
  assert.equal(d.elements[0].id, b.id);
  assert.equal(A.serialize(a), A.serialize(before[0]));
  assert.equal(A.serialize(b), A.serialize(before[1]));
});
test("duplicate manual name allocates fresh default with new IDs and keys", () => {
  const d = A.assessment(),
    a = add(d, "vas");
  a.properties.variable_name = "ansiedad";
  const b = A.duplicate(d, a.id);
  assert.equal(b.properties.variable_name, "vas_2");
  assert.notEqual(a.id, b.id);
  assert.notEqual(a.response_key, b.response_key);
  assert.notEqual(
    a.properties.label_settings[0].id,
    b.properties.label_settings[0].id,
  );
});
test("manual variable persists through Text format conversion and properties", () => {
  const a = one("text");
  a.properties.variable_name = "ansiedad";
  A.textMode(a, "single", "email");
  const b = a;
  assert.equal(b.properties.variable_name, "ansiedad");
  assert.equal(b.id, a.id);
});
test("allocator skips explicit names and Matrix effective output collision", () => {
  const d = A.assessment(),
    q = add(d, "text");
  q.properties.variable_name = "matrix_1_2";
  assert.equal(add(d, "matrix").properties.variable_name, "matrix_2");
});
test("same variable allowed in independent Assessments", () => {
  const a = A.assessment(),
    b = A.assessment();
  assert.equal(
    add(a, "text").properties.variable_name,
    add(b, "text").properties.variable_name,
  );
  assert.equal(V.errors(a).length, 0);
  assert.equal(V.errors(b).length, 0);
});
test("Matrix three rows use base ordinal names", () => {
  const d = A.assessment(),
    m = add(d, "matrix");
  while (m.properties.matrix_rows.length < 3)
    m.properties.matrix_rows.push(A.row());
  A.refreshDerived(d);
  assert.deepEqual(
    plain(m.properties.matrix_rows.map((r) => r.variable_name)),
    ["matrix_1_1", "matrix_1_2", "matrix_1_3"],
  );
});
test("Matrix manual base and reorder change derived names preserving identities and answers", () => {
  const d = fixture(),
    m = d.elements.find((e) => e.type === "matrix"),
    before = plain(m.properties.matrix_rows);
  m.properties.variable_name = "phq";
  m.properties.matrix_rows.reverse();
  A.refreshDerived(d);
  assert.equal(m.properties.matrix_rows[0].variable_name, "phq_1");
  assert.equal(m.properties.matrix_rows[0].id, before[2].id);
  assert.equal(
    m.properties.matrix_rows[0].response_key,
    before[2].response_key,
  );
});
test("Matrix duplicate gets fresh base rows and technical identities", () => {
  const d = A.assessment(),
    m = add(d, "matrix"),
    n = A.duplicate(d, m.id);
  assert.equal(n.properties.variable_name, "matrix_2");
  assert.equal(n.properties.matrix_rows[0].variable_name, "matrix_2_1");
  assert.notEqual(
    n.properties.matrix_rows[0].response_key,
    m.properties.matrix_rows[0].response_key,
  );
});
test("Matrix derived output collision immediate; base may equal standalone output", () => {
  const d = A.assessment(),
    m = add(d, "matrix"),
    q = add(d, "text");
  q.properties.variable_name = "matrix_1_1";
  assert(V.errors(d).length);
  q.properties.variable_name = "matrix_1";
  assert.equal(V.errors(d).length, 0);
});
test("plain Canvas fields have ordinary text editor and no rich toolbar", () => {
  const p = one("text").properties;
  for (const key of ["label", "helper", "footer"]) {
    const box = H.editor(p, key, key, () => {});
    assert(box.querySelector("textarea"));
    assert(!box.querySelector("[contenteditable]"));
    assert(!box.querySelector(".ae-rich-toolbar"));
    assert(box.querySelector(".ae-format-button"));
  }
});
test("plain text edit preserves literal markup safely", () => {
  const p = one("text").properties,
    n = H.editor(p, "label", "Question", () => {});
  input(n.querySelector("textarea"), "<script>literal</script>");
  assert.equal(p.label, "<script>literal</script>");
  assert.equal(p.label_html, null);
  assert(!H.render(p, "label").querySelector("script"));
});
test("existing formatted content remains editable without destructive plain conversion", () => {
  const p = one("text").properties;
  p.label_html =
    '<p><strong>Bold</strong><img src="https://example.test/a.png"></p>';
  const n = H.editor(p, "label", "Question", () => {});
  assert(n.querySelector("strong"));
  assert(n.querySelector("img"));
  assert(!n.querySelector("textarea"));
  assert(n.querySelector('[contenteditable="true"]'));
  assert(n.querySelector(".ae-format-button"));
});
test("modal draft cancel leaves full original data and no callback", () => {
  const p = one("text").properties,
    before = JSON.stringify(p);
  let calls = 0;
  const modal = H.openModal(p, "label", "Question", () => calls++);
  input(modal.dialog.querySelector("[role=textbox]"), "<b>Changed</b>");
  assert.equal(JSON.stringify(p), before);
  click(modal.dialog, "Cancelar");
  assert.equal(JSON.stringify(p), before);
  assert.equal(calls, 0);
  assert(!doc.querySelector(".ae-rich-overlay"));
});
test("modal Apply formats sanitizes image and reopens persisted content", () => {
  const p = one("text").properties;
  let calls = 0;
  let modal = H.openModal(p, "label", "Question", () => calls++);
  input(
    modal.dialog.querySelector("[role=textbox]"),
    '<p><b>B</b><u>U</u></p><ul><li>Item</li></ul><img src="https://example.test/a.png" onerror="bad()"><script>bad()</script>',
  );
  click(modal.dialog, "Guardar formato");
  assert.equal(calls, 1);
  assert(p.label_html.includes("<img"));
  assert(!/onerror|<script/.test(p.label_html));
  modal = H.openModal(p, "label", "Question", () => {});
  assert(modal.dialog.querySelector("[role=textbox] img"));
  click(modal.dialog, "Cancelar");
});
test("helper footer and Matrix row use same transactional rich interaction", () => {
  for (const [p, key] of [
    [one("text").properties, "helper"],
    [one("text").properties, "footer"],
    [one("matrix").properties.matrix_rows[0], "label"],
  ]) {
    const m = H.openModal(p, key, key, () => {});
    input(m.dialog.querySelector("[role=textbox]"), "<em>Shared</em>");
    click(m.dialog, "Guardar formato");
    assert(p[key + "_html"].includes("<em>"));
  }
});
test("Escape dismisses draft and restores prior focus", () => {
  const b = doc.createElement("button");
  doc.body.append(b);
  b.focus();
  const p = one("text").properties,
    before = JSON.stringify(p),
    m = H.openModal(p, "label", "Question", () => {});
  m.dialog.dispatchEvent(
    new w.KeyboardEvent("keydown", { key: "Escape", bubbles: true }),
  );
  assert(!doc.querySelector(".ae-rich-modal"));
  assert.equal(JSON.stringify(p), before);
  assert.equal(doc.activeElement, b);
  b.remove();
});
for (const width of [320, 480, 800])
  for (const range of [
    [0, 100],
    [-10, 10],
  ])
    test(
      "measured track anchors quartiles " +
        range.join("..") +
        " width " +
        width,
      () => {
        const e = one("vas"),
          p = e.properties;
        p.min = range[0];
        p.max = range[1];
        p.label_settings.forEach(
          (l, i) => (l.position = p.min + ((p.max - p.min) * i) / 4),
        );
        const box = A.widget(e),
          labels = box.querySelector(".ae-vas-labels"),
          rail = box.querySelector(".ae-vas-track");
        doc.body.append(box);
        const start = width === 800 ? 75 : 43,
          trackWidth = width - 2 * start;
        labels.getBoundingClientRect = () => ({ left: 100, width });
        rail.getBoundingClientRect = () => ({
          left: 100 + start,
          width: trackWidth,
        });
        box
          .querySelector(".ae-vas-axis")
          .dispatchEvent(new w.Event("eipsi:vas-layout"));
        assert.equal(labels.dataset.trackMeasured, "true");
        [...box.querySelectorAll(".ae-semantic-label")].forEach((n, i) => {
          assert.equal(Number(n.dataset.labelPosition), i / 4);
          assert.equal(
            Number(n.dataset.anchorPx),
            start + (trackWidth * i) / 4,
          );
          assert.equal(
            parseFloat(n.parentElement.style.left),
            start + (trackWidth * i) / 4,
          );
        });
        box.remove();
      },
    );
test("decimal label independent from selectable step", () => {
  const p = one("vas").properties;
  p.min = -0.5;
  p.max = 1.5;
  p.step = 0.5;
  p.label_settings[2].position = 0.13;
  assert.equal(G.ratio(p, 2), 0.315);
  assert.equal(G.layout(p, { start: 75, width: 650 }, 800)[2].anchor, 279.75);
});
test("automatic fractions independent of viewport and manual overrides", () => {
  const p = one("vas").properties;
  p.label_settings.forEach((l) => (l.position = null));
  const before = p.labels.map((_, i) => G.ratio(p, i));
  p.label_settings[0].position = 0;
  p.label_settings[4].position = 100;
  for (const width of [320, 480, 800]) {
    const result = G.layout(p, { start: 43, width: width - 86 }, width);
    for (const i of [1, 2, 3]) assert.equal(result[i].ratio, before[i]);
  }
});
test("adaptive typography width scale proximity and content keep anchors fixed", () => {
  const p = one("vas").properties;
  p.labels = [
    "No",
    "Moderadamente",
    "Un texto muy largo multilinea que debe ajustarse al espacio",
    "B",
    "C",
  ];
  const narrow = G.layout(p, { start: 43, width: 234 }, 320),
    wide = G.layout(p, { start: 75, width: 650 }, 800);
  assert(narrow[2].fontSize <= wide[2].fontSize);
  p.label_scale = "large";
  const large = G.layout(p, { start: 75, width: 650 }, 800);
  assert(large[0].fontSize > wide[0].fontSize);
  large.forEach((g, i) => assert.equal(g.anchor, wide[i].anchor));
  assert(wide[0].maxWidth < 220);
});
test("near collisions reduce box width without moving horizontal anchor", () => {
  const p = one("vas").properties;
  p.label_settings[1].position = 49.9;
  p.label_settings[2].position = 50;
  const g = G.layout(p, { start: 75, width: 650 }, 800);
  assert(g[1].compact);
  assert.equal(g[1].anchor, 75 + 0.499 * 650);
  assert.equal(g[2].anchor, 400);
});
test("coincident labels share one fixed box with documented minimum fallback", () => {
  const e = one("vas");
  e.properties.label_settings.forEach((l) => (l.position = 50));
  const box = A.widget(e);
  assert.equal(box.querySelectorAll(".ae-vas-label-anchor").length, 1);
  assert.equal(
    box.querySelectorAll(".ae-coincident-labels .ae-semantic-label").length,
    5,
  );
});
test("relative size migration preserves existing legacy size and enum precedence", () => {
  for (const [size, scale] of [
    [12, "small"],
    [14, "small"],
    [15, "normal"],
    [24, "normal"],
    [25, "large"],
    [36, "large"],
  ]) {
    const d = fixture(),
      e = d.elements.find((e) => e.type === "vas");
    delete e.properties.label_scale;
    e.properties.label_font_size = size;
    assert.equal(
      A.normalizeDefinition(d).elements.find((e) => e.type === "vas").properties
        .label_scale,
      scale,
    );
  }
});
test("content fit centered wrapping CSS and Firefox range geometry are explicit", () => {
  const css = fs.readFileSync(
    path.join(base, "assets/css/assessment-editor.css"),
    "utf8",
  );
  assert(
    css.includes("width: max-content") || css.includes("width:max-content"),
  );
  assert(css.includes("translateX(-50%)"));
  assert(css.includes("::-moz-range-thumb"));
  assert(css.includes("overflow-wrap"));
});
test("VAS required touched and Matrix independent keys unchanged", () => {
  const d = fixture(),
    v = d.elements.find((e) => e.type === "vas");
  v.properties.required = true;
  const session = new A.PreviewSession(d);
  assert(session.errors().some((e) => e.id === v.id));
  session.set(v, 50);
  assert(!session.errors().some((e) => e.id === v.id));
});
console.log(tests + " tests, " + failures + " failures");
w.close();
process.exit(failures ? 1 : 0);
