(function (root) {
  "use strict";
  const A = root.EIPSIAssessment,
    el = A.el;
  A.mount = function (host, config, transport) {
    let d = null,
      selected = null,
      revision = 0,
      dirty = false,
      saving = false,
      drag = null, historical = null, viewEpoch = 0;
    const api =
      transport ||
      async function (operation, data = {}) {
        const response = await fetch(config.url, {
          method: "POST",
          credentials: "same-origin",
          body: new URLSearchParams({
            action: "eipsi_assessment_editor",
            nonce: config.nonce,
            operation,
            ...data,
          }),
        });
        let result;
        try {
          result = await response.json();
        } catch (_) {
          throw Error(
            "Respuesta inesperada. Recargá la página e intentá de nuevo.",
          );
        }
        if (!response.ok || !result.success)
          throw Error(
            (result.data && result.data.message) ||
              "No se pudo completar la operación.",
          );
        return result.data;
      };
    const message = el("p", {
        class: "ae-message",
        role: "status",
        "aria-live": "polite",
      }),
      status = el("span", { class: "ae-save-status", role: "status" }),
      toolbar = el("header", { class: "ae-topbar" }),
      workspace = el("div", { class: "ae-workspace" }),
      palette = el("aside", { class: "ae-palette", "aria-label": "Elementos" }),
      canvas = el("main", { class: "ae-canvas", "aria-label": "Evaluación" }),
      inspector = el("aside", {
        class: "ae-inspector",
        "aria-label": "Propiedades",
      });
    const save = button("Guardar", saveNow, "ae-primary"),
      preview = button("Preview", () => action(() => {
        if (d)
          A.openPreview(d, {
            exporter: (s) =>
              api("export", {
                definition: A.serialize(s.resolvedDefinition),
                answers: JSON.stringify(s.answers),
                touched: JSON.stringify(s.touched),
              }),
          });
      },true)),
      library = button("Listado de evaluaciones", showLibrary),
      create = button("Nueva evaluación", newAssessment);
    const importFile = el("input", { type: "file", accept: ".json,application/json", hidden: true, "aria-label": "Importar evaluación JSON" });
    const importJson = button("Importar JSON", () => {
      if (!allowDiscard()) return;
      importFile.value = "";
      importFile.click();
    });
    const exportJson = button("Exportar JSON", async () => {
      try {
        const title = d.title;
        const document = await api("export_definition", { id: d.id, revision: String(revision) });
        const url = root.URL.createObjectURL(new Blob([JSON.stringify(document)], { type: "application/json" }));
        const link = el("a", { href: url, download: title.replace(/[^a-zA-Z0-9_-]+/g, "-") + ".json" });
        link.click();root.URL.revokeObjectURL(url);
      } catch (error) { announce(error.message, true); }
    });
    importFile.onchange = async () => {
      const file = importFile.files?.[0];
      if (!file || saving) return;
      if (file.size > 1048576) { announce("El archivo supera el máximo de 1 MB.", true);return; }
      saving = true;workspace.inert = true;state();
      try {
        const document = await new Promise((resolve, reject) => {
          const reader = new root.FileReader();
          reader.onload = () => resolve(reader.result);
          reader.onerror = () => reject(Error("No se pudo leer el archivo."));
          reader.readAsText(file);
        });
        JSON.parse(document);
        const record = await api("import_definition", { document });
        ++viewEpoch;historical=null;d = A.normalizeDefinition(record.definition);revision = record.revision;
        selected = null;dirty = false;render();
        announce("Evaluación importada.");
      } catch (error) { announce(error instanceof SyntaxError ? "El archivo no contiene un JSON válido." : error.message, true); }
      finally { saving = false;workspace.inert = false;state(); }
    };
    const questionFile = el("input", { type: "file", accept: ".json,application/json", hidden: true, "aria-label": "Importar pregunta JSON" });
    const importQuestion = button("Importar pregunta JSON", () => { questionFile.value = "";questionFile.click(); });
    questionFile.onchange = async () => {
      const file = questionFile.files?.[0];
      if (!file || !d || saving) return;
      if (file.size > 1048576) { announce("El archivo supera el máximo de 1 MB.", true);return; }
      const target = selected && A.find(d, selected);
      const container = target && ["group", "randomization"].includes(target.node.type);
      const parent = container ? target.node.id : target?.parent?.id || null;
      const index = container ? target.node.children.length : target ? target.index + 1 : d.elements.length;
      saving = true;workspace.inert = true;state();
      try {
        const document = await new Promise((resolve, reject) => {
          const reader = new root.FileReader();reader.onload = () => resolve(reader.result);
          reader.onerror = () => reject(Error("No se pudo leer el archivo."));reader.readAsText(file);
        });
        JSON.parse(document);
        const question = await api("import_question", { document });
        const next = JSON.parse(A.serialize(d));
        const inserted = A.importQuestion(next, question, parent, index);
        d = next;selected = inserted.id;changed();render();
        announce("Pregunta importada.");
      } catch (error) { announce(error instanceof SyntaxError ? "El archivo no contiene un JSON válido." : error.message, true); }
      finally { saving = false;workspace.inert = false;state(); }
    };
    function downloadJson(document, name) {
      const url = root.URL.createObjectURL(new Blob([JSON.stringify(document)], { type: "application/json" }));
      const link = el("a", { href: url, download: name.replace(/[^a-zA-Z0-9_-]+/g, "-") + ".json" });
      link.click();root.URL.revokeObjectURL(url);
    }
    const versionHistory = button("Versiones publicadas", () => showVersions(d?.id));
    const historyInfo = el("div", {class:"ae-version-info",hidden:true});
    function lockHistory(container) {
      if (!historical) return;
      container.querySelectorAll("input,textarea,select,button").forEach(n => n.disabled = true);
      container.querySelectorAll('[contenteditable="true"]').forEach(n => n.contentEditable = "false");
      container.querySelectorAll('[draggable="true"]').forEach(n => n.draggable = false);
    }
    async function openVersion(id, version) {
      if (!allowDiscard()) return;
      const epoch = ++viewEpoch;
      try {
        const record = await api("load_version", {id,version_id:version});
        if (epoch !== viewEpoch) return;
        d=A.normalizeDefinition(record.definition);historical=record.version;revision=0;selected=null;dirty=false;render();
        announce("Consultando versión publicada "+historical.number+".");
      } catch(e) {announce(e.message,true);}
    }
    async function showVersions(id) {
      if (!id) return;
      try {
        const versions=await api("versions",{id});
        const dialog=el("dialog",{class:"ae-versions-modal","aria-label":"Versiones publicadas"});
        dialog.append(el("h2",{},"Versiones publicadas"),button("Cerrar",()=>dialog.close()));
        if (!versions.length) dialog.append(el("p",{},"Esta evaluación todavía no tiene versiones publicadas."));
        for(const v of versions){const row=el('article',{class:'ae-library-item'});row.append(el('strong',{},'Versión '+v.number),el('span',{},'Revisión '+v.author_revision+' · '+new Date(v.created_at*1000).toLocaleString()+(v.state==='RETIRED'?' · Retirada':'')),button('Ver versión '+v.number,async()=>{dialog.close();await openVersion(id,v.id);}));dialog.append(row);} 
        dialog.addEventListener('close',()=>dialog.remove(),{once:true});host.append(dialog);dialog.showModal();
      } catch(e) {announce(e.message,true);}
    }
    const settings = button("Configuración", () => {
      if (d) {
        selected = null;
        renderInspector();
      }
    });
    const publish = button("Publicar versión", async () => {
      if (!d) return;
      if (dirty) {
        announce("Guardá los cambios antes de publicar.", true);
        return;
      }
      if (
        !root.confirm("¿Publicar una versión inmutable de “" + d.title + "”?")
      )
        return;
      try {
        const result = await api("publish", {
          id: d.id,
          revision: String(revision),
        });
        announce("Versión publicada: " + result.version_id);
        const local = button("Preparar ejecución para mi sesión", async () => {
          try {
            const execution = await api("assign", {
              version_id: result.version_id,
            });
            const link = el(
              "a",
              { href: execution.url, target: "_blank", rel: "noopener" },
              "Abrir evaluación participante",
            );
            message.append(" · ", link, button("Exportar respuestas guardadas", async () => {
              try {
                const data = await api("export_execution", {execution_id: execution.execution_id});
                const url = root.URL.createObjectURL(new Blob([data.csv], {type: "text/csv;charset=utf-8"}));
                const download = el("a", {href: url, download: execution.execution_id + ".csv"});
                download.click(); root.URL.revokeObjectURL(url);
              } catch (e) { announce(e.message, true); }
            }));
          } catch (e) {
            announce(e.message, true);
          }
        });
        message.append(local);
      } catch (e) {
        announce(e.message, true);
      }
    });
    const moreActions=el('details',{class:'ae-more-actions'}),
      moreToggle=el('summary',{'aria-label':'Más opciones',title:'Más opciones'},'⋮'),
      morePanel=el('div',{class:'ae-more-actions-panel'});
    morePanel.append(library,versionHistory,importJson,exportJson,importQuestion);
    moreActions.append(moreToggle,morePanel);
    moreActions.addEventListener('click',event=>{if(event.target.closest('button'))moreActions.open=false;},true);
    moreActions.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();moreActions.open=false;moreToggle.focus();}});
    host.ownerDocument.addEventListener('click',event=>{host.querySelectorAll('details.ae-more-actions').forEach(menu=>{if(!menu.contains(event.target))menu.open=false;});});
    toolbar.append(
      el("h1", {}, "Assessment Editor"),
      importFile,
      questionFile,
      status,
      preview,
      settings,
      publish,
      save,
      create,
      moreActions,
    );
    workspace.append(palette, canvas, inspector);
    host.replaceChildren(toolbar, message, historyInfo, workspace);
    function button(text, fn, cls = "") {
      const b = el("button", { type: "button", class: cls }, text);
      b.onclick = (event) => {
        event.stopPropagation();
        fn(event);
      };
      return b;
    }
    function announce(text, error = false) {
      message.textContent = text;
      message.classList.toggle("ae-error", error);
    }
    function state(text) {
      versionHistory.hidden=!d||(!historical&&revision===0);
      versionHistory.disabled=saving;
      historyInfo.hidden=!historical;
      historyInfo.replaceChildren();
      if(historical)historyInfo.append(el('strong',{},'Versión '+historical.number+' · Solo lectura'),el('span',{},' · Revisión '+historical.author_revision),button('Volver al draft',async()=>{const epoch=++viewEpoch;try{const record=await api('load',{id:d.id});if(epoch!==viewEpoch)return;historical=null;d=A.normalizeDefinition(record.definition);revision=record.revision;dirty=false;selected=null;render();announce('Draft abierto.');}catch(e){announce(e.message,true);}}));

      status.textContent =
        text || (historical ? "Versión publicada · Solo lectura" : "") ||
        (saving ? "Guardando…" : dirty ? "Cambios sin guardar" : "Guardado");
      status.hidden = !d;
      preview.hidden = !d;
      save.hidden = !d;
      create.hidden = !!d;
      library.hidden = !d;
      publish.hidden = !d;
      exportJson.hidden = !d;
      exportJson.disabled = !!historical || !d || dirty || saving || revision === 0;
      exportJson.title = dirty ? "Guardá los cambios antes de exportar." : "Exportar evaluación JSON";
      importJson.disabled = saving;
      importQuestion.hidden = !d;
      importQuestion.disabled = !!historical || !d || saving;
      host.querySelectorAll("[data-export-question]").forEach(button => {
        button.disabled = !!historical || dirty || saving || revision === 0;
        button.title = dirty ? "Guardá los cambios antes de exportar." : "Exportar pregunta JSON";
      });
      settings.hidden = !d;
      save.disabled = !!historical || !d || saving;
      preview.disabled = !d || saving;
      settings.disabled = saving;
      publish.disabled = !!historical || saving;
      library.disabled = saving;
      create.disabled = saving;
    }
    function changed() {
      if(historical)return;
      dirty = true;
      if (d) {
        A.refreshDerived(d);
        root.EIPSIAssessmentFlow.normalize(d);
        host.querySelectorAll("[data-row-variable-id]").forEach((n) => {
          const owner = A.find(d, n.dataset.matrixId)?.node;
          const r = owner?.properties.matrix_rows.find(
            (r) => r.id === n.dataset.rowVariableId,
          );
          if (r) n.textContent = r.variable_name;
        });
        const errors = A.variableErrors(d);
        host.querySelectorAll(".ae-variable-name").forEach((n) => {
          const id =
            n.closest("[data-row-id]")?.dataset.rowId ||
            n.closest(".ae-element")?.dataset.id;
          const problem = errors.find((x) => x.id === id);
          n.setAttribute("aria-invalid", String(Boolean(problem)));
          n.setCustomValidity(problem?.message || "");
        });
        const problems = errors.map((x) => x.message).join(" ");
        if (problems) announce(problems, true);
        else if (message.textContent.startsWith("Nombre de variable"))
          announce("");
      }
      state();
    }
    function allowDiscard() {
      return (
        !dirty || root.confirm("Hay cambios sin guardar. ¿Querés descartarlos?")
      );
    }
    function action(fn, readable=false) {
      if(historical&&!readable)return;
      try {
        fn();
        announce("");
      } catch (error) {
        announce(error.message, true);
      }
    }
    function newAssessment() {
      if (!allowDiscard()) return;
      ++viewEpoch;historical=null;
      d = A.assessment();
      revision = 0;
      selected = null;
      dirty = true;
      render();
      announce("Agregá elementos con un clic o arrastrándolos al centro.");
    }
    async function showLibrary() {
      if (!allowDiscard()) return;
      ++viewEpoch;historical=null;historyInfo.hidden=true;
      d = null;
      workspace.classList.remove('ae-history-workspace');
      workspace.classList.add('ae-library-workspace');
      palette.hidden = true;inspector.hidden = true;
      selected = null;
      dirty = false;
      palette.replaceChildren();
      inspector.replaceChildren();
      canvas.replaceChildren(
        el("h2", {}, "Listado de evaluaciones"),
        el("p", {}, "Editá, duplicá o eliminá tus evaluaciones."),
      );
      state("");
      try {
        const rows = await api("list");
        if (d) return;
        const list = el("div", { class: "ae-library" });
        if (!rows.length)
          list.append(
            el(
              "p",
              {},
              "Todavía no hay evaluaciones guardadas. Creá la primera.",
            ),
          );
        rows.forEach((r) => {
          const row = el("article", { class: "ae-library-item" });
          const open = async (record) => {
            ++viewEpoch;historical=null;
            d = A.normalizeDefinition(record.definition);
            revision = record.revision;
            selected = null;
            dirty = false;
            render();
            announce("Evaluación abierta.");
          };
          row.append(
            el("strong", {}, r.title),
            el("span", {}, "Revisión " + r.revision),
          );
          row.append(
            button("Editar", async () => {
              try {
                await open(await api("load", { id: r.id }));
              } catch (e) {
                announce(e.message, true);
              }
            }),
          );
          row.append(
            button("Duplicar", async () => {
              try {
                await open(
                  await api("duplicate", {
                    id: r.id,
                    revision: String(r.revision),
                  }),
                );
              } catch (e) {
                announce(e.message, true);
              }
            }),
          );
          row.append(
            button("Eliminar", async () => {
              if (
                !root.confirm(
                  "¿Eliminar la evaluación “" +
                    r.title +
                    "”? Esta acción elimina su draft y parciales de autoría.",
                )
              )
                return;
              try {
                await api("delete", { id: r.id, revision: String(r.revision) });
                await showLibrary();
              } catch (e) {
                announce(e.message, true);
              }
            }),
          );
          const rowMenu=el('details',{class:'ae-more-actions'}),rowToggle=el('summary',{'aria-label':'Opciones de '+r.title,title:'Opciones de la evaluación'},'⋮'),rowPanel=el('div',{class:'ae-more-actions-panel'});
          rowPanel.append(button('Versiones publicadas',()=>showVersions(r.id)));
          rowMenu.append(rowToggle,rowPanel);
          rowMenu.addEventListener('click',event=>{if(event.target.closest('button'))rowMenu.open=false;},true);
          rowMenu.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();rowMenu.open=false;rowToggle.focus();}});
          row.append(rowMenu);
          list.append(row);
        });
        canvas.append(list);
      } catch (error) {
        announce(error.message, true);
      }
    }
    async function saveNow() {
      if (!d || saving || historical) return;
      const conflicts = A.variableErrors(d);
      if (conflicts.length) {
        announce(conflicts[0].message, true);
        return;
      }
      const snapshot = A.serialize(d), saveEpoch=viewEpoch;
      saving = true;
      state();
      announce("");
      try {
        const record = await api("save", {
          definition: snapshot,
          revision: String(revision),
        });
        if (!d || historical || saveEpoch!==viewEpoch || d.id !== record.definition.id) return;
        revision = record.revision;
        dirty = A.serialize(d) !== snapshot;
        if (!dirty) {
          d = A.normalizeDefinition(record.definition);
          renderCanvas();
          renderInspector();
        }
        announce("Evaluación guardada.");
      } catch (error) {
        announce("No se pudo guardar: " + error.message, true);
        state("Error al guardar");
      } finally {
        saving = false;
        if (!message.classList.contains("ae-error")) state();
        else save.disabled = !d;
      }
    }
    function setSelected(id) {
      if (selected === id) return;
      selected = id;
      canvas
        .querySelectorAll(".ae-element")
        .forEach((n) => n.classList.toggle("is-selected", n.dataset.id === id));
      renderInspector();
    }
    function insertion(type) {
      action(() => {
        let parent = null,
          index = d.elements.length;
        if (selected && A.find(d, selected)) {
          const f = A.find(d, selected);
          if (["group", "randomization"].includes(f.node.type) && type !== "page_break") {
            parent = f.node.id;
            index = f.node.children.length;
          } else if (type === "page_break") {
            let current = f;
            while (current.parent) current = A.find(d, current.parent.id);
            index = current.index + 1;
          } else {
            parent = f.parent && f.parent.id;
            index = f.index + 1;
          }
        }
        const e = A.element(type, d);
        A.insert(d, e, parent, index);
        selected = e.id;
        changed();
        render();
      });
    }
    function renderPalette() {
      palette.replaceChildren(el("h2", {}, "Elementos"));
      [
        ["Texto", ["text", "date"]],
        ["Selección", ["single_choice", "multiple_choice", "select"]],
        ["Escalas", ["likert", "vas", "matrix"]],
        ["Contenido", ["instruction", "image"]],
        ["Estructura", ["group", "randomization", "page_break"]],
      ].forEach(([name, types]) => {
        palette.append(el("h3", {}, name));
        types.forEach((type) => {
          const b = button(
            "+ " + A.labels[type],
            () => insertion(type),
            "ae-palette-item",
          );
          b.dataset.type = type;
          b.draggable = true;
          b.addEventListener("dragstart", (event) =>
            startDrag(event, { kind: "palette", type }),
          );
          b.addEventListener("dragend", endDrag);
          palette.append(b);
        });
      });

    }
    function startDrag(event, payload) {
      if(historical){event.preventDefault();return;}
      drag = payload;
      if (event.dataTransfer) {
        event.dataTransfer.setData(
          "application/x-eipsi-assessment",
          JSON.stringify(payload),
        );
        event.dataTransfer.effectAllowed =
          payload.kind === "palette" ? "copy" : "move";
      }
      host.classList.add("ae-dragging");
      event.stopPropagation();
    }
    function endDrag() {
      drag = null;
      host.classList.remove("ae-dragging");
      host
        .querySelectorAll(".ae-drop-active")
        .forEach((n) => n.classList.remove("ae-drop-active"));
    }
    function zone(parent, index) {
      const z = el("div", {
        class: "ae-dropzone",
        tabindex: "0",
        "data-parent": parent || "",
        "data-index": index,
        "aria-label":
          "Insertar aquí: " + (parent ? "dentro del grupo" : "evaluación"),
      });
      z.append(el("span", {}, "+ Soltar aquí"));
      z.addEventListener("dragover", (event) => {
        event.preventDefault();
        event.stopPropagation();
        z.classList.add("ae-drop-active");
      });
      z.addEventListener("dragleave", () =>
        z.classList.remove("ae-drop-active"),
      );
      z.addEventListener("drop", (event) => {
        event.preventDefault();
        event.stopPropagation();
        action(() => {
          let payload = drag;
          if (event.dataTransfer) {
            const raw = event.dataTransfer.getData(
              "application/x-eipsi-assessment",
            );
            if (raw) payload = JSON.parse(raw);
          }
          if (!payload) throw Error("Arrastrá un elemento de esta evaluación.");
          if (payload.kind === "palette") {
            const e = A.element(payload.type, d);
            A.insert(d, e, parent, index);
            selected = e.id;
          } else if (payload.kind === "element") {
            A.move(d, payload.id, parent, index);
            selected = payload.id;
          } else throw Error("Elemento no reconocido");
          changed();
          render();
        });
        endDrag();
      });
      return z;
    }
    function inline(value, label, oninput, multiline = false) {
      const input = el(multiline ? "textarea" : "input", {
        class: "ae-inline",
        "aria-label": label,
      });
      input.value = value;
      input.addEventListener("input", () => {
        oninput(input.value);
        changed();
      });
      return input;
    }
    function markCustom(e) {
      if (!["likert", "matrix"].includes(e.type)) return;
      e.properties.preset = "custom";
      if (selected === e.id) {
        const field = inspector.querySelector('[data-property="preset"]');
        if (field) field.value = "custom";
      }
    }
    function optionEditor(e) {
      const box = el("div", { class: "ae-option-editor" });
      e.options.forEach((o, i) => {
        const row = el("div", {
          class:
            "ae-option-row" +
            (["likert", "matrix"].includes(e.type)
              ? " ae-scale-option-card"
              : ""),
        });
        const optionInput = inline(
          o.label,
          "Texto de opción " + (i + 1),
          (v) => {
            o.label = v;
            markCustom(e);
            host.querySelectorAll("[data-option-id]").forEach((other) => {
              if (
                other.dataset.optionId === o.id &&
                ["INPUT", "TEXTAREA"].includes(other.tagName)
              )
                other.value = v;
            });
          },
          true,
        );
        optionInput.dataset.optionId = o.id;
        row.append(
          el(
            "span",
            { "aria-hidden": "true" },
            e.type === "multiple_choice" ? "☐" : "○",
          ),
          optionInput,
          button("↑", () => {
            if (i > 0) {
              [e.options[i - 1], e.options[i]] = [
                e.options[i],
                e.options[i - 1],
              ];
              markCustom(e);
              changed();
              render();
            }
          }),
          button("↓", () => {
            if (i < e.options.length - 1) {
              [e.options[i + 1], e.options[i]] = [
                e.options[i],
                e.options[i + 1],
              ];
              markCustom(e);
              changed();
              render();
            }
          }),
          button("Quitar", () => {
            if (e.options.length <= 1) {
              announce("Conservá al menos una opción.", true);
              return;
            }
            markCustom(e);
            e.options.splice(i, 1);
            changed();
            render();
          }),
        );
        if (["likert", "matrix"].includes(e.type)) {
          const value = el("input", {
            type: "number",
            step: "any",
            "aria-label": "Valor analítico de opción " + (i + 1),
            placeholder: "Etiqueta como valor",
          });
          value.value = o.value ?? "";
          value.onchange = () => {
            o.value = value.value === "" ? null : Number(value.value);
            markCustom(e);
            changed();
          };
          row.append(el("span", { class: "ae-scale-value-label" }, "Valor"), value);
        }
        if (["likert", "matrix"].includes(e.type)) {
          const actions = el("div", { class: "ae-scale-option-actions" });
          Array.from(row.querySelectorAll(":scope > button")).forEach((b) =>
            actions.append(b),
          );
          row.append(actions);
          optionInput.before(
            el("span", { class: "ae-scale-label-title" }, "Etiqueta"),
          );
        }
        if (e.properties.randomize_options === true && root.EIPSIAssessmentRandomization.optionTypes.includes(e.type)) {
          const label = el("label", {class:"ae-option-fixed"}), fixed = el("input", {type:"checkbox", "data-fixed-option":o.id});
          fixed.checked = o.fixed === true;
          fixed.onchange = () => {o.fixed = fixed.checked; changed(); renderCanvas();};
          label.append(fixed, el("span", {}, "Posición fija")); row.append(label);
        }
        box.append(row);
      });
      box.append(
        button("+ Agregar opción", () => {
          if (e.options.length >= 100) {
            announce("Máximo 100 opciones.", true);
            return;
          }
          markCustom(e);
          const option = A.option("Nueva opción");
          if (["likert", "matrix"].includes(e.type)) option.value = null;
          e.options.push(option);
          changed();
          render();
        }),
      );
      return box;
    }
    function moveRelative(e, delta) {
      action(() => {
        const f = A.find(d, e.id),
          target = f.index + delta;
        if (target < 0 || target >= f.items.length) return;
        A.move(
          d,
          e.id,
          f.parent && f.parent.id,
          delta < 0 ? target : target + 1,
        );
        changed();
        render();
      });
    }
    function renderElement(e) {
      const card = el("section", {
        class: "ae-element" + (selected === e.id ? " is-selected" : ""),
        "data-id": e.id,
        tabindex: "0",
        "aria-label": A.labels[e.type],
      });
      card.addEventListener("click", (event) => {
        if (event.target.closest(".ae-element") === card) setSelected(e.id);
      });
      card.addEventListener("focusin", (event) => {
        if (event.target.closest(".ae-element") === card) setSelected(e.id);
      });
      const tools = el("div", { class: "ae-element-tools" }),
        handle = button("⠿ Mover", () => setSelected(e.id), "ae-drag-handle");
      handle.draggable = true;
      handle.addEventListener("dragstart", (event) =>
        startDrag(event, { kind: "element", id: e.id }),
      );
      handle.addEventListener("dragend", endDrag);
      tools.append(
        el("span", { class: "ae-kind" }, A.labels[e.type]),
        handle,
        button("↑", () => moveRelative(e, -1)),
        button("↓", () => moveRelative(e, 1)),
        button("Duplicar", () => {
          selected = A.duplicate(d, e.id).id;
          changed();
          render();
        }),
        button("Eliminar", () => {
          A.remove(d, e.id);
          selected = null;
          changed();
          render();
        }),
      );
      if (A.questions.includes(e.type)) {
        const exportQuestion = button("Exportar pregunta JSON", async () => {
          try {
            const name = e.properties.variable_name || e.type;
            const document = await api("export_question", { id: d.id, revision: String(revision), question_id: e.id });
            downloadJson(document, name);
          } catch (error) { announce(error.message, true); }
        });
        exportQuestion.dataset.exportQuestion = e.id;
        tools.append(exportQuestion);
      }
      card.append(tools);
      if (e.type === "page_break") {
        card.classList.add("ae-page-break");
        card.append(el("strong", {}, "──────── Salto de página ────────"));
        return card;
      }
      if (e.response_key) {
        const variable = el("input", {
          class: "ae-variable-name",
          "aria-label": "Nombre de variable",
        });
        variable.value = e.properties.variable_name;
        variable.oninput = () => {
          e.properties.variable_name = variable.value;
          const field = inspector.querySelector(
            '[data-property="variable_name"]',
          );
          if (field) field.value = variable.value;
          changed();
        };
        card.append(variable);
        card.append(
          A.richText.editor(e.properties, "label", "Texto de pregunta", () => {
            changed();
          }),
        );
        if (e.properties.required)
          card.append(el("span", { class: "ae-required" }, "* Obligatoria"));
        card.append(
          A.richText.editor(
            e.properties,
            "helper",
            "Ayuda de la pregunta",
            () => {
              changed();
            },
          ),
        );
      } else if (e.type === "instruction")
        card.append(
          A.richText.editor(e.properties, "label", "Instrucciones", () =>
            changed(),
          ),
        );
      else
        card.append(
          inline(
            e.properties.label,
            e.type === "group" ? "Título de sección" : "Instrucciones",
            (v) => (e.properties.label = v),
            e.type === "instruction",
          ),
        );
      if (["group", "randomization"].includes(e.type)) {
        if (e.type === "randomization") {
          card.classList.add("ae-randomization");
          card.append(el("p", {class: "ae-randomization-summary"}, A.randomizationSummary(e)));
        }
        const children = el("div", { class: "ae-group-children" });
        renderItems(e.children, children, e.id);
        card.append(children);
      } else {
        const edit = {
          row: () => changed(),
          option: (o, v) => {
            o.label = v;
            markCustom(e);
            host.querySelectorAll("[data-option-id]").forEach((other) => {
              if (
                other.dataset.optionId === o.id &&
                ["INPUT", "TEXTAREA"].includes(other.tagName)
              )
                other.value = v;
            });
            changed();
          },
          semantic: (index, v) => {
            e.properties.labels[index] = v;
            host.querySelectorAll("[data-semantic-index]").forEach((other) => {
              if (Number(other.dataset.semanticIndex) === index)
                other.value = v;
            });
            changed();
          },
        };
        card.append(A.widget(e, null, edit));
        if (e.response_key)
          card.append(
            A.richText.editor(e.properties, "footer", "Footer opcional", () =>
              changed(),
            ),
          );
        if (e.options.length) {
          const tools = el("details", { class: "ae-options-tools" });
          tools.append(
            el("summary", {}, "Agregar, quitar y ordenar opciones"),
            optionEditor(e),
          );
          if (A.questions.includes(e.type)) {
        const exportQuestion = button("Exportar pregunta JSON", async () => {
          try {
            const name = e.properties.variable_name || e.type;
            const document = await api("export_question", { id: d.id, revision: String(revision), question_id: e.id });
            downloadJson(document, name);
          } catch (error) { announce(error.message, true); }
        });
        exportQuestion.dataset.exportQuestion = e.id;
        tools.append(exportQuestion);
      }
      card.append(tools);
        }
      }
      return card;
    }
    function renderItems(items, container, parent = null) {
      container.append(zone(parent, 0));
      let page = 1;
      items.forEach((e, index) => {
        container.append(renderElement(e));
        if (!parent && e.type === "page_break") {
          page++;
          container.append(pageHeader(page - 1));
        }
        container.append(zone(parent, index + 1));
      });
      if (!items.length)
        container.append(
          el(
            "p",
            { class: "ae-empty" },
            parent
              ? "Arrastrá preguntas a este grupo."
              : "Arrastrá elementos aquí o elegilos en la paleta.",
          ),
        );
    }
    function property(e, key, label, type = "text", nullable = false) {
      const row = el("label", { class: "ae-property" }),
        input = el(type === "textarea" ? "textarea" : "input", {
          "data-property": key,
        });
      if (type !== "textarea") input.type = type;
      if (type === "number") input.step = "any";
      if (e.type === "randomization" && key === "count") {input.step = "1"; input.min = "1"; input.max = String(Math.max(0, root.EIPSIAssessmentRandomization.eligible(e).length - 1));}
      row.append(el("span", {}, label));
      if (type === "checkbox") {
        input.checked = e.properties[key];
        if (e.type === "matrix" && key === "required") {
          const rows = e.properties.matrix_rows;
          input.checked = e.properties.required || rows.every(row => row.required);
          input.indeterminate = !input.checked && rows.some(row => row.required);
        }
      }
      else input.value = e.properties[key] ?? ({required_message:'Respondé esta pregunta para continuar.',matrix_heading:'Item'}[key] ?? "");
      if(key==="selection_min_message")input.placeholder="Elegí al menos [MINIMO] opciones.";
      if(key==="selection_max_message")input.placeholder="Elegí como máximo [MAXIMO] opciones.";
      const extraDefaults={unanswered_value_message:"Sin responder",length_message:'La respuesta debe tener entre [MINIMO] - [MAXIMO] caracteres.',number_range_message:'La respuesta debe estar entre [MINIMO] - [MAXIMO].',empty_image_message:'Imagen sin asignar',min_length_message:'La respuesta debe tener al menos [MINIMO] caracteres.',max_length_message:'La respuesta no debe superar [MAXIMO] caracteres.',invalid_value_message:({date:'Ingresá una fecha válida.',vas:'Elegí un valor válido en la escala.',multiple_choice:'Elegí opciones disponibles sin repetir.'}[e.type]??'Elegí una opción disponible.'),date_placeholder:({'DD/MM/YYYY':'DD/MM/AAAA','MM/DD/YYYY':'MM/DD/AAAA'}[e.properties.date_format]??'AAAA-MM-DD'),date_open_label:'Elegir fecha',date_close_label:'Cerrar',date_previous_label:'Anterior',date_next_label:'Siguiente',date_month_names:'Enero|Febrero|Marzo|Abril|Mayo|Junio|Julio|Agosto|Septiembre|Octubre|Noviembre|Diciembre',date_weekday_names:'Domingo|Lunes|Martes|Miércoles|Jueves|Viernes|Sábado'};
      if(key in extraDefaults && e.properties[key]===undefined)input.value=extraDefaults[key];
      if(key==="validation_message")input.placeholder="Mensaje automático según la validación";
      const update = () => {
        e.properties[key] =
          type === "checkbox"
            ? input.checked
            : type === "number"
            ? nullable && input.value === ""
              ? null
              : Number(input.value)
            : input.value;
        if (e.type === "matrix" && key === "required")
          e.properties.matrix_rows.forEach(row => {row.required = input.checked;});
        if (["likert", "matrix"].includes(e.type) && key === "min")
          markCustom(e);
        if (e.type === "randomization" && key === "count") e.properties.algorithm = "selection_uniform_order";
        changed();
        renderCanvas();
        if (key === "min_selected" || key === "max_selected" || key === "randomize_options" || key === "show_value" || (e.type === "matrix" && key === "required") || (e.type === "randomization" && key === "count")) renderInspector();
      };
      input.addEventListener("change", update);
      if (type === "color" || type === "textarea" || key === "variable_name")
        input.addEventListener("input", update);
      row.append(input);
      inspector.append(row);
    }
    function semanticEditor(e) {
      const box = el("div", { class: "ae-semantic-editor" });
      e.properties.labels.forEach((label, index) => {
        const row = el("div", { class: "ae-semantic-card ae-option-row" }),
          input = el("textarea", {
            "aria-label": "Etiqueta semántica " + (index + 1),
            "data-semantic-index": index,
          });
        input.value = label;
        input.oninput = () => {
          e.properties.labels[index] = input.value;
          canvas.querySelectorAll("[data-label-index]").forEach((other) => {
            if (
              Number(other.dataset.labelIndex) === index &&
              other.closest(".ae-element").dataset.id === e.id
            ) {
              other.textContent = input.value;
              other.dispatchEvent(
                new root.Event("eipsi:vas-layout", { bubbles: true }),
              );
            }
          });
          changed();
        };
        const position = el("input", {
          type: "number",
          step: "any",
          placeholder: "Automática",
          "aria-label": "Posición de etiqueta " + (index + 1),
        });
        position.value = e.properties.label_settings[index].position ?? "";
        position.onchange = () => {
          e.properties.label_settings[index].position =
            position.value === "" ? null : Number(position.value);
          changed();
          renderCanvas();
        };
        const textField = el("label", { class: "ae-property" }),
          positionField = el("label", { class: "ae-property" }),
          actions = el("div", { class: "ae-semantic-actions" });
        textField.append(el("span", {}, "Texto"), input);
        positionField.append(
          el("span", {}, "Posición (valor de la escala)"),
          position,
        );
        row.append(
          el("strong", {}, "Etiqueta " + (index + 1)),
          textField,
          positionField,
        );
        actions.append(
          button("Automática", () => {
            position.value = "";
            e.properties.label_settings[index].position = null;
            changed();
            renderCanvas();
          }),
        );
        actions.append(
          button("↑", () => {
            if (index) {
              [e.properties.labels[index - 1], e.properties.labels[index]] = [
                e.properties.labels[index],
                e.properties.labels[index - 1],
              ];
              [
                e.properties.label_settings[index - 1],
                e.properties.label_settings[index],
              ] = [
                e.properties.label_settings[index],
                e.properties.label_settings[index - 1],
              ];
              changed();
              render();
            }
          }),
          button("↓", () => {
            if (index < e.properties.labels.length - 1) {
              [e.properties.labels[index + 1], e.properties.labels[index]] = [
                e.properties.labels[index],
                e.properties.labels[index + 1],
              ];
              [
                e.properties.label_settings[index + 1],
                e.properties.label_settings[index],
              ] = [
                e.properties.label_settings[index],
                e.properties.label_settings[index + 1],
              ];
              changed();
              render();
            }
          }),
          button("Quitar etiqueta", () => {
            if (e.properties.labels.length > 1) {
              e.properties.labels.splice(index, 1);
              e.properties.label_settings.splice(index, 1);
              changed();
              render();
            } else announce("Conservá al menos una etiqueta.", true);
          }),
        );
        row.append(actions);
        box.append(row);
      });
      box.append(
        button("+ Agregar etiqueta", () => {
          if (e.properties.labels.length < 100) {
            e.properties.labels.push("Nueva etiqueta");
            e.properties.label_settings.push({ id: A.id("L"), position: null });
            changed();
            render();
          }
        }),
      );
      return box;
    }
    function matrixEditor(e) {
      const box = el("div", { class: "ae-matrix-row-editor" });
      e.properties.matrix_rows.forEach((r, i) => {
        const row = el("div", { class: "ae-matrix-edit-row" });
        const required = el("input", {
          type: "checkbox",
          "aria-label": "Item obligatorio " + (i + 1),
        });
        required.checked = e.properties.required || r.required;
        required.onchange = () => {
          // Materialize the existing global rule before allowing one row to differ.
          if (e.properties.required) e.properties.matrix_rows.forEach(row => {row.required = true;});
          r.required = required.checked;
          e.properties.required = e.properties.matrix_rows.every(row => row.required);
          changed();
          renderCanvas();
          renderInspector();
        };
        row.append(
          el("strong", {}, "Item " + (i + 1)),
          A.richText.editor(r, "label", "Texto del item", () => {
            changed();
            renderCanvas();
          }),
        );
        row.append(
          el("span", {}, "Item obligatorio"),
          required,
          button("↑ Item", () => {
            if (i) {
              [e.properties.matrix_rows[i - 1], e.properties.matrix_rows[i]] = [
                r,
                e.properties.matrix_rows[i - 1],
              ];
              changed();
              render();
            }
          }),
          button("↓ Item", () => {
            if (i < e.properties.matrix_rows.length - 1) {
              [e.properties.matrix_rows[i + 1], e.properties.matrix_rows[i]] = [
                r,
                e.properties.matrix_rows[i + 1],
              ];
              changed();
              render();
            }
          }),
          button("Duplicar item", () => {
            if (e.properties.matrix_rows.length >= 100) return;
            const n = { ...A.copy(r), ...A.row(r.label) };
            n.label_html = r.label_html;
            n.helper_html = r.helper_html;
            n.footer_html = r.footer_html;
            n.required = r.required;
            e.properties.matrix_rows.splice(i + 1, 0, n);
            changed();
            render();
          }),
          button("Quitar item", () => {
            if (e.properties.matrix_rows.length > 1) {
              e.properties.matrix_rows.splice(i, 1);
              changed();
              render();
            }
          }),
        );
        box.append(row);
      });
      box.append(
        button("+ Agregar item", () => {
          if (e.properties.matrix_rows.length < 100) {
            const row = A.row();
            row.required = e.properties.required || e.properties.matrix_rows.every(item => item.required);
            e.properties.matrix_rows.push(row);
            changed();
            render();
          }
        }),
      );
      return box;
    }
    const panelState = new Map();
    function renderInspector() {
      renderInspectorContent();
      let section = null;
      for (const node of [...inspector.children]) {
        if (node.tagName === "H3") {
          if (section && section.children.length === 1) section.remove();
          const name = node.textContent, key = (selected || "@settings") + ":" + name;
          section = el("details", {class: "ae-property-section"});
          section.open = panelState.get(key) ?? false;
          section.append(el("summary", {}, name));
          const current = section;
          section.addEventListener("toggle", () => panelState.set(key, current.open));
          node.replaceWith(section);
        } else if (node.tagName === "DETAILS") {
          if (section && section.children.length === 1) section.remove();
          section = null;
          node.classList.add("ae-property-section");
        } else if (section) section.append(node);
      }
      if (section && section.children.length === 1) section.remove();
      lockHistory(inspector);
    }
    function renderInspectorContent() {
      inspector.replaceChildren(el("h2", {}, "Propiedades"));
      if (selected === "@completion") {
        A.completionInspector(d.flow.completion, inspector, changed);
        return;
      }
      const page = selected?.startsWith("@page:")
        ? d.flow.pages.find((p) => p.id === selected.slice(6))
        : selected
        ? d.flow.pages.find((p) => p.boundary_id === selected)
        : null;
      if (page) {
        inspector.append(el("p", {}, "Editá el título y la descripción directamente en la página."));
        return;
      }
      const f = selected && A.find(d, selected);
      if (!f) {
        if (d)
          A.flowInspector(d, inspector, () => {
            changed();
            renderCanvas();
          });
        else inspector.append(el("p", {}, "Creá o abrí una evaluación."));
        return;
      }
      const e = f.node;
      inspector.append(el("h3", {}, A.labels[e.type]));
      A.controlInspector(e, {
        inspector,
        el,
        property,
        options: optionEditor,
        semantic: semanticEditor,
        rows: matrixEditor,
        refresh: () => {
          changed();
          renderCanvas();
          renderInspector();
        },
      });
      if (e.type === "image") {
        inspector.append(button("Elegir imagen en WordPress",()=>{
          const picker=root.wp.media({title:"Elegir imagen",library:{type:"image"},multiple:false});
          picker.on("select",()=>{const image=picker.state().get("selection").first().toJSON();e.properties.src=image.url;if(!e.properties.alt)e.properties.alt=image.alt||"";changed();renderCanvas();renderInspector();});picker.open();
        }));
        property(e, "src", "URL de imagen");
        property(e, "alt", "Texto alternativo");
        property(e, "caption", "Descripción");
        property(e,"empty_image_message","Texto cuando no hay imagen");
        inspector.append(
          el(
            "p",
            {},
            "Seleccioná una imagen de la biblioteca de WordPress o cargá una nueva.",
          ),
        );
      }
      if (e.type !== "page_break") {
        const groups = [];
        A.walk(d.elements, (g) => {
          if (["group", "randomization"].includes(g.type) && g.id !== e.id) {
            let descendant = false;
            A.walk(e.children, (c) => {
              if (c.id === g.id) descendant = true;
            });
            if (!descendant) groups.push(g);
          }
        });
        if (groups.length) {
          const select = el("select", { "aria-label": "Grupo de destino" });
          groups.forEach((g) =>
            select.append(el("option", { value: g.id }, g.properties.label)),
          );
          inspector.append(
            select,
            button("Mover al grupo", () =>
              action(() => {
                A.move(
                  d,
                  e.id,
                  select.value,
                  A.find(d, select.value).node.children.length,
                );
                changed();
                render();
              }),
            ),
          );
        }
        if (f.parent)
          inspector.append(
            button("Sacar del grupo", () => {
              const parent = A.find(d, f.parent.id);
              A.move(
                d,
                e.id,
                parent.parent && parent.parent.id,
                parent.index + 1,
              );
              changed();
              render();
            }),
          );
      }
      const advanced = el("details"),
        summary = el("summary", {}, "Avanzado");
      advanced.append(
        summary,
        el("p", {}, "ID: " + e.id),
        el("p", {}, "Response key: " + (e.response_key || "No responde")),
        el("p", {}, "Tipo: " + e.type),
      );
      inspector.append(advanced);
    }
    function pageHeader(index) {
      const page = d.flow.pages[index], box = el("section", {class: "ae-page-header", "data-page-id": page.id});
      box.append(el("h2", {class: "ae-page-heading"}, "Página " + (index + 1)));
      const title = el("div"), description = el("div");
      function titleView() {
        title.replaceChildren();
        if (page.title) title.append(el("h3", {}, page.title));
        const control = button(page.title ? "Editar título" : "+ Agregar título", () => {
          const input = inline(page.title, "Título de página", v => {page.title = v;}, true);
          input.maxLength = 200;
          input.onblur = titleView;
          title.replaceChildren(input);
          A.autoGrow(input);
          input.focus();
        }, "ae-page-affordance");
        title.append(control);
      }
      function descriptionView() {
        description.replaceChildren();
        if (page.description_html) description.append(A.richText.render({label_html: page.description_html}, "label"));
        description.append(button(page.description_html ? "Editar descripción" : "+ Agregar descripción", () => {
          const draft = {label: "", label_html: page.description_html};
          const field = A.richText.editor(draft, "label", "Descripción de página", () => {
            page.description_html = draft.label_html ?? A.richText.escaped(draft.label);
            changed();
          });
          description.replaceChildren(field);
          field.querySelector('textarea,[contenteditable="true"]')?.focus();
        }, "ae-page-affordance"));
      }
      titleView(); descriptionView(); box.append(title, description);
      return box;
    }
    function renderCanvas() {
      canvas.replaceChildren();
      if (!d) return;
      const main = el("section", {
        class: "ae-assessment-container",
        tabindex: "0",
        "aria-label": "Configuración de la evaluación",
      });
      main.onclick = (e) => {
        if (e.target === main) {
          selected = null;
          renderInspector();
        }
      };
      main.onkeydown = (e) => {
        if (e.target === main && (e.key === "Enter" || e.key === " ")) {
          e.preventDefault();
          selected = null;
          renderInspector();
        }
      };
      const title = inline(
        d.title,
        "Título de la evaluación",
        (v) => (d.title = v),
      );
      title.classList.add("ae-title");
      title.onclick = () => {
        selected = null;
        renderInspector();
      };
      main.append(
        title,
        el(
          "p",
          { class: "ae-helper" },
          "Seleccioná este contenedor para configurar la evaluación.",
        ),
        pageHeader(0),
      );
      renderItems(d.elements, main);
      canvas.append(main);
      const c = d.flow.completion,
        card = el("section", {
          class: "ae-completion-card",
          tabindex: "0",
          "aria-label": "Pantalla final",
        });
      card.append(el("h2", {}, "Pantalla final"));
      const content = {label: "", label_html: (c.title ? "<h2>" + A.richText.escaped(c.title) + "</h2>" : "") + c.message_html +
        (c.image_url ? '<p><img src="' + A.richText.escaped(c.image_url) + '" alt=""></p>' : "")};
      card.append(A.richText.editor(content, "label", "Contenido de pantalla final", () => {
        c.title = ""; c.image_url = "";
        c.message_html = content.label_html ?? A.richText.escaped(content.label);
        changed();
      }));
      card.addEventListener("focusin", () => {
        if (selected !== "@completion") { selected = "@completion"; renderInspector(); }
      });
      canvas.append(card);
    }
    function render() {
      workspace.classList.remove('ae-library-workspace');
      workspace.classList.toggle('ae-history-workspace',!!historical);
      palette.hidden = false;inspector.hidden = false;
      renderPalette();
      renderCanvas();
      renderInspector();
      state();
      if(historical){palette.hidden=true;lockHistory(canvas);lockHistory(inspector);}
      library.disabled = saving;
      create.disabled = saving;
    }
    root.addEventListener("beforeunload", (event) => {
      if (dirty) {
        event.preventDefault();
        event.returnValue = "";
      }
    });
    showLibrary().then(async()=>{
      const params=new URL(root.location.href).searchParams,id=params.get('assessment');if(!id)return;
      if(params.get('version'))return openVersion(id,params.get('version'));
      try{const record=await api('load',{id});d=A.normalizeDefinition(record.definition);revision=record.revision;historical=null;selected=null;dirty=false;render();}catch(e){announce(e.message,true);}
    });
    return {
      newAssessment,
      showLibrary,
      save: saveNow,
      get definition() {
        return d;
      },
      get selected() {
        return selected;
      },
    };
  };
  function boot() {
    const host = document.getElementById("eipsi-assessment-editor");
    if (host && root.eipsiAssessmentEditorConfig)
      A.mount(host, root.eipsiAssessmentEditorConfig);
  }
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", boot);
  else boot();
})(window);
