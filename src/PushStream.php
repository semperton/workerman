<?php

declare(strict_types=1);

namespace Semperton\Workerman;

use Closure;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

use function strlen;
use function substr;

use const SEEK_SET;

/**
 * A response body the application pushes to while the response is sent, e.g. server-sent events
 * It is sent chunked (closed by end() on HTTP/1.0), the connection receives no further requests meanwhile
 *
 * Data pushed before the response is sent is buffered and readable like any stream
 *
 * @api
 */
final class PushStream implements StreamInterface
{
    private string $buffer = '';
    private bool $ended = false;
    private bool $closed = false;
    /** @var ?Closure(string): bool */
    private ?Closure $send = null;
    /** @var ?Closure(bool): void */
    private ?Closure $finish = null;
    /** @var list<Closure(): void> */
    private array $closeListeners = [];

    /**
     * @return bool false if the data was not taken, as the stream has ended or the client doesn't read (backpressure)
     */
    public function push(string $data): bool
    {
        if ($this->ended) {
            return false;
        }

        if ($this->send === null) {
            $this->buffer .= $data;
            return true;
        }

        return $data === '' || ($this->send)($data);
    }

    /**
     * Completes the body, the connection stays open for further requests
     */
    public function end(): void
    {
        if (!$this->ended) {
            $this->ended = true;
            $this->release(true);
        }
    }

    /**
     * Called once the stream is closed: the client is gone, the body has ended or the worker stops
     * e.g. to remove timers and subscriptions
     * Every listener is called, the first exception is thrown afterwards, or logged if the client is gone
     *
     * @param Closure(): void $listener
     */
    public function onClose(Closure $listener): void
    {
        if ($this->closed) {
            $listener();
        } else {
            $this->closeListeners[] = $listener;
        }
    }

    /**
     * @internal called by the response sender once the head is sent
     * @param Closure(string): bool $send
     * @param Closure(bool): void $finish called once, with true if the body is complete
     */
    public function attach(Closure $send, Closure $finish): void
    {
        $this->send = $send;
        $this->finish = $finish;

        if ($this->ended) {
            $this->release(!$this->closed);
        }
    }

    /**
     * Aborts the body if it hasn't ended, the connection is closed
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->ended = true;
        $this->release(false);

        $listeners = $this->closeListeners;
        $this->closeListeners = [];
        $error = null;
        // e.g. a timer that keeps pushing would never be removed
        foreach ($listeners as $listener) {
            try {
                $listener();
            } catch (Throwable $exception) {
                $error ??= $exception;
            }
        }

        if ($error !== null) {
            throw $error;
        }
    }

    public function detach()
    {
        $this->close();
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        throw new RuntimeException('The stream is not seekable');
    }

    public function eof(): bool
    {
        return $this->ended && $this->buffer === '';
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('The stream is not seekable');
    }

    public function rewind(): void
    {
        throw new RuntimeException('The stream is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('The stream is not writable, use push()');
    }

    public function isReadable(): bool
    {
        return true;
    }

    /**
     * Reads the buffered data, it doesn't wait for more
     */
    public function read(int $length): string
    {
        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($data));

        return $data;
    }

    public function getContents(): string
    {
        $data = $this->buffer;
        $this->buffer = '';

        return $data;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    /**
     * Hands the end to the response sender once
     */
    private function release(bool $complete): void
    {
        $finish = $this->finish;
        $this->send = null;
        $this->finish = null;

        if ($finish !== null) {
            $finish($complete);
        }
    }
}
