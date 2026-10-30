{{-- #1003 Hochrechnen am Basisrezept (Rezept-Detail am Computer und Handbuch am Handy): „auf ___ kg" oder „nach Zutat",
     Faktor sichtbar, Zurücksetzen. Rechnung = Support\Hochrechnung (dieselbe wie das Dokument). Ansicht speichert nichts;
     „Als neuen Ansatz übernehmen" nur am Computer ($uebernehmbar). Parameter: $hoch, $handy, $uebernehmbar. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $feld = $handy ? 'h-11 w-28 text-[length:var(--fa-text-base)]' : 'h-8 w-24';
    $zutat = $hoch['zutat'];
@endphp
<div class="flex flex-col gap-1.5 mb-2 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-3 py-2" data-fa-hochrechner>
    <div class="flex flex-wrap items-center gap-2">
        <label for="hoch-ziel" class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">Hochrechnen auf</label>
        <span class="inline-flex items-center gap-1.5">
            <input id="hoch-ziel" type="text" inputmode="decimal" autocomplete="off" wire:model.live.debounce.400ms="hochZielKg"
                   class="fa-control {{ $feld }} text-right tabular-nums" data-fa-hoch-ziel>
            <span class="text-[var(--fa-ink-2)]">kg</span>
        </span>
        @if($hoch['aktiv'])
            <x-fa::badge tone="info" data-fa-hoch-faktor>{{ $hoch['faktor_text'] }}</x-fa::badge>
            <button type="button" wire:click="hochZuruecksetzen" class="{{ $handy ? 'min-h-11 px-2' : '' }} underline underline-offset-2 {{ $leise }}" data-fa-hoch-zuruecksetzen>Zurücksetzen</button>
        @endif
    </div>

    @if($zutat !== null)
        <div class="flex flex-wrap items-center gap-2" data-fa-hoch-zutat="{{ $zutat->id }}">
            <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">Von <strong class="font-medium text-[var(--fa-ink)]">{{ $zutat->gp?->name ?? $zutat->referencedRecipe?->name ?? $zutat->display_name ?? $zutat->raw_text }}</strong> habe ich</span>
            <span class="inline-flex items-center gap-1.5">
                <input type="text" inputmode="decimal" autocomplete="off" wire:model.live.debounce.400ms="hochHabe" aria-label="Vorhandene Menge"
                       class="fa-control {{ $feld }} text-right tabular-nums" data-fa-hoch-habe>
                <span class="text-[var(--fa-ink-2)]">{{ $zutat->unit?->slug }}</span>
            </span>
            @if($hoch['ziel_kg'] !== null)
                <span class="{{ $leise }}">reicht für {{ \Platform\FoodAlchemist\Support\Hochrechnung::zahlText($hoch['ziel_kg']) }} kg</span>
            @endif
        </div>
    @endif

    @if($hoch['hinweis'])
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]" data-fa-hoch-hinweis>{{ $hoch['hinweis'] }}</p>
    @elseif(! $hoch['aktiv'])
        <p class="{{ $leise }}">Oder Menge einer Zutat antippen: „davon habe ich …“.</p>
    @endif

    @if($hoch['aktiv'] && $uebernehmbar)
        @if($hoch['ohne_menge'] === [])
            <div>
                <x-fa::button size="sm" variant="secondary" wire:click="hochUebernehmen" wire:confirm="{{ $hoch['bestaetigung'] }}" data-fa-hoch-uebernehmen>Als neuen Ansatz übernehmen</x-fa::button>
            </div>
        @else
            <p class="{{ $leise }}" data-fa-hoch-ohne-menge>Übernehmen geht erst, wenn alle Zeilen eine Menge haben (fehlt bei {{ implode(', ', $hoch['ohne_menge']) }}).</p>
        @endif
    @endif
</div>
