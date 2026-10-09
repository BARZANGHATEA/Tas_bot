<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        // Admin dashboard forms: show the rule violation as a flash message.
        if ($request->is('admin', 'admin/*') && ! $request->expectsJson()) {
            return back()->withInput($request->except(['password', 'password_confirmation', 'confirm_password']))
                ->with('error', $this->getMessage());
        }

        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'context' => (object) $this->context,
        ], $this->status);
    }
}
