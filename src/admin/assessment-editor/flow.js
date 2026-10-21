(function (root) {
  "use strict";
  const messages = root.EIPSIContent.get('flow_messages','es');
  const units = {
    minutes: 60,
    hours: 3600,
    days: 86400,
    weeks: 604800,
    months: 2592000,
  };
  function defaults(language=null) {return root.EIPSIContent.get(language===null?'flow_legacy':'flow',language??'es');}
  function pageId(id) {
    if (id.length <= 97) return "PG_" + id;
    let h = 2166136261;
    for (const char of id)
      h = Math.imul(h ^ char.charCodeAt(0), 16777619) >>> 0;
    return "PG_" + id.slice(0, 80) + "_" + h.toString(16).padStart(8, "0");
  }
  function normalize(d) {
    d.flow ??= defaults(d.content_language??null);
    if(d.content_language){const base=defaults(d.content_language);d.flow={...base,...d.flow,messages:{...base.messages,...d.flow.messages}};}
    d.flow.navigation_labels ??= { back: "", next: "", finish: "" };
    const old = new Map(d.flow.pages.map((p) => [p.id, p]));
    const boundaries = [
      null,
      ...d.elements.filter((e) => e.type === "page_break").map((e) => e.id),
    ];
    const generated = new Set();
    d.flow.pages = boundaries.map((boundary) => {
      const id = boundary
        ? pageId(boundary)
        : d.flow.pages.find((p) => p.boundary_id === null)?.id || pageId(d.id);
      if (generated.has(id)) throw Error("Identidad de página duplicada");
      generated.add(id);
      return (
        old.get(id) || {
          id,
          boundary_id: boundary,
          title: "",
          description_html: "",
        }
      );
    });
    return d;
  }
  function seconds(window) {
    return window.value * units[window.unit];
  }
  root.EIPSIAssessmentFlow = { defaults, normalize, seconds, units, messages };
})(typeof window !== "undefined" ? window : globalThis);
