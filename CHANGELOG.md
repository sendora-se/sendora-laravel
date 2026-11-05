# Changelog

## 1.1.0

- The container holds `TrackingDomains`, the resource of the account client
  that adds, lists, reads, checks and removes tracking domains. It requires
  `sendora/sendora-php` 1.1.0 or a later 1.x release.

## 1.0.0

- The first stable release. From 1.0.0 on, only a new major version
  breaks code written for the one before it: a renamed or removed
  class, method, setting or event, or a changed default. A minor adds
  them, and a patch fixes a bug. No code changes from 0.2.0.
- It requires `sendora/sendora-php` 1.0.0 or a later 1.x release, so
  each minor of the SDK reaches the app without a new release here.

## 0.2.0

- Requires `sendora/sendora-php` 0.11, which removes the error code
  `unpaid_limit_reached` and its amounts. Nothing in this package used
  them.

## 0.1.0

- `SendoraServiceProvider`, which Laravel finds by itself, puts the
  server client, the account client and each of their resources in the
  container, built on first use from `config/sendora.php` with Laravel's
  logger. `php artisan vendor:publish --tag=sendora-config` publishes
  the settings.
- The `Sendora` facade reaches each resource of the server client, as in
  `Sendora::email()->send(...)`.
- `MAIL_MAILER=sendora` sends Laravel's mail through the API, with the
  SDK's driver, without an entry in `config/mail.php`. A mailable's tags
  and metadata become Sendora's. A mailer of its own may set `token`,
  `base_url` and `stream`. `SENDORA_STREAM` sets the stream of mail sent
  with the package's own key.
- Once `SENDORA_WEBHOOK_SECRET` is set, the route `POST /sendora/webhook`
  checks each delivery's signature and fires a Laravel event for its
  webhook, such as `MessageBounced` or `InboundEmailReceived`. A listener
  of `WebhookReceived` hears them all. A bad or stale signature gets a
  400, and a listener that throws a 500, which Sendora retries. The
  route's path, domain and middleware are settings. Several secrets may be
  set, separated by commas.
