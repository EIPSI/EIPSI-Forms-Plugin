(function (root) {
  "use strict";
  const A = root.EIPSIAssessment,
    C = A.controls;
  A.randomizationSummary = e => {
    const I=root.EIPSIAdminI18n, R=root.EIPSIAssessmentRandomization;
    const always=e.children.filter(c=>R.settings(c).always_show).length;
    const selection=pool=>I.n('Show %2$d of %1$d candidate',pool,e.properties.count);
    if(['weighted_positions','selection_uniform_order'].includes(e.properties.algorithm))
      return e.properties.mode==='all' ? I.n('Weighted order of %d item',e.children.length) : selection(R.eligible(e).length)+' + '+I.n('%d always visible item',always);
    if(always) return (e.properties.mode==='all'?I.t('Randomize all eligible items'):selection(R.eligible(e).length))+' + '+I.n('%d fixed item',always);
    return e.properties.mode==='all'?I.n('Randomize %d item',e.children.length):selection(e.children.length)+' · '+I.t(e.properties.order==='random'?'Random order':'Original order');
  };
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
        (key === "label_placement" ? "above" : key === "edge_spacing" ? "auto" : key === "mobile_orientation" ? "horizontal" : key === "vertical_top" ? "max" : key === "date_format" ? "YYYY-MM-DD" : key === "display_mode"
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
      heading(root.EIPSIAdminI18n.t("Selection and order"));
      select("mode", root.EIPSIAdminI18n.t("What to randomize"), [["all", root.EIPSIAdminI18n.t("Randomize all items")], ["select", root.EIPSIAdminI18n.t("Select a number of items")]], mode => {e.properties.mode = mode;e.properties.algorithm = "selection_uniform_order";});
      const selecting = e.properties.mode === "select";
      if (selecting) {
        property(e, "count", root.EIPSIAdminI18n.n('Show N of %d candidate',pool.length), "number");
        if (e.properties.count >= pool.length || e.properties.count < 1)
          inspector.append(el("p", {role:"alert"}, pool.length < 2 ? root.EIPSIAdminI18n.t("Add at least two candidates to select a number of items.") : root.EIPSIAdminI18n.f('Choose a number between 1 and %d.',pool.length-1)));
      }
      heading(root.EIPSIAdminI18n.t("Candidates, weights and fixed questions"));
      const competing = e.children.filter(c => selecting ? !R.settings(c).always_show : !R.settings(c).fixed_position);
      const maximum = Math.max(1, ...competing.map(c => R.settings(c).weight));
      const total = competing.reduce((sum,c) => sum + R.settings(c).weight / maximum, 0);
      e.children.forEach(child => {
        const value = R.settings(child), box = el("fieldset", {class:"ae-random-child", "data-random-child":child.id});
        box.append(el("legend", {}, child.properties.label || child.type));
        const update = changes => {child.randomization = {...value,...changes};e.properties.algorithm = "selection_uniform_order";refresh();};
        if (selecting ? !value.always_show : !value.fixed_position) {
          const label = el("label", {class:"ae-property ae-random-property"}), weight = el("input", {type:"number", min:"0", step:"any", value:String(value.weight), "aria-label":root.EIPSIAdminI18n.t("Weight")});
          weight.onchange = () => {
            const n = Number(weight.value);
            if (!weight.value.trim() || !Number.isFinite(n) || n <= 0) {weight.setCustomValidity(root.EIPSIAdminI18n.t("Enter a positive weight."));weight.reportValidity();return;}
            weight.setCustomValidity("");update({weight:n});
          };
          label.append(el("span", {}, root.EIPSIAdminI18n.t("Weight")), weight);box.append(label);
        }
        const checkbox = (key,text) => {
          const label = el("label", {class:"ae-property ae-random-property"}), input = el("input", {type:"checkbox", "aria-label":text});
          input.checked = value[key];input.onchange = () => update({[key]:input.checked});
          label.append(el("span", {}, text),input);box.append(label);
        };
        if (selecting) checkbox("always_show",root.EIPSIAdminI18n.t("Always show"));
        checkbox("fixed_position",root.EIPSIAdminI18n.t("Fixed question"));
        const probability = total ? (100 * (value.weight / maximum) / total).toFixed(2) : "0.00";
        let help = selecting
          ? value.always_show ? root.EIPSIAdminI18n.t("Always shown in addition to N; does not compete in selection.") : root.EIPSIAdminI18n.f('Probability on first selection: %s%%. Recalculated without replacement.',probability)
          : value.fixed_position ? root.EIPSIAdminI18n.t("Keeps its position; other questions compete for order.") : root.EIPSIAdminI18n.f('Probability of the first available position: %s%%. Remaining weights are then recalculated.',probability);
        if (selecting) help += value.fixed_position ? root.EIPSIAdminI18n.t(" If shown, retains its original position or the last possible position when fewer items are selected.") : root.EIPSIAdminI18n.t("Its position is determined by uniform random order, independently of selection weights.");
        box.append(el("p", {class:"ae-helper"},help));inspector.append(box);
      });
    }
    if (e.response_key) {
      heading(root.EIPSIAdminI18n.t("Response"));
      property(e, "required", root.EIPSIAdminI18n.t("Required response"), "checkbox");
      property(e,"required_message",root.EIPSIAdminI18n.t("Required response message"));
      if(["text","email","tel","url","number"].includes(e.type))property(e,"validation_message",root.EIPSIAdminI18n.t("Validation message"));
      if(e.type==='matrix'){property(e,'matrix_heading',root.EIPSIAdminI18n.t("Item heading"));}
      if(['date','vas','single_choice','multiple_choice','select','likert','matrix'].includes(e.type))property(e,'invalid_value_message',e.type==='date'?root.EIPSIAdminI18n.t("Invalid date message"):root.EIPSIAdminI18n.t("Invalid value message"));
      property(e, "variable_name", root.EIPSIAdminI18n.t("Variable name"));
      inspector.append(
        el(
          "p",
          { class: "ae-helper" },
          root.EIPSIAdminI18n.t("Edit content and footer directly on the canvas."),
        ),
      );
    }
    if (C.textTypes.includes(e.type) && e.type !== "date") {
      heading(root.EIPSIAdminI18n.t("Text content"));
      const p = e.properties;
      select(
        "display_mode",
        root.EIPSIAdminI18n.t("Text presentation"),
        [
          ["single", root.EIPSIAdminI18n.t("Single line")],
          ["multiline", root.EIPSIAdminI18n.t("Multiple lines")],
        ],
        (mode) =>
          A.textMode(
            e,
            mode,
            p.content_type || (e.type === "number" ? "number" : "free"),
            h.contentLanguage,
          ),
      );
      if(e.type!=="textarea")select(
        "content_type",
        root.EIPSIAdminI18n.t("Expected content"),
        [
          ["free", root.EIPSIAdminI18n.t("Unrestricted")],
          ["email", "Email"],
          ["url", "URL"],
          ["tel", root.EIPSIAdminI18n.t("Phone")],
          ["number", root.EIPSIAdminI18n.t("Numeric")],
          ["letters", root.EIPSIAdminI18n.t("Letters only")],
        ],
        (format) => A.textMode(e, p.display_mode || "single", format,h.contentLanguage),
      );
    }
    if (e.properties.placeholder !== undefined)
      property(
        e,
        "placeholder",
        e.type === "select" ? root.EIPSIAdminI18n.t("Dropdown placeholder") : root.EIPSIAdminI18n.t("Placeholder text"),
      );
    if (e.properties.max_length !== undefined) {
      property(e, "min_length", root.EIPSIAdminI18n.t("Minimum characters"), "number", true);
      property(e, "max_length", root.EIPSIAdminI18n.t("Maximum characters"), "number", true);
      property(e,"length_message",root.EIPSIAdminI18n.t("Length message — [MINIMO] and [MAXIMO]"));
    }
    if (e.type === "textarea") {
      property(e,"rows",root.EIPSIAdminI18n.t("Visible rows"),"number");
    }
    if(e.type==='date'){
      heading(root.EIPSIAdminI18n.t("Calendar"));
      for(const [key,label] of [['date_placeholder',root.EIPSIAdminI18n.t("Date placeholder")],['date_open_label',root.EIPSIAdminI18n.t("Open calendar button")],['date_close_label',root.EIPSIAdminI18n.t("Close calendar button")],['date_previous_label',root.EIPSIAdminI18n.t("Previous month button")],['date_next_label',root.EIPSIAdminI18n.t("Next month button")],['date_month_names',root.EIPSIAdminI18n.t("Months — separated by |")],['date_weekday_names',root.EIPSIAdminI18n.t("Days from Sunday to Saturday — separated by |")]])property(e,key,label);
    }
    if (e.type === "number") {
      property(e, "integer_only", root.EIPSIAdminI18n.t("Integers only"), "checkbox");
      inspector.append(
        el(
          "p",
          { class: "ae-helper" },
          root.EIPSIAdminI18n.t("Numeric content uses a single line."),
        ),
      );
      property(e, "min", root.EIPSIAdminI18n.t("Minimum (empty: no limit)"), "number", true);
      property(e, "max", root.EIPSIAdminI18n.t("Maximum (empty: no limit)"), "number", true);
      property(e,"number_range_message",root.EIPSIAdminI18n.t("Range message — [MINIMO] and [MAXIMO]"));
    }
    if (e.options.length) {
      heading(root.EIPSIAdminI18n.t("Options"));
      if (e.type !== "select")
        select("presentation", root.EIPSIAdminI18n.t("Presentation"), [
          ["vertical", root.EIPSIAdminI18n.t("Vertical")],
          ["horizontal", root.EIPSIAdminI18n.t("Horizontal")],
        ]);
      if (["single_choice", "multiple_choice"].includes(e.type))
        property(e, "columns", root.EIPSIAdminI18n.t("Maximum columns (1–12)"), "number");
      if (root.EIPSIAssessmentRandomization.optionTypes.includes(e.type))
        property(e, "randomize_options", root.EIPSIAdminI18n.t("Randomize option order"), "checkbox");
      inspector.append(options(e));
    }
    if (e.type === "multiple_choice") {
      if(e.properties.min_selected>0)property(e,"selection_min_message",root.EIPSIAdminI18n.t("Minimum options message"));
      if(e.properties.max_selected!==null)property(e,"selection_max_message",root.EIPSIAdminI18n.t("Maximum options message"));
      property(e, "min_selected", root.EIPSIAdminI18n.t("Minimum selected options"), "number");
      property(
        e,
        "max_selected",
        root.EIPSIAdminI18n.t("Maximum options (empty: no limit)"),
        "number",
        true,
      );
    }
    if (e.type === "matrix") {
      heading(root.EIPSIAdminI18n.t("Matrix items"));
      inspector.append(rows(e));
    }
    if (e.type === "vas") {
      heading(root.EIPSIAdminI18n.t("VAS scale"));
      [
        ["min", root.EIPSIAdminI18n.t("Minimum")],
        ["max", root.EIPSIAdminI18n.t("Maximum")],
        ["step", root.EIPSIAdminI18n.t("Step")],
        ["initial", root.EIPSIAdminI18n.t("Initial visual position")],
      ].forEach(([k, l]) => property(e, k, l, "number"));
      inspector.append(
        el(
          "p",
          { class: "ae-helper" },
          root.EIPSIAdminI18n.t("The initial position does not count as a response. Touch the scale to respond."),
        ),
      );
      heading(root.EIPSIAdminI18n.t("Semantic labels"));
      inspector.append(semantic(e));
      property(
        e,
        "label_alignment",
        root.EIPSIAdminI18n.t("Distribution: 0 compact / 100 spread"),
        "number",
      );
      heading(root.EIPSIAdminI18n.t("Presentation"));
      const sizeRow=el('label',{class:'ae-property'}),sizeInput=el('input',{type:'number','data-property':'label_scale',min:25,max:300,step:1});
      sizeInput.value=typeof e.properties.label_scale==='number'?e.properties.label_scale:({small:80,normal:100,large:120}[e.properties.label_scale]??100);
      sizeInput.onchange=()=>{e.properties.label_scale=Number(sizeInput.value);refresh();};
      sizeRow.append(el('span',{},root.EIPSIAdminI18n.t('Relative label size (%)')),sizeInput);inspector.append(sizeRow);
      inspector.append(el('p',{class:'ae-helper'},root.EIPSIAdminI18n.t('Automatic size: 100%. Labels still adapt to the available space.')));
      select("label_placement",root.EIPSIAdminI18n.t("Label placement"),[["above",root.EIPSIAdminI18n.t("All above")],["below",root.EIPSIAdminI18n.t("All below")],["alternate",root.EIPSIAdminI18n.t("Alternate above and below")]]);
      inspector.append(el("p",{class:"ae-helper"},root.EIPSIAdminI18n.t("Labels stay centered on their values. In vertical mode, above and below become left and right.")));
      select("edge_spacing",root.EIPSIAdminI18n.t("Space at scale ends"),[["auto",root.EIPSIAdminI18n.t("Automatic")],["custom",root.EIPSIAdminI18n.t("Custom")]],v=>{e.properties.edge_spacing=v;if(v==='custom')e.properties.edge_margin??=24;});
      if(e.properties.edge_spacing==='custom')property(e,"edge_margin",root.EIPSIAdminI18n.t("Margin at each end (px)"),"number");
      select("mobile_orientation",root.EIPSIAdminI18n.t("VAS orientation on mobile"),[["horizontal",root.EIPSIAdminI18n.t("Horizontal")],["vertical",root.EIPSIAdminI18n.t("Vertical")]],v=>{e.properties.mobile_orientation=v;if(v==='vertical'){e.properties.vertical_top??='max';e.properties.vertical_height??=320;}});
      if(e.properties.mobile_orientation==='vertical') {
        select("vertical_top",root.EIPSIAdminI18n.t("Upper end on mobile"),[["max",root.EIPSIAdminI18n.t("Maximum above, minimum below")],["min",root.EIPSIAdminI18n.t("Minimum above, maximum below")]]);
        property(e,"vertical_height",root.EIPSIAdminI18n.t("Vertical scale height (px)"),"number");
      }
      property(e, "bold_labels", root.EIPSIAdminI18n.t("Bold labels"), "checkbox");
      property(e, "label_boxes", root.EIPSIAdminI18n.t("Label background"), "checkbox");
      property(e, "show_value", root.EIPSIAdminI18n.t("Show value"), "checkbox");
      if (e.properties.show_value) {
        property(e,"unanswered_value_message",root.EIPSIAdminI18n.t("Text before responding"));
        select("value_position", root.EIPSIAdminI18n.t("Value position"), [
          ["above", root.EIPSIAdminI18n.t("Above the slider")],
          ["below", root.EIPSIAdminI18n.t("Below the slider")],
        ]);
        property(e, "value_font_size", root.EIPSIAdminI18n.t("Value size (16–72 px)"), "number");
      }
    }
    if (["likert", "matrix", "vas"].includes(e.type)) {
      heading(root.EIPSIAdminI18n.t("Gradient"));
      select("gradient", root.EIPSIAdminI18n.t("Gradient / direction"), [
        ["none", root.EIPSIAdminI18n.t("No gradient")],
        ["improvement-right", root.EIPSIAdminI18n.t("Red → green")],
        ["improvement-left", root.EIPSIAdminI18n.t("Green → red")],
        ["progress-neutral", root.EIPSIAdminI18n.t("Neutral progress")],
        ["custom", root.EIPSIAdminI18n.t("Custom")],
      ]);
      if (e.properties.gradient === "custom") {
        property(e, "gradient_start", root.EIPSIAdminI18n.t("Start color"), "color");
        property(e, "gradient_end", root.EIPSIAdminI18n.t("End color"), "color");
      }
    }
  };
})(window);
