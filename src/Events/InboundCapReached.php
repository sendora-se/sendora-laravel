<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\InboundCapReachedEvent;

/**
 * The account's monthly cap on received mail is used up. It comes from an
 * `inbound_cap_reached` webhook. Its listeners run before the route
 * answers, and Sendora waits 10 seconds for that, so queue slow work. Key
 * your handling on `webhook->id`, since a delivery can arrive again.
 */
final readonly class InboundCapReached implements WebhookReceived
{
    public function __construct(public InboundCapReachedEvent $webhook) {}
}
