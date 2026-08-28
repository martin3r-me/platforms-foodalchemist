{{-- Gesamt-Preis pro Person (nur wenn vorhanden — bei à la carte / preislos entfällt der Block). --}}
@php $total = $content['total'] ?? null; @endphp
@if($total && ($total['vk_pro_person'] ?? null) !== null)
    <div class="pt-measure pt-reveal">
        <div class="pt-price-summary">
            <span class="pt-price-big">{{ number_format((float) $total['vk_pro_person'], 2, ',', '.') }} €</span>
            <span class="pt-price-label">pro Person</span>
        </div>
    </div>
@endif
