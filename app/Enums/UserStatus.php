<?php

namespace App\Enums;

enum UserStatus: string
{
    /** Full access. */
    case Active = 'active';
    /** Can sign in and view, but cannot play, claim rewards or withdraw. */
    case Restricted = 'restricted';
    /** Cannot use the Mini App at all. */
    case Suspended = 'suspended';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
