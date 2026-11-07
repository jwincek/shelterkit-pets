# ShelterKit Pets

Adoptable pet listings and printable kennel cards for animal shelters, on your
own WordPress site.

Your animals become ordinary WordPress content: indexed by search engines,
styled by your theme, and shown with a set of blocks for listings, filters,
galleries, favorites and side-by-side comparison. Kennel cards print from the
same records, so the card on the kennel and the listing online cannot disagree.

Add animals by hand, import a spreadsheet, or sync from
[Petstablished](https://petstablished.com). No platform account is required.

## What it does

- **Printable kennel cards.** Pick the animals, choose a size (four, two or one
  to a sheet), print. The card is a template part you rearrange in the Site
  Editor, and every field on it is a block binding.
- **Three ways in.** Enter a pet in the editor, import a CSV from
  **Pets → Import** (headings are matched loosely, and every row is previewed
  before anything is written), or connect Petstablished and sync on a schedule.
  The three can be mixed: a sync only ever touches the pets it imported.
- **Export** to CSV or JSON in the same format the importer reads.
- **21 blocks:** pet cards, listing grid, slider, filters, gallery,
  comparison, favorites, adoption call to action and more. Favorites and
  comparison work for visitors without an account.
- **Shelter Details:** your shelter's name, address and phone, entered once
  and shared with the other ShelterKit plugins.
- **Optional `AnimalShelter` structured data** that refines what an SEO plugin
  already says about your organisation instead of competing with it.
- **WP-CLI:** `wp shelterkit-pets export`, `import` and `migrate`.

Built on the Abilities API, Block Bindings and the Interactivity API, with no
build step.

## Requirements

- WordPress 6.9 or newer
- PHP 8.1 or newer

## Installation

1. Download `shelterkit-pets-<version>.zip` from the
   [latest release](https://github.com/jwincek/shelterkit-pets/releases) and
   upload it under **Plugins → Add New → Upload Plugin**.
2. Activate it.
3. Add an animal under **Pets → Add New**, or import a spreadsheet from
   **Pets → Import**. Using Petstablished? Enter your public key under
   **Pets → Sync Settings** and click **Sync Now** instead.
4. Add the pet blocks to your pages from the block inserter.

Install the release zip, not a clone of this repository. The repository also
carries tests, tooling and CI configuration that the release build strips out.

## Privacy

Without a Petstablished key, your server contacts no outside service at all.
With one, it fetches your listings from Petstablished, whose photos are hosted
on Amazon Web Services. A pet's YouTube video is embedded from YouTube's
privacy-enhanced domain. The **External services** section of
[`readme.txt`](readme.txt) says exactly what is sent, when, and where.

Microchip numbers are visible only to people who can edit the pet. A visitor's
favorites and comparisons are kept in their own browser and a cookie, or, for
a logged-in user, with their account.

## Getting help

- **Questions and how-tos:** the WordPress.org support forum, once the plugin
  is listed there.
- **Bugs and feature requests:**
  [GitHub issues](https://github.com/jwincek/shelterkit-pets/issues).
- **Security problems:** please report privately. See [SECURITY.md](SECURITY.md).

## Part of ShelterKit

Plugins for animal shelters, each of which works on its own:

- **ShelterKit Pets** (this plugin): adoptable animals and kennel cards
- [ShelterKit Donations](https://github.com/jwincek/shelterkit-donations):
  donations, memberships and memorials through WooCommerce
- [ShelterKit Events](https://github.com/jwincek/shelterkit-events): recurring
  shelter programs for The Events Calendar

## Developing

[CONTRIBUTING.md](CONTRIBUTING.md) covers setup, the quality checks, how the
code is organised and what must never be renamed.
[RELEASING.md](RELEASING.md) covers cutting a release and the WordPress.org
listing. [CHANGELOG.md](CHANGELOG.md) has the full history.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
