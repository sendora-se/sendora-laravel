<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\DeferredEvent;

/**
 * The receiver asked Sendora to try a message later; several may follow. It
 * comes from a `deferred` webhook. Its listeners run before the route
 * answers, and Sendora waits 10 seconds for that, so queue slow work. Key
 * your handling on `webhook->id`, since a delivery can arrive again.
 */
final readonly class MessageDeferred implements WebhookReceived
{
    public function __construct(public DeferredEvent $webhook) {}
}
