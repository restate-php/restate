<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Support;

/**
 * An in-flight request, for scenarios that must observe that a call has not completed yet.
 */
final class PendingHttpRequest
{
    private readonly \CurlMultiHandle $multi;

    private ?int $errno = null;

    public function __construct(private readonly \CurlHandle $handle)
    {
        $this->multi = curl_multi_init();
        curl_multi_add_handle($this->multi, $this->handle);
        $this->drive(0.0);
    }

    public function __destruct()
    {
        curl_multi_remove_handle($this->multi, $this->handle);
        curl_multi_close($this->multi);
    }

    /** Whether the response arrives within the given number of seconds. */
    public function finishesWithin(float $seconds): bool
    {
        return $this->drive($seconds);
    }

    /** Wait for the response; the request's own timeout bounds the wait. */
    public function wait(): HttpResponse
    {
        while (!$this->drive(1.0)) {
            // The curl timeout set on the handle guarantees this terminates.
        }

        return HttpClient::response($this->handle, curl_multi_getcontent($this->handle), $this->errno ?? 0);
    }

    private function drive(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while (true) {
            if ($this->errno !== null) {
                return true;
            }

            curl_multi_exec($this->multi, $running);
            while (($info = curl_multi_info_read($this->multi)) !== false) {
                if ($info['handle'] === $this->handle) {
                    $this->errno = $info['result'];
                }
            }
            if ($this->errno !== null) {
                return true;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return false;
            }
            if (curl_multi_select($this->multi, min($remaining, 0.05)) === -1) {
                usleep(10_000);
            }
        }
    }
}
