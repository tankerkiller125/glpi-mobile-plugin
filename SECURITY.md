# Security policy

## Reporting a vulnerability

Please **do not** open a public issue.

Use GitHub's private vulnerability reporting on this repository
(*Security → Report a vulnerability*), or contact the maintainer privately
through their GitHub profile. Expect an acknowledgement within a week — this is
a spare-time project, not a vendor with an on-call rota.

Include the GLPI version, the plugin version, and enough detail to reproduce.

## Supported versions

Only the latest release is supported. There are no backports.

## What this plugin holds and exposes

If you are reviewing it, start here:

| File | Why it matters |
| --- | --- |
| `src/OAuthBroker.php` | Performs a server-side `authorization_code` exchange using the user's session cookie, and holds the client secret |
| `src/PairController.php` | The unauthenticated pairing/refresh/device endpoints, and file upload |
| `src/DeviceSession.php` | Device credentials, the stored refresh token, revocation |
| `src/WebPush.php` | RFC 8291 payload encryption and RFC 8292 VAPID signing, implemented from scratch |
| `src/Fcm.php`, `src/Apns.php` | JWT signing and delivery to Google/Apple |
| `src/FormController.php` | Feeds user input into GLPI's own `AnswersHandler` |

### Deliberately unauthenticated endpoints

`POST /pair`, `POST /refresh` and `DELETE /devices` carry no session, because
the value presented *is* the credential:

- a **pairing code** is 24 random bytes, single-use, and expires in 120 seconds;
- a **device secret** is issued at pairing and stored hashed;
- a **push endpoint** is an unguessable URL minted by the push server.

`GET /config` is public and returns only the VAPID public key, which transports
are available, and the Firebase *client* config — values that ship in every APK
anyway. No secret is returned by any endpoint.

### Secrets at rest

The OAuth client secret, VAPID private key, FCM service account and APNs `.p8`
are stored GLPIKey-encrypted via `secured_configs`. Pairing rows hold their
tokens encrypted until claimed. Device secrets are hashed.

### Session handling

The broker's loopback self-call must present the original `Host` header while
connecting to the internal port, because GLPI derives its session cookie name
from `root_dir + HTTP_HOST + SERVER_PORT`. It calls `session_write_close()`
first to release the session lock. If you change that code, understand this
first — it is the part most likely to break in a subtle, security-relevant way.

## Known gaps

- **No independent security review has been performed.** The Web Push
  implementation matches the RFC's test vectors, which validates the algorithm
  and nothing else.
- **APNs delivery is untested** against real Apple infrastructure.
- The plugin was written largely by an AI assistant under human direction — see
  [AI-DISCLOSURE.md](AI-DISCLOSURE.md). A review of the auth broker and the
  crypto by someone with no stake in it would be genuinely welcome.
