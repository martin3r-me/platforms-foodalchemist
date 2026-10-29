{{-- „Mit KI schätzen" (HatGpAromaKi): Vorschau, NICHTS gespeichert, bis „Übernehmen" (GL-07). Wahrnehmung auf allen 7 Achsen; die Nährwerte stehen als Grundlage daneben. --}}
@if($sensorikVorschlag !== null)
    <div class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] px-3 py-2.5" data-gp-sensorik-vorschau>
        <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink)]">KI-Vorschlag, noch nicht gespeichert · Sicherheit {{ number_format($sensorikVorschlag['confidence'] * 100, 0, ',', '.') }} %</p>
        <div class="flex flex-wrap gap-1.5">
            @foreach($this->sensorikVorschauZeilen() as $z)
                <x-fa::badge tone="accent" title="{{ $z['konzentration'] !== null ? 'Konzentration ohne Maskierung ' . number_format($z['konzentration'], 2, ',', '.') : 'geschätzt' }}">{{ $z['label'] }} {{ number_format($z['wert'], 2, ',', '.') }}</x-fa::badge>
            @endforeach
        </div>
        @if($sensorikVorschlag['texturen'] !== [])
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">Textur: {{ collect($sensorikVorschlag['texturen'])->pluck('slug')->implode(', ') }}</p>
        @endif
        @if(($sensorikVorschlag['reasoning'] ?? null) !== null)
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] italic">{{ $sensorikVorschlag['reasoning'] }}</p>
        @endif
        <div class="flex gap-2">
            <x-fa::button size="sm" variant="secondary" wire:click="sensorikUebernehmen" data-gp-sensorik-uebernehmen>Übernehmen</x-fa::button>
            <x-fa::button size="sm" variant="ghost" wire:click="sensorikVerwerfen">Verwerfen</x-fa::button>
        </div>
    </div>
@endif
