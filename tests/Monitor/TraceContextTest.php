<?php

namespace NightpostTests\Monitor;

use Illuminate\Http\Request;
use Nightpost\Laravel\Monitor\SpanCollector;
use Nightpost\Laravel\Monitor\TraceContext;
use Nightpost\Laravel\Monitor\TraceContextMiddleware;
use NightpostTests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * The traceparent lifecycle (nightpost#9 v2): exactly `00-<32 hex>-<16 hex>-01`
 * is adopted, anything else is minted, and the context this request used is
 * echoed on the response so a caller can join the trace without polling.
 */
class TraceContextTest extends TestCase
{
    private const INBOUND = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    public function test_a_valid_inbound_traceparent_is_adopted(): void
    {
        $context = TraceContext::fromInbound(self::INBOUND);

        $this->assertTrue($context->adopted());
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $context->traceId());
        // The caller's span is our PARENT; our own hop gets a fresh id.
        $this->assertSame('00f067aa0ba902b7', $context->parentSpanId());
        $this->assertNotSame('00f067aa0ba902b7', $context->spanId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $context->spanId());
        $this->assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-'.$context->spanId().'-01', $context->header());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformed(): array
    {
        return [
            'empty' => [''],
            'future version' => ['01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
            'unsampled' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00'],
            'uppercase' => ['00-4BF92F3577B34DA6A3CE929D0E0E4736-00F067AA0BA902B7-01'],
            'short trace id' => ['00-4bf92f3577b34da6a3ce929d0e0e47-00f067aa0ba902b7-01'],
            'trailing field' => [self::INBOUND.'-extra'],
            'all-zero trace' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
            'all-zero parent' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'],
        ];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_traceparent_is_minted_past(string $inbound): void
    {
        $context = TraceContext::fromInbound($inbound);

        $this->assertFalse($context->adopted());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $context->traceId());
        $this->assertSame('', $context->parentSpanId());
        $this->assertSame('00-'.$context->traceId().'-'.$context->spanId().'-01', $context->header());
    }

    public function test_the_payload_carries_the_traceparent_and_its_own_span_id(): void
    {
        $context = TraceContext::fromInbound(self::INBOUND);

        $payload = $context->attach(['name' => 'GET /chapters/1']);

        $this->assertSame('GET /chapters/1', $payload['name']);
        $this->assertSame($context->header(), $payload['traceparent']);
        $this->assertSame($context->spanId(), $payload['span_id']);
    }

    public function test_the_middleware_echoes_the_context_on_the_response(): void
    {
        $trace = new TraceContext;
        $middleware = new TraceContextMiddleware($trace, new SpanCollector($trace, static fn (): float => 1000.0));

        $request = Request::create('/', 'GET', [], [], [], ['HTTP_TRACEPARENT' => self::INBOUND]);
        $response = $middleware->handle($request, static fn (): Response => new Response('', 200));

        $this->assertTrue($trace->adopted());
        $this->assertSame($trace->header(), $response->headers->get('traceparent'));
        $this->assertStringStartsWith('00-4bf92f3577b34da6a3ce929d0e0e4736-', (string) $response->headers->get('traceparent'));
        $this->assertStringEndsWith('-01', (string) $response->headers->get('traceparent'));
    }

    public function test_the_middleware_mints_when_no_header_arrives(): void
    {
        $trace = new TraceContext;
        $middleware = new TraceContextMiddleware($trace, new SpanCollector($trace));

        $request = Request::create('/', 'GET');
        $response = $middleware->handle($request, static fn (): Response => new Response('', 200));

        $this->assertFalse($trace->adopted());
        $this->assertSame($trace->header(), $response->headers->get('traceparent'));
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $trace->header());
    }
}
