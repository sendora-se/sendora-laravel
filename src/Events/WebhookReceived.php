<?php

declare(strict_types=1);

namespace Sendora\Laravel\Events;

use Sendora\Events\WebhookEvent;

/**
 * A webhook delivery whose signature the route has checked. Every event of
 * the package implements it, so a listener of this interface hears them
 * all.
 *
 * Listeners that are not queued run before the route answers. Sendora
 * waits 10 seconds for the answer, so queue slow work. A listener that
 * throws makes the answer 500, and Sendora delivers the webhook again. A
 * queued listener that fails is not delivered again. Key your handling on
 * `webhook->id`, since a delivery can arrive again.
 *
 * @property-read WebhookEvent $webhook the SDK's event, as the delivery carried it
 */
interface WebhookReceived {}
