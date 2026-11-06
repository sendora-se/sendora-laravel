<?php

declare(strict_types=1);

namespace Sendora\Laravel\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Psr\Log\LoggerInterface;
use Sendora\Events\BouncedEvent;
use Sendora\Events\CapReachedEvent;
use Sendora\Events\CapWarningEvent;
use Sendora\Events\ClickedEvent;
use Sendora\Events\DeferredEvent;
use Sendora\Events\DeliveredEvent;
use Sendora\Events\InboundCapReachedEvent;
use Sendora\Events\InboundCapWarningEvent;
use Sendora\Events\InboundEvent;
use Sendora\Events\OpenedEvent;
use Sendora\Events\SpamComplaintEvent;
use Sendora\Events\UnsubscribedEvent;
use Sendora\Events\WebhookEvent;
use Sendora\Exception\WebhookVerificationException;
use Sendora\Laravel\Events\CapReached;
use Sendora\Laravel\Events\CapWarning;
use Sendora\Laravel\Events\InboundCapReached;
use Sendora\Laravel\Events\InboundCapWarning;
use Sendora\Laravel\Events\InboundEmailReceived;
use Sendora\Laravel\Events\LinkClicked;
use Sendora\Laravel\Events\MessageBounced;
use Sendora\Laravel\Events\MessageDeferred;
use Sendora\Laravel\Events\MessageDelivered;
use Sendora\Laravel\Events\MessageOpened;
use Sendora\Laravel\Events\RecipientUnsubscribed;
use Sendora\Laravel\Events\SpamComplaintReceived;
use Sendora\Laravel\Events\UnknownWebhookReceived;
use Sendora\Laravel\Events\WebhookReceived;
use Sendora\Types\ErrorCode;
use Sendora\WebhookVerifier;

/**
 * The webhook route. It verifies a delivery under each secret in turn and
 * fires the event for its webhook, then answers 204. A bad or stale
 * signature answers 400 and fires nothing. A listener that throws makes it
 * 500, so Sendora delivers the webhook again.
 *
 * @internal
 */
final class WebhookController
{
    public function __construct(
        private readonly Repository $config,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        $settings = WebhookSettings::of($this->config);
        // Cached routes keep the route after it is turned off or its last secret is gone.
        if (!$settings->routed()) {
            return new Response(status: 404);
        }
        try {
            $webhook = self::verified($request, $settings->secrets, $settings->tolerance);
        } catch (WebhookVerificationException $error) {
            $this->logger->debug('A Sendora webhook delivery failed its signature check.', ['code' => $error->errorCode]);

            return new Response(status: 400);
        }
        $this->events->dispatch(self::received($webhook));

        return new Response(status: 204);
    }

    /**
     * The webhook, under the first secret that verifies the delivery.
     *
     * @param list<string> $secrets
     *
     * @throws WebhookVerificationException under every secret
     */
    private static function verified(Request $request, #[\SensitiveParameter] array $secrets, int $tolerance): WebhookEvent
    {
        $failure = new WebhookVerificationException('No secret verifies the delivery.', ErrorCode::InvalidSignature->value);
        foreach ($secrets as $secret) {
            try {
                return WebhookVerifier::verify(
                    $request->getContent(),
                    signature: $request->headers->get('Sendora-Signature'),
                    secret: $secret,
                    tolerance: $tolerance,
                    now: Date::now()->getTimestamp(),
                );
            } catch (WebhookVerificationException $error) {
                $failure = $error;
                // A stale signature is stale under every secret, and a body that is no event is none under any.
                if ($error->errorCode !== ErrorCode::InvalidSignature->value) {
                    break;
                }
            }
        }

        throw $failure;
    }

    private static function received(WebhookEvent $webhook): WebhookReceived
    {
        return match (true) {
            $webhook instanceof DeliveredEvent => new MessageDelivered($webhook),
            $webhook instanceof BouncedEvent => new MessageBounced($webhook),
            $webhook instanceof DeferredEvent => new MessageDeferred($webhook),
            $webhook instanceof SpamComplaintEvent => new SpamComplaintReceived($webhook),
            $webhook instanceof UnsubscribedEvent => new RecipientUnsubscribed($webhook),
            $webhook instanceof OpenedEvent => new MessageOpened($webhook),
            $webhook instanceof ClickedEvent => new LinkClicked($webhook),
            $webhook instanceof CapWarningEvent => new CapWarning($webhook),
            $webhook instanceof CapReachedEvent => new CapReached($webhook),
            $webhook instanceof InboundEvent => new InboundEmailReceived($webhook),
            $webhook instanceof InboundCapWarningEvent => new InboundCapWarning($webhook),
            $webhook instanceof InboundCapReachedEvent => new InboundCapReached($webhook),
            default => new UnknownWebhookReceived($webhook),
        };
    }
}
