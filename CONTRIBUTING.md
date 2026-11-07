# Contributing to ShelterKit Pets

Part of [ShelterKit](README.md#part-of-shelterkit). Bug reports and pull
requests are welcome. Support questions belong on the WordPress.org forum, as
`.github/ISSUE_TEMPLATE/config.yml` explains, and security problems should be
reported privately as described in [SECURITY.md](SECURITY.md).

## Requirements

- PHP 8.1 or newer. 8.1 is the floor the plugin header promises, and CI pins
  its jobs to it, because syntax that parses on a newer PHP can be a fatal
  error on 8.1.
- WordPress 6.9 or newer
- Composer, and Node 20+ for the JavaScript and CSS linters

## Setup

```sh
composer install
npm ci
```

Use `npm ci`, not `npm install`. CI installs exactly what `package-lock.json`
pins, and a `node_modules` resolved earlier can pass a lint rule that CI fails.

There is **no build step**. Blocks render on the server, editor scripts use the
`wp.*` globals, and the Interactivity stores are plain ES modules, so what is in
the repository is what runs.

For a local site, any WordPress 6.9+ with this directory in
`wp-content/plugins/` works. The repository also ships a
[wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
config that starts WordPress 6.9 on PHP 8.1 with the plugin active
(`npm start`, then `http://localhost:8888`; `npm run stop` to stop). It needs
Docker.

## Quality checks

Run these before opening a pull request. CI runs all of them on every one.

| Command | CI job | Catches |
| --- | --- | --- |
| `composer lint` | PHP Coding Standards | WordPress coding standards, every `WordPress.Security.*` sniff included |
| `npm run lint:js` / `npm run lint:css` | JS & CSS Lint | `@wordpress/scripts` ESLint and Stylelint rules |
| `php bin/validate-config.php` | Config Contract Validator | Config and code disagreeing; the fifteen checks are listed below |
| `composer test:unit` | PHPUnit | Pure logic, no WordPress |
| `composer test:integration` | PHPUnit (WP 6.9, WP 7.1) | Behaviour against a real WordPress and database; see [tests/README.md](tests/README.md) |
| `bin/build-dist.sh` | Build distribution zip | A development file reaching the package, or a runtime file missing from it |
| Plugin Check on the build | Plugin Check | What WordPress.org's own review tooling reports |

### Coding standards

`phpcs.xml.dist` is the full `WordPress` ruleset. **Errors fail the build;
warnings do not.** The warnings that remain are advisory: a nonce recommended
on screens that only read, `file_get_contents()` on files inside the plugin,
and hook callbacks that do not use every parameter a hook passes them.

Tabs, not spaces, as everywhere else in the codebase.

A `phpcs:ignore` must name the sniff and say why on the same line, and it
applies only to the **next** line, so nothing may be inserted between the two.

### The config validator

`bin/validate-config.php` is static analysis with no WordPress loaded. It
checks the agreements a config-driven plugin can break without any error at
runtime:

1. Every `Config::get_path()` / `get_item()` string resolves to a real key.
2. Every `computed` field has an arm in `Pet_Hydrator::compute_field()`.
3. Every name in the profile lists and in `staff_only_fields` is a real field.
4. Every ability has a resolvable callback and a known permission.
5. Taxonomies and `attribute_terms` reference things that exist.
6. `actions.X` / `callbacks.X` in block markup exist in a store.
7. The sync's change-detection hash covers every field the sync reads.
8. Every version declaration agrees with the plugin header.
9. Screenshot captions pair with screenshot files (a warning until they exist).
10. `uninstall.php` knows every name Site Editor customizations were filed under.
11. Provider response paths are declared and well formed.
12. `Requires at least` and `Tested up to` match the CI matrix.
13. Every block attribute the server honours can be set in the editor, and
    every editor control is read by something.
14. Post-type comparisons in editor scripts name a real post type.
15. No Interactivity directive sits on or inside an `<svg>`.

### Plugin Check

Run it against the **built** package, never the repository, and pass the slug:

```sh
bin/build-dist.sh
wp plugin check build/shelterkit-pets --slug=shelterkit-pets
```

The repository reports errors on development files users never receive, and
misses whatever packaging actually produced. Plugin Check derives the expected
text domain from the directory name, so `--slug` keeps a build checked from any
other folder from reporting every string as a mismatch.

Plugin Check's own warnings are mostly block `render.php` variables it takes
for globals. WordPress includes those files inside a function, so they are
local; CI ignores Plugin Check warnings for that reason.

## Tests

[tests/README.md](tests/README.md) explains the two suites and how to run the
integration one locally. Three conventions, all earned:

- **A fix comes with the test that would have caught it.** Then revert the fix
  and watch the test fail. A guard that cannot fail proves nothing, and several
  here once could not.
- **Exercise abilities through `WP_Ability::execute()`**, not by calling the
  callback. That is the path REST, MCP and the block bindings take, and it is
  where output validation and redaction happen.
- **A skipped test verifies nothing.** If a test needs something the
  environment lacks, provide it; do not mark the test skipped.

## How the code is organised

```
shelterkit-pets.php  → bootstrap, autoloader, activation, the migration rail
config/              → JSON: entities, abilities, post types, taxonomies, schemas
config/providers/    → one file per shelter platform: how it names things
includes/core/       → Config loader, CPT registry, Query builder, Pet_Hydrator
includes/abilities/  → ability callbacks, registered through Provider
includes/import/     → CSV import: heading matching, validation, preview
includes/export/     → CSV and JSON export, sharing one column schema with import
includes/cli/        → WP-CLI commands
includes/schema/     → AnimalShelter JSON-LD and the per-SEO-plugin adapters
includes/shelterkit/ → files shared with the other ShelterKit plugins
blocks/              → 21 server-rendered blocks
templates/, parts/   → block templates, the kennel card, the floating UI
assets/              → editor scripts, Interactivity stores, stylesheets
tests/               → unit and integration suites
bin/                 → build, version bump, validator, test and screenshot tooling
```

### What a pet is, and what a platform calls it

`config/entities.json` declares what a pet *has*. Each
`config/providers/<slug>.json` is the only place that knows how one platform
*spells* it: field names, value translation (`f` becomes `Female`), polarity
(`invert: true`, for a platform that records "not good with cats" where the
entity records "good with cats") and nested response paths. Adding a platform
means adding a file, not editing PHP, and the validator checks every provider
map against the entity.

### Abilities are the public boundary

Business logic lives in abilities: small operations with JSON Schema input and
output and a permission callback. Blocks, REST routes, the admin and WP-CLI are
thin consumers.

Every ability's output passes through `Pet_Hydrator::redact()` where abilities
are registered (`Provider::register_ability()`). That removes the fields named
in `entities.json` `staff_only_fields`, such as microchip numbers, for anyone
who cannot edit the pet. **Hydration itself never redacts**, because export and
import need those fields and a WP-CLI export runs as no user at all. Anything
that hands pet data to the public must go through an ability.

### Stored data changes through the migration rail

To change the shape of stored data, bump `PETSYNC_DB_VERSION` and add an
entry to `petsync_get_migrations()`. A migration must be idempotent, because
a fresh install runs the whole list. It may return `false` to stop the rail, so
the step runs again rather than being silently marked done. Never reuse a
version number: an install already at that version will not run it again.
`wp shelterkit-pets migrate --dry-run` shows what would run.

### Presentation belongs in CSS, not in the kennel card's markup

The kennel card ships as a template part. Once a shelter edits it in the Site
Editor, WordPress serves their copy from the database and never reads the
shipped file again. A fix written as a block attribute in `parts/kennel-card.html`
therefore misses exactly the sites most likely to have hit the bug. Markup
decides which fields appear and in what order; measurements and print mechanics
live in `assets/css/kennel-cards.css`, which reaches every site.

### Habits this codebase relies on

- **IDs are `intval()` with a `> 0` check, never `absint()`.** `absint( -17 )`
  is 17, a real and unrelated pet. This has been fixed three times.
- **Every script that calls `__()` is registered with
  `wp_set_script_translations()`**, and a `block.json` script needs a
  hand-written `.asset.php` naming its dependencies. Without the first, its
  translations never load; without the second, it can run before the packages
  it uses exist. `ScriptTranslationsTest` and `ScriptDependenciesTest` enforce
  both.
- **No inline `<script>` or `<style>`.** Enqueue a file, or pass values as
  custom properties on a block's wrapper.

## What must not change

These are stored in sites' databases, in saved post content, in visitors'
browsers or in people's own scripts and spreadsheets. Renaming any of them
breaks existing installs, and once the plugin is listed there is no install
that can be reached to fix it up.

- The post type `vcps_pet`, the nine `pet_*` taxonomies, and every `_pet_*`
  post and user meta key
- The options `petsync_settings`, `petsync_last_sync`,
  `petsync_last_sync_stats`, `petsync_sync_log`, `petsync_db_version` and
  `petsync_preview_pet_id`; the shared `shelterkit_organization`; and the
  legacy `petstablished_*` names the first migration still reads
- The cron hook `petsync_scheduled_sync`
- **The 21 `petsync/*` block names, their attribute names and enum values**
  (including the adoption action's `formMode` value `petstablished`), and the
  nine `petsync/*` Interactivity store namespaces. These are written into saved
  post content.
- The template and template-part slugs, and the `shelterkit-pets` `wp_theme`
  name Site Editor customizations are filed under
- Block-binding keys, which are stored in saved block markup
- The `petsync/v1` REST namespace and the twelve `petsync/*` ability names, with
  their input and output field names
- The `petsync_*` and `shelterkit_profile_*` action and filter hooks
- The `pet_favorites` and `pet_comparison` cookies, and the
  `petstablished_favorites` and `petstablished_comparison` localStorage keys
  that hold visitors' saved lists in their own browsers
- URL parameters people bookmark and share: `compare` and the `compat_*`
  filters
- CSV export column headings. They land in spreadsheets no migration can
  reach, so if one must change, the importer has to accept the old heading
  forever.
- The WP-CLI commands `wp shelterkit-pets export|import|migrate`, and the
  deprecated `wp shelterkit` names until they are removed in a deliberate
  release

The `Petsync\` namespace, `Petsync_` classes, `PETSYNC_` constants, `petsync-`
script handles and admin page slugs are internal, and deliberately do **not**
track the plugin's public name. That separation is what made the ShelterKit
rename a day's work rather than a week's.

## The shared ShelterKit files

`includes/shelterkit/` holds `ShelterKit_Profile` and
`ShelterKit_Profile_Versions`, carried by every ShelterKit plugin. Each plugin
registers its copy and its version at file scope, and on `plugins_loaded` the
highest version present is the one that loads. That is Action Scheduler's
pattern, and it means no ShelterKit plugin depends on a sibling being
installed. The classes are neither namespaced nor prefixed with this plugin's
name, because every copy must declare the same class; `phpcs.xml.dist` allows
the `ShelterKit` prefix for that directory alone.

**Copy these files unchanged, with one exception:** the text domain must be
this plugin's slug. Plugin Check rejects a foreign text domain as an error, and
translation tools extract by domain, so a copy carrying a sibling's domain
would leave every label untranslatable. If you change the logic, change it in
one plugin, copy it to the others, and bump `VERSION` so the newer copy wins.

Register the Shelter Details screen only when
`ShelterKit_Profile_Versions::winner()` is this plugin's own copy. Testing
`class_exists( 'ShelterKit_Profile' )` instead is true in every plugin that
carries a copy, and registers one screen per installed ShelterKit plugin.

Pets currently carries version 1.1.0. ShelterKit Donations carries a newer one,
which wins when both are installed; bringing Pets up to date is tracked in #111.
