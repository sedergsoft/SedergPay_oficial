<?php

declare(strict_types=1);

namespace app\Infrastructure\Payment;

/**
 * Raised when an Idempotency-Key is replayed with a different request body.
 */
final class IdempotencyConflictException extends \RuntimeException
{
}
