{{-- Kompakte Allergen-/Diät-Zusammenfassung für eingeklappte Panels:
     Diät-Chips + NUR enthaltene Allergene (rot) + Fleisch-Hinweise. Die volle
     14er-Liste + LMIV-Zusatzstoffe liegt im Baustein x-fa::deklaration (aufgeklappt).
     Erwartet $rezept. fa-pass: x-fa::badge statt Ui-Pillen (hell + Werkbank-Modus). --}}
@php
    $enthalten = collect(\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE)
        ->filter(fn ($lbl, $feld) => $rezept->{"allergen_{$feld}"} === 'enthalten');
    $kurz = fn (string $lbl) => $lbl === 'Glutenhaltiges Getreide' ? 'Gluten' : trim(explode(' (', $lbl)[0]);
@endphp

<div class="flex flex-wrap items-center gap-1.5" data-deklaration-summary>
    @if($rezept->spec_is_vegan === true)<x-fa::badge tone="ok">vegan</x-fa::badge>
    @elseif($rezept->spec_is_vegetarian === true)<x-fa::badge tone="ok">vegetarisch</x-fa::badge>@endif
    @if($rezept->spec_is_gluten_free === true)<x-fa::badge tone="info">glutenfrei</x-fa::badge>@endif
    @if($rezept->spec_is_lactose_free === true)<x-fa::badge tone="info">laktosefrei</x-fa::badge>@endif
    @if($rezept->spec_contains_pork === true)<x-fa::badge tone="warn">enthält Schwein</x-fa::badge>@endif
    @if($rezept->spec_contains_beef === true)<x-fa::badge tone="warn">enthält Rind</x-fa::badge>@endif
    @foreach($enthalten as $feld => $lbl)
        <x-fa::badge tone="crit" title="{{ $lbl }}: enthalten">{{ $kurz($lbl) }}</x-fa::badge>
    @endforeach
    @if($enthalten->isEmpty())<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Keines der 14 EU-Allergene enthalten</span>@endif
</div>
