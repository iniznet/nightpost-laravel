<?php

namespace NightpostTests\Monitor;

use Nightpost\Laravel\Monitor\ProfilerEndpoint;
use PHPUnit\Framework\TestCase;

/**
 * #164: the profiler's project key travels as the URL user component, so the
 * endpoint must resolve through MONITOR_PROJECT_KEY instead of being trusted
 * verbatim — that drift is what put profiles in a different dashboard bucket
 * than the rest of the backend's telemetry.
 */
class ProfilerEndpointTest extends TestCase
{
    public function test_it_inserts_the_project_key_when_the_endpoint_names_no_user(): void
    {
        $this->assertSame(
            'http://backend@monitor:8000/api/profiler/store',
            ProfilerEndpoint::resolve('http://monitor:8000/api/profiler/store', 'backend'),
        );
    }

    public function test_it_replaces_a_drifted_user_component(): void
    {
        $this->assertSame(
            'http://fenrirealm-backend-production@fenrirealm-monitor-production:8000/api/profiler/store',
            ProfilerEndpoint::resolve(
                'http://backend@fenrirealm-monitor-production:8000/api/profiler/store',
                'fenrirealm-backend-production',
            ),
        );
    }

    public function test_an_unset_endpoint_falls_back_to_the_default_host(): void
    {
        $expected = 'http://backend@127.0.0.1:8000/api/profiler/store';

        $this->assertSame($expected, ProfilerEndpoint::resolve(null, 'backend'));
        $this->assertSame($expected, ProfilerEndpoint::resolve('', 'backend'));
        $this->assertSame($expected, ProfilerEndpoint::resolve('   ', 'backend'));
    }

    public function test_a_non_http_value_is_returned_untouched(): void
    {
        $this->assertSame('tcp://127.0.0.1:9913', ProfilerEndpoint::resolve('tcp://127.0.0.1:9913', 'backend'));
    }

    public function test_an_empty_project_key_leaves_the_endpoint_as_given(): void
    {
        $this->assertSame(
            'http://monitor:8000/api/profiler/store',
            ProfilerEndpoint::resolve('http://monitor:8000/api/profiler/store', ''),
        );
    }

    public function test_the_project_key_is_url_encoded(): void
    {
        $this->assertSame(
            'http://a%2Fb@monitor:8000/x',
            ProfilerEndpoint::resolve('http://monitor:8000/x', 'a/b'),
        );
    }
}
