@extends('admin.layout')
@section('title', 'Settings · '.$definition['label'])
@section('content')
<div class="tabs">
    @foreach ($schema as $key => $g)
        <a href="{{ route('admin.settings.edit', $key) }}" class="{{ $key === $group ? 'active' : '' }}">{{ $g['label'] }}@if ($g['sensitive'] ?? false) 🔒@endif</a>
    @endforeach
</div>

@php $locked = ($definition['sensitive'] ?? false) && ! $canSensitive; @endphp
@if ($definition['help'] ?? null)<div class="alert alert-info">{{ $definition['help'] }}</div>@endif
@if ($locked)<div class="alert alert-warn">Only super administrators can change these settings. You can view them.</div>@endif

<form method="post" action="{{ route('admin.settings.update', $group) }}" class="panel" @if ($definition['sensitive'] ?? false) data-confirm="Save these sensitive settings?" @endif>
    @csrf @method('put')
    <fieldset @disabled($locked) style="border:0;padding:0;margin:0">
        <div class="form-grid">
        @foreach ($definition['fields'] as $key => $field)
            @php
                $name = str_replace('.', '__', $key);
                $value = old('settings.'.$name, $values[$key] ?? null);
                $wide = in_array($field['type'], ['text', 'json'], true);
            @endphp
            <div style="{{ $wide ? 'grid-column: 1 / -1' : '' }}">
                @if ($field['type'] === 'bool')
                    <input type="hidden" name="settings[{{ $name }}]" value="0">
                    <label class="check"><input type="checkbox" name="settings[{{ $name }}]" value="1" @checked(filter_var($value, FILTER_VALIDATE_BOOLEAN))> {{ $field['label'] }}</label>
                @else
                    <label class="field"><span>{{ $field['label'] }}</span>
                        @switch($field['type'])
                            @case('text')
                                <textarea name="settings[{{ $name }}]" rows="4">{{ $value }}</textarea>
                                @break
                            @case('json')
                                <textarea class="code" name="settings[{{ $name }}]" spellcheck="false">{{ is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</textarea>
                                @break
                            @case('select')
                                <select name="settings[{{ $name }}]">@foreach ($field['options'] as $optValue => $optLabel)<option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $optLabel }}</option>@endforeach</select>
                                @break
                            @case('color')
                                <input type="color" name="settings[{{ $name }}]" value="{{ $value }}">
                                @break
                            @case('datetime')
                                <input class="input" type="datetime-local" name="settings[{{ $name }}]" value="{{ $value ? \Carbon\CarbonImmutable::parse($value)->format('Y-m-d\TH:i') : '' }}">
                                @break
                            @case('int')
                                <input class="input" type="number" name="settings[{{ $name }}]" value="{{ $value }}" step="1">
                                @break
                            @default
                                <input class="input" name="settings[{{ $name }}]" value="{{ $value }}" @if ($field['type'] === 'decimal') inputmode="decimal" @endif>
                        @endswitch
                        @if ($field['help'] ?? null)<span class="help">{{ $field['help'] }}</span>@endif
                        @error($name)<span class="error-text">{{ $message }}</span>@enderror
                    </label>
                @endif
            </div>
        @endforeach
        </div>

        @if ($definition['sensitive'] ?? false)
            <div style="max-width:360px"><x-admin.confirm-password /></div>
        @endif
        <button class="btn" type="submit">Save {{ strtolower($definition['label']) }}</button>
    </fieldset>
</form>
@endsection
