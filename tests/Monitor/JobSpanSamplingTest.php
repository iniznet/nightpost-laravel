<?php

namespace NightpostTests\Monitor;

use Nightpost\Laravel\Monitor\JobCapture;
use Nightpost\Laravel\Monitor\MonitorClient;
use NightpostTests\TestCase;

class SilentClient extends MonitorClient
{
    public function __construct()
    {
        parent::__construct('http://127.0.0.1:1', 'backend', 1, '');
    }

    public function postJob(array $payload): void {}

    public function postProfile(array $payload): void {}
}

/**
 * A sampled job run opens the span collector, so the profile it already emits
 * carries database / Redis / outbound spans (nightpost#4). Sampling is
 * deterministic on the run's trace and capped per hour, because one queue in
 * the field runs 2.8M times a day: an unbounded sampler would be an outage.
 */
class JobSpanSamplingTest extends TestCase
{
    /** @param list<string> $opened */
    private function capture(float $rate, int $cap, array &$opened): JobCapture
    {
        return new JobCapture(
            new SilentClient(),
            0.0, 0.0, 0.0, 0,
            null, null, null,
            spanRate: $rate,
            beginSpans: static function (string $trace) use (&$opened): void {
                $opened[] = $trace;
            },
            spanCap: $cap,
        );
    }

    public function test_a_sampled_run_opens_the_collector_once(): void
    {
        $opened = [];
        $capture = $this->capture(1.0, 200, $opened);
        $capture->jobStarted('1');
        $capture->jobDone('1', 'App\\Jobs\\Thing', 'default', true);
        $this->assertCount(1, $opened, 'a sampled run opens the span collector exactly once');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $opened[0]);
    }

    public function test_an_unsampled_run_opens_nothing(): void
    {
        $opened = [];
        $capture = $this->capture(0.0, 200, $opened);
        $capture->jobStarted('2');
        $capture->jobDone('2', 'App\\Jobs\\Thing', 'default', true);
        $this->assertSame([], $opened, 'rate 0 must send the v1 payload it always sent');
    }

    public function test_the_hourly_cap_suppresses_runs_past_the_limit(): void
    {
        $opened = [];
        $capture = $this->capture(1.0, 2, $opened);
        for ($i = 0; $i < 5; $i++) {
            $capture->jobStarted((string) $i);
            $capture->jobDone((string) $i, 'App\\Jobs\\Thing', 'default', true);
        }
        $this->assertCount(2, $opened, 'the per-worker hourly cap bounds span profiling');
    }

    public function test_a_nested_run_opens_the_collector_once_at_the_root(): void
    {
        $opened = [];
        $capture = $this->capture(1.0, 200, $opened);
        $capture->commandStarted('app:thing');
        $capture->jobStarted('9');                 // dispatchSync inside the command
        $capture->jobDone('9', 'App\\Jobs\\Inner', 'sync', true);
        $capture->commandDone('app:thing', true);
        $this->assertCount(1, $opened, 'a nested run inherits the root run, it does not open a second collector');
    }
}
