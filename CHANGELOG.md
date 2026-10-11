# Changelog

All notable changes are listed here, the format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org).

## [Unreleased]

## [1.0.0] - unreleased

First stable release.

### Added

- `HttpServer` serves PSR-15 request handlers with Workerman, one handler per worker process
- Options for the body size, worker restarts after a number of requests, idle, header and stop timeouts
- Graceful reloads and stops, in-flight requests and streamed responses complete
- Requests converted like PHP-FPM populates them: server params, cookies, query params, form posts and uploaded files
- Streamed responses with backpressure, chunked encoding for bodies of unknown size
- `PushStream` for server-sent events
- `WorkerLifecycle` to reset the handler after every request and clean up when the worker stops
- `FileMonitor` reloads the workers when files change, for development

[Unreleased]: https://github.com/semperton/workerman/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/semperton/workerman/releases/tag/v1.0.0
