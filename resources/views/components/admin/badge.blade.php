@props(['status', 'label' => null])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $tone = match ($value) {
        'paid', 'rewarded', 'credited', 'completed', 'active', 'resolved', 'sent', 'won', 'ok', 'low' => 'success',
        'pending', 'pending_review', 'waiting', 'open', 'medium', 'restricted', 'paused', 'partial' => 'warning',
        'rejected', 'cancelled', 'suspended', 'reversed', 'failed', 'high', 'unfunded', 'flagged', 'lost' => 'danger',
        'approved', 'processing', 'ready', 'playing', 'started', 'info' => 'info',
        default => 'neutral',
    };
    $text = $label ?? ($status instanceof \App\Enums\WithdrawalStatus ? $status->label() : ucfirst(str_replace('_', ' ', $value)));
@endphp
<span {{ $attributes->merge(['class' => 'badge badge-'.$tone]) }}>{{ $text }}</span>
