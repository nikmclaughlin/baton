# Orchestration Handoff — B1 (baton project)

You are the main orchestrator for the Baton WordPress plugin (Solo project 6).
You own B1 execution through merge. This doc is your complete context.

## In flight right now

- **Solo process 46 ("b1-impl")** — implementation subagent running Brief A
  (storage class, run_id threading, four utility abilities, PHP tests) on branch
  `b1-persistence`. Baseline suite was green (201 tests) when it started.
  It will send a macOS notification when done; you should also set an idle-timer
  on it (timer_fire_when_idle_all, processes=[46]) and wake yourself to review.
- **Branch:** `b1-persistence` (off main at e96ef03). Spec committed (9adfa0f).

## Read these first

- `docs/b1-persistence-spec.md` — the agreed design. Contracts are SETTLED;
  do not redesign. Key rules: the fork rule, additive-only run report, Load
  table-scope boundary, no auto-pruning in B1.
- `docs/b1-brief-a.md` — what process 46 was assigned.
- `docs/roadmap.md` — B-first order; next after B1: B2 external execution,
  plus the JS test infrastructure decision.

## Orchestration recipe (proven in H0/B1)

- Spawn subagents: `spawn_agent(agent_tool_id=8, project_id=6, name=...,
  extra_args=["--no-profile","--with-builtin","developer","--provider","ollama",
  "--model","glm-5.3-flash:cloud"])`. Task content goes via `send_input` after
  spawn, never in spawn args. `--no-profile` avoids MCP extension noise; workers
  don't need Solo tools. You (orchestrator) run WITHOUT --no-profile so you keep
  soloterm. Your model: glm-5.3:cloud. Workers: glm-5.3-flash:cloud.
- You are Solo-managed: timers work for you. Use `timer_fire_when_idle_all`
  instead of polling. Re-arm if a wake-up fires early (workers idle briefly
  between tool calls).
- Explicit `project_id=6` on every project-scoped call. Close idle processes
  (`close_process`) when their work is done.

## Review gate (do this yourself before anything lands)

1. When b1-impl reports done: run `npm run test:php`, `composer check`,
   `npm run check` yourself — all must be green including the original 201.
2. Review its local commits on `b1-persistence` against the spec's contract
   rules: report keys additive-only (success/steps/steps[].success/output
   unchanged); per-test `setExpectedIncorrectUsage` (NEVER class-wide — WP 6.9
   expectations are two-way promises); strict_types + Baton_* style;
   load-not-found = loud failure; upsert only for kind='state'.
3. Iterate via `send_input` to process 46 if fixes are needed; re-gate.
4. Then assign Brief B: editor/catalog verification ("Baton Utilities" grouping,
   native selects), admin viewer (spec §Admin viewer), docs/readme updates.
   Editor changes require `npm run build` (commit build/).
5. When green: push `b1-persistence`, open the PR, let CI gate it, review CI,
   then **report to the user with a merge recommendation — they confirm the
   merge**. You own the PR; subagents never push.

## Hard-won lessons (do not relearn)

- Never trust a lockfile validated with a non-CI npm: use the docker node:24
  check (see AGENTS.md Lockfile rule) for any dependency change.
- Let `php -l` output precede a successful `git add` (piping through tail can
  fake success). No `cat -A` on macOS.
- WP 6.9: ability registration outside `wp_abilities_api_init` is a
  _doing_it_wrong no-op; a saved workflow's ability registers on the NEXT init
  fire. Tests expect notices per-test only.
- Notify the user via `osascript -e 'display notification "..." with title
  "Baton B1"'` when you need a human decision or when a phase completes.

## User preferences

- Plain-language reports. Design decisions are consultative — surface options
  with a recommendation; don't redesign settled contracts.
- Notion extension is disabled; persistent memory/design docs live in repo
  docs/ for now.
## Update (2026-10-15): goose commit attribution (user-approved)

- New rule for ALL subagent briefs: worker agents must end every commit message with the attribution block (Claude-Code style):
  `🤖 Generated with goose 🪿` line, then `Co-Authored-By: goose <noreply@aaif.org>` trailer.
- Demoed in nikmclaughlin/goose-trailer-demo; user approved "trailers going forward" (2026-10-15).
- PR #4 (B1) is grandfathered: its commits keep their original messages — never rewrite merged/PR'd history for this.
