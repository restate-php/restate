<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services\Payloads;

use Restate\Attributes\Handler;
use Restate\Attributes\Input;
use Restate\Attributes\Output;
use Restate\Attributes\Service;
use Restate\Context;
use Restate\Reflection\HandlerSerdes;

/**
 * One handler per payload shape the endpoint maps (PayloadMappingTest): DTOs, backed enums, null, binary, a Custom
 * Serializer; and one handler that calls the others with the serdes a caller gets from {@see HandlerSerdes}.
 */
#[Service('Payloads')]
final class Payloads
{
    /** DTO in, DTO out. */
    #[Handler]
    public function quote(Context $ctx, Order $order): Receipt
    {
        return Receipt::for($order);
    }

    /** A nullable DTO, in and out: no input, no receipt. */
    #[Handler]
    public function maybeQuote(Context $ctx, ?Order $order = null): ?Receipt
    {
        return $order === null ? null : Receipt::for($order);
    }

    /** A backed enum in and out. */
    #[Handler]
    public function escalate(Context $ctx, Priority $priority): Priority
    {
        return $priority === Priority::Normal ? Priority::Express : Priority::Normal;
    }

    /** Binary in and out. */
    #[Handler]
    #[Input(json: false, contentType: 'application/octet-stream')]
    #[Output(json: false, contentType: 'application/octet-stream')]
    public function reverseBytes(Context $ctx, string $bytes): string
    {
        return strrev($bytes);
    }

    /** A Custom Serializer in and out. */
    #[Handler]
    #[Input(serde: TaggedTextSerde::class)]
    #[Output(serde: TaggedTextSerde::class)]
    public function shout(Context $ctx, string $text): string
    {
        return strtoupper($text);
    }

    /**
     * Calls every other handler of this service, with the serdes {@see HandlerSerdes} gives for each, and reports what
     * this caller decoded: classes and enum cases, not only values.
     *
     * @return array<string, mixed>
     */
    #[Handler]
    public function relay(Context $ctx, Order $order): array
    {
        $payloads = $ctx->serviceClient('Payloads');

        $quote = HandlerSerdes::of('Payloads', 'quote', static fn (Order $order): Receipt => throw new \LogicException());
        $receipt = $payloads->request('quote', $order)->inputSerde($quote->input)->outputSerde($quote->output)->call();

        $maybeQuote = HandlerSerdes::of('Payloads', 'maybeQuote', static fn (?Order $order): ?Receipt => throw new \LogicException());
        $none = $payloads->request('maybeQuote')->inputSerde($maybeQuote->input)->outputSerde($maybeQuote->output)->call();

        $escalate = HandlerSerdes::of('Payloads', 'escalate', static fn (Priority $priority): Priority => throw new \LogicException());
        $priority = $payloads->request('escalate', $order->priority)->inputSerde($escalate->input)->outputSerde($escalate->output)->call();

        $bytes = HandlerSerdes::bytes();
        $reversed = $payloads->request('reverseBytes', "\x00\x01\xff")->inputSerde($bytes)->outputSerde($bytes)->call();

        $shouted = $payloads->request('shout', 'quiet')->serde(new TaggedTextSerde())->call();

        return [
            'receipt' => $receipt instanceof Receipt
                ? [$receipt::class, $receipt->orderId, $receipt->deliverTo::class, $receipt->deliverTo->postalCode, $receipt->priority]
                : get_debug_type($receipt),
            'none' => $none,
            'priority' => $priority instanceof Priority ? $priority : get_debug_type($priority),
            'reverseBytes' => is_string($reversed) ? bin2hex($reversed) : get_debug_type($reversed),
            'shout' => $shouted,
        ];
    }
}
