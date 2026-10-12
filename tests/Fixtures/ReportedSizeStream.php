<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Fixtures;

use Psr\Http\Message\StreamInterface;

/**
 * A stream that reports the given size, unknown (null) like a generated or proxied body, or a wrong one
 */
final class ReportedSizeStream extends StreamDecorator
{
    public function __construct(
        StreamInterface $stream,
        private readonly ?int $size = null,
    ) {
        parent::__construct($stream);
    }

    public function getSize(): ?int
    {
        return $this->size;
    }
}
