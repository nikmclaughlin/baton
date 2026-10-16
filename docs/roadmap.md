# Baton roadmap

Re-sequenced 2026-10-13: **B-series (engine) first, E-series (editor polish) deferred.**
Rationale: keep momentum on functional capability (persistence, external execution, scheduling).
The editor is a fiddly time-sink; do it once there are functional backend pieces in hand to play with.

## Order

1. **H0 — merge `test-coverage` → `main`** *(in progress)*
   - 6-step sequence in `ASSESSMENT-test-coverage.md` (repo root, untracked).
   - Wrap-up after merge: delete `HANDOFF.md` + `ASSESSMENT-test-coverage.md`;
     pin WP 6.9 registration-gate behavior in a test; decide JS test infra (wp-scripts jest / vitest / Playwright).

2. **B1 — workflow output persistence** *(keystone)*
   - **Decide storage before coding:** custom table vs options/transients.
   - **Constraint: additive only** — do not reshape the run report that `admin-run.js` / editor badges consume.
   - Results keyed by step ID.

3. **B2 — external execution** (needs B1)
   - Flip `show_in_rest` on workflow abilities → core `/abilities/v1/{name}/run`; auth hardening.

4. **B3 — CLI + Command Palette** — thin wrappers over B2.

5. **B4 — ActionScheduler scheduling** (needs B1 only; order vs B2/B3 flexible).

6. **WordPress.org release** — trigger: B1 landed. Pending slug approval (docs/TODO-wordpress-org-svn-deploy.md).

7. **E1+E2 — canvas overhaul** (docs/canvas-overhaul-plan.md) — deferred until B-side momentum pauses or the urge to fiddle returns.
   - E1 (#3): chain reordering via node drag. E2 (#4): mapping auto-suggest via ComboboxControl.
   - Editor changes → always `npm run build` + commit `build/`.

## Floaters

- **W1 code snippet nodes** — engine-side, anytime, gated on security review.
- **DAG** — deferred until designed as its own doc; linear chain = degenerate DAG, so boundary work survives a future engine.

## Locked principles

- Workflow definition is the single source of truth; the canvas is a view, not a format.
- Persistence keyed by step ID; boundary code speaks only in workflow IDs and step IDs — never hardcode `previous` as a mapping source.
- Grouped ability lists: native `<select>` + `<optgroup>`; `SelectControl` only for flat lists.
- WP 6.9 gate: `wp_register_ability` only during `wp_abilities_api_init`; ability appears on the NEXT request after save; tests use `setExpectedIncorrectUsage`.