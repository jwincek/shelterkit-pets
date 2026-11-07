# Security policy

## Reporting a vulnerability

Please report security problems privately, through GitHub:
**[Report a vulnerability](https://github.com/jwincek/shelterkit-pets/security/advisories/new)**
(the Security tab of this repository). Please do not open a public issue, pull
request or forum post for anything that could put a site at risk before it is
fixed.

A useful report says:

- the plugin version, and the WordPress and PHP versions;
- what an attacker can do, and what they need first (logged out, a subscriber
  account, an editor);
- steps to reproduce it, ideally on a fresh install with only this plugin
  active.

Reports are handled by the maintainer. You will be credited in the advisory
and the changelog unless you would rather not be. If you hear nothing after a
reasonable time, the WordPress.org Plugins Team can be reached at
plugins@wordpress.org for plugins listed there.

## Supported versions

Only the latest release receives security fixes. Updating is the fix.

## What is public by design

Some things are readable by anyone on purpose, and are not vulnerabilities in
themselves:

- **Published pets** and everything the blocks display about them, through the
  pages, the REST API and the read abilities (`get-pet`, `list-pets`,
  `filter-pets`, `batch-get-pets`, `get-filter-options`, `get-adoption-stats`).
  Draft, pending and private pets are not.
- **Favorites and comparison** work for logged-out visitors, keyed to a cookie
  in their own browser. They hold only pet IDs. A request from another site
  cannot reach a logged-in user's saved lists, because WordPress treats a
  cookie-authenticated REST request without a nonce as anonymous.
- **Shelter Details**, the shelter's own contact information, is meant to be
  seen. It prints on kennel cards and, when structured data is turned on,
  describes the shelter to search engines.

What should **not** be public, and is worth reporting if you find a way to it:

- a pet's **microchip number**, which is returned only to users who can edit
  that pet;
- the provider's record ID and raw API data stored with a synced pet;
- anything about draft, pending or private pets;
- another user's saved favorites or comparison;
- any write action (sync, import, export, settings, kennel-card setup) without
  the matching capability and nonce.

## Out of scope

- Problems in Petstablished's own platform or API: please report those to
  Petstablished.
- WordPress core and other plugins: report those to their own projects. For
  WordPress core, that is the
  [WordPress HackerOne program](https://hackerone.com/wordpress).
