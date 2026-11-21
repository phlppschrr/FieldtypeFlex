"use strict";
(() => {
  // scripts/typescript/dom.ts
  var controlSelector = "input, select, textarea, button";
  function all(scope, selector) {
    return Array.from(scope.querySelectorAll(selector));
  }
  function one(scope, selector) {
    const element = scope.querySelector(selector);
    if (!element) throw new Error("Missing Flex element: " + selector);
    return element;
  }
  function closest(element, selector) {
    const parent = element.closest(selector);
    if (!parent) throw new Error("Missing Flex parent: " + selector);
    return parent;
  }
  function attr(element, name) {
    return element.getAttribute(name) || "";
  }
  function ready(callback) {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", callback, { once: true });
    else callback();
  }
  function value(control) {
    if (control instanceof HTMLSelectElement && control.multiple) return Array.from(control.selectedOptions, (option) => option.value);
    return control.value;
  }
  function setValue(control, value2) {
    if (control instanceof HTMLSelectElement && Array.isArray(value2)) {
      Array.from(control.options).forEach((option) => option.selected = value2.includes(option.value));
    } else control.value = Array.isArray(value2) ? value2.join(",") : value2;
  }
  function serialize(scope) {
    const controls = scope instanceof HTMLFormElement ? Array.from(scope.elements) : all(scope, controlSelector);
    return controls.flatMap((element) => {
      if (!(element instanceof HTMLInputElement || element instanceof HTMLSelectElement || element instanceof HTMLTextAreaElement)) return [];
      if (!element.name || element.matches(":disabled") || /^(file|submit|button|image|reset)$/i.test(element.type)) return [];
      if (element instanceof HTMLInputElement && /^(checkbox|radio)$/i.test(element.type) && !element.checked) return [];
      const values = element instanceof HTMLSelectElement ? Array.from(element.selectedOptions).filter((option) => !option.disabled && !(option.parentElement instanceof HTMLOptGroupElement && option.parentElement.disabled)).map((option) => option.value) : [element.value];
      return values.map((item) => [element.name, item.replace(/\r?\n/g, "\r\n")]);
    });
  }
  function bridgeTheme() {
    const theme = window.ProcessWireAdminTheme;
    return typeof theme?.upstream === "function" && theme.legacy && theme.trusted ? theme : void 0;
  }
  function nativeBridge() {
    const theme = bridgeTheme();
    return !!theme && !theme.upstream();
  }
  function needsLegacy(scope) {
    const fields = all(scope, ".Inputfield[data-bridge-legacy]");
    if (scope.matches(".Inputfield[data-bridge-legacy]")) fields.unshift(scope);
    return fields.some((field) => bridgeTheme()?.legacy.needsStack(field));
  }
  function trustedHTML(html) {
    return bridgeTheme()?.trusted.html(html) ?? html;
  }
  function setHTML(scope, html) {
    scope.innerHTML = trustedHTML(html);
  }
  function insertHTML(scope, where, html) {
    scope.insertAdjacentHTML(where, trustedHTML(html));
  }
  function parseHTML(html) {
    const template = document.createElement("template");
    template.innerHTML = trustedHTML(html.trim());
    return one(template.content, "*");
  }
  async function activateScripts(scope, response) {
    const theme = bridgeTheme();
    if (theme) {
      await theme.runScripts(scope, { legacy: needsLegacy(scope), nonce: response?.headers?.get("X-Bridge-Nonce") || "" });
      return;
    }
    all(scope, "script").forEach((inert) => {
      if (inert.type && !/^(text|application)\/javascript$/.test(inert.type) && inert.type !== "module") return;
      const script = document.createElement("script");
      Array.from(inert.attributes).forEach((attribute) => script.setAttribute(attribute.name, attribute.value));
      script.textContent = inert.textContent;
      script.async = false;
      inert.replaceWith(script);
    });
  }
  function change(control, value2) {
    control.value = value2;
    control.dispatchEvent(new Event("change", { bubbles: true }));
  }
  function delegate(name, selector, callback) {
    document.addEventListener(name, (event) => {
      if (!(event.target instanceof Element)) return;
      const element = event.target.closest(selector);
      if (element) callback(event, element);
    });
  }

  // scripts/typescript/classic.ts
  var tableTagMenus = /* @__PURE__ */ new WeakSet();
  var classic = {
    async setup(scope) {
      const theme = bridgeTheme();
      if (theme) {
        if (needsLegacy(scope)) await window.Bridge?.legacy.load();
        theme.legacy.initElement(scope);
        return;
      }
      const $ = window.jQuery;
      if (!$) return;
      if (window.Inputfields) window.Inputfields.init($(scope));
      else window.InputfieldsInit?.($(scope));
      const inputs = all(scope, ".Inputfield");
      if (scope.matches(".Inputfield")) inputs.unshift(scope);
      inputs.forEach((input) => $(input).trigger("reloaded"));
    },
    toggle(row, open) {
      if (nativeBridge() && window.Bridge?.contract === "1") {
        if (open) window.Bridge.Inputfields.open(row);
        else window.Bridge.Inputfields.close(row);
        return;
      }
      if (window.jQuery && window.Inputfields?.toggle) window.Inputfields.toggle(window.jQuery(row), open, 0);
      else {
        row.classList.toggle("InputfieldStateCollapsed", !open);
        row.dispatchEvent(new Event(open ? "opened" : "closed", { bubbles: true }));
      }
    },
    events(reloaded, toggled, changed, mediaRefresh) {
      document.addEventListener("reloaded", (event) => {
        if (event.target instanceof HTMLElement && event.target.matches(".Inputfield")) reloaded(event.target);
      });
      ["opened", "closed"].forEach((name) => document.addEventListener(name, (event) => {
        if (event.target instanceof HTMLElement && event.target.matches(".flex-row")) toggled(event.target, name === "opened");
      }));
      document.addEventListener("change", (event) => {
        if (event.target instanceof HTMLElement && event.target.matches(".Inputfield")) changed(event.target);
      });
      let bound = false;
      const bindLegacy = () => {
        const $ = window.jQuery;
        if (!$ || bound) return;
        bound = true;
        $(document).on("reloaded.flexRows", ".Inputfield", function(event) {
          if (!event.originalEvent && event.target === this) reloaded(event.target);
        });
        $(document).on("opened.flexRows closed.flexRows", ".flex-row", function(event) {
          if (!event.originalEvent && event.target === this) toggled(event.target, event.type === "opened");
        });
        $(document).on("change.flexRows", ".Inputfield", function(event) {
          if (!event.originalEvent && event.target instanceof HTMLElement && event.target.closest(".Inputfield") === this) changed(event.target);
        });
        $(document).on("flexMediaRefresh.flexRows", (_event, draft, path) => {
          if (typeof draft === "string" && typeof path === "string") mediaRefresh(draft, path);
        });
      };
      bindLegacy();
      document.addEventListener("pw-legacy-loaded", bindLegacy);
      document.addEventListener("flexMediaRefresh", (event) => {
        const detail = event.detail;
        if (detail) mediaRefresh(detail.draft, detail.path);
      });
    },
    added(row) {
      if (window.jQuery) window.jQuery(document).trigger("flexRowAdded", [window.jQuery(row)]);
      else document.dispatchEvent(new CustomEvent("flexRowAdded", { detail: { row } }));
    },
    saveReady(scope) {
      if (window.jQuery) all(scope, ".InputfieldTinyMCE").forEach((input) => window.jQuery(input).trigger("saveReady"));
    },
    tableTags(input) {
      const wrapper = input.closest(".selectize-control");
      const scroll = wrapper?.closest(".flex-table-scroll");
      const source = wrapper?.previousElementSibling;
      const widget = source?.selectize;
      if (!wrapper || !scroll || !widget || widget.settings.dropdownParent || tableTagMenus.has(widget)) return;
      const menu = widget.$dropdown[0];
      const close = () => widget.close();
      const open = () => {
        widget.settings.dropdownParent = "body";
        document.body.append(menu);
        widget.positionDropdown();
        scroll.addEventListener("scroll", close, { passive: true });
      };
      widget.on("dropdown_open", open);
      widget.on("dropdown_close", () => {
        scroll.removeEventListener("scroll", close);
        widget.settings.dropdownParent = null;
        wrapper.append(menu);
      });
      tableTagMenus.add(widget);
      if (widget.isOpen) open();
    },
    destroyEditors(scope) {
      if (window.jQuery) window.InputfieldTinyMCE?.destroyEditorsIn(window.jQuery(scope));
    },
    initInline(editor) {
      window.InputfieldTinyMCE?.init(editor, "flex.mediaMetadata");
    },
    closeDialog() {
      if (nativeBridge()) window.Bridge?.dialog.close();
      else window.jQuery?.("iframe.ui-dialog-content").dialog("close");
    },
    confirm(message2, callback) {
      if (bridgeTheme()) {
        void window.Bridge?.confirm(message2, callback);
        return;
      }
      if (window.ProcessWire?.confirm) window.ProcessWire.confirm(message2, callback);
      else if (window.confirm(message2)) callback();
    },
    preserveSelectorPaths() {
      const selector = window.InputfieldSelector;
      if (!selector || selector.flexPathsPreserved) return;
      const original = selector.changeAny;
      if (!/\.substring\(0,\s*[\w$.]+\.lastIndexOf\(["']\.["']\)\)/.test(String(original))) return;
      let active = false;
      selector.changeAny = function(item) {
        if (active) return original.call(this, item);
        const fields = window.ProcessWire?.config.FieldtypeFlexSelectorFields || [];
        const adjusted = [];
        all(closest(item[0], ".InputfieldSelector"), ".select-subfield option:checked").forEach((option) => {
          const path = option.value.split(".");
          if (path.length !== 3 || !fields.includes(path[0])) return;
          adjusted.push([option, option.value]);
          option.value += ".__flex_terminal";
        });
        active = true;
        try {
          return original.call(this, item);
        } finally {
          adjusted.forEach(([option, value2]) => option.value = value2);
          active = false;
        }
      };
      selector.flexPathsPreserved = true;
    }
  };

  // scripts/typescript/sortable.ts
  var nativeSorts = /* @__PURE__ */ new Map();
  var observing = false;
  var sortable = {
    init(list, start, stop) {
      const theme = bridgeTheme();
      if (theme) {
        if (window.Bridge?.contract !== "1" || nativeSorts.has(list)) return;
        const state2 = { disabled: false };
        nativeSorts.set(list, state2);
        void window.Bridge.sortable(list, {
          items: ".flex-row[data-flex-row]",
          handle: ".flex-row-sort",
          cancel: "input, select, textarea, a, button:not(.flex-row-sort)",
          axis: "y",
          threshold: 5,
          enabled: () => !state2.disabled && list.isConnected,
          start,
          stop
        }).then((destroy) => {
          if (!list.isConnected) {
            destroy();
            nativeSorts.delete(list);
          } else state2.destroy = destroy;
        }).catch((error) => {
          nativeSorts.delete(list);
          console.error("Flex sorting failed", error);
        });
        if (!observing) {
          observing = true;
          theme.observe(() => nativeSorts.forEach((state3, list2) => {
            if (!list2.isConnected) {
              state3.destroy?.();
              nativeSorts.delete(list2);
            }
          }));
        }
        return;
      }
      const $ = window.jQuery;
      if (!$?.fn.sortable || $(list).hasClass("ui-sortable")) return;
      list.addEventListener("mousedown", (event) => {
        if (event.target instanceof Element && event.target.closest(".flex-rows-items") === list) event.stopPropagation();
      });
      $(list).on("sortstart.flexRows sortstop.flexRows sortupdate.flexRows", (event) => {
        if (event.target === list) event.stopPropagation();
      }).sortable({
        items: "> .flex-row[data-flex-row]",
        handle: ".flex-row-sort",
        cancel: ":input:not(.flex-row-sort), a",
        axis: "y",
        distance: 5,
        tolerance: "pointer",
        placeholder: "flex-row-placeholder",
        start(_event, ui) {
          ui.placeholder.height(ui.item[0].getBoundingClientRect().height);
          start(ui.item[0]);
        },
        stop(_event, ui) {
          stop(ui.item[0]);
        }
      });
    },
    disabled(list, disabled) {
      const state2 = nativeSorts.get(list);
      if (state2) {
        state2.disabled = disabled;
        return;
      }
      const $ = window.jQuery;
      if ($?.fn.sortable && $(list).hasClass("ui-sortable")) $(list).sortable("option", "disabled", disabled);
    }
  };

  // scripts/typescript/row-title-parts.mjs
  function evaluateTitle(nodes, values, group = false) {
    const parts = [];
    let omittedGroup = false;
    for (const node of nodes) {
      const attributes = node.classes ? { classes: node.classes } : {};
      if (node.children) {
        const children = evaluateTitle(node.children, values, true);
        omittedGroup = !children.length;
        if (children.length) parts.push(...node.classes ? [{ children, ...attributes }] : children);
        continue;
      }
      let text = node.text ?? "";
      if (omittedGroup && /\s$/.test(parts[parts.length - 1]?.text ?? "")) text = text.trimStart();
      let position = {};
      for (const choice of node.choices ?? []) {
        const candidate = "text" in choice ? choice.text : Object.prototype.hasOwnProperty.call(values, choice.ref) ? values[choice.ref] : "";
        if (candidate.trim() === "") continue;
        text = candidate;
        if ("ref" in choice && choice.ref === "@index") position = { index: true };
        break;
      }
      if (text !== "") {
        parts.push({ text, ...attributes, ...position });
        omittedGroup = false;
      } else if (group && node.choices) return [];
    }
    return parts;
  }
  function trimTitleEdge(parts, end) {
    while (parts.length) {
      const index = end ? parts.length - 1 : 0;
      const part = parts[index];
      if (part.children) {
        trimTitleEdge(part.children, end);
        if (part.children.length) return;
      } else {
        part.text = end ? (part.text ?? "").trimEnd() : (part.text ?? "").trimStart();
        if (part.text !== "") return;
      }
      parts.splice(index, 1);
    }
  }
  function titleParts(title, index) {
    function indexed(parts2) {
      return parts2.map((part) => part.children ? { ...part, children: indexed(part.children) } : part.index ? { ...part, text: String(index) } : part);
    }
    if (title.override?.length) return indexed(title.override);
    const parts = title.override ?? evaluateTitle(title.nodes, { ...title.values, ...title.custom, "@index": String(index) });
    trimTitleEdge(parts, false);
    trimTitleEdge(parts, true);
    return parts.length ? parts : [{ text: "#" }, { text: String(index), index: true }];
  }

  // scripts/typescript/row-title.ts
  function titleFragment(parts) {
    const result = document.createDocumentFragment();
    for (const part of parts) {
      const content = part.children ? titleFragment(part.children) : document.createTextNode(part.text ?? "");
      if (part.classes?.length) {
        const span = document.createElement("span");
        span.classList.add(...part.classes);
        span.append(content);
        result.append(span);
      } else result.append(content);
    }
    return result;
  }
  function plainTitleValue(value2) {
    const decoder = document.createElement("textarea");
    setHTML(decoder, value2.replace(/<[^>]*>/g, "").replace(/</g, "&lt;"));
    return Array.from(decoder.value.replace(/\s+/g, " ").trim()).slice(0, 160).join("");
  }
  function sourceMatches(source, target) {
    const name = attr(target, "name").replace(/\[\]$/, "");
    if (source.kind === "media") return target.closest(".InputfieldFile")?.id === "wrap_Inputfield_" + source.base;
    return source.names.some((base) => name === base || source.kind === "date" && name.startsWith(base + "__") || target.id === "wrap_Inputfield_" + base || target.id === "wrap_Inputfield_" + base.replace(/__\d+$/, ""));
  }
  function mediaTitleValue(tree, source) {
    const wrapper = all(tree, ".InputfieldFile").find((input) => input.id === "wrap_Inputfield_" + source.base);
    if (!wrapper) return;
    const prefix = "sort_" + source.base + "_";
    const items = all(wrapper, 'input[name^="sort_"]').filter((input) => input.name.startsWith(prefix)).map((input) => {
      const suffix = input.name.slice(5);
      const item = input.closest("li") || wrapper;
      const find = (name) => all(item, "[name]").find((control) => control.name === name + "_" + suffix);
      return { find, item };
    }).filter(({ find }) => !find("delete")?.checked);
    const first = items[0];
    if (!first) return "";
    if (source.leaf === "description" || source.leaf === "tags") {
      const leaf = source.leaf;
      const metadata = all(first.item, "[data-flex-metadata-property]").find((field) => attr(field, "data-flex-metadata-property") === leaf);
      if (metadata) {
        const controls = all(metadata, 'input:not([type="hidden"]), textarea');
        const localized2 = source.language ? controls.find((control) => control.name.endsWith("__" + source.language)) : void 0;
        return localized2?.value.trim() ? localized2.value : controls[0]?.value;
      }
      const localized = leaf === "description" && source.language ? first.find("description" + source.language) : void 0;
      return localized?.value.trim() ? localized.value : first.find(leaf)?.value;
    }
    const basename = (item) => {
      const original = item.querySelector(".gridImage__overflow img[data-original]");
      const link = item.querySelector("a.InputfieldFileName, a.InputfieldFileLink");
      const image = item.querySelector(".InputfieldImageEdit__image[src]");
      const url = original?.getAttribute("data-original") || link?.href || image?.src;
      if (!url) return "";
      try {
        return decodeURIComponent(new URL(url, document.baseURI).pathname.split("/").pop() || "");
      } catch {
        return "";
      }
    };
    const names = (source.leaf === "basename" ? [first] : items).map(({ item }) => basename(item)).filter(Boolean);
    return names.length ? names.join(", ") : void 0;
  }
  function titleSourceValue(tree, source) {
    if (source.kind === "media") {
      const value2 = mediaTitleValue(tree, source);
      return value2 === void 0 ? value2 : plainTitleValue(value2);
    }
    let current;
    for (const name of source.names) {
      const controls = all(tree, `[name="${name}"], [name="${name}[]"]`);
      if (!controls.length) continue;
      const control = controls[0];
      const label = (key) => source.options && Object.prototype.hasOwnProperty.call(source.options, key) ? source.options[key] : key;
      if (control.matches(".InputfieldTinyMCEInline")) {
        current = control.innerHTML;
      } else if (control instanceof HTMLSelectElement) {
        current = Array.from(control.selectedOptions).filter((option) => source.kind !== "page" || Boolean(+option.value)).map((option) => source.options ? label(option.value) : option.textContent ?? "").join(", ");
      } else if (control instanceof HTMLInputElement && control.matches('[type="checkbox"], [type="radio"]')) {
        current = controls.filter((option) => option instanceof HTMLInputElement && option.checked).map((option) => source.kind === "page" ? option.labels?.[0]?.textContent?.trim() ?? "" : label(option.value)).join(", ");
        if (!current && source.unchecked !== void 0) current = label(source.unchecked);
      } else if (control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement) {
        if (source.kind === "page") {
          if (!control.value || control.value === "0") current = "";
          else {
            const widget = control.closest(".Inputfield");
            const selected = widget && all(widget, "li:not(.itemTemplate, .itemDeleted, .delete) > .itemLabel");
            if (selected?.length) current = selected.map((item) => item.textContent ?? "").join(", ");
            else current = widget?.querySelector(".PageListSelectName")?.textContent ?? (control.id ? tree.ownerDocument.getElementById(control.id + "_input")?.value : void 0);
          }
        } else {
          current = label(control.value);
          if (control.matches(".InputfieldDatetimeValue")) {
            const dates = ["y", "m", "d"].map((part) => tree.querySelector(`[name="${name}__${part}"]`)?.value ?? "");
            current = dates.every((value2) => /^\d+$/.test(value2)) ? dates.map((value2, part) => value2.padStart(part ? 2 : 4, "0")).join("-") : "";
          } else if (source.kind === "date") {
            const time = tree.querySelector(`[name="${name}__time"]`);
            if (time?.value) current += " " + time.value;
          }
        }
      }
      if (current && current.trim()) break;
    }
    return current === void 0 ? current : plainTitleValue(current);
  }

  // scripts/typescript/FieldtypeFlex.ts
  var state = /* @__PURE__ */ new WeakMap();
  var disabledBeforeRemoval = /* @__PURE__ */ new WeakMap();
  var busyTrees = /* @__PURE__ */ new WeakSet();
  var overlayMenus = ".flex-table-more, .flex-collection-menu";
  var rowMenus = `${overlayMenus}, .flex-row-clipboard`;
  var selection = /* @__PURE__ */ new WeakMap();
  var columnSort = ":scope > .flex-table-scroll > .flex-table-grid > thead .flex-table-column-sort";
  var tableEditors = /* @__PURE__ */ new WeakMap();
  var initializedDetailEditors = /* @__PURE__ */ new WeakSet();
  function rowState(row) {
    let current = state.get(row);
    if (!current) {
      current = {};
      state.set(row, current);
    }
    return current;
  }
  function message(key, fallback) {
    return window.ProcessWire?.config.FieldtypeFlex?.[key] || fallback;
  }
  function errorMessage(error) {
    return error instanceof Error ? error.message : String(error);
  }
  function rowList(set) {
    return one(set, ".flex-rows-items");
  }
  function rows(set) {
    return all(rowList(set), ":scope > .flex-row[data-flex-row]");
  }
  function rowBodies(row) {
    return all(row, ":scope > .flex-row-content, :scope > .flex-table-cell");
  }
  function activeRows(set) {
    return rows(set).filter((row) => !row.matches(".flex-row-remove-pending"));
  }
  function removalState(row, removed) {
    closeRowEditors(row);
    const bodies = rowBodies(row);
    const transport = row.querySelector(":scope > .flex-table-actions .flex-row-transport") || row;
    row.classList.toggle("flex-row-remove-pending", removed);
    one(row, ":scope > .flex-row-header").classList.toggle("ui-state-error", removed);
    one(row, ":scope > .flex-row-remove-value, :scope > .flex-table-actions .flex-row-remove-value").value = removed ? "1" : "0";
    all(transport, ":scope > .flex-row-remove-mirror").forEach((input) => input.remove());
    if (removed) {
      bodies.flatMap(serialize).forEach(([name, value2]) => {
        const mirror = document.createElement("input");
        mirror.type = "hidden";
        mirror.className = "flex-row-remove-mirror";
        mirror.name = name;
        mirror.value = value2;
        transport.append(mirror);
      });
    }
    bodies.flatMap((body) => all(body, controlSelector)).forEach((input) => {
      if (removed) {
        if (!disabledBeforeRemoval.has(input)) disabledBeforeRemoval.set(input, input.disabled);
        input.disabled = true;
      } else if (disabledBeforeRemoval.has(input)) {
        input.disabled = disabledBeforeRemoval.get(input);
        disabledBeforeRemoval.delete(input);
      }
    });
    const button = one(row, ':scope > .flex-row-header [data-flex-row-action="remove"]');
    const label = attr(button, removed ? "data-flex-undo-label" : "data-flex-remove-label");
    button.setAttribute("aria-pressed", String(removed));
    button.setAttribute("aria-label", label);
    button.title = label;
  }
  function rowTitleData(row) {
    var _a;
    const data = attr(row, "data-flex-title");
    if (data) return (_a = rowState(row)).title ?? (_a.title = JSON.parse(data));
  }
  function renderTitle(row, title, index) {
    row.querySelector(":scope > .flex-row-header .flex-row-title")?.replaceChildren(titleFragment(titleParts(title, index)));
  }
  function syncTitleSources(row, target, afterWidgetUpdate = false) {
    const title = rowTitleData(row);
    const tree = row.closest(".InputfieldFlex");
    if (!title || !tree) return;
    let changed = false;
    for (const [reference, source] of Object.entries(title.sources)) {
      if (target && !sourceMatches(source, target)) continue;
      if (target && source.kind === "page" && !afterWidgetUpdate) {
        queueMicrotask(() => syncTitleSources(row, target, true));
        continue;
      }
      const value2 = titleSourceValue(tree, source);
      if (value2 === void 0 || value2 === title.values[reference]) continue;
      title.values[reference] = value2;
      changed = true;
    }
    if (changed) renderTitle(row, title, rows(closest(row, ".flex-rows")).indexOf(row) + 1);
  }
  function updateLabels(set) {
    rows(set).forEach((row, index) => {
      const title = rowTitleData(row);
      if (title) renderTitle(row, title, index + 1);
    });
  }
  function treeBusy(scope) {
    return busyTrees.has(closest(scope, ".InputfieldFlex"));
  }
  function setTreeBusy(scope, busy) {
    const tree = closest(scope, ".InputfieldFlex");
    if (busy) busyTrees.add(tree);
    else busyTrees.delete(tree);
    tree.setAttribute("aria-busy", String(busy));
    all(tree, ".flex-rows").forEach(updateActions);
    const form = closest(tree, "form");
    if (!busy && !form.querySelector('.InputfieldFlex[aria-busy="true"], .flex-row[data-flex-load-failed="1"]')) all(form, ":scope > .flex-form-request-error").forEach((error) => error.remove());
  }
  function updateActions(set) {
    const count = activeRows(set).length, min = Number(attr(set, "data-flex-min")) || 0, max = Number(attr(set, "data-flex-max")) || 0, busy = treeBusy(set);
    const atLimit = max > 0 && count >= max;
    const selecting = selection.has(set);
    const menu = set.querySelector(":scope > .flex-rows-footer > .flex-collection-menu");
    if (menu) {
      one(menu, '[data-flex-selection-action="start"]').disabled = busy || selecting || count === 0;
      one(menu, '[data-flex-row-action="paste-end"]').disabled = busy || selecting || atLimit;
    }
    all(set, ":scope > .flex-rows-footer > :is(.flex-row-add, .flex-row-type)").forEach((control) => control.disabled = busy || selecting || atLimit);
    all(set, columnSort).forEach((button) => {
      button.hidden = false;
      button.disabled = busy || selecting || count < 2;
    });
    rows(set).forEach((row) => {
      const header = one(row, ":scope > .flex-row-header");
      const toggle = header.querySelector(".flex-row-toggle");
      const removed = row.matches(".flex-row-remove-pending");
      if (toggle) toggle.disabled = busy;
      one(header, '[data-flex-row-action="duplicate"]').disabled = busy || selecting || atLimit;
      all(header, '[data-flex-row-action="clipboard-copy"], [data-flex-row-action^="paste-"]').forEach((button) => {
        button.disabled = busy || selecting || removed || attr(button, "data-flex-row-action") !== "clipboard-copy" && atLimit;
      });
      one(header, '[data-flex-row-action="remove"]').disabled = busy || selecting || !removed && count <= min;
      const handle = row.querySelector(":scope > .flex-row-header .flex-row-sort, :scope > .flex-table-sort > .flex-row-sort");
      if (handle) handle.disabled = busy || selecting || removed || count < 2;
    });
    sortable.disabled(rowList(set), busy || selecting || count < 2);
    updateSelection(set);
  }
  function order(set) {
    clearColumnSort(set);
    change(one(set, ":scope > .flex-rows-order"), rows(set).map((row) => attr(row, "data-flex-row")).join(","));
    closest(set, ".InputfieldFlex").classList.add("InputfieldStateChanged");
    updateLabels(set);
    updateActions(set);
  }
  function csrf() {
    return document.querySelector("#ProcessPageEdit input._post_token");
  }
  function teardown(row) {
    const table = row.closest(".flex-table");
    if (table && row.tagName === "TR") closeTableEditor(table);
    closeRowEditors(row);
    if (!window.tinymce) return;
    all(row, "textarea.InputfieldTinyMCEEditor").forEach((input) => {
      if (input.closest(".flex-table-editor[hidden]")) return;
      const editor = window.tinymce.get(input.id);
      if (editor) {
        editor.save();
        editor.remove();
      }
      input.classList.remove("InputfieldTinyMCELoaded", "InputfieldTinyMCELazy");
    });
  }
  function syncToggle(row) {
    row.querySelector(":scope > .flex-row-header .flex-row-toggle")?.setAttribute("aria-expanded", String(!row.matches(".InputfieldStateCollapsed")));
  }
  function openRow(row, open) {
    if (row.tagName === "TR") return;
    classic.toggle(row, open);
    syncToggle(row);
  }
  function loadRow(row) {
    if (attr(row, "data-flex-loaded") !== "0") return Promise.resolve(true);
    if (rowState(row).load) return rowState(row).load;
    const set = closest(row, ".flex-rows"), body = one(row, ":scope > .flex-row-content");
    if (treeBusy(set)) return Promise.resolve(false);
    const operation = (async () => {
      setTreeBusy(set, true);
      one(body, ".flex-row-load-status").textContent = message("loading", "Loading\u2026");
      one(body, ".flex-row-retry").hidden = true;
      try {
        const data = new URLSearchParams({ flex_action: "row-render", flex_set: attr(set, "data-flex-set"), flex_source: attr(row, "data-flex-row"), flex_complete: attr(set, "data-flex-draft") });
        const token = csrf();
        if (token) data.set(token.name, token.value);
        const response = await fetch(attr(set, "data-flex-page-url"), { method: "POST", credentials: "same-origin", headers: requestHeaders(set), body: data });
        const result = await response.json();
        if (!response.ok || result.error) throw new Error(result.message || message("unableToLoad", "Unable to load item"));
        if (!row.isConnected) return false;
        const fresh = parseHTML(result.html), content = fresh.querySelector(":scope > .flex-row-content");
        if (attr(fresh, "data-flex-loaded") !== "1" || !content) throw new Error(message("unableToLoad", "Unable to load item"));
        body.replaceChildren(...content.childNodes);
        await activateScripts(body, response);
        row.setAttribute("data-flex-loaded", "1");
        row.removeAttribute("data-flex-load-failed");
        initRows(row);
        await classic.setup(row);
        if (row.matches(".flex-row-remove-pending")) removalState(row, true);
        return true;
      } catch (error) {
        row.setAttribute("data-flex-load-failed", "1");
        one(body, ".flex-row-load-status").textContent = error instanceof SyntaxError ? message("unableToLoad", "Unable to load item") : errorMessage(error);
        one(body, ".flex-row-retry").hidden = false;
        return false;
      } finally {
        delete rowState(row).load;
        setTreeBusy(set, false);
      }
    })();
    rowState(row).load = operation;
    return operation;
  }
  function requestHeaders(set) {
    return { "X-Requested-With": "XMLHttpRequest", "X-FIELDNAME": attr(set, "data-flex-field"), "X-FLEX-DRAFT": attr(set, "data-flex-draft") };
  }
  function moved(set, row) {
    const status = one(set, ":scope > .flex-rows-status");
    status.classList.add("uk-hidden-visually");
    status.textContent = message("itemMoved", "Item moved to position {position} of {count}").replace("{position}", String(rows(set).indexOf(row) + 1)).replace("{count}", String(rows(set).length));
  }
  function updateImagePreview(button, editor) {
    const images = all(editor, ".InputfieldImageList > li.gridImage, .InputfieldImageEditSingle").filter((item) => !item.querySelector(".gridImage__deletebox:checked"));
    const thumbnails = images.flatMap((item) => {
      const source = item.querySelector('img[src]:not([src=""])');
      if (!source) return [];
      const thumb = document.createElement("img");
      thumb.src = source.src;
      thumb.alt = "";
      return [thumb];
    });
    button.replaceChildren(...thumbnails);
  }
  function richTextPreview(editor) {
    const field = editor.querySelector(".InputfieldTinyMCE");
    const inputs = field ? all(field, ".InputfieldTinyMCEEditor") : [];
    const source = inputs.find((input) => input.closest(".LanguageSupportCurrent")) ?? inputs[0];
    const html = source instanceof HTMLTextAreaElement ? source.value : source?.innerHTML || "";
    const text = document.createElement("textarea");
    setHTML(text, html.replace(/<[^>]*>/g, " ").replace(/</g, "&lt;").replace(/>/g, "&gt;"));
    return text.value.replace(/\s+/g, " ").trim();
  }
  function updateTablePreview(button, editor) {
    const kind = attr(button, "data-flex-preview");
    if (kind === "image") {
      updateImagePreview(button, editor);
    } else {
      const text = one(button, "span");
      if (kind === "page") {
        const id = editor.querySelector(".InputfieldPageListSelectData")?.value;
        const label = editor.querySelector(".PageListSelectName");
        if (!Number(id)) text.textContent = "";
        else if (label?.textContent?.trim()) text.textContent = label.textContent.trim();
      } else if (kind === "richtext") {
        text.textContent = richTextPreview(editor);
      } else {
        const labels = kind === "file" ? all(editor, ".InputfieldFileList > li:not(:has(.InputfieldFileDelete input:checked)) .InputfieldFileName") : all(editor, ".InputfieldPage > .InputfieldContent ol > li:not(.itemTemplate) > .itemLabel");
        text.textContent = labels.length ? (labels[0].textContent || "").trim() : "";
        if (labels.length > 1) text.textContent += " +" + (labels.length - 1);
      }
      button.title = text.textContent || "";
    }
    button.classList.toggle("ui-state-error", !!editor.querySelector(".InputfieldStateError, .InputfieldError"));
  }
  function closeTableEditor(set, focus = false) {
    const active = tableEditors.get(set);
    if (!active) return;
    const { button, editor, detail } = active;
    const richText = button.matches('[data-flex-preview="richtext"]');
    const iframes = richText ? all(editor, ".InputfieldTinyMCENormal") : [];
    if (iframes.length) {
      iframes.forEach((input) => {
        const tiny = window.tinymce?.get(input.id);
        if (tiny?.initialized) tiny.save();
      });
    } else if (richText) classic.saveReady(editor);
    updateTablePreview(button, editor);
    editor.hidden = true;
    if (iframes.length) {
      classic.destroyEditors(editor);
      iframes.forEach((input) => {
        input.classList.remove("InputfieldTinyMCELoaded");
        input.classList.add("InputfieldTinyMCELazy");
      });
    }
    one(editor, ".flex-table-editor-close").hidden = true;
    button.after(editor);
    button.setAttribute("aria-expanded", "false");
    detail.remove();
    tableEditors.delete(set);
    if (focus) button.focus({ preventScroll: true });
  }
  function closeRowEditors(row) {
    const set = row.closest(".flex-table");
    if (set && tableEditors.get(set)?.button.closest(".flex-row") === row) closeTableEditor(set);
    all(row, ".flex-table").forEach((set2) => closeTableEditor(set2));
  }
  function openTableEditor(button, focus = true) {
    const row = button.closest(".flex-row");
    if (!(row instanceof HTMLTableRowElement)) return;
    const set = closest(button, ".flex-table");
    if (treeBusy(set) || row.matches(".flex-row-remove-pending")) return;
    if (tableEditors.get(set)?.button === button) {
      closeTableEditor(set, focus);
      return;
    }
    closeTableEditor(set);
    const editor = button.nextElementSibling;
    if (!(editor instanceof HTMLElement) || !editor.matches(".flex-table-editor")) return;
    const detail = document.createElement("tr");
    detail.className = "flex-table-detail";
    const cell = detail.insertCell();
    cell.colSpan = row.cells.length;
    row.after(detail);
    cell.append(editor);
    editor.hidden = false;
    const close = one(editor, ".flex-table-editor-close");
    close.hidden = false;
    button.setAttribute("aria-expanded", "true");
    tableEditors.set(set, { button, editor, detail });
    if (focus) {
      editor.tabIndex = -1;
      editor.focus();
    }
    if (button.matches('[data-flex-preview="richtext"]')) {
      all(editor, ".InputfieldTinyMCEPlaceholder").forEach((placeholder) => placeholder.remove());
      void classic.setup(editor);
    } else if (!initializedDetailEditors.has(editor)) {
      initializedDetailEditors.add(editor);
      void classic.setup(editor);
    }
  }
  function initRows(context) {
    const sets = all(context, ".flex-rows");
    if (context instanceof HTMLElement && context.matches(".flex-rows")) sets.unshift(context);
    sets.forEach((set) => {
      const menu = set.querySelector(":scope > .flex-rows-footer > .flex-collection-menu");
      if (menu) menu.hidden = false;
      let errorPreview;
      rows(set).forEach((row) => {
        all(row, ":scope > .flex-table-cell > .flex-table-preview[hidden]").forEach((button) => {
          const editor = one(button.parentElement, ":scope > .flex-table-editor");
          updateTablePreview(button, editor);
          editor.hidden = true;
          if (button.matches('[data-flex-preview="richtext"]')) {
            all(editor, ".InputfieldTinyMCENormal").forEach((input) => input.classList.add("InputfieldTinyMCELazy"));
          }
          button.hidden = false;
          if (!row.matches(".flex-row-remove-pending") && button.matches(".ui-state-error")) errorPreview ?? (errorPreview = button);
        });
        if (row.matches(".flex-row-remove-pending") && !rowState(row).removalReady) {
          removalState(row, true);
          rowState(row).removalReady = true;
        }
        if (!row.matches(".flex-row-remove-pending") && row.querySelector(".InputfieldStateError")) openRow(row, true);
        syncToggle(row);
      });
      if (errorPreview && !tableEditors.has(set)) openTableEditor(errorPreview, false);
      if (!set.matches(".flex-table-auto")) {
        sortable.init(rowList(set), teardown, async (row) => {
          order(set);
          await classic.setup(row);
          moved(set, row);
        });
      }
      updateLabels(set);
      updateActions(set);
    });
  }
  function selectionControl(row) {
    return row.querySelector(":scope > .flex-row-header .flex-row-select, :scope > .flex-table-sort > .flex-row-select");
  }
  function setSelectionMode(set, active) {
    set.classList.toggle("flex-selection-active", active);
    const footer = one(set, ":scope > .flex-rows-footer");
    const menu = one(footer, ":scope > .flex-collection-menu");
    menu.open = false;
    all(footer, '[data-flex-selection-action], [data-flex-row-action="paste-end"], .flex-selection-count').forEach((control) => {
      control.hidden = control.matches('[data-flex-selection-action="start"], [data-flex-row-action="paste-end"]') ? active : !active;
    });
    for (const row of rows(set)) {
      const control = selectionControl(row);
      if (control) {
        control.hidden = !active;
        control.checked = false;
      }
      all(row, ":scope > .flex-row-header .flex-row-toggle, :scope > .flex-table-cell > *").forEach((editor) => editor.toggleAttribute("inert", active));
    }
  }
  function updateSelection(set) {
    const current = selection.get(set);
    if (!current) return;
    const active = activeRows(set);
    const chosen = active.filter((row) => current.rows.has(row));
    const busy = treeBusy(set);
    for (const row of rows(set)) {
      const control = selectionControl(row);
      if (!control) continue;
      const removed = row.matches(".flex-row-remove-pending");
      control.checked = current.rows.has(row) && !removed;
      control.disabled = busy || removed;
    }
    const bar = one(set, ":scope > .flex-rows-footer");
    const counter = one(bar, ".flex-selection-count");
    counter.textContent = attr(counter, "data-flex-count").replace("{count}", String(chosen.length));
    one(bar, '[data-flex-selection-action="copy"]').disabled = busy || !chosen.length;
    one(bar, '[data-flex-selection-action="hide"]').disabled = busy || !chosen.some((row) => !row.matches(".flex-row-hidden"));
    one(bar, '[data-flex-selection-action="remove"]').disabled = busy || !chosen.length || active.length - chosen.length < Number(attr(set, "data-flex-min"));
    one(bar, '[data-flex-selection-action="done"]').disabled = busy;
  }
  function finishSelection(set) {
    const current = selection.get(set);
    if (!current) return;
    selection.delete(set);
    setSelectionMode(set, false);
    current.expanded.forEach((row) => {
      if (row.isConnected && !row.matches(".flex-row-remove-pending")) openRow(row, true);
    });
    updateActions(set);
  }
  function selectRow(event) {
    if (!(event.target instanceof Element)) return;
    const target = event.target;
    const header = target.closest(".flex-row-header");
    const row = target.closest(".flex-row");
    if (!row) return;
    const set = row.closest(".flex-rows");
    const current = set && selection.get(set);
    if (!set || !current || row.tagName !== "TR" && header?.parentElement !== row) return;
    const control = selectionControl(row);
    event.stopImmediatePropagation();
    if (target !== control || control.disabled) event.preventDefault();
    if (!control || control.disabled) return;
    const active = activeRows(set);
    if (event.shiftKey && current.anchor && active.includes(current.anchor)) {
      const from = active.indexOf(current.anchor), to = active.indexOf(row);
      active.slice(Math.min(from, to), Math.max(from, to) + 1).forEach((item) => current.rows.add(item));
    } else {
      if (!current.rows.delete(row)) current.rows.add(row);
      current.anchor = row;
    }
    control.focus({ preventScroll: true });
    updateSelection(set);
  }
  document.addEventListener("click", selectRow, true);
  delegate("click", "[data-flex-selection-action]", (event, control) => {
    event.preventDefault();
    event.stopPropagation();
    const set = control.closest(".flex-rows");
    if (!set || treeBusy(set) || control.disabled) return;
    const action = attr(control, "data-flex-selection-action");
    const footer = one(set, ":scope > .flex-rows-footer");
    const menu = one(footer, ":scope > .flex-collection-menu");
    if (action === "start") {
      if (!activeRows(set).length) return;
      all(closest(set, ".InputfieldFlex"), ".flex-selection-active").forEach(finishSelection);
      window.tinymce?.triggerSave();
      classic.saveReady(set);
      closeTableEditor(set);
      const expanded = activeRows(set).filter((row) => row.tagName !== "TR" && !row.matches(".InputfieldStateCollapsed"));
      expanded.forEach((row) => {
        closeRowEditors(row);
        openRow(row, false);
      });
      selection.set(set, { rows: /* @__PURE__ */ new Set(), expanded });
      setSelectionMode(set, true);
      updateActions(set);
      selectionControl(activeRows(set)[0])?.focus();
      return;
    }
    const current = selection.get(set);
    if (!current) return;
    if (action === "done") {
      finishSelection(set);
      one(menu, ":scope > summary").focus();
      return;
    }
    menu.open = false;
    one(menu, ":scope > summary").focus();
    const chosen = activeRows(set).filter((row) => current.rows.has(row));
    if (action === "copy" && chosen.length) {
      void writeClipboard(set, chosen);
    } else if (action === "hide") {
      chosen.forEach((row) => setVisibility(row, true));
      closest(set, ".InputfieldFlex").classList.add("InputfieldStateChanged");
      updateActions(set);
    } else if (action === "remove" && activeRows(set).length - chosen.length >= Number(attr(set, "data-flex-min"))) {
      window.tinymce?.triggerSave();
      chosen.forEach((row) => {
        removalState(row, true);
        rowState(row).removalReady = true;
      });
      current.rows.clear();
      current.anchor = void 0;
      order(set);
    }
  });
  function formError(form, text, pending) {
    let error = form.querySelector(":scope > .flex-form-limit-error");
    if (!error) {
      error = document.createElement("p");
      error.className = "flex-form-limit-error notice-error";
      error.setAttribute("role", "alert");
      error.tabIndex = -1;
      form.prepend(error);
    }
    error.classList.toggle("flex-form-request-error", pending);
    error.textContent = text;
    error.focus();
  }
  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches("[data-flex-max-input-vars]")) return;
    const pending = all(form, '.InputfieldFlex[aria-busy="true"], .flex-row[data-flex-load-failed="1"]').find((item) => !item.closest(".flex-row-remove-pending"));
    if (pending) {
      event.preventDefault();
      event.stopImmediatePropagation();
      formError(form, pending.matches(".flex-row") ? message("unableToLoad", "Unable to load item") : message("requestPending", "Wait for the current request to finish before saving."), true);
      return;
    }
    const max = Number(attr(form, "data-flex-max-input-vars"));
    if (max <= 0) return;
    const submitter = event.submitter;
    let count = submitter?.name && !submitter.disabled ? 1 : 0;
    for (const [, value2] of new FormData(form)) if (typeof value2 === "string") count++;
    if (count <= max) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    formError(form, message("inputVarsExceeded", "This form contains {count} input values, exceeding PHP max_input_vars={max}.").replace("{count}", String(count)).replace("{max}", String(max)), false);
  }, true);
  classic.preserveSelectorPaths();
  ready(() => {
    classic.preserveSelectorPaths();
    initRows(document);
  });
  document.addEventListener("pw-runtime-ready", () => {
    initRows(document);
  });
  classic.events((scope) => {
    initRows(scope);
  }, (row, open) => {
    syncToggle(row);
    if (open) void loadRow(row);
  }, (scope) => {
    all(scope, "textarea").forEach((input) => {
      const editor = window.tinymce?.get(input.id);
      if (editor) editor.save();
    });
    titlesChanged(scope);
  }, (draft, path) => {
    void refreshMedia(draft, path);
  });
  function tableColumnResizer(handle) {
    const table = handle.closest(".flex-table-grid");
    const heading = handle.closest("th");
    const headings = table?.tHead?.rows[0]?.cells;
    if (!table || !heading || !headings) return;
    const columns = all(table, ":scope > colgroup > col");
    const index = heading.cellIndex;
    const widths = Array.from(headings, (cell) => cell.getBoundingClientRect().width);
    const width = widths[index];
    if (!width || columns.length !== widths.length) return;
    const tableWidth = table.getBoundingClientRect().width;
    const direction = getComputedStyle(table).direction === "rtl" ? -1 : 1;
    return (distance) => {
      const nextWidth = Math.max(Math.min(64, width), width + direction * distance);
      columns.forEach((column, i) => {
        column.style.width = `${i === index ? nextWidth : widths[i]}px`;
      });
      table.style.width = `${tableWidth + nextWidth - width}px`;
      table.classList.add("flex-table-resized");
    };
  }
  delegate("pointerdown", ".flex-table-resize", (event, handle) => {
    if (event.button !== 0 || !event.isPrimary) return;
    const resize = tableColumnResizer(handle);
    if (!resize) return;
    event.preventDefault();
    handle.setPointerCapture(event.pointerId);
    const listeners = new AbortController();
    let moved2 = false;
    handle.addEventListener("pointermove", (move) => {
      if (move.pointerId !== event.pointerId) return;
      if (move.clientX !== event.clientX) moved2 = true;
      if (moved2) resize(move.clientX - event.clientX);
    }, { signal: listeners.signal });
    handle.addEventListener("lostpointercapture", () => listeners.abort(), { once: true });
  });
  delegate("keydown", ".flex-table-resize", (event, handle) => {
    if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
    event.preventDefault();
    tableColumnResizer(handle)?.(event.key === "ArrowRight" ? 16 : -16);
  });
  function columnDirection(button, direction) {
    const heading = closest(button, "th");
    if (direction) heading.setAttribute("aria-sort", direction);
    else heading.removeAttribute("aria-sort");
    one(button, "span").textContent = direction === "ascending" ? "\u2191" : direction === "descending" ? "\u2193" : "\u2195";
    const label = attr(button, direction === "ascending" ? "data-flex-sort-desc" : "data-flex-sort-asc");
    button.title = label;
    button.setAttribute("aria-label", label);
  }
  function clearColumnSort(set) {
    if (!set.matches(".flex-table")) return;
    const button = all(set, columnSort).find((control) => control.closest("th")?.hasAttribute("aria-sort"));
    if (button) columnDirection(button);
  }
  function columnSortValue(cell, type, decimal) {
    const inputName = attr(cell, "data-flex-sort-input");
    const input = all(cell, "input[name]").find((candidate) => candidate.name === inputName);
    const raw = (input?.value ?? attr(cell, "data-flex-sort-value")).trim();
    if (!raw) return null;
    if (type === "text") return raw;
    if (type === "integer") {
      const integer = input?.type === "number" ? raw : raw.replace(/[\s,]/g, "");
      return /^[+-]?\d+$/.test(integer) ? BigInt(integer) : null;
    }
    let normalized = raw;
    if (input?.type === "text") {
      normalized = raw.replace(/\s/g, "");
      if (decimal === "." && /^[+-]?\d{1,3}(,\d{3})+(\.\d+)?$/.test(normalized)) {
        normalized = normalized.replace(/,/g, "");
      } else if (normalized.includes(",")) {
        const comma = normalized.lastIndexOf(",");
        const point = normalized.lastIndexOf(".");
        normalized = comma > point ? normalized.replace(/\./g, "").replace(",", ".") : normalized.replace(/,/g, "");
      }
    }
    const number = Number(normalized);
    return Number.isFinite(number) ? number : null;
  }
  function textCollator(locale) {
    try {
      return new Intl.Collator(locale || "en", { sensitivity: "accent" });
    } catch {
      return new Intl.Collator("en", { sensitivity: "accent" });
    }
  }
  delegate("click", ".flex-table-column-sort", (event, element) => {
    event.preventDefault();
    event.stopPropagation();
    const button = element;
    const set = closest(button, ".flex-table");
    if (button.disabled || treeBusy(set) || selection.has(set) || set.matches(".flex-table-auto")) return;
    const heading = closest(button, "th");
    const table = closest(heading, ".flex-table-grid");
    const direction = heading.getAttribute("aria-sort") === "ascending" ? "descending" : "ascending";
    const sign = direction === "ascending" ? 1 : -1;
    const type = attr(button, "data-flex-sort-type");
    const locale = attr(table, "data-flex-sort-locale");
    const decimal = attr(table, "data-flex-sort-decimal") || ".";
    const collator = type === "text" ? textCollator(locale) : null;
    closeTableEditor(set);
    const ordered = activeRows(set).map((row) => ({
      row,
      value: columnSortValue(row.cells[heading.cellIndex], type, decimal)
    }));
    ordered.sort(({ value: a }, { value: b }) => {
      if (a === null || b === null) return Number(a === null) - Number(b === null);
      if (collator) return sign * collator.compare(String(a), String(b));
      return sign * (a < b ? -1 : a > b ? 1 : 0);
    });
    const list = rowList(set);
    let index = 0;
    const next = rows(set).map((row) => row.matches(".flex-row-remove-pending") ? row : ordered[index++].row);
    next.forEach((row, i) => {
      if (list.children[i] !== row) list.insertBefore(row, list.children[i] || null);
    });
    order(set);
    columnDirection(button, direction);
  });
  var tableInputs = ".flex-table-grid > tbody > .flex-row > .flex-table-cell, .flex-table-grid > thead > tr > th";
  var cellControl = 'input:not([type="hidden"]), textarea, select, button, [contenteditable]:not([contenteditable="false"])';
  function visibleControl(control) {
    return !control.matches(":disabled") && !!control.getClientRects().length && getComputedStyle(control).visibility === "visible";
  }
  delegate("click", tableInputs, (event, cell) => {
    const target = event.target;
    if (event.defaultPrevented || !(target instanceof Element) || target.closest("td, th") !== cell) return;
    if (target.closest('input, textarea, select, button, a, label, summary, [contenteditable], [role="button"], [role="tab"]')) return;
    if (window.getSelection()?.type === "Range") return;
    const preview = cell.querySelector(":scope > .flex-table-preview");
    if (preview && visibleControl(preview)) {
      const active = tableEditors.get(closest(cell, ".flex-table"));
      if (active?.button === preview) active.editor.focus({ preventScroll: true });
      else openTableEditor(preview);
      return;
    }
    const control = all(cell, `:scope > .Inputfields > .flex-table-input > .InputfieldContent :is(${cellControl}), :scope > .flex-table-heading > .Inputfields > .flex-table-input > .InputfieldContent :is(${cellControl})`).find(visibleControl);
    control?.focus({ preventScroll: true });
  });
  delegate("click", ".flex-row-retry", (event, button) => {
    event.preventDefault();
    void loadRow(closest(button, ".flex-row"));
  });
  var tableCells = ".flex-table-grid > tbody > .flex-row > :is(td, th), .flex-table-grid > thead > tr > th";
  var tablePointer;
  var closeAfterPointer;
  delegate("pointerdown", tableCells, (event) => {
    if (event.isPrimary && event.button === 0) tablePointer = event.pointerId;
  });
  for (const type of ["pointerup", "pointercancel"]) {
    document.addEventListener(type, (event) => {
      if (tablePointer !== event.pointerId) return;
      if (closeAfterPointer) window.setTimeout(closeAfterPointer, 0);
      tablePointer = void 0;
      closeAfterPointer = void 0;
    }, true);
  }
  delegate("focusin", tableCells, (event, cell) => {
    if (event.target instanceof HTMLInputElement) classic.tableTags(event.target);
    const set = closest(cell, ".flex-table");
    const active = tableEditors.get(set);
    if (!active || active.button.parentElement === cell) return;
    const close = () => {
      if (tableEditors.get(set) === active && cell.contains(document.activeElement) && !treeBusy(set)) closeTableEditor(set);
    };
    if (tablePointer === void 0) close();
    else closeAfterPointer = close;
  });
  delegate("click", ".flex-table-preview", (_event, button) => openTableEditor(button));
  delegate("click", ".flex-table-editor-close", (_event, button) => {
    if (!treeBusy(button)) closeTableEditor(closest(button, ".flex-table"), true);
  });
  function positionMenu(menu) {
    const summary = one(menu, ":scope > summary");
    const panel = one(menu, ":scope > .flex-clipboard-actions");
    if (typeof panel.showPopover === "function" && !panel.matches(":popover-open")) {
      panel.setAttribute("popover", "manual");
      panel.showPopover();
    }
    const anchor = summary.getBoundingClientRect();
    const margin = 8;
    const width = document.documentElement.clientWidth || window.innerWidth;
    const height = document.documentElement.clientHeight || window.innerHeight;
    const rtl = getComputedStyle(summary).direction === "rtl";
    let left = rtl ? anchor.right : anchor.left - panel.offsetWidth;
    let top = anchor.top;
    if (menu.matches(".flex-collection-menu")) {
      left = rtl ? anchor.right - panel.offsetWidth : anchor.left;
      top = anchor.bottom + panel.offsetHeight + margin <= height ? anchor.bottom : anchor.top - panel.offsetHeight;
    }
    panel.style.left = Math.max(margin, Math.min(left, width - panel.offsetWidth - margin)) + "px";
    panel.style.top = Math.max(margin, Math.min(top, height - panel.offsetHeight - margin)) + "px";
  }
  document.addEventListener("toggle", (event) => {
    const menu = event.target;
    if (!(menu instanceof HTMLDetailsElement) || !menu.matches(overlayMenus)) return;
    if (menu.open) positionMenu(menu);
    else {
      const panel = one(menu, ":scope > .flex-clipboard-actions");
      if (typeof panel.hidePopover === "function" && panel.matches(":popover-open")) panel.hidePopover();
    }
  }, true);
  function positionOpenMenus() {
    all(document, `:is(${overlayMenus})[open]`).forEach(positionMenu);
  }
  document.addEventListener("scroll", positionOpenMenus, { capture: true, passive: true });
  window.addEventListener("resize", positionOpenMenus);
  for (const name of ["click", "focusin"]) document.addEventListener(name, (event) => {
    const target = event.target;
    if (!(target instanceof Node)) return;
    all(document, `:is(${rowMenus})[open]`).forEach((details) => {
      if (!details.contains(target)) details.open = false;
    });
  }, true);
  delegate("keydown", `:is(${rowMenus})[open]`, (event, details) => {
    if (event.key !== "Escape") return;
    event.preventDefault();
    event.stopPropagation();
    details.open = false;
    one(details, "summary").focus();
  });
  delegate("click", ".flex-row-toggle", (event, button) => {
    event.preventDefault();
    event.stopPropagation();
    const row = closest(button, ".flex-row");
    openRow(row, row.matches(".InputfieldStateCollapsed"));
  });
  delegate("keydown", ".flex-row-sort", async (event, button) => {
    if (!["ArrowUp", "ArrowDown", "Home", "End"].includes(event.key)) return;
    event.preventDefault();
    event.stopPropagation();
    const row = closest(button, ".flex-row"), set = closest(row, ".flex-rows");
    if (treeBusy(set)) return;
    if (set.matches(".flex-table")) closeTableEditor(set);
    const siblings = rows(set);
    const index = siblings.indexOf(row);
    let target = event.key === "ArrowUp" ? siblings[index - 1] : siblings[index + 1];
    if (event.key === "Home") target = siblings[0];
    if (event.key === "End") target = siblings[siblings.length - 1];
    if (!target?.matches(".flex-row") || target === row) return;
    teardown(row);
    if (event.key === "ArrowUp" || event.key === "Home") target.before(row);
    else target.after(row);
    order(set);
    await classic.setup(row);
    button.focus();
    moved(set, row);
  });
  function titlesChanged(target) {
    const tree = target.closest(".InputfieldFlex");
    if (tree) all(tree, ".flex-row[data-flex-title]").forEach((row) => syncTitleSources(row, target));
  }
  ["input", "change"].forEach((name) => document.addEventListener(name, (event) => {
    const input = event.target;
    if (!(input instanceof HTMLElement) || !input.matches("input:not(.flex-rows-order), select, textarea, .InputfieldTinyMCEEditor[contenteditable]")) return;
    const cell = input.closest("[data-flex-sort-input]");
    if (cell && attr(input, "name") === attr(cell, "data-flex-sort-input")) {
      const heading = cell.closest("table")?.tHead?.rows[0]?.cells[cell.cellIndex];
      const button = heading?.querySelector(".flex-table-column-sort");
      if (button && heading?.hasAttribute("aria-sort")) columnDirection(button);
    }
    titlesChanged(input);
  }));
  var openingInvalidDetail = false;
  document.addEventListener("invalid", (event) => {
    if (!(event.target instanceof Element)) return;
    const selecting = event.target.closest(".flex-selection-active");
    if (selecting) finishSelection(selecting);
    for (let row = event.target.closest(".flex-row"); row; row = row.parentElement?.closest(".flex-row") || null) openRow(row, true);
    const editor = event.target.closest(".flex-table-editor[hidden]");
    const button = editor?.previousElementSibling;
    if (button instanceof HTMLButtonElement && !openingInvalidDetail) {
      openingInvalidDetail = true;
      queueMicrotask(() => {
        openingInvalidDetail = false;
      });
      openTableEditor(button, false);
    }
  }, true);
  delegate("dblclick", '[data-flex-row-action="remove"]', (event, element) => {
    event.preventDefault();
    event.stopPropagation();
    const button = element, set = closest(button, ".flex-rows");
    if (button.disabled || treeBusy(set)) return;
    const undo = closest(button, ".flex-row").matches(".flex-row-remove-pending");
    const apply = () => rows(set).forEach((row) => {
      if (row.matches(".flex-row-remove-pending") === undo) one(row, ':scope > .flex-row-header [data-flex-row-action="remove"]').click();
    });
    if (undo) apply();
    else classic.confirm(message("removeAll", "Remove all items?"), apply);
  });
  delegate("click", ".flex-row-add, .flex-row-action", (event, control) => {
    void rowAction(event, control);
  });
  delegate("change", ".flex-row-type", (event, control) => {
    void rowAction(event, control);
  });
  async function rowAction(event, control) {
    event.preventDefault();
    event.stopPropagation();
    const set = control.closest(".flex-rows");
    if (!set) return;
    const ancestor = control.closest(".flex-row");
    const row = ancestor?.closest(".flex-rows") === set ? ancestor : null;
    if (control.disabled || treeBusy(set)) return;
    const more = control.closest(rowMenus);
    if (more) {
      more.open = false;
      one(more, "summary").focus();
    }
    let type = attr(set, "data-flex-type"), action = attr(control, "data-flex-row-action");
    if (control instanceof HTMLSelectElement) {
      type = control.value;
      if (!type) return;
      control.selectedIndex = 0;
      action = "add";
    } else if (control.matches(".flex-row-add")) action = "add";
    const pasting = action.startsWith("paste-");
    const error = one(set, ":scope > .flex-rows-error");
    const status = one(set, ":scope > .flex-rows-status");
    error.textContent = "";
    status.textContent = "";
    status.classList.toggle("uk-hidden-visually", action !== "clipboard-copy" && !pasting);
    if (action === "clipboard-copy" && row) {
      await writeClipboard(set, [row]);
      return;
    }
    if (action === "visibility" && row) {
      setVisibility(row, !row.matches(".flex-row-hidden"));
      return;
    }
    if (action === "remove" && row) {
      const removed = !row.matches(".flex-row-remove-pending");
      window.tinymce?.triggerSave();
      if (removed) openRow(row, false);
      removalState(row, removed);
      rowState(row).removalReady = true;
      order(set);
      control.focus();
      return;
    }
    if (row) closeRowEditors(row);
    if (action === "duplicate" && row && attr(row, "data-flex-loaded") === "0" && !await loadRow(row)) return;
    setTreeBusy(set, true);
    let focus;
    try {
      const clipboard = pasting ? await readClipboard() : "";
      window.tinymce?.triggerSave();
      const data = pasting ? new FormData() : new URLSearchParams();
      data.set("flex_action", action);
      data.set("flex_set", attr(set, "data-flex-set"));
      data.set("flex_order", activeRows(set).map((row2) => attr(row2, "data-flex-row")).join(","));
      const orders = {};
      all(closest(set, ".InputfieldFlex"), ".flex-rows").forEach((current) => {
        if (!current.closest(".flex-row-remove-pending")) orders[attr(current, "data-flex-set")] = activeRows(current).map((row2) => attr(row2, "data-flex-row"));
      });
      data.set("flex_orders", JSON.stringify(orders));
      if (action === "add") data.set("flex_type", type);
      else if (action === "duplicate" && row) appendSource(data, row);
      if (pasting) {
        data.set("flex_clipboard", clipboard);
        if (action !== "paste-end" && row) data.set("flex_source", attr(row, "data-flex-row"));
      }
      const token = csrf();
      if (token) data.set(token.name, token.value);
      data.set("flex_complete", attr(set, "data-flex-draft"));
      const response = await fetch(attr(set, "data-flex-page-url"), { method: "POST", credentials: "same-origin", headers: requestHeaders(set), body: data });
      const parsed = await response.json().catch(() => null);
      if (response.status === 413) {
        throw new Error(parsed?.message || message("clipboardTooLarge", "The clipboard content exceeds the server upload limit."));
      }
      const result = parsed ?? { html: "", error: true, message: message("unableToAdd", "Unable to add item") };
      if (!response.ok || result.error) throw new Error(result.message || message("unableToAdd", "Unable to add item"));
      if (!set.isConnected) return;
      const inserted = (result.rows ?? [result]).map((item) => parseHTML(item.html));
      if (action === "paste-above" && row) row.before(...inserted);
      else if ((action === "duplicate" || action === "paste-below") && row) row.after(...inserted);
      else rowList(set).append(...inserted);
      const owner = set.closest(".Inputfield");
      if (owner?.matches(".InputfieldStateCollapsed")) classic.toggle(owner, true);
      for (const fresh of inserted) await activateScripts(fresh, response);
      order(set);
      for (const fresh of inserted) await classic.setup(fresh);
      initRows(set);
      for (const fresh of inserted) {
        [fresh, ...all(fresh, ".flex-row[data-flex-title]")].forEach((row2) => syncTitleSources(row2));
        classic.added(fresh);
      }
      focus = inserted[0]?.querySelector(":scope > .flex-row-header .flex-row-toggle, :scope > .flex-table-actions summary") || void 0;
      if (pasting) {
        const pasted = inserted.length === 1 ? message("rowPasted", "Row pasted.") : message("rowsPasted", "Rows pasted.");
        status.textContent = [pasted, result.warning].filter(Boolean).join(" ");
      }
    } catch (problem) {
      error.textContent = pasting ? clipboardError(problem) : errorMessage(problem);
    } finally {
      setTreeBusy(set, false);
    }
    focus?.focus();
  }
  function setVisibility(row, hidden) {
    if (row.matches(".flex-row-hidden") === hidden) return;
    row.classList.toggle("flex-row-hidden", hidden);
    change(one(row, ":scope > .flex-row-hidden-value, :scope > .flex-table-actions .flex-row-hidden-value"), hidden ? "1" : "0");
    const control = one(row, ':scope > .flex-row-header [data-flex-row-action="visibility"]');
    const label = attr(control, hidden ? "data-flex-show-label" : "data-flex-hide-label");
    control.setAttribute("aria-pressed", String(hidden));
    control.setAttribute("aria-label", label);
    control.title = label;
    const text = control.querySelector(".flex-row-action-label");
    if (text) text.textContent = label;
    const icon = one(control, "i");
    icon.classList.toggle("fa-eye", !hidden);
    icon.classList.toggle("fa-eye-slash", hidden);
  }
  async function writeClipboard(set, sources) {
    const error = one(set, ":scope > .flex-rows-error"), status = one(set, ":scope > .flex-rows-status");
    error.textContent = "";
    status.textContent = "";
    status.classList.remove("uk-hidden-visually");
    setTreeBusy(set, true);
    try {
      const clipboard = navigator.clipboard;
      if (!clipboard?.writeText) throw new Error(message("clipboardUnavailable", "The clipboard is unavailable. Open the editor over HTTPS."));
      const text = Promise.resolve().then(() => copyRows(set, sources));
      if (window.ClipboardItem && clipboard.write) {
        const blob = text.then((value2) => new Blob([value2], { type: "text/plain" }));
        const writing = clipboard.write([new ClipboardItem({ "text/plain": blob })]);
        await Promise.all([text, writing.catch(async (problem) => {
          await text;
          throw problem;
        })]);
      } else {
        await clipboard.writeText(await text);
      }
      status.textContent = sources.length === 1 ? message("rowCopied", "Row copied to the clipboard.") : message("rowsCopied", "Rows copied to the clipboard.");
    } catch (problem) {
      error.textContent = clipboardError(problem);
    } finally {
      setTreeBusy(set, false);
    }
  }
  function appendSource(data, row) {
    data.set("flex_source", attr(row, "data-flex-row"));
    serialize(closest(row, "form")).forEach(([name, value2]) => {
      if (name !== "id") data.append(name, value2);
    });
  }
  async function copyRows(set, sources) {
    window.tinymce?.triggerSave();
    classic.saveReady(closest(set, ".InputfieldFlex"));
    const data = new URLSearchParams({ flex_action: "clipboard-copy", flex_set: attr(set, "data-flex-set") });
    appendSource(data, sources[0]);
    if (sources.length > 1) data.set("flex_sources", sources.map((row) => attr(row, "data-flex-row")).join(","));
    const token = csrf();
    if (token) data.set(token.name, token.value);
    data.set("flex_complete", attr(set, "data-flex-draft"));
    const response = await fetch(attr(set, "data-flex-page-url"), { method: "POST", credentials: "same-origin", headers: requestHeaders(set), body: data });
    const result = await response.json();
    if (!response.ok || result.error || typeof result.clipboard !== "string") throw new Error(result.message || message("unableToLoad", "Unable to load item"));
    return result.clipboard;
  }
  function readClipboard() {
    if (!navigator.clipboard?.readText) throw new Error(message("clipboardUnavailable", "The clipboard is unavailable. Open the editor over HTTPS."));
    return navigator.clipboard.readText();
  }
  function clipboardError(problem) {
    return problem instanceof DOMException && (problem.name === "NotAllowedError" || problem.name === "SecurityError") ? message("clipboardDenied", "Clipboard access was denied. Allow access in your browser and try again.") : errorMessage(problem);
  }
  function metadataProperty(element) {
    return element.closest("[data-flex-metadata-property]")?.getAttribute("data-flex-metadata-property") || "";
  }
  function nativeMetadataName(name) {
    return (name.match(/^(description[0-9]*|tags)_/) || [])[1] || "";
  }
  function mediaMetadata(scope) {
    classic.saveReady(scope);
    return {
      inline: all(scope, ".InputfieldTinyMCEEditor.InputfieldTinyMCEInline").map((editor) => ({ id: editor.id, suffix: editor.id.split("__").slice(2).join("__"), html: editor.innerHTML, loaded: editor.matches(".InputfieldTinyMCELoaded"), property: metadataProperty(editor) })),
      controls: all(scope, controlSelector).filter((input) => input.name && (/^(description[0-9]*|tags|focus)_/.test(input.name) || input.closest("[data-flex-metadata]"))).map((input) => ({
        name: input.name,
        value: value(input),
        type: input.type,
        checked: input instanceof HTMLInputElement && input.checked,
        property: metadataProperty(input),
        suffix: input.name.split("__").slice(2).join("__"),
        native: nativeMetadataName(input.name)
      })),
      errors: all(scope, "[data-flex-metadata-property].InputfieldStateError").map((wrapper) => ({ id: wrapper.id, property: attr(wrapper, "data-flex-metadata-property"), markup: all(wrapper, ":scope > .InputfieldContent > .InputfieldError").map((error) => error.outerHTML).join("") }))
    };
  }
  function restoreMediaMetadata(scope, snapshot, replacement) {
    snapshot.controls.forEach((item) => {
      all(scope, controlSelector).filter((input) => {
        let match = input.name === item.name;
        if (replacement && item.property) match = metadataProperty(input) === item.property && input.name.split("__").slice(2).join("__") === item.suffix;
        else if (replacement && item.native) match = nativeMetadataName(input.name) === item.native;
        return match && (!/^(checkbox|radio)$/.test(item.type) || input.value === item.value);
      }).forEach((input) => {
        if (input instanceof HTMLInputElement && /^(checkbox|radio)$/.test(item.type)) input.checked = item.checked;
        else setValue(input, item.value);
      });
    });
    snapshot.errors.forEach((item) => {
      all(scope, "[data-flex-metadata-property]").filter((wrapper) => replacement ? attr(wrapper, "data-flex-metadata-property") === item.property : wrapper.id === item.id).forEach((wrapper) => {
        wrapper.classList.add("InputfieldStateError", "ui-state-error");
        if (!wrapper.querySelector(".InputfieldError")) insertHTML(one(wrapper, ":scope > .InputfieldContent"), "afterbegin", item.markup);
      });
    });
    snapshot.inline.forEach((item) => {
      all(scope, ".InputfieldTinyMCEEditor.InputfieldTinyMCEInline").filter((editor) => replacement ? metadataProperty(editor) === item.property && editor.id.split("__").slice(2).join("__") === item.suffix : editor.id === item.id).forEach((editor) => {
        setHTML(editor, item.html);
        if (item.loaded) classic.initInline(editor);
      });
    });
  }
  async function upload(input, files) {
    const upload2 = closest(input, '[data-flex-upload="1"]'), field = closest(upload2, ".Inputfield"), token = csrf();
    if (!files.length) return;
    if (treeBusy(upload2)) {
      input.value = "";
      return;
    }
    setTreeBusy(upload2, true);
    input.disabled = true;
    const status = document.createElement("p");
    status.className = "flex-upload-status";
    status.setAttribute("role", "status");
    upload2.append(status);
    try {
      for (const file of files) {
        status.textContent = message("uploading", "Uploading {name}\u2026").replace("{name}", file.name);
        const headers = { "X-Requested-With": "XMLHttpRequest", "X-FIELDNAME": attr(upload2, "data-fieldname"), "X-FLEX-DRAFT": attr(upload2, "data-flex-draft"), "X-FLEX-PATH": attr(upload2, "data-flex-path"), "X-FILENAME": encodeURIComponent(file.name), "Content-Type": "application/octet-stream" };
        if (token) headers["X-" + token.name] = token.value;
        const response = await fetch(attr(upload2, "data-posturl"), { method: "POST", credentials: "same-origin", headers, body: file });
        const result = await response.json();
        if (!response.ok || !Array.isArray(result) && result.error) throw new Error(!Array.isArray(result) && result.message || message("unableToUpload", "Unable to upload file"));
        if (!Array.isArray(result)) throw new Error(message("unableToUpload", "Unable to upload file"));
        for (const item of result) {
          if (item.error) throw new Error(item.message || message("unableToUpload", "Unable to upload file"));
          let list = field.querySelector(".InputfieldFileList") || field.querySelector(".InputfieldImageList");
          if (!list) {
            list = document.createElement("ul");
            list.className = field.matches(".InputfieldImage") ? "InputfieldImageList gridImages ui-helper-clearfix" : "InputfieldFileList ui-helper-clearfix";
            upload2.before(list);
            all(field, ".InputfieldFileListPlaceholder").forEach((item2) => item2.remove());
          }
          const replacement = Number(field.querySelector(".InputfieldFileMaxFiles, .InputfieldImageMaxFiles")?.value) === 1;
          const metadata = replacement ? mediaMetadata(list) : null;
          if (replacement) {
            classic.destroyEditors(list);
            all(list, ":scope > li").forEach((item2) => item2.remove());
          }
          const fresh = parseHTML(item.markup);
          list.append(fresh);
          await activateScripts(fresh, response);
          if (metadata) restoreMediaMetadata(fresh, metadata, true);
        }
        field.classList.remove("InputfieldFileEmpty");
        field.classList.add("InputfieldStateChanged");
        await classic.setup(field);
        titlesChanged(field);
      }
      status.remove();
    } catch (error) {
      status.setAttribute("role", "alert");
      status.textContent = errorMessage(error);
    } finally {
      input.value = "";
      input.disabled = false;
      setTreeBusy(upload2, false);
    }
  }
  document.addEventListener("change", (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== "file" || !input.closest('[data-flex-upload="1"]')) return;
    event.stopImmediatePropagation();
    void upload(input, Array.from(input.files || []));
  }, true);
  ["dragenter", "dragover", "dragleave", "drop"].forEach((name) => document.addEventListener(name, (rawEvent) => {
    const event = rawEvent;
    if (!(event.target instanceof Element)) return;
    const field = event.target.closest(".Inputfield");
    if (!field || !event.dataTransfer || !Array.from(event.dataTransfer.types).includes("Files")) return;
    const input = field.querySelector('[data-flex-upload="1"] input[type="file"]');
    if (!input || input.closest(".Inputfield") !== field) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (name === "dragleave" && event.relatedTarget instanceof Node && field.contains(event.relatedTarget)) return;
    const active = name === "dragenter" || name === "dragover";
    field.classList.toggle("pw-drag-in-file", active);
    all(field, ":scope > .InputfieldContent").forEach((content) => content.classList.toggle("ui-state-hover", active));
    if (name === "drop") void upload(input, Array.from(event.dataTransfer.files));
  }, true));
  async function refreshMedia(draft, path) {
    const upload2 = all(document, '[data-flex-upload="1"]').find((upload3) => attr(upload3, "data-flex-draft") === draft && attr(upload3, "data-flex-path") === path);
    if (!upload2) return;
    const field = closest(upload2, ".Inputfield"), token = csrf();
    try {
      const data = new URLSearchParams({ flex_action: "media-render", flex_path: path, flex_complete: draft });
      if (token) data.set(token.name, token.value);
      const response = await fetch(attr(upload2, "data-posturl"), { method: "POST", credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest", "X-FIELDNAME": attr(upload2, "data-fieldname"), "X-FLEX-DRAFT": draft }, body: data });
      const result = await response.json();
      if (!response.ok || result.error) throw new Error(result.message || message("unableToRefreshImage", "Unable to refresh image"));
      const metadata = mediaMetadata(field);
      classic.destroyEditors(field);
      const content = one(field, ":scope > .InputfieldContent");
      setHTML(content, result.html);
      await activateScripts(content, response);
      restoreMediaMetadata(field, metadata, false);
      await classic.setup(field);
      field.classList.add("InputfieldStateChanged");
      titlesChanged(field);
      classic.closeDialog();
    } catch (error) {
      const status = document.createElement("p");
      status.setAttribute("role", "alert");
      status.className = "flex-upload-status";
      status.textContent = errorMessage(error);
      field.append(status);
    }
  }
})();
