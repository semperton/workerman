<?php

declare(strict_types=1);

use Bench\App;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

require __DIR__ . '/vendor/autoload.php';

$factory = new Psr17Factory();
$worker = new PSR7Worker(Worker::create(), $factory, $factory, $factory);
$app = new App();

while (true) {
    try {
        $request = $worker->waitRequest();
        if ($request === null) {
            break;
        }
    } catch (Throwable) {
        $worker->respond(new Response(400));
        continue;
    }

    try {
        $worker->respond($app->handle($request));
    } catch (Throwable $exception) {
        $worker->getWorker()->error((string) $exception);
    }
}
