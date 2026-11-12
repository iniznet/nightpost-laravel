<?php

namespace Nightpost\Laravel\Monitor;

use Illuminate\Support\Str;

/**
 * The v2 producer identity every payload carries (nightpost#9): the service
 * this deployment reports as, and the environment it runs in.
 *
 * The name defaults to the slug of app.name, so an install that configures
 * nothing still names itself instead of arriving anonymous; the environment is
 * always the running app environment, never a second declaration that can
 * drift from it. Both fields are OPTIONAL on the wire: a v1 payload without
 * them stays valid and the monitor labels it with the project key.
 */
final class ServiceIdentity
{
    public function __construct(
        private readonly string $service,
        private readonly string $environment,
    ) {}

    /**
     * @param array<string, mixed> $config the monitor config array
     */
    public static function fromConfig(array $config, string $appName, string $environment): self
    {
        $service = trim((string) ($config['service'] ?? ''));
        if ($service === '') {
            $service = Str::slug($appName);
        }
        if ($service === '') {
            // Last resort: the project is already the monitor-side name of
            // this deployment; naming it twice beats an empty service.
            $service = trim((string) ($config['project_key'] ?? ''));
        }

        return new self(
            $service !== '' ? $service : 'backend',
            $environment !== '' ? $environment : 'production',
        );
    }

    public function name(): string
    {
        return $this->service;
    }

    public function env(): string
    {
        return $this->environment;
    }

    /**
     * Add the identity to a payload, never overwriting a field the caller set:
     * the difference between a v1 and a v2 payload is two extra keys.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function decorate(array $payload): array
    {
        if (! isset($payload['service']) || $payload['service'] === '') {
            $payload['service'] = $this->service;
        }
        if (! isset($payload['env']) || $payload['env'] === '') {
            $payload['env'] = $this->environment;
        }

        return $payload;
    }
}
