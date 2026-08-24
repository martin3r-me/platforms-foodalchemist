@php
    // Geteilter Concept-Report-Körper (Übersicht + Slots): einmal für den Concept-Zweig,
    // einmal je Edition im Format-Zweig — so ist der Filter-Satz (opt) LITERAL derselbe.
    $opt = $optionen ?? [];
    $money = fn ($v, $dec = 2) => $v !== null && $v !== '' ? number_format((float) $v, $dec, ',', '.') . ' €' : '—';
@endphp
<section>
    <h2>Concept-Übersicht</h2>
    <div class="grid meta">
        <div><span>Status</span>{{ $concept['status'] ?? '—' }}</div>
        <div><span>Anlass</span>{{ $concept['occasion'] ?? '—' }}</div>
        <div><span>Niveau</span>{{ $concept['level'] ?? '—' }}</div>
        <div><span>Kategorie</span>{{ $concept['category'] ?? '—' }}</div>
        <div><span>Preis/Person</span>{{ $money($concept['price_per_person_cache'] ?? null) }}</div>
        <div><span>EK/Person</span>{{ $money($concept['ek_per_person_cache'] ?? null) }}</div>
        <div><span>Arbeitszeit</span>{{ ($concept['work_time_min_cache'] ?? null) !== null ? $concept['work_time_min_cache'] . ' min' : '—' }}</div>
        <div><span>Servierform</span>{{ $concept['serving_form'] ?? '—' }}</div>
    </div>
    @if($concept['description'] ?? null)<p class="intro">{{ $concept['description'] }}</p>@endif
    @if(count($concept['moments'] ?? []) || count($concept['seasons'] ?? []))
        <p class="muted">Einsatzmomente: {{ implode(', ', $concept['moments'] ?? []) ?: '—' }} · Saison: {{ implode(', ', $concept['seasons'] ?? []) ?: '—' }}</p>
    @endif
</section>

<section>
    <h2>Slots</h2>
    @forelse($concept['slots'] as $slot)
        <div class="slot">
            <h3>{{ $slot['role'] ?: 'Slot' }} @if($slot['title'])<span class="muted">· {{ $slot['title'] }}</span>@endif <span class="badge">{{ $slot['type'] }}</span></h3>
            @if($slot['package'])
                <p class="muted">Paket: {{ $slot['package']['name'] }} · Preis {{ $money($slot['package']['price_per_person']) }} p. P. · EK {{ $money($slot['package']['ek_per_person'], 2) }} p. P.</p>
            @endif
            @forelse($slot['gerichte'] as $g)
                @if($g['paket'] ?? null)<p class="muted">Aus Paket {{ $g['paket'] }}{{ $g['menge'] ? ' · ' . $g['menge'] : '' }}</p>@endif
                @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $g['recipe'], 'optionen' => $opt])
            @empty
                <p class="muted">Leer.</p>
            @endforelse
        </div>
    @empty
        <p class="muted">Keine Slots.</p>
    @endforelse
</section>
