<?php

declare(strict_types=1);

namespace Nightpost\Laravel\Profiler;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use SpiralPackages\Profiler\Driver\NullDriver;
use SpiralPackages\Profiler\DriverFactory;
use SpiralPackages\Profiler\Profiler;
use SpiralPackages\Profiler\Storage\NullStorage;
use SpiralPackages\Profiler\Storage\WebStorage;
use Symfony\Component\HttpClient\CurlHttpClient;

/**
 * Drop-in replacement for OptionalProfilerFactory that tags enabled profiles
 * with a non-empty tags map.
 *
 * Why: spiral-packages/profiler defaults $tags to an empty PHP array and the
 * maantje wrapper never passes one, so json_encode() emits `"tags":[]` — a
 * JSON array. The profiler ingest rejects that payload (`tags` must be a JSON
 * object), so every profile POST is dropped. Merging a non-empty map through Profiler::$tags (end()
 * merges constructor tags with call-time tags) makes the emitted value a JSON
 * object, which the server accepts.
 */
final class TaggedProfilerFactory
{
    private const ENABLE_HEADER = 'X-Xhprof-Enabled';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Request $request,
    ) {}

    public function create(): Profiler
    {
        if (! $this->isEnabled()) {
            return new Profiler(
                new NullStorage,
                new NullDriver,
                appName: (string) $this->config->get('app.name'),
            );
        }

        return new Profiler(
            // CurlResponse's destructor waits for the transfer to finish, so
            // an unreachable monitor endpoint would otherwise wedge the worker
            // until max_execution_time fatals it. Bound the whole
            // fire-and-forget POST (fenrirealm-tracker#145), then drop — never
            // surface — transport failures so a dead monitor cannot flood the
            // production log channel with one error per profiled request.
            new FailSafeStorage(
                new WebStorage(
                    new CurlHttpClient([
                        'max_connect_duration' => 1.0,
                        'timeout' => 2.0,
                        'max_duration' => 3.0,
                    ]),
                    (string) $this->config->get('xhprof.endpoint'),
                ),
            ),
            DriverFactory::createXhrofDriver(),
            (string) $this->config->get('app.name'),
            $this->tags(),
        );
    }

    private function isEnabled(): bool
    {
        // The xhprof extension ships only in the production image. Without it
        // XhprofDriver's XHPROF_FLAGS_* constants do not exist, so any enabled
        // decision would fatal the request mid-construct (tracker#167). The
        // header stays intact on every host where profiling is possible; where
        // it is not, nothing can profile and the NullDriver path must win.
        if (! extension_loaded('xhprof')) {
            return false;
        }

        if ($this->request->hasHeader(self::ENABLE_HEADER)) {
            return filter_var($this->request->header(self::ENABLE_HEADER), FILTER_VALIDATE_BOOLEAN);
        }

        // Forced trace attribution (#180): the SvelteKit SSR leg already decided
        // to sample this request chain (X-Monitor-Profiled + minted trace id),
        // so the deep callgraph rides the same decision instead of an
        // independent 0.5% roll that almost never co-fires. Bounded by its own
        // per-minute cap (atomic add+increment per the counter invariant) —
        // over the cap falls through to the normal roll, never errors.
        if ($this->request->headers->get('X-Monitor-Profiled') === '1'
            && (string) $this->request->headers->get('X-Monitor-Trace-Id', '') !== '') {
            $cap = (int) $this->config->get('monitor.forced_deep_cap', 2);
            if ($cap > 0) {
                $minute = (int) floor(microtime(true) / 60);
                $key = "monitor:forced-deep:{$minute}";
                Cache::add($key, 0, 60);
                if ((int) Cache::increment($key) <= $cap) {
                    return true;
                }
            }
        }

        // Sampled code attribution (nightpost, #145): the profiler is
        // constructed per request (scoped), so the sampling decision lives here
        // — the package middleware is prepended and always calls start(), so a
        // header-setting middleware could never win. Header > forced-trace >
        // sample > blanket.
        $rate = (float) $this->config->get('monitor.profile_rate', 0);
        if ($rate > 0 && (mt_rand() / (float) mt_getrandmax()) < $rate) {
            return true;
        }

        return (bool) $this->config->get('xhprof.enabled');
    }

    /**
     * @return array<string, string>
     */
    private function tags(): array
    {
        $host = gethostname();

        $tags = [
            'env' => (string) $this->config->get('app.env', 'production'),
            'host' => $host === false ? 'unknown' : $host,
        ];

        // The callgraph lands on the SAME trace as the request profiles (#180):
        // the monitor reads tags["trace_id"] into profiles.trace_id.
        $traceId = mb_substr((string) $this->request->headers->get('X-Monitor-Trace-Id', ''), 0, 64);
        if ($traceId !== '') {
            $tags['trace_id'] = $traceId;
        }

        return $tags;
    }
}
