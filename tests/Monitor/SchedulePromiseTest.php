<?php

namespace NightpostTests\Monitor;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Nightpost\Laravel\Monitor\JobCapture;
use Nightpost\Laravel\Monitor\MonitorClient;
use Nightpost\Laravel\Monitor\SchedulePromise;
use NightpostTests\TestCase;

class ScheduleRecordingClient extends MonitorClient
{
    /** @var list<array<string, mixed>> */
    public array $jobs = [];

    public function __construct()
    {
        parent::__construct('http://127.0.0.1:1', 'backend', 1, '');
    }

    public function postJob(array $payload): void
    {
        $this->jobs[] = $payload;
    }

    public function postProfile(array $payload): void {}
}

/**
 * Scheduled runs carry the promise they made (nightpost#9 v2): the cron
 * expression, the next expected fire time from the scheduler's own next-run
 * calculation, and the grace window. No cron parser lives in this package.
 */
class SchedulePromiseTest extends TestCase
{
    private function event(string $command): Event
    {
        $mutex = new class implements EventMutex
        {
            public function create(Event $event)
            {
                return true;
            }

            public function exists(Event $event)
            {
                return false;
            }

            public function forget(Event $event) {}
        };

        return new Event($mutex, $command);
    }

    public function test_the_promise_reads_the_schedulers_own_expression_and_next_run(): void
    {
        $task = $this->event("'/usr/bin/php' 'artisan' quests:reset-stale-progress");
        $task->hourly();

        $promise = SchedulePromise::fromScheduledEvent($task);

        $this->assertSame('quests:reset-stale-progress', $promise->name());
        $this->assertSame('0 * * * *', $promise->cron());
        $this->assertSame($task->getExpression(), $promise->cron());
        $this->assertSame(
            (int) $task->nextRunDate()->getTimestamp(),
            $promise->nextExpectedAt(),
        );
        $this->assertGreaterThan(time(), $promise->nextExpectedAt());
        $this->assertSame(0, $promise->graceSeconds());
    }

    public function test_the_overlap_window_becomes_the_grace_seconds(): void
    {
        $task = $this->event("'artisan' cache:prune");
        $task->hourly()->withoutOverlapping(10);

        $promise = SchedulePromise::fromScheduledEvent($task);

        $this->assertSame(600, $promise->graceSeconds());
    }

    public function test_a_closure_task_is_named_by_its_description(): void
    {
        $task = $this->event('some-binary --flag');
        $task->description = 'nightly report';

        $this->assertSame('nightly report', SchedulePromise::fromScheduledEvent($task)->name());
    }

    public function test_a_scheduled_job_row_carries_the_promise(): void
    {
        $client = new ScheduleRecordingClient;
        $capture = new JobCapture($client, 0.0, 0.0, 0.0);

        $capture->scheduledStarted(new SchedulePromise('quests:reset-stale-progress', '0 * * * *', 1790000000, 300));
        $capture->scheduledDone('quests:reset-stale-progress', 1.5, true);

        $this->assertCount(1, $client->jobs);
        $this->assertSame('quests:reset-stale-progress', $client->jobs[0]['name']);
        $this->assertSame('command', $client->jobs[0]['kind']);
        $this->assertSame('0 * * * *', $client->jobs[0]['schedule_cron']);
        $this->assertSame(1790000000, $client->jobs[0]['next_expected_at']);
        $this->assertSame(300, $client->jobs[0]['grace_seconds']);
    }

    public function test_a_failed_scheduled_run_keeps_the_promise_and_flips_ok(): void
    {
        $client = new ScheduleRecordingClient;
        $capture = new JobCapture($client, 0.0, 0.0, 0.0);

        $capture->scheduledStarted(new SchedulePromise('quests:reset-stale-progress', '0 * * * *', 1790000000, 300));
        $capture->scheduledDone('quests:reset-stale-progress', 0.0, false);

        $this->assertFalse($client->jobs[0]['ok']);
        $this->assertSame('0 * * * *', $client->jobs[0]['schedule_cron']);
    }

    public function test_a_plain_command_row_stays_the_v1_shape(): void
    {
        $client = new ScheduleRecordingClient;
        $capture = new JobCapture($client, 0.0, 0.0, 0.0);

        $capture->commandStarted('cache:prune');
        $capture->commandDone('cache:prune', true);

        $this->assertCount(1, $client->jobs);
        $this->assertArrayNotHasKey('schedule_cron', $client->jobs[0]);
        $this->assertArrayNotHasKey('next_expected_at', $client->jobs[0]);
        $this->assertArrayNotHasKey('grace_seconds', $client->jobs[0]);
    }
}
