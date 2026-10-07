@php
    $dek = $deklaration ?? [];
    $allergene = collect($dek['allergene'] ?? []);
    $zusatzstoffe = collect($dek['zusatzstoffe'] ?? []);
    $specs = collect($dek['specs'] ?? []);
@endphp

<h4>Deklaration</h4>
@if($specs->isNotEmpty())
    <div class="grid meta">
        @foreach($specs as $label => $wert)
            @if($wert !== null)
                <div><span>{{ $label }}</span>{{ $wert ? 'ja' : 'nein' }}</div>
            @endif
        @endforeach
    </div>
@endif

{{-- fa-pass Druck-Muster (2026-10-05): gruppiert statt einer Zeile je Allergen. Wichtiges zuerst
     (Enthalten, Spuren), Unbewertetes gesammelt darunter — gleiche Information, ein Drittel der Höhe. --}}
@php
    $gruppe = fn ($wert) => $allergene->filter(fn ($a) => ($a['wert'] ?? 'unbekannt') === $wert)->pluck('label');
    $aEnth = $gruppe('enthalten');
    $aSpur = $gruppe('spuren');
    $aOffen = $allergene->filter(fn ($a) => ! in_array($a['wert'] ?? 'unbekannt', ['enthalten', 'spuren', 'nicht_enthalten'], true))->pluck('label');
    $zEnth = $zusatzstoffe->filter(fn ($z) => ($z['wert'] ?? null) !== null && (int) $z['wert'] === 3)->pluck('label');
    $zOffen = $zusatzstoffe->filter(fn ($z) => ($z['wert'] ?? null) === null)->pluck('label');
@endphp
<h5>Allergene <span class="muted">· Sicherheit der Angaben: {{ \Platform\FoodAlchemist\Support\Labels::konfidenz($dek['allergens_confidence'] ?? null) }}</span></h5>
<table class="deklaration">
    <tbody>
        @if($aEnth->isNotEmpty())<tr><th>Enthalten</th><td><strong>{{ $aEnth->implode(', ') }}</strong></td></tr>@endif
        @if($aSpur->isNotEmpty())<tr><th>Spuren</th><td>{{ $aSpur->implode(', ') }}</td></tr>@endif
        @if($aOffen->isNotEmpty())<tr><th>Ohne Angabe</th><td class="muted">{{ $aOffen->implode(', ') }}</td></tr>@endif
        @if($aEnth->isEmpty() && $aSpur->isEmpty() && $aOffen->isEmpty())
            <tr><td colspan="2" class="muted">Frei von allen 14 EU-Allergenen.</td></tr>
        @endif
    </tbody>
</table>

@if($zusatzstoffe->isNotEmpty())
    <h5>Zusatzstoffe</h5>
    <table class="deklaration">
        <tbody>
            @if($zEnth->isNotEmpty())<tr><th>Enthalten</th><td><strong>{{ $zEnth->implode(', ') }}</strong></td></tr>@endif
            @if($zOffen->isNotEmpty())<tr><th>Nicht bewertet</th><td class="muted">{{ $zOffen->implode(', ') }}</td></tr>@endif
            @if($zEnth->isEmpty() && $zOffen->isEmpty())
                <tr><td colspan="2" class="muted">Keine Zusatzstoffe deklariert.</td></tr>
            @endif
        </tbody>
    </table>
@endif
