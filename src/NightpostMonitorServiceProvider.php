<?php

namespace Nightpost\Laravel;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nightpost\Laravel\Monitor\JobCapture;
use Nightpost\Laravel\Monitor\MonitorClient;
use Nightpost\Laravel\Monitor\ProfileSender;
use Nightpost\Laravel\Monitor\SchedulePromise;
use Nightpost\Laravel\Monitor\ServiceIdentity;
use Nightpost\Laravel\Monitor\SessionBeacon;
use Nightpost\Laravel\Monitor\SessionBeaconMiddleware;
use Nightpost\Laravel\Monitor\SlowQueryCapture;
use Nightpost\Laravel\Monitor\SpanCollector;
use Nightpost\Laravel\Monitor\TraceContext;
use Nightpost\Laravel\Monitor\TraceContextMiddleware;
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

        // Producer identity (v2): one service name per deployment, defaulting
        // to the app name slug, plus the running environment.
        $this->app->singleton(ServiceIdentity::class, function (\Illuminate\Foundation\Application $app) {
            return ServiceIdentity::fromConfig(
                (array) ($app['config']['monitor'] ?? []),
                (string) $app['config']->get('app.name', ''),
                $app->environment(),
            );
        });

        // Trace context and child spans are per request/per job, never shared
        // across an Octane worker's requests.
        $this->app->scoped(TraceContext::class, static fn (): TraceContext => new TraceContext);
        $this->app->scoped(SpanCollector::class, static function (\Illuminate\Foundation\Application $app): SpanCollector {
            return new SpanCollector($app->make(TraceContext::class));
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
                $app->make(ServiceIdentity::class),
                // Resolved lazily: the collector is scoped, the client is a
                // singleton, and a captured instance would leak one request's
                // spans into the next.
                static fn (array $payload): array => $app->make(SpanCollector::class)->attach($payload),
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

        $this->app->singleton(SessionBeacon::class, function (\Illuminate\Foundation\Application $app) {
            $config = $app['config']['monitor'] ?? [];

            return new SessionBeacon(
                $app->make(MonitorClient::class),
                (string) ($config['release'] ?? ''),
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
                // Span sampling (nightpost#4): the collector is scoped, so it
                // must be resolved at run start, never captured here — a
                // captured instance would belong to whichever job ran first.
                spanRate: (float) ($config['job_span_rate'] ?? 0),
                beginSpans: static function (string $trace) use ($app): void {
                    $collector = $app->make(SpanCollector::class);
                    if (! $collector->active()) {
                        $collector->begin();
                    }
                },
                spanCap: (int) ($config['job_span_cap'] ?? 200),
            );
        });
    }

    public function boot(): void
    {
        $this->registerSlowQueryCapture();
        $this->registerJobCapture();
        $this->registerSpanCapture();
        $this->registerHttpMiddleware();
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

        // Scheduled runs (v2, nightpost#9): the scheduler's events are the only
        // place that holds the expression a run belongs to, so the promise is
        // read here and attached to the run's /job row — next run included.
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            app(JobCapture::class)->scheduledStarted(SchedulePromise::fromScheduledEvent($event->task));
        });
        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            app(JobCapture::class)->scheduledDone(
                SchedulePromise::fromScheduledEvent($event->task)->name(),
                (float) $event->runtime,
                true,
            );
        });
        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            app(JobCapture::class)->scheduledDone(
                SchedulePromise::fromScheduledEvent($event->task)->name(),
                0.0,
                false,
            );
        });
    }

    /**
     * Register the v2 child-span capture (nightpost#9). OFF by default: these
     * listeners fire on EVERY query and every outbound call, so an install that
     * does not want span waterfalls must not pay for them.
     */
    public function registerSpanCapture(): void
    {
        if (! config('monitor.enabled') || ! config('monitor.spans')) {
            return;
        }

        DB::listen(function (QueryExecuted $event): void {
            app(SpanCollector::class)->query($event);
        });
        Event::listen(CommandExecuted::class, function (CommandExecuted $event): void {
            app(SpanCollector::class)->redis($event);
        });
        Event::listen(RequestSending::class, function (RequestSending $event): void {
            app(SpanCollector::class)->sending($event);
        });
        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            app(SpanCollector::class)->received($event);
        });
        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            app(SpanCollector::class)->failed($event);
        });
    }

    /**
     * Register the v2 HTTP middleware (nightpost#9): the traceparent lifecycle
     * runs first so every later middleware inherits the context, and the
     * session beacon runs last so it reports the status the client received.
     */
    public function registerHttpMiddleware(): void
    {
        if (! config('monitor.enabled') || ! $this->app->bound(\Illuminate\Contracts\Http\Kernel::class)) {
            return;
        }

        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        if (! method_exists($kernel, 'prependMiddleware')) {
            return;
        }

        $kernel->prependMiddleware(TraceContextMiddleware::class);
        if (config('monitor.session_beacon')) {
            $kernel->pushMiddleware(SessionBeaconMiddleware::class);
        }
    }
}
