(function (root) {
  "use strict";
  const A = root.EIPSIAssessment,
    C = A.controls;
  function responsiveLikert(box) {
    let attached = false, observer;
    const refresh = () => {
      if (!box.isConnected) {
        if (attached) { observer?.disconnect(); root.removeEventListener("resize", refresh); }
        return;
      }
      attached = true;
      if (!box.classList.contains("ae-horizontal")) return;
      const style = root.getComputedStyle(box), number = value => parseFloat(value) || 0;
      const width = box.clientWidth - number(style.paddingLeft) - number(style.paddingRight);
      if (width <= 0) return;
      const choices = [...box.querySelectorAll(":scope > .ae-choice")];
      // Labels may wrap inside each equal-width option. Only the option columns
      // need to fit on one row; measuring unwrapped text stacked ordinary scales.
      const minimum = number(style.getPropertyValue("--ae-likert-choice-min")) || 60;
      const gap = number(style.columnGap) || number(style.gap);
      const needed = minimum * choices.length + gap * Math.max(0, choices.length - 1);
      box.classList.toggle("is-stacked", needed > width + 0.5);
    };
    box.addEventListener("eipsi:likert-layout", refresh);
    if (root.ResizeObserver) { observer = new root.ResizeObserver(refresh); observer.observe(box); }
    else root.addEventListener("resize", refresh);
    root.setTimeout(refresh, 0);
    if (document.fonts?.ready) document.fonts.ready.then(refresh);
  }
  function el(tag, attrs = {}, text) {
    const n = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
      if (k === "class") n.className = v;
      else if (k === "checked" || k === "disabled") n[k] = v;
      else n.setAttribute(k, String(v));
    });
    if (text !== undefined) n.textContent = text;
    return n;
  }
  function modalOpen(dialog){if(dialog.showModal)dialog.showModal();else dialog.setAttribute('open','');}
  function modalClose(dialog){if(dialog.close)dialog.close();else dialog.removeAttribute('open');}
  function confirmDiscard(host,message){
    return new Promise(resolve=>{const focus=document.activeElement,dialog=el('dialog',{class:'ae-control-dialog','aria-label':message('discard_confirm')}),body=el('p',{},message('discard_confirm')),actions=el('div',{class:'ae-preview-actions'}),cancel=el('button',{type:'button'},message('discard_cancel')),accept=el('button',{type:'button'},message('discard_accept'));let done=false;
      const finish=value=>{if(done)return;done=true;modalClose(dialog);dialog.remove();if(focus?.isConnected)focus.focus();resolve(value);};cancel.onclick=()=>finish(false);accept.onclick=()=>finish(true);dialog.addEventListener('cancel',event=>{event.preventDefault();finish(false);});actions.append(cancel,accept);dialog.append(body,actions);host.append(dialog);modalOpen(dialog);cancel.focus();
    });
  }
  function dateDisplay(iso,format){
    const m=/^(\d{4})-(\d{2})-(\d{2})$/.exec(iso||'');if(!m)return iso||'';
    return format==='DD/MM/YYYY'?m[3]+'/'+m[2]+'/'+m[1]:format==='MM/DD/YYYY'?m[2]+'/'+m[3]+'/'+m[1]:iso;
  }
  function dateValue(raw,format){
    if(!raw||!format||format==='YYYY-MM-DD')return raw;
    const m=/^(\d{2})\/(\d{2})\/(\d{4})$/.exec(raw);if(!m)return raw;
    return m[3]+'-'+(format==='DD/MM/YYYY'?m[2]:m[1])+'-'+(format==='DD/MM/YYYY'?m[1]:m[2]);
  }
  function calendar(e,input,change,language){
    const p=e.properties,label=(key,fallback)=>p[key]??fallback,open=el('button',{type:'button',class:'ae-calendar-open'},label('date_open_label','Elegir fecha')),dialog=el('dialog',{class:'ae-control-dialog ae-calendar','aria-label':p.label}),content=el('div'),close=el('button',{type:'button'},label('date_close_label','Cerrar'));
    const names=(key,fallback,count)=>{const parts=(typeof p[key]==='string'?p[key].trim():'').split('|');return parts.length===count?parts:fallback.split('|');};
    const defaults=root.EIPSIContent.question('date',language),monthNames=names('date_month_names',defaults.date_month_names,12),weekdays=names('date_weekday_names',defaults.date_weekday_names,7);let cursor=new Date();
    const date=(year,month,day)=>{const d=new Date();d.setHours(12,0,0,0);d.setFullYear(year,month,day);return d;};
    const finish=()=>{modalClose(dialog);open.focus();};close.onclick=finish;dialog.addEventListener('cancel',event=>{event.preventDefault();finish();});
    function render(){content.replaceChildren();const year=cursor.getFullYear(),month=cursor.getMonth(),header=el('div',{class:'ae-calendar-navigation'}),previous=el('button',{type:'button'},label('date_previous_label','Anterior')),next=el('button',{type:'button'},label('date_next_label','Siguiente')),title=el('strong',{'aria-live':'polite'},monthNames[month]+' '+year);previous.disabled=year<=1&&month===0;next.disabled=year>=9999&&month===11;previous.onclick=()=>{cursor=date(year,month-1,1);render();};next.onclick=()=>{cursor=date(year,month+1,1);render();};header.append(previous,title,next);const table=el('table'),head=el('tr'),body=el('tbody');weekdays.forEach(name=>head.append(el('th',{scope:'col'},name)));const thead=el('thead');thead.append(head);table.append(thead,body);const start=date(year,month,1).getDay(),days=date(year,month+1,0).getDate();let row;
      for(let index=0;index<Math.ceil((start+days)/7)*7;index++){if(index%7===0){row=el('tr');body.append(row);}const cell=el('td'),day=index-start+1;if(day>0&&day<=days){const iso=String(year).padStart(4,'0')+'-'+String(month+1).padStart(2,'0')+'-'+String(day).padStart(2,'0'),button=el('button',{type:'button','aria-label':String(day)+' '+monthNames[month]+' '+year},String(day));if(dateValue(input.value,p.date_format)===iso)button.setAttribute('aria-current','date');button.onclick=()=>{input.value=dateDisplay(iso,p.date_format);input.dispatchEvent(new root.Event("input",{bubbles:true}));input.dispatchEvent(new root.Event("change",{bubbles:true}));finish();input.focus();};cell.append(button);}row.append(cell);}content.append(header,table);
    }
    open.onclick=()=>{const match=/^(\d{4})-(\d{2})-(\d{2})$/.exec(dateValue(input.value,p.date_format));cursor=match&&Number(match[1])>=1&&Number(match[1])<=9999&&Number(match[2])>=1&&Number(match[2])<=12?date(Number(match[1]),Number(match[2])-1,1):date(new Date().getFullYear(),new Date().getMonth(),1);render();modalOpen(dialog);close.focus();};dialog.append(content,close);return [open,dialog];
  }
  function widget(e, session = null, edit = null, definition = session?.definition) {
    const p = { ...C.defaults(e.type, e.properties), ...e.properties },
      box = el("div", { class: "ae-widget", "data-widget": e.type }),
      value = session ? session.answers[e.response_key] : undefined;
    const change = (v) => {
      if (session) session.set(e, v);
    };
    if (C.textTypes.includes(e.type)) {
      const control = el(e.type === "textarea" ? "textarea" : "input", {
        "aria-label": p.label,
      });
      if (e.type !== "textarea")
        control.type = ["text", "number", "date"].includes(e.type) ? "text" : e.type;
      if (p.placeholder !== undefined) control.placeholder = p.placeholder;
      if (p.max_length !== undefined && p.max_length !== null)
        control.maxLength = p.max_length;
      if (p.min_length !== undefined && p.min_length !== null)
        control.minLength = p.min_length;
      if (e.type === "textarea") control.rows = p.rows;
      if (e.type === "number") {
        control.inputMode = "decimal";
        for (const k of ["min", "max"])
          if (p[k] !== null) control.setAttribute(k, p[k]);
      }
      if(e.type==="date"){control.placeholder=p.date_placeholder??({"DD/MM/YYYY":"DD/MM/AAAA","MM/DD/YYYY":"MM/DD/AAAA"}[p.date_format]??"AAAA-MM-DD");control.inputMode="numeric";}
      control.value = e.type==="date"?dateDisplay(value,p.date_format):value ?? "";
      control.addEventListener("input", () => {
        if(e.type!=="number"){change(e.type==="date"?dateValue(control.value,p.date_format):control.value);return;}
        const raw=control.value,trimmed=raw.trim();
        if(trimmed===''){change(null);return;}
        // Preserve malformed input: native number inputs erase it before validation.
        const numeric=/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/.test(trimmed),number=Number(trimmed);
        change(numeric&&Number.isFinite(number)?number:raw);
      });
      box.append(control);
      if(e.type==="date")box.append(...calendar(e,control,change,root.EIPSIContent.language(definition)));
    }
    if (e.type === "matrix") {
      box.classList.add("ae-matrix");
      const table = el("table"),
        thead = el("thead"),
        head = el("tr");
      head.append(el("th", { scope: "col" }, p.matrix_heading ?? "Item"));
      const ordered = e.options.map((o, index) => ({ o, index }));
      if (p.reversed) ordered.reverse();
      ordered.forEach(({ o, index }) =>
        head.append(el("th", { scope: "col" }, o.label)),
      );
      thead.append(head);
      const body = el("tbody");
      p.matrix_rows.forEach((r) => {
        const tr = el("tr", { id: "preview-" + r.id, "data-row-id": r.id }),
          title = el("th", { scope: "row" });
        if (edit) {
          title.append(
            A.richText.editor(r, "label", "Texto del item", edit.row),
          );
        } else title.append(A.richText.render(r, "label"));
        if (r.helper_html) title.append(A.richText.render(r, "helper"));
        if (p.required || r.required) {
          const label = title.querySelector(".ae-label");
          const start = label?.querySelector("p,h1,h2,h3,h4,h5,h6") || label || title;
          start.prepend(el("span", { class: "ae-required", "aria-hidden": "true" }, "* "));
        }
        tr.append(title);
        ordered.forEach(({ o, index }) => {
          const cell = el("td"),
            label = el("label", { class: "ae-matrix-choice" }),
            input = el("input", {
              type: "radio",
              name: (session ? "preview-" : "canvas-") + r.id,
              value: o.id,
              "aria-label": r.label + ": " + o.label,
              checked: session?.answers[r.response_key] === o.id,
            });
          input.onchange = () => {
            if (session)
              session.set({ ...e, response_key: r.response_key }, o.id);
          };
          label.append(
            input,
            el("span", { class: "ae-matrix-mobile-label" }, o.label),
          );
          cell.append(label);
          tr.append(cell);
        });
        if (r.footer_html) {
          const footer = el("div", { class: "ae-matrix-row-footer" });
          footer.append(A.richText.render(r, "footer"));
          title.append(footer);
        }
        body.append(tr);
      });
      table.append(thead, body);
      table.style.backgroundImage = C.gradient(p);
      box.append(table);
    }
    if (e.type === "select") {
      const select = el("select", { "aria-label": p.label });
      select.append(
        el("option", { value: "" }, p.placeholder ?? root.EIPSIContent.question("select",root.EIPSIContent.language(definition)).placeholder),
      );
      e.options.forEach((o) =>
        select.append(el("option", { value: o.id }, o.label)),
      );
      select.value = value ?? "";
      select.onchange = () => change(select.value);
      box.append(select);
    }
    if (["single_choice", "multiple_choice", "likert"].includes(e.type)) {
      if (e.type !== "likert") box.classList.add("ae-choice-cards");
      box.classList.add(
        p.presentation === "horizontal" ? "ae-horizontal" : "ae-vertical",
      );
      if (
        ["single_choice", "multiple_choice"].includes(e.type) &&
        p.columns > 1
      ) {
        box.classList.add("ae-columns");
        box.style.setProperty("--ae-columns", p.columns);
      }
      if (e.type === "likert") {
        box.classList.add("ae-likert");
        box.style.backgroundImage = C.gradient(p);
        box.classList.toggle("ae-reversed", p.reversed);
      }
      const ordered = e.options.map((o, index) => ({ o, index }));
      if (e.type === "likert" && p.reversed) ordered.reverse();
      ordered.forEach(({ o, index }) => {
        const label = el("label", {
            class: "ae-choice",
            "data-option-id": o.id,
          }),
          input = el("input", {
            type: e.type === "multiple_choice" ? "checkbox" : "radio",
            name: "answer-" + (session ? "preview-" : "canvas-") + e.id,
            value: o.id,
            checked: Array.isArray(value)
              ? value.includes(o.id)
              : value === o.id,
          });
        input.onchange = () =>
          change(
            e.type === "multiple_choice"
              ? Array.from(
                  box.querySelectorAll("input:checked"),
                  (n) => n.value,
                )
              : o.id,
          );
        const text = el(
          edit ? "textarea" : "span",
          { class: "ae-option-label" },
          edit ? undefined : o.label,
        );
        if (edit) {
          text.value = o.label;
          text.setAttribute("aria-label", "Texto de opción " + (index + 1));
          text.dataset.optionId = o.id;
          text.oninput = () => edit.option(o, text.value);
        }
        if(e.type==="likert")label.append(text,input);else label.append(input, text);
        box.append(label);
      });
      if (e.type === "likert") responsiveLikert(box);
    }
    if (e.type === "vas") {
      box.classList.add("ae-vas");
      const slider = el("input", {
        type: "range",
        min: p.min,
        max: p.max,
        step: p.step,
        "aria-label": p.label,
        "data-touched": "false",
      });
      slider.value = value === undefined ? p.initial : value;
      slider.classList.add("ae-vas-native-range");
      const control = el("div", { class: "ae-vas-control" }),
        rail = el("div", { class: "ae-vas-track", "aria-hidden": "true" }),
        thumb = el("span", { class: "ae-vas-thumb" });
      rail.style.backgroundImage = C.gradient(p);
      rail.append(thumb);
      control.append(rail, slider);
      let vertical = false;
      const paintThumb = () => {
        const ratio=(Number(slider.value)-p.min)/(p.max-p.min);
        thumb.style.left=vertical?"50%":ratio*100+"%";
        thumb.style.top=vertical?(p.vertical_top==='min'?ratio:1-ratio)*100+"%":"50%";
      };
      paintThumb();
      slider.style.backgroundImage = C.gradient(p);
      slider.classList.toggle("ae-gradient-range", p.gradient !== "none");
      const output = el(
        "output",
        { class: "ae-vas-value" },
        value === undefined ? (p.unanswered_value_message ?? "Sin responder") : String(value),
      );
      output.style.fontSize = p.value_font_size + "px";
      const touch = () => {
        const number = Number(slider.value);
        if (Number.isFinite(number)) {
          change(number);
          paintThumb();
          slider.dataset.touched = "true";
          output.textContent = String(number);
        }
      };
      ["input", "pointerdown", "pointerup", "touchend", "keyup"].forEach(
        (name) =>
          slider.addEventListener(name, (event) => {
            if (
              name === "keyup" &&
              ![
                "ArrowLeft",
                "ArrowRight",
                "ArrowUp",
                "ArrowDown",
                "Home",
                "End",
                "PageUp",
                "PageDown",
              ].includes(event.key)
            )
              return;
            touch();
          }),
      );
      slider.addEventListener("keydown", (event) => {
        if(vertical&&["ArrowUp","ArrowDown"].includes(event.key)) {
          event.preventDefault();const up=event.key==='ArrowUp',increase=p.vertical_top==='min'?!up:up;
          if(increase)slider.stepUp();else slider.stepDown();touch();return;
        }
        if (
          [
            "ArrowLeft",
            "ArrowRight",
            "ArrowUp",
            "ArrowDown",
            "Home",
            "End",
            "PageUp",
            "PageDown",
          ].includes(event.key)
        )
          touch();
      });
      const axis = el("div", { class: "ae-vas-axis" }),
        labels = el("div", { class: "ae-vas-labels" }),
        lowerLabels = el("div", { class: "ae-vas-labels ae-vas-labels-below" });
      labels.classList.toggle("ae-label-boxes", p.label_boxes);
      labels.style.fontWeight = lowerLabels.style.fontWeight = p.bold_labels ? "700" : "400";
      lowerLabels.classList.toggle("ae-label-boxes",p.label_boxes);
      let refreshLayout = () => {};
      const nodes = p.labels.map((text, index) => {
        const label = el(
          "span",
          { class: "ae-semantic-label", "data-label-index": index },
          text,
        );
        if (edit) {
          label.setAttribute("contenteditable", "plaintext-only");
          label.setAttribute("role", "textbox");
          label.setAttribute("aria-label", root.EIPSIAdminI18n.f("Semantic label %d",index+1));
          label.tabIndex = 0;
          label.oninput = () => {
            edit.semantic(index, label.textContent);
            refreshLayout();
          };
          label.onpaste = (event) => {
            event.preventDefault();
            const text = event.clipboardData?.getData("text/plain") || "";
            const selection = root.getSelection();
            if (selection?.rangeCount && label.contains(selection.anchorNode)) {
              const range = selection.getRangeAt(0);
              range.deleteContents();
              range.insertNode(document.createTextNode(text));
            } else label.append(document.createTextNode(text));
            edit.semantic(index, label.textContent);
            refreshLayout();
          };
        }
        return label;
      });
      const measureContext=root.CanvasRenderingContext2D?document.createElement("canvas").getContext("2d"):null;
      const clusters = [];
      const ratios = p.labels.map((_, i) => root.EIPSIVASGeometry.ratio(p, i));
      ratios
        .filter(
          (r, i) => !ratios.slice(0, i).some((v) => Math.abs(v - r) < 1e-9),
        )
        .sort((a, b) => a - b)
        .forEach((ratio) => {
          const group = el("div", {
            class: "ae-vas-label-anchor",
            "data-anchor-ratio": ratio,
          });
          nodes.forEach((node, i) => {
            if (Math.abs(ratios[i] - ratio) < 1e-9) group.append(node);
          });
          if (group.children.length > 1) {
            group.classList.add("ae-coincident-labels");
            group.title = "Etiquetas que comparten la misma posición";
          }
          clusters.push(group);
          labels.append(group);
        });
      axis.append(labels, control, lowerLabels);
      box.append(axis);
      refreshLayout = () => {
        const viewport=root.matchMedia?root.matchMedia('(max-width: 600px)').matches:root.innerWidth<=600;
        vertical=p.mobile_orientation==='vertical'&&(viewport||!!axis.closest('.ae-preview-frame[data-width="mobile"]'));
        const size=p.vertical_height??320;
        axis.classList.toggle('is-vertical',vertical);axis.style.setProperty('--ae-vas-vertical-height',size+'px');
        slider.setAttribute('aria-orientation',vertical?'vertical':'horizontal');
        slider.style.setProperty('--ae-vas-rotation',p.vertical_top==='min'?'90deg':'-90deg');
        rail.style.backgroundImage=vertical?C.gradient(p).replace('to right',p.vertical_top==='min'?'to bottom':'to top'):C.gradient(p);paintThumb();
        const sides=[[],[]];clusters.forEach((group,index)=>{
          const side=p.label_placement==='below'?1:p.label_placement==='alternate'?index%2:0;
          const plane=side?lowerLabels:labels;if(group.parentElement!==plane)plane.append(group);group.dataset.side=String(side);
          Array.from(group.children).forEach(node=>sides[side].push(Number(node.dataset.labelIndex)));
        });
        labels.hidden=!sides[0].length;lowerLabels.hidden=!sides[1].length;
        const ar=axis.getBoundingClientRect(),initialPlane=(sides[0].length?labels:lowerLabels).getBoundingClientRect(),width=ar.width||initialPlane.width||axis.clientWidth||800;
        const family=root.getComputedStyle(labels).fontFamily||'sans-serif';let previousFont=null;
        const measure=measureContext?(text,fontSize)=>{const font=(p.bold_labels?'700 ':'400 ')+fontSize+'px '+family;if(previousFont!==font){measureContext.font=font;previousFont=font;}return measureContext.measureText(text).width;}:undefined;
        const gutter=root.EIPSIVASGeometry.gutter(p,width,measure);axis.style.setProperty('--ae-vas-gutter',gutter+'px');
        const rr=rail.getBoundingClientRect(),plane=(sides[0].length?labels:lowerLabels).getBoundingClientRect();
        const measured=vertical?rr.height>0&&plane.height>0:rr.width>0&&(ar.width>0||plane.width>0);
        const track=vertical?(measured?{start:rr.top-plane.top,width:rr.height}:{start:11,width:size-22}):(measured?{start:rr.left-(ar.width>0?ar.left:plane.left),width:rr.width}:{start:gutter+11,width:Math.max(1,width-2*(gutter+11))});
        const geometry=[];sides.forEach(indices=>{
          const g=vertical?root.EIPSIVASGeometry.verticalLayout(p,track,size,Math.max(24,(width-44)/2),p.vertical_top!=='min',measure,indices):root.EIPSIVASGeometry.layout(p,track,width,measure,indices);
          g.forEach((entry,i)=>geometry[i]=entry);
        });
        const common=Math.min(...geometry.filter(Boolean).map(g=>g.fontSize)),heights=[0,0];
        [labels,lowerLabels].forEach(plane=>{plane.dataset.trackMeasured=String(measured);plane.dataset.trackStart=String(track.start);plane.dataset.trackWidth=String(track.width);});
        clusters.forEach(group=>{
          let groupHeight=0;Array.from(group.children).forEach(node=>{
            const i=Number(node.dataset.labelIndex),g=geometry[i];
            group.style.left=vertical?'':g.anchor+'px';group.style.top=vertical?g.anchor+'px':'0px';
            node.dataset.labelPosition=String(g.ratio);node.dataset.anchorPx=String(g.anchor);
            node.style.maxWidth=g.maxWidth+'px';node.style.fontSize=common+'px';node.classList.toggle('ae-label-compact',g.compact);node.title=p.labels[i];
            node.setAttribute('aria-label',edit?root.EIPSIAdminI18n.f('Semantic label %d',i+1):p.labels[i]);
            const lines=Math.max(p.labels[i].split('\n').length,Math.ceil(Array.from(p.labels[i]).length*common*.55/Math.max(1,g.maxWidth-8)));
            groupHeight+=node.getBoundingClientRect().height||(g.compact?20:lines*common*1.4+8);
          });heights[Number(group.dataset.side)]=Math.max(heights[Number(group.dataset.side)],groupHeight);
        });
        labels.style.height=Math.ceil(vertical?size:heights[0])+'px';lowerLabels.style.height=Math.ceil(vertical?size:heights[1])+'px';
      };
      axis.addEventListener("eipsi:vas-layout", refreshLayout);
      refreshLayout();
      let observer,connected=false;
      const resize=()=>{if(!axis.isConnected){if(connected){observer?.disconnect();root.removeEventListener('resize',resize);}return;}connected=true;refreshLayout();};
      root.addEventListener('resize',resize);
      if(root.ResizeObserver){observer=new root.ResizeObserver(resize);observer.observe(axis);}
      root.setTimeout(resize,0);
      if (document.fonts?.ready)
        document.fonts.ready.then(() => {
          if (axis.isConnected) refreshLayout();
        });
      if (p.show_value && p.value_position === "above") box.prepend(output);
      if (p.show_value && p.value_position === "below") box.append(output);
    }
    if (e.type === "image") {
      if (p.src)
        box.append(
          el("img", {
            src: p.src,
            alt: p.alt || "",
            style: "max-width:100%;height:auto",
          }),
        );
      else
        box.append(
          el(
            "div",
            {
              class: "ae-image-placeholder",
              role: "img",
              "aria-label": p.alt || (p.empty_image_message ?? "Imagen sin asignar"),
            },
            p.empty_image_message ?? "Imagen sin asignar",
          ),
        );
      if (p.caption) box.append(el("p", {}, p.caption));
    }
    return box;
  }
  A.confirmDiscard=confirmDiscard;
  A.el = el;
  A.widget = widget;
})(window);
