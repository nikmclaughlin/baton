# Canvas overhaul — design plan (next phase, planning draft)

## Status

**Planned, not started.** This is the "proper planning" pass the #3 and #4
roadmap items were broken off into when #1 (stack-view input parity) and #2
(run-report badges) shipped. No implementation work happens until this doc is
reviewed and its open questions are decided.

## Problem

Two canvas gaps remain after the Phase 0–3 node-editor rollout:

1. **#3 — Chain reordering from the canvas.** The canvas draws steps in
   definition order, but a user cannot *reorder* the chain by dragging: node
   drags move positions in the layout only. Reordering today means either the
   stack view's Move up/Move down buttons or deleting and re-adding a step.
2. **#4 — Mapping auto-suggest from schema paths.** Data-filter mapping rows
   render as fixed `SelectControl` dropdowns fed by the `Baton_Schema_Paths`
   catalogs. That works for the pre-enumerated paths, but it silently cuts off
   anything the catalog didn't enumerate (paths deeper than
   `MAX_PATH_DEPTH = 5`, dynamic array keys). There is no way to type a value.

Goal: close both gaps so the canvas is fully self-sufficient as an editor,
without changing the definition format or the runner.

## Design principle (unchanged)

> **The definition stays the single source of truth.** Reordering edits the
> `steps` array; auto-suggest only changes *how* a path value gets into the
> row. No new meta, no new endpoints, no runner changes.

---

## Item #3 — chain reordering

### Verified current behavior (start of phase)

| Behavior | Where | State |
|---|---|---|
| Node drag moves positions only | `NodeView.onNodesChange` filters `'position'` changes into `_baton_editor_layout` | Intentional; order untouched |
| Definition order change | Remove + re-add, or stack view | Stack has `moveStep`; canvas has none |
| Drop-on-edge insertion | `NodeView.onDrop` → `insertionIndexForDrop()` | Reuses the existing index convention |
| Reorder affordances in stack | `App.js` `moveStep( index, direction )` | Also calls `setActiveFilter( null )` |

### Proposed design

- **Reorder gesture:** drag an `abilityNode` near the chain and drop it in a
  gap. While dragging, React Flow highlights candidate gaps (existing edges
  brighten); dropping on edge *k* (the edge from step *k* to step *k+1*) splices
  the dragged step before step *k+1*. Dropping on the input node's edge inserts
  at 0. This reuses the drop-on-edge UX that already exists in the canvas
  (`onDrop`, `onDragOver`) — one new gesture, one convention.
- **Definition update:** simple array splice in the shell's `setSteps`
  (`prev.slice(0, to) → dragged → prev.slice(to)` with the source removed).
- **Layout:** keep each node's position; let the auto-layout call repair
  overlaps after a reorder (call the same pruning the saved layout already
  goes through server-side, client-side, before rendering).
- **Badges:** a reorder invalidates the run report the same way any step edit
  already does (shell clears `runReport` on step changes — already wired).
- **Stack parity:** no change needed; Move up/Move down remain. Both views stay
  in sync because they edit the same state.

### Semantics to decide before building

1. **`previous` sources move with the step.** Mappings always read from
   source `'previous'`, so a moved step now reads the *new* previous step's
   output. That changes what data flows at run time — correct behavior, but
   the UX must not look like a pure cosmetic shuffle. Options: (a) rely on the
   edge summaries (they re-render live), (b) show a one-line notice before
   applying. Leaning (a) plus the badges-clearing behavior.
2. **Multi-row drag / drag of arbitrary nodes:** in scope only if free; start
   with single-node drags.
3. **Moving a loop step before/after the step whose output it loops over** is
   semantically legal but may produce empty inputs. Same mitigation as (1) —
   live edge summaries.
4. **Position bookkeeping:** after splicing, do we re-key `step-N` fallback
   layout ids? `stepNodeId()` already resolves stable ids; fallbacks only
   reappear for unsaved new steps. Confirm the prune-on-save path handles
   re-numbered fallback ids (it prunes to current definition ids, so stale
   `step-N` entries are dropped — verify in tests).

---

## Item #4 — mapping auto-suggest

### Verified current behavior (start of phase)

| Behavior | Where | State |
|---|---|---|
| Path/target picks | `DataFilterPanel` rows render `SelectControl`s when `_selectable`, read-only display otherwise | Only catalog paths are pickable |
| Path catalog depth | `Baton_Schema_Paths::MAX_PATH_DEPTH = 5` | Deeper/dynamic keys unreachable by typing |
| Custom paths | Not expressible anywhere in the UI | The gap |
| Row sanitize at save | `sanitizeMappingsForSave()` | Already tolerant of arbitrary strings |

### Proposed design

- Replace the row-level `SelectControl`s with
  `ComboboxControl` (@wordpress/components) when the ability side is
  `_selectable`: suggestions come from the catalog (`output_paths` /
  `input_targets`), and **free-typed values are allowed** — the catalog stays
  a suggestion, not a constraint. Dot-path parsing is unchanged (`Baton_Runner`
  reads the same shape).
- Keep the read-only displays for non-selectable (scalar / raw JSON) sides —
  no change.
- Keyboard flow for free-typed custom paths needs a small validation pass
  (no leading/trailing dots, valid `[-_a-z0-9]` segments — mirror
  `Baton_Schema_Paths`'s own conventions) with an inline warning, not a block.
- No PHP changes: the catalogs, `_selectable` flags, and sanitize behavior
  already cover this.

### Semantics to decide before building

1. **Should free-typed values be silently allowed or opt-in per ability?**
   Leaning: allowed everywhere the side is selectable; the warning banner
   covers the rest.
2. **Suggest vs full list:** keep suggestions below, say, 20 entries; for
   longer catalogs combobox search makes the flat list irrelevant.
3. **Deeper-than-depth-5 paths:** typing works (it's a string), but the schema
   catalog cannot validate them — show the existing "schema hint" copy when
   the typed path isn't in the catalog.

---

## Delivery

| Phase | Item | Notes |
|---|---|---|
| A | #3 chain reordering | NodeView + graph.js helpers; full gate battery (build, lint:js, lint:css, composer check, test:php) |
| B | #4 mapping auto-suggest | components.js only; same gates |

## Non-goals

- DAG execution / branching (separate design doc per the locked Phase-4
  decision in [node-editor-plan.md](node-editor-plan.md)).
- Code snippet nodes (new execution concept + security review).
- External workflow execution (`show_in_rest` flip — separate decision).
- Undo/redo history in the editor.