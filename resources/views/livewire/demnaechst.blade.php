{{-- R7: «In Planung» — Phase-2-Domänen als Vorschau (Scope: docs/14_ROADMAP_PHASE2.md) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="In Planung" icon="heroicon-o-light-bulb" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Kommende Domänen</h3>
            <p class="text-xs text-gray-400 mt-0.5">Entschieden 2026-06-12: erst die Basis fertig (M9), dann Foodbook (M10), dann Brainstorming je Domäne. Der Chat-Assistent wurde verworfen.</p>
        </div>

        <div class="grid md:grid-cols-2 gap-3" data-demnaechst-liste>
            @foreach($domaenen as $d)
                <div class="relative overflow-hidden {{ $card }} px-4 py-3" wire:key="dom-{{ $loop->index }}">
                    <div class="{{ $cardAccent }}"></div>
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $d['icon'] }} {{ $d['name'] }}</p>
                        <span class="{{ $pill }} {{ str_starts_with($d['status'], 'M10') ? $variantPill['info'] : $variantPill['secondary'] }} shrink-0">{{ $d['status'] }}</span>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 leading-relaxed">{{ $d['idee'] }}</p>
                </div>
            @endforeach
        </div>

        <p class="text-xs text-gray-400">Vollständiger Plan: <code class="font-mono">docs/14_ROADMAP_PHASE2.md</code> im Modul-Repo.</p>
    </x-ui-page-container>
</x-ui-page>
