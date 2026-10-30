<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\InboundEvent;

/**
 * A message was received on an inbound stream. It comes from an `inbound`
 * webhook. `webhook->content` holds the mail when the webhook was created
 * with `inboundContent: 'full'`; otherwise fetch it with
 * `Sendora::inbound()->get($event->webhook->inboundMessageId)`.
 *
 * A queued listener would put the mail in the queue's store, in clear. So
 * queue only the message's id and fetch the mail in the job, or make the
 * listener `ShouldBeEncrypted`. Its listeners run before the route answers,
 * and Sendora waits 10 seconds for that, so queue slow work. Key your
 * handling on `webhook->id`, since a delivery can arrive again.
 */
final readonly class InboundEmailReceived implements WebhookReceived
{
    public function __construct(public InboundEvent $webhook) {}
}
