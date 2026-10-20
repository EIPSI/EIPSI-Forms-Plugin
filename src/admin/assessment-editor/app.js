(function (root) {
  "use strict";
  const A = root.EIPSIAssessment,
    el = A.el;
  const typeNames = {text: 'Text', textarea: 'Long text', email: 'Email', number: 'Number', tel: 'Phone', date: 'Date', url: 'URL', single_choice: 'Single choice', multiple_choice: 'Multiple choice', select: 'Dropdown', likert: 'Likert', vas: 'VAS', matrix: 'Likert matrix', instruction: 'Text / instructions', image: 'Image', group: 'Group / section', randomization: 'Randomization', page_break: 'Page break'};
  const typeLabel = type => root.EIPSIAdminI18n.t(typeNames[type] || A.labels[type]);
  const editorViews = new WeakMap();
  // Rebuilding an editor must preserve navigation state, not change authored data.
  A.withEditorViewport = (host, draw, scope) => {
    const same = editorViews.get(host) === scope;editorViews.set(host,scope);
    if(!same)return draw();
    const path=node=>{const parts=[];while(node&&node!==host){const parent=node.parentElement;if(!parent)return null;parts.unshift(Array.prototype.indexOf.call(parent.children,node));node=parent;}return node===host?parts:null;};
    const locate=parts=>parts?.reduce((node,index)=>node?.children[index],host);
    const scroll=[host,...host.querySelectorAll('*')].filter(node=>node.scrollTop||node.scrollLeft).map(node=>({node,path:path(node),top:node.scrollTop,left:node.scrollLeft}));
    for(let node=host.parentElement;node;node=node.parentElement)scroll.push({node,top:node.scrollTop,left:node.scrollLeft});
    const details=[...host.querySelectorAll('details')].map(node=>({path:path(node),title:node.querySelector(':scope > summary')?.textContent,open:node.open}));
    const active=document.activeElement,focus=host.contains(active)&&active.matches('input,textarea,select,[contenteditable=true]')?{node:active,path:path(active),tag:active.tagName,type:active.type,label:active.closest('label')?.textContent,start:active.selectionStart,end:active.selectionEnd}:null;
    const x=root.scrollX,y=root.scrollY;
    try{return draw();}finally{
      details.forEach(saved=>{const node=locate(saved.path);if(node?.tagName==='DETAILS'&&node.querySelector(':scope > summary')?.textContent===saved.title)node.open=saved.open;});
      if(focus&&!focus.node.isConnected){const node=locate(focus.path);if(node?.tagName===focus.tag&&node.type===focus.type&&node.closest('label')?.textContent===focus.label){node.focus({preventScroll:true});if(focus.start!==null&&focus.start!==undefined)try{node.setSelectionRange(focus.start,focus.end);}catch(_){}}}
      scroll.forEach(saved=>{const node=saved.node.isConnected?saved.node:locate(saved.path);if(node){node.scrollTop=saved.top;node.scrollLeft=saved.left;}});
      if(root.scrollX!==x||root.scrollY!==y)root.scrollTo(x,y);
    }
  };
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
            root.EIPSIAdminI18n.t("Unexpected response. Reload the page and try again."),
          );
        }
        if (!response.ok || !result.success)
          throw Error(
            (result.data && result.data.message) ||
              root.EIPSIAdminI18n.t("Could not complete the operation."),
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
      palette = el("aside", { class: "ae-palette", "aria-label": root.EIPSIAdminI18n.t("Elements") }),
      canvas = el("main", { class: "ae-canvas", "aria-label": root.EIPSIAdminI18n.t("Assessment") }),
      inspector = el("aside", {
        class: "ae-inspector",
        "aria-label": root.EIPSIAdminI18n.t("Properties"),
      });
    const save = button(root.EIPSIAdminI18n.t("Save"), saveNow, "ae-primary"),
      preview = button(root.EIPSIAdminI18n.t("Preview"), () => action(() => {
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
      library = button(root.EIPSIAdminI18n.t("Assessment list"), showLibrary),
      create = button(root.EIPSIAdminI18n.t("New assessment"), newAssessment);
    const importFile = el("input", { type: "file", accept: ".json,application/json", hidden: true, "aria-label": root.EIPSIAdminI18n.t("Import assessment JSON") });
    const importJson = button(root.EIPSIAdminI18n.t("Import JSON"), () => {
      if (!allowDiscard()) return;
      importFile.value = "";
      importFile.click();
    });
    const exportJson = button(root.EIPSIAdminI18n.t("Export JSON"), async () => {
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
      if (file.size > 1048576) { announce(root.EIPSIAdminI18n.t("The file exceeds the 1 MB limit."), true);return; }
      saving = true;workspace.inert = true;state();
      try {
        const document = await new Promise((resolve, reject) => {
          const reader = new root.FileReader();
          reader.onload = () => resolve(reader.result);
          reader.onerror = () => reject(Error(root.EIPSIAdminI18n.t("Could not read the file.")));
          reader.readAsText(file);
        });
        JSON.parse(document);
        const record = await api("import_definition", { document });
        ++viewEpoch;historical=null;d = A.normalizeDefinition(record.definition);revision = record.revision;
        selected = null;dirty = false;render();
        announce(root.EIPSIAdminI18n.t("Assessment imported."));
      } catch (error) { announce(error instanceof SyntaxError ? root.EIPSIAdminI18n.t("The file does not contain valid JSON.") : error.message, true); }
      finally { saving = false;workspace.inert = false;state(); }
    };
    const questionFile = el("input", { type: "file", accept: ".json,application/json", hidden: true, "aria-label": root.EIPSIAdminI18n.t("Import question JSON") });
    const importQuestion = button(root.EIPSIAdminI18n.t("Import question JSON"), () => { questionFile.value = "";questionFile.click(); });
    questionFile.onchange = async () => {
      const file = questionFile.files?.[0];
      if (!file || !d || saving) return;
      if (file.size > 1048576) { announce(root.EIPSIAdminI18n.t("The file exceeds the 1 MB limit."), true);return; }
      const target = selected && A.find(d, selected);
      const container = target && ["group", "randomization"].includes(target.node.type);
      const parent = container ? target.node.id : target?.parent?.id || null;
      const index = container ? target.node.children.length : target ? target.index + 1 : d.elements.length;
      saving = true;workspace.inert = true;state();
      try {
        const document = await new Promise((resolve, reject) => {
          const reader = new root.FileReader();reader.onload = () => resolve(reader.result);
          reader.onerror = () => reject(Error(root.EIPSIAdminI18n.t("Could not read the file.")));reader.readAsText(file);
        });
        JSON.parse(document);
        const question = await api("import_question", { document });
        const next = JSON.parse(A.serialize(d));
        const inserted = A.importQuestion(next, question, parent, index);
        d = next;selected = inserted.id;changed();render();
        announce(root.EIPSIAdminI18n.t("Question imported."));
      } catch (error) { announce(error instanceof SyntaxError ? root.EIPSIAdminI18n.t("The file does not contain valid JSON.") : error.message, true); }
      finally { saving = false;workspace.inert = false;state(); }
    };
    function downloadJson(document, name) {
      const url = root.URL.createObjectURL(new Blob([JSON.stringify(document)], { type: "application/json" }));
      const link = el("a", { href: url, download: name.replace(/[^a-zA-Z0-9_-]+/g, "-") + ".json" });
      link.click();root.URL.revokeObjectURL(url);
    }
    const versionHistory = button(root.EIPSIAdminI18n.t("Published versions"), () => showVersions(d?.id));
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
        announce(root.EIPSIAdminI18n.f("Viewing published version %d." ,historical.number));
      } catch(e) {announce(e.message,true);}
    }
    async function showVersions(id) {
      if (!id) return;
      try {
        const versions=await api("versions",{id});
        const dialog=el("dialog",{class:"ae-versions-modal","aria-label":root.EIPSIAdminI18n.t("Published versions")});
        dialog.append(el("h2",{},root.EIPSIAdminI18n.t("Published versions")),button(root.EIPSIAdminI18n.t("Close"),()=>dialog.close()));
        if (!versions.length) dialog.append(el("p",{},root.EIPSIAdminI18n.t("This assessment has no published versions yet.")));
        for(const v of versions){const row=el('article',{class:'ae-library-item'});row.append(el('strong',{},root.EIPSIAdminI18n.f("Version %d" ,v.number)),el('span',{},root.EIPSIAdminI18n.f("Revision %d" ,v.author_revision)+' · '+new Date(v.created_at*1000).toLocaleString()+(v.state==='RETIRED'?root.EIPSIAdminI18n.t(' · Retired'):'')),button(root.EIPSIAdminI18n.f("View version %d" ,v.number),async()=>{dialog.close();await openVersion(id,v.id);}));dialog.append(row);}
        dialog.addEventListener('close',()=>dialog.remove(),{once:true});host.append(dialog);dialog.showModal();
      } catch(e) {announce(e.message,true);}
    }
    const settings = button(root.EIPSIAdminI18n.t("Configuration"), () => {
      if (d) {
        selected = null;
        renderInspector();
      }
    });
    const publish = button(root.EIPSIAdminI18n.t("Publish version"), async () => {
      if (!d) return;
      if (dirty) {
        announce(root.EIPSIAdminI18n.t("Save changes before publishing."), true);
        return;
      }
      if (
        !root.confirm(root.EIPSIAdminI18n.f("Publish an immutable version of “%s”?" ,d.title))
      )
        return;
      try {
        const result = await api("publish", {
          id: d.id,
          revision: String(revision),
        });
        announce(root.EIPSIAdminI18n.f("Published version: %s" ,result.version_id));
        const local = button(root.EIPSIAdminI18n.t("Prepare an execution for my session"), async () => {
          try {
            const execution = await api("assign", {
              version_id: result.version_id,
            });
            const link = el(
              "a",
              { href: execution.url, target: "_blank", rel: "noopener" },
              root.EIPSIAdminI18n.t("Open participant assessment"),
            );
            message.append(" · ", link, button(root.EIPSIAdminI18n.t("Export saved responses"), async () => {
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
      moreToggle=el('summary',{'aria-label':root.EIPSIAdminI18n.t("More options"),title:root.EIPSIAdminI18n.t("More options")},'⋮'),
      morePanel=el('div',{class:'ae-more-actions-panel'});
    morePanel.append(library,versionHistory,importJson,exportJson,importQuestion);
    moreActions.append(moreToggle,morePanel);
    moreActions.addEventListener('click',event=>{if(event.target.closest('button'))moreActions.open=false;},true);
    moreActions.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();moreActions.open=false;moreToggle.focus();}});
    host.ownerDocument.addEventListener('click',event=>{host.querySelectorAll('details.ae-more-actions').forEach(menu=>{if(!menu.contains(event.target))menu.open=false;});});
    toolbar.append(
      el("h1", {}, root.EIPSIAdminI18n.t("Assessment Editor")),
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
      if(error)text=root.EIPSIAdminI18n.error(text);
      message.textContent = text;
      message.classList.toggle("ae-error", error);
    }
    function state(text) {
      versionHistory.hidden=!d||(!historical&&revision===0);
      versionHistory.disabled=saving;
      historyInfo.hidden=!historical;
      historyInfo.replaceChildren();
      if(historical)historyInfo.append(el('strong',{},root.EIPSIAdminI18n.f('Version %d · Read only',historical.number)),el('span',{},root.EIPSIAdminI18n.f(' · Revision %d',historical.author_revision)),button(root.EIPSIAdminI18n.t("Back to draft"),async()=>{const epoch=++viewEpoch;try{const record=await api('load',{id:d.id});if(epoch!==viewEpoch)return;historical=null;d=A.normalizeDefinition(record.definition);revision=record.revision;dirty=false;selected=null;render();announce(root.EIPSIAdminI18n.t("Draft opened."));}catch(e){announce(e.message,true);}}));

      status.textContent =
        text || (historical ? root.EIPSIAdminI18n.t('Published version · Read only') : "") ||
        (saving ? root.EIPSIAdminI18n.t('Saving…') : dirty ? root.EIPSIAdminI18n.t('Unsaved changes') : root.EIPSIAdminI18n.t('Saved'));
      status.hidden = !d;
      preview.hidden = !d;
      save.hidden = !d;
      create.hidden = !!d;
      library.hidden = !d;
      publish.hidden = !d;
      exportJson.hidden = !d;
      exportJson.disabled = !!historical || !d || dirty || saving || revision === 0;
      exportJson.title = dirty ? root.EIPSIAdminI18n.t("Save changes before exporting.") : root.EIPSIAdminI18n.t("Export assessment JSON");
      importJson.disabled = saving;
      importQuestion.hidden = !d;
      importQuestion.disabled = !!historical || !d || saving;
      host.querySelectorAll("[data-export-question]").forEach(button => {
        button.disabled = !!historical || dirty || saving || revision === 0;
        button.title = dirty ? root.EIPSIAdminI18n.t("Save changes before exporting.") : root.EIPSIAdminI18n.t("Export question JSON");
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
        else if (message.textContent.startsWith(root.EIPSIAdminI18n.t("Variable name")))
          announce("");
      }
      state();
    }
    function allowDiscard() {
      return (
        !dirty || root.confirm(root.EIPSIAdminI18n.t("There are unsaved changes. Discard them?"))
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
      d = A.assessment(root.EIPSIAdminI18n.language);
      revision = 0;
      selected = null;
      dirty = true;
      render();
      announce(root.EIPSIAdminI18n.t("Click elements or drag them into the center to add them."));
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
        el("h2", {}, root.EIPSIAdminI18n.t("Assessment list")),
        el("p", {}, root.EIPSIAdminI18n.t("Edit, duplicate or delete your assessments.")),
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
              root.EIPSIAdminI18n.t("No assessments have been saved yet. Create your first assessment."),
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
            announce(root.EIPSIAdminI18n.t("Assessment opened."));
          };
          row.append(
            el("strong", {}, r.title),
            el("span", {}, root.EIPSIAdminI18n.f("Revision %d" ,r.revision)),
          );
          row.append(
            button(root.EIPSIAdminI18n.t("Edit"), async () => {
              try {
                await open(await api("load", { id: r.id }));
              } catch (e) {
                announce(e.message, true);
              }
            }),
          );
          row.append(
            button(root.EIPSIAdminI18n.t("Duplicate"), async () => {
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
            button(root.EIPSIAdminI18n.t("Delete"), async () => {
              if (
                !root.confirm(
                  root.EIPSIAdminI18n.f("Delete assessment “%s”? This deletes its draft and authoring partials." ,r.title),
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
          const rowMenu=el('details',{class:'ae-more-actions'}),rowToggle=el('summary',{'aria-label':root.EIPSIAdminI18n.f("Options for %s" ,r.title),title:root.EIPSIAdminI18n.t("Assessment options")},'⋮'),rowPanel=el('div',{class:'ae-more-actions-panel'});
          rowPanel.append(button(root.EIPSIAdminI18n.t("Published versions"),()=>showVersions(r.id)));
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
        announce(root.EIPSIAdminI18n.t("Assessment saved."));
      } catch (error) {
        announce(root.EIPSIAdminI18n.f("Could not save: %s",root.EIPSIAdminI18n.error(error.message)), true);
        state(root.EIPSIAdminI18n.t("Save error"));
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
      palette.replaceChildren(el("h2", {}, root.EIPSIAdminI18n.t("Elements")));
      [
        [root.EIPSIAdminI18n.t("Text"), ["text", "date"]],
        [root.EIPSIAdminI18n.t("Selection"), ["single_choice", "multiple_choice", "select"]],
        ["Escalas", ["likert", "vas", "matrix"]],
        [root.EIPSIAdminI18n.t("Content"), ["instruction", "image"]],
        [root.EIPSIAdminI18n.t("Structure"), ["group", "randomization", "page_break"]],
      ].forEach(([name, types]) => {
        palette.append(el("h3", {}, name));
        types.forEach((type) => {
          const b = button(
            "+ " + typeLabel(type),
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
          root.EIPSIAdminI18n.f("Insert here: %s" ,root.EIPSIAdminI18n.t(parent?'inside group':'assessment')),
      });
      z.append(el("span", {}, root.EIPSIAdminI18n.t("+ Drop here")));
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
          if (!payload) throw Error(root.EIPSIAdminI18n.t("Drag an element from this assessment."));
          if (payload.kind === "palette") {
            const e = A.element(payload.type, d);
            A.insert(d, e, parent, index);
            selected = e.id;
          } else if (payload.kind === "element") {
            A.move(d, payload.id, parent, index);
            selected = payload.id;
          } else throw Error(root.EIPSIAdminI18n.t("Unrecognized element"));
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
          root.EIPSIAdminI18n.f("Option text %d" ,(i+1)),
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
          button(root.EIPSIAdminI18n.t("Remove"), () => {
            if (e.options.length <= 1) {
              announce(root.EIPSIAdminI18n.t("Keep at least one option."), true);
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
            "aria-label": root.EIPSIAdminI18n.f("Analytical option value %d" ,(i+1)),
            placeholder: root.EIPSIAdminI18n.t("Use label as value"),
          });
          value.value = o.value ?? "";
          value.onchange = () => {
            o.value = value.value === "" ? null : Number(value.value);
            markCustom(e);
            changed();
          };
          row.append(el("span", { class: "ae-scale-value-label" }, root.EIPSIAdminI18n.t("Value")), value);
        }
        if (["likert", "matrix"].includes(e.type)) {
          const actions = el("div", { class: "ae-scale-option-actions" });
          Array.from(row.querySelectorAll(":scope > button")).forEach((b) =>
            actions.append(b),
          );
          row.append(actions);
          optionInput.before(
            el("span", { class: "ae-scale-label-title" }, root.EIPSIAdminI18n.t("Label")),
          );
        }
        if (e.properties.randomize_options === true && root.EIPSIAssessmentRandomization.optionTypes.includes(e.type)) {
          const label = el("label", {class:"ae-option-fixed"}), fixed = el("input", {type:"checkbox", "data-fixed-option":o.id});
          fixed.checked = o.fixed === true;
          fixed.onchange = () => {o.fixed = fixed.checked; changed(); renderCanvas();};
          label.append(fixed, el("span", {}, root.EIPSIAdminI18n.t("Fixed position"))); row.append(label);
        }
        box.append(row);
      });
      box.append(
        button(root.EIPSIAdminI18n.t("+ Add option"), () => {
          if (e.options.length >= 100) {
            announce(root.EIPSIAdminI18n.t("Maximum 100 options."), true);
            return;
          }
          markCustom(e);
          const option = A.option(root.EIPSIContent.get('authoring',root.EIPSIContent.language(d)).new_option);
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
        "aria-label": typeLabel(e.type),
      });
      card.addEventListener("click", (event) => {
        if (event.target.closest(".ae-element") === card) setSelected(e.id);
      });
      card.addEventListener("focusin", (event) => {
        if (event.target.closest(".ae-element") === card) setSelected(e.id);
      });
      const tools = el("div", { class: "ae-element-tools" }),
        handle = button(root.EIPSIAdminI18n.t("⠿ Move"), () => setSelected(e.id), "ae-drag-handle");
      handle.draggable = true;
      handle.addEventListener("dragstart", (event) =>
        startDrag(event, { kind: "element", id: e.id }),
      );
      handle.addEventListener("dragend", endDrag);
      tools.append(
        el("span", { class: "ae-kind" }, typeLabel(e.type)),
        handle,
        button("↑", () => moveRelative(e, -1)),
        button("↓", () => moveRelative(e, 1)),
        button(root.EIPSIAdminI18n.t("Duplicate"), () => {
          selected = A.duplicate(d, e.id).id;
          changed();
          render();
        }),
        button(root.EIPSIAdminI18n.t("Delete"), () => {
          A.remove(d, e.id);
          selected = null;
          changed();
          render();
        }),
      );
      if (A.questions.includes(e.type)) {
        const exportQuestion = button(root.EIPSIAdminI18n.t("Export question JSON"), async () => {
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
        card.append(el("strong", {}, root.EIPSIAdminI18n.t("──────── Page break ────────")));
        return card;
      }
      if (e.response_key) {
        const variable = el("input", {
          class: "ae-variable-name",
          "aria-label": root.EIPSIAdminI18n.t("Variable name"),
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
          A.richText.editor(e.properties, "label", root.EIPSIAdminI18n.t("Question text"), () => {
            changed();
          }),
        );
        if (e.properties.required)
          card.append(el("span", { class: "ae-required" }, root.EIPSIAdminI18n.t("* Required")));
        card.append(
          A.richText.editor(
            e.properties,
            "helper",
            root.EIPSIAdminI18n.t("Question help"),
            () => {
              changed();
            },
          ),
        );
      } else if (e.type === "instruction")
        card.append(
          A.richText.editor(e.properties, "label", root.EIPSIAdminI18n.t("Instructions"), () =>
            changed(),
          ),
        );
      else
        card.append(
          inline(
            e.properties.label,
            e.type === "group" ? root.EIPSIAdminI18n.t("Section title") : root.EIPSIAdminI18n.t("Instructions"),
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
        card.append(A.widget(e, null, edit, d));
        if (e.response_key)
          card.append(
            A.richText.editor(e.properties, "footer", root.EIPSIAdminI18n.t("Optional footer"), () =>
              changed(),
            ),
          );
        if (e.options.length) {
          const tools = el("details", { class: "ae-options-tools" });
          tools.append(
            el("summary", {}, root.EIPSIAdminI18n.t("Add, remove and reorder options")),
            optionEditor(e),
          );
          if (A.questions.includes(e.type)) {
        const exportQuestion = button(root.EIPSIAdminI18n.t("Export question JSON"), async () => {
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
              ? root.EIPSIAdminI18n.t("Drag questions into this group.")
              : root.EIPSIAdminI18n.t("Drag elements here or choose them from the palette."),
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
      else input.value = e.properties[key] ?? (root.EIPSIContent.question(e.type,root.EIPSIContent.language(d))[key] ?? "");
      if(key==="selection_min_message")input.placeholder=root.EIPSIContent.get('question_messages',root.EIPSIContent.language(d)).selection_min_message;
      if(key==="selection_max_message")input.placeholder=root.EIPSIContent.get('question_messages',root.EIPSIContent.language(d)).selection_max_message;
      const extraDefaults={unanswered_value_message:"Sin responder",length_message:'La respuesta debe tener entre [MINIMO] - [MAXIMO] caracteres.',number_range_message:'La respuesta debe estar entre [MINIMO] - [MAXIMO].',empty_image_message:'Imagen sin asignar',min_length_message:'La respuesta debe tener al menos [MINIMO] caracteres.',max_length_message:'La respuesta no debe superar [MAXIMO] caracteres.',invalid_value_message:({date:'Ingresá una fecha válida.',vas:'Elegí un valor válido en la escala.',multiple_choice:'Elegí opciones disponibles sin repetir.'}[e.type]??'Elegí una opción disponible.'),date_placeholder:({'DD/MM/YYYY':'DD/MM/AAAA','MM/DD/YYYY':'MM/DD/AAAA'}[e.properties.date_format]??'AAAA-MM-DD'),date_open_label:'Elegir fecha',date_close_label:'Cerrar',date_previous_label:'Anterior',date_next_label:'Siguiente',date_month_names:'Enero|Febrero|Marzo|Abril|Mayo|Junio|Julio|Agosto|Septiembre|Octubre|Noviembre|Diciembre',date_weekday_names:'Domingo|Lunes|Martes|Miércoles|Jueves|Viernes|Sábado'};
      if(key in extraDefaults && e.properties[key]===undefined)input.value=root.EIPSIContent.question(e.type,root.EIPSIContent.language(d))[key]??extraDefaults[key];
      if(key==="validation_message")input.placeholder=root.EIPSIAdminI18n.t("Automatic message based on validation");
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
            "aria-label": root.EIPSIAdminI18n.f("Semantic label %d" ,(index+1)),
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
          placeholder: root.EIPSIAdminI18n.t("Automatic"),
          "aria-label": root.EIPSIAdminI18n.f("Label position %d" ,(index+1)),
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
        textField.append(el("span", {}, root.EIPSIAdminI18n.t("Text")), input);
        positionField.append(
          el("span", {}, root.EIPSIAdminI18n.t("Position (scale value)")),
          position,
        );
        row.append(
          el("strong", {}, root.EIPSIAdminI18n.f("Label %d",index+1)),
          textField,
          positionField,
        );
        actions.append(
          button(root.EIPSIAdminI18n.t("Automatic"), () => {
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
          button(root.EIPSIAdminI18n.t("Remove label"), () => {
            if (e.properties.labels.length > 1) {
              e.properties.labels.splice(index, 1);
              e.properties.label_settings.splice(index, 1);
              changed();
              render();
            } else announce(root.EIPSIAdminI18n.t("Keep at least one label."), true);
          }),
        );
        row.append(actions);
        box.append(row);
      });
      box.append(
        button(root.EIPSIAdminI18n.t("+ Add label"), () => {
          if (e.properties.labels.length < 100) {
            e.properties.labels.push(root.EIPSIContent.get('authoring',root.EIPSIContent.language(d)).new_label);
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
          "aria-label": root.EIPSIAdminI18n.f("Required item %d",i+1),
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
          A.richText.editor(r, "label", root.EIPSIAdminI18n.t("Item text"), () => {
            changed();
            renderCanvas();
          }),
        );
        row.append(
          el("span", {}, root.EIPSIAdminI18n.t("Required item")),
          required,
          button(root.EIPSIAdminI18n.t("↑ Item"), () => {
            if (i) {
              [e.properties.matrix_rows[i - 1], e.properties.matrix_rows[i]] = [
                r,
                e.properties.matrix_rows[i - 1],
              ];
              changed();
              render();
            }
          }),
          button(root.EIPSIAdminI18n.t("↓ Item"), () => {
            if (i < e.properties.matrix_rows.length - 1) {
              [e.properties.matrix_rows[i + 1], e.properties.matrix_rows[i]] = [
                r,
                e.properties.matrix_rows[i + 1],
              ];
              changed();
              render();
            }
          }),
          button(root.EIPSIAdminI18n.t("Duplicate item"), () => {
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
          button(root.EIPSIAdminI18n.t("Remove item"), () => {
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
        button(root.EIPSIAdminI18n.t("+ Add item"), () => {
          if (e.properties.matrix_rows.length < 100) {
            const row = A.row(root.EIPSIContent.get('authoring',root.EIPSIContent.language(d)).row);
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
    function renderInspector() { return A.withEditorViewport(host,renderInspectorContents,d?.id); }
    function renderInspectorContents() {
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
      inspector.replaceChildren(el("h2", {}, root.EIPSIAdminI18n.t("Properties")));
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
        inspector.append(el("p", {}, root.EIPSIAdminI18n.t("Edit the title and description directly on the page.")));
        return;
      }
      const f = selected && A.find(d, selected);
      if (!f) {
        if (d)
          A.flowInspector(d, inspector, () => {
            changed();
            renderCanvas();
          });
        else inspector.append(el("p", {}, root.EIPSIAdminI18n.t("Create or open an assessment.")));
        return;
      }
      const e = f.node;
      inspector.append(el("h3", {}, typeLabel(e.type)));
      A.controlInspector(e, {
        inspector,
        contentLanguage:root.EIPSIContent.language(d),
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
        inspector.append(button(root.EIPSIAdminI18n.t("Choose image in WordPress"),()=>{
          const picker=root.wp.media({title:root.EIPSIAdminI18n.t("Choose image"),library:{type:"image"},multiple:false});
          picker.on("select",()=>{const image=picker.state().get("selection").first().toJSON();e.properties.src=image.url;if(!e.properties.alt)e.properties.alt=image.alt||"";changed();renderCanvas();renderInspector();});picker.open();
        }));
        property(e, "src", root.EIPSIAdminI18n.t("Image URL"));
        property(e, "alt", root.EIPSIAdminI18n.t("Alternative text"));
        property(e, "caption", root.EIPSIAdminI18n.t("Description"));
        property(e,"empty_image_message",root.EIPSIAdminI18n.t("Text when no image is available"));
        inspector.append(
          el(
            "p",
            {},
            root.EIPSIAdminI18n.t("Select an image from the WordPress library or upload a new one."),
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
          const select = el("select", { "aria-label": root.EIPSIAdminI18n.t("Destination group") });
          groups.forEach((g) =>
            select.append(el("option", { value: g.id }, g.properties.label)),
          );
          inspector.append(
            select,
            button(root.EIPSIAdminI18n.t("Move to group"), () =>
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
            button(root.EIPSIAdminI18n.t("Move out of group"), () => {
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
        summary = el("summary", {}, root.EIPSIAdminI18n.t("Advanced"));
      advanced.append(
        summary,
        el("p", {}, "ID: " + e.id),
        el("p", {}, root.EIPSIAdminI18n.f("Response key: %s",e.response_key||root.EIPSIAdminI18n.t("No response"))),
        el("p", {}, root.EIPSIAdminI18n.f("Type: %s",e.type)),
      );
      inspector.append(advanced);
    }
    function pageHeader(index) {
      const page = d.flow.pages[index], box = el("section", {class: "ae-page-header", "data-page-id": page.id});
      box.append(el("h2", {class: "ae-page-heading"}, root.EIPSIAdminI18n.f("Page %d" ,(index+1))));
      const title = el("div"), description = el("div");
      function titleView() {
        title.replaceChildren();
        if (page.title) title.append(el("h3", {}, page.title));
        const control = button(page.title ? root.EIPSIAdminI18n.t("Edit title") : root.EIPSIAdminI18n.t("+ Add title"), () => {
          const input = inline(page.title, root.EIPSIAdminI18n.t("Page title"), v => {page.title = v;}, true);
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
        description.append(button(page.description_html ? root.EIPSIAdminI18n.t("Edit description") : root.EIPSIAdminI18n.t("+ Add description"), () => {
          const draft = {label: "", label_html: page.description_html};
          const field = A.richText.editor(draft, "label", root.EIPSIAdminI18n.t("Page description"), () => {
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
    function renderCanvas() { return A.withEditorViewport(host,renderCanvasContents,d?.id); }
    function renderCanvasContents() {
      if(d)canvas.lang=root.EIPSIContent.language(d);
      canvas.replaceChildren();
      if (!d) return;
      const main = el("section", {
        class: "ae-assessment-container",
        tabindex: "0",
        "aria-label": root.EIPSIAdminI18n.t("Assessment settings"),
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
        root.EIPSIAdminI18n.t("Assessment title"),
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
          root.EIPSIAdminI18n.t("Select this container to configure the assessment."),
        ),
        pageHeader(0),
      );
      renderItems(d.elements, main);
      canvas.append(main);
      const c = d.flow.completion,
        card = el("section", {
          class: "ae-completion-card",
          tabindex: "0",
          "aria-label": root.EIPSIAdminI18n.t("Completion screen"),
        });
      card.append(el("h2", {}, root.EIPSIAdminI18n.t("Completion screen")));
      const content = {label: "", label_html: (c.title ? "<h2>" + A.richText.escaped(c.title) + "</h2>" : "") + c.message_html +
        (c.image_url ? '<p><img src="' + A.richText.escaped(c.image_url) + '" alt=""></p>' : "")};
      card.append(A.richText.editor(content, "label", root.EIPSIAdminI18n.t("Completion screen content"), () => {
        c.title = ""; c.image_url = "";
        c.message_html = content.label_html ?? A.richText.escaped(content.label);
        changed();
      }));
      card.addEventListener("focusin", () => {
        if (selected !== "@completion") { selected = "@completion"; renderInspector(); }
      });
      canvas.append(card);
    }
    function render() { return A.withEditorViewport(host,renderContents,d?.id); }
    function renderContents() {
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
