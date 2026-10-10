@props(['series', 'title', 'unit' => '', 'id'])
@php
    // Single-series column chart: one hue, hairline grid, hover/focus tooltip, table view.
    $values = array_column($series, 'count');
    $max = max(1, max($values ?: [0]));
    // Clean y-axis: round the top tick up to 1, 2 or 5 × 10^n and draw 4 intervals.
    $raw = max(1, $max / 4);
    $magnitude = 10 ** floor(log10($raw));
    $step = collect([1, 2, 5, 10])->map(fn ($m) => $m * $magnitude)->first(fn ($v) => $v >= $raw);
    $step = max(1, $step);
    $top = (int) (ceil($max / $step) * $step);
    $ticks = range(0, $top, $step);
    $fmt = fn ($n) => $n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.').'K' : (string) (int) round($n);
    $last = count($series) - 1;
@endphp
<div class="chart" {{ $attributes }}>
    <div class="chart-plot" role="img" aria-label="{{ $title }}: column chart, latest {{ end($values) ?? 0 }}{{ $unit }}">
        <div class="chart-yaxis" aria-hidden="true">
            @foreach ($ticks as $tick)<span style="bottom: {{ $tick / $top * 100 }}%">{{ $fmt($tick) }}</span>@endforeach
        </div>
        <div class="chart-area">
            @foreach ($ticks as $tick)<div class="chart-grid" style="bottom: {{ $tick / $top * 100 }}%" aria-hidden="true"></div>@endforeach
            <div class="chart-bars">
                @foreach ($series as $i => $point)
                    <button type="button" class="chart-col {{ $i === $last ? 'is-today' : '' }}" aria-label="{{ $point['date'] }}: {{ $point['count'] }}{{ $unit }}">
                        <span class="chart-bar" style="height: {{ $point['count'] / $top * 100 }}%"></span>
                        <span class="chart-tip" aria-hidden="true"><strong>{{ number_format($point['count']) }}</strong>{{ $unit }} · {{ $point['date'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>
    <div class="chart-xaxis" aria-hidden="true">
        <span></span>
        <div class="chart-xlabels">@foreach ($series as $i => $point)<span>{{ ($last - $i) % 3 === 0 ? $point['date'] : '' }}</span>@endforeach</div>
    </div>
    <div class="row mt-2">
        <button type="button" class="link-btn small" data-toggle-target="{{ $id }}-table" aria-expanded="false" aria-controls="{{ $id }}-table">Show data table</button>
    </div>
    <div id="{{ $id }}-table" class="chart-table table-wrap" hidden>
        <table class="table table-compact">
            <caption class="sr-only">{{ $title }}</caption>
            <thead><tr><th scope="col">Day</th><th scope="col" class="num">Count</th></tr></thead>
            <tbody>@foreach ($series as $point)<tr><td>{{ $point['date'] }}</td><td class="num">{{ number_format($point['count']) }}</td></tr>@endforeach</tbody>
        </table>
    </div>
</div>
