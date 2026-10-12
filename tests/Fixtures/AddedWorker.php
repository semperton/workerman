<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Fixtures;

use Closure;
use RuntimeException;
use Workerman\Worker;

use function array_diff_key;
use function array_values;
use function count;

/**
 * The worker that listen() added to Workerman, whose callbacks the tests call like Workerman does
 */
final class AddedWorker
{
    /**
     * @param Closure(): void $listen
     */
    public static function of(Closure $listen): Worker
    {
        $workers = Worker::getAllWorkers();
        $listen();
        // workers stay registered, so their ids are not reused
        $added = array_values(array_diff_key(Worker::getAllWorkers(), $workers));

        if (count($added) !== 1) {
            throw new RuntimeException('Expected one added worker, got ' . count($added));
        }

        return $added[0];
    }
}
