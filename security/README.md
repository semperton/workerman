# Security tests

Hostile input for the server, from raw bytes to slow clients. Every finding becomes a test, so it stays fixed.

| When | What | Where |
|---|---|---|
| every CI run | 70+ raw requests: smuggling, framing, invalid heads, limits | [tests/Security](../tests/Security) |
| every CI run | Psalm taint analysis, request data reaching `include`, shell, `header()`, `unserialize()` ... | `composer check`, [psalm/taint-sources.phpstub](../psalm/taint-sources.phpstub) |
| weekly CI | fuzzing of the request parsing, 3 million runs | [fuzz/](fuzz) |
| weekly CI | mutation testing, tests must catch broken checks | [infection.json5](../infection.json5) |
| weekly CI | [Http11Probe](https://github.com/MDA2AV/Http11Probe), about 200 RFC 9110 / 9112 tests, directly and through nginx | [run.php](run.php) |
| weekly CI | [slowhttptest-ng](https://github.com/shekyan/slowhttptest): slowloris, slow body, slow read, range, 4000 connections | [run.php](run.php) |
| before a release | HTTP Garden and Burp, see [manual campaigns](#manual-campaigns) | |

The weekly job can also be started by hand (`workflow_dispatch`), its reports are uploaded as the `security-results` artifact.

## Raw requests

[tests/Security/requests.php](../tests/Security/requests.php) lists raw requests with the responses they must get, and whether the connection stays open.
`RawRequestTest` sends them to a real server, then sends another request on every open connection: a desync would answer it with the wrong response.

## The target

[target/server.php](target/server.php) is an echo server with small limits (64 KB bodies, short timeouts), built from the working copy with the `event` extension, like in production.
[compose.yaml](compose.yaml) runs it on `127.0.0.1:8080` and nginx in front of it on `127.0.0.1:8081`, with upstream keep-alive, where a desync would mix up requests.

```
php security/run.php                                    # Http11Probe and slowhttptest, about 10 minutes
php security/run.php --tools=http11probe
php security/run.php --tools=slowhttptest --duration=30
php security/run.php --keep                             # keeps the target running, e.g. for manual campaigns
```

Requires Docker and PHP on the host. The tools run in containers on the host network, their versions are pinned in `run.php`. Reports go to `security/results/`.

Http11Probe failures are compared with [baseline.json](baseline.json), which lists the accepted ones with a reason. The run fails on any other failure.
Through nginx, most failures are nginx's own answers (501 for unknown transfer codings, dropped invalid headers) or requests nginx normalizes: it buffers chunked bodies and forwards them with a `Content-Length`.

## Fuzzing

[fuzz/protocol.php](fuzz/protocol.php) feeds bytes through `HttpProtocol`, Workerman's `Http` and the `RequestConverter` with [PHP-Fuzzer](https://github.com/nikic/PHP-Fuzzer).
Besides crashes, it checks that a request is parsed the same when it arrives in parts: a prefix of a valid request must neither be rejected nor complete (request smuggling).

```
composer install -d security/fuzz
php security/fuzz/seed.php security/.cache/corpus/protocol    # the raw requests as the starting corpus
security/fuzz/vendor/bin/php-fuzzer fuzz security/fuzz/protocol.php security/.cache/corpus/protocol
security/fuzz/vendor/bin/php-fuzzer run-single security/fuzz/protocol.php crash-<hash>.txt
```

A crash is written to `crash-<hash>.txt`, add the minimized request to the raw requests before fixing it.

## Mutation testing

```
infection --threads=max     # needs pcov or xdebug
```

The CI fails below the minimum MSI, raise it as tests improve. Code that only runs in the server processes of `ServerTest` counts as uncovered, as its coverage isn't collected.

## Manual campaigns

Before a release, with the target running (`php security/run.php --keep`):

1. **[HTTP Garden](https://github.com/narfindustries/http-garden)** (GPL-3.0, not part of this repository) compares how servers and proxies parse the same bytes.
   Add an origin image to a local checkout like `images/puma`, building this repository and starting the target with `ECHO=garden`, which echoes requests in the format HTTP Garden expects.
   Fuzz directly and through its proxies, e.g. `payload '...' | transduce nginx_proxy haproxy | fanout | grid`. Any difference a proxy forwards is a desync candidate.
2. **[Burp Suite](https://portswigger.net/burp)** (Community is enough) with **HTTP Request Smuggler** (3.1 or later) and **HTTP Hacker**, against nginx on port 8081.
   Run the CL.0, 0.CL and `Expect` scans of the "HTTP/1.1 must die" research.
3. **A long soak**, e.g. [vegeta](https://github.com/tsenart/vegeta) at a constant rate for hours, while the memory of the workers stays flat.

## Findings

Reproduce every finding as a raw request, a protocol test or a property test first, then fix it.
Vulnerabilities are reported and fixed privately, see [SECURITY.md](../SECURITY.md). Issues of Workerman itself are reported upstream, this package mitigates them meanwhile.

Found so far:

- chunk extensions and trailers weren't validated, e.g. a bare LF that a proxy reads as a line end would end the body elsewhere (request smuggling)
- the body limit allowed up to 16 KB more, the size of the head was counted
- fragments and non-ASCII bytes in the request target were accepted
- a numeric header name crashed the conversion of the request, it was answered with a 500 and logged as an error
