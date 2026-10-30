<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\WebhookEvent;

/**
 * A verified webhook that the package has no event for: the SDK's
 * `UnknownEvent`, for an event newer than the SDK, or an event of an SDK
 * newer than the package. `webhook->event` is its name, and
 * `webhook->toArray()` its payload as it came. Its listeners run before the
 * route answers, and Sendora waits 10 seconds for that, so queue slow work.
 * Key your handling on `webhook->id`, since a delivery can arrive again.
 */
final readonly class UnknownWebhookReceived implements WebhookReceived
{
    public function __construct(public WebhookEvent $webhook) {}
}
