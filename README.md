# nightpost/laravel

Producer package for the Nightpost monitor (github.com/iniznet/nightpost):
one fire-and-forget client that ships request profiles, error reports, slow
queries, job/command telemetry and the monitor log channel from any Laravel
11/12 app. Nothing here can block or break a request — every sender is OFF
until the operator switches it on via env.

MIT licensed. The server it ships to is AGPL-3.0 (iniznet/nightpost).

## Install

composer require nightpost/laravel

The service provider auto-discovers. Configure the senders you want:

| Variable | Sender |
|---|---|
| NIGHTPOST_ENABLED (= true) | master switch for the event-based senders |
| NIGHTPOST_ENDPOINT | monitor ingest base URL |
| NIGHTPOST_PROJECT_KEY | the project name registered at the monitor's /keys page |
| NIGHTPOST_INGEST_KEY | per-project ingest key (X-Monitor-Key header, #198); empty = legacy unsigned |
| NIGHTPOST_SAMPLE_RATE | request timing sample rate (default 5%) |
| NIGHTPOST_PROFILE_RATE | deep xhprof callgraph rate (default 0.5%, needs the extension) |
| NIGHTPOST_SLOW_QUERY_MS | slow-query capture threshold; 0 (default) = off |
| NIGHTPOST_JOB_CAPTURE | job/command telemetry; off by default, zero overhead when off |
| NIGHTPOST_JOB_PROFILE_SECONDS | per-run trace enrolment threshold (#203); 0 = off |
| SENTRY_LARAVEL_DSN | Sentry-SDK exception stream (stays key-less — the /api/1 bucket must stay unsigned) |
| PROFILER_ENDPOINT | xhprof extension endpoint; the key travels as the URL password |

Dual-read (iniznet/fenrirealm-tracker#278 §3): every variable also resolves
under its legacy MONITOR_* name — NIGHTPOST_* wins, MONITOR_* keeps working,
so an existing install never needs a coordinated env cutover.

## Monitor log channel

Add the channel to config/logging.php and put it in LOG_STACK:

    'monitor' => [
        'driver' => 'custom',
        'via' => Nightpost\Laravel\Logging\MonitorLogFormatterFactory::class,
        'connectionString' => env('NIGHTPOST_LOG_SOCKET_URL', ''),
        'level' => env('NIGHTPOST_LOG_LEVEL', 'error'),
        'project_key' => env('NIGHTPOST_PROJECT_KEY', 'backend'),
        'environment' => env('APP_ENV', 'production'),
    ],

An empty connectionString resolves a sink-less logger: zero sockets, zero
connect attempts. Named channels ship themselves — LOG_STACK only governs the
default channel, so Log::channel('security') and friends need their own legs
built the same way.

## Error reports

Nightpost\Laravel\Monitor\MonitorClient::postError() ships a first-party
error report (non-Sentry capture paths). Resolve it from the container; the
Sentry SDK keeps handling unhandled exceptions.

## The wire is frozen

Every route this package POSTs (/ingest/{project}/profile|error|query|job,
/api/profiler/store, TCP 9913) is a compat-locked contract of WIRE_SPEC.md in
the monitor repo — additive-only.
