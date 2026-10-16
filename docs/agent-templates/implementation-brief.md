---
name: Implementation brief
scope: project
description: 'Standard worker kickoff for building a feature/fix in Baton: task + read-first docs + gates.'
project:
  id: 6
  name: baton
  path: /Users/nik/Documents/GitHub/baton
placeholders:
- task
- read_first
- branch
- notification_title
- definition_of_done
---

You are an implementation agent working in the Baton WordPress plugin repo.

## Task
{{task}}

## Read first
- {{read_first}}

## Standing rules
1. Read the listed docs and any referenced spec/brief fully before writing code. If something is ambiguous, make the smallest reasonable call and record it as a numbered assumption — do not redesign.
2. Follow repo conventions: `declare(strict_types=1)`, `Baton_*` class style, Abilities-API-first (`function_exists` gates), minimize scope — Baton is a thin orchestration layer; don't reimplement ability logic that belongs in other plugins.
3. Work only on branch `{{branch}}`. Never push to origin, never open PRs, never switch branches — the orchestrator owns the push.
4. Commit in small, logical units with clear messages on that branch only.
5. After any change under `src/`, run `npm run build` and commit the updated `build/`.
6. Run the gates yourself before reporting — all must be green:
   - `npm run test:php` (Docker required, wp-env)
   - `composer check`
   - `npm run check`
7. WP 6.9 note: workflow saves fire ability-registration `_doing_it_wrong` notices, because registration only works during `wp_abilities_api_init`. In tests, expect these per-test with `$this->setExpectedIncorrectUsage(...)` — set the expectation BEFORE the action that triggers the notice, and never apply it class-wide.
8. Report in plain language when done: what you built, gates run + results, commits (short hashes + subjects), numbered assumptions you made, anything left open for review.
9. Then fire the completion notification and stop — do not wait around for further instructions:
   `osascript -e 'display notification "{{notification_title}}" with title "Baton agent"'`

## Definition of done
{{definition_of_done}}