/* The encounter editor's graph: reads the form AS IT IS NOW (saved or not) and hands a spec
   to UI.nodeGraph. Redraws while the accordion is open, as fields change or rows are added.

   Edges are choices. A choice with no roll is one plain edge to "Leads to"; one that rolls is
   a pass edge to "On pass, to" and a fail edge to "On fail, to", each falling back to "Leads to".
   A screen is a bare glyph: its card (key, text, and one row per button) is floating on hover and
   a modal on tap, via the kit's inspector.
   A choice that leads nowhere closes the encounter and draws nothing, and an ending's choices
   are ignored, as the editor ignores them. A key that names no screen is skipped. */
(function () {
  "use strict";

  const box = document.getElementById("encounter-graph");
  const accordion = box && box.closest("details");
  if (!box || !accordion || !window.UI) return;

  const t = (name) => box.dataset["text" + name] || "";
  const form = box.closest("form") || document.querySelector("form[action*='encounters']");
  const field = (name) => form.elements[name];
  const value = (name) => ((field(name) || {}).value || "").trim();

  function spec() {
    const nodes = [];
    const byKey = new Map();
    const entryAt = (form.querySelector("input[name='entry']:checked") || {}).value;

    form.querySelectorAll("input[name]").forEach((input) => {
      const m = input.name.match(/^nodes\[(\d+)\]\[key\]$/);
      if (!m) return;
      const i = m[1], key = input.value.trim(), body = value(`nodes[${i}][body]`);
      // Empty, or still only the "<slug>_" default with nothing written: an untouched blank row.
      if (key === "" || (body === "" && key === (input.dataset.applied || ""))) return;
      if (byKey.has(key)) return;
      const ends = !!(field(`nodes[${i}][ends]`) || {}).checked;
      const kind = i === entryAt ? "entry" : ends ? "end" : "plain";
      const tags = kind === "entry" ? [t("Entry")] : kind === "end" ? [t("End")] : [];
      const node = { id: key, label: key, kind, i, detail: { title: key, tags, text: body.slice(0, 400) || t("Empty"), rows: [] } };
      // What the screen itself does on arrival: one line on its card per outcome, as the form reads.
      for (let k = 0; field(`nodes[${i}][outcomes][${k}][kind]`); k++) {
        const at = `nodes[${i}][outcomes][${k}]`, kindField = field(`${at}[kind]`);
        if (kindField.value === "") continue;
        // "Damage · Hit points · 5": a picked injury or damage type sits in a group named for its kind. The
        // text field repeats a picked type, so it is only shown for a kind that has no list (a flag).
        const picked = kindField.selectedOptions[0];
        const grouped = picked.parentElement.tagName === "OPTGROUP";
        const bits = [grouped ? picked.parentElement.label : picked.text, grouped ? picked.text : "", value(`${at}[amount]`), grouped ? "" : value(`${at}[ref]`)].filter(Boolean);
        node.detail.rows.push({ left: t("Arrival"), right: bits.join(" · "), tone: "" });
      }
      nodes.push(node);
      byKey.set(key, node);
    });

    const edges = [];
    nodes.forEach((node) => {
      if (node.kind === "end") return;
      for (let j = 0; field(`nodes[${node.i}][choices][${j}][label]`); j++) {
        const at = `nodes[${node.i}][choices][${j}]`;
        const label = value(`${at}[label]`);
        if (label === "") continue;
        const next = value(`${at}[next_key]`);
        const price = Number(value(`${at}[cost]`)) > 0 ? ` (${value(`${at}[cost]`)} ¤)` : "";
        const stat = value(`${at}[check_stat]`);
        // One edge per way a button can go, and one row on the card for each, in the line's colour.
        const add = (to, tone, suffix, word) => {
          if (to !== "" && byKey.has(to)) edges.push({ from: node.id, to, tone, title: `${label}${price}${suffix}` });
          node.detail.rows.push({
            left: tone === "plain" ? `${label}${price}` : `${label}${price} · ${t("Roll")} ${stat}`,
            right: (word ? word + " " : "") + (to === "" ? t("Closes") : "→ " + to),
            tone: tone === "plain" ? "" : tone,
          });
        };
        if (value(`${at}[fight_npc_id]`) !== "") {
          // A fight goes three ways, by how it ends; none of them falls back to "next".
          add(value(`${at}[win_key]`), "pass", " — " + t("Win"), t("Win"));
          add(value(`${at}[lose_key]`), "fail", " — " + t("Lose"), t("Lose"));
          add(value(`${at}[flee_key]`), "plain", " — " + t("Away"), t("Away"));
        } else if (stat !== "") {
          add(value(`${at}[pass_key]`) || next, "pass", " — " + t("Pass"), t("Pass"));
          add(value(`${at}[fail_key]`) || next, "fail", " — " + t("Fail"), t("Fail"));
        } else {
          add(next, "plain", "");
        }
      }
    });

    const root = (nodes.find((n) => n.kind === "entry") || nodes[0] || {}).id;
    return { root, nodes, edges, looseLabel: t("Loose") };
  }

  let timer = null;
  const draw = () => {
    if (!accordion.open) return;
    const s = spec();
    box.hidden = s.nodes.length === 0;
    box.nextElementSibling && (box.nextElementSibling.hidden = s.nodes.length === 0);
    window.UI.nodeGraph(box, s);
  };
  const later = () => { clearTimeout(timer); timer = setTimeout(draw, 150); };

  accordion.addEventListener("toggle", draw);
  form.addEventListener("input", later);
  form.addEventListener("change", later);
  // A row added by a "+" has no input event of its own.
  form.addEventListener("click", (e) => { if (e.target.closest("[data-add-row]")) setTimeout(later, 0); });
  draw();
})();
