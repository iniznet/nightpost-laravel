<?php

namespace Nightpost\Laravel\Monitor;

use Illuminate\Support\Facades\Cache;

/**
 * Batches job/command telemetry into monitor events (#158) and enrols each
 * run as a trace (#203): a random per-run trace id is minted at run start,
 * nested runs (dispatchSync inside a command, $this->call() children of
 * schedule:run) re-use the root's id, and slow or failed runs emit a
 * timing profile leg under that id so /jobs can drill into /traces.
 * When the xhprof extension is present and a cap slot is free, the root
 * run is additionally wrapped in the callgraph profiler; the raw edges
 * ship to the monitor's xhprof endpoint tagged with the same trace id,
 * landing the flame tree on the very trace the /jobs row links to.
 *
 * Redaction by construction: only the job class / command signature, queue
 * name, duration, outcome and a random hex trace id cross this boundary -
 * job payload arguments (which can carry reader data) are structurally
 * unreachable, the same posture as SlowQueryCapture's bindings-by-omission.
 *
 * Queue events window per (kind|name): a retry storm costs ONE POST per
 * window with the batch size in "count" (the monitor adds counts
 * atomically). Commands flush immediately because schedule:run is a
 * short-lived process - a daily cron's telemetry must not die in an
 * unflushed bucket, and the heartbeat rule depends on it. The capture
 * never throws into the job path; transport is the client's
 * fire-and-forget posture.
 */
final class JobCapture
{
    private const MAX_BUCKETS = 512;
    private const MAX_STARTED = 512;
    private const MAX_TRACES = 512;

    /** @var array<string, array{queue: string, ok_n: int, ok_sec: float, fail_n: int, fail_sec: float, next: float, trace: string}> */
    private array $buckets = [];

    /** @var array<string, float> job id / command name => start microtime */
    private array $started = [];

    /** @var array<string, string> job id => trace id minted at start */
    private array $traces = [];

    private int $runDepth = 0;

    private string $activeTrace = '';

    private bool $profiling = false;

    /**
     * The three profiling closures exist so the enable/disable/shipping
     * machinery is testable without the extension: production passes null
     * and gets the defaults; tests inject recorders. The container binding
     * never shares a Profiler instance across runs (#139 posture: the
     * worker-scope Profiler is not touched at all).
     */
    public function __construct(
        private readonly MonitorClient $client,
        private readonly float $windowSeconds = 60.0,
        private readonly float $minSeconds = 0.0,
        private readonly float $profileSeconds = 0.0,
        private readonly int $profileCap = 2,
        private readonly ?\Closure $startProfiling = null,
        private readonly ?\Closure $stopProfiling = null,
        private readonly ?\Closure $shipCallgraph = null,
    ) {}

    public function jobStarted(string $jobId): void
    {
        $this->markStart($jobId);
        $this->rememberTrace($jobId, $this->beginRun());
    }

    public function jobDone(string $jobId, string $name, string $queue, bool $ok): void
    {
        $seconds = $this->takeStart($jobId);
        $trace = $this->endRun('queue', $name, $queue, $seconds, $ok, $this->takeTrace($jobId));
        $this->record('queue', $name, $queue, $seconds, $ok, $trace);
    }

    public function commandStarted(string $command): void
    {
        $this->markStart('cmd|'.$command);
        $this->beginRun();
    }

    public function commandDone(string $command, bool $ok): void
    {
        $seconds = $this->takeStart('cmd|'.$command);
        $trace = $this->endRun('command', $command, '', $seconds, $ok);
        $this->record('command', $command, '', $seconds, $ok, $trace);
    }

    public function record(string $kind, string $name, string $queue, float $seconds, bool $ok, string $trace = ''): void
    {
        if ($name === '') {
            return;
        }
        if ($ok && $seconds < $this->minSeconds) {
            return; // under the floor fast successes are not news; failures always report
        }
        if ($kind === 'command') {
            $this->send($kind, $name, $queue, $seconds, $ok, 1, $trace);

            return;
        }

        $key = $kind.'|'.$name;
        $now = microtime(true);
        $b = $this->buckets[$key] ?? null;
        if ($b === null) {
            if (count($this->buckets) >= self::MAX_BUCKETS) {
                $this->flushAll(); // bounded memory: a full table flushes, nothing is evicted silently
            }
            $b = ['queue' => $queue, 'ok_n' => 0, 'ok_sec' => 0.0, 'fail_n' => 0, 'fail_sec' => 0.0, 'next' => $now + max(0.0, $this->windowSeconds), 'trace' => ''];
        }
        if ($queue !== '') {
            $b['queue'] = $queue;
        }
        if ($trace !== '') {
            $b['trace'] = $trace; // last profiled run of this name wins the link
        }
        if ($ok) {
            $b['ok_n']++;
            $b['ok_sec'] = $seconds;
        } else {
            $b['fail_n']++;
            $b['fail_sec'] = $seconds;
        }
        $this->buckets[$key] = $b;
        if ($now >= $b['next']) {
            $this->flush($key);
        }
    }

    /** Flush every windowed batch - worker shutdown hook. */
    public function flushAll(): void
    {
        foreach (array_keys($this->buckets) as $key) {
            $this->flush($key);
        }
    }

    private function flush(string $key): void
    {
        $b = $this->buckets[$key] ?? null;
        unset($this->buckets[$key]);
        if ($b === null) {
            return;
        }
        $sep = strpos($key, '|');
        $kind = substr($key, 0, $sep);
        $name = substr($key, $sep + 1);
        if ($b['ok_n'] > 0) {
            $this->send($kind, $name, $b['queue'], $b['ok_sec'], true, $b['ok_n'], $b['trace']);
        }
        if ($b['fail_n'] > 0) {
            $this->send($kind, $name, $b['queue'], $b['fail_sec'], false, $b['fail_n'], $b['trace']);
        }
    }

    private function markStart(string $key): void
    {
        if (count($this->started) >= self::MAX_STARTED) {
            $this->started = array_slice($this->started, intdiv(self::MAX_STARTED, 2), null, true);
        }
        $this->started[$key] = microtime(true);
    }

    private function takeStart(string $key): float
    {
        $start = $this->started[$key] ?? null;
        unset($this->started[$key]);

        return $start === null ? 0.0 : max(0.0, microtime(true) - $start);
    }

    private function rememberTrace(string $jobId, string $trace): void
    {
        if ($trace === '') {
            return;
        }
        if (count($this->traces) >= self::MAX_TRACES) {
            $this->traces = array_slice($this->traces, intdiv(self::MAX_TRACES, 2), null, true);
        }
        $this->traces[$jobId] = $trace;
    }

    private function takeTrace(string $jobId): string
    {
        $trace = $this->traces[$jobId] ?? '';
        unset($this->traces[$jobId]);

        return $trace;
    }

    /**
     * Open a run: nested runs inherit the root's trace id; only the root
     * may arm the callgraph profiler, and only with a cap slot reserved.
     */
    private function beginRun(): string
    {
        if ($this->runDepth === 0 && $this->profiling) {
            // Defensive: a previous root never closed. Discard its buffer
            // rather than bleeding attribution into this run.
            $this->profiling = false;
            $this->stopXhprof();
        }
        $this->runDepth++;
        if ($this->runDepth > 1) {
            return $this->activeTrace;
        }
        $this->activeTrace = bin2hex(random_bytes(16));
        if ($this->profileSeconds > 0 && $this->reserveCapSlot()) {
            $this->profiling = $this->startXhprof();
        }

        return $this->activeTrace;
    }

    /**
     * Close a run; returns the trace id to advertise on the aggregate
     * event ('' when no leg was emitted, so the /jobs link never points
     * at a trace that does not exist).
     */
    private function endRun(string $kind, string $name, string $queue, float $seconds, bool $ok, ?string $jobTrace = null): string
    {
        $trace = $jobTrace ?? $this->activeTrace;
        $this->runDepth = max(0, $this->runDepth - 1);
        if ($this->runDepth === 0) {
            if ($this->profiling) {
                $this->profiling = false;
                $data = $this->stopXhprof();
                if ($data !== null && ($seconds >= $this->profileSeconds || ! $ok)) {
                    $this->shipCallgraphData($data, [
                        'trace_id' => $trace,
                        'leg' => $kind,
                        'name' => $name,
                        'env' => (string) config('app.env', 'production'),
                    ]);
                }
            }
            $this->activeTrace = '';
        }
        if ($trace === '' || $this->profileSeconds <= 0 || ($ok && $seconds < $this->profileSeconds)) {
            return '';
        }
        $this->emitLeg($kind, $name, $queue, $seconds, $ok, $trace);

        return $trace;
    }

    private function emitLeg(string $kind, string $name, string $queue, float $seconds, bool $ok, string $trace): void
    {
        $ms = round(max(0.001, $seconds) * 1000, 2);
        $this->client->postProfile([
            'name' => $name,
            'duration_ms' => $ms,
            'environment' => (string) config('app.env', 'production'),
            'type' => $kind === 'queue' ? 'job' : 'command',
            'trace_id' => $trace,
            'spans' => [[
                'name' => 'run',
                'duration_ms' => $ms,
                'tags' => ['ok' => $ok ? '1' : '0', 'queue' => $queue !== '' ? $queue : '-'],
            ]],
        ]);
    }

    private function startXhprof(): bool
    {
        if ($this->startProfiling !== null) {
            return (bool) ($this->startProfiling)();
        }
        if (! extension_loaded('xhprof')) {
            return false;
        }
        \xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_NO_BUILTINS);

        return true;
    }

    private function stopXhprof(): ?array
    {
        if ($this->stopProfiling !== null) {
            $data = ($this->stopProfiling)();

            return is_array($data) && $data !== [] ? $data : null;
        }
        if (! extension_loaded('xhprof')) {
            return null;
        }
        $data = \xhprof_disable();

        return is_array($data) && $data !== [] ? $data : null;
    }

    /**
     * Ship raw xhprof edges to the monitor's xhprof endpoint (the same
     * wire the request-side profiler uses, so the monitor builds the call
     * tree and lands it on the run's trace via tags.trace_id). Never
     * throws: attribution must not break the run it describes.
     */
    private function shipCallgraphData(array $data, array $tags): void
    {
        try {
            if ($this->shipCallgraph !== null) {
                ($this->shipCallgraph)($data, $tags);

                return;
            }
            $endpoint = (string) config('xhprof.endpoint', '');
            if ($endpoint === '') {
                return;
            }
            $storage = new \SpiralPackages\Profiler\Storage\WebStorage(
                new \Symfony\Component\HttpClient\CurlHttpClient([
                    'max_connect_duration' => 1.0,
                    'timeout' => 2.0,
                    'max_duration' => 3.0,
                ]),
                $endpoint,
            );
            $storage->store((string) config('app.name'), $tags, new \DateTimeImmutable(), $data);
        } catch (\Throwable) {
            // fire-and-forget posture
        }
    }

    private function reserveCapSlot(): bool
    {
        if ($this->profileCap <= 0) {
            return false;
        }
        $minute = (int) floor(microtime(true) / 60);
        $key = "monitor:job-profiles:{$minute}";
        Cache::add($key, 0, 60);
        // Approximate under parallel reads by design; the ceiling is a
        // cost bound for worker sampling, not an accounting record.
        return (int) Cache::increment($key) <= $this->profileCap;
    }

    private function send(string $kind, string $name, string $queue, float $seconds, bool $ok, int $count, string $trace = ''): void
    {
        $payload = [
            'name' => $name,
            'kind' => $kind,
            'queue' => $queue,
            'seconds' => round(max(0.0, $seconds), 3),
            'ok' => $ok,
            'count' => $count,
        ];
        if ($trace !== '') {
            $payload['trace'] = $trace;
        }
        $this->client->postJob($payload);
    }
}
