<?php

declare(strict_types=1);

/**
 * Runs the security tests against the target in Docker, see README.md
 *
 * php security/run.php [--tools=http11probe,slowhttptest] [--duration=60] [--keep]
 *
 * http11probe   about 200 raw RFC 9110 / 9112 tests, directly and through nginx, failures are compared with baseline.json
 * slowhttptest  slowloris, slow body, slow read and range attacks, the target must stay available
 *
 * Exits with 1 if a result is worse than the baseline, the reports are written to results/
 */

const TOOLS = ['http11probe', 'slowhttptest'];
const TARGETS = ['direct' => 8080, 'proxy' => 8081];
const RESULTS = __DIR__ . '/results';
const CACHE = __DIR__ . '/.cache';

// pinned, updates are deliberate, as they change the results
const HTTP11PROBE_REPOSITORY = 'https://github.com/MDA2AV/Http11Probe.git';
const HTTP11PROBE_COMMIT = 'c5ee0b1c2faae55c51a5ec436838fc8c6b1fee41';
const DOTNET_IMAGE = 'mcr.microsoft.com/dotnet/sdk:10.0.401';
const SLOWHTTPTEST_IMAGE = 'shekyan/slowhttptest:ng@sha256:bdf03cc4686000518ccd5dfb0168b904d83bba372258f1743b63cb28c23f6573';

const SLOW_ATTACKS = [
    'slowloris' => ['-H'],
    'slow-body' => ['-B', '-s', '60000'],
    'slow-read' => ['-X', '-k', '3'],
    'range' => ['-R'],
];
// more than the 1024 connections select() could handle, the target uses the event extension
const SLOW_CONNECTIONS = 4000;

$options = getopt('', ['tools:', 'duration:', 'keep']);
$tools = isset($options['tools']) ? explode(',', (string) $options['tools']) : TOOLS;
$duration = (int) ($options['duration'] ?? 60);

foreach (array_diff($tools, TOOLS) as $unknown) {
    fwrite(STDERR, "Unknown tool < $unknown >\n");
    exit(1);
}

/** @var array{http11probe: array<string, array<string, string>>} $baseline */
$baseline = json_decode((string) file_get_contents(__DIR__ . '/baseline.json'), true, flags: JSON_THROW_ON_ERROR);
$failures = [];

if (!is_dir(RESULTS)) {
    mkdir(RESULTS);
}

logLine('== starting the target');
compose('up', '-d', '--build', '--quiet-pull');

try {
    foreach (TARGETS as $port) {
        waitUntilReady($port);
    }

    if (in_array('http11probe', $tools, true)) {
        logLine('== http11probe');
        foreach (http11probe() as $target => $results) {
            $failures = [...$failures, ...compareProbe($target, $results, $baseline['http11probe'][$target] ?? [])];
        }
    }

    if (in_array('slowhttptest', $tools, true)) {
        foreach (SLOW_ATTACKS as $attack => $flags) {
            logLine("== slowhttptest $attack, $duration s");
            $failures = [...$failures, ...slowhttptest($attack, $flags, $duration)];
        }
    }
} finally {
    if (!isset($options['keep'])) {
        compose('down');
    }
}

if ($failures !== []) {
    logLine('== ' . count($failures) . ' failures');
    foreach ($failures as $failure) {
        logLine($failure);
    }
    exit(1);
}

logLine('== passed');

/**
 * Builds Http11Probe once and runs it against every target
 *
 * @return array<string, list<array{id: string, scored: bool, verdict: string, statusCode: ?int}>> target => results
 */
function http11probe(): array
{
    $source = CACHE . '/Http11Probe';
    if (!is_dir($source . '/.git')) {
        command(['git', 'clone', '--quiet', HTTP11PROBE_REPOSITORY, $source]);
    }
    command(['git', '-C', $source, 'fetch', '--quiet', 'origin', HTTP11PROBE_COMMIT]);
    command(['git', '-C', $source, 'checkout', '--quiet', '--detach', HTTP11PROBE_COMMIT]);

    $script = 'cp -r /src /tmp/probe && dotnet publish --nologo -v quiet -c Release -o /tmp/app /tmp/probe/src/Http11Probe.Cli';
    foreach (TARGETS as $target => $port) {
        $script .= " && dotnet /tmp/app/Http11Probe.Cli.dll --host 127.0.0.1 --port $port --output /results/http11probe-$target.json > /dev/null";
    }
    docker(['-v', "$source:/src:ro", '-e', 'HOME=/tmp', '-e', 'DOTNET_CLI_TELEMETRY_OPTOUT=1', DOTNET_IMAGE, 'sh', '-c', $script]);

    $results = [];
    foreach (array_keys(TARGETS) as $target) {
        /** @var array{results: list<array{id: string, scored: bool, verdict: string, statusCode: ?int}>} $report */
        $report = json_decode((string) file_get_contents(RESULTS . "/http11probe-$target.json"), true, flags: JSON_THROW_ON_ERROR);
        $results[$target] = $report['results'];
    }

    return $results;
}

/**
 * Failed scored tests that are not accepted in the baseline, accepted ones that pass now are only reported
 *
 * @param list<array{id: string, scored: bool, verdict: string, statusCode: ?int}> $results
 * @param array<string, string> $accepted test id => reason
 * @return list<string>
 */
function compareProbe(string $target, array $results, array $accepted): array
{
    $failures = [];
    $passed = $failedTotal = 0;

    foreach ($results as $result) {
        if (!$result['scored']) {
            continue;
        }

        $failed = $result['verdict'] !== 'Pass' && $result['verdict'] !== 'Warn';
        $failedTotal += (int) $failed;
        $passed += (int) !$failed;

        if ($failed && !isset($accepted[$result['id']])) {
            $failures[] = "http11probe $target: {$result['id']} {$result['verdict']}, got " . ($result['statusCode'] ?? 'no response');
        } elseif (!$failed && isset($accepted[$result['id']])) {
            logLine("http11probe $target: {$result['id']} passes now, remove it from baseline.json");
        }
    }

    logLine("http11probe $target: $passed passed, $failedTotal failed, " . count($failures) . ' not in the baseline');

    return $failures;
}

/**
 * The attack must not make the target unavailable for its availability probe
 *
 * @param list<string> $flags
 * @return list<string>
 */
function slowhttptest(string $attack, array $flags, int $duration): array
{
    $code = docker([
        '--ulimit',
        'nofile=65536:65536',
        '--entrypoint',
        'slowhttptest-ng',
        SLOWHTTPTEST_IMAGE,
        ...$flags,
        ...['-u', 'http://127.0.0.1:' . TARGETS['direct'] . '/', '-c', (string) SLOW_CONNECTIONS, '-r', '500', '-i', '5'],
        ...['-l', (string) $duration, '-g', '-o', "/results/slowhttptest-$attack", '-v', '0'],
    ], check: false);

    /** @var array{result: array{outcome: string, summary: string}} $report */
    $report = json_decode((string) file_get_contents(RESULTS . "/slowhttptest-$attack.json"), true, flags: JSON_THROW_ON_ERROR);
    logLine($report['result']['summary']);

    return $code === 0 ? [] : ["slowhttptest $attack: {$report['result']['outcome']}"];
}

/**
 * Runs a tool container on the host network, its reports are written to results/
 *
 * @param list<string> $arguments
 */
function docker(array $arguments, bool $check = true): int
{
    $user = posix_getuid() . ':' . posix_getgid();

    return command(['docker', 'run', '--rm', '--network', 'host', '--user', $user, '-v', RESULTS . ':/results', ...$arguments], $check);
}

function compose(string ...$arguments): void
{
    command(['docker', 'compose', '-f', __DIR__ . '/compose.yaml', ...$arguments]);
}

/**
 * Runs a command, its output goes to stderr
 *
 * @param list<string> $command
 */
function command(array $command, bool $check = true): int
{
    $process = proc_open($command, [1 => STDERR, 2 => STDERR], $pipes);
    if ($process === false) {
        throw new RuntimeException('Unable to run ' . implode(' ', $command));
    }

    $code = proc_close($process);
    if ($check && $code !== 0) {
        throw new RuntimeException(implode(' ', $command) . " failed with exit code $code");
    }

    return $code;
}

function waitUntilReady(int $port): void
{
    $deadline = microtime(true) + 60;

    while (microtime(true) < $deadline) {
        $socket = @stream_socket_client("tcp://127.0.0.1:$port", timeout: 1.0);
        if ($socket !== false) {
            fwrite($socket, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
            $response = (string) stream_get_contents($socket);
            fclose($socket);
            if (str_starts_with($response, 'HTTP/1.1 200')) {
                return;
            }
        }
        usleep(250_000);
    }

    throw new RuntimeException("The target on port $port didn't start");
}

function logLine(string $message): void
{
    fwrite(STDERR, '[' . date('H:i:s') . "] $message\n");
}
