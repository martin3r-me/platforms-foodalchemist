{{-- M1-01: Settings-Gerüst — vertikale Sektions-Tabs (links), Sektion = eigene URL (V-17) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Einstellungen" icon="heroicon-o-cog-6-tooth" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Einstellungen'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-5">

        @if($istKindTeam)
            <div class="{{ $card }} p-4 border-amber-500/20">
                <p class="text-xs text-amber-600 dark:text-amber-400">
                    Du siehst den geerbten Katalog deines Eltern-Teams — editierbar ist nur, was deinem Team gehört (D1).
                    Einkaufs- und Kalkulations-Einstellungen entscheidet dein Team selbst.
                </p>
            </div>
        @endif

        <div class="flex gap-4 items-start">
            {{-- vertikale Sektions-Navigation --}}
            <nav class="w-72 shrink-0 {{ $card }} p-3 space-y-1" data-settings-nav>
                @foreach($sektionen as $key => $meta)
                    <a href="{{ route('foodalchemist.einstellungen', ['sektion' => $key]) }}" wire:navigate.hover
                       class="block px-3 py-2 rounded-lg transition-all duration-150 {{ $sektion === $key
                            ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300'
                            : 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                        <span class="block text-xs font-medium">{{ $meta['label'] }}</span>
                        <span class="block text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{{ $meta['hint'] }}</span>
                    </a>
                @endforeach
            </nav>

            {{-- aktive Sektion (eigene Livewire-Komponente, isolierter State) --}}
            <div class="flex-1 min-w-0" data-settings-sektion="{{ $sektion }}">
                @livewire('foodalchemist.settings.' . $sektion, key('sektion-' . $sektion))
            </div>
        </div>

    </x-ui-page-container>
</x-ui-page>
