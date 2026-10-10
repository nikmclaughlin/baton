![Baton — orchestrate WordPress workflows with the Abilities API](assets/images/baton-banner.png)

# Baton

A conductor’s wand, properly called a baton, is a thin, lightweight stick used by music directors to amplify and guide physical gestures.

Baton is a WordPress plugin that adds a thin, lightweight layer used by admins to orchestrate site workflows based on the WP [Abilities API](https://developer.wordpress.org/apis/abilities/). It provides field-level input mapping to control data passing between registered Abilities, and each workflow is also exposed as it's own Ability (`baton/workflow-{id}`) for nesting or use in external tooling.

## Requirements

- WordPress 6.9+
- PHP 7.4+

## Try it on Playground

[![Try Baton on WordPress Playground](https://img.shields.io/badge/Try%20it%20on-WordPress%20Playground-blue)](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fnikmclaughlin%2Fbaton%2Fmain%2Fblueprint.json)

Opens a disposable WordPress 6.9 site with Baton installed and lands on **Tools → Baton**.

## Installation

1. Clone this repository into `wp-content/plugins/baton`.
2. Activate **Baton** in the WordPress Plugins screen.

Prebuilt editor assets are included in `build/` — you do not need Node or npm to use the plugin.

## Usage

![Baton - Workflow list view](.wordpress-org/screenshot-1.jpg)

**Tools → Baton** — create workflows in the visual editor and run them from the admin. Each workflow can be edited in two synced views: a **node canvas** (drag abilities from the library, wire data between nodes, save without leaving the page; after a run from the panel, each step node is badged with its result) and a **stack view** (a workflow-input card plus vertical step cards with data-filter slots between them, including the initial-input → step 1 filter). The chosen view is remembered locally in the browser (`localStorage`); the `baton_editor_mode` filter only changes the default for browsers that haven't picked a view yet.

Data filters on the connections between steps control what flows between abilities (field-level dot-path mapping); every published workflow is itself available as `baton/workflow-{post_id}` for nesting in other workflows or use in external tooling.

The admin's save and run actions ride the REST API (`POST /wp-json/baton/v1/workflows` and `POST /wp-json/baton/v1/workflows/{id}/run`, `manage_options` + cookie + `wp_rest` nonce), so failures surface as real HTTP status codes instead of opaque admin-ajax responses.


## Roadmap / Known Gaps 

- **Canvas overhaul** (planned next phase — see [docs/canvas-overhaul-plan.md](docs/canvas-overhaul-plan.md)): persisted chain reordering from node drags and other advanced drag-and-drop workflow building, plus mapping auto-suggest from schema paths.
- CLI & Command Palette integrations for "external" workflow execution.
- ActionScheduler integration for scheduling step/workflow execution.
- Database/transient integration for persisting workflow output.
- Code snippet nodes for fully custom data processing.
- Replace list table with DataViews when appropriate in core.
- User-facing copy pass on mapper warning strings.

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) for pull request prerequisites and command reference.

### Building the editor UI

Builds run on Node **24**, the version CI pins (`nvm use` picks it up from the repo's `.nvmrc`). Only needed when changing files under `src/`. The workflow editor is a React bundle built with `@wordpress/scripts`:

```bash
cd wp-content/plugins/baton   # or your clone path
npm install
npm run build
```

After making any changes within `src/`, run `npm run build` (or `npm start` while developing). The built files land in `build/` and are enqueued on the workflow edit screen.

### Before you push

Matches the **php-lint** and **js** CI jobs — usually a few seconds, no Docker:

```bash
composer install
npm install
npm run check
```

That runs PHP syntax (`php -l`), PHPCS, PHPStan, and ESLint on `src/`. Run integration tests separately when you change PHP behavior:

```bash
npm run test:php
```

### WordPress.org directory assets

Files under [`.wordpress-org/`](.wordpress-org/) are **not** included in the plugin zip. They are stored in Git for convenience and copied to the WordPress.org SVN **`assets/`** directory (top level, alongside `trunk/`) when publishing.

Trunk exclusions for releases live in [`.distignore`](.distignore) (same file the 10up deploy action will use later).

### Build release tree (until SVN deploy is wired up)

```bash
npm run release:org              # release/baton/ (SVN layout) + release/baton-{version}.zip
npm run release:org -- --check   # run static checks first
```

| Build output | SVN destination |
|--------------|-----------------|
| `release/baton/assets/` | `assets/` |
| `release/baton/trunk/` | `trunk/` |
| `release/baton/tags/{version}/` | `tags/{version}/` |

The script uses `.distignore` for trunk (aligned with 10up) and rsyncs `.wordpress-org/` → `assets/`. It also checks that `baton.php` `Version` matches `readme.txt` `Stable tag`.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
