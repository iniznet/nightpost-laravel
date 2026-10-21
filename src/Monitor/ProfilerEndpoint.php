<?php

namespace Nightpost\Laravel\Monitor;

/**
 * Resolves the xhprof/spiral-profiler ingest URL (iniznet/fenrirealm-tracker#164).
 *
 * The profiler's project key travels as the URL user component, so
 * PROFILER_ENDPOINT and MONITOR_PROJECT_KEY were two independent places to name
 * the same project — and they drifted (profiles landed under `backend` while
 * monitor clients used the configured key). MONITOR_PROJECT_KEY is the single
 * source of truth: the user component is rewritten to it on every resolution.
 */
final class ProfilerEndpoint
{
    public const DEFAULT_ENDPOINT = 'http://127.0.0.1:8000/api/profiler/store';

    public static function resolve(?string $endpoint, string $projectKey): string
    {
        $endpoint = trim((string) $endpoint);
        if ($endpoint === '') {
            $endpoint = self::DEFAULT_ENDPOINT;
        }

        if ($projectKey === '') {
            return $endpoint;
        }

        // Insert (or replace) the user component right after the scheme; host,
        // port and path stay untouched. Non-HTTP values are returned as given.
        $resolved = preg_replace(
            '#^(https?://)(?:[^/@]*@)?#i',
            '$1'.rawurlencode($projectKey).'@',
            $endpoint,
            1,
        );

        return $resolved ?? $endpoint;
    }
}
