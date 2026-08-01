{{-- Bestellungen: Browser (Schienen-Liste + Filter + Neue Bestellung). Bearbeiten im Fullscreen-Editor. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusLabels = ['draft' => 'Entwurf', 'sent' => 'versendet', 'confirmed' => 'bestätigt', 'delivered' => 'geliefert', 'cancelled' => 'storniert'])

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Bestellungen" icon="heroicon-o-shopping-cart" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Bestellungen'],
        ]" />
    </x-slot>

    {{-- Fullscreen-Editor (pro Schiene), geöffnet per orders-editor.bearbeiten --}}
    <livewire:foodalchemist.orders.editor />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        @if($hinweis)<div class="{{ $sectionCard }} !bg-emerald-500/[0.06] !border-emerald-500/20 text-[12px] text-emerald-700">✓ {{ $hinweis }}</div>@endif
        @if($fehler)<div class="{{ $sectionCard }} !bg-rose-500/[0.06] !border-rose-500/20 text-[12px] text-rose-700">{{ $fehler }}</div>@endif

        {{-- Neue Bestellung + Filter --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="flex items-end gap-2">
                <div>
                    <span class="{{ $label }} block mb-1">Neue Bestellung</span>
                    <div class="flex gap-1">
                        <select wire:model="neuerLieferant" class="{{ $input }}">
                            <option value="">Lieferant…</option>
                            @foreach($alleLieferanten as $l)
                                <option value="{{ $l['id'] }}">{{ $l['name'] }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="neueBestellung" class="{{ $btnPrimary }} shrink-0" data-orders-neu>+ Anlegen</button>
                    </div>
                </div>
            </div>
            <div class="flex items-end gap-2 flex-wrap">
                <div>
                    <span class="{{ $label }} block mb-1">Status</span>
                    <div class="inline-flex flex-wrap rounded-lg bg-black/[0.03] p-0.5 text-xs">
                        <button type="button" wire:click="$set('statusFilter','')" class="px-2.5 py-1 rounded-md {{ $statusFilter === '' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">alle</button>
                        @foreach(['draft','sent','confirmed','delivered','cancelled'] as $s)
                            <button type="button" wire:click="$set('statusFilter','{{ $s }}')" class="px-2.5 py-1 rounded-md {{ $statusFilter === $s ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">{{ $statusLabels[$s] }}</button>
                        @endforeach
                    </div>
                </div>
                <div>
                    <span class="{{ $label }} block mb-1">Lieferant</span>
                    <select wire:model.live="supplierFilter" class="{{ $input }}">
                        <option value="">alle Lieferanten</option>
                        @foreach($lieferanten as $l)
                            <option value="{{ $l['id'] }}">{{ $l['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <span class="{{ $label }} block mb-1">Suche</span>
                    <input type="search" wire:model.live.debounce.300ms="suche" placeholder="Lieferant / Anlass…" class="{{ $input }}" />
                </div>
            </div>
        </div>

        {{-- Schienen-Liste --}}
        <div class="relative overflow-hidden {{ $card }}" data-orders-tabelle>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900">Schienen &amp; Bestellungen</h3>
                <span class="{{ $label }}">{{ number_format($liste->count(), 0, ',', '.') }} Treffer</span>
            </div>
            <div class="max-h-[70vh] overflow-auto">
                <table class="{{ $table }}">
                    <thead><tr class="text-left">
                        <th class="{{ $th }} sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Lieferant</th>
                        <th class="{{ $th }} w-full sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Anlass</th>
                        <th class="{{ $th }} text-right whitespace-nowrap sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Netto</th>
                        <th class="{{ $th }} sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Status</th>
                    </tr></thead>
                    <tbody>
                        @forelse($liste as $o)
                            <x-foodalchemist::table-row wire:key="ord-{{ $o['id'] }}" wire:click="oeffnen({{ $o['id'] }})" data-orders-zeile="{{ $o['id'] }}">
                                <td class="{{ $td }} font-medium text-gray-900 whitespace-nowrap">{{ $o['supplier'] }}</td>
                                <td class="{{ $td }} text-gray-600">{{ $o['reference'] ?: '—' }}</td>
                                <td class="{{ $td }} text-right whitespace-nowrap tabular-nums text-gray-700">{{ number_format($o['total_net'], 2, ',', '.') }} €</td>
                                <td class="{{ $td }}"><span class="{{ $pill }} font-medium {{ $variantPill[$o['status']->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $o['status']->label() }}</span></td>
                            </x-foodalchemist::table-row>
                        @empty
                            <tr><td colspan="4" class="px-5 py-10 text-center text-gray-500">Keine Bestellungen. „Neue Bestellung" oben oder Bedarf aus der Produktion übergeben.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </x-ui-page-container>
</x-ui-page>
