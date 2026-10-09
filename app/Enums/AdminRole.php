<?php

namespace App\Enums;

enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Finance = 'finance';
    case Support = 'support';
    case Analyst = 'analyst';

    public function label(): string
    {
        return config('dicegame.roles.'.$this->value, $this->value);
    }
}
