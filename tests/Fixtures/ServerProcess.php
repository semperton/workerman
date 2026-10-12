<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Fixtures;

use RuntimeException;

use function fclose;
use function microtime;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function usleep;

use const PHP_BINARY;
use const SIGINT;

/**
 * Runs server.php in real Workerman processes, on a free port
 */
final class ServerProcess
{
    /**
     * @return array{resource, int}
     */
    public static function start(int $maxRequests = 0, float $idleTimeout = 60, float $headerTimeout = 20, float $stopTimeout = 30): array
    {
        // a free port, assigned by the os
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Unable to find a free port');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $output = ['file', sys_get_temp_dir() . "/semperton-workerman-$port.out", 'w'];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/server.php', 'start', (string) $port, (string) $maxRequests, (string) $idleTimeout, (string) $headerTimeout, (string) $stopTimeout],
            [['file', '/dev/null', 'r'], $output, $output],
            $pipes,
        );
        if ($process === false) {
            throw new RuntimeException('Unable to start the server');
        }

        // wait for the workers
        $timeout = microtime(true) + 5.0;
        while (($client = @stream_socket_client("tcp://127.0.0.1:$port")) === false) {
            if (microtime(true) > $timeout) {
                self::stop($process);
                throw new RuntimeException("The server didn't start");
            }
            usleep(50_000);
        }
        fclose($client);

        return [$process, $port];
    }

    /**
     * @param resource $process
     */
    public static function stop($process): void
    {
        proc_terminate($process, SIGINT);
        proc_close($process);
    }
}
