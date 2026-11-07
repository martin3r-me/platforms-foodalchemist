{{-- Observatorium zentral · wöchentliches Lagebild (Master schreibt per MCP, alle Mandanten lesen). Jede Aussage mit Quelle. --}}
@php $fmt = fn (?string $d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d.m.Y') : ''; @endphp
@forelse($lagebilder as $lb)
    <article class="fa-obs-briefing" data-observatorium-lagebild="{{ $lb['id'] }}">
        <span class="fa-obs-pill">Lagebild · {{ $fmt($lb['zeitraum_von']) }} – {{ $fmt($lb['zeitraum_bis']) }}</span>
        <h2>{{ $lb['titel'] }}</h2>
        @if($lb['kurzfazit'])<p>{{ $lb['kurzfazit'] }}</p>@endif
        <h3>Was diese Woche wichtig war</h3>
        @foreach($lb['aussagen'] as $a)
            <div class="fa-obs-brief-item" data-lagebild-aussage>
                <p>{{ $a['text'] }}</p>
                <div class="fa-obs-meta">
                    @foreach($a['belege'] as $b)<span>@if($b['url'])<a class="fa-obs-link" href="{{ $b['url'] }}" target="_blank" rel="noopener noreferrer">{{ $b['herausgeber'] ?: \Illuminate\Support\Str::limit($b['titel'], 60) }}</a>@else{{ $b['herausgeber'] ?: \Illuminate\Support\Str::limit($b['titel'], 60) }}@endif</span>@endforeach
                    @if($a['markt'])<span>Markt in Zahlen (Destatis)</span>@endif
                </div>
            </div>
        @endforeach
        @if($lb['impulse'] !== [])
            <h3>Impulse für euch</h3>
            @foreach($lb['impulse'] as $a)
                <div class="fa-obs-brief-item" data-lagebild-impuls>
                    <p>{{ $a['text'] }}</p>
                    <div class="fa-obs-meta">@foreach($a['belege'] as $b)<span>{{ $b['herausgeber'] ?: \Illuminate\Support\Str::limit($b['titel'], 60) }}</span>@endforeach @if($a['markt'])<span>Markt in Zahlen (Destatis)</span>@endif</div>
                </div>
            @endforeach
        @endif
        <p class="fa-obs-subtitle">Kuratiert von BHG.DIGITAL · Zahlen nur aus den genannten Quellen @if($lb['verworfen'] > 0)· {{ $lb['verworfen'] }} {{ $lb['verworfen'] === 1 ? 'Aussage' : 'Aussagen' }} ohne Beleg verworfen @endif</p>
    </article>
@empty
    <div class="fa-obs-empty" data-observatorium-lagebild-leer><h2>Noch kein Lagebild.</h2><p>Jeden Montag erscheint hier die Woche auf einen Blick – jede Aussage mit Quelle.</p></div>
@endforelse
