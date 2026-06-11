{{--
    Test View — Linear/Raycast Design
    UI-Komponenten Showcase mit eigenem Design-System

    Shell-Komponenten (unverändert):
    x-ui-page, x-ui-page-navbar, x-ui-page-actionbar, x-ui-page-container, x-ui-page-sidebar

    Content-Bereich: Custom Buttons, Inputs, Cards — kein x-ui-button etc.
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
            ['label' => 'Test'],
        ]" />
    </x-slot>

    {{-- Hauptinhalt --}}
    <x-ui-page-container>
        <div class="space-y-8">

            {{-- Page Header --}}
            <div>
                <h1 class="text-2xl font-medium tracking-tight text-gray-900 dark:text-gray-100">
                    UI Components
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Custom Design-System mit Linear/Raycast-Personality. Keine shared UI-Komponenten im Content.
                </p>
            </div>

            {{-- Section: Buttons --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm shadow-black/5">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/30 to-transparent"></div>
                <div class="px-5 py-4 border-b border-black/5 dark:border-white/5">
                    <h2 class="text-sm font-medium tracking-tight text-gray-900 dark:text-gray-100">Buttons</h2>
                    <p class="text-xs text-gray-400 mt-0.5">Gradient Primary, Ghost Secondary, Danger</p>
                </div>
                <div class="p-5 space-y-4">
                    {{-- Primary Buttons --}}
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-3">Primary</div>
                        <div class="flex flex-wrap gap-3">
                            <button class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-r from-violet-500 to-indigo-500 rounded-lg shadow-sm shadow-violet-500/25 hover:shadow-md hover:shadow-violet-500/30 hover:-translate-y-0.5 transition-all duration-150">
                                @svg('heroicon-o-plus', 'w-4 h-4')
                                Erstellen
                            </button>
                            <button class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-r from-violet-500 to-indigo-500 rounded-lg shadow-sm shadow-violet-500/25 hover:shadow-md hover:shadow-violet-500/30 transition-all duration-150">
                                Speichern
                            </button>
                            <button class="inline-flex items-center gap-2 px-3 py-1.5 text-xs font-medium text-white bg-gradient-to-r from-violet-500 to-indigo-500 rounded-md shadow-sm shadow-violet-500/25 hover:shadow-md hover:shadow-violet-500/30 transition-all duration-150">
                                Klein
                            </button>
                        </div>
                    </div>

                    {{-- Secondary Buttons --}}
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-3">Secondary / Ghost</div>
                        <div class="flex flex-wrap gap-3">
                            <button class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 dark:text-gray-300 bg-white/60 dark:bg-white/5 backdrop-blur-sm border border-black/5 dark:border-white/10 rounded-lg hover:bg-white/80 dark:hover:bg-white/10 transition-all duration-150">
                                @svg('heroicon-o-arrow-path', 'w-4 h-4')
                                Aktualisieren
                            </button>
                            <button class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-600 dark:text-gray-300 bg-white/60 dark:bg-white/5 backdrop-blur-sm border border-black/5 dark:border-white/10 rounded-lg hover:bg-white/80 dark:hover:bg-white/10 transition-all duration-150">
                                Abbrechen
                            </button>
                        </div>
                    </div>

                    {{-- Danger Buttons --}}
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-3">Danger</div>
                        <div class="flex flex-wrap gap-3">
                            <button class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-gradient-to-r from-red-500 to-rose-500 rounded-lg shadow-sm shadow-red-500/25 hover:shadow-md hover:shadow-red-500/30 transition-all duration-150">
                                @svg('heroicon-o-trash', 'w-4 h-4')
                                Löschen
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Section: Inputs --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm shadow-black/5">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-indigo-500/30 to-transparent"></div>
                <div class="px-5 py-4 border-b border-black/5 dark:border-white/5">
                    <h2 class="text-sm font-medium tracking-tight text-gray-900 dark:text-gray-100">Form Inputs</h2>
                    <p class="text-xs text-gray-400 mt-0.5">Minimal, borderless mit subtle background</p>
                </div>
                <div class="p-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- Text Input --}}
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">Name</label>
                            <input
                                type="text"
                                wire:model="testValue"
                                placeholder="Max Mustermann..."
                                class="w-full px-3 py-2 text-sm text-gray-900 dark:text-gray-100 bg-black/[0.03] dark:bg-white/5 rounded-lg border-0 placeholder-gray-400 focus:ring-2 focus:ring-violet-500/20 focus:bg-white dark:focus:bg-white/10 transition-all duration-150"
                            />
                        </div>

                        {{-- Number Input --}}
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">Anzahl</label>
                            <input
                                type="number"
                                wire:model="testNumber"
                                placeholder="42"
                                class="w-full px-3 py-2 text-sm text-gray-900 dark:text-gray-100 bg-black/[0.03] dark:bg-white/5 rounded-lg border-0 placeholder-gray-400 focus:ring-2 focus:ring-violet-500/20 focus:bg-white dark:focus:bg-white/10 transition-all duration-150"
                            />
                        </div>

                        {{-- Select --}}
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">Kategorie</label>
                            <select class="w-full px-3 py-2 text-sm text-gray-900 dark:text-gray-100 bg-black/[0.03] dark:bg-white/5 rounded-lg border-0 focus:ring-2 focus:ring-violet-500/20 focus:bg-white dark:focus:bg-white/10 transition-all duration-150">
                                <option value="">Auswählen...</option>
                                <option value="a">Option A</option>
                                <option value="b">Option B</option>
                                <option value="c">Option C</option>
                            </select>
                        </div>

                        {{-- Textarea --}}
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">Beschreibung</label>
                            <textarea
                                rows="3"
                                placeholder="Beschreibung eingeben..."
                                class="w-full px-3 py-2 text-sm text-gray-900 dark:text-gray-100 bg-black/[0.03] dark:bg-white/5 rounded-lg border-0 placeholder-gray-400 focus:ring-2 focus:ring-violet-500/20 focus:bg-white dark:focus:bg-white/10 transition-all duration-150 resize-none"
                            ></textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Section: Cards & Badges --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm shadow-black/5">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-emerald-500/30 to-transparent"></div>
                <div class="px-5 py-4 border-b border-black/5 dark:border-white/5">
                    <h2 class="text-sm font-medium tracking-tight text-gray-900 dark:text-gray-100">Cards & Badges</h2>
                    <p class="text-xs text-gray-400 mt-0.5">Frosted glass cards, farbige Status-Badges</p>
                </div>
                <div class="p-5 space-y-5">
                    {{-- Badges --}}
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-3">Status Badges</div>
                        <div class="flex flex-wrap gap-2">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-violet-600 dark:text-violet-400 bg-violet-500/10 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span>
                                Active
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                Success
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-amber-600 dark:text-amber-400 bg-amber-500/10 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                Warning
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-red-600 dark:text-red-400 bg-red-500/10 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                                Error
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium text-gray-500 dark:text-gray-400 bg-gray-500/10 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                Inactive
                            </span>
                        </div>
                    </div>

                    {{-- Mini Cards --}}
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-3">Frosted Cards</div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="p-4 rounded-lg bg-black/[0.02] dark:bg-white/[0.03] hover:bg-black/[0.04] dark:hover:bg-white/[0.05] transition-colors duration-150 cursor-pointer group">
                                <div class="flex items-center gap-3 mb-2">
                                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-500/20 to-indigo-500/20 flex items-center justify-center">
                                        @svg('heroicon-o-document-text', 'w-4 h-4 text-violet-500')
                                    </div>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Dokument</span>
                                </div>
                                <p class="text-xs text-gray-400">Ein Beispiel-Dokument mit frosted glass styling.</p>
                            </div>
                            <div class="p-4 rounded-lg bg-black/[0.02] dark:bg-white/[0.03] hover:bg-black/[0.04] dark:hover:bg-white/[0.05] transition-colors duration-150 cursor-pointer group">
                                <div class="flex items-center gap-3 mb-2">
                                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500/20 to-teal-500/20 flex items-center justify-center">
                                        @svg('heroicon-o-shield-check', 'w-4 h-4 text-emerald-500')
                                    </div>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Verifiziert</span>
                                </div>
                                <p class="text-xs text-gray-400">Status-Card mit grünem Akzent und Icon.</p>
                            </div>
                            <div class="p-4 rounded-lg bg-black/[0.02] dark:bg-white/[0.03] hover:bg-black/[0.04] dark:hover:bg-white/[0.05] transition-colors duration-150 cursor-pointer group">
                                <div class="flex items-center gap-3 mb-2">
                                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-amber-500/20 to-orange-500/20 flex items-center justify-center">
                                        @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 text-amber-500')
                                    </div>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Warnung</span>
                                </div>
                                <p class="text-xs text-gray-400">Warning-Card mit amber Akzent und Icon.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Section: Test Action --}}
            <div class="relative overflow-hidden rounded-xl bg-white/60 dark:bg-white/5 backdrop-blur-xl border border-black/5 dark:border-white/10 shadow-sm shadow-black/5">
                <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-sky-500/30 to-transparent"></div>
                <div class="px-5 py-4 border-b border-black/5 dark:border-white/5">
                    <h2 class="text-sm font-medium tracking-tight text-gray-900 dark:text-gray-100">Test Action</h2>
                    <p class="text-xs text-gray-400 mt-0.5">Livewire wire:click Demo</p>
                </div>
                <div class="p-5">
                    <div class="flex items-center gap-4">
                        <button
                            wire:click="testAction"
                            class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-white bg-gradient-to-r from-violet-500 to-indigo-500 rounded-lg shadow-sm shadow-violet-500/25 hover:shadow-md hover:shadow-violet-500/30 hover:-translate-y-0.5 transition-all duration-150"
                        >
                            @svg('heroicon-o-play', 'w-4 h-4')
                            Test-Aktion ausführen
                        </button>
                        <span class="text-xs text-gray-400">Führt die testAction()-Methode aus</span>
                    </div>
                </div>
            </div>

            {{-- Section: Baustein master-detail (M0-07 / P-1) — Demo der 3 Zonen --}}
            <div>
                <div class="mb-3">
                    <h2 class="text-sm font-medium tracking-tight text-gray-900 dark:text-gray-100">Baustein: master-detail (P-1)</h2>
                    <p class="text-xs text-gray-400 mt-0.5">3 Zonen — Baum · Tabelle · kollabierbares Detail-Panel</p>
                </div>
                <x-foodalchemist::master-detail height="h-96">
                    <x-slot:tree>
                        <div class="p-4 space-y-2">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-400">Warengruppen</div>
                            @foreach([['01 Gemüse', 412], ['02 Obst', 188], ['03 Fisch', 96]] as [$label, $count])
                                <div class="flex items-center justify-between px-2 py-1.5 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-violet-500/5 cursor-default">
                                    <span>{{ $label }}</span>
                                    <span class="text-xs text-gray-400">{{ $count }}</span>
                                </div>
                            @endforeach
                        </div>
                    </x-slot:tree>

                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left">
                                @foreach(['Name', 'Status', 'LAs'] as $head)
                                    <th class="px-5 py-2 text-xs font-medium uppercase tracking-wider text-gray-400">{{ $head }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach([['Zanderfilet', 'Freigegeben', 4], ['Limettensaft, konserviert', 'Freigegeben', 14], ['Rote Bete', 'Vorläufig', 2]] as [$name, $status, $las])
                                <tr class="border-t border-black/5 dark:border-white/10 hover:bg-gradient-to-r hover:from-violet-500/5 hover:to-indigo-500/5 transition-all duration-150">
                                    <td class="px-5 py-2.5 font-medium text-gray-900 dark:text-gray-100">{{ $name }}</td>
                                    <td class="px-5 py-2.5"><span class="inline-flex px-2 py-0.5 rounded-full text-xs {{ $status === 'Freigegeben' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400' }}">{{ $status }}</span></td>
                                    <td class="px-5 py-2.5 text-gray-500">{{ $las }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <x-slot:panel>
                        <div class="px-4 pb-4 space-y-3">
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-400">Detail-Panel</div>
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">Limettensaft, konserviert</div>
                            <p class="text-xs text-gray-400">Hier rendert ab M3-03 die DetailPanel-Livewire-Komponente (Sektionen lazy). Einklappen über den Pfeil oben rechts.</p>
                        </div>
                    </x-slot:panel>
                </x-foodalchemist::master-detail>
            </div>

        </div>
    </x-ui-page-container>

    {{-- Linke Sidebar --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Schnellzugriff" width="w-80" :defaultOpen="true">
            <div class="p-5 space-y-5">
                <div>
                    <h3 class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-3">Sektionen</h3>
                    <div class="space-y-1">
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/[0.03] transition-colors duration-150 cursor-pointer">
                            <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span>
                            Buttons
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/[0.03] transition-colors duration-150 cursor-pointer">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                            Form Inputs
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/[0.03] transition-colors duration-150 cursor-pointer">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                            Cards & Badges
                        </div>
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/[0.03] transition-colors duration-150 cursor-pointer">
                            <span class="w-1.5 h-1.5 rounded-full bg-sky-500"></span>
                            Test Action
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-3">Design</h3>
                    <div class="p-3 rounded-lg bg-gradient-to-br from-violet-500/5 to-indigo-500/5">
                        <div class="text-xs font-medium text-violet-600 dark:text-violet-400">Linear / Raycast</div>
                        <div class="text-xs text-gray-400 mt-0.5">Custom Design-System</div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Rechte Sidebar --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktivitäten" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-5 space-y-4">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-400">Hinweise</div>
                <div class="space-y-3">
                    <div class="p-3 rounded-lg bg-violet-500/5">
                        <div class="text-xs font-medium text-violet-600 dark:text-violet-400 mb-1">Kein x-ui-button</div>
                        <div class="text-xs text-gray-400">Alle Buttons sind custom HTML mit Tailwind-Klassen.</div>
                    </div>
                    <div class="p-3 rounded-lg bg-indigo-500/5">
                        <div class="text-xs font-medium text-indigo-600 dark:text-indigo-400 mb-1">Kein x-ui-panel</div>
                        <div class="text-xs text-gray-400">Cards verwenden frosted-glass Styling statt shared Panels.</div>
                    </div>
                    <div class="p-3 rounded-lg bg-emerald-500/5">
                        <div class="text-xs font-medium text-emerald-600 dark:text-emerald-400 mb-1">Shell bleibt shared</div>
                        <div class="text-xs text-gray-400">x-ui-page, Navbar, Actionbar, Sidebar bleiben Platform-Komponenten.</div>
                    </div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>
</x-ui-page>
