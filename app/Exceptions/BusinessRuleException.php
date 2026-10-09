<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A request that is well-formed but not allowed by the platform's rules
 * (cooldown active, insufficient balance, invalid state transition, ...).
 * Rendered as a JSON error with a stable machine-readable code.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'rule_violation',
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'context' => (object) $this->context,
        ], $this->status);
    }
}
