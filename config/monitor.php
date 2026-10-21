<?php

// Nightpost monitor client settings.
// Everything defaults to OFF — the monitor is opt-in per environment via env.
//
// Env names are dual-read (iniznet/fenrirealm-tracker#278 §3): the product
// namespace NIGHTPOST_* wins, the original MONITOR_* leg keeps working, so an
// existing install never needs a coordinated env cutover when it adopts this
// package.
$env2 = function (string $name, $default) {
    return env('NIGHTPOST_' . $name, env('MONITOR_' . $name, $default));
};

return [
    'enabled' => (bool) $env2('ENABLED', false),
    'endpoint' => $env2('ENDPOINT', 'http://127.0.0.1:8000'),
    'project_key' => $env2('PROJECT_KEY', 'backend'),
    // Per-project ingest key issued at the monitor's /keys page (#198).
    // Empty = legacy unsigned admission (fine while the project has no key).
    'ingest_key' => $env2('INGEST_KEY', ''),
    // Timing sample rate (request profiles). Deep code attribution (xhprof)
    // has its own, much lower rate: monitor.profile_rate is consumed by
    // Nightpost\Laravel\Profiler\TaggedProfilerFactory::isEnabled().
    'sample_rate' => (float) $env2('SAMPLE_RATE', 0.05),
    'profile_rate' => (float) $env2('PROFILE_RATE', 0.005),
    // Trace headers from a sampling upstream force a sampled profile so the
    // backend leg of a cross-service trace always exists (#171). The forced
    // request profile is a timing + fire-and-forget POST — cheap enough to
    // keep traces complete (30/min default). Deep xhprof attribution rides
    // the SAME decision (#180) under its own, much tighter cap because the
    // payloads are the expensive kind; the profile rate stays the
    // independent background roll.
    'forced_sample_cap' => (int) $env2('FORCED_SAMPLE_CAP', 30),
    'forced_deep_cap' => (int) $env2('FORCED_DEEP_CAP', 2),
    // Slow-query capture (#157): queries at or above this many ms POST to
    // /ingest/{project}/query. 0 (default) = OFF: no listener is registered.
    'slow_query_ms' => (int) $env2('SLOW_QUERY_MS', 0),
    // Job/command telemetry (#158): OFF by default — when false, no queue
    // or console listener is registered at all (zero per-job overhead).
    'job_capture' => (bool) $env2('JOB_CAPTURE', false),
    'job_min_seconds' => (float) $env2('JOB_MIN_SECONDS', 0),
    // Per-run trace enrolment (#203): 0 = off (default). When > 0, runs at or
    // above the threshold - and every failure - emit a timing profile leg
    // under a fresh per-run trace id, and the /jobs row links to that trace.
    'job_profile_seconds' => (float) $env2('JOB_PROFILE_SECONDS', 0),
    'job_profile_cap' => (int) $env2('JOB_PROFILE_CAP', 2),
    'job_window_seconds' => (float) $env2('JOB_WINDOW_SECONDS', 60),
];
