<?php

namespace NightpostTests\Monitor;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Nightpost\Laravel\Monitor\MonitorClient;
use Nightpost\Laravel\Monitor\SessionBeacon;
use Nightpost\Laravel\Monitor\SessionBeaconMiddleware;
use NightpostTests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The v2 session beacon (nightpost#9): the framework session id keys the row,
 * a request with no session is skipped, and a 5xx ends the session as
 * "abnormal" — crash-free is read from these rows, never computed here.
 */
class BeaconRecordingClient extends MonitorClient
{
    /** @var list<array<string, mixed>> */
    public array $sessions = [];

    public function __construct()
    {
        parent::__construct('http://127.0.0.1:1', 'backend', 1, '');
    }

    public function postSession(array $payload): void
    {
        $this->sessions[] = $payload;
    }
}

class SessionBeaconTest extends TestCase
{
    private function store(): Store
    {
        return new Store('nightpost', new ArraySessionHandler(120));
    }

    public function test_a_finished_session_is_reported_under_the_framework_session_id(): void
    {
        $client = new BeaconRecordingClient;
        $beacon = new SessionBeacon($client, '2026.10.10-b2c3d4');

        $beacon->record('abc123', '/chapters/{id}', 200, 8120.4);

        $this->assertCount(1, $client->sessions);
        $this->assertSame([
            'session_id' => 'abc123',
            'release' => '2026.10.10-b2c3d4',
            'status' => 'ok',
            'duration_ms' => 8120,
            'route' => '/chapters/{id}',
        ], $client->sessions[0]);
    }

    public function test_a_5xx_ends_the_session_as_abnormal(): void
    {
        $client = new BeaconRecordingClient;
        $beacon = new SessionBeacon($client);

        $beacon->record('abc123', '/checkout', 500, 120.0);

        $this->assertSame('abnormal', $client->sessions[0]['status']);
    }

    public function test_a_request_without_a_session_id_sends_nothing(): void
    {
        $client = new BeaconRecordingClient;
        $beacon = new SessionBeacon($client);

        $beacon->record('', '/chapters/{id}', 200, 10.0);

        $this->assertSame([], $client->sessions);
    }

    public function test_the_middleware_reports_the_route_pattern_never_the_url(): void
    {
        $client = new BeaconRecordingClient;
        $middleware = new SessionBeaconMiddleware(new SessionBeacon($client, 'rel-1'));
        $store = $this->store();

        $request = Request::create('/chapters/42', 'GET');
        $request->setLaravelSession($store);
        $request->setRouteResolver(static fn (): Route => new Route(['GET'], 'chapters/{id}', static fn () => null));

        $response = $middleware->handle($request, static fn (): Response => new Response('', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $client->sessions);
        $this->assertSame($store->getId(), $client->sessions[0]['session_id']);
        $this->assertSame('/chapters/{id}', $client->sessions[0]['route']);
        $this->assertStringNotContainsString('42', (string) $client->sessions[0]['route']);
        $this->assertSame('ok', $client->sessions[0]['status']);
        $this->assertSame('rel-1', $client->sessions[0]['release']);
    }

    public function test_the_middleware_marks_a_500_response_abnormal(): void
    {
        $client = new BeaconRecordingClient;
        $middleware = new SessionBeaconMiddleware(new SessionBeacon($client));

        $request = Request::create('/checkout', 'POST');
        $request->setLaravelSession($this->store());

        $middleware->handle($request, static fn (): Response => new Response('', 500));

        $this->assertSame('abnormal', $client->sessions[0]['status']);
    }

    public function test_the_middleware_skips_a_request_without_a_session(): void
    {
        $client = new BeaconRecordingClient;
        $middleware = new SessionBeaconMiddleware(new SessionBeacon($client));

        $middleware->handle(Request::create('/feed', 'GET'), static fn (): Response => new Response('', 200));

        $this->assertSame([], $client->sessions);
    }
}
