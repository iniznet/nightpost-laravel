<?php

namespace Nightpost\Laravel\Monitor;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Redis\Events\CommandExecuted;

/**
 * Collects the child spans of one request and attaches them to its profile
 * payload (nightpost#9 v2): one span for database work, one for Redis when the
 * connection was used, and one per outbound HTTP client call.
 *
 * `start_ms` is always an OFFSET from the request's own start, never a wall
 * clock: producers run on different hosts, and a join on absolute time needs
 * clock sync no install has. Nothing here can throw into the request path, and
 * a request that never opened a collector attaches nothing, so an unconfigured
 * install keeps sending exactly the v1 payload it always did.
 *
 * Database and Redis work are AGGREGATED into one span each (count + total
 * time): a request with 40 queries is one hop with a cost, not 40 rows that
 * bury the waterfall. Outbound calls stay separate — each has its own peer and
 * outcome, which is the whole point of naming the callee.
 */
final class SpanCollector
{
    private const MAX_CLIENT_SPANS = 20;

    private ?float $startedAt = null;

    /** @var array{count: int, first: float, total: float} */
    private array $database = ['count' => 0, 'first' => 0.0, 'total' => 0.0];

    /** @var array{count: int, first: float, total: float} */
    private array $redis = ['count' => 0, 'first' => 0.0, 'total' => 0.0];

    /** @var array<int, array{method: string, host: string, started: float}> */
    private array $sending = [];

    /** @var list<array{method: string, host: string, started: float, duration: float, status: string}> */
    private array $clients = [];

    private readonly \Closure $clock;

    public function __construct(
        private readonly TraceContext $trace,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** Open the collector for one request. */
    public function begin(): void
    {
        $this->startedAt = $this->now();
    }

    public function active(): bool
    {
        return $this->startedAt !== null;
    }

    public function query(QueryExecuted $event): void
    {
        $this->recordDatabase((float) $event->time);
    }

    public function recordDatabase(float $milliseconds): void
    {
        if (! $this->active()) {
            return;
        }
        $elapsed = max(0.0, $milliseconds);
        if ($this->database['count'] === 0) {
            $this->database['first'] = $this->now() - $elapsed;
        }
        $this->database['count']++;
        $this->database['total'] += $elapsed;
    }

    public function redis(CommandExecuted $event): void
    {
        $this->recordRedis((float) $event->time);
    }

    public function recordRedis(float $milliseconds): void
    {
        if (! $this->active()) {
            return;
        }
        $elapsed = max(0.0, $milliseconds);
        if ($this->redis['count'] === 0) {
            $this->redis['first'] = $this->now() - $elapsed;
        }
        $this->redis['count']++;
        $this->redis['total'] += $elapsed;
    }

    public function sending(RequestSending $event): void
    {
        if (! $this->active()) {
            return;
        }
        if (count($this->sending) >= self::MAX_CLIENT_SPANS) {
            // Bounded memory: keep the NEWEST opens, which are the ones whose
            // response has not arrived yet.
            $this->sending = array_slice($this->sending, intdiv(self::MAX_CLIENT_SPANS, 2), null, true);
        }
        $this->sending[spl_object_id($event->request)] = [
            'method' => strtoupper((string) $event->request->method()),
            'host' => self::host((string) $event->request->url()),
            'started' => $this->now(),
        ];
    }

    public function received(ResponseReceived $event): void
    {
        if (! $this->active()) {
            return;
        }
        $pending = $this->close($event->request);
        $now = $this->now();
        $status = (int) $event->response->status();
        $this->recordClient(
            $pending['method'] ?? strtoupper((string) $event->request->method()),
            $pending['host'] ?? self::host((string) $event->request->url()),
            $pending['started'] ?? $now,
            ($now - ($pending['started'] ?? $now)) * 1000,
            $status >= 500 ? 'error' : 'ok',
        );
    }

    public function failed(ConnectionFailed $event): void
    {
        if (! $this->active()) {
            return;
        }
        $pending = $this->close($event->request);
        $now = $this->now();
        $this->recordClient(
            $pending['method'] ?? strtoupper((string) $event->request->method()),
            $pending['host'] ?? self::host((string) $event->request->url()),
            $pending['started'] ?? $now,
            ($now - ($pending['started'] ?? $now)) * 1000,
            'error',
        );
    }

    /**
     * Record one outbound call. Kept as primitives so a producer that talks to
     * a client the framework does not wrap can still report the hop.
     */
    public function recordClient(string $method, string $host, float $startedAt, float $durationMs, string $status): void
    {
        if (! $this->active()) {
            return;
        }
        if (count($this->clients) >= self::MAX_CLIENT_SPANS) {
            return;
        }
        $this->clients[] = [
            'method' => $method,
            'host' => $host,
            'started' => $startedAt,
            'duration' => max(0.0, $durationMs),
            'status' => $status === 'error' ? 'error' : 'ok',
        ];
    }

    /**
     * The child spans of this request, ready for the profile payload.
     *
     * @return list<array<string, mixed>>
     */
    public function spans(): array
    {
        if (! $this->active()) {
            return [];
        }

        $root = $this->trace->spanId();
        $spans = [];

        if ($this->database['count'] > 0) {
            $spans[] = $this->span(
                'db',
                'db: '.self::plural($this->database['count'], 'query', 'queries'),
                $this->database['first'],
                $this->database['total'],
                'ok',
                '',
                $root,
            );
        }
        if ($this->redis['count'] > 0) {
            $spans[] = $this->span(
                'redis',
                'redis: '.self::plural($this->redis['count'], 'command', 'commands'),
                $this->redis['first'],
                $this->redis['total'],
                'ok',
                '',
                $root,
            );
        }
        foreach ($this->clients as $client) {
            $spans[] = $this->span(
                'client',
                trim($client['method'].' '.$client['host']),
                $client['started'],
                $client['duration'],
                $client['status'],
                $client['host'],
                $root,
            );
        }

        return $spans;
    }

    /**
     * Add this request's trace context and child spans to a profile payload.
     * An inactive collector returns the payload untouched — the v1 shape.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function attach(array $payload): array
    {
        if (! $this->active()) {
            return $payload;
        }

        $payload = $this->trace->attach($payload);
        $children = $this->spans();
        if ($children === []) {
            return $payload;
        }

        $existing = isset($payload['spans']) && is_array($payload['spans']) ? $payload['spans'] : [];
        $payload['spans'] = array_merge($existing, $children);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function span(
        string $kind,
        string $name,
        float $startedAt,
        float $durationMs,
        string $status,
        string $peer,
        string $root,
    ): array {
        $span = [
            'span_id' => TraceContext::mintSpanId(),
            'parent_span_id' => $root,
            'kind' => $kind,
            'name' => $name,
            'start_ms' => round(max(0.0, ($startedAt - (float) $this->startedAt) * 1000), 2),
            'duration_ms' => round(max(0.0, $durationMs), 2),
            'status' => $status,
        ];
        if ($peer !== '') {
            $span['peer_service'] = $peer;
        }

        return $span;
    }

    /**
     * @return array{method: string, host: string, started: float}|null
     */
    private function close(object $request): ?array
    {
        $id = spl_object_id($request);
        $pending = $this->sending[$id] ?? null;
        unset($this->sending[$id]);

        return $pending;
    }

    private static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    private static function plural(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    private function now(): float
    {
        return ($this->clock)();
    }
}
