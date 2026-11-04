{{-- Spec 94 · Konfigurator-Editor, Reiter Leistungen: Grundausstattung und Zusatz-Leistungen je Konzept, einfügen aus
     Vorlagen (Kopie) oder frei. Formular-Felder aus partials/leistung-regel (wie bei den Vorlagen). --}}
@php
    $konzeptOptionen = $k->konzepte->mapWithKeys(fn ($x) => [$x->id => ($x->rolle === 'addon' ? 'Add-on: ' : '') . ($x->kunden_titel ?: 'Konzept ' . $x->id)])->all();
    $gruppen = $k->leistungen->groupBy(fn ($l) => $l->konfigurator_konzept_id ?? 0);
    $grund = $k->leistungen->whereNull('konfigurator_konzept_id');
    $lf = $leistungForm;
@endphp

<x-fa::section title="Aus Vorlagen übernehmen" icon="heroicon-o-document-duplicate" description="Vorlagen werden kopiert. Ändert sich eine Vorlage später, zeigt die Zeile „Vorlage geändert“.">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,16rem),1fr))] gap-x-4 gap-y-1 max-h-64 overflow-auto" data-kf-vorlagen>
        @forelse($vorlagen as $v)
            <label wire:key="kfv-{{ $v->id }}" class="inline-flex items-start gap-2 py-0.5">
                <input type="checkbox" value="{{ $v->id }}" wire:model="vorlageAuswahl" class="mt-1 accent-[var(--fa-accent)]" data-kf-vorlage="{{ $v->id }}" />
                <span>{{ $v->bezeichnung }} <span class="{{ $leise }}">· {{ $kategorieNamen[$v->kategorie] ?? $v->kategorie }} · EK {{ $euro($v->ek_satz) }}</span></span>
            </label>
        @empty
            <p class="{{ $leise }}">Noch keine Vorlagen. Unter Konfiguratoren → Leistungs-Vorlagen anlegen.</p>
        @endforelse
    </div>
    <div class="flex flex-wrap items-end gap-2">
        <x-fa::field label="Gilt für" for="kf-vziel"><x-fa::select id="kf-vziel" wire:model="vorlageZielKonzept" :options="$konzeptOptionen" placeholder="Grundausstattung (alle)" /></x-fa::field>
        <x-fa::field label="Tageszeit" for="kf-vgilt" optional><x-fa::select id="kf-vgilt" wire:model="vorlageGiltFuer" :options="['lunch' => 'Nur Lunch', 'dinner' => 'Nur Dinner', 'beide' => 'Beide']" placeholder="Beide" /></x-fa::field>
        <x-fa::button variant="primary" icon="heroicon-m-arrow-down-tray" wire:click="vorlagenUebernehmen" data-kf-vorlagen-uebernehmen>Ausgewählte übernehmen</x-fa::button>
        <x-fa::button variant="ghost" icon="heroicon-m-plus" wire:click="leistungNeu" data-kf-leistung-neu>Freie Leistung</x-fa::button>
        @if(count($geaendert) > 0)
            <x-fa::button variant="ghost" icon="heroicon-m-arrow-path" wire:click="alleVorlagenAbgleichen" wire:confirm="Alle {{ count($geaendert) }} geänderten Vorlagen übernehmen?">{{ count($geaendert) }} Vorlage(n) geändert, alle übernehmen</x-fa::button>
        @endif
    </div>
</x-fa::section>

@if($leistungEditId !== null)
    <x-fa::section :title="$leistungEditId === 0 ? 'Neue Leistung' : 'Leistung bearbeiten'" icon="heroicon-o-pencil-square" data-kf-leistung-formular>
        <div class="flex flex-col gap-4">
            @include('foodalchemist::livewire.angebote.partials.leistung-regel', ['f' => $lf, 'm' => 'leistungForm', 'sm' => 'leistungStufen', 'pm' => 'leistungPlanung', 'px' => 'kfl',
                'bezugOptionen' => $k->leistungen->reject(fn ($l) => $l->id === $leistungEditId)->mapWithKeys(fn ($l) => [$l->id => $l->bezeichnung])->all(), 'vorschau' => $vkVorschau])
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))] gap-3">
                <x-fa::field label="Gilt für" for="kfl-konzept"><x-fa::select id="kfl-konzept" wire:model.live="leistungForm.konfigurator_konzept_id" :options="$konzeptOptionen" placeholder="Grundausstattung (alle)" /></x-fa::field>
                @if(($lf['konfigurator_konzept_id'] ?? '') !== '' && optional($k->konzepte->firstWhere('id', (int) $lf['konfigurator_konzept_id']))->rolle === 'haupt')
                    <x-fa::field label="Tageszeit" for="kfl-gilt"><x-fa::select id="kfl-gilt" wire:model="leistungForm.gilt_fuer" :options="['beide' => 'Beide', 'lunch' => 'Nur Lunch', 'dinner' => 'Nur Dinner']" placeholder="Beide" /></x-fa::field>
                    <x-fa::field label="Ersetzt aus der Grundausstattung" for="kfl-ersetzt" optional hint="z. B. Tischservice statt Buffetservice">
                        <x-fa::select id="kfl-ersetzt" wire:model="leistungForm.ersetzt_leistung_id" :options="$grund->mapWithKeys(fn ($l) => [$l->id => $l->bezeichnung])->all()" placeholder="Nichts" />
                    </x-fa::field>
                @endif
            </div>
            <div class="flex justify-end gap-2">
                <x-fa::button variant="ghost" wire:click="leistungAbbrechen">Abbrechen</x-fa::button>
                <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="leistungSpeichern" data-kf-leistung-speichern>Übernehmen</x-fa::button>
            </div>
        </div>
    </x-fa::section>
@endif

@foreach([0 => null] + $k->konzepte->keyBy('id')->all() as $kid => $konzept)
    @php $zeilen = $gruppen->get($kid, collect()); @endphp
    @if($kid === 0 || $zeilen->isNotEmpty())
        <x-fa::section :title="$kid === 0 ? 'Grundausstattung' : 'Zusatz: ' . ($konzeptOptionen[$kid] ?? $kid)" icon="heroicon-o-wrench-screwdriver" :meta="$zeilen->count()" wire:key="kflg-{{ $kid }}">
            @if($zeilen->isEmpty())
                <p class="{{ $leise }}">Noch keine Leistungen. Oben aus Vorlagen übernehmen oder frei anlegen.</p>
            @else
                <table class="fa-table fa-table--compact">
                    <thead><tr><th>Leistung</th><th>Menge</th><th>Preis je</th><th class="num">EK</th><th class="num">VK</th><th></th></tr></thead>
                    <tbody>
                        @foreach($zeilen as $l)
                            <tr wire:key="kfl-{{ $l->id }}" data-kf-leistung="{{ $l->id }}">
                                <td>
                                    <span class="font-medium">{{ $l->bezeichnung }}</span>
                                    <span class="{{ $leise }}">· {{ $kategorieNamen[$l->kategorie] ?? $l->kategorie }}</span>
                                    @if($l->gilt_fuer && $l->gilt_fuer !== 'beide')<x-fa::badge class="ml-1">{{ $l->gilt_fuer === 'lunch' ? 'Lunch' : 'Dinner' }}</x-fa::badge>@endif
                                    @if($l->ersetzt_leistung_id)<div class="{{ $leise }}">ersetzt {{ optional($grund->firstWhere('id', $l->ersetzt_leistung_id))->bezeichnung }}</div>@endif
                                    @if(in_array($l->id, $geaendert, true))
                                        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-path" wire:click="vorlageAbgleich({{ $l->id }})">Vorlage geändert, übernehmen</x-fa::button>
                                    @endif
                                </td>
                                <td>{{ \Platform\FoodAlchemist\Livewire\Angebote\LeistungVorlagen::regelText($l) }}</td>
                                <td>{{ $l->preis_je === 'stunde' ? 'Stunde' : 'Stück' }}{{ $l->haeufigkeit === 'je_tageszeit' ? ' · je Tageszeit' : '' }}</td>
                                <td class="num">{{ $euro($l->ek_satz) }}</td>
                                <td class="num">{{ $euro($l->vk_satz) }}</td>
                                <td class="text-right whitespace-nowrap">
                                    <x-fa::button size="sm" wire:click="leistungBearbeiten({{ $l->id }})">Bearbeiten</x-fa::button>
                                    <x-fa::button size="sm" variant="danger" wire:click="leistungEntfernen({{ $l->id }})" wire:confirm="Leistung „{{ $l->bezeichnung }}“ entfernen?">Entfernen</x-fa::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-fa::section>
    @endif
@endforeach
