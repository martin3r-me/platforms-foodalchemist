@php
    $dek = $deklaration ?? [];
    $allergene = collect($dek['allergene'] ?? []);
    $zusatzstoffe = collect($dek['zusatzstoffe'] ?? []);
    $specs = collect($dek['specs'] ?? []);
    $allergenText = fn ($wert) => match ($wert) {
        'enthalten' => 'enthalten',
        'spuren' => 'Spuren',
        'nicht_enthalten' => 'frei',
        default => 'unbekannt',
    };
    $zusatzText = fn ($wert) => $wert === null ? 'unbewertet' : ((int) $wert === 3 ? 'enthalten' : 'nicht enthalten');
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

<h5>Allergene <span class="muted">Konfidenz {{ strtoupper((string) ($dek['allergens_confidence'] ?? '—')) }}</span></h5>
<table>
    <thead><tr><th>Allergen</th><th>Status</th></tr></thead>
    <tbody>
        @foreach($allergene as $a)
            @if(($a['wert'] ?? 'unbekannt') !== 'nicht_enthalten')
                <tr><td>{{ $a['label'] }}</td><td>{{ $allergenText($a['wert'] ?? null) }}</td></tr>
            @endif
        @endforeach
        @if($allergene->filter(fn ($a) => ($a['wert'] ?? 'unbekannt') !== 'nicht_enthalten')->isEmpty())
            <tr><td colspan="2" class="muted">Keine der 14 EU-Allergene deklariert.</td></tr>
        @endif
    </tbody>
</table>

@if($zusatzstoffe->isNotEmpty())
    <h5>Zusatzstoffe</h5>
    <table>
        <thead><tr><th>Zusatzstoff</th><th>Status</th></tr></thead>
        <tbody>
            @foreach($zusatzstoffe as $z)
                @if(($z['wert'] ?? null) === null || (int) $z['wert'] === 3)
                    <tr><td>{{ $z['label'] }}</td><td>{{ $zusatzText($z['wert'] ?? null) }}</td></tr>
                @endif
            @endforeach
            @if($zusatzstoffe->filter(fn ($z) => ($z['wert'] ?? null) === null || (int) $z['wert'] === 3)->isEmpty())
                <tr><td colspan="2" class="muted">Keine Zusatzstoffe deklariert.</td></tr>
            @endif
        </tbody>
    </table>
@endif
