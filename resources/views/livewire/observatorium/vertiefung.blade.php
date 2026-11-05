@php
    $v = (array) ($e->vertiefung ?? []);
    $status = $e->vertiefung_status;
    $label = $status !== null ? (\Platform\FoodAlchemist\Services\TrendVertiefungService::STATUS[$status] ?? null) : null;
    $kern = (array) ($v['kernaussagen'] ?? []);
    $methodik = array_filter((array) ($v['methodik'] ?? []));
@endphp
@if($status !== null)
<section class="fa-obs-vertiefung" data-observatorium-vertiefung="{{ $status }}" aria-label="Vertiefung aus der Quelle">
    <div class="fa-obs-kicker">Aus der Quelle vertieft · {{ $label }}</div>
    @if($status === 'laeuft')
        <p class="fa-obs-subtitle" role="status">Die Quelle wird gelesen. Die Kernaussagen erscheinen nach dem Neuladen.</p>
    @else
        @if($kern !== [])
            <ol class="fa-obs-kern">
            @foreach($kern as $k)
                <li>@if($k['zahl'] ?? null)<strong>{{ $k['zahl'] }}</strong> · @endif{{ $k['aussage'] }}
                    @if(($k['bezug'] ?? null) || ($k['fundstelle'] ?? null))<span class="fa-obs-subtitle">{{ implode(' · ', array_filter([$k['bezug'] ?? null, isset($k['fundstelle']) ? 'Fundstelle: '.$k['fundstelle'] : null])) }}</span>@endif
                </li>
            @endforeach
            </ol>
        @endif
        @if($methodik !== [])<p class="fa-obs-subtitle">Methodik: {{ implode(' · ', $methodik) }}</p>@endif
        @if($v['hinweis'] ?? null)<p class="fa-obs-subtitle">{{ $status === 'fertig' ? 'Einschränkung: ' : '' }}{{ $v['hinweis'] }}</p>@endif
        <p class="fa-obs-subtitle">Kernaussagen in eigenen Worten, nur mit Zahlen, die im Text der Quelle stehen{{ ($v['art'] ?? null) === 'pdf' ? ' (PDF)' : '' }}@if($e->vertieft_at) · Stand {{ $e->vertieft_at->format('d.m.Y') }}@endif. Kein Volltext gespeichert.</p>
    @endif
</section>
@endif
