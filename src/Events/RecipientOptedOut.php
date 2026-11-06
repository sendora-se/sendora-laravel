<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\TrackingOptedOutEvent;

/**
 * A recipient asked not to be tracked, on the page that
 * `{{ tracking_opt_out_url }}` links to. Its listeners run before the route
 * answers, and Sendora waits 10 seconds for that, so queue slow work. Key your
 * handling on `webhook->id`, since a delivery can arrive again.
 */
final readonly class RecipientOptedOut implements WebhookReceived
{
    public function __construct(public TrackingOptedOutEvent $webhook) {}
}
