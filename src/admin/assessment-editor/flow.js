(function (root) {
  "use strict";
  const messages = {"expired": "La sesión anterior venció. Empezá nuevamente desde la primera página.", "expired_saved": "Las respuestas parciales de la sesión anterior quedaron guardadas. Empezá nuevamente desde la primera página.", "processing": "Actualizando sesión…", "updated": "Sesión actualizada.", "completed": "Respuestas guardadas correctamente.", "invalid": "No se pudieron procesar tus respuestas. Volvé a intentarlo.", "delivery_error": "No se pudo confirmar el guardado de tus respuestas. Volvé a intentarlo con el mismo botón.", "discard_label": "Descartar respuesta", "discard_confirm": "¿Descartar esta respuesta? Se eliminarán la sesión y las respuestas de esta evaluación para comenzar desde cero.", "discarded": "Respuesta descartada. Podés empezar desde el comienzo.", "progress": "Página [ACTUAL] de [TOTAL]", "progress_accessible": "Progreso por páginas", "discard_accept": "Aceptar", "discard_cancel": "Cancelar"};
  const units = {
    minutes: 60,
    hours: 3600,
    days: 86400,
    weeks: 604800,
    months: 2592000,
  };
  function defaults() {
    return {
      pages: [],
      allow_back: true,
      navigation_labels: { back: "", next: "", finish: "" },
      progress: "pages",
      completion: {
        title: "Gracias por responder",
        message_html: "<p>Gracias por completar la evaluación.</p>",
        image_url: "",
        button_label: "",
        allow_restart: true,
      },
      save_continue: { enabled: false, window: { value: 7, unit: "days" } },
      metadata_profile: "basic",
    };
  }
  function pageId(id) {
    if (id.length <= 97) return "PG_" + id;
    let h = 2166136261;
    for (const char of id)
      h = Math.imul(h ^ char.charCodeAt(0), 16777619) >>> 0;
    return "PG_" + id.slice(0, 80) + "_" + h.toString(16).padStart(8, "0");
  }
  function normalize(d) {
    d.flow ??= defaults();
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
