{{-- #380: Angebote-Browser (am Concepter orientiert) — Anfrage → Angebot, kundengebunden --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Angebote" icon="heroicon-o-document-text" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Angebote'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Angebote" width="w-80">
            <div class="p-3 space-y-3">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Angebot/Anfrage suchen …" class="{{ $input }}" />

                <div class="space-y-1 pt-2 border-t border-black/5 dark:border-white/10">
                    <span class="{{ $label }}">Status</span>
                    <div class="flex flex-wrap gap-1">
                        <button type="button" wire:click="waehleStatus('')" class="{{ $pill }} {{ $statusFilter === '' ? $variantPill['primary'] : $variantPill['secondary'] }}">Alle</button>
                        @foreach($statusWerte as $sw)
                            <button type="button" wire:key="st-{{ $sw['value'] }}" wire:click="waehleStatus('{{ $sw['value'] }}')"
                                    class="{{ $pill }} {{ $statusFilter === $sw['value'] ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $sw['label'] }}</button>
                        @endforeach
                    </div>
                </div>

                <button type="button" wire:click="neu" class="{{ $btnPrimary }} w-full justify-center">+ Neue Anfrage</button>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Detail" width="w-96" :maxWidth="640" storeKey="activityOpen" side="right">
            <livewire:foodalchemist.angebote.detail-panel />
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <table class="{{ $table }}">
                <thead>
                    <tr>
                        <th class="{{ $th }} w-full text-left">Name</th>
                        <th class="{{ $th }} text-left">Status</th>
                        <th class="{{ $th }} text-left">Anlass</th>
                        <th class="{{ $th }} text-right">Pax</th>
                        <th class="{{ $th }} text-left">Datum</th>
                        <th class="{{ $th }} text-right">Gesamt €</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $it)
                        <tr wire:key="ang-{{ $it->id }}" wire:click="waehle({{ $it->id }})"
                            x-data x-on:click="$store.ui?.mSet('activity', 'open', true)"
                            class="{{ $tr }} cursor-pointer {{ $selectedId === $it->id ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : '' }}">
                            <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100">{{ $it->name }}</td>
                            <td class="{{ $td }}">
                                <span class="{{ $pill }} {{ $variantPill[$it->status->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $it->status->label() }}</span>
                            </td>
                            <td class="{{ $td }} text-gray-500">{{ $it->anlass ?: '—' }}</td>
                            <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ $it->personen ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-500">{{ $it->event_datum ? $it->event_datum->format('d.m.Y') : '—' }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $it->gesamtpreis !== null ? number_format((float) $it->gesamtpreis, 2, ',', '.') . ' €' : '—' }}</td>
                        </tr>
                    @empty
                        <tr wire:key="ang-empty"><td colspan="6" class="px-3 py-10 text-center text-sm text-gray-400">Keine Angebote. Oben „+ Neue Anfrage".</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $items->links() }}</div>
    </x-ui-page-container>

    {{-- #380: Concepter-Editor wiederverwendet — bearbeitet angebots-lokale Menü-Entwürfe
         (öffnet via concepter-editor.oeffnen aus dem Angebote-Detail-Panel). Gleiche
         Einbettung wie im Concepter-Browser, damit die Slot-Engine identisch läuft. --}}
    <livewire:foodalchemist.concepter.editor />
    <livewire:foodalchemist.recipes.recipe-modal />
    <livewire:foodalchemist.verkauf.vk-modal />
</x-ui-page>
