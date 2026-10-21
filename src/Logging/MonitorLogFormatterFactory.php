<?php

namespace Nightpost\Laravel\Logging;

use Monolog\Handler\NullHandler;
use Monolog\Handler\SocketHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Builds the non-default "monitor" Monolog channel (nightpost,
 * iniznet/fenrirealm-tracker#145 — renamed from the channel it replaces; the
 * wire format is identical newline-JSON over TCP 9913).
 *
 * SocketHandler throws on connect failure (verified: connection refused/timeout
 * propagates out of connect()), which would wedge the request path whenever the
 * monitor is down — the exact failure mode the observability tickets forbid
 * (#135, #137). WhatFailureGroupHandler swallows any handler exception so log
 * shipping can never stall a request.
 *
 * The formatter (not a processor) stamps project_key: Monolog 3 records accept
 * only 'extra' through ArrayAccess and JsonFormatter nests extra, so the
 * monitor's top-level project_key/environment can only come from the formatter
 * (iniznet/fenrirealm-tracker#164).
 *
 * An empty connectionString builds a sink-less logger instead of a socket
 * (#178): named-channel stacks must be provably inert on a machine without a
 * monitor, where a localhost default would silently dial a dead port from every
 * worker. The monitor itself fails safe (429/shed), never the request path.
 */
class MonitorLogFormatterFactory
{
    /**
     * Context keys the security stream may ship (iniznet/fenrirealm-tracker#178).
     *
     * This is the reader-protection stream, so the list is what leaves the
     * container — anything else stays in security.log. `exception` is excluded on
     * purpose: the file writes its full stack trace while the JSON leg would
     * flatten it to {class,message,code,file}, and exception payloads already
     * reach the monitor through the Sentry sender. An unlisted key is never
     * silently dropped: the shipped line names it in context._dropped_keys, which
     * is what makes a new field added at a writer visible instead of lost (the
     * #165 "cleared a bucket nobody counts into" class of silent no-op).
     */
    public const SECURITY_CONTEXT = [
        'error_codes', 'error', 'source', 'status', 'body', 'response', 'method', 'anonymized_ip', 'ip_address',
    ];

    /** Auth stream subset: identity and provider only, never the user model dump. */
    public const AUTH_CONTEXT = ['error', 'id', 'ip', 'provider', 'trace', 'user_id'];

    /** Moderation stream subset: verdict and ids, never comment bodies. */
    public const MODERATION_CONTEXT = [
        'action', 'comment_id', 'decision_version', 'error', 'final_status', 'moderator_id', 'verdict',
        'reason', 'rule_confidence_score', 'trace', 'user_id',
    ];

    public function __invoke(array $config): \Monolog\Logger
    {
        $name = $config['name'] ?? 'monitor';
        $connectionString = trim((string) ($config['connectionString'] ?? ''));

        if ($connectionString === '') {
            return new \Monolog\Logger($name, [new NullHandler]);
        }

        $socket = new SocketHandler(
            $connectionString,
            level: $config['level'] ?? 'error',
            persistent: (bool) ($config['persistent'] ?? true),
            timeout: (float) ($config['timeout'] ?? 1.0),
            writingTimeout: (float) ($config['writingTimeout'] ?? 1.0),
            connectionTimeout: (float) ($config['connectionTimeout'] ?? 1.0),
        );
        $socket->setFormatter(new MonitorJsonFormatter(
            projectKey: (string) ($config['project_key'] ?? 'backend'),
            environment: (string) ($config['environment'] ?? ''),
            // #178: a nested stack logger is named after config('app.name'), not
            // after the channel key, so the stream label has to be stamped here
            // or every protection line lands in the monitor as `local`.
            stream: isset($config['stream']) ? (string) $config['stream'] : null,
            contextAllow: $config['context_allow'] ?? null,
        ));

        $handler = new WhatFailureGroupHandler([$socket]);
        $handler->pushProcessor(new PsrLogMessageProcessor);

        return new \Monolog\Logger($name, [$handler]);
    }
}
