<?php

declare(strict_types=1);

namespace Restate\Tests\Benchmark;

use GuzzleHttp\Psr7\HttpFactory;
use PhpBench\Attributes as Bench;
use Restate\Ingress\IngressClient;
use Restate\Tests\Benchmark\Support\Dto\Order;
use Restate\Tests\Benchmark\Support\Expect;
use Restate\Tests\Benchmark\Support\FixedResponseHttpClient;

/**
 * The ingress client's own work per call (request building, payload serialization, response decoding) over a PSR-18
 * client that answers at once.
 */
#[Bench\Iterations(10)]
#[Bench\Warmup(1)]
#[Bench\Revs(1000)]
#[Bench\OutputTimeUnit('microseconds', precision: 2)]
final class IngressBench
{
    private IngressClient $client;

    /** A request-response call with an array payload, decoding the JSON result. */
    #[Bench\BeforeMethods('setUpCall')]
    public function benchCall(): void
    {
        $this->client->serviceClient('Orders')->request('place', ['id' => 'order-1', 'quantity' => 3])->call();
    }

    public function setUpCall(): void
    {
        $this->client = self::client(200, Order::exampleJson());
        $result = $this->client->serviceClient('Orders')->request('place', ['id' => 'order-1', 'quantity' => 3])->call();
        Expect::same(json_decode(Order::exampleJson(), true), $result, 'ingress call result');
    }

    /** A one-way send to a virtual object with an idempotency key and headers. */
    #[Bench\BeforeMethods('setUpSend')]
    public function benchSend(): void
    {
        $this->client->objectClient('Counter', 'alice')->request('add', 1)->idempotencyKey('add-1')->header('x-tenant', 'acme')->send();
    }

    public function setUpSend(): void
    {
        $this->client = self::client(202, '{"invocationId":"inv_1abc","status":"Accepted"}');
        $response = $this->client->objectClient('Counter', 'alice')->request('add', 1)->idempotencyKey('add-1')->header('x-tenant', 'acme')->send();
        Expect::same('inv_1abc', $response->invocationId(), 'ingress send invocation id');
    }

    private static function client(int $status, string $json): IngressClient
    {
        return IngressClient::connect('http://localhost:8080', new FixedResponseHttpClient($status, $json), new HttpFactory());
    }
}
