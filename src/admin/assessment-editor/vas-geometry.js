(function (root) {
  "use strict";
  const factors = { small: 0.8, normal: 1, large: 1.2 };
  const numericScale=p=>typeof p.label_scale==='number'&&Number.isFinite(p.label_scale);
  const scale=p=>numericScale(p)?Math.max(.25,Math.min(3,p.label_scale/100)):(factors[p.label_scale]||1);
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
    const active=arguments[4];
    entries
      .filter(e=>!active||active.includes(e.index))
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
    let low = numericScale(p)?6:8,
      high = numericScale(p)?20*scale(p):20;
    for (let i = 0; i < 18; i++) {
      const middle = (low + high) / 2;
      if (fits(middle)) low = middle;
      else high = middle;
    }
    const common = Math.max(
      numericScale(p)?6:8,
      Math.min(numericScale(p)?60:24, numericScale(p)?low:low*scale(p)),
    );
    result.forEach((g) => (g.fontSize = common));
    return result;
  }
  function gutter(p,width,measure) {
    if(p.edge_spacing==='custom')return Math.min(p.edge_margin??24,Math.max(0,width/2-24));
    const size=16*scale(p),textWidth=measure||((text,s)=>Array.from(text).length*s*.56);let inset=12;
    p.labels.forEach((text,i)=>{const r=ratio(p,i),near=Math.min(r,1-r);if(near>=.5)return;const desired=Math.min(160,Math.max(64,width*.3),Math.max(...text.split("\n").map(line=>textWidth(line,size)))+8);inset=Math.max(inset,((desired+6)/2-near*width)/(1-2*near)-11);});
    return Math.max(0,Math.min(48,width*.2,inset));
  }
  function verticalLayout(p,track,height,labelWidth,maxAtTop,measure,indices) {
    const mapped=layout(p,{start:track.start+24,width:track.width},height+48,measure,indices),result=[];
    const available=Math.max(8,Math.min(220,labelWidth-8)),m=measure||((text,size)=>Array.from(text).length*size*.56);
    let low=numericScale(p)?6:8,high=numericScale(p)?20*scale(p):20;
    const boxWidth=g=>Math.max(8,(available-4*(g.cluster.length-1))/g.cluster.length);
    const fits=size=>mapped.every((g,i)=>!g||wrappedLines(p.labels[i],Math.max(1,boxWidth(g)-8),size,m)*size*1.4+8<=g.maxWidth);
    for(let i=0;i<18;i++){const middle=(low+high)/2;if(fits(middle))low=middle;else high=middle;}
    mapped.forEach((g,i)=>{const r=maxAtTop?1-g.ratio:g.ratio;result[i]={...g,anchor:track.start+r*track.width,maxWidth:boxWidth(g),fontSize:Math.max(numericScale(p)?6:8,Math.min(numericScale(p)?60:24,numericScale(p)?low:low*scale(p))),compact:boxWidth(g)<18};});return result;
  }
  root.EIPSIVASGeometry = { ratio, layout, gutter, verticalLayout, factors };
})(typeof window !== "undefined" ? window : globalThis);
