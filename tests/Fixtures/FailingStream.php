<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Fixtures;

use RuntimeException;

/**
 * A stream that fails after its first read, e.g. a proxied body whose upstream is gone
 */
final class FailingStream extends StreamDecorator
{
    private int $reads = 0;

    public function read(int $length): string
    {
        if (++$this->reads > 1) {
            throw new RuntimeException('Failed');
        }

        return $this->stream->read($length);
    }
}
