<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\CapReachedEvent;

/**
 * A send was refused because a monthly cap on sending is used up. It comes
 * from a `cap_reached` webhook. Its listeners run before the route answers,
 * and Sendora waits 10 seconds for that, so queue slow work. Key your
 * handling on `webhook->id`, since a delivery can arrive again.
 */
final readonly class CapReached implements WebhookReceived
{
    public function __construct(public CapReachedEvent $webhook) {}
}
