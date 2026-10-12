<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Semperton\Workerman\FileMonitor;
use Semperton\Workerman\Tests\Fixtures\AddedWorker;

use function mkdir;
use function rmdir;
use function symlink;
use function sys_get_temp_dir;
use function touch;
use function uniqid;
use function unlink;

final class FileMonitorTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/' . uniqid('semperton-monitor-');
        mkdir($this->dir . '/sub', recursive: true);
        touch($this->dir . '/sub', 1000);
        touch($this->dir, 1000);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/sub/file.php');
        @unlink($this->dir . '/file.php');
        @unlink($this->dir . '/broken');
        @rmdir($this->dir . '/sub');
        @rmdir($this->dir);
    }

    public function testChange(): void
    {
        $monitor = new FileMonitor([$this->dir, $this->dir . '/missing']);
        self::assertSame(1000, $this->lastModified($monitor));

        touch($this->dir . '/sub/file.php', 2000);
        // adding the file changed the time of the directory
        touch($this->dir . '/sub', 1000);
        self::assertSame(2000, $this->lastModified($monitor));
    }

    public function testRemovedFile(): void
    {
        touch($this->dir . '/file.php', 1000);
        touch($this->dir, 1000);
        $monitor = new FileMonitor([$this->dir]);
        self::assertSame(1000, $this->lastModified($monitor));

        // removing the file changes the time of the monitored directory itself
        unlink($this->dir . '/file.php');
        self::assertGreaterThan(1000, $this->lastModified($monitor));
    }

    public function testBrokenSymlink(): void
    {
        symlink($this->dir . '/missing', $this->dir . '/broken');
        touch($this->dir, 1000);

        self::assertSame(1000, $this->lastModified(new FileMonitor([$this->dir])));
    }

    public function testWorker(): void
    {
        $monitor = new FileMonitor([$this->dir]);
        $worker = AddedWorker::of(static fn() => $monitor->listen());

        // reloading would restart the monitor, it would miss the change
        self::assertFalse($worker->reloadable);
        self::assertNotNull($worker->onWorkerStart);
    }

    #[TestWith([0.0])]
    #[TestWith([-1.0])]
    public function testInvalidInterval(float $interval): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FileMonitor([$this->dir], $interval);
    }

    private function lastModified(FileMonitor $monitor): int
    {
        $lastModified = (new ReflectionMethod($monitor, 'lastModified'))->invoke($monitor);
        self::assertIsInt($lastModified);

        return $lastModified;
    }
}
