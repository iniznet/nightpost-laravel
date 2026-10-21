<?php

declare(strict_types=1);

namespace NightpostTests\Profiler;

use Nightpost\Laravel\Profiler\TaggedProfilerFactory;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use ReflectionClass;
use SpiralPackages\Profiler\Driver\NullDriver;
use SpiralPackages\Profiler\Profiler;
use NightpostTests\TestCase;

class TaggedProfilerFactoryTest extends TestCase
{
    private function requiresXhprofExtension(): void
    {
        if (! extension_loaded('xhprof')) {
            $this->markTestSkipped('xhprof PHP extension not loaded; XHPROF_FLAGS_* constants unavailable.');
        }
    }

    public function test_disabled_by_default_without_header_or_config(): void
    {
        config(['xhprof.enabled' => false]);

        $profiler = $this->make(false);

        $this->assertInstanceOf(NullDriver::class, $this->driverOf($profiler));
    }

    public function test_header_false_overrides_enabled_config(): void
    {
        config(['xhprof.enabled' => true]);

        $profiler = $this->make('0');

        $this->assertInstanceOf(NullDriver::class, $this->driverOf($profiler));
    }

    public function test_header_empty_string_disables(): void
    {
        config(['xhprof.enabled' => true]);

        $profiler = $this->make('');

        $this->assertInstanceOf(NullDriver::class, $this->driverOf($profiler));
    }

    public function test_header_enabled_profiles_with_non_empty_tags(): void
    {
        $this->requiresXhprofExtension();
        config(['xhprof.enabled' => false]);

        $profiler = $this->make('1');

        $this->assertNotInstanceOf(NullDriver::class, $this->driverOf($profiler));

        $tags = $this->tagsOf($profiler);

        $this->assertArrayHasKey('env', $tags);
        $this->assertArrayHasKey('host', $tags);
        $this->assertNotSame('', $tags['host']);
    }

    public function test_config_enabled_profiles_with_non_empty_tags(): void
    {
        $this->requiresXhprofExtension();
        config(['xhprof.enabled' => true]);

        $profiler = $this->make(false);

        $tags = $this->tagsOf($profiler);

        $this->assertSame('testing', $tags['env']);
    }

    public function test_tags_encode_as_json_object_not_array(): void
    {
        $this->requiresXhprofExtension();
        config(['xhprof.enabled' => true]);

        $profiler = $this->make(false);

        $encoded = json_encode(['tags' => $this->tagsOf($profiler)], JSON_THROW_ON_ERROR);

        $this->assertStringStartsWith('{', $encoded);
    }

    private function make(string|false $headerValue): Profiler
    {
        $request = Request::create('/', 'GET');

        if ($headerValue !== false) {
            $request->headers->set('X-Xhprof-Enabled', $headerValue);
        }

        return (new TaggedProfilerFactory(
            new ConfigRepository(config()->all()),
            $request,
        ))->create();
    }

    private function driverOf(Profiler $profiler): object
    {
        return (new ReflectionClass(Profiler::class))->getProperty('driver')->getValue($profiler);
    }

    private function tagsOf(Profiler $profiler): array
    {
        return (new ReflectionClass(Profiler::class))->getProperty('tags')->getValue($profiler);
    }

    // --- #180: deep attribution rides the trace decision -------------------

    private function makeWithTrace(string $traceId, bool $profiled = true): Profiler
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('X-Monitor-Trace-Id', $traceId);
        if ($profiled) {
            $request->headers->set('X-Monitor-Profiled', '1');
        }

        return (new TaggedProfilerFactory(
            new ConfigRepository(config()->all()),
            $request,
        ))->create();
    }

    public function test_trace_headers_enable_deep_within_cap(): void
    {
        $this->requiresXhprofExtension();
        config(['xhprof.enabled' => false, 'monitor.profile_rate' => 0, 'monitor.forced_deep_cap' => 2]);

        $profiler = $this->makeWithTrace('t-force-1');

        $this->assertNotInstanceOf(NullDriver::class, $this->driverOf($profiler));
    }

    public function test_trace_headers_beyond_deep_cap_fall_through_to_the_normal_roll(): void
    {
        $this->requiresXhprofExtension();
        config(['xhprof.enabled' => false, 'monitor.profile_rate' => 0, 'monitor.forced_deep_cap' => 1]);

        $first = $this->makeWithTrace('t-a');
        $second = $this->makeWithTrace('t-b');

        $this->assertNotInstanceOf(NullDriver::class, $this->driverOf($first));
        $this->assertInstanceOf(NullDriver::class, $this->driverOf($second));
    }

    public function test_trace_id_header_without_profiled_flag_does_not_force_deep(): void
    {
        $this->requiresXhprofExtension();
        config(['xhprof.enabled' => false, 'monitor.profile_rate' => 0, 'monitor.forced_deep_cap' => 5]);

        $profiler = $this->makeWithTrace('t-solo', profiled: false);

        $this->assertInstanceOf(NullDriver::class, $this->driverOf($profiler));
    }

    public function test_tags_carry_trace_id_for_the_callgraph_link(): void
    {
        $this->requiresXhprofExtension();
        config(['xhprof.enabled' => false]);

        $request = Request::create('/', 'GET');
        $request->headers->set('X-Xhprof-Enabled', '1');
        $request->headers->set('X-Monitor-Trace-Id', 't-tag-9');
        $profiler = (new TaggedProfilerFactory(new ConfigRepository(config()->all()), $request))->create();

        $tags = $this->tagsOf($profiler);
        $this->assertSame('t-tag-9', $tags['trace_id']);
    }

    public function test_forced_trace_never_bypasses_the_extension_gate(): void
    {
        if (extension_loaded('xhprof')) {
            $this->markTestSkipped('asserts the extension-less path only');
        }
        config(['xhprof.enabled' => true, 'monitor.forced_deep_cap' => 5]);

        $profiler = $this->makeWithTrace('t-gate');

        $this->assertInstanceOf(NullDriver::class, $this->driverOf($profiler));
    }
}
