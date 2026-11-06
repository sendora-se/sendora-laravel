# sendora/sendora-laravel

[Sendora](https://sendora.se), transactional email delivery, for Laravel.
It puts the API's clients in Laravel's container, with a facade. It sends
Laravel's mail through the API with `MAIL_MAILER=sendora`. And it fires a
Laravel event for each webhook, from a route that checks its signature.
Laravel 12 on PHP 8.2 or newer, and Laravel 13 on PHP 8.3 or newer.

```sh
composer require sendora/sendora-laravel
```

Laravel finds the package by itself. Then set the server key, and send
mail through it:

```dotenv
SENDORA_API_TOKEN=sk_…
MAIL_MAILER=sendora
MAIL_FROM_ADDRESS=no-reply@example.se
```

An account has servers, and each server has its own mail, log and keys.
A server key (`sk_…`) sends and reads everything of its server, and you
get one when you create the server in the dashboard. An account key
(`ak_…`) manages the account's servers, keys and domains. Both are
secrets, so keep them on the server.

The package builds on
[`sendora/sendora-php`](https://packagist.org/packages/sendora/sendora-php),
the PHP client, which it installs. That package's README has every call,
response, error code and webhook event. This one covers what Laravel
adds.

## Settings

The settings live in `config/sendora.php`, and the environment sets the
ones a deploy changes. Publish the file to change the rest:

```sh
php artisan vendor:publish --tag=sendora-config
```

| Key                  | From the environment     | Default                     |
| -------------------- | ------------------------ | --------------------------- |
| `token`              | `SENDORA_API_TOKEN`      | none                        |
| `account_token`      | `SENDORA_ACCOUNT_TOKEN`  | none                        |
| `base_url`           | `SENDORA_BASE_URL`       | `https://api.sendora.se`    |
| `timeout`            |                          | 10 seconds an attempt       |
| `max_retries`        |                          | 2; 0 turns retries off      |
| `http_client`        |                          | none, so curl               |
| `stream`             | `SENDORA_STREAM`         | the server's default stream |
| `webhook.enabled`    |                          | true                        |
| `webhook.path`       |                          | `sendora/webhook`           |
| `webhook.domain`     |                          | none, so any domain         |
| `webhook.middleware` |                          | none                        |
| `webhook.secrets`    | `SENDORA_WEBHOOK_SECRET` | none, so no route           |
| `webhook.tolerance`  |                          | 300 seconds                 |

- Three attempts of 10 seconds and the waits between them stay within
  the 60 seconds that `queue:work` gives a job by default.
- `http_client` names a PSR-18 client in the container, for a proxy or
  for tests. Otherwise the clients send through curl, so Laravel's
  `Http::fake()` never sees their requests.
- A stream is a lane of a server's mail, transactional or broadcast, with
  a suppression list of its own.
- `webhook.secrets` takes several secrets, separated by commas, since
  each webhook has its own.

A client is built on first use, so an app that never calls Sendora needs
no key. A client without its key throws `Sendora\Exception\InvalidArgumentException`,
naming the variable. A setting of the wrong type throws it too, naming
the setting.

## Sending mail

With `MAIL_MAILER=sendora`, Laravel's mail goes through the API, and
`config/mail.php` needs no entry for it. A mailable's attachments, Cc,
Bcc and Reply-To go through as Laravel sets them. Its tags and metadata
become the message's `tag` and `metadata` in Sendora.

```php
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Mail;

final class InvoiceMail extends Mailable
{
    public function __construct(public readonly string $invoiceId) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Din faktura för september',
            tags: ['invoice'],
            metadata: ['invoice_id' => $this->invoiceId],
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Hej Anna, fakturan finns bifogad.</p>');
    }
}

$sent = Mail::to('anna@example.com')->send(new InvoiceMail('2026-0912'));
if ($sent !== null) {
    $messageId = $sent->getMessageId(); // Sendora's message id, as the log and the webhooks name it
}
```

- `send()` returns null when the mail is queued, or when a listener of
  Laravel's `MessageSending` stops it. For queued mail, Laravel's
  `MessageSent` event carries the sent message, and with it Sendora's id.
- A message has one tag. The same tag twice counts once, and an
  `X-Sendora-Tag` header wins over the mailable's tags. Two different
  tags fail the send before anything goes out.
- Laravel sends from `MAIL_FROM_ADDRESS` unless a mailable sets its own
  sender. Either way, the address must be on a domain that the account
  has verified.
- Notifications sent by mail go the same way, and the `tag()` and
  `metadata()` of a `MailMessage` set Sendora's.
- The headers `X-Sendora-Metadata-<key>` and `X-Sendora-Stream` set
  metadata and the stream from a mailable's `headers()`.

A failure is Symfony's `TransportException`, with the SDK's exception as
its previous one. Laravel's queue retries a queued mail that fails, as
its settings say. Laravel's `failover` mailer moves to its next mailer
when Sendora fails, as it does for any transport. The SDK's README maps
each part of a message to the API's request.

### Several servers

A mailer of `config/mail.php` with the transport `sendora` may set its
own `token`, `base_url` and `stream`, for an app that sends from several
servers:

```php
// config/mail.php, beside the mailers that are there
return [
    'mailers' => [
        'billing' => [
            'transport' => 'sendora',
            'token' => env('SENDORA_BILLING_TOKEN'),
            'stream' => env('SENDORA_BILLING_STREAM'),
        ],
    ],
];
```

Then `Mail::mailer('billing')->to(...)->send(...)` sends with that
server's key.

- A setting that the mailer leaves out, or that is null as an unset
  variable leaves it, comes from `config/sendora.php`. So does every
  other setting, such as the timeout.
- The stream is the exception. A mailer with its own `token` sends on
  its server's default stream, unless it sets `stream`.
- For calls on that server, build a client with its key:
  `new \Sendora\Sendora(config('mail.mailers.billing.token'))`.
- The secrets of that server's webhooks go in `SENDORA_WEBHOOK_SECRET`
  too, beside the first server's.

### Queued mail

The SDK sends each request under an idempotency key, so a retried request
never sends twice. But Laravel retries a failed queued mail as a new
send, under a new key. If the first attempt went out before it failed,
the retry sends the mail again. Set `X-Sendora-Idempotency-Key` from an
id of your own to stop that:

```php
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;

final class ReceiptMail extends Mailable
{
    public function __construct(public readonly string $orderId) {}

    public function headers(): Headers
    {
        return new Headers(text: ['X-Sendora-Idempotency-Key' => "receipt-{$this->orderId}"]);
    }
}
```

- The API remembers a key for 24 hours.
- The key protects a retry only when the mailable renders the same bytes
  each time. An embedded image gets a new content id on each render, and
  a signed URL a new expiry.
- Otherwise the API refuses the reused key with
  `idempotency_key_mismatch`. The failure's message then says that the
  mail went out on an earlier attempt, so that retry has nothing left to
  send. Inside a `failover` mailer, though, the next mailer sends it
  again.

## The clients

The `Sendora` facade reaches each resource of the server client:
`broadcasts()`, `email()`, `inbound()`, `inboundDomains()`, `messages()`,
`server()`, `streams()`, `suppressions()`, `tokens()`, `trackingOptOuts()`
and `webhooks()`.

```php
use Sendora\Laravel\Facades\Sendora;

$accepted = Sendora::email()->send(
    from: 'no-reply@example.se',
    to: ['anna@example.com'],
    subject: 'Din faktura för september',
    text: 'Hej Anna, fakturan finns bifogad.',
    tag: 'invoice',
);
$message = Sendora::messages()->get($accepted->messageId);
```

The container holds one `Sendora\Sendora`, from `SENDORA_API_TOKEN`, and
one `Sendora\SendoraAccount`, from `SENDORA_ACCOUNT_TOKEN`, each with
Laravel's logger. It binds each resource class too, such as `Email` and
`Messages` of the server client, and `Servers` and `Domains` of the
account client. So a class may take what it uses:

```php
use Sendora\Resources\Messages;

final class DeliveryStatus
{
    public function __construct(private readonly Messages $messages) {}

    public function of(string $messageId): string
    {
        return $this->messages->get($messageId)->status;
    }
}
```

The account client has no facade. Take `SendoraAccount` from the
container, or one of its resources: `Domains`, `TrackingDomains`, `Erasures`
for erasing an address's data, `Servers` and `ServerTokens`.

## Webhooks

A webhook is a URL of yours that Sendora posts events to, as they happen.
Each post is a delivery, signed with the webhook's secret. The package's
route takes the deliveries, checks each signature, and fires a Laravel
event for each.

1. Create the webhook in the dashboard, or with the client. The route
   does not exist until a secret is set, so build its URL from its path:

   ```php
   use Sendora\Laravel\Facades\Sendora;

   $webhook = Sendora::webhooks()->create(url: url('sendora/webhook'));
   $secret = $webhook->secret; // shown this once
   ```

   It receives every event except `opened` and `clicked`, unless `events:`
   lists the ones it wants.

2. Put the secret in `SENDORA_WEBHOOK_SECRET`, and deploy.
3. If the app caches its config or its routes, run `config:cache` and
   `route:cache` again where the secret is set.

The route is `POST /sendora/webhook`, named `sendora.webhook`. Until a
secret is set, the route does not exist. A delivery then gets a 404, and
Sendora tries it again later. The route is outside the `web` middleware
group, so Laravel's CSRF check never applies to it.

Listen to the event of each webhook you want, as to any Laravel event:

```php
use Illuminate\Support\Facades\Log;
use Sendora\Laravel\Events\MessageBounced;

final class MarkTheAddressUndeliverable
{
    public function handle(MessageBounced $event): void
    {
        if ($event->webhook->details->hard) {
            Log::warning("{$event->webhook->messageId} bounced for good: {$event->webhook->details->classification}");
        }
    }
}
```

Laravel finds a listener in `app/Listeners` by the type of its `handle()`
argument. `Event::listen()` registers one in a provider instead.

| Webhook event         | Laravel event            | `$event->webhook`        |
| --------------------- | ------------------------ | ------------------------ |
| `delivered`           | `MessageDelivered`       | `DeliveredEvent`         |
| `bounced`             | `MessageBounced`         | `BouncedEvent`           |
| `deferred`            | `MessageDeferred`        | `DeferredEvent`          |
| `spam_complaint`      | `SpamComplaintReceived`  | `SpamComplaintEvent`     |
| `unsubscribed`        | `RecipientUnsubscribed`  | `UnsubscribedEvent`      |
| `opened`              | `MessageOpened`          | `OpenedEvent`            |
| `clicked`             | `LinkClicked`            | `ClickedEvent`           |
| `tracking_opted_out`  | `RecipientOptedOut`      | `TrackingOptedOutEvent`  |
| `cap_warning`         | `CapWarning`             | `CapWarningEvent`        |
| `cap_reached`         | `CapReached`             | `CapReachedEvent`        |
| `inbound`             | `InboundEmailReceived`   | `InboundEvent`           |
| `inbound_cap_warning` | `InboundCapWarning`      | `InboundCapWarningEvent` |
| `inbound_cap_reached` | `InboundCapReached`      | `InboundCapReachedEvent` |
| any other             | `UnknownWebhookReceived` | `WebhookEvent`           |

The Laravel events are in `Sendora\Laravel\Events`. `$event->webhook` is
the SDK's event, from `Sendora\Events`, whose fields the SDK's README
lists. A cap is a monthly limit, on sending or on received mail. Every
Laravel event implements `WebhookReceived`, so a listener of that
interface hears them all.

A webhook that the package has no event for fires
`UnknownWebhookReceived`. Its `webhook->event` is the event's name, and
`webhook->toArray()` its payload as it came. Upgrade the package to read
it as its class.

### Responding in time

- The route responds with 204 once the listeners that are not queued
  have run. Sendora waits 10 seconds for the response, and counts a later
  one as a failure. So queue slow work.
- A listener that throws makes the response 500. After a failure,
  Sendora delivers the webhook again, with growing delays, for about a
  day.
- A queued listener that fails is retried by Laravel's queue, not by
  Sendora, which has had its 204.
- A delivery can arrive more than once, so key your handling on
  `$event->webhook->id`. The id is the same on each attempt, and
  `webhook->attempt` counts them.
- A bad or stale signature gets a 400 and fires nothing. The log gets
  the failure's code at debug level, and nothing of the request.

### Received mail

`InboundEmailReceived` carries the received mail under
`webhook->content` when the webhook was created with
`inboundContent: 'full'`, the default. A webhook created with
`inboundContent: 'reference'` carries no content. Then fetch the mail
with `Sendora::inbound()->get($event->webhook->inboundMessageId)`, for 30
days by default.

A queued listener of `InboundEmailReceived` would put the mail in the
queue's store, in clear, and could pass a queue's size limit, such as
SQS's. So queue the message's id alone and fetch the mail in the job:

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Sendora\Laravel\Events\InboundEmailReceived;
use Sendora\Resources\Inbound;

final class FileTheTicket implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly string $inboundMessageId) {}

    public function handle(Inbound $inbound): void
    {
        $mail = $inbound->get($this->inboundMessageId);
        Storage::put("tickets/{$this->inboundMessageId}.txt", $mail->text ?? '');
    }
}

Event::listen(static function (InboundEmailReceived $event): void {
    FileTheTicket::dispatch($event->webhook->inboundMessageId);
});
```

A listener that is `ShouldBeEncrypted` keeps the mail unreadable in the
queue, but not small. A delivery with the mail's content can take a few
megabytes. That is more than nginx's default `client_max_body_size` of
1 MB, so raise it for the route, or create the webhook with
`inboundContent: 'reference'`.

### The route's settings

- `webhook.path` and `webhook.domain` move the route, and
  `webhook.middleware` adds middleware to it, such as a rate limit.
- `webhook.enabled` set to false removes the route.
- `webhook.tolerance` sets how old a signature may be, in seconds,
  either way. Keep the server's clock in sync.

Cached routes keep the path, the domain and the middleware that they were
cached with. So cache the routes where the secret is set, as at a deploy,
and not in an image built without it. The route reads the secrets and the
tolerance with each delivery. It responds with 404 once it is turned off
or its last secret is gone.

### Rolling a secret

A webhook holds up to two live secrets, and signs each delivery with
both. So a secret rolls without a gap:

1. Create the second secret with
   `Sendora::webhooks()->createSecret($webhookId)`. Its `secret` is the
   new value, shown once.
2. Add it to `SENDORA_WEBHOOK_SECRET`, after a comma, and deploy.
3. Delete the old secret with
   `Sendora::webhooks()->deleteSecret($webhookId, $secretId)`.
4. Remove the old secret from the variable, and deploy.

With the config cached, run `config:cache` after each change to the
variable.

## Testing your code

`Mail::fake()` and `Event::fake()` work as usual, and a faked mailer
never reaches Sendora.

To test code that calls the API, replace a resource in the container.
`Sendora::email()` and a class that takes `Email` then both get the
double. The two client classes are final, so `Sendora::shouldReceive()`
cannot double them. The resource classes are not, so double those.

```php
use Illuminate\Foundation\Testing\TestCase;
use Mockery\MockInterface;
use Sendora\Laravel\Facades\Sendora;
use Sendora\Resources\Email;
use Sendora\Types\AcceptedEmail;

final class WelcomeMailTest extends TestCase
{
    public function testTheWelcomeIsSent(): void
    {
        $this->mock(Email::class, static function (MockInterface $email): void {
            $email->expects('send')->andReturn(new AcceptedEmail(
                messageId: '0b7c6a55-6f0e-4bd4-9a3c-0f6f2f8d4a11',
                status: 'accepted',
                submittedAt: new \DateTimeImmutable('2026-09-26T10:00:00Z'),
                test: true,
            ));
        });

        $accepted = Sendora::email()->send(from: 'no-reply@example.se', to: ['anna@example.com'], subject: 'Välkommen', text: 'Hej!');

        self::assertSame('accepted', $accepted->status);
    }
}
```

A response's constructor is outside the semver promise, since a new
property adds a parameter. So build one with named arguments, and expect
an upgrade to ask for more.

To fake the API itself, give a client a fake PSR-18 client, as the SDK's
README shows. Then swap it in:
`Sendora::swap(new \Sendora\Sendora('sk_test_…', httpClient: $fake))`.

To test that a delivery reaches your code through the route, set a
secret in `phpunit.xml`, so the route exists when the app boots:

```xml
<env name="SENDORA_WEBHOOK_SECRET" value="whsec_test"/>
```

Then post a delivery signed as Sendora signs it. The route checks the
signature's time against Laravel's clock, so `travelTo()` moves it too.

```php
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Event;
use Sendora\Laravel\Events\CapWarning;

final class CapWarningTest extends TestCase
{
    public function testTheRouteFiresTheWarning(): void
    {
        Event::fake([CapWarning::class]);
        $body = (string) json_encode([
            'event' => 'cap_warning',
            'id' => '4d6f8a0b-2c4e-4f6a-8b0c-2d4e6f8a0b1c',
            'attempt' => 1,
            'scope' => 'account',
            'serverId' => null,
            'cap' => 50000,
            'used' => 40000,
            'periodStart' => '2026-09-01T00:00:00.000Z',
            'periodEnd' => '2026-10-01T00:00:00.000Z',
            'occurredAt' => '2026-09-24T09:30:00.000Z',
        ]);
        $at = now()->getTimestamp();
        $signature = "t={$at},v1=" . hash_hmac('sha256', "{$at}.{$body}", 'whsec_test');

        $this->call('POST', route('sendora.webhook'), server: ['HTTP_SENDORA_SIGNATURE' => $signature], content: $body)
            ->assertNoContent();

        Event::assertDispatched(CapWarning::class, static fn(CapWarning $event): bool => $event->webhook->used === 40000);
    }
}
```

`Event::fake()` keeps the listeners from running, so this checks that
the route fires the event. `Event::assertListening()` then checks that
your listener hears it. Leave the events unfaked to run the listener
itself.

For tests that talk to Sendora itself, use the key of a test server,
which starts `sk_test_`. A test server goes through everything a live
one does, but nothing it accepts is delivered.

## Logging

The clients log each retry at debug level on Laravel's logger, with the
method, the path, the code and the wait. The route logs a refused
delivery's code at debug level. Neither logs a key, an address or a
message's content.

## Versions

The package follows semantic versioning. A breaking change comes only in
a major version: a renamed or removed class, method, setting or event, or
a changed default. A minor adds them, and a patch fixes a bug. Each release
requires the major of `sendora/sendora-php` that it was tested with, from
1.0.0, and Composer installs it beside the package. `CHANGELOG.md` lists
what each release changed.

## Docs

Guides and the HTTP reference: [sendora.se/docs](https://sendora.se/docs).
