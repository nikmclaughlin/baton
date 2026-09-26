# Node-based workflow editor — design plan

## Problem

The workflow editor is a **vertical stack of step cards** (`src/editor/App.js`): each ability is a card, and data filters live in connector slots between cards. This works well for 2–5 steps, but the roadmap already calls for an "advanced drag-and-drop workflow building UI". A linear list has no spatial memory (where is the step that calls the GitHub API?), no overview at scale, and every reorder is a Move up/Move down button press.

The goal: a **node-based drag-and-drop canvas** — abilities as nodes, data filters as the edges between them — using [React Flow](https://reactflow.dev/) as the canvas library, while keeping everything inside the nodes native WordPress.

**Critical constraint:** `Baton_Workflow_Runner` (includes/class-workflow-runner.php) executes steps **strictly sequentially**. Until branching/DAG execution exists, the graph we draw must still represent a linear chain. This plan ships the canvas **without changing any runtime semantics** — the runner, the definition format, and the save contract stay untouched.

## Design principle

> **The canvas is a view of the definition, not a new format.**
> The workflow definition JSON remains the single source of truth. Node positions are presentation metadata stored separately. Edges are derived from the definition, not stored.
>
> **Phase 1–3 draws the same workflow we have today, just spatially.** Nodes and edges carry exactly the data the chain editor carries today. True DAG/branching execution is a later, separate design (it needs topological scheduling, join semantics, and loop interplay — it must not ride along with a UI migration).

---

## 1. Library decision: React Flow (`@xyflow/react`)

| Fact | Value |
|------|-------|
| Package | `@xyflow/react` (v12+, the current React Flow) |
| License | MIT |
| Maintainer | xyflow (formerly webkid), actively maintained |
| React requirement | peer-dep `react >= 17` — WordPress 6.9 ships React 18.3, fine |
| Required CSS | `import '@xyflow/react/dist/style.css'` — under wp-scripts this is emitted as a CSS chunk we enqueue |
| Sizing | `<ReactFlow />` requires a parent with explicit width/height (the mount root gets a fixed height) |
| State model | Controlled: `onNodesChange`/`onEdgesChange`/`onConnect` with `applyNodeChanges` / `applyEdgeChanges` / `addEdge` |
| References | Official templates "Workflow Editor" and "AI Workflow Editor" |
| Attribution | Free tier renders a small attribution badge; removing it is a paid Pro feature (MIT permits keeping it) |

**Why React Flow and not hand-rolled or another lib:** pan/zoom viewport, bezier edge geometry, connection drag + hit-testing, handle snapping, auto-pan at canvas edges, minimap, and keyboard-accessible canvas interaction are all solved, tested, and ~100 KB of dependency versus ~2–3k LOC of permanent custom maintenance.

## 2. Why not pure WordPress components

We evaluated building the canvas entirely with native components. `@wordpress/components` has only **page-level** drag helpers:

- `Draggable` — moves an element by deltas, no coordinate system, no edges.
- `ResizableBox` — resizing, not positioning.

There is **no pan/zoom node-graph primitive** in Gutenberg's component library. Hand-building it means owning viewport transforms, edge path geometry (bezier between handles), connection drag state and hit-testing, snapping, auto-pan, minimap, and an undo-friendly state model — all of which React Flow provides, battle-tested, today.

**Verdict:** pure WP components = no. WP components everywhere *except* the canvas = yes.

## 3. Hybrid architecture — who owns what

| Layer | Owner | Components / notes |
|-------|-------|--------------------|
| Canvas, viewport, pan/zoom, edges, connection drag | React Flow | `<ReactFlow>`, custom node/edge types, `Background`, `Controls`, `MiniMap` |
| Everything *inside* a node | `@wordpress/components` | Reused from the current editor: ability `<select>` with `<optgroup>`s (project convention — native select, not `SelectControl`), IO chips, loop panel |
| Modals (data filter, IO schema/static input) | `@wordpress/components` | `DataFilterPanel` and `IoDetailPanel` reused **unchanged** — they are modals, they don't care what opened them |
| Toolbar (add input, add step, run, view toggle) | `@wordpress/components` | `Button`, `ToggleGroupControl` |
| Notices / intro copy | `@wordpress/components` | Existing `Notice` |

This keeps the wp-admin look, i18n (`__()` via `@wordpress/i18n`), and accessibility for all the *content*, while React Flow handles only the one thing WordPress has no primitive for.

## 4. Non-negotiables (what must not change)

| Contract | Status |
|----------|--------|
| `Baton_Workflow_Runner` execution order + loop semantics | **Untouched** through Phase 3 |
| Definition JSON shape: `initial_input`, `steps[] { ability, input, use_previous_output, input_mappings, loop }` | **Untouched** (one additive exception below) |
| Hidden-field save contract: editor syncs `JSON.stringify(definitionFromSteps(steps, initial_input))` into `#workflow_definition`, exposes `window.BatonEditorSync` | **Untouched** |
| `Baton_Workflow_CPT::sanitize_definition()` whitelist | Untouched except: optionally preserve a new stable step `id` (see §6) |
| Hooks `baton_before_step` / `baton_after_step` | Untouched |
| Ability catalog contract (`input_summary`, `output_paths`, `source_selectable`, …) | Untouched |

## 5. Graph model — `src/editor/graph.js`

New pure module with **bidirectional adapters**. The node editor never talks to the definition directly; the chain editor never talks to nodes/edges.

```
definition ──toGraph()──▶ { nodes, edges }   (React Flow state)
{ nodes, edges } ──toDefinition()──▶ definition   (hidden field, save)
```

- **Node types**
  - `abilityNode` — one per step. Body reuses `AbilitySelect`, IO chips, and `LoopPanel` from the current editor nearly verbatim. Header shows step label + slug.
  - `inputNode` — a single source node for `initial_input` (id: `input`). This incidentally resolves a known roadmap gap: the workflow's initial input is currently not editable from the UI at all.
- **Edge type** — custom `mappingEdge`. It is *derived*, never stored:
  - Renders `connectorSummary( mappings, downstreamAbility )` chips along the edge (the same summary the current `DataFilterNode` shows).
  - Click opens the existing `DataFilterPanel` modal unchanged; "clear mappings" removes the rows — mappings live in the step's `input_mappings` exactly as today.
  - `isValidConnection` validates against the ability catalog (`source_selectable` / `target_selectable`) and **blocks cycles**.
- **Step order** = topological order of the single linear edge chain. `toDefinition()` back-derives the `steps` array by walking the chain from `inputNode`.
- **Linearity enforcement (Phase 1–3):** the graph must remain a single chain. The editor rejects a second outgoing edge from any node and a second incoming edge to any node ("this workflow runs top-to-bottom; branching comes later" notice). This keeps the serialized definition a valid sequential `steps` array for the sequential runner.

Legacy step field `use_previous_output` continues to round-trip untouched (stack view shows it as today; node view treats it as an implicit full-output mapping when no explicit rows exist).

## 6. Layout persistence — `_baton_editor_layout`

Node positions are **not** part of the definition. New separate post meta:

```
_baton_editor_layout = {
  "input":   { "x": 40,  "y": 240 },
  "step-a1": { "x": 260, "y": 240 }
}
```

- Registered and sanitized in `Baton_Workflow_CPT`: array of `node_id → { x, y }`, floats only, ids sanitized strings. Unknown keys dropped.
- **Stable node identity.** Step nodes need ids that survive insertion/reordering — index-based ids (`step-2`) would scramble saved positions the moment a step is inserted. Decision (see Decisions): add an optional stable `id` string to each step in the definition. This is the one deliberate, backwards-compatible change to the definition format: the runner ignores it, `sanitize_definition()` whitelists it (sanitized, length-capped), the stack editor keeps existing ids when present and generates one for new steps.
- **Fallback:** if `_baton_editor_layout` is missing or its node ids don't match the definition, the graph renders with a computed **auto-layout** (fixed-x vertical stack for a linear chain — no dagre dependency needed while graphs are linear). Old workflows load perfectly with zero migration.

## 7. View toggle (node | stack) — built in Phase 0

Both view shells exist **from the start**, in a clean space:

- **UI:** a segmented control in the editor toolbar — `ToggleGroupControl` with two options: **Node canvas** / **Stack**. One click switches views; no page reload, no loss of editor state.
- **Shared state parent:** the current `App.js` state (`steps`, `initial_input`, modals) is lifted into a thin shell component. The existing chain UI becomes `StackView` (identical behavior, now props-driven), and the new `NodeView` renders from the same state via the graph adapters. Both views are live views of one state; toggling is instant and lossless.
- **Default + user preference:** server-side filter `baton_editor_mode` (`node | stack`, default `node`) sets the **default** view, passed to JS via the bootstrapped editor data. The toggle choice is a per-user browser preference (localStorage), not saved into the workflow — a workflow's layout is site data, the view is a person's comfort.
- **Why Phase 0 and not later:** scaffolding both shells up front means every subsequent phase is developed *inside* the toggle (we're never wedging a second view into a single-view architecture), and the stack editor remains a first-class, working fallback for the entire rollout rather than a deprecated afterthought.

## 8. Implementation order

| Phase | Scope | Files |
|-------|-------|-------|
| **0 — Spike** | Dependency + toggle + both shells | `package.json`, `src/index.js`, `src/editor/App.js` → shell + `StackView.js`, `src/editor/NodeView.js`, `assets/baton-editor.css`, `includes/admin/class-admin.php` |
| **1 — Data model** | Graph adapters + layout meta + PHP | `src/editor/graph.js`, `src/editor/utils.js`, `class-workflow-cpt.php`, `Test_Workflow_Cpt.php` |
| **2 — Canvas editor** | Full editing in node view | `src/editor/NodeView.js`, `graph.js`, `App.js`, `assets/baton-editor.css` |
| **3 — Parity & polish** | Run-report badges, cleanup, docs | `assets/admin-run.js`, `assets/baton-editor.css`, `AGENTS.md`, README |
| **4 — Stretch (DAG)** | Branching execution | separate design doc |

### Phase 0 — spike (deliverables)

1. Add `@xyflow/react` to `package.json` dependencies.
2. Restructure mount: `src/index.js` renders a shell that owns definition state + the view toggle; `StackView` is today's chain editor unchanged; `NodeView` is a minimal spike — a fixed-height canvas rendering the current definition as one `inputNode` + `abilityNode`s connected by a single `mappingEdge` (draggable, read-only editing is fine at this stage).
3. Toggle UI (`ToggleGroupControl` node | stack) with `baton_editor_mode` as the server-side default.
4. Run `npm run build`, commit `build/` (project convention).

**Verification checklist for the spike:**

- [ ] wp-scripts bundles `@xyflow/react` with core's React (externals resolve; no duplicate React in the bundle) — check `build/index.asset.php` deps and bundle contents.
- [ ] `@xyflow/react/dist/style.css` is emitted as a CSS chunk and enqueued alongside `build/index.css`.
- [ ] Bundle-size delta measured and acceptable (expect ~60–100 KB min+gzip; record the real number).
- [ ] Canvas renders inside `#baton-editor-root` with explicit height; pan/zoom/drag work in wp-admin (check for admin CSS collisions — `wp-admin` sets `box-sizing` and link colors globally).
- [ ] Stack view still saves correctly through the hidden-field contract; toggling views does not lose state; `npm run check` passes.
- [ ] Attribution badge: **keep the default badge** during the spike/rollout (it is MIT-legal to keep; removing is a paid Pro feature and hiding it via CSS violates xyflow's terms). Revisit only if the wp.org review flags it.

### Phase 1 — data model & layout persistence

- `graph.js` adapters + auto-layout fallback, unit-tested logic (pure functions).
- `_baton_editor_layout` registration, sanitization, and tests (`Test_Workflow_Cpt.php`: valid layout preserved; malformed/unknown ids dropped; missing → no meta).
- Step `id` pass-through in `sanitize_definition()` + tests (missing ids tolerated; runner unaffected).
- Node view still read-only-ish; positions persist.

### Phase 2 — canvas editor

- `abilityNode` gains the full body (ability select, IO chips, static input editor via the existing `IoDetailPanel`, loop panel).
- `mappingEdge` with `connectorSummary()` chips; click → `DataFilterPanel`; clear/edit mappings.
- `inputNode` edits `initial_input` (closes the known UI gap).
- Editing model: React Flow controlled state (`applyNodeChanges`/`applyEdgeChanges`); add node via toolbar or **drop-on-edge** (inserts between two steps, wiring mappings across); Remove = remove node + re-link neighbors into a single edge; no Move up/Move down (drag instead). Linearity + cycle guards via `isValidConnection`.
- Hidden-field sync driven by `toDefinition()` — same `#workflow_definition` contract as today.

### Phase 3 — parity & polish

- `admin-run.js`: step results rendered with node ids so the run report maps onto the canvas (badges/outline on nodes after a run).
- Auto-layout refinement if needed; CSS audit (the stack view is permanent now, so `baton-editor.css` chain styles **stay** for `StackView` — only genuinely dead styles are removed).
- Docs: README, `AGENTS.md` (record the hybrid-architecture rule and the native-select convention), screenshot updates.

### Phase 4 — DAG (stretch, separate design doc)

Branching edges, topological scheduling in the runner, join/merge semantics, loop × branch interplay, definition format for parallel steps. **Not** part of this migration; the Phase 1–3 linearity guard exists precisely to keep that door clean.

## 9. React Flow risk/concern table

| Concern | Assessment | Mitigation |
|---------|------------|-----------|
| License / attribution | MIT; free tier shows a small badge; removal is Pro | Keep badge; re-evaluate only if wp.org review objects |
| React version interop | wp-scripts externalizes `react`/`react-dom`/`react-jsx-runtime` to core's copies; `@wordpress/element` shares the same instance | Verified in spike checklist |
| Bundle size | Adds a real dependency (~60–100 KB min+gzip est.) | Measure in spike; canvas is the one place a library beats 2–3k LOC of custom code |
| CSS collisions | React Flow ships its own stylesheet; wp-admin has aggressive global styles | Enqueue the chunk; fix collisions in Phase 2 with scoped overrides |
| Sizing | `<ReactFlow>` needs explicit parent height | Fixed-height mount in the editor screen |
| A11y / keyboard | Canvas interaction is pointer-first; focus management of inner components is ours since we render WP components inside nodes | Modal-heavy interactions (data filter, IO) keep keyboard paths; node select is a native `<select>` |
| Maintenance risk | xyflow actively maintains v12; MIT; no WordPress lock-in | Canvas is isolated in `NodeView.js` + custom node/edge types — swap cost is contained |

## 10. Test & verification plan

- **PHP** (existing suite, `npm run test:php`): `Test_Workflow_Cpt` — layout meta sanitization + step `id` pass-through. No runner tests change (runner untouched).
- **JS**: no automated JS tests currently exist (project reality); `graph.js` adapters are pure functions and are the natural first candidates if we add vitest later. For now:
- **Manual smoke**: save in node view → open in stack view → identical definition; save in stack view → node view auto-layouts; old workflow (no ids, no layout meta) loads in both; run a loop workflow and confirm the run report; `npm run check` green after every phase.

---

## Decisions

1. **Hybrid, not pure React Flow and not pure WP components.** React Flow owns only the canvas; `@wordpress/components` owns everything users read or type. Native `<select>` + `<optgroup>` stays for the ability picker per project convention.

2. **No DAG until DAG is designed.** Phase 1–3 keeps the graph linear (single chain enforced in the editor) so the sequential runner's semantics are exactly preserved. Branching is Phase 4 with its own doc.

3. **Definition stays the source of truth; one deliberate addition.** Node positions live in `_baton_editor_layout`. The only definition change is an optional stable per-step `id` so saved layouts survive insertion/reordering — backwards compatible, ignored by the runner, whitelisted by `sanitize_definition()`.

4. **Edges are derived, mappings stay on steps.** `input_mappings` remain step data; edges are recomputed from the chain on load. Nothing about edge *storage* to migrate later if Phase 4 changes the graph shape.

5. **The view toggle ships in Phase 0.** Both shells are scaffolded up front behind a `ToggleGroupControl`; `baton_editor_mode` filter sets the default (`node`), the user's choice persists per browser. The stack editor is a permanent, supported view — not a deprecated fallback.

6. **Auto-layout beats migration.** Missing layout meta (or id drift) → computed vertical layout. No DB migration, no upgrade routine.

7. **Attribution badge stays.** MIT-legal to keep; hiding it via CSS violates xyflow's terms and removal is paid Pro. Revisit only if the WordPress.org review process raises it.