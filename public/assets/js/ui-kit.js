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

  /* --- Repeating rows ----------------------------------------------------
     A form that grows: a "+" clones a blank row from a <template>, putting the
     next index where the token is. Rows are direct children of [data-rows], and
     the index is how many there already are — the server renders them 0..n-1.
     Templates may nest: only THIS button's token is replaced, so an inner
     template keeps its own for later.
     <div data-rows>
       <div data-row>… name="x[0][y]" …</div>
       <template><div data-row>… name="x[__N__][y]" …</div></template>
       <button type="button" data-add-row data-token="__N__">+</button>
     </div>                                                                  */
  on("click", "[data-add-row]", (e, btn) => {
    const box = btn.closest("[data-rows]");
    const tpl = box && box.querySelector(":scope > template");
    if (!tpl) return;
    // Rows may have been removed, so the count is not a free index: the next one is past every index ever used.
    const n = Math.max(box.querySelectorAll(":scope > [data-row]").length, Number(box.dataset.nextIndex || 0));
    box.dataset.nextIndex = String(n + 1);
    tpl.insertAdjacentHTML("beforebegin", tpl.innerHTML.split(btn.dataset.token).join(String(n)));
    fillPrefixes(box);
  });

  /* A "✕" on a row removes it, and everything nested inside it. Nothing is deleted until the form is saved. The indexes of
     the rows left are NOT renumbered (the server reads them by key), so remember how far they ran, or the next "+" would
     reuse an index a row still has.
     <div data-row><button type="button" data-remove-row>✕</button> …</div>                                    */
  on("click", "[data-remove-row]", (e, btn) => {
    const row = btn.closest("[data-row]");
    const box = row && row.parentElement;
    if (!box) return;
    box.dataset.nextIndex = String(Math.max(Number(box.dataset.nextIndex || 0), box.querySelectorAll(":scope > [data-row]").length));
    row.remove();
  });

  /* A button that must be pressed twice. The first press swaps its label for the data-confirm text and holds the submit;
     the second, within four seconds, goes through. Anything else puts the label back. A guard against misclicks, not a
     security measure: the server still judges the request.
     <button type="submit" data-confirm="Are you sure?">Forget</button>                                           */
  on("click", "[data-confirm]", (e, btn) => {
    if (btn.dataset.armed) {
      clearTimeout(Number(btn.dataset.armed));
      delete btn.dataset.armed;
      return;
    }
    e.preventDefault();
    const label = btn.textContent;
    btn.textContent = btn.dataset.confirm;
    btn.dataset.armed = String(setTimeout(() => {
      btn.textContent = label;
      delete btn.dataset.armed;
    }, 4000));
  });

  /* --- Suggestions as you type -------------------------------------------
     An input with data-suggest="URL" and data-suggest-into="LIST-ID" asks the URL for ?q=<what is typed> and puts the JSON
     list of strings it gets back into that <datalist>. The browser does the drawing and the picking. A short pause between
     keystrokes, and a late answer to an older question is thrown away. Suggestions are a courtesy: the server still judges
     whatever is sent, and a failed request just leaves the list as it was.
     <input list="x" data-suggest="/handles" data-suggest-into="x"> <datalist id="x"></datalist>                   */
  // Delegated, so an input cloned into a modal (an item card's contract recipient) suggests too.
  const suggestState = new WeakMap();
  document.addEventListener("input", (e) => {
    const input = e.target.closest?.("input[data-suggest]");
    if (!input) return;
    const list = document.getElementById(input.dataset.suggestInto);
    if (!list) return;
    const state = suggestState.get(input) || { timer: null, asked: 0 };
    suggestState.set(input, state);
    clearTimeout(state.timer);
    const q = input.value.trim();
    if (q === "") { list.replaceChildren(); return; }
    state.timer = setTimeout(() => {
      const mine = ++state.asked;
      fetch(input.dataset.suggest + "?q=" + encodeURIComponent(q), {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
      })
        .then((r) => (r.ok ? r.json() : []))
        .then((names) => {
          if (mine !== state.asked || !Array.isArray(names)) return;
          // textContent through option.value, never innerHTML: a handle is player-supplied.
          list.replaceChildren(...names.map((n) => Object.assign(document.createElement("option"), { value: String(n) })));
        })
        .catch(() => {});
    }, 150);
  });

  /* --- A default that follows another field ------------------------------
     An input with data-prefix="foo" (fixed) or data-prefix-from="#slug" (follows
     that input) starts as "foo_". It keeps following until somebody types
     something else: only a value that is empty, or still exactly the default
     last applied, is replaced.
     <input data-prefix-from="#slug"> … <input id="slug">                   */
  const prefixed = "input[data-prefix], input[data-prefix-from]";
  const fillPrefixes = (root) =>
    root.querySelectorAll(prefixed).forEach((input) => {
      const from = input.dataset.prefixFrom
        ? (document.querySelector(input.dataset.prefixFrom) || {}).value
        : input.dataset.prefix;
      const next = from ? from + "_" : "";
      if (input.dataset.applied === undefined && input.value === next) input.dataset.applied = next;
      if (input.value === "" || input.value === (input.dataset.applied || "")) {
        input.value = next;
        input.dataset.applied = next;
      }
    });
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => fillPrefixes(document));
  } else {
    fillPrefixes(document);
  }
  document.addEventListener("input", (e) => {
    if (e.target.id && document.querySelector('[data-prefix-from="#' + CSS.escape(e.target.id) + '"]')) {
      fillPrefixes(document);
    }
  });

  /* --- A choice that carries a value ---------------------------------------
     A <select data-split> whose option value may be "kind:thing". Choosing one
     copies "thing" into the [data-split-target] input in the same [data-row].
     The select still submits the whole "kind:thing"; the server splits it, and
     an edited input wins. Choosing a plain option leaves the input alone.
     <option value="injury:neural_damage">Neural damage</option>             */
  on("change", "select[data-split]", (e, select) => {
    const [, thing] = select.value.split(/:(.*)/s);
    const target = select.closest("[data-row]")?.querySelector("input[data-split-target]");
    if (thing !== undefined && target) target.value = thing;
    // The journal's three inputs only exist for a journal outcome: a disabled field is not submitted, which keeps
    // the form under the server's field limit.
    select.closest("[data-row]")?.querySelectorAll("input[data-journal]").forEach((i) => { i.disabled = select.value !== "journal"; });
    select.closest("[data-row]")?.querySelectorAll("input[data-message]").forEach((i) => { i.disabled = select.value !== "message"; });
    select.closest("[data-row]")?.querySelectorAll("[data-file]").forEach((i) => { i.disabled = select.value !== "file"; });
  });

  /* --- Range with a word -------------------------------------------------
     A slider whose label says what the number means. data-bands is JSON,
     [[ceiling, "word"], …] lowest first: the first row the value does not
     exceed wins. data-format is the label, with {band} and {value} in it. The
     server renders the first label; this only keeps it true as it moves.
     <label>… <span data-range-out>…</span>
       <input type="range" data-bands='[[2,"Rare"],[100,"Common"]]'
              data-format="{band} · {value} in 100"></label>                */
  on("input", "input[type=range][data-bands]", (e, range) => {
    const out = range.closest("label, .field")?.querySelector("[data-range-out]");
    if (!out) return;
    const v = Number(range.value);
    const band = (JSON.parse(range.dataset.bands).find(([upto]) => v <= upto) || [])[1] || "";
    out.textContent = (range.dataset.format || "{value}").replace("{band}", band).replace("{value}", String(v));
  });

  /* --- Range with a price ------------------------------------------------
     A slider whose steps cost something: data-unit-price is the price of one,
     data-format has {value} and {total}. The server renders the first label.
     <label><span data-range-out>…</span>
       <input type="range" data-unit-price="40" data-format="{value} points · {total} ¤"></label> */
  on("input", "input[type=range][data-unit-price]", (e, range) => {
    const out = range.closest("label, .field")?.querySelector("[data-range-out]");
    if (!out) return;
    const v = Number(range.value);
    const total = Math.ceil(v * Number(range.dataset.unitPrice));
    out.textContent = (range.dataset.format || "{value}")
      .replace("{value}", String(v)).replace("{total}", total.toLocaleString("en-US"));
  });

  /* --- Tabs -------------------------------------------------------------
     <div class="tabs" role="tablist">
       <button class="tab" role="tab" aria-selected="true" data-tab="a">…</button>
     </div>
     <div class="tabpanel" data-panel="a">…</div>                         */
  on("click", ".tab[data-tab]", (e, tab) => {
    const list = tab.closest(".tabs");
    const key = tab.dataset.tab;
    if (list.dataset.tabRemember) {
      try { sessionStorage.setItem("tab." + list.dataset.tabRemember, key); } catch { /* it opens on the first tab then */ }
    }
    list.querySelectorAll(".tab").forEach((t) =>
      t.setAttribute("aria-selected", String(t === tab))
    );
    const scope = list.parentElement;
    scope.querySelectorAll("[data-panel]").forEach((p) => {
      p.hidden = p.dataset.panel !== key;
    });
  });

  /* A tablist marked data-tab-remember="name" reopens on the tab it was left on, so a form that posts and
     comes back (decrypt, use, fit) lands on the same view. Per browser tab, and gone with it. */
  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".tabs[data-tab-remember]").forEach((list) => {
      let key = null;
      try { key = sessionStorage.getItem("tab." + list.dataset.tabRemember); } catch { /* first tab */ }
      list.querySelector(`.tab[data-tab="${CSS.escape(key ?? "")}"]`)?.click();
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
  /* A preview of a draft: button[data-bio-preview="URL"] posts the textarea that data-from names and puts the server's
     drawing of it in the dialog data-dialog names ([data-preview-body]), so a draft is drawn by the code that will draw
     the saved one. A refusal closes the dialog. */
  on("click", "[data-bio-preview]", (e, btn) => {
    e.preventDefault();
    const dlg = document.querySelector(btn.dataset.dialog);
    const box = dlg?.querySelector("[data-preview-body]");
    const source = document.querySelector(btn.dataset.from);
    if (!box || !source) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    box.textContent = btn.dataset.previewLoading ?? "";
    dlg.showModal();
    fetch(btn.dataset.bioPreview, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded", "X-Requested-With": "XMLHttpRequest", ...(token ? { "X-CSRF-TOKEN": token } : {}) },
      credentials: "same-origin",
      body: new URLSearchParams({ bio: source.value }),
    })
      .then((r) => (r.ok ? r.text() : Promise.reject()))
      .then((html) => { box.innerHTML = html; })
      .catch(() => dlg.close());
  });
  /* A run link (`run:<id>` in chat): ask to join that party, say how it went, and go to the briefing on success. */
  on("click", "[data-run-join]", (e, btn) => {
    e.preventDefault();
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    fetch(btn.dataset.runJoin, {
      method: "POST",
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...(token ? { "X-CSRF-TOKEN": token } : {}) },
      credentials: "same-origin",
    })
      .then((r) => r.json().then((j) => ({ ok: r.ok, ...j })))
      .then(async (j) => {
        // Invited but elsewhere: the address goes to the clipboard, for Jump.
        if (j.copy) {
          try {
            await navigator.clipboard.writeText(j.copy);
            toast(j.message);
          } catch {
            toast(j.message.replace(/ is copied: paste it/, " (copy it): paste it"), { level: "warn" });
          }

          return;
        }

        toast(j.message, { level: j.ok ? "ok" : "warn" });
        if (j.ok && j.url) setTimeout(() => { location.href = j.url; }, 800);
      })
      .catch(() => toast("Could not join the run.", { level: "warn" }));
  });
  /* A listing link (`market:<id>` in chat or a bio): fetch the card fresh into #listing and open it. data-dialog names
     another dialog with a [data-listing-body] (the text file reader) in its place. */
  on("click", "[data-listing]", (e, btn) => {
    e.preventDefault();
    const dlg = document.querySelector(btn.dataset.dialog || "#listing");
    const body = dlg && dlg.querySelector("[data-listing-body]");
    if (!body) return;
    body.textContent = "…";
    if (dlg.showModal && !dlg.open) dlg.showModal();
    fetch(btn.dataset.listing, { headers: { "X-Requested-With": "XMLHttpRequest" }, credentials: "same-origin" })
      .then((r) => (r.ok ? r.text() : Promise.reject()))
      .then((html) => { body.innerHTML = html; })
      .catch(() => { body.textContent = "Could not load the listing."; });
  });
  on("click", "[data-close]", (e, btn) => {
    const dlg = btn.closest("dialog");
    if (dlg) dlg.close();
  });

  /* A dialog that opens by itself: <dialog data-show-on-load>. A form inside
     it marked data-post-on-close is sent however the dialog closes — button,
     Esc or backdrop — with keepalive, so a close that navigates away still
     arrives. The patch notes are the first. */
  document.querySelectorAll("dialog[data-show-on-load]").forEach((dlg) => {
    const form = dlg.querySelector("form[data-post-on-close]");
    if (form) {
      dlg.addEventListener("close", () => {
        fetch(form.action, {
          method: "POST",
          body: new FormData(form),
          headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
          credentials: "same-origin",
          keepalive: true,
        }).catch(() => {});
      }, { once: true });
    }
    if (dlg.showModal) dlg.showModal();
  });

  /* A dialog the server asks to have open again after a reload: <dialog id="x" data-reopen>. Clicking its own opener
     rather than calling showModal() keeps whatever the opener does (the Running modal's lists are fetched on that click). */
  document.querySelectorAll("dialog[data-reopen][id]").forEach((dlg) => {
    // Deferred: the click handlers further down (the Running modal's fetches) are not registered yet, and a click
    // fired now would open the modal and never fill it.
    setTimeout(() => document.querySelector(`[data-open="#${dlg.id}"]`)?.click(), 0);
  });

  /* Sound on or off: <button data-sound-toggle="URL" aria-pressed="true|false" data-label-on data-label-off> holding
     [data-sound-glyph]. Pressing flips it, redraws the glyph and POSTs the choice to the account. Everything that
     plays a sound asks UI.soundOn() first, so the toggle is the one switch. */
  const soundBtn = document.querySelector("[data-sound-toggle]");
  const soundOn = () => soundBtn?.getAttribute("aria-pressed") !== "false";
  if (soundBtn) {
    soundBtn.addEventListener("click", () => {
      const on = !soundOn();
      soundBtn.setAttribute("aria-pressed", String(on));
      soundBtn.title = on ? soundBtn.dataset.labelOn : soundBtn.dataset.labelOff;
      const g = soundBtn.querySelector("[data-sound-glyph]");
      if (g) g.textContent = on ? "\u25C1))" : "\u25C1\u00D7";
      const token = document.querySelector('meta[name="csrf-token"]')?.content;
      fetch(soundBtn.dataset.soundToggle, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...(token ? { "X-CSRF-TOKEN": token } : {}) },
        credentials: "same-origin",
        body: JSON.stringify({ enabled: on }),
      }).catch(() => {});
    });
  }

  /* An iframe that exists only while its modal is open: <iframe data-embed="URL"> inside a <dialog>. The src is set on
     open and cleared on close, so nothing third-party loads unasked and closing stops what was playing. */
  document.querySelectorAll("dialog").forEach((dlg) => {
    const frame = dlg.querySelector("iframe[data-embed]");
    if (!frame) return;
    /* data-embed-shuffle: YouTube has no URL parameter for it, so once the player is up it is told, through the
       iframe API (the embed URL carries enablejsapi=1), to shuffle the playlist. Sent a few times because the player
       is not listening the moment `load` fires; shuffling twice before anything plays is harmless. */
    if (frame.hasAttribute("data-embed-shuffle")) {
      frame.addEventListener("load", () => {
        if (!frame.getAttribute("src")) return;
        const tell = (msg) => frame.contentWindow?.postMessage(JSON.stringify(msg), "https://www.youtube-nocookie.com");
        [0, 500, 1500].forEach((ms) => setTimeout(() => {
          tell({ event: "listening", id: 1, channel: "widget" });
          tell({ event: "command", func: "setShuffle", args: [true] });
        }, ms));
      });
    }
    new MutationObserver(() => {
      if (dlg.open) { if (!frame.getAttribute("src")) frame.src = frame.dataset.embed; }
      else frame.removeAttribute("src");
    }).observe(dlg, { attributes: true, attributeFilter: ["open"] });
  });

  /* The inbox, polled: <a data-inbox="URL" data-inbox-latest="ID" data-inbox-sound="SRC"> in the rail. Every five
     seconds it asks for how many messages are unread and the id of the newest, and redraws the badge. A message
     NEWER than the last this page knew of plays the sound, at most once in ten seconds; that moment is kept in
     localStorage so moving between pages does not start the count again. Browsers refuse sound before the person has
     touched the page, and that refusal is swallowed: the badge still moves. */
  (function inbox() {
    const link = document.querySelector("a[data-inbox]");
    if (!link) return;

    const INTERVAL = 5000, COOLDOWN = 10000, KEY = "inbox.lastSound";
    let latest = Number(link.dataset.inboxLatest) || 0;
    const audio = link.dataset.inboxSound ? new Audio(link.dataset.inboxSound) : null;

    const lastPlayed = () => { try { return Number(localStorage.getItem(KEY)) || 0; } catch { return 0; } };
    const stamp = () => { try { localStorage.setItem(KEY, String(Date.now())); } catch { /* the cooldown is per page then */ } };

    // Ember while a Priority message is unread, grey when only standard mail is.
    const badge = (unread, priority) => {
      let el = link.querySelector(".dock__count");
      if (unread <= 0) { el?.remove(); return; }
      if (!el) {
        el = document.createElement("span");
        el.className = "dock__count";
        link.querySelector(".dock__icon")?.after(el);
      }
      el.classList.toggle("dock__count--quiet", priority <= 0);
      el.textContent = unread > 99 ? "99+" : String(unread);
    };

    // The chat's count rides on the same poll: one timer for both.
    const chatBadge = (n) => {
      const btn = document.querySelector("[data-chat-badge]");
      if (!btn) return;
      let el = btn.querySelector(".dock__count");
      if (n <= 0) { el?.remove(); return; }
      if (!el) {
        el = document.createElement("span");
        el.className = "dock__count";
        btn.querySelector(".dock__icon")?.after(el);
      }
      el.textContent = n > 99 ? "99+" : String(n);
    };

    const ding = () => {
      if (!audio || !soundOn() || Date.now() - lastPlayed() < COOLDOWN) return;
      stamp();
      audio.currentTime = 0;
      audio.play().catch(() => {});
    };

    const poll = () => {
      fetch(link.dataset.inbox, { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }, credentials: "same-origin" })
        .then((r) => (r.ok ? r.json() : Promise.reject()))
        .then((data) => {
          badge(Number(data.unread) || 0, Number(data.priority) || 0);
          chatBadge(Number(data.chat) || 0);
          if (Number(data.latest) > latest) { latest = Number(data.latest); ding(); }
        })
        .catch(() => {});
    };

    setInterval(poll, INTERVAL);
    // Reading a message on the inbox page asks at once, so the badge's colour follows.
    document.addEventListener("inbox:refresh", poll);
  })();

  /* The training plan's level picker: <form data-plan data-plan-floors='{"SKILL_ID": FLOOR}' data-plan-max="5"
     data-plan-label="Level :level"> holding select[data-plan-skill] and select[data-plan-level]. Choosing a skill redraws
     the levels to those ABOVE what that skill already has reached or queued, the highest selected. */
  document.querySelectorAll("form[data-plan]").forEach((form) => {
    const skill = form.querySelector("select[data-plan-skill]");
    const level = form.querySelector("select[data-plan-level]");
    if (!skill || !level) return;
    const floors = JSON.parse(form.dataset.planFloors || "{}");
    const max = Number(form.dataset.planMax);
    const redraw = () => {
      level.innerHTML = "";
      for (let l = (floors[skill.value] || 0) + 1; l <= max; l++) {
        const option = document.createElement("option");
        option.value = String(l);
        option.textContent = form.dataset.planLabel.replace(":level", l);
        option.selected = l === max;
        level.appendChild(option);
      }
    };
    skill.addEventListener("change", redraw);
  });

  /* The Running modal's "Could run" list: <div data-startable="URL"> is filled with the server's fragment each time
     the modal is opened from [data-open="#processes"], so it is never older than the click. */
  document.addEventListener("click", (e) => {
    if (!e.target.closest('[data-open="#processes"]')) return;
    const box = document.querySelector("[data-startable]");
    if (!box) return;
    fetch(box.dataset.startable, { headers: { "X-Requested-With": "XMLHttpRequest" }, credentials: "same-origin" })
      .then((r) => (r.ok ? r.text() : Promise.reject()))
      .then((html) => { box.innerHTML = html; })
      .catch(() => { box.textContent = "Could not load the list."; });
  });

  /* THE OVERVIEW MAP: the opener's data-overview is the JSON (size, cells [x, y, type], portal, you). One 10px cell per
     place on a canvas, coloured by the map's own tokens; you are ember. The view scrolls toward whichever edge or corner
     the cursor is near, and opens centred on you. */
  const OVERVIEW_CELL = 10;
  let overviewPulse = null;
  let overviewAddress = new Map();
  let overviewSystem = new Map();
  let overviewHover = null;
  const OVERVIEW_TOKEN = { silo: "--steel", compute: "--cyan", relay: "--jade", exchange: "--brass", fortress: "--ember", gateway: "--brass", portal: "--brass" };
  on("click", "[data-overview]", (e, btn) => {
    const view = document.querySelector("[data-overview-view]");
    const canvas = view?.querySelector("canvas");
    if (!canvas) return;
    fetch(btn.dataset.overview, { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }, credentials: "same-origin" })
      .then((r) => r.json())
      .then((d) => {
        document.querySelector("[data-overview-title]").textContent = d.title;
        const css = getComputedStyle(document.documentElement);
        const tok = (name) => css.getPropertyValue(name).trim();
        const px = d.size * OVERVIEW_CELL;
        canvas.width = px;
        canvas.height = px;
        const ctx = canvas.getContext("2d");
        const cell = (x, y, colour) => {
          ctx.fillStyle = colour;
          ctx.fillRect(x * OVERVIEW_CELL, y * OVERVIEW_CELL, OVERVIEW_CELL, OVERVIEW_CELL);
        };
        // Ground never looked at is the darker grey, ground swept with nothing on it a little lighter.
        const unexplored = tok("--bg-1");
        const empty = tok("--bg-4");
        d.swept.forEach((row, y) => [...row].forEach((bit, x) => cell(x, y, bit === "1" ? empty : unexplored)));
        cell(d.portal[0], d.portal[1], tok(OVERVIEW_TOKEN.portal));
        d.cells.forEach(([x, y, type]) => cell(x, y, tok(OVERVIEW_TOKEN[type] || "--ink-2")));
        overviewAddress = new Map(d.cells.map(([x, y, , address]) => [`${x},${y}`, address]));
        overviewSystem = new Map(d.cells.map(([x, y, , , name, grade]) => [`${x},${y}`, { name, grade }]));
        const at = d.you || d.portal;
        view.scrollLeft = (at[0] + 0.5) * OVERVIEW_CELL - view.clientWidth / 2;
        view.scrollTop = (at[1] + 0.5) * OVERVIEW_CELL - view.clientHeight / 2;
        // While the map is open, where you stand pulses white and black (quickly) and exchanges and gateways brass and ember (2 seconds a cycle).
        clearInterval(overviewPulse);
        const ember = tok("--ember");
        const washed = [
          [d.cells.filter((c) => c[2] === "exchange"), 0],
          // Gateways run the same wash half a cycle behind, so the two read apart.
          [d.cells.filter((c) => c[2] === "gateway"), Math.PI],
        ];
        const brass = tok(OVERVIEW_TOKEN.exchange);
        const white = tok("--ink-0");
        const dark = tok("--bg-0");
        let n = 0;
        overviewPulse = setInterval(() => {
          if (!view.closest("dialog")?.open) { clearInterval(overviewPulse); return; }
          n++;
          washed.forEach(([list, phase]) => {
            ctx.globalAlpha = 1;
            list.forEach(([x, y]) => cell(x, y, brass));
            ctx.globalAlpha = (Math.sin((n * Math.PI * 2) / 25 + phase) + 1) / 2;
            list.forEach(([x, y]) => cell(x, y, ember));
            ctx.globalAlpha = 1;
          });
          // After the washes, or standing on an exchange or gateway they repaint the cell and hide it.
          if (d.you) cell(d.you[0], d.you[1], Math.floor(n / 3) % 2 ? white : dark);
        }, 80);
      });
  });
  /* Pointing at a system floats its name, its grade (when a scan has found it out) and where it is: the inspector's layer,
     placed over the cell. Nothing for ground with no system on it. */
  on("pointermove", "[data-overview-view] canvas", (e, canvas) => {
    const r = canvas.getBoundingClientRect();
    const x = Math.floor(((e.clientX - r.left) * canvas.width) / r.width / OVERVIEW_CELL);
    const y = Math.floor(((e.clientY - r.top) * canvas.height) / r.height / OVERVIEW_CELL);
    const key = `${x},${y}`;
    const system = overviewSystem.get(key);
    if (!system) { overviewHover = null; uninspect(); return; }
    if (overviewHover === key && layer?.hasAttribute("data-shown")) return;
    overviewHover = key;
    const label = canvas.closest("[data-overview-view]").dataset.gradeLabel;
    const line = (text) => { const p = document.createElement("div"); p.className = "item-card__stat"; p.textContent = text; return p; };
    const card = document.createElement("div");
    card.className = "item-card";
    const hd = document.createElement("div");
    hd.className = "item-card__hd";
    const name = document.createElement("span");
    name.className = "item-card__name";
    name.textContent = system.name;
    hd.appendChild(name);
    const bd = document.createElement("div");
    bd.className = "item-card__stats";
    if (system.grade !== null) bd.appendChild(line(`${label} ${system.grade}`));
    bd.appendChild(line(`${x}, ${y}`));
    card.append(hd, bd);
    const cell = new DOMRect(
      r.left + (x * OVERVIEW_CELL * r.width) / canvas.width,
      r.top + (y * OVERVIEW_CELL * r.height) / canvas.height,
      (OVERVIEW_CELL * r.width) / canvas.width,
      (OVERVIEW_CELL * r.height) / canvas.height,
    );
    inspect(cell, card);
  });
  on("pointerout", "[data-overview-view] canvas", () => { overviewHover = null; uninspect(); });
  /* Clicking a cell copies the address of the system there, or its coordinates when there is none (unexplored or empty). */
  on("click", "[data-overview-view] canvas", async (e, canvas) => {
    const view = canvas.closest("[data-overview-view]");
    const note = document.querySelector("[data-overview-note]");
    const r = canvas.getBoundingClientRect();
    const x = Math.floor(((e.clientX - r.left) * canvas.width) / r.width / OVERVIEW_CELL);
    const y = Math.floor(((e.clientY - r.top) * canvas.height) / r.height / OVERVIEW_CELL);
    const address = overviewAddress.get(`${x},${y}`);
    const value = address || `${x},${y}`;
    let message;
    try {
      await navigator.clipboard.writeText(value);
      message = (address ? view.dataset.copiedAddress : view.dataset.copiedCoords).replace(":value", value);
    } catch {
      message = view.dataset.copyFailed;
    }
    note.textContent = message;
    clearTimeout(note._restore);
    note._restore = setTimeout(() => { note.textContent = note.dataset.note; }, 3000);
  });
  (() => {
    const EDGE = 0.2;
    const SPEED = 14;
    let view = null;
    let dx = 0;
    let dy = 0;
    let running = false;
    const tick = () => {
      if (!view || !(dx || dy)) { running = false; return; }
      view.scrollLeft += dx;
      view.scrollTop += dy;
      requestAnimationFrame(tick);
    };
    // Pushing harder the nearer the edge: 0 at the edge's inner line, full at the edge itself.
    const push = (pos, size) => {
      const f = pos / size;
      if (f < EDGE) return -SPEED * (1 - f / EDGE);
      if (f > 1 - EDGE) return SPEED * (1 - (1 - f) / EDGE);
      return 0;
    };
    document.addEventListener("mousemove", (e) => {
      view = e.target.closest?.("[data-overview-view]");
      if (!view) { dx = dy = 0; return; }
      const r = view.getBoundingClientRect();
      dx = push(e.clientX - r.left, r.width);
      dy = push(e.clientY - r.top, r.height);
      if ((dx || dy) && !running) { running = true; requestAnimationFrame(tick); }
    });
    document.addEventListener("mouseleave", () => { dx = dy = 0; });
  })();
  /* A modal body that is fetched each time it opens: <div data-fragment="URL"> inside the <dialog> that the opener
     [data-open="#id"] names. Never older than the click. */
  document.addEventListener("click", (e) => {
    const opener = e.target.closest("[data-open]");
    if (!opener) return;
    const box = document.querySelector(opener.dataset.open + " [data-fragment]");
    if (!box) return;
    // [data-fragment-remember="key"] on the box: it opens with the filter last used (kept in localStorage).
    let saved = "";
    if (box.dataset.fragmentRemember) { try { saved = localStorage.getItem("fragment." + box.dataset.fragmentRemember) || ""; } catch { /* no memory then */ } }
    fetch(box.dataset.fragment + (saved ? "?" + saved : ""), { headers: { "X-Requested-With": "XMLHttpRequest" }, credentials: "same-origin" })
      .then((r) => (r.ok ? r.text() : Promise.reject()))
      .then((html) => { box.innerHTML = html; })
      .catch(() => { box.textContent = "Could not load the list."; });
  });

  /* A fragment that filters itself: a form[data-fragment-filter] inside a [data-fragment] box. Any change refetches the
     fragment with the form's values as the query string and swaps the box's contents, so the filters, the options they
     offer and the rows all stay the server's. */
  document.addEventListener("change", (e) => {
    const form = e.target.closest("form[data-fragment-filter]");
    const box = form?.closest("[data-fragment]");
    if (!form || !box) return;
    const params = new URLSearchParams(new FormData(form));
    if (box.dataset.fragmentRemember) { try { localStorage.setItem("fragment." + box.dataset.fragmentRemember, params.toString()); } catch { /* not remembered */ } }
    fetch(box.dataset.fragment + "?" + params.toString(), { headers: { "X-Requested-With": "XMLHttpRequest" }, credentials: "same-origin" })
      .then((r) => (r.ok ? r.text() : Promise.reject()))
      .then((html) => { box.innerHTML = html; })
      .catch(() => { box.textContent = "Could not load the list."; });
  });

  /* A sortable list that saves itself: <div data-queue-sort="URL"> of [data-queue-row="ID"], each with a
     [data-queue-handle]. Pointer events rather than HTML5 drag, which a phone does not do. Dragging moves
     the row in the DOM as you go; letting go POSTs the whole order as ids. A refusal (422) snaps the rows
     back and says why; success reloads, because the positions, estimates and warnings are the server's.
     ArrowUp / ArrowDown on a focused handle do the same one step at a time. */
  document.querySelectorAll("[data-queue-sort]").forEach((list) => {
    const rows = () => [...list.querySelectorAll(":scope > [data-queue-row]")];
    const order = () => rows().map((r) => r.dataset.queueRow);

    const save = (before, focusId) => {
      if (order().join() === before.join()) return;
      const token = document.querySelector('meta[name="csrf-token"]')?.content;
      fetch(list.dataset.queueSort, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...(token ? { "X-CSRF-TOKEN": token } : {}) },
        credentials: "same-origin",
        body: JSON.stringify({ order: order().map(Number) }),
      }).then(async (r) => {
        if (r.ok) { location.reload(); return; }
        const body = await r.json().catch(() => ({}));
        before.forEach((id) => list.appendChild(list.querySelector('[data-queue-row="' + id + '"]')));
        // A toast, not the page's flash: it is fixed to the screen, and a refusal that lands off-screen at the top of a
        // scrolled page is a refusal nobody sees.
        toast(body.message || "Could not reorder.", { level: "warn", timeout: 9000 });
        if (focusId) list.querySelector('[data-queue-row="' + focusId + '"] [data-queue-handle]')?.focus();
      }).catch(() => {
        before.forEach((id) => list.appendChild(list.querySelector('[data-queue-row="' + id + '"]')));
        toast("Could not reorder.", { level: "warn" });
      });
    };

    list.addEventListener("pointerdown", (e) => {
      const handle = e.target.closest("[data-queue-handle]");
      if (!handle || e.button > 0) return;
      const row = handle.closest("[data-queue-row]");
      const before = order();
      row.classList.add("is-dragging");

      // On the WINDOW, not the handle: moving the row in the DOM drops pointer capture from the
      // element inside it, and the pointerup would then never arrive to save anything.
      const move = (ev) => {
        const siblings = rows().filter((r) => r !== row);
        const next = siblings.find((r) => ev.clientY < r.getBoundingClientRect().top + r.offsetHeight / 2);
        if (next) { if (row.nextElementSibling !== next) list.insertBefore(row, next); }
        else if (list.lastElementChild !== row) list.appendChild(row);
      };
      const end = () => {
        window.removeEventListener("pointermove", move);
        window.removeEventListener("pointerup", end);
        window.removeEventListener("pointercancel", end);
        row.classList.remove("is-dragging");
        save(before, row.dataset.queueRow);
      };
      window.addEventListener("pointermove", move);
      window.addEventListener("pointerup", end);
      window.addEventListener("pointercancel", end);
      e.preventDefault();
    });

    list.addEventListener("keydown", (e) => {
      const handle = e.target.closest("[data-queue-handle]");
      if (!handle || (e.key !== "ArrowUp" && e.key !== "ArrowDown")) return;
      const row = handle.closest("[data-queue-row]");
      const before = order();
      if (e.key === "ArrowUp" && row.previousElementSibling) list.insertBefore(row, row.previousElementSibling);
      else if (e.key === "ArrowDown" && row.nextElementSibling) list.insertBefore(row.nextElementSibling, row);
      else return;
      e.preventDefault();
      handle.focus();
      save(before, row.dataset.queueRow);
    });
  });

  /* Select all: <input type="checkbox" data-select-all="FORM-ID"> ticks and unticks every
     <input data-select-row form="FORM-ID">, and follows them back: it is ticked while all are
     and clear while any is not. */
  document.querySelectorAll("input[data-select-all]").forEach((all) => {
    const rows = () => document.querySelectorAll('input[data-select-row][form="' + all.dataset.selectAll + '"]');
    all.addEventListener("change", () => rows().forEach((r) => { r.checked = all.checked; }));
    document.addEventListener("change", (e) => {
      if (e.target.matches("input[data-select-row]")) all.checked = [...rows()].every((r) => r.checked);
    });
  });

  /* A fold that remembers: <details data-remember="key"> keeps its open state in this browser across page loads (the
     run screen reloads after every turn). Storage that fails leaves the markup's own default. */
  document.querySelectorAll("details[data-remember]").forEach((d) => {
    const key = "fold:" + d.dataset.remember;
    try {
      const saved = localStorage.getItem(key);
      if (saved !== null) d.open = saved === "1";
    } catch (e) { /* the default stands */ }
    d.addEventListener("toggle", () => {
      try { localStorage.setItem(key, d.open ? "1" : "0"); } catch (e) { /* not remembered */ }
    });
  });

  /* Unfolding an inbox message reads it: <details data-mark-read="URL">. Once, on the first
     open, with keepalive like the patch notes. The row quietens and the rail's count comes
     down, so the page agrees with the server without a reload. */
  document.querySelectorAll("details[data-mark-read]:not([data-read])").forEach((d) => {
    d.addEventListener("toggle", () => {
      if (!d.open || d.hasAttribute("data-read")) return;
      d.setAttribute("data-read", "");
      const token = document.querySelector('meta[name="csrf-token"]')?.content;
      fetch(d.dataset.markRead, {
        method: "POST",
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...(token ? { "X-CSRF-TOKEN": token } : {}) },
        credentials: "same-origin",
        keepalive: true,
      }).then((r) => {
        if (!r.ok) return;
        d.querySelector("[data-message-new]")?.remove();
        const title = d.querySelector("[data-message-title]");
        if (title) title.style.color = "var(--ink-3)";
        const badge = document.querySelector(".dock__count");
        if (badge) {
          const left = parseInt(badge.textContent, 10) - 1;
          if (Number.isNaN(left)) return;
          if (left > 0) badge.textContent = String(left); else badge.remove();
        }
        document.dispatchEvent(new Event("inbox:refresh"));
      }).catch(() => {});
    });
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
    // An element, or a plain rect for things with no element of their own (a cell on a canvas).
    const a = anchorEl.getBoundingClientRect ? anchorEl.getBoundingClientRect() : anchorEl;
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

  function inspect(el, given) {
    const body = given || detail(el);
    if (!body) return;
    if (!layer) {
      layer = document.createElement("div");
      layer.className = "inspector";
      layer.setAttribute("role", "tooltip");
      // In the top layer, so a card pointed at from inside a modal (the known systems) is not hidden under it.
      if ("showPopover" in layer) layer.setAttribute("popover", "manual");
      document.body.appendChild(layer);
    }
    layer.replaceChildren(body);
    layer.hidden = false;
    if (layer.hasAttribute("popover") && !layer.matches(":popover-open")) layer.showPopover();
    place(el);
    layer.setAttribute("data-shown", "");
  }

  function uninspect() {
    if (!layer) return;
    layer.removeAttribute("data-shown");
    layer.hidden = true;
    if (layer.hasAttribute("popover") && layer.matches(":popover-open")) layer.hidePopover();
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

  // data-inspect-hover: pointing and keyboard focus only. For a thing whose click already DOES
  // something (a submit button), a modal on top of the action would be the wrong second effect.
  on("click", "[data-inspect]", (e, el) => {
    if (el.hasAttribute("data-inspect-hover")) return;
    const body = detail(el);
    if (body) openInspector(body);
  });

  /* --- Filter ---------------------------------------------------------
     <input type="search" data-filter="#list">
     <div id="list">
       <details data-filter-group> … <div data-filter-text="words to match">…
       <p data-filter-empty hidden>Nothing matches.</p>

     Hides every [data-filter-text] under the target whose words do not contain
     what was typed, and every group left with nothing showing. While there is
     a query a group with a match is opened; clearing the box puts each group
     back the way the player left it. Matching only, never fetching: whatever
     the filter can find is already on the page. */
  on("input", "[data-filter]", (e, box) => {
    const root = document.querySelector(box.dataset.filter);
    if (!root) return;
    const q = box.value.trim().toLowerCase();

    root.querySelectorAll("[data-filter-text]").forEach((el) => {
      el.hidden = q !== "" && !el.dataset.filterText.toLowerCase().includes(q);
    });

    root.querySelectorAll("[data-filter-group]").forEach((g) => {
      g.hidden = !g.querySelector("[data-filter-text]:not([hidden])");
      if (g.tagName !== "DETAILS") return;
      if (q !== "") {
        if (!("filterWasOpen" in g.dataset)) g.dataset.filterWasOpen = g.open ? "1" : "";
        g.open = true;
      } else if ("filterWasOpen" in g.dataset) {
        g.open = g.dataset.filterWasOpen === "1";
        delete g.dataset.filterWasOpen;
      }
    });

    const empty = root.querySelector("[data-filter-empty]");
    if (empty) empty.hidden = !!root.querySelector("[data-filter-text]:not([hidden])");
  });

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

  /* A toast the server asked for with its redirect: <div data-toast="words" data-toast-level="warn" hidden>. Shown once. */
  function serverToasts() {
    document.querySelectorAll("[data-toast]").forEach((el) => {
      if (el.dataset.shown) return;
      el.dataset.shown = "1";
      toast(el.dataset.toast, { level: el.dataset.toastLevel || "", timeout: 9000 });
    });
  }
  serverToasts();
  document.addEventListener("DOMContentLoaded", serverToasts);

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
        list.lastElementChild?.querySelector("select:not([hidden])")?.focus();
      });

      field.addEventListener("click", (e) => {
        const dupe = e.target.closest("[data-modifier-duplicate]");
        if (dupe && field.contains(dupe)) {
          const row = dupe.closest("[data-modifier-row]");
          const copy = row.cloneNode(true);
          // cloneNode keeps attributes, not what was typed or chosen since.
          const live = row.querySelectorAll("input, select");
          copy.querySelectorAll("input, select").forEach((el, i) => { el.value = live[i].value; });
          row.after(copy);
          reindex();
          copy.querySelectorAll("[data-target-picker]").forEach(targetLabel);
          copy.querySelector('[data-modifier-field="level"], [data-modifier-field="amount"]')?.focus();
          return;
        }
        const remove = e.target.closest("[data-modifier-remove]");
        if (!remove || !field.contains(remove)) return;
        remove.closest("[data-modifier-row]")?.remove();
        reindex();
      });

      reindex();
    });
  }

  /* The target picker: the <select> beside it holds the value, this sets it.
     Everything is delegated, so rows cloned from the template or by Duplicate
     work with no wiring; the label is re-read from the select each time. */
  function targetLabel(picker) {
    const select = picker.parentElement.querySelector("select");
    const label = picker.querySelector("[data-target-label]");
    if (!select || !label) return;
    label.textContent = select.selectedOptions[0]?.textContent.trim() || "";
    picker.querySelectorAll("[data-target-pick]").forEach((b) => {
      b.setAttribute("aria-pressed", b.dataset.targetPick === select.value ? "true" : "false");
    });
  }

  on("click", "[data-target-pick]", (e, btn) => {
    const picker = btn.closest("[data-target-picker]");
    picker.parentElement.querySelector("select").value = btn.dataset.targetPick;
    targetLabel(picker);
    picker.open = false;
  });

  document.addEventListener("input", (e) => {
    const box = e.target.closest?.("[data-target-filter]");
    if (!box) return;
    const q = box.value.trim().toLowerCase();
    box.closest("[data-target-picker]").querySelectorAll(".target-picker__group").forEach((group) => {
      let any = false;
      group.querySelectorAll("[data-target-pick]").forEach((b) => {
        const hit = !q || b.textContent.toLowerCase().includes(q);
        b.hidden = !hit;
        any = any || hit;
      });
      group.hidden = !any;
      if (q) group.open = any;
    });
  });

  function targetPickers() { document.querySelectorAll("[data-target-picker]").forEach(targetLabel); }
  targetPickers();
  document.addEventListener("DOMContentLoaded", targetPickers);

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

  /* --- Sounds: a click on every button, and the scan's ping -------------------
     <body data-click-sounds='["url", ...]' data-scan-sound="url" data-checkbox-sound="url" data-scanwindow-sound="url" data-run-sound="url" data-ice-sound="url" data-jack-sound="url" data-jack-in-sound="url" data-failure-sound="url" data-success-sound="url" data-noise-sound="url">: fetched and decoded ONCE into memory (an
     <audio> element would fetch and decode on its first play, which is the lag). A press then starts a random click from the
     buffer. THE SMALL ONES (clicks, cues) are fetched once the page has settled, or at the first press if that comes first; the
     two beds (the scan window's and the run screen's, most of the weight) only when something asks for one. Decoded on an OfflineAudioContext so nothing waits for a gesture, played on a real one made at the first. Mouse and
     touch play on pointerdown, the earliest moment; a keyboard press (a click with no pointer, detail 0) plays on click.
     sfx.scan() is the ping for a read that has found something or a map that has: it waits for its buffer if the page has only
     just loaded, and gives up on a browser that will not let a page play before it has been touched. */
  const sfx = (function sounds() {
    const none = { scan() {}, ice() {}, failure() {}, success: () => 0, noise() {}, jack: () => 0, jackIn: () => 0, loopIn() {}, loopOut() {}, runOut: () => false };
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx || !window.OfflineAudioContext) return none;
    let clicks = [];
    try { clicks = JSON.parse(document.body.dataset.clickSounds || "[]"); } catch (e) { /* none */ }
    const decoder = new OfflineAudioContext(1, 1, 44100);
    const load = (url) => fetch(url).then((r) => r.arrayBuffer()).then((raw) => decoder.decodeAudioData(raw));
    const buffers = [];
    const queued = [];
    const later = (fn) => queued.push(fn);
    const flush = () => { while (queued.length) queued.shift()(); };
    const settled = () => ("requestIdleCallback" in window ? requestIdleCallback(flush, { timeout: 4000 }) : setTimeout(flush, 2000));
    document.readyState === "complete" ? settled() : window.addEventListener("load", settled, { once: true });
    ["pointerdown", "keydown"].forEach((type) => window.addEventListener(type, flush, { once: true, capture: true }));
    let ctx = null;
    const context = () => {
      ctx = ctx || new Ctx();
      return ctx;
    };
    const start = (buffer) => {
      const c = context();
      const src = c.createBufferSource();
      src.buffer = buffer;
      src.connect(c.destination);
      src.start();
      return buffer.duration * 1000;
    };
    // A cue: a sound announcing something that has just happened, played once. Waits for its buffer if the page has only just
    // loaded; on a page the browser will not yet let play, it gives up rather than sounding long after what it announces.
    const cue = (url) => {
      let buffer = null;
      let wanted = false;
      let started = false;
      // Fetched when the page has settled, or the moment it is wanted, whichever is first.
      const fetchIt = () => {
        if (started || !url) return;
        started = true;
        load(url).then((b) => { buffer = b; if (wanted) { wanted = false; play(); } }).catch(() => {});
      };
      // Returns how long the sound will run, in ms, when it starts at once; 0 when it does not (or later).
      const play = () => {
        if (!soundOn()) return 0;
        if (!buffer) { wanted = true; fetchIt(); return 0; }
        const c = context();
        if (c.state === "running") return start(buffer);
        const asked = performance.now();
        c.resume().then(() => { if (performance.now() - asked < 300 && c.state === "running") start(buffer); }).catch(() => {});
        return 0;
      };
      later(fetchIt);
      return play;
    };
    const scan = cue(document.body.dataset.scanSound);
    const ice = cue(document.body.dataset.iceSound);
    const failure = cue(document.body.dataset.failureSound);
    const success = cue(document.body.dataset.successSound);
    const noise = cue(document.body.dataset.noiseSound);
    const jack = cue(document.body.dataset.jackSound);
    const jackIn = cue(document.body.dataset.jackInSound);
    later(() => clicks.forEach((url) => load(url).then((b) => buffers.push(b)).catch(() => {})));
    const click = (e) => {
      const hit = e.target.closest?.("button, .btn, [role=button], input[type=submit]");
      if (!hit || hit.disabled || hit.getAttribute("aria-disabled") === "true" || !buffers.length || !soundOn()) return;
      if (context().state === "suspended") context().resume();
      start(buffers[Math.floor(Math.random() * buffers.length)]);
    };
    document.addEventListener("pointerdown", (e) => { if (e.button === 0) click(e); }, true);
    document.addEventListener("click", (e) => { if (e.detail === 0) click(e); }, true);
    // A checkbox or a radio the player flipped (a change nobody made, such as a script ticking a box, is silent).
    if (document.body.dataset.checkboxSound) {
      let tick = null;
      later(() => load(document.body.dataset.checkboxSound).then((b) => { tick = b; }).catch(() => {}));
      document.addEventListener("change", (e) => {
        if (tick && e.isTrusted && e.target.matches?.("input[type=checkbox], input[type=radio]") && soundOn()) {
          if (context().state === "suspended") context().resume();
          start(tick);
        }
      }, true);
    }
    /* A bed: one buffer source on loop (sample-accurate, so no gap at the seam), behind a gain that ramps 0 -> 1 over FADE
       seconds as it comes in and 1 -> 0 as it goes out, when the source is stopped and let go. The scan window has one, and the
       run screen another. */
    const FADE = 0.5;
    const beds = [];
    const bed = (url) => {
      let buffer = null;
      let wanted = false;
      let playing = null;
      let started = false;
      // A bed is the heavy part: fetched only when something wants it.
      const fetchBed = () => {
        if (started || !url) return;
        started = true;
        load(url).then((b) => { buffer = b; up(); }).catch(() => {});
      };
      const up = () => {
        if (!wanted || playing || !soundOn()) return;
        if (!buffer) { fetchBed(); return; }
        const c = context();
        const go = () => {
          if (!wanted || playing || c.state !== "running") return;
          const src = c.createBufferSource();
          const gain = c.createGain();
          src.buffer = buffer;
          src.loop = true;
          gain.gain.setValueAtTime(0, c.currentTime);
          gain.gain.linearRampToValueAtTime(1, c.currentTime + FADE);
          src.connect(gain).connect(c.destination);
          src.start();
          playing = { src, gain };
        };
        c.state === "running" ? go() : c.resume().then(go).catch(() => {});
      };
      const out = () => {
        wanted = false;
        if (!playing) return;
        const { src, gain } = playing;
        const c = context();
        playing = null;
        // Read where the fade-in has got to BEFORE cancelling it: a cancelled ramp leaves the gain at its resting 1.
        const level = gain.gain.value;
        gain.gain.cancelScheduledValues(c.currentTime);
        gain.gain.setValueAtTime(level, c.currentTime);
        gain.gain.linearRampToValueAtTime(0, c.currentTime + FADE);
        src.stop(c.currentTime + FADE);
        src.onended = () => gain.disconnect();
      };
      beds.push({ up, playing: () => playing, wanted: () => wanted });
      return { in() { wanted = true; up(); }, out, playing: () => !!playing };
    };
    // A bed that opens by itself (a scan just begun, or a run just started, reloads the page) has no press to start sound on,
    // so the first press or key anywhere while it is wanted does: the browser lets a page play only once it has been touched.
    ["pointerdown", "keydown"].forEach((type) => document.addEventListener(type, () => beds.forEach((b) => { if (b.wanted() && !b.playing()) b.up(); }), true));
    const scanBed = bed(document.body.dataset.scanwindowSound);
    // The run screen carries one for as long as it is up. Leaving the page ends it, as it ends everything on the page.
    const runBed = bed(document.body.dataset.runSound);
    if (document.querySelector("[data-run-watch]")) runBed.in();
    // A district or place carries its own, named by an element on the page.
    const placeBed = document.querySelector("[data-bed-sound]");
    if (placeBed) bed(placeBed.dataset.bedSound).in();
    const loopIn = scanBed.in;
    const loopOut = scanBed.out;
    // Leaving the run screen for another page: true when there was a loop to fade, so the caller waits out the fade.
    const runOut = () => { const was = runBed.playing(); runBed.out(); return was; };
    return { scan, ice, failure, success, noise, jack, jackIn, loopIn, loopOut, runOut };
  })();

  /* --- Watching a read ---------------------------------------------------
     A scan pass takes minutes, so the dialog that shows one has to keep up
     with it. It polls while it is open and stops the moment it is closed —
     there is nothing to watch behind a closed dialog, and a timer left running
     on a screen nobody is looking at is a request every few seconds for ever.

     The endpoint settles a matured pass on the way past, which is why the
     answers fill in as soon as one finishes rather than at the next reload. */
  function reading() {
    const dlg = document.querySelector("[data-reading]");
    // Once: this runs at load and again at DOMContentLoaded, and it registers document handlers, so a second run would answer
    // every press twice (Start scan posted the scan twice).
    if (!dlg || dlg.dataset.bound) return;
    dlg.dataset.bound = "1";

    let timer = null;
    let address = null;
    // The window is up but nothing has begun, because Start scan is waiting for its press: there is no pass to leave running or
    // to abandon, so the one way out is "Close window".
    let waiting = false;
    let heard = {};

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
          { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } }   // XHR: else Laravel keeps this JSON URL as the "previous page" a redirect back lands on
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
      dlg.querySelectorAll("[data-reading-abandon-address], [data-reading-export-address], [data-reading-jump-address]")
        .forEach((field) => { if (data.address) field.value = data.address; });
      set("instrument", data.instrument);
      set("pass", dlg.dataset.readingPass.replace(":pass", data.pass));
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
      /* A row shows only when this scan has the fact: the run tree's rows appear on a system that walks a tree. */
      const keys = (data.facts || []).map((fact) => fact.key);
      dlg.querySelectorAll("[data-reading-facts] [data-fact]").forEach((row) => { row.hidden = !keys.includes(row.dataset.fact); });

      // A pass that finished and revealed something, seen between two polls of the same read.
      const found = JSON.stringify((data.facts || []).map((fact) => [fact.key, fact.value]));
      if (heard.address === data.address && heard.found !== found) sfx.scan();
      heard = { address: data.address, found };

      (data.facts || []).forEach((fact) => {
        const value = dlg.querySelector('[data-fact="' + fact.key + '"] [data-fact-value]');
        if (!value || !fact.discoverable) return;

        const known = fact.value != null;
        // Brass: what the reader's runs saw of the tree, short of the whole of it.
        value.classList.toggle("tag--ember", known && !fact.partial);
        value.classList.toggle("tag--warn", known && !!fact.partial);

        const mark = known && fact.mark
          ? '<span class="' + (fact.mark === "ok" ? "mark--ok" : "mark--no") + '" title="' +
            esc(fact.mark === "ok" ? dlg.dataset.readingOsKnown : dlg.dataset.readingOsUnknown) +
            '">' + (fact.mark === "ok" ? "\u2713" : "\u2717") + "</span> "
          : "";

        value.innerHTML = mark + esc(known ? fact.value : dlg.dataset.readingUnread);
        // The Nodes: a count that opens the breakdown by kind (the [data-listing] handler fetches it).
        if (known && fact.more) {
          value.dataset.listing = fact.more;
          value.dataset.dialog = "#nodes-view";
          value.setAttribute("role", "button");
          value.tabIndex = 0;
          value.style.cursor = "pointer";
        }
      });

      // The tree, once the read has the Layout: the button fetches from where the poll says.
      dlg.querySelectorAll("[data-reading-tree]").forEach((box) => {
        box.hidden = !data.tree;
        const btn = box.querySelector("[data-listing]");
        if (btn && data.tree) btn.dataset.listing = data.tree;
      });

      /* A read goes round by itself until the file is whole or the file goes
         stale, so a stopped read that is not complete stopped for the one
         remaining reason: nothing is running at it. */
      const filename = (dlg.dataset.readingFilename || "").replace(":address", data.address || "");

      set("note", data.complete
        ? dlg.dataset.readingComplete.replace(":file", filename)
        : (data.running ? "" : dlg.dataset.readingIdle));

      /* Once the file is whole, Abandon and Run in background both describe
         something that is no longer happening. They go, and what takes their
         place is the only thing left to decide about a finished read: file it
         as something you can carry and sell, or shut the window. */
      const done = !!data.complete;
      dlg.querySelectorAll("[data-reading-abandon], [data-reading-background]")
        .forEach((el) => { el.hidden = done || (waiting && el.matches("[data-reading-background]")); });
      dlg.querySelectorAll("[data-reading-export], [data-reading-done]")
        .forEach((el) => { el.hidden = !(done || (waiting && el.matches("[data-reading-done]"))); });
      // A read that filed itself has nothing left to save.
      dlg.querySelectorAll("[data-reading-export]").forEach((el) => { el.hidden = !done || !!data.filed; });

      // Nothing left to watch: the read stopped, and the dialog stays open
      // showing what it found — it is the polling that stops.
      if (!data.running) stop();
    }

    // `touched`: the window was opened by a press, so the loop may start at once. Opened by itself (a scan just begun reloads
    // the page) it may not, and the Start scan button waits for the press instead. With sound off there is nothing to unlock,
    // so the button is not shown at all (and a Scan pressed with sound off begins the pass at once, in the scan-open handler).
    function start(target, touched) {
      stop();
      const startBtn = dlg.querySelector("[data-reading-start]");
      const below = dlg.querySelector("[data-reading-below]");
      if (touched || !startBtn || !soundOn()) {
        waiting = false;
        startBtn?.remove();
        if (below) below.inert = false;
        sfx.loopIn();
      } else {
        waiting = true;
        startBtn.hidden = false;
        if (below) below.inert = true;
      }
      address = target;
      // The forms need the address before the first poll comes back: abandoning during a slow first load posted none (a 400).
      dlg.querySelectorAll("[data-reading-abandon-address], [data-reading-export-address], [data-reading-jump-address]")
        .forEach((field) => { field.value = target; });
      refresh();
      timer = setInterval(refresh, 5000);
    }

    function stop() {
      if (timer) clearInterval(timer);
      timer = null;
    }

    dlg.addEventListener("close", () => {
      stop();
      sfx.loopOut();
      pending = null;
      // A scan begun from this window: the screen behind it still shows the Scan button. After the fade, so it is heard out.
      if (dlg.dataset.started) {
        delete dlg.dataset.started;
        setTimeout(() => location.reload(), 550);
      }
    });

    /* THE SCAN WINDOW, BEFORE ANYTHING IS SPENT. The Scan button opens it on what the page already knows (target, place,
       instrument) with everything beneath greyed; Start scan is the press that begins the pass (posted from here, answered in
       JSON) and, being a press, lets the loop play. Nothing is begun on the server until then, and closing the window first
       leaves it all as it was. Once begun the page is reloaded when the window closes, so the screen behind it says so. */
    let pending = null;
    on("click", "[data-scan-open]", (e, btn) => {
      pending = { url: btn.dataset.scanOpen, address: btn.dataset.scanAddress };
      set("name", btn.dataset.scanName);
      set("address", btn.dataset.scanAddress);
      set("position", btn.dataset.scanPosition);
      set("instrument", btn.dataset.scanInstrument);
      set("pass", "—");
      set("ready", "");
      set("note", "");
      const bar = dlg.querySelector("[data-reading-bar]");
      if (bar) bar.style.setProperty("--v", 0);
      dlg.querySelectorAll("[data-reading-facts] [data-fact]").forEach((row) => {
        const value = row.querySelector("[data-fact-value]");
        if (value) { value.className = "tag"; value.textContent = dlg.dataset.readingUnread; }
      });
      const addr = dlg.querySelector("[data-reading-address]");
      if (addr) addr.dataset.copy = btn.dataset.scanAddress;
      waiting = true;
      dlg.querySelectorAll("[data-reading-abandon], [data-reading-export], [data-reading-background]").forEach((el) => { el.hidden = true; });
      dlg.querySelectorAll("[data-reading-done]").forEach((el) => { el.hidden = false; });
      const startBtn = dlg.querySelector("[data-reading-start]");
      if (startBtn) { startBtn.hidden = false; startBtn.disabled = false; }
      const below = dlg.querySelector("[data-reading-below]");
      if (below) below.inert = true;
      if (dlg.showModal && !dlg.open) dlg.showModal();
      // Sound off: there is no sound for the press to unlock, so the pass begins at once, by the same handler Start scan runs.
      if (!soundOn() && startBtn) {
        waiting = false;
        startBtn.hidden = true;
        dlg.querySelectorAll("[data-reading-background]").forEach((el) => { el.hidden = false; });
        dlg.querySelectorAll("[data-reading-done]").forEach((el) => { el.hidden = true; });
        startBtn.click();
      }
    });

    on("click", "[data-reading-start]", async (e, btn) => {
      // Opened by itself after a reload (no JS scan to begin): the pass is already running, so this only starts the sound.
      if (!pending) {
        waiting = false;
        dlg.querySelectorAll("[data-reading-background]").forEach((el) => { el.hidden = false; });
        dlg.querySelectorAll("[data-reading-done]").forEach((el) => { el.hidden = true; });
        btn.remove();
        dlg.querySelector("[data-reading-below]")?.removeAttribute("inert");
        sfx.loopIn();
        return;
      }
      btn.disabled = true;
      const token = document.querySelector('meta[name="csrf-token"]')?.content;
      let answer = null;
      try {
        const res = await fetch(pending.url, {
          method: "POST",
          headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...(token ? { "X-CSRF-TOKEN": token } : {}) },
          credentials: "same-origin",
        });
        answer = await res.json();
      } catch (err) { /* an answer that is not JSON: handled as a refusal below */ }
      if (!answer || !answer.ok) {
        // Refused: nothing was begun, so the window goes and the reason is said where flashes go.
        pending = null;
        dlg.close();
        flash((answer && answer.message) || dlg.dataset.readingGone || "", "crit");
        return;
      }
      const address = pending.address;
      pending = null;
      dlg.dataset.started = "1";
      btn.remove();
      dlg.querySelector("[data-reading-below]")?.removeAttribute("inert");
      start(address, true);
    });

    // `:not(dialog)`: right after a scan starts the dialog itself carries data-reading-open (below), and without
    // this every click inside it, Run in background included, matched the dialog, closed it via data-close and
    // then reopened it here.
    on("click", "[data-reading-open]:not(dialog)", (e, btn) => {
      start(btn.dataset.readingOpen, true);
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
      return;
    }

    // data-copy-then="URL": after a copy that worked, POST there (the admin's beta key is handed out by copying it),
    // and strike through whatever data-copy-mark names in the same row.
    if (el.dataset.copyThen) {
      const token = document.querySelector('meta[name="csrf-token"]')?.content;
      fetch(el.dataset.copyThen, {
        method: "POST",
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...(token ? { "X-CSRF-TOKEN": token } : {}) },
        credentials: "same-origin",
      }).then((r) => {
        if (r.ok && el.dataset.copyMark) el.closest("tr")?.querySelector(el.dataset.copyMark)?.classList.add("strike");
      }).catch(() => {});
    }
  });

  reading();
  document.addEventListener("DOMContentLoaded", reading);

  /* --- Flashes ------------------------------------------------------------
     <div class="callout callout--ok callout--flash">Bought.</div>

     A flash is an event, so it leaves: five seconds after it appears it is
     marked [data-leaving], glitches out in CSS and is removed. The removal
     waits for animationend, with a timer behind it for a browser that never
     sends one (a hidden tab, an element already detached).

     UI.flash(message, level) raises one into [data-flash-host] from script —
     how an action that did not reload the page says what happened. */
  const FLASH_HOLD = 5000;

  function dismissFlash(el) {
    if (el.hasAttribute("data-leaving")) return;
    el.setAttribute("data-leaving", "");
    const gone = () => el.remove();
    el.addEventListener("animationend", gone, { once: true });
    setTimeout(gone, 1000);
  }

  function timeFlashes() {
    document.querySelectorAll(".callout--flash:not([data-timed])").forEach((el) => {
      el.setAttribute("data-timed", "");
      setTimeout(() => dismissFlash(el), FLASH_HOLD);
    });
  }

  function flash(message, level = "ok") {
    const host = document.querySelector("[data-flash-host]");
    if (!host) return toast(message, { level });
    const el = document.createElement("div");
    el.className = "callout callout--" + level + " callout--flash";
    el.textContent = message;
    host.appendChild(el);
    timeFlashes();
    return el;
  }

  timeFlashes();
  document.addEventListener("DOMContentLoaded", timeFlashes);

  /* --- Buying without a reload ------------------------------------------
     <form data-buy method="POST" action="/market/7">…</form>

     Posts the form with fetch and asks for JSON; MarketController::buy()
     answers with what changed, and this puts it on the page: the money
     wherever [data-creds] shows it, the shelf's count, the owned tick, and
     whether every other card on the page can still be afforded.

     ANYTHING BUT THE JSON IT EXPECTS FALLS BACK TO AN ORDINARY SUBMIT. An
     expired session answers 419 with a page, a server fault with another:
     submitting the form normally lets the server say so the way it always
     has, and a purchase is never lost to a script that did not understand
     the answer. A refusal is JSON with a message, and is shown as one. */
  function formatCreds(n, sep) {
    return Math.round(n).toLocaleString("en-US").replace(/,/g, sep);
  }

  // Every card that carries a price: can it still be bought, and how many?
  function reprice(creds) {
    document.querySelectorAll("[data-price]").forEach((card) => {
      const price = Number(card.dataset.price);
      const left = Number(card.dataset.left);
      const dear = price > creds;
      const btn = card.querySelector("button[type=submit]");
      const tag = card.querySelector("[data-too-dear]");
      const qty = card.querySelector("input[name=quantity]");
      if (btn) btn.disabled = dear;
      if (tag) tag.hidden = !dear;
      if (qty) {
        const most = Math.min(left, Math.max(price > 0 ? Math.floor(creds / price) : left, 1));
        qty.max = String(most);
        if (Number(qty.value) > most) qty.value = String(most);
      }
    });
  }

  on("submit", "form[data-buy]", async (e, form) => {
    e.preventDefault();
    const btn = form.querySelector("button[type=submit]");
    if (btn) btn.disabled = true;

    let res = null;
    let data = null;
    try {
      res = await fetch(form.action, {
        method: "POST",
        body: new FormData(form),
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
      });
      data = await res.json();
    } catch {
      data = null;
    }

    if (!data || typeof data.message !== "string" || (res.status !== 200 && res.status !== 422)) {
      form.submit();
      return;
    }

    if (res.status === 422) {
      if (btn) btn.disabled = false;
      flash(data.message, "crit");
      return;
    }

    flash(data.message, "ok");

    document.querySelectorAll("[data-creds]").forEach((el) => {
      el.textContent = formatCreds(data.creds, el.dataset.credsSep ?? ",");
    });

    const card = form.closest("[data-price]");
    if (card) {
      card.dataset.left = String(data.left);
      const count = card.querySelector("[data-left-text]");
      if (count) count.textContent = data.left_text;
      if (data.tick) {
        const old = card.querySelector(".tick");
        const tpl = document.createElement("template");
        tpl.innerHTML = data.tick.trim();
        const tick = tpl.content.firstElementChild;
        if (tick && old) old.replaceWith(tick);
        else if (tick) card.insertBefore(tick, card.querySelector(".card__hd"));
      }
      // Sold out: the shelf lists what is left, so the card goes, and so
      // does a group left with nothing in it.
      if (data.left <= 0) {
        // Groups nest (a kind, then its variants): settle each from the innermost out.
        const groups = [];
        for (let g = card.closest("[data-filter-group]"); g; g = g.parentElement && g.parentElement.closest("[data-filter-group]")) groups.push(g);
        card.remove();
        groups.forEach((group) => {
          const shown = group.querySelectorAll("[data-price]").length;
          const tally = group.querySelector("[data-group-count]");
          if (tally) tally.textContent = String(shown);
          if (shown === 0) group.remove();
        });
      }
    }

    if (btn && btn.isConnected) btn.disabled = false;
    reprice(data.creds);
  });

  /* --- A running scan pass, ticking ----------------------------------------
     <button data-pass-clock data-remaining="SECONDS" data-total="SECONDS"> holding a [data-pass-bar] meter and a
     [data-pass-left] time. The server renders both once; this moves them every second so a pass visibly runs, and
     reloads shortly after the end so the page settles it (the next pass begins, or the read is whole) — or, while the read's own window is open, when that is closed. Seconds
     REMAINING, as with [data-countdown]: never an absolute time, which would trust the browser's clock. */
  function passClocks() {
    document.querySelectorAll("[data-pass-clock]").forEach((el) => {
      if (el.dataset.ticking) return;
      el.dataset.ticking = "1";
      const end = Date.now() + Number(el.dataset.remaining) * 1000;
      const total = Math.max(1, Number(el.dataset.total));
      const bar = el.querySelector("[data-pass-bar]");
      const text = el.querySelector("[data-pass-left]");
      const tick = () => {
        const left = Math.max(0, Math.ceil((end - Date.now()) / 1000));
        if (bar) bar.style.setProperty("--v", String(Math.max(0, Math.min(1, 1 - left / total))));
        if (text) {
          const h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60), s = left % 60;
          text.textContent = (h > 0 ? h + ":" + String(m).padStart(2, "0") : m) + ":" + String(s).padStart(2, "0");
        }
        if (left > 0) return setTimeout(tick, 1000);
        // A read being watched keeps its window: the page settles when that is closed instead.
        setTimeout(() => {
          const watching = document.querySelector("[data-reading]");
          if (watching?.open) watching.addEventListener("close", () => location.reload(), { once: true });
          else location.reload();
        }, 1500);
      };
      tick();
    });
  }
  passClocks();
  document.addEventListener("DOMContentLoaded", passClocks);

  /* --- The owner's ticks, told to the party ------------------------------------
     form[data-owner-loadout="url"]: when the owner changes the icebreaker or the scripts they have ticked, the new set is
     posted (after a short pause) so the others' panels show what is coming. Advisory: Start posts the real loadout. */
  function ownerLoadout() {
    const form = document.querySelector("form[data-owner-loadout]");
    if (!form || form.dataset.ownerBound) return;
    form.dataset.ownerBound = "1";
    let timer = null;
    form.addEventListener("change", (e) => {
      if (!e.target.matches("input[name=icebreaker], input[name='programs[]'], input[name='scripts[]']")) return;
      clearTimeout(timer);
      timer = setTimeout(() => {
        const body = new FormData();
        body.append("_token", form.querySelector("input[name=_token]").value);
        form.querySelectorAll("input[name=icebreaker]:checked, input[name='programs[]']:checked, input[name='scripts[]']:checked").forEach((i) => { if (i.value) body.append(i.name, i.value); });
        fetch(form.dataset.ownerLoadout, { method: "POST", body, headers: { Accept: "application/json" }, credentials: "same-origin" }).catch(() => {});
      }, 400);
    });
  }
  ownerLoadout();
  document.addEventListener("DOMContentLoaded", ownerLoadout);

  /* --- What happened since this page was last seen ---------------------------
     The run screen reloads often (after a turn, and on its own while a party waits), so what is new is worked out
     against what THIS tab last saw of THIS run, kept in sessionStorage: a round that resolved pulses the actions box
     (a party's only: a solo turn is the player's own press), and every log row beyond the ones already seen fades in
     from ember. The first look at a run flashes nothing. */
  function runWatch() {
    const box = document.querySelector("[data-run-watch]");
    const log = document.querySelector("[data-log-watch]");
    const id = (box || log) && (box || log).dataset.runWatch || (log && log.dataset.logWatch);
    if (!id) return;
    const key = "run-seen-" + id;
    let seen = null;
    try { seen = JSON.parse(sessionStorage.getItem(key) || "null"); } catch (e) { /* storage may be off */ }
    const turn = box ? Number(box.dataset.turn) : 0;
    const ice = box ? box.dataset.ice === "1" : false;
    const logs = log ? Number(log.dataset.logCount) : 0;
    if (seen) {
      // The ICE was asleep when this run was last seen and is awake now, however it was woken.
      if (ice && seen.ice === false) sfx.ice();
      if (box && box.dataset.party === "1" && turn > seen.turn) {
        box.classList.add("round-flash");
        box.addEventListener("animationend", () => box.classList.remove("round-flash"), { once: true });
      }
      // One sound of each kind per turn, however many of its lines the turn wrote.
      const cues = new Set();
      if (log) log.querySelectorAll("[data-log-index]").forEach((row) => {
        if (Number(row.dataset.logIndex) >= seen.logs) {
          row.classList.add("log-new");
          cues.add(row.dataset.sound);
        }
      });
      if (cues.has("scan")) sfx.scan();
      if (cues.has("failure")) sfx.failure();
      if (cues.has("success")) sfx.success();
      if (cues.has("noise")) sfx.noise();
    }
    try { sessionStorage.setItem(key, JSON.stringify({ turn, logs, ice })); } catch (e) { /* ignore */ }
  }
  runWatch();
  document.addEventListener("DOMContentLoaded", runWatch);

  /* --- A turn, once it has been chosen ---------------------------------------
     form[data-turn-form] holds a run's action buttons. While the choice is waiting on the other runners
     (`data-waiting` names the action that was pressed, rendered by the server) every button is disabled and the
     pressed one carries a spinner. Pressing one shows the same at once, before the page answers: the others
     are disabled a tick later, since a disabled submitter would not be sent. `data-turn-field` names the field the
     buttons carry when it is not `action` (a run tree's nodes are `node`). */
  function turnForms() {
    const spin = (btn) => {
      if (btn.querySelector(".spinner")) return;
      const s = document.createElement("span");
      s.className = "spinner";
      s.setAttribute("aria-hidden", "true");
      btn.prepend(s);
    };
    document.querySelectorAll("form[data-turn-form]").forEach((form) => {
      if (form.dataset.bound) return;
      form.dataset.bound = "1";
      const field = form.dataset.turnField || "action";
      const buttons = () => form.querySelectorAll(`button[name=${field}]`);
      const pending = form.dataset.waiting;
      if (pending) {
        buttons().forEach((b) => { b.disabled = true; if (b.value === pending) spin(b); });
      }
      form.addEventListener("submit", (e) => {
        const pressed = e.submitter;
        if (!pressed || pressed.name !== field) return;
        spin(pressed);
        setTimeout(() => buttons().forEach((b) => { if (b !== pressed) b.disabled = true; }), 0);
      });
    });
  }
  turnForms();

  /* --- The district map on the location editor ---------------------------------
     [data-district-map] holds every district's places as JSON ({id: {hue, places: [{id, name, x, y}]}}); the map is drawn
     from the form's district, name and across/down fields, the place being edited as the highlighted pin, and redrawn on every
     change. Names go in as attributes, never markup. */
  function districtMaps() {
    document.querySelectorAll("[data-district-map]").forEach((box) => {
      if (box.dataset.bound) return;
      box.dataset.bound = "1";
      const form = box.closest("form");
      const frame = box.querySelector(".board");
      if (!form || !frame) return;
      let data = {};
      try { data = JSON.parse(box.dataset.districtMap); } catch (e) { return; }
      const current = box.dataset.current || "";
      const pin = (name, x, y, hue, here) => {
        const node = document.createElement("button");
        node.type = "button";
        node.className = "board__node" + (here ? " board__node--here" : "");
        node.style.left = x + "%";
        node.style.top = y + "%";
        node.style.setProperty("--hue", hue);
        node.setAttribute("data-tip", name);
        node.setAttribute("aria-label", name);
        const boxEl = document.createElement("span");
        boxEl.className = "board__box";
        const glyph = document.createElement("span");
        glyph.className = "board__pin";
        glyph.setAttribute("aria-hidden", "true");
        glyph.textContent = "\u25C8";
        boxEl.appendChild(glyph);
        node.appendChild(boxEl);
        return node;
      };
      const draw = () => {
        frame.querySelectorAll(".board__node").forEach((n) => n.remove());
        const district = data[form.elements.district_id?.value];
        if (!district) return;
        district.places.filter((p) => String(p.id) !== current)
          .forEach((p) => frame.appendChild(pin(p.name, p.x, p.y, district.hue, false)));
        const x = parseFloat(form.elements.map_x?.value);
        const y = parseFloat(form.elements.map_y?.value);
        if (!isNaN(x) && !isNaN(y)) frame.appendChild(pin(form.elements.name?.value || "", x, y, district.hue, true));
      };
      form.addEventListener("input", draw);
      form.addEventListener("change", draw);
      draw();
    });
  }
  districtMaps();

  /* --- The city map on the district editor ---------------------------------------
     [data-city-map] holds every district and the roads between them as JSON ({current, districts: [{id, name, x, y, hue}],
     links: [[a, b]], hues: {colour: token}}). The map is drawn from the form's name, colour, across/down and link boxes for
     the district being edited (the highlighted one; a new district has no id yet), and redrawn on every change. */
  function cityMaps() {
    const NS = "http://www.w3.org/2000/svg";
    document.querySelectorAll("[data-city-map]").forEach((box) => {
      if (box.dataset.bound) return;
      box.dataset.bound = "1";
      const form = box.closest("form");
      const frame = box.querySelector(".board");
      if (!form || !frame) return;
      let data = {};
      try { data = JSON.parse(box.dataset.cityMap); } catch (e) { return; }
      const draw = () => {
        frame.querySelectorAll(".board__node, .board__wires").forEach((n) => n.remove());
        const me = data.current;
        const x = parseFloat(form.elements.map_x?.value);
        const y = parseFloat(form.elements.map_y?.value);
        const places = data.districts.filter((d) => d.id !== me).map((d) => ({ ...d, mine: false }));
        if (!isNaN(x) && !isNaN(y)) {
          places.push({ id: me ?? 0, name: form.elements.name?.value || "", x, y, hue: data.hues[form.elements.color?.value] || "var(--line-2)", mine: true });
        }
        const at = new Map(places.map((p) => [p.id, p]));
        const chosen = [...form.querySelectorAll('input[name="links[]"]:checked')].map((c) => parseInt(c.value, 10));
        const roads = data.links.filter(([a, b]) => a !== me && b !== me).concat(chosen.map((id) => [me ?? 0, id]));
        const svg = document.createElementNS(NS, "svg");
        svg.setAttribute("class", "board__wires");
        svg.setAttribute("viewBox", "0 0 100 100");
        svg.setAttribute("preserveAspectRatio", "none");
        svg.setAttribute("aria-hidden", "true");
        svg.setAttribute("focusable", "false");
        roads.forEach(([a, b]) => {
          const from = at.get(a), to = at.get(b);
          if (!from || !to) return;
          const line = document.createElementNS(NS, "line");
          line.setAttribute("class", "board__wire");
          line.setAttribute("x1", from.x); line.setAttribute("y1", from.y);
          line.setAttribute("x2", to.x); line.setAttribute("y2", to.y);
          svg.appendChild(line);
        });
        frame.appendChild(svg);
        places.forEach((p) => {
          const node = document.createElement("div");
          node.className = "board__node" + (p.mine ? " board__node--here" : "");
          node.style.left = p.x + "%";
          node.style.top = p.y + "%";
          node.style.setProperty("--hue", p.hue);
          const boxEl = document.createElement("span");
          boxEl.className = "board__box";
          const name = document.createElement("span");
          name.className = "board__name";
          name.textContent = p.name;
          boxEl.appendChild(name);
          node.appendChild(boxEl);
          frame.appendChild(node);
        });
      };
      form.addEventListener("input", draw);
      form.addEventListener("change", draw);
      draw();
    });
  }
  cityMaps();
  document.addEventListener("DOMContentLoaded", cityMaps);
  document.addEventListener("DOMContentLoaded", districtMaps);
  document.addEventListener("DOMContentLoaded", turnForms);

  /* --- A run tree, alive ------------------------------------------------------
     .board[data-tree-run] remembers, per run and for this tab only, which nodes it showed and where the runner stood.
     On the next page a node it had not shown dissolves in (with its wires), and a changed position sends a mark along
     the wire from the old node to the new. Storage that fails, a first look, or reduced motion: nothing animates. */
  function treeLife() {
    const still = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    document.querySelectorAll(".board[data-tree-run]").forEach((board) => {
      if (board.dataset.alive) return;
      board.dataset.alive = "1";
      // The map scrolls sideways on a narrow screen: put where they stand in the middle of what shows.
      const wrap = board.closest(".board-wrap");
      const here = board.querySelector(".board__node--here");
      if (wrap && here && wrap.scrollWidth > wrap.clientWidth) {
        const box = here.getBoundingClientRect();
        const view = wrap.getBoundingClientRect();
        wrap.scrollLeft += box.left + box.width / 2 - (view.left + view.width / 2);
      }

      // THE WAYS ON: an arrow (or a red X where the way is closed) at the edge of the box they stand in, over each wire.
      const exits = () => {
        board.querySelectorAll(".board__exit").forEach((e) => e.remove());
        const at = here && here.querySelector(".board__box");
        if (!at) return;
        const frame = board.getBoundingClientRect();
        const box = at.getBoundingClientRect();
        const cx = box.left + box.width / 2, cy = box.top + box.height / 2;
        board.querySelectorAll('button.board__node[name="node"] .board__box, .board__node[data-blocked] .board__box').forEach((to) => {
          const r = to.getBoundingClientRect();
          const dx = r.left + r.width / 2 - cx, dy = r.top + r.height / 2 - cy;
          const len = Math.hypot(dx, dy);
          if (!len) return;
          // Where the wire leaves the box: the mark sits on its border.
          const t = Math.min(dx ? box.width / 2 / Math.abs(dx) : Infinity, dy ? box.height / 2 / Math.abs(dy) : Infinity);
          const blocked = to.parentElement.hasAttribute("data-blocked");
          const mark = document.createElement("span");
          mark.className = "board__exit" + (blocked ? " board__exit--blocked" : "");
          mark.setAttribute("aria-hidden", "true");
          mark.textContent = blocked ? "\u2716" : "\u25BA";
          mark.style.setProperty("--exit-x", (cx + dx * t - frame.left) + "px");
          mark.style.setProperty("--exit-y", (cy + dy * t - frame.top) + "px");
          mark.style.setProperty("--exit-turn", Math.atan2(dy, dx) + "rad");
          board.appendChild(mark);
        });
      };
      exits();
      if (window.ResizeObserver) new ResizeObserver(exits).observe(board);

      const key = "tree:" + board.dataset.treeRun;
      const nodes = [...board.querySelectorAll(".board__node[data-node]")];
      const now = { here: board.dataset.treeHere, nodes: nodes.map((n) => n.dataset.node) };
      let before = null;
      try { before = JSON.parse(sessionStorage.getItem(key) || "null"); } catch (e) { before = null; }
      try { sessionStorage.setItem(key, JSON.stringify(now)); } catch (e) { /* the page works without it */ }
      if (!before || still) return;

      const fresh = new Set(now.nodes.filter((n) => !before.nodes.includes(n)));
      nodes.forEach((n) => { if (fresh.has(n.dataset.node)) n.classList.add("board__node--reveal"); });
      board.querySelectorAll(".board__wire").forEach((w) => {
        if (fresh.has(w.dataset.from) || fresh.has(w.dataset.to)) w.classList.add("board__wire--reveal");
      });

      const from = board.querySelector(`.board__node[data-node="${before.here}"]`);
      const to = board.querySelector(`.board__node[data-node="${now.here}"]`);
      if (!from || !to || before.here === now.here) return;
      const mark = document.createElement("span");
      mark.className = "board__trail";
      mark.setAttribute("aria-hidden", "true");
      mark.textContent = "\u25C6";
      mark.style.setProperty("--trail-x", from.style.left);
      mark.style.setProperty("--trail-y", from.style.top);
      board.appendChild(mark);
      requestAnimationFrame(() => requestAnimationFrame(() => {
        mark.style.setProperty("--trail-x", to.style.left);
        mark.style.setProperty("--trail-y", to.style.top);
        mark.style.opacity = "0";
      }));
      // The flash replaces the you-are-here breathing while it runs, so it is taken off again afterwards.
      setTimeout(() => { to.classList.add("board__node--arrive"); }, 480);
      setTimeout(() => { to.classList.remove("board__node--arrive"); }, 1150);
      setTimeout(() => mark.remove(), 900);
    });
  }
  treeLife();
  document.addEventListener("DOMContentLoaded", treeLife);

  /* --- The party panel, kept current ---------------------------------------
     [data-party-poll="url"] asks the server every five seconds who is in, and swaps the HTML of each
     [data-party-live] for what it answers (the member lines are drawn on the server, so the wording is
     the server's). The owner's purpose buttons ([data-party-gate]) are live only while somebody could go
     in, and the "nobody yet" line ([data-party-hint]) shows while not. When the party is gone or has
     started, or the person's role in it changed, the whole screen is different: reload.
     Nothing in the panel that is being typed in is touched. */
  function partyPanels() {
    document.querySelectorAll("[data-party-poll]").forEach((panel) => {
      if (panel.dataset.polling) return;
      panel.dataset.polling = "1";
      const live = panel.querySelectorAll("[data-party-live]");
      const tick = async () => {
        // Not paused while the tab is hidden: a party waits on people who are often in another window.
        try {
          const res = await fetch(panel.dataset.partyPoll, { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }, credentials: "same-origin" });
          const data = await res.json();
          if (data.state !== "open" || data.role !== panel.dataset.partyRole) return location.reload();
          live.forEach((el) => { if (el.innerHTML.trim() !== data.html.trim()) el.innerHTML = data.html; });
          // The Trade box shows only while somebody else is in the party, and its table follows who offered and claimed what.
          document.querySelectorAll("[data-party-pool-box]").forEach((el) => { el.hidden = !data.pool_open; });
          document.querySelectorAll("[data-party-pool]").forEach((el) => { if (data.pool !== undefined && el.innerHTML.trim() !== data.pool.trim()) el.innerHTML = data.pool; });
          document.querySelectorAll("[data-party-gate]").forEach((b) => { b.disabled = !data.ready; });
          document.querySelectorAll("[data-party-hint]").forEach((p) => { p.hidden = data.ready; });
        } catch (e) { /* a missed beat is fine: the next one asks again */ }
        setTimeout(tick, 5000);
      };
      setTimeout(tick, 5000);
    });
  }
  partyPanels();
  document.addEventListener("DOMContentLoaded", partyPanels);

  /* --- A countdown that reloads the page when it ends ----------------------
     <span data-countdown="95">1:35</span> — the value is SECONDS REMAINING,
     sent by the server, never an absolute time: a phone's clock can be wrong
     and the server's is the one that settles anything. It ticks once a second
     as m:ss and, a moment after zero, reloads, because the server's lazy
     settle on that request is what actually moves the City along. */
  function countdowns() {
    document.querySelectorAll("[data-countdown]").forEach((el) => {
      if (el.dataset.ticking) return;
      el.dataset.ticking = "1";
      const end = Date.now() + Number(el.dataset.countdown) * 1000;
      const tick = () => {
        const left = Math.max(0, Math.ceil((end - Date.now()) / 1000));
        el.textContent = Math.floor(left / 60) + ":" + String(left % 60).padStart(2, "0");
        if (left > 0) return setTimeout(tick, 1000);
        setTimeout(reloadPage, 1500);
      };
      tick();
    });
  }

  countdowns();
  document.addEventListener("DOMContentLoaded", countdowns);

  /* A tab strip that scrolls says so: data-more is set while there is more to the right (CSS draws the fade and arrow). */
  const tabsMore = () => document.querySelectorAll(".tabs").forEach((t) => {
    const set = () => t.toggleAttribute("data-more", t.scrollLeft + t.clientWidth < t.scrollWidth - 8);
    t.addEventListener("scroll", set, { passive: true });
    set();
  });
  document.addEventListener("DOMContentLoaded", tabsMore);
  window.addEventListener("resize", () => document.querySelectorAll(".tabs").forEach((t) => t.toggleAttribute("data-more", t.scrollLeft + t.clientWidth < t.scrollWidth - 8)));


  /* --- A node graph -----------------------------------------------------
     UI.nodeGraph(el, spec) draws a directed graph as an SVG inside el (a .graph).
     spec = {
       root:  "id",                                   // where the walk starts
       nodes: [{ id, label, kind: "entry"|"end"|"plain", detail? }],
       edges: [{ from, to, tone: "plain"|"pass"|"fail", title? }],
     }
     Nodes REACHABLE from the root are laid out left to right by distance from it;
     the rest are "loose" and sit in a row underneath, unconnected, with no edges
     drawn. Parallel edges bend apart, and one that goes back (or to itself) loops
     round underneath so it never hides behind the node it leaves. A node carries no
     text of its own: pointing at its glyph floats its `detail` as a card, and
     clicking or tapping opens the same card in a modal (the inspector below, so a
     phone can read it too). detail = { title, tags: [], text, rows: [{ left, right,
     tone }] }, and spec.looseLabel tags a node that is not joined up. Text goes in
     with textContent, so any of it may be anything. Or write the spec as JSON into
     <div class="graph" data-graph='{…}'> and it is drawn on load.            */
  function nodeGraph(el, spec) {
    const NS = "http://www.w3.org/2000/svg";
    const COL = 150, ROW = 64, PAD = 36, R = 15, LOOSE_PER_ROW = 10;
    const make = (name, attrs, text) => {
      const n = document.createElementNS(NS, name);
      Object.entries(attrs || {}).forEach(([k, v]) => n.setAttribute(k, String(v)));
      if (text !== undefined) n.textContent = text;
      return n;
    };
    el.replaceChildren();
    const nodes = (spec.nodes || []).filter((n, i, all) => all.findIndex((m) => m.id === n.id) === i);
    if (!nodes.length) return;
    const byId = new Map(nodes.map((n) => [n.id, n]));
    const edges = (spec.edges || []).filter((e) => byId.has(e.from) && byId.has(e.to));

    // Distance from the root: a breadth-first walk, so each node sits one column past its nearest parent.
    const root = byId.has(spec.root) ? spec.root : nodes[0].id;
    const depth = new Map([[root, 0]]);
    const queue = [root];
    while (queue.length) {
      const id = queue.shift();
      edges.filter((e) => e.from === id).forEach((e) => {
        if (!depth.has(e.to)) { depth.set(e.to, depth.get(id) + 1); queue.push(e.to); }
      });
    }
    const layers = [];
    [...depth.keys()].forEach((id) => (layers[depth.get(id)] = layers[depth.get(id)] || []).push(id));
    const tallest = Math.max(...layers.map((l) => l.length));
    const pos = new Map();
    layers.forEach((layer, d) =>
      layer.forEach((id, i) => pos.set(id, { x: PAD + d * COL, y: PAD + (tallest - 1) * ROW / 2 + (i - (layer.length - 1) / 2) * ROW })));
    const connectedBottom = PAD + (tallest - 1) * ROW + ROW * 0.7;   // room for loops underneath

    const loose = nodes.filter((n) => !depth.has(n.id));
    loose.forEach((n, i) => pos.set(n.id, { x: PAD + (i % LOOSE_PER_ROW) * COL * 0.6, y: connectedBottom + PAD + Math.floor(i / LOOSE_PER_ROW) * ROW * 0.8 }));

    const width = Math.max(...[...pos.values()].map((p) => p.x)) + PAD;
    const height = Math.max(...[...pos.values()].map((p) => p.y)) + PAD;
    const svg = make("svg", { viewBox: `0 0 ${width} ${height}`, width, height, role: "img", "aria-label": el.dataset.label || "" });

    // One arrowhead per tone, filled by class: `context-stroke` is not everywhere yet.
    const uid = "g" + Math.random().toString(36).slice(2, 7);
    const defs = make("defs");
    ["plain", "pass", "fail"].forEach((tone) => {
      const m = make("marker", { id: `${uid}-${tone}`, viewBox: "0 0 10 10", refX: 9, refY: 5, markerWidth: 7, markerHeight: 7, orient: "auto" });
      m.appendChild(make("path", { d: "M0 0 L10 5 L0 10 z", class: `graph__arrow graph__arrow--${tone}` }));
      defs.appendChild(m);
    });
    svg.appendChild(defs);

    // Edges first so the glyphs sit on top. Edges between the same two nodes bend apart.
    const seen = new Map();
    edges.filter((e) => depth.has(e.from) && depth.has(e.to)).forEach((e) => {
      const a = pos.get(e.from), b = pos.get(e.to);
      const pair = [e.from, e.to].sort().join("\u0000");
      const k = seen.get(pair) || 0;
      seen.set(pair, k + 1);
      const bend = (k % 2 ? 1 : -1) * Math.ceil(k / 2) * 22;
      let d;
      if (e.from === e.to) {
        d = `M${a.x - 8} ${a.y - R} C${a.x - 38} ${a.y - R - 38 - k * 10} ${a.x + 38} ${a.y - R - 38 - k * 10} ${a.x + 8} ${a.y - R - 2}`;
      } else if (b.x > a.x) {
        const mx = (a.x + b.x) / 2;
        d = `M${a.x + R} ${a.y} C${mx} ${a.y + bend} ${mx} ${b.y + bend} ${b.x - R - 3} ${b.y}`;
      } else {
        const low = Math.max(a.y, b.y) + ROW * 0.5 + Math.abs(bend);
        d = `M${a.x} ${a.y + R} C${a.x} ${low} ${b.x} ${low} ${b.x} ${b.y + R + 3}`;
      }
      const path = make("path", { d, class: `graph__edge graph__edge--${e.tone || "plain"}`, "marker-end": `url(#${uid}-${e.tone || "plain"})` });
      if (e.title) path.appendChild(make("title", {}, e.title));
      svg.appendChild(path);
    });

    // ◈ for a screen in the graph, ▤ for one outside it: both from the proven glyph set. A node
    // is the glyph and nothing else: it points at a <template> holding its card, which the
    // inspector floats on hover and opens as a modal on click or tap.
    const html = (tag, cls, text) => {
      const n = document.createElement(tag);
      if (cls) n.className = cls;
      if (text !== undefined) n.textContent = text;
      return n;
    };
    const card = (n, out) => {
      const d = n.detail || {};
      const c = html("div", "item-card");
      const hd = html("div", "item-card__hd");
      hd.appendChild(html("span", "item-card__name", d.title || n.label));
      const tags = [...(d.tags || []), ...(out && spec.looseLabel ? [spec.looseLabel] : [])];
      if (tags.length) {
        const row = html("div", "row row--wrap");
        row.style.cssText = "--gap:var(--s-2);margin-top:var(--s-2)";
        tags.forEach((t) => row.appendChild(html("span", "tag", t)));
        hd.appendChild(row);
      }
      c.appendChild(hd);
      if (d.text) c.appendChild(html("div", "item-card__flavor", d.text));
      if ((d.rows || []).length) {
        const stats = html("div", "item-card__stats");
        d.rows.forEach((r) => {
          const row = html("div", "item-card__stat item-card__stat--stack");
          row.appendChild(html("span", "", r.left));
          row.appendChild(html("b", r.tone ? `graph__tone graph__tone--${r.tone}` : "", r.right));
          stats.appendChild(row);
        });
        c.appendChild(stats);
      }
      return c;
    };
    nodes.forEach((n, i) => {
      const p = pos.get(n.id), out = !depth.has(n.id);
      const g = make("g", {
        class: `graph__node graph__node--${out ? "loose" : n.kind || "plain"}`,
        transform: `translate(${p.x} ${p.y})`,
        tabindex: 0, role: "button", "aria-label": n.label, "data-inspect": `#${uid}-n${i}`,
      });
      g.appendChild(make("text", { class: "graph__glyph", "text-anchor": "middle", "dominant-baseline": "central" }, out ? "▤" : "◈"));
      svg.appendChild(g);
      const tpl = document.createElement("template");
      tpl.id = `${uid}-n${i}`;
      tpl.content.appendChild(card(n, out));
      el.appendChild(tpl);
    });
    el.appendChild(svg);
  }

  document.querySelectorAll(".graph[data-graph]").forEach((el) => {
    try { nodeGraph(el, JSON.parse(el.dataset.graph)); } catch (e) { /* a bad spec draws nothing */ }
  });

  /* A checkbox marked [data-autosubmit] sends its form the moment it changes. A range with [data-labels] (a JSON list) names
     its position in the [data-range-label="#id"] span beside its label: 1 is the first word. A [data-tags] list (the same
     length) gives that span the kit tag class of the position too. */
  document.addEventListener("change", (e) => {
    if (e.target.matches?.("[data-autosubmit]")) e.target.form.requestSubmit();
  });
  document.querySelectorAll("input[type=range][data-labels]").forEach((range) => {
    const words = JSON.parse(range.dataset.labels), tags = JSON.parse(range.dataset.tags || "[]"), out = document.querySelector('[data-range-label="#' + range.id + '"]');
    const show = () => {
      if (!out) return;
      const i = Number(range.value) - Number(range.min);
      out.textContent = words[i] ?? "";
      if (tags.length) out.className = "tag " + (tags[i] ?? "");
    };
    range.addEventListener("input", show);
    show();
  });

  /* Autotraverse: <button data-autotraverse="DIALOG-ID" data-url data-x data-y data-steps data-step-ms>. Opens the modal
     dialog (its backdrop is the overlay) and posts one step toward the target every data-step-ms. Cancelling, or the
     dialog closing any other way, stops it where it stands and reloads to show the new position; so does arriving or
     the server refusing a step. */
  document.querySelectorAll("[data-autotraverse]").forEach((btn) => {
    const dlg = document.getElementById(btn.dataset.autotraverse);
    if (!dlg || !dlg.showModal) return;
    const total = parseInt(btn.dataset.steps, 10) || 0;
    const gap = parseInt(btn.dataset.stepMs, 10) || 10000;
    const label = dlg.querySelector("[data-traverse-left]");
    const bar = dlg.querySelector("[data-traverse-meter]");
    let timer = null;
    let left = total;

    const draw = () => {
      label.textContent = left + (left === 1 ? " step left" : " steps left");
      meter(bar, total ? (total - left) / total : 1);
    };
    const stop = () => { clearTimeout(timer); timer = null; if (dlg.open) dlg.close(); };
    const next = () => {
      timer = setTimeout(() => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        fetch(btn.dataset.url, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json", "X-Requested-With": "XMLHttpRequest", ...(token ? { "X-CSRF-TOKEN": token } : {}) },
          body: JSON.stringify({ x: btn.dataset.x, y: btn.dataset.y }),
          credentials: "same-origin",
        }).then((r) => r.json()).then((res) => {
          if (res.stop) { stop(); return; }
          left = res.left;
          draw();
          if (left > 0) next(); else stop();
        }).catch(stop);
      }, gap);
    };

    btn.addEventListener("click", () => { left = total; draw(); dlg.showModal(); next(); });
    dlg.querySelector("[data-traverse-cancel]").addEventListener("click", stop);
    dlg.addEventListener("close", () => { clearTimeout(timer); timer = null; location.reload(); });
  });

  /* The run briefing's budget: <form data-run-budget data-free-qflops data-free-memory> (tenths of what the deck has free),
     each loadout input and script carrying data-cost-qflops / data-cost-memory in the same unit. A script that no longer fits
     beside everything ticked is disabled and says so. A running process ticked to be suspended (data-gain-qflops /
     data-gain-memory, the same unit) gives its cost back. Display only: starting the run asks the runner again. */
  document.querySelectorAll("[data-run-budget]").forEach((form) => {
    const freeQ = Number(form.dataset.freeQflops), freeM = Number(form.dataset.freeMemory);
    const cost = (el, k) => Number(el.dataset["cost" + k]) || 0;
    const inputs = [...form.querySelectorAll("[data-cost-qflops]")];
    // Outside the form (an accordion higher up the page) and tied to it by form="id".
    const gains = [...document.querySelectorAll("[data-gain-qflops]")];
    // Everything that can be ticked and carries a cost: the icebreaker radios, run-only programs and scripts.
    const choices = inputs.filter((el) => el.closest("label")?.querySelector("[data-unable]"));
    const refused = new Set(inputs.filter((el) => el.disabled));
    const readout = form.querySelector("[data-free-readout]");
    const starts = [...form.querySelectorAll('button[name="purpose"]')];
    const startsOff = new Set(starts.filter((el) => el.disabled));
    const fmt = (n) => String(n / 10);
    // The Assessor's overall for what is ticked: the server works it out (and a later tick wins over an earlier answer);
    // this only sends the ticks and shows what comes back.
    const assess = document.querySelector("[data-assess-readout]");
    let asked = 0, lastQuery = null;
    const askAssessor = () => {
      if (!assess) return;
      const q = new URLSearchParams();
      const pick = form.querySelector('input[name="icebreaker"]:checked');
      if (pick && pick.value) q.set("icebreaker", pick.value);
      form.querySelectorAll('input[name="programs[]"]:checked').forEach((el) => q.append("programs[]", el.value));
      document.querySelectorAll('input[name="suspend[]"]:checked').forEach((el) => q.append("suspend[]", el.value));
      const query = q.toString();
      // The first call is the page as the server drew it, already answered.
      // Unless something comes pre-ticked in the Suspend box: the server drew the page with nothing stopped.
      if ((lastQuery === null && !q.has("suspend[]")) || query === lastQuery) { lastQuery = query; return; }
      lastQuery = query;
      const mine = ++asked;
      fetch(assess.dataset.assessUrl + "?" + query, { headers: { "X-Requested-With": "XMLHttpRequest" }, credentials: "same-origin" })
        .then((r) => (r.ok ? r.json() : null))
        .then((j) => { if (j && mine === asked) assess.innerHTML = j.overall; })
        .catch(() => {});
    };
    const recalc = () => {
      const ticked = inputs.filter((el) => el.checked);
      const freed = gains.filter((el) => el.checked);
      const usedQ = ticked.reduce((n, el) => n + cost(el, "Qflops"), 0) - freed.reduce((n, el) => n + Number(el.dataset.gainQflops), 0);
      const usedM = ticked.reduce((n, el) => n + cost(el, "Memory"), 0) - freed.reduce((n, el) => n + Number(el.dataset.gainMemory), 0);
      const leftQ = freeQ - usedQ, leftM = freeM - usedM;
      choices.forEach((el) => {
        if (refused.has(el)) return;
        // A ticked one is judged by the whole sum. An unticked one by whether it would fit on top of it, with
        // the radio it would replace taken off first.
        let cpu, ram;
        if (el.checked) {
          cpu = leftQ < 0; ram = leftM < 0;
        } else {
          const swap = el.type === "radio" ? inputs.find((o) => o !== el && o.type === "radio" && o.name === el.name && o.checked) : null;
          cpu = cost(el, "Qflops") > leftQ + (swap ? cost(swap, "Qflops") : 0);
          ram = cost(el, "Memory") > leftM + (swap ? cost(swap, "Memory") : 0);
        }
        // Left enabled while ticked, so it can be ticked off again.
        el.disabled = !el.checked && (cpu || ram);
        const note = el.closest("label").querySelector("[data-unable]");
        note.textContent = cpu && ram ? form.dataset.unableBoth : cpu ? form.dataset.unableCpu : ram ? form.dataset.unableRam : "";
        note.style.display = cpu || ram ? "block" : "none";
      });
      // A selection that is over the deck cannot start, whatever the buttons were.
      const over = leftQ < 0 || leftM < 0;
      starts.forEach((btn) => { btn.disabled = over || startsOff.has(btn); });
      // The deck's own bars (<x-deck-vitals>) move with the selection: what is already spent plus what is ticked.
      const vitals = document.querySelector("[data-format]");
      [["qflops", usedQ], ["memory", usedM]].forEach(([key, extra]) => {
        const v = document.querySelector('.vital[data-vital="' + key + '"]');
        if (!v) return;
        const used = Math.round(Number(v.dataset.used) * 10) + extra, total = Math.round(Number(v.dataset.total) * 10);
        meter(v.querySelector(".meter"), total > 0 ? used / total : (used > 0 ? 1 : 0));
        v.querySelector(".meter").style.setProperty("--fill", (total > 0 ? used >= total : used > 0) ? "var(--crit)" : total > 0 && used * 10 >= total * 9 ? "var(--warn)" : "var(--ok)");
        const text = v.querySelector("[data-vital-value]");
        if (text && vitals) text.textContent = vitals.dataset.format.replace(":used", fmt(used)).replace(":total", fmt(total));
      });
      askAssessor();
      if (readout) readout.textContent = fmt(Math.max(0, leftQ)) + " qFLOPS \u00b7 " + fmt(Math.max(0, leftM)) + " QB";
    };
    form.addEventListener("change", recalc);
    gains.forEach((el) => el.addEventListener("change", recalc));
    recalc();
  });


  /* --- Chat ---------------------------------------------------------------
     The chat page: [data-chat-page data-chat-url] holding [data-chat] (tabs, log, form). Forms marked
     form[data-chat-form] POST and get {chat: id} (or 422 {message}) back; the same chat stays put and another is
     visited. [data-chat-join] joins a public room the same way. LINES ARRIVE BY WEBSOCKET (Reverb, the Pusher
     protocol, spoken below) and the page keeps its socket for as long as it is open; with no socket the poll URL is
     fetched every five seconds instead. Only the tabs and the log are ever swapped, so what is half typed survives.
     The server draws every line; nothing is built here from message text. */
  (function chat() {
    const page = document.querySelector("[data-chat-page]");
    if (!page) return;
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content || "";
    const headers = { "X-Requested-With": "XMLHttpRequest" };
    const log = () => page.querySelector("[data-chat-log]");
    const bottom = () => { const l = log(); if (l) l.scrollTop = l.scrollHeight; };
    bottom();
    try {
      const notice = sessionStorage.getItem("chat.notice");
      if (notice) { sessionStorage.removeItem("chat.notice"); toast(notice); }
    } catch { /* nothing to show */ }

    const post = (url, body) => fetch(url, { method: "POST", body, headers: { ...headers, Accept: "application/json", "X-CSRF-TOKEN": token() }, credentials: "same-origin" })
      .then(async (r) => {
        const data = await r.json().catch(() => ({}));
        if (!r.ok) { toast(data.message || "Could not reach the chat.", { level: "warn" }); return null; }
        // A command with nothing to draw (invited, ignored, left) says so once, on the page it lands on.
        if (data.notice) { try { sessionStorage.setItem("chat.notice", data.notice); } catch { /* no toast then */ } }
        return data.chat;
      })
      .catch(() => { toast("Could not reach the chat.", { level: "warn" }); return null; });
    const go = (id) => { window.location.href = page.dataset.chatUrl + "?chat=" + encodeURIComponent(id); };

    document.addEventListener("click", (e) => {
      const join = e.target.closest("[data-chat-join]");
      if (!join) return;
      e.preventDefault();
      post(join.dataset.chatJoin, new FormData()).then((id) => id && go(id));
    });

    // Scroll to the end now, and again as each picture in the log finishes loading (it grows the log after the fact).
    const stick = (box) => {
      box.scrollTop = box.scrollHeight;
      box.querySelectorAll("img").forEach((i) => { if (!i.complete) i.addEventListener("load", () => { box.scrollTop = box.scrollHeight; }, { once: true }); });
    };

    // Tabs and log from the server's truth: the poll screen, swapped in without touching the form.
    const swap = () => {
      const root = page.querySelector("[data-chat-poll]");
      if (!root) return Promise.resolve();
      return fetch(root.dataset.chatPoll, { headers, credentials: "same-origin" })
        .then((r) => (r.ok ? r.text() : Promise.reject()))
        .then((html) => {
          const next = new DOMParser().parseFromString(html, "text/html");
          for (const sel of ["[data-chat-tabs]", "[data-chat-log]"]) {
            const fresh = next.querySelector(sel), old = root.querySelector(sel);
            if (!fresh || !old) continue;
            const atEnd = old.scrollHeight - old.scrollTop - old.clientHeight < 24;
            old.innerHTML = fresh.innerHTML;
            if (atEnd) stick(old);
          }
        })
        .catch(() => {});
    };
    let lazyTimer = null;
    const lazy = () => { clearTimeout(lazyTimer); lazyTimer = setTimeout(swap, 800); };

    // Enter sends, Shift+Enter is a new line; the box is one row until there is more than one, then it grows to fit (up to 8).
    const fit = (box) => { box.style.height = "auto"; box.style.height = Math.min(box.scrollHeight, 8 * 16 + 18) + "px"; };
    document.addEventListener("keydown", (e) => {
      const box = e.target.closest && e.target.closest("[data-chat-form] textarea");
      if (box && e.key === "Enter" && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        box.form.requestSubmit();
      }
    });
    document.addEventListener("input", (e) => {
      const box = e.target.closest && e.target.closest("[data-chat-form] textarea");
      if (box) fit(box);
    });
    // A picture on the clipboard is uploaded and said as a line of its own; text still pastes as text.
    document.addEventListener("paste", (e) => {
      const box = e.target.closest && e.target.closest("[data-chat-form] textarea");
      const file = box && [...(e.clipboardData?.files || [])].find((f) => f.type.startsWith("image/"));
      if (!file) return;
      e.preventDefault();
      const data = new FormData();
      data.append("image", file);
      data.append("chat", box.form.querySelector("[name=chat]").value);
      post(box.form.dataset.imageUrl, data).then((id) => {
        if (id && String(id) === page.querySelector("[data-chat]")?.dataset.chat) swap();
      });
    });
    document.addEventListener("submit", (e) => {
      const form = e.target.closest("form[data-chat-form]");
      if (!form) return;
      e.preventDefault();
      post(form.action, new FormData(form)).then((id) => {
        if (!id) return;
        form.reset();
        form.querySelectorAll("textarea").forEach(fit);
        // The same chat stays put (the line arrives by socket, and the swap is the fallback); another is a visit.
        if (String(id) === page.querySelector("[data-chat]")?.dataset.chat) { swap(); form.querySelector("[name=message]")?.focus(); } else go(id);
      });
    });

    /* The socket. Reverb speaks the Pusher protocol, which is small enough to speak by hand: connect, answer a
       ping, ask the app for a signature per private channel, subscribe, and read {event, channel, data}. A line
       pushed for the open chat is inserted at once (the HTML is the server's); one for another chat only refreshes
       the tabs. While there is no socket the five-second poll runs, as before. */
    let live = false;
    const ignored = new Set(JSON.parse(page.dataset.chatIgnored || "[]"));
    const onLine = (d) => {
      if (ignored.has(Number(d.by))) return;
      const root = page.querySelector("[data-chat]");
      const box = log();
      if (root && String(d.chat) === root.dataset.chat && box) {
        if (!box.querySelector('[data-line="' + d.id + '"]')) {
          const atEnd = box.scrollHeight - box.scrollTop - box.clientHeight < 24;
          box.querySelector(":scope > p")?.remove();
          box.insertAdjacentHTML("beforeend", d.html);
          if (atEnd) stick(box);
        }
      }
      lazy();
    };

    const cfg = (() => { try { return JSON.parse(page.dataset.chatWs || "null"); } catch { return null; } })();
    if (cfg && cfg.key && "WebSocket" in window) {
      let retry = 1000;
      const open = () => {
        let ws;
        try { ws = new WebSocket((cfg.scheme === "https" ? "wss" : "ws") + "://" + cfg.host + ":" + cfg.port + "/app/" + cfg.key + "?protocol=7&client=dedlok&version=1"); } catch { return; }
        const subscribe = (socketId, channel) => fetch(cfg.auth, {
          method: "POST", credentials: "same-origin",
          headers: { ...headers, Accept: "application/json", "X-CSRF-TOKEN": token(), "Content-Type": "application/x-www-form-urlencoded" },
          body: new URLSearchParams({ socket_id: socketId, channel_name: channel }),
        })
          .then((r) => (r.ok ? r.json() : Promise.reject()))
          .then((a) => ws.send(JSON.stringify({ event: "pusher:subscribe", data: { auth: a.auth, channel } })))
          .catch(() => {});
        ws.onmessage = (m) => {
          let msg, data;
          try { msg = JSON.parse(m.data); data = typeof msg.data === "string" ? JSON.parse(msg.data) : msg.data; } catch { return; }
          if (msg.event === "pusher:connection_established") {
            retry = 1000;
            subscribe(data.socket_id, "private-chat.general");
            subscribe(data.socket_id, "private-chat-user." + page.dataset.chatMe);
          } else if (msg.event === "pusher:ping") {
            ws.send(JSON.stringify({ event: "pusher:pong", data: {} }));
          } else if (msg.event === "pusher_internal:subscription_succeeded") {
            if (!live) { live = true; swap(); }
          } else if (msg.event === "line") {
            onLine(data);
          }
        };
        ws.onclose = () => {
          live = false;
          setTimeout(open, retry);
          retry = Math.min(retry * 2, 30000);
        };
        ws.onerror = () => ws.close();
      };
      open();
    }

    // The fallback: with no socket, the page asks every five seconds as it always did.
    setInterval(() => { if (!document.hidden && !live) swap(); }, 5000);
  })();

  /* --- THE PAGE GLITCH ------------------------------------------------------
     UI.glitch("tick" | "hit" | "crash"): the whole page tears for a moment. A level is a list of hard-cut frames,
     [ms, tear px, ember ghost px, inverted bands, edge flash 0-1, black 0-1, readout]; a new one is a new list. Where it
     fires is the server's call, never the page's own: `body[data-glitch]` (Glitch::flash(), played once on load) and
     `.log-new[data-fx]` (a run's new log lines, the strongest wins). The tear is the SVG filter in layouts/glitch.blade.php,
     on the page root only while it runs. Reduced motion: no movement, just the edge flash, held. */
  const GLITCH = {
    tick: [[50, 6, 3, 0, .6, 0], [40, 0, 0, 0, 0, 0], [60, 10, 4, 1, .4, 0], [70, 0, 0, 0, 0, 0]],
    hit: [[60, 40, 10, 2, 1, 0], [50, 0, 0, 0, .7, 0], [70, 55, 14, 3, 0, 0], [40, 0, 0, 0, 0, 0], [80, 28, 8, 1, .5, 0], [60, 12, 5, 0, 0, 0],
      [90, 0, 0, 0, 0, 0], [70, 20, 6, 1, 0, 0], [50, 0, 0, 0, 0, 0], [80, 8, 3, 0, 0, 0], [60, 0, 0, 0, 0, 0]],
    crash: [[70, 30, 8, 1, 1, 0], [60, 60, 14, 2, 0, 0], [60, 90, 20, 3, .8, 0], [50, 0, 0, 0, 0, 1], [90, 120, 28, 3, 0, 0], [40, 0, 0, 0, 0, 1],
      [140, 0, 0, 0, 0, 1, true], [60, 150, 34, 3, 1, 0], [70, 80, 18, 2, 0, 0], [50, 0, 0, 0, .6, 0], [90, 60, 14, 2, 0, 0],
      [60, 0, 0, 0, 0, 0], [110, 35, 10, 1, 0, 0], [80, 0, 0, 0, 0, 0], [100, 16, 5, 1, 0, 0], [90, 0, 0, 0, 0, 0], [120, 6, 2, 0, 0, 0], [80, 0, 0, 0, 0, 0]],
  };
  const GLITCH_RANK = { tick: 1, hit: 2, crash: 3 };
  let glitching = false;
  async function glitch(level) {
    const root = document.querySelector("[data-glitch-layer]");
    const stage = document.documentElement;
    const frames = GLITCH[level];
    if (!root || !stage || !frames || glitching) return;
    glitching = true;
    const still = matchMedia("(prefers-reduced-motion: reduce)").matches;
    const hit = root.querySelector(".glitch__hit"), black = root.querySelector(".glitch__black"), read = root.querySelector(".glitch__read");
    const bands = [...root.querySelectorAll(".glitch__band")];
    const noise = document.getElementById("glitch-noise"), disp = document.getElementById("glitch-disp"), ghost = document.getElementById("glitch-ghost");
    const rnd = (a, b) => a + Math.random() * (b - a);
    const clear = () => {
      stage.style.filter = "";
      hit.style.opacity = 0; black.style.opacity = 0; read.style.opacity = 0;
      bands.forEach((b) => { b.style.display = "none"; });
    };
    const list = still ? [[frames[0][0] * 4, 0, 0, 0, 1, 0], [10, 0, 0, 0, 0, 0]] : frames;
    for (const [ms, tear, g, nb, h, b, text] of list) {
      if (!still) {
        stage.style.filter = tear || g ? "url(#glitch-tear)" : "";
        noise.setAttribute("seed", Math.floor(rnd(1, 9999)));
        disp.setAttribute("scale", tear);
        ghost.setAttribute("dx", g * (Math.random() < .5 ? -1 : 1));
        black.style.opacity = b;
        read.style.opacity = text ? 1 : 0;
        bands.forEach((el, i) => {
          el.style.display = i < nb ? "block" : "none";
          if (i < nb) { el.style.top = rnd(0, 92) + "%"; el.style.height = rnd(1, i ? 4 : 9) + "%"; }
        });
      }
      hit.style.opacity = h;
      hit.style.setProperty("--glitch-edge", `calc(var(--s-1) * ${1 + h})`);
      await new Promise((r) => setTimeout(r, ms));
    }
    clear();
    glitching = false;
  }
  // The server's say-so: whatever it put on the body for this page, and the strongest new log line.
  let glitchPlayed = false;
  function glitchOnLoad() {
    if (glitchPlayed) return;
    // A jack in or out already played its glitch beside its sound, on the page that was leaving (see data-glitch-submit).
    let skip = false;
    try {
      skip = Date.now() - Number(sessionStorage.getItem("glitch-skip") || 0) < 15000;
      sessionStorage.removeItem("glitch-skip");
    } catch (err) { /* storage may be off */ }
    if (skip) delete document.body.dataset.glitch;
    const levels = [document.body.dataset.glitch, ...[...document.querySelectorAll(".log-new[data-fx]")].map((n) => n.dataset.fx)].filter(Boolean);
    if (!levels.length) return;
    glitchPlayed = true;
    delete document.body.dataset.glitch;
    glitch(levels.sort((a, b) => (GLITCH_RANK[b] || 0) - (GLITCH_RANK[a] || 0))[0]);
  }
  on("click", "[data-glitch-demo]", (e, btn) => glitch(btn.dataset.glitchDemo));
  glitchOnLoad();
  document.addEventListener("DOMContentLoaded", glitchOnLoad);

  /* --- The blinking marker on the City map -----------------------------------
     <span data-traveller data-x1 data-y1 data-x2 data-y2 data-remaining data-total>: slides from (x1,y1) to (x2,y2), in board
     percent, finishing when `remaining` seconds are gone of `total`. Remaining, never an absolute time, as [data-countdown]. */
  function travellers() {
    document.querySelectorAll("[data-traveller]").forEach((el) => {
      if (el.dataset.moving) return;
      el.dataset.moving = "1";
      const d = el.dataset, total = Math.max(1, Number(d.total)) * 1000, left = Math.max(0, Number(d.remaining)) * 1000;
      const at = (t) => ({ left: `${Number(d.x1) + (Number(d.x2) - Number(d.x1)) * t}%`, top: `${Number(d.y1) + (Number(d.y2) - Number(d.y1)) * t}%` });
      Object.assign(el.style, at(1 - left / total));
      if (!el.animate || matchMedia("(prefers-reduced-motion: reduce)").matches) return;
      el.animate([at(1 - left / total), at(1)], { duration: left, easing: "linear", fill: "forwards" });
    });
  }
  travellers();
  document.addEventListener("DOMContentLoaded", travellers);

  /* --- The run screen, without a reload ------------------------------------------
     A page cannot keep sound playing across a navigation, and the run screen used to navigate after every turn, so its music
     restarted each time and waited for a press. Here the run screen's own forms (a turn, a move, an install) are sent in the
     background and the page's header numbers, flash and content are swapped for what the server answers, so the document, its
     audio context and the loop all stay up. Anything else the answer turns out to be (the run has ended and it is redirecting to
     the map, an error) is an ordinary navigation to where it went. The screen asking for itself again while a party waits is
     [data-run-refresh="SECONDS"] on the watch box, in place of a `Refresh` header. */
  let runTimer = null;
  let soundHeldUntil = 0;   // when the sound of a press that leaves the page (jacking out) is over
  const reinit = () => {
    glitchPlayed = false;
    [fillPrefixesAll, serverToasts, hotMeters, centreMap, targetPickers, modifierRows, timeFlashes, passClocks, ownerLoadout, runWatch,
      turnForms, treeLife, countdowns, tabsMore, glitchOnLoad, travellers].forEach((f) => f());
  };
  function fillPrefixesAll() { fillPrefixes(document); }
  async function softLoad(url, init) {
    clearTimeout(runTimer);
    let res;
    let html;
    try {
      res = await fetch(url, { credentials: "same-origin", ...init });
      html = await res.text();
    } catch (e) {
      return location.reload();   // No answer: the hard way, which says so itself.
    }
    const doc = new DOMParser().parseFromString(html, "text/html");
    if (!res.ok || new URL(res.url).pathname !== location.pathname || !doc.querySelector("[data-run-watch]")) {
      if (res.redirected || !init) {
        // Leaving the run screen: the loop fades out first, as the scan window's does.
        // The run ended on a success (an egress, a burn, a backdoor landed): the page it lands on says so, and it is heard out.
        if (doc.body.dataset.cue === "success") soundHeldUntil = performance.now() + Math.min(sfx.success(), 2000);
        return setTimeout(() => location.assign(res.url), Math.max(sfx.runOut() ? 500 : 0, soundHeldUntil - performance.now()));
      }
      document.open();   // A refusal or an error that came straight back: show it.
      document.write(html);
      document.close();
      return;
    }
    // The bar's dials and chips, one for one (the sound toggle has handlers of its own and is left alone).
    const bar = "header.topbar [role=meter], header.topbar .chip-stat:not([data-sound-toggle])";
    const now = document.querySelectorAll(bar);
    const next = doc.querySelectorAll(bar);
    if (now.length === next.length) now.forEach((el, i) => el.replaceWith(next[i]));
    ["[data-flash-host]", "main"].forEach((sel) => {
      const a = document.querySelector(sel);
      const b = doc.querySelector(sel);
      if (a && b) a.innerHTML = b.innerHTML;
    });
    document.title = doc.title;
    if (doc.body.dataset.glitch) document.body.dataset.glitch = doc.body.dataset.glitch;
    if (res.url !== location.href) history.replaceState(null, "", res.url);
    reinit();
    armRunRefresh();
  }
  function reloadPage() {
    if (document.querySelector("[data-run-watch]")) softLoad(location.href); else location.reload();
  }
  function armRunRefresh() {
    clearTimeout(runTimer);
    const secs = Number(document.querySelector("[data-run-refresh]")?.dataset.runRefresh);
    if (secs > 0) runTimer = setTimeout(() => softLoad(location.href), secs * 1000);
  }
  /* A form that LEAVES the page with a sound of its own (<form data-sound-submit="jack"> jacking out, "jack-in" jacking in): the sound starts on the
     press and the page waits it out, up to two seconds, since a navigation would cut it off. On the run screen the soft submit
     below does the waiting; anywhere else this holds the submit back and sends it again when the sound is done. */
  document.addEventListener("submit", (e) => {
    const form = e.target;
    const cue = { jack: sfx.jack, "jack-in": sfx.jackIn }[form.dataset.soundSubmit];
    if (!cue || form.dataset.soundDone) return;
    const ms = Math.min(cue(), 2000);
    soundHeldUntil = performance.now() + ms;
    // The page's glitch starts WITH the sound, not after it: the server would draw it on the next page, once the sound had
    // ended, so the next page is told (sessionStorage, read by glitchOnLoad) that it has been played. Only when the press is
    // being held for a sound: a submit that goes straight through leaves the glitch to the next page, as ever.
    if (ms > 0 && form.dataset.glitchSubmit) {
      try { sessionStorage.setItem("glitch-skip", String(Date.now())); } catch (err) { /* then it plays twice; no harm */ }
      glitch(form.dataset.glitchSubmit);
    }
    if (document.querySelector("[data-run-watch]") && form.closest("main")) return;
    if (ms <= 0) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    form.dataset.soundDone = "1";
    setTimeout(() => form.requestSubmit(e.submitter), ms);
  }, true);
  document.addEventListener("submit", (e) => {
    const form = e.target;
    // Attributes, not form.action / .method / .target: a field NAMED `action` (the turn buttons') shadows the property.
    // The pressed button's own `formaction` wins (Disarm and Install carry their node and kind in it), as it does in a real submit.
    const action = e.submitter?.getAttribute("formaction") || form.getAttribute("action");
    if (e.defaultPrevented || !document.querySelector("[data-run-watch]") || !form.closest("main") || form.getAttribute("target")
      || (form.getAttribute("method") || "").toLowerCase() !== "post" || !action || new URL(action, location.href).origin !== location.origin) return;
    e.preventDefault();
    const body = new FormData(form);
    if (e.submitter?.name) body.append(e.submitter.name, e.submitter.value);
    // Held while it is away: a second press would be a second turn.
    setTimeout(() => form.querySelectorAll("button, input[type=submit]").forEach((b) => { b.disabled = true; }), 0);
    softLoad(new URL(action, location.href).href, { method: "POST", body });
  });
  armRunRefresh();

  window.UI = { glitch, toast, flash, meter, log, type, esc, captcha, centreMap, modifierRows, nodeGraph };
})();
