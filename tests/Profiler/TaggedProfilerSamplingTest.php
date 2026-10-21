<?php

namespace NightpostTests\Profiler;

use Nightpost\Laravel\Profiler\TaggedProfilerFactory;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use ReflectionMethod;
use NightpostTests\TestCase;

/**
 * Guards the #145 sampled code-attribution gate inside isEnabled():
 * header > MONITOR_PROFILE_RATE sampling > blanket xhprof.enabled.
 *
 * isEnabled() is exercised via reflection (not create()) so the test does not
 * require the xhprof PHP extension: an enabled decision would otherwise throw
 * in DriverFactory::createXhrofDriver() on machines without the extension.
 */
class TaggedProfilerSamplingTest extends TestCase
{
    private function decide(array $config, array $server = []): bool
    {
        $factory = new TaggedProfilerFactory(
            new Repository($config),
            Request::create('/probe', 'GET', server: $server),
        );

        $method = new ReflectionMethod($factory, 'isEnabled');
        $method->setAccessible(true);

        return $method->invoke($factory);
    }

    /** The #167 guard: without the xhprof extension nothing may enable the profiler. */
    private function requiresMissingXhprofExtension(): void
    {
        if (extension_loaded('xhprof')) {
            $this->markTestSkipped('xhprof PHP extension loaded; the absence guard is unreachable here.');
        }
    }

    public function test_extension_absent_disables_sampling(): void
    {
        $this->requiresMissingXhprofExtension();

        // Rate 1.0 means the sampler would always fire — only the missing
        // extension keeps the decision off.
        $this->assertFalse($this->decide([
            'app' => ['name' => 'fenrirealm', 'env' => 'testing'],
            'xhprof' => ['enabled' => false],
            'monitor' => ['profile_rate' => 1.0],
        ]));
    }

    public function test_extension_absent_header_cannot_enable(): void
    {
        $this->requiresMissingXhprofExtension();

        // The explicit opt-in previously stayed reachable on ext-less hosts
        // and fataled in DriverFactory::createXhrofDriver() (tracker#167).
        $this->assertFalse($this->decide([
            'app' => ['name' => 'fenrirealm', 'env' => 'testing'],
            'xhprof' => ['enabled' => true],
        ], ['HTTP_X_XHPROF_ENABLED' => 'true']));
    }

    /** The #145 pre-mortem: rate 0 must capture NOTHING when blanket is off. */
    public function test_rate_zero_never_enables(): void
    {
        $this->assertFalse($this->decide([
            'app' => ['name' => 'fenrirealm', 'env' => 'testing'],
            'xhprof' => ['enabled' => false],
            'monitor' => ['profile_rate' => 0.0],
        ]));
    }

    /** Enabled-decision tests need the extension present: without it the
     * #167 guard returns false before rate/header/blanket are consulted. */
    private function requiresXhprofExtension(): void
    {
        if (! extension_loaded('xhprof')) {
            $this->markTestSkipped('xhprof PHP extension not loaded; enabled decisions resolve false by design.');
        }
    }

    public function test_rate_one_always_enables(): void
    {
        $this->requiresXhprofExtension();

        $this->assertTrue($this->decide([
            'app' => ['name' => 'fenrirealm', 'env' => 'testing'],
            'xhprof' => ['enabled' => false],
            'monitor' => ['profile_rate' => 1.0],
        ]));
    }

    public function test_explicit_header_wins_over_zero_rate(): void
    {
        $this->requiresXhprofExtension();

        $this->assertTrue($this->decide([
            'app' => ['name' => 'fenrirealm', 'env' => 'testing'],
            'xhprof' => ['enabled' => false],
            'monitor' => ['profile_rate' => 0.0],
        ], ['HTTP_X_XHPROF_ENABLED' => 'true']));
    }

    public function test_blanket_xhprof_enabled_still_works(): void
    {
        $this->requiresXhprofExtension();

        $this->assertTrue($this->decide([
            'app' => ['name' => 'fenrirealm', 'env' => 'testing'],
            'xhprof' => ['enabled' => true],
            'monitor' => ['profile_rate' => 0.0],
        ]));
    }

    public function test_explicit_false_header_wins_over_blanket(): void
    {
        // A manual deep-dive opt-out must be able to disable capture even when
        // the blanket flag is on.
        $this->assertFalse($this->decide([
            'app' => ['name' => 'fenrirealm', 'env' => 'testing'],
            'xhprof' => ['enabled' => true],
        ], ['HTTP_X_XHPROF_ENABLED' => 'false']));
    }
}