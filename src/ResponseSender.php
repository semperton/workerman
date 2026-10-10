<?php

declare(strict_types=1);

namespace Semperton\Workerman;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Workerman\Connection\TcpConnection;

use function dechex;
use function gmdate;
use function str_contains;
use function strlen;
use function strtolower;
use function time;

/**
 * Writes PSR-7 responses to Workerman connections
 *
 * Bodies of known size up to 2 MB are sent at once, larger or unknown sized ones are streamed
 * and wait for the client to read (backpressure), the connection receives no further requests meanwhile
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
     */
    public function send(
        TcpConnection $connection,
        ResponseInterface $response,
        string $method,
        string $protocolVersion,
        string $connectionHeader,
    ): void {
        $http10 = $protocolVersion === '1.0';
        $keepAlive = $http10
            ? str_contains(strtolower($connectionHeader), 'keep-alive')
            : !str_contains(strtolower($connectionHeader), 'close');

        if (str_contains(strtolower($response->getHeaderLine('Connection')), 'close')) {
            $keepAlive = false;
        }

        $status = $response->getStatusCode();
        $body = $response->getBody();
        $size = null;
        $contentLength = null;
        $chunked = false;

        // rfc 9112 6.3, message body length
        if ($status === 304) {
            $contentLength = $response->getHeaderLine('Content-Length') ?: null;
        } elseif ($status >= 200 && $status !== 204) {
            $size = $body->getSize();
            if ($size !== null) {
                $contentLength = (string) $size;
            } elseif ($response->hasHeader('Content-Length')) {
                $contentLength = $response->getHeaderLine('Content-Length');
            } elseif ($http10) {
                // http/1.0 has no chunked encoding, closing the connection ends the body
                $keepAlive = false;
            } else {
                $chunked = true;
            }
        }

        $head = 'HTTP/1.1 ' . $status . ' ' . $response->getReasonPhrase() . "\r\n";

        foreach ($response->getHeaders() as $name => $values) {
            $lowerName = strtolower((string) $name);
            if ($lowerName === 'content-length' || $lowerName === 'transfer-encoding' || $lowerName === 'connection') {
                continue;
            }
            foreach ($values as $value) {
                $head .= $name . ': ' . $value . "\r\n";
            }
        }

        if (!$response->hasHeader('Date')) {
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

        $head .= "\r\n";

        $withBody = $method !== 'HEAD' && $status >= 200 && $status !== 204 && $status !== 304;
        $writable = $connection->getSendBufferQueueSize() < $connection->maxSendBufferSize;

        // fast path, everything fits into the send buffer
        if ($writable && (!$withBody || ($size !== null && $size <= self::BUFFER_LIMIT))) {
            if ($withBody) {
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                $head .= $body->getContents();
            }

            $connection->send($head, true);

            if (!$keepAlive) {
                $connection->close();
            }

            return;
        }

        $this->stream($connection, $head, $withBody ? $body : null, $chunked, $keepAlive);
    }

    private function stream(
        TcpConnection $connection,
        string $head,
        ?StreamInterface $body,
        bool $chunked,
        bool $keepAlive,
    ): void {
        if ($body?->isSeekable()) {
            $body->rewind();
        }

        $pending = $head;
        $connection->pauseRecv();

        $finish = static function (TcpConnection $connection) use ($body, $keepAlive): void {
            $connection->onBufferDrain = null;
            $body?->close();

            if ($keepAlive) {
                $connection->resumeRecv();
            } else {
                $connection->close();
            }
        };

        $write = function (TcpConnection $connection) use (&$pending, $body, $chunked, $finish): void {
            try {
                while ($connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
                    // continued by onBufferDrain, once the client has read the buffered data
                    if ($connection->getSendBufferQueueSize() >= $connection->maxSendBufferSize) {
                        return;
                    }

                    if ($pending === '') {
                        $data = $body === null || $body->eof() ? '' : $body->read(self::CHUNK_SIZE);

                        if ($data === '') {
                            if ($chunked && $body !== null) {
                                $connection->send("0\r\n\r\n", true);
                            }
                            $finish($connection);
                            return;
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
                $connection->close();
            }
        };

        $connection->onBufferDrain = $write;
        $write($connection);
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
