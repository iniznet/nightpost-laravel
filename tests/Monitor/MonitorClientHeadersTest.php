<?php

namespace NightpostTests\Monitor;

use Nightpost\Laravel\NightpostMonitorServiceProvider;
use Nightpost\Laravel\Monitor\MonitorClient;
use NightpostTests\TestCase;

class MonitorClientHeadersTest extends TestCase
{
    public function test_no_key_sends_only_content_type(): void
    {
        $client = new MonitorClient('http://monitor.test', 'backend');
        $this->assertSame(['Content-Type: application/json'], $client->httpHeaders());
    }

    public function test_key_rides_x_monitor_key(): void
    {
        $client = new MonitorClient('http://monitor.test', 'backend', 300, 'abc123');
        $this->assertSame(
            ['Content-Type: application/json', 'X-Monitor-Key: abc123'],
            $client->httpHeaders(),
        );
    }

    public function test_provider_wires_the_config_key_into_the_singleton(): void
    {
        config(['monitor.ingest_key' => 'from-config']);
        (new NightpostMonitorServiceProvider($this->app))->register();
        $client = $this->app->make(MonitorClient::class);
        $this->assertContains('X-Monitor-Key: from-config', $client->httpHeaders());

        config(['monitor.ingest_key' => '']);
        unset($this->app[MonitorClient::class]);
        (new NightpostMonitorServiceProvider($this->app))->register();
        $legacy = $this->app->make(MonitorClient::class);
        $this->assertCount(1, $legacy->httpHeaders());
    }
}
