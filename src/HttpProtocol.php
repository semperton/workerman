<?php

declare(strict_types=1);

namespace Semperton\Workerman;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;

use function microtime;
use function preg_match;
use function strlen;
use function strpos;
use function substr;

/**
 * Workerman's HTTP protocol, extended by:
 * - "Expect: 100-continue", clients like curl wait a second for it before sending larger bodies
 * - the time a request head started to arrive, slow heads are answered with a 408 by ConnectionTimeouts
 *
 * @internal
 */
final class HttpProtocol extends Http
{
    private const CONTINUE = "HTTP/1.1 100 Continue\r\n\r\n";

    /**
     * Called for every read until the request is complete, returns its length or 0 if unknown yet
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        $headEnd = strpos($buffer, "\r\n\r\n");

        if ($headEnd === false) {
            /** @psalm-suppress PossiblyNullPropertyFetch, PossiblyNullPropertyAssignment the context is always set */
            $connection->context->headStartedAt ??= microtime(true);
            return parent::input($buffer, $connection);
        }

        /** @psalm-suppress PossiblyNullPropertyFetch the context is always set */
        unset($connection->context->headStartedAt);

        $length = parent::input($buffer, $connection);
        $bufferLength = strlen($buffer);

        // only the head has arrived, the body is announced (0 = chunked), and the parent rejected nothing
        if (
            $bufferLength === $headEnd + 4
            && ($length === 0 || $length > $bufferLength)
            && $connection->getStatus() === TcpConnection::STATUS_ESTABLISHED
            && self::expectsContinue(substr($buffer, 0, $headEnd))
        ) {
            $connection->send(self::CONTINUE, true);
        }

        return $length;
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
