{{-- Phase 2: Kunde-DNA (Ebene 2) — nutzt das geteilte Canvas-Board-Partial (wie Team-DNA/Foodbook/Concept) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
<div>
    @if($companyId)
        <div class="relative overflow-hidden {{ $card }} p-5">
            <div class="{{ $cardAccent }}"></div>
            <p class="{{ $label }} mb-2">Kunde-DNA — wer der Kunde ist · Kommunikation/Ton · Erwartungen (fließt in jedes Foodbook dieses Kunden)</p>
            @include('foodalchemist::livewire.canvas.partials.board')
        </div>
    @else
        <div class="{{ $card }} p-5 text-sm text-gray-500">Kunde-DNA: erst im <strong>Briefing</strong>-Tab einen CRM-Kunden verknüpfen — dann pflegst du hier die stabile Marken-/Kommunikations-Identität des Kunden.</div>
    @endif
</div>
