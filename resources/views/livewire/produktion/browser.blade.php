{{-- Spec 18 — Produktion: Browser-Liste der Produktionsaufträge --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Produktion" icon="heroicon-o-clipboard-document-list" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Produktion'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" width="w-72">
            <div class="p-3 space-y-2">
                <input type="search" wire:model.live.debounce.300ms="suche" placeholder="Name/Anlass suchen …" class="{{ $input }}" data-produktion-suche />
                <select wire:model.live="statusFilter" class="{{ $input }}">
                    <option value="">Alle Status</option>
                    @foreach($statusFaelle as $fall)
                        <option value="{{ $fall->value }}">{{ $fall->label() }}</option>
                    @endforeach
                </select>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="{{ $label }}">von</label>
                        <input type="date" wire:model.live="von" class="{{ $input }}" />
                    </div>
                    <div>
                        <label class="{{ $label }}">bis</label>
                        <input type="date" wire:model.live="bis" class="{{ $input }}" />
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Rollout: Detail-Panel ist in den Editor gemergt (Tab „Einkauf & Status"). Zeilen-Klick
         öffnet direkt den Fullscreen-Editor — kein separates Detail-Panel mehr (wie Rezept/GP). --}}
    <livewire:foodalchemist.produktion.editor />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="flex items-center justify-between pt-1">
            <button type="button" wire:click="neuerAuftrag" class="{{ $btnPrimary }}" data-produktion-anlegen>+ Neuer Produktionsauftrag</button>
        </div>

        <div class="relative overflow-hidden {{ $card }}" data-produktion-tabelle>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900">Produktionsaufträge</h3>
                <span class="{{ $label }}">{{ number_format($auftraege->count(), 0, ',', '.') }} Treffer</span>
            </div>
            <div class="max-h-[70vh] overflow-auto">
                <table class="{{ $table }}">
                    <thead><tr class="text-left">
                        <th class="{{ $th }} sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Name</th>
                        <th class="{{ $th }} w-full sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Ziele</th>
                        <th class="{{ $th }} whitespace-nowrap sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Ansätze / Port.</th>
                        <th class="{{ $th }} sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Datum</th>
                        <th class="{{ $th }} sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Status</th>
                        <th class="{{ $th }} sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Einkauf</th>
                    </tr></thead>
                    <tbody>
                        @php($einkaufPill = ['keine' => ['—', $variantPill['secondary'] ?? ''], 'offen' => ['offen', $variantPill['warning'] ?? ''], 'versendet' => ['versendet', $variantPill['success'] ?? '']])
                        @forelse($auftraege as $a)
                            @php($ziele = collect($a->targets ?? [])->pluck('label')->filter()->values())
                            <x-foodalchemist::table-row wire:key="po-{{ $a->id }}" wire:click="$dispatch('produktion-editor.bearbeiten', { id: {{ $a->id }} })"
                                data-produktion-zeile="{{ $a->id }}">
                                <td class="{{ $td }} font-medium text-gray-900 whitespace-nowrap">
                                    {{ $a->name ?: $a->reference ?: '—' }}
                                    @if($a->reference && $a->name && $a->reference !== $a->name)<span class="block text-[11px] font-normal text-gray-500">{{ $a->reference }}</span>@endif
                                </td>
                                <td class="{{ $td }} text-gray-600">
                                    @if($ziele->isEmpty())
                                        <span class="text-gray-400">—</span>
                                    @else
                                        <span class="text-[12px]">{{ $ziele->take(2)->implode(' · ') }}</span>@if($ziele->count() > 2)<span class="text-[11px] text-gray-400"> +{{ $ziele->count() - 2 }}</span>@endif
                                    @endif
                                </td>
                                <td class="{{ $td }} whitespace-nowrap tabular-nums text-gray-600">
                                    {{ rtrim(rtrim(number_format((float) $a->lines->sum('ansaetze'), 2, ',', '.'), '0'), ',') ?: '0' }}
                                    <span class="text-gray-400">/</span>
                                    {{ (int) $a->lines->sum('portionen') }}
                                </td>
                                <td class="{{ $td }} whitespace-nowrap tabular-nums">{{ $a->production_date->format('d.m.Y') }}</td>
                                <td class="{{ $td }}"><span class="{{ $pill }} font-medium {{ $variantPill[$a->status->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $a->status->label() }}</span></td>
                                <td class="{{ $td }}">
                                    @php($ind = $einkaufPill[$indikatoren[$a->id] ?? 'keine'] ?? $einkaufPill['keine'])
                                    <span class="{{ $pill }} font-medium {{ $ind[1] }}" data-einkauf-indikator="{{ $indikatoren[$a->id] ?? 'keine' }}">{{ $ind[0] }}</span>
                                </td>
                            </x-foodalchemist::table-row>
                        @empty
                            <tr><td colspan="6" class="px-5 py-10 text-center text-gray-500">Keine Produktionsaufträge. „+ Neuer Produktionsauftrag" oben.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </x-ui-page-container>
</x-ui-page>
