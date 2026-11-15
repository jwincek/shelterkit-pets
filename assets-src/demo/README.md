# Demo shelter

A fictional shelter with sixteen animals, for the WordPress.org screenshots and
for anyone who wants to see the plugin with something in it. It is not shipped:
`.distignore` excludes `assets-src/`.

| File | What it is |
|---|---|
| `pets.csv` | The animals, in the importer's own format. `image` and `gallery` name files in `photos/`. |
| `photos/` | 20 photos: one per animal, plus a gallery for Rocco and Marmalade. |
| `CREDITS.md` | Where each photo came from. All are CC0. |
| `shelter.json` | Shelter Details for Larkspur Lane Animal Shelter. |
| `listed.json` | How many days ago each animal was listed. |
| `intake.csv` | The sheet screenshot 6 previews. `@Sunny` in the id column stands for Sunny's post ID on the site. |

## Loading it

Use a fresh, throwaway WordPress site with ShelterKit Pets active and pretty
permalinks on. Then, from the site root:

```sh
wp eval-file wp-content/plugins/shelterkit-pets/bin/demo-site.php import
```

That puts the photos in the Media Library, fills in Shelter Details, imports the
animals through the same importer as **Pets → Import**, and dates the listings.
Without `import`, it stops after writing the CSV with this site's photo
addresses, so the import preview can be opened and captured first.

The script refuses to run on a site that already has pets, or whose Shelter
Details name another shelter. Demo animals must never mix with a real shelter's.

## Why it looks the way it does

The data is meant to be realistic, not flattering, so screenshots show what a
shelter will actually get.

- **Long values, on purpose.** Breeds like "American Staffordshire Terrier Mix"
  and ages like "2 years, 5 months" are what real listings contain, and they
  are what decides whether a printed card fits. Clementine's card is the
  tightest. Her special-needs note wraps to two lines, and on a test site
  with a long address her card at index size ran 18px past the cell (see #107).
- **The features that matter all appear:**
  - a bonded pair (Oreo and Domino);
  - two animals with special needs (Duke and Clementine);
  - an Adoption Pending dog and an On Hold cat;
  - a rabbit, so the species filter has more than two choices;
  - mixed answers on compatibility, including unknowns.
- **Clearly fictional contact details:** a 555-01xx phone number, which is
  reserved for fiction, and `.example` addresses, which can never belong to
  anyone.
- **Photos cropped for how they are shown.** Listings and galleries crop to
  landscape, so portrait photos were cropped to 4:3 around the animal's face
  rather than left to a center crop.

## Screenshots

`npm run screenshots` captures the WordPress.org screenshots from a site loaded
with this data. [CONTRIBUTING.md](../../CONTRIBUTING.md#screenshots) has the
steps.

## Changing it

Edit `pets.csv` like any import file; the loader checks that every photo it
names exists. A new photo must be CC0 or public domain, with an entry in
`CREDITS.md`. Keep photos at 1200px on the long edge, saved without metadata.
