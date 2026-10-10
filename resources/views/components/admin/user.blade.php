@props(['u', 'meta' => true])
@if ($u)
    <span class="user-cell">
        <span class="avatar" aria-hidden="true">{{ $u->initials() }}</span>
        <span class="truncate">
            <a href="{{ route('admin.users.show', $u) }}" class="user-cell-name">{{ $u->displayName() }}</a>
            @if ($u->is_flagged)<span class="flag-dot" data-tooltip="Flagged for review"><x-admin.icon name="flag" size="xs" /><span class="sr-only">flagged</span></span>@endif
            @if ($meta)<span class="user-cell-meta">{{ $u->publicId() }}{{ $u->username ? ' · @'.$u->username : '' }}</span>@endif
        </span>
    </span>
@else
    <span class="muted">—</span>
@endif
