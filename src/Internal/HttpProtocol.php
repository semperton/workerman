<?php

declare(strict_types=1);

namespace Semperton\Workerman\Internal;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Response;

use function hexdec;
use function is_int;
use function microtime;
use function preg_match;
use function str_ends_with;
use function strlen;
use function strpos;
use function substr;

/**
 * Workerman's HTTP protocol, delegated to and extended by:
 * - "Expect: 100-continue", clients like curl wait a second for it before sending larger bodies
 * - the time a request head started to arrive, slow heads are answered with a 408 by ConnectionTimeouts
 * - the body size limit, Workerman only limits the whole request, and chunked bodies once a chunk is complete
 * - the syntax of chunk lines and trailers, Workerman only reads the chunk sizes
 *
 * @internal
 */
final class HttpProtocol
{
    private const CONTINUE = "HTTP/1.1 100 Continue\r\n\r\n";
    // the phrase of the 413 workerman sends for too large bodies of known size, rfc 9110 calls it "Content Too Large"
    private const TOO_LARGE = "HTTP/1.1 413 Payload Too Large\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    private const BAD_REQUEST = "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";

    // rfc 9112 7.1.1 and rfc 9110 5.5, a token may contain '~'
    private const TOKEN = '[!#$%&\'*+\-.^_`|~0-9A-Za-z]+';
    private const CHUNK_LINE = '/^([0-9A-Fa-f]+)(?:[ \t]*;[ \t]*' . self::TOKEN
        . '(?:[ \t]*=[ \t]*(?:' . self::TOKEN . '|"(?:[\t \x21\x23-\x5b\x5d-\x7e\x80-\xff]|\\\\[\t\x20-\x7e\x80-\xff])*"))?)*$/D';
    private const TRAILER_LINE = '/^' . self::TOKEN . ':[\t\x20-\x7e\x80-\xff]*$/D';
    /** control characters are part of no line, e.g. a bare CR or LF */
    private const CONTROL = '~[\x00-\x08\x0a-\x1f\x7f]~';

    /**
     * Called for every read until the request is complete, returns its length or 0 if unknown yet
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        $headEnd = strpos($buffer, "\r\n\r\n");

        if ($headEnd === false) {
            /** @psalm-suppress PossiblyNullPropertyFetch, PossiblyNullPropertyAssignment the context is always set */
            $connection->context->headStartedAt ??= microtime(true);
            return Http::input($buffer, $connection);
        }

        /** @psalm-suppress PossiblyNullPropertyFetch the context is always set */
        unset($connection->context->headStartedAt);

        $length = Http::input($buffer, $connection);

        // Http has rejected the request
        if ($connection->getStatus() !== TcpConnection::STATUS_ESTABLISHED) {
            return $length;
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

        // a proxy that reads a chunk line differently, e.g. with a bare LF as line end, sees another end of the body (request smuggling)
        if (isset($connection->context->chunked) && !self::validChunks($buffer, $headEnd + 4)) {
            $connection->end(self::BAD_REQUEST, true);
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

    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        return Http::decode($buffer, $connection);
    }

    public static function encode(Response|string $response, TcpConnection $connection): string
    {
        return Http::encode($response, $connection);
    }

    /**
     * The chunk lines and trailers received so far, the chunk data is skipped
     */
    private static function validChunks(string $buffer, int $pos): bool
    {
        $trailers = false;
        $length = strlen($buffer);

        // the position is past the end while chunk data is incomplete
        while ($pos <= $length && ($lineEnd = strpos($buffer, "\r\n", $pos)) !== false) {
            $line = substr($buffer, $pos, $lineEnd - $pos);
            $pos = $lineEnd + 2;

            if ($trailers) {
                // an empty line ends the body, the next request may follow
                if ($line === '') {
                    return true;
                }
                if (preg_match(self::TRAILER_LINE, $line) !== 1) {
                    return false;
                }
                continue;
            }

            if (preg_match(self::CHUNK_LINE, $line, $matches) !== 1) {
                return false;
            }

            $size = hexdec($matches[1]);
            if (!is_int($size)) {
                return false;
            }

            if ($size === 0) {
                $trailers = true;
            } else {
                // the data and its CRLF, which workerman checks
                $pos += $size + 2;
            }
        }

        // an incomplete line, it may end with the CR of its CRLF
        $line = substr($buffer, $pos);
        if (str_ends_with($line, "\r")) {
            $line = substr($line, 0, -1);
        }

        return preg_match(self::CONTROL, $line) !== 1;
    }

    /**
     * HTTP/1.0 clients don't know 100 (Continue), rfc 9110 10.1.1
     */
    private static function expectsContinue(string $head): bool
    {
        return preg_match('~^[^\r]* HTTP/1\.1\r\n~', $head) === 1
            && preg_match('~\r\nexpect[ \t]*:[ \t]*100-continue[ \t]*(?:\r\n|$)~i', $head) === 1;
    }
}
