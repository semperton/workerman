<?php

declare(strict_types=1);

use Bench\App;
use Semperton\Workerman\HttpServer;
use Workerman\Events\Select;
use Workerman\Worker;

require __DIR__ . '/vendor/autoload.php';

// workerman uses the event extension if loaded, select is its fallback
if (getenv('EVENT_LOOP') === 'select') {
    Worker::$eventLoopClass = Select::class;
}
Worker::$pidFile = '/tmp/workerman.pid';
Worker::$logFile = '/tmp/workerman.log';

$server = new HttpServer(static fn() => new App());
$server->listen('http://0.0.0.0:8080', workers: 4);

Worker::runAll();
