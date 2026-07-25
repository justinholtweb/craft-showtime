# Showtime

Events, bookings and memberships for Craft CMS — in one plugin, under one licence.

Showtime combines three plugins we also sell separately into a single install: **Owl**
(events and calendars), **Stub** (bookings and appointments) and **Headcount** (memberships
and subscriptions). Buying them individually costs more than the bundle, and the bundle does
things none of them can do alone.

## What's included

Everything from all three plugins, plus the features that only exist because they ship
together:

- **Member perks** — a plan discounts a service or an event ticket, or restricts either to
  members. Booking discounts are applied before the payment amount is derived, so a member
  is *charged* less, not just shown less.
- **Member-only events** — access rules can target events by calendar or individually, with
  redirect, paywall or hide behaviour. Gated events also drop out of the public calendar feed
  and stop being buyable.
- **Provider events block appointment slots** — somebody teaching a class at 10am is no
  longer bookable for a 1:1 at 10am.
- **Bookings on the events calendar** — one calendar for staff instead of two screens.
- **One Stripe endpoint, one sender** — one webhook URL and one from-address for the whole
  bundle instead of one per plugin, with every notification listed on a single screen.
- **One person, one view** — *Showtime → People* shows a customer's bookings, membership and
  ticket orders together, and `craft.showtime.person()` gives templates the same object.

## Requirements

- Craft CMS 5.6 or later
- PHP 8.2 or later
- Craft Commerce 5.0 or later — **only** for event ticketing, which stays dormant without it

## Installation

Install from the Plugin Store, or with Composer:

```bash
composer require justinholtweb/craft-showtime
php craft plugin/install showtime
```

### Already running Owl, Stub or Headcount?

**Do not uninstall the standalone plugin first** — `plugin/uninstall` runs its install
migration in reverse and drops your bookings, members or events. Installing Showtime adopts
an existing install in place instead, without moving a row of data:

```bash
composer require justinholtweb/craft-showtime
php craft plugin/install showtime      # adopts whatever is already installed
composer remove justinholtweb/craft-stub justinholtweb/craft-headcount justinholtweb/craft-owl
```

That last step matters: while both packages are present, Composer reports "Ambiguous class
resolution" and may load the standalone's classes instead of the bundled copies.

Your settings, data, permissions and migration history all carry over. Nothing needs
reconfiguring in Stripe — each plugin's existing webhook endpoint keeps working, so moving
to the bundle's single endpoint is optional.

## How the single licence works

Craft licenses plugins per *handle*, and has no native concept of a bundle. So Showtime is
one plugin (handle `showtime`, one licence) that **mounts** the other three as internal
modules rather than requiring them as separate installs. Each keeps its own namespace,
services, templates, controllers, database tables and migration track — but because none of
them is registered with Craft's Plugins service, **none needs its own licence key.** Only
Showtime's is checked.

## Documentation and support

- Issues and feature requests: [GitHub issues](https://github.com/justinholtweb/craft-showtime/issues)
- Changelog: [`CHANGELOG.md`](CHANGELOG.md)

## Development

The canonical source for each bundled plugin is its own repository. `src/modules/{owl,stub,headcount}/`
holds a vendored copy, synced one way:

```bash
bin/sync-modules.sh              # pull the latest sub-plugin source in
bin/sync-modules.sh stub         # …or just one
```

**Never edit `src/modules/*`** — the next sync overwrites it. Fixes belong in the
sub-plugin's own repo, then re-sync.

```bash
composer phpstan                 # host code only; src/modules/* is linted in its own repo
composer ecs                     # composer ecs-fix to autofix
```

`php craft showtime/test/run` asserts the whole mount contract against a real database and
exits non-zero on failure. Run it against a **fresh install**, not just a migrated one — the
install and migrate paths diverge, and only the fresh one catches a table that a later
migration created instead of `Install.php`.

## Licence

Licensed under the [Craft License](LICENSE.md).
