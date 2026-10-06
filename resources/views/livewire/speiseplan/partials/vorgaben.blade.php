{{-- Spec 59: Vorgaben je Woche (Reiter Stammdaten). Erwartet: $sp (Plan), $mahlzeiten.
     Katalog aus der Komponente (`vorgabenKatalog`, Einstellungen › Speiseplan-Chips). Jede Änderung
     speichert sofort über SpeiseplanVorgabenService (gleiche Validierung wie MCP). Rechnet NICHTS:
     die Prüfung (ist/Status) steht in der Karte „Abwechslung der Woche“ der rechten Spalte.
     fa-pass: Section + Tabelle wie die Menü-Linien, Bausteine/Tokens. --}}
@php
    $katalog = $this->vorgabenKatalog;
    $aktiveChips = $katalog->where('is_active', true)->values();
    $vorgabenListe = array_values((array) ($sp->vorgaben ?? []));
    $chipsLink = route('foodalchemist.einstellungen', ['sektion' => 'speiseplan-chips']);
@endphp
<x-fa::section title="Vorgaben je Woche" icon="heroicon-o-check-badge" :meta="$vorgabenListe !== [] ? count($vorgabenListe) . ' Vorgaben' : null"
    description="Was dieser Plan je Woche erfüllen soll, zum Beispiel mind. 2× vegan und höchstens 1× Schwein. Gezählt wird je Gericht: ein Paket mit drei Gerichten zählt dreimal. Alle Mahlzeiten heißt: die Vorgabe gilt für jede Mahlzeit einzeln. Die Prüfung steht rechts in der Karte Abwechslung der Woche."
    data-sp-vorgaben>
    @if($katalog->isNotEmpty())
        <x-slot:actions>
            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-cog-6-tooth" :href="$chipsLink" target="_blank">Chips verwalten</x-fa::button>
        </x-slot:actions>
    @endif

    @if($vorgabenFehler)<x-fa::notice tone="crit" data-sp-vorgaben-fehler>{{ $vorgabenFehler }}</x-fa::notice>@endif

    @if($katalog->isEmpty())
        <x-fa::empty compact icon="heroicon-o-tag" title="Noch keine Prüf-Chips" data-sp-vorgaben-katalog-leer>
            Vorgaben brauchen Chips wie Vegan, Schwein oder Suppe. Angelegt werden sie einmal für das Team.
            <x-slot:action>
                <x-fa::button size="sm" icon="heroicon-o-cog-6-tooth" :href="$chipsLink" target="_blank">Speiseplan-Chips anlegen</x-fa::button>
            </x-slot:action>
        </x-fa::empty>
    @else
        @if($vorgabenListe !== [])
            <div class="overflow-x-auto -mx-4">
                <table class="fa-table fa-table--compact">
                    <thead><tr>
                        <th class="pl-4">Chip</th><th>Mahlzeit</th><th class="num">mind.</th><th class="num">höchstens</th><th class="pr-4 w-full"><span class="sr-only">Aktionen</span></th>
                    </tr></thead>
                    <tbody>
                        @foreach($vorgabenListe as $i => $v)
                            @php($chipId = (int) ($v['chip_id'] ?? 0))
                            <tr wire:key="vorgabe-{{ $i }}-{{ $chipId }}-{{ $v['mahlzeit'] ?? 'alle' }}" data-sp-vorgabe="{{ $i }}">
                                <td class="pl-4">
                                    <x-fa::select size="sm" wire:change="vorgabeSetzen({{ $i }}, 'chip_id', $event.target.value)" class="w-48" aria-label="Chip">
                                        @foreach($katalog as $c)
                                            @if($c->is_active || (int) $c->id === $chipId)
                                                <option value="{{ $c->id }}" @selected((int) $c->id === $chipId)>{{ $c->label }}{{ $c->is_active ? '' : ' (stillgelegt)' }}</option>
                                            @endif
                                        @endforeach
                                    </x-fa::select>
                                </td>
                                <td>
                                    <x-fa::select size="sm" wire:change="vorgabeSetzen({{ $i }}, 'mahlzeit', $event.target.value)" class="w-44" aria-label="Mahlzeit">
                                        <option value="" @selected(($v['mahlzeit'] ?? null) === null)>Alle Mahlzeiten</option>
                                        @foreach($mahlzeiten as $mk => $ml)
                                            <option value="{{ $mk }}" @selected(($v['mahlzeit'] ?? null) === $mk)>{{ $ml }}</option>
                                        @endforeach
                                    </x-fa::select>
                                </td>
                                <td class="num">
                                    <x-fa::input size="sm" numeric type="number" min="0" value="{{ $v['min'] ?? '' }}" wire:change="vorgabeSetzen({{ $i }}, 'min', $event.target.value)"
                                        aria-label="mindestens" class="w-20" placeholder="–" data-sp-vorgabe-min />
                                </td>
                                <td class="num">
                                    <x-fa::input size="sm" numeric type="number" min="0" value="{{ $v['max'] ?? '' }}" wire:change="vorgabeSetzen({{ $i }}, 'max', $event.target.value)"
                                        aria-label="höchstens" class="w-20" placeholder="–" data-sp-vorgabe-max />
                                </td>
                                <td class="pr-4 text-right">
                                    <x-fa::icon-button icon="heroicon-m-trash" size="sm" tone="danger" label="Vorgabe entfernen" wire:click="vorgabeEntfernen({{ $i }})" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div class="flex flex-wrap items-end gap-2 {{ $vorgabenListe !== [] ? 'pt-3 border-t border-[var(--fa-line)]' : '' }}">
            <x-fa::field label="Neue Vorgabe" for="sp-vorgabe-neu" class="w-64 max-w-full">
                <x-fa::select id="sp-vorgabe-neu" wire:model="neueVorgabeChip" placeholder="Chip wählen" data-sp-vorgabe-neu-chip>
                    @foreach($aktiveChips as $c)
                        <option value="{{ $c->id }}">{{ $c->label }}{{ $c->default_min !== null ? ', mind. ' . $c->default_min : '' }}{{ $c->default_max !== null ? ', höchstens ' . $c->default_max : '' }}</option>
                    @endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::button icon="heroicon-m-plus" wire:click="vorgabeHinzu" data-sp-vorgabe-hinzu>Vorgabe hinzufügen</x-fa::button>
        </div>
    @endif
</x-fa::section>
