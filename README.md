<div align="center">
<a href="https://github.com/semperton">
<img width="140" src="https://raw.githubusercontent.com/semperton/.github/main/readme-logo.svg" alt="Semperton">
</a>
<h1>Semperton Workerman</h1>
<p>Serves PSR-15 applications with <a href="https://github.com/walkor/workerman">Workerman</a>.<br>Every worker builds the application once and handles all its requests.</p>
</div>

---

## Installation

Just use Composer:

```
composer require semperton/workerman
```
Requires PHP 8.2+, the `pcntl` and `posix` extensions (Linux / macOS) and any PSR-7 / PSR-17 implementation.

## Usage

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Semperton\Workerman\HttpServer;
use Semperton\Workerman\RequestConverter;
use Workerman\Worker;

$factory = new Psr17Factory();

$server = new HttpServer(
    // called in every worker, after the fork, returns any PSR-15 request handler
    static fn() => createApplication(),
    new RequestConverter($factory, $factory, $factory),
    $logger, // optional PSR-3 logger
);

$server->listen('http://0.0.0.0:8080', workers: 4);

Worker::runAll();
```
Run the script with `php server.php start`, see the [Workerman docs](https://manual.workerman.net/doc/en/) for `-d` (daemon), `stop`, `reload` and `status`.

The handler is created in the workers, so database connections and other resources are never shared between processes.

## Options

```php
$server->listen(
    'http://0.0.0.0:8080',
    workers: 4,                     // one per CPU core, 2 to 4 per core if requests wait for a database or API
    maxBodySize: 10 * 1024 * 1024,  // larger bodies get a 413, the body is kept in memory
    maxRequests: 0,                 // a worker restarts after this many requests, 0 = never
);
```
With `maxRequests`, the worker stops accepting connections and answers further requests with `Connection: close`.
Idle keep-alive connections are closed, so the worker exits once its last response is sent, and Workerman starts a new one.
This limits slow memory leaks without dropping requests.

The workers share one socket, so stopping or reloading workers doesn't drop queued connections.

## Requests and responses

Requests are converted like PHP-FPM would populate them: server params (`REMOTE_ADDR`, `HTTPS`, `HTTP_*` ...), cookies, query params, form posts and uploaded files.
Other bodies (e.g. JSON) are left to the application.
Requests with a missing or invalid `Host` header, which could change the path of the uri, get a `400`.

Responses support keep-alive, `HEAD`, HTTP/1.0 and chunked encoding for bodies of unknown size.
Bodies larger than 2 MB are streamed and wait for the client to read them (backpressure).

The handler should render its errors, e.g. with an error middleware. Uncaught exceptions are logged and answered with an empty `500`.

## Development

The `FileMonitor` reloads the workers when a file changes:
```php
use Semperton\Workerman\FileMonitor;

(new FileMonitor([__DIR__ . '/src', __DIR__ . '/config']))->listen();
```
Files loaded before the workers start need a restart.

```
composer test   # phpunit
composer check  # psalm + php-cs-fixer (PER-CS)
composer fix    # apply the code style
```

## Long-running processes

- **Keep services stateless.** Handlers, middleware and services live as long as the worker. Request data must never end up in properties, use request attributes instead.
- **Expect long-lived connections to time out.** Reconnect on errors.
- **Avoid blocking for long.** A worker handles one request at a time.

In production, run the server behind a reverse proxy that handles TLS, compression and static files. Install the `event` extension for better performance with many connections.
