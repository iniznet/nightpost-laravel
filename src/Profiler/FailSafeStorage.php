<?php

declare(strict_types=1);

namespace Nightpost\Laravel\Profiler;

use SpiralPackages\Profiler\Storage\StorageInterface;

/**
 * Fire-and-forget telemetry boundary: a failed profile ship is a dropped
 * sample, not a failed request. Wrapping the storage (rather than the HTTP
 * client) keeps transport errors — unreachable monitor endpoint, transfer
 * timeout — out of the production log channel, where one error per profiled
 * request would recreate the storm the profiler exists to observe.
 */
final class FailSafeStorage implements StorageInterface
{
    public function __construct(private readonly StorageInterface $inner) {}

    public function store(string $appName, array $tags, \DateTimeInterface $date, array $data): void
    {
        try {
            $this->inner->store($appName, $tags, $date, $data);
        } catch (\Throwable) {
            // Intentionally swallowed: this is the documented drop-on-failure
            // contract of the fire-and-forget ship path (fenrirealm-tracker#145).
        }
    }
}
