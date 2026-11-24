<?php

declare(strict_types=1);

/*
 * Router script of the load-test endpoint (the Caddyfile next to it sends every request here). It builds the endpoint
 * for each request, as under FPM or FrankenPHP classic mode, with the services of services.php and the identity key of
 * request-identity.pub (Restate signs every request with the matching request-identity.pem). It appends one line per
 * request to load-requests.log in LOAD_STATE_DIR (default: the temporary directory):
 * `<invocation id or -> <status> <microseconds> <path>`, the microseconds from request start (autoloading and building
 * the endpoint included) until the response is ready.
 *
 * `/load-stats` (not a Restate route) is what k6.js reads: `POST` starts counting, `GET` answers what happened since.
 * It reports the server process's CPU time, user and system (getrusage(): FrankenPHP is one process, its PHP threads
 * included), the requests answered 401, the attempts per service (`/invoke/<service>/…` lines of the request log),
 * and the run executions and duplicates of the effects ledger (services.php). An endpoint in another language serves
 * the same services and the same two calls.
 */

use Restate\Endpoint;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$stateDir = getenv('LOAD_STATE_DIR');
$stateDir = is_string($stateDir) && $stateDir !== '' ? $stateDir : sys_get_temp_dir();
$requestLog = "{$stateDir}/load-requests.log";

if (($_SERVER['REQUEST_URI'] ?? '') === '/load-stats') {
    // Where the counts start, from the last POST: the logs' sizes and the CPU time then.
    $startFile = "{$stateDir}/load-stats-start.json";
    $effectsLog = "{$stateDir}/load-effects.log";
    $cpu = static function (): int {
        $usage = getrusage();
        if ($usage === false) {
            throw new RuntimeException('getrusage() failed.');
        }

        return $usage['ru_utime.tv_sec'] * 1_000_000 + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_sec'] * 1_000_000 + $usage['ru_stime.tv_usec'];
    };
    $size = static function (string $path): int {
        clearstatcache();

        return is_file($path) ? (int) filesize($path) : 0;
    };
    /** @return list<string> the file's non-empty lines after this byte offset */
    $lines = static function (string $path, int $offset): array {
        $contents = is_file($path) ? @file_get_contents($path, offset: $offset) : '';

        return array_values(array_filter(explode("\n", (string) $contents), static fn (string $line): bool => trim($line) !== ''));
    };

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $stats = ['cpu_usec' => $cpu(), 'requests' => $size($requestLog), 'effects' => $size($effectsLog)];
        file_put_contents($startFile, json_encode($stats, JSON_THROW_ON_ERROR));
    } else {
        $start = json_decode((string) @file_get_contents($startFile), true);
        $start = is_array($start) ? $start : [];
        $since = static fn (string $key): int => is_int($start[$key] ?? null) ? $start[$key] : 0;
        $unauthorized = 0;
        $attempts = [];
        foreach ($lines($requestLog, $since('requests')) as $line) {
            $fields = explode(' ', $line, 4);
            if (count($fields) !== 4) {
                continue;
            }
            if ($fields[1] === '401') {
                $unauthorized++;
            }
            if (preg_match('#^/invoke/([^/]+)/#', $fields[3], $match) === 1) {
                $attempts[$match[1]] = ($attempts[$match[1]] ?? 0) + 1;
            }
        }
        $runs = $lines($effectsLog, $since('effects'));
        $stats = [
            'cpu_usec' => $cpu() - $since('cpu_usec'),
            'status_401' => $unauthorized,
            'attempts' => $attempts,
            'run_executions' => count($runs),
            'run_duplicates' => count($runs) - count(array_unique($runs)),
        ];
    }
    header('content-type: application/json');
    echo json_encode($stats, JSON_THROW_ON_ERROR);

    return;
}

$response = Endpoint::builder()
    ->services(require __DIR__ . '/services.php')
    ->identityKeys([trim((string) file_get_contents(__DIR__ . '/request-identity.pub'))])
    ->build()
    ->handleRequest($_SERVER, (string) file_get_contents('php://input'));

$started = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
$micros = is_float($started) ? (int) round((microtime(true) - $started) * 1_000_000) : -1;

http_response_code($response->statusCode);
foreach ($response->headers as [$name, $value]) {
    header("{$name}: {$value}");
}
echo $response->body;

$invocationId = $_SERVER['HTTP_X_RESTATE_INVOCATION_ID'] ?? '-';
$path = $_SERVER['REQUEST_URI'] ?? '/';
file_put_contents($requestLog, sprintf("%s %d %d %s\n", is_string($invocationId) ? $invocationId : '-', $response->statusCode, $micros, is_string($path) ? $path : '/'), FILE_APPEND);
