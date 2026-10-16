# B1 Brief A — Implementation: storage, run IDs, abilities + tests

You are the implementation agent for Baton (WordPress plugin, this repo).
Read `docs/b1-persistence-spec.md` FIRST — it is the complete, agreed design.
Follow it exactly. The design decisions are settled; do not redesign.

## Scope (spec build order, steps 1–3 only)

1. **Storage class** (`includes/class-saved-values.php`, name to match repo style):
   - Custom table per spec columns; `dbDelta` on plugin activation (hook into the
     existing activation flow in `includes/class-plugin.php`); drop on uninstall
     (check for existing `uninstall.php` / deactivation patterns and follow them).
   - Methods: append (save/log), upsert (state, keyed workflow_id+label+kind),
     newest-wins lookup (label, optional workflow_id/run_id), delete row / delete run.
     ~1MB value cap with truncated-write flag in the returned summary.
2. **run_id threading** in `Baton_Workflow_Runner`: generate per execution
   (uuid-ish, VARCHAR(64)), thread to steps, add `run_id` to the run report as an
   ADDITIVE key. Do NOT rename/move existing report keys (`success`, `steps`,
   `steps[].success`, `steps[].output`) — that shape is a contract.
3. **Four ability classes** + registration at `wp_abilities_api_init`
   (`baton/save-output`, `baton/save-state` display "Keep latest",
   `baton/load-output`, `baton/log`) under category `baton-utility`, label
   "Baton Utilities". Contracts exactly per spec (Load not-found = loud step
   failure via WP_Error). Register in the same pattern as
   `Baton_Workflow_Abilities` (same init action, same 6.9 gate).

## Hard rules

- PHP strict_types; match existing `Baton_*` class style.
- WP 6.9 gate test pattern: `setExpectedIncorrectUsage` PER-TEST, never in
  `set_up()` — expectations are two-way promises (see Test_Rest_Save_Workflow.php).
- Tests live in `tests/php/Test_*.php`. Cover the spec's "Tests" section for your
  scope: storage (append, upsert, newest-wins, not-found, size cap), ability
  contracts (save→load same run, cross-run load, state upsert, log shape),
  runner (run_id consistent, report old keys byte-stable).
- Editor (`src/`), viewer, and docs are OUT OF SCOPE for this brief. No JS changes.
- If the spec is ambiguous, make the minimal assumption and note it in your
  final report — do not stall.

## Definition of done (run all, all green)

```bash
npm run test:php   # all green incl. the existing 201 tests
composer check     # phpstan clean
npm run check      # static checks
```

## Git

- Work on branch `b1-persistence` (already checked out).
- Commit locally in small logical commits. NEVER push — the main session reviews
  and owns the PR.

## When finished

Stop and report: files added/changed, test count, any spec deviations or
assumptions. Do not start viewer/editor work — that is Brief B, assigned later.