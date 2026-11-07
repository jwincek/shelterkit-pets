# Tests

Two suites, mirroring the split in the sibling plugins.

| Suite | Command | Needs WordPress? |
|---|---|---|
| Unit | `composer test:unit` | No: stubs a handful of functions and loads one class |
| Integration | `composer test:integration` | Yes: a real WordPress and database |

`composer test` runs both. CI runs both on PHP 8.1, against WordPress 6.9 and
7.1, the two ends of the range the readme claims.

## Running the integration suite locally

```sh
# The version must be explicit. "latest" is not a tag in the
# wordpress-develop repository the library is fetched from.
bin/install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host> 7.1
composer test:integration
```

Use a database name that is not your working site's: the installer **drops
and recreates** it.

On Local by Flywheel, the host is a socket rather than a port:

```sh
SOCK="$HOME/Library/Application Support/Local/run/<run-id>/mysql/mysqld.sock"
bin/install-wp-tests.sh shelter_pets_tests root root "localhost:$SOCK" 7.1
```

The library installs under `sys_get_temp_dir()`, which on macOS is a
`/var/folders/...` path rather than `/tmp`, and is gone after a reboot. Set
`WP_TESTS_DIR` to put it somewhere else.

## Traps worth knowing

**What the plugin registers stays registered for the whole run.** The test
library resets post types and taxonomies between tests only when
`WP_RUN_CORE_TESTS` is set, which is true of WordPress's own suite and not of a
plugin's. Two consequences:

- A test that unregisters something must put it back. Unregistering a post type
  also drops the meta registered on it. `PetTestCase::set_up()` re-registers the
  post type, taxonomies and meta before every test as insurance.
- Code that runs while the plugin is *not* loaded never meets that condition in
  a test unless the test creates it. `uninstall.php` is the case that matters,
  and `UninstallTest` unregisters everything around the run.

**Hydration is memoised per request.** `Pet_Hydrator::get()` caches by pet and
profile, so a test that writes meta after something has already hydrated the
pet reads the old value. Call `Pet_Hydrator::flush_cache()` after writing.

**Exercise abilities the way callers do.** Call
`wp_get_ability( $name )->execute( $input )`, not the callback function.
Output validation and the redaction of staff-only fields both happen there, and
an ability's callback file may not even be loaded until the ability is.

## What is covered

Each file opens with a docblock saying what it protects, and usually the bug
that prompted it.

| Area | Tests |
|---|---|
| What the public can read | `StaffOnlyFieldsTest`, `VisitorStateSecurityTest`, `ReadAbilitiesTest`, `AbilityOutputSchemaTest`, `McpExposureTest`, `BatchGetPetsTest`, `SharedComparisonTest`, `MetaSanitizersTest` |
| Hydration and the data model | `HydratorPrecedenceTest`, `HydrationShapesTest`, `HydrationQueryCountTest`, `TristateTest` (unit), `AttributeTermsTest`, `DefaultStatusTest`, `BlockBindingAliasTest`, `CacheFlushTest` |
| Providers and sync | `ProviderMapTest`, `ProviderScopingTest`, `ApiShapesTest`, `AdoptAPetProviderTest`, `RescueGroupsProviderTest`, `StalenessTest`, `SyncScheduleTest`, `ImageBudgetTest` |
| Import and export | `CsvImportTest`, `ExportTest` |
| Kennel cards and the shelter profile | `KennelCardsTest`, `CardWrappingTest`, `CardShelterDetailsTest`, `EditorPreviewTest`, `ShelterProfileTest`, `AnimalShelterSchemaTest` |
| Editor scripts and controls | `PetHealthControlsTest`, `ScriptTranslationsTest`, `ScriptDependenciesTest` |
| Install lifecycle | `MigrationsTest`, `UninstallTest` |

A few of the bugs behind them, as a sense of what the suite is for:

- **`TristateTest`.** Compatibility was tested with `! empty()`, and `'no'` is
  a non-empty string. 22 of 93 published pets were advertised as good with
  dogs, cats or children when the shelter had recorded the opposite.
- **`MetaSanitizersTest`.** `absint( -5 )` is `5`, so a negative attachment ID
  silently resolved to a real, unrelated image.
- **`HydrationQueryCountTest`.** A 99-pet archive once issued 202 queries
  against a documented "about 5".
- **`StaffOnlyFieldsTest`.** Any visitor could read every listed pet's
  microchip number through four different routes. It runs every registered
  ability as a logged-out visitor, so a fifth route cannot be added unnoticed.

Provider fixtures in `fixtures/` are written by hand from published field
names, not captured from live requests; see [fixtures/README.md](fixtures/README.md).
