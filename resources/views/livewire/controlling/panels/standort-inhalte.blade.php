{{-- Spec 77d · Controlling → Standorte & Freigaben: je Unter-Team „übernimmt alles vom Oberteam" oder nur Freigegebenes
     (Sammlungen + fertige Ausgaben). Freigegebenes ist im Standort lesend; anpassen = eigene Kopie dort. --}}
@php($leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]')
<div class="flex flex-col gap-4" data-settings-standort-inhalte>
    @if($fehler)<x-fa::notice tone="crit" data-inhalte-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($meldung)<x-fa::notice tone="ok" data-inhalte-meldung>{{ $meldung }}</x-fa::notice>@endif

    <x-fa::section title="Standorte" icon="heroicon-o-building-office-2"
        description="Standard: ein Standort übernimmt alles vom Oberteam, auch Neues. Ohne Haken sieht er nur, was ihm freigegeben ist — samt allem, was daran hängt (Konzepte, Gerichte, Basisrezepte). Grundprodukte und Lieferantenartikel bleiben immer sichtbar. Eigene Rezepturen legt jeder Standort weiter selbst an.">
        @if($standorte === [])
            <x-fa::empty compact icon="heroicon-o-building-office-2" title="Keine Standorte">Dieses Team hat keine Unter-Teams.</x-fa::empty>
        @else
            <div class="flex flex-col gap-3">
                @foreach($standorte as $st)
                    <div class="rounded-[var(--fa-radius-card)] border border-[var(--fa-line)] p-3 flex flex-col gap-2" wire:key="sti-{{ $st['id'] }}" data-inhalte-standort="{{ $st['id'] }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold">{{ $st['name'] }}</span>
                            <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)]">
                                <input type="checkbox" class="w-4 h-4 rounded accent-[var(--fa-accent)]" data-inhalte-erbt="{{ $st['id'] }}"
                                    @checked($st['erbt_alles']) wire:change="erbtAllesSetzen({{ $st['id'] }}, $event.target.checked)" />
                                übernimmt alles vom Oberteam
                            </label>
                        </div>
                        @unless($st['erbt_alles'])
                            @php($fs = $freigaben->get($st['id'], collect()))
                            @if($fs->isEmpty())
                                <p class="{{ $leise }}">Noch nichts freigegeben — der Standort sieht nur seine eigenen Rezepturen.</p>
                            @else
                                <ul class="flex flex-wrap gap-2">
                                    @foreach($fs as $f)
                                        <li class="inline-flex items-center gap-1" wire:key="fr-{{ $f['id'] }}" data-inhalte-freigabe="{{ $f['id'] }}">
                                            <x-fa::badge>{{ $ausgabeLabels[$f['ausgabe_typ']] ?? $f['ausgabe_typ'] }} · {{ $f['ausgabe'] }}</x-fa::badge>
                                            <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="freigabeEntziehen({{ $f['id'] }})" aria-label="Freigabe entziehen" />
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <div class="flex flex-wrap items-end gap-2">
                                <x-fa::select class="w-72 max-w-full" wire:model="freigabeNeu.{{ $st['id'] }}" placeholder="Sammlung oder Ausgabe wählen" aria-label="Freigabe für {{ $st['name'] }}">
                                    @foreach($ausgaben as $gruppe => $optionen)
                                        @if($optionen !== [])
                                            <optgroup label="{{ $gruppe }}">
                                                @foreach($optionen as $wert => $text)<option value="{{ $wert }}">{{ $text }}</option>@endforeach
                                            </optgroup>
                                        @endif
                                    @endforeach
                                </x-fa::select>
                                <x-fa::button size="sm" variant="primary" wire:click="freigeben({{ $st['id'] }})" data-inhalte-freigeben="{{ $st['id'] }}">Freigeben</x-fa::button>
                            </div>
                        @endunless
                    </div>
                @endforeach
            </div>
        @endif
    </x-fa::section>

    <x-fa::section title="Sammlungen" icon="heroicon-o-rectangle-stack" :meta="count($sammlungen) > 0 ? count($sammlungen) . ' angelegt' : null"
        description="Benannte Listen aus Gerichten, Basisrezepten, Konzepten, Paketen und Formaten — ohne Layout, nur zum Freigeben. Ein Rezept darf in mehreren Sammlungen stehen.">
        <div class="flex flex-wrap items-end gap-2">
            <x-fa::field label="Neue Sammlung" for="sammlung-neu" class="w-72 max-w-full">
                <x-fa::input id="sammlung-neu" wire:model="neueSammlung" wire:keydown.enter="sammlungAnlegen" placeholder="z. B. Grundsortiment Mittag" data-sammlung-neu />
            </x-fa::field>
            <x-fa::button icon="heroicon-m-plus" wire:click="sammlungAnlegen">Sammlung anlegen</x-fa::button>
        </div>
        <div class="flex flex-col gap-2">
            @foreach($sammlungen as $s)
                <div class="rounded-[var(--fa-radius-card)] border border-[var(--fa-line)] p-3 flex flex-col gap-2" wire:key="sam-{{ $s['id'] }}" data-sammlung="{{ $s['id'] }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-semibold">{{ $s['name'] }} <span class="{{ $leise }} font-normal">{{ count($s['objekte']) }} Einträge</span></span>
                        <div class="flex gap-1">
                            <x-fa::button size="sm" wire:click="sammlungOeffnen({{ $s['id'] }})">{{ $sammlungOffen === $s['id'] ? 'Schließen' : 'Befüllen' }}</x-fa::button>
                            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-trash" wire:click="sammlungLoeschen({{ $s['id'] }})" wire:confirm="Sammlung löschen? Freigaben dieser Sammlung entfallen." aria-label="Sammlung löschen" />
                        </div>
                    </div>
                    @if($s['objekte'] !== [])
                        <ul class="flex flex-wrap gap-2">
                            @foreach($s['objekte'] as $o)
                                <li class="inline-flex items-center gap-1" wire:key="samo-{{ $s['id'] }}-{{ $o['typ'] }}-{{ $o['id'] }}">
                                    <x-fa::badge>{{ $typLabels[$o['typ']] ?? $o['typ'] }} · {{ $o['name'] }}</x-fa::badge>
                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="sammlungEntfernen({{ $s['id'] }}, '{{ $o['typ'] }}', {{ $o['id'] }})" aria-label="Aus Sammlung entfernen" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if($sammlungOffen === $s['id'])
                        {{-- Picker: Ebene → filtern → anhaken → hinzufügen --}}
                        <div class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] p-3" data-sammlung-picker>
                            <div class="flex flex-wrap gap-1" role="tablist" aria-label="Ebene">
                                @foreach($ebenen as $key => $label)
                                    <x-fa::button size="sm" :variant="$ebene === $key ? 'primary' : 'ghost'" wire:click="$set('ebene', '{{ $key }}')" data-picker-ebene="{{ $key }}">{{ $label }}</x-fa::button>
                                @endforeach
                            </div>
                            <div class="flex flex-wrap items-end gap-2" data-picker-filter>
                                <x-fa::input wire:model.live.debounce.300ms="suche" placeholder="{{ $ebenen[$ebene] }} suchen" class="w-64 max-w-full" data-sammlung-suche />
                                @if($kategorieOptionen !== [])
                                    <x-fa::select size="sm" class="w-48" wire:model.live="filterKategorie" :options="$kategorieOptionen" placeholder="Alle Kategorien" aria-label="Kategorie" data-picker-kategorie />
                                @endif
                                @if($statusOptionen !== [])
                                    <x-fa::select size="sm" class="w-40" wire:model.live="filterStatus" :options="$statusOptionen" placeholder="Jeder Status" aria-label="Status" data-picker-status />
                                @endif
                                <label class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)]">
                                    <input type="checkbox" class="w-4 h-4 rounded accent-[var(--fa-accent)]" wire:model.live="nurNeue" data-picker-nur-neue /> nur noch nicht enthaltene
                                </label>
                            </div>
                            @if($treffer === [])
                                <p class="{{ $leise }}">Keine {{ $ebenen[$ebene] }} gefunden.</p>
                            @else
                                <ul class="flex flex-col gap-1 max-h-80 overflow-y-auto">
                                    @foreach($treffer as $t)
                                        <li class="flex items-center gap-2" wire:key="tr-{{ $ebene }}-{{ $t['id'] }}">
                                            <input type="checkbox" class="w-4 h-4 rounded accent-[var(--fa-accent)]" wire:model.live="pickerAuswahl.{{ $t['id'] }}"
                                                @disabled($t['drin']) aria-label="{{ $t['name'] }} auswählen" data-picker-eintrag="{{ $t['id'] }}" />
                                            <span class="{{ $t['drin'] ? $leise : '' }}">{{ $t['name'] }}@if($t['drin']) · schon in der Sammlung @endif</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="flex items-center gap-2">
                                    <x-fa::button size="sm" variant="ghost" wire:click="alleAnhaken({{ json_encode(array_values(array_map(fn ($t) => $t['id'], array_filter($treffer, fn ($t) => ! $t['drin'])))) }})" data-picker-alle>Alle anhaken</x-fa::button>
                                    <x-fa::button size="sm" variant="primary" icon="heroicon-m-plus" wire:click="auswahlHinzu" data-picker-hinzu>{{ count(array_filter($pickerAuswahl)) }} hinzufügen</x-fa::button>
                                    <span class="{{ $leise }}">Höchstens 60 Einträge je Ansicht — mit dem Filter eingrenzen.</span>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-fa::section>
</div>
