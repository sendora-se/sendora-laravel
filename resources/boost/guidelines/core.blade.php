# Sendora

- This app sends its mail through Sendora with `sendora/sendora-laravel`. With `MAIL_MAILER=sendora`, every mailable and mail notification goes through Sendora's API, and `config/mail.php` needs no entry for it.
- Send mail with Laravel's mailables and notifications. For the API's other calls, use the package's clients, never `Http` or curl.
- `SENDORA_API_TOKEN` holds a server key, `sk_…`, which sends. `SENDORA_ACCOUNT_TOKEN` holds the account key, `ak_…`, which manages servers, keys and domains and never sends. Both are secrets. Keep them in the environment, and never write one into code, a config file or a page.
- In development, tests and CI, use the key of a test server, which starts `sk_test_`. A test server takes and logs mail and fires webhooks as a live one does, but delivers nothing.
- The sender, `MAIL_FROM_ADDRESS` or a mailable's own, must be on a domain that the account has verified.
- The `tags` and `metadata` of a mailable's `Envelope` become the message's tag and metadata in Sendora. A message has one tag, so two different tags fail the send.
- The facade `Sendora\Laravel\Facades\Sendora` reaches the server's resources, as in `Sendora::messages()->get($messageId)`. A class may take a resource from the container instead, such as `Sendora\Resources\Messages`.
- When the queue retries a job, the package does not send again a mail that went out on an earlier attempt, so add no guard of your own. A mail that comes out different on the retry, as an embedded image or a signed URL makes it, goes again. To send such a mail once, set the header `X-Sendora-Idempotency-Key` in the mailable's `headers()` from an id of the app's own, such as the order's.
- Webhooks reach the package's route, `POST /sendora/webhook`, once `SENDORA_WEBHOOK_SECRET` holds the webhook's secret. Never write a controller of your own for them. Listen to the events in `Sendora\Laravel\Events`, such as `MessageBounced`, whose `webhook` property is the SDK's event.
- Sendora waits 10 seconds for the route's answer, so queue slow work. A delivery can arrive more than once, so key its handling on `$event->webhook->id`.
- In tests, `Mail::fake()` and `Event::fake()` work as usual. To fake the API, mock a resource class with `$this->mock()`, such as `Sendora\Resources\Email`. `Sendora::shouldReceive()` cannot work, since the client is final.
- A failed mail throws Symfony's `TransportException`, whose previous exception is the SDK's `Sendora\Exception\SendoraException`. A failed call to a resource throws the `SendoraException` itself. Never swallow either.
- Activate the `sendora` skill for anything beyond these rules. `vendor/sendora/sendora-laravel/README.md` has the settings, the mailer, the clients and every event. `vendor/sendora/sendora-php/README.md` has every call and error code.
