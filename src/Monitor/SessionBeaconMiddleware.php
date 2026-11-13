<?php

namespace Nightpost\Laravel\Monitor;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ships one session outcome per request (nightpost#9 v2), after the response
 * is built so the status is the one the client receives.
 *
 * The route is the PATTERN the router matched (`/chapters/{id}`), never the
 * URL: a concrete path carries ids, and the monitor only needs the shape to
 * group sessions by screen.
 */
final class SessionBeaconMiddleware
{
    public function __construct(private readonly SessionBeacon $beacon) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $response = $next($request);

        try {
            if ($request->hasSession()) {
                $this->beacon->record(
                    (string) $request->session()->getId(),
                    self::routePattern($request),
                    (int) $response->getStatusCode(),
                    (microtime(true) - $startedAt) * 1000,
                );
            }
        } catch (\Throwable) {
            // Telemetry must never break a response that already exists.
        }

        return $response;
    }

    private static function routePattern(Request $request): string
    {
        $uri = trim((string) ($request->route()?->uri() ?? ''));
        if ($uri === '') {
            return '';
        }

        return '/'.ltrim($uri, '/');
    }
}
