<?php

namespace NightpostTests\Monitor;

use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Nightpost\Laravel\Monitor\SpanCollector;
use Nightpost\Laravel\Monitor\TraceContext;
use NightpostTests\TestCase;

/**
 * Child spans on the profile payload (nightpost#9 v2): database and Redis work
 * are one aggregated hop each, every outbound call is its own client span
 * named by the host it reached, and every offset is RELATIVE to the request.
 */
class SpanCollectorTest extends TestCase
{
    private const INBOUND = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    private float $now = 1000.0;

    private TraceContext $trace;

    private SpanCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trace = TraceContext::fromInbound(self::INBOUND);
        $this->collector = new SpanCollector($this->trace, fn (): float => $this->now);
    }

    public function test_an_unopened_collector_attaches_nothing(): void
    {
        $profile = ['name' => 'GET /chapters/1', 'duration_ms' => 12.5];

        $this->assertFalse($this->collector->active());
        $this->assertSame([], $this->collector->spans());
        $this->assertSame($profile, $this->collector->attach($profile));
    }

    public function test_database_work_is_one_aggregated_span(): void
    {
        $this->collector->begin();
        $this->now = 1000.2;
        $connection = $this->createMock(Connection::class);
        $connection->method('getName')->willReturn('mysql');
        $this->collector->query(new QueryExecuted('select * from chapters where id = ?', [1], 10.0, $connection));
        $this->now = 1000.6;
        $this->collector->recordDatabase(5.0);

        $spans = $this->collector->spans();

        $this->assertCount(1, $spans);
        $this->assertSame('db', $spans[0]['kind']);
        $this->assertSame('db: 2 queries', $spans[0]['name']);
        $this->assertEqualsWithDelta(15.0, $spans[0]['duration_ms'], 0.001);
        $this->assertSame('ok', $spans[0]['status']);
        $this->assertSame($this->trace->spanId(), $spans[0]['parent_span_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $spans[0]['span_id']);
    }

    public function test_a_single_query_is_named_in_the_singular(): void
    {
        $this->collector->begin();
        $this->now = 1000.1;
        $this->collector->recordDatabase(3.0);

        $this->assertSame('db: 1 query', $this->collector->spans()[0]['name']);
    }

    public function test_redis_is_spanned_only_when_the_connection_is_used(): void
    {
        $this->collector->begin();
        $this->assertSame([], $this->collector->spans());

        $this->now = 1000.4;
        $this->collector->recordRedis(2.0);

        $spans = $this->collector->spans();
        $this->assertCount(1, $spans);
        $this->assertSame('redis', $spans[0]['kind']);
        $this->assertSame('redis: 1 command', $spans[0]['name']);
        $this->assertEqualsWithDelta(2.0, $spans[0]['duration_ms'], 0.001);
    }

    public function test_an_outbound_call_is_a_client_span_named_by_peer_host(): void
    {
        $this->collector->begin();
        $request = new ClientRequest(new PsrRequest('GET', 'https://api.example.com/v1/items'));

        $this->now = 1000.1;
        $this->collector->sending(new RequestSending($request));
        $this->now = 1000.3;
        $this->collector->received(new ResponseReceived($request, new ClientResponse(new PsrResponse(200))));

        $spans = $this->collector->spans();

        $this->assertCount(1, $spans);
        $this->assertSame('client', $spans[0]['kind']);
        $this->assertSame('GET api.example.com', $spans[0]['name']);
        $this->assertSame('api.example.com', $spans[0]['peer_service']);
        $this->assertEqualsWithDelta(100.0, $spans[0]['start_ms'], 0.001);
        $this->assertEqualsWithDelta(200.0, $spans[0]['duration_ms'], 0.001);
        $this->assertSame('ok', $spans[0]['status']);
    }

    public function test_a_5xx_outbound_call_and_a_dead_connection_are_error_spans(): void
    {
        $this->collector->begin();
        $bad = new ClientRequest(new PsrRequest('POST', 'https://payments.example.com/charge'));
        $dead = new ClientRequest(new PsrRequest('GET', 'https://mail.example.com/ping'));

        $this->now = 1000.1;
        $this->collector->sending(new RequestSending($bad));
        $this->now = 1000.2;
        $this->collector->sending(new RequestSending($dead));
        $this->now = 1000.3;
        $this->collector->received(new ResponseReceived($bad, new ClientResponse(new PsrResponse(502))));
        $this->now = 1000.4;
        $this->collector->failed(new ConnectionFailed($dead, new ConnectionException('refused')));

        $spans = $this->collector->spans();

        $this->assertSame('error', $spans[0]['status']);
        $this->assertSame('error', $spans[1]['status']);
        $this->assertSame('mail.example.com', $spans[1]['peer_service']);
    }

    public function test_attach_appends_children_to_the_profile_and_never_rewrites_v1_fields(): void
    {
        $this->collector->begin();
        $this->now = 1000.5;
        $this->collector->recordDatabase(4.0);

        $profile = $this->collector->attach([
            'name' => 'GET /chapters/1',
            'duration_ms' => 12.5,
            'environment' => 'production',
            'spans' => [['name' => 'request', 'duration_ms' => 12.5]],
        ]);

        $this->assertSame('GET /chapters/1', $profile['name']);
        $this->assertSame(12.5, $profile['duration_ms']);
        $this->assertSame('production', $profile['environment']);
        $this->assertCount(2, $profile['spans']);
        $this->assertSame('request', $profile['spans'][0]['name']);
        $this->assertSame('db', $profile['spans'][1]['kind']);
        $this->assertSame($this->trace->header(), $profile['traceparent']);
        $this->assertSame($this->trace->spanId(), $profile['span_id']);
    }

    public function test_spans_carry_relative_offsets_and_no_wall_clock(): void
    {
        $this->collector->begin();
        $this->now = 1000.2;
        $this->collector->recordDatabase(10.0);
        $this->now = 1000.3;
        $this->collector->recordRedis(1.0);

        foreach ($this->collector->spans() as $span) {
            $this->assertSame(
                ['span_id', 'parent_span_id', 'kind', 'name', 'start_ms', 'duration_ms', 'status'],
                array_keys($span),
            );
            $this->assertGreaterThanOrEqual(0.0, $span['start_ms']);
            $this->assertLessThan(1000.0, $span['start_ms']);
        }
    }
}
