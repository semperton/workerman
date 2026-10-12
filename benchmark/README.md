# Benchmarks

Compares `semperton/workerman` with Workerman alone, the ceiling for this library, RoadRunner and FrankenPHP, the other ways to run a PSR-15 application in long-running workers, and nginx + PHP-FPM, the classic way to serve PHP. Every server runs the same PSR-15 application ([app/App.php](app/App.php)). The comparison covers throughput and latency, and also how each server handles HTTP/1.1 edge cases.

| Target | Version | Model | PHP workers | PSR-7 bridge |
|---|---|---|---|---|
| `semperton-event` | this repository | event loop, prefork | 4 | this library, `ext-event` loop |
| `semperton-select` | this repository | event loop, prefork | 4 | this library, `select()` loop |
| `workerman` | Workerman 5.2.3 | event loop, prefork | 4 | none: Workerman's own request and response, the ceiling for this library |
| `roadrunner` | RoadRunner 2025.1.15 | Go server, PHP workers over pipes | 8 (faster than 4) | `spiral/roadrunner-http` 4.1.0 |
| `frankenphp` | FrankenPHP 1.12.7 | Go server (Caddy), embedded PHP threads, worker mode | 4 (faster than 8) | `nyholm/psr7-server` |
| `nginx-fpm` | nginx 1.31.6, PHP-FPM | shared nothing, the app boots per request | 8 (`pm = static`) | `nyholm/psr7-server` |

All servers run PHP 8.4.26 with the same [php.ini](php.ini), including OPcache with the tracing JIT. FrankenPHP uses its own image, with PHP built thread safe (ZTS). The PSR-7 implementation is `nyholm/psr7`.

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
php benchmark/run.php                                   # everything, about 25 minutes
php benchmark/run.php --targets=semperton-event,workerman --scenarios=plaintext,json --duration=5 --rounds=1
php benchmark/probe.php 127.0.0.1:8080                  # probe any running server
SERVER_CPUS=0-7 CLIENT_CPUS=8-15 php benchmark/run.php  # other cpu sets
```

The raw results are written to [results/](results/), and the tables below are regenerated.

## Results

<!-- results:start -->
_2026-10-12, AMD Ryzen 7 2700X Eight-Core Processor, kernel 6.18.40.1-microsoft-standard-WSL2, Docker 29.8.2. Servers on CPUs 0-3, wrk on CPUs 8-11. Median of 3 rounds of 10 s, after 5 s of warmup._

#### GET / plaintext, 64 connections

| Server | Req/s | vs FPM | p50 | p99 | Errors |
|---|---:|---:|---:|---:|---:|
| Workerman, without PSR-7 | 191,896 | 16.3× | 286 µs | 989 µs | 0 |
| semperton/workerman (select) | 75,232 | 6.4× | 797 µs | 1.9 ms | 0 |
| semperton/workerman (event) | 73,930 | 6.3× | 833 µs | 2.2 ms | 0 |
| FrankenPHP, worker mode | 14,404 | 1.2× | 4.4 ms | 10.1 ms | 0 |
| nginx + PHP-FPM | 11,794 | 1.0× | 5.3 ms | 7.6 ms | 0 |
| RoadRunner | 11,768 | 1.0× | 5.3 ms | 8.6 ms | 0 |

#### GET / plaintext, 256 connections

| Server | Req/s | vs FPM | p50 | p99 | Errors |
|---|---:|---:|---:|---:|---:|
| Workerman, without PSR-7 | 212,932 | 19.1× | 1.1 ms | 4.9 ms | 0 |
| semperton/workerman (event) | 73,770 | 6.6× | 3.3 ms | 6.6 ms | 0 |
| semperton/workerman (select) | 72,117 | 6.5× | 3.4 ms | 6.1 ms | 0 |
| FrankenPHP, worker mode | 16,886 | 1.5× | 14.8 ms | 30.4 ms | 0 |
| RoadRunner | 11,364 | 1.0× | 22.1 ms | 31.1 ms | 0 |
| nginx + PHP-FPM | 11,171 | 1.0× | 22.5 ms | 28.3 ms | 0 |

#### GET /json, browser-like headers, query and cookies, 64 connections

| Server | Req/s | vs FPM | p50 | p99 | Errors |
|---|---:|---:|---:|---:|---:|
| Workerman, without PSR-7 | 153,450 | 14.5× | 373 µs | 1.2 ms | 0 |
| semperton/workerman (select) | 55,091 | 5.2× | 1.1 ms | 2.5 ms | 0 |
| semperton/workerman (event) | 54,247 | 5.1× | 1.1 ms | 2.7 ms | 0 |
| FrankenPHP, worker mode | 14,845 | 1.4× | 4.2 ms | 9.8 ms | 0 |
| nginx + PHP-FPM | 10,558 | 1.0× | 5.9 ms | 8.4 ms | 0 |
| RoadRunner | 7,701 | 0.7× | 8.1 ms | 12.1 ms | 0 |

#### POST /form, url encoded form, 64 connections

| Server | Req/s | vs FPM | p50 | p99 | Errors |
|---|---:|---:|---:|---:|---:|
| Workerman, without PSR-7 | 170,979 | 14.9× | 345 µs | 1.1 ms | 0 |
| semperton/workerman (select) | 63,371 | 5.5× | 927 µs | 2.2 ms | 0 |
| semperton/workerman (event) | 60,948 | 5.3× | 1.0 ms | 2.5 ms | 0 |
| FrankenPHP, worker mode | 15,696 | 1.4× | 3.8 ms | 12.5 ms | 0 |
| nginx + PHP-FPM | 11,477 | 1.0× | 5.4 ms | 7.5 ms | 0 |
| RoadRunner | 9,307 | 0.8× | 6.7 ms | 9.9 ms | 0 |

#### GET / with Connection: close, a new connection per request, 64 connections

| Server | Req/s | vs FPM | p50 | p99 | Errors |
|---|---:|---:|---:|---:|---:|
| semperton/workerman (select) | 41,156 | 3.9× | 1.2 ms | 1.9 ms | 0 |
| semperton/workerman (event) | 35,856 | 3.4× | 1.5 ms | 2.2 ms | 0 |
| Workerman, without PSR-7 | 30,705 | 2.9× | 681 µs | 2.0 ms | 0 |
| FrankenPHP, worker mode | 11,429 | 1.1× | 5.4 ms | 9.6 ms | 0 |
| nginx + PHP-FPM | 10,629 | 1.0× | 5.8 ms | 8.7 ms | 0 |
| RoadRunner | 8,434 | 0.8× | 7.3 ms | 11.1 ms | 0 |

#### Memory after the load

| Server | All processes |
|---|---:|
| semperton/workerman (event) | 20 MB |
| semperton/workerman (select) | 19 MB |
| Workerman, without PSR-7 | 19 MB |
| RoadRunner | 150 MB |
| FrankenPHP, worker mode | 44 MB |
| nginx + PHP-FPM | 24 MB |

#### Protocol behaviour

| Probe | semperton/workerman (event) | semperton/workerman (select) | Workerman, without PSR-7 | RoadRunner | FrankenPHP, worker mode | nginx + PHP-FPM |
|---|---|---|---|---|---|---|
| Pipelining | yes | yes | yes | yes | yes | yes |
| Expect: 100-continue | yes, 0 ms | yes, 0 ms | no, clients wait (curl: 1 s) | yes, 0 ms | yes, 0 ms | yes, 0 ms |
| Chunked request body | yes | yes | yes | yes | yes | yes |
| HEAD without body | yes | yes | no, 13 bytes | yes | yes | yes |
| Content-Length + Transfer-Encoding | rejected, 400 | rejected, 400 | rejected, 400 | accepted, kept open, 200 | accepted, kept open, 200 | rejected, 400 |
| Invalid header value | 400 | 400 | 200 | 400 | 400 | 500 |
| Header of 32 KB | 431 | 431 | 431 | 200 | 200 | 400 |
| Slow head (slowloris) | 408 after 20 s | 408 after 20 s | open after 25 s | open after 25 s | open after 25 s | open after 25 s |
<!-- results:end -->

## Reading the results

- **This is a hello-world benchmark.** It measures what a server adds to every request. A real application spends far more time in its own code and in I/O, so differences of 10–20% between servers rarely matter in practice. Being several times slower usually does.
- **PSR-7 has a price.** `workerman` skips PSR-7 entirely and shows how much time goes into building PSR-7 request and response objects. Every server with a PSR-7 bridge pays this cost.
- **`select` and `event` perform the same at these connection counts.** The `event` extension is still recommended, as `select()` can't handle more than 1024 connections per worker.
- **The Go servers spend CPU outside PHP.** RoadRunner passes every request over pipes to a PHP worker, FrankenPHP runs PHP threads inside Caddy. Their Go side shares the same 4 CPUs, which costs throughput on a small CPU budget, all servers use the 4 CPUs fully. In exchange they serve HTTP/2, HTTP/3, TLS and static files themselves.
- **The machine matters.** These numbers come from WSL2 on a desktop CPU, over loopback. Absolute values on other hardware will differ; the ranking is more stable than the numbers.

## Micro benchmarks

[micro/](micro/) contains [phpbench](https://phpbench.readthedocs.io) benchmarks for the request conversion and response sending of this library, which help when optimising its hot path. They run with OPcache and the tracing JIT, like the servers:

```
composer bench
vendor/bin/phpbench run --report=summary --tag=before   # then compare a change with --ref=before
```

