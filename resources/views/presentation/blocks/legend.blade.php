{{-- LMIV-Legende: nur real vorkommende Allergene/Zusatzstoffe (nur wenn Deklaration an). --}}
@php $lg = $content['legend'] ?? null; @endphp
@if($lg && (count($lg['allergene'] ?? []) || count($lg['zusatzstoffe'] ?? [])))
    <footer class="pt-legend">
        @if(count($lg['allergene'] ?? []))
            <div><strong>Allergene:</strong>
                @foreach($lg['allergene'] as $a)<span class="pt-code">{{ $a['code'] }}</span> {{ $a['label'] }}@if(!$loop->last) · @endif @endforeach
            </div>
        @endif
        @if(count($lg['zusatzstoffe'] ?? []))
            <div><strong>Zusatzstoffe:</strong>
                @foreach($lg['zusatzstoffe'] as $z)<span class="pt-code">{{ $z['code'] }}</span> {{ $z['label'] }}@if(!$loop->last) · @endif @endforeach
            </div>
        @endif
        <div class="pt-legend-note">* = Spuren möglich · ohne Angabe = nicht bewertet, nicht „frei"</div>
    </footer>
@endif
