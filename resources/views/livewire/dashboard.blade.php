{{--
    Dashboard View — Linear/Raycast Design
    Custom Design-System Showcase

    Shell-Komponenten (unverändert):
    x-ui-page, x-ui-page-navbar, x-ui-page-actionbar, x-ui-page-container, x-ui-page-sidebar

    Content-Bereich: Eigenes Design (kein x-ui-panel, x-ui-button etc.)
--}}

<x-ui-page>
    {{-- Navbar --}}
    <x-slot name="navbar">
        <x-ui-page-navbar title="" />
    </x-slot>

    {{-- Actionbar --}}
    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
        ]" />
    </x-slot>

    {{-- Hauptinhalt --}}
    <x-ui-page-container>
        <div class="space-y-8">

            {{-- Hero Section --}}
            <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-violet-500/10 via-indigo-500/5 to-transparent dark:from-violet-500/20 dark:via-indigo-500/10 dark:to-transparent border border-white/20 dark:border-white/10 shadow-sm shadow-black/5 p-8">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/60 to-transparent"></div>
                <div class="absolute -top-24 -right-24 w-64 h-64 bg-violet-500/10 rounded-full blur-3xl"></div>
                <div class="relative">
                    <div class="inline-flex items-center gap-2 px-3 py-1 text-xs font-medium text-violet-600 dark:text-violet-400 bg-violet-500/10 rounded-full mb-4">
                        @svg('heroicon-o-sparkles', 'w-3.5 h-3.5')
                        <span>Design System Showcase</span>
                    </div>
                    <h1 class="text-2xl font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-2">
                        Food Alchemist
                    </h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 max-w-lg">
                        Eigene visuelle Identität pro Modul. Die Page-Shell bleibt shared, der Content-Bereich ist custom mit Linear/Raycast-Personality.
                    </p>
                </div>
            </div>

            {{-- Stat Cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {{-- Stat 1 --}}
                <div class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm shadow-black/5 p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Items</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-violet-500/10">
                            @svg('heroicon-o-cube', 'w-4 h-4 text-violet-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">0</div>
                    <div class="text-xs text-gray-400 mt-1">Gesamt</div>
                </div>

                {{-- Stat 2 --}}
                <div class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm shadow-black/5 p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Aktiv</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-500/10">
                            @svg('heroicon-o-check-circle', 'w-4 h-4 text-emerald-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">0</div>
                    <div class="text-xs text-gray-400 mt-1">Aktive Elemente</div>
                </div>

                {{-- Stat 3 --}}
                <div class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm shadow-black/5 p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-amber-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Pending</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-amber-500/10">
                            @svg('heroicon-o-clock', 'w-4 h-4 text-amber-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">0</div>
                    <div class="text-xs text-gray-400 mt-1">In Bearbeitung</div>
                </div>

                {{-- Stat 4 --}}
                <div class="group relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm shadow-black/5 p-5 hover:-translate-y-0.5 hover:shadow-md transition-all duration-150">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-sky-500/50 to-transparent"></div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-gray-400">Templates</span>
                        <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-sky-500/10">
                            @svg('heroicon-o-document-duplicate', 'w-4 h-4 text-sky-500')
                        </div>
                    </div>
                    <div class="text-3xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">0</div>
                    <div class="text-xs text-gray-400 mt-1">Vorlagen</div>
                </div>
            </div>

            {{-- Content Grid --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                {{-- Recent Activity --}}
                <div class="lg:col-span-2 relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm shadow-black/5">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/30 to-transparent"></div>
                    <div class="px-5 py-4 border-b border-black/5 dark:border-white/5">
                        <h2 class="text-sm font-medium tracking-tight text-gray-900 dark:text-gray-100">Letzte Aktivitäten</h2>
                    </div>
                    <div class="p-5">
                        <div class="space-y-3">
                            @for($i = 0; $i < 3; $i++)
                            <div class="flex items-center gap-3 p-3 rounded-lg bg-black/[0.02] dark:bg-white/[0.02] hover:bg-black/[0.04] dark:hover:bg-white/[0.04] transition-colors duration-150">
                                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-gradient-to-br from-violet-500/20 to-indigo-500/20 flex items-center justify-center">
                                    @svg('heroicon-o-bolt', 'w-4 h-4 text-violet-500')
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate">Placeholder Aktivität {{ $i + 1 }}</div>
                                    <div class="text-xs text-gray-400">vor {{ ($i + 1) * 5 }} Minuten</div>
                                </div>
                                <div class="flex-shrink-0">
                                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium text-violet-600 dark:text-violet-400 bg-violet-500/10 rounded-full">neu</span>
                                </div>
                            </div>
                            @endfor
                        </div>
                        <div class="mt-4 text-center">
                            <span class="text-xs text-gray-400">Keine weiteren Aktivitäten</span>
                        </div>
                    </div>
                </div>

                {{-- Quick Actions --}}
                <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm shadow-black/5">
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-indigo-500/30 to-transparent"></div>
                    <div class="px-5 py-4 border-b border-black/5 dark:border-white/5">
                        <h2 class="text-sm font-medium tracking-tight text-gray-900 dark:text-gray-100">Schnellzugriff</h2>
                    </div>
                    <div class="p-4 space-y-2">
                        <a href="{{ route('foodalchemist.test') }}" wire:navigate class="flex items-center gap-3 p-3 rounded-lg hover:bg-black/[0.03] dark:hover:bg-white/[0.03] transition-colors duration-150 group">
                            <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-gradient-to-br from-violet-500/10 to-indigo-500/10 group-hover:from-violet-500/20 group-hover:to-indigo-500/20 transition-colors duration-150">
                                @svg('heroicon-o-beaker', 'w-4.5 h-4.5 text-violet-500')
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-700 dark:text-gray-300">Test-Seite</div>
                                <div class="text-xs text-gray-400">UI-Komponenten Demo</div>
                            </div>
                            @svg('heroicon-o-chevron-right', 'w-4 h-4 text-gray-300 dark:text-gray-600 ml-auto opacity-0 group-hover:opacity-100 transition-opacity duration-150')
                        </a>
                        <div class="flex items-center gap-3 p-3 rounded-lg opacity-50 cursor-not-allowed">
                            <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-gray-500/10">
                                @svg('heroicon-o-cog-6-tooth', 'w-4.5 h-4.5 text-gray-400')
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-700 dark:text-gray-300">Einstellungen</div>
                                <div class="text-xs text-gray-400">Kommt bald</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-lg opacity-50 cursor-not-allowed">
                            <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-gray-500/10">
                                @svg('heroicon-o-chart-bar', 'w-4.5 h-4.5 text-gray-400')
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-700 dark:text-gray-300">Analytics</div>
                                <div class="text-xs text-gray-400">Kommt bald</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </x-ui-page-container>

    {{-- Linke Sidebar --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Schnellzugriff" width="w-80" :defaultOpen="true">
            <div class="p-5 space-y-5">
                {{-- Quick Stats --}}
                <div>
                    <h3 class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-3">Statistiken</h3>
                    <div class="space-y-2">
                        <div class="p-3 rounded-lg bg-black/[0.02] dark:bg-white/[0.03]">
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-400">Items</span>
                                <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">0</span>
                            </div>
                        </div>
                        <div class="p-3 rounded-lg bg-black/[0.02] dark:bg-white/[0.03]">
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-400">Aktiv</span>
                                <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Status --}}
                <div>
                    <h3 class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-3">Status</h3>
                    <div class="flex items-center gap-2 p-3 rounded-lg bg-emerald-500/5">
                        <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                        <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">Alle Systeme aktiv</span>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Rechte Sidebar --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktivitäten" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-5 space-y-4">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-400">Timeline</div>
                <div class="space-y-3">
                    <div class="flex gap-3">
                        <div class="flex flex-col items-center">
                            <div class="w-2 h-2 rounded-full bg-violet-500 mt-1.5"></div>
                            <div class="w-px h-full bg-black/5 dark:bg-white/10"></div>
                        </div>
                        <div class="pb-4">
                            <div class="text-sm font-medium text-gray-700 dark:text-gray-300">Dashboard geladen</div>
                            <div class="text-xs text-gray-400">vor 1 Minute</div>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <div class="flex flex-col items-center">
                            <div class="w-2 h-2 rounded-full bg-gray-300 dark:bg-gray-600 mt-1.5"></div>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-700 dark:text-gray-300">Modul initialisiert</div>
                            <div class="text-xs text-gray-400">vor 2 Minuten</div>
                        </div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>
</x-ui-page>
