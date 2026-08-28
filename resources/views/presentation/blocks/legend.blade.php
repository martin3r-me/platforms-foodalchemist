{{-- LMIV-Legende: nur real vorkommende Allergene/Zusatzstoffe. --}}
@php $lg = $content['legend'] ?? null; @endphp
@if($lg && ((count($lg['allergene'] ?? []) > 0) || (count($lg['zusatzstoffe'] ?? []) > 0)))
    <div class="pt-measure pt-reveal">
        <div class="pt-legend">
            @if(count($lg['allergene'] ?? []) > 0)
                <div><strong>Allergene:</strong>
                    @foreach($lg['allergene'] as $a)<span class="pt-code">{{ $a['code'] }}</span> {{ $a['label'] }}@if(!$loop->last) · @endif @endforeach
                </div>
            @endif
            @if(count($lg['zusatzstoffe'] ?? []) > 0)
                <div><strong>Zusatzstoffe:</strong>
                    @foreach($lg['zusatzstoffe'] as $z)<span class="pt-code">{{ $z['code'] }}</span> {{ $z['label'] }}@if(!$loop->last) · @endif @endforeach
                </div>
            @endif
            <div class="pt-legend-note">* = Spuren möglich · ohne Angabe = nicht bewertet, nicht „frei"</div>
        </div>
    </div>
@endif
