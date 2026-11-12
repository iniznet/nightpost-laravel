<?php

namespace Nightpost\Laravel\Monitor;

/**
 * The request's W3C trace context (nightpost#9 v2).
 *
 * A request that arrives with a valid `traceparent` already belongs to a
 * trace: the caller minted the trace id, and minting a second one here would
 * draw two waterfalls for one request. So a valid header is ADOPTED — its
 * trace id is kept, our own span id is fresh (this request's work is its own
 * hop), and the caller's span id becomes our parent. Anything else is minted
 * here, because salvaging a malformed header would silently group unrelated
 * requests under one id.
 *
 * The exact shape adopted is `00-<32 lower hex>-<16 lower hex>-01`: version
 * 00 and the sampled flag set. A future version, an unsampled trace or a
 * padded field is minted past rather than guessed at.
 *
 * Both the trace id and the span id travel to the monitor as `traceparent`
 * (and the span id as the payload's own `span_id`), and the same header is
 * echoed on the response so a browser or an upstream proxy can join.
 */
final class TraceContext
{
    private const PATTERN = '/^00-([0-9a-f]{32})-([0-9a-f]{16})-01$/';

    private string $traceId = '';

    private string $spanId = '';

    private string $parentSpanId = '';

    private bool $adopted = false;

    public function __construct()
    {
        $this->mint();
    }

    /**
     * Resolve the context to use for one request: adopt a valid inbound
     * header, mint otherwise.
     */
    public function begin(?string $inbound): void
    {
        $header = trim((string) $inbound);
        if (preg_match(self::PATTERN, $header, $matches) === 1
            && ! self::allZero($matches[1])
            && ! self::allZero($matches[2])) {
            $this->traceId = $matches[1];
            $this->spanId = self::mintSpanId();
            $this->parentSpanId = $matches[2];
            $this->adopted = true;

            return;
        }

        $this->mint();
    }

    public static function fromInbound(?string $inbound): self
    {
        $context = new self;
        $context->begin($inbound);

        return $context;
    }

    public function mint(): void
    {
        $this->traceId = self::mintTraceId();
        $this->spanId = self::mintSpanId();
        $this->parentSpanId = '';
        $this->adopted = false;
    }

    /** The header this producer propagates: version 00, our own span, sampled. */
    public function header(): string
    {
        return '00-'.$this->traceId.'-'.$this->spanId.'-01';
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function spanId(): string
    {
        return $this->spanId;
    }

    /** The caller's span id, '' when this context was minted here. */
    public function parentSpanId(): string
    {
        return $this->parentSpanId;
    }

    public function adopted(): bool
    {
        return $this->adopted;
    }

    /**
     * The payload fields that carry this context: `traceparent` for the
     * trace, `span_id` as the id of the payload's own (root) span.
     *
     * @return array{traceparent: string, span_id: string}
     */
    public function payload(): array
    {
        return [
            'traceparent' => $this->header(),
            'span_id' => $this->spanId,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function attach(array $payload): array
    {
        return array_merge($payload, $this->payload());
    }

    public static function mintTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function mintSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * An all-zero trace id is not a trace, and an all-zero parent id is OTLP's
     * "no parent": adopting either would bind unrelated requests to one bogus
     * waterfall.
     */
    private static function allZero(string $hex): bool
    {
        return trim($hex, '0') === '';
    }
}
