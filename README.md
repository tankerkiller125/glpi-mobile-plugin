# GLPI Mobile — companion plugin

The server side of [**GLPI Mobile**](https://github.com/tankerkiller125/glpi-mobile-app),
an offline-first mobile client for [GLPI](https://glpi-project.org/) 11.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
![GLPI](https://img.shields.io/badge/GLPI-11.0%2B-orange)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4)

It does three jobs the mobile app cannot do for itself:

1. **Passwordless pairing.** It holds the OAuth client secret server-side and
   brokers real tokens for a QR code, so no password, client id or secret ever
   lives in a distributed APK.
2. **Push notifications.** Ticket events are enqueued in-process and delivered
   from cron over **UnifiedPush** (self-hosted, Firebase-free), **FCM** or
   **APNs** — GLPI itself has no push, only email and an AJAX poll.
3. **The endpoints GLPI's high-level API doesn't publish** — service-catalog
   forms, ITIL relationships, change/problem analysis fields, aggregated
   planning, asset ports and installed software, and file uploads that actually
   store the file.

> ### 🤖 Built with AI
>
> **Substantially all of the code and documentation in this repository was
> written by an AI assistant** (Anthropic's Claude, via Claude Code), under a
> human maintainer's direction, with every endpoint verified by hand against a
> live GLPI 11.0.8 server before it was considered done.
> This plugin holds OAuth secrets, exposes unauthenticated endpoints by design,
> and implements Web Push cryptography — please read
> [AI-DISCLOSURE.md](AI-DISCLOSURE.md) and review those parts yourself before
> deploying it.

---

## Requirements

- **GLPI 11.0+** with the high-level API enabled (*Setup → General → API*).
- PHP 8.2+ with `openssl` and `curl` (both are already GLPI requirements).
- For UnifiedPush: a push server you control, e.g. [ntfy](https://ntfy.sh/).
  No third-party account is needed — the plugin generates its own VAPID keys.
- For FCM: a Firebase project and a service account. For APNs: an Apple `.p8`
  key.

No Composer dependencies: everything, including the Web Push encryption and the
JWT signing for FCM/APNs, is implemented against PHP's own `openssl`/`hash`
extensions. The QR code is rendered with the `bacon/bacon-qr-code` library that
ships with GLPI core.

## Installation

```sh
cd /var/www/glpi/plugins
git clone https://github.com/tankerkiller125/glpi-mobile-plugin.git glpimobile
```

The directory **must** be named `glpimobile` — GLPI derives class and hook names
from it. Then *Setup → Plugins → GLPI Mobile → Install → Enable*.

Installation is idempotent and creates:

| | |
| --- | --- |
| `glpi_plugin_glpimobile_pairings` | one-time pairing codes (120 s TTL), tokens encrypted at rest |
| `glpi_plugin_glpimobile_sessions` | paired devices: device id, hashed secret, GLPI refresh token (encrypted) |
| `glpi_plugin_glpimobile_devices` | push registrations: transport, endpoint, Web Push keys |
| `glpi_plugin_glpimobile_notifqueue` | pending notifications with retry state |
| `glpi_plugin_glpimobile_formsubmits` | form-submission ledger, so a retried submit can't file twice |
| An OAuth client | confidential, `authorization_code` + `refresh_token`, redirect `glpimobile://paired` |
| A VAPID keypair | for Web Push / UnifiedPush; the private key is stored encrypted |
| A cron task | `Push::Send`, every 60 s, drains the notification queue |

Uninstalling drops all of the above, including the OAuth client.

## Configuration

Everything lives at **Setup → GLPI Mobile** (also reachable from the gear in the
plugin list). Nothing has to be configured for pairing to work — only push
needs setup.

### UnifiedPush (Android, no Google dependency)

Nothing to configure on the server: the app registers an endpoint on whatever
push server the device's UnifiedPush distributor points at, and the plugin
delivers to it with encrypted payloads (RFC 8291) signed with the VAPID key
generated at install (RFC 8292). Run your own [ntfy](https://ntfy.sh/) and tell
technicians to install the distributor app.

### FCM (Android fallback)

There is **no baked-in `google-services.json`** in the app — a distributed APK
would otherwise be locked to one Firebase project forever, which is useless when
every self-hosted GLPI has its own. Instead the app initialises Firebase at
runtime from this server. In *Setup → GLPI Mobile*, provide:

- **Client config** (public; the app fetches it): project id, app id, API key,
  sender id. Register an Android app in your Firebase project with the package
  name `com.tankerkiller125.glpi`.
- **Service account JSON** (secret, stored encrypted) — the sender credential.

FCM is only offered to the app when all five are present.

### APNs (iOS)

Provide the `.p8` key (secret, encrypted), key id, team id and bundle id
(`com.tankerkiller125.glpi`), and choose sandbox or production.

> **Not verified end to end.** The APNs sender is implemented and its ES256 JWT
> signing is validated locally, but no Apple device or key was available to test
> real delivery.

### Verifying

Use **Send test to my devices** on the config page. Notifications are delivered
by cron, so on a dev box you may want to run it by hand:

```sh
php /var/www/glpi/front/cron.php     # GLPI 11 has no `glpi:cron` console command
```

### Paired devices

The config page lists every paired device — user, platform, when it paired, when
it was last seen — with a **Revoke** button. A revoked device loses access within
the hour (when its access token expires) and lands back on the sign-in screen.

## How pairing works

GLPI 11 **always** issues a client secret and requires it at `/token`;
`is_confidential` is a dead column and `Client.php` hardcodes
`isConfidential = true`. There is no such thing as a public/secretless OAuth
client, so the secret cannot ship in an app. This plugin is the answer:

1. The technician opens **My Settings → Mobile app** in GLPI web. Whatever
   protects that login — SSO, 2FA, LDAP — has already done its job.
2. The plugin performs an `authorization_code` exchange **server-side**, on
   behalf of that session, and stores the resulting tokens against a one-time
   code (24 random bytes, 120 s TTL, encrypted at rest). It renders that code as
   a QR.
3. The app scans it and `POST /GlpiMobile/pair`s the code. The code is marked
   used; a replay gets `410`.
4. The app receives an access token **and a device credential**
   (`device_id` + `device_secret`) — but *not* the refresh token.

### Why the app doesn't hold the refresh token

GLPI's `RefreshTokenGrant` revokes the old refresh token *before* replying. An
app holding one is a single dropped response away from permanent lockout with no
recovery but a re-scan — which happened during development. So the rotating
refresh token stays here, encrypted, against a **device credential that never
rotates**. A lost reply becomes a non-event: the app simply asks again.

Legacy installs that still send `{refresh_token}` are migrated in place on their
next refresh and handed device credentials, so nobody re-scans because of the
change.

### Failure handling is asymmetric on purpose

| Situation | Response | App behaviour |
| --- | --- | --- |
| Device unknown/revoked, or the grant is genuinely dead (`invalid_grant`) | `401` | Wipe credentials, return to sign-in |
| Anything else — GLPI unreachable, timeout, 5xx | `503 refresh_unavailable` | Keep the pairing, retry later |

Collapsing the second case into the first is how one slow afternoon signs out
every technician at once. `OAuthBroker::tokenRequest` throws `SessionRejected`
only for `invalid_grant` / `invalid_request`; everything else is a
`RuntimeException`.

Idle devices are expected to expire on their own at ~30 days;
`DeviceSession::purgeIdle()` exists for housekeeping.

## API

All routes live under the high-level API:

```
{glpi}/api.php/v2.3/GlpiMobile/…
```

### Pairing and devices

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `POST` | `/pair` | none¹ | Exchange a one-time pairing code for an access token + device credential |
| `POST` | `/refresh` | none¹ | `{device_id, device_secret}` → a fresh access token |
| `GET` | `/config` | none | VAPID public key, available transports, FCM client config (no secrets) |
| `POST` | `/devices` | OAuth | Register/replace a push registration for the signed-in user |
| `DELETE` | `/devices` | none¹ | Unregister by endpoint (the endpoint is itself an unguessable secret) |

¹ Unauthenticated by design: the pairing code, the device secret and the push
endpoint *are* the credentials being presented. Pairing codes are single-use and
expire in 120 seconds.

### Documents

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/items/{itemtype}/{id}/documents` | multipart upload, linked to the item, idempotent by `marker` |
| `GET` | `/items/{itemtype}/{id}/documents` | list attachments with download URLs |

The high-level API's `POST /Management/Document` returns `201` over OAuth but
**does not store the file bytes**, which is why this exists. `{itemtype}` covers
ITIL objects plus every asset type and an allow-list of management types, so any
record can carry attachments.

### Service catalog forms

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/forms` | the catalog the signed-in user may answer |
| `GET` | `/forms/{id}` | sections → questions, with dropdown options resolved server-side |
| `POST` | `/forms/{id}/submit` | `{marker, answers: {question_id: value}}` |

GLPI 11's Service Catalog is served only by session-authenticated Symfony *web*
controllers, so there is no REST route for it. Submission calls GLPI's own
`AnswersHandler::validateAnswers → removeUnusedAnswers → saveAnswers`, which
means **the form's destination configuration maps answers onto the ticket
exactly as it does on the web** — no field mapping is reimplemented here.
Repeating a submit with the same `marker` returns the original result instead of
filing a second ticket.

### ITIL objects

| Method | Path | Purpose |
| --- | --- | --- |
| `GET`/`PATCH` | `/itil/{itemtype}/{id}/extra` | change analysis (impact, control list, rollout plan, backout plan, checklist) and problem analysis (impact, cause, symptom) |
| `GET`/`POST`/`DELETE` | `/itil/{itemtype}/{id}/links` | relationships between tickets, changes and problems |
| `GET`/`POST`/`DELETE` | `/itil/{itemtype}/{id}/items` | assets linked to an ITIL object |

Those columns exist in `glpi_changes` / `glpi_problems` but the HL schemas omit
them; the relationship schemas are defined in GLPI but publish no routes. `GET`
on links resolves **both directions** and flips `SON_OF`/`PARENT_OF` when read
from the far side.

### Assets, planning, raw records

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/planning?start=&end=` | one aggregated feed of everything GLPI schedules, mirroring `Planning::constructEventsArray` |
| `GET` | `/asset/{itemtype}/{id}/ports` | network ports with MAC and resolved IPs |
| `GET` | `/asset/{itemtype}/{id}/software` | installed software |
| `GET` | `/asset/{itemtype}/{id}/itil` | tickets/changes/problems logged against an asset |
| `GET` | `/record/{itemtype}/{id}/raw` | the raw DB row for an allow-listed itemtype — GLPI's Supplier/Contact schemas omit phone, email, website and address |

## Notifications: what triggers a push

Hooked on `item_add`:

| Event | Who gets it |
| --- | --- |
| `Ticket_User` (assign) | the assignee |
| `Group_Ticket` (assign) | members of the assigned group |
| `TicketValidation` | the requested approver, or members of the requested group |
| `ITILFollowup` | individual assignees + members of assigned groups |
| `TicketTask` | the task's tech, its group, or the ticket's assignees |

The actor who caused the event is never notified about their own action;
inactive users and users with no registered device are skipped. Delivery is
queued, not inline, so a slow push server can't slow down GLPI: `Push::cronSend`
drains the queue, dispatches per device, and prunes registrations the push
server reports as gone (`404`/`410`).

## Development

The app repository's `dev-env/` provisions a GLPI 11 stack with this plugin
bind-mounted, plus an ntfy container for UnifiedPush testing.

```sh
find . -name '*.php' -exec php -l {} \;   # lint
php /var/www/glpi/front/cron.php          # drain the push queue by hand
bin/console cache:clear                   # REQUIRED after adding/changing a route
```

Traps that have already cost real time here — worth reading before you change
anything:

- **Adding or renaming an HL API route requires `bin/console cache:clear`.** The
  route table is cached; without it your new endpoint 404s and you will blame
  your code.
- **Adding a new PHP class to a running container needs an opcache reset**
  (restart the container). A fresh install is fine.
- **`getMenuContent()`'s `canView()` / `canCreate()` must declare `: bool`.** A
  signature mismatch with `CommonGLPI` is a compile error inside
  `generateMenuSession`, which makes **every HTML page in GLPI 500**.
- **A plugin front page must not set `action="$_SERVER['PHP_SELF']"`.** Under
  GLPI 11's front controller that resolves to `/index.php`, whose path-info `/`
  is intercepted by `CatchInventoryAgentRequestListener`, and your form POST
  comes back as "Inventory is disabled". Use a plain `<form method="post">`.
- **A plugin front page must not call `Session::checkCSRF()`.** GLPI 11's
  `CheckCsrfListener` validates *and consumes* the token before the page runs,
  so a second check always `403`s.
- **Uploads must `copy()` the `$_FILES` temp file, not `move_uploaded_file()`.**
  Symfony's HttpFoundation re-references that file when finalising the request;
  moving it throws `FileNotFoundException` *after* your handler returned — an
  uncatchable 500 whose only trace is in `php-errors.log`.
- **The self-call in `OAuthBroker` must connect to the internal port but send
  the original `Host` header.** GLPI's session cookie name is
  `glpi_<sha512(root_dir + HTTP_HOST + SERVER_PORT)>`, so the inner request only
  authenticates when it derives the same name. In Docker, `SERVER_PORT` is the
  *published* port — never connect to it.
- **Answer values must carry the right PHP type** when feeding
  `AnswersHandler`. GLPI's destination strategies index into them, and an
  `item_dropdown` answer arriving as `"7"` instead of `7` is a fatal error
  ("Cannot access offset of type string on string"). `FormController::coerce()`
  handles this.

## Continuous integration and releases

`.github/workflows/ci.yml` runs on every push to `main`, every pull request, and
on demand:

| Job | What it proves |
| --- | --- |
| PHP syntax check | Every file parses on PHP 8.2, 8.3 and 8.4 — the range GLPI 11 supports |
| PSR-4 layout | Each `src/*.php` declares `GlpiPlugin\Glpimobile\<Filename>`. GLPI autoloads by that convention, so a half-finished rename otherwise fails at runtime on whichever page touches the class |
| Distribution archive | `tools/package.sh` builds cleanly and the archive has exactly one top-level `glpimobile/` directory with no repository metadata in it |

Build the archive yourself the same way CI does:

```sh
tools/package.sh      # → dist/glpimobile-<version>.{zip,tar.bz2} + SHA256SUMS.txt
```

### Cutting a release

Tags are created by hand. `PLUGIN_GLPIMOBILE_VERSION` in `setup.php` is the
source of truth — it is what GLPI displays and compares — so the release run
refuses to publish when the tag disagrees with it, or when `CHANGELOG.md` has no
section for that version:

```sh
# 1. bump PLUGIN_GLPIMOBILE_VERSION in setup.php and add a CHANGELOG section, commit
# 2. tag and push
git tag -a v0.2.0 -m 'v0.2.0'
git push origin v0.2.0
```

`.github/workflows/release.yml` lints, packages, verifies the archive layout and
publishes a GitHub release with the `.zip`, the `.tar.bz2` and `SHA256SUMS.txt`.
A version containing a hyphen (`0.2.0-rc.1`) is published as a pre-release. No
secrets are needed; the run uses the default `GITHUB_TOKEN`. Running the
workflow manually builds and validates the archive without creating a release.

## Security notes

- The OAuth client secret, the VAPID private key, the FCM service account and
  the APNs `.p8` are stored **GLPIKey-encrypted** (`secured_configs`) and never
  returned by any endpoint.
- Device secrets are stored hashed, not in plaintext.
- Pairing codes are single-use, expire in 120 seconds, and store their tokens
  encrypted until claimed.
- The Web Push implementation (`src/WebPush.php`) was validated against the RFC
  8291 §5 test vectors and an encrypt→decrypt round-trip. It has **not** had an
  independent cryptographic review.
- Please report vulnerabilities privately — see [SECURITY.md](SECURITY.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). In short: verify against a real GLPI
with `curl` before writing code, `php -l` everything, `cache:clear` after route
changes, and say so if you used an AI assistant.

## License

[MIT](LICENSE) © 2026 tankerkiller125.

GLPI is a registered trademark of Teclib'. This plugin is independent and is not
affiliated with or endorsed by Teclib' or the GLPI project.
