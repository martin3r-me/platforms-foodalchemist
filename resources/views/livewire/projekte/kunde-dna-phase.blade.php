{{-- Spec 82 §9 · Phase 3 DNA & Handschrift: die Kunden-DNA der CRM-Firma; „Bleibt“ = verbindlich. --}}
<x-fa::section title="DNA & Handschrift des Kunden" data-projekt-kunde-dna
    description="Wer der Kunde ist, wie er klingt und was er erwartet. Felder mit „Bleibt – unverhandelbar“ gelten in jeder KI-Erzeugung für diesen Kunden unverändert.">
    <div class="flex flex-wrap items-center gap-2 mb-2">
        <x-fa::badge tone="{{ $bleibt > 0 ? 'accent' : 'neutral' }}" icon="heroicon-m-lock-closed" data-projekt-bleibt-zahl>{{ $bleibt }} {{ $bleibt === 1 ? 'Feld bleibt' : 'Felder bleiben' }}</x-fa::badge>
        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Dieselbe Kunden-DNA wie in Einstellungen und im Foodbook, es gibt sie nur einmal je Firma.</span>
    </div>
    @include('foodalchemist::livewire.canvas.partials.board')
</x-fa::section>
