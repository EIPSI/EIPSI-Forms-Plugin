// Explicit deterministic maximal fixture for authoring correction contracts.
const fs = require("fs"),
  vm = require("vm"),
  { webcrypto } = require("crypto");
const ctx = { crypto: webcrypto, URL };
ctx.globalThis = ctx;
vm.createContext(ctx);
for (const f of ["flow.js", "variables.js", "controls.js", "model.js"])
  vm.runInContext(
    fs.readFileSync("src/admin/assessment-editor/" + f, "utf8"),
    ctx,
  );
const A = ctx.EIPSIAssessment,
  d = A.assessment();
d.id = "AE_CORRECTIONS";
d.title = "Revisión de correcciones de autoría";
for (const type of A.questions) {
  const e = A.element(type, d);
  e.id = "EL_" + type;
  e.response_key = "R_" + type;
  e.properties.label = "Pregunta " + type;
  e.options.forEach((o, i) => (o.id = "O_" + type + "_" + i));
  if (type === "vas") {
    Object.assign(e.properties, {
      labels: [
        "No",
        "Poco",
        "Moderadamente",
        "Mucho",
        "Texto largo\ncon varias líneas",
      ],
      label_settings: [0, 25, 50, 75, 100].map((position, i) => ({
        id: "L_vas_" + i,
        position,
      })),
      label_scale: "normal",
    });
  }
  if (type === "matrix") {
    e.properties.matrix_rows = Array.from({ length: 3 }, (_, i) => ({
      id: "ROW_" + i,
      response_key: "R_ROW_" + i,
      variable_name: e.properties.variable_name + "_" + (i + 1),
      label: "Item " + (i + 1),
      label_html: null,
      helper_html: null,
      footer_html: "",
      required: false,
    }));
  }
  d.elements.push(e);
}
fs.writeFileSync(
  "tests/assessment-editor/corrections-fixture.json",
  JSON.stringify(d, null, 2) + "\n",
);
