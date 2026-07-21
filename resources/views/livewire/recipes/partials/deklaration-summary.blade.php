{{-- Kompakte Allergen-/Diät-Zusammenfassung für eingeklappte Panels:
     Diät-Chips + NUR enthaltene Allergene (rot) + Fleisch-Hinweise. Der volle
     14er-Grid + LMIV-Zusatzstoffe liegt im Partial deklaration (aufgeklappt).
     Erwartet $rezept + Ui-Maps im Kontext. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($enthalten = collect(\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE)
        ->filter(fn ($lbl, $feld) => $rezept->{"allergen_{$feld}"} === 'enthalten'))

<div class="flex flex-wrap gap-1" data-deklaration-summary>
    @if($rezept->spec_is_vegan === true)<span class="{{ $pill }} {{ $variantPill['success'] }}">vegan</span>
    @elseif($rezept->spec_is_vegetarian === true)<span class="{{ $pill }} {{ $variantPill['success'] }}">vegetarisch</span>@endif
    @if($rezept->spec_is_gluten_free === true)<span class="{{ $pill }} {{ $variantPill['info'] }}">glutenfrei</span>@endif
    @if($rezept->spec_is_lactose_free === true)<span class="{{ $pill }} {{ $variantPill['info'] }}">laktosefrei</span>@endif
    @if($rezept->spec_contains_pork === true)<span class="{{ $pill }} {{ $variantPill['warning'] }}">enth. Schwein</span>@endif
    @if($rezept->spec_contains_beef === true)<span class="{{ $pill }} {{ $variantPill['warning'] }}">enth. Rind</span>@endif
    @foreach($enthalten as $feld => $lbl)
        <span class="{{ $pill }} {{ $variantPill['danger'] }}" title="{{ $lbl }} — enthalten">{{ explode(' ', $lbl)[0] }}</span>
    @endforeach
    @if($enthalten->isEmpty())<span class="text-[11px] text-gray-500 italic">keine der 14 EU-Allergene enthalten</span>@endif
</div>
