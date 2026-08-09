# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] — 2026-08-08

First public release. Requires GLPI 11.0+. Pairs with
[glpi-mobile](https://github.com/tankerkiller125/glpi-mobile) 0.1.0.

### Added

- **QR pairing broker.** Auto-provisions a confidential OAuth client at install
  and keeps its secret server-side; a "Mobile app" tab in *My Settings* mints a
  single-use, 120-second pairing code rendered as a QR. The app never sees a
  password, client id or client secret.
- **Device sessions.** Pairing returns a non-rotating device credential; the
  rotating GLPI refresh token is stored here, encrypted, so a lost response
  can't lock a technician out. Legacy refresh-token clients are migrated in
  place. Transient failures answer `503`, never `401`.
- **Admin console** at *Setup → GLPI Mobile*: push transport configuration, a
  "send test notification" button, and a **Paired devices** panel with
  per-device revocation.
- **Push notifications** for assignment, group assignment, approval requests,
  followups and tasks — queued on `item_add` and delivered from cron over:
  - **UnifiedPush**, with RFC 8291 payload encryption and RFC 8292 VAPID
    signing implemented in plain PHP (validated against the RFC test vectors);
  - **FCM**, with the client config served to the app so Firebase is initialised
    at runtime rather than baked into the APK;
  - **APNs**, via an ES256-signed JWT over HTTP/2 (implemented, not verified
    against real Apple infrastructure).
- **Service catalog API** — list forms, serialize sections/questions with
  server-resolved options, and submit through GLPI's own `AnswersHandler` so
  destination mapping matches the web UI exactly. Submissions are deduplicated
  by marker.
- **Document upload/list** for ITIL objects, assets and management records —
  the high-level API returns `201` for uploads without storing the bytes.
- **ITIL extensions** — change and problem analysis fields, and relationships
  between tickets, changes and problems (resolved in both directions).
- **Asset extensions** — network ports with resolved IPs, installed software,
  linked ITIL objects, and raw record access for itemtypes whose HL schema omits
  contact details.
- **Aggregated planning feed** mirroring `Planning::constructEventsArray`.

### Known limitations

- APNs delivery has never been verified end to end.
- No independent security review.
- Tested only on GLPI 11.0.8 with MariaDB, in Docker.

[Unreleased]: https://github.com/tankerkiller125/glpimobile/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/tankerkiller125/glpimobile/releases/tag/v0.1.0
