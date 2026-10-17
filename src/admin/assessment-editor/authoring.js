(function (root) {
  "use strict";
  const A = root.EIPSIAssessment;
  const allowed = new Set(
    "p br strong b em i u s del sub sup h1 h2 h3 h4 h5 h6 ul ol li blockquote span figure figcaption a img hr pre code table thead tbody tfoot tr th td".split(
      " ",
    ),
  );
  function safeURL(value, image = false) {
    try {
      if (image && !/^(https?:\/\/|\/(?!\/))/i.test(value)) return false;
      return (
        image ? ["http:", "https:"] : ["http:", "https:", "mailto:"]
      ).includes(new URL(value, document.baseURI).protocol);
    } catch (_) {
      return false;
    }
  }
  // Presentation-only CSS, shared with the server sanitizer. No URL, positioning or classes.
  function safeStyle(style) {
    const rules = {
      "font-family": /^[a-zA-Z0-9 ,"'-]{1,160}$/,
      "font-size": /^(?:[0-9]+(?:\.[0-9]+)?)(?:px|pt|em|rem|%)$/,
      "font-weight": /^(?:normal|bold|bolder|lighter|[1-9]00)$/,
      "font-style": /^(?:normal|italic|oblique)$/,
      "text-decoration": /^(?:none|underline|line-through|overline)(?: (?:underline|line-through|overline))*$/,
      "text-align": /^(?:left|center|right|justify)$/,
      "color": /^(?:#[0-9a-fA-F]{3,8}|[a-zA-Z]{1,24}|rgba?\([0-9.,% ]+\))$/,
      "background-color": /^(?:#[0-9a-fA-F]{3,8}|[a-zA-Z]{1,24}|rgba?\([0-9.,% ]+\))$/,
      "line-height": /^(?:[0-9]+(?:\.[0-9]+)?)(?:px|pt|em|rem|%)?$/,
      "margin-left": /^(?:[0-9]+(?:\.[0-9]+)?)(?:px|pt|em|rem|%)$/,
      "margin-right": /^(?:[0-9]+(?:\.[0-9]+)?)(?:px|pt|em|rem|%)$/,
      "vertical-align": /^(?:baseline|sub|super|top|middle|bottom)$/,
    };
    return String(style).split(";").flatMap(rule => {
      const colon = rule.indexOf(":");
      if (colon < 0) return [];
      const key = rule.slice(0, colon).trim().toLowerCase(), value = rule.slice(colon + 1).trim();
      return rules[key]?.test(value) ? [key + ": " + value] : [];
    }).join("; ");
  }
  function sanitize(html) {
    const template = document.createElement("template");
    template.innerHTML = String(html);
    function clean(parent) {
      Array.from(parent.childNodes).forEach((n) => {
        if (n.nodeType === 8) {
          n.remove();
          return;
        }
        if (n.nodeType !== 1) return;
        const tag = n.tagName.toLowerCase();
        if (
          [
            "script",
            "style",
            "iframe",
            "object",
            "embed",
            "svg",
            "math",
            "template",
          ].includes(tag)
        ) {
          n.remove();
          return;
        }
        clean(n);
        if (!allowed.has(tag)) {
          n.replaceWith(...n.childNodes);
          return;
        }
        const attrs =
          tag === "a"
            ? ["href", "title"]
            : tag === "img"
            ? ["src", "alt", "title", "width", "height"]
            : [];
        attrs.push("style");
        if (["td", "th"].includes(tag)) attrs.push("colspan", "rowspan");
        Array.from(n.attributes).forEach((a) => {
          if (
            !attrs.includes(a.name) ||
            (["href", "src"].includes(a.name) &&
              !safeURL(a.value, tag === "img"))
          )
            n.removeAttribute(a.name);
        });
        if (n.hasAttribute("style")) {
          const style = safeStyle(n.getAttribute("style"));
          if (style) n.setAttribute("style", style); else n.removeAttribute("style");
        }
        if (tag === "a") {
          n.target = "_blank";
          n.rel = "noopener noreferrer";
        }
        if (tag === "img" && !n.hasAttribute("src")) n.remove();
      });
    }
    clean(template.content);
    return !template.content.textContent.trim() &&
      !template.content.querySelector("img,hr")
      ? ""
      : template.innerHTML;
  }
  function plain(html) {
    const t = document.createElement("div");
    t.innerHTML = sanitize(html);
    t.querySelectorAll("br").forEach((n) => n.replaceWith("\n"));
    t.querySelectorAll("p,h2,h3,h4,li,blockquote").forEach((n) =>
      n.append("\n"),
    );
    return t.textContent.replace(/\n$/, "");
  }
  function escaped(text) {
    const t = document.createElement("div");
    t.textContent = text || "";
    return t.innerHTML.replace(/\n/g, "<br>");
  }
  function render(p, key, tag = "div") {
    const n = document.createElement(tag);
    n.className = "ae-rich-content ae-" + key;
    n.innerHTML = sanitize(p[key + "_html"] ?? escaped(p[key] || ""));
    return n;
  }
  function richBody(p, key, label, onchange) {
    const box = document.createElement("div");
    box.className = "ae-rich-editor";
    const tools = document.createElement("div");
    tools.className = "ae-rich-toolbar";
    tools.setAttribute("role", "toolbar");
    tools.setAttribute("aria-label", "Formato: " + label);
    const input = render(p, key);
    input.setAttribute("contenteditable", "true");
    input.tabIndex = 0;
    input.setAttribute("role", "textbox");
    input.setAttribute("aria-multiline", "true");
    input.setAttribute("aria-label", label);
    input.dataset.richField = key;
    input.dataset.placeholder = label;
    let selection = null;
    function remember() {
      const s = root.getSelection();
      if (s && s.rangeCount && input.contains(s.anchorNode))
        selection = s.getRangeAt(0).cloneRange();
    }
    function restore() {
      input.focus();
      if (selection) {
        const s = root.getSelection();
        s.removeAllRanges();
        s.addRange(selection);
      }
    }
    function update() {
      p[key + "_html"] = sanitize(input.innerHTML);
      if (key !== "footer") p[key] = plain(p[key + "_html"]);
      onchange();
    }
    function insertHTML(html) {
      restore();
      const clean = sanitize(html);
      const s = root.getSelection();
      if (s && s.rangeCount && input.contains(s.anchorNode)) {
        const r = s.getRangeAt(0);
        r.deleteContents();
        const t = document.createElement("template");
        t.innerHTML = clean;
        const last = t.content.lastChild;
        r.insertNode(t.content);
        if (last) {
          r.setStartAfter(last);
          r.collapse(true);
          s.removeAllRanges();
          s.addRange(r);
        }
      } else input.insertAdjacentHTML("beforeend", clean);
      update();
    }
    function button(text, fn) {
      const b = document.createElement("button");
      b.type = "button";
      b.textContent = text;
      b.onmousedown = (e) => {
        e.preventDefault();
        remember();
      };
      b.onclick = (e) => {
        e.stopPropagation();
        fn();
      };
      tools.append(b);
    }
    [
      ["Negrita", "bold"],
      ["Cursiva", "italic"],
      ["Subrayado", "underline"],
      ["Lista", "insertUnorderedList"],
      ["Lista numerada", "insertOrderedList"],
    ].forEach(([label, cmd]) =>
      button(label, () => {
        restore();
        if (document.execCommand) document.execCommand(cmd, false, null);
        update();
      }),
    );
    button("Título", () => {
      restore();
      if (document.execCommand)
        document.execCommand("formatBlock", false, "h3");
      update();
    });
    button("Párrafo", () => {
      restore();
      if (document.execCommand) document.execCommand("formatBlock", false, "p");
      update();
    });
    button("Imagen", () => {
      remember();
      if (root.wp?.media) {
        const media = root.wp.media({
          title: "Insertar imagen",
          multiple: false,
          library: { type: "image" },
        });
        media.on("select", () => {
          const item = media.state().get("selection").first().toJSON();
          if (safeURL(item.url, true)) {
            const img = document.createElement("img");
            img.src = item.url;
            img.alt = item.alt || "";
            insertHTML(img.outerHTML);
          }
        });
        document.body.classList.add("ae-rich-media-open");
        media.on("close", () =>
          document.body.classList.remove("ae-rich-media-open"),
        );
        media.open();
      } else {
        const url = root.prompt("URL de imagen (https://…)");
        if (url && safeURL(url, true)) {
          const img = document.createElement("img");
          img.src = url;
          img.alt = "";
          insertHTML(img.outerHTML);
        }
      }
    });
    function command(cmd) {
      restore();
      if (document.execCommand) document.execCommand(cmd, false, null);
      update();
    }
    button("Deshacer", () => command("undo"));
    button("Rehacer", () => command("redo"));
    button("Limpiar formato", () => command("removeFormat"));
    input.oninput = update;
    input.onkeyup = remember;
    input.onmouseup = remember;
    input.addEventListener("paste", (event) => {
      event.preventDefault();
      remember();
      const data = event.clipboardData;
      insertHTML(
        data?.getData("text/html") ||
          escaped(data?.getData("text/plain") || ""),
      );
    });
    input.addEventListener("drop", (event) => event.preventDefault());
    box.append(tools, input);
    return box;
  }
  function hasRich(html) {
    const t = document.createElement("template");
    t.innerHTML = sanitize(html ?? "");
    return Boolean(
      t.content.querySelector(
        "strong,b,em,i,u,s,del,sub,sup,h1,h2,h3,h4,h5,h6,ul,ol,li,a,img,blockquote,figure,pre,code,hr,table,[style]",
      ),
    );
  }
  function openModal(p, key, label, onapply) {
    const overlay = document.createElement("div");
    overlay.className = "ae-rich-overlay";
    const dialog = document.createElement("section");
    dialog.className = "ae-rich-modal";
    dialog.setAttribute("role", "dialog");
    dialog.setAttribute("aria-modal", "true");
    dialog.setAttribute("aria-label", "Formato enriquecido: " + label);
    dialog.tabIndex = -1;
    const title = document.createElement("h2");
    title.textContent = "Formato enriquecido — " + label;
    const draft = { ...p },
      body = richBody(draft, key, label, () => {}),
      actions = document.createElement("div");
    actions.className = "ae-rich-modal-actions";
    const savedFocus = document.activeElement,
      overflow = document.body.style.overflow;
    let closed = false,
      classicId = null,
      htmlMode = false;
    const editorTools = document.createElement("div");
    editorTools.className = "ae-rich-modes";
    const htmlButton = document.createElement("button");
    htmlButton.type = "button";
    htmlButton.textContent = "Editar HTML";
    htmlButton.setAttribute("aria-pressed", "false");
    const source = document.createElement("textarea");
    source.className = "ae-html-source";
    source.setAttribute("aria-label", "Código HTML");
    source.spellcheck = false;
    source.rows = 12;
    source.hidden = true;
    function readHTML() {
      if (htmlMode) return source.value;
      const ed = classicId && root.tinymce?.get(classicId);
      return ed ? ed.getContent() : classicId ? document.getElementById(classicId).value : body.querySelector('[contenteditable="true"]').innerHTML;
    }
    function writeHTML(value) {
      const clean = sanitize(value), ed = classicId && root.tinymce?.get(classicId);
      if (ed) ed.setContent(clean);
      else if (classicId) document.getElementById(classicId).value = clean;
      else body.querySelector('[contenteditable="true"]').innerHTML = clean;
      draft[key + "_html"] = clean;
    }
    htmlButton.onclick = () => {
      if (!htmlMode) source.value = readHTML();
      else writeHTML(source.value);
      htmlMode = !htmlMode;
      source.hidden = !htmlMode;
      body.hidden = htmlMode;
      linkButton.disabled = htmlMode;
      linkPanel.hidden = true;
      htmlButton.textContent = htmlMode ? "Volver a visual" : "Editar HTML";
      htmlButton.setAttribute("aria-pressed", String(htmlMode));
      if (htmlMode) source.focus();
      else {const ed = classicId && root.tinymce?.get(classicId); if(ed) ed.focus(); else body.querySelector('textarea,[contenteditable="true"]')?.focus();}
    };
    const linkButton = document.createElement("button");
    linkButton.type = "button";
    linkButton.textContent = "Insertar o editar enlace";
    const linkPanel = document.createElement("fieldset");
    linkPanel.className = "ae-link-panel";
    linkPanel.hidden = true;
    const legend = document.createElement("legend"); legend.textContent = "Enlace";
    function linkField(label) {
      const row = document.createElement("label"), input = document.createElement("input");
      row.textContent = label; input.type = "text"; input.setAttribute("aria-label",label); row.append(input); linkPanel.append(row); return input;
    }
    linkPanel.append(legend);
    const linkURL = linkField("URL del enlace"), linkText = linkField("Texto del enlace"), linkError = document.createElement("p");
    linkURL.placeholder = "https://…";
    linkError.setAttribute("role", "alert");
    const insertLink = document.createElement("button"), cancelLink = document.createElement("button");
    insertLink.type = cancelLink.type = "button";
    insertLink.textContent = "Aplicar enlace"; cancelLink.textContent = "Cancelar enlace";
    let bookmark = null, linkRange = null, selectedHTML = "", originalText = "";
    linkButton.onmousedown = event => event.preventDefault();
    linkButton.onclick = () => {
      const ed = classicId && root.tinymce?.get(classicId), input = body.querySelector('[contenteditable="true"]');
      let anchor = null;
      if (classicId && !ed) {linkPanel.hidden = false; linkError.textContent = "El editor visual todavía se está cargando. Volvé a intentarlo."; return;}
      if (ed) {
        const node = ed.selection.getNode?.(); anchor = node?.closest?.("a");
        if (anchor) ed.selection.select(anchor);
        bookmark = ed.selection.getBookmark(2,true);
        selectedHTML = anchor ? anchor.innerHTML : ed.selection.getContent?.({format:"html"}) || "";
      } else {
        const selection = root.getSelection(); linkRange = null;
        if (selection?.rangeCount && input?.contains(selection.anchorNode)) {
          linkRange = selection.getRangeAt(0).cloneRange();
          anchor = (selection.anchorNode.nodeType === 1 ? selection.anchorNode : selection.anchorNode.parentElement).closest("a");
          if (anchor && input.contains(anchor)) linkRange.selectNode(anchor);
          const copy = document.createElement("div"); copy.append(linkRange.cloneContents()); selectedHTML = anchor ? anchor.innerHTML : copy.innerHTML;
        } else selectedHTML = "";
      }
      originalText = plain(selectedHTML);
      linkURL.value = anchor?.getAttribute("href") || "";
      linkText.value = originalText;
      linkError.textContent = ""; linkPanel.hidden = false; linkURL.focus();
    };
    cancelLink.onclick = () => {linkPanel.hidden = true; linkButton.focus();};
    insertLink.onclick = () => {
      const url = linkURL.value.trim();
      if (!url || !safeURL(url)) {linkError.textContent = "Ingresá una URL válida (https://…, http://…, mailto:… o una ruta relativa)."; linkURL.focus(); return;}
      const anchor = document.createElement("a"); anchor.setAttribute("href",url);
      anchor.innerHTML = linkText.value === originalText && selectedHTML ? sanitize(selectedHTML) : escaped(linkText.value || url);
      const html = sanitize(anchor.outerHTML), ed = classicId && root.tinymce?.get(classicId);
      if (ed) {ed.focus(); ed.selection.moveToBookmark(bookmark); ed.insertContent(html);}
      else {
        const input = body.querySelector('[contenteditable="true"]'), template = document.createElement("template"); template.innerHTML = html;
        if (linkRange && input.contains(linkRange.commonAncestorContainer)) {linkRange.deleteContents(); linkRange.insertNode(template.content);}
        else input.append(template.content);
        input.dispatchEvent(new Event("input", {bubbles:true})); input.focus();
      }
      linkPanel.hidden = true;
    };
    linkPanel.append(linkError,insertLink,cancelLink);
    editorTools.append(htmlButton,linkButton);
    function close() {
      if (closed) return;
      closed = true;
      if (classicId && root.wp?.editor?.remove)
        root.wp.editor.remove(classicId);
      overlay.remove();
      document.body.style.overflow = overflow;
      document.body.classList.remove("ae-rich-media-open");
      if (savedFocus?.isConnected) savedFocus.focus();
    }
    const cancel = document.createElement("button");
    cancel.type = "button";
    cancel.textContent = "Cancelar";
    cancel.onclick = close;
    const apply = document.createElement("button");
    apply.type = "button";
    apply.className = "ae-primary";
    apply.textContent = "Guardar formato";
    apply.onclick = () => {
      draft[key + "_html"] = readHTML();
      p[key + "_html"] = sanitize(
        draft[key + "_html"] ?? escaped(draft[key] || ""),
      );
      if (key !== "footer") p[key] = plain(p[key + "_html"]);
      close();
      onapply();
    };
    actions.append(cancel, apply);
    dialog.append(title, editorTools, linkPanel, body, source, actions);
    overlay.append(dialog);
    document.body.append(overlay);
    document.body.style.overflow = "hidden";
    overlay.onmousedown = (e) => {
      if (e.target === overlay) close();
    };
    dialog.onkeydown = (e) => {
      if (e.key === "Escape") {
        e.preventDefault();
        close();
      }
      if (e.key === "Tab") {
        const nodes = [
          ...dialog.querySelectorAll(
            'button,input,textarea,select,[contenteditable="true"]',
          ),
        ].filter((n) => !n.disabled);
        const first = nodes[0],
          last = nodes[nodes.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };
    if (root.wp?.editor?.initialize) {
      classicId = A.id("RICH");
      const textarea = document.createElement("textarea");
      textarea.id = classicId;
      textarea.value = draft[key + "_html"] ?? escaped(draft[key] || "");
      const imageButton = document.createElement("button");
      imageButton.type = "button";
      imageButton.textContent = "Imagen · Media Library";
      imageButton.onclick = () => {
        if (!root.wp?.media) return;
        const ed = root.tinymce?.get(classicId),
          bookmark = ed?.selection.getBookmark(2, true),
          media = root.wp.media({
            title: "Insertar imagen",
            multiple: false,
            library: { type: "image" },
          });
        media.on("select", () => {
          const attachment = media.state().get("selection").first().toJSON();
          if (!safeURL(attachment.url, true)) return;
          const image = document.createElement("img");
          image.src = attachment.url;
          image.alt = attachment.alt || "";
          const instance = root.tinymce?.get(classicId);
          if (instance) {
            if (bookmark) instance.selection.moveToBookmark(bookmark);
            instance.insertContent(sanitize(image.outerHTML));
          } else textarea.value += sanitize(image.outerHTML);
        });
        document.body.classList.add("ae-rich-media-open");
        media.on("close", () =>
          document.body.classList.remove("ae-rich-media-open"),
        );
        media.open();
      };
      body.replaceChildren(imageButton, textarea);
      root.wp.editor.initialize(classicId, {
        mediaButtons: true,
        quicktags: false,
        tinymce: {
          height: 320,
          menubar: "edit insert view format",
          plugins: "charmap colorpicker directionality fullscreen hr image link lists paste textcolor wordpress wpeditimage",
          branding: false,
          toolbar1:
            "undo redo | formatselect fontselect fontsizeselect | bold italic underline strikethrough",
          toolbar2: "forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | blockquote subscript superscript | link unlink image hr charmap | removeformat fullscreen",
          font_formats: "Arial=Arial,Helvetica,sans-serif;Georgia=Georgia,serif;Times New Roman=Times New Roman,Times,serif;Verdana=Verdana,Geneva,sans-serif;Tahoma=Tahoma,Geneva,sans-serif;Trebuchet MS=Trebuchet MS,Helvetica,sans-serif;Courier New=Courier New,Courier,monospace",
          fontsize_formats: "10px 12px 14px 16px 18px 20px 24px 28px 32px 36px 48px 64px",
          formats: {bold: {inline:"strong"}, italic: {inline:"em"}, underline: {inline:"u"}, strikethrough: {inline:"s"}},
          content_style: "body{font-family:Arial,sans-serif;font-size:16px;line-height:1.5}img{max-width:100%;height:auto}strong,b{font-weight:700}em,i{font-style:italic}",
          block_formats: "Párrafo=p;Título 2=h2;Título 3=h3;Título 4=h4;Título 5=h5;Título 6=h6;Código=pre",
          setup: (ed) => {
            ed.on("init", () => {
              if (closed) ed.remove();
              else ed.focus();
            });
          },
        },
      });
    } else body.querySelector('[contenteditable="true"]').focus();
    return { dialog, close, draft };
  }
  function editor(p, key, label, onchange) {
    const box = document.createElement("div");
    box.className = "ae-content-field";
    function paint() {
      box.replaceChildren();
      if (hasRich(p[key + "_html"])) {
        const view = render(p, key);
        view.classList.add("ae-formatted-view");
        view.setAttribute("aria-label", label);
        view.setAttribute("contenteditable", "true");
        view.setAttribute("role", "textbox");
        view.setAttribute("aria-multiline", "true");
        function content() {
          const copy = view.cloneNode(true);
          // Browsers can create divs on Enter; persist their line breaks as paragraphs.
          copy.querySelectorAll("div").forEach(div => {
            const paragraph = document.createElement("p");
            paragraph.append(...div.childNodes); div.replaceWith(paragraph);
          });
          return sanitize(copy.innerHTML);
        }
        view.oninput = () => {
          p[key + "_html"] = content();
          if (key !== "footer") p[key] = plain(p[key + "_html"]);
          onchange();
        };
        view.onpaste = event => {
          event.preventDefault();
          const data = event.clipboardData;
          if (!data) return;
          const template = document.createElement("template");
          template.innerHTML = sanitize(data.getData("text/html") || escaped(data.getData("text/plain")));
          const selection = root.getSelection();
          if (selection.rangeCount && view.contains(selection.anchorNode)) {
            const range = selection.getRangeAt(0);
            range.deleteContents();
            const last = template.content.lastChild;
            range.insertNode(template.content);
            if (last) {range.setStartAfter(last); range.collapse(true); selection.removeAllRanges(); selection.addRange(range);}
          } else view.append(template.content);
          view.oninput();
        };
        view.ondrop = event => event.preventDefault();
        view.onclick = event => {if (event.target.closest("a")) event.preventDefault();};
        view.onblur = () => {
          if (view.innerHTML !== content()) view.innerHTML = content();
        };
        box.append(view);
      } else {
        const input = document.createElement("textarea");
        input.className = "ae-inline ae-simple-content";
        input.setAttribute("aria-label", label);
        input.placeholder = label;
        input.rows = 1;
        input.value =
          p[key + "_html"] !== null && p[key + "_html"] !== undefined
            ? plain(p[key + "_html"])
            : p[key] || "";
        input.oninput = () => {
          if (key !== "footer") {
            p[key] = input.value;
            p[key + "_html"] = null;
          } else p.footer_html = escaped(input.value);
          onchange();
        };
        box.append(input);
      }
      const format = document.createElement("button");
      format.type = "button";
      format.className = "ae-format-button";
      format.textContent = "✎";
      format.title = "Editar formato enriquecido";
      format.setAttribute("aria-label", "Editar formato enriquecido: " + label);
      format.onclick = (e) => {
        e.stopPropagation();
        openModal(p, key, label, () => {
          paint();
          onchange();
          box.querySelector(".ae-format-button")?.focus();
        });
      };
      box.append(format);
    }
    paint();
    return box;
  }
  const variableErrors = root.EIPSIAssessmentVariables.errors;
  function textMode(e, mode, format) {
    const p = e.properties;
    if (mode === "multiline") format = "free";
    if (format === "number") mode = "single";
    const t =
      format === "number"
        ? "number"
        : mode === "multiline"
        ? "textarea"
        : ["email", "url", "tel"].includes(format)
        ? format
        : "text";
    const keep = {};
    for (const k of [
      "label",
      "helper",
      "required",
      "label_html",
      "helper_html",
      "footer_html",
      "variable_name",
      "placeholder",
    ])
      keep[k] = p[k];
    for(const key of ["required_message","validation_message"])if(p[key]!==undefined)keep[key]=p[key];
    const next = { ...A.element(t).properties, ...keep };
    if (t === "number") {
      for (const k of ["min", "max", "step", "integer_only", "number_range_message"])
        if (p[k] !== undefined) next[k] = p[k];
    } else {
      next.display_mode = mode;
      next.content_type = format;
      for (const k of ["min_length", "max_length", "rows", "length_message"])
        if (p[k] !== undefined && (k !== "rows" || t === "textarea"))
          next[k] = p[k];
    }
    e.type = t;
    e.properties = next;
  }
  const grown = new WeakSet();
  function grow(input) {
    input.style.height = "auto";
    if (input.scrollHeight) input.style.height = input.scrollHeight + "px";
  }
  function attachGrowth(input) {
    if (grown.has(input) || input.closest(".ae-rich-modal")) return;
    grown.add(input);
    input.rows = 1;
    input.style.resize = "none";
    input.addEventListener("input", () => grow(input));
    grow(input);
    resize?.observe(input);
  }
  const resize = root.ResizeObserver ? new root.ResizeObserver(entries => entries.forEach(({target}) => grow(target))) : null;
  new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
    if (node.nodeType !== 1) return;
    if (node.matches("textarea")) attachGrowth(node);
    node.querySelectorAll("textarea").forEach(attachGrowth);
  }))).observe(document.body, {childList: true, subtree: true});
  A.autoGrow = attachGrowth;
  A.richText = { safeStyle, sanitize, plain, render, editor, escaped, hasRich, openModal };
  A.variableErrors = variableErrors;
  A.textMode = textMode;
})(window);
