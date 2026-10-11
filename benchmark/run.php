<?php

declare(strict_types=1);

/**
 * Benchmarks PHP servers running the same PSR-15 app, see README.md
 *
 * php benchmark/run.php [--targets=semperton-event,swoole] [--scenarios=plaintext,json]
 *                       [--duration=10] [--rounds=3] [--warmup=5] [--no-probes]
 *
 * Every target is started alone, warmed up, measured in rounds (the median by req/s is kept),
 * probed for protocol behaviour and stopped. Results go to results/<date>.json and the tables in README.md
 */

require __DIR__ . '/probe.php';

const TARGETS = [
    'semperton-event' => 'semperton/workerman (event)',
    'semperton-select' => 'semperton/workerman (select)',
    'workerman' => 'Workerman, without PSR-7',
    'swoole' => 'Swoole',
    'roadrunner' => 'RoadRunner',
    'frankenphp' => 'FrankenPHP (worker mode)',
    'reactphp' => 'ReactPHP',
    'nginx-fpm' => 'nginx + PHP-FPM',
];

const SCENARIOS = [
    'plaintext' => ['lua' => 'hello', 'connections' => 64, 'title' => 'GET / plaintext, 64 connections'],
    'plaintext-256' => ['lua' => 'hello', 'connections' => 256, 'title' => 'GET / plaintext, 256 connections'],
    'json' => ['lua' => 'json', 'connections' => 64, 'title' => 'GET /json, browser-like headers, query and cookies, 64 connections'],
    'form' => ['lua' => 'form', 'connections' => 64, 'title' => 'POST /form, url encoded form, 64 connections'],
    'close' => ['lua' => 'close', 'connections' => 64, 'title' => 'GET / with Connection: close, a new connection per request, 64 connections'],
];

const ADDRESS = '127.0.0.1:8080';
const BASELINE = 'nginx-fpm';

$options = getopt('', ['targets:', 'scenarios:', 'duration:', 'rounds:', 'warmup:', 'no-probes']);
$targets = isset($options['targets']) ? explode(',', (string) $options['targets']) : array_keys(TARGETS);
$scenarios = isset($options['scenarios']) ? explode(',', (string) $options['scenarios']) : array_keys(SCENARIOS);
$duration = (int) ($options['duration'] ?? 10);
$rounds = (int) ($options['rounds'] ?? 3);
$warmup = (int) ($options['warmup'] ?? 5);
$withProbes = !isset($options['no-probes']);

foreach ([...array_diff($targets, array_keys(TARGETS)), ...array_diff($scenarios, array_keys(SCENARIOS))] as $unknown) {
    fwrite(STDERR, "Unknown target or scenario < $unknown >\n");
    exit(1);
}

compose('--profile', 'tools', 'build', '--quiet');
compose('down', '--remove-orphans');

$results = [
    'date' => gmdate('c'),
    'machine' => machine(),
    'settings' => compact('duration', 'rounds', 'warmup'),
    'targets' => [],
];

foreach ($targets as $target) {
    logLine("== $target");
    compose('up', '-d', $target);

    try {
        waitUntilReady();
        $result = ['scenarios' => []];

        foreach ($scenarios as $scenario) {
            wrk($scenario, $warmup);
            $runs = [];
            for ($round = 1; $round <= $rounds; $round++) {
                $runs[] = wrk($scenario, $duration);
            }
            // the median round by throughput
            usort($runs, static fn(array $a, array $b): int => $a['rps'] <=> $b['rps']);
            $result['scenarios'][$scenario] = $runs[intdiv(count($runs), 2)];
            logLine(sprintf('%-14s %10.0f req/s  p99 %s', $scenario, $result['scenarios'][$scenario]['rps'], duration($result['scenarios'][$scenario]['p99_us'])));
        }

        $result['memory_mb'] = memory();
        $result['probes'] = $withProbes ? probe(ADDRESS) : [];
        $results['targets'][$target] = $result;
    } finally {
        compose('down');
    }
}

$file = __DIR__ . '/results/' . gmdate('Y-m-d\THis') . '.json';
file_put_contents($file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
logLine("Results written to $file");

$readme = __DIR__ . '/README.md';
$markdown = (string) preg_replace(
    '~(<!-- results:start -->).*(<!-- results:end -->)~s',
    "$1\n" . render($results) . "\n$2",
    (string) file_get_contents($readme),
);
file_put_contents($readme, $markdown);
logLine("Tables updated in $readme");

/**
 * Runs docker compose with the benchmark's compose file, fails on errors
 */
function compose(string ...$arguments): string
{
    $command = ['docker', 'compose', '-f', __DIR__ . '/compose.yaml', ...$arguments];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        throw new RuntimeException('Unable to run docker compose');
    }

    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('docker compose ' . implode(' ', $arguments) . " failed:\n$error");
    }

    return $output;
}

/**
 * @return array{requests: int, rps: float, p50_us: int, p90_us: int, p99_us: int, max_us: int, errors: array<string, int>}
 */
function wrk(string $scenario, int $seconds): array
{
    $settings = SCENARIOS[$scenario];
    $wrk = "-t 4 -c {$settings['connections']} -d {$seconds}s --timeout 5s -s /scripts/bench.lua http://" . ADDRESS;
    $output = compose('run', '--rm', '-e', 'SCENARIO=' . $settings['lua'], 'wrk', ...explode(' ', $wrk));

    if (preg_match('/^BENCH_JSON (.+)$/m', $output, $matches) !== 1) {
        throw new RuntimeException("Unexpected wrk output:\n$output");
    }

    /** @var array{requests: int, rps: float, p50_us: int, p90_us: int, p99_us: int, max_us: int, errors: array<string, int>} */
    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

function waitUntilReady(): void
{
    $deadline = microtime(true) + 60;

    while (microtime(true) < $deadline) {
        try {
            $response = exchange(ADDRESS, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n", 1.0);
            if (status($response['data']) === '200') {
                return;
            }
        } catch (RuntimeException) {
            // not listening yet
        }
        usleep(250_000);
    }

    throw new RuntimeException("The server didn't start");
}

/**
 * Memory of all containers of the target after the load, in MB
 */
function memory(): float
{
    $ids = array_filter(explode("\n", compose('ps', '-q')));
    $process = proc_open(['docker', 'stats', '--no-stream', '--format', '{{.MemUsage}}', ...$ids], [1 => ['pipe', 'w']], $pipes);
    $output = $process === false ? '' : (string) stream_get_contents($pipes[1]);
    if ($process !== false) {
        proc_close($process);
    }

    $total = 0.0;
    // e.g. "12.5MiB / 15.6GiB"
    preg_match_all('~^([\d.]+)(KiB|MiB|GiB)~m', $output, $matches, PREG_SET_ORDER);
    foreach ($matches as [, $value, $unit]) {
        $total += (float) $value * ['KiB' => 1 / 1024, 'MiB' => 1, 'GiB' => 1024][$unit];
    }

    return round($total, 1);
}

/**
 * @return array<string, string>
 */
function machine(): array
{
    preg_match('/^model name\s*:\s*(.+)$/m', (string) @file_get_contents('/proc/cpuinfo'), $cpu);

    return [
        'cpu' => $cpu[1] ?? php_uname('m'),
        'kernel' => php_uname('r'),
        'docker' => trim(shell_exec("docker version --format '{{.Server.Version}}'") ?: ''),
        'server_cpus' => getenv('SERVER_CPUS') ?: '0-3',
        'client_cpus' => getenv('CLIENT_CPUS') ?: '8-11',
    ];
}

/**
 * @param array{date: string, machine: array<string, string>, settings: array<string, int>, targets: array<string, array{scenarios: array<string, array<string, mixed>>, memory_mb: float, probes: array<string, string>}>} $results
 */
function render(array $results): string
{
    $machine = $results['machine'];
    $settings = $results['settings'];
    $lines = [
        sprintf(
            '_%s, %s, kernel %s, Docker %s. Servers on CPUs %s, wrk on CPUs %s. Median of %d rounds of %d s, after %d s of warmup._',
            substr($results['date'], 0, 10),
            $machine['cpu'],
            $machine['kernel'],
            $machine['docker'],
            $machine['server_cpus'],
            $machine['client_cpus'],
            $settings['rounds'],
            $settings['duration'],
            $settings['warmup'],
        ),
    ];

    foreach (SCENARIOS as $scenario => ['title' => $title]) {
        $rows = array_filter(array_map(static fn(array $target) => $target['scenarios'][$scenario] ?? null, $results['targets']));
        if ($rows === []) {
            continue;
        }
        uasort($rows, static fn(array $a, array $b): int => $b['rps'] <=> $a['rps']);
        $baseline = $rows[BASELINE]['rps'] ?? null;

        $lines[] = "\n#### $title\n";
        $lines[] = '| Server | Req/s | ' . ($baseline !== null ? 'vs FPM | ' : '') . 'p50 | p99 | Errors |';
        $lines[] = '|---|---:|' . ($baseline !== null ? '---:|' : '') . '---:|---:|---:|';
        foreach ($rows as $target => $row) {
            $lines[] = sprintf(
                '| %s | %s | %s%s | %s | %s |',
                TARGETS[$target],
                number_format($row['rps']),
                $baseline !== null ? sprintf('%.1f× | ', $row['rps'] / $baseline) : '',
                duration($row['p50_us']),
                duration($row['p99_us']),
                number_format(array_sum($row['errors'])),
            );
        }
    }

    $lines[] = "\n#### Memory after the load\n";
    $lines[] = '| Server | All processes |';
    $lines[] = '|---|---:|';
    foreach ($results['targets'] as $target => $result) {
        $lines[] = sprintf('| %s | %.0f MB |', TARGETS[$target], $result['memory_mb']);
    }

    $probed = array_filter($results['targets'], static fn(array $target): bool => $target['probes'] !== []);
    if ($probed !== []) {
        $lines[] = "\n#### Protocol behaviour\n";
        $lines[] = '| Probe | ' . implode(' | ', array_map(static fn(string $target) => TARGETS[$target], array_keys($probed))) . ' |';
        $lines[] = '|---|' . str_repeat('---|', count($probed));
        foreach (array_keys(reset($probed)['probes']) as $probe) {
            $lines[] = "| $probe | " . implode(' | ', array_map(static fn(array $target) => $target['probes'][$probe], $probed)) . ' |';
        }
    }

    return implode("\n", $lines);
}

function duration(int $microseconds): string
{
    return $microseconds >= 1000 ? sprintf('%.1f ms', $microseconds / 1000) : $microseconds . ' µs';
}

function logLine(string $message): void
{
    fwrite(STDERR, '[' . date('H:i:s') . "] $message\n");
}
