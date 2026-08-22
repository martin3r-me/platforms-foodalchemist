{{-- Format-Modul (Phase B): Top-Level-Browser „Formate" (3-Panel wie Concepter) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusLabel = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiv'])
@php($statusPill = ['draft' => $variantPill['secondary'], 'active' => $variantPill['success'], 'archiviert' => $variantPill['warning']])
@php($originLabel = ['eigen' => 'Eigen', 'gruppe' => 'Gruppe', 'kunde' => 'Kunde'])

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Formate" icon="heroicon-o-rectangle-group" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Formate'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Formate" width="w-80">
            <div class="p-3 space-y-3">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Format suchen …" class="{{ $input }}" />

                <div class="space-y-0.5 pt-2 border-t border-black/5">
                    <span class="{{ $label }}">Status</span>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" wire:click="waehleStatus('')" class="{{ $pill }} {{ $statusFilter === '' ? $variantPill['primary'] : $variantPill['secondary'] }}">Alle</button>
                        @foreach($statusLabel as $val => $lbl)
                            <button type="button" wire:key="fst-{{ $val }}" wire:click="waehleStatus('{{ $val }}')"
                                    class="{{ $pill }} {{ $statusFilter === $val ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $lbl }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="space-y-0.5 pt-2 border-t border-black/5">
                    <span class="{{ $label }}">Herkunft</span>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" wire:click="waehleOrigin('')" class="{{ $pill }} {{ $originFilter === '' ? $variantPill['primary'] : $variantPill['secondary'] }}">Alle</button>
                        @foreach($originLabel as $val => $lbl)
                            <button type="button" wire:key="fori-{{ $val }}" wire:click="waehleOrigin('{{ $val }}')"
                                    class="{{ $pill }} {{ $originFilter === $val ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $lbl }}</button>
                        @endforeach
                    </div>
                </div>

                <button type="button" wire:click="neu" class="{{ $btnPrimary }} w-full justify-center">+ Neues Format</button>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-96" :maxWidth="640" scope="activity_formate" side="right">
            <livewire:foodalchemist.formate.detail-panel />
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <div class="max-h-[70vh] overflow-auto">
            <table class="{{ $table }}">
                <thead>
                    <tr>
                        <th class="{{ $th }} w-full text-left sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Name</th>
                        <th class="{{ $th }} text-left sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Konsumentenbez.</th>
                        <th class="{{ $th }} text-left sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Herkunft</th>
                        <th class="{{ $th }} text-left sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Status</th>
                        <th class="{{ $th }} text-right sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Editionen</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $it)
                        <x-foodalchemist::table-row :active="$selectedId === $it->id" wire:key="frow-{{ $it->id }}" wire:click="waehle({{ $it->id }})"
                            x-data x-on:click="$store.ui?.mSet('activity_formate', 'open', true)">
                            <td wire:click.stop="bearbeite({{ $it->id }})" class="{{ $td }} font-medium text-gray-900 hover:text-violet-600 cursor-pointer" title="Editor öffnen">
                                {{ $it->name }}
                            </td>
                            <td class="{{ $td }} text-gray-600">{{ $it->consumer_name ?: '—' }}</td>
                            <td class="{{ $td }}">
                                @if($it->origin === 'kunde')
                                    <span class="{{ $pill }} {{ $variantPill['warning'] }}" title="Kunden-IP — nicht für andere adaptieren">Kunde 🔒</span>
                                @elseif($it->origin)
                                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $originLabel[$it->origin] ?? $it->origin }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="{{ $td }} whitespace-nowrap" wire:click.stop @click.stop>
                                <select wire:key="fstsel-{{ $it->id }}-{{ $it->status }}" wire:change="statusSetzen({{ $it->id }}, $event.target.value)"
                                        class="{{ $pill }} font-medium {{ $statusPill[$it->status] ?? $variantPill['secondary'] }} border-0 cursor-pointer focus:ring-1 focus:ring-violet-400 pr-6">
                                    @foreach($statusLabel as $val => $lbl)
                                        <option value="{{ $val }}" @selected($it->status === $val)>{{ $lbl }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="{{ $td }} text-right tabular-nums text-gray-600">{{ $it->editions_count }}</td>
                        </x-foodalchemist::table-row>
                    @empty
                        <tr><td colspan="5" class="px-3 py-10 text-center text-sm text-gray-500">Keine Formate. Oben „+ Neues Format".</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
        <div>{{ $items->links() }}</div>
    </x-ui-page-container>

    {{-- Voll-Editor-Modal — auf Seitenebene, öffnet via formate-editor.oeffnen --}}
    <livewire:foodalchemist.formate.editor />
</x-ui-page>
