<?php

namespace NightpostTests\Monitor;

use NightpostTests\TestCase;

/**
 * The producer reads one namespace: NIGHTPOST_* (iniznet/fenrirealm-tracker#564).
 *
 * The MONITOR_* fallback is gone, not deprecated: an install that still sets the
 * old names must be inert rather than half-working, because a partially resolved
 * config reports under the wrong project instead of visibly sending nothing.
 */
class MonitorEnvNamespaceTest extends TestCase
{
    /** @var list<string> */
    private array $keys = [
        'NIGHTPOST_SLOW_QUERY_MS', 'MONITOR_SLOW_QUERY_MS',
        'NIGHTPOST_PROJECT_KEY', 'MONITOR_PROJECT_KEY',
    ];

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        parent::tearDown();
    }

    public function test_the_product_namespace_is_read(): void
    {
        $_ENV['NIGHTPOST_SLOW_QUERY_MS'] = '123';
        $_ENV['NIGHTPOST_PROJECT_KEY'] = 'storefront';

        $config = include __DIR__.'/../../config/monitor.php';

        $this->assertSame(123, $config['slow_query_ms']);
        $this->assertSame('storefront', $config['project_key']);
    }

    public function test_the_legacy_monitor_namespace_is_inert(): void
    {
        $_ENV['MONITOR_SLOW_QUERY_MS'] = '999';
        $_ENV['MONITOR_PROJECT_KEY'] = 'legacy-proj';

        $config = include __DIR__.'/../../config/monitor.php';

        $this->assertSame(0, $config['slow_query_ms']);
        $this->assertSame('backend', $config['project_key']);
    }
}
