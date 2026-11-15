# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Kennel cards say who a bonded pet must be adopted with.** A line under the
  banner reads "Bonded with Val: adopt together." On a 4×6 card it fits beside
  the photo, so the card is no taller, unless a long name or a group of three
  wraps it to a second line. A pet that is not bonded gets no line and the
  same layout as before. A kennel card edited in the Site Editor keeps
  its own design and does not get the line. Add a paragraph bound to the
  `bonded_pair_summary` key, or reset the card (see the readme's FAQ). Any
  block can bind to that key.

### Changed
- **`bonded_names` holds bonded partners only.** For a synced pet it was
  Petstablished's siblings list, which includes littermates outside the
  bonded group, and the export carried it that way. It is now the names of
  the group's other members. A value entered by hand is kept as typed.
- **A pet taxonomy link now opens the pet archive with that filter applied.**
  Breed, color, status and the other pet terms, wherever they are linked (menus,
  the Post Terms block, the admin's View link), go to the adoptable pets archive
  filtered to that term. The old term pages, such as `/pet_breed/beagle/`,
  redirect there permanently, and core sitemaps no longer list them. The
  archive can now also be filtered by color, coat, status, hypoallergenic and
  declawed from the URL.
- **The pet taxonomies are named `vcps_pet_*`, matching the post type.**
  `pet_breed` is now `vcps_pet_breed`, and likewise for all nine. Terms and
  their assignments move over automatically on update. URLs and the REST API
  keep the old names, so `/pet_breed/beagle/`, `/wp/v2/pet_breed` and
  `/wp/v2/vcps_pet?pet_breed=` work as before. Code that names a taxonomy, in a
  `tax_query` or a call such as `get_the_terms()`, needs the new name, and so
  does a `?pet_breed=` query-string link, which only sites without pretty
  permalinks used.
- **Descriptions export as plain paragraphs.** The CSV export used to write a
  description as HTML, with Petstablished's apostrophes as `&#039;`. It now
  writes paragraphs separated by a blank line, and an import turns them back
  into paragraphs in the editor's own format. A description with a list, a
  link or other structure travels as its markup, so nothing is lost.
- **Every breed and colour is exported, not just the first.** A cell lists
  them separated by `|`, and the import reads them the same way. In JSON they
  are lists.
- **Re-importing an export no longer duplicates pets without a microchip.** A
  row is matched on the `id` column of a file this site exported, unless the
  pet's microchip says it is a different animal; otherwise on its microchip.
  The preview notes an id match whose name differs, since a file from another
  site would match unrelated pets. When several pets share a microchip, as a
  re-listed animal's old draft copy does, the listed one is matched and the
  preview names the others.
- **An import no longer changes a pet synced from a platform.** The next sync
  would have undone it. Such rows are skipped with a note saying to edit the
  pet on the platform. A synced pet's microchip is now found in its provider
  data, so a shelter's own sheet no longer adds a copy of a synced pet.
- **The import preview is now the confirmation step.** The first screen can
  only preview; the "Import now" button that wrote on the first click is gone.
  The preview keeps the uploaded file, so confirming imports exactly the file
  previewed, without choosing it again. It shows how each column will be read,
  with an example from the first row, and a dropdown to change or ignore any
  column. Every row with an error or a note is listed, not only the first 25,
  and so are the breeds and other terms the file would add.
- **An unknown status is refused.** "Adoptable" instead of "Available" used to
  create a new status, which hid those pets from every adoptable listing. A
  status must now be one the site has, by name or slug.
- **Export can include unpublished pets, for a complete backup.** Pets →
  Export has an option, and WP-CLI `--include-unpublished`, to add drafts,
  pending and private pets. A `post_status` column now travels with every
  row. An import applies it only to pets it adds, so restoring a backup does
  not publish its drafts, and an export edited for a week does not put back
  up a pet unlisted in the meantime. Two records sharing a microchip, such
  as a re-listed animal's old draft, are both restored.
- **Spreadsheets as Excel saves them import cleanly.** A file in Excel's
  older Windows "CSV" format (Windows-1252) is converted, so accented letters
  and curly apostrophes no longer fail with "Could not insert post into the
  database". A file separated by semicolons, as Excel writes in much of
  Europe, is read as such. The preview says when either happened.
- **The JSON export says `"portable"` instead of `"importable"`.** Nothing
  imports JSON; the flag says whether its columns are the ones a CSV import
  reads.
- **A synced pet is shown only if its status is one the site shows.**
  Petstablished's feed is not filtered by status, so an adopted, returned or
  euthanized pet got a public page offering an adoption application. Now only
  Available, Adoption Pending and On Hold pets are published, and Pets → Sync
  Settings can change the list. Other synced pets are kept as drafts, and the
  pets list says why ("Adopted: not shown on this site", "No longer listed on
  Petstablished since …", "Hidden on Petstablished"). Pets added by hand are
  not affected.
- **Only an available pet offers the adoption application.** A pending,
  on-hold or adopted pet's page says it is not taking applications instead.
- **Visitors can no longer list the statuses the site does not show** through
  the REST API, which named every status, Euthanized included.
- A synced pet drafted in WordPress is published again by the next sync, as
  the pets list now says. To keep one off the site, hide it on Petstablished.
- **The sync can bring in every species.** It asked Petstablished for cats
  and dogs only, so a shelter's rabbits, birds and other animals never
  appeared. Pets → Sync Settings now has "Species to sync": empty syncs every
  species. A site that was already syncing keeps cats and dogs until someone
  clears or changes it; a new site syncs everything.
- **An import can bring photos.** An `image` column (one URL) sets a pet's
  main photo and a `gallery` column (URLs separated by `|`) its gallery,
  named as the pet model names them; "Photo" and "Photos" work too. The
  photos are downloaded after the import, a few at a time in the
  background, within the same size budget as synced photos; WP-CLI imports
  download them at once. A photo already in the Media Library, as in a
  same-site export, is used where it is, and one downloaded before is not
  downloaded again. Each address must be a public http(s) link to an image
  file, which the preview checks. The portable export now carries each
  pet's main photo, so a restore brings the pictures back.

### Fixed
- A bonded pet whose partner was not listed was shown as bonded with all its
  Petstablished siblings, some of them in other pairs or in no pair at all.
  Partners now come from the bonded group, and a sibling entry is used only
  to name a member of it. In a group of three or more, one listed partner no
  longer hides the unlisted ones.
- A pet entered by hand with "Bonded with" names was not marked bonded
  anywhere: the badge and partner list came only from Petstablished's group.
  Names entered by hand now count, shown without links. Separate them with
  commas.
- A block bound to `bonded_pair_names` showed its saved text instead of the
  partners' names.
- Saving Pets → Sync Settings reset the staleness settings, which have no
  field on that screen, to their defaults.
- A synced pet drafted because it left Petstablished's listing stayed a draft
  after it came back, if its details had not changed: the sync skipped it
  before deciding whether it should be published.
- The same skip left an unchanged pet's last-seen date alone, so a pet still
  listed on Petstablished looked stale after a week. The staleness notice
  counted it, and the optional auto-draft would have taken it off the site.
- The `list-pets` and `filter-pets` abilities returned pets of every status
  when no status was given, though their schemas say the default is
  available. An empty string still lists every status.
- A pet's compatibility terms ("Good with dogs" and the rest) were derived
  before the sync stored the data they come from. A newly synced pet got none,
  and kept none, since later syncs skip an unchanged pet, so it was missing from
  every compatibility filter; a changed pet's terms were one sync behind.
  Updating recomputes every pet's terms once.
- An export of synced pets could not be imported back: every row failed,
  because the import required a number for `numerical_age` and Petstablished
  writes ages like "6 years, 1 months".
- Re-importing an export emptied every pet's curated gallery.
- An import updating pets from a sheet without a description column emptied
  their descriptions.
- An import that updated a draft pet published it.
- A description written in the block editor came back from an export with its
  paragraphs broken, and displayed with an empty paragraph around each one.
- A value the export protected from Excel, such as a name starting with `+` or
  `-`, came back from an import with an apostrophe in front of it.
- The same microchip on two rows of one file: the preview promised two new
  pets, then the import added one and overwrote it with the second. Both now
  report the second row as an error.
- Backslashes in imported values were dropped.

## [1.6.0] - 2026-11-06

### Security
- **Microchip numbers are no longer readable by the public.** No page displayed
  one, but any visitor could read every listed pet's microchip number through
  the REST API and the plugin's public abilities: `get-pet`, `batch-get-pets` and
  the comparison abilities, which block bindings and MCP clients also use. They
  are now returned only to users who can edit the pet. The editor, import and
  export are unchanged. Microchip ID is no longer offered as a block binding,
  because it would print empty for every visitor.
- The provider's record ID (`_pet_ps_id`) is no longer exposed through the REST
  API. Nothing outside the sync uses it.

### Added
- **Settings for 25 block options that existed but could not be changed.** The
  Pet Comparison block had no settings at all, so which rows it showed was
  fixed. Pet Filters gains its status, compatibility and presentation settings,
  and Pet Gallery its thumbnail columns, badges and video settings.

### Changed
- **The WP-CLI commands are now `wp shelterkit-pets export`, `import` and
  `migrate`.** `wp shelterkit` belongs to the whole ShelterKit family, and
  ShelterKit Donations already uses `wp shelterkit-donations`.
- **The automatic sync is only scheduled once a Petstablished key is entered.**
  Sites without one ran a sync every evening that could only fail, and filled
  the Sync Log with the failures. Those sites stop on their own after updating.
- The Import and Export screens moved to `page=shelterkit-pets-import` and
  `page=shelterkit-pets-export`. Update any bookmarks.
- Script and script-module handles are `petsync-*` rather than
  `petstablished-*`, for any theme or plugin that dequeues them.
- The readme lists every external service the plugin contacts, now including
  Amazon S3 (where Petstablished keeps pet photos) and YouTube (for pet videos).
  It also explains what to do when a kennel card is cut off, and why fixes to
  the shipped card do not reach a design you have edited.

### Deprecated
- `wp shelterkit export`, `import` and `migrate`. They still work, with a
  warning naming the replacement, and will be removed in a future release.

### Fixed
- **The Pet Details block's editor interface never loaded on a clean site.**
  Its editor script ran before WordPress's editor packages and stopped with an
  error, so the block could not be arranged in the editor. Sites where another
  plugin happened to load those packages first never saw it.
- **Gallery: Show Thumbnails and Enable Lightbox did nothing.** Both settings
  saved, and neither was read. Both now take effect.
- **The Pet Gallery block never previewed in the editor**, and the Pet Data
  Bindings sidebar told you to open a pet while you were editing one. Both
  compared against the wrong post type.
- **Pet Slider font choices with a quoted name were ignored on the front end.**
  That includes most theme fonts, such as Twenty Twenty-Five's "Fira Code". The
  editor preview showed them correctly, which is why it went unnoticed.
- **Editor translations never loaded.** The block settings and the pet details
  panels stayed in English whatever language pack was installed.
- **Two PHP notices on every pet page** from the favorites button, whenever
  `WP_DEBUG` is on.
- **The comparison share link returned by the abilities opened the home page**
  on sites with default permalinks, and gave a 404 where the pets archive is not
  at `/pets/`. The Share button itself was not affected.
- **Two "Shelter Details" screens appeared** when a second ShelterKit plugin was
  installed, both editing the same details.
- **"Delete all data" left data behind:** the kennel card preview setting, pets
  in the trash, unfinished drafts, and a WordPress option for the default pet
  status. All are removed now. Pet photos are kept on purpose, because they are
  Media Library items that may be used elsewhere. The setting's description and
  the FAQ now say so, and say that it deletes pets entered by hand, which it
  always did.

## [1.5.0] - 2026-08-22

### Added
- **Five settings that were missing rather than absent by design.** The Pet
  Health and Pet Attributes blocks read these when deciding what to show, but
  the editor never offered them, so the fields they control were on permanently:
  **Coat Pattern**, **Special Needs**, **Hypoallergenic**, **Declawed**, and a
  choice of which health answers to show — known ones only, everything including
  unknown, or only what is true of the animal.

  This is why a printed card kept showing Coat Pattern after Coat had been
  switched off.

- **An option to drop the "Yes"/"No" wording** beside each health icon and rely
  on the icon alone. Useful on kennel cards printed four to a sheet, where it
  gives back about a third of the space the health section uses. Screen readers
  are unaffected either way: the answer is already attached to each item for
  them, whether or not the word is on the page.

### Fixed
- **Half-page cards printed one per sheet instead of two.** Two cards needed
  10.4in of a 10.2in page, so the second was pushed onto a sheet of its own —
  doubling the paper for every batch. It looked correct in the preview, because
  the preview and the printed page had been given different measurements.

- **A full page now uses the page.** It was drawing the half-page card and
  leaving the bottom half of the sheet empty, filling 46% of the paper. The
  photograph now takes the full width, and the card fills 89%.

- **The "some pets may no longer be available" notice appeared inside a pet's
  card** on the print screen, pushing that card's own details off the bottom of
  the cell.

### Internal
- Config validator check 13: a block attribute the server honours must be one
  the editor can actually set. 45 were unreachable; the 5 above are fixed and
  the remaining 40 are tracked as a ratchet — a new one is an error, a known one
  is a warning, and a listed entry that has since been fixed is also an error,
  so the list can only shrink.

## [1.4.0] - 2026-08-20

### Fixed
- **Printed kennel cards were cut off at the bottom.** Card content overflowed
  the cell and was clipped without warning — and because the shelter's name,
  address and phone number sit at the foot of the card, they were the first
  thing lost. A card that does not say who to call is the one thing a kennel
  card must never be.

  Four separate causes, each of which alone was enough: the Group block's
  default padding and width constraints, columns stacking below 782px, a
  section-gap rule inheriting from the theme, and two earlier height caps
  written as percentages, which a browser drops when the parent has no height.

  Every published animal on the test shelter is now measured on each change.
  The worst card is 444px against 460px of usable space. **Reprint any cards
  you have already made.**

- **Photographs cut off the animal's head.** Every photo the sync downloads is
  portrait — roughly 3:4 — and the card was placing them in a box close to 2:1.
  The crop discarded about 60% of each image's height, taken from the centre,
  which is exactly where a standing animal's head is not. It was not bad luck
  on particular photos; every portrait photograph lost the head.

  Photos are now square and cropped from the top.

### Changed
- **The coat row no longer appears on printed cards.** It was blank on every
  dog, and on the cats that had it, the breed already said the same thing —
  "Domestic Short Hair" contains "Short". Across the test shelter it added
  something new to two cards out of forty-three.

  Coat is untouched on the animal's own page, where it is worth showing, and
  can be switched back on for cards in the Site Editor.

- The card's photo column is narrower and the name column wider, so long names
  have more room. The "Adopt me!" badge sits under the name rather than beside
  the photo, which is where the wasted space was.

### Compatibility
- **Tested against WordPress 7.1**, including the changes to the Abilities API.
  All twelve of the plugin's abilities were verified against the output
  validation that WordPress 7.1 introduced, which did not exist in 6.9 and is
  now enforced for every caller. Nothing changed for existing sites, and REST
  exposure is unchanged.

### Internal
- The test suite now runs against **both** ends of the supported range, 6.9 and
  7.1, on every push. The floor had never been tested — "Requires at least" was
  an assertion rather than a measurement, and it is the claim that breaks
  someone's site rather than merely affecting search results.
- Config validator check 12 holds `Requires at least` and `Tested up to` to the
  versions CI actually runs, so neither header can drift ahead of the evidence.
- Abilities are now tested through `WP_Ability::execute()` rather than by
  calling their handlers directly, so the contract WordPress validates is the
  contract the suite checks.

## [1.3.1] - 2026-08-18

### Fixed
- **The structured-data setting would not save.** Ticking "Search engines" under
  Pets → Shelter Details appeared to work and was gone on the next page load, so
  the feature added in 1.3.0 could not actually be switched on.

  The plugin's settings are sanitised through a list of known keys, and that
  list runs on every write to the option — not only on saves from the settings
  form — so a setting added on a different screen was discarded on the way in,
  silently and with no error. **If you tried to turn this on in 1.3.0, turn it
  on again.**

### Internal
- The release workflow refuses a tag that is not on `main`. v1.2.0 and v1.3.0
  were both tagged from their release branch, so they point at commits `main`
  never took — identical content, but unreachable from the history, and the
  release page reads "1 commit to main since this release" permanently. The
  instruction that caused it never said where to tag from; documentation alone
  did not prevent it, so the workflow now checks.

## [1.3.0] - 2026-08-17

### Added
- **Shelter Details.** The shelter's own name, address, phone, email and website
  are entered once under **Pets → Shelter Details** and used wherever the
  shelter identifies itself. Shared with the other ShelterKit plugins, so
  entering it here fills it in for all of them, and removing one plugin does not
  take it away from the others.
- **Optional structured data.** Switched on, the site describes itself to search
  engines as an animal shelter, with the address entered above. Where an SEO
  plugin already describes your organisation — SEOPress, Slim SEO or The SEO
  Framework — it refines what that plugin emits rather than adding a second,
  competing description. Off by default, and it says which SEO plugin it found.

### Fixed
- **Printed kennel cards no longer carry the placeholder instruction.** The card
  shipped with "Add your shelter's phone, email and address here" as part of its
  design, so it printed on every card until the design was edited by hand. The
  contact line now comes from Shelter Details, prints nothing at all until those
  are filled in, and the prompt to fill them in appears on the Kennel Cards
  screen instead — where the person who can act on it will see it.

  **If you have printed cards, reprint them.** Anyone who has not edited the
  card design has been handing out cards with that instruction on them.

### Internal
- The shelter profile is a shared class carried byte-identically by each
  ShelterKit plugin, with the newest copy winning — so a shelter running two of
  them gets the newer screen without either depending on the other.
- Contributing documents that CI waits for approval on a first pull request from
  a fork, which is otherwise indistinguishable from being ignored.

## [1.2.0] - 2026-08-13

### Added
- **The kennel card design previews against a real pet.** Editing it in the Site
  Editor used to show an empty card — bound fields carry no fallback content and
  the pet blocks render their nothing-to-show branch — so a newly inserted block
  looked identical whether it worked or not. A pet now stands in, chosen from a
  picker on the Kennel Cards screen and defaulting to the first animal you would
  print.

  Two halves, because bindings and dynamic blocks resolve differently: a shim
  for server-rendered previews, and the plugin's first JS block-bindings source
  for the name, photo and URL.

### Fixed
- **Printed kennel cards broke labels mid-word.** Cards read
  "Spayed/Neutere / d" and "YE / S". Several themes set
  `word-break: break-word`, which collapses a label's minimum width to a single
  character; the card blocks now assert their own wrapping. **Cards printed on
  1.1.x will look different when reprinted — this is the fix, not a change of
  design.**
- **A shared comparison link wiped the visitor's own comparison.** Opening the
  link the Share button produces showed "Compare Pets (0)" above a full table,
  and silently cleared whatever the visitor had saved. The client re-fetched the
  list over a request that cannot carry the `?compare=` parameter, got an empty
  answer, overwrote the correct state and persisted the emptiness. It now
  refuses to overwrite a list the URL supplied, and never lets an empty answer
  clear a list that already exists. Clearing on purpose still works.

### Internal
- Releases publish from a pushed tag: one command builds the package, deploys it
  to WordPress.org and attaches the installable zip to the GitHub release. The
  tag must match the plugin header or nothing is published.
- The screenshot capture script produces images that match their captions;
  two shots were previously wrong in ways only visible by looking at them.

## [1.1.1] - 2026-08-11

### Fixed
- **Bonded-pair badges vanished from listing grids, sliders and favourites
  modals.** A bonded pet showed its badge on its own page and nowhere else, so
  the two animals looked adoptable separately in exactly the places a visitor
  browses.

  `summary` and `grid` request the computed `is_bonded_pair` and
  `bonded_pair_names` but not the api_fields they are derived from, and
  hydration narrowed api_fields to the shape *before* computing. Those fields
  therefore derived from a partial entity and always resolved to false.
  Introduced in 1.1.0 by the provider-decoupling work, which moved the
  computation from the always-present API snapshot onto the shape-filtered
  entity.

  Fixed structurally rather than per-field: api_fields hydrate in full and the
  output is narrowed at the end, so a computed field always sees a complete
  entity. Measured at 0.7ms per pet and zero extra queries for a 60-pet grid.

## [1.1.0] - 2026-08-11

Renamed to **ShelterKit Pets**. The slug, text domain and main plugin file all
changed with it; the plugin's internal naming (`Petsync\`, `PETSYNC_`) and every
stored identifier — post type, taxonomies, meta keys, option names, cron hooks
and all 21 block names — deliberately did not, so no placed block or stored
record is affected.

### Added
- **Import pets from CSV.** Pets → Import, and `wp shelterkit import`. Column
  headings are matched loosely, so "Good with dogs?" finds the right field and a
  shelter's existing spreadsheet needs no renaming. A preview shows exactly what
  will be added, changed or skipped — including any heading that could not be
  placed — and nothing is written until it is confirmed. Re-uploading a corrected
  sheet updates rather than duplicates, matching on microchip number. Imported
  pets count as hand-entered, so a later sync never overwrites or unpublishes
  them.
- **Export pets to CSV and JSON.** Pets → Export, and `wp shelterkit export`.
  Two modes: `portable` re-imports, `full` includes computed fields for reading.
  Both use the same column schema as the importer, so an export can be edited in
  a spreadsheet and imported straight back.
- **`wp shelterkit migrate`**, so a database upgrade is deliberate and
  observable rather than firing on whichever page load happens to be first.
  `--dry-run` names every pending migration without writing. The rail no longer
  runs automatically under WP-CLI; web traffic still triggers it as before.
- **Provider vocabulary moved into `config/providers/<slug>.json`.** Adding a
  platform is adding a file, not editing PHP. Maps can rename a field, translate
  a value (Adopt-a-Pet sends sex as `f`/`m`), invert a polarity, and declare
  nested response paths.
- Fixture-backed maps for **Adopt-a-Pet** and **RescueGroups.org**, neither
  verified against a live account and both marked as such in the file. They exist
  to prove the provider layer is general, not to be shipped to a shelter.
- **A notice when the provider goes dark.** Pets carry `_pet_last_seen`, so a
  feed that stops returning an animal is visible rather than silent.
- **Read abilities offered to MCP clients** via `meta.mcp.public`, after the read
  surface was covered by tests.
- Pet gallery images can be managed from the dashboard.
- A test suite: 248 integration and unit tests at this release.

### Changed
- **Image sizes are budgeted.** A sideloaded photo is capped at 1600px on its
  longest edge and generates only the three sizes the plugin actually renders,
  cutting the pet media footprint by 39%. Filterable via
  `petsync_max_image_edge` and `petsync_rendered_image_sizes`. Existing
  attachments are untouched.
- **CSV is written and read as RFC 4180** — quotes doubled, no backslash escape —
  which is what Excel and Google Sheets both produce and consume.
- Kennel-card printing now requires `edit_others_posts` rather than being
  available to any author.
- The canonical field vocabulary is frozen, with two renames carried by a
  migration and one block-binding alias kept for compatibility.

### Fixed
- **A portable export omitted every pet's description.** `description` is
  computed from the post content, so it never arrived through the editable-field
  list — a backup carried all of a pet's data and none of its story, and
  restoring one produced empty descriptions. Found by the import round-trip.
  **Anyone holding an export taken before this release should re-export.**
- **The gallery lightbox opened behind the page.** `position: sticky` always
  creates a stacking context, even at `z-index: auto`, so a sticky ancestor
  buried the overlay. Now a native `<dialog>`, whose top layer escapes every
  stacking context without moving the element.
- **Hand-entered pets received no compatibility attribute terms**, so they were
  invisible to filtering while looking correct on their own page. Terms are now
  derived from the canonical field rather than from raw provider data.
- **Site Editor customisations survived the renames.** Migrations 4 and 5
  consolidate template customisations filed under any of the plugin's four
  previous `wp_theme` namespaces.
- A negative ID passed to the batch-get ability resolved to a real, unrelated
  pet, because `absint()` takes the absolute value. Three separate occurrences
  fixed to `intval()` with a positive filter.
- The hero crossfade read state where it should have read context.
- Kennel-card block styles are loaded on the admin screen, so the print sheet
  is styled.

### Security
- Visitor favourite and comparison cookies are guarded, and the REST security
  model is documented at `Petsync_REST::check_permission`.
- Kennel-card ID parsing and plugin deactivation paths are hardened.

## [1.0.0] - 2026-07-30

### Added
- Initial public release.
- Printable kennel cards. **Pets → Kennel Cards** filters to the animals
  currently available, and prints the selected ones four to a sheet, two to a
  sheet, or one per page. The sheet renders on the admin screen and print CSS
  hides everything WordPress wraps around it.
- The card's design is a `kennel-card` block template part rather than markup
  in PHP, so it is edited in the Site Editor and every field on it is a block
  binding. **Edit card design** deep-links straight to that one part. Its
  layout follows the card Petfinder used to print, which is the format the
  shelters that relied on it already know.
- Manual pet entry: every field a sync would supply can be entered by hand from
  grouped panels in the editor sidebar, so the plugin works with no Petstablished
  account at all. Field list, grouping and control types come from
  `entities.json`, so adding a field is a config change.
- Pets record which platform they came from. Fields on imported pets are
  read-only in the editor, since the platform is their source of record, and a
  sync never touches pets it did not import.

### Fixed
- Pet listings issue 3 database queries for a 99-pet archive instead of 202.
  Batch priming covered the pets' own meta and terms but not the separate
  posts hydration reaches for — featured images, gallery attachments and
  bonded partners — so each of those cost a query per pet.
- Hand-created pets are given the `Available` status when saved with none, so
  they appear on the pet archive. The listing grid filters on that status, so a
  pet without one rendered correctly on its own page while being invisible on
  the archive — missing rather than visibly broken.
- Pets record which platform they were imported from (`_pet_provider`), and the
  sync matches records on provider + ID rather than ID alone. Stale-pet pruning
  is scoped the same way, so a sync only ever drafts pets it imported itself —
  never hand-authored pets, and never another platform's.
- Versioned schema rail (`petsync_db_version`) so changes to stored data run
  once, in order, on upgrade.
- Batched sync from the Petstablished public API with an admin progress UI and WP-Cron scheduling.
- `vcps_pet` custom post type with taxonomy filtering (species, breed, age, size, gender, color) and URL-driven compatibility filters.
- Block library: pet card, listing grid, slider, filters, details, gallery, actions, attributes, health, compatibility, comparison, compare bar, favorites (toggle and modal), adoption CTA, adoption action, adoption fee, breadcrumb, tagline, notifications toast, and back-to-top.
- Adoption action block supports three modes: Petstablished application form link, internal page link, or PDF download.
- Plugin-wide notification region (`petsync/pet-toast`) surfacing favorites/comparison/sharing confirmations and sync errors — visible toast plus screen-reader announcement from a single aria-live region.
- Hover/focus tooltips on the icon-only pet-actions overlay buttons, driven by their state-aware aria-labels.
- Anonymous favorites and side-by-side comparison powered by the Interactivity API.
- Block templates and template parts editable in the Site Editor, including user customizations (served from the plugin's `wp_theme` namespace).
- Built on the WordPress Abilities API and Block Bindings; server-rendered blocks with no build step.
- Uninstall handler with an opt-in "Delete all data when this plugin is deleted" setting (default off, so delete + reinstall loses nothing). Ephemeral state (cron event, transients) is always cleaned up; pets, `pet_*` taxonomy terms, Site Editor template customizations, options, and per-user favorites/comparison meta are removed only when opted in (multisite-aware).

[Unreleased]: https://github.com/jwincek/shelterkit-pets/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/jwincek/shelterkit-pets/releases/tag/v1.0.0
