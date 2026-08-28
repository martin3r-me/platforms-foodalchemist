{{-- Preis-Zusammenfassung: VK pro Person (nur wenn Preisanzeige an → total gesetzt). --}}
@php $total = $content['total'] ?? null; @endphp
@if($total !== null && ($total['vk_pro_person'] ?? null) !== null)
    <div class="pt-price-summary">
        <span class="pt-price-big">{{ number_format((float) $total['vk_pro_person'], 2, ',', '.') }} €</span>
        <span class="pt-price-label">pro Person</span>
    </div>
@endif
