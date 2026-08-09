# Contributing

This plugin exists to serve the [GLPI Mobile
app](https://github.com/tankerkiller125/glpi-mobile). Changes that aren't needed
by a client are welcome too, but the app is the reason each endpoint is here.

## Setup

Clone into a GLPI 11 instance as `glpi/plugins/glpimobile` (the directory name
matters — GLPI derives hook and class names from it), then install and enable it
from *Setup → Plugins*. The app repository's `dev-env/` provisions a Dockerised
GLPI 11 with this plugin bind-mounted, plus an ntfy container for UnifiedPush
testing.

```sh
find . -name '*.php' -exec php -l {} \;   # lint everything you touched
bin/console cache:clear                   # after ANY route change
php /var/www/glpi/front/cron.php          # drain the push queue by hand
```

## Ground rules

- **Verify the contract before you build on it.** GLPI returns `200` for writes
  it silently ignores. `curl` the endpoint, then read the database row back.
- **Don't add an endpoint the high-level API already provides.** This plugin is
  for verified gaps only; the HL API is generic over itemtypes far more often
  than it first appears (`/Assistance/{itemtype}` covers Ticket, Change and
  Problem).
- **Every write must be idempotent.** The app queues writes offline and retries
  them; a retry after a lost response must re-adopt the existing object, not
  create a second one. Use a marker column or a ledger table, as the existing
  document and form endpoints do.
- **Route changes require `bin/console cache:clear`**, and a brand-new class
  requires an opcache reset (restart the container) on an already-running
  instance. If your new endpoint 404s, this is why.
- **Keep secrets in `secured_configs`.** Anything that would let someone else
  send as you belongs there; plain identifiers (key ids, bundle ids, Firebase
  client config) do not.
- **No Composer dependencies.** Everything so far is built on PHP's `openssl`,
  `curl` and `hash` extensions plus what GLPI core already ships. Keep it that
  way unless there is no alternative.

## Things that will waste your afternoon

All of these have already happened here, and are documented in the README:

- `getMenuContent()`'s `canView()`/`canCreate()` must declare `: bool` — a
  mismatch 500s *every* page in GLPI.
- A plugin front page must not set `action="$_SERVER['PHP_SELF']"`, and must not
  call `Session::checkCSRF()`.
- Uploads must `copy()` the `$_FILES` temp file, never `move_uploaded_file()`.
- Answer values fed to `AnswersHandler` must carry the correct PHP type.

## Pull requests

- One logical change per PR, with the GLPI version you tested against and how
  you verified it — ideally the `curl` you ran and the row you read back.
- Include the app-side change (or an issue linking to it) if the endpoint is new.
- **If you used an AI assistant, say so.** Most of this repository was written
  that way — see [AI-DISCLOSURE.md](AI-DISCLOSURE.md) — and disclosing it simply
  tells reviewers where to look hardest.

Security issues go to [SECURITY.md](SECURITY.md), not the public tracker.
