<?php

declare(strict_types=1);

/**
 * Writes the requests of tests/Security/requests.php as the starting corpus of the fuzzer
 *
 * php security/fuzz/seed.php <corpus>
 */

$corpus = $argv[1] ?? throw new InvalidArgumentException('Usage: php security/fuzz/seed.php <corpus>');

if (!is_dir($corpus)) {
    mkdir($corpus, recursive: true);
}

/** @var array<string, array{string, list<int>, bool}> $requests */
$requests = require dirname(__DIR__, 2) . '/tests/Security/requests.php';

foreach ($requests as $name => [$request]) {
    file_put_contents($corpus . '/' . md5($name) . '.txt', $request);
}

fwrite(STDERR, count($requests) . " requests written to $corpus\n");
