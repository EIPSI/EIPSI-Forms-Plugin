(function (root) {
  "use strict";
  const A = root.EIPSIAssessment,
    clone = A.copy;
  class MetadataRecorder {
    constructor(d, clock = () => Date.now(), snapshot = null) {
      this.definition = d;
      this.clock = clock;
      this.profile = d.flow.metadata_profile;
      this.focused = {};
      this.currentPage = null;
      this.lastTrajectory = {};
      this.data = snapshot
        ? clone(snapshot)
        : {
            contract: "NATIVE_PROCESS_V1",
            assessment_id: d.id,
            definition_ref:{kind:"NATIVE_SESSION_SNAPSHOT",assessment_id:d.id,contract:d.contract},
            started_at: clock(),
            completed_at: null,
            duration: 0,
            status: "active",
            recovery_used: false,
            recovery_count: 0,
            pages: {},
            items: {},
            events: [],
            dropped_events: 0,
          };
      if (this.profile !== "basic" && !snapshot)
        this.data.context = {
          device: root.matchMedia?.("(pointer:coarse)").matches
            ? "touch-capable"
            : "desktop-or-unknown",
          browser:
            (root.navigator?.userAgent || "").match(
              /(?:Firefox|Chrome|Safari|Edg)\/[\d.]+/,
            )?.[0] || "unknown",
          os: root.navigator?.platform || "unknown",
          screen: {
            width: root.screen?.width || 0,
            height: root.screen?.height || 0,
          },
          viewport: {
            width: root.innerWidth || 0,
            height: root.innerHeight || 0,
          },
          timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
          language: root.navigator?.language || "",
          input_capabilities: {
            touch: (root.navigator?.maxTouchPoints || 0) > 0,
            pointer: !!root.PointerEvent,
          },
        };
      this.items = new Map();
      A.walk(d.elements, (e) => {
        if (e.type === "matrix")
          e.properties.matrix_rows.forEach((r, i) =>
            this.register(
              {
                ...r,
                type: "likert",
                properties: {
                  ...e.properties,
                  variable_name: e.properties.variable_name + "_" + (i + 1),
                },
              },
              r.id,
            ),
          );
        else if (e.response_key) this.register(e, e.id);
      });
    }
    register(e, id) {
      this.items.set(e.response_key, { ...e, id });
    }
    state(e, value) {
      return ["text", "textarea", "email", "tel", "url"].includes(e.type)
        ? { length: typeof value === "string" ? Array.from(value).length : 0 }
        : clone(value ?? null);
    }
    event(kind, identity = null, state = null) {
      if (this.profile === "basic") return;
      const events = this.data.events;
      if (events.length < 2048)
        events.push({
          timestamp: this.clock(),
          kind,
          identity,
          state: this.profile === "detailed" ? state : null,
        });
      else this.data.dropped_events++;
    }
    present(page, items) {
      const now = this.clock();
      this.currentPage = page;
      let p = this.data.pages[page];
      if (!p)
        p = this.data.pages[page] = {
          page_id: page,
          presented_at: now,
          leave_at: null,
          duration: 0,
          visit_count: 0,
          revisit_count: 0,
          next_transitions: 0,
          previous_transitions: 0,
          resumed_here: false,
        };
      p.visit_count++;
      p.revisit_count = Math.max(0, p.visit_count - 1);
      p.current_presented_at = now;
      A.walk(items, (e) => {
        const keys =
          e.type === "matrix"
            ? e.properties.matrix_rows.map((r) => r.response_key)
            : e.response_key
            ? [e.response_key]
            : [];
        keys.forEach((key) => {
          const item = this.items.get(key);
          let v = this.data.items[key];
          if (!v)
            v = this.data.items[key] = {
              item_id: item.id,
              response_key: key,
              variable_name: item.properties.variable_name,
              presented_at: now,
              first_interaction_at: null,
              final_response_at: null,
              time_to_first_interaction: null,
              time_to_final_response: null,
              interaction_count: 0,
              change_count: 0,
              focus_count: 0,
              focus_duration: 0,
              initial_state: this.state(
                item,
                item.type === "vas" ? item.properties.initial : null,
              ),
              first_interacted_state: null,
              final_state: null,
              visit_count: 0,
              revisit_count: 0,
              trajectory: [],
            };
          v.visit_count++;
          v.revisit_count = Math.max(0, v.visit_count - 1);
        });
      });
      this.event(p.visit_count > 1 ? "revisit" : "presented", page);
    }
    leave(direction) {
      const p = this.data.pages[this.currentPage];
      if (!p) return;
      const now = this.clock();
      p.leave_at = now;
      p.duration += Math.max(0, now - p.current_presented_at);
      if (direction === "next") p.next_transitions++;
      if (direction === "previous") p.previous_transitions++;
      for (const key of Object.keys(this.focused)) this.focus(key, false);
      this.event(direction || "leave", p.page_id);
      this.currentPage = null;
    }
    focus(key, active, method = "unknown") {
      const v = this.data.items[key];
      if (!v || this.profile === "basic") return;
      const now = this.clock();
      if (active) {
        if (this.focused[key] === undefined) {
          this.focused[key] = now;
          v.focus_count++;
          this.event("focus", v.item_id);
        }
      } else if (this.focused[key] !== undefined) {
        v.focus_duration += Math.max(0, now - this.focused[key]);
        delete this.focused[key];
        this.event("blur", v.item_id);
      }
      v.input_method = method;
    }
    interact(e, value, method = "unknown", previous = undefined) {
      const item = this.items.get(e.response_key),
        v = this.data.items[e.response_key];
      if (!item || !v) return;
      const now = this.clock(),
        state = this.state(item, value);
      if (v.first_interaction_at === null) {
        v.first_interaction_at = now;
        v.time_to_first_interaction = Math.max(0,now - v.presented_at);
        v.first_interacted_state = state;
        this.event("first_interaction", item.id, state);
      }
      v.interaction_count++;
      if (JSON.stringify(previous) !== JSON.stringify(value)) v.change_count++;
      v.final_state = state;
      v.final_response_at = now;
      v.time_to_final_response = Math.max(0,now - v.presented_at);
      if (this.profile !== "basic") v.input_method = method;
      if (this.profile === "detailed") {
        const trajectory = v.trajectory,
          last = trajectory[trajectory.length - 1];
        if (last && now - last.timestamp < 250 && trajectory.length > 1) {
          last.timestamp = now;
          last.state = state;
        } else if (trajectory.length < 128)
          trajectory.push({ timestamp: now, state });
        else this.data.dropped_events++;
      }
      this.event(
        value === null || value === "" ? "clear" : "interaction",
        item.id,
        state,
      );
    }
    resume() {
      this.data.recovery_used = true;
      this.data.recovery_count++;
      this.data.status = "active";
      this.event("resume", this.currentPage);
      const p = this.data.pages[this.currentPage];
      if (p) p.resumed_here = true;
    }
    complete() {
      this.leave("next");
      const now = this.clock();
      this.data.completed_at = now;
      this.data.duration = Math.max(0,now - this.data.started_at);
      this.data.status = "completed";
      for (const v of Object.values(this.data.items))
        this.event("final_response", v.item_id, v.final_state);
    }
    snapshot() {
      return clone(this.data);
    }
  }
  A.MetadataRecorder = MetadataRecorder;
})(typeof window !== "undefined" ? window : globalThis);
