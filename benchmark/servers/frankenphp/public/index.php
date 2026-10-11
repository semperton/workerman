<?php

declare(strict_types=1);

use Bench\App;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Worker mode: booted once, handles requests in a loop, superglobals are reset per request
 */

$factory = new Psr17Factory();
$creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
$app = new App();

$emit = static function (ResponseInterface $response): void {
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header("$name: $value", false);
        }
    }
    echo $response->getBody();
};

$handle = static fn() => $emit($app->handle($creator->fromGlobals()));

while (frankenphp_handle_request($handle)) {
    // next request
}
