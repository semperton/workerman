<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps the logged messages
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}
