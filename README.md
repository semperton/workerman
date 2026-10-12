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
Requires PHP 8.2+ and the `ctype`, `pcntl` and `posix` extensions (Linux / macOS, Windows is not supported).
Requests are created with [nyholm/psr7](https://github.com/Nyholm/psr7), responses may come from any PSR-7 implementation.

## Usage

```php
use Semperton\Workerman\HttpServer;
use Workerman\Worker;

$server = new HttpServer(
    // called in every worker, after the fork, returns any PSR-15 request handler
    static fn() => createApplication(),
    logger: $logger, // optional PSR-3 logger
);

$server->listen('http://0.0.0.0:8080', workers: 4);

Worker::runAll();
```
Run the script with `php server.php start`, see the [Workerman docs](https://manual.workerman.net/doc/en/) for `-d` (daemon), `stop`, `reload` and `status`.

The handler is created in the workers, so database connections and other resources are never shared between processes.
It is created when the worker starts, so errors show up early: if the factory throws, the worker exits and Workerman starts it again, until the error is fixed.

`listen()` adds the workers to Workerman, `Worker::runAll()` starts them together with other workers, e.g. [WebSockets](#websockets).
It can be called more than once, e.g. for another address with other options, and throws an `InvalidArgumentException` for invalid options.

## Options

```php
$server->listen(
    'http://0.0.0.0:8080',
    workers: 4,                     // one per CPU core, 2 to 4 per core if requests wait for a database or API
    maxBodySize: 1024 * 1024,       // larger bodies get a 413, the body is kept in memory
    maxRequests: 0,                 // a worker restarts after this many requests, 0 = never
    idleTimeout: 60,                // seconds a connection may receive and send nothing, 0 = never
    headerTimeout: 20,              // seconds a client may take to send a request head, 0 = never
    stopTimeout: 30,                // seconds a stopping worker waits for its connections, then closes them
    user: 'www-data',               // the user of the worker processes if started as root, '' = the current user
    group: 'www-data',              // their group, '' = the group of the user
);
```
The whole request is kept in memory while it arrives, so `maxBodySize` limits the memory of every connection.
The default of 1 MB is the default of nginx, raise both together for uploads, e.g. with `client_max_body_size 20m`.

With `maxRequests`, the worker stops accepting connections and answers further requests with `Connection: close`.
Idle keep-alive connections are closed, so the worker exits once its last response is sent, and Workerman starts a new one.
This limits slow memory leaks without dropping requests.

Workerman has no timeouts of its own, idle or slow clients would hold their connections forever.
Connections are closed after `idleTimeout` without progress, e.g. idle keep-alive connections, stalled uploads, or clients that stop reading a response.
Clients that trickle a request head (slowloris) get a `408` after `headerTimeout`. The timeouts are checked once a second.

## Reloads and restarts

`php server.php reload -g` (or `restart -g`, `stop -g`) stops the workers gracefully: in-flight requests and streamed responses complete,
idle keep-alive connections are closed, so the old workers exit within a second, and further responses carry `Connection: close`.
The workers share one socket, so connections queued meanwhile are accepted by the new workers, none are dropped.

Connections that are still busy after `stopTimeout`, e.g. long downloads or clients that don't read, are closed, so a stop always ends.

Without `-g`, the workers stop right away and drop their connections. Workerman stops gracefully on `SIGQUIT` only, `SIGTERM` and `SIGINT` stop right away.
Process managers send `SIGTERM` by default, so configure `SIGQUIT`, and keep `stopTimeout` below the time they wait before they kill the server:

- systemd: `KillSignal=SIGQUIT` and `TimeoutStopSec=` above `stopTimeout`
- Docker and Kubernetes: `STOPSIGNAL SIGQUIT` in the image, and `terminationGracePeriodSeconds` above `stopTimeout`

## Requests and responses

Requests are converted like PHP-FPM would populate them: server params (`REMOTE_ADDR`, `SERVER_NAME`, `HTTP_*` ...), cookies, query params, form posts and uploaded files.
Other bodies (e.g. JSON) are left to the application.

- Keep-alive, pipelining, HTTP/1.0 and chunked request bodies are supported.
- `Expect: 100-continue` is answered right away, clients like curl would otherwise wait a second before sending larger bodies.
- Invalid requests get a `400`, e.g. a missing or invalid `Host` header, which could change the path of the uri, an invalid header value,
  both `Content-Length` and `Transfer-Encoding`, invalid chunk extensions and trailers, or a bare LF or CR in the head (request smuggling),
  and request targets with a fragment or non-ASCII bytes. Too large heads get a `431`, too large bodies a `413`.
- The security tests send hostile requests, from raw bytes to slow clients, see [security/](security/README.md).
- Error responses close the connection gracefully, so clients receive them even if they are still sending.

Responses support `HEAD`, `204` / `205` / `304` without a body, and chunked encoding for bodies of unknown size.
Bodies larger than 2 MB are streamed and wait for the client to read them (backpressure).
A body that is shorter than its size closes the connection, so the client notices, a longer one is cut.

The handler should render its errors, e.g. with an error middleware. Uncaught exceptions are logged and answered with an empty `500`, so are interim (`1xx`) responses
and responses with invalid header names or values, e.g. a line break that would add headers (response splitting), not every PSR-7 implementation validates them.

## Lifecycle

The handler lives as long as its worker. It may implement `WorkerLifecycle` to reset its state after every request, and to clean up when the worker stops:

```php
use Semperton\Workerman\WorkerLifecycle;

final class Application implements RequestHandlerInterface, WorkerLifecycle
{
    public function handle(ServerRequestInterface $request): ResponseInterface { /* ... */ }

    public function requestHandled(ServerRequestInterface $request): void
    {
        $this->container->reset(); // e.g. identity maps, request scoped services
    }

    public function workerStopping(): void
    {
        $this->database->close();
    }
}
```
`requestHandled()` is called once the response was handed to the connection, streamed and pushed bodies are still being sent.
If it throws, the error is logged and the worker restarts gracefully, as its state is unknown.

`workerStopping()` is called when the worker stops, after its last connection is closed (reload, restart, `maxRequests` or shutdown).
Workerman keeps a stopping worker running while it has open async connections (e.g. `AsyncTcpConnection` or async Redis clients),
close them there and release every reference, otherwise the worker only exits after `stopTimeout`.

## Server-Sent Events

A `PushStream` body is sent while the application pushes to it, e.g. from a timer or a subscription. The response is sent chunked and the connection receives no further requests until it ends.

```php
use Nyholm\Psr7\Response;
use Semperton\Workerman\PushStream;
use Workerman\Timer;

$stream = new PushStream();
$timer = Timer::add(1.0, static fn() => $stream->push("data: " . time() . "\n\n"));
// the client is gone, the stream has ended or the worker stops
$stream->onClose(static fn() => Timer::del($timer));

return new Response(200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache'], $stream);
```
- `push()` returns `false` if the data was not taken: the stream is closed, or the client doesn't read (there is no queue, so a slow client can't fill the memory).
- `end()` completes the body, `close()` aborts it and closes the connection.
- A `PushStream` is the body of one response, sending it again is answered with a `500`.
- The idle timeout applies, push something (e.g. a `:` comment) more often than `idleTimeout`, this also detects clients that are gone.
- On reloads and restarts, push connections are closed like idle ones, `EventSource` clients reconnect.
- Behind nginx, disable response buffering for the route, e.g. with an `X-Accel-Buffering: no` header.

The handler must not block meanwhile, a worker handles one request at a time.
Exceptions thrown in timer callbacks stop the worker with all its connections (Workerman), catch them in the callback.
Exceptions thrown by `onClose()` listeners are logged when the client is gone.

## WebSockets

WebSockets are served by a Workerman worker of their own, next to the HTTP workers, the reverse proxy routes e.g. `/ws` to it:

```php
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

$server->listen('http://0.0.0.0:8080', workers: 4);

$websocket = new Worker('websocket://0.0.0.0:8081');
$websocket->onMessage = static fn(TcpConnection $connection, string $data) => $connection->send($data);

Worker::runAll();
```

## Development

The `FileMonitor` reloads the workers when a file changes:
```php
use Semperton\Workerman\FileMonitor;

// checked every second, or e.g. interval: 0.5
(new FileMonitor([__DIR__ . '/src', __DIR__ . '/config']))->listen();
```
The workers are reloaded gracefully, like `reload -g`. Files loaded before the workers start need a restart.
The monitor does nothing in daemon mode (`-d`).

```
composer test   # phpunit
composer check  # psalm + php-cs-fixer (PER-CS)
composer fix    # apply the code style
composer bench  # phpbench micro benchmarks of the request conversion and response sending
```

## Long-running processes

- **Keep services stateless.** Handlers, middleware and services live as long as the worker. Request data must never end up in properties, use request attributes instead.
  State that can't be avoided is reset with [`WorkerLifecycle`](#lifecycle).
- **Expect long-lived connections to time out.** Reconnect on errors.
- **Avoid blocking for long.** A worker handles one request at a time.

In production, run the server behind a reverse proxy that handles TLS, compression and static files.

- **Use the forwarded headers of your proxy only.** Requests arrive over plain HTTP from the proxy, so the uri has the scheme `http` and `REMOTE_ADDR` is the address of the proxy.
  A PSR-15 middleware that trusts `X-Forwarded-For` and `X-Forwarded-Proto` only from the proxy's address restores the client address and scheme. Never trust them from anyone else, clients can send them too.

- **Install the `event` extension.** Without it, Workerman falls back to `select()`, which handles at most 1024 connections per worker.
- **Enable OPcache for the CLI**, `opcache.enable_cli=1`, and the JIT, e.g. `opcache.jit=tracing`.

## Performance

Requests per second for the same PSR-15 application on 4 CPU cores, compared with other ways to run PHP,
see [benchmark/](benchmark/README.md) for the method, all scenarios, latencies, memory and how each server handles HTTP edge cases.

| Server | Plaintext | Browser-like GET | Form POST | New connection per request |
|---|---:|---:|---:|---:|
| Workerman, without PSR-7 | 191,896 | 153,450 | 170,979 | 30,705 |
| **semperton/workerman** (`select()` loop) | **75,232** | **55,091** | **63,371** | **41,156** |
| FrankenPHP, worker mode | 14,404 | 14,845 | 15,696 | 11,429 |
| nginx + PHP-FPM | 11,794 | 10,558 | 11,477 | 10,629 |
| RoadRunner | 11,768 | 7,701 | 9,307 | 8,434 |

Workerman without PSR-7 serves about three times as many requests, which is the cost of creating PSR-7 objects for every request.
RoadRunner and FrankenPHP run a Go server next to the PHP workers on the same 4 cores, in exchange they serve HTTP/2, TLS and static files themselves.
With a real application, the time spent in the application and its I/O outweighs these differences.

## Limitations

Workerman parses the requests and answers these with a `400`, they are rare behind a reverse proxy:

- methods other than `GET`, `HEAD`, `POST`, `PUT`, `PATCH`, `DELETE` and `OPTIONS`, e.g. `QUERY` or WebDAV methods (RFC 9110 recommends `501`)
- request targets in absolute form (`GET http://host/path`) and `OPTIONS *`, only `/path?query` is accepted
- protocol versions other than HTTP/1.x (RFC 9110 recommends `505`)

Interim responses (`1xx`) and protocol upgrades are not supported, see [WebSockets](#websockets).

## Versioning

This package follows [Semantic Versioning](https://semver.org). The public API is `HttpServer`, `PushStream`, `WorkerLifecycle` and `FileMonitor`,
the classes in `Semperton\Workerman\Internal` and methods marked `@internal` may change in any release. Changes are listed in the [changelog](CHANGELOG.md).
Security issues are reported privately, see [SECURITY.md](SECURITY.md).

## License

MIT, see [LICENSE](LICENSE).
