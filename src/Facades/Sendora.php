<?php

declare(strict_types=1);

namespace Sendora\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Sendora\Resources\Broadcasts;
use Sendora\Resources\Email;
use Sendora\Resources\Inbound;
use Sendora\Resources\InboundDomains;
use Sendora\Resources\Messages;
use Sendora\Resources\Streams;
use Sendora\Resources\Suppressions;
use Sendora\Resources\ThisServer;
use Sendora\Resources\Tokens;
use Sendora\Resources\TrackingOptOuts;
use Sendora\Resources\Webhooks;
use Sendora\Sendora as Client;

/**
 * The server client in the container, by its resources:
 * `Sendora::email()->send(...)`. Each method resolves its resource's class
 * from the container, so a test's `$this->mock(Email::class)` replaces
 * what `Sendora::email()` returns, and `Sendora::swap()` takes a client of
 * the test's own. The client is final, so `Sendora::shouldReceive()`
 * cannot double it.
 */
final class Sendora extends Facade
{
    public static function broadcasts(): Broadcasts
    {
        return self::resource(Broadcasts::class);
    }

    public static function email(): Email
    {
        return self::resource(Email::class);
    }

    public static function inbound(): Inbound
    {
        return self::resource(Inbound::class);
    }

    public static function inboundDomains(): InboundDomains
    {
        return self::resource(InboundDomains::class);
    }

    public static function messages(): Messages
    {
        return self::resource(Messages::class);
    }

    public static function server(): ThisServer
    {
        return self::resource(ThisServer::class);
    }

    public static function streams(): Streams
    {
        return self::resource(Streams::class);
    }

    public static function suppressions(): Suppressions
    {
        return self::resource(Suppressions::class);
    }

    public static function tokens(): Tokens
    {
        return self::resource(Tokens::class);
    }

    public static function trackingOptOuts(): TrackingOptOuts
    {
        return self::resource(TrackingOptOuts::class);
    }

    public static function webhooks(): Webhooks
    {
        return self::resource(Webhooks::class);
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $resource
     *
     * @return T
     */
    private static function resource(string $resource): object
    {
        $app = self::getFacadeApplication();
        if ($app === null) {
            throw new \RuntimeException('The Sendora facade needs a Laravel application, which sets it up.');
        }

        return $app->make($resource);
    }
}
