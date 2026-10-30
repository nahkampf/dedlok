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
  /* A listing link (`market:<id>` in chat or a bio): fetch the card fresh into #listing and open it. */
  on("click", "[data-listing]", (e, btn) => {
    e.preventDefault();
    const dlg = document.querySelector("#listing");
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
  const OVERVIEW_TOKEN = { silo: "--steel", compute: "--cyan", relay: "--jade", exchange: "--brass", fortress: "--ember", gateway: "--brass", portal: "--brass" };
  on("click", "[data-overview]", (e, btn) => {
    const view = document.querySelector("[data-overview-view]");
    const canvas = view?.querySelector("canvas");
    if (!canvas) return;
    fetch(btn.dataset.overview, { headers: { Accept: "application/json" }, credentials: "same-origin" })
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
          if (d.you && n % 3 === 0) cell(d.you[0], d.you[1], (n / 3) % 2 ? white : dark);
          washed.forEach(([list, phase]) => {
            ctx.globalAlpha = 1;
            list.forEach(([x, y]) => cell(x, y, brass));
            ctx.globalAlpha = (Math.sin((n * Math.PI * 2) / 25 + phase) + 1) / 2;
            list.forEach(([x, y]) => cell(x, y, ember));
            ctx.globalAlpha = 1;
          });
        }, 80);
      });
  });
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
    fetch(box.dataset.fragment, { headers: { "X-Requested-With": "XMLHttpRequest" }, credentials: "same-origin" })
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
        .forEach((el) => { el.hidden = done; });
      dlg.querySelectorAll("[data-reading-export], [data-reading-done]")
        .forEach((el) => { el.hidden = !done; });
      // A read that filed itself has nothing left to save.
      dlg.querySelectorAll("[data-reading-export]").forEach((el) => { el.hidden = !done || !!data.filed; });

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

    // `:not(dialog)`: right after a scan starts the dialog itself carries data-reading-open (below), and without
    // this every click inside it, Run in background included, matched the dialog, closed it via data-close and
    // then reopened it here.
    on("click", "[data-reading-open]:not(dialog)", (e, btn) => {
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
     reloads shortly after the end so the page settles it (the next pass begins, or the read is whole). Seconds
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
        setTimeout(() => location.reload(), 1500);
      };
      tick();
    });
  }
  passClocks();
  document.addEventListener("DOMContentLoaded", passClocks);

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
        setTimeout(() => location.reload(), 1500);
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
     its position in the [data-range-label="#id"] span beside its label: 1 is the first word. */
  document.addEventListener("change", (e) => {
    if (e.target.matches?.("[data-autosubmit]")) e.target.form.requestSubmit();
  });
  document.querySelectorAll("input[type=range][data-labels]").forEach((range) => {
    const words = JSON.parse(range.dataset.labels), out = document.querySelector('[data-range-label="#' + range.id + '"]');
    const show = () => { if (out) out.textContent = words[Number(range.value) - Number(range.min)] ?? ""; };
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
      if (lastQuery === null || query === lastQuery) { lastQuery = query; return; }
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

  window.UI = { toast, flash, meter, log, type, esc, captcha, centreMap, modifierRows, nodeGraph };
})();
