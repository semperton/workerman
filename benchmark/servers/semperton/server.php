<?php

declare(strict_types=1);

use Bench\App;
use Nyholm\Psr7\Factory\Psr17Factory;
use Semperton\Workerman\HttpServer;
use Semperton\Workerman\RequestConverter;
use Workerman\Events\Select;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

// workerman uses the event extension if loaded, select is its fallback
if (getenv('EVENT_LOOP') === 'select') {
    Worker::$eventLoopClass = Select::class;
}
Worker::$pidFile = '/tmp/workerman.pid';
Worker::$logFile = '/tmp/workerman.log';

$factory = new Psr17Factory();
$server = new HttpServer(static fn() => new App(), new RequestConverter($factory, $factory, $factory));
$server->listen('http://0.0.0.0:8080', workers: 4);

Worker::runAll();
