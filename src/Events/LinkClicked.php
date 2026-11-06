<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\ClickedEvent;

/**
 * A tracked link was followed. It comes from a `clicked` webhook, which a
 * webhook gets only if it names it. Its listeners run before the route
 * answers, and Sendora waits 10 seconds for that, so queue slow work. Key your
 * handling on `webhook->id`, since a delivery can arrive again.
 */
final readonly class LinkClicked implements WebhookReceived
{
    public function __construct(public ClickedEvent $webhook) {}
}
