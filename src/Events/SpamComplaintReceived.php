<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\SpamComplaintEvent;

/**
 * A recipient reported a message as spam. It comes from a `spam_complaint`
 * webhook. Its listeners run before the route answers, and Sendora waits 10
 * seconds for that, so queue slow work. Key your handling on `webhook->id`,
 * since a delivery can arrive again.
 */
final readonly class SpamComplaintReceived implements WebhookReceived
{
    public function __construct(public SpamComplaintEvent $webhook) {}
}
