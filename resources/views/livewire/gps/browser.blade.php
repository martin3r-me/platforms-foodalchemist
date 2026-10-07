{{-- Grundprodukte-Browser — Warengruppen links, Tabelle Mitte, Detail rechts.
     fa-pass Welle 2 (2026-10-05): auf Bausteine <x-fa::…> umgestellt (Muster wie recipes/browser).
     Funktion, wire:-Bindungen und data-Marker unverändert. Neu: Status-Filter als Chips über der
     Tabelle, Status als Chip mit Menü statt Dropdown je Zeile, fehlender Preis als Signal,
     Detail-Spalte erst sichtbar, wenn ein Grundprodukt gewählt ist. --}}
@php
    $statusOptionen = ['' => 'Alle ' . number_format(collect($statusFaelle)->sum(fn ($f) => $statusCounts[$f->value] ?? 0), 0, ',', '.')];
    foreach ($statusFaelle as $fall) {
        if (($statusCounts[$fall->value] ?? 0) > 0 || $status === $fall->value) {
            $statusOptionen[$fall->value] = $fall->label() . ' ' . number_format($statusCounts[$fall->value] ?? 0, 0, ',', '.');
        }
    }
    $statusWahl = [\Platform\FoodAlchemist\Enums\GpStatus::Approved, \Platform\FoodAlchemist\Enums\GpStatus::Tentative, \Platform\FoodAlchemist\Enums\GpStatus::Rejected];
    $bestand = collect([
        'gps' => 'Grundprodukte',
        'las' => 'Lieferantenartikel',
        'lieferanten' => 'Lieferanten',
    ])->map(fn ($text, $key) => isset($kpis[$key]) ? number_format($kpis[$key], 0, ',', '.') . ' ' . $text : null)->filter();
    $untertitel = number_format($gps->total(), 0, ',', '.') . ' Treffer' . ($bestand->isNotEmpty() ? ' · Bestand: ' . $bestand->implode(' · ') : '');
    $wgName = $commodity_group !== '' ? ($warengruppen->firstWhere('code', $commodity_group)?->name ?? $commodity_group) : null;
    $allergenKurz = fn ($feld) => explode(' ', \Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE[$feld] ?? $feld)[0];
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Grundprodukte" icon="heroicon-o-cube" />
    </x-slot:navbar>

    {{-- Zone links: Suche · Warengruppen-Baum mit Zahlen · Unterkategorien --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Warengruppen" width="w-80">
            <div class="p-3 flex flex-col gap-3" data-gp-baum>
                <div class="relative">
                    <label for="gp-suche" class="sr-only">Grundprodukte durchsuchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="gp-suche" type="search" wire:model.live.debounce.300ms="search" placeholder="Name oder Hauptzutat" class="pl-8" data-gp-suche />
                </div>

                <div class="flex flex-col gap-0.5 pt-2 border-t border-[var(--fa-line)]" data-wg-liste>
                    <x-foodalchemist::filter-row wire:click="waehleWg('')" :active="$commodity_group === ''"
                        :count="array_sum($wgCounts)">Alle Warengruppen</x-foodalchemist::filter-row>
                    @foreach($warengruppen as $wg)
                        <div wire:key="wg-{{ $wg->code }}">
                            <x-foodalchemist::filter-row wire:click="waehleWg('{{ $wg->code }}')"
                                :active="$commodity_group === $wg->code" :child-active="$subKategorie !== ''"
                                :count="$wgCounts[$wg->code] ?? 0">{{ $wg->codedLabel() }}</x-foodalchemist::filter-row>
                            @if($commodity_group === $wg->code && count($subCounts) > 0)
                                <x-foodalchemist::filter-ast data-sub-liste>
                                    @foreach($subCounts as $sub => $n)
                                        <x-foodalchemist::filter-row level="child" wire:key="sub-{{ md5($sub) }}"
                                            wire:click="waehleSub('{{ addslashes($sub) }}')"
                                            :active="$subKategorie === $sub" :count="$n">{{ $sub }}</x-foodalchemist::filter-row>
                                    @endforeach
                                </x-foodalchemist::filter-ast>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Detail-Spalte immer vorhanden: nach dem Anlegen im Dialog wählt `gp-selected` das neue Grundprodukt
         direkt im Panel aus. Ohne gewähltes Grundprodukt zeigt das Panel einen Leerzustand. --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-96" :maxWidth="760" scope="activity_gps" side="right" :aufziehen="$sprungDetail">
            <livewire:foodalchemist.gps.detail-panel :gp-id="$gpId" key="gps-browser--gps.detail-panel" />
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    {{-- Editoren und Dialoge (innerhalb x-ui-page, P-2) --}}
    <livewire:foodalchemist.gps.gp-modal key="gps-browser--gps.gp-modal" />
    {{-- D-5: neutrale Platzhalter für Grundrezept-Vorlagen --}}
    <livewire:foodalchemist.gps.platzhalter-modal key="gps-browser--gps.platzhalter-modal" />
    {{-- R9/M9-05: Verwendungs-Klicks aus dem Panel öffnen die Rezept-Editoren --}}
    <livewire:foodalchemist.recipes.recipe-modal key="gps-browser--recipes.recipe-modal" />
    <livewire:foodalchemist.recipes.pairing-netz-modal key="gps-browser--recipes.pairing-netz-modal" />{{-- „Netz öffnen" aus dem Rezept-/Gericht-Editor --}}
    <livewire:foodalchemist.verkauf.vk-modal key="gps-browser--verkauf.vk-modal" />
    {{-- Klick auf einen Lieferantenartikel im Panel öffnet dessen Artikel-Dialog --}}
    <livewire:foodalchemist.suppliers.item-modal key="gps-browser--suppliers.item-modal" />

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Grundprodukte" :subtitle="$untertitel">
            <x-slot:actions>
                @if($gpId !== null)
                    <x-fa::button variant="ghost" icon="heroicon-o-printer" :href="route('foodalchemist.gps.dokument', ['id' => $gpId, 'profil' => 'kalkulation'])" target="_blank"
                        title="Blatt zum gewählten Grundprodukt mit Artikeln, Preisen und Verwendung" data-gp-druck>Blatt drucken</x-fa::button>
                @endif
                <x-fa::button icon="heroicon-o-square-2-stack" wire:click="$dispatch('platzhalter-modal.oeffnen')"
                    title="Neutrale Platzhalter für Grundrezept-Vorlagen verwalten" data-platzhalter-oeffnen>Platzhalter verwalten</x-fa::button>
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="$dispatch('gp-modal.oeffnen')" data-gp-anlegen>Neues Grundprodukt</x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-fa::choice name="status" :options="$statusOptionen" />
            {{-- E14: Ansichts-Schalter — knappe Spalten je Aufgabe --}}
            <div class="flex items-center gap-3">
                <div role="group" aria-label="Ansicht" class="flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]" data-ansicht-schalter>
                    @foreach($ansichten as $ak => [$al, $unused])
                        <button type="button" wire:click="$set('ansicht', '{{ $ak }}')" aria-pressed="{{ $ansicht === $ak ? 'true' : 'false' }}"
                                class="h-7 px-3 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors {{ $ansicht === $ak ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}"
                                data-ansicht="{{ $ak }}">{{ $al }}</button>
                    @endforeach
                </div>
                <x-fa::select wire:model.live="perPage" size="sm" aria-label="Einträge je Seite" class="w-auto" data-per-page>
                    @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }} je Seite</option>@endforeach
                </x-fa::select>
            </div>
        </div>

        <div class="fa-surface overflow-hidden" data-gp-tabelle>
            @if($wgName !== null)
                <div class="flex flex-wrap items-center gap-2 px-4 py-2.5 border-b border-[var(--fa-line)] text-[length:var(--fa-text-md)]">
                    <span class="font-semibold text-[var(--fa-ink)]">{{ $wgName }}</span>
                    @if($subKategorie !== '')
                        @svg('heroicon-m-chevron-right', 'w-4 h-4 text-[var(--fa-ink-3)]')
                        <span class="text-[var(--fa-ink-2)]">{{ $subKategorie }}</span>
                    @endif
                </div>
            @endif
            {{-- Eigener Scroll-Container: der Tabellenkopf klebt; breite Tabellen scrollen waagerecht statt abzuschneiden. --}}
            <div class="max-h-[70vh] overflow-auto">
                <table class="fa-table">
                    <thead class="sticky top-0 z-20 bg-[var(--fa-surface)]">
                        <tr>
                            <th class="w-full">Name</th>
                            {{-- E14: Kopf folgt dem KATALOG, nicht der Ansicht --}}
                            @foreach($spaltenKatalog as $sk => [$skLabel, $skAlign])
                                @if(in_array($sk, $spalten, true))
                                    <th class="w-px {{ $skAlign === 'text-right' ? 'num' : '' }}">{{ $skLabel }}</th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($gps as $gp)
                            <x-foodalchemist::table-row :active="$gpId === $gp->id" wire:key="gp-{{ $gp->id }}" wire:click="waehleGp({{ $gp->id }})"
                                x-data x-on:click="$store.ui?.mSet('activity_gps', 'open', true)"
                                data-gp-zeile="{{ $gp->id }}">
                                {{-- R6: Namens-Klick öffnet direkt den Editor (Zeilen-Klick bleibt Detail-Auswahl) --}}
                                <td class="min-w-[12rem]">
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <button type="button" wire:click.stop="bearbeite({{ $gp->id }})" title="{{ $gp->name }} bearbeiten"
                                                class="text-left font-medium text-[var(--fa-ink)] hover:text-[var(--fa-accent)] hover:underline"
                                                data-gp-name>{{ $gp->name }}</button>
                                        @if($gp->is_derivat)<x-fa::badge tone="info">Derivat</x-fa::badge>@endif
                                        @if($gp->is_platzhalter)<x-fa::badge>Platzhalter</x-fa::badge>@endif
                                    </span>
                                </td>
                                @if(in_array('warengruppe', $spalten, true))<td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $gp->commodity_group?->name ?? $gp->commodity_group_code ?? '–' }}</td>@endif
                                @if(in_array('leadpreis', $spalten, true))<td class="num" data-lead-preis>
                                    @if($gp->lead_vergleichspreis)
                                        <x-fa::money :value="$gp->lead_vergleichspreis['value']" :per="\Illuminate\Support\Str::after($gp->lead_vergleichspreis['unit'], '€/')" />
                                    @elseif($gp->lead_preis !== null)
                                        <span class="inline-flex items-center gap-1.5" title="Gebindepreis: ohne Menge kein Preis je Kilo oder Liter">
                                            <x-fa::money :value="$gp->lead_preis" class="text-[var(--fa-ink-2)]" />
                                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Gebinde</span>
                                        </span>
                                    @elseif(! $gp->requires_la)
                                        <span class="text-[var(--fa-ink-3)]" title="Braucht keinen Lieferantenartikel">–</span>
                                    @else
                                        <x-fa::money :value="null" />
                                    @endif
                                </td>@endif
                                @if(in_array('las', $spalten, true))<td class="num">
                                    @if($gp->n_las_total > 0)<span class="text-[var(--fa-ink-2)]">{{ $gp->n_las_total }}</span>
                                    @elseif(! $gp->requires_la)<span class="text-[var(--fa-ink-3)]" title="Braucht keinen Lieferantenartikel">entfällt</span>
                                    @else<x-fa::badge tone="warn" title="Kein Lieferantenartikel verknüpft: Preis und Allergene fehlen">keiner</x-fa::badge>@endif
                                </td>@endif
                                @if(in_array('rezepte', $spalten, true))<td class="num text-[var(--fa-ink-2)]">{{ $gp->rezepte_count ?? '–' }}</td>@endif
                                {{-- Drei Zustände: enthält · frei · keine Daten. Effektivwerte (manuell > Mutter > Artikel), nur Anzeige. --}}
                                @if(in_array('allergene', $spalten, true))<td class="whitespace-nowrap" data-allergen-status="{{ $gp->allergen_status ?? 'keine_daten' }}">
                                    @php
                                        $kiSuffix = $gp->allergen_ki ? ', von der KI geschätzt' . ($gp->allergen_ki_conf !== null ? ' (' . round($gp->allergen_ki_conf * 100) . ' %)' : '') . ', nicht durch Lieferantenartikel belegt' : '';
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5">
                                        @if(($gp->allergen_status ?? 'keine_daten') === 'vorhanden')
                                            <x-fa::badge tone="crit" title="Enthält: {{ collect($gp->allergen_badges)->map($allergenKurz)->implode(', ') ?: 'Spuren' }}{{ $kiSuffix }}">enthält</x-fa::badge>
                                        @elseif($gp->allergen_status === 'frei')
                                            <x-fa::signal tone="ok" icon="heroicon-m-check" title="Keines der 14 Hauptallergene deklariert{{ $kiSuffix }}">frei</x-fa::signal>
                                        @else
                                            <x-fa::signal tone="warn" title="Die Lieferantenartikel haben keine Allergenangaben. Nicht als frei werten.">keine Daten</x-fa::signal>
                                        @endif
                                        @if($gp->allergen_ki && ($gp->allergen_status ?? 'keine_daten') !== 'keine_daten')
                                            <x-fa::badge tone="accent" icon="heroicon-m-sparkles" data-allergen-ki>KI</x-fa::badge>
                                        @endif
                                    </span>
                                </td>@endif
                                @if(in_array('status', $spalten, true))
                                {{-- Status als Chip; Kuratoren ändern ihn über ein kleines Menü --}}
                                <td class="whitespace-nowrap" wire:click.stop @click.stop>
                                    @if(\Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $gp) && $gp->status !== \Platform\FoodAlchemist\Enums\GpStatus::Merged)
                                        <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false" wire:key="st-{{ $gp->id }}-{{ $gp->status->value }}">
                                            <button type="button" x-on:click="toggle($event)" class="inline-flex items-center gap-0.5" aria-haspopup="menu" x-bind:aria-expanded="offen" aria-label="Status von {{ $gp->name }} ändern" data-status-select>
                                                <x-fa::status :value="$gp->status" />@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]')
                                            </button>
                                            <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-40 fa-surface shadow-lg py-1">
                                                @foreach($statusWahl as $fall)
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="statusSetzen({{ $gp->id }}, '{{ $fall->value }}')"
                                                            class="flex w-full items-center justify-between px-3 py-1.5 text-left text-[length:var(--fa-text-md)] hover:bg-[var(--fa-hover)] {{ $gp->status === $fall ? 'font-semibold text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}">
                                                        {{ $fall->label() }}@if($gp->status === $fall)@svg('heroicon-m-check', 'w-4 h-4')@endif
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @else
                                        <x-fa::status :value="$gp->status" />
                                    @endif
                                </td>@endif
                            </x-foodalchemist::table-row>
                        @empty
                            <tr>
                                <td colspan="{{ count($spalten) + 1 }}">
                                    <x-fa::empty icon="heroicon-o-cube" title="Keine Grundprodukte gefunden">Suche oder Filter zurücksetzen oder ein neues Grundprodukt anlegen.</x-fa::empty>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-[var(--fa-line)]">{{ $gps->links('foodalchemist::components.fa.pagination') }}</div>
        </div>
    </x-ui-page-container>
</x-ui-page>
