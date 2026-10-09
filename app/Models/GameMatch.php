<?php

namespace App\Models;

use App\Enums\MatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameMatch extends Model
{
    protected $table = 'game_matches';

    protected $guarded = ['id'];

    protected $hidden = ['invite_code'];

    protected function casts(): array
    {
        return [
            'status' => MatchStatus::class,
            'rules' => 'array',
            'is_tie' => 'boolean',
            'expires_at' => 'datetime',
            'joined_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'settled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function opponent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opponent_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    public function rolls(): HasMany
    {
        return $this->hasMany(MatchRoll::class, 'match_id');
    }

    public function isParticipant(User $user): bool
    {
        return $this->creator_id === $user->id || ($this->opponent_id !== null && $this->opponent_id === $user->id);
    }

    public function otherParticipantId(User $user): ?int
    {
        return $this->creator_id === $user->id ? $this->opponent_id : $this->creator_id;
    }
}
