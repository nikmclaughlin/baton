# B1 Spec — Workflow Output Persistence (Save / Load / Log)

Status: spec agreed with maintainer; implementation pending. Pre-alpha — no
backwards-compatibility constraints, defaults may change before release.

## Goal

Ship Baton's first "utility abilities" — a `baton-utility` category of built-in
abilities registered by the plugin: **`baton/save-output`** (append a permanent
record), **`baton/save-state`** (keep the latest value per label), **`baton/load-output`**
(read saved values back into workflow steps), **`baton/log`** (write inspectable
entries). An admin viewer lists stored values per workflow, per run.

## Core design principles

1. **The fork rule** (governs the whole utility class): *parameters tune one
   semantic; a "mode" that switches which semantic applies is a separate ability.*
   This is why save is two abilities, why Load has no `source` mode, and why
   Log is one ability (no fork exists). Apply this test to every future sibling
   (delay: duration is a parameter → one ability; etc.).
2. **Save and Load are one system — "Baton's memory" — keyed by user-chosen labels.**
   No IDs are required in the common path; the primary usage is the cursor pattern
   (Load `last_cursor` → work → Save-state `last_cursor`), which works with
   zero workflow/run bookkeeping.
3. **Additive-only run report** (hard rule): the report shape is a contract with
   `admin-run.js` (results panel) and the editor badge event (`baton:run-report`).
   New keys (e.g. `run_id`) may be added; `success`, `steps`, `steps[].success`,
   `steps[].output` must never be renamed or moved.
4. **Zero runner changes to execute utilities** — they are ordinary abilities;
   the runner already runs abilities and `input_mappings` already route data
   between steps. The runner change in B1 is limited to generating/threading
   `run_id` and additive report keys.

## Identity / categories

| Source | Name | Category |
|--------|------|----------|
| Core WP | `category/slug` | core-owned (future DB abilities belong here) |
| User workflows | `baton/workflow-{id}` | `baton-workflows` |
| Baton built-ins | `baton/save-output`, `baton/save-state`, `baton/load-output`, `baton/log` | `baton-utility` (label: "Baton Utilities") |

Registered by the plugin at `wp_abilities_api_init` (WP 6.9 gate — same as
workflow abilities; tests must expect registration-gate notices per-test, never
class-wide — see the two-way promise lesson from H0).

## Storage — one custom table

Table: `{prefix}baton_saved_values` (name TBD). Single table chosen on **design
merits, not migration concern** (pre-alpha): save/state/log are semantically the
same act ("record a value during a run"), one viewer renders a unified per-run
timeline without joins, and one future retention mechanism covers all kinds.

Columns (draft):

- `id` BIGINT UNSIGNED PK AUTO_INCREMENT
- `kind` VARCHAR(16) — `save` | `state` | `log` (the fork rule made visible in data)
- `workflow_id` BIGINT UNSIGNED (indexed)
- `run_id` VARCHAR(64) — runner-generated per execution (indexed)
- `label` VARCHAR(191) — user-chosen key for save/state; for logs, the message
  (first ~190 chars, powers search + timeline headline)
- `value` LONGTEXT — JSON-encoded payload; for save/state the saved data, for
  logs the full `{message, context}`
- `step_id` VARCHAR(64) — the editor node id that wrote the row (attribution in the timeline)
- `created_at` DATETIME

**No DB-level unique constraint** — save-output deliberately allows repeated
labels (append). save-state's upsert is app-level: `SELECT … WHERE workflow_id,
label, kind='state'` → UPDATE existing row or INSERT. Unit tests must pin the
upsert semantics.

Schema lifecycle: `dbDelta` on plugin activation; table dropped on uninstall
(via uninstall cleanup routine).

## Retention

**No automatic pruning in B1.** Nothing the user deliberately saved is silently
deleted:

- `save-output` rows: permanent until deleted in the viewer.
- `save-state` rows: self-limiting (one row per workflow+label, ever).
- `log` rows: permanent until deleted; logs are the acknowledged growth
  pressure (a log step in a loop writes one row per iteration) and therefore
  the **first justified candidate for the future retention knob**.

The earlier "last-10 runs" default was **rejected**: it conflated state
(self-cleaning via upsert) with records (pruning deliberate data is a
correctness bug, not cleanup). A per-node/per-workflow retention option remains
a future, additive feature (retention field + cleanup pass; no major migration).

## Ability contracts

### `baton/save-output` — "Save output"
- Input: `label` (string, required), `value` (mixed; mapped from a previous
  step via input_mappings).
- Behavior: append row `{kind: save, workflow_id, run_id, label, value, step_id}`.
- Serves: artifact/record persistence (persist transformed data per object);
  rows scale with objects, which is correct — each save is deliberate.
- Output: row summary `{id, label, run_id, kind}` so downstream steps can chain.

### `baton/save-state` — display label "Keep latest"
- Input: `label` (string, required), `value` (mixed).
- Behavior: upsert keyed by (workflow_id, label, kind='state') — one row per
  label, newest wins.
- Serves: cursor/counter pattern; self-limiting storage.
- Output: row summary, same shape as save-output.

### `baton/load-output` — "Load saved value"
- Input: `label` (string, required), `workflow_id` (optional; defaults to the
  current workflow — cross-workflow loads pick from a workflow dropdown in the
  editor, never a raw ID), `from_run_id` (optional, advanced; only meaningful
  for save-output history).
- Behavior: newest-wins lookup by (workflow_id, label, kind IN save|state).
- Output: the decoded stored value. Not-found = **loud step failure** (never
  silent bad data).
- **Boundary (do not drift):** reads **only** values saved by Baton's save
  abilities. General site-data reads (options, postmeta, arbitrary tables) are
  out of scope; if ever warranted they ship as separate, security-reviewed
  abilities (per-source capability gating, exfiltration review) — never as a
  `source` mode on Load. Site-data reads may ultimately belong to core WP
  abilities Baton composes with.

### `baton/log` — "Log entry"
- Input: `message` (string, required), `context` (optional, mixed).
- Behavior: append row `{kind: log, label: message[:190], value: {message, context},
  run_id, step_id}`. Granularity: per-run (tagged with run_id).
- Output: `{logged: true, id}`. No output dependency expected downstream.

## Run IDs + report integration

The runner generates a `run_id` (uuid-ish) at execution start, threads it to every
step and stores it on every row; the report gains `run_id` as an **additive** key.
Utility steps appear in `steps[]` exactly like any ability step. Tests pin the
report shape: old keys unchanged, new keys additive.

## Admin viewer

- Surface: per-workflow (edit screen), newest-first runs, each run rendering a
  **unified timeline** of its save/state/log rows (kind chip, label/message,
  truncated value with expand-on-click, time). One table + one viewer is the
  point; **decision-with-revisit marker:** maintainer is lukewarm on saves and
  logs sharing the timeline — if real usage wants separation, kind-based
  filtering or tabs is trivial to add later.
- Controls: delete row / delete run (cheap, keeps users out of the DB).
- Capability: `manage_options`. Values JSON-decoded then escaped on render.
- Size guard: cap single stored value at ~1MB; a truncated write is flagged in
  the step's output summary (visible in the run report).

## Security / capability

- Ability execution gates with existing workflow-run permissions (workflows run
  under `manage_options` context today).
- No ability reads anything outside Baton's own table (see Load boundary).
- Viewer escapes all rendered values; truncation everywhere.

## Tests (PHP first; JS test infra decision still open)

- Storage class: append, upsert (one row per label after repeated save-state),
  newest-wins lookup, not-found path, value-size cap.
- Ability contracts: save→load round trip (same run), cross-run load (run 1
  saves, run 2 loads — the cursor pattern end-to-end), state upsert overwrites,
  log write shape (label truncation + context JSON), load-not-found failure.
- Runner: `run_id` present and consistent across rows/report; report old keys
  byte-stable, new keys additive.
- Registration: utility abilities registered at `wp_abilities_api_init`; WP 6.9
  gate notices expected per-test only (two-way promise).

## Build order

1. Storage class + schema lifecycle (activation/uninstall) + retention-free CRUD
   tests.
2. `run_id` threading + additive report keys (tests pin the report contract).
3. Four ability classes + registration (tests; catalog grouping verified under
   "Baton Utilities").
4. Editor/catalog verification — they are ordinary abilities and should "just
   appear"; confirm input fields render (native selects for enums per project
   convention) and input_mappings target `value` cleanly.
5. Admin viewer (+ delete controls).
6. Docs/readme updates; reassess unified-timeline decision from real usage.

## Non-goals / parked

- Per-node retention options (future additive knob; logs are the first candidate).
- Delay, format/transform, conditional-gate utilities (future siblings — each
  must pass the fork rule).
- Code-snippet nodes (W1, parked behind security review).
- General site-data reads (see Load boundary).
- Any run-report restructuring (additive only, forever).