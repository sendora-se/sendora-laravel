<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\InboundCapWarningEvent;

/**
 * 80 percent of the account's monthly cap on received mail is used. It
 * comes from an `inbound_cap_warning` webhook. Its listeners run before the
 * route answers, and Sendora waits 10 seconds for that, so queue slow work.
 * Key your handling on `webhook->id`, since a delivery can arrive again.
 */
final readonly class InboundCapWarning implements WebhookReceived
{
    public function __construct(public InboundCapWarningEvent $webhook) {}
}
