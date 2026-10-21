<?php

namespace Nightpost\Laravel\Monitor;

use Illuminate\Database\Events\QueryExecuted;

/**
 * Turns a slow QueryExecuted event into one monitor query observation (#157).
 *
 * Redaction by omission: Laravel keeps bound values out of ->sql (they live
 * in ->bindings as ? placeholders) and this class never reads ->bindings —
 * parameter values cannot reach the monitor. The placeholder SQL is shipped
 * verbatim; the monitor's normalizeSQL() is the single grouping authority.
 * One statement re-alerts at most once per dedupe window per worker, so a
 * bad deploy flooding slow queries cannot outrun the ingest budget.
 */
final class SlowQueryCapture
{
    private const MAX_SQL_BYTES = 8000;

    /** @var array<string, float> sql-hash => last send-at microtime */
    private array $recent = [];

    public function __construct(
        private readonly MonitorClient $client,
        private readonly float $thresholdMs,
        private readonly string $environment,
        private readonly int $dedupeSeconds = 60,
    ) {}

    public function handle(QueryExecuted $event): void
    {
        if ($this->thresholdMs <= 0 || $event->time < $this->thresholdMs) {
            return;
        }
        if (strlen($event->sql) > self::MAX_SQL_BYTES) {
            return;
        }

        $key = md5($event->sql);
        $now = microtime(true);
        if ($now - ($this->recent[$key] ?? 0.0) < $this->dedupeSeconds) {
            return;
        }
        $this->recent[$key] = $now;
        if (count($this->recent) > 512) {
            // Keep the NEWEST half: during a flood the fresh shapes are the
            // ones that must stay suppressed.
            arsort($this->recent);
            $this->recent = array_slice($this->recent, 0, 256, true);
        }

        $this->client->postQuery([
            'sql' => $event->sql,
            'time_ms' => round($event->time, 2),
            'environment' => $this->environment,
            'connection' => $event->connectionName,
        ]);
    }
}
