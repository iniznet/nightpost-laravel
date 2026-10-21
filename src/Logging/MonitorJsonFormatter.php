<?php

namespace Nightpost\Laravel\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * JSON formatter for the monitor log channel (iniznet/fenrirealm-tracker#164).
 *
 * Monolog 3 cannot carry arbitrary top-level record fields: LogRecord::offsetSet
 * accepts only 'extra' and 'formatted', and JsonFormatter::normalizeRecord emits
 * extra as a NESTED key. The monitor's socket ingest reads project_key and
 * environment off the record root, so a processor cannot produce them — only a
 * formatter can. Stamping here keeps every shipped line self-identifying, which
 * is what makes the dashboard show one project row instead of `default`.
 *
 * The same reasoning is the only way to label a protection stream (#178): a
 * nested stack logger is named after config('app.name'), not after the channel
 * key, so `stream` rewrites the record's `channel` for the network sink alone --
 * the file line keeps `local.ERROR`, while the monitor's /logs channel filter
 * gets security | auth | moderation.
 *
 * `contextAllow` bounds what leaves the container: the shipped context keeps the
 * allowlisted keys and names the rest in `context._dropped_keys`, so a field can
 * be lost to the allowlist but never lost silently.
 */
final class MonitorJsonFormatter extends JsonFormatter
{
    /** Shipped marker naming the context keys the allowlist held back. */
    public const DROPPED_KEY = '_dropped_keys';

    public function __construct(
        private readonly string $projectKey,
        private readonly ?string $environment = null,
        private readonly ?string $stream = null,
        private readonly ?array $contextAllow = null,
    ) {
        parent::__construct();
    }

    /**
     * @return array<array<mixed>|bool|float|int|\stdClass|string|null>
     */
    protected function normalizeRecord(LogRecord $record): array
    {
        $normalized = parent::normalizeRecord($record);

        if ($this->stream !== null && $this->stream !== '') {
            $normalized['channel'] = $this->stream;
        }

        if ($this->contextAllow !== null) {
            $context = (array) ($normalized['context'] ?? []);
            $shipped = array_intersect_key($context, array_flip($this->contextAllow));
            $dropped = array_values(array_diff(array_keys($context), $this->contextAllow));

            if ($dropped !== []) {
                $shipped[self::DROPPED_KEY] = $dropped;
            }

            $normalized['context'] = $shipped;
        }

        $normalized['project_key'] = $this->projectKey;

        if ($this->environment !== null && $this->environment !== '') {
            $normalized['environment'] = $this->environment;
        }

        return $normalized;
    }
}
