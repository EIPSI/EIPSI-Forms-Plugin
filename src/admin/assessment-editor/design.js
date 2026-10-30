(function(root) {
  "use strict";
  const A = root.EIPSIAssessment;
  const defaults = {preset: "Clinical Blue", primary: "#3b6caa", background: "#ffffff", text: "#2c3e50", button_text: "#ffffff", font: "system", font_size: 16, width: 960, desktop_margin: 24, tablet_margin: 24, mobile_margin: 16, full_width: false, padding: 40, gap: 24, radius: 12, shadow: true, navigation_alignment: "edges"};
  // Persisted keys remain unchanged; labels are presentation only.
  const presets = A.designPresets = {
    "Clinical Blue": {label: "Clinical Blue", values: {}},
    "Minimal White": {label: "Minimal White", values: {primary: "#475569", text: "#0f172a", padding: 56, gap: 32, radius: 6, shadow: false}},
    "Warm Neutral": {label: "Warm Neutral", values: {primary: "#8b6f47", background: "#fdfcfa", text: "#3d3935", gap: 28, radius: 14}},
    "Serene Teal": {label: "Serene Teal", values: {primary: "#0e7490", text: "#0c4a6e", padding: 44, gap: 28, radius: 16}},
  };
  A.designDefaults = () => ({...defaults});
  A.applyDesign = (node, design) => {
    const d = {...defaults, ...design};
    node.classList.add("ae-designed");
    for (const key of ["primary", "background", "text", "button_text"])
      node.style.setProperty("--ae-" + key.replaceAll("_", "-"), d[key]);
    for (const key of ["font_size", "width", "padding", "gap", "radius"])
      node.style.setProperty("--ae-" + key.replaceAll("_", "-"), d[key] + "px");
    node.style.setProperty("--ae-content-width", d.full_width ? "none" : d.width + "px");
    for (const key of ["desktop_margin", "tablet_margin", "mobile_margin"]) node.style.setProperty("--ae-" + key.replaceAll("_", "-"), d[key] + "px");
    node.style.setProperty("--ae-font", {system: 'system-ui, sans-serif', serif: 'Georgia, serif', sans: 'Arial, sans-serif'}[d.font]);
    node.style.setProperty("--ae-shadow", d.shadow ? "0 8px 28px #00000012" : "none");
  };
  A.designInspector = (definition, host, onchange, field) => {
    const group = A.el("details", {class: "ae-design-settings"});
    group.append(A.el("summary", {}, root.EIPSIAdminI18n.t("Design")));
    // Old published definitions remain byte-for-byte unchanged until an explicit author edit.
    const current = {...defaults, ...definition.flow.design};
    const change = () => {definition.flow.design = {...current}; onchange();};
    field(group, current, "preset", root.EIPSIAdminI18n.t("Base style"), () => {
      Object.assign(current, defaults, presets[current.preset].values, {preset: current.preset});
      change();
      const sibling = group.nextSibling;
      group.remove();
      A.designInspector(definition, host, onchange, field);
      const fresh = host.querySelector(".ae-design-settings");
      host.insertBefore(fresh, sibling);
      fresh.open = true;
    }, "text", Object.entries(presets).map(([id, preset]) => [id, root.EIPSIAdminI18n.t(preset.label)]));
    for (const [key, label] of [["primary",root.EIPSIAdminI18n.t("Primary and button color")],["background",root.EIPSIAdminI18n.t("Background")],["text",root.EIPSIAdminI18n.t("Text")],["button_text",root.EIPSIAdminI18n.t("Button text")]])
      field(group, current, key, label, change, "color");
    field(group, current, "font", root.EIPSIAdminI18n.t("Font family"), change, "text", [["system",root.EIPSIAdminI18n.t("System")],["sans",root.EIPSIAdminI18n.t("Sans serif")],["serif",root.EIPSIAdminI18n.t("Serif")]]);
    let maximumWidthField;
    for (const [key, label, min, max] of [["font_size",root.EIPSIAdminI18n.t("Text size (px)"),12,32],["width",root.EIPSIAdminI18n.t("Maximum width (px)"),320,1400],["padding",root.EIPSIAdminI18n.t("Inner spacing (px)"),0,96],["gap",root.EIPSIAdminI18n.t("Question spacing (px)"),8,80],["radius",root.EIPSIAdminI18n.t("Corner radius (px)"),0,32]]) {
      const input = field(group, current, key, label, change, "number");
      input.min = min; input.max = max; input.step = 1;
      if(key==='width'){maximumWidthField=input;input.disabled=current.full_width;}
    }
    field(group, current, "full_width", root.EIPSIAdminI18n.t("Use full available width"), ()=>{if(maximumWidthField)maximumWidthField.disabled=current.full_width;change();}, "checkbox");
    for (const [key,label] of [["desktop_margin","Desktop side margin (px)"],["tablet_margin","Tablet side margin (px)"],["mobile_margin","Mobile side margin (px)"]]) {
      const input=field(group,current,key,root.EIPSIAdminI18n.t(label),change,"number");input.min=0;input.max=96;input.step=1;
    }
    field(group, current, "shadow", root.EIPSIAdminI18n.t("Questionnaire shadow"), change, "checkbox");
    field(group, current, "navigation_alignment", root.EIPSIAdminI18n.t("Back, Next and Finish alignment"), change, "text", [["edges",root.EIPSIAdminI18n.t("At the edges")],["center",root.EIPSIAdminI18n.t("Centered")]]);
    host.append(group);
  };
})(window);
