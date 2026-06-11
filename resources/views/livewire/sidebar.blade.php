{{--
    Modul-Sidebar — rendert dynamisch aus config('foodalchemist.sidebar')
    (EINE Quelle; M2-13-Abnahme-Fund: die Template-Version war hartkodiert,
    Lieferanten/Einstellungen fehlten deshalb in der Navigation).
--}}
@php($gruppen = config('foodalchemist.sidebar', []))

<div>
    {{-- Modul Header --}}
    <div x-show="!collapsed" class="p-3 text-sm italic text-[var(--ui-secondary)] uppercase border-b border-[var(--ui-border)] mb-2">
        Food Alchemist
    </div>

    @foreach($gruppen as $gruppe)
        <x-ui-sidebar-list :label="$gruppe['group'] ?? ''">
            @foreach($gruppe['items'] ?? [] as $item)
                <x-ui-sidebar-item :href="route($item['route'])">
                    @svg($item['icon'] ?? 'heroicon-o-cube', 'w-4 h-4 text-[var(--ui-secondary)]')
                    <span class="ml-2 text-sm">{{ $item['label'] }}</span>
                </x-ui-sidebar-item>
            @endforeach
        </x-ui-sidebar-list>
    @endforeach

    {{-- Collapsed: Icons-only --}}
    <div x-show="collapsed" class="px-2 py-2 border-b border-[var(--ui-border)]">
        <div class="flex flex-col gap-2">
            @foreach($gruppen as $gruppe)
                @foreach($gruppe['items'] ?? [] as $item)
                    <a href="{{ route($item['route']) }}" wire:navigate title="{{ $item['label'] }}"
                       class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                        @svg($item['icon'] ?? 'heroicon-o-cube', 'w-5 h-5')
                    </a>
                @endforeach
            @endforeach
        </div>
    </div>
</div>
