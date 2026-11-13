<?php

namespace Nightpost\Laravel\Monitor;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the trace context for one HTTP request and echoes it back
 * (nightpost#9 v2): the inbound `traceparent` is read once, a valid one is
 * adopted and anything else is minted, and the response carries the context
 * this request actually used. A caller can therefore join the trace without
 * polling the monitor.
 *
 * The child-span collector is opened here too, so every span's `start_ms`
 * is an offset from the start of the request that produced it — never a wall
 * clock two hosts would disagree about.
 */
final class TraceContextMiddleware
{
    public function __construct(
        private readonly TraceContext $trace,
        private readonly SpanCollector $spans,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->trace->begin($request->headers->get('traceparent'));
        $this->spans->begin();

        $response = $next($request);
        $response->headers->set('traceparent', $this->trace->header());

        return $response;
    }
}
