{{-- Spec 94c · Konfigurator-Editor, Reiter Getränke: Getränkepakete aus dem Concepter (Kategorie „Getränke“). Im Concept steht der Inhalt
     (Positionen, Mengen je Gast, Wording, Bilder), hier das Kommerzielle: Pauschale je Event oder je Stunde, optional Staffel
     (Grundpreis für X Stunden + Satz je Folgestunde), je Zeitfenster ein eigenes Concept (Lunch tagsüber, Dinner abends; Tag und Abend =
     beide Teile), Bindung, Pflicht. --}}
@php
    $haupt = $k->konzepte->where('rolle', 'haupt')->values();
    $getraenke = $k->konzepte->where('rolle', 'getraenk')->values();
    $conceptName = fn ($c) => $c ? ($c->consumer_name ?: $c->name) : null;
    $dauer = fn ($g) => $g->folgestunde_satz !== null
        ? 'Grundpreis für ' . rtrim(rtrim(number_format((float) $g->grund_stunden, 2, ',', ''), '0'), ',') . ' Std., je weitere Stunde ' . $euro($g->folgestunde_satz)
        : ($g->getraenk_dauer === 'stunde' ? 'je Gast und Stunde' : 'je Gast fürs ganze Event');
    $vorschau = fn ($g) => collect($matrix['pakete'])->last()['faelle'] ?? [];
@endphp

<x-fa::section title="Getränkepaket hinzufügen" icon="heroicon-o-plus-circle" description="Concepts der Kategorie „Getränke“ (ohne diese Kategorie: Suche über alle Concepts). Der Preis läuft mit dem Concepter mit.">
    <div class="flex flex-wrap items-end gap-3">
        <x-fa::field label="Concept suchen" for="kf-gsuche" class="w-80 max-w-full"><x-fa::input id="kf-gsuche" type="search" wire:model.live.debounce.300ms="getraenkSuche" placeholder="z. B. Softdrinks, Wein" data-kf-getraenk-suche /></x-fa::field>
        <x-fa::choice name="getraenkZiel" label="Für" :options="['dinner' => 'Abend (Dinner)', 'lunch' => 'Tag (Lunch)']" />
        <label class="inline-flex items-center gap-2 pb-2"><input type="checkbox" wire:model="form.getraenke_pflicht" class="accent-[var(--fa-accent)]" data-kf-getraenke-pflicht /> Getränke Pflicht im Link (sonst „ohne Getränke“ möglich, wirkt nach „Speichern“)</label>
    </div>
    @if($getraenkTreffer->isNotEmpty())
        <ul class="flex flex-col divide-y divide-[var(--fa-line)]" data-kf-getraenk-treffer>
            @foreach($getraenkTreffer as $c)
                <li wire:key="kfgt-{{ $c->id }}" class="flex items-center justify-between gap-2 py-1.5">
                    <span>{{ $conceptName($c) }} <span class="{{ $leise }}">· {{ $euro($c->price_per_person_cache) }} je Person</span></span>
                    <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="getraenkHinzu({{ $c->id }})" data-kf-getraenk-hinzu="{{ $c->id }}">Als Getränkepaket</x-fa::button>
                </li>
            @endforeach
        </ul>
    @endif
</x-fa::section>

<x-fa::section title="Getränkepakete" icon="heroicon-o-beaker" :meta="$getraenke->count()" data-kf-getraenke>
    @forelse($getraenke as $g)
        <div wire:key="kfg-{{ $g->id }}" class="flex flex-col gap-2 py-2 border-b border-[var(--fa-line)] last:border-0" data-kf-getraenk="{{ $g->id }}">
            @if($konzeptEditId === $g->id)
                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))] gap-3">
                    <x-fa::field label="Titel für den Kunden" for="kfg-titel"><x-fa::input id="kfg-titel" wire:model="konzeptForm.kunden_titel" /></x-fa::field>
                    <x-fa::field label="Claim" for="kfg-claim" optional><x-fa::input id="kfg-claim" wire:model="konzeptForm.claim" /></x-fa::field>
                    <x-fa::field label="Pauschale" for="kfg-dauer" hint="je Stunde: Einsatzzeit aus der Kalkulation">
                        <x-fa::select id="kfg-dauer" wire:model="konzeptForm.getraenk_dauer" :options="['event' => 'Je Gast fürs ganze Event', 'stunde' => 'Je Gast und Stunde']" data-kfg-dauer />
                    </x-fa::field>
                    <x-fa::field label="Staffel: Grundstunden" for="kfg-grund" optional hint="Concept-Preis gilt für so viele Stunden"><x-fa::input id="kfg-grund" wire:model="konzeptForm.grund_stunden" numeric data-kfg-grund /></x-fa::field>
                    <x-fa::field label="Staffel: je Folgestunde (€)" for="kfg-folge" optional hint="VK je Gast und weitere Stunde"><x-fa::input id="kfg-folge" wire:model="konzeptForm.folgestunde_satz" numeric data-kfg-folge /></x-fa::field>
                    <x-fa::field label="Tag: Lunch-Concept (Id)" for="kfg-lunch" optional hint="leer = tagsüber nicht wählbar"><x-fa::input id="kfg-lunch" type="number" wire:model="konzeptForm.lunch_concept_id" /></x-fa::field>
                    <x-fa::field label="Abend: Dinner-Concept (Id)" for="kfg-dinner" optional hint="leer = abends nicht wählbar"><x-fa::input id="kfg-dinner" type="number" wire:model="konzeptForm.dinner_concept_id" /></x-fa::field>
                    <x-fa::field label="Nur bei Hauptkonzept" for="kfg-bind" optional>
                        <x-fa::select id="kfg-bind" wire:model="konzeptForm.gebunden_an_id" :options="$haupt->mapWithKeys(fn ($h) => [$h->id => $h->kunden_titel ?: 'Konzept ' . $h->id])->all()" placeholder="Bei allen" />
                    </x-fa::field>
                </div>
                <p class="{{ $leise }}">Staffel: Grundstunden und Folgestunden-Satz zusammen setzen (z. B. 4 Std. zum Concept-Preis, jede weitere 3,50 €), sonst gilt die Pauschale oben.</p>
                <div class="flex justify-end gap-2">
                    <x-fa::button size="sm" variant="ghost" wire:click="konzeptBearbeitenAbbrechen">Abbrechen</x-fa::button>
                    <x-fa::button size="sm" variant="primary" wire:click="konzeptSpeichern" data-kfg-speichern>Übernehmen</x-fa::button>
                </div>
            @else
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="flex flex-col gap-0.5">
                        <span class="font-medium">{{ $g->kunden_titel ?: $conceptName($g->dinnerConcept ?? $g->lunchConcept) }}@if($g->claim)<span class="{{ $leise }}"> · {{ $g->claim }}</span>@endif</span>
                        <span class="{{ $leise }}">Pauschale {{ $dauer($g) }}@if($g->gebunden_an_id) · nur bei {{ optional($haupt->firstWhere('id', $g->gebunden_an_id))->kunden_titel }}@endif</span>
                        <span class="{{ $leise }}">Tag: {{ $conceptName($g->lunchConcept) ?? '—' }} · Abend: {{ $conceptName($g->dinnerConcept) ?? '—' }}</span>
                        <span class="{{ $leise }}" data-kfg-preise="{{ $g->id }}">
                            @foreach($vorschau($g) as $f)
                                @if($f['fall']['typ'] === 'getraenk' && $f['fall']['g'] === $g->id){{ ['lunch' => 'Lunch', 'dinner' => 'Dinner', 'day_night' => 'Tag und Abend'][$f['fall']['t']] }} {{ $euro($f['preis_pp']) }} (DB {{ $euro($f['db_pp']) }})@if(! $loop->last) · @endif @endif
                            @endforeach
                        </span>
                    </div>
                    <div class="flex items-center gap-1">
                        <x-fa::button size="sm" wire:click="konzeptBearbeiten({{ $g->id }})" data-kfg-bearbeiten="{{ $g->id }}">Bearbeiten</x-fa::button>
                        <x-fa::button size="sm" variant="danger" wire:click="konzeptEntfernen({{ $g->id }})" wire:confirm="Getränkepaket entfernen? Seine Zusatz-Leistungen gehen mit.">Entfernen</x-fa::button>
                    </div>
                </div>
                @include('foodalchemist::livewire.angebote.partials.konfigurator.wording', ['kz' => $g])
            @endif
        </div>
    @empty
        <p class="{{ $leise }}">Noch kein Getränkepaket. Oben ein Concept suchen und als Getränkepaket hinzufügen. Leistungen zum Getränk (Barkeeper, Gläser) im Reiter Leistungen an das Paket hängen.</p>
    @endforelse
</x-fa::section>
