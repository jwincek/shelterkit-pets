# Releasing ShelterKit Pets

A release is a pull request that bumps the version and writes the changelogs,
then a tag pushed from `main`. The tag drives everything else.

## The release pull request

Fix pull requests do not write changelog entries; the release does, once, for
everything merged since the last tag. On a branch named `chore/vX.Y.Z`:

1. **Bump every version declaration:**

   ```sh
   php bin/bump-version.php X.Y.Z
   ```

   That rewrites the plugin header, `PETSYNC_VERSION`, `readme.txt`'s
   `Stable tag`, `package.json` and all 21 `block.json` files. Validator
   check 8 fails the build if any of them disagree.

2. **Write `CHANGELOG.md`.** Move `[Unreleased]` to `[X.Y.Z] - date`, grouped
   as Security, Added, Changed, Deprecated and Fixed, written for the people
   who run the plugin.

3. **Write `readme.txt`:** a `= X.Y.Z =` entry under `== Changelog ==`, and an
   entry under `== Upgrade Notice ==` of **300 characters or fewer**, which is
   all WordPress.org displays. Put whatever a shelter must act on first.

4. **Check `Tested up to`.** Validator check 12 requires it to match the
   newest WordPress in the CI matrix, so claiming a new version means adding it
   to the matrix in `.github/workflows/ci.yml` and seeing the suite pass there.

5. **Run every check against the build:** the commands in
   [CONTRIBUTING.md](CONTRIBUTING.md#quality-checks), then Plugin Check
   against `build/shelterkit-pets` with `--slug=shelterkit-pets`.

6. **Do a clean-install pass** (below) when the release changes anything a
   fresh site meets differently: activation, uninstall, scripts, defaults.

### Choosing the number

- **Patch** for fixes that change no public contract.
- **Minor** for new settings, deprecations, and changes to what an ability, the
  REST API or a hook returns. 1.6.0 was minor because microchip numbers
  stopped being returned to visitors.
- **Major** for removing something deprecated, such as the old `wp shelterkit`
  commands, or for anything on the
  [must-not-change list](CONTRIBUTING.md#what-must-not-change).

## Publishing

After the release pull request is squash-merged:

```sh
git checkout main && git pull
git tag vX.Y.Z && git push origin vX.Y.Z
```

`.github/workflows/release.yml` then:

1. refuses a tag that is not on `main`, or that disagrees with the plugin
   header;
2. runs the config validator and builds the package, with its guard;
3. creates the GitHub Release with `shelterkit-pets-X.Y.Z.zip` attached;
4. deploys to WordPress.org SVN: `trunk/`, `tags/X.Y.Z/`, and `.wordpress-org/`
   into `assets/`. This step is skipped until the `SVN_USERNAME` and
   `SVN_PASSWORD` secrets exist.

**Tag from `main`, never from the release branch.** A squash merge creates a
new commit, so a tag on the branch points at a commit `main` never took. The
content is identical, but the tag is orphaned from the history.

**Never create the GitHub Release by hand.** The tag must come first so the
checks run before anything is published. A hand-made release is how 1.1.1
shipped with no zip attached.

If a run fails after the tag is correct, re-run it from the Actions tab with
the tag as input. Every step is safe to repeat.

## The clean-install pass

The test suite runs inside one long-lived WordPress, and a development site has
other plugins that load things in a convenient order. Neither is what a new
user, or a WordPress.org reviewer, starts from. Before 1.6.0 this pass found
five bugs that a green suite and a working development site had both hidden,
among them an editor script that never ran on a clean site.

1. Install a fresh WordPress at the current release, with a default theme, no
   other plugins, default settings (plain permalinks), and `WP_DEBUG` and
   `WP_DEBUG_LOG` on.
2. Install **the built zip**, not the repository, and activate it.
   `debug.log` should stay empty, and activation should create nothing but the
   pet status and compatibility terms.
3. As an administrator, open every Pets screen and the pet editor, with the
   browser console open. Add a pet by hand, import a CSV, export, print kennel
   cards.
4. As a logged-out visitor, browse the archive and a pet page, favorite a pet
   from a card, and compare two.
5. Uninstall twice, once with **Delete all data** off and once with it on, and
   compare each table with a fresh site. With it on, nothing of the plugin's
   should remain but the shared `shelterkit_organization` option.

## WordPress.org

### First submission

WordPress.org reviews one plugin per author at a time, and each review takes
weeks, so the order of submissions matters.

1. Submit the zip attached to the GitHub Release at
   <https://wordpress.org/plugins/developers/add/>, not a local build.
2. The slug comes from the `Plugin Name` header and is permanent. It should be
   `shelterkit-pets`, which must also stay the text domain.
3. Once approved, confirm the slug WordPress.org actually assigned matches
   `SLUG` in `release.yml`. Then add the `SVN_USERNAME` and `SVN_PASSWORD`
   repository secrets, and re-run the release workflow for the current tag to
   publish it.

### Listing assets

`.wordpress-org/` holds the banner, icons and `screenshot-N.png` files. The
deploy copies it to SVN `assets/`, which sits beside `trunk/` rather than inside
it, and `.distignore` keeps it out of the zip. `assets-src/` has the editable
sources and how to regenerate them.

Screenshots pair with the `== Screenshots ==` captions in `readme.txt` by
number, not by filename. A gap attaches captions to the wrong images without
any error; validator check 9 reports a mismatch.
