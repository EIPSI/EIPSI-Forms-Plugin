(function (root) {
  "use strict";
  const A = root.EIPSIAssessment,
    C = A.controls;
  A.randomizationSummary = e => ["weighted_positions","selection_uniform_order"].includes(e.properties.algorithm)
    ? (e.properties.mode === "all" ? "Orden ponderado de " + e.children.length + " elementos" : "Mostrar " + e.properties.count + " de " + root.EIPSIAssessmentRandomization.eligible(e).length + " candidatos + " + e.children.filter(c => root.EIPSIAssessmentRandomization.settings(c).always_show).length + " siempre visibles")
    : e.children.some(c => root.EIPSIAssessmentRandomization.settings(c).always_show)
    ? (e.properties.mode === "all" ? "Ordenar todos los aleatorios" : "Mostrar " + e.properties.count + " de " + root.EIPSIAssessmentRandomization.eligible(e).length + " aleatorios") + " + " + e.children.filter(c => root.EIPSIAssessmentRandomization.settings(c).always_show).length + " fijos"
    : e.properties.mode === "all"
    ? "Ordenar " + e.children.length + " elementos aleatoriamente"
    : "Mostrar " + e.properties.count + " de " + e.children.length + " · " + (e.properties.order === "random" ? "Orden aleatorio" : "Orden original");
  A.controlInspector = function (e, h) {
    const { inspector, el, property, options, semantic, rows, refresh } = h;
    const heading = (text) => inspector.append(el("h3", {}, text));
    function select(key, label, items, onchange) {
      const row = el("label", { class: "ae-property" }),
        control = el("select", { "data-property": key });
      items.forEach(([value, label]) =>
        control.append(el("option", { value }, label)),
      );
      control.value =
        e.properties[key] ??
        (key === "date_format" ? "YYYY-MM-DD" : key === "display_mode"
          ? "single"
          : key === "content_type" && e.type === "number"
          ? "number"
          : "");
      control.onchange = () => {
        if (onchange) onchange(control.value);
        else e.properties[key] = control.value;
        refresh();
      };
      row.append(el("span", {}, label), control);
      inspector.append(row);
    }
    if (e.type === "randomization") {
      const R = root.EIPSIAssessmentRandomization, pool = R.eligible(e);
      heading("Selección y orden");
      select("mode", "Qué randomizar", [["all", "Ordenar todos aleatoriamente"], ["select", "Seleccionar una cantidad"]], mode => {e.properties.mode = mode;e.properties.algorithm = "selection_uniform_order";});
      const selecting = e.properties.mode === "select";
      if (selecting) {
        property(e, "count", "Mostrar N de " + pool.length + " candidatos", "number");
        if (e.properties.count >= pool.length || e.properties.count < 1)
          inspector.append(el("p", {role:"alert"}, pool.length < 2 ? "Agregá al menos dos candidatos para seleccionar una cantidad." : "Elegí una cantidad entre 1 y " + (pool.length - 1) + "."));
      }
      heading("Candidatos, pesos y fijos");
      const competing = e.children.filter(c => selecting ? !R.settings(c).always_show : !R.settings(c).fixed_position);
      const maximum = Math.max(1, ...competing.map(c => R.settings(c).weight));
      const total = competing.reduce((sum,c) => sum + R.settings(c).weight / maximum, 0);
      e.children.forEach(child => {
        const value = R.settings(child), box = el("fieldset", {class:"ae-random-child", "data-random-child":child.id});
        box.append(el("legend", {}, child.properties.label || child.type));
        const update = changes => {child.randomization = {...value,...changes};e.properties.algorithm = "selection_uniform_order";refresh();};
        if (selecting ? !value.always_show : !value.fixed_position) {
          const label = el("label", {class:"ae-property ae-random-property"}), weight = el("input", {type:"number", min:"0", step:"any", value:String(value.weight), "aria-label":"Peso"});
          weight.onchange = () => {
            const n = Number(weight.value);
            if (!weight.value.trim() || !Number.isFinite(n) || n <= 0) {weight.setCustomValidity("Ingresá un peso positivo.");weight.reportValidity();return;}
            weight.setCustomValidity("");update({weight:n});
          };
          label.append(el("span", {}, "Peso"), weight);box.append(label);
        }
        const checkbox = (key,text) => {
          const label = el("label", {class:"ae-property ae-random-property"}), input = el("input", {type:"checkbox", "aria-label":text});
          input.checked = value[key];input.onchange = () => update({[key]:input.checked});
          label.append(el("span", {}, text),input);box.append(label);
        };
        if (selecting) checkbox("always_show","Mostrar siempre");
        checkbox("fixed_position","Pregunta Fija");
        const probability = total ? (100 * (value.weight / maximum) / total).toFixed(2) : "0.00";
        let help = selecting
          ? value.always_show ? "Se muestra siempre y se agrega a N; no compite en la selección." : "Probabilidad en la primera selección: " + probability + "%. Se recalcula sin reemplazo."
          : value.fixed_position ? "Conserva su posición; las demás preguntas compiten por el orden." : "Probabilidad de ocupar el primer lugar disponible: " + probability + "%. Después se recalculan los pesos restantes.";
        if (selecting) help += value.fixed_position ? " Si se muestra, conserva su posición original o la última posible si hay menos seleccionados." : "Su posición se determina por orden aleatorio uniforme, independiente del peso de selección.";
        box.append(el("p", {class:"ae-helper"},help));inspector.append(box);
      });
    }
    if (e.response_key) {
      heading("Respuesta");
      property(e, "required", "Respuesta obligatoria", "checkbox");
      property(e,"required_message","Mensaje de obligatoriedad");
      if(["text","email","tel","url","number"].includes(e.type))property(e,"validation_message","Mensaje de validación");
      if(e.type==='matrix'){property(e,'matrix_heading','Encabezado de los ítems');}
      if(['date','vas','single_choice','multiple_choice','select','likert','matrix'].includes(e.type))property(e,'invalid_value_message',e.type==='date'?'Mensaje de fecha inválida':'Mensaje ante un valor no válido');
      property(e, "variable_name", "Nombre de variable");
      inspector.append(
        el(
          "p",
          { class: "ae-helper" },
          "Contenido y footer se editan directamente en el canvas.",
        ),
      );
    }
    if (C.textTypes.includes(e.type) && e.type !== "date") {
      heading("Contenido Texto");
      const p = e.properties;
      select(
        "display_mode",
        "Presentación del texto",
        [
          ["single", "Una línea"],
          ["multiline", "Multilínea"],
        ],
        (mode) =>
          A.textMode(
            e,
            mode,
            p.content_type || (e.type === "number" ? "number" : "free"),
          ),
      );
      if(e.type!=="textarea")select(
        "content_type",
        "Contenido esperado",
        [
          ["free", "Libre"],
          ["email", "Email"],
          ["url", "URL"],
          ["tel", "Teléfono"],
          ["number", "Numérico"],
          ["letters", "Sólo letras"],
        ],
        (format) => A.textMode(e, p.display_mode || "single", format),
      );
    }
    if (e.properties.placeholder !== undefined)
      property(
        e,
        "placeholder",
        e.type === "select" ? "Placeholder de la lista" : "Texto de ejemplo",
      );
    if (e.properties.max_length !== undefined) {
      property(e, "min_length", "Mínimo de caracteres", "number", true);
      property(e, "max_length", "Máximo de caracteres", "number", true);
      property(e,"length_message","Mensaje de longitud — [MINIMO] y [MAXIMO]");
    }
    if (e.type === "textarea") {
      property(e,"rows","Filas visibles","number");
    }
    if(e.type==='date'){
      heading('Calendario');
      for(const [key,label] of [['date_placeholder','Texto de ejemplo de fecha'],['date_open_label','Botón abrir calendario'],['date_close_label','Botón cerrar calendario'],['date_previous_label','Botón mes anterior'],['date_next_label','Botón mes siguiente'],['date_month_names','Meses — separados por |'],['date_weekday_names','Días de domingo a sábado — separados por |']])property(e,key,label);
    }
    if (e.type === "number") {
      property(e, "integer_only", "Sólo enteros", "checkbox");
      inspector.append(
        el(
          "p",
          { class: "ae-helper" },
          "El contenido numérico utiliza una línea.",
        ),
      );
      property(e, "min", "Mínimo (vacío: sin límite)", "number", true);
      property(e, "max", "Máximo (vacío: sin límite)", "number", true);
      property(e,"number_range_message","Mensaje de rango — [MINIMO] y [MAXIMO]");
    }
    if (e.options.length) {
      heading("Opciones");
      if (e.type !== "select")
        select("presentation", "Presentación", [
          ["vertical", "Vertical"],
          ["horizontal", "Horizontal"],
        ]);
      if (["single_choice", "multiple_choice"].includes(e.type))
        property(e, "columns", "Número máximo de columnas (1–12)", "number");
      if (root.EIPSIAssessmentRandomization.optionTypes.includes(e.type))
        property(e, "randomize_options", "Randomizar orden de opciones", "checkbox");
      inspector.append(options(e));
    }
    if (e.type === "multiple_choice") {
      if(e.properties.min_selected>0)property(e,"selection_min_message","Mensaje de mínimo de opciones");
      if(e.properties.max_selected!==null)property(e,"selection_max_message","Mensaje de máximo de opciones");
      property(e, "min_selected", "Mínimo de opciones seleccionadas", "number");
      property(
        e,
        "max_selected",
        "Máximo de opciones (vacío: sin límite)",
        "number",
        true,
      );
    }
    if (e.type === "matrix") {
      heading("Items de la matriz");
      inspector.append(rows(e));
    }
    if (e.type === "vas") {
      heading("Escala VAS");
      [
        ["min", "Mínimo"],
        ["max", "Máximo"],
        ["step", "Paso"],
        ["initial", "Posición inicial visual"],
      ].forEach(([k, l]) => property(e, k, l, "number"));
      inspector.append(
        el(
          "p",
          { class: "ae-helper" },
          "La posición inicial no cuenta como respuesta. Tocá la escala para responder.",
        ),
      );
      heading("Etiquetas semánticas");
      inspector.append(semantic(e));
      property(
        e,
        "label_alignment",
        "Distribución: 0 compacta / 100 extendida",
        "number",
      );
      heading("Presentación");
      select("label_scale", "Tamaño relativo de etiquetas", [
        ["small", "Pequeño"],
        ["normal", "Normal"],
        ["large", "Grande"],
      ]);
      property(e, "bold_labels", "Etiquetas en negrita", "checkbox");
      property(e, "label_boxes", "Fondo de etiquetas", "checkbox");
      property(e, "show_value", "Mostrar valor", "checkbox");
      if (e.properties.show_value) {
        property(e,"unanswered_value_message","Texto antes de responder");
        select("value_position", "Posición del valor", [
          ["above", "Arriba del slider"],
          ["below", "Abajo del slider"],
        ]);
        property(e, "value_font_size", "Tamaño del valor (16–72 px)", "number");
      }
    }
    if (["likert", "matrix", "vas"].includes(e.type)) {
      heading("Gradiente");
      select("gradient", "Gradiente / dirección", [
        ["none", "Sin gradiente"],
        ["improvement-right", "Rojo → verde"],
        ["improvement-left", "Verde → rojo"],
        ["progress-neutral", "Progreso neutro"],
        ["custom", "Personalizado"],
      ]);
      if (e.properties.gradient === "custom") {
        property(e, "gradient_start", "Color inicial", "color");
        property(e, "gradient_end", "Color final", "color");
      }
    }
  };
})(window);
