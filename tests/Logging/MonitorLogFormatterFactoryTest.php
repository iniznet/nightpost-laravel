<?php

namespace NightpostTests\Logging;

use Monolog\Handler\NullHandler;
use Monolog\Handler\SocketHandler;
use Monolog\Level;
use Nightpost\Laravel\Logging\MonitorJsonFormatter;
use Nightpost\Laravel\Logging\MonitorLogFormatterFactory;
use NightpostTests\TestCase;

/**
 * Pins the Monolog 3 named-argument contract of the monitor channel factory
 * (iniznet/fenrirealm-tracker#137): a parameter-name mismatch throws inside
 * the custom driver and is silently replaced by the emergency logger, so the
 * socket leg must construct against the pinned Monolog signature.
 */
class MonitorLogFormatterFactoryTest extends TestCase
{
    public function test_a_connection_string_builds_the_whatfailure_wrapped_socket(): void
    {
        $logger = (new MonitorLogFormatterFactory)([
            'name' => 'monitor',
            'connectionString' => 'tcp://127.0.0.1:9913',
            'level' => 'error',
            'project_key' => 'my-app',
            'environment' => 'testing',
        ]);

        $group = $logger->getHandlers()[0];
        $this->assertInstanceOf(\Monolog\Handler\WhatFailureGroupHandler::class, $group);

        $socket = (new \ReflectionObject($group))->getProperty('handlers')->getValue($group)[0];
        $this->assertInstanceOf(SocketHandler::class, $socket);
        $this->assertSame(Level::Error, $socket->getLevel());

        $formatter = $socket->getFormatter();
        $this->assertInstanceOf(MonitorJsonFormatter::class, $formatter);
        $this->assertSame('my-app', (new \ReflectionObject($formatter))->getProperty('projectKey')->getValue($formatter));
    }

    public function test_an_empty_connection_string_is_sink_less(): void
    {
        $logger = (new MonitorLogFormatterFactory)(['name' => 'monitor', 'connectionString' => '']);

        $handler = $logger->getHandlers()[0];
        $this->assertInstanceOf(NullHandler::class, $handler);
    }
}
