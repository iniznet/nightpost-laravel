<?php

namespace NightpostTests\Monitor;

use Nightpost\Laravel\Monitor\ServiceIdentity;
use Nightpost\Laravel\NightpostMonitorServiceProvider;
use NightpostTests\TestCase;

/**
 * v2 producer identity (nightpost#9): a payload names the service that sent it
 * and the environment it ran in. Both keys are OPTIONAL on the wire, so the
 * identity is added without touching any field the caller already set.
 */
class ServiceIdentityTest extends TestCase
{
    public function test_the_service_defaults_to_the_app_name_slug(): void
    {
        $identity = ServiceIdentity::fromConfig([], 'My Reader App', 'production');

        $this->assertSame('my-reader-app', $identity->name());
        $this->assertSame('production', $identity->env());
    }

    public function test_configured_service_wins_over_the_app_name(): void
    {
        $identity = ServiceIdentity::fromConfig(['service' => 'front'], 'My Reader App', 'staging');

        $this->assertSame('front', $identity->name());
        $this->assertSame('staging', $identity->env());
    }

    public function test_a_nameless_app_falls_back_to_the_project_key(): void
    {
        $identity = ServiceIdentity::fromConfig(['project_key' => 'backend'], '', '');

        $this->assertSame('backend', $identity->name());
        $this->assertSame('production', $identity->env());
    }

    public function test_decorated_payload_is_the_v1_body_plus_two_keys(): void
    {
        $identity = new ServiceIdentity('backend', 'production');
        $v1 = ['name' => 'GET /chapters/1', 'duration_ms' => 12.5, 'environment' => 'production'];

        $payload = $identity->decorate($v1);

        $this->assertSame('backend', $payload['service']);
        $this->assertSame('production', $payload['env']);
        $this->assertSame($v1, array_diff_key($payload, ['service' => true, 'env' => true]));
    }

    public function test_a_caller_supplied_field_is_never_overwritten(): void
    {
        $identity = new ServiceIdentity('backend', 'production');

        $payload = $identity->decorate(['service' => 'front', 'env' => 'staging']);

        $this->assertSame('front', $payload['service']);
        $this->assertSame('staging', $payload['env']);
    }

    public function test_the_provider_wires_the_configured_identity(): void
    {
        $this->assertSame('nightpost-test', $this->app->make(ServiceIdentity::class)->name());
        $this->assertSame('testing', $this->app->make(ServiceIdentity::class)->env());

        config(['monitor.service' => 'front']);
        unset($this->app[ServiceIdentity::class]);
        (new NightpostMonitorServiceProvider($this->app))->register();

        $this->assertSame('front', $this->app->make(ServiceIdentity::class)->name());
    }
}
