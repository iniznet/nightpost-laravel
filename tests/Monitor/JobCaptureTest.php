<?php

namespace NightpostTests\Monitor;

use Nightpost\Laravel\Monitor\JobCapture;
use Nightpost\Laravel\Monitor\MonitorClient;
use ArrayObject;
use NightpostTests\TestCase;

final class RecordingMonitorClient extends MonitorClient
{
    /** @var list<array<string, mixed>> */
    public array $jobs = [];

    /** @var list<array<string, mixed>> */
    public array $profiles = [];

    public function __construct()
    {
        parent::__construct('http://127.0.0.1:1', 'backend', 1, '');
    }

    public function postJob(array $payload): void
    {
        $this->jobs[] = $payload;
    }

    public function postProfile(array $payload): void
    {
        $this->profiles[] = $payload;
    }
}

class JobCaptureTest extends TestCase
{
    /**
     * profileSeconds defaults to 0.01 so "slow" means >10ms of wall time;
     * windowSeconds 0 flushes queue batches immediately; the three
     * closures replace the xhprof machinery so enable/disable balance,
     * discard decisions and cap exhaustion are all observable without
     * the extension (which is absent on every dev host).
     *
     * @return array{0: JobCapture, 1: RecordingMonitorClient, 2: ArrayObject}
     */
    private function capture(float $profileSeconds = 0.01, int $cap = 100): array
    {
        $client = new RecordingMonitorClient;
        $log = new ArrayObject(['start' => 0, 'stop' => 0, 'ships' => []]);
        $capture = new JobCapture(
            $client,
            0.0,
            0.0,
            $profileSeconds,
            $cap,
            startProfiling: function () use ($log): bool {
                $log['start']++;

                return true;
            },
            stopProfiling: function () use ($log): ?array {
                $log['stop']++;

                return ['main()==>slow():' => ['ct' => 1, 'wt' => 5000, 'cpu' => 4000, 'mu' => 0, 'pmu' => 0]];
            },
            shipCallgraph: function (array $data, array $tags) use ($log): void {
                $log['ships'][] = ['data' => $data, 'tags' => $tags];
            },
        );

        return [$capture, $client, $log];
    }

    public function test_slow_run_emits_leg_and_trace_rides_the_job_event(): void
    {
        [$capture, $client, $log] = $this->capture();
        $capture->commandStarted('schedule:run');
        usleep(20000);
        $capture->commandDone('schedule:run', true);

        $this->assertCount(1, $client->profiles);
        $leg = $client->profiles[0];
        $this->assertSame('command', $leg['type']);
        $this->assertSame('schedule:run', $leg['name']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $leg['trace_id']);
        $this->assertGreaterThan(0.0, $leg['duration_ms']);
        $this->assertSame($leg['trace_id'], $client->jobs[0]['trace']);
        $this->assertSame(1, $log['start']);
        $this->assertSame(1, $log['stop']);
        $this->assertCount(1, $log['ships']);
        $this->assertSame($leg['trace_id'], $log['ships'][0]['tags']['trace_id']);
    }

    public function test_fast_ok_run_emits_no_leg_and_discards_the_callgraph(): void
    {
        [$capture, $client, $log] = $this->capture(profileSeconds: 60.0);
        $capture->commandStarted('t');
        $capture->commandDone('t', true);

        $this->assertSame([], $client->profiles);
        $this->assertArrayNotHasKey('trace', $client->jobs[0]);
        $this->assertSame(1, $log['start']); // armed blind; slowness is only known at the end
        $this->assertSame(1, $log['stop']);
        $this->assertCount(0, $log['ships']); // fast + ok: data discarded, nothing shipped
    }

    public function test_failure_emits_leg_below_threshold_and_ships_tree(): void
    {
        [$capture, $client, $log] = $this->capture(profileSeconds: 60.0);
        $capture->commandStarted('t');
        $capture->commandDone('t', false);

        $this->assertCount(1, $client->profiles);
        $this->assertSame('0', $client->profiles[0]['spans'][0]['tags']['ok']);
        $this->assertCount(1, $log['ships']);
        $this->assertSame($client->profiles[0]['trace_id'], $client->jobs[0]['trace']);
    }

    public function test_two_runs_do_not_share_traces_and_profiler_stays_balanced(): void
    {
        [$capture, $client, $log] = $this->capture();
        $capture->commandStarted('a');
        usleep(20000);
        $capture->commandDone('a', true);
        $capture->commandStarted('b');
        usleep(20000);
        $capture->commandDone('b', true);

        $this->assertCount(2, $client->profiles);
        $this->assertNotSame($client->profiles[0]['trace_id'], $client->profiles[1]['trace_id']);
        $this->assertSame(2, $log['start']);
        $this->assertSame(2, $log['stop']);
    }

    public function test_nested_children_share_root_trace_and_arm_profiler_once(): void
    {
        [$capture, $client, $log] = $this->capture();
        $capture->commandStarted('schedule:run');
        $capture->commandStarted('chapters:publish');
        usleep(20000);
        $capture->commandDone('chapters:publish', true);
        $capture->commandDone('schedule:run', true);

        $this->assertSame(1, $log['start']); // only the root arms xhprof
        $this->assertSame(1, $log['stop']);
        $this->assertCount(2, $client->profiles);
        $this->assertSame($client->profiles[0]['trace_id'], $client->profiles[1]['trace_id']);
    }

    public function test_profiling_off_touches_no_xhprof_machinery(): void
    {
        [$capture, $client, $log] = $this->capture(profileSeconds: 0.0);
        $capture->commandStarted('a');
        usleep(20000);
        $capture->commandDone('a', true);

        $this->assertSame(0, $log['start']);
        $this->assertSame(0, $log['stop']);
        $this->assertSame([], $client->profiles);
        $this->assertArrayNotHasKey('trace', $client->jobs[0]);
    }

    public function test_cap_exhaustion_disables_profiling_but_keeps_legs(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        [$capture, $client, $log] = $this->capture(cap: 1);
        $capture->commandStarted('a');
        usleep(20000);
        $capture->commandDone('a', true);
        $capture->commandStarted('b');
        usleep(20000);
        $capture->commandDone('b', true);

        $this->assertSame(1, $log['start']); // second root found no cap slot
        $this->assertCount(2, $client->profiles); // legs are independent of the cap
        $this->assertNotSame($client->profiles[0]['trace_id'], $client->profiles[1]['trace_id']);
    }

    public function test_queue_job_leg_is_typed_job_and_batched_event_carries_trace(): void
    {
        [$capture, $client, $log] = $this->capture();
        $capture->jobStarted('j1');
        usleep(20000);
        $capture->jobDone('j1', 'App\\Jobs\\Slow', 'default', true);

        $this->assertCount(1, $client->profiles);
        $this->assertSame('job', $client->profiles[0]['type']);
        $this->assertSame($client->profiles[0]['trace_id'], $client->jobs[0]['trace']);
    }

    public function test_job_inside_command_shares_the_command_trace(): void
    {
        [$capture, $client, $log] = $this->capture();
        $capture->commandStarted('outer');
        $capture->jobStarted('j1');
        usleep(20000);
        $capture->jobDone('j1', 'Slow', 'q', true);
        $capture->commandDone('outer', true);

        $this->assertSame(1, $log['start']);
        $this->assertCount(2, $client->profiles);
        $this->assertSame($client->profiles[0]['trace_id'], $client->profiles[1]['trace_id']);
    }
}
