<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reply_markup' => 'array',
            'send_after' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
