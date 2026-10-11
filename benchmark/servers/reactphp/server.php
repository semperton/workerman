<?php

declare(strict_types=1);

use Bench\App;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Socket\SocketServer;

require __DIR__ . '/vendor/autoload.php';

/**
 * ReactPHP is single process, 4 processes share the port with SO_REUSEPORT
 * Forked before the event loop is created, the event extension is used
 */

for ($i = 1; $i < 4; $i++) {
    if (pcntl_fork() === 0) {
        break;
    }
}

$app = new App();
$http = new HttpServer(static fn(ServerRequestInterface $request) => $app->handle($request));
$http->listen(new SocketServer('0.0.0.0:8080', ['tcp' => ['so_reuseport' => true, 'backlog' => 1024]]));
