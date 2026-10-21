<?php

namespace Nightpost\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nightpost\Laravel\Monitor\JobCapture;
use Nightpost\Laravel\Monitor\MonitorClient;
use Nightpost\Laravel\Monitor\ProfileSender;
use Nightpost\Laravel\Monitor\SlowQueryCapture;
use SpiralPackages\Profiler\Profiler;

/**
 * Registers the Nightpost producer bindings and event listeners (ported from
 * the fenrirealm-backend AppServiceProvider, iniznet/fenrirealm-tracker#278 §3).
 *
 * Every sender stays OFF until the operator switches it on via env: with
 * monitor.enabled=false no listener is subscribed at all (zero per-job
 * overhead), and nothing here can ship telemetry on its own.
 */
class NightpostMonitorServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitor.php', 'monitor');

        // Replace the profiler package's scoped Profiler binding: enabled
        // profiles must carry a non-empty tags map or the monitor's profiler
        // ingest drops them (see TaggedProfilerFactory for the payload contract).
        $this->app->scoped(Profiler::class, function (\Illuminate\Foundation\Application $app) {
            return (new \Nightpost\Laravel\Profiler\TaggedProfilerFactory($app['config'], $app['request']))->create();
        });

        // Monitor profile sender: resolve endpoint + project key once per
        // worker (singleton), never per request.
        $this->app->singleton(MonitorClient::class, function (\Illuminate\Foundation\Application $app) {
            $config = $app['config']['monitor'] ?? [];

            return new MonitorClient(
                $config['endpoint'] ?? 'http://127.0.0.1:8000',
                $config['project_key'] ?? 'backend',
                300,
                (string) ($config['ingest_key'] ?? ''),
            );
        });
        // Interface alias kept for every existing ProfileSender consumer
        // (expand/contract, #157).
        $this->app->alias(MonitorClient::class, ProfileSender::class);

        $this->app->singleton(SlowQueryCapture::class, function (\Illuminate\Foundation\Application $app) {
            $config = $app['config']['monitor'] ?? [];

            return new SlowQueryCapture(
                $app->make(MonitorClient::class),
                (float) ($config['slow_query_ms'] ?? 0),
                (string) config('app.env', 'production'),
            );
        });

        $this->app->singleton(JobCapture::class, function (\Illuminate\Foundation\Application $app) {
            $config = $app['config']['monitor'] ?? [];

            return new JobCapture(
                $app->make(MonitorClient::class),
                (float) ($config['job_window_seconds'] ?? 60),
                (float) ($config['job_min_seconds'] ?? 0),
                (float) ($config['job_profile_seconds'] ?? 0),
                (int) ($config['job_profile_cap'] ?? 2),
            );
        });
    }

    public function boot(): void
    {
        $this->registerSlowQueryCapture();
        $this->registerJobCapture();
    }

    /**
     * Register the #157 slow-query listener. DB::listen fires per query; the
     * whenQueryingForLongerThan hook is NOT usable here: it latches after one
     * fire, compares CUMULATIVE duration, and calls the handler as
     * ($connection, $event).
     */
    public function registerSlowQueryCapture(): void
    {
        $slowQueryMs = (int) config('monitor.slow_query_ms', 0);
        if (! config('monitor.enabled') || $slowQueryMs <= 0) {
            return;
        }

        DB::listen(function (QueryExecuted $event) use ($slowQueryMs): void {
            if ($event->time < $slowQueryMs) {
                return;
            }
            app(SlowQueryCapture::class)->handle($event);
        });
    }

    /**
     * Register the #158 job/command telemetry listeners. OFF means NO listener
     * is subscribed at all, zero per-job overhead until job_capture=true.
     * Commands flush per run because schedule:run is a short-lived process;
     * queue successes batch per (kind|name) over a window, and WorkerStopping
     * flushes the tail.
     */
    public function registerJobCapture(): void
    {
        if (! config('monitor.enabled') || ! config('monitor.job_capture')) {
            return;
        }

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            app(JobCapture::class)->jobStarted((string) $event->job->getJobId());
        });
        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            app(JobCapture::class)->jobDone(
                (string) $event->job->getJobId(),
                $event->job->resolveName(),
                (string) ($event->job->getQueue() ?: $event->job->getConnectionName()),
                true,
            );
        });
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            app(JobCapture::class)->jobDone(
                (string) $event->job->getJobId(),
                $event->job->resolveName(),
                (string) ($event->job->getQueue() ?: $event->job->getConnectionName()),
                false,
            );
        });
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            app(JobCapture::class)->commandStarted((string) $event->command);
        });
        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            app(JobCapture::class)->commandDone((string) $event->command, $event->exitCode === null || $event->exitCode === 0);
        });
        Event::listen(WorkerStopping::class, function (): void {
            app(JobCapture::class)->flushAll();
        });
    }
}
