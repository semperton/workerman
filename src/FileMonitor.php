<?php

declare(strict_types=1);

namespace Semperton\Workerman;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use UnexpectedValueException;
use Workerman\Timer;
use Workerman\Worker;

use function clearstatcache;
use function is_dir;
use function max;
use function posix_getppid;
use function posix_kill;

use const SIGUSR2;

/**
 * Development only, reloads the workers when a file changes
 * Files loaded before the workers start (settings, server classes) need a restart
 *
 * @api
 */
final readonly class FileMonitor
{
    /**
     * @param list<string> $paths directories, missing ones are skipped
     * @param float $interval seconds between the checks
     */
    public function __construct(
        private array $paths,
        private float $interval = 1.0,
    ) {}

    /**
     * Adds the monitor worker, Worker::runAll() starts it
     */
    public function listen(): void
    {
        $worker = new Worker();
        $worker->name = 'monitor';
        $worker->reloadable = false;
        $worker->onWorkerStart = $this->start(...);
    }

    private function start(): void
    {
        if (Worker::$daemonize) {
            return;
        }

        $lastModified = $this->lastModified();

        Timer::add($this->interval, function () use (&$lastModified): void {
            $modified = $this->lastModified();
            if ($modified > $lastModified) {
                $lastModified = $modified;
                // gracefully, like 'reload -g', requests in progress complete
                posix_kill(posix_getppid(), SIGUSR2);
            }
        });
    }

    /**
     * Directories are included, their time changes when files are added or removed
     */
    private function lastModified(): int
    {
        clearstatcache();
        $lastModified = 0;

        foreach ($this->paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            try {
                /** @var SplFileInfo $file */
                foreach ($files as $file) {
                    $lastModified = max($lastModified, $this->modified($file));
                }
            } catch (UnexpectedValueException) {
                // a directory was removed meanwhile, its parent directory has changed
            }
        }

        return $lastModified;
    }

    private function modified(SplFileInfo $file): int
    {
        try {
            return $file->getMTime();
        } catch (RuntimeException) {
            // e.g. a broken symlink or a file removed meanwhile
            return 0;
        }
    }
}
