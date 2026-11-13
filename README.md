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
| NIGHTPOST_JOB_SPAN_RATE | fraction of runs whose profile carries child spans (nightpost#4); 0 = off, default 0.002 |
| NIGHTPOST_JOB_SPAN_CAP | per-worker hourly cap on span-profiled runs; default 200 |
| NIGHTPOST_SERVICE | the service name every payload reports as (default: APP_NAME slug) |
| NIGHTPOST_RELEASE | release marker shipped with each session beacon |
| NIGHTPOST_SESSION_BEACON | session outcome beacon; needs a session driver, off by default |
| NIGHTPOST_SPANS | child spans (db, redis, outbound HTTP) on request profiles; off by default |
| SENTRY_LARAVEL_DSN | Sentry-SDK exception stream (stays key-less — the /api/1 bucket must stay unsigned) |
| PROFILER_ENDPOINT | xhprof extension endpoint; the key travels as the URL password |

One namespace (iniznet/fenrirealm-tracker#564): every variable resolves under
NIGHTPOST_* only. The MONITOR_* fallback was removed in 0.2.0, so rename the
env before you deploy this version — until then every sender stays OFF.

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

## Journeys (v2)

The v2 additions are optional fields on the routes above, so a payload that
sets none of them is still a valid v1 payload.

- **Identity.** Every payload carries `service` (NIGHTPOST_SERVICE, default the
  APP_NAME slug) and `env` (the running app environment).
- **traceparent.** A valid inbound `00-<32 hex>-<16 hex>-01` traceparent is
  adopted: the trace id is kept, this request gets its own span id, and the
  caller's span id becomes its parent. Missing or malformed is minted. The
  profile ships the context as `traceparent` + `span_id` and the same header
  is echoed on the HTTP response.
- **spans[]** (NIGHTPOST_SPANS): one aggregated span for database work, one for
  Redis when the connection was used, one per outbound HTTP client call with
  `peer_service` set to the target host. `start_ms` is an offset from the
  request start, never a wall clock.
- **Session beacon** (NIGHTPOST_SESSION_BEACON): POST
  /ingest/{project}/session with the framework session id, the route pattern,
  the release and the duration. A request without a session is skipped; a 5xx
  response reports `abnormal`.
- **Scheduled runs.** A scheduled task's /job row gains `schedule_cron`,
  `next_expected_at` (unix seconds, from the scheduler's own next-run
  calculation) and `grace_seconds` (the overlap window, 0 when unguarded).

## The wire is frozen

Every route this package POSTs (/ingest/{project}/profile|error|query|job|session,
/api/profiler/store, TCP 9913) is a compat-locked contract of WIRE_SPEC.md in
the monitor repo — additive-only.
