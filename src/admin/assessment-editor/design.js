(function(root) {
  "use strict";
  const A = root.EIPSIAssessment;
  const defaults = {preset: "Clinical Blue", primary: "#3b6caa", background: "#ffffff", text: "#2c3e50", button_text: "#ffffff", font: "system", font_size: 16, width: 800, padding: 40, gap: 24, radius: 12, shadow: true, navigation_alignment: "edges"};
  const presets = {
    "Clinical Blue": {},
    "Minimal White": {primary: "#475569", text: "#0f172a", padding: 56, gap: 32, radius: 6, shadow: false},
    "Warm Neutral": {primary: "#8b6f47", background: "#fdfcfa", text: "#3d3935", gap: 28, radius: 14},
    "Serene Teal": {primary: "#0e7490", text: "#0c4a6e", padding: 44, gap: 28, radius: 16},
  };
  A.designDefaults = () => ({...defaults});
  A.applyDesign = (node, design) => {
    const d = {...defaults, ...design};
    node.classList.add("ae-designed");
    for (const key of ["primary", "background", "text", "button_text"])
      node.style.setProperty("--ae-" + key.replaceAll("_", "-"), d[key]);
    for (const key of ["font_size", "width", "padding", "gap", "radius"])
      node.style.setProperty("--ae-" + key.replaceAll("_", "-"), d[key] + "px");
    node.style.setProperty("--ae-font", {system: 'system-ui, sans-serif', serif: 'Georgia, serif', sans: 'Arial, sans-serif'}[d.font]);
    node.style.setProperty("--ae-shadow", d.shadow ? "0 8px 28px #00000012" : "none");
  };
  A.designInspector = (definition, host, onchange, field) => {
    const group = A.el("details", {class: "ae-design-settings"});
    group.append(A.el("summary", {}, "Diseño"));
    // Old published definitions remain byte-for-byte unchanged until an explicit author edit.
    const current = {...defaults, ...definition.flow.design};
    const change = () => {definition.flow.design = {...current}; onchange();};
    field(group, current, "preset", "Estilo base", () => {
      Object.assign(current, defaults, presets[current.preset], {preset: current.preset});
      change();
      const sibling = group.nextSibling;
      group.remove();
      A.designInspector(definition, host, onchange, field);
      const fresh = host.querySelector(".ae-design-settings");
      host.insertBefore(fresh, sibling);
      fresh.open = true;
    }, "text", Object.keys(presets).map(name => [name, name]));
    for (const [key, label] of [["primary","Color principal y botones"],["background","Fondo"],["text","Texto"],["button_text","Texto de botones"]])
      field(group, current, key, label, change, "color");
    field(group, current, "font", "Tipografía", change, "text", [["system","Sistema"],["sans","Sans serif"],["serif","Serif"]]);
    for (const [key, label, min, max] of [["font_size","Tamaño de texto (px)",12,32],["width","Ancho máximo (px)",320,1400],["padding","Espacio interior (px)",0,96],["gap","Separación de preguntas (px)",8,80],["radius","Redondeado de bordes (px)",0,32]]) {
      const input = field(group, current, key, label, change, "number");
      input.min = min; input.max = max; input.step = 1;
    }
    field(group, current, "shadow", "Sombra del cuestionario", change, "checkbox");
    field(group, current, "navigation_alignment", "Posición de Atrás, Siguiente y Terminar", change, "text", [["edges","En los extremos"],["center","Centrados"]]);
    host.append(group);
  };
})(window);
