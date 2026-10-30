"use strict";
const fs = require("fs"),
  path = require("path"),
  assert = require("assert/strict"),
  { JSDOM } = require("jsdom"),
  { webcrypto } = require("crypto");
const base = path.resolve(__dirname, "../..");
let tests = 0,
  failures = 0;
const fixture = () =>
  JSON.parse(
    fs.readFileSync(path.join(__dirname, "functional-fixture.json"), "utf8"),
  );
const plain = (v) => JSON.parse(JSON.stringify(v));
const tick = () => new Promise((r) => setTimeout(r, 0));
function env() {
  const dom = new JSDOM('<div id="root"></div>', {
    url: "http://localhost/wp-admin/",
    runScripts: "outside-only",
  });
require("../i18n1b/admin-language.js")(dom.window);
  const w = dom.window;
  Object.defineProperty(w, "crypto", { value: webcrypto });
  w.confirm = () => true;
  for (const file of JSON.parse(
    fs.readFileSync(
      path.join(base, "src/admin/assessment-editor/manifest.json"),
    ),
  ).entry_order)
    w.eval(
      fs.readFileSync(
        path.join(base, "src/admin/assessment-editor", file),
        "utf8",
      ),
    );
  w.eval(
    fs.readFileSync(
      path.join(base, "src/admin/assessment-editor/product-runtime.js"),
      "utf8",
    ),
  );
  return {
    dom,
    w,
    A: w.EIPSIAssessment,
    host: w.document.getElementById("root"),
  };
}
function click(host, label) {
  const button = [...host.querySelectorAll("button")].find(
    (b) => b.textContent === label,
  );
  assert(button, "Button " + label);
  button.click();
  return button;
}
async function test(name, fn) {
  tests++;
  let e;
  try {
    e = env();
    await fn(e);
    console.log("PASS " + name);
  } catch (error) {
    failures++;
    console.log("FAIL " + name + ": " + error.stack);
  } finally {
    e?.dom.window.close();
  }
}
async function editor(e, extra = null) {
  const d = fixture(),
    calls = [];
  if(extra)d.elements.push(extra);
  const api = async (op, data) => {
    calls.push([op, data]);
    if (op === "list") return [{ id: d.id, title: d.title, revision: 3 }];
    if (op === "load") return { definition: d, revision: 3 };
    if (op === "duplicate") {
      const copy = plain(d);
      copy.id = "AE_COPY";
      return { definition: copy, revision: 1 };
    }
    if (op === "delete") return { deleted: true };
    throw Error("Unexpected " + op);
  };
  const app = e.A.mount(e.host, {}, api);
  await tick();
  return { app, d, calls };
}
function frame(d) {
  return {
    execution_id: "NX_TEST",
    version_id: "NV_TEST",
    definition_hash: "a".repeat(64),
    definition: d,
    revision: 0,
    state: "ACTIVE",
    page: 0,
    partial: null,
    metadata: null,
    csrf: "session-csrf",
  };
}
async function runtime(e, d = fixture()) {
  const calls = [],
    f = frame(d);
  let current = plain(f);
  const api = async (q) => {
    calls.push(plain(q));
    if (q.action === "view") return plain(current);
    assert.equal(q.version_id, "NV_TEST");
    assert.equal(q.revision, current.revision);
    current.revision++;
    if (q.action === "open") {
      if (!current.partial) current.page = 0;
    } else if (q.action === "next") current.page++;
    else if (q.action === "back") current.page--;
    else if (q.action === "submit") {
      current.state = "COMPLETED";
      current.partial = null;
    }
    if (!["open", "submit"].includes(q.action))
      current.partial = {
        answers: q.answers,
        touched: q.touched,
        page: current.page,
        metadata: q.metadata,
      };
    current.metadata = q.metadata;
    return plain(current);
  };
  const view = e.A.mountProductRuntime(e.host, f, api);
  await view.ready;
  return { view, calls };
}
(async () => {
  await test("Library has Edit Duplicate Delete and no redundant return button", async (e) => {
    await editor(e);
    const row = e.host.querySelector(".ae-library-item");
    for (const label of ["Editar", "Duplicar", "Eliminar"])
      assert(
        [...row.querySelectorAll("button")].some(
          (b) => b.textContent === label,
        ),
      );
    assert([...e.host.querySelectorAll(".ae-more-actions-panel button")].find(b=>b.textContent==="Listado de evaluaciones").hidden);
  });
  await test("Library Edit opens existing Assessment rather than copy", async (e) => {
    const { app, d, calls } = await editor(e);
    click(e.host.querySelector(".ae-library-item"), "Editar");
    await tick();
    assert.equal(app.definition.id, d.id);
    assert(calls.some(([op]) => op === "load"));
    assert(!calls.some(([op]) => op === "duplicate"));
    assert(
      [...e.host.querySelectorAll("button")].some(
        (b) => b.textContent === "Listado de evaluaciones" && !b.hidden,
      ),
    );
  });
  await test("Library Duplicate opens independent server record", async (e) => {
    const { app, calls } = await editor(e);
    click(e.host.querySelector(".ae-library-item"), "Duplicar");
    await tick();
    assert.equal(app.definition.id, "AE_COPY");
    assert.equal(calls.find(([op]) => op === "duplicate")[1].revision, "3");
  });
  await test("Library delete confirmation names Assessment and cancellation sends nothing", async (e) => {
    const { d, calls } = await editor(e);
    let confirm;
    e.w.confirm = (text) => {
      confirm = text;
      return false;
    };
    click(e.host.querySelector(".ae-library-item"), "Eliminar");
    await tick();
    assert(confirm.includes(d.title));
    assert(!calls.some(([op]) => op === "delete"));
  });
  await test("Library confirmed deletion sends exact revision", async (e) => {
    const { calls } = await editor(e);
    click(e.host.querySelector(".ae-library-item"), "Eliminar");
    await tick();
    assert.equal(calls.find(([op]) => op === "delete")[1].revision, "3");
  });
  await test("Editor importer UI and source-ID prompt are absent", async (e) => {
    await editor(e);
    assert(!e.host.textContent.includes("Importar Gutenberg"));
    assert(
      !fs.existsSync(
        path.join(base, "includes/assessment-editor/GutenbergImporter.php"),
      ),
    );
  });
  await test("Rich icon visibility is focus-only with no layout shift", (e) => {
    const p = { label: "Pregunta", label_html: null };
    e.host.append(e.A.richText.editor(p, "label", "Pregunta", () => {}));
    const field = e.host.querySelector(".ae-content-field"),
      icon = field.querySelector(".ae-format-button");
    assert.equal(icon.title, "Editar formato enriquecido");
    const css = fs.readFileSync(
      path.join(base, "assets/css/assessment-editor.css"),
      "utf8",
    );
    assert(css.includes(".ae-content-field:focus-within > .ae-format-button"));
    assert(css.includes("opacity: 0; pointer-events: none"));
    assert(css.includes("position: absolute"));
    field.querySelector("textarea").focus();
    assert(field.contains(e.w.document.activeElement));
  });
  await test("Individual Likert labels are DOM-before controls and values stay hidden", (e) => {
    const q = fixture().elements.find((x) => x.type === "likert"),
      s = new e.A.PreviewSession(fixture());
    const widget = e.A.widget(q, s);
    widget.querySelectorAll(".ae-choice").forEach((label) => {
      assert.equal(label.firstElementChild.className, "ae-option-label");
      assert.equal(label.lastElementChild.type, "radio");
    });
    assert(!widget.textContent.includes("7"));
  });
  await test("Likert and Matrix share compact option cards with separate controls", async (e) => {
    const { app } = await editor(e);
    click(e.host.querySelector(".ae-library-item"), "Editar");
    await tick();
    for (const type of ["likert", "matrix"]) {
      const q = app.definition.elements.find((x) => x.type === type);
      e.host.querySelector('[data-id="' + q.id + '"]').click();
      const card = e.host.querySelector(".ae-inspector .ae-scale-option-card");
      assert(card);
      assert(card.querySelector(".ae-scale-option-actions"));
      assert.equal(card.querySelector("input[type=number]").step, "any");
    }
    const css = fs.readFileSync(
      path.join(base, "assets/css/assessment-editor.css"),
      "utf8",
    );
    assert(css.includes("width: 9ch"));
  });
  await test("Matrix derived row names remain absent in normal Canvas and Preview", async (e) => {
    const { app } = await editor(e);
    click(e.host.querySelector(".ae-library-item"), "Editar");
    await tick();
    assert(!e.host.querySelector(".ae-canvas").textContent.includes("phq_1"));
    const p = e.A.openPreview(app.definition);
    assert(!p.dialog.textContent.includes("phq_1"));
    p.close();
  });
  await test("Page header affordance opens page context and inline content updates", async (e) => {
    const { app } = await editor(e);
    click(e.host.querySelector(".ae-library-item"), "Editar");
    await tick();
    const page = e.host.querySelector(".ae-page-header");
    click(page, page.textContent.includes("Editar título") ? "Editar título" : "+ Agregar título");
    const input = e.host.querySelector('[aria-label="Título de página"]');
    input.value = "Título nuevo";
    input.dispatchEvent(new e.w.Event("input"));
    input.dispatchEvent(new e.w.Event("blur"));
    assert(page.textContent.includes("Título nuevo"));
    assert(!e.host.querySelector(".ae-inspector").querySelector('[aria-label="Título de página"]'));
    assert.equal(app.definition.flow.pages[0].title, "Título nuevo");
  });
  await test("PageBreak selects associated page context rather than global settings", async (e) => {
    const { app } = await editor(e);
    click(e.host.querySelector(".ae-library-item"), "Editar");
    await tick();
    const br = app.definition.elements.find((x) => x.type === "page_break");
    e.host.querySelector('[data-id="' + br.id + '"]').click();
    assert(e.host.querySelector(".ae-inspector").textContent.includes("directamente en la página"));
    assert(!e.host.querySelector('input[name="ae-metadata-profile"]'));
  });
  await test("Completion card is after and outside main Assessment container", async (e) => {
    await editor(e);
    click(e.host.querySelector(".ae-library-item"), "Editar");
    await tick();
    const main = e.host.querySelector(".ae-assessment-container"),
      card = e.host.querySelector(".ae-completion-card");
    assert.equal(main.nextElementSibling, card);
    assert(!main.contains(card));
    card.querySelector('[contenteditable="true"],textarea').focus();
    assert(card.querySelector('[aria-label="Contenido de pantalla final"]'));
    assert(!e.host.querySelector('[aria-label="Título final"]'));
  });
  await test("Main container context excludes page and completion content", async (e) => {
    await editor(e);
    click(e.host.querySelector(".ae-library-item"), "Editar");
    await tick();
    e.host.querySelector(".ae-assessment-container").click();
    const inspector = e.host.querySelector(".ae-inspector");
    assert(inspector.textContent.includes("Configuración"));
    assert(!inspector.querySelector('[aria-label="Título de página"]'));
    assert(!inspector.querySelector('[aria-label="Título final"]'));
  });
  await test("Back Next Finish labels update Preview with empty defaults", (e) => {
    const d = fixture();
    d.flow.navigation_labels = {
      back: "Regresar",
      next: "Avanzar",
      finish: "Enviar",
    };
    const p = e.A.openPreview(d);
    assert(p.dialog.textContent.includes("Avanzar"));
    click(p.dialog, "Avanzar");
    assert(p.dialog.textContent.includes("Regresar"));
    click(p.dialog, "Avanzar");
    assert(p.dialog.textContent.includes("Enviar"));
    p.close();
  });
  await test("Back disabled hides custom Back label and prevents model regression", (e) => {
    const d = fixture();
    d.flow.allow_back = false;
    d.flow.navigation_labels.back = "Regresar";
    const p = e.A.openPreview(d);
    click(p.dialog, "Siguiente");
    assert(!p.dialog.textContent.includes("Regresar"));
    assert.equal(p.session.previous(), false);
    p.close();
  });
  await test("Metadata profile cards explain capture and text privacy without mixed recovery copy", (e) => {
    e.A.flowInspector(fixture(), e.host, () => {});
    assert.equal(e.host.querySelectorAll(".ae-metadata-profile").length, 3);
    assert(e.host.textContent.includes("no almacena cada tecla"));
    assert(!e.host.textContent.includes("Preview: simulación local"));
  });
  await test("Metadata human diagnostics have Evaluation Page Question sections and optional technical view", (e) => {
    const d = fixture(),
      s = new e.A.PreviewSession(d),
      q = d.elements[0];
    s.set(q, "Texto final");
    e.A.renderDiagnostics(e.host, s);
    assert(e.host.textContent.includes("Evaluación"));
    assert(e.host.textContent.includes("Primera interacción"));
    assert(e.host.textContent.includes("Tiempo hasta responder"));
    assert(!e.host.querySelector("details").open);
    assert(
      !e.host
        .querySelector(".ae-diagnostics-human")
        .textContent.includes("first_interaction_at"),
    );
  });
  await test("Detailed human trajectory uses labels and Text length without raw intermediate text", (e) => {
    const d = fixture(),
      s = new e.A.PreviewSession(d);
    s.set(d.elements[0], "INTERMEDIO PRIVADO");
    s.set(d.elements[1], d.elements[1].options[2].id);
    e.A.renderDiagnostics(e.host, s);
    const timeline = e.host.querySelectorAll(".ae-response-timeline");
    assert(timeline.length >= 2);
    const text = [...timeline].map((n) => n.textContent).join(" ");
    assert(text.includes("caracteres"));
    assert(!text.includes("INTERMEDIO PRIVADO"));
    assert(text.includes("Siempre"));
  });
  await test("Matrix diagnostics show individual friendly row variables without response keys", (e) => {
    const d = fixture(),
      s = new e.A.PreviewSession(d);
    e.A.renderDiagnostics(e.host, s);
    const human = e.host.querySelector(".ae-diagnostics-human");
    assert(human.textContent.includes("phq_1"));
    const row = d.elements[2].properties.matrix_rows[0];
    assert(!human.textContent.includes(row.response_key));
  });
  await test("Native participant real adapter mounts Select Date VAS Multiple Matrix and no author UI", async (e) => {
    const d = fixture();
    d.elements = d.elements.filter((x) => x.type !== "page_break");
    d.flow.pages = [];
    e.w.EIPSIAssessmentFlow.normalize(d);
    await runtime(e, d);
    for (const selector of [
      "select",
      "[data-widget=date] input",
      "input[type=range]",
      "input[type=checkbox]",
      "table",
    ])
      assert(e.host.querySelector(selector), selector);
    assert(!e.host.querySelector(".ae-format-button"));
    assert(!e.host.textContent.includes("Diagnóstico"));
  });
  await test("Native participant does not send a request for every input movement", async (e) => {
    const { view, calls } = await runtime(e);
    const q = view.session.definition.elements[0];
    for (let i = 0; i < 20; i++) view.session.set(q, "value " + i);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].action, "open");
  });
  await test("Native page transition flush carries same recorder and snapshot binding", async (e) => {
    const { view, calls } = await runtime(e);
    view.session.set(view.session.definition.elements[0], "Page answer");
    click(e.host, "Siguiente");
    await tick();
    assert.equal(calls.at(-1).action, "next");
    assert.equal(calls.at(-1).metadata.contract, "NATIVE_PROCESS_V1");
    assert.equal(calls.at(-1).version_id, "NV_TEST");
    assert.equal(view.session.page, 1);
  });
  await test("Automatic session flow without manual exit 0",async e=>{const {view,calls}=await runtime(e);assert.equal(view.save,undefined);assert.equal(view.returnToQuestionnaire,undefined);view.session.set(view.session.definition.elements[0],'Respuesta temporal');await view.heartbeat();assert.equal(calls.at(-1).action,'flush');assert(!e.host.textContent.includes('Volver al cuestionario'));assert(e.host.querySelector('input,textarea'));});
  await test("Automatic session flow without manual exit 1",async e=>{const {view,calls}=await runtime(e);assert.equal(view.save,undefined);assert.equal(view.returnToQuestionnaire,undefined);view.session.set(view.session.definition.elements[0],'Respuesta temporal');await view.heartbeat();assert.equal(calls.at(-1).action,'flush');assert(!e.host.textContent.includes('Volver al cuestionario'));assert(e.host.querySelector('input,textarea'));});
  await test("Native recovery restores answers page history and resume marker", async (e) => {
    const d = fixture(),
      f = frame(d),
      s = new e.A.PreviewSession(d);
    s.set(d.elements[0], "Recovered");
    s.next();
    f.partial = {
      answers: plain(s.answers),
      touched: plain(s.touched),
      page: 1,
      metadata: plain(s.metadata.snapshot()),
    };
    f.page = 1;
    let revision = 0;
    const view = e.A.mountProductRuntime(e.host, f, async (q) => ({
      ...f,
      revision: ++revision,
    }));
    await view.ready;
    assert.equal(view.session.page, 1);
    assert(Object.values(view.session.answers).includes("Recovered"));
    assert(view.session.metadata.data.recovery_used);
    assert.equal(
      view.session.metadata.data.started_at,
      s.metadata.data.started_at,
    );
  });
  await test("Native final flush shows completion only after confirmed persistence", async (e) => {
    const d = fixture();
    d.elements = [d.elements[0]];
    d.flow.pages = [];
    e.w.EIPSIAssessmentFlow.normalize(d);
    const { view, calls } = await runtime(e, d);
    view.session.set(d.elements[0], "Final");
    click(e.host, "Terminar");
    await tick();
    assert.equal(calls.at(-1).action, "submit");
    assert.equal(calls.at(-1).metadata.status, "completed");
    assert.equal(view.frame.state, "COMPLETED");
    assert(e.host.textContent.includes(d.flow.completion.title));
  });
  await test("Native ambiguous transport failure retries exact command identity rather than overwrite", async (e) => {
    const d = fixture(),
      f = frame(d),
      calls = [];
    let failure = true;
    const view = e.A.mountProductRuntime(e.host, f, async (q) => {
      calls.push(plain(q));
      if (q.action === "open") return { ...f, revision: 1 };
      if (failure) {
        failure = false;
        throw Error("Network lost");
      }
      return { ...f, revision: 2, page: 1 };
    });
    await view.ready;
    click(e.host, "Siguiente");
    await tick();
    assert.equal(e.host.querySelector('[role="status"]').textContent, "No se pudo confirmar el guardado de tus respuestas. Volvé a intentarlo con el mismo botón.");
    assert(!e.host.textContent.includes("Reintentar guardado"));
    assert(e.host.querySelector(".ae-nav-forward"));
    click(e.host, "Siguiente");
    await tick();
    assert.deepEqual(calls[1], calls[2]);
  });
  await test("Reopening completed native execution shows final without issuing an open mutation", async (e) => {
    const d = fixture(), f = frame(d);
    f.state = "COMPLETED";
    let calls = 0;
    const view = e.A.mountProductRuntime(e.host, f, async () => {
      calls++;
      throw Error("Completed execution rejects mutation");
    });
    await view.ready;
    assert.equal(calls, 0);
    assert(e.host.textContent.includes(d.flow.completion.title));
    assert(!e.host.textContent.includes("Reintentar guardado"));
  });
  await test("Empty PHP recovery collections remain keyed objects when answering after resume", async (e) => {
    const d = fixture(), f = frame(d), recorder = new e.A.MetadataRecorder(d);
    const metadata = plain(recorder.snapshot());
    metadata.pages = []; metadata.items = [];
    f.partial = {answers: [], touched: [], page: 0, metadata};
    let sent;
    const view = e.A.mountProductRuntime(e.host, f, async (q) => {
      sent = plain(q); return {...f, revision: 1};
    });
    await view.ready;
    const item = d.elements[0];
    view.session.set(item, "Answer after empty recovery");
    await view.heartbeat();
    assert.equal(sent.answers[item.response_key], "Answer after empty recovery");
    assert(sent.metadata.items[item.response_key]);
    assert(!Array.isArray(sent.touched));
  });
  await test("Automatic session flow without manual exit 2",async e=>{const {view,calls}=await runtime(e);assert.equal(view.save,undefined);assert.equal(view.returnToQuestionnaire,undefined);view.session.set(view.session.definition.elements[0],'Respuesta temporal');await view.heartbeat();assert.equal(calls.at(-1).action,'flush');assert(!e.host.textContent.includes('Volver al cuestionario'));assert(e.host.querySelector('input,textarea'));});
  for (const saved of [false, true]) await test("Native expiry response restarts automatically saved=" + saved, async (e) => {
    const d = fixture(), f = frame(d), calls = [];
    let expired = false;
    const view = e.A.mountProductRuntime(e.host, f, async (q) => {
      calls.push(plain(q));
      if (q.action === "open") return {...f, revision: calls.length, previous_partial_saved: expired && saved};
      expired = true;
      return {...f, state: "SESSION_EXPIRED", revision: calls.length, session_reset: true, previous_partial_saved: saved};
    });
    await view.ready;
    view.session.set(d.elements[0], "Old answer");
    await view.heartbeat();
    assert.equal(view.session.page, 0);
    assert.equal(Object.keys(view.session.answers).length, 0);
    assert.equal(calls.at(-1).action, "open");
    assert(e.host.textContent.includes(saved ? "quedaron guardadas" : "sesión anterior venció"));
  });
  await test("Native heartbeat batches session state without remounting or losing input focus", async (e) => {
    const {view, calls} = await runtime(e), item = view.session.definition.elements[0];
    view.session.set(item, "Active answer");
    const field = e.host.querySelector("input[type=text]") || e.host.querySelector("textarea");
    field.focus();
    await view.heartbeat();
    assert.equal(calls.at(-1).action, "flush");
    assert.equal(calls.at(-1).answers[item.response_key], "Active answer");
    assert(field.isConnected);
    assert.equal(e.w.document.activeElement, field);
  });
  await test("Automatic session flow without manual exit 3",async e=>{const {view,calls}=await runtime(e);assert.equal(view.save,undefined);assert.equal(view.returnToQuestionnaire,undefined);view.session.set(view.session.definition.elements[0],'Respuesta temporal');await view.heartbeat();assert.equal(calls.at(-1).action,'flush');assert(!e.host.textContent.includes('Volver al cuestionario'));assert(e.host.querySelector('input,textarea'));});
  await test("Session duration remains editable when permanent partial saving is OFF", async (e) => {
    const d = fixture(); d.flow.save_continue.enabled = false;
    e.A.flowInspector(d, e.host, () => {});
    const duration = e.host.querySelector('[aria-label="Conservar la sesión al salir durante"]');
    assert(duration && !duration.parentElement.parentElement.hidden);
    assert(e.host.textContent.includes("aunque el guardado parcial esté desactivado"));
  });
  await test("Rich modal can be reopened and inline edited repeatedly without losing formatting", async e => {
    const p = {label: "Original", label_html: null}; let changes = 0;
    const field = e.A.richText.editor(p, "label", "Pregunta", () => changes++); e.host.append(field);
    click(field, "✎");
    let modal = e.w.document.querySelector(".ae-rich-modal");
    const edit = modal.querySelector('[contenteditable="true"]');
    edit.innerHTML = "<p><strong>Primero</strong></p>"; edit.dispatchEvent(new e.w.Event("input"));
    click(modal, "Guardar formato");
    const inline = field.querySelector('[contenteditable="true"]');
    inline.innerHTML = "<p><strong>Segundo</strong></p><div>Otra línea</div>"; inline.dispatchEvent(new e.w.Event("input"));
    assert(p.label_html.includes("<strong>Segundo</strong>")); assert(p.label.includes("Segundo")); assert(p.label_html.includes("<p>Otra línea</p>"));
    click(field, "✎"); modal = e.w.document.querySelector(".ae-rich-modal");
    assert(modal.querySelector('[contenteditable="true"]').textContent.includes("Segundo"));
    click(modal, "Cancelar"); assert(changes >= 2);
  });
  await test("Inline rich paste removes scripts handlers and unsafe URLs before inserting", async e => {
    const p = {label: "", label_html: "<p><strong>Texto</strong></p>"};
    const field = e.A.richText.editor(p, "label", "Pregunta", () => {}); e.host.append(field);
    const edit = field.querySelector('[contenteditable="true"]'), event = new e.w.Event("paste", {cancelable:true});
    Object.defineProperty(event, "clipboardData", {value:{getData: type => type === "text/html" ? '<p onclick="alert(1)"><script>x</script><a href="javascript:alert(1)">Seguro</a></p>' : ""}});
    edit.dispatchEvent(event);
    assert(event.defaultPrevented); assert(!edit.querySelector("script,[onclick],[href^='javascript:']"));
    assert(p.label_html.includes("Seguro"));
  });
  await test("Multiline author fields start at one row and grow after input without mouse resize", async e => {
    const field = e.A.richText.editor({label:"", label_html:null}, "label", "Pregunta", () => {}); e.host.append(field);
    const input = field.querySelector("textarea");
    let height = 28; Object.defineProperty(input, "scrollHeight", {get: () => height});
    e.A.autoGrow(input); assert.equal(input.rows,1); assert.equal(input.style.resize,"none");
    height = 72; input.value = "Una línea\nOtra línea"; input.dispatchEvent(new e.w.Event("input"));
    assert.equal(input.style.height,"72px"); height = 28; input.value = "Corta"; input.dispatchEvent(new e.w.Event("input"));
    assert.equal(input.style.height,"28px");
  });
  await test("Page description edits in place independently of title", async e => {
    const {app} = await editor(e); click(e.host.querySelector(".ae-library-item"),"Editar"); await tick();
    const page = e.host.querySelector(".ae-page-header"), title = app.definition.flow.pages[0].title;
    click(page, page.textContent.includes("Editar descripción") ? "Editar descripción" : "+ Agregar descripción");
    const input = page.querySelector('[aria-label="Descripción de página"]');
    if (input.tagName === "TEXTAREA") input.value = "Descripción nueva"; else input.innerHTML = "<p>Descripción nueva</p>";
    input.dispatchEvent(new e.w.Event("input"));
    assert(app.definition.flow.pages[0].description_html.includes("Descripción nueva"));
    assert.equal(app.definition.flow.pages[0].title,title);
    assert(!e.host.querySelector(".ae-inspector").querySelector('[aria-label="Descripción de página"]'));
  });
  await test("Completion unified rich content replaces old title and image without duplication", async e => {
    const {app} = await editor(e); click(e.host.querySelector(".ae-library-item"),"Editar"); await tick();
    const card = e.host.querySelector(".ae-completion-card"), input = card.querySelector('[aria-label="Contenido de pantalla final"]');
    input.innerHTML = '<h2>Listo</h2><p>Gracias</p><img src="/wp-content/uploads/review.png">'; input.dispatchEvent(new e.w.Event("input"));
    assert.equal(app.definition.flow.completion.title,""); assert.equal(app.definition.flow.completion.image_url,"");
    assert(app.definition.flow.completion.message_html.includes("review.png"));
    const p = e.A.openPreview(app.definition); click(p.dialog,"Salir del cuestionario"); p.session.finished = true; click(p.dialog,"Volver al cuestionario");
    assert(p.dialog.querySelector('.ae-preview-frame img[src="/wp-content/uploads/review.png"]')); p.close();
  });
  await test("Navigation defaults use placeholders and session duration shares a row", async e => {
    const d = fixture(); e.A.flowInspector(d,e.host,()=>{});
    for (const [key,label] of [["back","Anterior"],["next","Siguiente"],["finish","Terminar"]]) {
      const input = e.host.querySelector('[data-flow-property="'+key+'"]'); assert.equal(input.placeholder,label);
      assert(!input.parentElement.textContent.includes("("));
    }
    assert.equal(e.host.querySelector('.ae-session-window-row').querySelectorAll("label").length,2);
  });
  await test("Design presets and custom navigation persist into Preview without mutating source on open", async e => {
    const d = fixture(); e.A.flowInspector(d,e.host,()=>{});
    const preset = e.host.querySelector('[data-flow-property="preset"]'); preset.value = "Serene Teal"; preset.dispatchEvent(new e.w.Event("change"));
    assert.equal(d.flow.design.primary,"#0e7490");
    const align = e.host.querySelector('[data-flow-property="navigation_alignment"]'); align.value = "center"; align.dispatchEvent(new e.w.Event("change"));
    const before=JSON.stringify(d), p=e.A.openPreview(d);
    assert.equal(p.dialog.querySelector('.ae-preview-actions').dataset.alignment,"center");
    assert.equal(p.dialog.querySelector('.ae-designed').style.getPropertyValue('--ae-primary'),"#0e7490");
    assert.equal(JSON.stringify(d),before); p.close();
  });
  await test("Actual participant uses published design and forward action marker with Back OFF", async e => {
    const d=fixture(); d.flow.allow_back=false; d.flow.design=e.A.designDefaults(); d.flow.design.navigation_alignment="center";
    d.flow.design.primary="#0e7490";
    const r=await runtime(e,d);
    assert.equal(e.host.style.getPropertyValue('--ae-primary'),"#0e7490");
    assert.equal(e.host.querySelector('.ae-preview-actions').dataset.alignment,"center");
    assert(e.host.querySelector('.ae-nav-forward')); assert(!e.host.querySelector('.ae-nav-back'));
    assert(!e.host.querySelector('.ae-preview-actions .ae-session-exit'));
    r.view.destroy();
  });
  await test("Palette removes obsolete design placeholder while right inspector retains Design", async e => {
    await editor(e); click(e.host.querySelector(".ae-library-item"),"Editar"); await tick();
    assert(!e.host.querySelector(".ae-palette").textContent.includes("Diseño"));
    assert([...e.host.querySelectorAll(".ae-inspector summary")].some(n => n.textContent === "Diseño"));
  });
  await test("All property groups collapse independently and retain controls and open state", async e => {
    const {app}=await editor(e); click(e.host.querySelector(".ae-library-item"),"Editar"); await tick();
    const inspector=e.host.querySelector(".ae-inspector");
    assert.equal(inspector.querySelectorAll(":scope > h3").length,0);
    const groups=[...inspector.querySelectorAll(":scope > details")]; assert(groups.length>=4);
    const session=groups.find(n => n.firstElementChild.textContent === "Sesión del cuestionario y guardado parcial");
    assert(session && !session.open); session.open=true; session.dispatchEvent(new e.w.Event("toggle"));
    assert(session.querySelector('[aria-label="Conservar la sesión al salir durante"]'));
    const text=app.definition.elements.find(n=>n.type==="text"); e.host.querySelector('[data-id="'+text.id+'"]').click();
    assert(inspector.querySelector('details > summary')); assert(!inspector.querySelector(":scope > h3"));
    e.host.querySelector(".ae-assessment-container").click();
    const restored=[...inspector.querySelectorAll("details")].find(n=>n.firstElementChild.textContent==="Sesión del cuestionario y guardado parcial");
    assert(restored.open);
  });
  await test("Session duration accepts four digits and prevents overlong or fractional updates", async e => {
    const d=fixture(); e.A.flowInspector(d,e.host,()=>{});
    const input=e.host.querySelector('[aria-label="Conservar la sesión al salir durante"]');
    input.value="12345"; input.dispatchEvent(new e.w.Event("input")); assert.equal(input.value,"1234");
    input.dispatchEvent(new e.w.Event("change")); assert.equal(d.flow.save_continue.window.value,1234);
    input.value="1.5"; input.dispatchEvent(new e.w.Event("change")); assert.equal(d.flow.save_continue.window.value,1234);
  });
  await test("HTML mode switches back to visual with sanitized content and cancel preserves original", async e => {
    const p={label:"Original",label_html:"<p><strong>Original</strong></p>"}, before=JSON.stringify(p);
    const m=e.A.richText.openModal(p,"label","Texto",()=>{});
    click(m.dialog,"Editar HTML"); const source=m.dialog.querySelector('[aria-label="Código HTML"]');
    assert(!source.hidden); source.value='<h2>Nuevo</h2><script>bad</script><p onclick="bad()">Texto</p>';
    click(m.dialog,"Volver a visual"); const visual=m.dialog.querySelector('[contenteditable="true"]');
    assert.equal(visual.querySelector("h2").textContent,"Nuevo"); assert(!visual.querySelector("script,[onclick]"));
    click(m.dialog,"Cancelar"); assert.equal(JSON.stringify(p),before);
  });
  await test("Saving while in HTML mode persists clean source without requiring visual toggle", async e => {
    const p={label:"Original",label_html:null}; let calls=0;
    const m=e.A.richText.openModal(p,"label","Texto",()=>calls++); click(m.dialog,"Editar HTML");
    m.dialog.querySelector('[aria-label="Código HTML"]').value='<p><em>Editado</em><a href="javascript:alert(1)">Enlace</a></p>';
    click(m.dialog,"Guardar formato"); assert(p.label_html.includes("<em>Editado</em>")); assert(!p.label_html.includes("javascript:")); assert.equal(calls,1);
  });
  await test("Explicit link URL panel preserves selected rich text and supports editing existing URL", async e => {
    const p={label:"Texto",label_html:"<p><strong>Texto</strong></p>"};
    const m=e.A.richText.openModal(p,"label","Texto",()=>{}), visual=m.dialog.querySelector('[contenteditable="true"]');
    const selection=e.w.getSelection(), range=e.w.document.createRange(); range.selectNodeContents(visual.querySelector("strong")); selection.removeAllRanges(); selection.addRange(range);
    click(m.dialog,"Insertar o editar enlace");
    let url=m.dialog.querySelector('[aria-label="URL del enlace"]'); assert(!url.closest("fieldset").hidden);
    assert.equal(m.dialog.querySelector('[aria-label="Texto del enlace"]').value,"Texto");
    url.value="https://example.test/path"; click(m.dialog,"Aplicar enlace");
    let anchor=visual.querySelector("a"); assert.equal(anchor.getAttribute("href"),"https://example.test/path");
    range.selectNodeContents(anchor); selection.removeAllRanges(); selection.addRange(range);
    click(m.dialog,"Insertar o editar enlace"); url.value="https://example.test/new"; click(m.dialog,"Aplicar enlace");
    assert.equal(visual.querySelectorAll("a").length,1); assert.equal(visual.querySelector("a").getAttribute("href"),"https://example.test/new");
    click(m.dialog,"Guardar formato"); assert(p.label_html.includes("https://example.test/new"));
  });
  await test("Invalid link URL remains visible for correction and cancel does not modify content", async e => {
    const p={label:"Texto",label_html:"<p><strong>Texto</strong></p>"}, before=JSON.stringify(p);
    const m=e.A.richText.openModal(p,"label","Texto",()=>{}); click(m.dialog,"Insertar o editar enlace");
    m.dialog.querySelector('[aria-label="URL del enlace"]').value="javascript:alert(1)"; click(m.dialog,"Aplicar enlace");
    assert(m.dialog.querySelector('.ae-link-panel [role="alert"]').textContent.includes("URL válida"));
    assert(!m.dialog.querySelector('[contenteditable="true"] a'));
    click(m.dialog,"Cancelar enlace"); assert(m.dialog.querySelector(".ae-link-panel").hidden);
    click(m.dialog,"Cancelar"); assert.equal(JSON.stringify(p),before);
  });
  await test("TinyMCE route has explicit HTML and URL controls and restores selection bookmark", async e => {
    let html="<p>Original</p>", moved=false, inserted="", removed=false;
    const ed={getContent:()=>html,setContent:value=>{html=value;},focus:()=>{},selection:{getNode:()=>null,getBookmark:()=>({id:1}),getContent:()=>"<strong>Selección</strong>",moveToBookmark:b=>{assert.equal(b.id,1);moved=true;}},insertContent:value=>{inserted=value;html=value;}};
    e.w.wp={editor:{initialize:()=>{},remove:()=>{removed=true;}}}; e.w.tinymce={get:()=>ed};
    const p={label:"Original",label_html:html}, m=e.A.richText.openModal(p,"label","Texto",()=>{});
    click(m.dialog,"Insertar o editar enlace"); m.dialog.querySelector('[aria-label="URL del enlace"]').value="https://example.test";
    click(m.dialog,"Aplicar enlace"); assert(moved); assert(inserted.includes("<strong>Selección</strong>"));
    click(m.dialog,"Editar HTML"); m.dialog.querySelector('[aria-label="Código HTML"]').value="<h2>HTML clásico</h2>";
    click(m.dialog,"Volver a visual"); assert.equal(html,"<h2>HTML clásico</h2>");
    click(m.dialog,"Guardar formato"); assert.equal(p.label_html,html); assert(removed);
  });
  await test("VAS hidden value removes dependent controls and restores their previous settings", async e => {
    const {app}=await editor(e); click(e.host.querySelector(".ae-library-item"),"Editar"); await tick();
    const vas=app.definition.elements.find(n=>n.type==="vas");
    vas.properties.unanswered_value_message="Elegí un valor"; vas.properties.show_value=false; vas.properties.value_position="below"; vas.properties.value_font_size=48;
    e.host.querySelector('[data-id="'+vas.id+'"]').click();
    const inspector=e.host.querySelector(".ae-inspector");
    assert(!inspector.querySelector('[data-property="unanswered_value_message"]')); assert(!inspector.querySelector('[data-property="value_position"]')); assert(!inspector.querySelector('[data-property="value_font_size"]'));
    let show=inspector.querySelector('[data-property="show_value"]'); show.checked=true; show.dispatchEvent(new e.w.Event("change"));
    const text=inspector.querySelector('[data-property="unanswered_value_message"]');assert.equal(text.value,"Elegí un valor");assert.equal(text.closest('label').previousElementSibling.querySelector('input').dataset.property,'show_value');
    text.value='Tu valor';text.dispatchEvent(new e.w.Event('change'));assert.equal(e.A.widget(vas).querySelector('output').textContent,'Tu valor');
    const session=new e.A.PreviewSession(app.definition);session.set(vas,vas.properties.initial,true);assert.equal(e.A.widget(vas,session).querySelector('output').textContent,String(vas.properties.initial));
    assert.equal(inspector.querySelector('[data-property="value_position"]').value,"below");
    assert.equal(inspector.querySelector('[data-property="value_font_size"]').value,"48");
    show=inspector.querySelector('[data-property="show_value"]'); show.checked=false; show.dispatchEvent(new e.w.Event("change"));
    assert(!inspector.querySelector('[data-property="unanswered_value_message"]')); assert.equal(vas.properties.unanswered_value_message,"Tu valor");assert(!inspector.querySelector('[data-property="value_position"]')); assert.equal(vas.properties.value_font_size,48);
  });
  await test("Likert and Matrix remove redundant scale section without changing existing content", async e => {
    const {app}=await editor(e); click(e.host.querySelector(".ae-library-item"),"Editar"); await tick();
    for (const type of ["likert","matrix"]) {
      const node=app.definition.elements.find(n=>n.type===type); node.properties.reversed=true;
      const before=JSON.stringify(node);
      e.host.querySelector('[data-id="'+node.id+'"]').click(); const inspector=e.host.querySelector(".ae-inspector");
      assert(!inspector.textContent.includes("Escala Likert")); assert(!inspector.querySelector('[data-property="preset"],[data-property="reversed"]'));
      assert(!inspector.textContent.includes("Cada opción tiene etiqueta y valor independiente"));
      assert(inspector.querySelector('[aria-label="Valor analítico de opción 1"]'));
      assert.equal(JSON.stringify(node),before);
    }
  });
  await test("Matrix master requirement checks every item and individual exception is actually optional", async e => {
    const {app}=await editor(e); click(e.host.querySelector(".ae-library-item"),"Editar"); await tick();
    const node=app.definition.elements.find(n=>n.type==="matrix"); node.properties.required=false; node.properties.matrix_rows.forEach(r=>r.required=false);
    e.host.querySelector('[data-id="'+node.id+'"]').click(); const inspector=e.host.querySelector(".ae-inspector");
    let master=inspector.querySelector('[data-property="required"]'); master.checked=true; master.dispatchEvent(new e.w.Event("change"));
    assert(node.properties.matrix_rows.every(r=>r.required)); assert([...inspector.querySelectorAll('[aria-label^="Item obligatorio "]')].every(n=>n.checked));
    const optional=inspector.querySelector('[aria-label="Item obligatorio 1"]'); optional.checked=false; optional.dispatchEvent(new e.w.Event("change"));
    assert(!node.properties.required); assert(!node.properties.matrix_rows[0].required); assert(node.properties.matrix_rows.slice(1).every(r=>r.required));
    master=inspector.querySelector('[data-property="required"]'); assert(master.indeterminate);
    const d=e.A.assessment(); d.elements=[e.A.copy(node)]; const preview=new e.A.PreviewSession(d);
    assert.equal(preview.errors().length,node.properties.matrix_rows.length-1);
    node.properties.matrix_rows.slice(1).forEach(row=>preview.set({...node,response_key:row.response_key},node.options[0].id));
    assert.equal(preview.errors().length,0);
  });
  await test("New Matrix item inherits all-required and clearing master makes every item optional", async e => {
    const {app}=await editor(e); click(e.host.querySelector(".ae-library-item"),"Editar"); await tick();
    const node=app.definition.elements.find(n=>n.type==="matrix"); e.host.querySelector('[data-id="'+node.id+'"]').click();
    let master=e.host.querySelector('.ae-inspector [data-property="required"]'); master.checked=true; master.dispatchEvent(new e.w.Event("change"));
    click(e.host.querySelector(".ae-inspector"),"+ Agregar item"); assert(node.properties.matrix_rows.at(-1).required);
    master=e.host.querySelector('.ae-inspector [data-property="required"]'); master.checked=false; master.dispatchEvent(new e.w.Event("change"));
    assert(!node.properties.required); assert(node.properties.matrix_rows.every(r=>!r.required));
    const d=e.A.assessment();d.elements=[e.A.copy(node)]; assert.equal(new e.A.PreviewSession(d).errors().length,0);
    const first=e.host.querySelector('.ae-inspector [aria-label="Item obligatorio 1"]'); first.checked=true; first.dispatchEvent(new e.w.Event("change"));
    while(node.properties.matrix_rows.length>1) click([...e.host.querySelectorAll('.ae-inspector .ae-matrix-edit-row')].at(-1),"Quitar item");
    assert(e.host.querySelector('.ae-inspector [data-property="required"]').checked);
    click(e.host.querySelector(".ae-inspector"),"+ Agregar item"); assert(node.properties.matrix_rows.at(-1).required);
  });
  await test("Customizing a historical globally-required Matrix preserves the requirement on other items", async e => {
    const {app}=await editor(e); click(e.host.querySelector(".ae-library-item"),"Editar"); await tick();
    const node=app.definition.elements.find(n=>n.type==="matrix"); node.properties.required=true; node.properties.matrix_rows.forEach(r=>r.required=false);
    const before=JSON.stringify(node); e.host.querySelector('[data-id="'+node.id+'"]').click();
    assert.equal(JSON.stringify(node),before);
    const rows=[...e.host.querySelectorAll('.ae-inspector [aria-label^="Item obligatorio "]')]; assert(rows.every(r=>r.checked));
    rows[0].checked=false; rows[0].dispatchEvent(new e.w.Event("change"));
    assert(!node.properties.matrix_rows[0].required); assert(node.properties.matrix_rows.slice(1).every(r=>r.required));
    assert(!node.properties.required);
  });
  await test("General title checkbox defaults to visible without mutating historical definitions", async e => {
    const d=fixture(); delete d.flow.show_title; const before=JSON.stringify(d);
    e.A.flowInspector(d,e.host,()=>{});
    const check=e.host.querySelector('[aria-label="Mostrar título de la evaluación"]'); assert(check.checked);
    assert.equal(JSON.stringify(d),before);
    check.checked=false; check.dispatchEvent(new e.w.Event("change")); assert.equal(d.flow.show_title,false);
    check.checked=true; check.dispatchEvent(new e.w.Event("change")); assert.equal(d.flow.show_title,true);
  });
  await test("Title visibility applies consistently to Preview and real participant without changing answers", async e => {
    for (const visible of [false,true]) {
      const d=fixture(); d.flow.show_title=visible; const before=JSON.stringify(d);
      const p=e.A.openPreview(d);
      assert.equal([...p.dialog.querySelectorAll('.ae-preview-frame h2')].some(n=>n.textContent===d.title),visible);
      assert.equal(JSON.stringify(d),before); p.close();
      const r=await runtime(e,d); assert.equal([...e.host.querySelectorAll('h1')].some(n=>n.textContent===d.title),visible);
      assert.equal(Object.keys(r.view.session.answers).length,0); r.view.destroy();
    }
  });
  await test("Discard opt-in is absent by default and configurable without changing historical flow", async e => {
    const d=fixture(),before=JSON.stringify(d);e.A.flowInspector(d,e.host,()=>{});
    const c=e.host.querySelector('[aria-label="Permitir descartar respuesta"]');assert(!c.checked);assert.equal(JSON.stringify(d),before);c.click();assert.equal(d.flow.allow_discard,true);
  });
  await test("Preview discard erases only the current session and stays visible on completion", async e => {
    const d=fixture();d.flow.allow_discard=true;const p=e.A.openPreview(d);p.session.answers={R_TEST:"secret"};p.session.touched={R_TEST:true};p.session.finished=true;p.recovery.savedPartials=[{answers:{R_TEST:"saved"}}];
    p.dialog.querySelector('.ae-discard-response').click();click(p.dialog.querySelector('.ae-control-dialog'),'Aceptar');await tick();assert.equal(p.session.page,0);assert(!p.session.finished);assert.deepEqual(plain(p.session.answers),{});assert.equal(p.recovery.savedPartials.length,0);assert(p.dialog.querySelector('.ae-discard-response'));p.close();
  });
  await test("Participant discard is confirmed during an active attempt and resets through server command", async e => {
    const d=fixture();d.flow.allow_discard=true;let f=frame(d);f.can_discard=true;const calls=[];
    const view=e.A.mountProductRuntime(e.host,f,async q=>{calls.push(q.action);if(q.action==='discard'){f={...f,state:'ACTIVE',page:0,partial:null,session:null,metadata:null,revision:f.revision+1};}return plain(f);});await view.ready;
    e.w.confirm=()=>{throw Error('Native dialog forbidden');};e.host.querySelector('.ae-discard-response').click();click(e.host.querySelector('.ae-control-dialog'),'Cancelar');await tick();assert(!calls.includes('discard'));
    e.host.querySelector('.ae-discard-response').click();click(e.host.querySelector('.ae-control-dialog'),'Aceptar');await tick();await tick();assert(calls.includes('discard'));assert(calls.includes('open'));assert(!view.session.finished);assert.deepEqual(plain(view.session.answers),{});view.destroy();
  });
  await test('Completed Study assessment shows email correction before its thank-you',async e=>{
    const d=fixture();let f=frame(d);f.state='COMPLETED';f.study_url='https://local.invalid/?eipsi_study=PUBLIC_TEST';f.email_confirmation_html='<main class="eipsi-study eipsi-initial"><h1>Confirmá tu email</h1><form class="eipsi-study-form" method="post" target="_top" action="https://local.invalid/?eipsi_study=PUBLIC_TEST&amp;eipsi_completion=NX_TEST"><input name="email" type="email" value="dummy@example.test"><button name="action" value="confirm_email">Confirmar dirección</button></form></main>';
    const view=e.A.mountProductRuntime(e.host,f,async()=>plain(f));await view.ready;assert(e.host.querySelector('.ae-email-confirmation input[type=email]'));assert(!e.host.textContent.includes(d.title),'Assessment title leaked into email review');assert(!e.host.textContent.includes(d.flow.completion.title));assert.equal(e.host.querySelector('.ae-email-confirmation form').target,'_top');const transport=fs.readFileSync(path.join(base,'includes/assessment-editor/runtime/Transport.php'),'utf8');const stylePaths=[...transport.matchAll(/assets\/css\/[a-z-]+\.css/g)].map(m=>m[0]);assert(stylePaths.slice(0,2).includes('assets/css/study-runtime.css'),'Native document must load the confirmation stylesheet before it can arrive over AJAX');for(const asset of stylePaths.slice(0,2)){const style=e.w.document.createElement('style');style.textContent=fs.readFileSync(path.join(base,asset),'utf8');e.w.document.head.append(style);}const form=e.host.querySelector('.ae-email-confirmation form'),css=e.w.getComputedStyle(form);assert.equal(css.display,'flex');assert.equal(css.flexDirection,'column');assert.equal(css.gap,'12px');assert.equal(e.w.getComputedStyle(form.querySelector('button')).alignSelf,'flex-start');assert.equal(e.w.getComputedStyle(form.querySelector('input')).width,'100%');view.destroy();
  });
  await test('Native inline email correction uses the same empty field and update acknowledgement',async e=>{
    const d=fixture(),f=frame(d);f.state='COMPLETED';f.study_url='https://local.invalid/?eipsi_study=P';f.email_confirmation_html='<main><form data-previous-email="old@example.test"><input name="email" type="email" value="" autocomplete="off" required><input type="hidden" name="confirm_email_change" value="0"><button>Confirmar dirección</button></form></main>';const view=e.A.mountProductRuntime(e.host,f,async()=>plain(f));await view.ready;const form=e.host.querySelector('[data-previous-email]'),input=form.querySelector('[name=email]');assert.equal(input.value,'');input.value='different@example.test';e.w.confirm=()=>false;assert.equal(form.dispatchEvent(new e.w.Event('submit',{bubbles:true,cancelable:true})),false);assert.equal(form.querySelector('[name=confirm_email_change]').value,'0');form.addEventListener('submit',event=>event.preventDefault());form.querySelector('[data-accept-email-change]').click();assert.equal(form.querySelector('[name=confirm_email_change]').value,'1');view.destroy();
  });
  await test('Finishing cancels a pending logical debounce and completion has no actions',async e=>{let d=fixture();d.flow.allow_discard=true;d.elements=d.elements.filter(q=>q.type==='text');d.flow.pages=[];d=e.A.normalizeDefinition(d);let f=frame(d);f.has_logic=true;f.participant_contract='EIPSI_PARTICIPANT_FRAME_V2';let afterCompletion=0;const view=e.A.mountProductRuntime(e.host,f,async q=>{if(f.state==='COMPLETED'){afterCompletion++;throw Object.assign(new Error('Execution already complete'),{status:409});}if(q.action==='submit')f={...f,state:'COMPLETED',session:null,partial:null,revision:f.revision+1};return plain(f);});await view.ready;const input=e.host.querySelector('.ae-widget input');input.value='Valid response';input.dispatchEvent(new e.w.Event('input',{bubbles:true}));input.dispatchEvent(new e.w.Event('change',{bubbles:true}));e.host.querySelector('.ae-nav-forward').click();await new Promise(resolve=>setTimeout(resolve,350));assert.equal(view.frame.state,'COMPLETED');assert.equal(afterCompletion,0);assert(!e.host.textContent.includes(e.w.EIPSIContent.get('flow_messages',e.w.EIPSIContent.language(d)).delivery_error));assert.equal(e.host.querySelector('button'),null);assert(e.host.textContent.includes(d.flow.completion.title));view.destroy();});
  await test('A confirmed submit survives blocked History API without delivery error or repeat button',async e=>{let d=fixture();d.elements=d.elements.filter(q=>q.type==='text');d.flow.pages=[];d=e.A.normalizeDefinition(d);d.flow.completion.button_label='Responder nuevamente';let f=frame(d);f.participant_contract='NATIVE_PARTICIPANT_V1';let submits=0,historyWrites=0;const view=e.A.mountProductRuntime(e.host,f,async q=>{if(q.action==='submit'){submits++;f={...f,state:'COMPLETED',execution_id:'NX_DONE',revision:f.revision+1};}return plain(f);});await view.ready;e.w.history.replaceState=()=>{historyWrites++;throw new e.w.DOMException('History write blocked','SecurityError');};const input=e.host.querySelector('.ae-widget input');input.value='Valid response';input.dispatchEvent(new e.w.Event('input',{bubbles:true}));e.host.querySelector('.ae-nav-forward').click();await tick();await tick();assert.equal(submits,1);assert.equal(historyWrites,1);assert.equal(view.frame.state,'COMPLETED');assert(view.session.finished);assert(e.host.textContent.includes(d.flow.completion.title));assert(!e.host.textContent.includes(e.w.EIPSIContent.get('flow_messages',e.w.EIPSIContent.language(d)).delivery_error));assert(!e.host.textContent.includes('Responder nuevamente'));assert.equal(e.host.querySelector('.ae-preview-actions button'),null);view.destroy();});
  await test('Acknowledged commands do not rewrite an unchanged execution URL',async e=>{let d=fixture();d.elements=d.elements.filter(q=>q.type==='text');d.flow.pages=[];d=e.A.normalizeDefinition(d);const r=await runtime(e,d);let writes=0;e.w.history.replaceState=()=>{writes++;};const input=e.host.querySelector('.ae-widget input');input.value='Valid response';input.dispatchEvent(new e.w.Event('input',{bubbles:true}));e.host.querySelector('.ae-nav-forward').click();await tick();assert.equal(writes,0);assert.equal(r.view.frame.state,'COMPLETED');assert(e.host.textContent.includes(d.flow.completion.title));r.view.destroy();});
  await test('Completed Study does not repurpose a forbidden repeat label as a return button',async e=>{const d=fixture();d.flow.completion.button_label='Responder nuevamente';const f=frame(d);f.state='COMPLETED';f.study_url='http://localhost/?eipsi_study=PUBLIC_TEST';f.allow_study_repeat=false;const view=e.A.mountProductRuntime(e.host,f,async()=>plain(f));await view.ready;assert(!e.host.textContent.includes('Responder nuevamente'));assert(e.host.textContent.includes(d.flow.completion.title));assert.equal(e.w.location.search,'?eipsi_study=PUBLIC_TEST&eipsi_initial=1');assert.equal(e.host.querySelector('a'),null);view.destroy();});
  await test('Question messages and progress use researcher content',async e=>{const d=fixture(),q=d.elements[0];q.properties.required=true;q.properties.required_message='Mi obligatoriedad';q.properties.validation_message='Mi validación';q.properties.length_message='Mi longitud';q.properties.min_length=5;assert.equal(e.A.controls.answerError(q,'',true),'Mi obligatoriedad');assert.equal(e.A.controls.answerError(q,'a',true),'Mi longitud');d.flow.messages={progress:'Paso [ACTUAL] / [TOTAL]',updated:'Mi estado'};const {view}=await runtime(e,d);assert(e.host.textContent.includes('Paso 1 /'));assert(e.host.textContent.includes('Mi estado'));view.destroy();});
  await test('Text mode changes preserve both researcher messages for every text format',async e=>{const q=e.A.element('text');q.properties.required_message='Obligatorio propio';q.properties.validation_message='Formato propio';for(const format of ['email','url','tel','letters','number','free'])for(const mode of ['single','multiline']){e.A.textMode(q,mode,format);assert.equal(q.properties.required_message,'Obligatorio propio');assert.equal(q.properties.validation_message,'Formato propio');if(mode==='multiline'){assert.equal(q.type,'textarea');assert.equal(q.properties.content_type,'free');}} });
  await test('Multiple choice count limits use their own messages for zero one and excessive answers',async e=>{const q=e.A.element('multiple_choice');Object.assign(q.properties,{required:true,min_selected:2,max_selected:2,required_message:'Obligatorio',validation_message:'NO USAR',selection_min_message:'Mínimo [MINIMO]',selection_max_message:'Máximo [MAXIMO]'});q.options.push({id:'EXTRA',label:'Extra'});const ids=q.options.map(o=>o.id),C=e.A.controls;for(const v of [undefined,[],ids.slice(0,1)])assert.equal(C.answerError(q,v,true),'Mínimo 2');assert.equal(C.answerError(q,ids.slice(0,2),true),'');assert.equal(C.answerError(q,ids,true),'Máximo 2');assert(!C.answerError(q,['INVALID'],true).includes('NO USAR'));q.properties.min_selected=0;assert.equal(C.answerError(q,[],true),'Obligatorio');});
  await test('Only text formats expose validation messages and count messages follow configured limits',async e=>{for(const type of ['text','textarea','number','date','multiple_choice','likert','matrix','vas']){const q=e.A.element(type),keys=[];if(type==='multiple_choice'){q.properties.min_selected=2;q.properties.max_selected=2;}e.A.controlInspector(q,{inspector:e.w.document.createElement('div'),el:e.A.el,property:(_q,key)=>keys.push(key),options:()=>e.w.document.createElement('div'),semantic:()=>e.w.document.createElement('div'),rows:()=>e.w.document.createElement('div'),refresh:()=>{}});assert.equal(keys.includes('validation_message'),['text','number'].includes(type));if(type==='multiple_choice'){assert(keys.includes('selection_min_message'));assert(keys.includes('selection_max_message'));}}const date=e.A.element('date');date.properties.validation_message='NO USAR';assert.equal(e.A.controls.answerError(date,'invalid',true),'Ingresá una fecha válida.');});

  await test('Numeric widget preserves invalid input and separates validation from required messages',async e=>{const d=e.A.assessment(),q=e.A.element('number');Object.assign(q.properties,{required:true,required_message:'Falta respuesta',validation_message:'Número inválido',min:0,max:10,step:1,number_range_message:'Rango [MINIMO]-[MAXIMO]'});d.elements=[q];const session=new e.A.PreviewSession(d),widget=e.A.widget(q,session),input=widget.querySelector('input');assert.equal(input.inputMode,'decimal');for(const raw of ['abc','1e','-','.','0x10','1e9999']){input.value=raw;input.dispatchEvent(new e.w.Event('input',{bubbles:true}));assert.equal(session.answers[q.response_key],raw);assert.equal(session.errors()[0].message,'Número inválido');assert.equal(JSON.parse(JSON.stringify(session.answers))[q.response_key],raw);}input.value='';input.dispatchEvent(new e.w.Event('input'));assert.equal(session.answers[q.response_key],null);assert.equal(session.errors()[0].message,'Falta respuesta');q.properties.required=false;input.value='abc';input.dispatchEvent(new e.w.Event('input'));assert.equal(e.A.controls.answerError(q,session.answers[q.response_key],true),'Número inválido');for(const raw of ['2','2.5','11']){input.value=raw;input.dispatchEvent(new e.w.Event('input'));assert.equal(typeof session.answers[q.response_key],'number');assert.equal(e.A.controls.answerError(q,session.answers[q.response_key],true),raw==='11'?'Rango 0-10':'');}});
  await test('Server validation leaves a malformed numeric answer editable after an automatic flush',async e=>{const d=e.A.assessment(),q=e.A.element('number');q.properties.required_message='Obligatorio';q.properties.validation_message='Número inválido';d.elements=[q];const f=frame(d),view=e.A.mountProductRuntime(e.host,f,async request=>{if(request.action==='flush'){const error=new Error('Validation');error.status=400;error.validationErrors=[{id:q.id,message:'Número inválido'}];throw error;}return plain(f);});await view.ready;let input=e.host.querySelector('[data-widget=number] input');input.value='1e';input.dispatchEvent(new e.w.Event('input'));await view.heartbeat();input=e.host.querySelector('[data-widget=number] input');assert.equal(input.value,'1e');assert.equal(input.disabled,false);assert(e.host.textContent.includes('Número inválido'));input.value='5';input.dispatchEvent(new e.w.Event('input'));assert.equal(view.session.answers[q.response_key],5);view.destroy();});
  await test('Multiline length and internal value errors use their own researcher messages',async e=>{const q=e.A.element('textarea');Object.assign(q.properties,{min_length:3,max_length:5,length_message:'Entre [MINIMO] y [MAXIMO]'});assert.equal(e.A.controls.answerError(q,'a',true),'Entre 3 y 5');assert.equal(e.A.controls.answerError(q,'abcdef',true),'Entre 3 y 5');for(const type of ['date','vas','likert','select','multiple_choice']){const item=e.A.element(type);item.properties.invalid_value_message='Error propio';assert.equal(e.A.controls.answerError(item,'bad',true),'Error propio');}});
  await test('Empty image renders only editable plain text and accessible alternative',async e=>{const q=e.A.element('image');q.properties.empty_image_message='<b>Sin foto</b>';q.properties.alt='Alternativa propia';const widget=e.A.widget(q);assert.equal(widget.textContent,'<b>Sin foto</b>');assert(!widget.querySelector('b'));assert.equal(widget.querySelector('[role=img]').getAttribute('aria-label'),'Alternativa propia');});
  await test('Calendar uses researcher text navigates months selects ISO date and permits cancellation',async e=>{const d=e.A.assessment(),q=e.A.element('date');Object.assign(q.properties,{date_open_label:'Abrir fecha',date_close_label:'Salir fecha',date_previous_label:'Antes',date_next_label:'Después',date_month_names:'M1|M2|M3|M4|M5|M6|M7|M8|M9|M10|M11|M12',date_weekday_names:'D0|D1|D2|D3|D4|D5|D6',date_placeholder:'Fecha propia'});d.elements=[q];const session=new e.A.PreviewSession(d),widget=e.A.widget(q,session);e.host.append(widget);const input=widget.querySelector('input');assert.equal(input.placeholder,'Fecha propia');input.value='2028-02-10';click(widget,'Abrir fecha');assert(widget.textContent.includes('M2 2028'));assert(widget.textContent.includes('D0'));const day=[...widget.querySelectorAll('td button')].find(b=>b.textContent==='29');assert(day);day.click();assert.equal(session.answers[q.response_key],'2028-02-29');assert(!widget.querySelector('dialog').open);click(widget,'Abrir fecha');click(widget,'Después');assert(widget.textContent.includes('M3 2028'));click(widget,'Antes');assert(widget.textContent.includes('M2 2028'));click(widget,'Salir fecha');assert.equal(session.answers[q.response_key],'2028-02-29');q.properties.date_month_names=' ';q.properties.date_weekday_names=' ';const fallback=e.A.widget(q,session);e.host.append(fallback);click(fallback,'Abrir fecha');assert(fallback.textContent.includes('Febrero 2028'));assert(fallback.textContent.includes('Domingo'));click(fallback,'Salir fecha');});
  await test('Discard dialog exposes configured labels and cancellation never discards',async e=>{const labels={discard_confirm:'¿Borrar?',discard_cancel:'Conservar',discard_accept:'Eliminar'};let pending=e.A.confirmDiscard(e.host,k=>labels[k]);assert(e.host.textContent.includes('¿Borrar?'));click(e.host,'Conservar');assert.equal(await pending,false);pending=e.A.confirmDiscard(e.host,k=>labels[k]);click(e.host,'Eliminar');assert.equal(await pending,true);assert(!e.host.querySelector('dialog'));});

  await test('All character-limited text formats share the combined length message',async e=>{
    for(const type of ['text','textarea','email','tel','url']){const q=e.A.element(type);Object.assign(q.properties,{min_length:3,max_length:5,length_message:'Entre [MINIMO] - [MAXIMO]'});for(const answer of ['a','abcdef'])assert.equal(e.A.controls.answerError(q,answer,true),'Entre 3 - 5');}
  });
  await test('Assessment design exposes and applies per-device margins and centered width defaults',async e=>{
    const d=e.A.assessment(),node=e.w.document.createElement('div');e.A.applyDesign(node,d.flow.design);assert.equal(node.style.getPropertyValue('--ae-content-width'),'960px');assert.equal(node.style.getPropertyValue('--ae-mobile-margin'),'16px');const design={...e.A.designDefaults(),width:1100,desktop_margin:32,tablet_margin:20,mobile_margin:12,full_width:true};e.A.applyDesign(node,design);assert.equal(node.style.getPropertyValue('--ae-content-width'),'none');assert.equal(node.style.getPropertyValue('--ae-desktop-margin'),'32px');assert.equal(node.style.getPropertyValue('--ae-tablet-margin'),'20px');assert.equal(node.style.getPropertyValue('--ae-mobile-margin'),'12px');
  });
  await test('Simple Likert switches the entire scale between one row and one column without changing answers',async e=>{
    const q=e.A.element('likert');q.properties.presentation='horizontal';const d=e.A.assessment();d.elements=[q];const session=new e.A.PreviewSession(d);session.set(q,q.options[0].id);const old=e.w.HTMLElement.prototype.getBoundingClientRect;e.w.HTMLElement.prototype.getBoundingClientRect=function(){if(this.classList.contains('ae-likert-measure'))return {width:this.textContent.length*8};return old.call(this);};
    try{const widget=e.A.widget(q,session);e.host.replaceChildren(widget);let width=180;Object.defineProperty(widget,'clientWidth',{get:()=>width});const refresh=()=>widget.dispatchEvent(new e.w.Event('eipsi:likert-layout'));refresh();assert(widget.classList.contains('is-stacked'));refresh();assert(widget.classList.contains('is-stacked'));width=2000;refresh();assert(!widget.classList.contains('is-stacked'));assert(widget.querySelector('input').checked);assert.equal(session.answers[q.response_key],q.options[0].id);width=160;refresh();assert(widget.classList.contains('is-stacked'));assert.equal(widget.querySelectorAll('.ae-choice').length,q.options.length);assert(!widget.querySelector('.ae-likert-measure'));}finally{e.w.HTMLElement.prototype.getBoundingClientRect=old;}
  });
  await test('Date formats display localized dates while storing ISO values and rejecting impossible days',async e=>{
    const title=e.A.element('text');title.properties.required=true;title.properties.label_html='<p>Título <strong>rico</strong></p>';const rendered=e.w.document.createElement('div');e.A.renderParticipantElements([title],rendered,new e.A.PreviewSession(Object.assign(e.A.assessment(),{elements:[title]})));const star=rendered.querySelector('.ae-required');assert(star);assert.equal(star.parentElement.tagName,'P');assert(star.parentElement.textContent.includes('Título'));assert.equal(star.parentElement.firstChild,star);const matrix=e.A.element('matrix');matrix.properties.required=true;matrix.properties.matrix_rows[0].label_html='<p>Fila obligatoria</p>';const matrixView=e.A.widget(matrix,new e.A.PreviewSession(Object.assign(e.A.assessment(),{elements:[matrix]})));const matrixStar=matrixView.querySelector('.ae-required');assert.equal(matrixStar.parentElement.firstChild,matrixStar);assert(matrixStar.parentElement.textContent.startsWith('* '));

    for(const [format,display,typed] of [['DD/MM/YYYY','29/02/2028','01/03/2028'],['MM/DD/YYYY','02/29/2028','03/01/2028'],['YYYY-MM-DD','2028-02-29','2028-03-01']]){
      const nativeDefinition=e.A.assessment(),nativeDate=e.A.element('date');nativeDefinition.elements=[nativeDate];const original=JSON.stringify(nativeDefinition);const nativeFrame={...frame(nativeDefinition),study_date_format:format};const nativeView=e.A.mountProductRuntime(e.host,nativeFrame,async()=>nativeFrame);await nativeView.ready;const nativeInput=e.host.querySelector('[data-widget=date] input');nativeInput.value=display;nativeInput.dispatchEvent(new e.w.Event('input'));assert.equal(nativeView.session.answers[nativeDate.response_key],'2028-02-29');assert.equal(JSON.stringify(nativeDefinition),original);nativeView.destroy();
      const d=e.A.assessment(),q=e.A.element('date');q.properties.date_format=format;d.elements=[q];const session=new e.A.PreviewSession(d);session.set(q,'2028-02-29');const widget=e.A.widget(q,session);e.host.replaceChildren(widget);let input=widget.querySelector('input');assert.equal(input.value,display);input.value=typed;input.dispatchEvent(new e.w.Event('input'));assert.equal(session.answers[q.response_key],'2028-03-01');assert.equal(session.errors().length,0);
      input.value=format==='MM/DD/YYYY'?'02/30/2028':format==='DD/MM/YYYY'?'30/02/2028':'2028-02-30';input.dispatchEvent(new e.w.Event('input'));assert.equal(session.errors().length,1);
      input.value=typed;widget.querySelector('.ae-calendar-open').click();const day=[...widget.querySelectorAll('td button')].find(b=>b.textContent==='15');day.click();assert.equal(session.answers[q.response_key],'2028-03-15');assert.equal(input.value,format==='DD/MM/YYYY'?'15/03/2028':format==='MM/DD/YYYY'?'03/15/2028':'2028-03-15');
    }
  });
  await test('Progress text is hidden for bar and none, and appears for page counts',async e=>{
    const d=fixture();e.A.flowInspector(d,e.host,()=>{});const select=e.host.querySelector('[aria-label="Mostrar progreso"]'),input=e.host.querySelector('[aria-label="Texto de progreso — [ACTUAL] y [TOTAL]"]');
    for(const mode of ['bar','pages','both','none','bar']){select.value=mode;select.dispatchEvent(new e.w.Event('change'));assert.equal(Boolean(input.closest('[hidden]')),['bar','none'].includes(mode));}
  });
  await test('Image picker uses WordPress media and updates the selected image',async e=>{
    const image=e.A.element('image'),{app}=await editor(e,image);click(e.host.querySelector('.ae-library-item'),'Editar');await tick();const q=app.definition.elements.find(item=>item.id===image.id);e.host.querySelector('[data-id="'+q.id+'"]').click();let selected,opened=false;
    e.w.wp={media:config=>{assert.equal(config.library.type,'image');assert.equal(config.multiple,false);return {on:(event,fn)=>{assert.equal(event,'select');selected=fn;},open:()=>{opened=true;},state:()=>({get:()=>({first:()=>({toJSON:()=>({url:'http://localhost/wp-content/uploads/test.png',alt:'Imagen seleccionada'})})})})};}};
    click(e.host.querySelector('.ae-inspector'),'Elegir imagen en WordPress');assert(opened);selected();assert.equal(q.properties.src,'http://localhost/wp-content/uploads/test.png');assert.equal(q.properties.alt,'Imagen seleccionada');
  });
  console.log(tests + " tests, " + failures + " failures");
  process.exitCode = failures ? 1 : 0;
})();
