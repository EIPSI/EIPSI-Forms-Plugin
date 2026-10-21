(function (root) {
  "use strict";
  const A = root.EIPSIAssessment,
    el = A.el;
  A.mountProductRuntime = function (host, initial, transport) {
    let frame = initial,
      session,
      busy = false,
      pending = null,
      closed = false;
    const checkedRandomFrames = new Map();
    let renderedProjection = null, inputEpoch = 0, queuedLogic = false, queuedMove = null;
    // The public projection describes visible content; its randomization reference also
    // changes for audit/dependency bookkeeping that must not rebuild the controls.
    const projectionKey = value => JSON.stringify(value.participant_contract && value.projection_ref
      ? [value.execution_id,value.page,value.projection_ref]
      : [value.execution_id,value.state,value.logical_exit,value.page,value.logical_entry_question,value.logical_skipped_ids,value.randomization,value.projection_ref]);
    const mountedWithSession = !!(initial.session ?? initial.partial);
    const status = el("p", { role: "status", "aria-live": "polite" }),
      body = el("div"),
      actions = el("div", { class: "ae-preview-actions" });
    const discardFooter = el("footer", {class: "ae-discard-footer"});
    host.replaceChildren(status, body, actions, discardFooter);
    body.addEventListener('invalid',event=>root.EIPSIStudyInteraction.invalid(event),true);
    body.addEventListener('input',event=>root.EIPSIStudyInteraction.input(event));
    body.addEventListener('submit',event=>{const form=event.target.closest('[data-previous-email]');if(form&&!root.EIPSIStudyInteraction.confirmEmail(form))event.preventDefault();});
    const api =
      transport ||
      async function (payload, keepalive = false) {
        const response = await root.fetch(root.eipsiNativeParticipant.url, {
          method: "POST",
          credentials: "same-origin",
          keepalive,
          body: new URLSearchParams({
            action: "eipsi_native_participant",
            execution: frame.execution_id,
            payload: JSON.stringify(payload),
          }),
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
          const error = Error(result.data?.message || "No se pudo completar la solicitud.");
          error.status = response.status;
          error.entryUrl=result.data?.entry_url;
          error.validationErrors=result.data?.validation_errors;
          throw error;
        }
        return result.data;
      };
    function restore(markResume = true) {
      A.effectiveMetadataPolicy = frame.effective_metadata_policy || root.eipsiAssessmentEditorConfig?.metadata_policy || null;
      const definition = A.copy(frame.definition);
      const dates=items=>items.forEach(item=>{if(item.type==='date')item.properties.date_format=frame.study_date_format||'YYYY-MM-DD';dates(item.children);});dates(definition.elements);
      const skipped = new Set(frame.logical_skipped_ids || []);
      const optional = items => items.forEach(item => { if (skipped.has(item.id)) { item.properties.required = false; if (item.type === "multiple_choice") item.properties.min_selected = 0; } if (item.type === "matrix") item.properties.matrix_rows.forEach(row => { if (skipped.has(row.id)) row.required = false; }); optional(item.children); });
      optional(definition.elements);
      session = new A.PreviewSession(definition, frame.randomization);
      const hidden=new Set(skipped);if(frame.logical_entry_question){const order=[];const walk=items=>items.forEach(item=>{if(item.response_key)order.push(item.id);if(item.type==='matrix')item.properties.matrix_rows.forEach(row=>order.push(row.id));walk(item.children);});walk(session.resolvedDefinition.elements);const stop=order.indexOf(frame.logical_entry_question);if(stop>=0)order.slice(0,stop).forEach(id=>hidden.add(id));}
      const visible = items => items.filter(item => !hidden.has(item.id)).map(item => { const copy = A.copy(item); copy.children = visible(copy.children); if (copy.type === "matrix") copy.properties.matrix_rows = copy.properties.matrix_rows.filter(row => !hidden.has(row.id)); return copy; });
      session.pages = session.pages.map(visible);
      session.page = frame.page || 0;
      session.metadata.data.definition_ref = {
        kind: "NATIVE_PUBLISHED_SNAPSHOT",
        version_id: frame.version_id,
        assessment_id: frame.definition.id,
        sha256: frame.definition_hash,
      };
      const current = frame.session ?? frame.partial;
      if (!current && frame.has_logic) { session.metadata = new A.MetadataRecorder(session.definition); session.metadata.data.definition_ref = {kind:"NATIVE_PUBLISHED_SNAPSHOT",version_id:frame.version_id,assessment_id:frame.definition.id,sha256:frame.definition_hash}; session.metadata.present(session.definition.flow.pages[session.page].id,session.pages[session.page]); if(frame.randomization) session.metadata.data.randomization=A.copy(frame.randomization); }
      if (current) {
        session.answers = { ...A.copy(current.answers) };
        session.touched = { ...A.copy(current.touched) };
        session.page = current.page;
        session.metadata = new A.MetadataRecorder(
          session.definition,
          () => Date.now(),
          current.metadata,
        );
        session.metadata.data.pages = { ...session.metadata.data.pages };
        session.metadata.data.items = { ...session.metadata.data.items };
        if (markResume) {
          session.metadata.present(session.definition.flow.pages[session.page].id, session.pages[session.page]);
          session.metadata.resume();
        } else { const pageId=session.definition.flow.pages[session.page].id; if((frame.has_logic || frame.participant_contract)&&!current.metadata.pages?.[pageId])session.metadata.present(pageId,session.pages[session.page]);else session.metadata.currentPage = pageId; }
      }
      if (frame.participant_contract && current && frame.state === "ACTIVE") {
        const keys = [];
        A.walk(session.pages[session.page], e => {
          if (e.type === "matrix") keys.push(...e.properties.matrix_rows.map(row => row.response_key));
          else if (e.response_key) keys.push(e.response_key);
        });
        const missing = keys.filter(key => !session.metadata.data.items[key]);
        if (missing.length) {
          // Record newly delivered items without inventing a page revisit or replacing history.
          const introduced = new A.MetadataRecorder(session.definition);
          introduced.present(session.definition.flow.pages[session.page].id, session.pages[session.page]);
          missing.forEach(key => { session.metadata.data.items[key] = A.copy(introduced.data.items[key]); });
        }
      }
      session.finished = frame.state === "COMPLETED";
    }
    function request(action) {
      return {
        action,
        command_id: A.id("NC"),
        revision: frame.revision,
        version_id: frame.version_id,
        csrf: frame.csrf,
        answers: A.copy(session.answers),
        touched: A.copy(session.touched),
        metadata: session.metadata.snapshot(),
      };
    }
    function button(label, fn) {
      const b = el("button", { type: "button", disabled: busy }, label);
      b.onclick = fn;
      return b;
    }
    const message = key => frame.definition.flow.messages?.[key] ?? root.EIPSIContent.get('flow_messages',root.EIPSIContent.language(frame.definition))[key];
    const expiredNotice=()=>message(frame.previous_partial_saved?'expired_saved':'expired');
    async function send(payload, keepalive = false, quiet = false) {
      if (busy) return false;
      busy = true; pending = payload;
      if(quiet && payload.action==='logic')queuedLogic=false;
      let okay = false, resetSession = false, transitioned = false, logicChanged = false;
      const previousLogic=renderedProjection || projectionKey(frame), sentEpoch=inputEpoch;
      const previousExecution = frame.execution_id;

      if (!quiet) { status.textContent = message("processing"); render(); }
      try {
        frame = await api(payload, keepalive);
        if (root.history?.replaceState && root.location?.href) {
          const url = new URL(root.location.href);
          url.searchParams.set("eipsi_native", frame.execution_id);
          root.history.replaceState(null, "", url);
        }
        pending = null;
        transitioned = previousExecution !== frame.execution_id;
        logicChanged=transitioned||previousLogic!==projectionKey(frame);
        const newerInput=quiet && payload.action === 'logic' && sentEpoch!==inputEpoch && !transitioned && frame.state==='ACTIVE' && !frame.logical_exit && !frame.session_reset;
        if(newerInput)logicChanged=false; // Keep newer local edits until their own logical check.
        if(quiet && !logicChanged && frame.participant_contract && frame.randomization){session.randomization=A.copy(frame.randomization);session.metadata.data.randomization=A.copy(frame.randomization);}
        if (!newerInput && (frame.has_logic || frame.participant_contract) && (logicChanged || ["open","next","back","submit","partial","discard"].includes(payload.action))) restore(false);
        resetSession = !!frame.session_reset;
        if (resetSession) {  restore(false); }
        if (!quiet || resetSession) status.textContent = frame.state === "COMPLETED"
          ? message("completed")
          : resetSession || frame.previous_partial_saved ? expiredNotice() : message("updated");
        if(!resetSession&&(transitioned||frame.logical_exit||logicChanged))status.textContent="";
        okay = true;
      } catch (e) {
        if(Array.isArray(e.validationErrors))pending=null;
        if(e.status===403&&e.entryUrl){root.location.replace(e.entryUrl);return false;}
        status.textContent = Array.isArray(e.validationErrors) ? e.validationErrors.map(item=>item.message).join(" ") : e.status === 400
          ? message("invalid")
          : message("delivery_error");
      } finally { busy = false; if (!quiet || !okay || resetSession || logicChanged) render(); if(logicChanged) body.querySelector("input,textarea,select,button")?.focus(); }
      if (transitioned && frame.session == null && frame.state === "ACTIVE") { await send(request("open"),false,true); restore(false); render(); }
      if (resetSession) {
        const notice = status.textContent;
        await send(request("open"));
        status.textContent = notice;
        render();
        return false;
      }
      if (okay && frame.has_logic && frame.state === "ACTIVE" && !frame.logical_exit && (frame.randomization?.contract === "LOCAL_RANDOMIZATION_LAZY_V3" || frame.evaluate_logic_on_open)) {
        const signature=JSON.stringify([frame.randomization?.reference,frame.randomization?.blocks,frame.randomization?.option_orders]);
        if(checkedRandomFrames.get(frame.execution_id)!==signature){checkedRandomFrames.set(frame.execution_id,signature);await send(request("logic"),false,true);}
      }
      if(okay && quiet && payload.action==='logic' && queuedLogic && !closed && frame.state==='ACTIVE' && !frame.logical_exit){queuedLogic=false;await send(request('logic'),false,true);}
      if(okay && !busy && !pending && queuedMove && !closed){const direction=queuedMove;queuedMove=null;await move(direction);}
      return okay;
    }
    async function move(direction) {
      if (busy || pending) {if(busy && pending?.action==='logic')queuedMove=direction;return;}
      const errors = direction === "back" || frame.has_logic ? [] : session.errors();
      if (errors.length) {
        status.textContent = errors.map((e) => e.message).join(" ");
        return;
      }
      if (frame.participant_contract && !frame.has_logic && direction === "submit") session.metadata.complete();
      else if(frame.has_logic || frame.participant_contract)session.metadata.leave(direction === "back" ? "previous" : "next");
      if (!frame.has_logic && !frame.participant_contract) { if (direction === "back") session.previous(); else if (!session.next()) return; }
      const okay = await send(request(direction));
      if (!okay) {
        /* Keep exact attempted command and local state: retry is idempotent. */
      }
      render();
    }
    async function recoverAutomatically() {
      if (busy || pending) return;
      const okay = await send({action: "view"});
      if (!okay && pending) return;
      
      restore(false);
      if (okay && frame.state !== "COMPLETED") await send(request("open"));
      restore(okay); render();
    }
    function render() {
      host.lang=root.EIPSIContent.language(frame.definition);
      renderedProjection=projectionKey(frame);
      body.replaceChildren();
      actions.replaceChildren();
      discardFooter.replaceChildren();
      if (frame.definition.flow.allow_discard === true && frame.can_discard !== false) {
        const discard = button(message("discard_label"), async () => {
          if (busy || pending || !await A.confirmDiscard(host,message)) return;
          const okay = await send(request("discard"));
          if (!okay) return;
           restore(false);
          const opened = await send(request("open"));
          restore(false); status.textContent = opened ? message("discarded") : status.textContent; render();
        });
        discard.className = "ae-discard-response";
        discard.disabled = busy || !!pending;
        discardFooter.append(discard);
      }
      if (!session) return;

      const d = session.definition,
        f = d.flow;
      A.applyDesign(host, f.design);
      actions.dataset.alignment = f.design?.navigation_alignment || "edges";
      if (f.show_title !== false) body.append(el("h1", {}, d.title));
      if (pending && frame.state === "COMPLETED") {
        actions.append(
          button(pending.action === "discard" ? message("discard_label") : (f.completion.button_label || f.navigation_labels.finish || root.EIPSIContent.get('flow',root.EIPSIContent.language(frame.definition)).navigation_labels.finish), async () => {
            const okay = await send(pending);
            if (okay) {
              restore();
              render();
            }
          }),
        );
        return;
      }
      if (session.finished && frame.state === "COMPLETED" && frame.email_confirmation_html) {
        const confirmation=el('div',{class:'ae-email-confirmation'});confirmation.innerHTML=frame.email_confirmation_html;body.append(confirmation);return;
      }
      if ((session.finished && frame.state === "COMPLETED") || frame.logical_exit) {
        const c = f.completion;
        body.append(
          el("h2", {}, c.title),
          A.richText.render({ label_html: c.message_html }, "label"),
        );
        if (c.image_url)
          body.append(
            el("img", {
              src: c.image_url,
              alt: "",
              class: "ae-completion-image",
            }),
          );
        
        return;
      }
      const page = f.pages[session.page];
      if (page.title) body.append(el("h2", {}, page.title));
      if (page.description_html)
        body.append(
          A.richText.render({ label_html: page.description_html }, "label"),
        );
      if (["pages", "both"].includes(f.progress))
        body.append(
          el(
            "p",
            {},
            message("progress").replaceAll("[ACTUAL]",String(session.page - (frame.logical_entry_page || 0) + 1)).replaceAll("[TOTAL]",String(session.pages.length - (frame.logical_entry_page || 0))),
          ),
        );
      if (["bar", "both"].includes(f.progress))
        body.append(
          el("progress", {
            max: session.pages.length - (frame.logical_entry_page || 0),
            value: session.page - (frame.logical_entry_page || 0) + 1,
            "aria-label": message("progress_accessible"),
          }),
        );
      A.renderParticipantElements(
        session.pages[session.page],
        body,
        session,
        "native",
      );
      if (busy || pending)
        body
          .querySelectorAll("input,textarea,select,button")
          .forEach((n) => (n.disabled = true));
      if (pending) {
        actions.append(
          button(pending.action === "back" ? (f.navigation_labels.back || root.EIPSIContent.get('flow',root.EIPSIContent.language(frame.definition)).navigation_labels.back) : pending.action === "discard" ? message("discard_label") : session.page === session.pages.length - 1 ? (f.navigation_labels.finish || root.EIPSIContent.get('flow',root.EIPSIContent.language(frame.definition)).navigation_labels.finish) : (f.navigation_labels.next || root.EIPSIContent.get('flow',root.EIPSIContent.language(frame.definition)).navigation_labels.next), async () => {
            const action = pending.action;
            const okay = await send(pending);
            if (okay) {
              if (action === "open") restore(action === "open" && mountedWithSession);
              else if (action === "view") {  restore(); }

              render();
            }
          }),
        );
        actions.lastElementChild.classList.add(pending.action === "back" ? "ae-nav-back" : "ae-nav-forward");
        return;
      }
      if (f.allow_back && session.page > (frame.logical_entry_page || 0))
        actions.append(
          Object.assign(button(f.navigation_labels.back || root.EIPSIContent.get('flow',root.EIPSIContent.language(frame.definition)).navigation_labels.back, () => move("back")), {className: "ae-nav-back"}),
        );
      const last = session.page === session.pages.length - 1;
      actions.append(
        button(
          last
            ? f.navigation_labels.finish || root.EIPSIContent.get('flow',root.EIPSIContent.language(frame.definition)).navigation_labels.finish
            : f.navigation_labels.next || root.EIPSIContent.get('flow',root.EIPSIContent.language(frame.definition)).navigation_labels.next,
          () => move(last ? "submit" : "next"),
        ),
      );
      actions.lastElementChild.classList.add("ae-nav-forward");

    }
    let logicTimer = null;
    body.addEventListener('input',()=>{inputEpoch++;});
    body.addEventListener("change", () => {
      inputEpoch++;
      if (!frame.has_logic || frame.logical_exit) return;
      if(busy){if(pending?.action==='logic')queuedLogic=true;return;}
      if(pending)return;
      root.clearTimeout(logicTimer);
      logicTimer = root.setTimeout(() => { if (!closed && !busy && !pending) send(request("logic"),false,true); }, 250);
    });
    let method = "unknown";
    body.addEventListener("pointerdown", () => {
      session.inputMethod = method = "pointer";
    });
    body.addEventListener("keydown", () => {
      session.inputMethod = method = "keyboard";
    });
    function focus(event, active) {
      const row = event.target.closest("[data-row-id]"),
        block = event.target.closest('[id^="native-"]');
      const id = row?.dataset.rowId || block?.id.slice(7);
      const item = [...session.metadata.items.values()].find(
        (e) => e.id === id,
      );
      if (item) session.metadata.focus(item.response_key, active, method);
    }
    body.addEventListener("focusin", (e) => focus(e, true));
    body.addEventListener("focusout", (e) => focus(e, false));
    async function visibility() {
      if (
        document.visibilityState !== "hidden" ||
        busy ||
        pending ||
        session.finished ||
        closed
      )
        return;
      const payload = request("flush");
      await send(payload, true, true);
    }
    async function heartbeat() {
      if (document.visibilityState === "hidden" || busy || pending || session.finished || closed) return;
      await send(request("flush"), false, true);
    }
    function visibilityChanged() {
      if (document.visibilityState === "hidden") visibility();
      else if (frame.expires_at != null && Date.now() >= frame.expires_at * 1000 && !busy && !pending) recoverAutomatically();
    }
    document.addEventListener("visibilitychange", visibilityChanged);
    const heartbeatTimer = root.setInterval(heartbeat, Math.max(1000, Math.min(30000, root.EIPSIAssessmentFlow.seconds(frame.definition.flow.save_continue.window) * 1000 / 3)));
    restore(false);
    render();
    const ready = (async () => {
      if (frame.state === "COMPLETED" || frame.logical_exit) return;
      const payload = request("open");
      const okay = await send(payload);
      if (okay) {
        restore(mountedWithSession);
        render();
      }
    })();
    return {
      ready,
      get session() {
        return session;
      },
      get frame() {
        return frame;
      },
      flush: visibility,
      heartbeat,
      destroy() {
        closed = true;
        document.removeEventListener("visibilitychange", visibilityChanged);
        root.clearInterval(heartbeatTimer);
        root.clearTimeout(logicTimer);
        host.replaceChildren();
        session.answers = {};
        session.touched = {};
      },
    };
  };
  if (root.eipsiNativeParticipant) {
    const host = document.getElementById("eipsi-native-participant");
    if (host) A.mountProductRuntime(host, root.eipsiNativeParticipant.frame);
  }
})(typeof window !== "undefined" ? window : globalThis);
