{{-- Kopfzeile einer FA-Seite.
     Eigenständig (FA ist die Plattform): eigene Kopfzeile mit Titel und Betriebswahl.
     Plattform-Modus (demo/office, Support\FaShell): die Core-Kopfzeile wie bei allen Modulen
     (Modul-Leiste, Team, Benutzer) — plus das vom Modul ausgelieferte FA-CSS. Der <link> steht im
     Seiteninhalt: er gilt nur, solange eine FA-Seite angezeigt wird (wire:navigate tauscht ihn mit aus). --}}
@props([
    'title' => '',
    'icon' => null,
])

@if(\Platform\FoodAlchemist\Support\FaShell::eigenstaendig())
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
@else
    @php
        $faCss = \Platform\FoodAlchemist\Support\FaShell::cssUrl();
    @endphp
    @if($faCss)
        <link rel="stylesheet" href="{{ $faCss }}" data-fa-modul-css>
    @endif
    <x-foodalchemist::shell.fa-menu-script />
    {{-- data-fa-core: Grenze des FA-Designs — die Core-Kopfzeile behält Host-Farben, -Schrift und -Regeln (build-css.mjs) --}}
    <div data-fa-core class="contents">
        <x-ui-page-navbar :title="$title" :icon="$icon" />
    </div>
@endif
