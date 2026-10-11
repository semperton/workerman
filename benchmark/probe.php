<?php

declare(strict_types=1);

/**
 * Probes how a server handles HTTP/1.1 edge cases, with raw sockets
 * The app must answer GET / and echo the body of POST /echo, see app/App.php
 *
 * php benchmark/probe.php [host:port]
 */

const PROBE_GET = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
const PROBE_SLOW_HEAD_LIMIT = 25.0;

/**
 * @return array<string, string> probe => result
 */
function probe(string $address = '127.0.0.1:8080'): array
{
    $probes = [
        'Pipelining' => static function () use ($address): string {
            $result = exchange($address, str_repeat(PROBE_GET, 3), 2.0, static fn(string $data) => substr_count($data, 'HTTP/1.1 200') === 3);
            $responses = substr_count($result['data'], 'HTTP/1.1 200');

            return $responses === 3 ? 'yes' : "no, $responses of 3 responses";
        },
        'Expect: 100-continue' => static function () use ($address): string {
            $head = "POST /echo HTTP/1.1\r\nHost: localhost\r\nExpect: 100-continue\r\nContent-Length: 4\r\n\r\n";
            $result = exchange($address, $head, 1.5, static fn(string $data) => str_contains($data, "\r\n\r\n"));

            return match (true) {
                str_starts_with($result['data'], 'HTTP/1.1 100') => sprintf('yes, %d ms', $result['elapsed'] * 1000),
                $result['data'] === '' => 'no, clients wait (curl: 1 s)',
                default => 'answered ' . status($result['data']),
            };
        },
        'Chunked request body' => static function () use ($address): string {
            $request = "POST /echo HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n2\r\npi\r\n2\r\nng\r\n0\r\n\r\n";
            $result = exchange($address, $request, 2.0, static fn(string $data) => str_contains($data, 'ping'));

            return body($result['data']) === 'ping' ? 'yes' : 'no, ' . status($result['data']);
        },
        'HEAD without body' => static function () use ($address): string {
            $result = exchange($address, "HEAD / HTTP/1.1\r\nHost: localhost\r\n\r\n", 1.0);
            [, $body] = explode("\r\n\r\n", $result['data'], 2) + [1 => ''];

            return $body === '' ? 'yes' : 'no, ' . strlen($body) . ' bytes';
        },
        'Content-Length + Transfer-Encoding' => static function () use ($address): string {
            // request smuggling, rfc 9112 6.1: reject, or ignore Content-Length and close the connection afterwards
            $request = "POST /echo HTTP/1.1\r\nHost: localhost\r\nContent-Length: 4\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n";
            $result = exchange($address, $request, 2.0);
            $status = status($result['data']);

            return match (true) {
                str_starts_with($status, '4') => "rejected, $status",
                $result['closed'] => "accepted, closed, $status",
                default => "accepted, kept open, $status",
            };
        },
        'Invalid header value' => static function () use ($address): string {
            $result = exchange($address, "GET / HTTP/1.1\r\nHost: localhost\r\nX-Test: a\x01b\r\n\r\n", 2.0);

            return status($result['data']);
        },
        'Header of 32 KB' => static function () use ($address): string {
            $request = "GET / HTTP/1.1\r\nHost: localhost\r\nX-Large: " . str_repeat('a', 32 * 1024) . "\r\n\r\n";

            return status(exchange($address, $request, 2.0)['data']);
        },
        'Slow head (slowloris)' => static function () use ($address): string {
            $result = exchange($address, "GET / HTTP/1.1\r\nHost: loc", PROBE_SLOW_HEAD_LIMIT);

            if (!$result['closed']) {
                return sprintf('open after %d s', PROBE_SLOW_HEAD_LIMIT);
            }

            $answer = $result['data'] === '' ? 'closed' : status($result['data']);
            return sprintf('%s after %d s', $answer, $result['elapsed']);
        },
    ];

    return array_map(static fn(Closure $probe): string => $probe(), $probes);
}

/**
 * Sends the data and reads until the server closes the connection, the timeout passes, or $done returns true
 *
 * @param ?Closure(string): bool $done
 * @return array{data: string, elapsed: float, closed: bool}
 */
function exchange(string $address, string $data, float $timeout, ?Closure $done = null): array
{
    $socket = @stream_socket_client("tcp://$address", $errorCode, $errorMessage, 2.0);
    if ($socket === false) {
        throw new RuntimeException("Unable to connect to $address: $errorMessage");
    }

    $start = microtime(true);
    fwrite($socket, $data);
    stream_set_blocking($socket, false);

    $received = '';
    $closed = false;

    while (($left = $start + $timeout - microtime(true)) > 0) {
        $read = [$socket];
        $write = $except = null;
        if (stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1e6)) === 0) {
            break;
        }

        $chunk = (string) fread($socket, 65536);
        if ($chunk === '' && feof($socket)) {
            $closed = true;
            break;
        }

        $received .= $chunk;
        if ($done !== null && $done($received)) {
            break;
        }
    }

    $elapsed = microtime(true) - $start;
    fclose($socket);

    return ['data' => $received, 'elapsed' => $elapsed, 'closed' => $closed];
}

/**
 * The body of the first response, chunked bodies are decoded
 */
function body(string $data): string
{
    [$head, $body] = explode("\r\n\r\n", $data, 2) + [1 => ''];

    if (preg_match('/\r\nTransfer-Encoding:\s*chunked/i', $head) !== 1) {
        return $body;
    }

    $decoded = '';
    while (preg_match('/^([0-9a-f]+)[^\r]*\r\n/i', $body, $matches) === 1 && ($size = (int) hexdec($matches[1])) > 0) {
        $decoded .= substr($body, strlen($matches[0]), $size);
        $body = substr($body, strlen($matches[0]) + $size + 2);
    }

    return $decoded;
}

/**
 * The status code and reason of the first response, or what happened instead
 */
function status(string $data): string
{
    if ($data === '') {
        return 'no response';
    }

    return preg_match('~^HTTP/1\.[01] (\d{3})~', $data, $matches) === 1 ? $matches[1] : 'invalid response';
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    foreach (probe($argv[1] ?? '127.0.0.1:8080') as $name => $result) {
        printf("%-36s %s\n", $name, $result);
    }
}
