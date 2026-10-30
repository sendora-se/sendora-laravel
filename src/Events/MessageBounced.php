<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\BouncedEvent;

/**
 * The receiver refused a message for good, or Sendora gave up. It comes
 * from a `bounced` webhook. Its listeners run before the route answers, and
 * Sendora waits 10 seconds for that, so queue slow work. Key your handling
 * on `webhook->id`, since a delivery can arrive again.
 */
final readonly class MessageBounced implements WebhookReceived
{
    public function __construct(public BouncedEvent $webhook) {}
}
