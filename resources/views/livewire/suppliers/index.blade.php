{{-- M2-01/02/03: Lieferanten-Browser (P-7) — Liste links (Page-Sidebar, Platzierungs-Entscheid), dichte Artikel-Tabelle --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Lieferanten" icon="heroicon-o-truck" />
    </x-slot:navbar>

    {{-- Zone links: Lieferanten-Liste mit P-7-Zählern --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Lieferanten" width="w-96" storeKey="faSuppliersOpen">
            <div class="p-3 space-y-2" data-supplier-liste>
                <input type="search" wire:model.live.debounce.300ms="q"
                       placeholder="Artikel-Suche über ALLE Lieferanten …" class="{{ $input }}" data-global-suche />
                <input type="search" wire:model.live.debounce.300ms="supplierSuche"
                       placeholder="Lieferant filtern …" class="{{ $input }}" />
                <div class="flex items-center justify-between px-1">
                    <label class="flex items-center gap-2 {{ $label }} cursor-pointer">
                        <input type="checkbox" wire:model.live="includeInactive" class="rounded border-gray-300" /> Inaktive zeigen
                    </label>
                    <button type="button" @click="$dispatch('modal.open', { name: 'lieferant-neu' })"
                            class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-neuer-lieferant-btn>+ Lieferant</button>
                </div>
                <div class="space-y-0.5 -mx-1">
                    @foreach($lieferanten as $l)
                        <button type="button" wire:key="sup-{{ $l->id }}" wire:click="waehleLieferant({{ $l->id }})"
                                class="w-full px-2 py-1.5 rounded-lg text-left transition-all duration-150 {{ !$globaleSuche && $supplierId === $l->id
                                    ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10'
                                    : 'hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                            <span class="block text-sm {{ $l->is_inactive ? 'text-gray-400 line-through' : 'text-gray-700 dark:text-gray-200' }} truncate">{{ $l->name }}</span>
                            <span class="block text-xs text-gray-400">{{ number_format($l->item_count, 0, ',', '.') }} Artikel ·
                                <span class="text-emerald-600 dark:text-emerald-400">{{ number_format($l->mapped_count, 0, ',', '.') }} gemapped</span></span>
                        </button>
                    @endforeach
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        {{-- Kopf-Aktionen (P-7) --}}
        <div class="flex items-center justify-between gap-3 pt-1">
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100 min-w-0 truncate">
                @if($globaleSuche) Suche „{{ $q }}" über alle Lieferanten
                @else {{ $aktiverLieferant?->name ?? '—' }}
                @endif
            </h3>
            <div class="flex items-center gap-2 shrink-0">
                @if(!$globaleSuche && $aktiverLieferant)
                    <input type="search" wire:model.live.debounce.300ms="artikelSuche"
                           placeholder="Artikel dieses Lieferanten …" class="{{ $input }} !w-56 !py-1.5" data-lokale-suche />
                    @if($darfLieferantEdit)
                        <button type="button" wire:click="lieferantBearbeiten" class="{{ $btnGhostXs }}" data-lieferant-edit-btn>Bearbeiten</button>
                        <button type="button" wire:click="lieferantDeaktivieren({{ $aktiverLieferant->is_inactive ? 'false' : 'true' }})"
                                class="{{ $btnGhostXs }} {{ $aktiverLieferant->is_inactive ? '' : 'text-red-500' }}">{{ $aktiverLieferant->is_inactive ? 'Aktivieren' : 'Deaktivieren' }}</button>
                    @endif
                @endif
                <label class="flex items-center gap-2 {{ $label }} cursor-pointer">
                    <input type="checkbox" wire:model.live="onlyActive" class="rounded border-gray-300" /> Nur aktive
                </label>
                <button type="button" disabled title="Kommt mit M3-11 (Bulk-Match je Lieferant)" class="{{ $btnGhostXs }} opacity-40 cursor-not-allowed">Bulk-Match</button>
                <button type="button" wire:click="anomalienAnzeigen" wire:loading.attr="disabled" class="{{ $btnGhostXs }}" data-anomalien-btn>Preis-Anomalien</button>
                <button type="button" @click="$dispatch('modal.open', { name: 'artikel-neu' })" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-neuer-artikel-btn>+ Neuer Artikel</button>
            </div>
        </div>

        {{-- Artikel-Tabelle (M2-02) --}}
        <div class="relative overflow-hidden {{ $card }}" data-artikel-tabelle>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Artikel</h3>
                <span class="{{ $label }} flex items-center gap-2">
                    {{ $artikel ? number_format($artikel->total(), 0, ',', '.') : 0 }} Treffer ·
                    <select wire:model.live="perPage" class="bg-transparent border-0 text-xs uppercase tracking-wider text-gray-400 cursor-pointer focus:ring-0" data-per-page>
                        @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }}/Seite</option>@endforeach
                    </select>
                </span>
            </div>
            <table class="{{ $table }}">
                <thead>
                    <tr class="text-left">
                        @if($globaleSuche)<th class="{{ $th }}">Lieferant</th>@endif
                        @foreach(['ArtNr', 'Bezeichnung', 'Gebinde', 'Status', 'EK', 'Vergleichspreis', 'Grundprodukt'] as $head)
                            <th class="{{ $th }}">{{ $head }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($artikel ?? [] as $item)
                        <tr wire:key="item-{{ $item->id }}" class="{{ $tr }}">
                            @if($globaleSuche)
                                <td class="{{ $td }} text-gray-500">{{ $item->supplier?->name ?? '—' }}</td>
                            @endif
                            <td class="{{ $td }} font-mono text-xs text-gray-500">{{ $item->article_number ?? '—' }}</td>
                            <td class="{{ $td }} font-medium max-w-md truncate" title="{{ $item->designation }}">
                                <button type="button" wire:click="$dispatch('item-modal.oeffnen', { id: {{ $item->id }} })"
                                        class="text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400 transition-colors duration-150 truncate max-w-full text-left">{{ $item->designation }}</button>
                            </td>
                            <td class="{{ $td }} text-gray-500 whitespace-nowrap">
                                {{ $item->qty !== null ? rtrim(rtrim((string) $item->qty, '0'), '.') : '—' }} {{ $item->unit_code ?? $item->ordering_unit }}
                                @if($item->qty === null)<span class="ml-1 {{ $pill }} {{ $variantPill['warning'] }}" title="Gebinde-Menge fehlt (GL-03 A-2)">qty?</span>@endif
                            </td>
                            <td class="{{ $td }}">
                                @if($item->is_discontinued)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">ausgelistet</span>
                                @else<span class="{{ $pill }} {{ $variantPill['success'] }}">aktiv</span>@endif
                                @if($item->is_preorder)<span class="ml-1 {{ $pill }} {{ $variantPill['info'] }}" title="Vorbestell-Artikel (V-29){{ $item->preorder_days ? ' — ' . $item->preorder_days . ' Tage Vorlauf' : '' }}" data-vorbestell-pill>Vorbestellung{{ $item->preorder_days ? ' · ' . $item->preorder_days . ' T' : '' }}</span>@endif
                            </td>
                            <td class="{{ $td }} text-gray-900 dark:text-gray-100 whitespace-nowrap">
                                {{ $item->aktiver_preis !== null ? number_format((float) $item->aktiver_preis, 2, ',', '.') . ' €' : '—' }}
                            </td>
                            <td class="{{ $td }} text-gray-500 whitespace-nowrap" data-vergleichspreis>
                                {{ $item->vergleichspreis !== null ? number_format($item->vergleichspreis['wert'], 2, ',', '.') . ' ' . $item->vergleichspreis['einheit'] : '—' }}
                            </td>
                            <td class="{{ $td }}">
                                @if($item->structure?->gp)
                                    <a href="{{ route('foodalchemist.gps.show', $item->structure->gp_id) }}"
                                       class="text-violet-600 dark:text-violet-400 hover:underline">{{ $item->structure->gp->name }}</a>
                                @else
                                    <span class="text-gray-400">— nicht gemappt —</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-5 py-10 text-center text-gray-400">Keine Artikel gefunden.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if($artikel)
                <div class="px-5 py-3 border-t border-black/5 dark:border-white/10">{{ $artikel->links() }}</div>
            @endif
        </div>
        {{-- LA-Editor-Modal (M2-06/07/08) — innerhalb x-ui-page (Template-Regel) --}}
        <livewire:foodalchemist.suppliers.item-modal />

        {{-- Feedback 2026-06-11: Neuer Lieferant (gehört dem anlegenden Team — D1) --}}
        <x-foodalchemist::modal name="lieferant-neu" title="Neuer Lieferant" size="max-w-2xl">
            @if($fehler)<p class="text-sm text-red-600 dark:text-red-400">{{ $fehler }}</p>@endif
            <x-foodalchemist::modal-section title="Stammdaten">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2"><label class="block {{ $label }} mb-1">Name *</label>
                        <input type="text" wire:model="neuLieferant.name" wire:keydown.enter="lieferantAnlegen" class="{{ $input }}" data-neu-lieferant-name /></div>
                    <div><label class="block {{ $label }} mb-1">Ort</label>
                        <input type="text" wire:model="neuLieferant.city" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Bestell-E-Mail</label>
                        <input type="text" wire:model="neuLieferant.email_order" class="{{ $input }}" /></div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Gehört deinem Team (D1). Artikel danach über „+ Neuer Artikel".</p>
            </x-foodalchemist::modal-section>
            <x-slot:footer>
                <button type="button" @click="$dispatch('modal.close', { name: 'lieferant-neu' })" class="{{ $btnGhost }}">Abbrechen</button>
                <button type="button" wire:click="lieferantAnlegen" class="{{ $btnPrimary }}">Anlegen</button>
            </x-slot:footer>
        </x-foodalchemist::modal>

        {{-- M2-14: Lieferant bearbeiten (nur Besitzer-Team) --}}
        <x-foodalchemist::modal name="lieferant-edit" title="Lieferant bearbeiten" size="max-w-2xl">
            @if($fehler)<p class="text-sm text-red-600 dark:text-red-400">{{ $fehler }}</p>@endif
            <x-foodalchemist::modal-section title="Stammdaten">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2"><label class="block {{ $label }} mb-1">Name *</label>
                        <input type="text" wire:model="editLieferant.name" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Straße</label>
                        <input type="text" wire:model="editLieferant.address" class="{{ $input }}" /></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="block {{ $label }} mb-1">PLZ</label>
                            <input type="text" wire:model="editLieferant.postal_code" class="{{ $input }}" /></div>
                        <div><label class="block {{ $label }} mb-1">Ort</label>
                            <input type="text" wire:model="editLieferant.city" class="{{ $input }}" /></div>
                    </div>
                    <div><label class="block {{ $label }} mb-1">Bestell-E-Mail</label>
                        <input type="text" wire:model="editLieferant.email_order" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Homepage</label>
                        <input type="text" wire:model="editLieferant.homepage" class="{{ $input }}" /></div>
                </div>
            </x-foodalchemist::modal-section>
            <x-slot:footer>
                <button type="button" @click="$dispatch('modal.close', { name: 'lieferant-edit' })" class="{{ $btnGhost }}">Abbrechen</button>
                <button type="button" wire:click="lieferantSpeichern" class="{{ $btnPrimary }}">Speichern</button>
            </x-slot:footer>
        </x-foodalchemist::modal>

        {{-- M2-11: Neuer Artikel (Minimal-Pflichtfelder, gehört dem anlegenden Team — D1) --}}
        <x-foodalchemist::modal name="artikel-neu" title="Neuer Artikel" size="max-w-2xl">
            @if($fehler)<p class="text-sm text-red-600 dark:text-red-400">{{ $fehler }}</p>@endif
            <x-foodalchemist::modal-section title="Stammdaten">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2"><label class="block {{ $label }} mb-1">Bezeichnung *</label>
                        <input type="text" wire:model="neuArtikel.designation" wire:keydown.enter="artikelAnlegen" class="{{ $input }}" data-neu-bezeichnung /></div>
                    <div><label class="block {{ $label }} mb-1">Artikel-Nr.</label>
                        <input type="text" wire:model="neuArtikel.article_number" class="{{ $input }}" /></div>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="block {{ $label }} mb-1">Gebinde-Menge</label>
                            <input type="text" wire:model="neuArtikel.qty" class="{{ $input }}" /></div>
                        <div><label class="block {{ $label }} mb-1">Einheit</label>
                            <select wire:model="neuArtikel.unit_code" class="{{ $input }}">
                                <option value="">—</option>
                                @foreach(['kg', 'l', 'Stk'] as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach
                            </select></div>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Wird für <strong>{{ $aktiverLieferant?->name ?? '—' }}</strong> angelegt und gehört deinem Team — Eltern-/Geschwister-Teams sehen ihn nicht (D1).</p>
            </x-foodalchemist::modal-section>
            <x-slot:footer>
                <button type="button" @click="$dispatch('modal.close', { name: 'artikel-neu' })" class="{{ $btnGhost }}">Abbrechen</button>
                <button type="button" wire:click="artikelAnlegen" class="{{ $btnPrimary }}">Anlegen</button>
            </x-slot:footer>
        </x-foodalchemist::modal>

        {{-- M2-12: Preis-Anomalien-Report --}}
        <x-foodalchemist::modal name="preis-anomalien" title="Preis-Anomalien" size="max-w-5xl">
            @if($anomalien === null)
                <p class="text-sm text-gray-500">Wird berechnet …</p>
            @else
                <x-foodalchemist::modal-section title="Vergleichspreis-Ausreißer je Warengruppe (Faktor ≥ 4 vom Median)">
                    <table class="{{ $table }}" data-ausreisser>
                        <thead><tr class="text-left">@foreach(['Artikel', 'Lieferant', 'WG', 'Vergleichspreis', 'WG-Median', 'Faktor'] as $h)<th class="{{ $th }} !px-2">{{ $h }}</th>@endforeach</tr></thead>
                        <tbody>
                            @forelse($anomalien['ausreisser'] as $a)
                                <tr class="{{ $tr }}">
                                    <td class="{{ $td }} !px-2 max-w-sm truncate">{{ $a['bezeichnung'] }}</td>
                                    <td class="{{ $td }} !px-2 text-gray-500">{{ $a['lieferant'] }}</td>
                                    <td class="{{ $td }} !px-2 text-gray-500">{{ $a['wg'] }}</td>
                                    <td class="{{ $td }} !px-2">{{ number_format($a['wert'], 2, ',', '.') }} {{ $a['einheit'] }}</td>
                                    <td class="{{ $td }} !px-2 text-gray-500">{{ number_format($a['median'], 2, ',', '.') }} {{ $a['einheit'] }}</td>
                                    <td class="{{ $td }} !px-2"><span class="{{ $pill }} {{ $variantPill['warning'] }}">×{{ $a['faktor'] }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-2 py-6 text-center text-gray-400">Keine Ausreißer.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-foodalchemist::modal-section>
                <x-foodalchemist::modal-section title="Sprünge > 30 % zwischen Preis-Generationen">
                    @forelse($anomalien['spruenge'] as $sp)
                        <p class="text-sm text-gray-700 dark:text-gray-300 py-1">LA #{{ $sp->supplier_item_id }}: {{ number_format($sp->von, 2, ',', '.') }} € → {{ number_format($sp->nach, 2, ',', '.') }} € <span class="{{ $pill }} {{ $variantPill['warning'] }}">{{ $sp->sprung_pct }} %</span></p>
                    @empty
                        <p class="text-sm text-gray-400">Keine Generationen-Sprünge (Bestand ist Single-Snapshot — Historie wächst ab jetzt, GL-11 §3.3).</p>
                    @endforelse
                </x-foodalchemist::modal-section>
            @endif
        </x-foodalchemist::modal>
    </x-ui-page-container>
</x-ui-page>
