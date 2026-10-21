(function (root) {
  "use strict";
  const prefixes = [
      "text",
      "date",
      "single",
      "multiple",
      "select",
      "likert",
      "vas",
      "matrix",
    ],
    policy = "MATRIX_ORDINAL_V1";
  function prefix(t) {
    return (
      {
        textarea: "text",
        email: "text",
        number: "text",
        tel: "text",
        url: "text",
        single_choice: "single",
        multiple_choice: "multiple",
      }[t] || t
    );
  }
  function metadata() {
    return {
      variable_policy: policy,
      variable_next: Object.fromEntries(prefixes.map((p) => [p, 1])),
      legacy_row_variables: [],
    };
  }
  function walk(items, fn) {
    items.forEach((e) => {
      fn(e);
      walk(e.children, fn);
    });
  }
  function observe(d) {
    d.authoring ??= metadata();
    walk(d.elements, (e) => {
      const v = e.properties.variable_name;
      if (typeof v === "string")
        for (const p of prefixes) {
          const match = v.match(new RegExp("^" + p + "_([1-9][0-9]{0,8})$"));
          if (match)
            d.authoring.variable_next[p] = Math.max(
              d.authoring.variable_next[p],
              Number(match[1]) + 1,
            );
        }
    });
  }
  function reserved(d) {
    const names = new Set();
    walk(d.elements, (e) => {
      if (e.response_key && typeof e.properties.variable_name === "string") {
        names.add(e.properties.variable_name);
        if (e.type === "matrix")
          e.properties.matrix_rows.forEach((r, i) =>
            names.add(e.properties.variable_name + "_" + (i + 1)),
          );
      }
    });
    return names;
  }
  function allocate(d, e, extra = new Set()) {
    observe(d);
    const p = prefix(e.type),
      names = reserved(d);
    for (const n of extra) names.add(n);
    let candidate;
    do {
      const n = d.authoring.variable_next[p]++;
      if (!Number.isSafeInteger(n) || n >= 1e9)
        throw Error("Se agotaron los nombres automáticos.");
      candidate = p + "_" + n;
    } while (
      names.has(candidate) ||
      (e.type === "matrix" &&
        e.properties.matrix_rows.some((_, i) =>
          names.has(candidate + "_" + (i + 1)),
        ))
    );
    e.properties.variable_name = candidate;
    extra.add(candidate);
    if (e.type === "matrix")
      e.properties.matrix_rows.forEach((r, i) => {
        r.variable_name = candidate + "_" + (i + 1);
        extra.add(r.variable_name);
      });
    return candidate;
  }
  function derive(d) {
    walk(d.elements, (e) => {
      if (e.type === "matrix")
        e.properties.matrix_rows.forEach(
          (r, i) =>
            (r.variable_name = e.properties.variable_name + "_" + (i + 1)),
        );
    });
  }
  function normalize(d) {
    const legacy = !d.authoring;
    observe(d);
    const extra = reserved(d);
    walk(d.elements, (e) => {
      if (e.response_key) {
        if (e.properties.variable_name === undefined) allocate(d, e, extra);
        if (e.type === "matrix")
          e.properties.matrix_rows.forEach((r, i) => {
            const name = e.properties.variable_name + "_" + (i + 1);
            if (
              legacy &&
              typeof r.variable_name === "string" &&
              r.variable_name !== name
            )
              d.authoring.legacy_row_variables.push({
                row_id: r.id,
                variable_name: r.variable_name,
              });
            r.variable_name = name;
          });
      }
    });
    return d;
  }
  function errors(d) {
    const names = new Set(),
      bases = new Set(),
      out = [];
    function check(name, id, base = false, row = false) {
      if (
        typeof name !== "string" ||
        !new RegExp(
          "^[A-Za-z_][A-Za-z0-9_]{0," + (row ? 131 : 127) + "}$",
        ).test(name)
      )
        out.push({
          id,
          message:
            "Nombre de variable inválido: usá letras, números y underscore.",
        });
      else {
        const bag = base ? bases : names;
        if (bag.has(name))
          out.push({ id, message: "Nombre de variable duplicado: " + name });
        bag.add(name);
      }
    }
    walk(d.elements, (e) => {
      if (!e.response_key) return;
      check(e.properties.variable_name, e.id, e.type === "matrix");
      if (e.type === "matrix")
        e.properties.matrix_rows.forEach((r, i) =>
          check(e.properties.variable_name + "_" + (i + 1), r.id, false, true),
        );
    });
    return out;
  }
  root.EIPSIAssessmentVariables = {
    prefix,
    prefixes,
    metadata,
    normalize,
    observe,
    allocate,
    derive,
    errors,
  };
})(typeof window !== "undefined" ? window : globalThis);
