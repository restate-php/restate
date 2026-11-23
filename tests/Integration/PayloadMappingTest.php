<?php

declare(strict_types=1);

namespace Restate\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Restate\Ingress\IngressClient;
use Restate\Ingress\SendStatus;
use Restate\Ingress\ServiceClient;
use Restate\Reflection\HandlerSerdes;
use Restate\TerminalError;
use Restate\Tests\Integration\Services\Payloads\Address;
use Restate\Tests\Integration\Services\Payloads\Order;
use Restate\Tests\Integration\Services\Payloads\Priority;
use Restate\Tests\Integration\Services\Payloads\Receipt;
use Restate\Tests\Integration\Services\Payloads\TaggedTextSerde;
use Restate\Tests\Integration\Support\HttpClient;
use Restate\Tests\Integration\Support\IntegrationTestCase;

/**
 * DTO, backed enum, null, binary and custom payloads end to end: the attributed `Payloads` service and `PayloadCheckout`
 * workflow (tests/Integration/Services/Payloads/) map them on the endpoint; this test calls them through the ingress
 * client with the serdes `HandlerSerdes` gives a caller, and `Payloads/relay` does the same from a handler.
 */
final class PayloadMappingTest extends IntegrationTestCase
{
    public function testDtosRoundTripThroughTheIngressClient(): void
    {
        $quote = self::quoteSerdes();
        $order = new Order('o-1', 3, Address::of('1 Main St', '1234', 'Apt 5'), 2.5, true, Priority::Express, 'Happy birthday');

        $receipt = self::payloads()->request('quote', $order)->inputSerde($quote->input)->outputSerde($quote->output)->call();

        self::assertInstanceOf(Receipt::class, $receipt);
        self::assertEquals(Receipt::for($order), $receipt);
        self::assertSame(Priority::Express, $receipt->priority);
        self::assertSame(['1 Main St', '1234', 'Apt 5'], [$receipt->deliverTo->street, $receipt->deliverTo->postalCode, $receipt->deliverTo->unit]);
        self::assertSame(7.5, $receipt->totalWeightKg);
        self::assertTrue($receipt->fragile);
        self::assertSame('Happy birthday', $receipt->giftMessage);
        self::assertNull($receipt->approvedBy);

        // Nulls and a float without a fractional part.
        $plain = new Order('o-2', 2, Address::of('2 Side St', '5678'), 3.0);
        $receipt = self::payloads()->request('quote', $plain)->inputSerde($quote->input)->outputSerde($quote->output)->call();

        self::assertEquals(Receipt::for($plain), $receipt);
        self::assertInstanceOf(Receipt::class, $receipt);
        self::assertSame(6.0, $receipt->totalWeightKg);
        self::assertNull($receipt->giftMessage);
        self::assertNull($receipt->deliverTo->unit);
        self::assertSame(Priority::Normal, $receipt->priority);
    }

    /** The wire format, without the client: JSON names, defaults for omitted fields, enums as their values. */
    public function testTheEndpointHydratesAndNormalizesTheJsonShape(): void
    {
        $body = self::invoke('Payloads/quote', '{"id":"o-3","quantity":2,"shipTo":{"street":"3 Loop Rd","postal_code":"9"},"weight_kg":1.25}');

        self::assertSame([
            'orderId' => 'o-3',
            'itemCount' => 2,
            'priority' => 'normal',
            'deliverTo' => ['postal_code' => '9', 'street' => '3 Loop Rd', 'unit' => null],
            'totalWeightKg' => 2.5,
            'fragile' => false,
            'giftMessage' => null,
            'approvedBy' => null,
        ], json_decode($body, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testInputThatDoesNotFitTheTypeIsATerminal400(): void
    {
        $cases = [
            'quote: a missing field' => ['quote', ['id' => 'o-4', 'quantity' => 1]],
            'quote: a wrong type' => ['quote', ['id' => 'o-4', 'quantity' => 'one', 'shipTo' => ['street' => 's', 'postal_code' => 'p'], 'weight_kg' => 1]],
            'escalate: an unknown case' => ['escalate', 'urgent'],
        ];

        foreach ($cases as $case => [$handler, $payload]) {
            try {
                self::payloads()->request($handler, $payload)->call();
                self::fail("{$case}: expected a TerminalError.");
            } catch (TerminalError $e) {
                self::assertSame(400, $e->statusCode(), "{$case}: {$e->getMessage()}");
            }
        }
    }

    public function testNullableDtosAndBackedEnums(): void
    {
        $maybeQuote = HandlerSerdes::of('Payloads', 'maybeQuote', static fn (?Order $order): ?Receipt => throw new \LogicException());
        $escalate = HandlerSerdes::of('Payloads', 'escalate', static fn (Priority $priority): Priority => throw new \LogicException());
        $order = new Order('o-5', 1, Address::of('5 Hill St', '1111'), 0.5);

        self::assertNull(self::payloads()->request('maybeQuote')->inputSerde($maybeQuote->input)->outputSerde($maybeQuote->output)->call());
        self::assertEquals(Receipt::for($order), self::payloads()->request('maybeQuote', $order)->inputSerde($maybeQuote->input)->outputSerde($maybeQuote->output)->call());

        self::assertSame(Priority::Express, self::payloads()->request('escalate', Priority::Normal)->inputSerde($escalate->input)->outputSerde($escalate->output)->call());
        self::assertSame(Priority::Normal, self::payloads()->request('escalate', Priority::Express)->inputSerde($escalate->input)->outputSerde($escalate->output)->call());
    }

    public function testBinaryPayloadsAreTheExactBytes(): void
    {
        $bytes = HandlerSerdes::bytes();
        $all = implode('', array_map(chr(...), range(0, 255)));

        self::assertSame(strrev($all), self::payloads()->request('reverseBytes', $all)->inputSerde($bytes)->outputSerde($bytes)->call());
        self::assertSame("\xff\x00\r\n", self::payloads()->request('reverseBytes', "\n\r\x00\xff")->inputSerde($bytes)->outputSerde($bytes)->call());
    }

    public function testACustomSerializerOnBothSides(): void
    {
        self::assertSame('QUIET', self::payloads()->request('shout', 'quiet')->serde(new TaggedTextSerde())->call());
    }

    public function testASentRequestAttachesWithItsOutputSerde(): void
    {
        $quote = self::quoteSerdes();
        $order = new Order(self::key('o'), 4, Address::of('6 Bay Rd', '2222'), 1.5, priority: Priority::Express);

        $sent = self::payloads()->request('quote', $order)->inputSerde($quote->input)->outputSerde($quote->output)->idempotencyKey(self::key('quote'))->send();

        self::assertEquals(Receipt::for($order), $sent->attach());
        self::assertEquals(Receipt::for($order), $sent->output()->value());
    }

    public function testAWorkflowTakesAndReturnsDtos(): void
    {
        $run = HandlerSerdes::of('PayloadCheckout', 'run', static fn (Order $order): Receipt => throw new \LogicException());
        $key = self::key('order');
        $order = new Order($key, 2, Address::of('7 Dock St', '3333', 'Unit 1'), 4.0, priority: Priority::Express, giftMessage: 'Enjoy');
        $workflow = self::client()->workflowClient('PayloadCheckout', $key);

        $submitted = $workflow->request('run', $order)->inputSerde($run->input)->outputSerde($run->output)->send();
        self::assertSame(SendStatus::Accepted, $submitted->status());
        $workflow->request('approve', 'Grace')->call();

        $expected = Receipt::for($order, 'Grace');
        self::assertEquals($expected, $workflow->attach($run->output));
        self::assertEquals($expected, $workflow->output($run->output)->value());
        self::assertEquals($expected, $submitted->attach());
    }

    /** `Payloads/relay` calls the other handlers from a handler, with the serdes HandlerSerdes gives it. */
    public function testAHandlerCallsDtoEnumBinaryAndCustomHandlers(): void
    {
        $relay = HandlerSerdes::of('Payloads', 'relay', static fn (Order $order): array => throw new \LogicException());
        $order = new Order('o-8', 1, Address::of('8 Fork Ln', '4444'), 1.0, priority: Priority::Express);

        self::assertSame([
            'receipt' => [Receipt::class, 'o-8', Address::class, '4444', 'express'],
            'none' => null,
            'priority' => 'normal',
            'reverseBytes' => 'ff0100',
            'shout' => 'QUIET',
        ], self::payloads()->request('relay', $order)->inputSerde($relay->input)->outputSerde($relay->output)->call());
    }

    /** Restate registered the JSON Schemas the endpoint inferred from the handler's PHP types. */
    public function testRestateHasTheInferredJsonSchemas(): void
    {
        $response = HttpClient::get(self::restate()->adminUrl() . '/services/Payloads');
        self::assertTrue($response->isSuccessful(), "Failed to read the Payloads service: HTTP {$response->status} {$response->body}");
        $service = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($service);
        self::assertIsArray($service['handlers'] ?? null);
        // Restate 1.7 returns the schemas with their object keys sorted, 1.8 in the order they were registered. Member
        // order carries no meaning in JSON Schema, so compare with sorted keys; lists (`required`, `enum`) keep theirs.
        $handlers = self::sortedKeys(array_column($service['handlers'], null, 'name'));

        $address = self::sortedKeys([
            'type' => 'object',
            'properties' => [
                'street' => ['type' => 'string'],
                'postal_code' => ['type' => 'string'],
                'unit' => ['type' => ['string', 'null']],
            ],
            'required' => ['street', 'postal_code'],
        ]);
        self::assertSame(self::sortedKeys([
            '$defs' => ['Address' => $address],
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string'],
                'quantity' => ['type' => 'integer'],
                'shipTo' => ['$ref' => '#/$defs/Address'],
                'weight_kg' => ['type' => 'number'],
                'fragile' => ['type' => 'boolean'],
                'priority' => ['type' => 'string', 'enum' => ['normal', 'express']],
                'giftMessage' => ['type' => ['string', 'null']],
            ],
            'required' => ['id', 'quantity', 'shipTo', 'weight_kg'],
        ]), $handlers['quote']['input_json_schema'] ?? null);

        $output = $handlers['quote']['output_json_schema'] ?? null;
        self::assertIsArray($output);
        self::assertSame(['Address' => $address], $output['$defs'] ?? null);
        self::assertSame('object', $output['type'] ?? null);
        self::assertSame(['$ref' => '#/$defs/Address'], $output['properties']['deliverTo'] ?? null);
        self::assertSame(['orderId', 'itemCount', 'priority', 'deliverTo', 'totalWeightKg', 'fragile', 'giftMessage'], $output['required'] ?? null);

        self::assertSame(['object', 'null'], $handlers['maybeQuote']['input_json_schema']['type'] ?? null);
        self::assertSame(['object', 'null'], $handlers['maybeQuote']['output_json_schema']['type'] ?? null);
        self::assertSame(['enum' => ['normal', 'express'], 'type' => 'string'], $handlers['escalate']['input_json_schema'] ?? null);
        self::assertSame(['enum' => ['normal', 'express'], 'type' => 'string'], $handlers['escalate']['output_json_schema'] ?? null);

        // Binary and custom payloads have a content type and no schema.
        foreach (['reverseBytes' => 'application/octet-stream', 'shout' => 'text/plain'] as $name => $contentType) {
            self::assertSame([$contentType, $contentType], [$handlers[$name]['input_description'] ?? null, $handlers[$name]['output_description'] ?? null]);
            self::assertArrayNotHasKey('input_json_schema', $handlers[$name]);
            self::assertArrayNotHasKey('output_json_schema', $handlers[$name]);
        }
    }

    /**
     * The array with the keys of every string-keyed array in it sorted, recursively; lists keep their order.
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function sortedKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortedKeys($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private static function quoteSerdes(): HandlerSerdes
    {
        return HandlerSerdes::of('Payloads', 'quote', static fn (Order $order): Receipt => throw new \LogicException());
    }

    private static function payloads(): ServiceClient
    {
        return self::client()->serviceClient('Payloads');
    }

    private static function client(): IngressClient
    {
        // No proxy, also none from the environment: Restate listens on 127.0.0.1.
        return self::restate()->ingressClient(new Client(['timeout' => 30, 'proxy' => '']), new HttpFactory());
    }

    private static function key(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6));
    }
}
