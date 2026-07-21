{{-- Food-DNA als Einstellungen-Sektion (Ebene 1 der DNA-Kette) — geteiltes Canvas-Board --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
<div>
    <div class="relative overflow-hidden {{ $card }} p-5">
        <div class="{{ $cardAccent }}"></div>
        <p class="{{ $label }} mb-2">Food DNA — Küchen-/Marken-Identität. Ebene 1 der DNA-Kette (Team → Kunde → Foodbook): fließt als stehende Referenz in jede KI-Generierung.</p>
        @include('foodalchemist::livewire.canvas.partials.board')
    </div>
</div>
