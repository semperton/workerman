<?php

declare(strict_types=1);

use Bench\App;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require __DIR__ . '/vendor/autoload.php';

/**
 * Classic shared-nothing: the app is booted for every request
 */

$factory = new Psr17Factory();
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
$response = (new App())->handle($request);

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("$name: $value", false);
    }
}
echo $response->getBody();
