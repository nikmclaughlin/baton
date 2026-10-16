=== Baton ===
Contributors: nikskyverge
Tags: workflow, abilities, automation, admin
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.5.1-alpha
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Orchestrate WordPress Abilities into custom workflows with a no-code visual editor.

== Description ==

A conductor’s wand, properly called a baton, is a thin, lightweight stick used by music directors to amplify and guide physical gestures.

Baton is a WordPress plugin that adds a thin, lightweight layer used by admins to orchestrate site workflows based on the WP [Abilities API](https://developer.wordpress.org/apis/abilities/). 

It provides field-level input mapping to control data passing between registered Abilities, and each workflow is also registered as it's own Ability (`baton/workflow-{id}`) for nesting or use in external tooling.

External execution is per-workflow and strictly opt-in: the editor's **Expose externally** checkboxes publish a workflow to the channels you choose — REST (the core Abilities REST API) and/or WP-CLI. Exposed workflows are listed and runnable with `wp baton list`, `wp baton info`, and `wp baton run`, and every externally triggered run is logged in the workflow's saved values & logs viewer.

**Features:**

* Visual workflow editor with ability steps and data filters between steps
* Input mapping from static workflow input or the previous step's output
* Each published workflow registers as its own ability for nesting and tooling
* Cycle detection when workflows call each other
* Baton Utilities: save-output, save-state ("Keep latest"), load-output, and log built-ins for persistence and the cursor pattern
* Per-workflow saved values & logs viewer with delete row / delete run controls
* Prebuilt editor assets in `build/` — no Node.js required to use the plugin

**Requirements:** WordPress 6.9 or later (Abilities API). PHP 7.4 or later.

== Installation ==

**From WordPress.org**
1. Install Baton through the WordPress plugin directory
2. Activate **Baton** through the ‘Plugins’ menu in WordPress
3. Go to **Tools > Baton** and queue the orchestra!


== Frequently Asked Questions ==

= Why does Baton require WordPress 6.9? =

Baton depends on the Abilities API (`wp_register_ability`, `wp_get_abilities`, and related APIs) introduced in WordPress 6.9. On older versions, the plugin shows an admin notice and does not load workflow features.

= Can I run workflows from the REST API or WP-CLI? =

Yes, strictly opt-in per workflow. With **Expose externally (REST)** ticked, a workflow is served and executable through the core Abilities REST API (`POST /wp-json/wp-abilities/v1/abilities/baton/workflow-{id}/run`); execution still requires admin authentication, and application passwords are supported for machine callers. With **Expose externally (WP-CLI)** ticked, the workflow shows up in `wp baton list` and `wp baton info` and is runnable with `wp baton run`. Unexposed workflows are not listed and not runnable on external channels — REST requests 404 and `wp baton` commands stop with a generic "not exposed" error. Every externally triggered run is recorded as an "External run" row in the workflow's saved values & logs viewer.

= How do workflows accumulate state between runs? =

Use the **cursor pattern** with the Baton Utilities: a `baton/load-output` step reads the saved `last_cursor` label, the workflow does its work, then `baton/save-state` ("Keep latest") writes the new `last_cursor` back. Everything a workflow saves appears under its edit screen in the saved values & logs viewer, where rows or whole runs can be deleted.

= What happens when I uninstall Baton? =

Baton deletes all `baton_workflow` posts (and their definition meta) and drops the `baton_saved_values` table (saved values, state, and logs) when the plugin is removed via the Plugins screen.

= Why does load-output only read Baton-saved values? =

Reads are deliberately scoped to Baton's own memory (values written by `baton/save-output` and `baton/save-state`). General site-data reads (options, post meta, arbitrary tables) are out of scope; they would ship as separate, security-reviewed abilities — never as a "source" mode on Load.

== Screenshots ==

1. Workflow list under Tools → Baton
2. Visual workflow editor with ability steps and data filters

== Changelog ==

= Unreleased =
* Add Baton Utilities (`baton-utility` category): `baton/save-output`, `baton/save-state` ("Keep latest"), `baton/load-output`, and `baton/log` built-in abilities for workflow persistence and the cursor pattern.
* Thread a per-execution `run_id` through every step and store it on saved rows; the run report gains `run_id` additively.
* Runner injects `run_id`/`workflow_id`/`step_id` into step inputs when an ability's input schema declares them; the editor hides those runner-filled fields from input UIs and mappable paths.
* Add a per-workflow saved values & logs viewer on the edit screen: newest-first runs, unified save/state/log timeline, click-to-expand values, and nonced delete row / delete run controls.
* Detect a missing `baton_saved_values` table (e.g. after a database restore) and offer admins a one-click "Run database migration" repair; utility storage failures now include the underlying MySQL error.

= 0.5.1-alpha =
* Add subproperty selection in data filter UI (nested dot-path mapping for source and target).
* Add wildcard `*` array subproperty mapping (extract subproperty from all array elements).
* Add loop-over-array step execution (iterate a step once per array element, flat array output).
* Update @wordpress/scripts to v34 and @wordpress/env to v11.
* Update GitHub repo from nikolas4175-godaddy to nikmclaughlin.
* Patch npm dependencies via npm audit fix.
* Sync composer lock file.

= 0.5.0 =
* Pre-release beta — workflow builder, runner, and ability catalog are functional.
* Fix workflow step JSON input in the editor (local draft while typing; saves on blur without resetting the field).
* Add WordPress.org listing assets: banner, icon, and admin screenshots.
* Plugin Check compliance: prefixed uninstall variables, readme short description, and rely on core translation loading for wordpress.org installs.
* Add developer scripts for WordPress.org-compatible release packages.

= 0.4.0 =
* Abilities API workflow editor with data filters and input mapping
* Workflow-as-ability registration (`baton/workflow-{id}`)
* PHPUnit, PHPCS, PHPStan, and GitHub Actions CI
* Plugin lifecycle: deactivation hook, uninstall cleanup, recursive input sanitization
* Internationalization support

See the [GitHub releases](https://github.com/nikmclaughlin/baton/releases) page for the full history.

== Upgrade Notice ==

= 0.5.1-alpha =
Pre-release alpha. New features: subproperty mapping, wildcard arrays, loop-over-array execution. Updated build tooling.

= 0.5.0 =
Pre-release beta. Requires WordPress 6.9+ for the Abilities API.

= 0.4.0 =
Initial public release. Requires WordPress 6.9+ for the Abilities API.
