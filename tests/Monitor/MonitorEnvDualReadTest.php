<?php

namespace NightpostTests\Monitor;

use NightpostTests\TestCase;

/**
 * The producer env migration (iniznet/fenrirealm-tracker#278 §3) is a
 * dual-read: the product namespace NIGHTPOST_* wins, the legacy MONITOR_* leg
 * keeps resolving, so an existing install never needs a coordinated env
 * cutover. Pinned here so the fallback leg cannot silently disappear.
 */
class MonitorEnvDualReadTest extends TestCase
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

    public function test_the_product_namespace_wins_over_the_legacy_leg(): void
    {
        $_ENV['NIGHTPOST_SLOW_QUERY_MS'] = '123';
        $_ENV['MONITOR_SLOW_QUERY_MS'] = '999';

        $config = include __DIR__.'/../../config/monitor.php';

        $this->assertSame(123, $config['slow_query_ms']);
    }

    public function test_the_legacy_monitor_leg_resolves_when_nightpost_is_absent(): void
    {
        $_ENV['MONITOR_PROJECT_KEY'] = 'legacy-proj';

        $config = include __DIR__.'/../../config/monitor.php';

        $this->assertSame('legacy-proj', $config['project_key']);
    }
}
