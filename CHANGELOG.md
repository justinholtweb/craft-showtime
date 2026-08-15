# Release Notes for Showtime

## 5.2.0 - unreleased

### Added

- Picks up Headcount's **season memberships**: a membership plan can now run to a fixed
  calendar window shared by every member — a club's July–June year — instead of billing on
  each member's own anniversary, with the window rolling forward automatically each year and
  optional pro-rata pricing for anyone joining mid-season. Season plans are charged as a
  one-off Stripe payment, so they can't be paid for with PayPal.
- Picks up Headcount's **Apple Wallet and Google Wallet membership cards**, configured under
  a new *Memberships → Wallet Cards* settings screen, linked from Showtime's composite
  settings page. The credentials are the site owner's own Apple Pass Type ID certificate and
  Google Wallet issuer account. Apple cards keep themselves up to date on the device when a
  membership changes.
- Two new membership commands worth a daily cron entry:
  `headcount/subscriptions/expire` (now retires finished season terms as well as cancelled
  memberships) and `headcount/subscriptions/remind` (the expiration reminder email, which
  previously had no caller anywhere in the bundle and never sent).

### Fixed

- Headcount's expiration reminder email was never sent, and subscriptions past their end date
  that the member hadn't cancelled were left `active` indefinitely. Both are fixed upstream
  and come in with this sync.
- `src/modules/headcount/services/Gating.php` carried a hand-edited `@deprecated in 5.5.0`
  note that never matched any release; the sync restores Headcount's own `5.2.0`.

## 5.1.0 - 2026-08-13

### Added

- Picks up Stub's currency work: 25 common currencies instead of 5 (CHF among them), and
  any currency configured in Craft Commerce when it's installed. The shared **Default
  Currency** field is now a picker drawn from the same list Stub's own screen uses, so the
  two can't drift apart. Its empty "use each module's own default" option is unchanged.
- Showtime's shared currency is now validated as an ISO 4217 code. It's pushed into Stub
  and Headcount, both of which validate it, so a typo is caught on the screen it was typed
  into rather than being rejected later by a module with no visible field to blame.

### Fixed

- The composite settings screen would have rendered an empty currency dropdown for
  bookings. Stub's settings fragment is included with `only`, and it now needs a
  `currencyOptions` variable that Showtime wasn't passing — Stub's own `settingsHtml()`,
  which normally supplies it, never fires for a mounted module.

## 5.0.0 - 2026-07-26

First release. Showtime is the Owl, Stub and Headcount plugins combined into one plugin
under a single Craft licence, plus the features that only exist because they ship together.

### Added

**The bundle**

- **Owl (events and calendars), Stub (bookings and appointments) and Headcount (memberships
  and subscriptions) run as internal modules of one plugin.** Each keeps its own namespace,
  services, templates, controllers, database tables and migration track; none is registered
  with Craft's Plugins service, so **only Showtime's licence is enforced**.
- **One control-panel section.** A single *Showtime* nav item over the modules' existing
  routes, one combined permission heading — with the permission keys unchanged, so existing
  user groups keep working — and one composite settings screen.
- **A bundle dashboard** at *Showtime*: today's bookings, this week's events, active members
  and combined revenue. Degrades to whatever is mounted, and shows booking income and MRR as
  separate components as well as a total, since summing them alone would mislead.
- **Shared settings.** One Stripe account, one from-address, one currency — entered once and
  handed to each module, with per-module overrides and `config/<handle>.php` still honoured.

**Features only the bundle can offer**

- **Member perks.** A Headcount plan can discount a Stub service or an Owl event ticket, or
  restrict either to members. Booking discounts are applied before Stub derives the Stripe
  amount, so a member is *charged* less rather than merely shown less; ticket discounts are a
  promotional price on the Commerce line item, never a change to the shared purchasable.
  Members-only is enforced where a request can actually be refused, so a crafted POST or a
  known purchasable ID doesn't get past it. Overlapping plans resolve to the lowest price.
- **Member-only events.** Headcount's access rules can target Owl events — all of them, one
  calendar's worth, or a single event — with the usual redirect / paywall / hide behaviours.
  A gated event also drops out of the anonymous `owl/events.json` feed and its tickets stop
  being buyable, because a gate that only covers the page isn't a gate.
- **Provider events block appointment slots.** Someone teaching a class at 10am is no longer
  bookable for a 1:1 at 10am. Managed under *Showtime → Provider calendars*.
- **Bookings on the events calendar.** Staff get one calendar instead of two screens. Owl's
  feed is anonymous and bookings carry customer names, so bookings are added only for a
  viewer who holds `stub:viewBookings`.
- **One Stripe webhook endpoint** for the whole bundle — `/actions/showtime/webhook/stripe`.
  The signature is verified once and the event routed by type. Each module's own endpoint
  still works and behaves identically, so an existing Stripe configuration keeps working.
- **One outgoing sender and one notifications screen.** A from-name and from-address entered
  once apply to every email the bundle sends; *Showtime → Notifications* lists all of them
  with their switches, their available variables and a link to edit the wording. The copy
  itself lives in Craft's system messages, so it renders through the same email template as
  the rest of the site's mail.
- **The identity graph.** *Showtime → People* shows one person's bookings, membership and
  event tickets together, and `craft.showtime.person(user|email)` gives templates the same
  object. Nothing is merged and nothing is stored — the three plugins keep their own schemas
  and the join happens at read time on the email address.

**Upgrading from the standalone plugins**

- **Installing Showtime adopts an existing Stub, Headcount or Owl install in place**, without
  moving a row of data. Do *not* uninstall the standalone first — that runs its install
  migration down and drops your bookings, members or events.

  ```bash
  composer require justinholtweb/craft-showtime
  php craft plugin/install showtime      # adopts whatever is already installed
  composer remove justinholtweb/craft-stub justinholtweb/craft-headcount justinholtweb/craft-owl
  ```

  The last step matters: while both packages are present Composer reports "Ambiguous class
  resolution" and may load the standalone's classes instead of the bundled copies.

### Requires

- Craft CMS 5.0+ and PHP 8.2+
- Craft Commerce 5.0+ *only* for event ticketing, which is dormant without it.
