@props(['amount', 'decimals' => 2, 'signed' => false, 'unit' => 'USDT'])
@php
    $value = \App\Support\Money::of(is_numeric($amount) || $amount === null ? (string) $amount : $amount);
    $text = \App\Support\Money::format($value->abs(), $decimals);
    $sign = $signed ? ($value->isNegative() ? '−' : ($value->isPositive() ? '+' : '')) : ($value->isNegative() ? '−' : '');
    $class = $signed ? ($value->isNegative() ? 'neg' : ($value->isPositive() ? 'pos' : '')) : '';
@endphp
<span {{ $attributes->merge(['class' => trim('money '.$class)]) }}>{{ $sign }}{{ $text }}@if ($unit)<span class="unit">{{ $unit }}</span>@endif</span>
