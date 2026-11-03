{{-- Spec 94 · Konfigurator-Editor, Reiter Konzepte: Hauptkonzepte (Lunch-/Dinner-Concept) und Add-ons aus dem Concepter.
     Speisenpreise kommen aus dem Concept und werden nur angezeigt. --}}
@php
    $haupt = $k->konzepte->where('rolle', 'haupt')->values();
    $addons = $k->konzepte->where('rolle', 'addon')->values();
    $conceptName = fn ($c) => $c ? ($c->consumer_name ?: $c->name) : null;
    $preis = function ($c) use ($speisen, $euro) {
        $p = $speisen($c);
        return $p === null ? '–' : $euro($p['vk']) . ' · EK ' . $euro($p['ek']);
    };
@endphp

<x-fa::section title="Concept hinzufügen" icon="heroicon-o-plus-circle" description="Concepts werden verknüpft, nicht kopiert: der Speisenpreis läuft mit dem Concepter mit.">
    <div class="flex flex-wrap items-end gap-2">
        <x-fa::field label="Concept suchen" for="kf-csuche" class="w-80 max-w-full"><x-fa::input id="kf-csuche" type="search" wire:model.live.debounce.300ms="conceptSuche" placeholder="mind. 2 Zeichen" data-kf-concept-suche /></x-fa::field>
        <x-fa::choice name="conceptZiel" label="Als" :options="['haupt_dinner' => 'Hauptkonzept · Dinner', 'haupt_lunch' => 'Hauptkonzept · Lunch', 'addon' => 'Add-on']" />
    </div>
    @if($conceptTreffer->isNotEmpty())
        <ul class="flex flex-col divide-y divide-[var(--fa-line)]" data-kf-concept-treffer>
            @foreach($conceptTreffer as $c)
                <li wire:key="kfct-{{ $c->id }}" class="flex items-center justify-between gap-2 py-1.5">
                    <span>{{ $conceptName($c) }} <span class="{{ $leise }}">· {{ $euro($c->price_per_person_cache) }} je Person</span></span>
                    <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="conceptHinzu({{ $c->id }})" data-kf-concept-hinzu="{{ $c->id }}">Hinzufügen</x-fa::button>
                </li>
            @endforeach
        </ul>
    @endif
</x-fa::section>

<x-fa::section title="Hauptkonzepte" icon="heroicon-o-squares-2x2" :meta="$haupt->count()" data-kf-hauptkonzepte>
    @forelse($haupt as $kz)
        <div wire:key="kfk-{{ $kz->id }}" class="flex flex-col gap-2 py-2 border-b border-[var(--fa-line)] last:border-0" data-kf-konzept="{{ $kz->id }}">
            @if($konzeptEditId === $kz->id)
                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))] gap-3">
                    <x-fa::field label="Titel für den Kunden" for="kfk-titel"><x-fa::input id="kfk-titel" wire:model="konzeptForm.kunden_titel" /></x-fa::field>
                    <x-fa::field label="Claim" for="kfk-claim" optional><x-fa::input id="kfk-claim" wire:model="konzeptForm.claim" /></x-fa::field>
                    <x-fa::field label="Lunch-Concept (Id)" for="kfk-lunch" optional hint="leer = nur abends wählbar"><x-fa::input id="kfk-lunch" type="number" wire:model="konzeptForm.lunch_concept_id" /></x-fa::field>
                    <x-fa::field label="Dinner-Concept (Id)" for="kfk-dinner" optional hint="leer = nur mittags wählbar"><x-fa::input id="kfk-dinner" type="number" wire:model="konzeptForm.dinner_concept_id" /></x-fa::field>
                </div>
                <div class="flex flex-wrap items-center gap-4">
                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="konzeptForm.indikativ_lunch" class="accent-[var(--fa-accent)]" /> Lunch-Preis indikativ</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="konzeptForm.indikativ_dinner" class="accent-[var(--fa-accent)]" /> Dinner-Preis indikativ</label>
                    <span class="ml-auto flex gap-2">
                        <x-fa::button size="sm" variant="ghost" wire:click="konzeptBearbeitenAbbrechen">Abbrechen</x-fa::button>
                        <x-fa::button size="sm" variant="primary" wire:click="konzeptSpeichern">Übernehmen</x-fa::button>
                    </span>
                </div>
            @else
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="flex flex-col gap-0.5">
                        <span class="font-medium">{{ $kz->kunden_titel ?: 'Konzept ' . $kz->id }}@if($kz->claim)<span class="{{ $leise }}"> · {{ $kz->claim }}</span>@endif</span>
                        <span class="{{ $leise }}">Lunch: {{ $conceptName($kz->lunchConcept) ?? '—' }}@if($kz->lunchConcept) ({{ $preis($kz->lunchConcept) }})@endif{{ $kz->indikativ_lunch ? ' · indikativ' : '' }}</span>
                        <span class="{{ $leise }}">Dinner: {{ $conceptName($kz->dinnerConcept) ?? '—' }}@if($kz->dinnerConcept) ({{ $preis($kz->dinnerConcept) }})@endif{{ $kz->indikativ_dinner ? ' · indikativ' : '' }}</span>
                    </div>
                    <div class="flex items-center gap-1">
                        <x-fa::icon-button icon="heroicon-m-chevron-up" label="Nach oben" size="sm" wire:click="konzeptVerschieben({{ $kz->id }}, -1)" />
                        <x-fa::icon-button icon="heroicon-m-chevron-down" label="Nach unten" size="sm" wire:click="konzeptVerschieben({{ $kz->id }}, 1)" />
                        <x-fa::button size="sm" wire:click="konzeptBearbeiten({{ $kz->id }})">Bearbeiten</x-fa::button>
                        <x-fa::button size="sm" variant="danger" wire:click="konzeptEntfernen({{ $kz->id }})" wire:confirm="Konzept entfernen? Seine Zusatz-Leistungen gehen mit.">Entfernen</x-fa::button>
                    </div>
                </div>
                @include('foodalchemist::livewire.angebote.partials.konfigurator.wording', ['kz' => $kz])
            @endif
        </div>
    @empty
        <p class="{{ $leise }}">Noch kein Hauptkonzept. Oben ein Concept suchen und als Hauptkonzept hinzufügen.</p>
    @endforelse
</x-fa::section>

<x-fa::section title="Add-ons" icon="heroicon-o-plus" :meta="$addons->count()" data-kf-addons>
    @forelse($addons as $a)
        <div wire:key="kfa-{{ $a->id }}" class="flex flex-col gap-2 py-2 border-b border-[var(--fa-line)] last:border-0">
            @if($konzeptEditId === $a->id)
                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))] gap-3">
                    <x-fa::field label="Titel" for="kfa-titel"><x-fa::input id="kfa-titel" wire:model="konzeptForm.kunden_titel" /></x-fa::field>
                    <x-fa::field label="Abschnitt" for="kfa-abschnitt" hint="z. B. Frühstück, Pausen, Mitternachtssnack"><x-fa::input id="kfa-abschnitt" wire:model="konzeptForm.abschnitt" /></x-fa::field>
                    <x-fa::field label="Tageszeit" for="kfa-tz"><x-fa::select id="kfa-tz" wire:model="konzeptForm.addon_tageszeit" :options="['beide' => 'Lunch und Dinner', 'lunch' => 'Nur Lunch', 'dinner' => 'Nur Dinner']" /></x-fa::field>
                    <x-fa::field label="Nur bei Hauptkonzept" for="kfa-bind" optional>
                        <x-fa::select id="kfa-bind" wire:model="konzeptForm.gebunden_an_id" :options="$haupt->mapWithKeys(fn ($h) => [$h->id => $h->kunden_titel ?: 'Konzept ' . $h->id])->all()" placeholder="Bei allen" />
                    </x-fa::field>
                </div>
                <div class="flex justify-end gap-2">
                    <x-fa::button size="sm" variant="ghost" wire:click="konzeptBearbeitenAbbrechen">Abbrechen</x-fa::button>
                    <x-fa::button size="sm" variant="primary" wire:click="konzeptSpeichern">Übernehmen</x-fa::button>
                </div>
            @else
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span><span class="font-medium">{{ $a->kunden_titel ?: $conceptName($a->dinnerConcept) }}</span>
                        <span class="{{ $leise }}">· {{ $a->abschnitt ?: 'ohne Abschnitt' }} · {{ ['beide' => 'Lunch und Dinner', 'lunch' => 'Lunch', 'dinner' => 'Dinner'][$a->addon_tageszeit ?? 'beide'] ?? '' }}
                            @if($a->gebunden_an_id) · nur bei {{ optional($haupt->firstWhere('id', $a->gebunden_an_id))->kunden_titel }}@endif
                            · {{ $preis($a->dinnerConcept) }}</span></span>
                    <span class="flex gap-1">
                        <x-fa::button size="sm" wire:click="konzeptBearbeiten({{ $a->id }})">Bearbeiten</x-fa::button>
                        <x-fa::button size="sm" variant="danger" wire:click="konzeptEntfernen({{ $a->id }})" wire:confirm="Add-on entfernen?">Entfernen</x-fa::button>
                    </span>
                </div>
                @include('foodalchemist::livewire.angebote.partials.konfigurator.wording', ['kz' => $a])
            @endif
        </div>
    @empty
        <p class="{{ $leise }}">Keine Add-ons. Oben ein Concept suchen und „Add-on“ wählen.</p>
    @endforelse
</x-fa::section>
