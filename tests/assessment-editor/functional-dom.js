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
require("../i18n1b/admin-language.js")(dom.window);
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
      fs.readFileSync(path.join(__dirname, "functional-fixture.json")),
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
for (const width of [320, 480, 800])
  for (const close of [false, true])
    test("one global VAS size at " + width + " near=" + close, () => {
      const p = one("vas").properties;
      if (close) p.label_settings[2].position = 25.5;
      const result = G.layout(p, { start: 43, width: width - 86 }, width);
      assert.equal(new Set(result.map((g) => g.fontSize)).size, 1);
      result.forEach((g, i) =>
        assert.equal(g.anchor, 43 + G.ratio(p, i) * (width - 86)),
      );
      assert(result.every((g) => g.maxWidth > 0 && g.maxWidth <= 220));
    });
test("common font changes with user scale without changing anchors", () => {
  const p = one("vas").properties;
  p.label_scale = "small";
  const a = G.layout(p, { start: 75, width: 650 }, 800);
  p.label_scale = "large";
  const b = G.layout(p, { start: 75, width: 650 }, 800);
  assert(b[0].fontSize > a[0].fontSize);
  a.forEach((g, i) => assert.equal(g.anchor, b[i].anchor));
});
test("Matrix row derived variables absent in Canvas and Preview", () => {
  const m = one("matrix"),
    canvas = A.widget(m, null, { row: () => {} }),
    preview = A.widget(m, new A.PreviewSession(fixture()));
  for (const n of [canvas, preview]) {
    assert(!n.querySelector(".ae-derived-variable"));
    assert(!n.textContent.includes("phq_1"));
    assert(!n.textContent.includes("(7)"));
  }
});
test("Likert arbitrary and fallback values independent from labels", () => {
  const e = one("likert");
  e.options[1].value = null;
  assert.equal(A.controls.answerValue(e, e.options[0].id), 0);
  assert.equal(A.controls.answerValue(e, e.options[1].id), "A veces");
  assert.equal(A.controls.answerValue(e, e.options[2].id), 7);
  const n = A.widget(e);
  assert(!n.querySelector(".ae-likert-value"));
  assert(n.textContent.includes("Nunca"));
});
test("Likert reverse preserves option IDs and arbitrary values", () => {
  const e = one("likert");
  e.properties.reversed = true;
  const n = A.widget(e);
  assert.equal(n.querySelector("input").value, e.options[2].id);
  assert.equal(A.controls.answerValue(e, e.options[2].id), 7);
});
test("historical min projection normalized explicitly without changing identities", () => {
  const d = fixture(),
    e = d.elements.find((e) => e.type === "likert");
  e.properties.min = 4;
  e.options.forEach((o) => delete o.value);
  const n = A.normalizeDefinition(d).elements.find((e) => e.type === "likert");
  assert.deepEqual(plain(n.options.map((o) => o.value)), [4, 5, 6]);
  assert.equal(n.options[0].id, e.options[0].id);
});
test("rich icon inside field has tooltip and no long button text", () => {
  const n = H.editor(one("text").properties, "label", "Question", () => {}),
    b = n.querySelector("button");
  assert.equal(b.textContent, "✎");
  assert.equal(b.title, "Editar formato enriquecido");
  assert(b.getAttribute("aria-label").includes("Editar formato enriquecido"));
});
test("WordPress classic editor initialized and destroyed transactionally", () => {
  const p = one("text").properties;
  let initialized, removed;
  w.wp = {
    editor: {
      initialize: (id, options) => {
        initialized = { id, options };
      },
      remove: (id) => (removed = id),
    },
  };
  w.tinymce = { get: (id) => ({ getContent: () => "<p><b>Classic</b></p>" }) };
  const modal = H.openModal(p, "label", "Question", () => {});
  assert(initialized.options.tinymce.toolbar1.includes("undo redo"));
  assert(initialized.options.mediaButtons);
  click(modal.dialog, "Guardar formato");
  assert.equal(removed, initialized.id);
  assert(p.label_html.includes("<b>Classic</b>"));
  delete w.wp;
  delete w.tinymce;
});
test("stable page identity/title follows PageBreak under insertion and reorder", () => {
  const d = fixture(),
    id = d.flow.pages[1].id,
    title = d.flow.pages[1].title,
    pb = d.elements.find((e) => e.type === "page_break");
  A.move(d, pb.id, null, d.elements.length);
  w.EIPSIAssessmentFlow.normalize(d);
  assert.equal(d.flow.pages.find((p) => p.id === id).title, title);
  assert.equal(d.flow.pages.find((p) => p.id === id).boundary_id, pb.id);
});
test("page descriptions progress and title render in Preview", () => {
  const p = A.openPreview(fixture());
  assert(p.dialog.textContent.includes("Inicio"));
  assert(p.dialog.textContent.includes("Instrucciones de la página 1"));
  assert(p.dialog.querySelector("progress"));
  assert(p.dialog.querySelector(".ae-page-count"));
  p.close();
});
test("back disabled prevents API navigation and hides Previous", () => {
  const d = fixture();
  d.flow.allow_back = false;
  const p = A.openPreview(d);
  assert(
    ![...p.dialog.querySelectorAll("button")].some(
      (n) => n.textContent === "Anterior",
    ),
  );
  p.session.next();
  assert.equal(p.session.page, 1);
  p.session.previous();
  assert.equal(p.session.page, 1);
  p.close();
});
test("required question blocks Next without changing page", () => {
  const d = fixture();
  d.elements[0].properties.required = true;
  const s = new A.PreviewSession(d);
  assert.equal(s.next(), false);
  assert.equal(s.page, 0);
  s.set(d.elements[0], "ok");
  assert.equal(s.next(), true);
});
test("completion and configurable restart without fabricated PageBreak", () => {
  const d = fixture(),
    p = A.openPreview(d);
  click(p.dialog, "Siguiente");
  click(p.dialog, "Siguiente");
  click(p.dialog, "Terminar");
  assert(p.dialog.textContent.includes("Gracias"));
  assert(p.dialog.textContent.includes("Fin de la"));
  assert(!p.dialog.textContent.includes("Responder nuevamente"));
  assert(p.session.finished);
  p.close();
});
test("restart disabled removes restart and custom button closes Preview", () => {
  const d = fixture();
  d.flow.completion.allow_restart = false;
  d.flow.completion.button_label = "Cerrar";
  const p = A.openPreview(d);
  assert(
    ![...p.dialog.querySelectorAll("button")].some(
      (n) => n.textContent === "Reiniciar",
    ),
  );
  click(p.dialog, "Siguiente");
  click(p.dialog, "Siguiente");
  click(p.dialog, "Terminar");
  click(p.dialog, dom.window.EIPSIAdminI18n.t("Close preview"));
  assert(!p.dialog.isConnected);
});
test("Partial saving OFF still retains temporary questionnaire session", () => {
  const d = fixture();
  d.flow.save_continue.enabled = false;
  const s = new A.PreviewSession(d),
    r = new A.RecoverySimulation();
  assert.equal(r.save(s), true);
  assert(r.partial);
  assert.equal(r.savedPartials.length, 0);
  const p = A.openPreview(d);
  assert(p.dialog.textContent.includes("Salir del cuestionario"));
  p.close();
});
test("save resume retains page answers metadata original timestamps and back policy", () => {
  let now = 1000;
  const d = fixture();
  d.flow.allow_back = false;
  const s = new A.PreviewSession(d);
  s.metadata = new A.MetadataRecorder(s.definition, () => now);
  s.metadata.present(s.definition.flow.pages[0].id, s.pages[0]);
  s.set(d.elements[0], "secret final");
  now = 1200;
  s.next();
  const r = new A.RecoverySimulation(() => now);
  s.metadata.leave("suspend");
  r.save(s);
  const first = s.metadata.data.started_at;
  s.answers = {};
  s.page = 0;
  now = 1800;
  assert(r.resume(s));
  assert.equal(s.page, 1);
  assert.equal(s.answers[d.elements[0].response_key], "secret final");
  assert.equal(s.metadata.data.started_at, first);
  assert.equal(s.metadata.data.recovery_count, 1);
  assert(s.metadata.data.pages[s.definition.flow.pages[1].id].resumed_here);
  s.previous();
  assert.equal(s.page, 1);
});
test("session window restarts from the latest exit independently of saved records", () => {
  let now = 1000;
  const s = new A.PreviewSession(fixture()),
    r = new A.RecoverySimulation(() => now);
  r.save(s);
  const end = r.partial.expires_at;
  now = 2000;
  r.save(s);
  assert.equal(r.partial.expires_at, end + 1000);
  r.expire();
  assert.equal(r.resume(s), false);
  assert.equal(r.partial, null);
});
test("definition change invalidates simulated recovery", () => {
  const s = new A.PreviewSession(fixture()),
    r = new A.RecoverySimulation();
  r.save(s);
  s.definition.title = "Changed";
  assert.equal(r.resume(s), false);
  assert.equal(r.partial, null);
});
test("Preview leave resume and expiration controls preserve isolation", () => {
  const p = A.openPreview(fixture()),
    e = p.session.definition.elements[0];
  p.session.set(e, "answer");
  click(p.dialog, "Siguiente");
  click(p.dialog, "Salir del cuestionario");
  assert.equal(Object.keys(p.session.answers).length, 0);
  click(p.dialog, "Volver al cuestionario");
  assert.equal(p.session.page, 1);
  assert.equal(p.session.answers[e.response_key], "answer");
  click(p.dialog, "Salir del cuestionario");
  p.session.definition.flow.completion.allow_restart = false;
  p.dialog.querySelector('[aria-label="Tiempo transcurrido fuera"]').value = "11";
  click(p.dialog, "Volver al cuestionario");
  assert.equal(p.session.page, 0);
  assert.equal(Object.keys(p.session.answers).length, 0);
  assert.equal(Object.keys(p.session.touched).length, 0);
  assert.equal(p.session.finished, false);
  assert.equal(p.session.metadata.data.recovery_count, 0);
  assert.equal(p.recovery.partial, null);
  assert(p.dialog.querySelector("#preview-" + e.id));
  assert(p.dialog.textContent.includes("Empezá nuevamente"));
  p.session.set(e, "new answer");
  assert.equal(p.session.answers[e.response_key], "new answer");
  assert(p.dialog.querySelector("fieldset legend").textContent.includes("pantalla"));
  p.close();
  assert.equal(p.recovery.partial, null);
});
for (const profile of ["basic", "standard", "detailed"])
  test(
    "transversal metadata profile " +
      profile +
      " for six representative controls",
    () => {
      let now = 100;
      const d = A.assessment();
      d.flow.metadata_profile = profile;
      for (const type of [
        "text",
        "single_choice",
        "multiple_choice",
        "likert",
        "vas",
        "matrix",
      ])
        add(d, type);
      const s = new A.PreviewSession(d);
      s.metadata = new A.MetadataRecorder(s.definition, () => now);
      s.metadata.present(s.definition.flow.pages[0].id, s.pages[0]);
      for (const e of d.elements) {
        now += 10;
        if (e.type === "matrix")
          e.properties.matrix_rows.forEach((r) =>
            s.set({ ...e, response_key: r.response_key }, e.options[1].id),
          );
        else
          s.set(
            e,
            e.type === "text"
              ? "private text"
              : e.type === "vas"
              ? 50
              : e.type === "multiple_choice"
              ? [e.options[0].id]
              : e.options[0].id,
          );
      }
      const result = s.metadata.snapshot();
      assert.equal(Object.keys(result.items).length, 7);
      for (const item of Object.values(result.items)) {
        assert(item.first_interaction_at >= 110);
        assert(item.item_id);
        assert(item.response_key);
        assert.equal(item.interaction_count, 1);
        assert.equal(item.trajectory.length, profile === "detailed" ? 1 : 0);
      }
      assert(!JSON.stringify(result).includes("private text"));
      assert.equal(!!result.context, profile !== "basic");
      assert.equal(result.events.length > 0, profile !== "basic");
    },
  );
test("text intermediate privacy and same-length change counts", () => {
  const d = fixture(),
    s = new A.PreviewSession(d),
    e = d.elements[0];
  s.set(e, "secret1");
  s.set(e, "secret2");
  const m = s.metadata.snapshot();
  assert.equal(m.items[e.response_key].change_count, 2);
  assert(!JSON.stringify(m).includes("secret"));
  assert.deepEqual(plain(m.items[e.response_key].final_state), { length: 7 });
});
test("detailed trajectories preserve Likert Multiple VAS states with volume bound", () => {
  let now = 0;
  const d = fixture(),
    s = new A.PreviewSession(d);
  s.metadata = new A.MetadataRecorder(s.definition, () => now);
  for (let page = 0; page < s.pages.length; page++)
    s.metadata.present(s.definition.flow.pages[page].id, s.pages[page]);
  const v = d.elements.find((e) => e.type === "vas");
  for (let i = 0; i < 5000; i++) {
    now += 300;
    s.set(v, i % 101);
  }
  const m = s.metadata.snapshot();
  assert.equal(m.items[v.response_key].trajectory.length, 128);
  assert.equal(m.events.length, 2048);
  assert(m.dropped_events > 0);
  assert.equal(m.items[v.response_key].interaction_count, 5000);
});
test("focus duration transitions and completion timestamps accumulate", () => {
  let now = 100;
  const d = fixture(),
    m = new A.MetadataRecorder(d, () => now);
  m.present(d.flow.pages[0].id, A.pages(d)[0]);
  const key = d.elements[0].response_key;
  m.focus(key, true, "keyboard");
  now = 200;
  m.focus(key, false);
  m.leave("next");
  assert.equal(m.data.items[key].focus_duration, 100);
  assert.equal(m.data.pages[d.flow.pages[0].id].next_transitions, 1);
  now = 500;
  m.complete();
  assert.equal(m.data.duration, 400);
  assert.equal(m.data.status, "completed");
});
test("shared participant adapter renders real Select VAS Multiple Date URL Matrix widgets", () => {
  const d = fixture();
  d.elements = d.elements.filter((e) => e.type !== "page_break");
  const host = doc.createElement("div");
  doc.body.append(host);
  let changed = 0;
  const view = A.mountParticipant(host, d, { onChange: () => changed++ });
  assert(host.querySelector("select"));
  assert(host.querySelector("input[type=range]"));
  assert(host.querySelector("input[type=checkbox]"));
  assert(host.querySelector("[data-widget=date] input"));
  assert(host.querySelector("input[type=url]"));
  assert(host.querySelector("table"));
  assert(!host.querySelector(".ae-derived-variable"));
  view.session.set(d.elements[0], "final");
  assert.equal(changed, 1);
  assert.equal(view.values()[d.elements[0].response_key], "final");
  view.destroy();
  host.remove();
});
test("settings expose one retention window and no infrastructure auth TTLs", () => {
  const host = doc.createElement("div");
  A.flowInspector(fixture(), host, () => {});
  assert(host.querySelector('[data-flow-property="allow_back"]'));
  assert(host.querySelector('[data-flow-property="unit"]'));
  assert.equal(host.querySelectorAll('input[name="ae-metadata-profile"]').length,3);
  assert(
    !/provisional_hours|session_days|capability_hours/.test(host.textContent),
  );
});
test("large Assessment metadata remains bounded and Preview construction is independent", () => {
  const d = A.assessment();
  d.flow.metadata_profile = "detailed";
  for (let i = 0; i < 250; i++) add(d, "text");
  const before = A.serialize(d),
    s = new A.PreviewSession(d);
  assert.equal(Object.keys(s.metadata.data.items).length, 250);
  s.set(d.elements[0], "large review");
  assert.equal(A.serialize(d), before);
  assert(s.metadata.data.events.length <= 2048);
});
test("classic modal Media Library inserts sanitized image at bookmark", () => {
  const p = one("text").properties;
  let select, close, inserted;
  const ed = {
    getContent: () => inserted || "",
    selection: { getBookmark: () => ({}), moveToBookmark: () => {} },
    insertContent: (html) => (inserted = html),
  };
  w.wp = {
    editor: { initialize: () => {}, remove: () => {} },
    media: () => ({
      on: (event, fn) => {
        if (event === "select") select = fn;
        else if (event === "close") close = fn;
      },
      open: () => {},
      state: () => ({
        get: () => ({
          first: () => ({
            toJSON: () => ({
              url: "https://example.test/image.png",
              alt: "Safe image",
            }),
          }),
        }),
      }),
    }),
  };
  w.tinymce = { get: () => ed };
  const m = H.openModal(p, "label", "Question", () => {});
  click(m.dialog, "Imagen · Media Library");
  select();
  assert(inserted.includes("<img"));
  assert(inserted.includes("Safe image"));
  close();
  click(m.dialog, "Guardar formato");
  assert(p.label_html.includes("<img"));
  assert(!doc.body.classList.contains("ae-rich-media-open"));
  delete w.wp;
  delete w.tinymce;
});
test("standalone shared widget artifact runs without authoring UI or network", () => {
  const isolated = new JSDOM('<div id="participant"></div>', {
    url: "http://localhost/",
    runScripts: "outside-only",
  });
require("../i18n1b/admin-language.js")(isolated.window);
  Object.defineProperty(isolated.window, "crypto", { value: webcrypto });
  isolated.window.fetch = () => {
    throw Error("Unexpected transport");
  };
  isolated.window.eval(
    fs.readFileSync(
      path.join(base, "assets/js/assessment-participant.js"),
      "utf8",
    ),
  );
  const api = isolated.window.EIPSIAssessment;
  assert.equal(api.mount, undefined);
  assert.equal(api.openPreview, undefined);
  const view = api.mountParticipant(
    isolated.window.document.getElementById("participant"),
    fixture(),
  );
  assert(
    isolated.window.document.querySelector('[data-widget="matrix"] table'),
  );
  assert.equal(view.validate().length, 0);
  view.destroy();
  isolated.window.close();
});
test('long technical identities produce bounded stable page identities',()=>{const d=fixture();d.id='A'+'x'.repeat(99);d.flow.pages=[];const normalized=A.normalizeDefinition(d),id=normalized.flow.pages[0].id;assert(id.length<=100);assert.equal(A.normalizeDefinition(normalized).flow.pages[0].id,id);const clone=plain(normalized);clone.id='AE_COPY';assert.equal(A.normalizeDefinition(clone).flow.pages[0].id,id);});
for (const enabled of [false, true]) for (const expired of [false, true]) {
  test("Preview return matrix partial saving=" + enabled + " expired=" + expired, () => {
    const d = fixture();
    d.flow.save_continue.enabled = enabled;
    d.flow.save_continue.window = {value: 1, unit: "minutes"};
    const p = A.openPreview(d), item = p.session.definition.elements[0];
    p.session.set(item, "Previous answer");
    click(p.dialog, "Siguiente");
    click(p.dialog, "Salir del cuestionario");
    p.dialog.querySelector('[aria-label="Tiempo transcurrido fuera"]').value = expired ? "1" : "0.5";
    click(p.dialog, "Volver al cuestionario");
    assert.equal(p.session.page, expired ? 0 : 1);
    assert.equal(p.session.answers[item.response_key], expired ? undefined : "Previous answer");
    assert.equal(p.recovery.savedPartials.length, expired && enabled ? 1 : 0);
    if (expired && enabled) assert(p.dialog.textContent.includes("Las respuestas parciales de la sesión anterior quedaron guardadas."));
    if (expired && !enabled) assert(!p.dialog.textContent.includes("quedaron guardadas"));
    p.close();
  });
}
test("Preview return preserves completion regardless of simulated time", () => {
  const p = A.openPreview(fixture());
  while (!p.session.finished) click(p.dialog, p.session.page === p.session.pages.length - 1 ? "Terminar" : "Siguiente");
  click(p.dialog, "Salir del cuestionario");
  p.dialog.querySelector('[aria-label="Tiempo transcurrido fuera"]').value = "999";
  click(p.dialog, "Volver al cuestionario");
  assert(p.session.finished);
  assert(p.dialog.textContent.includes(p.session.definition.flow.completion.title));
  p.close();
});
console.log(tests + " tests, " + failures + " failures");
w.close();
process.exit(failures ? 1 : 0);
