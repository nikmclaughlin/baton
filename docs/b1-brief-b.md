# B1 Brief B — Editor/catalog verification, admin viewer, docs

You are the implementation agent for Baton (WordPress plugin, this repo),
continuing on branch `b1-persistence`. Brief A (storage class, run_id
threading, four utility abilities, tests) is implemented and has passed the
orchestrator's review gate — all tests and static checks green. Read
`docs/b1-persistence-spec.md` FIRST, especially §Admin viewer. Contracts are
settled; do not redesign.

## Scope (spec build order, steps 4–6 only)

1. **Editor/catalog verification + context-field hiding**
   - The four utilities should already appear under "Baton Utilities" in the
     catalog (smoke-tested in Brief A). Verify in the real editor UI: input
     fields render per schema, native `<select>` + `<optgroup>` for grouped
     lists (project convention), and `input_mappings` target the `value`
     field cleanly.
   - The utility abilities declare runner-filled context fields (`run_id`,
     `workflow_id`, `step_id`) in their input schemas with an
     `x-baton: context` marker (Brief A assumption #1). Hide these fields
     from all step input UIs and from the mappable-path catalog — they are
     filled by the runner, never by users. Do NOT remove the schema fields
     themselves (runner context injection depends on them).
   - Any editor polish for the utilities must be minimal and additive.
     `src/` changes require `npm run build` and committing updated `build/`.

2. **Admin viewer (spec §Admin viewer)**
   - New admin surface on the workflow edit screen, below the editor:
     per-workflow, newest-first runs, each run rendering a unified timeline
     of its save/state/log rows — kind chip, label/message, truncated value
     with expand-on-click, time.
   - Controls: delete row / delete run. Pick the minimal WP-idiomatic
     mechanism consistent with existing admin patterns (capability
     `manage_options`, nonce, follow the conventions in
     `includes/class-rest-controller.php` / `includes/admin/class-admin.php`)
     and note your choice as an assumption in the final report.
   - Add read/aggregate methods to `Baton_Saved_Values` as needed
     (additive only — do not change append/upsert/find semantics).
   - Rendering: values JSON-decoded then escaped on render; truncation
     everywhere; `manage_options` capability on every path.
   - Do NOT touch the runner or the run-report shape (additive-only,
     forever).

3. **Docs/readme updates**
   - README.md + readme.txt: the four utility abilities, the cursor pattern
     (load `last_cursor` → work → save-state `last_cursor`), run_id, and the
     admin viewer.
   - AGENTS.md: add the new classes + viewer to the project layout and
     concepts sections, matching existing style.
   - Keep the spec's "decision-with-revisit" note on the unified timeline.

## Hard rules

- PHP `declare( strict_types=1 );`, match existing `Baton_*` class style.
- No redesign of settled contracts; Load boundary unchanged; no retention or
  pruning features (viewer deletes are explicit user actions, not pruning).
- Tests for new PHP logic (viewer data assembly, delete handling) in
  `tests/php/Test_*.php`, following existing patterns. WP 6.9 gate
  expectations per-test only if needed (Brief A needed none). The existing
  240 tests must stay green and the run-report contract keys unchanged.

## Definition of done (run all, all green)

```bash
npm run test:php   # all green incl. the existing 240 tests
composer check     # phpstan clean
npm run check      # static checks
npm run build      # if src/ changed; commit updated build/
```

## Git

- Same branch `b1-persistence`. Small logical commits. NEVER push — the
  orchestrator reviews and owns the PR.

## When finished

Run this shell command so the human gets a desktop notification:
`osascript -e 'display notification "Brief B complete - review ready" with title "Baton B1"'`
Then stop and report: files added/changed, test counts, any spec deviations or
assumptions. Do not start any other work.