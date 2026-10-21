<?php

declare(strict_types=1);

namespace NightpostTests\Profiler;

use Nightpost\Laravel\Profiler\FailSafeStorage;
use PHPUnit\Framework\TestCase;
use SpiralPackages\Profiler\Storage\StorageInterface;

final class FailSafeStorageTest extends TestCase
{
    public function test_transport_failure_is_dropped_not_thrown(): void
    {
        $inner = $this->createMock(StorageInterface::class);
        $inner->method('store')->willThrowException(
            new \Symfony\Component\HttpClient\Exception\TransportException('Connection timed out')
        );
        $inner->expects($this->once())->method('store');

        (new FailSafeStorage($inner))->store('app', ['env' => 'test'], new \DateTimeImmutable, []);

        $this->assertTrue(true);
    }

    public function test_successful_store_passes_through_unchanged(): void
    {
        $inner = $this->createMock(StorageInterface::class);
        $inner->expects($this->once())
            ->method('store')
            ->with('app', ['env' => 'test'], self::anything(), ['data']);

        (new FailSafeStorage($inner))->store('app', ['env' => 'test'], new \DateTimeImmutable, ['data']);
    }
}
