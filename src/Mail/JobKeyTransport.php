<?php

declare(strict_types=1);

namespace Sendora\Laravel\Mail;

use Sendora\Exception\SendoraException;
use Sendora\Mailer\SendoraTransport;
use Sendora\Types\ErrorCode;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/** @internal */
final class JobKeyTransport implements TransportInterface
{
    private const KEY = 'X-Sendora-Idempotency-Key';

    public function __construct(
        private readonly SendoraTransport $transport,
        private readonly JobKeys $keys,
    ) {}

    public function send(#[\SensitiveParameter] RawMessage $message, #[\SensitiveParameter] ?Envelope $envelope = null): ?SentMessage
    {
        if (!$message instanceof Message || $message->getHeaders()->has(self::KEY)) {
            return $this->transport->send($message, $envelope);
        }
        $key = $this->keys->next();
        if ($key === null) {
            return $this->transport->send($message, $envelope);
        }
        $keyed = clone $message;
        $keyed->getHeaders()->addTextHeader(self::KEY, $key);
        try {
            return $this->transport->send($keyed, $envelope);
        } catch (TransportException $error) {
            if (!self::usedForAnotherMail($error)) {
                throw $error;
            }
        }

        // The key went with a different mail on an earlier attempt, as a new link expiry makes one, and failing would stop the job.
        return $this->transport->send($message, $envelope);
    }

    public function __toString(): string
    {
        return (string) $this->transport;
    }

    private static function usedForAnotherMail(TransportException $error): bool
    {
        $refusal = $error->getPrevious();

        return $refusal instanceof SendoraException && $refusal->errorCode === ErrorCode::IdempotencyKeyMismatch->value;
    }
}
