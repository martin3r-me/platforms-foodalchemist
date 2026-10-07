{{-- Kunde-DNA als Einstellungen-Sektion (Ebene 2 der DNA-Kette) — Firma wählen → geteiltes Canvas-Board
     fa-pass 2026-10-05: Kunde wählen als eigener Abschnitt oben (Suche mit Trefferliste), darunter das Board.
     Häufigste Aufgabe = Kunde finden und seine DNA pflegen. --}}
<div class="flex flex-col gap-4">
    <x-fa::section title="Kunde" description="Marke, Ton und No-Gos des Kunden fließen in jede KI-Erzeugung für diesen Kunden. Sie ergänzen die Food DNA deines Hauses.">
        @if(! $crmVerfuegbar)
            <x-fa::notice tone="warn" data-kunde-dna-kein-crm>Die Kundenverwaltung ist nicht erreichbar. Ohne sie lässt sich keine Kunden-DNA pflegen.</x-fa::notice>
        @else
            {{-- Firma-Auswahl --}}
            @if($firmaFehler)
                <x-fa::notice tone="warn" data-kunde-dna-fehler>{{ $firmaFehler }}</x-fa::notice>
            @endif
            @if($companyId === null)
                <div class="flex flex-col gap-2 max-w-md" data-kunde-dna-picker>
                    <x-fa::field label="Kunde suchen" for="kunde-dna-suche">
                        <div class="relative">
                            @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                            <x-fa::input id="kunde-dna-suche" type="search" wire:model.live.debounce.300ms="firmaSuche" placeholder="Firmenname" class="pl-8" data-kunde-dna-suche />
                        </div>
                    </x-fa::field>
                    @if($firmen->isNotEmpty())
                        <div class="flex flex-col rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] divide-y divide-[var(--fa-line)] overflow-hidden">
                            @foreach($firmen as $f)
                                <button type="button" wire:key="kdna-fi-{{ $f->id }}" wire:click="firmaWaehlen({{ $f->id }})"
                                        class="w-full text-left px-3 py-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">{{ $f->display_name }}</button>
                            @endforeach
                        </div>
                    @elseif(trim($firmaSuche) !== '')
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Keine Firma gefunden. Schreibweise prüfen oder die Firma zuerst in der Kundenverwaltung anlegen.</p>
                    @else
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Namen eintippen, dann den Kunden aus der Liste wählen.</p>
                    @endif
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2" data-kunde-dna-gewaehlt>
                    <x-fa::badge tone="accent" icon="heroicon-m-building-office-2">{{ $companyName }}</x-fa::badge>
                    <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrows-right-left" wire:click="firmaLoesen">Anderen Kunden wählen</x-fa::button>
                    @if(Route::has('crm.companies.show'))
                        <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrow-top-right-on-square" :href="route('crm.companies.show', $companyId)" data-kunde-dna-crm-link>Im CRM öffnen</x-fa::button>
                    @endif
                </div>
            @endif
        @endif
    </x-fa::section>

    {{-- Canvas-Board erst nach Firmen-Wahl (canvasInit ist dann gelaufen) --}}
    @if($companyId !== null && $crmVerfuegbar)
        @include('foodalchemist::livewire.canvas.partials.board')
    @endif
</div>
