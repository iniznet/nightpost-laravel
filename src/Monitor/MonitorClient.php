<?php

namespace Nightpost\Laravel\Monitor;

/**
 * Fire-and-forget HTTP client for the nightpost ingest endpoints
 * (first-party Buggregator replacement, iniznet/fenrirealm-tracker#145).
 *
 * Telemetry must never break the request path: every send is bounded by a
 * short curl timeout and swallow-on-failure, matching the posture the
 * senders before it already had (AGENTS.md, compose env-inert section).
 */
class MonitorClient implements ProfileSender
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $projectKey,
        private readonly int $timeoutMs = 300,
        private readonly string $ingestKey = '',
    ) {}

    /**
     * Wire headers for every ingest POST (#198): the per-project ingest key
     * rides X-Monitor-Key once the monitor issued one for this project.
     * Empty key = legacy unsigned posture (accepted while the project has
     * no key) — never send a bare header.
     *
     * @return array<int, string>
     */
    public function httpHeaders(): array
    {
        $headers = ['Content-Type: application/json'];
        if ($this->ingestKey !== '') {
            $headers[] = 'X-Monitor-Key: '.$this->ingestKey;
        }

        return $headers;
    }


    public function postProfile(array $payload): void
    {
        $this->post('/ingest/'.$this->projectKey.'/profile', $payload);
    }

    /**
     * Ship a first-party error report (non-Sentry capture paths).
     *
     * @param array<string, mixed> $payload
     */
    public function postError(array $payload): void
    {
        $this->post('/ingest/'.$this->projectKey.'/error', $payload);
    }

    /**
     * Ship one slow-query observation (shaped SQL only — bound values are
     * never serialized, iniznet/fenrirealm-tracker#157).
     *
     * @param array<string, mixed> $payload
     */
    public function postQuery(array $payload): void
    {
        $this->post('/ingest/'.$this->projectKey.'/query', $payload);
    }

    /**
     * Ship one batched job/command observation (#158). Scalars only —
     * job payload arguments are never serialized.
     *
     * @param array<string, mixed> $payload
     */
    public function postJob(array $payload): void
    {
        $this->post('/ingest/'.$this->projectKey.'/job', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $path, array $payload): void
    {
        $ch = curl_init(rtrim($this->baseUrl, '/').$path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $this->httpHeaders(),
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => 100,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}