<?php

declare(strict_types=1);

namespace Semperton\Workerman\Internal;

use stdClass;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;

use function ctype_xdigit;
use function hexdec;
use function implode;
use function is_int;
use function ltrim;
use function microtime;
use function preg_match;
use function preg_replace;
use function str_ends_with;
use function stripos;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function substr_count;

/**
 * Workerman's HTTP protocol, extended by:
 * - "Expect: 100-continue", clients like curl wait a second for it before sending larger bodies
 * - the time a request head started to arrive, slow heads are answered with a 408 by ConnectionTimeouts
 * - the body size limit, Workerman only limits the whole request, and chunked bodies once a chunk is complete
 * - the line ends of the head, Workerman splits it at CRLF and keeps a bare LF or CR in the values
 * - chunked bodies: the syntax of chunk lines and trailers is validated, Workerman only reads the chunk sizes,
 *   and they are read in linear time, Workerman searches the rest of the buffer for every chunk and starts again with every read
 *
 * @internal
 */
final class HttpProtocol extends Http
{
    private const CONTINUE = "HTTP/1.1 100 Continue\r\n\r\n";
    // the phrase of the 413 workerman sends for too large bodies of known size, rfc 9110 calls it "Content Too Large"
    private const TOO_LARGE = "HTTP/1.1 413 Payload Too Large\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    private const BAD_REQUEST = "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";

    // rfc 9112 7.1.1 and rfc 9110 5.5, a token may contain '~'
    public const TOKEN = '[!#$%&\'*+\-.^_`|~0-9A-Za-z]+';
    private const CHUNK_LINE = '/^([0-9A-Fa-f]+)(?:[ \t]*;[ \t]*' . self::TOKEN
        . '(?:[ \t]*=[ \t]*(?:' . self::TOKEN . '|"(?:[\t \x21\x23-\x5b\x5d-\x7e\x80-\xff]|\\\\[\t\x20-\x7e\x80-\xff])*"))?)*$/D';
    private const TRAILER_LINE = '/^' . self::TOKEN . ':[\t\x20-\x7e\x80-\xff]*$/D';
    /** control characters are part of no line, e.g. a bare CR or LF */
    private const CONTROL = '~[\x00-\x08\x0a-\x1f\x7f]~';
    /** Workerman's limit of the digits of a chunk size */
    private const MAX_SIZE_DIGITS = 16;

    /**
     * Called for every read until the request is complete, returns its length or 0 if unknown yet
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        // set by Http for chunked requests, only its decode() removes it, which is skipped for requests from workerman's cache,
        // the next request on the connection would be decoded as chunked
        /** @psalm-suppress PossiblyNullPropertyFetch the context is always set */
        unset($connection->context->chunked);

        $headEnd = strpos($buffer, "\r\n\r\n");

        if ($headEnd === false) {
            /** @psalm-suppress PossiblyNullPropertyFetch, PossiblyNullPropertyAssignment the context is always set */
            $connection->context->headStartedAt ??= microtime(true);
            // a new request, the chunked body read so far belonged to another one
            /** @psalm-suppress PossiblyNullPropertyFetch the context is always set */
            unset($connection->context->chunkedAt);
            return parent::input($buffer, $connection);
        }

        /** @psalm-suppress PossiblyNullPropertyFetch the context is always set */
        unset($connection->context->headStartedAt);

        $length = parent::input($buffer, $connection);

        // Http has rejected the request
        if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
            return $length;
        }

        // a proxy that reads a bare LF or CR as a line end would see another head (request smuggling), rfc 9112 2.2
        $lines = substr_count($buffer, "\r\n", 0, $headEnd);
        if (substr_count($buffer, "\n", 0, $headEnd) !== $lines || substr_count($buffer, "\r", 0, $headEnd) !== $lines) {
            $connection->end(self::BAD_REQUEST, true);
            return 0;
        }

        $bufferLength = strlen($buffer);
        // as sent, chunked bodies including their framing, the length is 0 until a chunked body is complete
        $bodyLength = ($length > 0 ? $length : $bufferLength) - $headEnd - 4;
        /**
         * @psalm-suppress PossiblyNullPropertyFetch the context is always set
         * @var ?int $maxBodySize set by HttpServer, a small head would leave room for a larger body in the package size
         */
        $maxBodySize = $connection->context->maxBodySize ?? null;
        // e.g. a large chunk or endless trailers would be buffered without limit
        $tooLarge = ($length === 0 && $bufferLength > $connection->maxPackageSize)
            || ($maxBodySize !== null && $bodyLength > $maxBodySize);

        if ($tooLarge) {
            $connection->end(self::TOO_LARGE, true);
            return 0;
        }

        // only the head has arrived and the body is announced (0 = chunked)
        if (
            $bufferLength === $headEnd + 4
            && ($length === 0 || $length > $bufferLength)
            && self::expectsContinue(substr($buffer, 0, $headEnd))
        ) {
            $connection->send(self::CONTINUE, true);
        }

        return $length;
    }

    /**
     * Called by Http::input() for chunked bodies, returns the length of the request or 0 if unknown yet
     * Every read continues where the previous one stopped, at the first line not complete yet
     * A proxy that reads a chunk line differently, e.g. with a bare LF as line end, sees another end of the body (request smuggling)
     */
    protected static function inputChunked(string $buffer, TcpConnection $connection, int $headerLength): int
    {
        /** @var stdClass $context */
        $context = $connection->context;
        // for decode()
        $context->chunked = true;

        $length = strlen($buffer);
        /** @var array{int, bool} $state the position of the next line, and if it is a trailer */
        $state = $context->chunkedAt ?? [$headerLength, false];
        [$pos, $trailers] = $state[0] <= $length ? $state : [$headerLength, false];
        unset($context->chunkedAt);

        while (($lineEnd = strpos($buffer, "\r\n", $pos)) !== false) {
            $line = substr($buffer, $pos, $lineEnd - $pos);

            if ($trailers) {
                // an empty line ends the body, the next request may follow
                if ($line === '') {
                    $requestLength = $lineEnd + 2;
                    return $requestLength > $connection->maxPackageSize ? self::reject($connection, self::TOO_LARGE) : $requestLength;
                }
                if (preg_match(self::TRAILER_LINE, $line) !== 1) {
                    return self::reject($connection, self::BAD_REQUEST);
                }
                $pos = $lineEnd + 2;
                continue;
            }

            $size = self::chunkSize($line);
            if ($size === null) {
                return self::reject($connection, self::BAD_REQUEST);
            }

            $dataStart = $lineEnd + 2;
            if ($size === 0) {
                $trailers = true;
                $pos = $dataStart;
                continue;
            }

            // the data and its CRLF are incomplete, the line is read again, a huge size would overflow the position
            if ($size > $length - $dataStart - 2) {
                $context->chunkedAt = [$pos, false];
                return 0;
            }

            if ($buffer[$dataStart + $size] !== "\r" || $buffer[$dataStart + $size + 1] !== "\n") {
                return self::reject($connection, self::BAD_REQUEST);
            }

            $pos = $dataStart + $size + 2;
            if ($pos > $connection->maxPackageSize) {
                return self::reject($connection, self::TOO_LARGE);
            }
        }

        // an incomplete line, it may end with the CR of its CRLF
        $line = substr($buffer, $pos);
        if (str_ends_with($line, "\r")) {
            $line = substr($line, 0, -1);
        }
        if (preg_match(self::CONTROL, $line) === 1) {
            return self::reject($connection, self::BAD_REQUEST);
        }

        $context->chunkedAt = [$pos, $trailers];
        return 0;
    }

    /**
     * Called by Http::decode() for the chunked bodies validated by inputChunked(), the same request as from Workerman
     *
     * @return array{string, array<string, string>} the request with the body as Content-Length, and the trailers
     */
    protected static function decodeChunked(string $buffer, int $headerEnd): array
    {
        $head = (string) preg_replace('~\r\nTransfer-Encoding[ \t]*:[^\r]*~i', '', substr($buffer, 0, $headerEnd), 1);
        $parts = [];
        $trailers = [];
        $pos = $headerEnd + 4;

        while (($lineEnd = strpos($buffer, "\r\n", $pos)) !== false) {
            $size = self::chunkSize(substr($buffer, $pos, $lineEnd - $pos));
            $pos = $lineEnd + 2;

            if ($size === null) {
                break;
            }

            if ($size > 0) {
                $parts[] = substr($buffer, $pos, $size);
                $pos += $size + 2;
                continue;
            }

            // the trailers, until the empty line
            while (($lineEnd = strpos($buffer, "\r\n", $pos)) !== false && $lineEnd !== $pos) {
                $colon = strpos($buffer, ':', $pos);
                if ($colon !== false && $colon < $lineEnd) {
                    $trailers[strtolower(substr($buffer, $pos, $colon - $pos))] = ltrim(substr($buffer, $colon + 1, $lineEnd - $colon - 1));
                }
                $pos = $lineEnd + 2;
            }
            break;
        }

        $body = implode('', $parts);

        return [$head . "\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body, $trailers];
    }

    /**
     * The size of a valid chunk line, rfc 9112 7.1
     * Like Workerman, the size is only hex digits up to the extensions, at most 16, a large one has to fit into an int
     */
    private static function chunkSize(string $line): ?int
    {
        $semicolon = strpos($line, ';');
        $hex = $semicolon === false ? $line : substr($line, 0, $semicolon);

        if (!ctype_xdigit($hex) || isset($hex[self::MAX_SIZE_DIGITS])) {
            return null;
        }

        // the extensions, rare
        if ($semicolon !== false && preg_match(self::CHUNK_LINE, $line) !== 1) {
            return null;
        }

        $size = hexdec($hex);

        return is_int($size) ? $size : null;
    }

    /**
     * @return 0 the length is unknown, the connection is closed
     */
    private static function reject(TcpConnection $connection, string $response): int
    {
        $connection->end($response, true);

        return 0;
    }

    /**
     * HTTP/1.0 clients don't know 100 (Continue), rfc 9110 10.1.1
     * The header is looked for first, most requests don't send it
     */
    private static function expectsContinue(string $head): bool
    {
        return stripos($head, '100-continue') !== false
            && preg_match('~\r\nexpect[ \t]*:[ \t]*100-continue[ \t]*(?:\r\n|$)~i', $head) === 1
            && preg_match('~^[^\r]* HTTP/1\.1\r\n~', $head) === 1;
    }
}
