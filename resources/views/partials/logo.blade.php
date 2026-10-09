@if ($settings->string('brand.logo_url'))
    <img class="logo-img" src="{{ $settings->string('brand.logo_url') }}" alt="{{ $settings->string('app.name') }}">
@else
    <svg class="logo-svg" viewBox="0 0 64 64" aria-hidden="true">
        <rect x="6" y="6" width="52" height="52" rx="14" fill="var(--brand)"/>
        <circle cx="21" cy="21" r="5" fill="#fff"/><circle cx="43" cy="21" r="5" fill="#fff"/>
        <circle cx="32" cy="32" r="5" fill="#fff"/>
        <circle cx="21" cy="43" r="5" fill="#fff"/><circle cx="43" cy="43" r="5" fill="#fff"/>
    </svg>
@endif
