<?php

declare(strict_types=1);

namespace Sendora\Laravel\Http;

use Illuminate\Contracts\Config\Repository;
use Sendora\Exception\InvalidArgumentException;
use Sendora\WebhookVerifier;

/**
 * The settings under `sendora.webhook`, checked, each failure naming its
 * setting. A key that is missing takes the package's default, since a
 * config cached before the package was installed lacks them all, and the
 * provider reads them as the app boots.
 *
 * @internal
 */
final readonly class WebhookSettings
{
    /**
     * @param list<string> $middleware
     * @param list<string> $secrets
     */
    private function __construct(
        public bool $enabled,
        public string $path,
        public ?string $domain,
        public array $middleware,
        public array $secrets,
        public int $tolerance,
    ) {}

    public static function of(Repository $config): self
    {
        return new self(
            enabled: self::enabled($config->get('sendora.webhook.enabled', true)),
            path: self::path($config->get('sendora.webhook.path', 'sendora/webhook')),
            domain: self::domain($config->get('sendora.webhook.domain')),
            middleware: self::middleware($config->get('sendora.webhook.middleware', [])),
            secrets: self::secrets($config->get('sendora.webhook.secrets')),
            tolerance: self::tolerance($config->get('sendora.webhook.tolerance', WebhookVerifier::TOLERANCE)),
        );
    }

    /** Whether the route exists: turned on, with a secret to check deliveries with. */
    public function routed(): bool
    {
        return $this->enabled && $this->secrets !== [];
    }

    private static function enabled(mixed $value): bool
    {
        if (!\is_bool($value)) {
            throw new InvalidArgumentException('sendora.webhook.enabled must be true or false.');
        }

        return $value;
    }

    private static function path(mixed $value): string
    {
        if (!\is_string($value) || $value === '') {
            throw new InvalidArgumentException('sendora.webhook.path must be a path, such as sendora/webhook.');
        }

        return $value;
    }

    private static function domain(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!\is_string($value)) {
            throw new InvalidArgumentException('sendora.webhook.domain must be a domain, or null for any.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function middleware(mixed $value): array
    {
        $listed = \is_string($value) ? [$value] : $value;
        if (!\is_array($listed) || !\array_is_list($listed)) {
            throw new InvalidArgumentException('sendora.webhook.middleware must be a list of middleware.');
        }
        $names = [];
        foreach ($listed as $name) {
            if (!\is_string($name)) {
                throw new InvalidArgumentException('sendora.webhook.middleware must be a list of middleware.');
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * The secrets, from a list or from text separated by commas, each
     * trimmed, the empty ones left out.
     *
     * @return list<string>
     */
    private static function secrets(#[\SensitiveParameter] mixed $value): array
    {
        $listed = \is_string($value) ? \explode(',', $value) : $value ?? [];
        if (!\is_array($listed)) {
            throw new InvalidArgumentException('sendora.webhook.secrets must be the signing secrets, separated by commas.');
        }
        $secrets = [];
        foreach ($listed as $secret) {
            if (!\is_string($secret)) {
                throw new InvalidArgumentException('sendora.webhook.secrets must be the signing secrets, separated by commas.');
            }
            if (\trim($secret) !== '') {
                $secrets[] = \trim($secret);
            }
        }

        return $secrets;
    }

    private static function tolerance(mixed $value): int
    {
        if (\is_int($value) && $value >= 0) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/\A[0-9]+\z/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('sendora.webhook.tolerance must be a whole number of seconds.');
    }
}
