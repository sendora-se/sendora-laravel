<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\CapWarningEvent;

/**
 * 80 percent of a monthly cap on sending is used. It comes from a
 * `cap_warning` webhook. Its listeners run before the route answers, and
 * Sendora waits 10 seconds for that, so queue slow work. Key your handling
 * on `webhook->id`, since a delivery can arrive again.
 */
final readonly class CapWarning implements WebhookReceived
{
    public function __construct(public CapWarningEvent $webhook) {}
}
