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

    <x-slot name="activity">
        <x-ui-page-sidebar title="Detail" width="w-96" :maxWidth="760" storeKey="activityOpen" side="right">
            <livewire:foodalchemist.produktion.detail-panel :order-id="$orderId" />
        </x-ui-page-sidebar>
    </x-slot>

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
            <div class="overflow-x-auto">
                <table class="{{ $table }}">
                    <thead><tr class="text-left">
                        <th class="{{ $th }} w-full">Name</th>
                        <th class="{{ $th }}">Produktionsdatum</th>
                        <th class="{{ $th }}">Status</th>
                    </tr></thead>
                    <tbody>
                        @forelse($auftraege as $a)
                            <tr wire:key="po-{{ $a->id }}" wire:click="waehle({{ $a->id }})"
                                x-data x-on:click="$store.ui?.mSet('activity', 'open', true)"
                                class="{{ $tr }} cursor-pointer {{ $orderId === $a->id ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : '' }}"
                                data-produktion-zeile="{{ $a->id }}">
                                <td class="{{ $td }} font-medium text-gray-900">
                                    {{ $a->name ?: $a->reference ?: '—' }}
                                    @if($a->reference && $a->name && $a->reference !== $a->name)<span class="block text-[11px] font-normal text-gray-500">{{ $a->reference }}</span>@endif
                                </td>
                                <td class="{{ $td }} whitespace-nowrap tabular-nums">{{ $a->production_date->format('d.m.Y') }}</td>
                                <td class="{{ $td }}"><span class="{{ $pill }} font-medium {{ $variantPill[$a->status->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $a->status->label() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-5 py-10 text-center text-gray-500">Keine Produktionsaufträge. „+ Neuer Produktionsauftrag" oben.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </x-ui-page-container>
</x-ui-page>
