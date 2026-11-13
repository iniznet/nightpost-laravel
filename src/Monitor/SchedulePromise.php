<?php

namespace Nightpost\Laravel\Monitor;

use Illuminate\Console\Scheduling\Event;

/**
 * The promise a scheduled run makes (nightpost#9 v2): the cron expression it
 * runs on, the next time it is expected, and how long past that time the
 * monitor should wait before calling it missed.
 *
 * The next expected time comes from the scheduler's OWN next-run calculation
 * (`Event::nextRunDate()`), never from a cron parser written here: the
 * expression already means something to the framework (timezone, filters,
 * sub-minute repeats), and a second interpretation would disagree with the
 * run it describes.
 */
final class SchedulePromise
{
    public function __construct(
        private readonly string $name,
        private readonly string $cron,
        private readonly int $nextExpectedAt,
        private readonly int $graceSeconds,
    ) {}

    public static function fromScheduledEvent(Event $task): self
    {
        return new self(
            self::nameFor($task),
            trim((string) $task->getExpression()),
            self::nextRunOf($task),
            self::graceOf($task),
        );
    }

    /** The job name the run is reported under. */
    public function name(): string
    {
        return $this->name;
    }

    public function cron(): string
    {
        return $this->cron;
    }

    /** Unix seconds; 0 when the expression cannot answer. */
    public function nextExpectedAt(): int
    {
        return $this->nextExpectedAt;
    }

    public function graceSeconds(): int
    {
        return $this->graceSeconds;
    }

    /**
     * The artisan signature when the task runs an artisan command, because
     * that is the name the run's own telemetry carries and the two rows must
     * agree; a closure/callback task has no signature, so its name is used.
     */
    private static function nameFor(Event $task): string
    {
        if (preg_match('/\bartisan\b[\'"]?\s+[\'"]?([^\s\'"]+)/', (string) $task->command, $matches) === 1) {
            return $matches[1];
        }

        $description = trim((string) ($task->description ?? ''));
        if ($description !== '') {
            return $description;
        }

        return trim((string) $task->command);
    }

    private static function nextRunOf(Event $task): int
    {
        try {
            return (int) $task->nextRunDate()->getTimestamp();
        } catch (\Throwable) {
            // An expression the scheduler itself cannot place (a disabled or
            // malformed task) reports no expectation rather than a guess.
            return 0;
        }
    }

    /**
     * The overlap window is the only grace the framework itself knows: when a
     * task holds a mutex for N minutes, a run that does not start within that
     * window is late. Without an overlap guard there is no grace at all.
     */
    private static function graceOf(Event $task): int
    {
        if (! $task->withoutOverlapping) {
            return 0;
        }

        return max(0, (int) $task->expiresAt) * 60;
    }
}
