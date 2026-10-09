<?php

namespace App\Exceptions;

class BudgetExhaustedException extends BusinessRuleException
{
    public function __construct(string $message = 'Rewards are temporarily unavailable. Please try again later.', string $code = 'budget_exhausted')
    {
        parent::__construct($message, $code, 503);
    }
}
