# Benchmarks

Compares `semperton/workerman` with Workerman alone, the ceiling for this library, and nginx + PHP-FPM, the classic way to serve PHP. Every server runs the same PSR-15 application ([app/App.php](app/App.php)). The comparison covers throughput and latency, and also how each server handles HTTP/1.1 edge cases.

| Target | Version | Model | PHP workers | PSR-7 bridge |
|---|---|---|---|---|
| `semperton-event` | this repository | event loop, prefork | 4 | this library, `ext-event` loop |
| `semperton-select` | this repository | event loop, prefork | 4 | this library, `select()` loop |
| `workerman` | Workerman 5.2.3 | event loop, prefork | 4 | none: Workerman's own request and response, the ceiling for this library |
| `nginx-fpm` | nginx 1.31.6, PHP-FPM | shared nothing, the app boots per request | 8 (`pm = static`) | `nyholm/psr7-server` |

All servers run PHP 8.4.26 with the same [php.ini](php.ini), including OPcache with the tracing JIT. The PSR-7 implementation is `nyholm/psr7`.

## Method

- One server runs at a time, on the host network, pinned to `SERVER_CPUS` (default `0-3`). [wrk](https://github.com/wg/wrk) runs pinned to `CLIENT_CPUS` (default `8-11`) with 4 threads, so client and server don't compete for CPUs.
- Each scenario gets a warmup of 5 s, then 3 rounds of 10 s. The table shows the round with the median throughput.
- Scenarios ([wrk/bench.lua](wrk/bench.lua)):
  - `GET /` plaintext at 64 and 256 connections: the overhead of the server itself.
  - `GET /json` with browser-like headers, a query and cookies: the cost of converting a realistic request.
  - `POST /form`: a URL-encoded form body.
  - `GET /` with `Connection: close`: a new TCP connection per request.
- Memory is the total of all containers of a server, read by `docker stats` after the load.
- [probe.php](probe.php) sends raw requests for HTTP/1.1 edge cases: pipelining, `Expect: 100-continue`, chunked request bodies, `HEAD`, request smuggling (`Content-Length` with `Transfer-Encoding`), invalid header values, oversized heads, and slowloris (a request head that never completes, observed for up to 25 s).

## Running

Requires Docker with Compose and PHP 8.2+ on the host.

```
php benchmark/run.php                                   # everything, about 15 minutes
php benchmark/run.php --targets=semperton-event,workerman --scenarios=plaintext,json --duration=5 --rounds=1
php benchmark/probe.php 127.0.0.1:8080                  # probe any running server
SERVER_CPUS=0-7 CLIENT_CPUS=8-15 php benchmark/run.php  # other cpu sets
```

The raw results are written to [results/](results/), and the tables below are regenerated.

## Results

<!-- results:start -->
_2026-10-10, AMD Ryzen 7 2700X Eight-Core Processor, kernel 6.18.40.1-microsoft-standard-WSL2, Docker 29.8.1. Servers on CPUs 0-3, wrk on CPUs 8-11. Median of 3 rounds of 10 s, after 5 s of warmup._

#### GET / plaintext, 64 connections

| Server | Req/s | vs FPM | p50 | p99 | Errors |
|---|---:|---:|---:|---:|---:|
| Workerman, without PSR-7 | 199,151 | 16.3× | 277 µs | 987 µs | 0 |
| semperton/workerman (select) | 70,479 | 5.8× | 838 µs | 2.0 ms | 0 |
| semperton/workerman (event) | 69,900 | 5.7× | 902 µs | 2.3 ms | 0 |
| nginx + PHP-FPM | 12,238 | 1.0× | 5.1 ms | 6.7 ms | 0 |

#### GET / plaintext, 256 connections

| Server | Req/s | vs FPM | p50 | p99 | Errors |
|---|---:|---:|---:|---:|---:|
| Workerman, without PSR-7 | 222,157 | 18.6× | 1.1 ms | 2.8 ms | 0 |
| semperton/workerman (select) | 71,423 | 6.0× | 3.3 ms | 6.2 ms | 0 |
| semperton/workerman (event) | 70,793 | 5.9× | 3.4 ms | 9.2 ms | 0 |
| nginx + PHP-FPM | 11,970 | 1.0× | 21.0 ms | 26.8 ms | 0 |

#### GET /json, browser-like headers, query and cookies, 64 connections

| Server | Req/s | vs FPM | p50 | p99 | Errors |
|---|---:|---:|---:|---:|---:|
| Workerman, without PSR-7 | 156,519 | 14.0× | 369 µs | 1.1 ms | 0 |
| semperton/workerman (select) | 52,485 | 4.7× | 1.1 ms | 2.9 ms | 0 |
| semperton/workerman (event) | 51,647 | 4.6× | 1.1 ms | 3.0 ms | 0 |
| nginx + PHP-FPM | 11,187 | 1.0× | 5.6 ms | 7.7 ms | 0 |

#### POST /form, url encoded form, 64 connections

| Server | Req/s | vs FPM | p50 | p99 | Errors |
|---|---:|---:|---:|---:|---:|
| Workerman, without PSR-7 | 174,371 | 14.9× | 329 µs | 1.0 ms | 0 |
| semperton/workerman (select) | 60,675 | 5.2× | 966 µs | 2.4 ms | 0 |
| semperton/workerman (event) | 58,726 | 5.0× | 961 µs | 2.6 ms | 0 |
| nginx + PHP-FPM | 11,717 | 1.0× | 5.4 ms | 7.3 ms | 0 |

#### GET / with Connection: close, a new connection per request, 64 connections

| Server | Req/s | vs FPM | p50 | p99 | Errors |
|---|---:|---:|---:|---:|---:|
| semperton/workerman (select) | 39,072 | 3.7× | 1.2 ms | 2.0 ms | 0 |
| semperton/workerman (event) | 35,238 | 3.3× | 1.5 ms | 2.3 ms | 0 |
| Workerman, without PSR-7 | 31,467 | 2.9× | 670 µs | 1.9 ms | 0 |
| nginx + PHP-FPM | 10,679 | 1.0× | 5.8 ms | 8.7 ms | 0 |

#### Memory after the load

| Server | All processes |
|---|---:|
| semperton/workerman (event) | 20 MB |
| semperton/workerman (select) | 19 MB |
| Workerman, without PSR-7 | 18 MB |
| nginx + PHP-FPM | 22 MB |

#### Protocol behaviour

| Probe | semperton/workerman (event) | semperton/workerman (select) | Workerman, without PSR-7 | nginx + PHP-FPM |
|---|---|---|---|---|
| Pipelining | yes | yes | yes | yes |
| Expect: 100-continue | yes, 0 ms | yes, 0 ms | no, clients wait (curl: 1 s) | yes, 0 ms |
| Chunked request body | yes | yes | yes | yes |
| HEAD without body | yes | yes | no, 13 bytes | yes |
| Content-Length + Transfer-Encoding | rejected, 400 | rejected, 400 | rejected, 400 | rejected, 400 |
| Invalid header value | 400 | 400 | 200 | 500 |
| Header of 32 KB | 431 | 431 | 431 | 400 |
| Slow head (slowloris) | 408 after 20 s | 408 after 20 s | open after 25 s | open after 25 s |
<!-- results:end -->

## Reading the results

- **This is a hello-world benchmark.** It measures what a server adds to every request. A real application spends far more time in its own code and in I/O, so differences of 10–20% between servers rarely matter in practice. Being several times slower usually does.
- **PSR-7 has a price.** `workerman` skips PSR-7 entirely and shows how much time goes into building PSR-7 request and response objects. Every server with a PSR-7 bridge pays this cost.
- **`select` and `event` perform the same at these connection counts.** The `event` extension is still recommended, as `select()` can't handle more than 1024 connections per worker.
- **The machine matters.** These numbers come from WSL2 on a desktop CPU, over loopback. Absolute values on other hardware will differ; the ranking is more stable than the numbers.

## Micro benchmarks

[micro/](micro/) contains [phpbench](https://phpbench.readthedocs.io) benchmarks for the request conversion and response sending of this library, which help when optimising its hot path. They run with OPcache and the tracing JIT, like the servers:

```
composer bench
vendor/bin/phpbench run --report=summary --tag=before   # then compare a change with --ref=before
```

