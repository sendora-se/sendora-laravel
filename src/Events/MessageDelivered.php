<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\DeliveredEvent;

/**
 * The receiver accepted a message for its recipient. It comes from a
 * `delivered` webhook. Its listeners run before the route answers, and
 * Sendora waits 10 seconds for that, so queue slow work. Key your handling
 * on `webhook->id`, since a delivery can arrive again.
 */
final readonly class MessageDelivered implements WebhookReceived
{
    public function __construct(public DeliveredEvent $webhook) {}
}
