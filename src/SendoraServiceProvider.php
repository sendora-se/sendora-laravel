<?php

declare(strict_types=1);

namespace Sendora\Laravel;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Sendora\Exception\InvalidArgumentException;
use Sendora\Laravel\Http\WebhookController;
use Sendora\Laravel\Http\WebhookSettings;
use Sendora\Mailer\SendoraTransport;
use Sendora\Resources\Broadcasts;
use Sendora\Resources\Domains;
use Sendora\Resources\Email;
use Sendora\Resources\Erasures;
use Sendora\Resources\Inbound;
use Sendora\Resources\InboundDomains;
use Sendora\Resources\Messages;
use Sendora\Resources\Servers;
use Sendora\Resources\ServerTokens;
use Sendora\Resources\Streams;
use Sendora\Resources\Suppressions;
use Sendora\Resources\ThisServer;
use Sendora\Resources\Tokens;
use Sendora\Resources\TrackingDomains;
use Sendora\Resources\TrackingOptOuts;
use Sendora\Resources\Webhooks;
use Sendora\Sendora;
use Sendora\SendoraAccount;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Puts both of Sendora's clients and each of their resources in the
 * container, built on first use from `config/sendora.php` with Laravel's
 * logger, so an app that never calls Sendora needs no key. The settings
 * publish under the tag `sendora-config`. The `sendora` mailer sends
 * Laravel's mail through the API, with the SDK's driver. Once a webhook
 * secret is set, the route `sendora.webhook` fires an event for each
 * delivery.
 */
final class SendoraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Recursively, so an app's settings that set some of the webhook's keys keep the rest.
        $this->replaceConfigRecursivelyFrom(__DIR__ . '/../config/sendora.php', 'sendora');
        $this->mergeConfigFrom(__DIR__ . '/../config/mailers.php', 'mail.mailers');

        // Once the manager resolves rather than in boot(), so a mailer that something resolved earlier still finds the driver.
        $this->callAfterResolving('mail.manager', static function (MailManager $manager, Container $app): void {
            $manager->extend('sendora', static fn(array $mailer): TransportInterface => self::transport($app, $mailer));
        });

        $this->app->singleton(Sendora::class, static fn(Container $app): Sendora => new Sendora(...self::options($app, 'token')));
        $this->app->singleton(SendoraAccount::class, static fn(Container $app): SendoraAccount => new SendoraAccount(...self::options($app, 'account_token')));

        // Bound rather than shared, so a client swapped in, as Sendora::swap() does, brings its own.
        $this->app->bind(Broadcasts::class, static fn(Container $app): Broadcasts => $app->make(Sendora::class)->broadcasts);
        $this->app->bind(Email::class, static fn(Container $app): Email => $app->make(Sendora::class)->email);
        $this->app->bind(Inbound::class, static fn(Container $app): Inbound => $app->make(Sendora::class)->inbound);
        $this->app->bind(InboundDomains::class, static fn(Container $app): InboundDomains => $app->make(Sendora::class)->inboundDomains);
        $this->app->bind(Messages::class, static fn(Container $app): Messages => $app->make(Sendora::class)->messages);
        $this->app->bind(ThisServer::class, static fn(Container $app): ThisServer => $app->make(Sendora::class)->server);
        $this->app->bind(Streams::class, static fn(Container $app): Streams => $app->make(Sendora::class)->streams);
        $this->app->bind(Suppressions::class, static fn(Container $app): Suppressions => $app->make(Sendora::class)->suppressions);
        $this->app->bind(Tokens::class, static fn(Container $app): Tokens => $app->make(Sendora::class)->tokens);
        $this->app->bind(TrackingOptOuts::class, static fn(Container $app): TrackingOptOuts => $app->make(Sendora::class)->trackingOptOuts);
        $this->app->bind(Webhooks::class, static fn(Container $app): Webhooks => $app->make(Sendora::class)->webhooks);
        $this->app->bind(Domains::class, static fn(Container $app): Domains => $app->make(SendoraAccount::class)->domains);
        $this->app->bind(TrackingDomains::class, static fn(Container $app): TrackingDomains => $app->make(SendoraAccount::class)->trackingDomains);
        $this->app->bind(Erasures::class, static fn(Container $app): Erasures => $app->make(SendoraAccount::class)->erasures);
        $this->app->bind(Servers::class, static fn(Container $app): Servers => $app->make(SendoraAccount::class)->servers);
        $this->app->bind(ServerTokens::class, static fn(Container $app): ServerTokens => $app->make(SendoraAccount::class)->servers->tokens);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/sendora.php' => $this->app->configPath('sendora.php')], 'sendora-config');
        if (!($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            self::route($this->app);
        }
    }

    /**
     * The webhook route, outside the `web` group, so no CSRF check applies.
     * It exists only once a secret is set, so until then a delivery meets
     * a 404, which Sendora retries.
     */
    private static function route(Container $app): void
    {
        $settings = WebhookSettings::of($app->make(Repository::class));
        if (!$settings->routed()) {
            return;
        }
        $route = $app->make(Registrar::class)->post($settings->path, WebhookController::class);
        $route->name('sendora.webhook');
        $route->middleware($settings->middleware);
        if ($settings->domain !== null) {
            $route->domain($settings->domain);
        }
    }

    /**
     * The driver for a mailer of `config/mail.php`: the container's client,
     * or one of the mailer's own key and address. It sends on the mailer's
     * stream, or else, with the package's key, on the package's.
     *
     * @param array<array-key, mixed> $mailer the mailer's settings
     */
    private static function transport(Container $app, array $mailer): SendoraTransport
    {
        $ownKey = ($mailer['token'] ?? null) !== null;
        $client = $ownKey || ($mailer['base_url'] ?? null) !== null ? new Sendora(...self::options($app, 'token', $mailer)) : $app->make(Sendora::class);
        // The package's stream belongs to the package's server, so another server's key goes on that server's default.
        $stream = self::text($mailer['stream'] ?? null, "A sendora mailer's stream")
            ?? ($ownKey ? null : self::text($app->make(Repository::class)->get('sendora.stream'), 'sendora.stream'));

        return new SendoraTransport($client, $stream);
    }

    /**
     * A client's arguments, from the settings, and from a mailer's own key
     * and address where it has them.
     *
     * @param array<array-key, mixed> $mailer
     *
     * @return array{token: null|string, baseUrl: null|string, timeout: float, maxRetries: int, httpClient: null|ClientInterface, requestFactory: null|RequestFactoryInterface, streamFactory: null|StreamFactoryInterface, logger: LoggerInterface}
     */
    private static function options(Container $app, string $key, array $mailer = []): array
    {
        $config = $app->make(Repository::class);
        $client = self::httpClient($app, $config->get('sendora.http_client'));
        $factory = $client === null || ($client instanceof RequestFactoryInterface && $client instanceof StreamFactoryInterface) ? null : new HttpFactory();

        return [
            'token' => self::text($mailer['token'] ?? null, "A sendora mailer's token") ?? self::text($config->get("sendora.{$key}"), "sendora.{$key}"),
            'baseUrl' => self::text($mailer['base_url'] ?? null, "A sendora mailer's base_url") ?? self::text($config->get('sendora.base_url'), 'sendora.base_url'),
            'timeout' => self::seconds($config->get('sendora.timeout')),
            'maxRetries' => self::retries($config->get('sendora.max_retries')),
            'httpClient' => $client,
            'requestFactory' => $factory,
            'streamFactory' => $factory,
            'logger' => $app->make(LoggerInterface::class),
        ];
    }

    /** A setting that is text, or empty, which leaves it to the SDK's own variable. */
    private static function text(mixed $value, string $setting): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!\is_string($value)) {
            throw new InvalidArgumentException("{$setting} must be text.");
        }

        return $value;
    }

    private static function seconds(mixed $value): float
    {
        if (\is_int($value) || \is_float($value) || (\is_string($value) && \is_numeric($value))) {
            return (float) $value;
        }

        throw new InvalidArgumentException('sendora.timeout must be a number of seconds.');
    }

    private static function retries(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/\A[0-9]+\z/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('sendora.max_retries must be a whole number.');
    }

    /** The PSR-18 client that `sendora.http_client` names in the container. */
    private static function httpClient(Container $app, mixed $name): ?ClientInterface
    {
        if ($name === null || $name === '') {
            return null;
        }
        if (!\is_string($name)) {
            throw new InvalidArgumentException('sendora.http_client must name a PSR-18 client in the container.');
        }
        $client = $app->make($name);
        if (!$client instanceof ClientInterface) {
            throw new InvalidArgumentException("sendora.http_client names {$name}, which is no PSR-18 client.");
        }

        return $client;
    }
}
