/* ==========================================================================
   ASH & EMBER — behaviours
   No dependencies, no build step. Everything is driven by data-attributes,
   so markup rendered by PHP works with zero glue code.
   Exposes window.UI for the handful of things that need calling.
   ========================================================================== */
(function () {
  "use strict";

  const on = (evt, sel, fn) =>
    document.addEventListener(evt, (e) => {
      const t = e.target.closest(sel);
      if (t) fn(e, t);
    });

  /* --- Tabs -------------------------------------------------------------
     <div class="tabs" role="tablist">
       <button class="tab" role="tab" aria-selected="true" data-tab="a">…</button>
     </div>
     <div class="tabpanel" data-panel="a">…</div>                         */
  on("click", ".tab[data-tab]", (e, tab) => {
    const list = tab.closest(".tabs");
    const key = tab.dataset.tab;
    list.querySelectorAll(".tab").forEach((t) =>
      t.setAttribute("aria-selected", String(t === tab))
    );
    const scope = list.parentElement;
    scope.querySelectorAll("[data-panel]").forEach((p) => {
      p.hidden = p.dataset.panel !== key;
    });
  });

  /* --- Dialogs ----------------------------------------------------------
     <button data-open="#confirm">  /  <button data-close>                */
  on("click", "[data-open]", (e, btn) => {
    // A control that opens a dialog does nothing else. Without this, one that
    // happens to sit inside a <label> would also activate the label's input —
    // looking at a picture would pick the archetype it belongs to.
    e.preventDefault();
    const dlg = document.querySelector(btn.dataset.open);
    if (dlg && dlg.showModal) dlg.showModal();
  });
  on("click", "[data-close]", (e, btn) => {
    const dlg = btn.closest("dialog");
    if (dlg) dlg.close();
  });

  /* --- Dock -------------------------------------------------------------
     <nav class="dock">
       <button class="dock__item" data-dock-toggle aria-expanded="false">…
     Hover opens the rail on a pointer device; this is the way in everywhere
     else, and the way to pin it open on a desktop. */
  function setDock(dock, open) {
    dock.toggleAttribute("data-expanded", open);
    dock.querySelectorAll("[data-dock-toggle]").forEach((b) =>
      b.setAttribute("aria-expanded", String(open))
    );
    // Closing has to take the focus out with it. The rail also opens on
    // :focus-within — which is what a keyboard user needs — so leaving focus
    // on the toggle would hold it open against the very press that shut it.
    if (!open && dock.contains(document.activeElement)) document.activeElement.blur();
  }

  on("click", "[data-dock-toggle]", (e, btn) => {
    const dock = btn.closest(".dock");
    if (dock) setDock(dock, !dock.hasAttribute("data-expanded"));
  });

  // A rail left open over the page is in the way, so anything that is not the
  // rail closes it: tapping the page, or Escape.
  document.addEventListener("click", (e) => {
    document.querySelectorAll(".dock[data-expanded]").forEach((dock) => {
      if (!dock.contains(e.target)) setDock(dock, false);
    });
  });
  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    document.querySelectorAll(".dock[data-expanded]").forEach((dock) => setDock(dock, false));
  });

  /* Open an item's detail modal from somewhere that is not the item — a menu
     row, say. The same template the inspector uses, without the hover layer
     that would otherwise follow the pointer across the menu. */
  on("click", "[data-inspect-open]", (e, el) => {
    const tpl = document.querySelector(el.dataset.inspectOpen);
    if (tpl && tpl.content) openInspector(tpl.content.cloneNode(true));
  });

  /* --- Board editor -----------------------------------------------------
     <div class="board" data-board-edit="#field" data-board-min data-board-max>
       <div class="board__node" data-node="ram_qdram" style="left:22%;top:30%">

     Drag a node, or focus one and use the arrows. Both write every node's
     coordinate back into the named hidden field as JSON, which is the whole
     of what gets saved — see App\Decks\BoardLayout.

     The bounds come from the markup rather than from a constant here: PHP
     clamps them on save, and a second copy of the number in this file is a
     node that jumps the moment you press Save. */
  const boardNodes = (board) => board.querySelectorAll(".board__node[data-node]");

  function boardBound(board, name, fallback) {
    const v = parseFloat(board.dataset[name]);
    return isNaN(v) ? fallback : v;
  }

  function boardPlace(node, x, y) {
    const board = node.closest(".board");
    const lo = boardBound(board, "boardMin", 0);
    const hi = boardBound(board, "boardMax", 100);
    node.style.left = Math.round(Math.max(lo, Math.min(hi, x)) * 10) / 10 + "%";
    node.style.top = Math.round(Math.max(lo, Math.min(hi, y)) * 10) / 10 + "%";
    boardWires(board);
  }

  // Every wire runs to the hub, so redrawing is reading two numbers off each
  // node. Cheap enough to do on every pointermove and impossible to get out
  // of step with where the boxes actually are.
  function boardWires(board) {
    const hub = board.querySelector(".board__node[data-hub]");
    if (!hub) return;
    const hx = parseFloat(hub.style.left);
    const hy = parseFloat(hub.style.top);

    board.querySelectorAll(".board__wire[data-from]").forEach((wire) => {
      const node = board.querySelector(
        '.board__node[data-node="' + CSS.escape(wire.dataset.from) + '"]'
      );
      if (!node) return;
      wire.setAttribute("x1", parseFloat(node.style.left));
      wire.setAttribute("y1", parseFloat(node.style.top));
      wire.setAttribute("x2", hx);
      wire.setAttribute("y2", hy);
    });
  }

  function boardSave(board) {
    const field = board.dataset.boardEdit && document.querySelector(board.dataset.boardEdit);
    if (!field) return;
    const out = {};
    boardNodes(board).forEach((node) => {
      out[node.dataset.node] = [parseFloat(node.style.left), parseFloat(node.style.top)];
    });
    field.value = JSON.stringify(out);
  }

  on("pointerdown", ".board[data-board-edit] .board__node", (e, node) => {
    node.setPointerCapture(e.pointerId);
    node.setAttribute("data-dragging", "");
    e.preventDefault();
  });

  document.addEventListener("pointermove", (e) => {
    const node = document.querySelector(".board__node[data-dragging]");
    if (!node) return;
    const r = node.closest(".board").getBoundingClientRect();
    boardPlace(node, ((e.clientX - r.left) / r.width) * 100, ((e.clientY - r.top) / r.height) * 100);
  });

  document.addEventListener("pointerup", () => {
    const node = document.querySelector(".board__node[data-dragging]");
    if (!node) return;
    node.removeAttribute("data-dragging");
    boardSave(node.closest(".board"));
  });

  // A pointer is not the only way to move a box. Shift for a bigger step.
  const ARROWS = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };

  on("keydown", ".board[data-board-edit] .board__node", (e, node) => {
    const step = ARROWS[e.key];
    if (!step) return;
    e.preventDefault();
    const by = e.shiftKey ? 5 : 1;
    boardPlace(node, parseFloat(node.style.left) + step[0] * by, parseFloat(node.style.top) + step[1] * by);
    boardSave(node.closest(".board"));
  });

  // Clearing the field is what "not arranged" is: the board falls back to the
  // ring PHP draws, so there is nothing here to reset it TO.
  on("click", "[data-board-reset]", (e, btn) => {
    const field = document.querySelector(btn.dataset.boardReset);
    if (field) field.value = "";
  });

  /* --- Inspector --------------------------------------------------------
     <button class="slot" data-inspect="#detail-7">…</button>
     <template id="detail-7"><div class="item-card">…</div></template>

     Pointing at a thing floats its detail beside it; clicking or tapping opens
     the same detail in a modal, where the actions live. Both paths clone the
     one <template>, so the detail is written once and the two presentations
     cannot drift. Hover on its own would leave a phone with no way to read it
     at all, which is the whole reason the click path exists. */
  const GAP = 8;
  let layer = null;
  let sheet = null;

  function detail(el) {
    const tpl = document.querySelector(el.dataset.inspect);
    return tpl && tpl.content ? tpl.content.cloneNode(true) : null;
  }

  function place(anchorEl) {
    const a = anchorEl.getBoundingClientRect();
    const r = layer.getBoundingClientRect();
    // Above by preference, below when there is no room — the top row of a
    // stash grid has nothing above it.
    let top = a.top - r.height - GAP;
    if (top < GAP) top = Math.min(a.bottom + GAP, innerHeight - r.height - GAP);
    let left = a.left + a.width / 2 - r.width / 2;
    left = Math.max(GAP, Math.min(left, innerWidth - r.width - GAP));
    layer.style.transform =
      "translate(" + Math.round(left) + "px," + Math.round(Math.max(GAP, top)) + "px)";
  }

  function inspect(el) {
    const body = detail(el);
    if (!body) return;
    if (!layer) {
      layer = document.createElement("div");
      layer.className = "inspector";
      layer.setAttribute("role", "tooltip");
      document.body.appendChild(layer);
    }
    layer.replaceChildren(body);
    layer.hidden = false;
    place(el);
    layer.setAttribute("data-shown", "");
  }

  function uninspect() {
    if (!layer) return;
    layer.removeAttribute("data-shown");
    layer.hidden = true;
  }

  // pointerover/out rather than enter/leave: only the bubbling pair reaches a
  // document-level listener. Touch is skipped — it would fire alongside the
  // click that opens the modal.
  on("pointerover", "[data-inspect]", (e, el) => {
    if (e.pointerType !== "touch") inspect(el);
  });
  on("pointerout", "[data-inspect]", (e, el) => {
    if (!el.contains(e.relatedTarget)) uninspect();
  });
  // :focus-visible, not plain focus: closing the modal returns focus to the
  // slot, and a mouse user would otherwise be left holding the layer open.
  on("focusin", "[data-inspect]", (e, el) => {
    if (el.matches(":focus-visible")) inspect(el);
  });
  on("focusout", "[data-inspect]", () => uninspect());

  // Fixed positioning is measured once, so anything that moves the anchor
  // invalidates it. Cheaper to drop the layer than to follow the scroll.
  addEventListener("scroll", uninspect, true);
  addEventListener("resize", uninspect);

  function openInspector(body) {
    uninspect();
    if (!sheet) {
      sheet = document.createElement("dialog");
      sheet.className = "modal modal--inspect";
      // A click on the backdrop lands on the dialog itself, never on the card.
      sheet.addEventListener("click", (ev) => {
        if (ev.target === sheet) sheet.close();
      });
      document.body.appendChild(sheet);
    }
    sheet.replaceChildren(body);
    // showModal() where there is one, show() where there is not: a card that
    // opens without a backdrop still says what the item is, and the same guard
    // is what [data-open] above already does.
    if (sheet.showModal) sheet.showModal();
    else if (sheet.show) sheet.show();
  }

  on("click", "[data-inspect]", (e, el) => {
    const body = detail(el);
    if (body) openInspector(body);
  });

  /* --- Run purpose reveals its follow-up ---------------------------------
     <select data-run-purpose>…</select>
     <div data-run-kind hidden>…</div>

     A backdoor's kind is permanent once the install lands, so it is asked at
     the door with the purpose and nowhere else. Hidden rather than disabled:
     it is not a field that is unavailable, it is a question that only one
     purpose asks. The server drops it for every other purpose anyway, so this
     is the courtesy and not the guard. */
  function syncRunKind() {
    const purpose = document.querySelector("[data-run-purpose]");
    const kind = document.querySelector("[data-run-kind]");
    if (purpose && kind) kind.hidden = purpose.value !== "backdoor";
  }

  on("change", "[data-run-purpose]", syncRunKind);
  syncRunKind();

  /* --- Sortable tables --------------------------------------------------
     <th aria-sort="none" data-sort="num|text">                           */
  on("click", "th[data-sort]", (e, th) => {
    const table = th.closest("table");
    const body = table.tBodies[0];
    const idx = [...th.parentNode.children].indexOf(th);
    const dir = th.getAttribute("aria-sort") === "ascending" ? -1 : 1;

    table.querySelectorAll("th[data-sort]").forEach((h) =>
      h.setAttribute("aria-sort", "none")
    );
    th.setAttribute("aria-sort", dir === 1 ? "ascending" : "descending");

    const val = (tr) => tr.children[idx].textContent.trim();
    const num = th.dataset.sort === "num";
    [...body.rows]
      .sort((a, b) =>
        dir *
        (num
          ? parseFloat(val(a).replace(/[^\d.-]/g, "")) -
            parseFloat(val(b).replace(/[^\d.-]/g, ""))
          : val(a).localeCompare(val(b)))
      )
      .forEach((tr) => body.appendChild(tr));
  });

  /* --- Toasts ----------------------------------------------------------- */
  function toaster() {
    let el = document.querySelector(".toaster");
    if (!el) {
      el = document.createElement("div");
      el.className = "toaster";
      document.body.appendChild(el);
    }
    return el;
  }

  function toast(message, opts = {}) {
    const { level = "", timeout = 4000, title } = opts;
    const el = document.createElement("div");
    el.className = "toast" + (level ? " toast--" + level : "");
    el.setAttribute("role", "status");
    el.innerHTML =
      '<span class="dot dot--' + (level || "ok") + '" style="margin-top:6px"></span>' +
      '<div><div style="font-weight:600">' + esc(title || message) + "</div>" +
      (title ? '<div class="muted" style="font-size:var(--fs-xs)">' + esc(message) + "</div>" : "") +
      "</div>";
    toaster().appendChild(el);
    if (timeout) setTimeout(() => el.remove(), timeout);
    return el;
  }

  /* --- Meters -----------------------------------------------------------
     UI.meter(el, 0.42) — or set style="--v:.42" server-side and skip JS.  */
  function meter(el, ratio) {
    const v = Math.max(0, Math.min(1, ratio));
    el.style.setProperty("--v", v);
    // role="meter" is only meaningful with its bounds, and setting them here
    // means no template has to remember two attributes that never vary.
    el.setAttribute("aria-valuenow", Math.round(v * 100));
    el.setAttribute("aria-valuemin", "0");
    el.setAttribute("aria-valuemax", "100");
    // data-hot-high: heat/overload bars. data-hot-low: health/ammo bars.
    var hot =
      (el.dataset.hotHigh !== undefined && v > 0.85) ||
      (el.dataset.hotLow !== undefined && v < 0.2);
    // A dial is the same number drawn as a ring, so it wears the same
    // threshold. One test, one place, two shapes.
    el.classList.toggle(
      el.classList.contains("dial") ? "dial--hot" : "meter--hot",
      hot
    );
  }

  /* --- Terminal ---------------------------------------------------------- */
  const pad = (n) => String(n).padStart(2, "0");

  /* NOTE: `text` is inserted as HTML so lines can carry <em> emphasis.
     Anything player-supplied must go through UI.esc() first. */
  function log(termOrSel, text, level = "info") {
    const term =
      typeof termOrSel === "string" ? document.querySelector(termOrSel) : termOrSel;
    const out = term.classList.contains("term__out")
      ? term
      : term.querySelector(".term__out");
    const d = new Date();
    const line = document.createElement("div");
    line.className = "term__line";
    line.dataset.lvl = level;
    line.innerHTML =
      '<span class="term__ts">' +
      pad(d.getHours()) + ":" + pad(d.getMinutes()) + ":" + pad(d.getSeconds()) +
      '</span><span class="term__lvl">' + esc(level.toUpperCase()) +
      '</span><span class="term__msg">' + text + "</span>";
    out.appendChild(line);
    out.scrollTop = out.scrollHeight;
    return line;
  }

  /* Types a line out character by character. Returns a promise. */
  function type(el, text, speed = 18) {
    return new Promise((resolve) => {
      el.textContent = "";
      el.classList.add("caret");
      let i = 0;
      const tick = setInterval(() => {
        el.textContent += text[i++];
        if (i >= text.length) {
          clearInterval(tick);
          el.classList.remove("caret");
          resolve(el);
        }
      }, speed);
    });
  }

  /* Terminal prompt: Enter dispatches ui:command on the .term element. */
  on("keydown", ".term__input", (e, input) => {
    if (e.key !== "Enter") return;
    const value = input.value.trim();
    if (!value) return;
    input.value = "";
    const term = input.closest(".term");
    log(term, '<span class="term__sigil">&gt;</span> ' + esc(value), "sys");
    term.dispatchEvent(
      new CustomEvent("ui:command", { detail: { value }, bubbles: true })
    );
  });

  function esc(s) {
    return String(s).replace(/[&<>"']/g, (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c])
    );
  }

  /* --- Captcha ----------------------------------------------------------
     <div class="captcha"></div>

     Upgraded into Cap's <cap-widget> (trycap.dev). The endpoint and the
     labels come from window.CAP_ENDPOINT and window.CAP_I18N, which the page
     sets once, so the markup stays a bare class and no form repeats them.

     Inside a <form> the widget injects its own hidden cap-token input, so
     the token posts with the rest of the fields and no page needs a solve
     handler. Nothing happens when no endpoint is configured, which is how
     the gallery and a Cap-less install stay quiet rather than erroring. */
  function captcha(host) {
    if (host.dataset.capMounted) return null;

    const endpoint = host.dataset.endpoint || window.CAP_ENDPOINT;
    if (!endpoint) return null;

    host.dataset.capMounted = "1";

    const widget = document.createElement("cap-widget");
    widget.setAttribute("data-cap-api-endpoint", endpoint);

    // Every one of these is required unless the markup says otherwise, so a
    // form cannot be submitted with the challenge left untouched.
    if (!host.hasAttribute("data-optional")) widget.setAttribute("required", "");

    const labels = window.CAP_I18N || {};
    Object.keys(labels).forEach((key) =>
      widget.setAttribute("data-cap-i18n-" + key, labels[key])
    );

    host.appendChild(widget);
    return widget;
  }

  document.querySelectorAll(".captcha").forEach(captcha);

  /* A server-rendered bar already carries --v in its style attribute. Run the
     same hot test over it, so "low" means one number in one place rather than
     a copy of it in every template that draws a bar.

     Twice, and idempotently: once now for the ordinary deferred case, and
     again at DOMContentLoaded for bars that something else put in the document
     after this file ran — which is exactly what the gallery does when it
     clones a demo out of its <template>. */
  function hotMeters() {
    document
      .querySelectorAll(
        ".meter[data-hot-low], .meter[data-hot-high], .dial[data-hot-low], .dial[data-hot-high]"
      )
      .forEach((el) => {
        meter(el, parseFloat(getComputedStyle(el).getPropertyValue("--v")) || 0);
      });
  }

  hotMeters();
  document.addEventListener("DOMContentLoaded", hotMeters);

  /* --- The grid map ------------------------------------------------------
     Two jobs, and both exist because the map is always centred on the player.

     1. SCROLL THEM INTO THE MIDDLE. The map is wider and taller than any
        phone, so without this it opens on its top-left corner and the player
        is somewhere off screen. Done without smooth scrolling, because this is
        the starting position rather than a movement.

     2. KEYS. Arrows move orthogonally and the numpad moves in all eight, the
        way every roguelike has done it — a diagonal is one step here, so four
        keys would only reach half the moves. Each steppable cell carries its
        own offset in data-step, so a key maps to a cell rather than to a
        direction the browser would have to resolve against a position only the
        server knows.                                                        */
  const STEP_KEYS = {
    ArrowUp: "0,-1", ArrowDown: "0,1", ArrowLeft: "-1,0", ArrowRight: "1,0",
    Numpad8: "0,-1", Numpad2: "0,1", Numpad4: "-1,0", Numpad6: "1,0",
    Numpad7: "-1,-1", Numpad9: "1,-1", Numpad1: "-1,1", Numpad3: "1,1",
  };

  function centreMap() {
    document.querySelectorAll(".gridmap").forEach((map) => {
      const you = map.querySelector(".cell--you");
      if (!you) return;
      map.scrollLeft = you.offsetLeft - map.clientWidth / 2 + you.offsetWidth / 2;
      map.scrollTop = you.offsetTop - map.clientHeight / 2 + you.offsetHeight / 2;
    });
  }

  centreMap();
  document.addEventListener("DOMContentLoaded", centreMap);

  document.addEventListener("keydown", (e) => {
    if (e.altKey || e.ctrlKey || e.metaKey) return;

    /* Never while somebody is typing. The map has an address field on it, and
       an arrow key inside that one must move the caret rather than the
       player. */
    const el = document.activeElement;
    if (el && (el.matches("input, textarea, select") || el.isContentEditable)) return;

    const offset = STEP_KEYS[e.code];
    if (!offset) return;

    const cell = document.querySelector('.cell--step[data-step="' + offset + '"]');
    if (!cell) return;

    e.preventDefault();
    cell.click();
  });

  /* --- Modifier rows -----------------------------------------------------
     Add and remove rows in the admin's effects editor.

     The blueprint is a <template> in the markup rather than a string here,
     because a row is a dozen elements with labels and a whole grouped picker
     in it — building that by concatenation is how a form ends up with two
     versions of itself that disagree.

     INDEXES ARE REWRITTEN AFTER EVERY CHANGE. PHP reads modifiers[0], [1], [2]
     as a list; deleting the middle one and leaving a hole turns the list into
     a map and the shape the request expects quietly changes.               */
  function modifierRows(scope) {
    (scope || document).querySelectorAll("[data-modifiers]").forEach((field) => {
      if (field.dataset.modifiersReady) return;
      field.dataset.modifiersReady = "1";

      const list = field.querySelector("[data-modifier-rows]");
      const template = field.querySelector("[data-modifier-template]");
      const empty = field.querySelector("[data-modifier-empty]");
      if (!list || !template) return;

      const reindex = () => {
        list.querySelectorAll("[data-modifier-row]").forEach((row, index) => {
          row.querySelectorAll("[name]").forEach((input) => {
            input.name = input.name.replace(/\[(?:\d+|__index__)\]/, "[" + index + "]");
          });
        });
        if (empty) empty.hidden = list.querySelector("[data-modifier-row]") !== null;
      };

      field.querySelector("[data-modifier-add]")?.addEventListener("click", () => {
        list.appendChild(template.content.cloneNode(true));
        reindex();
        list.lastElementChild?.querySelector("select")?.focus();
      });

      field.addEventListener("click", (e) => {
        const remove = e.target.closest("[data-modifier-remove]");
        if (!remove || !field.contains(remove)) return;
        remove.closest("[data-modifier-row]")?.remove();
        reindex();
      });

      reindex();
    });
  }

  modifierRows();
  document.addEventListener("DOMContentLoaded", () => modifierRows());

  /* --- The map's key -----------------------------------------------------
     The same three ways in as the rail: hover where there is a pointer, focus
     for a keyboard, and this button everywhere — which is the only one a phone
     has. Closing takes the focus out with it, or the key would stay open on
     :focus-within against the very press that shut it.                      */
  on("click", "[data-legend-toggle]", (e, btn) => {
    const legend = btn.closest(".maplegend");
    if (!legend) return;

    const open = !legend.hasAttribute("data-expanded");
    legend.toggleAttribute("data-expanded", open);
    btn.setAttribute("aria-expanded", String(open));

    if (!open && legend.contains(document.activeElement)) document.activeElement.blur();
  });

  /* --- Watching a read ---------------------------------------------------
     A scan pass takes minutes, so the dialog that shows one has to keep up
     with it. It polls while it is open and stops the moment it is closed —
     there is nothing to watch behind a closed dialog, and a timer left running
     on a screen nobody is looking at is a request every few seconds for ever.

     The endpoint settles a matured pass on the way past, which is why the
     answers fill in as soon as one finishes rather than at the next reload. */
  function reading() {
    const dlg = document.querySelector("[data-reading]");
    if (!dlg) return;

    let timer = null;
    let address = null;

    const set = (name, value) => {
      const el = dlg.querySelector("[data-reading-" + name + "]");
      if (el) el.textContent = value ?? "—";
    };

    async function refresh() {
      if (!address || !dlg.open) return;

      let data;
      try {
        const res = await fetch(
          dlg.dataset.readingUrl + "?address=" + encodeURIComponent(address),
          { headers: { Accept: "application/json" } }
        );
        data = await res.json();
      } catch {
        return;   // A dropped poll is not worth saying anything about.
      }

      if (!data.found) {
        set("note", dlg.dataset.readingGone || "");
        return stop();
      }

      set("name", data.name);
      set("position", data.position);
      set("address", data.address);

      // The address is a copy button, so its payload has to follow its label —
      // otherwise the read shows one machine and the clipboard hands over the
      // placeholder it was rendered with.
      const addr = dlg.querySelector("[data-reading-address]");
      if (addr && data.address) addr.dataset.copy = data.address;

      // Abandoning posts an address, and the dialog is rendered once for
      // whichever read is being watched — so the form has to follow the poll
      // rather than the page it was rendered on.
      const target = dlg.querySelector("[data-reading-abandon-address]");
      if (target && data.address) target.value = data.address;
      set("instrument", data.instrument);
      set("pass", dlg.dataset.readingPass
        .replace(":pass", data.pass)
        .replace(":total", data.passes));
      set("ready", data.running && data.readyIn ? "· " + data.readyIn : "");

      const bar = dlg.querySelector("[data-reading-bar]");
      if (bar) bar.style.setProperty("--v", data.running ? data.progress : 1);

      const spinner = dlg.querySelector("[data-reading-spinner]");
      if (spinner) spinner.hidden = !data.running;

      /* An answer looks different from the absence of one. A row the
         instrument has filled in turns ember and says what it found; a row it
         has not stays the same box it started as. The mark on the operating
         system is the one place a fact also says whether the reader can DO
         anything with it — see SystemScan::markFor(). */
      (data.facts || []).forEach((fact) => {
        const value = dlg.querySelector('[data-fact="' + fact.key + '"] [data-fact-value]');
        if (!value || !fact.discoverable) return;

        const known = fact.value != null;
        value.classList.toggle("tag--ember", known);

        const mark = known && fact.mark
          ? '<span class="' + (fact.mark === "ok" ? "mark--ok" : "mark--no") + '" title="' +
            esc(fact.mark === "ok" ? dlg.dataset.readingOsKnown : dlg.dataset.readingOsUnknown) +
            '">' + (fact.mark === "ok" ? "\u2713" : "\u2717") + "</span> "
          : "";

        value.innerHTML = mark + esc(known ? fact.value : dlg.dataset.readingUnread);
      });

      /* A read goes round by itself until the file is whole, so stopping
         short says WHICH of the two ways it stopped: the budget ran out, or
         the instrument was unloaded. They are different things to have done. */
      set("note", data.complete
        ? dlg.dataset.readingComplete
        : (data.running
          ? ""
          : (data.pass >= data.passes
            ? dlg.dataset.readingExhausted
            : dlg.dataset.readingIdle)));

      // Nothing left to watch: the read stopped, and the dialog stays open
      // showing what it found — it is the polling that stops.
      if (!data.running) stop();
    }

    function start(target) {
      stop();
      address = target;
      refresh();
      timer = setInterval(refresh, 5000);
    }

    function stop() {
      if (timer) clearInterval(timer);
      timer = null;
    }

    dlg.addEventListener("close", () => stop());

    on("click", "[data-reading-open]", (e, btn) => {
      start(btn.dataset.readingOpen);
      if (dlg.showModal) dlg.showModal();
    });

    // A read just begun opens the screen on it, so a player sees the
    // instrument start rather than a sentence saying it did.
    if (dlg.dataset.readingOpen) {
      start(dlg.dataset.readingOpen);
      if (dlg.showModal) dlg.showModal();
    }
  }

  /* AN ADDRESS, ONTO THE CLIPBOARD.

     Every address on screen is a click away from being pasted somewhere else,
     because that is what an address is FOR: it carries no position, so trading
     one is harmless and expected. The alternative is a player retyping eight
     hex characters and getting one wrong.

     The write can fail — an insecure origin, a browser that refuses, a denied
     permission — so the failure says so rather than leaving a button that
     quietly does nothing. */
  on("click", "[data-copy]", async (e, el) => {
    e.preventDefault();

    const text = el.dataset.copy;
    const ok = el.dataset.copied;
    const bad = el.dataset.copyFailed;

    try {
      await navigator.clipboard.writeText(text);
      if (ok) toast(ok);
    } catch {
      if (bad) toast(bad, { level: "warn" });
    }
  });

  reading();
  document.addEventListener("DOMContentLoaded", reading);

  window.UI = { toast, meter, log, type, esc, captcha, centreMap, modifierRows };
})();
