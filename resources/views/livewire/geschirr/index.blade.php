{{-- #388 Geschirr-Datenbank — Browser (Leih-Lieferant links, Geschirr-Artikel Mitte). Vorbild: Lieferanten-Browser.
     fa-pass (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Funktion, wire:-Bindungen und data-Marker unverändert.
     Neu: Seitenkopf mit Hauptaktion „Neuer Artikel" rechts, Lieferanten-Pflege (Bearbeiten/Deaktivieren) im Menü statt
     neben Speichern-artigen Knöpfen, fehlender Leihpreis als Signal, Leerzustände mit Weg zum Füllen. --}}
@php
    $mitLieferant = ! $globaleSuche && $aktiverLieferant !== null;
    $trefferZahl = $artikel ? number_format($artikel->total(), 0, ',', '.') : '0';
    $kopfTitel = $globaleSuche ? 'Suche „' . trim($q) . '“' : ($aktiverLieferant?->name ?? 'Geschirr');
    $kopfZeile = $globaleSuche
        ? $trefferZahl . ' Treffer bei allen Leih-Lieferanten'
        : ($aktiverLieferant !== null ? $trefferZahl . ($onlyActive ? ' aktive Artikel' : ' Artikel') . ($aktiverLieferant->is_inactive ? ' · Lieferant inaktiv' : '') : null);
    // Lieferant hat Geschirr, aber alles ist ausgeblendet (nur inaktive Artikel) → Leerzustand sagt das
    $nurInaktiveVersteckt = $onlyActive && ($aktiverLieferant?->item_count ?? 0) > 0;
    $spaltenZahl = $globaleSuche ? 9 : 8;
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Geschirr" icon="heroicon-o-square-2-stack" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Geschirr'],
        ]" />
    </x-slot>

    {{-- Zone links: Suche über alles, dann Leih-Lieferanten mit Artikel-Zähler --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Leih-Lieferanten" width="w-80" storeKey="faGeschirrOpen">
            <div class="p-3 flex flex-col gap-3" data-geschirr-liste>
                <div class="relative">
                    <label for="geschirr-suche" class="sr-only">Geschirr bei allen Lieferanten suchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="geschirr-suche" type="search" wire:model.live.debounce.300ms="q"
                        placeholder="Geschirr bei allen Lieferanten" class="pl-8" data-global-suche />
                </div>

                <div class="flex flex-col gap-2 pt-3 border-t border-[var(--fa-line)]">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Lieferanten</span>
                        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-plus"
                            @click="$dispatch('modal.open', { name: 'g-lieferant-neu' })" data-neuer-lieferant-btn>Lieferant anlegen</x-fa::button>
                    </div>
                    <label for="geschirr-lieferant-filter" class="sr-only">Lieferanten filtern</label>
                    <x-fa::input id="geschirr-lieferant-filter" type="search" size="sm" wire:model.live.debounce.300ms="supplierSuche"
                        placeholder="Lieferant filtern" />
                    <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] cursor-pointer">
                        <input type="checkbox" wire:model.live="includeInactive"
                               class="rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" /> Inaktive Lieferanten zeigen
                    </label>
                </div>

                <div class="flex flex-col gap-0.5">
                    @forelse($lieferanten as $l)
                        <x-foodalchemist::filter-row wire:key="gsup-{{ $l->id }}" wire:click="waehleLieferant({{ $l->id }})"
                            :active="! $globaleSuche && $supplierId === $l->id" :count="$l->item_count" title="{{ $l->name }}">
                            <span class="inline-flex items-center gap-1.5 min-w-0">
                                <span class="truncate {{ $l->is_inactive ? 'text-[var(--fa-ink-3)] line-through' : '' }}">{{ $l->name }}</span>
                                @if($l->is_inactive)<x-fa::badge>inaktiv</x-fa::badge>@endif
                            </span>
                        </x-foodalchemist::filter-row>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-truck" title="Noch kein Leih-Lieferant">
                            Lege über „Lieferant anlegen“ den ersten Geschirrverleih an.
                        </x-fa::empty>
                    @endforelse
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header :title="$kopfTitel" :subtitle="$kopfZeile">
            <x-slot:actions>
                @if($mitLieferant)
                    @if($darfLieferantEdit)
                        {{-- Lieferanten-Pflege im Menü: Deaktivieren steht nie direkt neben der Hauptaktion --}}
                        <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                            <x-fa::button iconRight="heroicon-m-chevron-down" x-on:click="toggle($event)"
                                aria-haspopup="menu" x-bind:aria-expanded="offen">Lieferant verwalten</x-fa::button>
                            <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-56 fa-surface shadow-lg py-1">
                                <button type="button" role="menuitem" x-on:click="offen = false" wire:click="lieferantBearbeiten"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]" data-lieferant-edit-btn>
                                    @svg('heroicon-o-pencil-square', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') Lieferant bearbeiten
                                </button>
                                <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                <button type="button" role="menuitem" x-on:click="offen = false"
                                        wire:click="lieferantDeaktivieren({{ $aktiverLieferant->is_inactive ? 'false' : 'true' }})"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] hover:bg-[var(--fa-hover)] {{ $aktiverLieferant->is_inactive ? 'text-[var(--fa-ink)]' : 'text-[var(--fa-crit)]' }}">
                                    @if($aktiverLieferant->is_inactive)
                                        @svg('heroicon-o-arrow-uturn-left', 'w-4 h-4 shrink-0') Lieferant aktivieren
                                    @else
                                        @svg('heroicon-o-no-symbol', 'w-4 h-4 shrink-0') Lieferant deaktivieren
                                    @endif
                                </button>
                            </div>
                        </div>
                    @endif
                    <x-fa::button icon="heroicon-o-printer" target="_blank"
                        :href="route('foodalchemist.geschirr.dokument', ['id' => $aktiverLieferant->id, 'profil' => 'kalkulation'])"
                        title="Druck- und PDF-Liste des Lieferanten mit allen Artikeln und Leihpreisen" data-geschirr-druck>Liste drucken</x-fa::button>
                    <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="artikelNeu" data-neuer-artikel-btn>Neuer Artikel</x-fa::button>
                @endif
            </x-slot:actions>
        </x-fa::page-header>

        @if($fehler)
            <x-fa::notice tone="crit" data-geschirr-fehler>{{ $fehler }}</x-fa::notice>
        @endif

        @if($artikel !== null)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    @if($mitLieferant)
                        <div class="relative w-64">
                            <label for="geschirr-lokal" class="sr-only">Geschirr dieses Lieferanten suchen</label>
                            @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                            <x-fa::input id="geschirr-lokal" type="search" size="sm" wire:model.live.debounce.300ms="artikelSuche"
                                placeholder="In diesem Sortiment suchen" class="pl-8" data-lokale-suche />
                        </div>
                    @endif
                    <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] cursor-pointer">
                        <input type="checkbox" wire:model.live="onlyActive"
                               class="rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" /> Nur aktive Artikel
                    </label>
                </div>
                <x-fa::select wire:model.live="perPage" size="sm" aria-label="Einträge je Seite" class="w-auto" data-per-page>
                    @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }} je Seite</option>@endforeach
                </x-fa::select>
            </div>
        @endif

        {{-- Geschirr-Tabelle --}}
        <div class="fa-surface overflow-hidden" data-geschirr-tabelle>
            @if($artikel === null)
                <x-fa::empty icon="heroicon-o-square-2-stack" title="Noch kein Geschirr erfasst">
                    Lege zuerst einen Leih-Lieferanten an. Danach erfasst du dessen Teller, Gläser und Besteck mit Leihpreis und Pfand.
                    <x-slot:action>
                        <x-fa::button variant="primary" icon="heroicon-m-plus" @click="$dispatch('modal.open', { name: 'g-lieferant-neu' })">Lieferant anlegen</x-fa::button>
                    </x-slot:action>
                </x-fa::empty>
            @else
                <div class="max-h-[70vh] overflow-auto">
                    <table class="fa-table">
                        <thead class="sticky top-0 z-20 bg-[var(--fa-surface)]">
                            <tr>
                                @if($globaleSuche)<th>Lieferant</th>@endif
                                <th class="w-full">Bezeichnung</th>
                                <th>Art.-Nr.</th>
                                <th>Kategorie</th>
                                <th>Material</th>
                                <th>Maße</th>
                                <th class="num">Leihpreis</th>
                                <th class="num">Pfand</th>
                                <th><span class="sr-only">Aktion</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($artikel as $item)
                                <x-foodalchemist::table-row wire:key="gitem-{{ $item->id }}" wire:click="artikelOeffnen({{ $item->id }})"
                                    class="{{ $item->is_inactive ? 'text-[var(--fa-ink-3)]' : '' }}">
                                    @if($globaleSuche)
                                        <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $item->supplier?->name ?? '–' }}</td>
                                    @endif
                                    <td class="min-w-[12rem]">
                                        <span class="flex items-center gap-2">
                                            <button type="button" wire:click.stop="artikelOeffnen({{ $item->id }})"
                                                    class="text-left font-medium hover:text-[var(--fa-accent)] hover:underline {{ $item->is_inactive ? 'text-[var(--fa-ink-3)]' : 'text-[var(--fa-ink)]' }}">{{ $item->label }}</button>
                                            @if($item->is_inactive)<x-fa::badge>inaktiv</x-fa::badge>@endif
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap tabular-nums text-[var(--fa-ink-2)]">{{ $item->artikel_nr ?? '–' }}</td>
                                    <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $item->category ?? '–' }}</td>
                                    <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $item->material ?? '–' }}</td>
                                    <td class="whitespace-nowrap tabular-nums text-[var(--fa-ink-2)]">{{ $item->masse_label ?? '–' }}</td>
                                    <td class="num"><x-fa::money :value="$item->rental_price" /></td>
                                    <td class="num text-[var(--fa-ink-2)]">
                                        @if($item->pfand !== null)<x-fa::money :value="$item->pfand" />@else<span class="text-[var(--fa-ink-3)]" title="Kein Pfand hinterlegt">–</span>@endif
                                    </td>
                                    <td class="text-right whitespace-nowrap" wire:click.stop @click.stop>
                                        @if($item->is_inactive)
                                            <x-fa::icon-button size="sm" icon="heroicon-o-arrow-uturn-left" label="Artikel wieder aktivieren"
                                                wire:click="artikelDeaktivieren({{ $item->id }}, false)" />
                                        @else
                                            <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-no-symbol" label="Artikel deaktivieren"
                                                wire:click="artikelDeaktivieren({{ $item->id }}, true)" />
                                        @endif
                                    </td>
                                </x-foodalchemist::table-row>
                            @empty
                                <tr>
                                    <td colspan="{{ $spaltenZahl }}">
                                        @if($globaleSuche)
                                            <x-fa::empty icon="heroicon-o-magnifying-glass" title="Kein Geschirr gefunden">Anderen Suchbegriff versuchen{{ $onlyActive ? ' oder „Nur aktive Artikel“ ausschalten' : '' }}.</x-fa::empty>
                                        @elseif(trim($artikelSuche) !== '')
                                            <x-fa::empty icon="heroicon-o-magnifying-glass" title="Kein Geschirr gefunden">In diesem Sortiment passt nichts zu „{{ $artikelSuche }}“.</x-fa::empty>
                                        @elseif($nurInaktiveVersteckt)
                                            <x-fa::empty icon="heroicon-o-eye-slash" title="Kein aktiver Artikel bei diesem Lieferanten">
                                                Alle Artikel sind deaktiviert. Schalte „Nur aktive Artikel“ aus, um sie zu sehen und wieder zu aktivieren.
                                            </x-fa::empty>
                                        @else
                                            <x-fa::empty icon="heroicon-o-square-2-stack" title="Noch kein Geschirr bei diesem Lieferanten">
                                                Über „Neuer Artikel“ Teller, Gläser oder Besteck mit Leihpreis anlegen.
                                            </x-fa::empty>
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-[var(--fa-line)]">{{ $artikel->links('foodalchemist::components.fa.pagination') }}</div>
            @endif
        </div>

        {{-- Neuer Leih-Lieferant --}}
        <x-foodalchemist::modal name="g-lieferant-neu" title="Neuer Leih-Lieferant" size="max-w-2xl">
            @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
            <x-foodalchemist::modal-section title="Stammdaten">
                <div class="grid grid-cols-2 gap-3">
                    <x-fa::field label="Name" for="gl-neu-name" required class="col-span-2">
                        <x-fa::input id="gl-neu-name" wire:model="neuLieferant.name" wire:keydown.enter="lieferantAnlegen" data-neu-lieferant-name />
                    </x-fa::field>
                    <x-fa::field label="Ort" for="gl-neu-ort" optional>
                        <x-fa::input id="gl-neu-ort" wire:model="neuLieferant.city" />
                    </x-fa::field>
                    <x-fa::field label="Telefon" for="gl-neu-telefon" optional>
                        <x-fa::input id="gl-neu-telefon" wire:model="neuLieferant.telefon" />
                    </x-fa::field>
                    <x-fa::field label="Bestell-E-Mail" for="gl-neu-mail" optional class="col-span-2"
                        hint="Der Lieferant gehört deinem Team. Das Geschirr legst du danach über „Neuer Artikel“ an.">
                        <x-fa::input id="gl-neu-mail" wire:model="neuLieferant.email_order" />
                    </x-fa::field>
                </div>
            </x-foodalchemist::modal-section>
            <x-slot:footer>
                <x-fa::button variant="ghost" @click="$dispatch('modal.close', { name: 'g-lieferant-neu' })">Abbrechen</x-fa::button>
                <x-fa::button variant="primary" wire:click="lieferantAnlegen">Lieferant anlegen</x-fa::button>
            </x-slot:footer>
        </x-foodalchemist::modal>

        {{-- Leih-Lieferant bearbeiten --}}
        <x-foodalchemist::modal name="g-lieferant-edit" title="Leih-Lieferant bearbeiten" size="max-w-2xl">
            @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
            <x-foodalchemist::modal-section title="Stammdaten">
                <div class="grid grid-cols-2 gap-3">
                    <x-fa::field label="Name" for="gl-ed-name" required class="col-span-2">
                        <x-fa::input id="gl-ed-name" wire:model="editLieferant.name" />
                    </x-fa::field>
                    <x-fa::field label="Straße" for="gl-ed-strasse">
                        <x-fa::input id="gl-ed-strasse" wire:model="editLieferant.address" />
                    </x-fa::field>
                    <div class="grid grid-cols-[6rem_1fr] gap-2">
                        <x-fa::field label="PLZ" for="gl-ed-plz">
                            <x-fa::input id="gl-ed-plz" wire:model="editLieferant.postal_code" />
                        </x-fa::field>
                        <x-fa::field label="Ort" for="gl-ed-ort">
                            <x-fa::input id="gl-ed-ort" wire:model="editLieferant.city" />
                        </x-fa::field>
                    </div>
                    <x-fa::field label="Telefon" for="gl-ed-telefon">
                        <x-fa::input id="gl-ed-telefon" wire:model="editLieferant.telefon" />
                    </x-fa::field>
                    <x-fa::field label="Bestell-E-Mail" for="gl-ed-mail">
                        <x-fa::input id="gl-ed-mail" wire:model="editLieferant.email_order" />
                    </x-fa::field>
                    <x-fa::field label="Homepage" for="gl-ed-web" class="col-span-2">
                        <x-fa::input id="gl-ed-web" wire:model="editLieferant.homepage" />
                    </x-fa::field>
                </div>
            </x-foodalchemist::modal-section>
            <x-slot:footer>
                <x-fa::button variant="ghost" @click="$dispatch('modal.close', { name: 'g-lieferant-edit' })">Abbrechen</x-fa::button>
                <x-fa::button variant="primary" wire:click="lieferantSpeichern">Lieferant speichern</x-fa::button>
            </x-slot:footer>
        </x-foodalchemist::modal>

        {{-- Geschirr-Artikel: Neu + Bearbeiten geteilt. Edit-Modus = Fullscreen-Editor auf dunklem Grund
             (fa-editor-panel), Neuanlage bleibt hell/klein — Muster wie suppliers/item-modal. --}}
        <x-foodalchemist::modal name="g-artikel" :title="$editItemId !== null ? 'Geschirr bearbeiten' : 'Neuer Geschirr-Artikel'" size="max-w-3xl"
            :title-name="$editItemId !== null ? ($artikelForm['label'] ?: null) : null"
            :fullscreen="$editItemId !== null" :dark-canvas="$editItemId !== null">
            @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
            <x-foodalchemist::modal-section title="Artikel">
                <div class="grid grid-cols-3 gap-3">
                    <x-fa::field label="Bezeichnung" for="ga-label" required class="col-span-2">
                        <x-fa::input id="ga-label" wire:model="artikelForm.label" data-label />
                    </x-fa::field>
                    <x-fa::field label="Artikel-Nr." for="ga-nr">
                        <x-fa::input id="ga-nr" wire:model="artikelForm.artikel_nr" />
                    </x-fa::field>
                    <x-fa::field label="Kategorie" for="ga-kategorie">
                        <x-fa::input id="ga-kategorie" list="g-kategorie" wire:model="artikelForm.category" placeholder="Teller, Glas, Besteck" />
                        <datalist id="g-kategorie"><option>Teller</option><option>Schale</option><option>Platte</option><option>Glas</option><option>Tasse</option><option>Besteck</option><option>Schüssel</option><option>Deko</option></datalist>
                    </x-fa::field>
                    <x-fa::field label="Präsentationsform" for="ga-vehikel" hint="Der Konzept-Planer schlägt dann passendes Geschirr vor.">
                        <x-fa::select id="ga-vehikel" wire:model="artikelForm.vehicle_vocab_id" placeholder="Keine Zuordnung"
                            title="Ordnet den Artikel der abstrakten Präsentationsform zu. Der Konzept-Planer bevorzugt dann passende Teile.">
                            @foreach($vehikelListe as $v)<option value="{{ $v->id }}">{{ $v->group_name ? $v->group_name . ' · ' : '' }}{{ $v->name }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::field label="Material" for="ga-material">
                        <x-fa::input id="ga-material" list="g-material" wire:model="artikelForm.material" placeholder="Porzellan, Glas" />
                        <datalist id="g-material"><option>Porzellan</option><option>Glas</option><option>Edelstahl</option><option>Holz</option><option>Schiefer</option><option>Keramik</option><option>Kunststoff</option></datalist>
                    </x-fa::field>
                    <x-fa::field label="Form" for="ga-form">
                        <x-fa::input id="ga-form" wire:model="artikelForm.form" placeholder="rund, eckig, oval" />
                    </x-fa::field>
                    <x-fa::field label="Farbe" for="ga-farbe">
                        <x-fa::input id="ga-farbe" wire:model="artikelForm.color" />
                    </x-fa::field>
                </div>
            </x-foodalchemist::modal-section>
            <x-foodalchemist::modal-section title="Leih-Konditionen">
                <div class="grid grid-cols-3 gap-3">
                    <x-fa::field label="Leihpreis netto in €" for="ga-preis">
                        <x-fa::input id="ga-preis" wire:model="artikelForm.rental_price" numeric />
                    </x-fa::field>
                    <x-fa::field label="Pfand in €" for="ga-pfand" optional>
                        <x-fa::input id="ga-pfand" wire:model="artikelForm.pfand" numeric />
                    </x-fa::field>
                    <x-fa::field label="Einheit" for="ga-einheit">
                        <x-fa::input id="ga-einheit" wire:model="artikelForm.unit" />
                    </x-fa::field>
                </div>
            </x-foodalchemist::modal-section>
            <x-foodalchemist::modal-section title="Maße">
                <div class="grid grid-cols-3 sm:grid-cols-6 gap-3">
                    <x-fa::field label="Ø in mm" for="ga-durchmesser">
                        <x-fa::input id="ga-durchmesser" wire:model="artikelForm.diameter_mm" numeric />
                    </x-fa::field>
                    <x-fa::field label="Länge in mm" for="ga-laenge">
                        <x-fa::input id="ga-laenge" wire:model="artikelForm.length_mm" numeric />
                    </x-fa::field>
                    <x-fa::field label="Breite in mm" for="ga-breite">
                        <x-fa::input id="ga-breite" wire:model="artikelForm.width_mm" numeric />
                    </x-fa::field>
                    <x-fa::field label="Höhe in mm" for="ga-hoehe">
                        <x-fa::input id="ga-hoehe" wire:model="artikelForm.height_mm" numeric />
                    </x-fa::field>
                    <x-fa::field label="Volumen in ml" for="ga-volumen">
                        <x-fa::input id="ga-volumen" wire:model="artikelForm.volumen_ml" numeric />
                    </x-fa::field>
                    <x-fa::field label="Gewicht in g" for="ga-gewicht">
                        <x-fa::input id="ga-gewicht" wire:model="artikelForm.weight_g" numeric />
                    </x-fa::field>
                </div>
                <x-fa::field label="Notiz" for="ga-notiz" optional class="mt-3">
                    <x-fa::textarea id="ga-notiz" wire:model="artikelForm.note" rows="2" />
                </x-fa::field>
            </x-foodalchemist::modal-section>
            <x-slot:footer>
                <x-fa::button variant="ghost" @click="$dispatch('modal.close', { name: 'g-artikel' })">Abbrechen</x-fa::button>
                <x-fa::button variant="primary" wire:click="artikelSpeichern">Artikel speichern</x-fa::button>
            </x-slot:footer>
        </x-foodalchemist::modal>
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
