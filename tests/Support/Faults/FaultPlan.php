<?php

declare(strict_types=1);

namespace Restate\Tests\Support\Faults;

/**
 * The faults a test wants for one invocation, by attempt number (1 for the first attempt the endpoint sees), kept in
 * a JSON file in the state directory the test shares with the endpoint (RESTATE_PHP_TEST_STATE_DIR). The
 * {@see FaultInjector} in the endpoint reads it on every attempt.
 *
 * A plan names its invocation by id, or by the idempotency key it is sent with: Restate starts an invocation as soon
 * as it accepts it, so a test that wants to fault the first attempt writes the plan before it sends, under the
 * idempotency key (in the start message from service protocol v7, Restate 1.8, on). A plan without faults still makes
 * the injector record every attempt's response frames ({@see FaultInjector::attempts()}).
 */
final readonly class FaultPlan
{
    /** @param array<int, Fault> $faults by attempt number */
    private function __construct(
        /** `invocation` or `idempotency-key`. */
        public string $kind,
        public string $key,
        public array $faults = [],
    ) {
    }

    public static function forInvocation(string $invocationId): self
    {
        return new self('invocation', self::nonEmpty($invocationId));
    }

    public static function forIdempotencyKey(string $idempotencyKey): self
    {
        return new self('idempotency-key', self::nonEmpty($idempotencyKey));
    }

    public function onAttempt(int $attempt, Fault $fault): self
    {
        if ($attempt < 1) {
            throw new \InvalidArgumentException("Attempts are numbered from 1, got {$attempt}.");
        }

        $faults = $this->faults;
        $faults[$attempt] = $fault;
        ksort($faults);

        return new self($this->kind, $this->key, $faults);
    }

    public function faultFor(int $attempt): ?Fault
    {
        return $this->faults[$attempt] ?? null;
    }

    /** Write the plan (atomically: the endpoint never reads half a file), replacing any earlier one for its key. */
    public function write(string $stateDir): void
    {
        $file = self::file($stateDir, $this->kind, $this->key);
        $json = json_encode([
            'kind' => $this->kind,
            'key' => $this->key,
            'faults' => array_map(static fn (Fault $fault): array => $fault->toArray(), $this->faults),
        ], JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);

        $temporary = $file . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $json) === false || !rename($temporary, $file)) {
            throw new \RuntimeException("Could not write the fault plan {$file}.");
        }
    }

    public function remove(string $stateDir): void
    {
        @unlink(self::file($stateDir, $this->kind, $this->key));
    }

    /** The plan for an attempt of this invocation: by its id, else by its idempotency key. */
    public static function find(string $stateDir, string $invocationId, ?string $idempotencyKey): ?self
    {
        return self::read(self::file($stateDir, 'invocation', $invocationId))
            ?? ($idempotencyKey === null || $idempotencyKey === '' ? null : self::read(self::file($stateDir, 'idempotency-key', $idempotencyKey)));
    }

    private static function read(string $file): ?self
    {
        $json = is_file($file) ? @file_get_contents($file) : false;
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['kind'] ?? null) || !is_string($data['key'] ?? null) || !is_array($data['faults'] ?? null)) {
            throw new \RuntimeException("Malformed fault plan {$file}: {$json}");
        }

        $faults = [];
        foreach ($data['faults'] as $attempt => $fault) {
            if (!is_array($fault)) {
                throw new \RuntimeException("Malformed fault plan {$file}: {$json}");
            }
            $faults[(int) $attempt] = Fault::fromArray($fault);
        }

        return new self($data['kind'], $data['key'], $faults);
    }

    private static function file(string $stateDir, string $kind, string $key): string
    {
        return $stateDir . '/fault-plan-' . hash('sha256', "{$kind}:{$key}") . '.json';
    }

    private static function nonEmpty(string $key): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('A fault plan needs a non-empty invocation id or idempotency key.');
        }

        return $key;
    }
}
