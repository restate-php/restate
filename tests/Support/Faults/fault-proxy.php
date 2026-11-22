<?php

declare(strict_types=1);

/*
 * The FaultProxy's process: a forking TCP proxy for HTTP/1.1 from Restate (the client) to an endpoint (the upstream),
 * which misbehaves on the connections its control file says. Started by FaultProxy::start(); not meant to be run by
 * hand.
 *
 * Usage: php fault-proxy.php <bind address> <upstream host> <upstream port> <state file prefix>
 *
 * It binds a free port and writes it to `<prefix>.port`. Before accepting each connection it reads `<prefix>.json`
 * ({"mode": …, "ms": …, "connections": n}); a mode applies to the next n connections (it counts them down in the
 * file), then the proxy passes connections through. Every connection is logged to `<prefix>.jsonl` (one JSON object
 * per event: accept, request, close). Modes:
 *
 * - pass: copy both ways.
 * - latency (ms): copy, but hold the response back for ms before its first byte.
 * - reset: read the request head, then reset the client connection (SO_LINGER 0) without contacting the upstream.
 * - reset-response: pass the request, then forward only the first half of the response and reset the client.
 * - blackhole (ms): read whatever the client sends and answer nothing for ms, then close; no upstream.
 * - stall-request (ms): forward the request head and the first 16 bytes of its body, stall for ms, then go on.
 * - truncate-request: read the whole (chunked) request, then send the upstream only the first half of its body, with
 *   a Content-Length, as a complete request.
 * - status (ms is the status code, default 503): answer that status with `Retry-After: 2` and no body, no upstream.
 */

if (!isset($argv) || count($argv) !== 5) {
    fwrite(STDERR, "usage: fault-proxy.php <bind address> <upstream host> <upstream port> <state file prefix>\n");
    exit(64);
}
[, $bind, $upstreamHost, $upstreamPort, $prefix] = $argv;

$server = stream_socket_server("tcp://{$bind}:0", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "fault-proxy: cannot listen on {$bind}: {$error}\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
file_put_contents("{$prefix}.port.tmp", substr($name, (int) strrpos($name, ':') + 1));
rename("{$prefix}.port.tmp", "{$prefix}.port");

/** @param array<string, mixed> $event */
$log = static function (array $event) use ($prefix): void {
    $event['at'] = (int) (microtime(true) * 1000);
    file_put_contents("{$prefix}.jsonl", json_encode($event) . "\n", FILE_APPEND | LOCK_EX);
};

/** @return array{mode: string, ms: int} the mode for the next connection, counted down in the control file */
$nextMode = static function () use ($prefix): array {
    $file = "{$prefix}.json";
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return ['mode' => 'pass', 'ms' => 0];
    }
    flock($handle, LOCK_EX);
    $control = json_decode((string) stream_get_contents($handle), true);
    $mode = ['mode' => 'pass', 'ms' => 0];
    if (is_array($control) && is_string($control['mode'] ?? null) && is_int($control['connections'] ?? null) && $control['connections'] > 0) {
        $mode = ['mode' => $control['mode'], 'ms' => is_int($control['ms'] ?? null) ? $control['ms'] : 0];
        $control['connections']--;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($control));
    }
    flock($handle, LOCK_UN);
    fclose($handle);

    return $mode;
};

/** Reset a connection (RST, not FIN): SO_LINGER with a zero timeout, then close. */
$reset = static function ($stream): void {
    $socket = socket_import_stream($stream);
    if ($socket === false) {
        fclose($stream);

        return;
    }
    socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
    // Closes the stream too.
    socket_close($socket);
};

/** Read until the end of the request head; returns the head and whatever body bytes came with it. */
$readHead = static function ($client): ?array {
    $buffer = '';
    while (!str_contains($buffer, "\r\n\r\n")) {
        $chunk = fread($client, 65536);
        if ($chunk === false || ($chunk === '' && feof($client))) {
            return null;
        }
        $buffer .= $chunk;
    }
    [$head, $rest] = explode("\r\n\r\n", $buffer, 2);

    return [$head . "\r\n\r\n", $rest];
};

/**
 * Copy both ways until both sides are done: `$holdResponse` ms before the first response byte; with `$cutResponse`
 * only the first half of the first piece of the response is forwarded, then the client is reset.
 */
$pipe = static function ($client, $upstream, int $holdResponse = 0, bool $cutResponse = false) use ($reset): string {
    stream_set_blocking($client, false);
    stream_set_blocking($upstream, false);
    $responseBytes = 0;
    $clientOpen = true;
    // Until the upstream closes: one request per connection.
    while (true) {
        $read = $clientOpen ? [$client, $upstream] : [$upstream];
        $write = $except = [];
        if (@stream_select($read, $write, $except, 30) === false) {
            return 'select-failed';
        }
        foreach ($read as $stream) {
            $data = fread($stream, 65536);
            if ($data === false || ($data === '' && feof($stream))) {
                if ($stream === $client) {
                    $clientOpen = false;
                    @stream_socket_shutdown($upstream, STREAM_SHUT_WR);
                } else {
                    return 'upstream-closed';
                }
                continue;
            }
            if ($stream === $client) {
                stream_set_blocking($upstream, true);
                fwrite($upstream, $data);
                stream_set_blocking($upstream, false);
                continue;
            }
            if ($responseBytes === 0 && $holdResponse > 0) {
                usleep($holdResponse * 1000);
            }
            if ($cutResponse) {
                stream_set_blocking($client, true);
                fwrite($client, substr($data, 0, intdiv(strlen($data), 2)));
                fflush($client);
                usleep(100_000);
                $reset($client);

                return 'reset-response';
            }
            $responseBytes += strlen($data);
            stream_set_blocking($client, true);
            fwrite($client, $data);
            stream_set_blocking($client, false);
        }
    }
};

/** The body of a chunked request whose head was read, de-chunked; null if the client went away. */
$readChunkedBody = static function ($client, string $buffer): ?string {
    $body = '';
    while (true) {
        $line = strpos($buffer, "\r\n");
        if ($line === false) {
            $chunk = fread($client, 65536);
            if ($chunk === false || ($chunk === '' && feof($client))) {
                return null;
            }
            $buffer .= $chunk;
            continue;
        }
        $size = hexdec(trim(explode(';', substr($buffer, 0, $line))[0]));
        if (!is_int($size)) {
            return null;
        }
        while (strlen($buffer) < $line + 2 + $size + 2) {
            $chunk = fread($client, 65536);
            if ($chunk === false || ($chunk === '' && feof($client))) {
                return null;
            }
            $buffer .= $chunk;
        }
        if ($size === 0) {
            return $body;
        }
        $body .= substr($buffer, $line + 2, $size);
        $buffer = substr($buffer, $line + 2 + $size + 2);
    }
};

$handle = static function ($client, int $connection, string $mode, int $ms) use ($upstreamHost, $upstreamPort, $log, $reset, $readHead, $pipe, $readChunkedBody): void {
    $request = $readHead($client);
    if ($request === null) {
        if (is_resource($client)) {
            fclose($client);
        }
        $log(['event' => 'close', 'connection' => $connection, 'end' => 'no-request']);

        return;
    }
    [$head, $rest] = $request;
    $log([
        'event' => 'request',
        'connection' => $connection,
        'request' => strtok($head, "\r\n"),
        'invocation' => preg_match('/^x-restate-invocation-id:\s*(\S+)/mi', $head, $match) === 1 ? $match[1] : null,
    ]);

    switch ($mode) {
        case 'blackhole':
            // Swallow the rest of the request, answer nothing.
            $until = microtime(true) + $ms / 1000;
            stream_set_blocking($client, false);
            while (microtime(true) < $until) {
                $read = [$client];
                $write = $except = [];
                if (@stream_select($read, $write, $except, 0, 100_000) > 0 && fread($client, 65536) === '' && feof($client)) {
                    break;
                }
            }
            fclose($client);
            $log(['event' => 'close', 'connection' => $connection, 'end' => 'blackhole']);

            return;

        case 'reset':
            $reset($client);
            $log(['event' => 'close', 'connection' => $connection, 'end' => 'reset']);

            return;

        case 'status':
            $status = $ms > 0 ? $ms : 503;
            fwrite($client, "HTTP/1.1 {$status} Fault Proxy\r\nretry-after: 2\r\ncontent-length: 0\r\nconnection: close\r\n\r\n");
            fclose($client);
            $log(['event' => 'close', 'connection' => $connection, 'end' => "status-{$status}"]);

            return;
    }

    $upstream = @stream_socket_client("tcp://{$upstreamHost}:{$upstreamPort}", $errno, $error, 5);
    if ($upstream === false) {
        $reset($client);
        $log(['event' => 'close', 'connection' => $connection, 'end' => 'upstream-unreachable']);

        return;
    }

    // One request per connection, so the mode of a connection is the mode of a request: the upstream closes after
    // its response (nginx would keep the connection alive and Restate would reuse it), and so does the proxy.
    $head = (string) preg_replace('/^connection:.*\r\n/mi', '', $head);
    $head = substr($head, 0, -2) . "connection: close\r\n\r\n";
    if ($mode === 'stall-request') {
        fwrite($upstream, $head . substr($rest, 0, 16));
        $log(['event' => 'stall', 'connection' => $connection]);
        usleep($ms * 1000);
        fwrite($upstream, substr($rest, 16));
    } elseif ($mode === 'truncate-request') {
        $body = $readChunkedBody($client, $rest) ?? '';
        $cut = substr($body, 0, intdiv(strlen($body), 2));
        $head = (string) preg_replace('/^transfer-encoding:.*\r\n/mi', '', $head);
        $head = substr($head, 0, -2) . 'content-length: ' . strlen($cut) . "\r\n\r\n";
        fwrite($upstream, $head . $cut);
        $log(['event' => 'truncated-request', 'connection' => $connection, 'length' => strlen($body), 'sent' => strlen($cut)]);
    } else {
        fwrite($upstream, $head . $rest);
    }

    $end = match ($mode) {
        'latency' => $pipe($client, $upstream, $ms),
        'reset-response' => $pipe($client, $upstream, cutResponse: true),
        default => $pipe($client, $upstream),
    };
    if (is_resource($client)) {
        fclose($client);
    }
    if (is_resource($upstream)) {
        fclose($upstream);
    }
    $log(['event' => 'close', 'connection' => $connection, 'end' => $end]);
};

pcntl_async_signals(true);
/** @var array<int, true> $children */
$children = [];
$stop = static function () use (&$children): never {
    foreach (array_keys($children) as $pid) {
        posix_kill($pid, SIGKILL);
    }
    exit(0);
};
pcntl_signal(SIGTERM, $stop);
pcntl_signal(SIGINT, $stop);

$connection = 0;
while (true) {
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
        unset($children[$pid]);
    }
    $client = @stream_socket_accept($server, 1.0);
    if ($client === false) {
        continue;
    }
    $connection++;
    ['mode' => $mode, 'ms' => $ms] = $nextMode();
    $log(['event' => 'accept', 'connection' => $connection, 'mode' => $mode, 'ms' => $ms]);

    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($server);
        pcntl_signal(SIGTERM, SIG_DFL);
        $handle($client, $connection, $mode, $ms);
        exit(0);
    }
    if ($pid > 0) {
        $children[$pid] = true;
    }
    fclose($client);
}
