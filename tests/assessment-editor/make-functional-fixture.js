const fs = require("fs"),
  vm = require("vm"),
  { webcrypto } = require("crypto");
const ctx = { crypto: webcrypto, URL };
ctx.globalThis = ctx;
vm.createContext(ctx);
for (const file of ["flow.js", "variables.js", "controls.js", "model.js"])
  vm.runInContext(
    fs.readFileSync("src/admin/assessment-editor/" + file, "utf8"),
    ctx,
  );
const A = ctx.EIPSIAssessment,
  d = A.assessment();
d.id = "AE_FUNCTIONAL";
d.flow.pages=[];
d.title = "Demo descartable — cierre funcional";
for (const [index, type] of [
  "text",
  "likert",
  "matrix",
  "page_break",
  "vas",
  "date",
  "multiple_choice",
  "select",
  "url",
  "page_break",
  "textarea",
].entries()) {
  const e = A.element(type, d);
  e.id = "EL_FN_" + index;
  e.response_key = e.response_key ? "R_FN_" + index : null;
  e.options.forEach((o, i) => (o.id = "O_FN_" + index + "_" + i));
  if (type === "text") {
    e.properties.variable_name = "ansiedad";
    e.properties.label = "¿Cómo te sentís?";
    e.properties.label_html = "<p>¿Cómo <strong>te sentís</strong>?</p>";
    e.properties.footer_html = "<p>Podés revisar tu respuesta.</p>";
  }
  if (["likert", "matrix"].includes(type)) {
    e.options = e.options.slice(0, 3);
    e.options.forEach((o, i) => {
      o.label = ["Nunca", "A veces", "Siempre"][i];
      o.value = [0, 2, 7][i];
    });
    e.properties.label = "Frecuencia";
  }
  if (type === "matrix") {
    e.properties.variable_name = "phq";
    e.properties.matrix_rows.forEach((r, i) => {
      r.id = "ROW_FN_" + i;
      r.response_key = "R_ROW_FN_" + i;
    });
  }
  if (type === "vas") {
    e.properties.labels = [
      "No",
      "Poco",
      "Moderadamente",
      "Mucho",
      "Un texto largo que necesita adaptarse al espacio",
    ];
    e.properties.label_settings = e.properties.labels.map((_, i) => ({
      id: "L_FN_" + i,
      position: i * 25,
    }));
  }
  A.insert(d, e);
}
A.refreshDerived(d);
ctx.EIPSIAssessmentFlow.normalize(d);
d.flow.pages.forEach((p, i) => {
  p.title = ["Inicio", "Medidas", "Comentarios"][i];
  p.description_html = "<p>Instrucciones de la página " + (i + 1) + ".</p>";
});
d.flow.progress = "both";
d.flow.save_continue = {
  enabled: true,
  window: { value: 10, unit: "minutes" },
};
d.flow.metadata_profile = "detailed";
d.flow.completion = {
  title: "Gracias",
  message_html: "<p>Fin de la <strong>simulación</strong>.</p>",
  image_url: "",
  button_label: "Responder nuevamente",
  allow_restart: true,
};
fs.writeFileSync(
  "tests/assessment-editor/functional-fixture.json",
  JSON.stringify(d, null, 2) + "\n",
);
