# AI disclosure

This plugin was built with AI, and says so plainly — it holds OAuth secrets,
exposes endpoints without authentication by design, and implements
cryptography. You should know how it came to exist before you install it on a
production GLPI.

## The short version

**Substantially all of the source code and documentation in this repository —
including this file — was written by Anthropic's Claude, operating as an agent
through [Claude Code](https://claude.com/claude-code).** A human maintainer
directed the work, made the design decisions, supplied the GLPI server, ran the
verification and decided what to keep. But the characters in these files were,
in the overwhelming majority, produced by a model rather than typed by a person.

The same is true of the mobile app,
[glpi-mobile](https://github.com/tankerkiller125/glpi-mobile).

## What that means in practice

### How it was built

Nothing here was written from documentation alone. GLPI 11's high-level API is
under-documented and, in several places, behaves differently from what its
schemas imply — so every endpoint followed the same loop:

1. **Establish the real contract with `curl`** against a live GLPI 11.0.8
   instance, and read the database row back afterwards rather than trusting a
   `200`.
2. **Implement**, then `php -l`, then `bin/console cache:clear` (route changes
   are cached and will otherwise 404).
3. **Verify from the app on a real device**, including at least one offline
   round-trip for anything that writes.
4. **Write down the trap** that made step 1 necessary. The "Development"
   section of the README is that list, and every item in it is a failure that
   actually happened here.

### What is genuinely verified

- Pairing, token refresh, device revocation, and the 401-vs-503 failure split
  were exercised against a real server, including by deliberately breaking the
  server-side OAuth configuration to confirm a transient failure does **not**
  sign devices out.
- The Web Push implementation was validated against the **RFC 8291 §5 test
  vectors** and an encrypt→decrypt round-trip, then end-to-end: a real
  notification, encrypted by this code, delivered through a self-hosted ntfy to
  an Android device — including to a killed app.
- FCM delivery was verified end-to-end against a real Firebase project
  (HTTP 200 plus a notification on the device).
- Form submission, document upload, ITIL links, analysis fields, planning,
  asset ports and software were each verified by `curl` and then from the app,
  with idempotency confirmed by repeating the call and checking that nothing was
  duplicated.

### What is not verified

- **APNs has never delivered a real notification.** No Mac, no Apple developer
  key, no iPhone. The sender is implemented and its ES256 JWT signing is
  validated locally, and that is all.
- **No independent security review.** Not of the pairing broker, not of the
  unauthenticated endpoints, not of the Web Push crypto. Matching the RFC test
  vectors proves the algorithm was implemented correctly; it says nothing about
  the surrounding code.
- **No load or scale testing.** The notification queue and cron drain were
  exercised with tens of notifications, not thousands.
- The plugin has run only on a Dockerised GLPI 11.0.8 with MariaDB. Other
  versions, other databases and other web-server configurations are untested.

## What you should do about it

- **Read `src/OAuthBroker.php`, `src/PairController.php` and
  `src/DeviceSession.php` before deploying.** That is where credentials are
  minted, stored and accepted.
- **Decide for yourself whether you accept the unauthenticated endpoints.**
  `/pair`, `/refresh` and `DELETE /devices` take no session because the code,
  the device secret and the push endpoint are themselves the credential. That is
  a deliberate design choice, and one worth agreeing with rather than
  inheriting.
- **Test the uninstall path** in a staging instance if you care about clean
  removal — it drops tables and the OAuth client.
- **Report what you find.** Findings from a real deployment, and especially from
  anyone who reviews the crypto or the auth flow, are the most valuable thing
  this project can receive.

## Attribution and licensing

The code is released under the [MIT License](LICENSE). AI-generated output does
not change the license you receive or the obligations attached to it. The
implementation was written against GLPI's public API and its own source, and no
third-party code was knowingly copied into this repository.

Contributions written with AI assistance are welcome — say so in the pull
request, so reviewers know where to aim their attention.
