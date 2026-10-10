@props(['details'])
@php
    // Free-form JSON context (fraud signals, audit entries) shown as readable key/value
    // pairs and link anything that refers to another player.
    $details = is_array($details) ? $details : [];
    $canView = auth('admin')->user()?->hasPermission('users.view');
@endphp
@if ($details)
    <dl {{ $attributes->merge(['class' => 'kv']) }}>
        @foreach ($details as $key => $value)
            @php $isUserRef = is_string($key) && preg_match('/user_ids?$/', $key); @endphp
            <div>
                <dt>{{ ucfirst(str_replace('_', ' ', preg_replace('/_ids?$/', '', (string) $key))) }}</dt>
                <dd>
                    @foreach ((array) $value as $item)
                        @if ($isUserRef && is_numeric($item) && $canView)
                            <a href="{{ route('admin.users.show', (int) $item) }}">#{{ (int) $item }}</a>@if (! $loop->last), @endif
                        @elseif (is_scalar($item) || $item === null)
                            {{ is_bool($item) ? ($item ? 'yes' : 'no') : \Illuminate\Support\Str::limit((string) ($item ?? '—'), 160) }}@if (! $loop->last), @endif
                        @else
                            <code>{{ json_encode($item) }}</code>
                        @endif
                    @endforeach
                </dd>
            </div>
        @endforeach
    </dl>
@else
    <span class="muted">—</span>
@endif
