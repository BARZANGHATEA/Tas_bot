@extends('admin.layout')
@section('title', 'Settings · '.$definition['label'])
@section('content')
@php
    $locked = ($definition['sensitive'] ?? false) && ! $canSensitive;
    $sensitive = (bool) ($definition['sensitive'] ?? false);
@endphp
<div class="page-header">
    <div><h1>Settings</h1><p class="page-sub">Everything players see and every business rule. Changes are logged with old and new values.</p></div>
</div>
<div class="grid grid-settings">
    <nav aria-label="Settings sections" class="card settings-nav">
        @foreach ($schema as $key => $g)
            <a href="{{ route('admin.settings.edit', $key) }}" class="nav-link" @if ($key === $group) aria-current="page" @endif>
                <span class="grow">{{ $g['label'] }}</span>
                @if ($g['sensitive'] ?? false)<span data-tooltip="Super administrators only"><x-admin.icon name="lock" size="xs" /><span class="sr-only">(restricted)</span></span>@endif
            </a>
        @endforeach
    </nav>

    <form method="post" action="{{ route('admin.settings.update', $group) }}" class="card" @if ($sensitive) data-confirm="These settings change rewards, limits or money rules for every player." data-confirm-title="Save sensitive settings?" data-confirm-button="Save changes" @endif>
        @csrf @method('put')
        <div class="card-header">
            <div><h2>{{ $definition['label'] }} @if ($sensitive)<x-admin.badge status="info" label="Sensitive" class="badge-plain" />@endif</h2>
                @if ($definition['help'] ?? null)<p class="card-sub">{{ $definition['help'] }}</p>@endif
            </div>
        </div>
        <div class="card-body">
            @if ($locked)<div class="alert alert-warning"><x-admin.icon name="lock" /><div class="alert-body">Only super administrators can change these settings. You can view them.</div></div>@endif
            <fieldset class="fieldset" @disabled($locked)>
                <legend class="sr-only">{{ $definition['label'] }}</legend>
                <div class="form-grid">
                @foreach ($definition['fields'] as $key => $field)
                    @php
                        $name = str_replace('.', '__', $key);
                        $id = 'f-'.$name;
                        $value = old('settings.'.$name, $values[$key] ?? null);
                        $wide = in_array($field['type'], ['text', 'json'], true);
                        $hasError = $errors->has($name);
                    @endphp
                    <div class="{{ $wide || $field['type'] === 'bool' ? 'span-2' : '' }}">
                        @if ($field['type'] === 'bool')
                            <input type="hidden" name="settings[{{ $name }}]" value="0">
                            <label class="switch" for="{{ $id }}">
                                <input type="checkbox" id="{{ $id }}" name="settings[{{ $name }}]" value="1" role="switch" @checked(filter_var($value, FILTER_VALIDATE_BOOLEAN))>
                                <span class="switch-track" aria-hidden="true"></span>
                                <span class="check-text">{{ $field['label'] }}</span>
                            </label>
                        @else
                            <div class="field">
                                <label class="field-label" for="{{ $id }}">{{ $field['label'] }}</label>
                                @switch($field['type'])
                                    @case('text')
                                        <textarea id="{{ $id }}" class="textarea" name="settings[{{ $name }}]" rows="4" @if ($hasError) aria-invalid="true" @endif>{{ $value }}</textarea>
                                        @break
                                    @case('json')
                                        <textarea id="{{ $id }}" class="textarea code" name="settings[{{ $name }}]" spellcheck="false" data-json @if ($hasError) aria-invalid="true" @endif aria-describedby="{{ $id }}-status">{{ is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</textarea>
                                        <span class="field-help" id="{{ $id }}-status" aria-live="polite"></span>
                                        @break
                                    @case('select')
                                        <select id="{{ $id }}" class="select" name="settings[{{ $name }}]">@foreach ($field['options'] as $optValue => $optLabel)<option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $optLabel }}</option>@endforeach</select>
                                        @break
                                    @case('color')
                                        <div class="row"><input id="{{ $id }}" type="color" class="input input-color" name="settings[{{ $name }}]" value="{{ $value }}"><code>{{ $value }}</code></div>
                                        @break
                                    @case('datetime')
                                        <input id="{{ $id }}" class="input" type="datetime-local" name="settings[{{ $name }}]" value="{{ $value ? \Carbon\CarbonImmutable::parse($value)->format('Y-m-d\TH:i') : '' }}">
                                        @break
                                    @case('int')
                                        <input id="{{ $id }}" class="input" type="number" name="settings[{{ $name }}]" value="{{ $value }}" step="1" @if ($hasError) aria-invalid="true" @endif>
                                        @break
                                    @default
                                        <input id="{{ $id }}" class="input" name="settings[{{ $name }}]" value="{{ $value }}" @if ($field['type'] === 'decimal') inputmode="decimal" @endif @if ($hasError) aria-invalid="true" @endif>
                                @endswitch
                                @if ($field['help'] ?? null)<span class="field-help">{{ $field['help'] }}</span>@endif
                                @error($name)<span class="field-error"><x-admin.icon name="alert" size="xs" />{{ $message }}</span>@enderror
                            </div>
                        @endif
                    </div>
                @endforeach
                </div>
                @if ($sensitive && ! $locked)
                    <div style="max-width:380px"><x-admin.confirm-password /></div>
                @endif
            </fieldset>
        </div>
        @unless ($locked)
            <div class="card-footer row-between">
                <span class="small muted">Saved values apply immediately.</span>
                <button class="btn btn-primary" type="submit">Save {{ strtolower($definition['label']) }}</button>
            </div>
        @endunless
    </form>
</div>
@endsection
