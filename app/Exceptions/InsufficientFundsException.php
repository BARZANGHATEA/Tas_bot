<?php

namespace App\Exceptions;

class InsufficientFundsException extends BusinessRuleException
{
    public function __construct(string $message = 'Insufficient available balance.')
    {
        parent::__construct($message, 'insufficient_funds');
    }
}
