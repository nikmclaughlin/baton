# B2 — External workflow execution (REST)

Scope locked with the user 2026-10-15. Companion kickoff: `docs/agent-templates/implementation-brief.md`.

## Locked decisions

1. **Per-workflow opt-in toggle** — publishing a workflow does NOT expose it externally. The toggle is designed for future per-surface exposure (REST now; CLI/MCP later), so exposure is stored as a **surface map**, not a boolean.
2. **Permissions unchanged** — ability `permission_callback` stays `current_user_can( 'manage_options' )`. External callers authenticate as WP admins (application passwords for machine callers). No new capabilities, no tokens, no rate limiting in this phase.
3. **External runs are logged** — every externally triggered run writes a log row visible in the saved-values viewer.
4. JS test infra is **out of scope** (issue #7).

## Work items

- **Exposure meta**: new workflow meta `_baton_external_surfaces` — an array of enabled surface slugs (`[ 'rest' ]` to start; room for `cli`, `mcp`). Sanitize in `Baton_Workflow_CPT` alongside definition/layout meta. Empty array (default) = not exposed.
- **Registration**: in `Baton_Workflow_Abilities::register_workflow_ability()`, set the ability's `'show_in_rest'` meta annotation to `true` only when `rest` is in the surface map for that workflow. Everything else about registration is unchanged.
- **Editor UI**: an "Expose externally (REST)" checkbox in the workflow editor (stack + node views as applicable), synced through the existing hidden-field/REST-save contract, saved into the surface map. `src/` change → `npm run build` + commit `build/`.
- **External-run logging**: when a run arrives through core's abilities REST route (distinguish from admin/nested execution), write a `kind = 'log'` row via `Baton_Saved_Values` (workflow ID, run ID, and a clear label like "External run"). Additive only — do not reshape the run report that `admin-run.js` and editor badges consume.
- **Verify core from source, not guesswork**: read WP 6.9's abilities REST controller (`/abilities/v1/{name}/run`) and pin the answers in tests: how `permission_callback` is enforced, whether/how input is validated against `input_schema`, and the response shape Baton's ability output feeds. Record findings in the PR description.
- **Tests**: exposure meta sanitization; registration with/without the toggle (expect the WP 6.9 `_doing_it_wrong` gate pattern where relevant); external run writes a log row; permissions unchanged; default-off means zero attack-surface change for existing workflows.

## Definition of done

- All gates green: `npm run test:php` (existing suite + new tests), `composer check`, `npm run check`
- `build/` committed if `src/` changed
- Default state (no toggle) leaves behavior and attack surface identical to main
- Run-report contract untouched except additive keys
- Security review pass (security-check brief) run before merge; findings addressed or explicitly logged as accepted
- User runs e2e on their QA site before merge