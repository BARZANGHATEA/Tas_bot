@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $tone = match ($value) {
        'paid', 'rewarded', 'credited', 'completed', 'active', 'resolved', 'sent', 'won' => 'ok',
        'pending', 'pending_review', 'waiting', 'open', 'medium', 'restricted', 'paused' => 'warn',
        'rejected', 'cancelled', 'suspended', 'reversed', 'failed', 'high', 'unfunded' => 'danger',
        'approved', 'processing', 'ready', 'playing', 'started' => 'info',
        default => 'neutral',
    };
    $label = $status instanceof \App\Enums\WithdrawalStatus ? $status->label() : ucfirst(str_replace('_', ' ', $value));
@endphp
<span {{ $attributes->merge(['class' => 'badge badge-'.$tone]) }}>{{ $label }}</span>
