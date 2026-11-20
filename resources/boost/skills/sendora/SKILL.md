---
name: sendora
description: Send a Laravel app's mail through Sendora with the sendora/sendora-laravel package, call the API through the Sendora facade or the container, receive signed webhooks as Laravel events, and test the code that does. Use when a task mentions Sendora, the sendora/sendora-laravel package, MAIL_MAILER=sendora or a Sendora webhook, or sending email from a Laravel app hosted in Sweden.
---

# Sendora for Laravel

`sendora/sendora-laravel` sends a Laravel app's mail through the Sendora
API with `MAIL_MAILER=sendora`. It puts the API's clients in the
container, with a facade. It fires a Laravel event for each webhook, from
a route that checks its signature. It runs on Laravel 12 with PHP 8.2 or
newer, and on Laravel 13 with PHP 8.3 or newer. It installs
`sendora/sendora-php`, the PHP client. The READMEs of the two packages
are the complete reference.

## Rules

- Send mail with Laravel's mailables and notifications. With
  `MAIL_MAILER=sendora` they go through the API, and `config/mail.php`
  needs no entry for it. For the API's other calls, use the package's
  clients. Never call the API with `Http` or curl, and never build a
  request body by hand.
- Two kinds of key, two clients. A server key (`sk_…`) in
  `SENDORA_API_TOKEN` drives the mailer and `Sendora\Sendora`: `email`,
  `broadcasts`, `messages`, `inbound`, `inboundDomains`, `server`,
  `streams`, `suppressions`, `tokens`, `trackingOptOuts` and `webhooks`,
  everything inside one server. The account key (`ak_…`) in
  `SENDORA_ACCOUNT_TOKEN` drives `Sendora\SendoraAccount`: `servers`,
  `servers->tokens`, `domains`, `trackingDomains` and `erasures`. It
  never sends. A key of the wrong kind throws `wrong_token_kind`.
- Every key is a secret. Keep keys in `.env` or the deploy's environment,
  which `config/sendora.php` reads. Never hard-code a key, log it or send
  it to a browser. A client is built on first use, so an app that never
  calls Sendora needs no key. A client without its key throws
  `Sendora\Exception\InvalidArgumentException`, naming the variable.
- The facade `Sendora\Laravel\Facades\Sendora` has a method for each
  resource of the server client, as in `Sendora::email()->send(...)`. A
  class may take the resource from the container instead, such as
  `Sendora\Resources\Messages`. The account client has no facade. Take
  `Sendora\SendoraAccount` from the container, or one of its resources:
  `Domains`, `TrackingDomains`, `Erasures`, `Servers` or `ServerTokens`.
- For development, tests and CI, use the key of a test server, which
  starts `sk_test_`. A test server accepts, logs and fires webhooks like
  a live one, but delivers nothing. `Sendora\Keys::isTestKey($token)`
  tells its keys apart, so an app can refuse a test key in production.
  Addresses at `simulator.sendora.se` (`hardbounce@`, `softbounce@`,
  `deferred@`, `complaint@`) produce those outcomes on a test server. A
  live one refuses them with `test_address_on_live_server`. Where the
  message's tracking is on, `opened@` adds an open and `clicked@` an open
  and a click.
- The `tags` and `metadata` of a mailable's `Envelope`, or the `tag()`
  and `metadata()` of a `MailMessage`, become the message's tag and
  metadata. A message has one tag. The same tag twice counts once, and
  two different tags fail the send before anything goes out. In a
  mailable's `headers()`, `X-Sendora-Tag` wins over its tags,
  `X-Sendora-Metadata-<key>` adds metadata and `X-Sendora-Stream` picks
  the stream.
- The sender must be on a sending domain that the account has verified.
  Laravel sends from `MAIL_FROM_ADDRESS` unless a mailable sets its own.
  `Sendora::server()->get()->sendingDomains` lists the domains that a
  sender may use now. With the account key, `domains->create(domain: ...)`
  returns the two DNS records to add, and `domains->verify($domainId)`
  checks them.
- `Mail::to(...)->send()` returns Laravel's `SentMessage`, whose
  `getMessageId()` is Sendora's message id. It returns null when the mail
  is queued, and Laravel's `MessageSent` event then carries the id.
  Delivery is asynchronous. Whether the receiver took the message is in
  `Sendora::messages()->get($messageId)->recipients`, and in the events
  `MessageDelivered`, `MessageBounced`, `MessageDeferred` and
  `SpamComplaintReceived`.
- A message goes on the server's default transactional stream unless
  `SENDORA_STREAM` or `X-Sendora-Stream` names another. A stream is a
  kind of mail, so never make one per message or per customer.
- An app that sends from several servers gives each its own mailer in
  `config/mail.php`, with the transport `sendora` and its own `token`.
  That mailer's mail goes on its server's default stream, unless the
  mailer sets `stream`.
- The SDK gives every send an idempotency key, and repeats a call only
  when repeating is safe. So add no retry loop of your own. Laravel
  retries a failed queued mail as a new send, though, and the retry sends
  it again if the first attempt went out. Set `X-Sendora-Idempotency-Key`
  in the mailable's `headers()` from an id of the app's own, such as the
  order's. The API remembers a key for 24 hours.
- The idempotency key protects a retry only when the mailable renders
  the same bytes each time. An embedded image gets a new content id on
  each render, and a signed URL a new expiry. Then the API refuses the
  reused key with `idempotency_key_mismatch`, and that retry fails
  without sending. Inside a `failover` mailer, though, the next mailer
  sends it again.
- A failed mail throws Symfony's `TransportException`, whose previous
  exception is the SDK's `Sendora\Exception\SendoraException`. A failed
  call through a client throws the `SendoraException` itself. Compare its
  `errorCode` with an `ErrorCode` case's `->value`, such as
  `ErrorCode::RecipientSuppressed->value`, and rethrow anything else.
  Never swallow it.
- Addresses on a stream's suppression list are refused with
  `recipient_suppressed`. Lift a hard bounce with
  `Sendora::suppressions()->delete(address: ...)`, with `streamId:` for a
  stream other than the default. A spam complaint or an unsubscribe
  cannot be lifted.
- Mail to many goes as one broadcast on a broadcast stream, never as a
  loop of mailables. `Sendora::broadcasts()->send()` takes the content
  once and one entry per recipient in `messages:`, at most 50,000. Its
  text and its HTML must carry `{{ unsubscribe_url }}`, where Sendora puts
  each recipient's own link.
- A person who asks to be forgotten is erased with the account key, by
  `erasures->create(address: ...)`. It deletes their messages' content on
  every server, and blocks nothing.
- Webhooks arrive at the package's route, `POST /sendora/webhook`, named
  `sendora.webhook`. The route exists only once `SENDORA_WEBHOOK_SECRET`
  holds the webhook's secret, or several separated by commas. It is
  outside the `web` middleware group, so it needs no CSRF token. Never
  write a controller of your own to check the signature. If the app
  caches its config or routes, run `config:cache` and `route:cache` again
  where the secret is set.
- `Sendora::webhooks()->create(url: url('sendora/webhook'))` creates a
  webhook, and its `secret` is shown that once. It receives every event
  but `opened` and `clicked`, unless `events:` lists the ones it wants.
- Listen to the Laravel event of each webhook, from
  `Sendora\Laravel\Events`, as to any Laravel event. `$event->webhook` is
  the SDK's event, from `Sendora\Events`. A listener of `WebhookReceived`
  hears every event. `UnknownWebhookReceived` carries an event newer than
  the package, with its name in `$event->webhook->event`.
- The route answers 204 once the listeners that are not queued have run.
  Sendora waits 10 seconds for it, so queue slow work. A listener that
  throws makes the answer 500, and Sendora delivers again for about a
  day. A queued listener that fails is retried by Laravel's queue, not by
  Sendora. Deliveries arrive at least once, so key the handling on
  `$event->webhook->id`.
- `InboundEmailReceived` carries a received mail. A queued listener of it
  would put the mail in the queue's store, in clear. So queue only
  `$event->webhook->inboundMessageId`, and fetch the mail in the job with
  the `get()` of `Sendora\Resources\Inbound`.
- In tests, `Mail::fake()` and `Event::fake()` work as usual, and a faked
  mailer never reaches Sendora. To test code that calls the API, replace
  a resource in the container with `$this->mock()`, as below.
  `Sendora::shouldReceive()` cannot work, since the clients are final. To
  test the route, set `SENDORA_WEBHOOK_SECRET` in `phpunit.xml`, and post
  a delivery signed as the package's README shows.

## The shape of a mail

```php
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\Mail;

final class ReceiptMail extends Mailable
{
    public function __construct(public readonly string $orderId) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ditt kvitto',
            tags: ['receipt'],
            metadata: ['order_id' => $this->orderId],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Sendora-Idempotency-Key' => "receipt-{$this->orderId}"]);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Tack för din beställning.</p>');
    }
}

Mail::to('anna@example.com')->queue(new ReceiptMail('2026-0912'));
```

## The shape of a test

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

A response's constructor is outside the semver promise, so build one
with named arguments.

## Where to read more

- `vendor/sendora/sendora-laravel/README.md`: the settings, the mailer,
  several servers, queued mail, the clients, the route and every Laravel
  event, and testing.
- `vendor/sendora/sendora-php/README.md`: every method, response, error
  code and event, retries, logging and tracing.
- The guides and the HTTP reference at https://sendora.se/docs.
