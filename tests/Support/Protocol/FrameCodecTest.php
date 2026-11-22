<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Protocol;

use PHPUnit\Framework\TestCase;

final class FrameCodecTest extends TestCase
{
    public function testVarintEncodesSmallAndMultiByteValues(): void
    {
        self::assertSame('00', bin2hex(FrameCodec::encodeVarint(0)));
        self::assertSame('7f', bin2hex(FrameCodec::encodeVarint(127)));
        self::assertSame('8001', bin2hex(FrameCodec::encodeVarint(128)));
        self::assertSame('9003', bin2hex(FrameCodec::encodeVarint(400)));
    }

    public function testUint48EncodesFrameLengths(): void
    {
        self::assertSame('000000000000', bin2hex(FrameCodec::encodeUint48(0)));
        self::assertSame('000000000010', bin2hex(FrameCodec::encodeUint48(16)));
        self::assertSame('000000000017', bin2hex(FrameCodec::encodeUint48(23)));
    }

    public function testFieldsDecodesAStartMessage(): void
    {
        $frames = FrameCodec::frames(FrameCodec::startFrame('inv-1', 3, key: 'k', state: ['a' => '1'], idempotencyKey: 'idem-1'));
        $fields = FrameCodec::fields($frames[0]['payload']);

        self::assertSame(['inv-1'], $fields[1]);
        self::assertSame([3], $fields[3]);
        self::assertSame(['k'], $fields[6]);
        self::assertSame(['idem-1'], $fields[12]);
        self::assertSame(['a'], FrameCodec::fields((string) $fields[4][0])[1]);
        self::assertSame([300], FrameCodec::fields("\x08" . FrameCodec::encodeVarint(300))[1]);
        self::assertSame([], FrameCodec::fields(''));
    }

    public function testFieldsRejectsTruncatedInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FrameCodec::fields("\x0a\x05abc");
    }

    public function testVarintRejectsNegativeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Restate varint value must be non-negative.');

        FrameCodec::encodeVarint(-1);
    }

    public function testUint48RejectsNegativeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Restate frame length must fit in uint48.');

        FrameCodec::encodeUint48(-1);
    }

    public function testUint48RejectsOutOfRangeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Restate frame length must fit in uint48.');

        FrameCodec::encodeUint48(0x1000000000000);
    }

    public function testOutputSuccessMatchesReferenceVector(): void
    {
        $encoded = FrameCodec::responseBody([
            FrameCodec::outputSuccessFrame('"Hello Mary"'),
        ]);

        // Reference vector: the response body of Greeter/greet returning "Hello Mary".
        self::assertSame('0401000000000010720e0a0c2248656c6c6f204d617279220003000000000000', bin2hex($encoded));
    }

    public function testOutputSuccessEncodesArbitraryJsonBytes(): void
    {
        $encoded = FrameCodec::responseBody([
            FrameCodec::outputSuccessFrame('{"message":"Hello Bob"}'),
        ]);

        self::assertSame(
            '040100000000001b72190a177b226d657373616765223a2248656c6c6f20426f62227d0003000000000000',
            bin2hex($encoded),
        );
    }

    public function testTerminalFailureWithoutMetadataMatchesReferenceVector(): void
    {
        $encoded = FrameCodec::responseBody([
            FrameCodec::terminalFailureFrame(400, 'Payment declined'),
        ]);

        // Reference vector: the response body of Payments/charge failing with terminal error 400.
        self::assertSame('04010000000000177a1508900312105061796d656e74206465636c696e65640003000000000000', bin2hex($encoded));
    }

    public function testTerminalFailureEncodesArbitraryCodeAndMessage(): void
    {
        $encoded = FrameCodec::responseBody([
            FrameCodec::terminalFailureFrame(500, 'boom'),
        ]);

        self::assertSame('040100000000000b7a0908f4031204626f6f6d0003000000000000', bin2hex($encoded));
    }

    public function testTerminalFailureEncodesMetadataMapDeterministically(): void
    {
        $encoded = FrameCodec::responseBody([
            FrameCodec::terminalFailureFrame(500, 'boom', ['key1' => 'value1']),
        ]);

        self::assertSame(
            '040100000000001b7a1908f4031204626f6f6d1a0e0a046b657931120676616c7565310003000000000000',
            bin2hex($encoded),
        );
    }

    public function testRunCommandAndCompletionFramesMatchReferenceVector(): void
    {
        $jsonOutput = '"Hello Mary from run"';

        $encoded = FrameCodec::responseBody([
            FrameCodec::runCommandFrame(1, 'format-message'),
            FrameCodec::runCompletionSuccessFrame(1, $jsonOutput),
            FrameCodec::outputSuccessFrame($jsonOutput),
        ]);

        // Reference vector: the response body of Greeter/greetWithRun journaling ctx.run('format-message') and returning its result.
        self::assertSame(
            '04110000000000125801620e666f726d61742d6d6573736167650005000000000019080172152248656c6c6f204d6172792066726f6d2072756e22040100000000001972170a152248656c6c6f204d6172792066726f6d2072756e220003000000000000',
            bin2hex($encoded),
        );
    }

    public function testSleepCommandFrameEncodesWakeTimeCompletionAndName(): void
    {
        $encoded = FrameCodec::sleepCommandFrame(
            wakeUpTimeMillis: 1234567890,
            resultCompletionId: 7,
            name: 'sleep',
        );

        self::assertSame(
            '040c00000000000f08d285d8cc0458076205736c656570',
            bin2hex($encoded),
        );
    }

    public function testCallCommandFrameEncodesServiceCallNotificationIndexes(): void
    {
        $encoded = FrameCodec::callCommandFrame(
            serviceName: 'Greeter',
            handlerName: 'greet',
            parameter: '{"name":"Mary"}',
            key: null,
            idempotencyKey: null,
            invocationIdNotificationIndex: 42,
            resultCompletionId: 43,
        );

        self::assertSame(
            '040d0000000000250a0747726565746572120567726565741a0f7b226e616d65223a224d617279227d502a582b',
            bin2hex($encoded),
        );
    }

    public function testCallCommandFrameEncodesKeyIdempotencyKeyAndName(): void
    {
        $encoded = FrameCodec::callCommandFrame(
            serviceName: 'Counter',
            handlerName: 'add',
            parameter: '3',
            key: 'alice',
            idempotencyKey: 'idem-1',
            invocationIdNotificationIndex: 42,
            resultCompletionId: 43,
            name: 'call-counter',
        );

        self::assertSame(
            '040d0000000000320a07436f756e74657212036164641a01332a05616c69636532066964656d2d31502a582b620c63616c6c2d636f756e746572',
            bin2hex($encoded),
        );
    }

    public function testCallCommandFrameEncodesHeaders(): void
    {
        $encoded = FrameCodec::callCommandFrame(
            serviceName: 'Greeter',
            handlerName: 'greet',
            parameter: 'null',
            key: null,
            idempotencyKey: null,
            invocationIdNotificationIndex: 42,
            resultCompletionId: 43,
            headers: [['x-trace-id', 'trace-1']],
        );

        self::assertSame(
            '040d0000000000310a0747726565746572120567726565741a046e756c6c22150a0a782d74726163652d6964120774726163652d31502a582b',
            bin2hex($encoded),
        );
    }

    public function testCallCommandFrameRejectsEmptyIdempotencyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Restate idempotency key must not be empty.');

        FrameCodec::callCommandFrame(
            serviceName: 'Greeter',
            handlerName: 'greet',
            parameter: 'null',
            key: null,
            idempotencyKey: '',
            invocationIdNotificationIndex: 42,
            resultCompletionId: 43,
        );
    }

    public function testSendCommandFrameEncodesImmediateSendInvocationIdNotificationIndex(): void
    {
        $encoded = FrameCodec::sendCommandFrame(
            serviceName: 'Greeter',
            handlerName: 'greet',
            parameter: '"Mary"',
            invokeTimeMillis: null,
            key: null,
            idempotencyKey: null,
            invocationIdNotificationIndex: 44,
        );

        self::assertSame(
            '040e00000000001a0a0747726565746572120567726565741a06224d61727922502c',
            bin2hex($encoded),
        );
    }

    public function testSendCommandFrameEncodesDelayKeyIdempotencyKeyAndName(): void
    {
        $encoded = FrameCodec::sendCommandFrame(
            serviceName: 'Counter',
            handlerName: 'add',
            parameter: '3',
            invokeTimeMillis: 2500,
            key: 'alice',
            idempotencyKey: 'idem-2',
            invocationIdNotificationIndex: 44,
            name: 'send-counter',
        );

        self::assertSame(
            '040e0000000000330a07436f756e74657212036164641a013320c4133205616c6963653a066964656d2d32502c620c73656e642d636f756e746572',
            bin2hex($encoded),
        );
    }

    public function testSendCommandFrameEncodesHeaders(): void
    {
        $encoded = FrameCodec::sendCommandFrame(
            serviceName: 'Greeter',
            handlerName: 'greet',
            parameter: 'null',
            invokeTimeMillis: null,
            key: null,
            idempotencyKey: null,
            invocationIdNotificationIndex: 44,
            headers: [['x-tenant', 'acme']],
        );

        self::assertSame(
            '040e00000000002a0a0747726565746572120567726565741a046e756c6c2a100a08782d74656e616e74120461636d65502c',
            bin2hex($encoded),
        );
    }

    public function testSendCommandFrameRejectsEmptyIdempotencyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Restate idempotency key must not be empty.');

        FrameCodec::sendCommandFrame(
            serviceName: 'Greeter',
            handlerName: 'greet',
            parameter: 'null',
            invokeTimeMillis: null,
            key: null,
            idempotencyKey: '',
            invocationIdNotificationIndex: 44,
        );
    }

    public function testSendSignalSuccessCommandFrameEncodesNamedSignalValueAndName(): void
    {
        $encoded = FrameCodec::sendSignalSuccessCommandFrame(
            targetInvocationId: 'inv-123',
            signal: 'ready',
            jsonOutput: '"ok"',
            name: 'signal-ready',
        );

        self::assertSame(
            '04100000000000260a07696e762d3132331a0572656164792a060a04226f6b22620c7369676e616c2d7265616479',
            bin2hex($encoded),
        );
    }

    public function testSendSignalFailureCommandFrameEncodesFailurePayloadAndName(): void
    {
        $encoded = FrameCodec::sendSignalFailureCommandFrame(
            targetInvocationId: 'inv-123',
            signal: 2,
            code: 500,
            message: 'boom',
            metadata: ['key1' => 'value1'],
            name: 'fail-signal',
        );

        self::assertSame(
            '04100000000000330a07696e762d3132331002321908f4031204626f6f6d1a0e0a046b657931120676616c756531620b6661696c2d7369676e616c',
            bin2hex($encoded),
        );
    }

    public function testSendSignalCommandFrameRejectsEmptySignalName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Restate signal name must not be empty.');

        FrameCodec::sendSignalSuccessCommandFrame(
            targetInvocationId: 'inv-123',
            signal: '',
            jsonOutput: '"ok"',
        );
    }

    public function testAttachWorkflowCommandFrameEncodesWorkflowTargetAndName(): void
    {
        $encoded = FrameCodec::attachWorkflowCommandFrame(
            workflowName: 'Checkout',
            workflowKey: 'order-1',
            resultCompletionId: 15,
            name: 'attach-workflow',
        );

        self::assertSame(
            '041200000000002822130a08436865636b6f757412076f726465722d31580f620f6174746163682d776f726b666c6f77',
            bin2hex($encoded),
        );
    }

    public function testGetIdempotentRequestOutputCommandFrameEncodesTargetAndName(): void
    {
        $encoded = FrameCodec::getIdempotentRequestOutputCommandFrame(
            serviceName: 'Counter',
            serviceKey: 'alice',
            handlerName: 'add',
            idempotencyKey: 'idem-1',
            resultCompletionId: 17,
            name: 'output-idem',
        );

        self::assertSame(
            '041300000000002e1a1d0a07436f756e7465721205616c6963651a0361646422066964656d2d315811620b6f75747075742d6964656d',
            bin2hex($encoded),
        );
    }

    public function testGetIdempotentRequestOutputCommandFrameRejectsEmptyIdempotencyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Restate idempotency key must not be empty.');

        FrameCodec::getIdempotentRequestOutputCommandFrame(
            serviceName: 'Counter',
            serviceKey: null,
            handlerName: 'add',
            idempotencyKey: '',
            resultCompletionId: 17,
        );
    }

    public function testSetStateCommandFrameEncodesKeyValueAndName(): void
    {
        $encoded = FrameCodec::setStateCommandFrame(
            key: 'count',
            value: '{"n":1}',
            name: 'set-count',
        );

        self::assertSame(
            '040300000000001d0a05636f756e741a090a077b226e223a317d62097365742d636f756e74',
            bin2hex($encoded),
        );
    }

    public function testClearStateCommandFrameEncodesKeyAndName(): void
    {
        $encoded = FrameCodec::clearStateCommandFrame(
            key: 'count',
            name: 'clear-count',
        );

        self::assertSame(
            '04040000000000140a05636f756e74620b636c6561722d636f756e74',
            bin2hex($encoded),
        );
    }

    public function testClearAllStateCommandFrameEncodesName(): void
    {
        $encoded = FrameCodec::clearAllStateCommandFrame(name: 'clear-all');

        self::assertSame(
            '040500000000000b6209636c6561722d616c6c',
            bin2hex($encoded),
        );
    }

    public function testGetLazyStateKeysFrameEncodesCompletionAndName(): void
    {
        $encoded = FrameCodec::getLazyStateKeysFrame(
            resultCompletionId: 8,
            name: 'keys',
        );

        self::assertSame(
            '0406000000000008580862046b657973',
            bin2hex($encoded),
        );
    }

    public function testGetEagerStateFrameEncodesValueAndName(): void
    {
        $encoded = FrameCodec::getEagerStateFrame(
            key: 'count',
            value: '42',
            name: 'get-count',
        );

        self::assertSame(
            '04070000000000180a05636f756e7462096765742d636f756e7472040a023432',
            bin2hex($encoded),
        );
    }

    public function testGetEagerStateFrameEncodesVoidForMissingState(): void
    {
        $encoded = FrameCodec::getEagerStateFrame(
            key: 'count',
            value: null,
            name: 'get-count',
        );

        self::assertSame(
            '04070000000000140a05636f756e7462096765742d636f756e746a00',
            bin2hex($encoded),
        );
    }

    public function testGetEagerStateKeysFrameEncodesKeysAndName(): void
    {
        $encoded = FrameCodec::getEagerStateKeysFrame(
            keys: ['alpha', 'beta'],
            name: 'keys',
        );

        self::assertSame(
            '040800000000001562046b657973720d0a05616c7068610a0462657461',
            bin2hex($encoded),
        );
    }

    public function testGetPromiseCommandFrameEncodesKeyCompletionAndName(): void
    {
        $encoded = FrameCodec::getPromiseCommandFrame(
            key: 'prom',
            resultCompletionId: 9,
            name: 'get-prom',
        );

        self::assertSame(
            '04090000000000120a0470726f6d580962086765742d70726f6d',
            bin2hex($encoded),
        );
    }

    public function testPeekPromiseCommandFrameEncodesKeyCompletionAndName(): void
    {
        $encoded = FrameCodec::peekPromiseCommandFrame(
            key: 'prom',
            resultCompletionId: 10,
            name: 'peek-prom',
        );

        self::assertSame(
            '040a0000000000130a0470726f6d580a62097065656b2d70726f6d',
            bin2hex($encoded),
        );
    }

    public function testCompletePromiseSuccessCommandFrameEncodesValueCompletionAndName(): void
    {
        $encoded = FrameCodec::completePromiseSuccessCommandFrame(
            key: 'prom',
            jsonOutput: '{"ok":true}',
            resultCompletionId: 11,
            name: 'complete-prom',
        );

        self::assertSame(
            '040b0000000000260a0470726f6d120d0a0b7b226f6b223a747275657d580b620d636f6d706c6574652d70726f6d',
            bin2hex($encoded),
        );
    }

    public function testCompletePromiseFailureCommandFrameEncodesFailurePayloadCompletionAndName(): void
    {
        $encoded = FrameCodec::completePromiseFailureCommandFrame(
            key: 'prom',
            code: 500,
            message: 'boom',
            resultCompletionId: 12,
            metadata: ['key1' => 'value1'],
            name: 'complete-prom',
        );

        self::assertSame(
            '040b0000000000320a0470726f6d1a1908f4031204626f6f6d1a0e0a046b657931120676616c756531580c620d636f6d706c6574652d70726f6d',
            bin2hex($encoded),
        );
    }

    public function testRunCompletionFailureProposalFrameEncodesFailurePayload(): void
    {
        $encoded = FrameCodec::runCompletionFailureFrame(
            1,
            500,
            'boom',
            ['key1' => 'value1'],
        );

        self::assertSame(
            '000500000000001d08017a1908f4031204626f6f6d1a0e0a046b657931120676616c756531',
            bin2hex($encoded),
        );
    }

    public function testCompleteAwakeableSuccessCommandFrameEncodesValueAndName(): void
    {
        $encoded = FrameCodec::completeAwakeableSuccessFrame(
            awakeableId: 'awk-123',
            jsonOutput: '{"ok":true}',
            name: 'complete-awakeable',
        );

        self::assertSame(
            '041400000000002c0a0761776b2d313233120d0a0b7b226f6b223a747275657d6212636f6d706c6574652d6177616b6561626c65',
            bin2hex($encoded),
        );
    }

    public function testCompleteAwakeableFailureCommandFrameEncodesFailurePayloadAndName(): void
    {
        $encoded = FrameCodec::completeAwakeableFailureFrame(
            awakeableId: 'awk-123',
            code: 500,
            message: 'boom',
            metadata: ['key1' => 'value1'],
            name: 'complete-awakeable',
        );

        self::assertSame(
            '04140000000000380a0761776b2d3132331a1908f4031204626f6f6d1a0e0a046b657931120676616c7565316212636f6d706c6574652d6177616b6561626c65',
            bin2hex($encoded),
        );
    }

    public function testSuspensionFrameEncodesWaitingCompletionsSignalsAndNames(): void
    {
        $encoded = FrameCodec::suspensionFrame(
            waitingCompletions: [1, 300],
            waitingSignals: [2],
            waitingNamedSignals: ['cancel'],
        );

        self::assertSame(
            '000100000000000f080108ac0210021a0663616e63656c',
            bin2hex($encoded),
        );
    }

    public function testSuspensionFrameRejectsEmptyWaitSets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Restate suspension frame requires at least one waiting completion or signal.');

        FrameCodec::suspensionFrame();
    }

    public function testErrorFrameEncodesRequiredAndOptionalFields(): void
    {
        $encoded = FrameCodec::errorFrame(
            code: 500,
            message: 'boom',
            stacktrace: 'trace',
            relatedCommandIndex: 4,
            relatedCommandName: 'run',
            relatedCommandType: 0x0411,
            nextRetryDelayMillis: 250,
        );

        self::assertSame(
            '000200000000001d08f4031204626f6f6d1a05747261636520042a0372756e30910840fa01',
            bin2hex($encoded),
        );
    }

    public function testErrorFrameOmitsOptionalFieldsWhenNull(): void
    {
        $encoded = FrameCodec::errorFrame(571, 'bad');

        self::assertSame('000200000000000808bb041203626164', bin2hex($encoded));
    }

    public function testTerminalFailureCastsNumericMetadataKeysToStrings(): void
    {
        $encoded = FrameCodec::responseBody([
            FrameCodec::terminalFailureFrame(500, 'boom', ['123' => 'value']),
        ]);

        self::assertSame(
            '04010000000000197a1708f4031204626f6f6d1a0c0a03313233120576616c75650003000000000000',
            bin2hex($encoded),
        );
    }

    public function testStartFrameCarriesEagerStateAndThePartialStateFlag(): void
    {
        self::assertSame(
            '0000000000000015' . '0a0169' . '120169' . '1801' . '22060a0173120176' . '2801' . '32016b',
            bin2hex(FrameCodec::startFrame('i', 1, 'k', ['s' => 'v'], partialState: true)),
        );
    }

    public function testStartFrameCarriesTheV7ScopeLimitKeyAndIdempotencyKey(): void
    {
        self::assertSame(
            '0000000000000011' . '0a0169' . '120169' . '1801' . '520173' . '5a016c' . '620169',
            bin2hex(FrameCodec::startFrame('i', 1, scope: 's', limitKey: 'l', idempotencyKey: 'i')),
        );
    }

    public function testCompletionFrameEncodesEachResult(): void
    {
        self::assertSame('800c000000000004' . '08012200', bin2hex(FrameCodec::completionFrame(FrameCodec::SLEEP_COMPLETION, 1)));
        self::assertSame('8002000000000008' . '08022a040a026162', bin2hex(FrameCodec::completionFrame(FrameCodec::GET_LAZY_STATE_COMPLETION, 2, value: 'ab')));
        self::assertSame(
            '800d00000000000b' . '0803320708940312026e6f',
            bin2hex(FrameCodec::completionFrame(FrameCodec::CALL_COMPLETION, 3, failure: ['code' => 404, 'message' => 'no'])),
        );
        self::assertSame(
            '800e00000000000a' . '0801820105696e762d32',
            bin2hex(FrameCodec::completionFrame(FrameCodec::CALL_INVOCATION_ID_COMPLETION, 1, invocationId: 'inv-2')),
        );
        self::assertSame(
            '8006000000000008' . '08018a01030a0161',
            bin2hex(FrameCodec::completionFrame(FrameCodec::GET_LAZY_STATE_KEYS_COMPLETION, 1, stateKeys: ['a'])),
        );
    }

    public function testCompletionFrameRejectsMoreThanOneResult(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A Restate notification has one result, got: value, invocation id.');

        FrameCodec::completionFrame(FrameCodec::CALL_COMPLETION, 1, value: 'x', invocationId: 'inv-2');
    }

    public function testSignalFrameDefaultsToVoidAndCancelIsSignalOne(): void
    {
        self::assertSame('fbff000000000004' . '10012200', bin2hex(FrameCodec::cancelSignalFrame()));
        self::assertSame('fbff000000000007' . '10112a030a0178', bin2hex(FrameCodec::signalFrame(17, value: 'x')));
    }

    public function testFramesSplitsOutputIntoTypedPayloads(): void
    {
        $output = FrameCodec::responseBody([FrameCodec::outputSuccessFrame('1')]);

        self::assertSame([FrameCodec::OUTPUT_COMMAND, FrameCodec::END], FrameCodec::messageTypes($output));
        self::assertSame(
            [['type' => FrameCodec::OUTPUT_COMMAND, 'payload' => "\x72\x03\x0a\x011"], ['type' => FrameCodec::END, 'payload' => '']],
            FrameCodec::frames($output),
        );
    }

    public function testFramesRejectsTruncatedOutput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Truncated Restate frame.');

        FrameCodec::frames(substr(FrameCodec::outputSuccessFrame('1'), 0, -1));
    }
}
