{{-- Kopfzeile der eigenständigen Hülle. Ersetzt x-ui-page-navbar (das die Plattform-Modulleiste rendert). --}}
@props([
    'title' => '',
    'icon' => null,
])

<header class="sticky top-0 z-40 h-14 shrink-0 flex items-center justify-between gap-4 px-6 bg-[var(--fa-surface)] border-b border-[var(--fa-line)]">
    <div class="flex items-center gap-2.5 min-w-0">
        @if($icon)
            @svg($icon, 'w-[18px] h-[18px] shrink-0 text-gray-500')
        @endif
        <h1 class="text-[15px] font-semibold text-gray-900 truncate">{{ $title }}</h1>
        {{ $slot }}
    </div>
    <div class="flex items-center gap-4 shrink-0">
        @livewire('foodalchemist.active-outlet-bar')
    </div>
</header>
