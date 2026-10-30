<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\UnsubscribedEvent;

/**
 * A recipient unsubscribed. It comes from an `unsubscribed` webhook. Its
 * listeners run before the route answers, and Sendora waits 10 seconds for
 * that, so queue slow work. Key your handling on `webhook->id`, since a
 * delivery can arrive again.
 */
final readonly class RecipientUnsubscribed implements WebhookReceived
{
    public function __construct(public UnsubscribedEvent $webhook) {}
}
