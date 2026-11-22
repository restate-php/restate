<?php

declare(strict_types=1);

namespace Restate\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Restate\Testing\RestateContainer;
use Restate\Testing\RestateProcess;

final class RestateProcessTest extends TestCase
{
    public function testTheDefaultIsRestateServerFromThePath(): void
    {
        $restate = RestateProcess::default();

        self::assertSame('restate-server', $restate->binary);
        self::assertSame([], $restate->environment);
        self::assertSame(RestateContainer::DEFAULT_STARTUP_TIMEOUT_SECONDS, $restate->startupTimeoutSeconds);
    }

    public function testEveryOptionIsKeptAndTheOriginalIsUnchanged(): void
    {
        $original = RestateProcess::default();
        $restate = $original
            ->withBinary('/opt/restate/bin/restate-server')
            ->withEnvironment(['RESTATE_WORKER__INVOKER__INACTIVITY_TIMEOUT' => '0s'])
            ->withEnvironment(['RESTATE_LOG_FILTER' => 'restate=debug', 'RESTATE_WORKER__INVOKER__INACTIVITY_TIMEOUT' => '1s'])
            ->withStartupTimeout(5);

        self::assertSame('/opt/restate/bin/restate-server', $restate->binary);
        self::assertSame(
            ['RESTATE_WORKER__INVOKER__INACTIVITY_TIMEOUT' => '1s', 'RESTATE_LOG_FILTER' => 'restate=debug'],
            $restate->environment,
        );
        self::assertSame(5, $restate->startupTimeoutSeconds);

        self::assertEquals(RestateProcess::default(), $original);
    }

    public function testRejectsInvalidOptions(): void
    {
        foreach ([
            'empty binary' => static fn () => RestateProcess::default()->withBinary(''),
            'environment name' => static fn () => RestateProcess::default()->withEnvironment(['' => 'x']),
            'zero timeout' => static fn () => RestateProcess::default()->withStartupTimeout(0),
        ] as $case => $with) {
            try {
                $with();
                self::fail("Invalid option ({$case}) was accepted.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRefusesTheSettingsTheHarnessOwns(): void
    {
        foreach (RestateProcess::HARNESS_VARIABLES as $variable) {
            try {
                RestateProcess::default()->withEnvironment(['RESTATE_LOG_FILTER' => 'debug', $variable => 'x']);
                self::fail("{$variable} was accepted.");
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($variable, $exception->getMessage());
                self::assertStringNotContainsString('RESTATE_LOG_FILTER', $exception->getMessage());
            }
        }
    }
}
