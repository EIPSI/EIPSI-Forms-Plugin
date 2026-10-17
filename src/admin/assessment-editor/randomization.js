(function (root) {
  "use strict";
  const optionTypes = ["single_choice", "multiple_choice", "likert"];
  function hash(text) {
    let h = 2166136261;
    for (let i = 0; i < text.length; i++) h = Math.imul(h ^ text.charCodeAt(i), 16777619) >>> 0;
    return h || 0x6d2b79f5;
  }
  function stream(seed) {
    let state = hash(seed);
    return () => {
      state ^= state << 13; state ^= state >>> 17; state ^= state << 5;
      return state >>> 0;
    };
  }
  function shuffle(ids, seed) {
    const next = stream(seed), result = ids.slice().sort();
    for (let i = result.length - 1; i > 0; i--) {
      const bound = i + 1, limit = Math.floor(4294967296 / bound) * bound;
      let n; do {n = next();} while (n >= limit);
      const j = n % bound;
      [result[i], result[j]] = [result[j], result[i]];
    }
    return result;
  }
  function settings(child) {
    const value = child.randomization === undefined ? {} : child.randomization;
    if (!value || typeof value !== "object" || Object.keys(value).some(key => !["weight", "always_show", "fixed_position"].includes(key))) throw Error("Configuración de hijo inválida.");
    const weight = Object.hasOwn(value, "weight") ? value.weight : 1, alwaysShow = Object.hasOwn(value, "always_show") ? value.always_show : false;
    if (typeof weight !== "number" || !Number.isFinite(weight) || weight <= 0 || typeof alwaysShow !== "boolean")
      throw Error("Peso debe ser un número positivo y Siempre mostrar debe ser Sí/No.");
    const fixedPosition = value.fixed_position ?? false;
    if (typeof fixedPosition !== "boolean") throw Error("Pregunta fija debe ser Sí/No.");
    return {weight, always_show: alwaysShow, fixed_position: fixedPosition};
  }
  function eligible(e) { return e.children.filter(child => !settings(child).always_show); }
  function weighted(ids, weights, n, seed) {
    const next = stream(seed), available = ids.slice().sort(), selected = [];
    while (selected.length < n) {
      // Rescale each draw to avoid overflow; remove the selected ID before the next draw.
      const max = Math.max(...available.map(id => weights[id]));
      const total = available.reduce((sum, id) => sum + weights[id] / max, 0);
      const target = (next() / 4294967296) * total;
      let cumulative = 0, index = available.length - 1;
      for (let i = 0; i < available.length; i++) {
        cumulative += weights[available[i]] / max;
        if (target < cumulative) { index = i; break; }
      }
      selected.push(available.splice(index, 1)[0]);
    }
    return selected;
  }
  function pinned(candidates, fixed, chosen, ordered) {
    const survivors = candidates.filter(id => fixed.includes(id) || chosen.includes(id));
    let index = 0;
    return survivors.map(id => fixed.includes(id) ? id : ordered[index++]);
  }
  function positions(candidates, visible, fixed, ordered) {
    const result = new Array(visible.length), pins = candidates.filter(id => visible.includes(id) && fixed.includes(id));
    let previous = -1;
    pins.forEach((id, index) => {
      const slot = Math.max(previous + 1, Math.min(candidates.indexOf(id), visible.length - pins.length + index));
      result[slot] = id; previous = slot;
    });
    let index = 0;
    for (let i = 0; i < result.length; i++) if (result[i] === undefined) result[i] = ordered[index++];
    return result;
  }
  function currentBlock(e, seed) {
    const candidates = e.children.map(c => c.id), p = e.properties, weights = {}, always = [], fixed = [];
    e.children.forEach(c => {const v = settings(c);weights[c.id] = v.weight;if(v.always_show)always.push(c.id);if(v.fixed_position)fixed.push(c.id);});
    if (!["all","select"].includes(p.mode) || e.children.some(c => c.type === "page_break")) throw Error("Configuración de randomización inválida.");
    const pool = p.mode === "all" ? candidates : candidates.filter(id => !always.includes(id));
    const maximum = pool.length - (p.algorithm === "selection_uniform_order" ? 1 : 0);
    if (p.mode === "select" && (!Number.isInteger(p.count) || p.count < 1 || p.count > maximum)) throw Error(maximum < 1 ? "Agregá al menos dos candidatos para seleccionar una cantidad." : "Mostrar N requiere entre 1 y " + maximum + " candidatos; Mostrar siempre se agrega aparte.");
    const count = p.mode === "all" ? pool.length : p.count;
    const draw = (ids, n, suffix, w = weights) => ids.every(id => w[id] === w[ids[0]]) ? shuffle(ids, seed + ":" + e.id + suffix).slice(0,n) : weighted(ids,w,n,seed + ":" + e.id + suffix);
    const chosen = p.mode === "all" ? pool : draw(pool,count,":selection");
    const visible = candidates.filter(id => chosen.includes(id) || (p.mode === "select" && always.includes(id)));
    const movable = visible.filter(id => !fixed.includes(id)), orderWeights = {...weights};
    if (p.mode === "select") always.forEach(id => {orderWeights[id] = 1;});
    const uniformOrder = p.mode === "select" && p.algorithm === "selection_uniform_order";
    const ordered = uniformOrder ? shuffle(movable,seed + ":" + e.id + ":order") : draw(movable,movable.length,":order",orderWeights);
    return {block_id:e.id,candidates,eligible:pool,selected:candidates.filter(id=>chosen.includes(id)),always_show:p.mode === "select" ? always : [],fixed,weights,order:positions(candidates,visible,fixed,ordered),mode:p.mode,count,order_policy:uniformOrder ? "uniform" : "weighted",fixed_policy:"absolute-clamped",selection_method:"weighted-sequential",algorithm:p.algorithm};
  }
  function hasState(record) {
    return !!(Object.keys(record?.blocks || {}).length || Object.keys(record?.option_orders || {}).length);
  }
  function resolve(d, seed, resolvedAt = Date.now()) {
    if (!/^[a-zA-Z0-9_-]{1,128}$/.test(seed)) throw Error("Seed de randomización inválida");
    const blocks = {}, optionOrders = {};
    let advanced = false;
    function visit(items) {
      for (const e of items) {
        if (e.type === "randomization" && ["weighted_positions","selection_uniform_order"].includes(e.properties.algorithm)) {
          blocks[e.id] = currentBlock(e, seed); advanced = true;
        } else if (e.type === "randomization") {
          const candidates = e.children.map(child => child.id), p = e.properties;
          const weights = {}, fixed = [];
          for (const child of e.children) {
            const value = settings(child); weights[child.id] = value.weight;
            if (value.always_show) fixed.push(child.id);
          }
          const pool = candidates.filter(id => !fixed.includes(id));
          const policy = p.fixed_policy ?? "original";
          if (e.children.some(child => child.type === "page_break")) throw Error("Randomización no admite saltos de página");
          if (!["all", "select"].includes(p.mode) || !["original", "random"].includes(p.order) || !["original", "mixed"].includes(policy)) throw Error("Configuración de randomización inválida");
          if (p.mode === "select" && (!Number.isInteger(p.count) || p.count < 1 || p.count > pool.length)) throw Error("Mostrar N requiere entre 1 y " + pool.length + " elementos aleatorios; los fijos se agregan aparte.");
          const count = p.mode === "all" ? pool.length : p.count;
          const equal = pool.every(id => weights[id] === weights[pool[0]]);
          const useWeights = p.mode === "select" && !equal;
          const chosen = useWeights ? weighted(pool, weights, count, seed + ":" + e.id + ":selection") : shuffle(pool, seed + ":" + e.id).slice(0, count);
          const selected = pool.filter(id => chosen.includes(id));
          const randomOrder = p.mode === "all" || p.order === "random";
          const ordered = randomOrder ? (useWeights ? shuffle(chosen, seed + ":" + e.id + ":order") : chosen) : selected;
          const order = policy === "mixed" && randomOrder
            ? shuffle([...chosen, ...fixed], seed + ":" + e.id + ":final")
            : pinned(candidates, fixed, chosen, ordered);
          const result = {block_id: e.id, candidates, selected, order};
          {
            Object.assign(result, {eligible: pool, fixed, weights, fixed_policy: policy, mode: p.mode, count, order_policy: p.order, selection_method: useWeights ? "weighted-sequential" : "uniform-fisher-yates"});
            advanced = true;
          }
          blocks[e.id] = result;
        }
        if (e.properties.randomize_options === true) {
          if (!optionTypes.includes(e.type)) throw Error("Esta pregunta no admite randomizar opciones.");
          const candidates = e.options.map(o => o.id), fixed = e.options.filter(o => o.fixed === true).map(o => o.id);
          const pool = candidates.filter(id => !fixed.includes(id));
          optionOrders[e.id] = {question_id: e.id, candidates, fixed, order: pinned(candidates, fixed, pool, shuffle(pool, seed + ":" + e.id + ":options")), method: "uniform-fisher-yates"};
          advanced = true;
        }
        visit(e.children);
      }
    }
    visit(d.elements);
    const record = {contract: advanced ? "LOCAL_RANDOMIZATION_V2" : "LOCAL_RANDOMIZATION_V1", seed, resolved_at: resolvedAt, blocks};
    if (Object.keys(optionOrders).length) record.option_orders = optionOrders;
    return record;
  }
  function apply(d, record) {
    const requires = items => items.some(e => e.type === "randomization" || e.properties.randomize_options === true || requires(e.children));
    if (!hasState(record) && !requires(d.elements)) return d;
    const copy = JSON.parse(JSON.stringify(d));
    function visit(items) {
      return items.map(e => {
        if (e.type === "randomization") {
          const result = record?.blocks[e.id];
          if (!result && record?.contract === "LOCAL_RANDOMIZATION_LAZY_V3") { e.type = "group"; e.properties = {label:e.properties.label}; e.children = []; return e; }
          if (!result) throw Error("Falta el resultado de randomización");
          const byId = new Map(e.children.map(child => [child.id, child]));
          e.children = result.order.map(id => {
            if (!byId.has(id)) throw Error("Identidad de randomización inválida");
            return byId.get(id);
          });
          e.type = "group"; e.properties = {label: e.properties.label};
        }
        if (e.properties.randomize_options === true) {
          const result = record?.option_orders?.[e.id];
          if (!result && record?.contract === "LOCAL_RANDOMIZATION_LAZY_V3") { delete e.properties.randomize_options; return e; }
          if (!result) throw Error("Falta el orden de opciones de la ejecución.");
          const byId = new Map(e.options.map(o => [o.id, o]));
          e.options = result.order.map(id => {
            if (!byId.has(id)) throw Error("Identidad de opción inválida.");
            return byId.get(id);
          });
          // Legacy reversal must not move fixed slots or reverse an already resolved visual order.
          if (e.type === "likert") e.properties.reversed = false;
        }
        e.children = visit(e.children);
        return e;
      });
    }
    copy.elements = visit(copy.elements);
    return copy;
  }
  root.EIPSIAssessmentRandomization = {resolve, apply, hasState, settings, eligible, optionTypes};
})(typeof window !== "undefined" ? window : globalThis);
