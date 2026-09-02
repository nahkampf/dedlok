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
    const dlg = document.querySelector(btn.dataset.open);
    if (dlg && dlg.showModal) dlg.showModal();
  });
  on("click", "[data-close]", (e, btn) => {
    const dlg = btn.closest("dialog");
    if (dlg) dlg.close();
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
    el.setAttribute("aria-valuenow", Math.round(v * 100));
    // data-hot-high: heat/overload bars. data-hot-low: health/ammo bars.
    var hot =
      (el.dataset.hotHigh !== undefined && v > 0.85) ||
      (el.dataset.hotLow !== undefined && v < 0.2);
    el.classList.toggle("meter--hot", hot);
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

  window.UI = { toast, meter, log, type, esc, captcha };
})();
