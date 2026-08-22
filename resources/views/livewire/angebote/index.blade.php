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

                <div class="space-y-1 pt-2 border-t border-black/5">
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

    {{-- Editor (Fullscreen, pro Angebot) statt Detail-Panel — geöffnet per angebot-editor.bearbeiten --}}
    <livewire:foodalchemist.angebote.editor />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            {{-- Spec 28: eigener Scroll-Kasten, damit der Tabellenkopf kleben kann --}}
            <div class="max-h-[70vh] overflow-auto">
            <table class="{{ $table }}">
                <thead>
                    <tr>
                        <th class="{{ $th }} w-full text-left sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Name</th>
                        <th class="{{ $th }} text-left sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Status</th>
                        <th class="{{ $th }} text-left sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Anlass</th>
                        <th class="{{ $th }} text-right sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Pax</th>
                        <th class="{{ $th }} text-left sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Datum</th>
                        <th class="{{ $th }} text-right sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Gesamt €</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $it)
                        <x-foodalchemist::table-row wire:key="ang-{{ $it->id }}" wire:click="waehle({{ $it->id }})" data-angebot-zeile="{{ $it->id }}">
                            <td class="{{ $td }} font-medium text-gray-900">{{ $it->name }}</td>
                            <td class="{{ $td }}">
                                <span class="{{ $pill }} {{ $variantPill[$it->status->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $it->status->label() }}</span>
                            </td>
                            <td class="{{ $td }} text-gray-600">{{ $it->occasion ?: '—' }}</td>
                            <td class="{{ $td }} text-right tabular-nums text-gray-600">{{ $it->personen ?? '—' }}</td>
                            <td class="{{ $td }} text-gray-600">{{ $it->event_date ? $it->event_date->format('d.m.Y') : '—' }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $it->total_price !== null ? number_format((float) $it->total_price, 2, ',', '.') . ' €' : '—' }}</td>
                        </x-foodalchemist::table-row>
                    @empty
                        <tr wire:key="ang-empty"><td colspan="6" class="px-3 py-10 text-center text-sm text-gray-500">Keine Angebote. Oben „+ Neue Anfrage".</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
        <div>{{ $items->links() }}</div>
    </x-ui-page-container>

    {{-- #380: Concepter-Editor wiederverwendet — bearbeitet angebots-lokale Menü-Entwürfe
         (öffnet via concepter-editor.oeffnen aus dem Angebote-Editor). Gleiche
         Einbettung wie im Concepter-Browser, damit die Slot-Engine identisch läuft. --}}
    <livewire:foodalchemist.concepter.editor />
    <livewire:foodalchemist.recipes.recipe-modal />
    <livewire:foodalchemist.verkauf.vk-modal />
</x-ui-page>
