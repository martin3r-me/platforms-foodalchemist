{{-- #388 Geschirr-Datenbank — Browser (Leih-Lieferant links, Geschirr-Artikel Mitte). Vorbild: Lieferanten-Browser. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Geschirr" icon="heroicon-o-square-2-stack" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Geschirr'],
        ]" />
    </x-slot>

    {{-- Zone links: Leih-Lieferanten + Artikel-Zähler --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Leih-Lieferanten" width="w-96" storeKey="faGeschirrOpen">
            <div class="p-3 space-y-2" data-geschirr-liste>
                <input type="search" wire:model.live.debounce.300ms="q"
                       placeholder="Geschirr-Suche über ALLE Lieferanten …" class="{{ $input }}" data-global-suche />
                <input type="search" wire:model.live.debounce.300ms="supplierSuche"
                       placeholder="Lieferant filtern …" class="{{ $input }}" />
                <div class="flex items-center justify-between px-1">
                    <label class="flex items-center gap-2 {{ $label }} cursor-pointer">
                        <input type="checkbox" wire:model.live="includeInactive" class="rounded border-gray-300" /> Inaktive zeigen
                    </label>
                    <button type="button" @click="$dispatch('modal.open', { name: 'g-lieferant-neu' })"
                            class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-neuer-lieferant-btn>+ Lieferant</button>
                </div>
                <div class="space-y-0.5 -mx-1">
                    @forelse($lieferanten as $l)
                        <button type="button" wire:key="gsup-{{ $l->id }}" wire:click="waehleLieferant({{ $l->id }})"
                                class="w-full px-2 py-1.5 rounded-lg text-left transition-all duration-150 {{ !$globaleSuche && $supplierId === $l->id
                                    ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10'
                                    : 'hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                            <span class="block text-xs {{ $l->is_inactive ? 'text-gray-400 line-through' : 'text-gray-700 dark:text-gray-200' }} truncate">{{ $l->name }}</span>
                            <span class="block text-[11px] text-gray-400">{{ number_format($l->item_count, 0, ',', '.') }} Artikel</span>
                        </button>
                    @empty
                        <p class="text-[11px] text-gray-400 px-2 py-3">Noch kein Leih-Lieferant — „+ Lieferant" anlegen.</p>
                    @endforelse
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        {{-- Kopf-Aktionen --}}
        <div class="flex items-center justify-between gap-3 pt-1">
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100 min-w-0 truncate">
                @if($globaleSuche) Suche „{{ $q }}" über alle Lieferanten
                @else {{ $aktiverLieferant?->name ?? '—' }}
                @endif
            </h3>
            <div class="flex items-center gap-2 shrink-0">
                @if(!$globaleSuche && $aktiverLieferant)
                    <input type="search" wire:model.live.debounce.300ms="artikelSuche"
                           placeholder="Geschirr dieses Lieferanten …" class="{{ $input }} !w-56 !py-1.5" data-lokale-suche />
                    @if($darfLieferantEdit)
                        <button type="button" wire:click="lieferantBearbeiten" class="{{ $btnGhostXs }}" data-lieferant-edit-btn>Bearbeiten</button>
                        <button type="button" wire:click="lieferantDeaktivieren({{ $aktiverLieferant->is_inactive ? 'false' : 'true' }})"
                                class="{{ $btnGhostXs }} {{ $aktiverLieferant->is_inactive ? '' : 'text-red-500' }}">{{ $aktiverLieferant->is_inactive ? 'Aktivieren' : 'Deaktivieren' }}</button>
                    @endif
                @endif
                <label class="flex items-center gap-2 {{ $label }} cursor-pointer">
                    <input type="checkbox" wire:model.live="onlyActive" class="rounded border-gray-300" /> Nur aktive
                </label>
                <button type="button" wire:click="artikelNeu" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-neuer-artikel-btn>+ Neuer Artikel</button>
            </div>
        </div>

        {{-- Geschirr-Tabelle --}}
        <div class="relative overflow-hidden {{ $card }}" data-geschirr-tabelle>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Geschirr-Artikel</h3>
                <span class="{{ $label }} flex items-center gap-2">
                    {{ $artikel ? number_format($artikel->total(), 0, ',', '.') : 0 }} Treffer ·
                    <select wire:model.live="perPage" class="bg-transparent border-0 text-[11px] uppercase tracking-wider text-gray-400 cursor-pointer focus:ring-0" data-per-page>
                        @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }}/Seite</option>@endforeach
                    </select>
                </span>
            </div>
            <div class="overflow-x-auto">
            <table class="{{ $table }}">
                <thead>
                    <tr class="text-left">
                        @if($globaleSuche)<th class="{{ $th }}">Lieferant</th>@endif
                        @foreach([['ArtNr', ''], ['Bezeichnung', 'w-full'], ['Kategorie', ''], ['Material', ''], ['Maße', ''], ['Leihpreis', 'text-right'], ['Pfand', 'text-right'], ['Status', ''], ['', 'text-right']] as [$head, $align])
                            <th class="{{ $th }} {{ $align }}">{{ $head }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($artikel ?? [] as $item)
                        <tr wire:key="gitem-{{ $item->id }}" class="{{ $tr }}">
                            @if($globaleSuche)
                                <td class="{{ $td }} text-gray-500">{{ $item->supplier?->name ?? '—' }}</td>
                            @endif
                            <td class="{{ $td }} font-mono text-[11px] text-gray-500">{{ $item->artikel_nr ?? '—' }}</td>
                            <td class="{{ $td }} font-medium w-full max-w-0 min-w-44 truncate" title="{{ $item->label }}">
                                <button type="button" wire:click="artikelOeffnen({{ $item->id }})"
                                        class="text-gray-900 dark:text-gray-100 hover:text-violet-600 dark:hover:text-violet-400 transition-colors duration-150 truncate max-w-full text-left">{{ $item->label }}</button>
                            </td>
                            <td class="{{ $td }} text-gray-500 whitespace-nowrap">{{ $item->category ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-500 whitespace-nowrap">{{ $item->material ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-500 whitespace-nowrap text-[11px]">{{ $item->masse_label ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-900 dark:text-gray-100 whitespace-nowrap text-right tabular-nums">
                                {{ $item->rental_price !== null ? number_format((float) $item->rental_price, 2, ',', '.') . ' €' : '—' }}
                            </td>
                            <td class="{{ $td }} text-gray-500 whitespace-nowrap text-right tabular-nums">
                                {{ $item->pfand !== null ? number_format((float) $item->pfand, 2, ',', '.') . ' €' : '—' }}
                            </td>
                            <td class="{{ $td }}">
                                @if($item->is_inactive)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">inaktiv</span>
                                @else<span class="{{ $pill }} {{ $variantPill['success'] }}">aktiv</span>@endif
                            </td>
                            <td class="{{ $td }} text-right whitespace-nowrap">
                                <button type="button" wire:click="artikelDeaktivieren({{ $item->id }}, {{ $item->is_inactive ? 'false' : 'true' }})"
                                        class="{{ $btnGhostXs }} {{ $item->is_inactive ? '' : 'text-red-500' }}">{{ $item->is_inactive ? 'Aktivieren' : 'Deaktivieren' }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-5 py-10 text-center text-gray-400">Kein Geschirr gefunden.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
            @if($artikel)
                <div class="px-5 py-3 border-t border-black/5 dark:border-white/10">{{ $artikel->links() }}</div>
            @endif
        </div>

        {{-- Neuer Leih-Lieferant --}}
        <x-foodalchemist::modal name="g-lieferant-neu" title="Neuer Leih-Lieferant" size="max-w-2xl">
            @if($fehler)<p class="text-xs text-red-600 dark:text-red-400">{{ $fehler }}</p>@endif
            <x-foodalchemist::modal-section title="Stammdaten">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2"><label class="block {{ $label }} mb-1">Name *</label>
                        <input type="text" wire:model="neuLieferant.name" wire:keydown.enter="lieferantAnlegen" class="{{ $input }}" data-neu-lieferant-name /></div>
                    <div><label class="block {{ $label }} mb-1">Ort</label>
                        <input type="text" wire:model="neuLieferant.city" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Telefon</label>
                        <input type="text" wire:model="neuLieferant.telefon" class="{{ $input }}" /></div>
                    <div class="col-span-2"><label class="block {{ $label }} mb-1">Bestell-E-Mail</label>
                        <input type="text" wire:model="neuLieferant.email_order" class="{{ $input }}" /></div>
                </div>
                <p class="text-[11px] text-gray-400 mt-2">Gehört deinem Team. Geschirr danach über „+ Neuer Artikel".</p>
            </x-foodalchemist::modal-section>
            <x-slot:footer>
                <button type="button" @click="$dispatch('modal.close', { name: 'g-lieferant-neu' })" class="{{ $btnGhost }}">Abbrechen</button>
                <button type="button" wire:click="lieferantAnlegen" class="{{ $btnPrimary }}">Anlegen</button>
            </x-slot:footer>
        </x-foodalchemist::modal>

        {{-- Leih-Lieferant bearbeiten --}}
        <x-foodalchemist::modal name="g-lieferant-edit" title="Leih-Lieferant bearbeiten" size="max-w-2xl">
            @if($fehler)<p class="text-xs text-red-600 dark:text-red-400">{{ $fehler }}</p>@endif
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
                    <div><label class="block {{ $label }} mb-1">Telefon</label>
                        <input type="text" wire:model="editLieferant.telefon" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Bestell-E-Mail</label>
                        <input type="text" wire:model="editLieferant.email_order" class="{{ $input }}" /></div>
                    <div class="col-span-2"><label class="block {{ $label }} mb-1">Homepage</label>
                        <input type="text" wire:model="editLieferant.homepage" class="{{ $input }}" /></div>
                </div>
            </x-foodalchemist::modal-section>
            <x-slot:footer>
                <button type="button" @click="$dispatch('modal.close', { name: 'g-lieferant-edit' })" class="{{ $btnGhost }}">Abbrechen</button>
                <button type="button" wire:click="lieferantSpeichern" class="{{ $btnPrimary }}">Speichern</button>
            </x-slot:footer>
        </x-foodalchemist::modal>

        {{-- Geschirr-Artikel: Neu + Bearbeiten geteilt --}}
        <x-foodalchemist::modal name="g-artikel" title="Geschirr-Artikel" size="max-w-3xl">
            @if($fehler)<p class="text-xs text-red-600 dark:text-red-400">{{ $fehler }}</p>@endif
            <x-foodalchemist::modal-section title="Artikel">
                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2"><label class="block {{ $label }} mb-1">Bezeichnung *</label>
                        <input type="text" wire:model="artikelForm.label" class="{{ $input }}" data-label /></div>
                    <div><label class="block {{ $label }} mb-1">Artikel-Nr.</label>
                        <input type="text" wire:model="artikelForm.artikel_nr" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Kategorie</label>
                        <input type="text" list="g-kategorie" wire:model="artikelForm.category" class="{{ $input }}" placeholder="Teller / Glas / Besteck …" />
                        <datalist id="g-kategorie"><option>Teller</option><option>Schale</option><option>Platte</option><option>Glas</option><option>Tasse</option><option>Besteck</option><option>Schüssel</option><option>Deko</option></datalist></div>
                    <div><label class="block {{ $label }} mb-1">Servier-Vehikel-Typ</label>
                        <select wire:model="artikelForm.vehicle_vocab_id" class="{{ $input }}" title="Ordnet den Artikel der abstrakten Präsentationsform zu — der Concepter-Picker bevorzugt dann passende Teile (Darreichungs-Scharnier)">
                            <option value="">—</option>
                            @foreach($vehikelListe as $v)<option value="{{ $v->id }}">{{ $v->group_name ? $v->group_name . ' · ' : '' }}{{ $v->name }}</option>@endforeach
                        </select></div>
                    <div><label class="block {{ $label }} mb-1">Material</label>
                        <input type="text" list="g-material" wire:model="artikelForm.material" class="{{ $input }}" placeholder="Porzellan / Glas …" />
                        <datalist id="g-material"><option>Porzellan</option><option>Glas</option><option>Edelstahl</option><option>Holz</option><option>Schiefer</option><option>Keramik</option><option>Kunststoff</option></datalist></div>
                    <div><label class="block {{ $label }} mb-1">Form</label>
                        <input type="text" wire:model="artikelForm.form" class="{{ $input }}" placeholder="rund / eckig / oval" /></div>
                    <div><label class="block {{ $label }} mb-1">Farbe</label>
                        <input type="text" wire:model="artikelForm.color" class="{{ $input }}" /></div>
                </div>
            </x-foodalchemist::modal-section>
            <x-foodalchemist::modal-section title="Maße & Leih-Konditionen">
                <div class="grid grid-cols-4 gap-3">
                    <div><label class="block {{ $label }} mb-1">Ø mm</label>
                        <input type="text" wire:model="artikelForm.diameter_mm" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Länge mm</label>
                        <input type="text" wire:model="artikelForm.length_mm" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Breite mm</label>
                        <input type="text" wire:model="artikelForm.width_mm" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Höhe mm</label>
                        <input type="text" wire:model="artikelForm.height_mm" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Volumen ml</label>
                        <input type="text" wire:model="artikelForm.volumen_ml" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Gewicht g</label>
                        <input type="text" wire:model="artikelForm.weight_g" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Einheit</label>
                        <input type="text" wire:model="artikelForm.unit" class="{{ $input }}" /></div>
                </div>
                <div class="grid grid-cols-3 gap-3 mt-3">
                    <div><label class="block {{ $label }} mb-1">Leihpreis € (netto)</label>
                        <input type="text" wire:model="artikelForm.rental_price" class="{{ $input }}" /></div>
                    <div><label class="block {{ $label }} mb-1">Pfand €</label>
                        <input type="text" wire:model="artikelForm.pfand" class="{{ $input }}" /></div>
                </div>
                <div class="mt-3"><label class="block {{ $label }} mb-1">Notiz</label>
                    <textarea wire:model="artikelForm.note" rows="2" class="{{ $input }}"></textarea></div>
            </x-foodalchemist::modal-section>
            <x-slot:footer>
                <button type="button" @click="$dispatch('modal.close', { name: 'g-artikel' })" class="{{ $btnGhost }}">Abbrechen</button>
                <button type="button" wire:click="artikelSpeichern" class="{{ $btnPrimary }}">Speichern</button>
            </x-slot:footer>
        </x-foodalchemist::modal>
    </x-ui-page-container>
</x-ui-page>
