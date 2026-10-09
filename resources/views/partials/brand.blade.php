@php
    $fonts = [
        'system' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
        'rounded' => 'ui-rounded, "SF Pro Rounded", "Nunito", -apple-system, "Segoe UI", Roboto, sans-serif',
        'serif' => 'ui-serif, Georgia, "Times New Roman", serif',
        'mono' => 'ui-monospace, "SF Mono", Menlo, Consolas, monospace',
    ];
    $color = fn (string $key, string $fallback) => preg_match('/^#[0-9a-fA-F]{6}$/', $settings->string($key)) ? $settings->string($key) : $fallback;
@endphp
<style>
    :root {
        --brand: {{ $color('brand.primary_color', '#7c5cff') }};
        --accent: {{ $color('brand.accent_color', '#22d3a6') }};
        --bg: {{ $color('brand.background_color', '#0f1020') }};
        --surface: {{ $color('brand.surface_color', '#1a1b33') }};
        --font: {!! $fonts[$settings->string('brand.font_family', 'system')] ?? $fonts['system'] !!};
    }
</style>
