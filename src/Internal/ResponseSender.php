<?php

declare(strict_types=1);

namespace Semperton\Workerman\Internal;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Semperton\Workerman\PushStream;
use Throwable;
use UnexpectedValueException;
use Workerman\Connection\TcpConnection;

use function ctype_digit;
use function dechex;
use function explode;
use function gmdate;
use function min;
use function preg_match;
use function strcasecmp;
use function strlen;
use function strtolower;
use function substr;
use function time;
use function trim;

/**
 * Writes PSR-7 responses to Workerman connections
 *
 * Bodies of known size up to 2 MB are sent at once, larger or unknown sized ones are streamed
 * and wait for the client to read (backpressure), the connection receives no further requests meanwhile
 * A PushStream body is sent as the application pushes it
 * A body that ends before its announced length closes the connection, so the client notices
 * Exceptions are only thrown before anything was sent, so the caller can still send an error response
 *
 * @internal
 */
final class ResponseSender
{
    private const BUFFER_LIMIT = 2 * 1024 * 1024;
    private const CHUNK_SIZE = 64 * 1024;

    private int $dateTime = 0;
    private string $date = '';

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param string $connectionHeader of the request
     * @throws UnexpectedValueException for interim (1xx) responses
     */
    public function send(
        TcpConnection $connection,
        ResponseInterface $response,
        string $method,
        string $protocolVersion,
        string $connectionHeader,
    ): void {
        $status = $response->getStatusCode();
        if ($status < 200) {
            // e.g. 101 Switching Protocols, the connection can't be taken over
            throw new UnexpectedValueException("Interim responses are not supported, got status < $status >");
        }

        $http10 = $protocolVersion === '1.0';
        $keepAlive = $http10
            ? self::hasToken($connectionHeader, 'keep-alive')
            : !self::hasToken($connectionHeader, 'close');

        if (self::hasToken($response->getHeaderLine('Connection'), 'close')) {
            $keepAlive = false;
        }

        $body = $response->getBody();
        $size = null;
        $contentLength = null;
        $chunked = false;

        // rfc 9112 6.3, message body length
        if ($status === 304) {
            // the length a 200 would have, there is no body
            $contentLength = self::length($response->getHeaderLine('Content-Length'));
        } elseif ($status === 205) {
            // no content is allowed, rfc 9110 15.3.6
            $contentLength = 0;
        } elseif ($status !== 204) {
            $size = $body->getSize();
            // the length of a push is unknown, whatever the header says
            $contentLength = $size ?? ($body instanceof PushStream ? null : self::length($response->getHeaderLine('Content-Length')));
            // http/1.0 has no chunked encoding, closing the connection ends the body
            $chunked = $contentLength === null && !$http10;
            $keepAlive = $keepAlive && ($contentLength !== null || $chunked);
        }

        $head = $this->head($response, $contentLength, $chunked, $keepAlive, $http10);
        $withBody = $method !== 'HEAD' && $status !== 204 && $status !== 205 && $status !== 304;

        if ($body instanceof PushStream) {
            if ($withBody) {
                $this->push($connection, $head, $body, $chunked, $keepAlive);
                return;
            }
            // e.g. a HEAD request, nothing is pushed
            $body->close();
        }

        $writable = $connection->getSendBufferQueueSize() < $connection->maxSendBufferSize;

        // fast path, everything fits into the send buffer
        if ($writable && (!$withBody || ($size !== null && $size <= self::BUFFER_LIMIT))) {
            if ($withBody) {
                $content = $this->contents($body, $size);
                $keepAlive = $keepAlive && strlen($content) === $size;
                $head .= $content;
            }

            $connection->send($head, true);

            if (!$keepAlive) {
                self::close($connection);
            }

            return;
        }

        $this->stream($connection, $head, $withBody ? $body : null, $contentLength, $chunked, $keepAlive);
    }

    /**
     * The status line and headers, the framing headers of the application are replaced by the server's
     */
    private function head(ResponseInterface $response, ?int $contentLength, bool $chunked, bool $keepAlive, bool $http10): string
    {
        $reasonPhrase = $response->getReasonPhrase();
        // e.g. a line break would end the status line, not every PSR-7 implementation validates it, rfc 9112 4
        if (preg_match('/[^\t\x20-\x7e\x80-\xff]/', $reasonPhrase) === 1) {
            $reasonPhrase = '';
        }

        $head = 'HTTP/1.1 ' . $response->getStatusCode() . ' ' . $reasonPhrase . "\r\n";
        $hasDate = false;

        foreach ($response->getHeaders() as $name => $values) {
            $lowerName = strtolower((string) $name);
            if ($lowerName === 'content-length' || $lowerName === 'transfer-encoding' || $lowerName === 'connection') {
                continue;
            }
            $hasDate = $hasDate || $lowerName === 'date';
            foreach ($values as $value) {
                $head .= $name . ': ' . $value . "\r\n";
            }
        }

        if (!$hasDate) {
            $head .= 'Date: ' . $this->date() . "\r\n";
        }

        if ($contentLength !== null) {
            $head .= 'Content-Length: ' . $contentLength . "\r\n";
        } elseif ($chunked) {
            $head .= "Transfer-Encoding: chunked\r\n";
        }

        if (!$keepAlive) {
            $head .= "Connection: close\r\n";
        } elseif ($http10) {
            $head .= "Connection: keep-alive\r\n";
        }

        return $head . "\r\n";
    }

    /**
     * @param ?int $length announced by Content-Length, null = until the end of the body
     */
    private function stream(
        TcpConnection $connection,
        string $head,
        ?StreamInterface $body,
        ?int $length,
        bool $chunked,
        bool $keepAlive,
    ): void {
        if ($body?->isSeekable()) {
            $body->rewind();
        }

        $pending = $head;
        $remaining = $length;
        $connection->pauseRecv();
        $onClose = $body !== null ? $this->closeOnDisconnect($connection, $body) : $connection->onClose;

        $write = function (TcpConnection $connection) use (&$pending, &$remaining, $body, $chunked, $keepAlive, $onClose): void {
            try {
                while ($connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
                    // continued by onBufferDrain, once the client has read the buffered data
                    if ($connection->getSendBufferQueueSize() >= $connection->maxSendBufferSize) {
                        return;
                    }

                    if ($pending === '') {
                        if ($body === null || $remaining === 0) {
                            self::finish($connection, $body, true, $keepAlive, $onClose);
                            return;
                        }

                        $data = $body->eof() ? '' : $body->read($remaining === null ? self::CHUNK_SIZE : min(self::CHUNK_SIZE, $remaining));

                        if ($data === '') {
                            if ($chunked) {
                                $connection->send("0\r\n\r\n", true);
                            } elseif ($remaining !== null) {
                                $this->logger->warning('The response body is shorter than its Content-Length');
                            }
                            self::finish($connection, $body, $remaining === null, $keepAlive, $onClose);
                            return;
                        }

                        if ($remaining !== null) {
                            $remaining -= strlen($data);
                        }
                        $pending = $chunked ? dechex(strlen($data)) . "\r\n" . $data . "\r\n" : $data;
                    }

                    $connection->send($pending, true);
                    $pending = '';
                }

                // the connection was closed, e.g. by the client
                $connection->onBufferDrain = null;
                $body?->close();
            } catch (Throwable $exception) {
                // headers are already sent, closing is the only way to signal the error
                $this->logger->error('Unable to stream the response body', ['exception' => $exception]);
                $connection->onBufferDrain = null;
                $body?->close();
                $connection->close();
            }
        };

        $connection->onBufferDrain = $write;
        $write($connection);
    }

    /**
     * Sends the head and what was pushed so far, further pushes are sent right away
     * There is no queue, a push is refused while the client doesn't read
     */
    private function push(TcpConnection $connection, string $head, PushStream $body, bool $chunked, bool $keepAlive): void
    {
        $frame = static fn(string $data): string => $chunked ? dechex(strlen($data)) . "\r\n" . $data . "\r\n" : $data;
        $buffered = $body->getContents();

        $connection->pauseRecv();
        // the client is gone or doesn't read, e.g. earlier pipelined responses
        if ($connection->send($buffered === '' ? $head : $head . $frame($buffered), true) === false) {
            self::finish($connection, $body, false, $keepAlive, $connection->onClose);
            return;
        }

        $onClose = $this->closeOnDisconnect($connection, $body);
        $body->attach(
            static fn(string $data): bool => $connection->send($frame($data), true) !== false,
            static function (bool $complete) use ($connection, $body, $chunked, $keepAlive, $onClose): void {
                if ($complete && $chunked) {
                    $connection->send("0\r\n\r\n", true);
                }
                self::finish($connection, $body, $complete, $keepAlive, $onClose);
            },
        );
    }

    /**
     * Closes the body once the client is gone, e.g. while the response waits for it to read, no drain follows then
     *
     * @return ?callable the previous callback, e.g. set by the application on the worker, it is still called
     */
    private function closeOnDisconnect(TcpConnection $connection, StreamInterface $body): ?callable
    {
        $onClose = $connection->onClose;
        $connection->onClose = function (TcpConnection $connection) use ($body, $onClose): void {
            try {
                $body->close();
            } catch (Throwable $exception) {
                // workerman would stop the worker, with all its connections
                $this->logger->error($exception->getMessage(), ['exception' => $exception]);
            }
            if ($onClose !== null) {
                $onClose($connection);
            }
        };

        return $onClose;
    }

    /**
     * Ends a streamed or pushed body, the connection receives further requests or is closed
     *
     * @param ?callable $onClose the callback before the body was sent
     */
    private static function finish(TcpConnection $connection, ?StreamInterface $body, bool $complete, bool $keepAlive, ?callable $onClose): void
    {
        $connection->onBufferDrain = null;
        $connection->onClose = $onClose;

        if ($complete && $keepAlive) {
            $connection->resumeRecv();
        } else {
            self::close($connection);
        }

        $body?->close();
    }

    /**
     * The body cut to its announced size, a shorter one is logged
     */
    private function contents(StreamInterface $body, int $size): string
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $content = $body->getContents();
        $length = strlen($content);

        if ($length > $size) {
            return substr($content, 0, $size);
        }

        if ($length < $size) {
            $this->logger->warning('The response body is shorter than its size');
        }

        return $content;
    }

    /**
     * Like nginx's lingering close: once everything is handed to the os and the client has sent nothing more,
     * the connection is closed right away, lingering would keep every short connection for a second
     * Otherwise it waits for the send buffer, then lingers, so a reset doesn't destroy the response
     */
    private static function close(TcpConnection $connection): void
    {
        if ($connection->getSendBufferQueueSize() === 0 && $connection->getRecvBufferQueueSize() === 0) {
            $connection->close();
        } else {
            $connection->end();
        }
    }

    /**
     * Connection options are comma separated and case-insensitive, rfc 9110 7.6.1
     */
    private static function hasToken(string $header, string $token): bool
    {
        if ($header === '') {
            return false;
        }

        foreach (explode(',', $header) as $value) {
            if (strcasecmp(trim($value), $token) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * A valid Content-Length header, rfc 9110 8.6
     */
    private static function length(string $header): ?int
    {
        return $header !== '' && ctype_digit($header) ? (int) $header : null;
    }

    private function date(): string
    {
        $time = time();
        if ($this->dateTime !== $time) {
            $this->dateTime = $time;
            $this->date = gmdate('D, d M Y H:i:s', $time) . ' GMT';
        }

        return $this->date;
    }
}
