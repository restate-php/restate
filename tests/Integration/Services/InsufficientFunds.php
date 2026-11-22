<?php

declare(strict_types=1);

namespace Restate\Tests\Integration\Services;

/** A domain exception that the integration endpoint's error mapper turns into a terminal 422 (HooksTest). */
final class InsufficientFunds extends \DomainException
{
    public function __construct(public readonly string $account)
    {
        parent::__construct("Insufficient funds on {$account}");
    }
}
