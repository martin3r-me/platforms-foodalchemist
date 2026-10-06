{{-- Spec 59: Vorgaben je Woche (Reiter Stammdaten). Erwartet: $sp (Plan), $mahlzeiten.
     Katalog aus der Komponente (`vorgabenKatalog`, Einstellungen › Speiseplan-Chips). Jede Änderung
     speichert sofort über SpeiseplanVorgabenService (gleiche Validierung wie MCP). Rechnet NICHTS —
     die Prüfung (ist/Status) steht in der Kennzahlen-Karte „Abwechslung · Woche“. --}}
@php($katalog = $this->vorgabenKatalog)
@php($aktiveChips = $katalog->where('is_active', true)->values())
@php($vorgabenListe = array_values((array) ($sp->vorgaben ?? [])))
<x-foodalchemist::modal-section title="Vorgaben je Woche">
    <div data-sp-vorgaben>
        <p class="text-[11px] text-gray-400 mb-2">
            Was dieser Plan je Woche erfüllen soll, z. B. „mind. 2× Vegan, höchstens 1× Schwein“. Gezählt wird je Gericht
            (ein Paket mit drei Gerichten zählt drei). „Alle Mahlzeiten“ gilt für jede Mahlzeit einzeln. Die Prüfung steht
            rechts in der Karte „Abwechslung · Woche“.
        </p>
        @if($vorgabenFehler)<div class="mb-2 rounded-lg bg-rose-500/15 border border-rose-500/30 text-rose-200 text-xs px-3 py-1.5" data-sp-vorgaben-fehler>{{ $vorgabenFehler }}</div>@endif

        @if($katalog->isEmpty())
            <p class="text-[11px] text-amber-300" data-sp-vorgaben-katalog-leer>
                Noch keine Prüf-Chips angelegt —
                <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'speiseplan-chips']) }}" class="underline" target="_blank">Einstellungen › Speiseplan-Chips</a>.
            </p>
        @else
            @if($vorgabenListe !== [])
                <div class="space-y-1.5 mb-2">
                    @foreach($vorgabenListe as $i => $v)
                        @php($chipId = (int) ($v['chip_id'] ?? 0))
                        <div class="flex flex-wrap items-end gap-2" wire:key="vorgabe-{{ $i }}-{{ $chipId }}-{{ $v['mahlzeit'] ?? 'alle' }}" data-sp-vorgabe="{{ $i }}">
                            <label class="block">
                                <span class="{{ $label }}">Chip</span>
                                <select wire:change="vorgabeSetzen({{ $i }}, 'chip_id', $event.target.value)" class="{{ $input }} h-8 w-44" aria-label="Chip">
                                    @foreach($katalog as $c)
                                        @if($c->is_active || (int) $c->id === $chipId)
                                            <option value="{{ $c->id }}" @selected((int) $c->id === $chipId)>{{ $c->label }}{{ $c->is_active ? '' : ' (stillgelegt)' }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="{{ $label }}">Mahlzeit</span>
                                <select wire:change="vorgabeSetzen({{ $i }}, 'mahlzeit', $event.target.value)" class="{{ $input }} h-8 w-40" aria-label="Mahlzeit">
                                    <option value="" @selected(($v['mahlzeit'] ?? null) === null)>alle Mahlzeiten</option>
                                    @foreach($mahlzeiten as $mk => $ml)
                                        <option value="{{ $mk }}" @selected(($v['mahlzeit'] ?? null) === $mk)>{{ $ml }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="{{ $label }}">mind.</span>
                                <input type="number" min="0" value="{{ $v['min'] ?? '' }}" wire:change="vorgabeSetzen({{ $i }}, 'min', $event.target.value)"
                                       class="{{ $input }} h-8 w-20 text-right tabular-nums" placeholder="—" data-sp-vorgabe-min />
                            </label>
                            <label class="block">
                                <span class="{{ $label }}">höchstens</span>
                                <input type="number" min="0" value="{{ $v['max'] ?? '' }}" wire:change="vorgabeSetzen({{ $i }}, 'max', $event.target.value)"
                                       class="{{ $input }} h-8 w-20 text-right tabular-nums" placeholder="—" data-sp-vorgabe-max />
                            </label>
                            <button type="button" wire:click="vorgabeEntfernen({{ $i }})" class="{{ $btnGhostXs }} h-8" aria-label="Vorgabe entfernen">Entfernen</button>
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="flex flex-wrap items-end gap-2">
                <select wire:model="neueVorgabeChip" class="{{ $input }} h-8 w-56" aria-label="Chip für neue Vorgabe" data-sp-vorgabe-neu-chip>
                    <option value="">— Chip wählen —</option>
                    @foreach($aktiveChips as $c)
                        <option value="{{ $c->id }}">{{ $c->label }}{{ $c->default_min !== null ? ' · mind. ' . $c->default_min : '' }}{{ $c->default_max !== null ? ' · höchstens ' . $c->default_max : '' }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="vorgabeHinzu" class="{{ $btnGhost }} h-8" data-sp-vorgabe-hinzu>+ Vorgabe hinzufügen</button>
                <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'speiseplan-chips']) }}" target="_blank" class="text-[11px] text-gray-400 underline">Chips verwalten</a>
            </div>
        @endif
    </div>
</x-foodalchemist::modal-section>
