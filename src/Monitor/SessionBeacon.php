<?php

namespace Nightpost\Laravel\Monitor;

/**
 * The v2 session outcome beacon (nightpost#9): one POST per session, keyed by
 * the FRAMEWORK session id.
 *
 * The monitor is idempotent on (project, session_id), so a retry updates the
 * outcome of the same session instead of counting it twice. A request without
 * a session is SKIPPED rather than sent under an invented id: two visitors
 * must never collapse into one crash-free row.
 *
 * "crashed" is deliberately unreachable here — a beacon is written by a
 * request that ended, and a process that died has nobody left to send it. What
 * a completed request can report is "abnormal", the 5xx case.
 */
final class SessionBeacon
{
    public function __construct(
        private readonly MonitorClient $client,
        private readonly string $release = '',
    ) {}

    /**
     * @param string $sessionId the framework session id; '' skips the beacon
     * @param string $route the route PATTERN, never a URL carrying ids
     */
    public function record(string $sessionId, string $route, int $statusCode, float $durationMs): void
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return;
        }

        $payload = [
            'session_id' => $sessionId,
            'release' => $this->release,
            'status' => $statusCode >= 500 ? 'abnormal' : 'ok',
            'duration_ms' => (int) round(max(0.0, $durationMs)),
        ];
        if ($route !== '') {
            $payload['route'] = $route;
        }

        $this->client->postSession($payload);
    }
}
