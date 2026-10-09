@if ($u)
    <a href="{{ route('admin.users.show', $u) }}">{{ $u->publicId() }}</a>
    <span class="muted small">{{ $u->username ? '@'.$u->username : $u->displayName() }}</span>
    @if ($u->is_flagged)<span class="badge badge-danger" title="Flagged">⚑</span>@endif
@else
    <span class="muted">—</span>
@endif
