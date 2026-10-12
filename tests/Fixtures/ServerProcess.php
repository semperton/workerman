<?php

declare(strict_types=1);

namespace Semperton\Workerman\Tests\Fixtures;

use RuntimeException;

use function fclose;
use function get_resource_id;
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
use function unlink;
use function usleep;

use const PHP_BINARY;
use const SIGINT;

/**
 * Runs server.php in real Workerman processes, on a free port
 */
final class ServerProcess
{
    /** @var array<int, int> the ports of the running processes, by their resource id */
    private static array $ports = [];

    /**
     * @param int $clientPort every worker keeps an async connection to it, closed when it stops unless kept
     * @param string $monitor a directory watched by a FileMonitor, '' = none
     * @return array{resource, int}
     */
    public static function start(
        int $maxRequests = 0,
        float $idleTimeout = 60,
        float $headerTimeout = 20,
        float $stopTimeout = 30,
        int $clientPort = 0,
        bool $keepClient = false,
        string $monitor = '',
    ): array {
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
            [
                PHP_BINARY, __DIR__ . '/server.php', 'start', (string) $port, (string) $maxRequests,
                (string) $idleTimeout, (string) $headerTimeout, (string) $stopTimeout, (string) $clientPort, $keepClient ? '1' : '', $monitor,
            ],
            [['file', '/dev/null', 'r'], $output, $output],
            $pipes,
        );
        if ($process === false) {
            throw new RuntimeException('Unable to start the server');
        }
        self::$ports[get_resource_id($process)] = $port;

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
     * Stops the server and removes its files
     *
     * @param resource $process
     */
    public static function stop($process): void
    {
        $id = get_resource_id($process);
        proc_terminate($process, SIGINT);
        proc_close($process);

        $port = self::$ports[$id] ?? null;
        unset(self::$ports[$id]);
        if ($port !== null) {
            foreach (['out', 'log', 'pid', 'status', 'stopping'] as $extension) {
                @unlink(sys_get_temp_dir() . "/semperton-workerman-$port.$extension");
            }
        }
    }
}
