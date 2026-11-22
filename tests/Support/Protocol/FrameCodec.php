<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Protocol;

/**
 * Builds Restate service protocol frames by hand, for tests that need protocol input or
 * expected output without the extension. Test support only; not part of any package.
 */
final class FrameCodec
{
    // Notification message types, for completionFrame().
    public const int GET_LAZY_STATE_COMPLETION = 0x8002;
    public const int GET_LAZY_STATE_KEYS_COMPLETION = 0x8006;
    public const int GET_PROMISE_COMPLETION = 0x8009;
    public const int PEEK_PROMISE_COMPLETION = 0x800A;
    public const int COMPLETE_PROMISE_COMPLETION = 0x800B;
    public const int SLEEP_COMPLETION = 0x800C;
    public const int CALL_COMPLETION = 0x800D;
    public const int CALL_INVOCATION_ID_COMPLETION = 0x800E;
    public const int RUN_COMPLETION = 0x8011;

    // Message types the VM writes, for frames() and messageTypes().
    public const int SUSPENSION = 0x0001;
    public const int ERROR = 0x0002;

    /** `ErrorMessage.behavior` (protocol v7): pause the invocation instead of retrying it. */
    public const int ERROR_BEHAVIOR_PAUSE = 1;
    public const int END = 0x0003;
    public const int PROPOSE_RUN_COMPLETION = 0x0005;
    public const int OUTPUT_COMMAND = 0x0401;
    public const int GET_LAZY_STATE_COMMAND = 0x0402;
    public const int SET_STATE_COMMAND = 0x0403;
    public const int GET_EAGER_STATE_COMMAND = 0x0407;
    public const int SLEEP_COMMAND = 0x040C;
    public const int ONE_WAY_CALL_COMMAND = 0x040E;
    public const int RUN_COMMAND = 0x0411;

    public static function outputSuccessFrame(string $jsonOutput): string
    {
        $inner = "\x0a"
            . self::encodeVarint(strlen($jsonOutput))
            . $jsonOutput;
        $payload = "\x72"
            . self::encodeVarint(strlen($inner))
            . $inner;

        return self::frame("\x04\x01", $payload);
    }

    /** @param array<array-key, string> $metadata */
    public static function terminalFailureFrame(int $code, string $message, array $metadata = []): string
    {
        $failure = self::failurePayload($code, $message, $metadata);

        return self::frame("\x04\x01", "\x7a" . self::encodeVarint(strlen($failure)) . $failure);
    }

    public static function runCommandFrame(int $handle, string $name): string
    {
        $payload = "\x58"
            . self::encodeVarint($handle)
            . "\x62"
            . self::encodeVarint(strlen($name))
            . $name;

        return self::frame("\x04\x11", $payload);
    }

    public static function setStateCommandFrame(string $key, string $value, string $name = ''): string
    {
        $payload = '';
        if ($key !== '') {
            $payload .= self::lengthDelimitedField(1, $key);
        }
        $payload .= self::lengthDelimitedField(3, self::valuePayload($value));
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame("\x04\x03", $payload);
    }

    public static function clearStateCommandFrame(string $key, string $name = ''): string
    {
        $payload = '';
        if ($key !== '') {
            $payload .= self::lengthDelimitedField(1, $key);
        }
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame("\x04\x04", $payload);
    }

    public static function clearAllStateCommandFrame(string $name = ''): string
    {
        $payload = '';
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame("\x04\x05", $payload);
    }

    public static function getLazyStateKeysFrame(int $resultCompletionId, string $name = ''): string
    {
        $payload = '';
        if ($resultCompletionId !== 0) {
            $payload .= self::varintField(11, $resultCompletionId);
        }
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame("\x04\x06", $payload);
    }

    public static function getEagerStateFrame(string $key, ?string $value, string $name = ''): string
    {
        $payload = '';
        if ($key !== '') {
            $payload .= self::lengthDelimitedField(1, $key);
        }
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }
        if ($value === null) {
            $payload .= self::lengthDelimitedField(13, '');
        } else {
            $payload .= self::lengthDelimitedField(14, self::valuePayload($value));
        }

        return self::frame("\x04\x07", $payload);
    }

    /** @param list<string> $keys */
    public static function getEagerStateKeysFrame(array $keys, string $name = ''): string
    {
        $payload = '';
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }
        $payload .= self::lengthDelimitedField(14, self::stateKeysPayload($keys));

        return self::frame("\x04\x08", $payload);
    }

    public static function getPromiseCommandFrame(string $key, int $resultCompletionId, string $name = ''): string
    {
        return self::promiseReadCommandFrame("\x04\x09", $key, $resultCompletionId, $name);
    }

    public static function peekPromiseCommandFrame(string $key, int $resultCompletionId, string $name = ''): string
    {
        return self::promiseReadCommandFrame("\x04\x0a", $key, $resultCompletionId, $name);
    }

    public static function completePromiseSuccessCommandFrame(
        string $key,
        string $jsonOutput,
        int $resultCompletionId,
        string $name = '',
    ): string {
        $payload = '';
        if ($key !== '') {
            $payload .= self::lengthDelimitedField(1, $key);
        }
        $payload .= self::lengthDelimitedField(2, self::valuePayload($jsonOutput));
        if ($resultCompletionId !== 0) {
            $payload .= self::varintField(11, $resultCompletionId);
        }
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame("\x04\x0b", $payload);
    }

    /** @param array<array-key, string> $metadata */
    public static function completePromiseFailureCommandFrame(
        string $key,
        int $code,
        string $message,
        int $resultCompletionId,
        array $metadata = [],
        string $name = '',
    ): string {
        $payload = '';
        if ($key !== '') {
            $payload .= self::lengthDelimitedField(1, $key);
        }
        $payload .= self::lengthDelimitedField(3, self::failurePayload($code, $message, $metadata));
        if ($resultCompletionId !== 0) {
            $payload .= self::varintField(11, $resultCompletionId);
        }
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame("\x04\x0b", $payload);
    }

    public static function sleepCommandFrame(int $wakeUpTimeMillis, int $resultCompletionId, string $name = ''): string
    {
        $payload = '';
        if ($wakeUpTimeMillis !== 0) {
            $payload .= self::varintField(1, $wakeUpTimeMillis);
        }
        if ($resultCompletionId !== 0) {
            $payload .= self::varintField(11, $resultCompletionId);
        }
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame("\x04\x0c", $payload);
    }

    /** @param list<array{0: string, 1: string}> $headers */
    public static function callCommandFrame(
        string $serviceName,
        string $handlerName,
        string $parameter,
        ?string $key,
        ?string $idempotencyKey,
        int $invocationIdNotificationIndex,
        int $resultCompletionId,
        string $name = '',
        array $headers = [],
        ?string $scope = null,
        ?string $limitKey = null,
    ): string {
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('Restate idempotency key must not be empty.');
        }

        $payload = '';
        if ($serviceName !== '') {
            $payload .= self::lengthDelimitedField(1, $serviceName);
        }
        if ($handlerName !== '') {
            $payload .= self::lengthDelimitedField(2, $handlerName);
        }
        if ($parameter !== '') {
            $payload .= self::lengthDelimitedField(3, $parameter);
        }
        foreach ($headers as [$headerName, $headerValue]) {
            $payload .= self::lengthDelimitedField(4, self::headerPayload($headerName, $headerValue));
        }
        if ($key !== null && $key !== '') {
            $payload .= self::lengthDelimitedField(5, $key);
        }
        if ($idempotencyKey !== null) {
            $payload .= self::lengthDelimitedField(6, $idempotencyKey);
        }
        if ($scope !== null) {
            $payload .= self::lengthDelimitedField(7, $scope);
        }
        if ($limitKey !== null) {
            $payload .= self::lengthDelimitedField(8, $limitKey);
        }
        if ($invocationIdNotificationIndex !== 0) {
            $payload .= self::varintField(10, $invocationIdNotificationIndex);
        }
        if ($resultCompletionId !== 0) {
            $payload .= self::varintField(11, $resultCompletionId);
        }
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame("\x04\x0d", $payload);
    }

    /** @param list<array{0: string, 1: string}> $headers */
    public static function sendCommandFrame(
        string $serviceName,
        string $handlerName,
        string $parameter,
        ?int $invokeTimeMillis,
        ?string $key,
        ?string $idempotencyKey,
        int $invocationIdNotificationIndex,
        string $name = '',
        array $headers = [],
        ?string $scope = null,
        ?string $limitKey = null,
    ): string {
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('Restate idempotency key must not be empty.');
        }

        $payload = '';
        if ($serviceName !== '') {
            $payload .= self::lengthDelimitedField(1, $serviceName);
        }
        if ($handlerName !== '') {
            $payload .= self::lengthDelimitedField(2, $handlerName);
        }
        if ($parameter !== '') {
            $payload .= self::lengthDelimitedField(3, $parameter);
        }
        if ($invokeTimeMillis !== null && $invokeTimeMillis !== 0) {
            $payload .= self::varintField(4, $invokeTimeMillis);
        }
        foreach ($headers as [$headerName, $headerValue]) {
            $payload .= self::lengthDelimitedField(5, self::headerPayload($headerName, $headerValue));
        }
        if ($key !== null && $key !== '') {
            $payload .= self::lengthDelimitedField(6, $key);
        }
        if ($idempotencyKey !== null) {
            $payload .= self::lengthDelimitedField(7, $idempotencyKey);
        }
        if ($scope !== null) {
            $payload .= self::lengthDelimitedField(8, $scope);
        }
        if ($limitKey !== null) {
            $payload .= self::lengthDelimitedField(9, $limitKey);
        }
        if ($invocationIdNotificationIndex !== 0) {
            $payload .= self::varintField(10, $invocationIdNotificationIndex);
        }
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame("\x04\x0e", $payload);
    }

    public static function sendSignalSuccessCommandFrame(
        string $targetInvocationId,
        int|string $signal,
        string $jsonOutput,
        string $name = '',
    ): string {
        return self::sendSignalCommandFrameInternal(
            $targetInvocationId,
            $signal,
            self::lengthDelimitedField(5, self::valuePayload($jsonOutput)),
            $name,
        );
    }

    /** @param array<array-key, string> $metadata */
    public static function sendSignalFailureCommandFrame(
        string $targetInvocationId,
        int|string $signal,
        int $code,
        string $message,
        array $metadata = [],
        string $name = '',
    ): string {
        return self::sendSignalCommandFrameInternal(
            $targetInvocationId,
            $signal,
            self::lengthDelimitedField(6, self::failurePayload($code, $message, $metadata)),
            $name,
        );
    }

    public static function attachWorkflowCommandFrame(
        string $workflowName,
        string $workflowKey,
        int $resultCompletionId,
        string $name = '',
        ?string $scope = null,
    ): string {
        return self::targetCommandFrame(
            "\x04\x12",
            self::lengthDelimitedField(4, self::workflowTargetPayload($workflowName, $workflowKey, $scope)),
            $resultCompletionId,
            $name,
        );
    }

    public static function getIdempotentRequestOutputCommandFrame(
        string $serviceName,
        ?string $serviceKey,
        string $handlerName,
        string $idempotencyKey,
        int $resultCompletionId,
        string $name = '',
        ?string $scope = null,
    ): string {
        return self::targetCommandFrame(
            "\x04\x13",
            self::lengthDelimitedField(3, self::idempotentRequestTargetPayload($serviceName, $serviceKey, $handlerName, $idempotencyKey, $scope)),
            $resultCompletionId,
            $name,
        );
    }

    public static function completeAwakeableSuccessFrame(string $awakeableId, string $jsonOutput, string $name = ''): string
    {
        $value = "\x0a"
            . self::encodeVarint(strlen($jsonOutput))
            . $jsonOutput;
        $payload = self::lengthDelimitedField(1, $awakeableId)
            . self::lengthDelimitedField(2, $value);

        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame("\x04\x14", $payload);
    }

    /** @param array<array-key, string> $metadata */
    public static function completeAwakeableFailureFrame(
        string $awakeableId,
        int $code,
        string $message,
        array $metadata = [],
        string $name = '',
    ): string {
        $failure = self::failurePayload($code, $message, $metadata);
        $payload = self::lengthDelimitedField(1, $awakeableId)
            . self::lengthDelimitedField(3, $failure);

        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame("\x04\x14", $payload);
    }

    public static function runCompletionSuccessFrame(int $handle, string $jsonOutput): string
    {
        $payload = "\x08"
            . self::encodeVarint($handle)
            . "\x72"
            . self::encodeVarint(strlen($jsonOutput))
            . $jsonOutput;

        return self::frame("\x00\x05", $payload);
    }

    /** @param array<array-key, string> $metadata */
    public static function runCompletionFailureFrame(int $handle, int $code, string $message, array $metadata = []): string
    {
        $failure = self::failurePayload($code, $message, $metadata);
        $payload = "\x08"
            . self::encodeVarint($handle)
            . "\x7a"
            . self::encodeVarint(strlen($failure))
            . $failure;

        return self::frame("\x00\x05", $payload);
    }

    /**
     * @param list<int> $waitingCompletions
     * @param list<int> $waitingSignals
     * @param list<string> $waitingNamedSignals
     */
    public static function suspensionFrame(array $waitingCompletions = [], array $waitingSignals = [], array $waitingNamedSignals = []): string
    {
        if ($waitingCompletions === [] && $waitingSignals === [] && $waitingNamedSignals === []) {
            throw new \InvalidArgumentException('Restate suspension frame requires at least one waiting completion or signal.');
        }

        $payload = '';
        foreach ($waitingCompletions as $completion) {
            $payload .= "\x08" . self::encodeVarint($completion);
        }
        foreach ($waitingSignals as $signal) {
            $payload .= "\x10" . self::encodeVarint($signal);
        }
        foreach ($waitingNamedSignals as $signal) {
            $payload .= "\x1a" . self::encodeVarint(strlen($signal)) . $signal;
        }

        return self::frame("\x00\x01", $payload);
    }

    public static function errorFrame(
        int $code,
        string $message,
        string $stacktrace = '',
        ?int $relatedCommandIndex = null,
        ?string $relatedCommandName = null,
        ?int $relatedCommandType = null,
        ?int $nextRetryDelayMillis = null,
        ?int $behavior = null,
    ): string {
        $payload = "\x08"
            . self::encodeVarint($code)
            . "\x12"
            . self::encodeVarint(strlen($message))
            . $message;

        if ($stacktrace !== '') {
            $payload .= "\x1a" . self::encodeVarint(strlen($stacktrace)) . $stacktrace;
        }
        if ($relatedCommandIndex !== null) {
            $payload .= "\x20" . self::encodeVarint($relatedCommandIndex);
        }
        if ($relatedCommandName !== null) {
            $payload .= "\x2a" . self::encodeVarint(strlen($relatedCommandName)) . $relatedCommandName;
        }
        if ($relatedCommandType !== null) {
            $payload .= "\x30" . self::encodeVarint($relatedCommandType);
        }
        if ($nextRetryDelayMillis !== null) {
            $payload .= "\x40" . self::encodeVarint($nextRetryDelayMillis);
        }
        if ($behavior !== null) {
            $payload .= "\x48" . self::encodeVarint($behavior);
        }

        return self::frame("\x00\x02", $payload);
    }

    /**
     * The start message Restate sends first: invocation id (used as debug id too), the number of journal entries that
     * follow, the object key, the state Restate sends up front, and whether that is only part of it (lazy state: every
     * key it did not send is read from Restate). From protocol v7 on it also carries the scope, limit key and idempotency
     * key the invocation was called with.
     *
     * @param array<array-key, string> $state numeric names arrive as integer keys
     */
    public static function startFrame(
        string $invocationId,
        int $knownEntries,
        string $key = '',
        array $state = [],
        bool $partialState = false,
        ?string $scope = null,
        ?string $limitKey = null,
        ?string $idempotencyKey = null,
    ): string {
        $payload = self::lengthDelimitedField(1, $invocationId)
            . self::lengthDelimitedField(2, $invocationId)
            . self::varintField(3, $knownEntries);
        foreach ($state as $stateKey => $value) {
            $payload .= self::lengthDelimitedField(4, self::lengthDelimitedField(1, (string) $stateKey) . self::lengthDelimitedField(2, $value));
        }
        if ($partialState) {
            $payload .= self::varintField(5, 1);
        }
        if ($key !== '') {
            $payload .= self::lengthDelimitedField(6, $key);
        }
        foreach ([10 => $scope, 11 => $limitKey, 12 => $idempotencyKey] as $fieldNumber => $value) {
            if ($value !== null) {
                $payload .= self::lengthDelimitedField($fieldNumber, $value);
            }
        }

        return self::frame("\x00\x00", $payload);
    }

    /**
     * The input command: the handler's input bytes and the request headers.
     *
     * @param list<array{0: string, 1: string}> $headers
     */
    public static function inputCommandFrame(string $input, array $headers = []): string
    {
        $payload = '';
        foreach ($headers as [$name, $value]) {
            $payload .= self::lengthDelimitedField(1, self::lengthDelimitedField(1, $name) . self::lengthDelimitedField(2, $value));
        }
        $payload .= self::lengthDelimitedField(14, self::lengthDelimitedField(1, $input));

        return self::frame("\x04\x00", $payload);
    }

    /** @param list<string> $frames */
    public static function responseBody(array $frames): string
    {
        return implode('', $frames) . self::frame("\x00\x03", '');
    }

    /**
     * A notification completing the command with this completion id: message type (one of the `*_COMPLETION`
     * constants), id, and one result: void (no result argument), a value, a failure, an invocation id, or state keys.
     *
     * @param array{code: int, message: string, metadata?: array<array-key, string>}|null $failure
     * @param list<string>|null $stateKeys
     */
    public static function completionFrame(
        int $messageType,
        int $completionId,
        ?string $value = null,
        ?array $failure = null,
        ?string $invocationId = null,
        ?array $stateKeys = null,
    ): string {
        return self::frame(
            pack('n', $messageType),
            self::varintField(1, $completionId) . self::notificationResult($value, $failure, $invocationId, $stateKeys),
        );
    }

    /**
     * A signal notification, void unless a value or failure is given. An int is a signal index: 1 is the built-in
     * cancel signal; awakeables are the signals from 17 on, in the order the invocation created them. A string is a
     * named signal.
     *
     * @param array{code: int, message: string, metadata?: array<array-key, string>}|null $failure
     */
    public static function signalFrame(int|string $signal, ?string $value = null, ?array $failure = null): string
    {
        return self::frame("\xfb\xff", self::signalIdPayload($signal) . self::notificationResult($value, $failure, null, null));
    }

    /** The notification Restate sends when the invocation is cancelled. */
    public static function cancelSignalFrame(): string
    {
        return self::signalFrame(1);
    }

    /**
     * Splits protocol output into its frames.
     *
     * @return list<array{type: int, payload: string}>
     */
    public static function frames(string $bytes): array
    {
        $frames = [];
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $header = strlen($bytes) - $offset >= 8 ? unpack('ntype/nflags/Nlength', $bytes, $offset) : false;
            if ($header === false || strlen($bytes) - $offset - 8 < $header['length']) {
                throw new \InvalidArgumentException('Truncated Restate frame.');
            }
            $frames[] = ['type' => $header['type'], 'payload' => substr($bytes, $offset + 8, $header['length'])];
            $offset += 8 + $header['length'];
        }

        return $frames;
    }

    /**
     * The message types of the frames in protocol output, in order.
     *
     * @return list<int>
     */
    public static function messageTypes(string $bytes): array
    {
        return array_column(self::frames($bytes), 'type');
    }

    /**
     * Decodes the top-level fields of a protobuf message, such as a frame's payload: field number => its values in
     * order. Varints are ints; length-delimited fields (strings, bytes, nested messages) and fixed 32/64-bit fields
     * are their raw bytes. For example field 1 of a start message's payload is the invocation id, field 12 (protocol
     * v7) the idempotency key.
     *
     * @return array<int, list<int|string>>
     */
    public static function fields(string $payload): array
    {
        $fields = [];
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $key = self::decodeVarint($payload, $offset);
            $number = $key >> 3;
            $fields[$number][] = match ($key & 7) {
                0 => self::decodeVarint($payload, $offset),
                1 => self::take($payload, $offset, 8),
                2 => self::take($payload, $offset, self::decodeVarint($payload, $offset)),
                5 => self::take($payload, $offset, 4),
                default => throw new \InvalidArgumentException(sprintf('Unsupported protobuf wire type %d in field %d.', $key & 7, $number)),
            };
        }

        return $fields;
    }

    private static function decodeVarint(string $bytes, int &$offset): int
    {
        $value = 0;
        for ($shift = 0; $shift < 64; $shift += 7) {
            if ($offset >= strlen($bytes)) {
                throw new \InvalidArgumentException('Truncated protobuf varint.');
            }
            $byte = ord($bytes[$offset++]);
            $value |= ($byte & 0x7f) << $shift;
            if (($byte & 0x80) === 0) {
                return $value;
            }
        }

        throw new \InvalidArgumentException('Protobuf varint longer than 10 bytes.');
    }

    private static function take(string $bytes, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($bytes)) {
            throw new \InvalidArgumentException('Truncated protobuf field.');
        }
        $value = substr($bytes, $offset, $length);
        $offset += $length;

        return $value;
    }

    /**
     * @param array{code: int, message: string, metadata?: array<array-key, string>}|null $failure
     * @param list<string>|null $stateKeys
     */
    private static function notificationResult(?string $value, ?array $failure, ?string $invocationId, ?array $stateKeys): string
    {
        $results = [];
        if ($value !== null) {
            $results['value'] = self::lengthDelimitedField(5, self::valuePayload($value));
        }
        if ($failure !== null) {
            $results['failure'] = self::lengthDelimitedField(6, self::failurePayload($failure['code'], $failure['message'], $failure['metadata'] ?? []));
        }
        if ($invocationId !== null) {
            $results['invocation id'] = self::lengthDelimitedField(16, $invocationId);
        }
        if ($stateKeys !== null) {
            $results['state keys'] = self::lengthDelimitedField(17, self::stateKeysPayload($stateKeys));
        }
        if (count($results) > 1) {
            throw new \InvalidArgumentException('A Restate notification has one result, got: ' . implode(', ', array_keys($results)) . '.');
        }

        return $results === [] ? self::lengthDelimitedField(4, '') : implode('', $results);
    }

    private static function varintField(int $fieldNumber, int $value): string
    {
        return self::encodeVarint($fieldNumber << 3) . self::encodeVarint($value);
    }

    private static function lengthDelimitedField(int $fieldNumber, string $value): string
    {
        return self::encodeVarint(($fieldNumber << 3) | 2)
            . self::encodeVarint(strlen($value))
            . $value;
    }

    private static function targetCommandFrame(string $prefix, string $targetPayload, int $resultCompletionId, string $name): string
    {
        $payload = $targetPayload;
        if ($resultCompletionId !== 0) {
            $payload .= self::varintField(11, $resultCompletionId);
        }
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame($prefix, $payload);
    }

    private static function idempotentRequestTargetPayload(
        string $serviceName,
        ?string $serviceKey,
        string $handlerName,
        string $idempotencyKey,
        ?string $scope = null,
    ): string {
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('Restate idempotency key must not be empty.');
        }

        $payload = '';
        if ($serviceName !== '') {
            $payload .= self::lengthDelimitedField(1, $serviceName);
        }
        if ($serviceKey !== null && $serviceKey !== '') {
            $payload .= self::lengthDelimitedField(2, $serviceKey);
        }
        if ($handlerName !== '') {
            $payload .= self::lengthDelimitedField(3, $handlerName);
        }
        $payload .= self::lengthDelimitedField(4, $idempotencyKey);
        if ($scope !== null) {
            $payload .= self::lengthDelimitedField(5, $scope);
        }

        return $payload;
    }

    private static function workflowTargetPayload(string $workflowName, string $workflowKey, ?string $scope = null): string
    {
        $payload = '';
        if ($workflowName !== '') {
            $payload .= self::lengthDelimitedField(1, $workflowName);
        }
        if ($workflowKey !== '') {
            $payload .= self::lengthDelimitedField(2, $workflowKey);
        }
        if ($scope !== null) {
            $payload .= self::lengthDelimitedField(3, $scope);
        }

        return $payload;
    }

    private static function sendSignalCommandFrameInternal(
        string $targetInvocationId,
        int|string $signal,
        string $resultPayload,
        string $name,
    ): string {
        $payload = '';
        if ($targetInvocationId !== '') {
            $payload .= self::lengthDelimitedField(1, $targetInvocationId);
        }
        $payload .= self::signalIdPayload($signal);
        $payload .= $resultPayload;
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame("\x04\x10", $payload);
    }

    private static function signalIdPayload(int|string $signal): string
    {
        if (is_int($signal)) {
            return self::varintField(2, $signal);
        }
        if ($signal === '') {
            throw new \InvalidArgumentException('Restate signal name must not be empty.');
        }

        return self::lengthDelimitedField(3, $signal);
    }

    private static function promiseReadCommandFrame(string $prefix, string $key, int $resultCompletionId, string $name): string
    {
        $payload = '';
        if ($key !== '') {
            $payload .= self::lengthDelimitedField(1, $key);
        }
        if ($resultCompletionId !== 0) {
            $payload .= self::varintField(11, $resultCompletionId);
        }
        if ($name !== '') {
            $payload .= self::lengthDelimitedField(12, $name);
        }

        return self::frame($prefix, $payload);
    }

    private static function valuePayload(string $value): string
    {
        return self::lengthDelimitedField(1, $value);
    }

    private static function headerPayload(string $name, string $value): string
    {
        return self::lengthDelimitedField(1, $name)
            . self::lengthDelimitedField(2, $value);
    }

    /** @param list<string> $keys */
    private static function stateKeysPayload(array $keys): string
    {
        $payload = '';
        foreach ($keys as $key) {
            $payload .= self::lengthDelimitedField(1, $key);
        }

        return $payload;
    }

    /** @param array<array-key, string> $metadata */
    private static function failurePayload(int $code, string $message, array $metadata = []): string
    {
        $failure = "\x08"
            . self::encodeVarint($code)
            . "\x12"
            . self::encodeVarint(strlen($message))
            . $message;

        foreach ($metadata as $key => $value) {
            $key = (string) $key;
            $entry = "\x0a"
                . self::encodeVarint(strlen($key))
                . $key
                . "\x12"
                . self::encodeVarint(strlen($value))
                . $value;
            $failure .= "\x1a" . self::encodeVarint(strlen($entry)) . $entry;
        }

        return $failure;
    }

    public static function frame(string $prefix, string $payload): string
    {
        return $prefix . self::encodeUint48(strlen($payload)) . $payload;
    }

    public static function encodeUint48(int $value): string
    {
        if ($value < 0 || $value > 0xffffffffffff) {
            throw new \InvalidArgumentException('Restate frame length must fit in uint48.');
        }

        return chr(($value >> 40) & 0xff)
            . chr(($value >> 32) & 0xff)
            . chr(($value >> 24) & 0xff)
            . chr(($value >> 16) & 0xff)
            . chr(($value >> 8) & 0xff)
            . chr($value & 0xff);
    }

    public static function encodeVarint(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Restate varint value must be non-negative.');
        }

        $bytes = '';
        do {
            $byte = $value & 0x7f;
            $value >>= 7;
            if ($value !== 0) {
                $byte |= 0x80;
            }
            $bytes .= chr($byte);
        } while ($value !== 0);

        return $bytes;
    }
}
