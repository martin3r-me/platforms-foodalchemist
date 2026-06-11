{{-- M2-06/07/08: LA-Editor-Modal (P-2/P-6) — read-only für Kette, Edit nur Besitzer --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div>
    <x-foodalchemist::modal name="item-modal" :title="$item?->designation ?? 'Artikel'">
        @if($item)
            @if($darfEdit)
                <x-slot:actions>
                    <button type="button" wire:click="speichern" class="inline-flex items-center gap-2 px-4 py-1.5 text-sm font-medium text-white bg-gradient-to-r from-violet-500 to-indigo-500 rounded-lg shadow-sm shadow-violet-500/25 hover:shadow-md transition-all duration-150">Speichern</button>
                </x-slot:actions>
            @endif

            {{-- Modal-Kopf: EK + Vergleichspreis (M2-05) + Lieferant + GP --}}
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 -mt-1" data-modal-kopf>
                <span class="text-sm text-gray-500">{{ $item->supplier?->name }}</span>
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                    EK: {{ $aktiverPreis?->price !== null ? number_format((float) $aktiverPreis->price, 2, ',', '.') . ' €' : '—' }}
                </span>
                <span class="text-sm text-gray-500" data-vergleichspreis-kopf>
                    {{ $vergleichspreis ? number_format($vergleichspreis['wert'], 2, ',', '.') . ' ' . $vergleichspreis['einheit'] : 'kein Vergleichspreis' }}
                </span>
                @if($item->structure?->gp)
                    <span class="{{ $pill }} {{ $variantPill['primary'] }}">{{ $item->structure->gp->name }}</span>
                @else
                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">nicht gemappt</span>
                @endif
                @unless($darfEdit)<span class="{{ $pill }} {{ $variantPill['secondary'] }}" title="Geerbter Katalog (D1)">read-only</span>@endunless
            </div>
            @if($fehler)<p class="text-sm text-red-600 dark:text-red-400 mt-2">{{ $fehler }}</p>@endif

            <x-foodalchemist::modal-section title="Stammdaten">
                <div class="grid grid-cols-2 gap-3">
                    @foreach([['designation', 'Bezeichnung'], ['article_number', 'Artikel-Nr.'], ['brand', 'Marke'], ['manufacturer', 'Hersteller'], ['origin', 'Herkunft'], ['marketing_name', 'Marketing-Name']] as [$feld, $lbl])
                        <div>
                            <label class="block {{ $label }} mb-1">{{ $lbl }}</label>
                            <input type="text" wire:model="stammdaten.{{ $feld }}" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" />
                        </div>
                    @endforeach
                </div>
            </x-foodalchemist::modal-section>

            <x-foodalchemist::modal-section title="Verpackung & Mengen">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div><label class="block {{ $label }} mb-1">Gebinde-Menge (qty)</label>
                        <input type="text" wire:model="verpackung.qty" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" /></div>
                    <div><label class="block {{ $label }} mb-1">Kalk-Einheit</label>
                        <select wire:model="verpackung.unit_code" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60">
                            <option value="">—</option>
                            @foreach(['kg', 'l', 'Stk'] as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach
                        </select></div>
                    <div><label class="block {{ $label }} mb-1">Verpackungseinheit</label>
                        <input type="text" wire:model="verpackung.packaging_unit" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" /></div>
                    <div><label class="block {{ $label }} mb-1">Bestelleinheit</label>
                        <input type="text" wire:model="verpackung.ordering_unit" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60" /></div>
                </div>
            </x-foodalchemist::modal-section>

            <x-foodalchemist::modal-section title="Eigenschaften">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    @foreach([['is_organic', 'Bio'], ['is_vegan', 'Vegan'], ['is_vegetarian', 'Vegetarisch'], ['is_alcohol', 'Alkohol']] as [$feld, $lbl])
                        <div><label class="block {{ $label }} mb-1">{{ $lbl }}</label>
                            <select wire:model="eigenschaften.{{ $feld }}" @unless($darfEdit) disabled @endunless class="{{ $input }} disabled:opacity-60">
                                <option value="">unbekannt</option>
                                <option value="1">ja</option>
                                <option value="0">nein</option>
                            </select></div>
                    @endforeach
                </div>
            </x-foodalchemist::modal-section>

            <x-foodalchemist::modal-section title="Preise">
                @if($darfEdit)
                    <div class="flex items-end gap-2 mb-3" data-preis-neu>
                        <div><label class="block {{ $label }} mb-1">Neuer Preis (€, netto)</label>
                            <input type="text" wire:model="preisNeu.preis" placeholder="z. B. 47,50" class="{{ $input }} !w-36" /></div>
                        <div><label class="block {{ $label }} mb-1">Status</label>
                            <select wire:model="preisNeu.status" class="{{ $input }} !w-40">
                                <option value="0">0 · Standard-EK</option>
                                <option value="2">2 · Aktion</option>
                            </select></div>
                        <button type="button" wire:click="preisAnlegen" class="{{ $btnGhostXs }} !px-3 !py-2 text-violet-600 dark:text-violet-400">+ Neuer Preis (schließt Vorgänger)</button>
                    </div>
                @endif
                <table class="{{ $table }}" data-preis-historie>
                    <thead><tr class="text-left">
                        @foreach(['Preis', 'Kategorie', 'Status', 'gültig bis', 'angelegt', ''] as $head)<th class="{{ $th }} !px-2">{{ $head }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @forelse($historie as $p)
                            <tr wire:key="preis-{{ $p->id }}" class="{{ $tr }}">
                                <td class="{{ $td }} !px-2 text-gray-900 dark:text-gray-100">{{ $p->price !== null ? number_format((float) $p->price, 2, ',', '.') . ' €' : '—' }}</td>
                                <td class="{{ $td }} !px-2"><span class="{{ $pill }} {{ $p->kategorie->istAktiv() ? $variantPill['success'] : $variantPill['secondary'] }}">{{ $p->kategorie->label() }}</span></td>
                                <td class="{{ $td }} !px-2 text-gray-500">{{ $p->status ?? '—' }}</td>
                                <td class="{{ $td }} !px-2 text-gray-500">{{ $p->valid_to ? \Illuminate\Support\Carbon::parse($p->valid_to)->format('d.m.Y') : 'unbefristet' }}</td>
                                <td class="{{ $td }} !px-2 text-gray-500">{{ $p->creation_date ? \Illuminate\Support\Carbon::parse($p->creation_date)->format('d.m.Y') : '—' }}</td>
                                <td class="{{ $td }} !px-2 text-right">
                                    @if($darfEdit)
                                        <button type="button" wire:click="preisLoeschen({{ $p->id }})" wire:confirm="Preiszeile löschen?" class="{{ $btnGhostXs }} text-red-500">Löschen</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-2 py-6 text-center text-gray-400">Keine Preiszeilen.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-foodalchemist::modal-section>
        @endif
    </x-foodalchemist::modal>
</div>
