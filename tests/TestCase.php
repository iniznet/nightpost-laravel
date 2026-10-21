<?php

namespace NightpostTests;

use Nightpost\Laravel\NightpostMonitorServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [NightpostMonitorServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Telemetry must be inert under test: array cache, no outbound sends.
        $app['config']->set('cache.default', 'array');
    }
}
