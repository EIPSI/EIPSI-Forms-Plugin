(function (root) {
  "use strict";
  const factors = { small: 0.8, normal: 1, large: 1.2 };
  function ratio(p, index) {
    const position = p.label_settings?.[index]?.position;
    if (position !== null && position !== undefined)
      return (position - p.min) / (p.max - p.min);
    const inset = (100 - p.label_alignment) * 0.002;
    return inset + ((1 - 2 * inset) * (index + 0.5)) / p.labels.length;
  }
  function wrappedLines(text, width, size, measure) {
    let lines = 0;
    for (const paragraph of text.split("\n")) {
      let line = "";
      lines++;
      for (const char of Array.from(paragraph)) {
        if (line && measure(line + char, size) > width) {
          lines++;
          line = char;
        } else line += char;
      }
    }
    return lines;
  }
  /** Bounds are the measured controlled rail, in coordinates relative to the label plane. */
  function layout(p, track, containerWidth) {
    const entries = p.labels.map((text, index) => ({
        index,
        text,
        ratio: ratio(p, index),
      })),
      groups = [];
    entries
      .sort((a, b) => a.ratio - b.ratio || a.index - b.index)
      .forEach((e) => {
        let g = groups[groups.length - 1];
        if (!g || Math.abs(g.ratio - e.ratio) > 1e-9) {
          g = { ratio: e.ratio, items: [] };
          groups.push(g);
        }
        g.items.push(e);
      });
    const result = [];
    groups.forEach((g, i) => {
      const anchor = track.start + g.ratio * track.width,
        left = i
          ? (anchor - (track.start + groups[i - 1].ratio * track.width)) / 2
          : anchor,
        right =
          i < groups.length - 1
            ? (track.start + groups[i + 1].ratio * track.width - anchor) / 2
            : containerWidth - anchor,
        maxWidth = Math.max(1, Math.min(220, 2 * Math.min(left, right) - 6));
      g.items.forEach((e) => {
        result[e.index] = {
          ratio: e.ratio,
          anchor,
          maxWidth,
          fontSize: 0,
          compact: maxWidth < 18,
          cluster: g.items.map((x) => x.index),
          clusterIndex: i,
        };
      });
    });
    // Solve one global feasibility problem: wrapping every box within the same
    // bounded label plane. Never move an anchor or select independent item sizes.
    const heightBudget = Math.max(56, Math.min(128, containerWidth * 0.2));
    const measure =
      arguments[3] ||
      ((text, size) =>
        Array.from(text).reduce(
          (n, c) =>
            n + (/[MW@]/.test(c) ? 0.9 : /[il .]/.test(c) ? 0.3 : 0.56) * size,
          0,
        ));
    function fits(size) {
      return result.every((g, i) => {
        if (g.compact) return true; // documented extreme ellipsis fallback
        const available = Math.max(1, g.maxWidth - 8),
          lines = wrappedLines(p.labels[i], available, size, measure);
        return (lines * size * 1.4 + 8) * g.cluster.length <= heightBudget;
      });
    }
    let low = 8,
      high = 20;
    for (let i = 0; i < 18; i++) {
      const middle = (low + high) / 2;
      if (fits(middle)) low = middle;
      else high = middle;
    }
    const common = Math.max(
      8,
      Math.min(24, low * (factors[p.label_scale] || 1)),
    );
    result.forEach((g) => (g.fontSize = common));
    return result;
  }
  root.EIPSIVASGeometry = { ratio, layout, factors };
})(typeof window !== "undefined" ? window : globalThis);
